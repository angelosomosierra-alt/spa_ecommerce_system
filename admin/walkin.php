<?php
require_once '../config.php';
redirect_if_not_admin();

// ─── PAGE META ────────────────────────────────────────────────────────────────
$page_title  = 'Walk-in Kiosk';
$page_icon   = '🏪';
$active_page = 'walkin';

// ─── GET OR CREATE WALK-IN USER ───────────────────────────────────────────────
$walkin_user_id = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username = 'walkin_customer' LIMIT 1");
$stmt->execute();
$walkin_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($walkin_user) {
    $walkin_user_id = $walkin_user['id'];
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES ('walkin_customer','N/A','walkin@spa.com','Walk-in Customer','N/A','Walk-in Customer','user')");
    $stmt->execute();
    $walkin_user_id = $stmt->insert_id;
    $stmt->close();
}

// ─── PROCESS ORDER ────────────────────────────────────────────────────────────
$walkin_message = '';
$walkin_type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['walkin_order'])) {
    $customer_name  = sanitize_input($_POST['customer_name']);
    $phone          = sanitize_input($_POST['phone']);
    $order_type     = $_POST['order_type'];
    $item_id        = intval($_POST['item_id']);
    $quantity       = max(1, intval($_POST['quantity'] ?? 1));
    $people_count   = max(1, intval($_POST['people_count'] ?? 1));
    $booking_date   = $_POST['booking_date'] ?? null;
    $payment_method = $_POST['payment_method'] ?? 'cash';

    if (empty($customer_name) || empty($phone) || empty($item_id)) {
        $walkin_message = "Please fill in all required fields.";
        $walkin_type    = "danger";
    } else {
        if ($order_type === 'product') {
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$item) {
                $walkin_message = "Product not found.";
                $walkin_type    = "danger";
            } elseif ($item['stock'] < $quantity) {
                $walkin_message = "Only {$item['stock']} left in stock.";
                $walkin_type    = "danger";
            } else {
                $total_amount  = $item['price'] * $quantity;
                // Walk-in cash = collected immediately → paid + approved
                // Walk-in online = pending payment
                $pay_status  = $payment_method === 'cash' ? 'paid'   : 'pending_payment';
                $apvl_status = $payment_method === 'cash' ? 'approved' : 'pending';

                $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, email, phone, address, total_amount, payment_method, payment_status, approval_status) VALUES (?, ?, 'walkin@spa.com', ?, 'Walk-in Customer', ?, ?, ?, ?)");
                $stmt->bind_param("issdssss", $walkin_user_id, $customer_name, $phone, $total_amount, $payment_method, $pay_status, $apvl_status);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();

                $subtotal = $item['price'] * $quantity;
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiddd", $order_id, $item_id, $quantity, $item['price'], $subtotal);
                $stmt->execute();
                $stmt->close();

                // Only deduct stock for cash (immediately confirmed)
                if ($payment_method === 'cash') {
                    $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                    $stmt->bind_param("ii", $quantity, $item_id);
                    $stmt->execute();
                    $stmt->close();
                }

                $payment_label  = $payment_method === 'cash' ? '💵 Cash' : '💳 Online (Pending)';
                $walkin_message = "✅ Order #<strong>$order_id</strong> placed for <strong>$customer_name</strong> — Total: <strong>₱" . number_format($total_amount, 2) . "</strong> | $payment_label";
                $walkin_type    = "success";
            }

        } elseif ($order_type === 'service') {
            if (empty($booking_date)) {
                $walkin_message = "Please select a booking date.";
                $walkin_type    = "danger";
            } else {
                $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
                $stmt->bind_param("i", $item_id);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$item) {
                    $walkin_message = "Service not found.";
                    $walkin_type    = "danger";
                } else {
                    $stmt = $conn->prepare("SELECT slots - IFNULL(SUM(people_count),0) as available FROM services s LEFT JOIN appointments a ON s.id = a.service_id AND DATE(a.appointment_date) = DATE(?) AND a.status IN ('pending','approved') WHERE s.id = ? GROUP BY s.id");
                    $stmt->bind_param("si", $booking_date, $item_id);
                    $stmt->execute();
                    $row       = $stmt->get_result()->fetch_assoc();
                    $available = $row['available'] ?? $item['slots'];
                    $stmt->close();

                    if ($people_count > $available) {
                        $walkin_message = "Only <strong>$available</strong> slot(s) available on " . date('M d, Y H:i', strtotime($booking_date)) . ".";
                        $walkin_type    = "danger";
                    } else {
                        $total_amount = $item['price'];

                        // Cash walk-in = paid + approved immediately
                        // Online walk-in = pending payment/approval
                        $pay_status  = $payment_method === 'cash' ? 'paid'     : 'pending_payment';
                        $apvl_status = $payment_method === 'cash' ? 'approved' : 'pending';
                        $appt_status = $payment_method === 'cash' ? 'approved' : 'pending';

                        $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, email, phone, address, booking_date, total_amount, payment_method, payment_status, approval_status) VALUES (?, ?, 'walkin@spa.com', ?, 'Walk-in Customer', ?, ?, ?, ?, ?)");
                        $stmt->bind_param("issssdssss", $walkin_user_id, $customer_name, $phone, $booking_date, $total_amount, $payment_method, $pay_status, $apvl_status);
                        $stmt->execute();
                        $order_id = $stmt->insert_id;
                        $stmt->close();

                        $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
                        $stmt->bind_param("iidd", $order_id, $item_id, $item['price'], $item['price']);
                        $stmt->execute();
                        $order_item_id = $stmt->insert_id;
                        $stmt->close();

                        $stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiissi", $walkin_user_id, $item_id, $order_item_id, $booking_date, $appt_status, $people_count);
                        $stmt->execute();
                        $stmt->close();

                        $status_label   = $payment_method === 'cash' ? '✅ Approved' : '⏳ Pending';
                        $payment_label  = $payment_method === 'cash' ? '💵 Cash — Collected' : '💳 Online (Pending)';
                        $walkin_message = "✅ Booking #<strong>$order_id</strong> created for <strong>$customer_name</strong> — <strong>{$item['name']}</strong> on <strong>" . date('M d, Y H:i', strtotime($booking_date)) . "</strong> for <strong>$people_count</strong> person(s) | $payment_label | Status: <strong>$status_label</strong>";
                        $walkin_type    = "success";
                    }
                }
            }
        }
    }
}

