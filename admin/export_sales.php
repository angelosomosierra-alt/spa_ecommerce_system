<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();

// ─── CONDITIONS ───────────────────────────────────────────────────────────────
$approved_where = "payment_status='paid' AND approval_status IN ('approved','completed')";
$pending_where  = "payment_status IN ('unpaid','pending_payment') OR (payment_status='paid' AND approval_status='pending')";

// ─── STATS ────────────────────────────────────────────────────────────────────
$total_revenue    = $conn->query("SELECT IFNULL(SUM(total_amount),0) as t FROM orders WHERE $approved_where")->fetch_assoc()['t'];
$total_orders     = $conn->query("SELECT COUNT(*) as c FROM orders WHERE $approved_where")->fetch_assoc()['c'];
$pending_orders   = $conn->query("SELECT COUNT(*) as c FROM orders WHERE $pending_where")->fetch_assoc()['c'];
$total_appts      = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status IN ('approved','completed')")->fetch_assoc()['c'];
$service_revenue  = $conn->query("SELECT IFNULL(SUM(oi.subtotal),0) as t FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.service_id IS NOT NULL AND $approved_where")->fetch_assoc()['t'];
$product_revenue  = $conn->query("SELECT IFNULL(SUM(oi.subtotal),0) as t FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.product_id IS NOT NULL AND $approved_where")->fetch_assoc()['t'];
$total_customers  = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='user' AND username!='walkin_customer'")->fetch_assoc()['c'];
$avg_order        = $total_orders > 0 ? $total_revenue / $total_orders : 0;
$best_day         = $conn->query("SELECT DAYNAME(created_at) as d, SUM(total_amount) as t FROM orders WHERE $approved_where GROUP BY DAYNAME(created_at) ORDER BY t DESC LIMIT 1")->fetch_assoc();

// ─── CHART DATA ───────────────────────────────────────────────────────────────
$daily_labels = []; $daily_data = [];
for ($i=13; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $daily_labels[] = date('M d', strtotime($date));
    $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount),0) as t FROM orders WHERE DATE(created_at)=? AND $approved_where");
    $stmt->bind_param("s",$date); $stmt->execute();
    $daily_data[] = floatval($stmt->get_result()->fetch_assoc()['t']); $stmt->close();
}

$weekly_labels = []; $weekly_data = [];
for ($i=11; $i>=0; $i--) {
    $ws = date('Y-m-d', strtotime("-$i weeks monday this week"));
    $we = date('Y-m-d', strtotime("-$i weeks sunday this week"));
    $weekly_labels[] = 'W'.date('W', strtotime($ws));
    $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount),0) as t FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND $approved_where");
    $stmt->bind_param("ss",$ws,$we); $stmt->execute();
    $weekly_data[] = floatval($stmt->get_result()->fetch_assoc()['t']); $stmt->close();
}

$monthly_labels = []; $monthly_data = [];
for ($i=11; $i>=0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthly_labels[] = date('M Y', strtotime("-$i months"));
    $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount),0) as t FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND $approved_where");
    $stmt->bind_param("s",$month); $stmt->execute();
    $monthly_data[] = floatval($stmt->get_result()->fetch_assoc()['t']); $stmt->close();
}

function linear_forecast($data, $steps=3) {
    $n=count($data); if ($n<2) return array_fill(0,$steps,0);
    $sx=$sy=$sxy=$sxx=0;
    for ($i=0;$i<$n;$i++) { $sx+=$i; $sy+=$data[$i]; $sxy+=$i*$data[$i]; $sxx+=$i*$i; }
    $d = $n*$sxx - $sx*$sx; if ($d==0) return array_fill(0,$steps,end($data));
    $sl=($n*$sxy-$sx*$sy)/$d; $ic=($sy-$sl*$sx)/$n;
    $f=[];
    for ($i=0;$i<$steps;$i++) $f[]=max(0,round($ic+$sl*($n+$i),2));
    return $f;
}

$forecast_data = linear_forecast($monthly_data,3);
$forecast_labels = [];
for ($i=1;$i<=3;$i++) $forecast_labels[] = date('M Y', strtotime("+$i months"));

$top_products=[]; $top_products_labels=[]; $top_products_data=[];
$result = $conn->query("SELECT p.name, SUM(oi.quantity) as tq, SUM(oi.subtotal) as tr FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id WHERE $approved_where GROUP BY p.id,p.name ORDER BY tq DESC LIMIT 8");
while ($r=$result->fetch_assoc()) { $top_products[]=$r; $top_products_labels[]=$r['name']; $top_products_data[]=intval($r['tq']); }

