<?php
require_once '../config.php';

// ── A/B/C/D: live panel queries — single source for page-load and AJAX ────────
function run_live_queries($conn): array {

    // A: On-duty therapists with real-time status
    // Note: is_assigned checks ap.status = 'approved' -- that's the app's
    // "customer actually checked in / session under way" status. 'assigned'
    // means a therapist was picked but the customer hasn't arrived yet, so
    // checking for it here would show a therapist as busy/available backwards
    // (same bug already fixed in admin/therapists.php's roster query).
    $rs = $conn->query("
        SELECT
            ta.rotation_order,
            ta.is_on_break,
            ta.time_out,
            t.id,
            t.full_name,
            t.is_generalist,
            (SELECT GROUP_CONCAT(DISTINCT c.name SEPARATOR '|')
             FROM   therapist_specialties tsp
             JOIN   categories c ON c.id = tsp.category_id
             WHERE  tsp.therapist_id = t.id
            ) AS specialty_names,
            (SELECT COUNT(*)
             FROM   appointment_therapists at2
             JOIN   appointments ap ON at2.appointment_id = ap.id
             JOIN   services     s2 ON s2.id = ap.service_id
             WHERE  at2.therapist_id = t.id
               AND  ap.status = 'approved'
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

    // Classify each therapist into one or more rotation groups, so the
    // Dashboard can show a separate "next up" per specialty. Keyword-based
    // (not hardcoded category IDs) so new categories named the same way
    // auto-classify correctly without code changes.
    $classify_category = function (string $name): string {
        $n = strtolower($name);
        if (preg_match('/aesthetic|laser|\brf\b|hifu|drip|pico|carbon glow/', $n)) return 'aesthetic';
        if (str_contains($n, 'facial')) return 'facial';
        if (str_contains($n, 'nail') || str_contains($n, 'lash')) return 'nail_lash';
        return 'therapist';
    };
    foreach ($today_roster as &$r) {
        if (!empty($r['is_generalist'])) {
            $r['groups'] = ['therapist', 'facial', 'nail_lash', 'aesthetic'];
            continue;
        }
        $groups = [];
        if (!empty($r['specialty_names'])) {
            foreach (explode('|', $r['specialty_names']) as $cat_name) {
                $groups[] = $classify_category($cat_name);
            }
            $groups = array_values(array_unique($groups));
        }
        $r['groups'] = $groups ?: ['therapist']; // no specialties recorded yet -- default bucket
    }
    unset($r);

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

<!-- ── Row 1: Therapist Rotation, full width, split by specialty group ─────── -->
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">🔄 Therapist Rotation</span>
        <?php if (!empty($roster)): ?>
        <span style="font-size:0.72rem;color:var(--gray);"><?php echo count($roster); ?> on duty</span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <?php if (empty($roster)): ?>
        <p style="text-align:center;color:var(--gray);font-size:0.82rem;padding:1rem 0;">No therapists on duty today.</p>
        <?php else: ?>
        <div class="rotation-groups-grid">
            <?php
            $group_defs = [
                'therapist' => ['💆', 'Therapist'],
                'facial'    => ['🧖', 'Facial'],
                'nail_lash' => ['💅', 'Nail and Lashes'],
                'aesthetic' => ['✨', 'Aesthetic'],
            ];
            foreach ($group_defs as $gkey => [$gicon, $glabel]):
                $g_roster = array_values(array_filter($roster, fn($r) => in_array($gkey, $r['groups'], true)));
                if (empty($g_roster)) continue;
                $g_next = null;
                foreach ($g_roster as $r) {
                    if (!$r['is_on_break'] && !$r['is_assigned'] && empty($r['time_out'])) { $g_next = $r; break; }
                }
            ?>
            <div style="background:var(--bg3);border-radius:8px;padding:0.75rem;">
                <div style="display:flex;flex-direction:column;gap:0.15rem;margin-bottom:0.5rem;">
                    <span style="font-size:0.8rem;font-weight:700;color:var(--brown);"><?php echo $gicon; ?> <?php echo $glabel; ?></span>
                    <span style="font-size:0.72rem;font-weight:700;color:<?php echo $g_next ? 'var(--green)' : '#dc3545'; ?>;">
                        <?php echo $g_next ? 'Next: ' . htmlspecialchars($g_next['full_name']) : 'All Busy'; ?>
                    </span>
                </div>
                <?php foreach ($g_roster as $r): ?>
                <div style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;border-bottom:1px solid var(--border2);">
                    <span style="font-size:0.7rem;color:var(--gray);width:1.2rem;text-align:right;flex-shrink:0;font-weight:600;"><?php echo (int)$r['rotation_order']; ?></span>
                    <span style="flex:1;font-size:0.8rem;color:var(--brown);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($r['full_name']); ?></span>
                    <?php echo $pill($r); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Row 2: Ongoing Sessions + Upcoming, side by side ─────────────────────── -->
<div class="live-panels-grid-2col">

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

</div><!-- /.live-panels-grid-2col -->
    <?php
}

// ── Appointments calendar: month grid + per-day list, shared by page-load and AJAX nav ─
function render_appt_calendar_html(mysqli $conn, int $year, int $month): string {
    if ($month < 1) { $month = 12; $year--; }
    if ($month > 12) { $month = 1; $year++; }
    $first_ts   = mktime(0, 0, 0, $month, 1, $year);
    $days_in_mo = (int)date('t', $first_ts);
    $start_wday = (int)date('w', $first_ts); // 0=Sun
    $ym         = sprintf('%04d-%02d', $year, $month);
    $today_str  = date('Y-m-d');

    $counts = [];
    $stmt = $conn->prepare("SELECT DATE(appointment_date) d, COUNT(*) c FROM appointments WHERE YEAR(appointment_date) = ? AND MONTH(appointment_date) = ? GROUP BY DATE(appointment_date)");
    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $counts[$row['d']] = (int)$row['c'];
    $stmt->close();

    ob_start();
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="apptCalNav(-1)">&lsaquo; Prev</button>
        <strong style="color:var(--brown);font-size:0.95rem;"><?php echo date('F Y', $first_ts); ?></strong>
        <button type="button" class="btn btn-secondary btn-sm" onclick="apptCalNav(1)">Next &rsaquo;</button>
    </div>
    <div class="appt-cal-grid appt-cal-dow">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>
    <div class="appt-cal-grid" data-ym="<?php echo $ym; ?>">
        <?php for ($i = 0; $i < $start_wday; $i++): ?>
        <div class="appt-cal-day appt-cal-blank"></div>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $days_in_mo; $d++):
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $count    = $counts[$date_str] ?? 0;
            $classes  = 'appt-cal-day' . ($date_str === $today_str ? ' today' : '') . ($count > 0 ? ' has-appts' : '');
        ?>
        <div class="<?php echo $classes; ?>" onclick="apptCalShowDay('<?php echo $date_str; ?>')">
            <span class="d-num"><?php echo $d; ?></span>
            <?php if ($count > 0): ?><span class="d-count"><?php echo $count; ?></span><?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
    <?php
    return ob_get_clean();
}

function render_appt_day_list_html(mysqli $conn, string $date_str): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) $date_str = date('Y-m-d');

    $stmt = $conn->prepare("SELECT a.appointment_date, a.status, u.full_name, s.name AS service_name,
            GROUP_CONCAT(DISTINCT t.full_name SEPARATOR ', ') AS therapist_names
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN services s ON a.service_id = s.id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists t ON t.id = at2.therapist_id
        WHERE DATE(a.appointment_date) = ?
        GROUP BY a.id
        ORDER BY a.appointment_date ASC");
    $stmt->bind_param("s", $date_str);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    ob_start();
    ?>
    <div style="font-size:0.85rem;font-weight:700;color:var(--brown);margin-bottom:0.75rem;">
        <?php echo date('F j, Y', strtotime($date_str)); ?>
    </div>
    <?php if (empty($rows)): ?>
        <div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">No appointments on this day.</div>
    <?php else: foreach ($rows as $a): ?>
        <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.5rem 0;border-bottom:1px solid var(--border2);">
            <div style="font-size:0.72rem;font-weight:700;color:var(--gold);white-space:nowrap;min-width:3.5rem;"><?php echo date('h:i A', strtotime($a['appointment_date'])); ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.82rem;font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($a['full_name']); ?></div>
                <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($a['service_name']); ?></div>
                <div style="font-size:0.68rem;color:var(--gray);">💆 <?php echo htmlspecialchars($a['therapist_names'] ?: 'Unassigned'); ?></div>
            </div>
            <span class="badge badge-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span>
        </div>
    <?php endforeach; endif; ?>
    <?php
    return ob_get_clean();
}

