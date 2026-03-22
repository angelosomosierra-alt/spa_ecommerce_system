<?php
require_once '../config.php';
require_once 'admin_header.php';
if (isset($_GET['logout'])) { logout(); }
redirect_if_not_admin();

$total_services  = $conn->query("SELECT COUNT(*) as c FROM services")->fetch_assoc()['c'];
$total_products  = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
$total_orders    = $conn->query("SELECT COUNT(*) as c FROM orders")->fetch_assoc()['c'];
$total_revenue   = $conn->query("SELECT IFNULL(SUM(total_amount),0) as t FROM orders WHERE payment_status='paid'")->fetch_assoc()['t'];
$pending_appts   = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='pending'")->fetch_assoc()['c'];
$pending_orders  = $conn->query("SELECT COUNT(*) as c FROM orders WHERE payment_status='unpaid'")->fetch_assoc()['c'];
$total_customers = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='user' AND username!='walkin_customer'")->fetch_assoc()['c'];
$low_stock       = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock <= 5 AND stock > 0")->fetch_assoc()['c'];

$recent_orders = [];
$result = $conn->query("SELECT o.*, COUNT(oi.id) as item_count FROM orders o LEFT JOIN order_items oi ON o.id=oi.order_id GROUP BY o.id ORDER BY o.created_at DESC LIMIT 6");
while ($row = $result->fetch_assoc()) $recent_orders[] = $row;

$recent_appts = [];
$result = $conn->query("SELECT a.*, u.full_name, s.name as service_name FROM appointments a JOIN users u ON a.user_id=u.id JOIN services s ON a.service_id=s.id ORDER BY a.created_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $recent_appts[] = $row;

$page_title = 'Dashboard'; $page_icon = '🏠'; $active_page = 'index';
require_once 'admin_header.php';
?>
<link rel="stylesheet" href="admin.css?v=<?php echo time(); ?>">
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-number">₱<?php echo number_format($total_revenue,0); ?></div><div class="stat-label">Total Revenue</div></div>
    <div class="stat-card blue"><div class="stat-icon">📦</div><div class="stat-number"><?php echo $total_orders; ?></div><div class="stat-label">Total Orders</div></div>
    <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-number"><?php echo $total_customers; ?></div><div class="stat-label">Customers</div></div>
    <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-number"><?php echo $pending_orders; ?></div><div class="stat-label">Pending Payments</div></div>
    <div class="stat-card rust"><div class="stat-icon">💆</div><div class="stat-number"><?php echo $total_services; ?></div><div class="stat-label">Services</div></div>
    <div class="stat-card"><div class="stat-icon">🛍️</div><div class="stat-number"><?php echo $total_products; ?></div><div class="stat-label">Products</div></div>
    <div class="stat-card amber"><div class="stat-icon">📅</div><div class="stat-number"><?php echo $pending_appts; ?></div><div class="stat-label">Pending Appts</div></div>
    <div class="stat-card red"><div class="stat-icon">⚠️</div><div class="stat-number"><?php echo $low_stock; ?></div><div class="stat-label">Low Stock</div></div>
</div>

<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header"><span class="panel-title">⚡ Quick Actions</span></div>
    <div class="panel-body">
        <div class="quick-actions-grid">
            <a href="walkin.php"              class="btn btn-primary btn-lg">🏪 Walk-in Kiosk</a>
            <a href="services.php?action=add" class="btn btn-secondary btn-lg">➕ Add Service</a>
            <a href="products.php?action=add" class="btn btn-secondary btn-lg">➕ Add Product</a>
            <a href="appointments.php"        class="btn btn-secondary btn-lg">📅 Appointments</a>
            <a href="orders.php"              class="btn btn-secondary btn-lg">📦 Orders</a>
            <a href="analytics.php"           class="btn btn-secondary btn-lg">📊 Analytics</a>
            <a href="categories.php"          class="btn btn-secondary btn-lg">🏷️ Categories</a>
            <a href="users.php"               class="btn btn-secondary btn-lg">👥 Users</a>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="panel">
        <div class="panel-header"><span class="panel-title">📦 Recent Orders</span><a href="orders.php" class="btn btn-secondary btn-sm">View All</a></div>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_orders as $o): $ps = $o['payment_status'] ?? 'unpaid'; ?>
                    <tr>
                        <td><strong style="color:var(--gold);">#<?php echo $o['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                        <td style="color:var(--rust);font-weight:600;">₱<?php echo number_format($o['total_amount'],2); ?></td>
                        <td><span class="badge badge-<?php echo $ps; ?>"><?php echo ucfirst($ps); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_orders)): ?><tr><td colspan="4" style="text-align:center;color:var(--gray);padding:2rem;">No orders yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">📅 Recent Appointments</span><a href="appointments.php" class="btn btn-secondary btn-sm">View All</a></div>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead><tr><th>Customer</th><th>Service</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_appts as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['full_name']); ?></td>
                        <td style="color:var(--cream3);"><?php echo htmlspecialchars($a['service_name']); ?></td>
                        <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, h:i A', strtotime($a['appointment_date'])); ?></td>
                        <td><span class="badge badge-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_appts)): ?><tr><td colspan="4" style="text-align:center;color:var(--gray);padding:2rem;">No appointments yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>