$top_services=[]; $top_services_labels=[]; $top_services_data=[];
$result = $conn->query("SELECT s.name, COUNT(a.id) as tb, SUM(oi.subtotal) as tr FROM appointments a JOIN services s ON a.service_id=s.id JOIN order_items oi ON a.order_item_id=oi.id JOIN orders o ON oi.order_id=o.id WHERE a.status IN ('approved','completed') AND $approved_where GROUP BY s.id,s.name ORDER BY tb DESC LIMIT 8");
while ($r=$result->fetch_assoc()) { $top_services[]=$r; $top_services_labels[]=$r['name']; $top_services_data[]=intval($r['tb']); }

$recent_orders = [];
$result = $conn->query("SELECT o.*, COUNT(oi.id) as ic FROM orders o LEFT JOIN order_items oi ON o.id=oi.order_id GROUP BY o.id ORDER BY o.created_at DESC LIMIT 8");
while ($r=$result->fetch_assoc()) $recent_orders[]=$r;

$extra_head = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
$page_title = 'Analytics'; $page_icon = '📊'; $active_page = 'analytics';
require_once 'admin_header.php';
?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-number">₱<?php echo number_format($total_revenue,0); ?></div><div class="stat-label">Total Revenue</div></div>
    <div class="stat-card blue"><div class="stat-icon">📦</div><div class="stat-number"><?php echo $total_orders; ?></div><div class="stat-label">Approved Orders</div></div>
    <div class="stat-card"><div class="stat-icon">🧾</div><div class="stat-number">₱<?php echo number_format($avg_order,0); ?></div><div class="stat-label">Avg Order Value</div></div>
    <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-number"><?php echo $pending_orders; ?></div><div class="stat-label">Pending Approval</div></div>
    <div class="stat-card blue"><div class="stat-icon">📅</div><div class="stat-number"><?php echo $total_appts; ?></div><div class="stat-label">Confirmed Appts</div></div>
    <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-number"><?php echo $total_customers; ?></div><div class="stat-label">Customers</div></div>
    <div class="stat-card green"><div class="stat-icon">💆</div><div class="stat-number">₱<?php echo number_format($service_revenue,0); ?></div><div class="stat-label">Services Revenue</div></div>
    <div class="stat-card"><div class="stat-icon">🛍️</div><div class="stat-number">₱<?php echo number_format($product_revenue,0); ?></div><div class="stat-label">Products Revenue</div></div>
    <?php if ($best_day): ?>
    <div class="stat-card amber"><div class="stat-icon">🏆</div><div class="stat-number" style="font-size:1.2rem;"><?php echo $best_day['d']; ?></div><div class="stat-label">Best Sales Day</div></div>
    <?php endif; ?>
    <div class="stat-card green"><div class="stat-icon">🔮</div><div class="stat-number" style="font-size:1.1rem;">₱<?php echo number_format(array_sum($forecast_data)/3,0); ?></div><div class="stat-label">Forecast Avg/Month</div></div>
</div>

<!-- Export Bar -->
<div class="export-bar" style="margin-bottom:1.5rem;">
    <span class="export-bar-label">📥 Export:</span>
    <a class="btn-export green"  href="export_sales.php?type=all">📊 Full Report</a>
    <a class="btn-export blue"   href="export_sales.php?type=orders_only">📦 Orders</a>
    <a class="btn-export orange" href="export_sales.php?type=monthly">📅 Monthly</a>
    <a class="btn-export gold"   href="export_sales.php?type=products">🛍️ Products</a>
    <a class="btn-export purple" href="export_sales.php?type=services">💆 Services</a>
</div>

<!-- Sales Chart -->
<div class="chart-card">
    <h3>📈 Sales Trend
        <div class="chart-tabs" style="margin-left:auto;margin-bottom:0;">
            <button class="chart-tab active" onclick="switchSales('daily',this)">Daily</button>
            <button class="chart-tab" onclick="switchSales('weekly',this)">Weekly</button>
            <button class="chart-tab" onclick="switchSales('monthly',this)">Monthly</button>
            <button class="chart-tab" onclick="switchSales('forecast',this)">🔮 Forecast <span class="forecast-badge">AI</span></button>
        </div>
    </h3>
    <canvas id="salesChart" height="90"></canvas>
</div>

