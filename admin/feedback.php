<?php
/**
 * feedback.php — Admin Feedback Dashboard
 * Place in: spa_ecommerce_system/admin/feedback.php
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

// ── Stats ─────────────────────────────────────────────────────────────────────
$r          = $conn->query("SELECT COUNT(*) as c, ROUND(AVG(rating),1) as avg FROM feedback");
$stats      = $r->fetch_assoc();
$total_fb   = intval($stats['c']);
$avg_rating = floatval($stats['avg']);

// Rating distribution
$dist = [5=>0,4=>0,3=>0,2=>0,1=>0];
$r = $conn->query("SELECT rating, COUNT(*) as c FROM feedback GROUP BY rating");
while ($row = $r->fetch_assoc()) $dist[intval($row['rating'])] = intval($row['c']);

// Per-service averages
$service_stats = [];
$r = $conn->query("
    SELECT s.name, COUNT(f.id) as cnt, ROUND(AVG(f.rating),1) as avg
    FROM feedback f
    JOIN appointments a ON f.appointment_id = a.id
    JOIN services s ON a.service_id = s.id
    GROUP BY s.id, s.name ORDER BY avg DESC LIMIT 5
");
while ($row = $r->fetch_assoc()) $service_stats[] = $row;

// All feedback — appointments
$appt_feedback = [];
$r = $conn->query("
    SELECT f.*, u.full_name, u.email,
           s.name AS service_name,
           a.appointment_date,
           'appointment' AS fb_type
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    JOIN appointments a ON f.appointment_id = a.id
    JOIN services s ON a.service_id = s.id
    WHERE f.appointment_id IS NOT NULL
    ORDER BY f.created_at DESC
");
while ($row = $r->fetch_assoc()) $appt_feedback[] = $row;

// All feedback — product orders
$order_feedback = [];
$r = $conn->query("
    SELECT f.*, u.full_name, u.email,
           o.created_at AS order_date,
           o.total_amount,
           'order' AS fb_type
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    JOIN orders o ON f.order_id = o.id
    WHERE f.appointment_id IS NULL AND f.order_id IS NOT NULL
    ORDER BY f.created_at DESC
");
while ($row = $r->fetch_assoc()) $order_feedback[] = $row;

$page_title  = 'Customer Feedback';
$page_icon   = '⭐';
$active_page = 'feedback';
require_once 'admin_header.php';
?>

<!-- ── KPI CARDS ──────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:1.5rem;">
    <div class="stat-card amber">
        <div class="stat-icon">⭐</div>
        <div class="stat-number"><?php echo $avg_rating ?: '—'; ?></div>
        <div class="stat-label">Overall Rating</div>
        <div style="font-size:0.72rem;margin-top:0.3rem;opacity:0.8;">out of 5.0</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💬</div>
        <div class="stat-number"><?php echo $total_fb; ?></div>
        <div class="stat-label">Total Reviews</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo count($appt_feedback); ?></div>
        <div class="stat-label">Service Reviews</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">🛍️</div>
        <div class="stat-number"><?php echo count($order_feedback); ?></div>
        <div class="stat-label">Product Reviews</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- ── Rating distribution ────────────────────────────────────────────── -->
    <div class="chart-card">
        <h3>Rating Distribution</h3>
        <?php for ($star = 5; $star >= 1; $star--):
            $count = $dist[$star];
            $pct   = $total_fb > 0 ? round($count / $total_fb * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
            <span style="font-size:0.85rem;color:var(--brown);width:12px;text-align:right;"><?php echo $star; ?></span>
            <span style="color:#f59e0b;">&#9733;</span>
            <div style="flex:1;background:var(--border);border-radius:4px;height:10px;overflow:hidden;">
                <div style="width:<?php echo $pct; ?>%;height:100%;background:#f59e0b;border-radius:4px;"></div>
            </div>
            <span style="font-size:0.78rem;color:var(--gray);width:55px;"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
        </div>
        <?php endfor; ?>
    </div>

    <!-- ── Top rated services ─────────────────────────────────────────────── -->
    <div class="chart-card">
        <h3>Top Rated Services</h3>
        <?php if (!empty($service_stats)): ?>
        <?php foreach ($service_stats as $i => $ss): ?>
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
            <div style="width:24px;height:24px;border-radius:50%;
                        background:<?php echo ['#f59e0b','#adb5bd','#cd7f32','#C96A2C','#888'][$i] ?? '#888'; ?>;
                        color:#fff;display:flex;align-items:center;justify-content:center;
                        font-size:0.72rem;font-weight:700;flex-shrink:0;">
                <?php echo $i+1; ?>
            </div>
            <div style="flex:1;">
                <div style="font-size:0.88rem;font-weight:600;color:var(--brown);">
                    <?php echo htmlspecialchars($ss['name']); ?>
                </div>
                <div style="display:flex;gap:2px;margin-top:2px;">
                    <?php for ($s=1;$s<=5;$s++): ?>
                    <span style="color:<?php echo $s<=$ss['avg']?'#f59e0b':'#e5e7eb'; ?>;font-size:0.78rem;">&#9733;</span>
                    <?php endfor; ?>
                    <span style="font-size:0.75rem;color:var(--gray);margin-left:4px;"><?php echo $ss['avg']; ?> · <?php echo $ss['cnt']; ?> review(s)</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <p style="color:var(--gray);font-size:0.85rem;">No service reviews yet.</p>
        <?php endif; ?>
    </div>

</div>

<!-- ── Service Feedback Table ─────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">💆 Service Feedback</span>
        <span class="badge badge-completed"><?php echo count($appt_feedback); ?> reviews</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Appointment Date</th>
                    <th>Rating</th>
                    <th>Comment</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($appt_feedback)): foreach ($appt_feedback as $f): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($f['full_name']); ?></div>
                    <div style="font-size:0.75rem;color:var(--gray);"><?php echo htmlspecialchars($f['email']); ?></div>
                </td>
                <td><?php echo htmlspecialchars($f['service_name']); ?></td>
                <td style="font-size:0.82rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($f['appointment_date'])); ?>
                </td>
                <td>
                    <div style="display:flex;gap:1px;">
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <span style="color:<?php echo $i<=$f['rating']?'#f59e0b':'#e5e7eb'; ?>;font-size:1rem;">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo $f['rating']; ?>/5</div>
                </td>
                <td style="max-width:200px;font-size:0.85rem;">
                    <?php echo $f['comment']
                        ? htmlspecialchars($f['comment'])
                        : '<span style="color:var(--gray);font-style:italic;">No comment</span>'; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($f['created_at'])); ?>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:2rem;">No service feedback yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Product Order Feedback Table ──────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🛍️ Product Order Feedback</span>
        <span class="badge badge-approved"><?php echo count($order_feedback); ?> reviews</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Order</th>
                    <th>Order Date</th>
                    <th>Rating</th>
                    <th>Comment</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($order_feedback)): foreach ($order_feedback as $f): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($f['full_name']); ?></div>
                    <div style="font-size:0.75rem;color:var(--gray);"><?php echo htmlspecialchars($f['email']); ?></div>
                </td>
                <td>
                    <strong style="color:var(--gold);">#<?php echo $f['order_id']; ?></strong><br>
                    <span style="font-size:0.78rem;color:var(--gray);">₱<?php echo number_format($f['total_amount'],2); ?></span>
                </td>
                <td style="font-size:0.82rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($f['order_date'])); ?>
                </td>
                <td>
                    <div style="display:flex;gap:1px;">
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <span style="color:<?php echo $i<=$f['rating']?'#f59e0b':'#e5e7eb'; ?>;font-size:1rem;">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo $f['rating']; ?>/5</div>
                </td>
                <td style="max-width:200px;font-size:0.85rem;">
                    <?php echo $f['comment']
                        ? htmlspecialchars($f['comment'])
                        : '<span style="color:var(--gray);font-style:italic;">No comment</span>'; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($f['created_at'])); ?>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:2rem;">No product feedback yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>