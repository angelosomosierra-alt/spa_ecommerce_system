<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

// Fetch user info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── INITIALIZE CHECKOUT ──────────────────────────────────────────────────────
$checkout_items = [];
$checkout_type  = null;
$total_amount   = 0;

// ─── DETERMINE CHECKOUT TYPE ──────────────────────────────────────────────────
if (isset($_SESSION['service_booking'])) {
    $checkout_type = 'service';
    $service_id    = $_SESSION['service_booking']['service_id'];

    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$service) {
        header("Location: index.php");
        exit();
    }

    $checkout_items[] = [
        'type'         => 'service',
        'id'           => $service['id'],
        'name'         => $service['name'],
        'image'        => $service['image'],
        'price'        => $service['price'],
        'quantity'     => 1,
        'session_time' => $service['session_time']
    ];
    $total_amount = $service['price'];

} elseif (isset($_SESSION['direct_checkout'])) {
    $checkout_type = 'product';
    $product_id    = $_SESSION['direct_checkout']['product_id'];
    $quantity      = $_SESSION['direct_checkout']['quantity'];

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        header("Location: index.php");
        exit();
    }

    $checkout_items[] = [
        'type'     => 'product',
        'id'       => $product['id'],
        'name'     => $product['name'],
        'image'    => $product['image'],
        'price'    => $product['price'],
        'quantity' => $quantity
    ];
    $total_amount = $product['price'] * $quantity;

} elseif (isset($_SESSION['checkout_items']) && !empty($_SESSION['checkout_items'])) {
    $checkout_type  = 'cart';
    $checkout_items = $_SESSION['checkout_items'];

    foreach ($checkout_items as $item) {
        $total_amount += $item['price'] * $item['quantity'];
    }

} else {
    header("Location: cart.php");
    exit();
}

