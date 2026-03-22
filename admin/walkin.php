<?php
require_once '../config.php';
redirect_if_not_admin(); // ← FIXED: was redirect_if_not_user()

// ─── GET OR CREATE WALK-IN PLACEHOLDER USER ───────────────────────────────────
$walkin_user_id = null;

$stmt = $conn->prepare("SELECT id FROM users WHERE username = 'walkin_customer' LIMIT 1");
$stmt->execute();
$walkin_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($walkin_user) {
    $walkin_user_id = $walkin_user['id'];
} else {
    $stmt = $conn->prepare("
        INSERT INTO users (username, password, email, full_name, phone, address, role)
        VALUES ('walkin_customer', 'N/A', 'walkin@spa.com', 'Walk-in Customer', 'N/A', 'Walk-in Customer', 'user')
    ");
    $stmt->execute();
    $walkin_user_id = $stmt->insert_id;
    $stmt->close();
}

// ─── WALK-IN ORDER SUBMISSION ─────────────────────────────────────────────────
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

        // ── PRODUCT ORDER ──────────────────────────────────────────────────
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
                $total_amount = $item['price'] * $quantity;

                $stmt = $conn->prepare("
                    INSERT INTO orders (user_id, customer_name, email, phone, address, total_amount)
                    VALUES (?, ?, 'walkin@spa.com', ?, 'Walk-in Customer', ?)
                ");
                $stmt->bind_param("issd", $walkin_user_id, $customer_name, $phone, $total_amount);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();

                $subtotal = $item['price'] * $quantity;
                $stmt = $conn->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iiddd", $order_id, $item_id, $quantity, $item['price'], $subtotal);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->bind_param("ii", $quantity, $item_id);
                $stmt->execute();
                $stmt->close();

                $payment_label  = $payment_method === 'cash' ? '💵 Cash' : '💳 Pay Online';
                $walkin_message = "✅ Walk-in product order placed! Order #<strong>$order_id</strong>
                                   for <strong>$customer_name</strong>.
                                   Total: <strong>$" . number_format($total_amount, 2) . "</strong> |
                                   Payment: <strong>$payment_label</strong>";
                $walkin_type = "success";
            }

        // ── SERVICE BOOKING ────────────────────────────────────────────────
        } elseif ($order_type === 'service') {
            if (empty($booking_date)) {
                $walkin_message = "Please select a booking date for the service.";
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
                    $stmt->bind_param("si", $booking_date, $item_id);
                    $stmt->execute();
                    $row       = $stmt->get_result()->fetch_assoc();
                    $available = $row['available'] ?? $item['slots'];
                    $stmt->close();

                    if ($people_count > $available) {
                        $walkin_message = "Only <strong>$available</strong> slot(s) available on "
                                        . date('M d, Y H:i', strtotime($booking_date)) . ".";
                        $walkin_type = "danger";
                    } else {
                        $total_amount   = $item['price'];

                        // Cash = pending, Online = pending until PayMongo confirms
                        $appt_status = 'pending';

                        $stmt = $conn->prepare("
                            INSERT INTO orders (user_id, customer_name, email, phone, address, booking_date, total_amount)
                            VALUES (?, ?, 'walkin@spa.com', ?, 'Walk-in Customer', ?, ?)
                        ");
                        $stmt->bind_param("isssd", $walkin_user_id, $customer_name, $phone, $booking_date, $total_amount);
                        $stmt->execute();
                        $order_id = $stmt->insert_id;
                        $stmt->close();

                        $stmt = $conn->prepare("
                            INSERT INTO order_items (order_id, service_id, quantity, price, subtotal)
                            VALUES (?, ?, 1, ?, ?)
                        ");
                        $stmt->bind_param("iidd", $order_id, $item_id, $item['price'], $item['price']);
                        $stmt->execute();
                        $order_item_id = $stmt->insert_id;
                        $stmt->close();

                        // Cash → pending, Online → pending until PayMongo webhook fires
                        $stmt = $conn->prepare("
                            INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("iiissi", $walkin_user_id, $item_id, $order_item_id, $booking_date, $appt_status, $people_count);
                        $stmt->execute();
                        $stmt->close();

                        $payment_label  = $payment_method === 'cash' ? '💵 Cash (Collect Payment)' : '💳 Pay Online (Pending)';
                        $walkin_message = "✅ Walk-in service booked! Order #<strong>$order_id</strong>
                                          for <strong>$customer_name</strong>.
                                          Service: <strong>{$item['name']}</strong> on
                                          <strong>" . date('M d, Y H:i', strtotime($booking_date)) . "</strong>
                                          for <strong>$people_count</strong> person(s) |
                                          Payment: <strong>$payment_label</strong> |
                                          Status: <strong>Pending</strong>";
                        $walkin_type = "success";
                    }
                }
            }
        }
    }
}

