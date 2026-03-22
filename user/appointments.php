<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

// ─── FILTER ───────────────────────────────────────────────────────────────────
$filter_status = isset($_GET['filter']) ? sanitize_input($_GET['filter']) : '';

// ─── SLOT HELPER ──────────────────────────────────────────────────────────────
function get_remaining_slots($conn, $service_id, $appointment_date) {
    $stmt = $conn->prepare("
        SELECT s.slots - IFNULL(SUM(a.people_count), 0) AS remaining
        FROM services s
        LEFT JOIN appointments a
            ON s.id = a.service_id
            AND DATE(a.appointment_date) = DATE(?)
            AND a.status IN ('pending','approved')
        WHERE s.id = ?
        GROUP BY s.id
    ");
    $stmt->bind_param("si", $appointment_date, $service_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['remaining'] ?? 0;
}

// ─── FETCH APPOINTMENTS ───────────────────────────────────────────────────────
$status_options = ['pending', 'approved', 'declined', 'completed'];
$appointments   = [];

if ($filter_status && in_array($filter_status, $status_options)) {
    $stmt = $conn->prepare("
        SELECT a.*, s.name AS service_name, s.price, s.session_time, s.image
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.user_id = ? AND a.status = ?
        ORDER BY a.appointment_date DESC
    ");
    $stmt->bind_param("is", $user_id, $filter_status);
} else {
    $stmt = $conn->prepare("
        SELECT a.*, s.name AS service_name, s.price, s.session_time, s.image
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.user_id = ?
        ORDER BY a.appointment_date DESC
    ");
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

// ─── APPOINTMENT STATS ────────────────────────────────────────────────────────
$stats = [];
foreach ($status_options as $status) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count FROM appointments WHERE user_id = ? AND status = ?
    ");
    $stmt->bind_param("is", $user_id, $status);
    $stmt->execute();
    $stats[$status] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// ─── SEPARATE UPCOMING / HISTORY ─────────────────────────────────────────────
$upcoming = [];
$history  = [];
$now      = new DateTime();

foreach ($appointments as $appt) {
    $appt_date = new DateTime($appt['appointment_date']);
    if ($appt['status'] === 'completed' || $appt_date < $now) {
        $history[] = $appt;
    } else {
        $upcoming[] = $appt;
    }
}

// ─── FETCH PRODUCT ORDERS ─────────────────────────────────────────────────────
$product_orders = [];
$stmt = $conn->prepare("
    SELECT
        o.id            AS order_id,
        o.created_at    AS order_date,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        oi.quantity,
        oi.price,
        oi.subtotal,
        p.name          AS product_name,
        p.image         AS product_image
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p     ON oi.product_id = p.id
    WHERE o.user_id = ?
    AND oi.product_id IS NOT NULL
    ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $product_orders[] = $row;
}
$stmt->close();

// Group product orders by order_id
$grouped_orders = [];
foreach ($product_orders as $row) {
    $oid = $row['order_id'];
    if (!isset($grouped_orders[$oid])) {
        $grouped_orders[$oid] = [
            'order_id'       => $oid,
            'order_date'     => $row['order_date'],
            'total_amount'   => $row['total_amount'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'items'          => [],
        ];
    }
    $grouped_orders[$oid]['items'][] = [
        'product_name'  => $row['product_name'],
        'product_image' => $row['product_image'],
        'quantity'      => $row['quantity'],
        'price'         => $row['price'],
        'subtotal'      => $row['subtotal'],
    ];
}

// ─── PAGE TITLE & HEADER ──────────────────────────────────────────────────────
$page_title = 'My Appointments & Orders';
require_once 'header.php';
?>

<style>
.tab-nav {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid #EAD8C0;
    padding-bottom: 0;
}
.tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    font-size: 1rem;
    font-weight: bold;
    color: #888;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}
.tab-btn.active { color: #C96A2C; border-bottom-color: #C96A2C; }
.tab-btn:hover:not(.active) { color: #3B2A1A; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

.appointment-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    border-left: 4px solid #C96A2C;
}
.order-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    border-left: 4px solid #0070f3;
}
.appointment-status, .payment-badge {
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: bold;
}
.status-pending   { background: #fff3cd; color: #664d03; }
.status-approved  { background: #d1e7dd; color: #0a3622; }
.status-declined  { background: #f8d7da; color: #842029; }
.status-completed { background: #cff4fc; color: #055160; }
.status-paid      { background: #d1e7dd; color: #0a3622; }
.status-unpaid    { background: #fff3cd; color: #664d03; }
.status-rejected  { background: #f8d7da; color: #842029; }
.method-onsite { background: #e2e3e5; color: #41464b; }
.method-online { background: #cfe2ff; color: #084298; }

.order-item-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #EAD8C0;
}
.order-item-row:last-child { border-bottom: none; }
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: #fff;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}
.stat-number { font-size: 1.8rem; font-weight: bold; color: #C96A2C; }
.stat-label  { font-size: 0.85rem; color: #888; margin-top: 0.3rem; }
.filter-bar  { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.section-title { color: #3B2A1A; margin: 1.5rem 0 1rem 0; font-size: 1.2rem; }
</style>

<div class="container">
<h1 style="color:#3B2A1A; margin:2rem 0;">My Appointments & Orders</h1>

<!-- ── TAB NAVIGATION ──────────────────────────────────────────────────── -->
<div class="tab-nav">
    <button class="tab-btn active" onclick="switchTab('appointments', this)">
        📅 Appointments
        <span style="background:#C96A2C; color:#fff; border-radius:20px;
                     padding:0.1rem 0.5rem; font-size:0.75rem; margin-left:0.3rem;">
            <?php echo count($appointments); ?>
        </span>
    </button>
    <button class="tab-btn" onclick="switchTab('orders', this)">
        🛍️ Product Orders
        <span style="background:#0070f3; color:#fff; border-radius:20px;
                     padding:0.1rem 0.5rem; font-size:0.75rem; margin-left:0.3rem;">
            <?php echo count($grouped_orders); ?>
        </span>
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: APPOINTMENTS                                                    -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="tab-appointments">

    <!-- Stats -->
    <div class="stats-grid">
        <?php foreach ($status_options as $status): ?>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats[$status]; ?></div>
            <div class="stat-label"><?php echo ucfirst($status); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
        <a href="appointments.php"
           class="btn <?php echo !$filter_status ? 'btn-primary' : 'btn-secondary'; ?>">
            All
        </a>
        <?php foreach ($status_options as $status): ?>
        <a href="appointments.php?filter=<?php echo $status; ?>"
           class="btn <?php echo $filter_status === $status ? 'btn-primary' : 'btn-secondary'; ?>">
            <?php echo ucfirst($status); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Upcoming -->
    <?php if (!empty($upcoming)): ?>
    <h2 class="section-title">📌 Upcoming Appointments</h2>
    <?php foreach ($upcoming as $appt):
        $remaining = get_remaining_slots($conn, $appt['service_id'], $appt['appointment_date']);
    ?>
    <div class="appointment-card">
        <div style="display:flex; gap:1.5rem; align-items:flex-start;">
            <img src="../uploads/services/<?php echo htmlspecialchars($appt['image']); ?>"
                 alt="<?php echo htmlspecialchars($appt['service_name']); ?>"
                 style="width:110px; height:110px; object-fit:cover; border-radius:10px; flex-shrink:0;">
            <div style="flex:1;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem;">
                    <h3 style="color:#3B2A1A; margin:0;"><?php echo htmlspecialchars($appt['service_name']); ?></h3>
                    <span class="appointment-status status-<?php echo $appt['status']; ?>">
                        <?php echo ucfirst($appt['status']); ?>
                    </span>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.3rem 2rem; font-size:0.92rem; color:#444;">
                    <p><strong>📅 Date & Time:</strong><br><?php echo date('F d, Y - h:i A', strtotime($appt['appointment_date'])); ?></p>
                    <p><strong>⏱ Duration:</strong><br><?php echo $appt['session_time']; ?> minutes</p>
                    <p><strong>💰 Price:</strong><br>$<?php echo number_format($appt['price'], 2); ?></p>
                    <p><strong>👥 People:</strong><br><?php echo $appt['people_count']; ?></p>
                    <p><strong>📆 Booked on:</strong><br><?php echo date('F d, Y', strtotime($appt['created_at'])); ?></p>
                    <p><strong>🪑 Remaining Slots:</strong><br>
                        <span style="color:<?php echo $remaining > 0 ? '#198754' : '#dc3545'; ?>; font-weight:bold;">
                            <?php echo $remaining; ?> slot(s) left
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <h2 class="section-title">🕐 Appointment History</h2>
    <?php foreach ($history as $appt): ?>
    <div class="appointment-card" style="opacity:0.85; border-left-color:#adb5bd;">
        <div style="display:flex; gap:1.5rem; align-items:flex-start;">
            <img src="../uploads/services/<?php echo htmlspecialchars($appt['image']); ?>"
                 alt="<?php echo htmlspecialchars($appt['service_name']); ?>"
                 style="width:110px; height:110px; object-fit:cover; border-radius:10px; flex-shrink:0; filter:grayscale(20%);">
            <div style="flex:1;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem;">
                    <h3 style="color:#3B2A1A; margin:0;"><?php echo htmlspecialchars($appt['service_name']); ?></h3>
                    <span class="appointment-status status-<?php echo $appt['status']; ?>">
                        <?php echo ucfirst($appt['status']); ?>
                    </span>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.3rem 2rem; font-size:0.92rem; color:#444;">
                    <p><strong>📅 Date & Time:</strong><br><?php echo date('F d, Y - h:i A', strtotime($appt['appointment_date'])); ?></p>
                    <p><strong>⏱ Duration:</strong><br><?php echo $appt['session_time']; ?> minutes</p>
                    <p><strong>💰 Price:</strong><br>$<?php echo number_format($appt['price'], 2); ?></p>
                    <p><strong>👥 People:</strong><br><?php echo $appt['people_count']; ?></p>
                    <p><strong>📆 Booked on:</strong><br><?php echo date('F d, Y', strtotime($appt['created_at'])); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Empty State -->
    <?php if (empty($appointments)): ?>
    <div style="text-align:center; padding:3rem; background:#fff; border-radius:10px;">
        <div style="font-size:3rem; margin-bottom:1rem;">📅</div>
        <h2 style="color:#3B2A1A; margin-bottom:0.5rem;">No Appointments Yet</h2>
        <p style="color:#666; margin-bottom:1.5rem;">You haven't booked any services yet.</p>
        <a href="index.php#services" class="btn btn-primary">Browse Services</a>
    </div>
    <?php endif; ?>

</div><!-- end tab-appointments -->

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: PRODUCT ORDERS                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-orders">

    <?php if (!empty($grouped_orders)): ?>

        <!-- Order Stats -->
        <?php
        $paid_count   = 0;
        $unpaid_count = 0;
        $total_spent  = 0;
        foreach ($grouped_orders as $o) {
            if (($o['payment_status'] ?? 'unpaid') === 'paid') $paid_count++;
            else $unpaid_count++;
            $total_spent += $o['total_amount'];
        }
        ?>
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(140px,1fr));">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($grouped_orders); ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#198754;"><?php echo $paid_count; ?></div>
                <div class="stat-label">✅ Paid</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#664d03;"><?php echo $unpaid_count; ?></div>
                <div class="stat-label">⏳ Unpaid</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="font-size:1.3rem;">$<?php echo number_format($total_spent, 2); ?></div>
                <div class="stat-label">💰 Total Spent</div>
            </div>
        </div>

        <?php foreach ($grouped_orders as $order): ?>
        <div class="order-card">

            <!-- Order Header -->
            <div style="display:flex; justify-content:space-between; align-items:center;
                        flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;
                        padding-bottom:0.75rem; border-bottom:1px solid #EAD8C0;">
                <div>
                    <strong style="color:#3B2A1A; font-size:1rem;">
                        Order #<?php echo $order['order_id']; ?>
                    </strong>
                    <span style="color:#888; font-size:0.85rem; margin-left:0.75rem;">
                        <?php echo date('F d, Y h:i A', strtotime($order['order_date'])); ?>
                    </span>
                </div>
                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                    <!-- Payment Method -->
                    <?php $method = $order['payment_method'] ?? 'onsite'; ?>
                    <span class="payment-badge method-<?php echo $method; ?>">
                        <?php echo $method === 'online' ? '💳 Online' : '🏪 Onsite'; ?>
                    </span>
                    <!-- Payment Status -->
                    <?php $pstatus = $order['payment_status'] ?? 'unpaid'; ?>
                    <span class="payment-badge status-<?php echo $pstatus; ?>">
                        <?php
                        if ($pstatus === 'paid')     echo '✅ Paid';
                        elseif ($pstatus === 'rejected') echo '❌ Rejected';
                        else echo '⏳ Awaiting Payment';
                        ?>
                    </span>
                </div>
            </div>

            <!-- Order Items -->
            <?php foreach ($order['items'] as $item): ?>
            <div class="order-item-row">
                <img src="../uploads/products/<?php echo htmlspecialchars($item['product_image']); ?>"
                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                     style="width:70px; height:70px; object-fit:cover; border-radius:8px; flex-shrink:0;">
                <div style="flex:1;">
                    <div style="font-weight:bold; color:#3B2A1A; margin-bottom:0.2rem;">
                        <?php echo htmlspecialchars($item['product_name']); ?>
                    </div>
                    <div style="font-size:0.88rem; color:#666;">
                        $<?php echo number_format($item['price'], 2); ?> × <?php echo $item['quantity']; ?>
                    </div>
                </div>
                <div style="font-weight:bold; color:#C96A2C; white-space:nowrap;">
                    $<?php echo number_format($item['subtotal'], 2); ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Order Footer -->
            <div style="display:flex; justify-content:flex-end; align-items:center;
                        margin-top:1rem; padding-top:0.75rem; border-top:1px solid #EAD8C0;">
                <div style="text-align:right;">
                    <div style="font-size:0.85rem; color:#888;">Order Total</div>
                    <div style="font-size:1.2rem; font-weight:bold; color:#C96A2C;">
                        $<?php echo number_format($order['total_amount'], 2); ?>
                    </div>
                </div>
            </div>

            <!-- Unpaid Notice -->
            <?php if (($order['payment_status'] ?? 'unpaid') === 'unpaid'): ?>
            <div style="margin-top:1rem; padding:0.75rem 1rem; background:#fff3cd;
                        color:#664d03; border-radius:8px; font-size:0.88rem;
                        border-left:4px solid #ffc107;">
                ⏳ <strong>Payment Pending</strong> — Please pay at the spa counter.
                Your order will be confirmed once payment is received.
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>

    <?php else: ?>
    <div style="text-align:center; padding:3rem; background:#fff; border-radius:10px;">
        <div style="font-size:3rem; margin-bottom:1rem;">🛍️</div>
        <h2 style="color:#3B2A1A; margin-bottom:0.5rem;">No Product Orders Yet</h2>
        <p style="color:#666; margin-bottom:1.5rem;">You haven't purchased any products yet.</p>
        <a href="index.php#products" class="btn btn-primary">Browse Products</a>
    </div>
    <?php endif; ?>

</div><!-- end tab-orders -->

</div><!-- end container -->

<script>
function switchTab(tab, el) {
    // Hide all panels
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    // Show selected
    document.getElementById('tab-' + tab).classList.add('active');
    el.classList.add('active');
}

// Auto-switch to orders tab if URL has #orders
if (window.location.hash === '#orders') {
    switchTab('orders', document.querySelectorAll('.tab-btn')[1]);
}
</script>

</body>
</html>