<!-- Top lists + pie charts -->
<div class="two-col">
    <div class="chart-card">
        <h3>🛍️ Top Products</h3>
        <?php if (!empty($top_products)):
            $max = max(array_column($top_products,'tq'));
            foreach ($top_products as $i=>$p):
                $rc = $i===0?'gold':($i===1?'silver':($i===2?'bronze':''));
                $bw = $max>0?($p['tq']/$max*100):0;
        ?>
        <div class="top-item">
            <div class="top-item-rank <?php echo $rc; ?>"><?php echo $i+1; ?></div>
            <div class="top-item-bar-wrap">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.2rem;">
                    <span style="font-size:0.82rem;color:var(--cream);"><?php echo htmlspecialchars($p['name']); ?></span>
                    <span style="font-size:0.78rem;color:var(--rust);"><?php echo $p['tq']; ?> sold</span>
                </div>
                <div class="top-item-bar-bg"><div class="top-item-bar" style="width:<?php echo $bw; ?>%"></div></div>
                <div style="font-size:0.7rem;color:var(--gray);margin-top:0.2rem;">₱<?php echo number_format($p['tr'],2); ?></div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:var(--gray);text-align:center;padding:2rem;font-size:0.85rem;">No product sales yet.</p>
        <?php endif; ?>
    </div>
    <div class="chart-card">
        <h3>💆 Top Services</h3>
        <?php if (!empty($top_services)):
            $max = max(array_column($top_services,'tb'));
            foreach ($top_services as $i=>$s):
                $rc = $i===0?'gold':($i===1?'silver':($i===2?'bronze':''));
                $bw = $max>0?($s['tb']/$max*100):0;
        ?>
        <div class="top-item">
            <div class="top-item-rank <?php echo $rc; ?>"><?php echo $i+1; ?></div>
            <div class="top-item-bar-wrap">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.2rem;">
                    <span style="font-size:0.82rem;color:var(--cream);"><?php echo htmlspecialchars($s['name']); ?></span>
                    <span style="font-size:0.78rem;color:var(--blue);"><?php echo $s['tb']; ?> bookings</span>
                </div>
                <div class="top-item-bar-bg"><div class="top-item-bar blue" style="width:<?php echo $bw; ?>%"></div></div>
                <div style="font-size:0.7rem;color:var(--gray);margin-top:0.2rem;">₱<?php echo number_format($s['tr'],2); ?></div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:var(--gray);text-align:center;padding:2rem;font-size:0.85rem;">No service bookings yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Forecast Table -->
<div class="chart-card">
    <h3>🔮 Sales Forecast — Next 3 Months</h3>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>Month</th><th>Forecast Revenue</th><th>Confidence</th><th>Trend</th></tr></thead>
            <tbody>
                <?php foreach ($forecast_labels as $i=>$lbl):
                    $prev = $i===0 ? end($monthly_data) : $forecast_data[$i-1];
                    $curr = $forecast_data[$i];
                    $chg  = $prev>0 ? (($curr-$prev)/$prev*100) : 0;
                    $up   = $curr>=$prev;
                    $conf = max(60, 90-($i*10));
                ?>
                <tr>
                    <td><strong style="color:var(--amber);"><?php echo $lbl; ?></strong></td>
                    <td><strong style="color:var(--rust);font-size:1rem;">₱<?php echo number_format($curr,2); ?></strong></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.6rem;">
                            <div style="flex:1;background:var(--bg3);border-radius:4px;height:6px;overflow:hidden;">
                                <div style="width:<?php echo $conf; ?>%;height:100%;background:var(--green);border-radius:4px;"></div>
                            </div>
                            <span style="font-size:0.75rem;color:var(--gray);"><?php echo $conf; ?>%</span>
                        </div>
                    </td>
                    <td>
                        <span style="color:<?php echo $up?'var(--green)':'var(--red)'; ?>;font-weight:700;font-size:0.85rem;">
                            <?php echo $up?'▲ +':'▼ -'; ?><?php echo number_format(abs($chg),1); ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Orders -->