// ─── FETCH DATA ────────────────────────────────────────────────────────────────
$all_services = [];
$result = $conn->query("SELECT id, name, price, slots, session_time FROM services ORDER BY name");
while ($row = $result->fetch_assoc()) $all_services[] = $row;

$all_products = [];
$result = $conn->query("SELECT id, name, price, stock FROM products WHERE stock > 0 ORDER BY name");
while ($row = $result->fetch_assoc()) $all_products[] = $row;

require_once 'admin_header.php';
?>

<?php if ($walkin_message): ?>
<div class="walkin-alert <?php echo $walkin_type; ?>">
    <?php echo $walkin_message; ?>
</div>
<?php endif; ?>

<!-- ── KIOSK TABS ─────────────────────────────────────────────── -->
<div class="kiosk-tabs" style="max-width:320px;">
    <button type="button" class="kiosk-tab active" onclick="switchTab('service', this)">
        💆 Book Service
    </button>
    <button type="button" class="kiosk-tab" onclick="switchTab('product', this)">
        🛍️ Buy Product
    </button>
</div>

<!-- ════════════════════════════════════════════════════════════
     SERVICE TAB
════════════════════════════════════════════════════════════ -->
<div class="kiosk-panel active" id="tab-service">
    <form method="POST" id="serviceForm">
        <input type="hidden" name="walkin_order"   value="1">
        <input type="hidden" name="order_type"     value="service">
        <input type="hidden" name="item_id"        id="service_item_id" value="">
        <input type="hidden" name="payment_method" id="service_payment_method" value="cash">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">

            <!-- LEFT: Customer + Service selection -->
            <div>
                <div class="form-section">
                    <div class="form-section-header">👤 Customer Information</div>
                    <div class="form-section-body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="customer_name" placeholder="Enter full name" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number <span class="required">*</span></label>
                                <input type="tel" name="phone" placeholder="09XXXXXXXXX" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">💆 Select Service <span class="required">*</span></div>
                    <div class="form-section-body" style="padding:1rem;">
                        <div class="item-select-grid" id="service-grid">
                            <?php foreach ($all_services as $svc): ?>
                                <div class="item-card"
                                     onclick="selectItem('service', <?php echo $svc['id']; ?>, this)"
                                     data-id="<?php echo $svc['id']; ?>">
                                    <div class="item-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                                    <div class="item-price">₱<?php echo number_format($svc['price'], 2); ?></div>
                                    <div class="item-meta">⏱ <?php echo $svc['session_time']; ?> min</div>
                                    <span class="slots-info">📅 <?php echo $svc['slots']; ?>/day</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_services)): ?>
                                <p style="color:var(--gray); font-size:0.82rem; padding:1rem; grid-column:1/-1;">No services available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Booking details + Payment -->
            <div>
                <div class="form-section">
                    <div class="form-section-header">📅 Booking Details</div>
                    <div class="form-section-body">
                        <div class="form-group" style="margin-bottom:1rem;">
                            <label>Booking Date & Time <span class="required">*</span></label>
                            <input type="datetime-local" name="booking_date" id="service_booking_date" required>
                        </div>
                        <div class="form-group">
                            <label>Number of People <span class="required">*</span></label>
                            <input type="number" name="people_count" value="1" min="1" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">💳 Payment Method <span class="required">*</span></div>
                    <div class="form-section-body">
                        <div class="payment-buttons">
                            <button type="submit"
                                    class="pay-btn pay-btn-cash"
                                    onclick="setPayment('service','cash')">
                                💵 Pay in Cash
                            </button>
                            <button type="button"
                                    class="pay-btn pay-btn-online"
                                    onclick="showPayMongoComingSoon()">
                                💳 Pay Online
                                <span class="coming-soon-badge">Soon</span>
                            </button>
                        </div>
                        <p class="payment-note">
                            💵 Cash → Status set to <strong style="color:var(--amber)">Pending</strong> until collected.<br>
                            💳 Online → <strong style="color:var(--green)">Auto-approved</strong> after PayMongo succeeds.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ════════════════════════════════════════════════════════════
     PRODUCT TAB
