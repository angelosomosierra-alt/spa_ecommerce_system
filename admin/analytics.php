<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

// ═══════════════════════════════════════════════════════════════════════════════
// KPI — TOTAL REVENUE
// Products  → payment_status='paid' AND approval_status='approved'
// Services  → payment_status='paid' AND appointment.status='completed'
// Total     → sum of both (never raw payment_status='paid' alone)
// ═══════════════════════════════════════════════════════════════════════════════

// Get filter dates or set defaults (Last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

// Strict validation — reject anything that isn't a valid YYYY-MM-DD date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !strtotime($end_date)) {
    $end_date = date('Y-m-d');
}
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$r_stmt = $conn->prepare("
    SELECT IFNULL(SUM(oi.subtotal),0) as t, COUNT(DISTINCT o.id) as c
    FROM order_items oi JOIN orders o ON oi.order_id = o.id
    WHERE oi.product_id IS NOT NULL
      AND o.payment_status = 'paid' AND o.approval_status = 'approved'
      AND DATE(o.created_at) BETWEEN ? AND ?
");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$prd_row         = $r_stmt->get_result()->fetch_assoc();
$r_stmt->close();
$product_revenue = floatval($prd_row['t']);
$product_orders  = intval($prd_row['c']);

$r_stmt = $conn->prepare("
    SELECT IFNULL(SUM(oi.subtotal),0) as t, COUNT(DISTINCT o.id) as c
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN appointments a ON a.order_item_id = oi.id
    WHERE oi.service_id IS NOT NULL
      AND a.status = 'completed' AND o.payment_status = 'paid'
      AND DATE(o.created_at) BETWEEN ? AND ?
");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$svc_row         = $r_stmt->get_result()->fetch_assoc();
$r_stmt->close();
$service_revenue = floatval($svc_row['t']);
$service_orders  = intval($svc_row['c']);

$total_revenue = $product_revenue + $service_revenue;
$total_orders  = $product_orders  + $service_orders;

$r_stmt = $conn->prepare("
    SELECT COUNT(*) as c FROM orders
    WHERE ((payment_status='paid'   AND approval_status='pending')
        OR (payment_status='unpaid' AND approval_status='pending')
        OR  payment_status='pending_payment')
      AND DATE(created_at) BETWEEN ? AND ?
");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$pending_orders = intval($r_stmt->get_result()->fetch_assoc()['c']);
$r_stmt->close();

$r_stmt = $conn->prepare("SELECT COUNT(*) as c FROM appointments WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$completed_appts = intval($r_stmt->get_result()->fetch_assoc()['c']);
$r_stmt->close();

$r_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT o.user_id) AS c
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE u.role = 'user'
      AND u.username != 'walkin_customer'
      AND o.payment_status = 'paid'
      AND DATE(o.created_at) BETWEEN ? AND ?
");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$total_customers = (int)$r_stmt->get_result()->fetch_assoc()['c'];
$r_stmt->close();

$avg_order = $total_orders > 0 ? $total_revenue / $total_orders : 0;

$r_stmt = $conn->prepare("
    SELECT DAYNAME(o.created_at) AS d, SUM(oi.subtotal) AS t
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN appointments a ON a.order_item_id = oi.id
    WHERE o.payment_status = 'paid'
      AND (
          (oi.product_id IS NOT NULL AND o.approval_status = 'approved')
          OR (oi.service_id IS NOT NULL AND a.status = 'completed')
      )
      AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY DAYNAME(o.created_at)
    ORDER BY t DESC LIMIT 1
");
$r_stmt->bind_param("ss", $start_date, $end_date);
$r_stmt->execute();
$best_day = $r_stmt->get_result()->fetch_assoc();
$r_stmt->close();

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════
function linear_forecast(array $data, int $steps = 3): array {
    $n = count($data);
    if ($n < 2) return array_fill(0, $steps, 0);
    $sx = $sy = $sxy = $sxx = 0;
    for ($i = 0; $i < $n; $i++) {
        $sx += $i; $sy += $data[$i];
        $sxy += $i * $data[$i]; $sxx += $i * $i;
    }
    $denom = $n * $sxx - $sx * $sx;
    if ($denom == 0) return array_fill(0, $steps, max(0, end($data)));
    $slope = ($n * $sxy - $sx * $sy) / $denom;
    $int   = ($sy - $slope * $sx) / $n;
    $out   = [];
    for ($i = 0; $i < $steps; $i++) $out[] = max(0, round($int + $slope * ($n + $i), 2));
    return $out;
}

function svc_revenue($conn, $col, $v1, $v2 = null) {
    $op   = $v2 === null ? '= ?' : 'BETWEEN ? AND ?';
    $sql  = "SELECT IFNULL(SUM(oi.subtotal),0) as t
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             JOIN appointments a ON a.order_item_id = oi.id
             WHERE oi.service_id IS NOT NULL AND a.status='completed'
               AND o.payment_status='paid' AND $col $op";
    $stmt = $conn->prepare($sql);
    $v2 === null ? $stmt->bind_param("s",$v1) : $stmt->bind_param("ss",$v1,$v2);
    $stmt->execute();
    $v = floatval($stmt->get_result()->fetch_assoc()['t']);
    $stmt->close();
    return $v;
}

function prd_revenue($conn, $col, $v1, $v2 = null) {
    $op   = $v2 === null ? '= ?' : 'BETWEEN ? AND ?';
    $sql  = "SELECT IFNULL(SUM(oi.subtotal),0) as t
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE oi.product_id IS NOT NULL
               AND o.payment_status='paid' 
               AND o.approval_status='approved'
               AND $col $op";
    $stmt = $conn->prepare($sql);
    $v2 === null ? $stmt->bind_param("s",$v1) : $stmt->bind_param("ss",$v1,$v2);
    $stmt->execute();
    $v = floatval($stmt->get_result()->fetch_assoc()['t']);
    $stmt->close();
    return $v;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DAILY — last 11 days + TODAY + 3 forecast days ahead
// Range: -10 days → today → +1, +2, +3 days
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
// DAILY — Based on Filtered Date Range
// ═══════════════════════════════════════════════════════════════════════════════
$daily_labels = [];
$daily_actual_total = $daily_actual_svc = $daily_actual_prd = [];

$begin = new DateTime($start_date);
$end   = new DateTime($end_date);
$end->modify('+1 day'); 
$interval = new DateInterval('P1D');
$dateRange = new DatePeriod($begin, $interval, $end);

foreach ($dateRange as $date) {
    $d = $date->format("Y-m-d");
    $daily_labels[] = date('M d', strtotime($d));
    $s = svc_revenue($conn, "DATE(o.created_at)", $d);
    $p = prd_revenue($conn, "DATE(o.created_at)", $d);
    $daily_actual_svc[]   = $s;
    $daily_actual_prd[]   = $p;
    $daily_actual_total[] = $s + $p;
}

$num_days = count($daily_actual_total);

// Forecast next 3 days
$daily_forecast_total = linear_forecast($daily_actual_total, 3);
$daily_forecast_svc   = linear_forecast($daily_actual_svc, 3);
$daily_forecast_prd   = linear_forecast($daily_actual_prd, 3);

for ($i = 1; $i <= 3; $i++) {
    $daily_labels[] = date('M d', strtotime($end_date . " +$i days")) . ' ▶';
}

$daily_total_actual_js = array_merge($daily_actual_total, [null, null, null]);
$daily_svc_actual_js   = array_merge($daily_actual_svc,   [null, null, null]);
$daily_prd_actual_js   = array_merge($daily_actual_prd,   [null, null, null]);

// Connect the forecast line to the last actual data point
$_pad_n = max(0, $num_days - 1);
$daily_total_fcast_js  = array_merge(array_fill(0, $_pad_n, null), $num_days > 0 ? [end($daily_actual_total)] : [0], $daily_forecast_total);
$daily_svc_fcast_js    = array_merge(array_fill(0, $_pad_n, null), $num_days > 0 ? [end($daily_actual_svc)]   : [0], $daily_forecast_svc);
$daily_prd_fcast_js    = array_merge(array_fill(0, $_pad_n, null), $num_days > 0 ? [end($daily_actual_prd)]   : [0], $daily_forecast_prd);

$daily_total = $daily_actual_total;
$daily_svc   = $daily_actual_svc;
$daily_prd   = $daily_actual_prd;

// ═══════════════════════════════════════════════════════════════════════════════
// WEEKLY — Adjusted to Range
// ═══════════════════════════════════════════════════════════════════════════════
$weekly_labels = $weekly_total = $weekly_svc = $weekly_prd = [];
// We keep the last 12 weeks for the "Weekly" view trend
for ($i = 11; $i >= 0; $i--) {
    $ws = date('Y-m-d', strtotime("-$i weeks monday this week"));
    $we = date('Y-m-d', strtotime("-$i weeks sunday this week"));
    $weekly_labels[] = 'Wk ' . date('W', strtotime($ws));
    $s = svc_revenue($conn, "DATE(o.created_at)", $ws, $we);
    $p = prd_revenue($conn, "DATE(o.created_at)", $ws, $we);
    $weekly_svc[]   = $s;
    $weekly_prd[]   = $p;
    $weekly_total[] = $s + $p;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MONTHLY — Adjusted to Range
// ═══════════════════════════════════════════════════════════════════════════════
$monthly_labels = $monthly_total = $monthly_svc = $monthly_prd = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthly_labels[] = date('M Y', strtotime("-$i months"));
    $s = svc_revenue($conn, "DATE_FORMAT(o.created_at,'%Y-%m')", $m);
    $p = prd_revenue($conn, "DATE_FORMAT(o.created_at,'%Y-%m')", $m);
    $monthly_svc[]   = $s;
    $monthly_prd[]   = $p;
    $monthly_total[] = $s + $p;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FORECAST — monthly projection, next 3 months
// ═══════════════════════════════════════════════════════════════════════════════
$forecast_total  = linear_forecast($monthly_total, 3);
$forecast_svc    = linear_forecast($monthly_svc,   3);
$forecast_prd    = linear_forecast($monthly_prd,   3);
$forecast_labels = [];
for ($i = 1; $i <= 3; $i++) $forecast_labels[] = date('M Y', strtotime("+{$i} months"));

// ═══════════════════════════════════════════════════════════════════════════════
// TOP PRODUCTS
// ═══════════════════════════════════════════════════════════════════════════════
$top_products = $top_products_labels = $top_products_qty = $top_products_rev = [];
$r = $conn->query("
    SELECT p.name, SUM(oi.quantity) AS total_qty, SUM(oi.subtotal) AS total_rev
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders   o ON oi.order_id   = o.id
    WHERE oi.product_id IS NOT NULL
      AND o.payment_status = 'paid' 
      AND o.approval_status = 'approved'
    GROUP BY p.id, p.name ORDER BY total_qty DESC LIMIT 8
");
while ($row = $r->fetch_assoc()) {
    $top_products[]        = $row;
    $top_products_labels[] = $row['name'];
    $top_products_qty[]    = intval($row['total_qty']);
    $top_products_rev[]    = floatval($row['total_rev']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOP SERVICES
// ═══════════════════════════════════════════════════════════════════════════════
$top_services = $top_services_labels = $top_services_cnt = $top_services_rev = [];
$r = $conn->query("
    SELECT s.name, COUNT(a.id) AS total_completed, IFNULL(SUM(oi.subtotal),0) AS total_rev
    FROM appointments a
    JOIN services    s  ON a.service_id    = s.id
    JOIN order_items oi ON a.order_item_id = oi.id
    JOIN orders      o  ON oi.order_id     = o.id
    WHERE a.status = 'completed' AND o.payment_status = 'paid'
    GROUP BY s.id, s.name ORDER BY total_completed DESC LIMIT 8
");
while ($row = $r->fetch_assoc()) {
    $top_services[]        = $row;
    $top_services_labels[] = $row['name'];
    $top_services_cnt[]    = intval($row['total_completed']);
    $top_services_rev[]    = floatval($row['total_rev']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECENT ORDERS
// ═══════════════════════════════════════════════════════════════════════════════
$recent_orders = [];
$r = $conn->query("
    SELECT o.id, o.customer_name, o.total_amount, o.payment_status,
           o.approval_status, o.payment_method, o.paymongo_method, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id
    GROUP BY o.id ORDER BY o.created_at DESC LIMIT 10
");
while ($row = $r->fetch_assoc()) $recent_orders[] = $row;


// ═══════════════════════════════════════════════════════════════════════════════
// FEEDBACK DATA (for Feedback tab)
// ═══════════════════════════════════════════════════════════════════════════════
$fb_stats    = $conn->query("SELECT COUNT(*) as c, ROUND(AVG(rating),1) as avg FROM feedback")->fetch_assoc();
$fb_total    = intval($fb_stats['c']);
$fb_avg      = floatval($fb_stats['avg']);
$fb_dist     = [5=>0,4=>0,3=>0,2=>0,1=>0];
$r = $conn->query("SELECT rating, COUNT(*) as c FROM feedback GROUP BY rating");
while ($row = $r->fetch_assoc()) $fb_dist[intval($row['rating'])] = intval($row['c']);

$fb_service_stats = [];
$r = $conn->query("
    SELECT s.name, COUNT(f.id) as cnt, ROUND(AVG(f.rating),1) as avg
    FROM feedback f
    JOIN appointments a ON f.appointment_id = a.id
    JOIN services s ON a.service_id = s.id
    GROUP BY s.id, s.name ORDER BY avg DESC LIMIT 5
");
while ($row = $r->fetch_assoc()) $fb_service_stats[] = $row;

$fb_appt = [];
$r = $conn->query("
    SELECT f.*, u.full_name, u.email,
           s.name AS service_name, a.appointment_date
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    JOIN appointments a ON f.appointment_id = a.id
    JOIN services s ON a.service_id = s.id
    WHERE f.appointment_id IS NOT NULL
    ORDER BY f.created_at DESC
");
while ($row = $r->fetch_assoc()) $fb_appt[] = $row;

$fb_orders = [];
$r = $conn->query("
    SELECT f.*, u.full_name, u.email,
           o.created_at AS order_date, o.total_amount
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    JOIN orders o ON f.order_id = o.id
    WHERE f.appointment_id IS NULL AND f.order_id IS NOT NULL
    ORDER BY f.created_at DESC
");
while ($row = $r->fetch_assoc()) $fb_orders[] = $row;

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE SETUP
// ═══════════════════════════════════════════════════════════════════════════════
$avg_forecast = count($forecast_total) ? array_sum($forecast_total) / count($forecast_total) : 0;
$extra_head   = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
$page_title   = 'Analytics';
$page_icon    = '📊';
$active_page  = 'analytics';
$analytics_tab = $_GET['atab'] ?? 'charts';
if (!in_array($analytics_tab, ['charts', 'feedback'], true)) {
    $analytics_tab = 'charts';
}
require_once 'admin_header.php';
?>

<!-- ── ANALYTICS TAB NAV ─────────────────────────────────────────────────── -->
<div style="display:flex;gap:0.4rem;margin-bottom:1.5rem;border-bottom:2px solid var(--border2);padding-bottom:0;flex-wrap:wrap;">
    <?php foreach([
        'charts'   => ['📊 Analytics',  false],
        'feedback' => ['⭐ Feedback',    false],
    ] as $tab_key => [$tab_label, $unused]): $is_active = $analytics_tab === $tab_key; ?>
    <a href="analytics.php?atab=<?php echo $tab_key; ?><?php echo isset($_GET['start_date']) ? '&start_date='.htmlspecialchars($start_date) : ''; ?><?php echo isset($_GET['end_date']) ? '&end_date='.htmlspecialchars($end_date) : ''; ?>"
       style="padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:700;text-decoration:none;
              border-radius:8px 8px 0 0;border:2px solid var(--border2);border-bottom:none;
              background:<?php echo $is_active ? 'var(--brown)' : 'var(--bg3)'; ?>;
              color:<?php echo $is_active ? '#fff' : 'var(--brown)'; ?>;
              margin-bottom:-2px;">
        <?php echo $tab_label; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($analytics_tab === 'charts'): ?>

<!-- ── EXPORT BAR ─────────────────────────────────────────────────────────── 
<div class="export-bar" style="margin-bottom:1.5rem;">
    <span class="export-bar-label">📥 Export:</span>
    <a class="btn-export green"  href="export_sales.php?type=all"        >📊 Full Report</a>
    <a class="btn-export blue"   href="export_sales.php?type=orders_only">📦 Orders</a>
    <a class="btn-export orange" href="export_sales.php?type=monthly"    >📅 Monthly</a>
    <a class="btn-export gold"   href="export_sales.php?type=products"   >🛍️ Products</a>
    <a class="btn-export purple" href="export_sales.php?type=services"   >💆 Services</a>
</div>-->

<div class="chart-card" style="margin-bottom: 1.5rem; padding: 1rem;">
    <form method="GET" action="analytics.php" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
        <input type="hidden" name="atab" value="<?php echo htmlspecialchars($analytics_tab); ?>">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <label style="font-size: 0.85rem; font-weight: 600;">From:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                   style="padding: 0.4rem; border: 1px solid var(--border); border-radius: 4px;">
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <label style="font-size: 0.85rem; font-weight: 600;">To:</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                   style="padding: 0.4rem; border: 1px solid var(--border); border-radius: 4px;">
        </div>
        <button type="submit" class="btn-export blue" style="border:none; cursor:pointer; padding: 0.5rem 1.2rem;">🔍 Filter Report</button>
        <a href="analytics.php" style="font-size: 0.8rem; color: var(--gray); text-decoration: none;">Reset</a>
    </form>
</div>

<!-- ── KPI CARDS ──────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:1.5rem;">
    <div class="stat-card rust">
        <div class="stat-icon">💰</div>
        <div class="stat-number">₱<?php echo number_format($total_revenue,0); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon">💆</div>
        <div class="stat-number">₱<?php echo number_format($service_revenue,0); ?></div>
        <div class="stat-label">Services Revenue</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">completed appointments</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">🛍️</div>
        <div class="stat-number">₱<?php echo number_format($product_revenue,0); ?></div>
        <div class="stat-label">Products Revenue</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">approved orders</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-number"><?php echo $total_orders; ?></div>
        <div class="stat-label">Paid Orders</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">🧾</div>
        <div class="stat-number">₱<?php echo number_format($avg_order,0); ?></div>
        <div class="stat-label">Avg Order Value</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">📅</div>
        <div class="stat-number"><?php echo $completed_appts; ?></div>
        <div class="stat-label">Completed Appts</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-number"><?php echo $total_customers; ?></div>
        <div class="stat-label">Customers</div>
    </div>
    <?php if ($best_day): ?>
    <div class="stat-card amber">
        <div class="stat-icon">🏆</div>
        <div class="stat-number" style="font-size:1.2rem;"><?php echo $best_day['d']; ?></div>
        <div class="stat-label">Best Sales Day</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">₱<?php echo number_format($best_day['t'],0); ?> total</div>
    </div>
    <?php endif; ?>
    <div class="stat-card green">
        <div class="stat-icon">🔮</div>
        <div class="stat-number" style="font-size:1.2rem;">₱<?php echo number_format($avg_forecast,0); ?></div>
        <div class="stat-label">Forecast Avg / Mo</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">next 3 months</div>
    </div>
</div>

<!-- ── REVENUE LINE CHART ─────────────────────────────────────────────────── -->
<div class="chart-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
        <h3 style="margin:0;">📈 Revenue Trend — Total · Services · Products</h3>
        <div class="chart-tabs" style="margin:0;">
            <button class="chart-tab active" onclick="switchChart('daily',this)">Daily</button>
            <button class="chart-tab"        onclick="switchChart('weekly',this)">Weekly</button>
            <button class="chart-tab"        onclick="switchChart('monthly',this)">Monthly</button>
            <button class="chart-tab"        onclick="switchChart('forecast',this)">🔮 Forecast</button>
        </div>
    </div>

    <!-- Revenue summary strip -->
    <div id="rev-strip" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;padding:0.75rem 1rem;background:var(--bg3);border-radius:var(--radius);font-size:0.82rem;">
        <span>⏱ Period: <strong id="strip-period">Last 14 days</strong></span>
        <span style="color:var(--rust);">💰 Total: <strong id="strip-total">₱<?php echo number_format(array_sum($daily_total),2); ?></strong></span>
        <span style="color:#0070f3;">💆 Services: <strong id="strip-svc">₱<?php echo number_format(array_sum($daily_svc),2); ?></strong></span>
        <span style="color:#198754;">🛍️ Products: <strong id="strip-prd">₱<?php echo number_format(array_sum($daily_prd),2); ?></strong></span>
    </div>

    <!-- Actual vs Forecast legend -->
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:0.75rem;font-size:0.82rem;">
        <span style="display:flex;align-items:center;gap:0.4rem;"><span style="display:inline-block;width:24px;height:3px;background:#C96A2C;border-radius:2px;"></span> Total (actual)</span>
        <span style="display:flex;align-items:center;gap:0.4rem;"><span style="display:inline-block;width:24px;height:3px;background:#0070f3;border-radius:2px;"></span> Services (actual)</span>
        <span style="display:flex;align-items:center;gap:0.4rem;"><span style="display:inline-block;width:24px;height:3px;background:#198754;border-radius:2px;"></span> Products (actual)</span>
        <span style="display:flex;align-items:center;gap:0.4rem;color:var(--gray);"><span style="display:inline-block;width:24px;height:2px;background:#f59e0b;border-radius:2px;border-top:2px dashed #f59e0b;"></span> Forecast Total</span>
        <span style="display:flex;align-items:center;gap:0.4rem;color:var(--gray);"><span style="display:inline-block;width:24px;height:2px;border-top:2px dashed #60a5fa;"></span> Forecast Services</span>
        <span style="display:flex;align-items:center;gap:0.4rem;color:var(--gray);"><span style="display:inline-block;width:24px;height:2px;border-top:2px dashed #34d399;"></span> Forecast Products</span>
    </div>

    <div style="position:relative;height:320px;">
        <canvas id="revenueChart"></canvas>
    </div>
</div>

<!-- ── PIE CHARTS ─────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <div class="chart-card">
        <h3>💆 Top Services — Completed Sessions</h3>
        <?php if (!empty($top_services)): ?>
        <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;">
            <div style="flex:0 0 200px;"><canvas id="servicesChart"></canvas></div>
            <div style="flex:1;min-width:160px;">
                <?php
                $max_s = max($top_services_cnt) ?: 1;
                $pal_s = ['#0070f3','#34a8ff','#63bfff','#0051b3','#003d8f','#5a95e8','#1a5fa8','#00bfff'];
                foreach ($top_services as $i => $s):
                    $w = round($s['total_completed'] / $max_s * 100);
                ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.6rem;">
                    <div style="width:22px;height:22px;border-radius:50%;background:<?php echo $pal_s[$i%8]; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;flex-shrink:0;"><?php echo $i+1; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
                            <span style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;"><?php echo htmlspecialchars($s['name']); ?></span>
                            <span style="color:#0070f3;font-weight:600;"><?php echo $s['total_completed']; ?></span>
                        </div>
                        <div style="background:var(--border);border-radius:3px;height:5px;margin-top:3px;">
                            <div style="width:<?php echo $w; ?>%;height:100%;background:<?php echo $pal_s[$i%8]; ?>;border-radius:3px;"></div>
                        </div>
                        <div style="font-size:0.7rem;color:var(--gray);margin-top:1px;">₱<?php echo number_format($s['total_rev'],0); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p style="color:var(--gray);text-align:center;padding:2rem;">No completed service sessions yet.</p>
        <?php endif; ?>
    </div>

    <div class="chart-card">
        <h3>🛍️ Top Products — Units Sold</h3>
        <?php if (!empty($top_products)): ?>
        <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;">
            <div style="flex:0 0 200px;"><canvas id="productsChart"></canvas></div>
            <div style="flex:1;min-width:160px;">
                <?php
                $max_p = max($top_products_qty) ?: 1;
                $pal_p = ['#C96A2C','#E8955A','#F5B887','#A94F1D','#7B3A0E','#D4845A','#FF8C42','#8B4513'];
                foreach ($top_products as $i => $p):
                    $w = round($p['total_qty'] / $max_p * 100);
                ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.6rem;">
                    <div style="width:22px;height:22px;border-radius:50%;background:<?php echo $pal_p[$i%8]; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;flex-shrink:0;"><?php echo $i+1; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
                            <span style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;"><?php echo htmlspecialchars($p['name']); ?></span>
                            <span style="color:var(--rust);font-weight:600;"><?php echo $p['total_qty']; ?> sold</span>
                        </div>
                        <div style="background:var(--border);border-radius:3px;height:5px;margin-top:3px;">
                            <div style="width:<?php echo $w; ?>%;height:100%;background:<?php echo $pal_p[$i%8]; ?>;border-radius:3px;"></div>
                        </div>
                        <div style="font-size:0.7rem;color:var(--gray);margin-top:1px;">₱<?php echo number_format($p['total_rev'],0); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p style="color:var(--gray);text-align:center;padding:2rem;">No approved product sales yet.</p>
        <?php endif; ?>
    </div>

</div>

<!-- ── FORECAST TABLE ─────────────────────────────────────────────────────── -->
<div class="chart-card" style="margin-bottom:1.5rem;">
    <h3>🔮 Revenue Forecast — Next 3 Months
        <span style="font-size:0.75rem;background:var(--amber-dim);color:var(--amber);padding:0.2rem 0.6rem;border-radius:20px;margin-left:0.5rem;font-weight:600;">Linear Regression</span>
    </h3>
    <p style="color:var(--gray);font-size:0.82rem;margin-bottom:1rem;">Projected from the last 12 months using least-squares linear regression. Confidence decreases further out.</p>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
        <thead>
            <tr style="background:var(--bg3);">
                <th style="padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border);">Month</th>
                <th style="padding:0.75rem 1rem;text-align:right;border-bottom:2px solid var(--border);">Total</th>
                <th style="padding:0.75rem 1rem;text-align:right;border-bottom:2px solid var(--border);">Services</th>
                <th style="padding:0.75rem 1rem;text-align:right;border-bottom:2px solid var(--border);">Products</th>
                <th style="padding:0.75rem 1rem;text-align:center;border-bottom:2px solid var(--border);">Confidence</th>
                <th style="padding:0.75rem 1rem;text-align:center;border-bottom:2px solid var(--border);">Trend</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $prev_total = end($monthly_total);
            foreach ($forecast_labels as $i => $lbl):
                $ft  = $forecast_total[$i];
                $fs  = $forecast_svc[$i];
                $fp  = $forecast_prd[$i];
                $chg = $prev_total > 0 ? ($ft - $prev_total) / $prev_total * 100 : 0;
                $conf = max(55, 88 - $i * 12);
                $up  = $ft >= $prev_total;
                $prev_total = $ft;
            ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:0.75rem 1rem;font-weight:600;"><?php echo $lbl; ?></td>
                <td style="padding:0.75rem 1rem;text-align:right;color:var(--rust);font-weight:700;">₱<?php echo number_format($ft,2); ?></td>
                <td style="padding:0.75rem 1rem;text-align:right;color:#0070f3;">₱<?php echo number_format($fs,2); ?></td>
                <td style="padding:0.75rem 1rem;text-align:right;color:var(--green);">₱<?php echo number_format($fp,2); ?></td>
                <td style="padding:0.75rem 1rem;">
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        <div style="flex:1;background:var(--border);border-radius:3px;height:7px;overflow:hidden;">
                            <div style="width:<?php echo $conf; ?>%;height:100%;background:var(--green);border-radius:3px;"></div>
                        </div>
                        <span style="font-size:0.78rem;color:var(--gray);white-space:nowrap;"><?php echo $conf; ?>%</span>
                    </div>
                </td>
                <td style="padding:0.75rem 1rem;text-align:center;">
                    <?php if ($up): ?>
                        <span style="color:var(--green);font-weight:700;">▲ +<?php echo number_format(abs($chg),1); ?>%</span>
                    <?php else: ?>
                        <span style="color:var(--red);font-weight:700;">▼ -<?php echo number_format(abs($chg),1); ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ── RECENT ORDERS ──────────────────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">🕐 Recent Orders</span></div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Order</th><th>Customer</th><th>Items</th><th>Total</th>
                    <th>Method</th><th>Payment</th><th>Approval</th><th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_orders as $o):
                $ps = $o['payment_status'];
                $as = $o['approval_status'];
                // Use paymongo_method (actual) if available, else payment_method
                $pm = !empty($o['paymongo_method']) ? $o['paymongo_method'] : ($o['payment_method'] ?? 'cash');
            ?>
                <tr>
                    <td><strong>#<?php echo $o['id']; ?></strong></td>
                    <td><?php echo htmlspecialchars($o['customer_name'] ?? '—'); ?></td>
                    <td><?php echo $o['item_count']; ?></td>
                    <td><strong style="color:var(--rust);">₱<?php echo number_format($o['total_amount'],2); ?></strong></td>
                    <td><?php
                        $pm_labels = ['cash'=>'💵 Cash','gcash'=>'📱 GCash','maya'=>'💜 Maya',
                                      'qrph'=>'📷 QRPH','bank'=>'🏦 Bank','card'=>'💳 Card','online'=>'💳 Online'];
                        $pm_class  = in_array($pm,['gcash','maya','qrph','bank','card','online']) ? 'online' : 'onsite';
                        echo '<span class="badge badge-'.$pm_class.'">'.(($pm_labels[$pm]) ?? ('💰 '.ucfirst($pm))).'</span>';
                    ?></td>
                    <td><span class="badge badge-<?php echo $ps; ?>"><?php echo ucfirst($ps); ?></span></td>
                    <td><span class="badge badge-<?php echo $as; ?>"><?php echo ucfirst($as); ?></span></td>
                    <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_orders)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--gray);padding:2rem;">No orders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const DATA = {
    daily: {
        labels:      <?php echo json_encode($daily_labels); ?>,
        total:       <?php echo json_encode($daily_total_actual_js); ?>,
        svc:         <?php echo json_encode($daily_svc_actual_js); ?>,
        prd:         <?php echo json_encode($daily_prd_actual_js); ?>,
        fcastTotal:  <?php echo json_encode($daily_total_fcast_js); ?>,
        fcastSvc:    <?php echo json_encode($daily_svc_fcast_js); ?>,
        fcastPrd:    <?php echo json_encode($daily_prd_fcast_js); ?>
    },
    weekly:  { labels: <?php echo json_encode($weekly_labels);  ?>, total: <?php echo json_encode($weekly_total);  ?>, svc: <?php echo json_encode($weekly_svc);  ?>, prd: <?php echo json_encode($weekly_prd);  ?> },
    monthly: { labels: <?php echo json_encode($monthly_labels); ?>, total: <?php echo json_encode($monthly_total); ?>, svc: <?php echo json_encode($monthly_svc); ?>, prd: <?php echo json_encode($monthly_prd); ?> },
    forecast: {
        allLabels:   <?php echo json_encode(array_merge($monthly_labels, $forecast_labels)); ?>,
        actualTotal: <?php echo json_encode(array_merge($monthly_total, array_fill(0,3,null))); ?>,
        actualSvc:   <?php echo json_encode(array_merge($monthly_svc,   array_fill(0,3,null))); ?>,
        actualPrd:   <?php echo json_encode(array_merge($monthly_prd,   array_fill(0,3,null))); ?>,
        fcastTotal:  <?php $pad=array_fill(0,count($monthly_total)-1,null); $pad[]=end($monthly_total); echo json_encode(array_merge($pad,$forecast_total)); ?>,
        fcastSvc:    <?php $pad=array_fill(0,count($monthly_svc)-1,null);   $pad[]=end($monthly_svc);   echo json_encode(array_merge($pad,$forecast_svc));   ?>,
        fcastPrd:    <?php $pad=array_fill(0,count($monthly_prd)-1,null);   $pad[]=end($monthly_prd);   echo json_encode(array_merge($pad,$forecast_prd));   ?>
    }
};

const PERIOD_NAMES = { daily:'Last 14 days', weekly:'Last 12 weeks', monthly:'Last 12 months', forecast:'Monthly + Forecast' };

Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#888';

function sum(arr) { return arr.reduce((a,b) => a+(b||0), 0); }

function mkLine(color, dash=false) {
    return { borderColor:color, backgroundColor:'transparent', borderWidth:dash?2:2.5,
             pointBackgroundColor:color, pointRadius:4, pointHoverRadius:7,
             tension:0.4, fill:false, borderDash:dash?[6,4]:[], spanGaps:true };
}

const rCtx = document.getElementById('revenueChart').getContext('2d');
let rChart = new Chart(rCtx, {
    type: 'line',
    data: {
        labels: DATA.daily.labels,
        datasets: [
            { label:'Total (₱)',             data:DATA.daily.total,      ...mkLine('#C96A2C'), backgroundColor:'rgba(201,106,44,0.10)', fill:true },
            { label:'Services (₱)',           data:DATA.daily.svc,        ...mkLine('#0070f3') },
            { label:'Products (₱)',           data:DATA.daily.prd,        ...mkLine('#198754') },
            { label:'Forecast Total (₱)',     data:DATA.daily.fcastTotal, ...mkLine('#f59e0b',true), pointStyle:'triangle', pointRadius:6 },
            { label:'Forecast Services (₱)',  data:DATA.daily.fcastSvc,   ...mkLine('#60a5fa',true) },
            { label:'Forecast Products (₱)',  data:DATA.daily.fcastPrd,   ...mkLine('#34d399',true) }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode:'index', intersect:false },
        plugins: {
            legend: { display:false },
            tooltip: {
                callbacks: {
                    label: ctx => '  '+ctx.dataset.label+': ₱'+(ctx.parsed.y??0).toLocaleString('en-PH',{minimumFractionDigits:2}),
                    afterBody: (items) => {
                        const idx = items[0]?.dataIndex;
                        if (idx >= 11) return ['  ── forecast ──'];
                        return [];
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color:'rgba(0,0,0,0.06)' },
                ticks: { callback: v => '₱'+v.toLocaleString() }
            },
            x: {
                grid: { display:false },
                ticks: {
                    font: { size:11 },
                    color: (ctx) => ctx.index >= 11 ? '#f59e0b' : '#888'
                }
            }
        }
    }
});

function switchChart(type, el) {
    document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');

    let labels, datasets;
    if (type === 'forecast') {
        const fd = DATA.forecast;
        labels   = fd.allLabels;
        datasets = [
            { label:'Actual Total (₱)',      data:fd.actualTotal, ...mkLine('#C96A2C'), backgroundColor:'rgba(201,106,44,0.08)', fill:true },
            { label:'Actual Services (₱)',   data:fd.actualSvc,   ...mkLine('#0070f3') },
            { label:'Actual Products (₱)',   data:fd.actualPrd,   ...mkLine('#198754') },
            { label:'Forecast Total (₱)',    data:fd.fcastTotal,  ...mkLine('#f59e0b',true), pointStyle:'triangle', pointRadius:6 },
            { label:'Forecast Services (₱)', data:fd.fcastSvc,    ...mkLine('#60a5fa',true) },
            { label:'Forecast Products (₱)', data:fd.fcastPrd,    ...mkLine('#34d399',true) }
        ];
        updateStrip('forecast', fd.fcastTotal, fd.fcastSvc, fd.fcastPrd);
    } else if (type === 'daily') {
        const d  = DATA.daily;
        labels   = d.labels;
        datasets = [
            { label:'Total (₱)',             data:d.total,      ...mkLine('#C96A2C'), backgroundColor:'rgba(201,106,44,0.10)', fill:true },
            { label:'Services (₱)',           data:d.svc,        ...mkLine('#0070f3') },
            { label:'Products (₱)',           data:d.prd,        ...mkLine('#198754') },
            { label:'Forecast Total (₱)',     data:d.fcastTotal, ...mkLine('#f59e0b',true), pointStyle:'triangle', pointRadius:6 },
            { label:'Forecast Services (₱)',  data:d.fcastSvc,   ...mkLine('#60a5fa',true) },
            { label:'Forecast Products (₱)',  data:d.fcastPrd,   ...mkLine('#34d399',true) }
        ];
        updateStrip(type, d.total, d.svc, d.prd);
    } else {
        const d  = DATA[type];
        labels   = d.labels;
        datasets = [
            { label:'Total (₱)',    data:d.total, ...mkLine('#C96A2C'), backgroundColor:'rgba(201,106,44,0.08)', fill:true },
            { label:'Services (₱)', data:d.svc,   ...mkLine('#0070f3') },
            { label:'Products (₱)', data:d.prd,   ...mkLine('#198754') }
        ];
        updateStrip(type, d.total, d.svc, d.prd);
    }
    rChart.data.labels   = labels;
    rChart.data.datasets = datasets;
    rChart.update('active');
}

function updateStrip(type, total, svc, prd) {
    const name = PERIOD_NAMES[type] ?? type;
    document.getElementById('strip-period').textContent = name;
    document.getElementById('strip-total').textContent  = '₱'+sum(total).toLocaleString('en-PH',{minimumFractionDigits:2});
    document.getElementById('strip-svc').textContent    = '₱'+sum(svc).toLocaleString('en-PH',{minimumFractionDigits:2});
    document.getElementById('strip-prd').textContent    = '₱'+sum(prd).toLocaleString('en-PH',{minimumFractionDigits:2});
}

<?php if (!empty($top_services)): ?>
new Chart(document.getElementById('servicesChart').getContext('2d'), {
    type:'doughnut',
    data:{ labels:<?php echo json_encode($top_services_labels); ?>, datasets:[{ data:<?php echo json_encode($top_services_cnt); ?>, backgroundColor:['#0070f3','#34a8ff','#63bfff','#0051b3','#003d8f','#5a95e8','#1a5fa8','#00bfff'], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, cutout:'62%', plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label: ctx=>' '+ctx.label+': '+ctx.parsed+' sessions' } } } }
});
<?php endif; ?>

<?php if (!empty($top_products)): ?>
new Chart(document.getElementById('productsChart').getContext('2d'), {
    type:'doughnut',
    data:{ labels:<?php echo json_encode($top_products_labels); ?>, datasets:[{ data:<?php echo json_encode($top_products_qty); ?>, backgroundColor:['#C96A2C','#E8955A','#F5B887','#A94F1D','#7B3A0E','#D4845A','#FF8C42','#8B4513'], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, cutout:'62%', plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label: ctx=>' '+ctx.label+': '+ctx.parsed+' sold' } } } }
});
<?php endif; ?>
</script>

<?php elseif ($analytics_tab === 'feedback'): ?>

<!-- ════════════════════════════════════════════════════════════
     FEEDBACK TAB
════════════════════════════════════════════════════════════ -->

<!-- KPI Cards -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:1.5rem;">
    <div class="stat-card amber">
        <div class="stat-icon">⭐</div>
        <div class="stat-number"><?php echo $fb_avg ?: '—'; ?></div>
        <div class="stat-label">Overall Rating</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">out of 5.0</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💬</div>
        <div class="stat-number"><?php echo $fb_total; ?></div>
        <div class="stat-label">Total Reviews</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo count($fb_appt); ?></div>
        <div class="stat-label">Service Reviews</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">🛍️</div>
        <div class="stat-number"><?php echo count($fb_orders); ?></div>
        <div class="stat-label">Product Reviews</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- Rating distribution -->
    <div class="chart-card">
        <h3>Rating Distribution</h3>
        <?php for ($star = 5; $star >= 1; $star--):
            $cnt = $fb_dist[$star];
            $pct = $fb_total > 0 ? round($cnt / $fb_total * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
            <span style="font-size:0.85rem;color:var(--brown);width:12px;text-align:right;"><?php echo $star; ?></span>
            <span style="color:#f59e0b;">&#9733;</span>
            <div style="flex:1;background:var(--border);border-radius:4px;height:10px;overflow:hidden;">
                <div style="width:<?php echo $pct; ?>%;height:100%;background:#f59e0b;border-radius:4px;"></div>
            </div>
            <span style="font-size:0.78rem;color:var(--gray);width:55px;"><?php echo $cnt; ?> (<?php echo $pct; ?>%)</span>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Top rated services -->
    <div class="chart-card">
        <h3>Top Rated Services</h3>
        <?php if (!empty($fb_service_stats)): ?>
        <?php foreach ($fb_service_stats as $i => $ss): ?>
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
            <div style="width:24px;height:24px;border-radius:50%;flex-shrink:0;
                        background:<?php echo ['#f59e0b','#adb5bd','#cd7f32','#C96A2C','#888'][$i] ?? '#ccc'; ?>;
                        color:#fff;font-size:0.7rem;font-weight:700;
                        display:flex;align-items:center;justify-content:center;">
                <?php echo $i+1; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:0.85rem;color:var(--brown);"><?php echo htmlspecialchars($ss['name']); ?></div>
                <div style="font-size:0.75rem;color:var(--gray);"><?php echo $ss['cnt']; ?> reviews</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.88rem;font-weight:700;color:#f59e0b;"><?php echo $ss['avg']; ?> ★</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <p style="color:var(--gray);font-size:0.85rem;text-align:center;padding:1rem;">No service feedback yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Service Appointment Feedback Table -->
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">💆 Service Appointment Feedback</span>
        <span class="badge badge-approved"><?php echo count($fb_appt); ?> reviews</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr><th>Customer</th><th>Service</th><th>Date</th><th>Rating</th><th>Comment</th><th>Submitted</th></tr>
            </thead>
            <tbody>
            <?php if (!empty($fb_appt)): foreach ($fb_appt as $f): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($f['full_name']); ?></div>
                    <div style="font-size:0.75rem;color:var(--gray);"><?php echo htmlspecialchars($f['email']); ?></div>
                </td>
                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($f['service_name']); ?></td>
                <td style="font-size:0.82rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($f['appointment_date'])); ?></td>
                <td>
                    <div style="display:flex;gap:1px;">
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <span style="color:<?php echo $i<=$f['rating']?'#f59e0b':'#e5e7eb'; ?>;font-size:1rem;">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo $f['rating']; ?>/5</div>
                </td>
                <td style="max-width:200px;font-size:0.85rem;">
                    <?php echo $f['comment'] ? htmlspecialchars($f['comment']) : '<span style="color:var(--gray);font-style:italic;">No comment</span>'; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($f['created_at'])); ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:2rem;">No service feedback yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Product Order Feedback Table -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🛍️ Product Order Feedback</span>
        <span class="badge badge-approved"><?php echo count($fb_orders); ?> reviews</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr><th>Customer</th><th>Order</th><th>Order Date</th><th>Rating</th><th>Comment</th><th>Submitted</th></tr>
            </thead>
            <tbody>
            <?php if (!empty($fb_orders)): foreach ($fb_orders as $f): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($f['full_name']); ?></div>
                    <div style="font-size:0.75rem;color:var(--gray);"><?php echo htmlspecialchars($f['email']); ?></div>
                </td>
                <td>
                    <strong style="color:var(--gold);">#<?php echo $f['order_id']; ?></strong><br>
                    <span style="font-size:0.78rem;color:var(--gray);">₱<?php echo number_format($f['total_amount'],2); ?></span>
                </td>
                <td style="font-size:0.82rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($f['order_date'])); ?></td>
                <td>
                    <div style="display:flex;gap:1px;">
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <span style="color:<?php echo $i<=$f['rating']?'#f59e0b':'#e5e7eb'; ?>;font-size:1rem;">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo $f['rating']; ?>/5</div>
                </td>
                <td style="max-width:200px;font-size:0.85rem;">
                    <?php echo $f['comment'] ? htmlspecialchars($f['comment']) : '<span style="color:var(--gray);font-style:italic;">No comment</span>'; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($f['created_at'])); ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:2rem;">No product feedback yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>