// ── AJAX endpoint: access-protected, outputs ONLY the three panels, then exits ─
if (($_GET['ajax'] ?? '') === 'live_panels') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    render_live_panels(run_live_queries($conn));
    exit;
}

if (($_GET['ajax'] ?? '') === 'calendar_month') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    $ym_parts = explode('-', $_GET['ym'] ?? date('Y-m'));
    $yy = isset($ym_parts[0]) ? (int)$ym_parts[0] : (int)date('Y');
    $mm = isset($ym_parts[1]) ? (int)$ym_parts[1] : (int)date('n');
    echo render_appt_calendar_html($conn, $yy, $mm);
    exit;
}

if (($_GET['ajax'] ?? '') === 'calendar_day') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo render_appt_day_list_html($conn, $_GET['date'] ?? date('Y-m-d'));
    exit;
}

if (isset($_GET['logout'])) { logout($conn); }
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

<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">📅 Appointments</span>
        <div style="display:flex;gap:0.6rem;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openApptCalendar()">📅 Calendar</button>
            <button type="button" class="btn btn-secondary btn-sm">Book Now</button>
        </div>
    </div>
</div>

<div id="resourceGridContainer" style="margin-bottom:1.5rem;"><?php require_once __DIR__ . '/_resource_grid.php'; echo render_resource_grid_html($conn); ?></div>
<?php include __DIR__ . '/_resource_grid_js.php'; ?>

