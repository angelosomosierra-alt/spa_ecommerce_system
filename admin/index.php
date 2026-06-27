<?php
require_once '../config.php';

// ── A/B/C/D: live panel queries — single source for page-load and AJAX ────────
function run_live_queries($conn): array {

    // A: On-duty therapists with real-time status
    $rs = $conn->query("
        SELECT
            ta.rotation_order,
            ta.is_on_break,
            ta.time_out,
            t.id,
            t.full_name,
            (SELECT COUNT(*)
             FROM   appointment_therapists at2
             JOIN   appointments ap ON at2.appointment_id = ap.id
             JOIN   services     s2 ON s2.id = ap.service_id
             WHERE  at2.therapist_id = t.id
               AND  ap.status = 'assigned'
               AND  NOW() >= ap.appointment_date
               AND  NOW() <  DATE_ADD(ap.appointment_date,
                                INTERVAL (s2.session_time + IF(ap.service_type = 'home', 30, 0)) MINUTE)
            ) AS is_assigned
        FROM   therapist_attendance ta
        JOIN   therapists t ON ta.therapist_id = t.id
        WHERE  ta.duty_date = CURDATE()
        ORDER  BY ta.rotation_order ASC, ta.time_in ASC
    ");
    $today_roster = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];

    // B: First available therapist in rotation (identical logic to Therapists.php)
    $next_up = null;
    foreach ($today_roster as $r) {
        if (!$r['is_on_break'] && !$r['is_assigned'] && empty($r['time_out'])) {
            $next_up = $r; break;
        }
    }

    // C: Appointments currently in progress — NOW() inside the service time window.
    //    Home-service adds +30 min buffer, matching the rest of the codebase.
    $os = $conn->query("
        SELECT
            o.customer_name,
            s.name          AS service_name,
            a.appointment_date,
            GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(),
                DATE_ADD(a.appointment_date,
                    INTERVAL (s.session_time + IF(a.service_type = 'home', 30, 0)) MINUTE)
            ))              AS minutes_remaining,
            GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', ')
                            AS therapists
        FROM   appointments a
        JOIN   order_items  oi  ON oi.id = a.order_item_id
        JOIN   orders       o   ON o.id  = oi.order_id
        JOIN   services     s   ON s.id  = a.service_id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists             t   ON t.id = at2.therapist_id
        WHERE  a.status = 'assigned'
          AND  NOW() >= a.appointment_date
          AND  NOW() <  DATE_ADD(a.appointment_date,
                           INTERVAL (s.session_time + IF(a.service_type = 'home', 30, 0)) MINUTE)
        GROUP BY a.id
        ORDER  BY a.appointment_date ASC
    ");
    $ongoing_sessions = $os ? $os->fetch_all(MYSQLI_ASSOC) : [];

    // D: Upcoming appointments today — future time, today only, max 8
    $up = $conn->query("
        SELECT
            o.customer_name,
            s.name          AS service_name,
            a.appointment_date,
            IFNULL(GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', '),
                   'Unassigned') AS therapists
        FROM   appointments a
        JOIN   order_items  oi  ON oi.id = a.order_item_id
        JOIN   orders       o   ON o.id  = oi.order_id
        JOIN   services     s   ON s.id  = a.service_id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists             t   ON t.id = at2.therapist_id
        WHERE  a.status IN ('approved','assigned')
          AND  a.appointment_date > NOW()
          AND  DATE(a.appointment_date) = CURDATE()
        GROUP BY a.id
        ORDER  BY a.appointment_date ASC
        LIMIT  8
    ");
    $upcoming_sessions = $up ? $up->fetch_all(MYSQLI_ASSOC) : [];

    return compact('today_roster', 'next_up', 'ongoing_sessions', 'upcoming_sessions');
}

