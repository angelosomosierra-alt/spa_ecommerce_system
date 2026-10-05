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
        <span class="panel-title">Therapist Rotation</span>
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
                'therapist' => 'Therapist',
                'facial'    => 'Facial',
                'nail_lash' => 'Nail and Lashes',
                'aesthetic' => 'Aesthetic',
            ];
            foreach ($group_defs as $gkey => $glabel):
                $g_roster = array_values(array_filter($roster, fn($r) => in_array($gkey, $r['groups'], true)));
                if (empty($g_roster)) continue;
                $g_next = null;
                foreach ($g_roster as $r) {
                    if (!$r['is_on_break'] && !$r['is_assigned'] && empty($r['time_out'])) { $g_next = $r; break; }
                }
            ?>
            <div style="background:var(--bg3);border-radius:8px;padding:0.75rem;">
                <div style="display:flex;flex-direction:column;gap:0.15rem;margin-bottom:0.5rem;">
                    <span style="font-size:0.8rem;font-weight:700;color:var(--brown);"><?php echo $glabel; ?></span>
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
        <span class="panel-title">Ongoing Sessions</span>
        <?php if (!empty($ongoing)): ?>
        <span style="background:#dc3545;color:#fff;font-size:0.72rem;padding:0.2rem 0.55rem;border-radius:20px;font-weight:700;"><?php echo count($ongoing); ?></span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <?php if (empty($ongoing)): ?>
        <div style="text-align:center;padding:2rem 1rem;color:var(--gray);">
            <div style="font-size:1.75rem;margin-bottom:0.35rem;"></div>
            <div style="font-size:0.82rem;">No sessions in progress.</div>
        </div>
        <?php else: foreach ($ongoing as $s): ?>
        <div style="background:var(--bg3);border-radius:8px;padding:0.65rem 0.75rem;margin-bottom:0.5rem;border-left:3px solid #dc3545;">
            <div style="font-weight:600;font-size:0.82rem;color:var(--brown);"><?php echo htmlspecialchars($s['customer_name']); ?></div>
            <div style="font-size:0.75rem;color:var(--gray);margin:0.1rem 0;"><?php echo htmlspecialchars($s['service_name']); ?></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.3rem;">
                <span style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($s['therapists'] ?? '&mdash;'); ?></span>
                <span style="font-size:0.72rem;font-weight:700;color:#dc3545;white-space:nowrap;"><?php echo (int)$s['minutes_remaining']; ?> min left</span>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Upcoming (today)</span>
        <?php if (!empty($upcoming)): ?>
        <span style="background:#0070f3;color:#fff;font-size:0.72rem;padding:0.2rem 0.55rem;border-radius:20px;font-weight:700;"><?php echo count($upcoming); ?></span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0.75rem;">
        <?php if (empty($upcoming)): ?>
        <div style="text-align:center;padding:2rem 1rem;color:var(--gray);">
            <div style="font-size:1.75rem;margin-bottom:0.35rem;"></div>
            <div style="font-size:0.82rem;">No more sessions today.</div>
        </div>
        <?php else: foreach ($upcoming as $s): ?>
        <div style="display:flex;align-items:flex-start;gap:0.65rem;padding:0.4rem 0;border-bottom:1px solid var(--border2);">
            <div style="font-size:0.72rem;font-weight:700;color:#0070f3;white-space:nowrap;padding-top:0.1rem;min-width:3.5rem;"><?php echo date('h:i A', strtotime($s['appointment_date'])); ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['customer_name']); ?></div>
                <div style="font-size:0.72rem;color:var(--gray);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['service_name']); ?></div>
                <div style="font-size:0.68rem;color:var(--gray);"><?php echo htmlspecialchars($s['therapists']); ?></div>
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
                <div style="font-size:0.68rem;color:var(--gray);"><?php echo htmlspecialchars($a['therapist_names'] ?: 'Unassigned'); ?></div>
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
                     data-home-price="<?php echo (float)($svc['home_service_price'] ?? 0); ?>"
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