// ─── FETCH SERVICES & PRODUCTS ────────────────────────────────────────────────
// ← FIXED: these were missing from your version!
$all_services = [];
$result = $conn->query("SELECT id, name, price, slots, session_time FROM services ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $all_services[] = $row;
}

$all_products = [];
$result = $conn->query("SELECT id, name, price, stock FROM products WHERE stock > 0 ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $all_products[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in Kiosk - Spa Ecommerce</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .kiosk-wrapper {
            max-width: 860px;
            margin: 2rem auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
            overflow: hidden;
        }
        .kiosk-header {
            background: #3B2A1A;
            color: #FAF3E8;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kiosk-header h2 { margin: 0; font-size: 1.5rem; }
        .kiosk-body { padding: 2rem; }
        .kiosk-tabs { display: flex; gap: 1rem; margin-bottom: 2rem; }
        .kiosk-tab {
            flex: 1; padding: 1rem; text-align: center;
            cursor: pointer; border-radius: 10px;
            border: 2px solid #EAD8C0; background: #FAF3E8;
            color: #3B2A1A; font-weight: bold; font-size: 1rem;
            transition: all 0.2s;
        }
        .kiosk-tab:hover  { background: #EAD8C0; }
        .kiosk-tab.active { background: #C96A2C; color: #fff; border-color: #C96A2C; }
        .kiosk-panel { display: none; }
        .kiosk-panel.active { display: block; }
        .item-select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .item-card {
            border: 2px solid #EAD8C0; border-radius: 10px;
            padding: 1rem; text-align: center;
            cursor: pointer; background: #fff; transition: all 0.2s;
        }
        .item-card:hover    { border-color: #C96A2C; background: #fff8f2; }
        .item-card.selected { border-color: #C96A2C; background: #C96A2C; color: #fff; }
        .item-card .item-name  { font-weight: bold; margin-bottom: 0.3rem; font-size: 0.95rem; }
        .item-card .item-price { font-size: 0.9rem; color: #C96A2C; }
        .item-card.selected .item-price { color: #fff; }
        .item-card .item-meta  { font-size: 0.8rem; color: #888; margin-top: 0.2rem; }
        .item-card.selected .item-meta { color: #ffe0c8; }
        .slots-info {
            display: inline-block; background: #EAD8C0;
            color: #3B2A1A; border-radius: 20px;
            padding: 0.2rem 0.7rem; font-size: 0.78rem; margin-top: 0.3rem;
        }
        .item-card.selected .slots-info { background: rgba(255,255,255,0.25); color: #fff; }
        .walkin-alert {
            padding: 1rem 1.5rem; border-radius: 10px;
            margin-bottom: 1.5rem; font-size: 1rem;
        }
        .walkin-alert.success { background: #d1e7dd; color: #0a3622; border-left: 5px solid #198754; }
        .walkin-alert.danger  { background: #f8d7da; color: #842029; border-left: 5px solid #dc3545; }
        .form-row { display: flex; gap: 1rem; }
        .form-row .form-group { flex: 1; }
        .section-label {
            font-weight: bold; color: #3B2A1A;
            display: block; margin-bottom: 0.75rem; font-size: 1rem;
        }
        .required { color: red; }

        /* ── Payment Buttons ── */
        .payment-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .pay-btn {
            flex: 1;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: bold;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .pay-btn-cash {
            background: #3B2A1A;
            color: #FAF3E8;
        }
        .pay-btn-cash:hover { background: #5a4030; }
        .pay-btn-online {
            background: #0070f3;
            color: #fff;
        }
        .pay-btn-online:hover { background: #0051b3; }
        .coming-soon-badge {
            font-size: 0.7rem;
            background: rgba(255,255,255,0.25);
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
        }
        .payment-note {
            font-size: 0.82rem;
            color: #888;
            text-align: center;
            margin-top: 0.75rem;
            line-height: 1.6;
        }
    </style>
</head>
<body>

<header>
    <nav>
        <div class="logo">Spa Admin</div>
        <ul class="nav-links">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="walkin.php" class="active">Walk-in</a></li>
        </ul>
        <div class="auth-links">
            <a href="index.php?logout=1">Logout</a>
        </div>
    </nav>
</header>

<div class="container">
<div class="kiosk-wrapper">

    <div class="kiosk-header">
        <h2>🏪 Walk-in Customer Kiosk</h2>
        <a href="index.php" class="btn btn-secondary" style="padding:0.5rem 1rem;">← Back to Dashboard</a>
    </div>

    <div class="kiosk-body">

        <?php if ($walkin_message): ?>
            <div class="walkin-alert <?php echo $walkin_type; ?>">
                <?php echo $walkin_message; ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="kiosk-tabs">
            <button type="button" class="kiosk-tab active" onclick="switchTab('service', this)">💆 Book a Service</button>
            <button type="button" class="kiosk-tab"        onclick="switchTab('product', this)">🛍️ Buy a Product</button>
        </div>

        <!-- ── SERVICE TAB ──────────────────────────────────────────────── -->
        <div class="kiosk-panel active" id="tab-service">
            <form method="POST" id="serviceForm">
                <input type="hidden" name="walkin_order"   value="1">
                <input type="hidden" name="order_type"     value="service">
                <input type="hidden" name="item_id"        id="service_item_id" value="">
                <input type="hidden" name="payment_method" id="service_payment_method" value="cash">

                <div class="form-row">
                    <div class="form-group">
                        <label>Customer Name <span class="required">*</span></label>
                        <input type="text" name="customer_name" placeholder="Enter full name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number <span class="required">*</span></label>
                        <input type="tel" name="phone" placeholder="e.g. 09123456789" required>
                    </div>
                </div>

                <span class="section-label">Select Service <span class="required">*</span></span>
                <div class="item-select-grid" id="service-grid">
                    <?php foreach ($all_services as $svc): ?>
                        <div class="item-card"
                             onclick="selectItem('service', <?php echo $svc['id']; ?>, this)"
                             data-id="<?php echo $svc['id']; ?>">
                            <div class="item-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                            <div class="item-price">$<?php echo number_format($svc['price'], 2); ?></div>
                            <div class="item-meta">⏱ <?php echo $svc['session_time']; ?> mins</div>
                            <span class="slots-info">📅 <?php echo $svc['slots']; ?> slots/day</span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($all_services)): ?>
                        <p style="color:#999;">No services available.</p>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Booking Date & Time <span class="required">*</span></label>
                        <input type="datetime-local" name="booking_date" id="service_booking_date" required>
                    </div>
                    <div class="form-group">
                        <label>Number of People <span class="required">*</span></label>
                        <input type="number" name="people_count" value="1" min="1" required>
                    </div>
                </div>

                <!-- Payment Buttons -->
                <span class="section-label" style="margin-top:1rem;">
                    Choose Payment Method <span class="required">*</span>
                </span>
                <div class="payment-buttons">
                    <button type="submit"
                            class="pay-btn pay-btn-cash"
                            onclick="setPayment('service', 'cash')">
                        💵 Pay in Cash
                    </button>
                    <button type="button"
                            class="pay-btn pay-btn-online"
                            onclick="showPayMongoComingSoon()">
                        💳 Pay Online
                        <span class="coming-soon-badge">Coming Soon</span>
                    </button>
                </div>
                <p class="payment-note">
                    💵 <strong>Cash</strong> → Appointment set to <strong>Pending</strong> until payment is collected by staff.<br>
                    💳 <strong>Online</strong> → Will be <strong>Auto-approved</strong> after PayMongo payment succeeds.
                </p>
            </form>
        </div>

        <!-- ── PRODUCT TAB ──────────────────────────────────────────────── -->
        <div class="kiosk-panel" id="tab-product">
            <form method="POST" id="productForm">
                <input type="hidden" name="walkin_order"   value="1">
                <input type="hidden" name="order_type"     value="product">
                <input type="hidden" name="item_id"        id="product_item_id" value="">
                <input type="hidden" name="payment_method" id="product_payment_method" value="cash">

                <div class="form-row">
                    <div class="form-group">
                        <label>Customer Name <span class="required">*</span></label>
                        <input type="text" name="customer_name" placeholder="Enter full name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number <span class="required">*</span></label>
                        <input type="tel" name="phone" placeholder="e.g. 09123456789" required>
                    </div>
                </div>

                <span class="section-label">Select Product <span class="required">*</span></span>
                <div class="item-select-grid" id="product-grid">
                    <?php foreach ($all_products as $prd): ?>
                        <div class="item-card"
                             onclick="selectItem('product', <?php echo $prd['id']; ?>, this)"
                             data-id="<?php echo $prd['id']; ?>">
                            <div class="item-name"><?php echo htmlspecialchars($prd['name']); ?></div>
                            <div class="item-price">$<?php echo number_format($prd['price'], 2); ?></div>
                            <span class="slots-info">📦 <?php echo $prd['stock']; ?> in stock</span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($all_products)): ?>
                        <p style="color:#999;">No products available.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="max-width:200px;">
                    <label>Quantity <span class="required">*</span></label>
                    <input type="number" name="quantity" id="product_quantity" value="1" min="1" required>
                </div>

                <!-- Payment Buttons -->
                <span class="section-label" style="margin-top:1rem;">
                    Choose Payment Method <span class="required">*</span>
                </span>
                <div class="payment-buttons">
                    <button type="submit"
                            class="pay-btn pay-btn-cash"
                            onclick="setPayment('product', 'cash')">
                        💵 Pay in Cash
                    </button>
                    <button type="button"
                            class="pay-btn pay-btn-online"
                            onclick="showPayMongoComingSoon()">
                        💳 Pay Online
                        <span class="coming-soon-badge">Coming Soon</span>
                    </button>
                </div>
                <p class="payment-note">
                    💵 <strong>Cash</strong> → Order placed immediately, collect payment from customer.<br>
                    💳 <strong>Online</strong> → Will be confirmed after PayMongo payment succeeds.
                </p>
            </form>
        </div>

    </div><!-- end kiosk-body -->
</div><!-- end kiosk-wrapper -->
</div><!-- end container -->

<script>
// ─── TAB SWITCHING ────────────────────────────────────────────────────────────
function switchTab(type, el) {
    document.querySelectorAll('.kiosk-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.kiosk-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + type).classList.add('active');
    el.classList.add('active');
}

// ─── ITEM CARD SELECTION ──────────────────────────────────────────────────────
function selectItem(type, id, el) {
    el.closest('.item-select-grid').querySelectorAll('.item-card').forEach(c => {
        c.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById(type + '_item_id').value = id;

    if (type === 'product') {
        const stock = parseInt(el.querySelector('.slots-info').textContent.replace(/\D/g, ''));
        document.getElementById('product_quantity').max = stock;
    }
}

// ─── SET PAYMENT METHOD BEFORE SUBMIT ─────────────────────────────────────────
function setPayment(formType, method) {
    document.getElementById(formType + '_payment_method').value = method;
}

// ─── PAYMONGO COMING SOON ─────────────────────────────────────────────────────
function showPayMongoComingSoon() {
    alert('💳 Online payment via PayMongo is coming soon!\n\nPlease collect Cash payment for now.\nOnce integrated, successful payments will automatically approve the appointment.');
}

// ─── FORM VALIDATION ──────────────────────────────────────────────────────────
document.getElementById('serviceForm').addEventListener('submit', function(e) {
    if (!document.getElementById('service_item_id').value) {
        e.preventDefault();
        alert('Please select a service first.');
        return;
    }
});

document.getElementById('productForm').addEventListener('submit', function(e) {
    if (!document.getElementById('product_item_id').value) {
        e.preventDefault();
        alert('Please select a product first.');
        return;
    }
});

// ─── SET MIN DATE TO NOW ──────────────────────────────────────────────────────
const now     = new Date();
const pad     = n => String(n).padStart(2, '0');
const minDate = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
document.getElementById('service_booking_date').min = minDate;
</script>

</body>
</html>