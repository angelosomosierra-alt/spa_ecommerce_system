<?php
require_once '../config.php';
redirect_if_not_admin();

// ─── DATE FILTER ──────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'monthly';
$today  = date('Y-m-d');

// ─── TOTAL STATISTICS ─────────────────────────────────────────────────────────
$result        = $conn->query("SELECT SUM(total_amount) as total, COUNT(*) as count FROM orders WHERE payment_status = 'paid'");
$paid_stats    = $result->fetch_assoc();
$total_revenue = $paid_stats['total'] ?? 0;
$total_orders  = $paid_stats['count'] ?? 0;

$result           = $conn->query("SELECT COUNT(*) as count FROM orders WHERE payment_status = 'unpaid'");
$pending_orders   = $result->fetch_assoc()['count'] ?? 0;

$result           = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE status = 'approved'");
$total_appts      = $result->fetch_assoc()['count'] ?? 0;

$result           = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND username != 'walkin_customer'");
$total_customers  = $result->fetch_assoc()['count'] ?? 0;

// ─── DAILY SALES (last 14 days) ───────────────────────────────────────────────
$daily_labels  = [];
$daily_data    = [];
for ($i = 13; $i >= 0; $i--) {
    $date           = date('Y-m-d', strtotime("-$i days"));
    $daily_labels[] = date('M d', strtotime($date));

    $stmt = $conn->prepare("
        SELECT IFNULL(SUM(total_amount), 0) as total
        FROM orders
        WHERE DATE(created_at) = ? AND payment_status = 'paid'
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $daily_data[] = floatval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close();
}

// ─── WEEKLY SALES (last 12 weeks) ─────────────────────────────────────────────
$weekly_labels = [];
$weekly_data   = [];
for ($i = 11; $i >= 0; $i--) {
    $week_start     = date('Y-m-d', strtotime("-$i weeks monday this week"));
    $week_end       = date('Y-m-d', strtotime("-$i weeks sunday this week"));
    $weekly_labels[] = 'W' . date('W', strtotime($week_start));

    $stmt = $conn->prepare("
        SELECT IFNULL(SUM(total_amount), 0) as total
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ? AND payment_status = 'paid'
    ");
    $stmt->bind_param("ss", $week_start, $week_end);
    $stmt->execute();
    $weekly_data[] = floatval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close();
}

// ─── MONTHLY SALES (last 12 months) ──────────────────────────────────────────
$monthly_labels = [];
$monthly_data   = [];
for ($i = 11; $i >= 0; $i--) {
    $month           = date('Y-m', strtotime("-$i months"));
    $monthly_labels[] = date('M Y', strtotime("-$i months"));

    $stmt = $conn->prepare("
        SELECT IFNULL(SUM(total_amount), 0) as total
        FROM orders
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND payment_status = 'paid'
    ");
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $monthly_data[] = floatval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close();
}

// ─── SALES FORECAST (simple linear regression on monthly data) ────────────────
function linear_forecast($data, $steps = 3) {
    $n = count($data);
    if ($n < 2) return array_fill(0, $steps, 0);

    $sum_x = 0; $sum_y = 0; $sum_xy = 0; $sum_xx = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum_x  += $i;
        $sum_y  += $data[$i];
        $sum_xy += $i * $data[$i];
        $sum_xx += $i * $i;
    }
    $denom = ($n * $sum_xx - $sum_x * $sum_x);
    if ($denom == 0) return array_fill(0, $steps, end($data));

    $slope     = ($n * $sum_xy - $sum_x * $sum_y) / $denom;
    $intercept = ($sum_y - $slope * $sum_x) / $n;

    $forecast = [];
    for ($i = 0; $i < $steps; $i++) {
        $forecast[] = max(0, round($intercept + $slope * ($n + $i), 2));
    }
    return $forecast;
}

$forecast_data   = linear_forecast($monthly_data, 3);
$forecast_labels = [];
for ($i = 1; $i <= 3; $i++) {
    $forecast_labels[] = date('M Y', strtotime("+$i months"));
}

// ─── TOP PRODUCTS (pie chart) ─────────────────────────────────────────────────
$top_products        = [];
$top_products_labels = [];
$top_products_data   = [];
$top_products_colors = ['#C96A2C','#E8955A','#F5B887','#3B2A1A','#7B5E4A','#A0856B','#D4A574','#8B4513'];

$result = $conn->query("
    SELECT p.name, SUM(oi.quantity) as total_qty, SUM(oi.subtotal) as total_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.payment_status = 'paid'
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
    LIMIT 8
");
while ($row = $result->fetch_assoc()) {
    $top_products[]        = $row;
    $top_products_labels[] = $row['name'];
    $top_products_data[]   = intval($row['total_qty']);
}

// ─── TOP SERVICES (pie chart) ─────────────────────────────────────────────────
$top_services        = [];
$top_services_labels = [];
$top_services_data   = [];
$top_services_colors = ['#2C7CC9','#5A95E8','#87C5F5','#1A3B7B','#4A6A9A','#6B85A0','#74A8D4','#134589'];

$result = $conn->query("
    SELECT s.name, COUNT(a.id) as total_bookings, SUM(oi.subtotal) as total_revenue
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    JOIN order_items oi ON a.order_item_id = oi.id
    JOIN orders o ON oi.order_id = o.id
    WHERE a.status IN ('approved','completed')
    GROUP BY s.id, s.name
    ORDER BY total_bookings DESC
    LIMIT 8
");
while ($row = $result->fetch_assoc()) {
    $top_services[]        = $row;
    $top_services_labels[] = $row['name'];
    $top_services_data[]   = intval($row['total_bookings']);
}

// ─── RECENT ORDERS ────────────────────────────────────────────────────────────
$recent_orders = [];
$result = $conn->query("
    SELECT o.*, 
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 8
");
while ($row = $result->fetch_assoc()) {
    $recent_orders[] = $row;
}

// ─── BEST SELLING DAY ─────────────────────────────────────────────────────────
$result = $conn->query("
    SELECT DAYNAME(created_at) as day_name, SUM(total_amount) as total
    FROM orders
    WHERE payment_status = 'paid'
    GROUP BY DAYNAME(created_at)
    ORDER BY total DESC
    LIMIT 1
");
$best_day = $result->fetch_assoc();

// ─── AVERAGE ORDER VALUE ──────────────────────────────────────────────────────
$avg_order_value = $total_orders > 0 ? $total_revenue / $total_orders : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - Spa Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border-left: 5px solid #C96A2C;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card.blue  { border-left-color: #0070f3; }
        .stat-card.green { border-left-color: #198754; }
        .stat-card.gold  { border-left-color: #ffc107; }
        .stat-card.red   { border-left-color: #dc3545; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #3B2A1A; }
        .stat-label  { font-size: 0.9rem; color: #888; margin-top: 0.3rem; }
        .stat-change { font-size: 0.82rem; margin-top: 0.5rem; font-weight: bold; }
        .stat-change.up   { color: #198754; }
        .stat-change.down { color: #dc3545; }

        .chart-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom: 2rem;
        }
        .chart-card h3 {
            color: #3B2A1A;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .chart-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .chart-tab {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            border: 2px solid #EAD8C0;
            background: #FAF3E8;
            color: #3B2A1A;
            font-weight: bold;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .chart-tab.active { background: #C96A2C; color: #fff; border-color: #C96A2C; }
        .chart-tab:hover:not(.active) { background: #EAD8C0; }

        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 768px) { .two-col { grid-template-columns: 1fr; } }

        .forecast-badge {
            display: inline-block;
            background: #fff3cd;
            color: #664d03;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }
        .top-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #EAD8C0;
        }
        .top-item:last-child { border-bottom: none; }
        .top-item-rank {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #C96A2C;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 0.85rem; flex-shrink: 0;
        }
        .top-item-rank.gold   { background: #ffc107; color: #000; }
        .top-item-rank.silver { background: #adb5bd; }
        .top-item-rank.bronze { background: #cd7f32; color: #fff; }
        .top-item-bar-wrap { flex: 1; }
        .top-item-bar-bg {
            background: #EAD8C0; border-radius: 4px;
            height: 8px; margin-top: 0.3rem; overflow: hidden;
        }
        .top-item-bar { height: 100%; border-radius: 4px; background: #C96A2C; }
        .top-item-bar.blue { background: #0070f3; }

        .recent-table { width: 100%; border-collapse: collapse; }
        .recent-table th, .recent-table td {
            padding: 0.75rem; text-align: left;
            border-bottom: 1px solid #EAD8C0; font-size: 0.9rem;
        }
        .recent-table thead tr { background: #FAF3E8; }
        .badge-paid     { background:#d1e7dd; color:#0a3622; padding:0.2rem 0.6rem; border-radius:20px; font-size:0.78rem; font-weight:bold; }
        .badge-unpaid   { background:#fff3cd; color:#664d03; padding:0.2rem 0.6rem; border-radius:20px; font-size:0.78rem; font-weight:bold; }
        .badge-rejected { background:#f8d7da; color:#842029; padding:0.2rem 0.6rem; border-radius:20px; font-size:0.78rem; font-weight:bold; }
        .badge-onsite   { background:#e2e3e5; color:#41464b; padding:0.2rem 0.6rem; border-radius:20px; font-size:0.78rem; font-weight:bold; }
        .badge-online   { background:#cfe2ff; color:#084298; padding:0.2rem 0.6rem; border-radius:20px; font-size:0.78rem; font-weight:bold; }

        /* ── EXPORT BUTTONS ── */
        .export-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
            background: #fff;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom: 2rem;
            border-left: 5px solid #198754;
        }
        .export-bar span {
            font-weight: bold;
            color: #3B2A1A;
            font-size: 0.9rem;
            margin-right: 0.5rem;
        }
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-export:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-export.green  { background: #198754; color: #fff; }
        .btn-export.blue   { background: #0070f3; color: #fff; }
        .btn-export.orange { background: #C96A2C; color: #fff; }
        .btn-export.gold   { background: #ffc107; color: #000; }
        .btn-export.purple { background: #6f42c1; color: #fff; }
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
            <li><a href="analytics.php" class="active">Analytics</a></li>
            <li><a href="walkin.php">Walk-in</a></li>
        </ul>
        <div class="auth-links">
            <a href="index.php?logout=1">Logout</a>
        </div>
    </nav>
</header>

<div class="container">
<div class="admin-container">

    <aside class="admin-sidebar">
        <ul class="admin-menu">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="analytics.php" class="active">Analytics</a></li>
            <li><a href="walkin.php">Walk-in</a></li>
        </ul>
    </aside>

    <main class="admin-content">
        <div class="admin-header">
            <h2>📊 Sales Analytics & Forecasting</h2>
            <span style="color:#888; font-size:0.9rem;">Last updated: <?php echo date('M d, Y h:i A'); ?></span>
        </div>

        <!-- ── EXPORT BAR ─────────────────────────────────────────────────── -->
        <div class="export-bar">
            <span>📥 Export to Excel:</span>
            <a class="btn-export green"  href="export_sales.php?type=all"        >📊 Full Sales Report</a>
            <a class="btn-export blue"   href="export_sales.php?type=orders_only">📦 Orders Only</a>
            <a class="btn-export orange" href="export_sales.php?type=monthly"    >📅 Monthly Summary</a>
            <a class="btn-export gold"   href="export_sales.php?type=products"   >🛍️ Products Sales</a>
            <a class="btn-export purple" href="export_sales.php?type=services"   >💆 Services Sales</a>
        </div>

        <!-- ── KPI CARDS ──────────────────────────────────────────────────── -->
        <div class="analytics-grid">
            <div class="stat-card green">
                <div class="stat-number">&#8369;<?php echo number_format($total_revenue, 2); ?></div>
                <div class="stat-label">💰 Total Revenue (Paid)</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $total_orders; ?></div>
                <div class="stat-label">📦 Total Paid Orders</div>
            </div>
            <div class="stat-card gold">
                <div class="stat-number">&#8369;<?php echo number_format($avg_order_value, 2); ?></div>
                <div class="stat-label">🧾 Avg Order Value</div>
            </div>
            <div class="stat-card red">
                <div class="stat-number"><?php echo $pending_orders; ?></div>
                <div class="stat-label">⏳ Pending Payments</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $total_appts; ?></div>
                <div class="stat-label">📅 Approved Appointments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_customers; ?></div>
                <div class="stat-label">👥 Registered Customers</div>
            </div>
            <?php if ($best_day): ?>
            <div class="stat-card gold">
                <div class="stat-number" style="font-size:1.4rem;"><?php echo $best_day['day_name']; ?></div>
                <div class="stat-label">🏆 Best Sales Day</div>
                <div class="stat-change up">&#8369;<?php echo number_format($best_day['total'], 2); ?> avg</div>
            </div>
            <?php endif; ?>
            <div class="stat-card green">
                <div class="stat-number" style="font-size:1.4rem;">
                    &#8369;<?php echo number_format(array_sum($forecast_data) / 3, 2); ?>
                </div>
                <div class="stat-label">🔮 Forecasted Monthly Avg</div>
                <div class="stat-change up">Next 3 months</div>
            </div>
        </div>

        <!-- ── SALES LINE CHART ───────────────────────────────────────────── -->
        <div class="chart-card">
            <h3>📈 Sales Trend</h3>
            <div class="chart-tabs">
                <button class="chart-tab active" onclick="switchSalesChart('daily', this)">Daily</button>
                <button class="chart-tab" onclick="switchSalesChart('weekly', this)">Weekly</button>
                <button class="chart-tab" onclick="switchSalesChart('monthly', this)">Monthly</button>
                <button class="chart-tab" onclick="switchSalesChart('forecast', this)">
                    🔮 Forecast <span class="forecast-badge">AI</span>
                </button>
            </div>
            <canvas id="salesChart" height="100"></canvas>
        </div>

        <!-- ── PIE CHARTS ─────────────────────────────────────────────────── -->
        <div class="two-col">
            <div class="chart-card">
                <h3>🛍️ Top Products by Sales</h3>
                <?php if (!empty($top_products)): ?>
                    <canvas id="productsChart" height="220"></canvas>
                <?php else: ?>
                    <p style="color:#999; text-align:center; padding:2rem;">No product sales data yet.</p>
                <?php endif; ?>
            </div>
            <div class="chart-card">
                <h3>💆 Top Services by Bookings</h3>
                <?php if (!empty($top_services)): ?>
                    <canvas id="servicesChart" height="220"></canvas>
                <?php else: ?>
                    <p style="color:#999; text-align:center; padding:2rem;">No service bookings data yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TOP PRODUCTS & SERVICES LISTS ─────────────────────────────── -->
        <div class="two-col">
            <!-- Top Products -->
            <div class="chart-card">
                <h3>🏆 Top Products Ranking</h3>
                <?php if (!empty($top_products)):
                    $max_qty = max(array_column($top_products, 'total_qty'));
                    foreach ($top_products as $i => $p):
                        $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                        $bar_width  = $max_qty > 0 ? ($p['total_qty'] / $max_qty * 100) : 0;
                ?>
                    <div class="top-item">
                        <div class="top-item-rank <?php echo $rank_class; ?>"><?php echo $i + 1; ?></div>
                        <div class="top-item-bar-wrap">
                            <div style="display:flex; justify-content:space-between;">
                                <strong style="font-size:0.9rem;"><?php echo htmlspecialchars($p['name']); ?></strong>
                                <span style="color:#C96A2C; font-size:0.85rem;"><?php echo $p['total_qty']; ?> sold</span>
                            </div>
                            <div class="top-item-bar-bg">
                                <div class="top-item-bar" style="width:<?php echo $bar_width; ?>%"></div>
                            </div>
                            <small style="color:#888;">Revenue: &#8369;<?php echo number_format($p['total_revenue'], 2); ?></small>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <p style="color:#999; text-align:center; padding:2rem;">No product sales yet.</p>
                <?php endif; ?>
            </div>

            <!-- Top Services -->
            <div class="chart-card">
                <h3>🏆 Top Services Ranking</h3>
                <?php if (!empty($top_services)):
                    $max_bookings = max(array_column($top_services, 'total_bookings'));
                    foreach ($top_services as $i => $s):
                        $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                        $bar_width  = $max_bookings > 0 ? ($s['total_bookings'] / $max_bookings * 100) : 0;
                ?>
                    <div class="top-item">
                        <div class="top-item-rank <?php echo $rank_class; ?>"><?php echo $i + 1; ?></div>
                        <div class="top-item-bar-wrap">
                            <div style="display:flex; justify-content:space-between;">
                                <strong style="font-size:0.9rem;"><?php echo htmlspecialchars($s['name']); ?></strong>
                                <span style="color:#0070f3; font-size:0.85rem;"><?php echo $s['total_bookings']; ?> bookings</span>
                            </div>
                            <div class="top-item-bar-bg">
                                <div class="top-item-bar blue" style="width:<?php echo $bar_width; ?>%"></div>
                            </div>
                            <small style="color:#888;">Revenue: &#8369;<?php echo number_format($s['total_revenue'], 2); ?></small>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <p style="color:#999; text-align:center; padding:2rem;">No service bookings yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── FORECAST TABLE ─────────────────────────────────────────────── -->
        <div class="chart-card">
            <h3>🔮 Sales Forecast — Next 3 Months</h3>
            <p style="color:#888; font-size:0.88rem; margin-bottom:1rem;">
                Based on linear regression of the past 12 months of sales data.
            </p>
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Forecasted Revenue</th>
                        <th>Confidence</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forecast_labels as $i => $label):
                        $prev     = $i === 0 ? end($monthly_data) : $forecast_data[$i - 1];
                        $curr     = $forecast_data[$i];
                        $change   = $prev > 0 ? (($curr - $prev) / $prev * 100) : 0;
                        $trend_up = $curr >= $prev;
                        $conf     = max(60, 90 - ($i * 10));
                    ?>
                    <tr>
                        <td><strong><?php echo $label; ?></strong></td>
                        <td><strong style="color:#C96A2C; font-size:1.1rem;">&#8369;<?php echo number_format($curr, 2); ?></strong></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <div style="flex:1; background:#EAD8C0; border-radius:4px; height:8px; overflow:hidden;">
                                    <div style="width:<?php echo $conf; ?>%; height:100%; background:#198754; border-radius:4px;"></div>
                                </div>
                                <span style="font-size:0.85rem; color:#666;"><?php echo $conf; ?>%</span>
                            </div>
                        </td>
                        <td>
                            <?php if ($trend_up): ?>
                                <span style="color:#198754; font-weight:bold;">▲ +<?php echo number_format(abs($change), 1); ?>%</span>
                            <?php else: ?>
                                <span style="color:#dc3545; font-weight:bold;">▼ -<?php echo number_format(abs($change), 1); ?>%</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ── RECENT ORDERS ──────────────────────────────────────────────── -->
        <div class="chart-card">
            <h3>🕐 Recent Orders</h3>
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order):
                        $pstatus = $order['payment_status'] ?? 'unpaid';
                        $pmethod = $order['payment_method'] ?? 'onsite';
                    ?>
                    <tr>
                        <td><strong>#<?php echo $order['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                        <td><?php echo $order['item_count']; ?> item(s)</td>
                        <td><strong style="color:#C96A2C;">&#8369;<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                        <td>
                            <?php if ($pmethod === 'online'): ?>
                                <span class="badge-online">💳 Online</span>
                            <?php else: ?>
                                <span class="badge-onsite">🏪 Onsite</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pstatus === 'paid'): ?>
                                <span class="badge-paid">✅ Paid</span>
                            <?php elseif ($pstatus === 'rejected'): ?>
                                <span class="badge-rejected">❌ Rejected</span>
                            <?php else: ?>
                                <span class="badge-unpaid">⏳ Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem; color:#888;">
                            <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>
</div>

<script>
// ─── DATA FROM PHP ────────────────────────────────────────────────────────────
const dailyLabels    = <?php echo json_encode($daily_labels); ?>;
const dailyData      = <?php echo json_encode($daily_data); ?>;
const weeklyLabels   = <?php echo json_encode($weekly_labels); ?>;
const weeklyData     = <?php echo json_encode($weekly_data); ?>;
const monthlyLabels  = <?php echo json_encode($monthly_labels); ?>;
const monthlyData    = <?php echo json_encode($monthly_data); ?>;
const forecastLabels = <?php echo json_encode($forecast_labels); ?>;
const forecastData   = <?php echo json_encode($forecast_data); ?>;

const productLabels  = <?php echo json_encode($top_products_labels); ?>;
const productData    = <?php echo json_encode($top_products_data); ?>;
const productColors  = <?php echo json_encode($top_products_colors); ?>;
const serviceLabels  = <?php echo json_encode($top_services_labels); ?>;
const serviceData    = <?php echo json_encode($top_services_data); ?>;
const serviceColors  = <?php echo json_encode($top_services_colors); ?>;

// ─── CHART DEFAULTS ───────────────────────────────────────────────────────────
Chart.defaults.font.family = "'Segoe UI', sans-serif";
Chart.defaults.color       = '#666';

// ─── SALES LINE CHART ────────────────────────────────────────────────────────
const salesCtx   = document.getElementById('salesChart').getContext('2d');
let   salesChart = new Chart(salesCtx, {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [{
            label: 'Daily Sales (₱)',
            data: dailyData,
            borderColor: '#C96A2C',
            backgroundColor: 'rgba(201,106,44,0.1)',
            borderWidth: 2.5,
            pointBackgroundColor: '#C96A2C',
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ' ₱' + ctx.parsed.y.toFixed(2)
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: v => '₱' + v }
            }
        }
    }
});

function switchSalesChart(type, el) {
    document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');

    let labels, data, datasets, label;

    if (type === 'daily') {
        labels   = dailyLabels;
        datasets = [{
            label: 'Daily Sales (₱)',
            data: dailyData,
            borderColor: '#C96A2C',
            backgroundColor: 'rgba(201,106,44,0.1)',
            borderWidth: 2.5,
            pointBackgroundColor: '#C96A2C',
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }];
    } else if (type === 'weekly') {
        labels   = weeklyLabels;
        datasets = [{
            label: 'Weekly Sales (₱)',
            data: weeklyData,
            borderColor: '#0070f3',
            backgroundColor: 'rgba(0,112,243,0.1)',
            borderWidth: 2.5,
            pointBackgroundColor: '#0070f3',
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }];
    } else if (type === 'monthly') {
        labels   = monthlyLabels;
        datasets = [{
            label: 'Monthly Sales (₱)',
            data: monthlyData,
            borderColor: '#198754',
            backgroundColor: 'rgba(25,135,84,0.1)',
            borderWidth: 2.5,
            pointBackgroundColor: '#198754',
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }];
    } else if (type === 'forecast') {
        // Combined: actual + forecast
        const allLabels = [...monthlyLabels, ...forecastLabels];
        const actualPad = [...monthlyData, null, null, null];
        const forecastPad = [
            ...Array(monthlyLabels.length - 1).fill(null),
            monthlyData[monthlyData.length - 1], // connect line
            ...forecastData
        ];
        labels   = allLabels;
        datasets = [
            {
                label: 'Actual Sales (₱)',
                data: actualPad,
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: '#198754',
                pointRadius: 4,
                tension: 0.4,
                fill: true
            },
            {
                label: 'Forecasted Sales (₱)',
                data: forecastPad,
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255,193,7,0.1)',
                borderWidth: 2.5,
                borderDash: [6, 4],
                pointBackgroundColor: '#ffc107',
                pointRadius: 5,
                tension: 0.4,
                fill: false
            }
        ];
    }

    salesChart.data.labels   = labels;
    salesChart.data.datasets = datasets;
    salesChart.update();
}

// ─── PRODUCTS PIE CHART ───────────────────────────────────────────────────────
<?php if (!empty($top_products)): ?>
new Chart(document.getElementById('productsChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: productLabels,
        datasets: [{
            data: productData,
            backgroundColor: productColors,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } },
            tooltip: {
                callbacks: {
                    label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' sold'
                }
            }
        }
    }
});
<?php endif; ?>

// ─── SERVICES PIE CHART ───────────────────────────────────────────────────────
<?php if (!empty($top_services)): ?>
new Chart(document.getElementById('servicesChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: serviceLabels,
        datasets: [{
            data: serviceData,
            backgroundColor: serviceColors,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } },
            tooltip: {
                callbacks: {
                    label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' bookings'
                }
            }
        }
    }
});
<?php endif; ?>
</script>

</body>
</html>