// ─── GET NEXT AVAILABLE SLOTS ─────────────────────────────────────────────────
function get_next_slots($conn, $service_id, $days = 7) {
    $slots_data = [];

    $stmt = $conn->prepare("SELECT slots FROM services WHERE id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service     = $stmt->get_result()->fetch_assoc();
    $total_slots = $service['slots'] ?? 5;
    $stmt->close();

    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+$i day"));

        $stmt = $conn->prepare("
            SELECT SUM(people_count) as booked_count
            FROM appointments
            WHERE service_id = ? AND DATE(appointment_date) = ? AND status IN ('pending','approved')
        ");
        $stmt->bind_param("is", $service_id, $date);
        $stmt->execute();
        $booked    = $stmt->get_result()->fetch_assoc()['booked_count'] ?? 0;
        $stmt->close();

        $slots_data[] = ['date' => $date, 'available' => max($total_slots - $booked, 0)];
    }
    return $slots_data;
}

// ─── HANDLE FORM SUBMISSION ───────────────────────────────────────────────────
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $customer_name  = sanitize_input($_POST['customer_name']);
    $email          = sanitize_input($_POST['email']);
    $phone          = sanitize_input($_POST['phone']);
    $address        = sanitize_input($_POST['address']);
    $booking_date   = $_POST['booking_date'] ?? null;
    $people_count   = intval($_POST['people_count'] ?? 1);
    $payment_method = $_POST['payment_method'] ?? 'onsite';

    // ── Determine payment status ───────────────────────────────────────────
    // onsite = unpaid (admin approves after collecting payment)
    // online = paid   (PayMongo will handle — placeholder for now)
    $payment_status = $payment_method === 'online' ? 'paid' : 'unpaid';

    if (empty($customer_name) || empty($email) || empty($phone) || empty($address)) {
        $message      = "All fields are required.";
        $message_type = "danger";
    } elseif (empty($payment_method)) {
        $message      = "Please select a payment method.";
        $message_type = "danger";
    } elseif ($checkout_type === 'service' && (empty($booking_date) || $people_count < 1)) {
        $message      = "Booking date and number of people are required for services.";
        $message_type = "danger";
    } else {

        // ── Validate product stock ─────────────────────────────────────────
        $stock_errors = [];
        if ($checkout_type === 'cart' || $checkout_type === 'product') {
            foreach ($checkout_items as $item) {
                if ($item['type'] === 'product') {
                    $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                    $stmt->bind_param("i", $item['id']);
                    $stmt->execute();
                    $current_stock = intval($stmt->get_result()->fetch_assoc()['stock']);
                    $stmt->close();

                    if ($item['quantity'] > $current_stock) {
                        $stock_errors[] = "'{$item['name']}' only has $current_stock left in stock.";
                    }
                }
            }
            if (!empty($stock_errors)) {
                $message      = implode('<br>', $stock_errors);
                $message_type = "danger";
            }
        }

        // ── Validate service slots ─────────────────────────────────────────
        if ($checkout_type === 'service' && empty($message)) {
            $stmt = $conn->prepare("
                SELECT slots - IFNULL(SUM(people_count), 0) as available
                FROM services s
                LEFT JOIN appointments a
                    ON s.id = a.service_id
                    AND DATE(a.appointment_date) = DATE(?)
                    AND a.status IN ('pending','approved')
                WHERE s.id = ?
                GROUP BY s.id
            ");
            $stmt->bind_param("si", $booking_date, $service_id);
            $stmt->execute();
            $available = $stmt->get_result()->fetch_assoc()['available'] ?? 0;
            $stmt->close();

            if ($people_count > $available) {
                $message      = "Only $available slots available on " . date('F d, Y H:i', strtotime($booking_date)) . ".";
                $message_type = "danger";
            }
        }

        // ── Place order ────────────────────────────────────────────────────
        if (empty($message)) {
            $stmt = $conn->prepare("
                INSERT INTO orders (user_id, customer_name, email, phone, address, booking_date, total_amount, payment_method, payment_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssssdss",
                $user_id,
                $customer_name,
                $email,
                $phone,
                $address,
                $booking_date,
                $total_amount,
                $payment_method,
                $payment_status
            );

            if ($stmt->execute()) {
                $order_id = $stmt->insert_id;
                $stmt->close();

                foreach ($checkout_items as $item) {
                    if ($item['type'] === 'product') {
                        $subtotal = $item['price'] * $item['quantity'];

                        $item_stmt = $conn->prepare("
                            INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $item_stmt->bind_param("iiidd", $order_id, $item['id'], $item['quantity'], $item['price'], $subtotal);
                        $item_stmt->execute();
                        $item_stmt->close();

                        // Deduct stock
                        $update_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                        $update_stmt->bind_param("ii", $item['quantity'], $item['id']);
                        $update_stmt->execute();
                        $update_stmt->close();

                    } elseif ($item['type'] === 'service') {
                        $item_stmt = $conn->prepare("
                            INSERT INTO order_items (order_id, service_id, quantity, price, subtotal)
                            VALUES (?, ?, 1, ?, ?)
                        ");
                        $item_stmt->bind_param("iidd", $order_id, $item['id'], $item['price'], $item['price']);
                        $item_stmt->execute();
                        $order_item_id = $item_stmt->insert_id;
                        $item_stmt->close();

                        // Appointment status: pending for onsite, approved for online
                        $appt_status = $payment_method === 'online' ? 'approved' : 'pending';

                        $appt_stmt = $conn->prepare("
                            INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $appt_stmt->bind_param("iiissi", $user_id, $item['id'], $order_item_id, $booking_date, $appt_status, $people_count);
                        $appt_stmt->execute();
                        $appt_stmt->close();
                    }
                }

                // ── Clear cart items that were checked out ─────────────────
                if ($checkout_type === 'cart') {
                    foreach ($checkout_items as $item) {
                        unset($_SESSION['cart'][$item['id']]);
                        remove_cart_item_from_db($conn, $user_id, $item['id']);
                    }
                }

                // Clear all checkout sessions
                // ─── REMOVE CHECKED-OUT ITEMS FROM CART ──────────────────────────────────────
if ($checkout_type === 'cart' && !empty($_SESSION['checkout_item_ids'])) {
    foreach ($_SESSION['checkout_item_ids'] as $checked_out_id) {
        // Remove from session cart
        unset($_SESSION['cart'][$checked_out_id]);
        // Remove from database cart
        remove_cart_item_from_db($conn, $user_id, $checked_out_id);
    }
}

// Clear all checkout sessions
unset(
    $_SESSION['direct_checkout'],
    $_SESSION['service_booking'],
    $_SESSION['checkout_items'],
    $_SESSION['checkout_item_ids']  // ← clear the tracking array too
);

// Sync the updated cart back to DB
if (!empty($_SESSION['cart'])) {
    sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
}

                $message      = "✅ Order placed successfully! Order ID: #$order_id";
                $message_type = "success";

                echo "<script>setTimeout(function(){ window.location.href = 'appointments.php'; }, 2500);</script>";

            } else {
                $message      = "Error placing order. Please try again.";
                $message_type = "danger";
            }
        }
    }
}

$page_title = 'Checkout';
require_once 'header.php';
?>

<div class="container">
<h1 style="color:#3B2A1A; margin:2rem 0;">Checkout</h1>

<?php if ($message): ?>
    <div style="padding:1rem 1.5rem; border-radius:10px; margin-bottom:1.5rem;
                background:<?php echo $message_type === 'success' ? '#d1e7dd' : '#f8d7da'; ?>;
                color:<?php echo $message_type === 'success' ? '#0a3622' : '#842029'; ?>;
                border-left:5px solid <?php echo $message_type === 'success' ? '#198754' : '#dc3545'; ?>;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div style="display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap;">

    <!-- ── Order Summary ────────────────────────────────────────────────── -->
    <div style="flex:1; min-width:280px; background:#FAF3E8; padding:1.5rem; border-radius:12px;">
        <h3 style="color:#3B2A1A; margin-bottom:1.5rem;">Order Summary</h3>

        <?php foreach ($checkout_items as $item):
            $item_type  = $item['type'] ?? 'product';
            $image_path = '/spa_ecommerce_system/uploads/products/default.png';
            if ($item_type === 'product') {
                $image_path = '/spa_ecommerce_system/uploads/products/' . $item['image'];
            } elseif ($item_type === 'service') {
                $image_path = '/spa_ecommerce_system/uploads/services/' . $item['image'];
            }
        ?>
            <div style="display:flex; gap:1rem; align-items:center; margin-bottom:1rem; padding-bottom:1rem; border-bottom:1px solid #EAD8C0;">
                <img src="<?php echo $image_path; ?>"
                     alt="<?php echo htmlspecialchars($item['name']); ?>"
                     style="width:70px; height:70px; object-fit:cover; border-radius:8px;">
                <div style="flex:1;">
                    <div style="font-weight:bold; color:#3B2A1A;"><?php echo htmlspecialchars($item['name']); ?></div>
                    <?php if (isset($item['session_time'])): ?>
                        <small style="color:#666;">⏱ <?php echo $item['session_time']; ?> minutes</small><br>
                    <?php endif; ?>
                    <div style="color:#C96A2C; font-weight:bold;">$<?php echo number_format($item['price'], 2); ?></div>
                    <small style="color:#666;">Qty: <?php echo $item['quantity']; ?></small>
                </div>
                <div style="font-weight:bold;">
                    $<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                </div>
            </div>
        <?php endforeach; ?>

        <hr style="border:none; border-top:2px solid #EAD8C0; margin:1rem 0;">
        <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
            <span>Subtotal:</span><strong>$<?php echo number_format($total_amount, 2); ?></strong>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
            <span>Shipping:</span><strong>Free</strong>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:1.2rem; color:#C96A2C; font-weight:bold; margin-top:0.5rem;">
            <strong>Total:</strong>
            <strong>$<?php echo number_format($total_amount, 2); ?></strong>
        </div>
    </div>

    <!-- ── Billing Form ──────────────────────────────────────────────────── -->
    <div style="flex:1; min-width:280px; background:#fff; padding:1.5rem; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
        <h3 style="color:#3B2A1A; margin-bottom:1.5rem;">Billing Information</h3>
        <form method="POST" id="checkoutForm">
            <input type="hidden" name="payment_method" id="payment_method" value="onsite">

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="customer_name"
                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone"
                       value="<?php echo htmlspecialchars($user['phone']); ?>" required>
            </div>
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" required><?php echo htmlspecialchars($user['address']); ?></textarea>
            </div>

            <?php if ($checkout_type === 'service'): ?>
                <div class="form-group">
                    <label>Number of People</label>
                    <input type="number" name="people_count" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label>Preferred Booking Date & Time</label>
                    <input type="datetime-local" name="booking_date" id="booking_date" required>
                </div>
            <?php endif; ?>

            <!-- ── Payment Method ──────────────────────────────────────── -->
            <label style="font-weight:bold; color:#3B2A1A; display:block; margin-bottom:0.75rem;">
                Payment Method <span style="color:red;">*</span>
            </label>
            <div style="display:flex; gap:1rem; margin-bottom:1.5rem;">

                <!-- Onsite Payment -->
                <div style="flex:1; border:2px solid #EAD8C0; border-radius:12px; padding:1rem;
                            text-align:center; cursor:pointer; background:#FAF3E8; transition:all 0.2s;"
                     id="card-onsite"
                     onclick="selectPayment('onsite')">
                    <div style="font-size:2rem;">🏪</div>
                    <div style="font-weight:bold; color:#3B2A1A; margin-top:0.3rem;">Onsite Payment</div>
                    <div style="font-size:0.8rem; color:#888; margin-top:0.3rem;">Pay when you arrive</div>
                </div>

                <!-- Online Payment -->
                <div style="flex:1; border:2px solid #EAD8C0; border-radius:12px; padding:1rem;
                            text-align:center; cursor:pointer; background:#FAF3E8; transition:all 0.2s;"
                     id="card-online"
                     onclick="selectPayment('online')">
                    <div style="font-size:2rem;">💳</div>
                    <div style="font-weight:bold; color:#3B2A1A; margin-top:0.3rem;">Online Payment</div>
                    <div style="font-size:0.8rem; color:#888; margin-top:0.3rem;">Pay via PayMongo</div>
                    <span style="display:inline-block; background:#EAD8C0; color:#3B2A1A;
                                 font-size:0.7rem; padding:0.1rem 0.5rem; border-radius:20px; margin-top:0.3rem;">
                        Coming Soon
                    </span>
                </div>
            </div>

            <!-- Payment info note -->
            <div id="payment-note" style="padding:0.75rem 1rem; border-radius:8px; margin-bottom:1rem;
                                          background:#fff8f2; border-left:4px solid #C96A2C;
                                          font-size:0.88rem; color:#3B2A1A;">
                🏪 <strong>Onsite Payment:</strong> Your order will be set to
                <strong>Pending</strong> — pay at the spa and staff will approve.
            </div>

            <button type="submit" name="place_order"
                    id="placeOrderBtn"
                    class="btn btn-primary"
                    style="width:100%; padding:1rem; font-size:1.1rem;">
                ✅ Place Order
            </button>
            <a href="<?php echo $checkout_type === 'service' ? 'index.php' : 'cart.php'; ?>"
               class="btn btn-secondary"
               style="width:100%; padding:1rem; margin-top:0.75rem; text-align:center; display:block;">
                ← Go Back
            </a>
        </form>
    </div>

</div>

<?php if ($checkout_type === 'service'):
    $slots = get_next_slots($conn, $service_id, 7);
?>
<div style="margin-top:2rem;">
    <h3 style="color:#3B2A1A; margin-bottom:1rem;">📅 Next 7-Day Availability</h3>
    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
        <?php foreach ($slots as $s): ?>
            <?php if ($s['available'] > 0): ?>
                <button type="button" class="btn btn-primary slot-btn"
                        data-date="<?php echo $s['date']; ?>"
                        style="padding:0.6rem 1rem; text-align:center;">
                    <?php echo date('D, M d', strtotime($s['date'])); ?><br>
                    <small><?php echo $s['available']; ?> slots left</small>
                </button>
            <?php else: ?>
                <span style="padding:0.6rem 1rem; background:#f8d7da; color:#842029;
                             border-radius:8px; font-size:0.9rem; text-align:center; display:inline-block;">
                    <?php echo date('D, M d', strtotime($s['date'])); ?><br>
                    <small>Full</small>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<style>
.payment-card-selected {
    border-color: #C96A2C !important;
    background: #C96A2C !important;
}
.payment-card-selected div { color: #fff !important; }
.payment-card-selected span {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
}
</style>

<script>
// ─── PAYMENT SELECTION ────────────────────────────────────────────────────────
function selectPayment(method) {
    // Reset both cards
    document.getElementById('card-onsite').className = '';
    document.getElementById('card-online').className = '';
    document.getElementById('card-onsite').removeAttribute('class');
    document.getElementById('card-online').removeAttribute('class');

    // Reset styles
    ['card-onsite', 'card-online'].forEach(id => {
        const el = document.getElementById(id);
        el.style.borderColor  = '#EAD8C0';
        el.style.background   = '#FAF3E8';
    });

    // Highlight selected
    const selected = document.getElementById('card-' + method);
    selected.style.borderColor = '#C96A2C';
    selected.style.background  = '#C96A2C';
    selected.querySelectorAll('div').forEach(d => d.style.color = '#fff');
    const badge = selected.querySelector('span');
    if (badge) { badge.style.background = 'rgba(255,255,255,0.25)'; badge.style.color = '#fff'; }

    // Set hidden input
    document.getElementById('payment_method').value = method;

    // Update note
    const note = document.getElementById('payment-note');
    if (method === 'onsite') {
        note.innerHTML = '🏪 <strong>Onsite Payment:</strong> Your order will be set to <strong>Pending</strong> — pay at the spa and staff will approve.';
        note.style.borderColor = '#C96A2C';
    } else {
        note.innerHTML = '💳 <strong>Online Payment (Coming Soon):</strong> Once PayMongo is integrated, your order will be <strong>automatically approved</strong> after payment.';
        note.style.borderColor = '#0070f3';
        // Show alert for now
        alert('💳 Online Payment via PayMongo is coming soon!\n\nPlease select Onsite Payment for now.');
        // Revert back to onsite
        selectPayment('onsite');
        return;
    }
}

// ─── SLOT BUTTONS ─────────────────────────────────────────────────────────────
document.querySelectorAll('.slot-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const bookingInput = document.getElementById('booking_date');
        if (bookingInput) {
            bookingInput.value = this.getAttribute('data-date') + 'T09:00';
        }
        // Highlight selected slot
        document.querySelectorAll('.slot-btn').forEach(b => b.style.opacity = '0.6');
        this.style.opacity = '1';
    });
});

// ─── INIT: SELECT ONSITE BY DEFAULT ──────────────────────────────────────────
selectPayment('onsite');
</script>

</body>
</html>