// ── Single rendering source — used by BOTH page-load and AJAX response ─────────
function render_live_panels(array $d): void {
    $roster   = $d['today_roster'];
    $next_up  = $d['next_up'];
    $ongoing  = $d['ongoing_sessions'];
    $upcoming = $d['upcoming_sessions'];

    // Status pill — mirrors Therapists.php status rules exactly
    $pill = function (array $r): string {
        if (!empty($r['time_out']))
            return '<span style="background:#e9ecef;color:#6c757d;font-size:0.68rem;padding:0.15rem 0.55rem;border-radius:20px;font-weight:600;">Done</span>';
        if ($r['is_on_break'])
            return '<span style="background:#cfe2ff;color:#084298;font-size:0.68rem;padding:0.15rem 0.55rem;border-radius:20px;font-weight:600;">On Break</span>';
        if ($r['is_assigned'])
            return '<span style="background:#f8d7da;color:#842029;font-size:0.68rem;padding:0.15rem 0.55rem;border-radius:20px;font-weight:600;">Busy</span>';
        return '<span style="background:rgba(25,135,84,0.12);color:#146c43;font-size:0.68rem;padding:0.15rem 0.55rem;border-radius:20px;font-weight:600;">Available</span>';
    };
    ?>
<div class="live-panels-grid">

<!-- ── Panel 1: Therapist Rotation ────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🔄 Therapist Rotation</span>
        <?php if (!empty($roster)): ?>
        <span style="font-size:0.72rem;color:var(--gray);"><?php echo count($roster); ?> on duty</span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <div style="background:<?php echo $next_up ? 'rgba(25,135,84,0.08)' : 'rgba(220,53,69,0.06)'; ?>;
                    border:1px solid <?php echo $next_up ? 'rgba(25,135,84,0.2)' : 'rgba(220,53,69,0.15)'; ?>;
                    border-radius:8px;padding:0.6rem 0.9rem;margin-bottom:0.75rem;text-align:center;">
            <div style="font-size:0.62rem;font-weight:700;color:var(--gray);text-transform:uppercase;
                        letter-spacing:0.06em;margin-bottom:0.2rem;">Next Up</div>
            <div style="font-weight:700;font-size:0.92rem;
                        color:<?php echo $next_up ? 'var(--green)' : '#dc3545'; ?>;">
                <?php echo $next_up ? htmlspecialchars($next_up['full_name']) : '&mdash; All Busy &mdash;'; ?>
            </div>
        </div>
        <?php if (empty($roster)): ?>
        <p style="text-align:center;color:var(--gray);font-size:0.82rem;padding:1rem 0;">No therapists on duty today.</p>
        <?php else: ?>
        <?php foreach ($roster as $r): ?>
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.35rem 0;border-bottom:1px solid var(--border2);">
            <span style="font-size:0.7rem;color:var(--gray);width:1.2rem;text-align:right;flex-shrink:0;font-weight:600;"><?php echo (int)$r['rotation_order']; ?></span>
            <span style="flex:1;font-size:0.82rem;color:var(--brown);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($r['full_name']); ?></span>
            <?php echo $pill($r); ?>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ── Panel 2: Ongoing Sessions ──────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">▶️ Ongoing Sessions</span>
        <?php if (!empty($ongoing)): ?>
        <span style="background:#dc3545;color:#fff;font-size:0.72rem;padding:0.2rem 0.55rem;border-radius:20px;font-weight:700;"><?php echo count($ongoing); ?></span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <?php if (empty($ongoing)): ?>
        <div style="text-align:center;padding:2rem 1rem;color:var(--gray);">
            <div style="font-size:1.75rem;margin-bottom:0.35rem;">💤</div>
            <div style="font-size:0.82rem;">No sessions in progress.</div>
        </div>
        <?php else: foreach ($ongoing as $s): ?>
        <div style="background:var(--bg3);border-radius:8px;padding:0.65rem 0.75rem;margin-bottom:0.5rem;border-left:3px solid #dc3545;">
            <div style="font-weight:600;font-size:0.82rem;color:var(--brown);"><?php echo htmlspecialchars($s['customer_name']); ?></div>
            <div style="font-size:0.75rem;color:var(--gray);margin:0.1rem 0;"><?php echo htmlspecialchars($s['service_name']); ?></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.3rem;">
                <span style="font-size:0.72rem;color:var(--gray);">💆 <?php echo htmlspecialchars($s['therapists'] ?? '&mdash;'); ?></span>
                <span style="font-size:0.72rem;font-weight:700;color:#dc3545;white-space:nowrap;"><?php echo (int)$s['minutes_remaining']; ?> min left</span>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ── Panel 3: Upcoming Sessions ─────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">⏭️ Upcoming (today)</span>
        <?php if (!empty($upcoming)): ?>
        <span style="background:#0070f3;color:#fff;font-size:0.72rem;padding:0.2rem 0.55rem;border-radius:20px;font-weight:700;"><?php echo count($upcoming); ?></span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <?php if (empty($upcoming)): ?>
        <div style="text-align:center;padding:2rem 1rem;color:var(--gray);">
            <div style="font-size:1.75rem;margin-bottom:0.35rem;">✅</div>
            <div style="font-size:0.82rem;">No more sessions today.</div>
        </div>
        <?php else: foreach ($upcoming as $s): ?>
        <div style="display:flex;align-items:flex-start;gap:0.65rem;padding:0.4rem 0;border-bottom:1px solid var(--border2);">
            <div style="font-size:0.72rem;font-weight:700;color:#0070f3;white-space:nowrap;padding-top:0.1rem;min-width:3.5rem;"><?php echo date('h:i A', strtotime($s['appointment_date'])); ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['customer_name']); ?></div>
                <div style="font-size:0.72rem;color:var(--gray);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['service_name']); ?></div>
                <div style="font-size:0.68rem;color:var(--gray);">💆 <?php echo htmlspecialchars($s['therapists']); ?></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

</div><!-- /.live-panels-grid -->
    <?php
}

// ── AJAX endpoint: access-protected, outputs ONLY the three panels, then exits ─
if (($_GET['ajax'] ?? '') === 'live_panels') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    render_live_panels(run_live_queries($conn));
    exit;
}