════════════════════════════════════════════════════════════ -->
<div class="kiosk-panel" id="tab-product">
    <form method="POST" id="productForm">
        <input type="hidden" name="walkin_order"   value="1">
        <input type="hidden" name="order_type"     value="product">
        <input type="hidden" name="item_id"        id="product_item_id" value="">
        <input type="hidden" name="payment_method" id="product_payment_method" value="cash">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">

            <!-- LEFT: Customer + Product selection -->
            <div>
                <div class="form-section">
                    <div class="form-section-header">👤 Customer Information</div>
                    <div class="form-section-body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="customer_name" placeholder="Enter full name" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number <span class="required">*</span></label>
                                <input type="tel" name="phone" placeholder="09XXXXXXXXX" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">🛍️ Select Product <span class="required">*</span></div>
                    <div class="form-section-body" style="padding:1rem;">
                        <div class="item-select-grid" id="product-grid">
                            <?php foreach ($all_products as $prd): ?>
                                <div class="item-card"
                                     onclick="selectItem('product', <?php echo $prd['id']; ?>, this)"
                                     data-id="<?php echo $prd['id']; ?>">
                                    <div class="item-name"><?php echo htmlspecialchars($prd['name']); ?></div>
                                    <div class="item-price">₱<?php echo number_format($prd['price'], 2); ?></div>
                                    <span class="slots-info">📦 <?php echo $prd['stock']; ?> left</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_products)): ?>
                                <p style="color:var(--gray); font-size:0.82rem; padding:1rem; grid-column:1/-1;">No products available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Quantity + Payment -->
            <div>
                <div class="form-section">
                    <div class="form-section-header">📦 Order Details</div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label>Quantity <span class="required">*</span></label>
                            <input type="number" name="quantity" id="product_quantity"
                                   value="1" min="1" required
                                   style="max-width:140px;">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">💳 Payment Method <span class="required">*</span></div>
                    <div class="form-section-body">
                        <div class="payment-buttons">
                            <button type="submit"
                                    class="pay-btn pay-btn-cash"
                                    onclick="setPayment('product','cash')">
                                💵 Pay in Cash
                            </button>
                            <button type="button"
                                    class="pay-btn pay-btn-online"
                                    onclick="showPayMongoComingSoon()">
                                💳 Pay Online
                                <span class="coming-soon-badge">Soon</span>
                            </button>
                        </div>
                        <p class="payment-note">
                            💵 Cash → Order placed, collect payment from customer.<br>
                            💳 Online → Confirmed after PayMongo payment succeeds.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function switchTab(type, el) {
    document.querySelectorAll('.kiosk-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.kiosk-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + type).classList.add('active');
    el.classList.add('active');
}

function selectItem(type, id, el) {
    el.closest('.item-select-grid').querySelectorAll('.item-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById(type + '_item_id').value = id;
    if (type === 'product') {
        const stock = parseInt(el.querySelector('.slots-info').textContent.replace(/\D/g,''));
        document.getElementById('product_quantity').max = stock;
    }
}

function setPayment(formType, method) {
    document.getElementById(formType + '_payment_method').value = method;
}

function showPayMongoComingSoon() {
    alert('💳 Online payment via PayMongo is coming soon!\n\nPlease use Cash payment for now.');
}

document.getElementById('serviceForm').addEventListener('submit', function(e) {
    if (!document.getElementById('service_item_id').value) {
        e.preventDefault(); alert('Please select a service first.');
    }
});

document.getElementById('productForm').addEventListener('submit', function(e) {
    if (!document.getElementById('product_item_id').value) {
        e.preventDefault(); alert('Please select a product first.');
    }
});

const now     = new Date();
const pad     = n => String(n).padStart(2,'0');
const minDate = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
document.getElementById('service_booking_date').min = minDate;
</script>

<?php require_once 'admin_footer.php'; ?>