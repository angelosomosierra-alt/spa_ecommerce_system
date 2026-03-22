<?php
require_once '../config.php';
redirect_if_not_admin();

// ─── APPROVE PAYMENT (mark as paid) ──────────────────────────────────────────
if (isset($_GET['approve_payment'])) {
    $order_id = intval($_GET['approve_payment']);
    $stmt = $conn->prepare("
        UPDATE orders SET payment_status = 'paid' WHERE id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
    header("Location: orders.php?success=Payment approved successfully.");
    exit();
}

// ─── REJECT ORDER ─────────────────────────────────────────────────────────────
if (isset($_GET['reject_order'])) {
    $order_id = intval($_GET['reject_order']);
    $stmt = $conn->prepare("
        UPDATE orders SET payment_status = 'rejected' WHERE id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
    header("Location: orders.php?success=Order rejected.");
    exit();
}

// ─── FETCH PRODUCT ORDERS ONLY ────────────────────────────────────────────────
$orders = [];
$result = $conn->query("
    SELECT DISTINCT o.*
    FROM orders o
    INNER JOIN order_items oi ON o.id = oi.order_id
    WHERE oi.product_id IS NOT NULL
    ORDER BY o.created_at DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
}

// ─── VIEW ORDER DETAILS ───────────────────────────────────────────────────────
$view_order = null;
if (isset($_GET['view'])) {
    $id = intval($_GET['view']);

    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $view_order = $result->fetch_assoc();

        $items_stmt = $conn->prepare("
            SELECT oi.*, p.name AS item_name, p.image AS item_image
            FROM order_items oi
            INNER JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ? AND oi.product_id IS NOT NULL
        ");
        $items_stmt->bind_param("i", $id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        $view_order['items'] = [];
        while ($item = $items_result->fetch_assoc()) {
            $view_order['items'][] = $item;
        }
        $items_stmt->close();
    }
    $stmt->close();
}

// ─── ORDER STATISTICS ─────────────────────────────────────────────────────────
$total_orders   = count($orders);
$total_revenue  = 0;
$pending_count  = 0;
$paid_count     = 0;
$rejected_count = 0;

foreach ($orders as $order) {
    $total_revenue += $order['total_amount'];
    $status = $order['payment_status'] ?? 'unpaid';
    if ($status === 'unpaid')    $pending_count++;
    if ($status === 'paid')      $paid_count++;
    if ($status === 'rejected')  $rejected_count++;
}

// ─── HELPER FUNCTIONS ─────────────────────────────────────────────────────────
function payment_status_badge($status) {
    switch ($status) {
        case 'paid':
            return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#d1e7dd; color:#0a3622;">✅ Paid</span>';
        case 'unpaid':
            return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#fff3cd; color:#664d03;">⏳ Unpaid</span>';
        case 'rejected':
            return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#f8d7da; color:#842029;">❌ Rejected</span>';
        default:
            return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#e2e3e5; color:#41464b;">' . htmlspecialchars($status) . '</span>';
    }
}

function payment_method_badge($method) {
    if ($method === 'online') {
        return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#cfe2ff; color:#084298;">💳 Online</span>';
    }
    return '<span style="padding:0.3rem 0.8rem; border-radius:20px; font-size:0.8rem; font-weight:bold; background:#e2e3e5; color:#41464b;">🏪 Onsite</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        table { width: 100%; border-collapse: collapse; }
        table th, table td { padding: 0.8rem; text-align: left; border-bottom: 1px solid #EAD8C0; }
        table thead tr { background-color: #f9f1e7; }
        .filter-tabs { display:flex; gap:0.5rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .filter-tab {
            padding:0.5rem 1.2rem; border-radius:20px; cursor:pointer;
            border:2px solid #EAD8C0; background:#FAF3E8;
            color:#3B2A1A; font-weight:bold; font-size:0.9rem;
            transition:all 0.2s; text-decoration:none;
        }
        .filter-tab:hover  { background:#EAD8C0; }
        .filter-tab.active { background:#C96A2C; color:#fff; border-color:#C96A2C; }
        .action-btn {
            padding:0.35rem 0.75rem; border-radius:6px;
            font-size:0.82rem; font-weight:bold;
            border:none; cursor:pointer; text-decoration:none;
            display:inline-block; margin:0.1rem;
        }
        .btn-approve { background:#198754; color:#fff; }
        .btn-approve:hover { background:#146c43; }
        .btn-reject  { background:#dc3545; color:#fff; }
        .btn-reject:hover  { background:#b02a37; }
        .btn-view    { background:#0dcaf0; color:#000; }
        .btn-view:hover    { background:#31d2f2; }
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
            <li><a href="orders.php" class="active">Orders</a></li>
        </ul>
        <div class="auth-links">
            <a href="../user/auth.php?logout=1">Logout</a>
        </div>
    </nav>
</header>

<div class="container">
<div class="admin-container">

    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <ul class="admin-menu">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php" class="active">Orders</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="admin-content">
        <div class="admin-header">
            <h2><?php echo $view_order ? 'Order Details #' . $view_order['id'] : 'Product Orders'; ?></h2>
            <?php if ($view_order): ?>
                <a href="orders.php" class="btn btn-secondary">← Back to Orders</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div style="padding:1rem; background:#d1e7dd; color:#0a3622; border-radius:8px;
                        border-left:5px solid #198754; margin-bottom:1.5rem; font-weight:bold;">
                ✅ <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <?php if ($view_order): ?>
        <!-- ─── ORDER DETAIL VIEW ──────────────────────────────────────────── -->
        <div style="background:#fff; padding:2rem; border-radius:10px; margin-bottom:2rem;">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:2rem; margin-bottom:2rem;">

                <!-- Customer Info -->
                <div>
                    <h3 style="color:#3B2A1A; margin-bottom:1rem;">Customer Information</h3>
                    <table style="width:100%;">
                        <tr><td><strong>Name</strong></td><td><?php echo htmlspecialchars($view_order['customer_name']); ?></td></tr>
                        <tr><td><strong>Email</strong></td><td><?php echo htmlspecialchars($view_order['email']); ?></td></tr>
                        <tr><td><strong>Phone</strong></td><td><?php echo htmlspecialchars($view_order['phone']); ?></td></tr>
                        <tr><td><strong>Address</strong></td><td><?php echo htmlspecialchars($view_order['address']); ?></td></tr>
                    </table>
                </div>

                <!-- Order Info -->
                <div>
                    <h3 style="color:#3B2A1A; margin-bottom:1rem;">Order Information</h3>
                    <table style="width:100%;">
                        <tr>
                            <td><strong>Order ID</strong></td>
                            <td><strong>#<?php echo $view_order['id']; ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Order Date</strong></td>
                            <td><?php echo date('M d, Y h:i A', strtotime($view_order['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Total Amount</strong></td>
                            <td style="color:#C96A2C; font-weight:bold; font-size:1.1rem;">
                                $<?php echo number_format($view_order['total_amount'], 2); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Payment Method</strong></td>
                            <td><?php echo payment_method_badge($view_order['payment_method'] ?? 'onsite'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Payment Status</strong></td>
                            <td><?php echo payment_status_badge($view_order['payment_status'] ?? 'unpaid'); ?></td>
                        </tr>
                    </table>

                    <!-- Action buttons on detail view -->
                    <?php $pstatus = $view_order['payment_status'] ?? 'unpaid'; ?>
                    <?php if ($pstatus === 'unpaid'): ?>
                        <div style="margin-top:1.5rem; display:flex; gap:0.75rem;">
                            <a href="orders.php?approve_payment=<?php echo $view_order['id']; ?>&view=<?php echo $view_order['id']; ?>"
                               class="action-btn btn-approve"
                               onclick="return confirm('Mark this order as Paid?')">
                                ✅ Mark as Paid
                            </a>
                            <a href="orders.php?reject_order=<?php echo $view_order['id']; ?>"
                               class="action-btn btn-reject"
                               onclick="return confirm('Reject this order?')">
                                ❌ Reject Order
                            </a>
                        </div>
                    <?php elseif ($pstatus === 'paid'): ?>
                        <div style="margin-top:1.5rem; padding:0.75rem 1rem; background:#d1e7dd;
                                    color:#0a3622; border-radius:8px; font-weight:bold;">
                            ✅ Payment has been confirmed.
                        </div>
                    <?php elseif ($pstatus === 'rejected'): ?>
                        <div style="margin-top:1.5rem; padding:0.75rem 1rem; background:#f8d7da;
                                    color:#842029; border-radius:8px; font-weight:bold;">
                            ❌ This order has been rejected.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="margin:2rem 0; border:none; border-top:2px solid #EAD8C0;">

            <!-- Order Items -->
            <h3 style="color:#3B2A1A; margin-bottom:1rem;">Ordered Products</h3>
            <?php if (!empty($view_order['items'])): ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($view_order['items'] as $item): ?>
                    <tr>
                        <td style="display:flex; align-items:center; gap:1rem;">
                            <img src="../uploads/products/<?php echo htmlspecialchars($item['item_image']); ?>"
                                 alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                 style="width:50px; height:50px; object-fit:cover; border-radius:5px;">
                            <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                        </td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td>$<?php echo number_format($item['price'], 2); ?></td>
                        <td><strong>$<?php echo number_format($item['subtotal'], 2); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right; padding-top:1rem;"><strong>Total:</strong></td>
                        <td style="padding-top:1rem; color:#C96A2C; font-weight:bold; font-size:1.1rem;">
                            $<?php echo number_format($view_order['total_amount'], 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php else: ?>
                <p style="color:#666;">No product items found for this order.</p>
            <?php endif; ?>

        </div>

        <?php else: ?>
        <!-- ─── ORDERS LIST VIEW ───────────────────────────────────────────── -->

        <!-- Statistics -->
        <div class="stats-grid" style="margin-bottom:2rem;">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_orders; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#664d03;"><?php echo $pending_count; ?></div>
                <div class="stat-label">Unpaid Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#0a3622;"><?php echo $paid_count; ?></div>
                <div class="stat-label">Paid Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#842029;"><?php echo $rejected_count; ?></div>
                <div class="stat-label">Rejected Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">$<?php echo number_format($total_revenue, 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <?php $filter = $_GET['filter'] ?? 'all'; ?>
        <div class="filter-tabs">
            <a href="orders.php?filter=all"      class="filter-tab <?php echo $filter === 'all'      ? 'active' : ''; ?>">All Orders</a>
            <a href="orders.php?filter=unpaid"   class="filter-tab <?php echo $filter === 'unpaid'   ? 'active' : ''; ?>">⏳ Unpaid</a>
            <a href="orders.php?filter=paid"     class="filter-tab <?php echo $filter === 'paid'     ? 'active' : ''; ?>">✅ Paid</a>
            <a href="orders.php?filter=rejected" class="filter-tab <?php echo $filter === 'rejected' ? 'active' : ''; ?>">❌ Rejected</a>
            <a href="orders.php?filter=onsite"   class="filter-tab <?php echo $filter === 'onsite'   ? 'active' : ''; ?>">🏪 Onsite</a>
            <a href="orders.php?filter=online"   class="filter-tab <?php echo $filter === 'online'   ? 'active' : ''; ?>">💳 Online</a>
        </div>

        <!-- Orders Table -->
        <div style="overflow-x:auto; background:#fff; border-radius:10px; padding:1.5rem;">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment Method</th>
                        <th>Payment Status</th>
                        <th>Order Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $has_rows = false;
                    foreach ($orders as $order):
                        $pstatus = $order['payment_status'] ?? 'unpaid';
                        $pmethod = $order['payment_method'] ?? 'onsite';

                        // Apply filter
                        if ($filter === 'unpaid'   && $pstatus !== 'unpaid')   continue;
                        if ($filter === 'paid'     && $pstatus !== 'paid')     continue;
                        if ($filter === 'rejected' && $pstatus !== 'rejected') continue;
                        if ($filter === 'onsite'   && $pmethod !== 'onsite')   continue;
                        if ($filter === 'online'   && $pmethod !== 'online')   continue;

                        $has_rows = true;

                        // Count product items
                        $count_stmt = $conn->prepare("
                            SELECT COUNT(*) as cnt FROM order_items
                            WHERE order_id = ? AND product_id IS NOT NULL
                        ");
                        $count_stmt->bind_param("i", $order['id']);
                        $count_stmt->execute();
                        $item_count = $count_stmt->get_result()->fetch_assoc()['cnt'];
                        $count_stmt->close();
                    ?>
                    <tr>
                        <td><strong>#<?php echo $order['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($order['phone']); ?></td>
                        <td>
                            <span style="padding:0.3rem 0.7rem; border-radius:20px; font-size:0.8rem;
                                         font-weight:bold; background:#d4edda; color:#155724;">
                                <?php echo $item_count; ?> product<?php echo $item_count != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td><strong style="color:#C96A2C;">$<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                        <td><?php echo payment_method_badge($pmethod); ?></td>
                        <td><?php echo payment_status_badge($pstatus); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></td>
                        <td>
                            <a href="orders.php?view=<?php echo $order['id']; ?>"
                               class="action-btn btn-view">View</a>

                            <?php if ($pstatus === 'unpaid'): ?>
                                <a href="orders.php?approve_payment=<?php echo $order['id']; ?>"
                                   class="action-btn btn-approve"
                                   onclick="return confirm('Mark Order #<?php echo $order['id']; ?> as Paid?')">
                                    ✅ Paid
                                </a>
                                <a href="orders.php?reject_order=<?php echo $order['id']; ?>"
                                   class="action-btn btn-reject"
                                   onclick="return confirm('Reject Order #<?php echo $order['id']; ?>?')">
                                    ❌ Reject
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (!$has_rows): ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:2rem; color:#666;">
                                No orders found for this filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </main>
</div>
</div>

</body>
</html>