<div class="chart-card">
    <h3>🕐 Recent Orders</h3>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Approval</th><th>Date</th></tr></thead>
            <tbody>
                <?php
                $pay_labels = [
                    'paid'            => '✅ Paid',
                    'unpaid'          => '⏳ Unpaid',
                    'rejected'        => '❌ Rejected',
                    'refunded'        => '↩️ Refunded',
                    'pending_payment' => '💳 Awaiting',
                    'cancelled'       => '🚫 Cancelled',
                ];
                $apv_labels = [
                    'pending'  => '🕐 Pending',
                    'approved' => '✅ Approved',
                    'declined' => '❌ Declined',
                ];
                foreach ($recent_orders as $o):
                    $ps  = $o['payment_status']  ?? 'unpaid';
                    $pm  = $o['payment_method']  ?? 'onsite';
                    $as  = $o['approval_status'] ?? 'pending';
                    $psl = $pay_labels[$ps] ?? ucfirst($ps);
                    $asl = $apv_labels[$as] ?? ucfirst($as);
                ?>
                <tr>
                    <td><strong style="color:var(--gold);">#<?php echo $o['id']; ?></strong></td>
                    <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                    <td style="color:var(--gray);"><?php echo $o['ic']; ?> item(s)</td>
                    <td><strong style="color:var(--rust);">₱<?php echo number_format($o['total_amount'],2); ?></strong></td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:3px;">
                            <span class="badge badge-<?php echo $pm; ?>" style="font-size:0.7rem;"><?php echo $pm==='online'?'💳 Online':'🏪 Onsite'; ?></span>
                            <span class="badge badge-<?php echo $ps; ?>" style="font-size:0.7rem;"><?php echo $psl; ?></span>
                        </div>
                    </td>
                    <td><span class="badge badge-<?php echo $as; ?>"><?php echo $asl; ?></span></td>
                    <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const dailyLabels=<?php echo json_encode($daily_labels); ?>;
const dailyData=<?php echo json_encode($daily_data); ?>;
const weeklyLabels=<?php echo json_encode($weekly_labels); ?>;
const weeklyData=<?php echo json_encode($weekly_data); ?>;
const monthlyLabels=<?php echo json_encode($monthly_labels); ?>;
const monthlyData=<?php echo json_encode($monthly_data); ?>;
const forecastLabels=<?php echo json_encode($forecast_labels); ?>;
const forecastData=<?php echo json_encode($forecast_data); ?>;

Chart.defaults.font.family="'Plus Jakarta Sans',sans-serif";
Chart.defaults.color='#7A6E64';

const salesCtx = document.getElementById('salesChart').getContext('2d');
let salesChart = new Chart(salesCtx, {
    type:'line',
    data:{
        labels:dailyLabels,
        datasets:[{label:'Daily Sales (₱)',data:dailyData,borderColor:'#C9963A',backgroundColor:'rgba(201,150,58,0.08)',borderWidth:2,pointBackgroundColor:'#C9963A',pointRadius:3,tension:0.4,fill:true}]
    },
    options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' ₱'+c.parsed.y.toFixed(2)}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₱'+v},grid:{color:'rgba(255,255,255,0.04)'}},x:{grid:{color:'rgba(255,255,255,0.04)'}}}}
});

function switchSales(type, el) {
    document.querySelectorAll('.chart-tab').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    let labels, datasets;
    if (type==='daily') {
        labels=dailyLabels;
        datasets=[{label:'Daily',data:dailyData,borderColor:'#C9963A',backgroundColor:'rgba(201,150,58,0.08)',borderWidth:2,pointBackgroundColor:'#C9963A',pointRadius:3,tension:0.4,fill:true}];
    } else if (type==='weekly') {
        labels=weeklyLabels;
        datasets=[{label:'Weekly',data:weeklyData,borderColor:'#3B82F6',backgroundColor:'rgba(59,130,246,0.08)',borderWidth:2,pointBackgroundColor:'#3B82F6',pointRadius:3,tension:0.4,fill:true}];
    } else if (type==='monthly') {
        labels=monthlyLabels;
        datasets=[{label:'Monthly',data:monthlyData,borderColor:'#22C55E',backgroundColor:'rgba(34,197,94,0.08)',borderWidth:2,pointBackgroundColor:'#22C55E',pointRadius:3,tension:0.4,fill:true}];
    } else {
        const all=[...monthlyLabels,...forecastLabels];
        const actualPad=[...monthlyData,null,null,null];
        const fcPad=[...Array(monthlyLabels.length-1).fill(null),monthlyData[monthlyData.length-1],...forecastData];
        labels=all;
        datasets=[
            {label:'Actual',data:actualPad,borderColor:'#22C55E',backgroundColor:'rgba(34,197,94,0.06)',borderWidth:2,pointBackgroundColor:'#22C55E',pointRadius:3,tension:0.4,fill:true},
            {label:'Forecast',data:fcPad,borderColor:'#F59E0B',backgroundColor:'rgba(245,158,11,0.06)',borderWidth:2,borderDash:[6,4],pointBackgroundColor:'#F59E0B',pointRadius:4,tension:0.4,fill:false}
        ];
    }
    salesChart.data.labels=labels; salesChart.data.datasets=datasets; salesChart.update();
}
</script>

<?php require_once 'admin_footer.php'; ?>