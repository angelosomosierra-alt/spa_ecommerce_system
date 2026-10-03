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
        <strong style="color:var(--brown);font-size:1.25rem;"><?php echo date('F Y', $first_ts); ?></strong>
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
        <div class="<?php echo $classes; ?>" onclick="apptCalShowDay('<?php echo $date_str; ?>', this)">
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

    $stmt = $conn->prepare("SELECT a.appointment_date, a.status,
            COALESCE(u.full_name, 'Unknown customer') AS full_name,
            COALESCE(s.name, 'Unknown service') AS service_name,
            (SELECT GROUP_CONCAT(DISTINCT t.full_name SEPARATOR ', ')
             FROM appointment_therapists at2
             JOIN therapists t ON t.id = at2.therapist_id
             WHERE at2.appointment_id = a.id) AS therapist_names
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE DATE(a.appointment_date) = ?
        ORDER BY a.appointment_date ASC");
    $stmt->bind_param("s", $date_str);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $is_future_or_today = $date_str >= date('Y-m-d');

    ob_start();
    ?>
    <div class="appt-cal-day-head"><?php echo date('F j, Y', strtotime($date_str)); ?></div>
    <div class="appt-cal-day-scroll">
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
    </div>
    <?php if ($is_future_or_today): ?>
    <div class="appt-cal-day-booknow">
        <button type="button" class="btn btn-primary btn-full" style="font-size:1.1rem;padding:1.1rem 1.5rem;" onclick="openQuickBook('<?php echo $date_str; ?>')">Book Now &mdash; <?php echo date('M j', strtotime($date_str)); ?></button>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ── Quick Book: compact walk-in booking (date/time -> service -> therapist &
// room -> payment), no slot-conflict checking — the receptionist has already
// confirmed availability via the calendar/resource grid before opening this. ─
function render_quick_book_services_html(mysqli $conn): string {
    $services = $conn->query("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON s.category_id = c.id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name")->fetch_all(MYSQLI_ASSOC);

    $by_cat = [];
    foreach ($services as $svc) {
        $key = $svc['category_id'] ? (int)$svc['category_id'] : 0;
        if (!isset($by_cat[$key])) $by_cat[$key] = ['label' => $svc['category_name'] ?: 'Other', 'items' => []];
        $by_cat[$key]['items'][] = $svc;
    }

    ob_start();
    ?>
    <div class="qb-svc-layout">
        <nav class="qb-svc-sidebar">
            <?php foreach ($by_cat as $cat_id => $cat_data): ?>
            <a href="#qb-cat-<?php echo $cat_id; ?>" class="qb-svc-cat-btn" data-cat-id="<?php echo $cat_id; ?>"><?php echo htmlspecialchars($cat_data['label']); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="qb-svc-content">
            <?php if (empty($by_cat)): ?>
            <div style="color:var(--gray);text-align:center;padding:2rem;">No services available.</div>
            <?php endif; ?>
            <?php foreach ($by_cat as $cat_id => $cat_data): ?>
            <section id="qb-cat-<?php echo $cat_id; ?>" data-cat-section="<?php echo $cat_id; ?>">
                <h4 class="qb-svc-cat-title"><?php echo htmlspecialchars($cat_data['label']); ?></h4>
                <?php foreach ($cat_data['items'] as $svc): ?>
                <div class="qb-svc-row" data-service-id="<?php echo $svc['id']; ?>"
                     data-service-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8'); ?>"
                     data-price="<?php echo (float)$svc['price']; ?>"
                     data-minutes="<?php echo (int)$svc['session_time']; ?>"
                     onclick="qbSelectService(this)">
                    <span class="qb-svc-row-name"><?php echo htmlspecialchars($svc['name']); ?></span>
                    <span class="qb-svc-row-meta"><?php echo (int)$svc['session_time']; ?> min</span>
                    <span class="qb-svc-row-price">₱<?php echo number_format($svc['price'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function quick_book_qualified_therapists(mysqli $conn, int $service_id): array {
    $stmt = $conn->prepare("
        SELECT t.id, t.full_name,
               CASE WHEN ta.therapist_id IS NOT NULL AND (ta.time_out IS NULL OR ta.time_out = '') THEN 1 ELSE 0 END AS on_duty
        FROM therapists t
        LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
        WHERE t.is_generalist = 1
           OR EXISTS(SELECT 1 FROM therapist_specialty_services WHERE therapist_id = t.id AND service_id = ?)
           OR EXISTS(SELECT 1 FROM therapist_specialties ts JOIN services s ON s.category_id = ts.category_id WHERE ts.therapist_id = t.id AND s.id = ?)
        ORDER BY on_duty DESC, t.full_name ASC
    ");
    $stmt->bind_param("ii", $service_id, $service_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function quick_book_active_resources(mysqli $conn): array {
    return $conn->query("SELECT id, name, type FROM service_resources WHERE is_active = 1 ORDER BY FIELD(type,'room','chair','head_spa'), sort_order, name")->fetch_all(MYSQLI_ASSOC);
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

if (($_GET['ajax'] ?? '') === 'quick_book_pickers') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $service_id = (int)($_GET['service_id'] ?? 0);
    echo json_encode([
        'therapists' => $service_id > 0 ? quick_book_qualified_therapists($conn, $service_id) : [],
        'resources'  => quick_book_active_resources($conn),
    ]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'quick_book_submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    verify_csrf_token();

    $date           = $_POST['date'] ?? '';
    $time           = $_POST['time'] ?? '';
    $service_id     = (int)($_POST['service_id'] ?? 0);
    $therapist_id   = (int)($_POST['therapist_id'] ?? 0);
    $resource_id    = (int)($_POST['resource_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $valid_payment_methods = ['cash', 'gcash', 'maya', 'qrph', 'bank', 'card'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        echo json_encode(['ok' => false, 'message' => 'Please choose a valid date and time.']); exit;
    }
    if ($service_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a service.']); exit; }
    if ($therapist_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a therapist.']); exit; }
    if ($resource_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a room, chair, or head spa.']); exit; }
    if (!in_array($payment_method, $valid_payment_methods, true)) { echo json_encode(['ok' => false, 'message' => 'Please choose a payment method.']); exit; }

    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$service) { echo json_encode(['ok' => false, 'message' => 'That service is no longer available.']); exit; }

    $price             = (float)$service['price'];
    $duration_minutes  = (int)$service['session_time'];
    $appointment_date  = $date . ' ' . $time . ':00';
    $walkin_user_id    = get_walkin_customer_id();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, booking_date, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number) VALUES (?, 'Walk-in Customer', 'N/A', ?, ?, ?, 'paid', 'approved', 'none', 0, ?, NULL)");
        $stmt->bind_param("isdsd", $walkin_user_id, $appointment_date, $price, $payment_method, $price);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
        $stmt->bind_param("iidd", $order_id, $service_id, $price, $price);
        $stmt->execute();
        $order_item_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count, service_type, rate_type, charged_price, resource_id, duration_minutes) VALUES (?, ?, ?, ?, 'assigned', 1, 'onsite', 'regular', ?, ?, ?)");
        $stmt->bind_param("iiisdii", $walkin_user_id, $service_id, $order_item_id, $appointment_date, $price, $resource_id, $duration_minutes);
        $stmt->execute();
        $appointment_id = $stmt->insert_id;
        $stmt->close();

        $commission = 0.00;
        $cm = $conn->prepare("SELECT commission_percent FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
        $cm->bind_param("ii", $therapist_id, $service_id);
        $cm->execute();
        $cm_row = $cm->get_result()->fetch_assoc();
        $cm->close();
        if ($cm_row) {
            $comm_base  = get_commission_base_price($service_id, null, $price);
            $commission = round($comm_base * (float)$cm_row['commission_percent'] / 100, 2);
        }

        $stmt = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, commission, people_handled, notes) VALUES (?, ?, ?, 1, '')");
        $stmt->bind_param("iid", $appointment_id, $therapist_id, $commission);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        echo json_encode(['ok' => true, 'message' => 'Appointment booked.', 'appointment_id' => $appointment_id]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'message' => 'Could not save the booking. Please try again.']);
    }
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
        <span class="panel-title">Appointments</span>
        <div style="display:flex;gap:0.6rem;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openApptCalendar()">Calendar</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openQuickBook()">Book Now</button>
        </div>
    </div>
</div>

<div id="resourceGridContainer" style="margin-bottom:1.5rem;"><?php require_once __DIR__ . '/_resource_grid.php'; echo render_resource_grid_html($conn); ?></div>
<?php include __DIR__ . '/_resource_grid_js.php'; ?>

<!-- Appointments calendar modal -->
<div class="modal-overlay" id="apptCalendarModal">
    <div class="modal-box" style="max-width:1720px;width:95vw;">
        <div class="modal-box-header">
            <span class="modal-box-title">Appointments Calendar</span>
            <button class="modal-box-close" onclick="closeApptCalendar()">✕</button>
        </div>
        <div class="modal-box-body" style="padding:0;">
            <div class="appt-cal-modal-grid">
                <div id="apptCalMonth" class="appt-cal-month-pane">
                    <?php echo render_appt_calendar_html($conn, (int)date('Y'), (int)date('n')); ?>
                </div>
                <div id="apptCalDayList" class="appt-cal-day-pane">
                    <div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">Select a day to view its appointments.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Book (walk-in) modal -->
<div class="modal-overlay" id="quickBookModal">
    <div class="modal-box" style="max-width:1720px;width:95vw;">
        <div class="modal-box-header">
            <span class="modal-box-title">Book Now</span>
            <button class="modal-box-close" onclick="closeQuickBook()">✕</button>
        </div>
        <div class="modal-box-body" style="padding:0;">
            <div class="qb-steps">
                <span class="qb-step" data-step="1">1. Date &amp; Time</span>
                <span class="qb-step" data-step="2">2. Service</span>
                <span class="qb-step" data-step="3">3. Therapist &amp; Room</span>
                <span class="qb-step" data-step="4">4. Payment</span>
            </div>

            <div class="qb-panel" id="qbPanel1">
                <div class="qb-field-row">
                    <div class="qb-field">
                        <label for="qbDate">Date</label>
                        <input type="date" id="qbDate">
                    </div>
                    <div class="qb-field">
                        <label for="qbTime">Time</label>
                        <input type="time" id="qbTime">
                    </div>
                </div>
            </div>

            <div class="qb-panel" id="qbPanel2" style="display:none;">
                <?php echo render_quick_book_services_html($conn); ?>
            </div>

            <div class="qb-panel" id="qbPanel3" style="display:none;">
                <div class="qb-field-row">
                    <div class="qb-field">
                        <label for="qbTherapist">Therapist</label>
                        <select id="qbTherapist"><option value="">Loading&hellip;</option></select>
                    </div>
                    <div class="qb-field">
                        <label for="qbResource">Room / Chair / Head Spa</label>
                        <select id="qbResource"><option value="">Loading&hellip;</option></select>
                    </div>
                </div>
            </div>

            <div class="qb-panel" id="qbPanel4" style="display:none;">
                <div class="qb-summary" id="qbSummary"></div>
                <div class="qb-field" style="margin-top:1.25rem;">
                    <label>Payment Method</label>
                    <div class="qb-pay-grid">
                        <button type="button" class="qb-pay-btn" data-method="cash" onclick="qbSelectPayment(this)">Cash</button>
                        <button type="button" class="qb-pay-btn" data-method="gcash" onclick="qbSelectPayment(this)">GCash</button>
                        <button type="button" class="qb-pay-btn" data-method="maya" onclick="qbSelectPayment(this)">Maya</button>
                        <button type="button" class="qb-pay-btn" data-method="qrph" onclick="qbSelectPayment(this)">QRPH</button>
                        <button type="button" class="qb-pay-btn" data-method="bank" onclick="qbSelectPayment(this)">Bank</button>
                        <button type="button" class="qb-pay-btn" data-method="card" onclick="qbSelectPayment(this)">Card</button>
                    </div>
                </div>
                <div id="qbError" class="qb-error" style="display:none;"></div>
            </div>
        </div>
        <div class="modal-box-footer">
            <button type="button" class="btn btn-secondary" id="qbBackBtn" onclick="qbBack()" style="display:none;">Back</button>
            <button type="button" class="btn btn-primary" id="qbNextBtn" onclick="qbNext()">Next</button>
            <button type="button" class="btn btn-primary" id="qbConfirmBtn" onclick="qbSubmit()" style="display:none;">Confirm Booking</button>
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

function apptCalShowDay(dateStr, el) {
    document.querySelectorAll('.appt-cal-day.selected').forEach(function (d) { d.classList.remove('selected'); });
    if (el) el.classList.add('selected');
    document.getElementById('apptCalDayList').innerHTML = '<div style="color:var(--gray);font-size:0.82rem;text-align:center;padding:2rem 0;">Loading&hellip;</div>';
    fetch('index.php?ajax=calendar_day&date=' + encodeURIComponent(dateStr), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { document.getElementById('apptCalDayList').innerHTML = html; });
}
</script>

<script>
var QB_CSRF = '<?php echo generate_csrf_token(); ?>';
var qbState = {};
var qbStep  = 1;

function openQuickBook(prefillDate) {
    qbState = { date: prefillDate || '', time: '', service_id: 0, service_name: '', price: 0, minutes: 0,
                therapist_id: 0, therapist_name: '', resource_id: 0, resource_name: '', payment_method: '' };
    document.getElementById('qbDate').value = prefillDate || new Date().toISOString().slice(0, 10);
    var now = new Date();
    document.getElementById('qbTime').value = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    document.querySelectorAll('.qb-svc-row.selected').forEach(function (r) { r.classList.remove('selected'); });
    document.querySelectorAll('.qb-pay-btn.selected').forEach(function (b) { b.classList.remove('selected'); });
    document.getElementById('qbTherapist').innerHTML = '<option value="">Select therapist&hellip;</option>';
    document.getElementById('qbResource').innerHTML  = '<option value="">Select room/chair&hellip;</option>';
    document.getElementById('qbSummary').innerHTML = '';
    document.getElementById('qbError').style.display = 'none';
    var confirmBtn = document.getElementById('qbConfirmBtn');
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Confirm Booking';
    qbGotoStep(1);
    document.getElementById('quickBookModal').classList.add('active');
}

function closeQuickBook() { document.getElementById('quickBookModal').classList.remove('active'); }

function qbGotoStep(n) {
    qbStep = n;
    for (var i = 1; i <= 4; i++) {
        document.getElementById('qbPanel' + i).style.display = (i === n) ? '' : 'none';
        var stepEl = document.querySelector('.qb-step[data-step="' + i + '"]');
        stepEl.classList.toggle('active', i === n);
        stepEl.classList.toggle('done', i < n);
    }
    document.getElementById('qbBackBtn').style.display    = (n === 1) ? 'none' : '';
    document.getElementById('qbNextBtn').style.display    = (n === 4) ? 'none' : '';
    document.getElementById('qbConfirmBtn').style.display = (n === 4) ? '' : 'none';
}

function qbBack() { if (qbStep > 1) qbGotoStep(qbStep - 1); }

function qbNext() {
    if (qbStep === 1) {
        var d = document.getElementById('qbDate').value, t = document.getElementById('qbTime').value;
        if (!d || !t) { uiAlert('Please choose a date and time.'); return; }
        qbState.date = d; qbState.time = t;
        qbGotoStep(2);
    } else if (qbStep === 2) {
        if (!qbState.service_id) { uiAlert('Please choose a service.'); return; }
        qbLoadPickers();
    } else if (qbStep === 3) {
        var thSel = document.getElementById('qbTherapist'), rsSel = document.getElementById('qbResource');
        if (!thSel.value) { uiAlert('Please choose a therapist.'); return; }
        if (!rsSel.value) { uiAlert('Please choose a room, chair, or head spa.'); return; }
        qbState.therapist_id   = thSel.value;
        qbState.therapist_name = thSel.options[thSel.selectedIndex].text;
        qbState.resource_id    = rsSel.value;
        qbState.resource_name  = rsSel.options[rsSel.selectedIndex].text;
        qbBuildSummary();
        qbGotoStep(4);
    }
}

function qbSelectService(el) {
    document.querySelectorAll('.qb-svc-row.selected').forEach(function (r) { r.classList.remove('selected'); });
    el.classList.add('selected');
    qbState.service_id   = el.getAttribute('data-service-id');
    qbState.service_name = el.getAttribute('data-service-name');
    qbState.price         = parseFloat(el.getAttribute('data-price'));
    qbState.minutes        = parseInt(el.getAttribute('data-minutes'), 10);
}

function qbLoadPickers() {
    document.getElementById('qbTherapist').innerHTML = '<option value="">Loading&hellip;</option>';
    document.getElementById('qbResource').innerHTML  = '<option value="">Loading&hellip;</option>';
    qbGotoStep(3);
    fetch('index.php?ajax=quick_book_pickers&service_id=' + encodeURIComponent(qbState.service_id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var thSel = document.getElementById('qbTherapist');
            thSel.innerHTML = '<option value="">Select therapist&hellip;</option>' + data.therapists.map(function (t) {
                return '<option value="' + t.id + '">' + t.full_name + (t.on_duty == 1 ? ' (on duty)' : '') + '</option>';
            }).join('');
            var rsSel = document.getElementById('qbResource');
            var typeLabels = { room: 'Room', chair: 'Chair', head_spa: 'Head Spa' };
            rsSel.innerHTML = '<option value="">Select room/chair&hellip;</option>' + data.resources.map(function (r) {
                return '<option value="' + r.id + '">' + (typeLabels[r.type] || r.type) + ' — ' + r.name + '</option>';
            }).join('');
        })
        .catch(function () { uiAlert('Could not load therapists/rooms. Please try again.'); });
}

function qbBuildSummary() {
    var dt = new Date(qbState.date + 'T' + qbState.time);
    var dateLabel = dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    var timeLabel = dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    document.getElementById('qbSummary').innerHTML =
        '<div class="qb-summary-row"><span>Date &amp; Time</span><strong>' + dateLabel + ', ' + timeLabel + '</strong></div>' +
        '<div class="qb-summary-row"><span>Service</span><strong>' + qbState.service_name + ' (' + qbState.minutes + ' min)</strong></div>' +
        '<div class="qb-summary-row"><span>Therapist</span><strong>' + qbState.therapist_name + '</strong></div>' +
        '<div class="qb-summary-row"><span>Room</span><strong>' + qbState.resource_name + '</strong></div>' +
        '<div class="qb-summary-row qb-summary-total"><span>Total</span><strong>₱' + qbState.price.toFixed(2) + '</strong></div>';
}

function qbSelectPayment(el) {
    document.querySelectorAll('.qb-pay-btn.selected').forEach(function (b) { b.classList.remove('selected'); });
    el.classList.add('selected');
    qbState.payment_method = el.getAttribute('data-method');
}

function qbSubmit() {
    if (!qbState.payment_method) { uiAlert('Please choose a payment method.'); return; }
    var errEl = document.getElementById('qbError');
    errEl.style.display = 'none';
    var confirmBtn = document.getElementById('qbConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Booking…';

    var body = new URLSearchParams({
        date: qbState.date, time: qbState.time, service_id: qbState.service_id,
        therapist_id: qbState.therapist_id, resource_id: qbState.resource_id,
        payment_method: qbState.payment_method
    });

    fetch('index.php?ajax=quick_book_submit', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': QB_CSRF },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeQuickBook();
                uiAlert('Appointment booked successfully.').then(function () { location.reload(); });
            } else {
                errEl.textContent = data.message || 'Could not save the booking.';
                errEl.style.display = '';
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm Booking';
            }
        })
        .catch(function () {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = '';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm Booking';
        });
}

(function () {
    var reduceMotionQB = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('.qb-svc-cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.getElementById('qb-cat-' + btn.dataset.catId);
            if (target) target.scrollIntoView({ behavior: reduceMotionQB ? 'auto' : 'smooth', block: 'start' });
        });
    });
}());
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

.appt-cal-modal-grid { display:grid; grid-template-columns:1.4fr 1fr; min-height:720px; }
@media (max-width:640px) { .appt-cal-modal-grid { grid-template-columns:1fr; min-height:0; } }
.appt-cal-month-pane { padding:1.5rem; border-right:1px solid var(--border); }
.appt-cal-day-pane { display:flex; flex-direction:column; min-height:720px; max-height:80vh; }
@media (max-width:640px) { .appt-cal-day-pane { max-height:60vh; } }
.appt-cal-day-head { padding:1.5rem 1.5rem 0.75rem; font-size:1rem; font-weight:700; color:var(--brown); flex-shrink:0; }
.appt-cal-day-scroll { flex:1; overflow-y:auto; padding:0 1.5rem; }
.appt-cal-day-booknow { flex-shrink:0; padding:1rem 1.5rem; border-top:1px solid var(--border); background:var(--bg3); }
.appt-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; }
.appt-cal-dow { text-align:center; font-size:0.85rem; font-weight:700; color:var(--gray); margin-bottom:8px; }
.appt-cal-day {
    position:relative; aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
    border-radius:10px; font-size:1.05rem; color:var(--brown); background:var(--bg3); border:1px solid transparent;
    cursor:pointer;
}
.appt-cal-day:hover { background:#f0e4d3; }
.appt-cal-day.appt-cal-blank { background:transparent; cursor:default; }
.appt-cal-day.today { border-color:var(--gold); font-weight:700; }
.appt-cal-day.has-appts { background:#FDE8D8; }
.appt-cal-day.has-appts:hover { background:#f8d1ad; }
.appt-cal-day.selected { background:#C96A2C; color:#fff; }
.appt-cal-day.selected:hover { background:#A94F1D; }
.appt-cal-day .d-count {
    font-size:0.75rem; font-weight:700; color:#fff; background:#C96A2C; border-radius:10px;
    padding:0 0.4rem; line-height:1.4; margin-top:0.15rem;
}
.badge-assigned { background:#cfe2ff; color:#084298; }

/* ── Quick Book modal ───────────────────────────────────────────────── */
.qb-steps { display:flex; border-bottom:1px solid var(--border); background:var(--bg3); }
.qb-step {
    flex:1; text-align:center; padding:1rem 0.5rem; font-size:0.85rem; font-weight:600;
    color:var(--gray); border-bottom:3px solid transparent;
}
.qb-step.active { color:var(--brown); border-bottom-color:#C96A2C; }
.qb-step.done { color:var(--brown); }
.qb-panel { padding:1.75rem; min-height:420px; }
.qb-field-row { display:flex; gap:1.5rem; flex-wrap:wrap; }
.qb-field { flex:1; min-width:220px; }
.qb-field label { display:block; font-size:0.85rem; font-weight:700; color:var(--brown-md); margin-bottom:0.5rem; }
.qb-field input, .qb-field select {
    width:100%; padding:0.85rem 1rem; border:1.5px solid var(--border); border-radius:10px;
    font-family:inherit; font-size:1.05rem; color:var(--brown); background:#fff; box-sizing:border-box;
}
.qb-field input:focus, .qb-field select:focus { outline:none; border-color:#C96A2C; }

.qb-svc-layout { display:grid; grid-template-columns:220px 1fr; gap:0; min-height:420px; max-height:60vh; }
.qb-svc-sidebar { display:flex; flex-direction:column; border-right:1px solid var(--border); overflow-y:auto; }
.qb-svc-cat-btn {
    padding:0.9rem 1.25rem; font-weight:600; color:var(--brown); text-decoration:none;
    border-bottom:1px solid var(--border); border-left:3px solid transparent;
}
.qb-svc-cat-btn:hover { background:var(--bg3); }
.qb-svc-content { overflow-y:auto; padding:0 1.5rem; }
.qb-svc-cat-title { font-size:1rem; font-weight:700; color:var(--brown); margin:1.25rem 0 0.5rem; }
.qb-svc-row {
    display:flex; align-items:center; gap:1rem; padding:0.9rem 0.75rem; border-radius:10px;
    cursor:pointer; border:1.5px solid transparent;
}
.qb-svc-row:hover { background:var(--bg3); }
.qb-svc-row.selected { background:#FDE8D8; border-color:#C96A2C; }
.qb-svc-row-name { flex:1; font-weight:600; color:var(--brown); }
.qb-svc-row-meta { font-size:0.82rem; color:var(--gray); white-space:nowrap; }
.qb-svc-row-price { font-weight:700; color:var(--rust-dark); white-space:nowrap; }

.qb-summary { background:var(--bg3); border:1px solid var(--border); border-radius:10px; padding:1.25rem 1.5rem; }
.qb-summary-row { display:flex; justify-content:space-between; padding:0.5rem 0; font-size:0.95rem; color:var(--brown); border-bottom:1px solid var(--border); }
.qb-summary-row:last-child { border-bottom:none; }
.qb-summary-total { font-size:1.1rem; }
.qb-pay-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; }
.qb-pay-btn {
    padding:1rem; border:1.5px solid var(--border); border-radius:10px; background:#fff;
    font-size:1rem; font-weight:600; color:var(--brown); cursor:pointer;
}
.qb-pay-btn:hover { background:var(--bg3); }
.qb-pay-btn.selected { background:#C96A2C; color:#fff; border-color:#C96A2C; }
.qb-error { margin-top:1rem; padding:0.85rem 1rem; background:#f8d7da; color:#842029; border-radius:10px; font-size:0.9rem; }
@media (max-width:640px) {
    .qb-svc-layout { grid-template-columns:1fr; max-height:none; }
    .qb-svc-sidebar { flex-direction:row; flex-wrap:wrap; overflow-x:auto; }
    .qb-pay-grid { grid-template-columns:repeat(2,1fr); }
}
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