if (isset($_GET['logout'])) { logout(); }
redirect_if_not_admin();

// Card 1: How busy is today
$today_appts    = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE DATE(appointment_date) = CURDATE() AND status NOT IN ('cancelled','declined','refunded')")->fetch_assoc()['c'];
// Card 2: Action — pending approvals (links to appointments.php)
$pending_appts  = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending'")->fetch_assoc()['c'];
// Card 3: Staffing right now (clocked in, not yet timed out)
$on_duty_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM therapist_attendance WHERE duty_date = CURDATE() AND (time_out IS NULL OR time_out = '')")->fetch_assoc()['c'];
// Card 4: Action — unpaid orders (links to orders.php)
$pending_orders = (int)$conn->query("SELECT COUNT(*) AS c FROM orders WHERE payment_status = 'unpaid'")->fetch_assoc()['c'];
// Card 5: Action — low stock (links to products.php)
$low_stock      = (int)$conn->query("SELECT COUNT(*) AS c FROM products WHERE stock <= 5 AND stock > 0")->fetch_assoc()['c'];

$recent_orders = [];
$result = $conn->query("SELECT o.*, COUNT(oi.id) as item_count FROM orders o LEFT JOIN order_items oi ON o.id=oi.order_id GROUP BY o.id ORDER BY o.created_at DESC LIMIT 6");
while ($row = $result->fetch_assoc()) $recent_orders[] = $row;

$recent_appts = [];
$result = $conn->query("SELECT a.*, u.full_name, s.name as service_name FROM appointments a JOIN users u ON a.user_id=u.id JOIN services s ON a.service_id=s.id ORDER BY a.created_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $recent_appts[] = $row;

$live = run_live_queries($conn);

$page_title = 'Dashboard'; $page_icon = '🏠'; $active_page = 'index';
require_once 'admin_header.php';
?>
<link rel="stylesheet" href="admin.css?v=<?php echo time(); ?>">
<div class="stats-grid stats-grid-5" style="grid-template-columns:repeat(5,1fr);">
    <div class="stat-card blue">
        <div class="stat-icon">📅</div>
        <div class="stat-number"><?php echo $today_appts; ?></div>
        <div class="stat-label">Today's Appointments</div>
    </div>
    <a href="appointments.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card amber" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon">⏳</div>
            <div class="stat-number"><?php echo $pending_appts; ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
    </a>
    <div class="stat-card green">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo $on_duty_count; ?></div>
        <div class="stat-label">Therapists On Duty</div>
    </div>
    <a href="orders.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card amber" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon">💰</div>
            <div class="stat-number"><?php echo $pending_orders; ?></div>
            <div class="stat-label">Pending Payments</div>
        </div>
    </a>
    <a href="products.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card red" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon">⚠️</div>
            <div class="stat-number"><?php echo $low_stock; ?></div>
            <div class="stat-label">Low Stock</div>
        </div>
    </a>
</div>

<style>
.live-panels-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1.5rem; margin-bottom:1.5rem; }
@media (max-width:1100px) { .live-panels-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:700px)  { .live-panels-grid { grid-template-columns:1fr; } }
.stats-grid-5 { margin-bottom:1.5rem; }
@media (max-width:1100px) { .stats-grid-5 { grid-template-columns:repeat(3,1fr) !important; } }
@media (max-width:700px)  { .stats-grid-5 { grid-template-columns:repeat(2,1fr) !important; } }
@media (max-width:480px)  { .stats-grid-5 { grid-template-columns:1fr !important; } }
</style>
<div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:0.35rem;">
    <span id="livePanelsTs" style="font-size:0.65rem;color:var(--gray);">Live &mdash; auto-refreshes every 45s</span>
</div>
<div id="livePanels"><?php render_live_panels($live); ?></div>

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
<!-- ── BUSINESS EXPENSES WIDGET ──────────────────────────────────────────── -->
<?php require 'expenses_widget.php'; ?>

<?php
$extra_scripts = '<script>
(function () {
    "use strict";
    var container = document.getElementById("livePanels");
    var tsEl      = document.getElementById("livePanelsTs");
    if (!container) return;
    var inFlight = false, timer = null;

    function refresh() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch("index.php?ajax=live_panels", { credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) {
                container.innerHTML = html;
                if (tsEl) {
                    var t = new Date();
                    tsEl.textContent = "Updated " + t.toLocaleTimeString([], {hour:"2-digit", minute:"2-digit"});
                }
            })
            .catch(function () { /* silently skip on network error */ })
            .finally(function () { inFlight = false; });
    }

    function schedule() { timer = setTimeout(function () { refresh(); schedule(); }, 45000); }
    function pause()    { clearTimeout(timer); timer = null; }
    function resume()   { if (!timer) schedule(); }

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) { pause(); } else { refresh(); resume(); }
    });

    schedule();
}());
</script>';
require_once 'admin_footer.php';
?>