function quick_book_partners(mysqli $conn): array {
    return $conn->query("SELECT id, name, type FROM partners WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

function quick_book_partner_rate(mysqli $conn, int $partner_id, int $service_id): ?float {
    $stmt = $conn->prepare("SELECT price FROM partner_rates WHERE partner_id = ? AND service_id = ? LIMIT 1");
    $stmt->bind_param("ii", $partner_id, $service_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['price'] : null;
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

if (($_GET['ajax'] ?? '') === 'quick_book_partner_rate') {
    redirect_if_not_admin();
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $service_id = (int)($_GET['service_id'] ?? 0);
    $partner_id = (int)($_GET['partner_id'] ?? 0);
    $rate = ($service_id > 0 && $partner_id > 0) ? quick_book_partner_rate($conn, $partner_id, $service_id) : null;
    echo json_encode(['rate' => $rate]);
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
    $rate_type      = $_POST['rate_type'] ?? 'regular';
    $partner_id     = (int)($_POST['partner_id'] ?? 0);
    $customer_name  = sanitize_input($_POST['customer_name'] ?? '');
    $phone          = sanitize_input($_POST['phone'] ?? '');
    $customer_note  = sanitize_input($_POST['customer_note'] ?? '');
    $discount_type  = $_POST['discount_type'] ?? 'none';
    $voucher_type_i = $_POST['voucher_type'] ?? 'cash';
    $voucher_value  = max(0.0, floatval($_POST['voucher_value'] ?? 0));
    $payment_method = $_POST['payment_method'] ?? '';
    $advance_payment = max(0.0, floatval($_POST['advance_payment'] ?? 0));
    $advance_pm      = $_POST['advance_payment_method'] ?? 'cash';

    $valid_payment_methods = ['cash', 'gcash', 'maya', 'qrph', 'card', 'swiper'];
    $valid_rate_types      = ['regular', 'home', 'hotel', 'influencer'];
    $valid_discount_types  = ['none', 'voucher', 'senior', 'pwd', 'employee', 'celebration'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        echo json_encode(['ok' => false, 'message' => 'Please choose a valid date and time.']); exit;
    }
    if ($service_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a service.']); exit; }
    if ($therapist_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a therapist.']); exit; }
    if ($resource_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please choose a room, chair, or head spa.']); exit; }
    if (!in_array($rate_type, $valid_rate_types, true)) { $rate_type = 'regular'; }
    if ($rate_type === 'hotel' && $partner_id <= 0) { echo json_encode(['ok' => false, 'message' => 'Please select a hotel/partner for the Hotel rate.']); exit; }
    if (empty($customer_name)) { echo json_encode(['ok' => false, 'message' => 'Please enter the customer\'s name.']); exit; }
    if (!in_array($discount_type, $valid_discount_types, true)) { $discount_type = 'none'; }
    if ($discount_type === 'voucher' && $voucher_value <= 0) { echo json_encode(['ok' => false, 'message' => 'Please enter the voucher amount, or select None if no voucher is used.']); exit; }
    if ($discount_type === 'celebration' && $voucher_value <= 0) { echo json_encode(['ok' => false, 'message' => 'Please enter the celebration discount percentage, or select None if no discount is used.']); exit; }
    if (!in_array($payment_method, $valid_payment_methods, true)) { echo json_encode(['ok' => false, 'message' => 'Please choose a payment method.']); exit; }
    if (!in_array($advance_pm, $valid_payment_methods, true)) { $advance_pm = 'cash'; }

    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$service) { echo json_encode(['ok' => false, 'message' => 'That service is no longer available.']); exit; }

    switch ($rate_type) {
        case 'home':       $charged_price = (float)($service['home_service_price'] ?? 0); break;
        case 'hotel':      $charged_price = quick_book_partner_rate($conn, $partner_id, $service_id) ?? (float)$service['price']; break;
        case 'influencer': $charged_price = 0.00; break;
        default:           $charged_price = (float)$service['price']; break;
    }

    $discount_amount_calc = 0.00;
    if ($discount_type === 'senior' || $discount_type === 'pwd') {
        $discount_amount_calc = round($charged_price * 0.20, 2);
    } elseif ($discount_type === 'employee') {
        $discount_amount_calc = round($charged_price * 0.50, 2);
    } elseif ($discount_type === 'celebration' && $voucher_value > 0) {
        $discount_amount_calc = round($charged_price * ($voucher_value / 100), 2);
    } elseif ($discount_type === 'voucher' && $voucher_value > 0) {
        $discount_amount_calc = $voucher_type_i === 'percent'
            ? round($charged_price * ($voucher_value / 100), 2)
            : min($voucher_value, $charged_price);
    }
    $final_amount = max(0.00, $charged_price - $discount_amount_calc - $advance_payment);

    $duration_minutes  = (int)$service['session_time'];
    $appointment_date  = $date . ' ' . $time . ':00';
    $svc_type_val      = ($rate_type === 'home') ? 'home' : 'onsite';
    $appt_partner_id   = ($rate_type === 'hotel' && $partner_id > 0) ? $partner_id : null;
    $walkin_user_id    = get_walkin_customer_id();
    $adv_pm_val        = $advance_payment > 0 ? $advance_pm : 'cash';
    $adv_date          = $advance_payment > 0 ? date('Y-m-d') : null;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, booking_date, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number) VALUES (?, ?, ?, ?, ?, ?, 'paid', 'approved', ?, ?, ?, NULL)");
        $stmt->bind_param("isssdssdd", $walkin_user_id, $customer_name, $phone, $appointment_date, $charged_price, $payment_method, $discount_type, $discount_amount_calc, $final_amount);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
        $stmt->bind_param("iidd", $order_id, $service_id, $charged_price, $charged_price);
        $stmt->execute();
        $order_item_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count, service_type, rate_type, partner_id, charged_price, customer_note, advance_payment, advance_payment_date, advance_payment_method, resource_id, duration_minutes) VALUES (?, ?, ?, ?, 'assigned', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisssidsdssii", $walkin_user_id, $service_id, $order_item_id, $appointment_date, $svc_type_val, $rate_type, $appt_partner_id, $charged_price, $customer_note, $advance_payment, $adv_date, $adv_pm_val, $resource_id, $duration_minutes);
        $stmt->execute();
        $appointment_id = $stmt->insert_id;
        $stmt->close();

        $commission = 0.00;
        $cm = $conn->prepare("SELECT commission_percent, influencer_flat_rate FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
        $cm->bind_param("ii", $therapist_id, $service_id);
        $cm->execute();
        $cm_row = $cm->get_result()->fetch_assoc();
        $cm->close();
        if ($cm_row) {
            if ($rate_type === 'influencer') {
                $commission = (float)$cm_row['influencer_flat_rate'];
            } elseif ($rate_type === 'hotel') {
                $commission = round((float)$service['price'] * (float)$cm_row['commission_percent'] / 100, 2);
            } else {
                $disc_frac = $charged_price > 0 ? ($discount_amount_calc / $charged_price) : 0.0;
                $comm_base = get_commission_base_price($service_id, null, $charged_price);
                $commission = round($comm_base * (1 - $disc_frac) * (float)$cm_row['commission_percent'] / 100, 2);
            }
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

$page_title = 'Dashboard'; $page_icon = ''; $active_page = 'index';
require_once 'admin_header.php';
?>
<link rel="stylesheet" href="admin.css?v=<?php echo time(); ?>">
<div class="stats-grid stats-grid-5" style="grid-template-columns:repeat(5,1fr);">
    <div class="stat-card blue">
        <div class="stat-icon"></div>
        <div class="stat-number"><?php echo $today_appts; ?></div>
        <div class="stat-label">Today's Appointments</div>
    </div>
    <a href="appointments.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card amber" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon"></div>
            <div class="stat-number"><?php echo $pending_appts; ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
    </a>
    <div class="stat-card green">
        <div class="stat-icon"></div>
        <div class="stat-number"><?php echo $on_duty_count; ?></div>
        <div class="stat-label">Therapists On Duty</div>
    </div>
    <a href="orders.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card amber" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon"></div>
            <div class="stat-number"><?php echo $pending_orders; ?></div>
            <div class="stat-label">Pending Payments</div>
        </div>
    </a>
    <a href="products.php" style="display:block;text-decoration:none;color:inherit;">
        <div class="stat-card red" style="height:100%;box-sizing:border-box;">
            <div class="stat-icon"></div>
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
                <span class="qb-step" data-step="1" onclick="qbStepClick(1)">1. Service</span>
                <span class="qb-step" data-step="2" onclick="qbStepClick(2)">2. Time, Therapist &amp; Room</span>
                <span class="qb-step" data-step="3" onclick="qbStepClick(3)">3. Customer</span>
                <span class="qb-step" data-step="4" onclick="qbStepClick(4)">4. Payment</span>
            </div>

            <div class="qb-panel" id="qbPanel1">
                <?php echo render_quick_book_services_html($conn); ?>
            </div>

            <div class="qb-panel" id="qbPanel2" style="display:none;">
                <div class="qb-booking-date" id="qbBookingDateLabel"></div>
                <div class="qb-field-row">
                    <div class="qb-field">
                        <label for="qbTime">Time</label>
                        <input type="time" id="qbTime">
                    </div>
                    <div class="qb-field">
                        <label for="qbTherapist">Therapist</label>
                        <select id="qbTherapist"><option value="">Loading&hellip;</option></select>
                    </div>
                    <div class="qb-field">
                        <label for="qbResource">Room / Chair / Head Spa</label>
                        <select id="qbResource"><option value="">Loading&hellip;</option></select>
                    </div>
                </div>

                <div class="qb-field" style="margin-top:1.25rem;">
                    <label>Rate Type</label>
                    <div class="qb-rate-grid">
                        <button type="button" class="qb-rate-btn selected" data-rate="regular" onclick="qbSelectRateType(this)">
                            <span class="qb-rate-title">Regular</span><span class="qb-rate-sub">Standard price</span>
                        </button>
                        <button type="button" class="qb-rate-btn" data-rate="home" onclick="qbSelectRateType(this)">
                            <span class="qb-rate-title">Home Service</span><span class="qb-rate-sub">Fixed total price</span>
                        </button>
                        <button type="button" class="qb-rate-btn" data-rate="hotel" onclick="qbSelectRateType(this)">
                            <span class="qb-rate-title">Hotel / Partner</span><span class="qb-rate-sub">Partner rate</span>
                        </button>
                        <button type="button" class="qb-rate-btn" data-rate="influencer" onclick="qbSelectRateType(this)">
                            <span class="qb-rate-title">Influencer / PR</span><span class="qb-rate-sub">Free — ₱0</span>
                        </button>
                    </div>
                    <div class="qb-field" id="qbPartnerBlock" style="display:none;margin-top:0.85rem;">
                        <label for="qbPartner">Select Partner</label>
                        <select id="qbPartner" onchange="qbPartnerChanged()">
                            <option value="">— Select partner —</option>
                            <?php foreach (quick_book_partners($conn) as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="qb-panel" id="qbPanel3" style="display:none;">
                <div class="qb-field-row">
                    <div class="qb-field">
                        <label for="qbCustomerName">Customer Name</label>
                        <input type="text" id="qbCustomerName" placeholder="Enter full name">
                    </div>
                    <div class="qb-field">
                        <label for="qbCustomerPhone">Phone Number <span style="font-weight:400;color:var(--gray);">(optional)</span></label>
                        <input type="tel" id="qbCustomerPhone" placeholder="09XXXXXXXXX">
                    </div>
                </div>
                <div class="qb-field" style="margin-top:1.25rem;">
                    <label for="qbCustomerNote">Notes <span style="font-weight:400;color:var(--gray);">(optional)</span></label>
                    <textarea id="qbCustomerNote" placeholder="e.g. Female therapist, light pressure, VIP guest…"></textarea>
                </div>
                <div class="qb-field" style="margin-top:1.5rem;">
                    <label>Appointment Details</label>
                    <div class="qb-summary" id="qbReviewSummary"></div>
                </div>
            </div>

            <div class="qb-panel" id="qbPanel4" style="display:none;">
                <div class="qb-field">
                    <label>Discount / Voucher</label>
                    <div class="qb-disc-grid">
                        <button type="button" class="qb-disc-btn selected" data-discount="none" onclick="qbSelectDiscount(this)">None</button>
                        <button type="button" class="qb-disc-btn" data-discount="voucher" onclick="qbSelectDiscount(this)">Voucher</button>
                        <button type="button" class="qb-disc-btn" data-discount="celebration" onclick="qbSelectDiscount(this)">Celebration<br><small>% off</small></button>
                        <button type="button" class="qb-disc-btn" data-discount="senior" onclick="qbSelectDiscount(this)">Senior<br><small>20% off</small></button>
                        <button type="button" class="qb-disc-btn" data-discount="pwd" onclick="qbSelectDiscount(this)">PWD<br><small>20% off</small></button>
                        <button type="button" class="qb-disc-btn" data-discount="employee" onclick="qbSelectDiscount(this)">Staff<br><small>50% off</small></button>
                    </div>
                    <div class="qb-sub-box" id="qbVoucherInputs" style="display:none;">
                        <div class="qb-field-row">
                            <div class="qb-field">
                                <label for="qbVoucherType">Voucher Type</label>
                                <select id="qbVoucherType" onchange="qbRecalc()">
                                    <option value="cash">Cash Off (₱)</option>
                                    <option value="percent">Percentage Off</option>
                                </select>
                            </div>
                            <div class="qb-field">
                                <label for="qbVoucherAmount">Amount</label>
                                <input type="number" id="qbVoucherAmount" min="0" step="0.01" value="0" oninput="qbRecalc()">
                            </div>
                        </div>
                    </div>
                    <div class="qb-sub-box" id="qbCelebInputs" style="display:none;">
                        <label for="qbCelebPct">Discount percentage for this celebration</label>
                        <input type="number" id="qbCelebPct" min="0" max="100" step="0.01" value="0" oninput="qbRecalc()" style="max-width:160px;">
                    </div>
                </div>

                <div class="qb-summary" id="qbPriceSummary" style="margin-top:1.25rem;"></div>

                <div class="qb-field" style="margin-top:1.25rem;">
                    <label>Payment Method</label>
                    <div class="qb-pay-grid" id="qbPayGrid"></div>
                    <?php if (!ONLINE_PAYMENT_ENABLED): ?>
                    <div class="qb-notice">Online payment is coming soon — please complete payment onsite for now.</div>
                    <?php endif; ?>
                </div>

                <div class="qb-field-row" style="margin-top:1rem;">
                    <div class="qb-field">
                        <label for="qbAdvancePayment">Advance Payment (₱) <span style="font-weight:400;color:var(--gray);">(optional)</span></label>
                        <input type="number" id="qbAdvancePayment" min="0" step="0.01" value="0" oninput="qbRecalc()">
                    </div>
                    <div class="qb-field">
                        <label>Advance Payment Method</label>
                        <div class="qb-pay-grid" id="qbAdvPayGrid"></div>
                    </div>
                </div>

                <div id="qbError" class="qb-error" style="display:none;"></div>
            </div>
        </div>
        <div class="modal-box-footer">
            <button type="button" class="btn btn-secondary" id="qbBackBtn" onclick="qbBack()" style="display:none;">Back</button>
            <button type="button" class="btn btn-primary" id="qbNextBtn" onclick="qbNext()">Next</button>
            <button type="button" class="btn btn-primary" id="qbConfirmBtn" onclick="qbSubmit()" style="display:none;">Book Walk-In</button>
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
var QB_PAYMENT_METHODS = <?php
    $pm_wk = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'qrph' => 'QR Ph', 'card' => 'Card', 'swiper' => 'Swiper'];
    $online_pm = ['gcash', 'maya', 'card', 'qrph'];
    $qb_pm_list = [];
    foreach ($pm_wk as $pmv => $pml) {
        if (!ONLINE_PAYMENT_ENABLED && in_array($pmv, $online_pm, true)) continue;
        if (ONLINE_PAYMENT_ENABLED && in_array($pmv, ['gcash', 'maya'], true) && !SHOW_GCASH_MAYA) continue;
        $qb_pm_list[] = ['value' => $pmv, 'label' => $pml];
    }
    echo json_encode($qb_pm_list);
?>;
var qbState = {};
var qbStep  = 1;
var qbMaxStepReached = 1;

function openQuickBook(prefillDate) {
    qbState = {
        date: prefillDate || new Date().toISOString().slice(0, 10), time: '',
        service_id: 0, service_name: '', price: 0, home_price: 0, minutes: 0,
        therapist_id: 0, therapist_name: '', resource_id: 0, resource_name: '',
        rate_type: 'regular', partner_id: 0, partner_name: '', partner_rate: null,
        customer_name: '', phone: '', customer_note: '',
        discount_type: 'none', voucher_type: 'cash', voucher_value: 0,
        payment_method: '', advance_payment: 0, advance_payment_method: ''
    };
    var now = new Date();
    document.getElementById('qbTime').value = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    document.getElementById('qbCustomerName').value  = '';
    document.getElementById('qbCustomerPhone').value = '';
    document.getElementById('qbCustomerNote').value  = '';
    document.getElementById('qbVoucherAmount').value = 0;
    document.getElementById('qbCelebPct').value      = 0;
    document.getElementById('qbAdvancePayment').value = 0;
    document.getElementById('qbPartnerBlock').style.display = 'none';
    document.getElementById('qbVoucherInputs').style.display = 'none';
    document.getElementById('qbCelebInputs').style.display   = 'none';
    document.querySelectorAll('.qb-svc-row.selected').forEach(function (r) { r.classList.remove('selected'); });
    document.querySelectorAll('.qb-rate-btn').forEach(function (b) { b.classList.toggle('selected', b.getAttribute('data-rate') === 'regular'); });
    document.querySelectorAll('.qb-disc-btn').forEach(function (b) { b.classList.toggle('selected', b.getAttribute('data-discount') === 'none'); });
    document.getElementById('qbTherapist').innerHTML = '<option value="">Select therapist&hellip;</option>';
    document.getElementById('qbResource').innerHTML  = '<option value="">Select room/chair&hellip;</option>';
    document.getElementById('qbReviewSummary').innerHTML = '';
    document.getElementById('qbPriceSummary').innerHTML  = '';
    document.getElementById('qbError').style.display = 'none';
    qbBuildPayGrid('qbPayGrid', 'payment_method');
    qbBuildPayGrid('qbAdvPayGrid', 'advance_payment_method');
    var confirmBtn = document.getElementById('qbConfirmBtn');
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Book Walk-In';
    qbMaxStepReached = 1;
    qbGotoStep(1);
    document.getElementById('quickBookModal').classList.add('active');
}

function closeQuickBook() { document.getElementById('quickBookModal').classList.remove('active'); }

function qbGotoStep(n) {
    qbStep = n;
    if (n > qbMaxStepReached) qbMaxStepReached = n;
    for (var i = 1; i <= 4; i++) {
        document.getElementById('qbPanel' + i).style.display = (i === n) ? '' : 'none';
        var stepEl = document.querySelector('.qb-step[data-step="' + i + '"]');
        stepEl.classList.toggle('active', i === n);
        stepEl.classList.toggle('done', i < n);
        stepEl.classList.toggle('reachable', i <= qbMaxStepReached && i !== n);
    }
    document.getElementById('qbBackBtn').style.display    = (n === 1) ? 'none' : '';
    document.getElementById('qbNextBtn').style.display    = (n === 4) ? 'none' : '';
    document.getElementById('qbConfirmBtn').style.display = (n === 4) ? '' : 'none';
    if (n === 2) {
        var dt = new Date(qbState.date + 'T00:00');
        document.getElementById('qbBookingDateLabel').textContent =
            'Booking for ' + dt.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
    }
    if (n === 3) qbBuildReviewSummary();
    var scrollBody = document.querySelector('#quickBookModal .modal-box-body');
    if (scrollBody) scrollBody.scrollTop = 0;
}

function qbStepClick(n) {
    if (n === qbStep || n > qbMaxStepReached) return;
    qbGotoStep(n);
}

function qbBack() { if (qbStep > 1) qbGotoStep(qbStep - 1); }

function qbNext() {
    if (qbStep === 1) {
        if (!qbState.service_id) { uiAlert('Please choose a service.'); return; }
        qbLoadPickers();
    } else if (qbStep === 2) {
        var t = document.getElementById('qbTime').value;
        var thSel = document.getElementById('qbTherapist'), rsSel = document.getElementById('qbResource');
        if (!t) { uiAlert('Please choose a time.'); return; }
        if (!thSel.value) { uiAlert('Please choose a therapist.'); return; }
        if (!rsSel.value) { uiAlert('Please choose a room, chair, or head spa.'); return; }
        if (qbState.rate_type === 'hotel' && !qbState.partner_id) { uiAlert('Please select a hotel/partner for the Hotel rate.'); return; }
        qbState.time            = t;
        qbState.therapist_id    = thSel.value;
        qbState.therapist_name  = thSel.options[thSel.selectedIndex].text;
        qbState.resource_id     = rsSel.value;
        qbState.resource_name   = rsSel.options[rsSel.selectedIndex].text;
        qbGotoStep(3);
    } else if (qbStep === 3) {
        var name = document.getElementById('qbCustomerName').value.trim();
        if (!name) { uiAlert("Please enter the customer's name."); return; }
        qbState.customer_name  = name;
        qbState.phone          = document.getElementById('qbCustomerPhone').value.trim();
        qbState.customer_note  = document.getElementById('qbCustomerNote').value.trim();
        qbRecalc();
        qbGotoStep(4);
    }
}

function qbSelectService(el) {
    document.querySelectorAll('.qb-svc-row.selected').forEach(function (r) { r.classList.remove('selected'); });
    el.classList.add('selected');
    qbState.service_id   = el.getAttribute('data-service-id');
    qbState.service_name = el.getAttribute('data-service-name');
    qbState.price         = parseFloat(el.getAttribute('data-price'));
    qbState.home_price    = parseFloat(el.getAttribute('data-home-price'));
    qbState.minutes        = parseInt(el.getAttribute('data-minutes'), 10);
}

function qbLoadPickers() {
    document.getElementById('qbTherapist').innerHTML = '<option value="">Loading&hellip;</option>';
    document.getElementById('qbResource').innerHTML  = '<option value="">Loading&hellip;</option>';
    qbGotoStep(2);
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

function qbSelectRateType(el) {
    document.querySelectorAll('.qb-rate-btn').forEach(function (b) { b.classList.remove('selected'); });
    el.classList.add('selected');
    qbState.rate_type = el.getAttribute('data-rate');
    document.getElementById('qbPartnerBlock').style.display = (qbState.rate_type === 'hotel') ? '' : 'none';
    if (qbState.rate_type !== 'hotel') { qbState.partner_id = 0; qbState.partner_rate = null; }
}

function qbPartnerChanged() {
    var sel = document.getElementById('qbPartner');
    qbState.partner_id   = sel.value;
    qbState.partner_name = sel.value ? sel.options[sel.selectedIndex].text : '';
    qbState.partner_rate = null;
    if (!sel.value) return;
    fetch('index.php?ajax=quick_book_partner_rate&service_id=' + encodeURIComponent(qbState.service_id) + '&partner_id=' + encodeURIComponent(sel.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { qbState.partner_rate = data.rate; });
}

function qbComputeBasePrice() {
    switch (qbState.rate_type) {
        case 'home':       return qbState.home_price || 0;
        case 'hotel':      return (qbState.partner_rate !== null && qbState.partner_rate !== undefined) ? qbState.partner_rate : qbState.price;
        case 'influencer': return 0;
        default:           return qbState.price;
    }
}

function qbRateTypeLabel() {
    return { regular: 'Regular', home: 'Home Service', hotel: 'Hotel / Partner', influencer: 'Influencer / PR' }[qbState.rate_type] || 'Regular';
}

function qbBuildReviewSummary() {
    var dt = new Date(qbState.date + 'T' + qbState.time);
    var dateLabel = dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    var timeLabel = dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    document.getElementById('qbReviewSummary').innerHTML =
        '<div class="qb-summary-row"><span>Date &amp; Time</span><strong>' + dateLabel + ', ' + timeLabel + '</strong></div>' +
        '<div class="qb-summary-row"><span>Service</span><strong>' + qbState.service_name + ' (' + qbState.minutes + ' min)</strong></div>' +
        '<div class="qb-summary-row"><span>Therapist</span><strong>' + qbState.therapist_name + '</strong></div>' +
        '<div class="qb-summary-row"><span>Room</span><strong>' + qbState.resource_name + '</strong></div>' +
        '<div class="qb-summary-row"><span>Rate Type</span><strong>' + qbRateTypeLabel() + (qbState.partner_name ? ' — ' + qbState.partner_name : '') + '</strong></div>';
}

function qbSelectDiscount(el) {
    document.querySelectorAll('.qb-disc-btn').forEach(function (b) { b.classList.remove('selected'); });
    el.classList.add('selected');
    qbState.discount_type = el.getAttribute('data-discount');
    document.getElementById('qbVoucherInputs').style.display = (qbState.discount_type === 'voucher') ? '' : 'none';
    document.getElementById('qbCelebInputs').style.display   = (qbState.discount_type === 'celebration') ? '' : 'none';
    qbRecalc();
}

function qbComputeDiscount(base) {
    switch (qbState.discount_type) {
        case 'senior': case 'pwd': return Math.round(base * 0.20 * 100) / 100;
        case 'employee': return Math.round(base * 0.50 * 100) / 100;
        case 'celebration': {
            var pct = parseFloat(document.getElementById('qbCelebPct').value) || 0;
            return pct > 0 ? Math.round(base * (pct / 100) * 100) / 100 : 0;
        }
        case 'voucher': {
            var vtype = document.getElementById('qbVoucherType').value;
            var vamt  = parseFloat(document.getElementById('qbVoucherAmount').value) || 0;
            if (vamt <= 0) return 0;
            return vtype === 'percent' ? Math.round(base * (vamt / 100) * 100) / 100 : Math.min(vamt, base);
        }
        default: return 0;
    }
}

function qbRecalc() {
    var base     = qbComputeBasePrice();
    var discount = qbComputeDiscount(base);
    var advance  = parseFloat(document.getElementById('qbAdvancePayment').value) || 0;
    var final    = Math.max(0, base - discount - advance);
    var rows = '<div class="qb-summary-row"><span>' + qbRateTypeLabel() + ' Price</span><strong>₱' + base.toFixed(2) + '</strong></div>';
    if (discount > 0) rows += '<div class="qb-summary-row"><span>Discount</span><strong>&minus;₱' + discount.toFixed(2) + '</strong></div>';
    if (advance > 0)  rows += '<div class="qb-summary-row"><span>Advance Payment</span><strong>&minus;₱' + advance.toFixed(2) + '</strong></div>';
    rows += '<div class="qb-summary-row qb-summary-total"><span>Balance Due</span><strong>₱' + final.toFixed(2) + '</strong></div>';
    document.getElementById('qbPriceSummary').innerHTML = rows;
}

function qbBuildPayGrid(containerId, field) {
    var container = document.getElementById(containerId);
    container.innerHTML = QB_PAYMENT_METHODS.map(function (pm, i) {
        return '<button type="button" class="qb-pay-btn' + (i === 0 ? ' selected' : '') + '" data-method="' + pm.value + '" onclick="qbSelectPayment(this, \'' + field + '\')">' + pm.label + '</button>';
    }).join('');
    if (QB_PAYMENT_METHODS.length) qbState[field] = QB_PAYMENT_METHODS[0].value;
}

function qbSelectPayment(el, field) {
    el.parentNode.querySelectorAll('.qb-pay-btn.selected').forEach(function (b) { b.classList.remove('selected'); });
    el.classList.add('selected');
    qbState[field] = el.getAttribute('data-method');
}

function qbSubmit() {
    if (!qbState.payment_method) { uiAlert('Please choose a payment method.'); return; }
    if (qbState.discount_type === 'voucher' && (parseFloat(document.getElementById('qbVoucherAmount').value) || 0) <= 0) {
        uiAlert('Please enter the voucher amount, or select None if no voucher is used.'); return;
    }
    if (qbState.discount_type === 'celebration' && (parseFloat(document.getElementById('qbCelebPct').value) || 0) <= 0) {
        uiAlert('Please enter the celebration discount percentage, or select None if no discount is used.'); return;
    }
    var errEl = document.getElementById('qbError');
    errEl.style.display = 'none';
    var confirmBtn = document.getElementById('qbConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Booking…';

    var body = new URLSearchParams({
        date: qbState.date, time: qbState.time, service_id: qbState.service_id,
        therapist_id: qbState.therapist_id, resource_id: qbState.resource_id,
        rate_type: qbState.rate_type, partner_id: qbState.partner_id,
        customer_name: qbState.customer_name, phone: qbState.phone, customer_note: qbState.customer_note,
        discount_type: qbState.discount_type,
        voucher_type: document.getElementById('qbVoucherType').value,
        voucher_value: qbState.discount_type === 'celebration'
            ? (document.getElementById('qbCelebPct').value || 0)
            : (document.getElementById('qbVoucherAmount').value || 0),
        payment_method: qbState.payment_method,
        advance_payment: document.getElementById('qbAdvancePayment').value || 0,
        advance_payment_method: qbState.advance_payment_method
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
                confirmBtn.textContent = 'Book Walk-In';
            }
        })
        .catch(function () {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = '';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Book Walk-In';
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
#quickBookModal .modal-box, #apptCalendarModal .modal-box {
    max-height:90vh; display:flex; flex-direction:column;
}
#quickBookModal .modal-box-body, #apptCalendarModal .modal-box-body {
    flex:1; overflow-y:auto;
}
.qb-steps {
    display:flex; height:52px; border-bottom:1px solid var(--border); background:var(--bg3);
    position:sticky; top:0; z-index:5;
}
.qb-step {
    flex:1; display:flex; align-items:center; justify-content:center; text-align:center;
    padding:0 0.5rem; font-size:0.85rem; font-weight:600;
    color:var(--gray); border-bottom:3px solid transparent; cursor:default;
}
.qb-step.active { color:var(--brown); border-bottom-color:#C96A2C; }
.qb-step.done { color:var(--brown); }
.qb-step.reachable { cursor:pointer; }
.qb-step.reachable:hover { background:rgba(201,106,44,0.08); }
.qb-panel { padding:1.75rem; min-height:420px; }
.qb-field-row { display:flex; gap:1.5rem; flex-wrap:wrap; }
.qb-field { flex:1; min-width:220px; }
.qb-field label { display:block; font-size:0.85rem; font-weight:700; color:var(--brown-md); margin-bottom:0.5rem; }
.qb-field input, .qb-field select {
    width:100%; padding:0.85rem 1rem; border:1.5px solid var(--border); border-radius:10px;
    font-family:inherit; font-size:1.05rem; color:var(--brown); background:#fff; box-sizing:border-box;
}
.qb-field input:focus, .qb-field select:focus { outline:none; border-color:#C96A2C; }
.qb-field textarea {
    width:100%; padding:0.85rem 1rem; border:1.5px solid var(--border); border-radius:10px;
    font-family:inherit; font-size:1rem; color:var(--brown); background:#fff; box-sizing:border-box;
    resize:vertical; min-height:80px;
}
.qb-field textarea:focus { outline:none; border-color:#C96A2C; }
.qb-booking-date { font-size:0.95rem; font-weight:700; color:var(--brown); margin-bottom:1.25rem; }

.qb-rate-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0.75rem; }
.qb-rate-btn {
    display:flex; flex-direction:column; align-items:center; gap:0.25rem; padding:1rem 0.5rem;
    border:1.5px solid var(--border); border-radius:10px; background:#fff; cursor:pointer;
}
.qb-rate-btn:hover { background:var(--bg3); }
.qb-rate-btn.selected { background:#FDE8D8; border-color:#C96A2C; }
.qb-rate-title { font-weight:700; color:var(--brown); font-size:0.9rem; }
.qb-rate-sub { font-size:0.75rem; color:var(--gray); }

.qb-disc-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:0.6rem; margin-bottom:0.75rem; }
.qb-disc-btn {
    padding:0.75rem 0.5rem; border:1.5px solid var(--border); border-radius:10px; background:#fff;
    font-size:0.85rem; font-weight:600; color:var(--brown); cursor:pointer; text-align:center; line-height:1.4;
}
.qb-disc-btn small { font-weight:400; color:var(--gray); }
.qb-disc-btn:hover { background:var(--bg3); }
.qb-disc-btn.selected { background:#C96A2C; color:#fff; border-color:#C96A2C; }
.qb-disc-btn.selected small { color:#fdece0; }
.qb-sub-box { background:var(--bg3); border:1px solid var(--border); border-radius:10px; padding:1rem; margin-bottom:0.75rem; }
.qb-notice { margin-top:0.6rem; padding:0.7rem 1rem; background:#fff8f2; border-left:3px solid #C96A2C; border-radius:6px; font-size:0.85rem; color:#92400e; }

.qb-svc-layout { display:grid; grid-template-columns:220px 1fr; gap:0; align-items:start; }
.qb-svc-sidebar { display:flex; flex-direction:column; border-right:1px solid var(--border); position:sticky; top:52px; background:#fff; }
.qb-svc-cat-btn {
    padding:0.9rem 1.25rem; font-weight:600; color:var(--brown); text-decoration:none;
    border-bottom:1px solid var(--border); border-left:3px solid transparent;
}
.qb-svc-cat-btn:hover { background:var(--bg3); }
.qb-svc-content { padding:0 1.5rem; }
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
    .qb-svc-layout { grid-template-columns:1fr; }
    .qb-svc-sidebar { position:static; }
    .qb-svc-sidebar { flex-direction:row; flex-wrap:wrap; overflow-x:auto; }
    .qb-pay-grid { grid-template-columns:repeat(2,1fr); }
    .qb-rate-grid { grid-template-columns:repeat(2,1fr); }
    .qb-disc-grid { grid-template-columns:repeat(2,1fr); }
}
</style>
<div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:0.35rem;">
    <span id="livePanelsTs" style="font-size:0.65rem;color:var(--gray);">Live &mdash; auto-refreshes every 45s</span>
</div>
<div id="livePanels"><?php render_live_panels($live); ?></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Recent Orders</span><a href="orders.php" class="btn btn-secondary btn-sm">View All</a></div>
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
        <div class="panel-header"><span class="panel-title">Recent Appointments</span><a href="appointments.php" class="btn btn-secondary btn-sm">View All</a></div>
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
<?php require 'expense_category_summary.php'; ?>

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