<!-- Appointments calendar modal -->
<div class="modal-overlay" id="apptCalendarModal">
    <div class="modal-box" style="max-width:860px;">
        <div class="modal-box-header">
            <span class="modal-box-title">📅 Appointments Calendar</span>
            <button class="modal-box-close" onclick="closeApptCalendar()">✕</button>
        </div>
        <div class="modal-box-body" style="padding:0;">
            <div class="appt-cal-modal-grid">
                <div id="apptCalMonth" style="padding:1.25rem;border-right:1px solid var(--border);">
                    <?php echo render_appt_calendar_html($conn, (int)date('Y'), (int)date('n')); ?>
                </div>
                <div id="apptCalDayList" style="padding:1.25rem;">
                    <div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">Select a day to view its appointments.</div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function openApptCalendar() { document.getElementById('apptCalendarModal').classList.add('active'); }
function closeApptCalendar() { document.getElementById('apptCalendarModal').classList.remove('active'); }

function apptCalNav(dir) {
    var grid = document.querySelector('#apptCalMonth [data-ym]');
    var ym = grid ? grid.getAttribute('data-ym') : '<?php echo date('Y-m'); ?>';
    var parts = ym.split('-');
    var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10) + dir;
    if (m < 1) { m = 12; y--; } else if (m > 12) { m = 1; y++; }
    var newYm = y + '-' + (m < 10 ? '0' + m : m);
    fetch('index.php?ajax=calendar_month&ym=' + newYm, { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            document.getElementById('apptCalMonth').innerHTML = html;
            document.getElementById('apptCalDayList').innerHTML = '<div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">Select a day to view its appointments.</div>';
        });
}

function apptCalShowDay(dateStr) {
    document.getElementById('apptCalDayList').innerHTML = '<div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">Loading&hellip;</div>';
    fetch('index.php?ajax=calendar_day&date=' + encodeURIComponent(dateStr), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { document.getElementById('apptCalDayList').innerHTML = html; });
}
</script>

<style>
.rotation-groups-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
@media (max-width:1100px) { .rotation-groups-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:600px)  { .rotation-groups-grid { grid-template-columns:1fr; } }
.live-panels-grid-2col { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; }
@media (max-width:700px)  { .live-panels-grid-2col { grid-template-columns:1fr; } }
.stats-grid-5 { margin-bottom:1.5rem; }
@media (max-width:1100px) { .stats-grid-5 { grid-template-columns:repeat(3,1fr) !important; } }
@media (max-width:700px)  { .stats-grid-5 { grid-template-columns:repeat(2,1fr) !important; } }
@media (max-width:480px)  { .stats-grid-5 { grid-template-columns:1fr !important; } }

.appt-cal-modal-grid { display:grid; grid-template-columns:1.4fr 1fr; }
@media (max-width:640px) { .appt-cal-modal-grid { grid-template-columns:1fr; } }
.appt-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.appt-cal-dow { text-align:center; font-size:0.68rem; font-weight:700; color:var(--gray); margin-bottom:4px; }
.appt-cal-day {
    position:relative; aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
    border-radius:8px; font-size:0.78rem; color:var(--brown); background:var(--bg3); border:1px solid transparent;
    cursor:pointer;
}
.appt-cal-day:hover { background:#f0e4d3; }
.appt-cal-day.appt-cal-blank { background:transparent; cursor:default; }
.appt-cal-day.today { border-color:var(--gold); font-weight:700; }
.appt-cal-day.has-appts { background:#FDE8D8; }
.appt-cal-day.has-appts:hover { background:#f8d1ad; }
.appt-cal-day .d-count {
    font-size:0.6rem; font-weight:700; color:#fff; background:#C96A2C; border-radius:10px;
    padding:0 0.3rem; line-height:1.3; margin-top:0.1rem;
}
.badge-assigned { background:#cfe2ff; color:#084298; }
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