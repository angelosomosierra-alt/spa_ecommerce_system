<?php
/**
 * _book_now.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Data for the Dashboard's Book Now modal (walk-ins happening now): several
 * guests, several services each, therapist/room/time planned client-side
 * (see _book_now_modal.php). This file only READS — one JSON snapshot of
 * everything the planner needs, fetched fresh each time the modal opens:
 *   - services + their single-session duration options and active price
 *   - which therapists are qualified for each service
 *   - therapists in today's rotation order, with duty/break state
 *   - active rooms/chairs/head spa stations
 *   - today's bookings that occupy a therapist or a room
 *
 * Saving (book_now_save): one unpaid order per service, exactly like the
 * classic Quick Book form, so check-in and Mark Complete in appointments.php
 * work unchanged. Every order from one booking shares orders.booking_group,
 * and orders.customer_name is the guest — that's what lets appointments.php
 * offer "Pay for group" (book_now_group_bill / book_now_pay_group), which
 * settles several guests in one payment with each guest's own discount.
 *
 * Requires: config.php loaded (get_booking_qualified_therapist_ids,
 * get_active_duration_price, RESOURCE_OCCUPYING_STATUSES, ...).
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!function_exists('book_now_ensure_schema')) {
// Self-healing, same convention as the rest of the codebase. See
// database/migrations/2026_10_08_add_booking_group_to_orders.sql.
function book_now_ensure_schema(mysqli $conn): void {
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS booking_group VARCHAR(32) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_booking_group (booking_group)");
    $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS package_followup_id INT NULL DEFAULT NULL");
    // Multi-session packages started before they were tracked in the system
    // (e.g. the October sales-report import): what is left to book and pay.
    $conn->query("CREATE TABLE IF NOT EXISTS package_followups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(150) NOT NULL,
        service_id INT NOT NULL,
        sessions_total INT NOT NULL DEFAULT 2,
        sessions_done INT NOT NULL DEFAULT 1,
        package_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
        last_date DATE NULL,
        last_therapist VARCHAR(120) NULL,
        last_slip VARCHAR(50) NULL,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        note VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pf_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
}

// Discounts a group payment can apply, per guest. Same rates as check-in.
if (!defined('BOOK_NOW_DISCOUNTS')) {
    define('BOOK_NOW_DISCOUNTS', ['none' => 0, 'senior' => 20, 'pwd' => 20, 'employee' => STAFF_DISCOUNT_PCT]);
}

if (!function_exists('book_now_resource_type')) {
// Same keyword mapping as suggest_resource_type_for_service() in config.php,
// applied to an already-fetched category name so the catalog doesn't cost
// one query per service.
function book_now_resource_type(?string $category_name): string {
    $cat = strtolower($category_name ?? '');
    if (str_contains($cat, 'head spa')) return 'head_spa';
    if (str_contains($cat, 'nail') || str_contains($cat, 'lash')
        || str_contains($cat, 'brow') || str_contains($cat, 'foot')) return 'chair';
    return 'room';
}
}

if (!function_exists('book_now_followup_split')) {
/**
 * Next session of a package_followups row, priced like the sales report and
 * like system packages (pkg_session_share): each session is a sale of its
 * share of the package; what the customer already paid beyond the sessions
 * done is an advance payment applied to it. Returns [share, prepaid, due].
 */
function book_now_followup_split(array $f): array {
    $total = max(1, (int)$f['sessions_total']); $done = (int)$f['sessions_done'];
    $share = pkg_session_share((float)$f['package_price'], $total, $done + 1);
    $used  = round((float)$f['package_price'] / $total, 2) * $done;
    $pre   = round(min($share, max(0.0, (float)$f['amount_paid'] - $used)), 2);
    return [$share, $pre, round($share - $pre, 2)];
}
}

if (!function_exists('book_now_data')) {
function book_now_data(mysqli $conn, ?string $date = null): array {
    book_now_ensure_schema($conn);
    $today    = date('Y-m-d');
    $day      = ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $today) ? $date : $today;
    $is_today = ($day === $today);
    $services = $conn->query("
        SELECT s.id, s.name, s.price, s.session_time, s.category_id, c.name AS category_name
        FROM services s
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE s.deleted_at IS NULL
        ORDER BY c.name, s.name
    ")->fetch_all(MYSQLI_ASSOC);

    // Duration options, including multi-session packages (session_count > 1):
    // 'm' is one session's minutes, 'p' the whole package price, 'n' the
    // number of sessions. Book Now places Session 1 now; the rest are
    // scheduled later from Appointments.
    $durations_by_svc = [];
    if ($conn->query("SHOW TABLES LIKE 'service_durations'")->num_rows > 0) {
        $has_sc = $conn->query("SHOW COLUMNS FROM service_durations LIKE 'session_count'")->num_rows > 0;
        $rows = $conn->query("SELECT * FROM service_durations ORDER BY service_id, "
            . ($has_sc ? "session_count, " : "") . "duration_minutes")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $d) {
            $active = get_active_duration_price($d);
            $durations_by_svc[(int)$d['service_id']][] = [
                'id'    => (int)$d['id'],
                'm'     => (int)$d['duration_minutes'],
                'p'     => (float)$active['price'],
                'promo' => (bool)$active['is_promo_active'],
                'n'     => max(1, (int)($d['session_count'] ?? 1)),
            ];
        }
    }

    $out_services = [];
    foreach ($services as $s) {
        $sid  = (int)$s['id'];
        $durs = $durations_by_svc[$sid] ?? [[
            'id' => null, 'm' => max(1, (int)$s['session_time']), 'p' => (float)$s['price'], 'promo' => false,
        ]];
        $out_services[] = [
            'id'   => $sid,
            'name' => $s['name'],
            'cat'  => $s['category_name'] ?: 'Other',
            'res'  => book_now_resource_type($s['category_name']),
            'durs' => $durs,
            'qual' => get_booking_qualified_therapist_ids($conn, $sid),
            'med'  => service_is_medical($conn, $sid),
            'aes'  => service_uses_aesthetic_slip($s['category_name'], $s['name']),
        ];
    }

    // Today: everyone, on-duty first in today's rotation order. Not timed in,
    // or already timed out → off duty (shown, never picked). Another day:
    // nobody has timed in yet, so every therapist can be planned.
    $therapists = !$is_today ? $conn->query("
        SELECT t.id, t.full_name, 1 AS on_duty, 0 AS on_break FROM therapists t ORDER BY t.full_name
    ")->fetch_all(MYSQLI_ASSOC) : $conn->query("
        SELECT t.id, t.full_name,
               ta.id IS NOT NULL AND (ta.time_out IS NULL OR ta.time_out = '') AS on_duty,
               IFNULL(ta.is_on_break, 0) AS on_break
        FROM therapists t
        LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
        ORDER BY on_duty DESC, ta.rotation_order IS NULL, ta.rotation_order, ta.time_in, t.full_name
    ")->fetch_all(MYSQLI_ASSOC);

    $resources = $conn->query("
        SELECT id, name, type FROM service_resources
        WHERE is_active = 1
        ORDER BY FIELD(type,'room','chair','head_spa'), sort_order, name
    ")->fetch_all(MYSQLI_ASSOC);

    // That day's bookings that hold a therapist and/or a room. Times are
    // minutes since midnight, the unit the planner works in.
    $statuses = "'" . implode("','", RESOURCE_OCCUPYING_STATUSES) . "'";
    $busy_q = $conn->prepare("
        SELECT a.id, a.resource_id,
               HOUR(a.appointment_date) * 60 + MINUTE(a.appointment_date) AS start_min,
               COALESCE(a.duration_minutes, s.session_time, 60) AS dur,
               (SELECT GROUP_CONCAT(DISTINCT at2.therapist_id) FROM appointment_therapists at2
                WHERE at2.appointment_id = a.id AND at2.therapist_id IS NOT NULL) AS therapist_ids,
               COALESCE(o.customer_name, u.full_name) AS who
        FROM appointments a
        LEFT JOIN services s ON s.id = a.service_id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE DATE(a.appointment_date) = ?
          AND a.status IN ($statuses)
    ");
    $busy_q->bind_param("s", $day); $busy_q->execute();
    $busy = $busy_q->get_result()->fetch_all(MYSQLI_ASSOC); $busy_q->close();
    // Session 2+ of a package booked for that day: holds its therapist (the
    // session has no room of its own).
    $sb = $conn->prepare("
        SELECT NULL AS resource_id, HOUR(x.session_date) * 60 + MINUTE(x.session_date) AS start_min,
               COALESCE(x.duration_minutes, 60) AS dur, x.therapist_id AS therapist_ids,
               COALESCE(o.customer_name, u.full_name) AS who
        FROM appointment_sessions x
        JOIN appointments a ON a.id = x.appointment_id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE x.session_number > 1 AND x.status IN ('scheduled','checked_in')
          AND x.therapist_id IS NOT NULL AND DATE(x.session_date) = ?
    ");
    $sb->bind_param("s", $day); $sb->execute();
    $busy = array_merge($busy, $sb->get_result()->fetch_all(MYSQLI_ASSOC)); $sb->close();

    // Packages with sessions left, for "Continue a package".
    $followups = [];
    // a) Packages booked in the system: their next session not scheduled yet.
    foreach ($conn->query("
        SELECT x.id AS session_id, x.session_number, a.service_id, x.duration_minutes,
               sd.session_count, s.name AS service_name, COALESCE(o.customer_name, u.full_name) AS who,
               (SELECT MAX(DATE(y.completed_at)) FROM appointment_sessions y WHERE y.appointment_id = a.id AND y.status = 'completed') AS last_date
        FROM appointment_sessions x
        JOIN appointments a ON a.id = x.appointment_id
        JOIN services s ON s.id = a.service_id
        LEFT JOIN service_durations sd ON sd.id = a.service_duration_id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE x.status = 'not_scheduled' AND a.status NOT IN ('cancelled','declined')
          AND x.session_number = (SELECT MIN(z.session_number) FROM appointment_sessions z WHERE z.appointment_id = a.id AND z.status = 'not_scheduled')
        ORDER BY who
    ")->fetch_all(MYSQLI_ASSOC) as $f) {
        $followups[] = ['kind' => 'session', 'id' => (int)$f['session_id'], 'who' => $f['who'] ?: 'Customer',
            'service_id' => (int)$f['service_id'], 'service' => $f['service_name'],
            'next' => (int)$f['session_number'], 'total' => (int)($f['session_count'] ?: $f['session_number']),
            'm' => (int)$f['duration_minutes'], 'due' => 0.0, 'paid_note' => 'Paid with the package',
            'last' => $f['last_date'] ? date('M j', strtotime($f['last_date'])) : ''];
    }
    // b) Packages started before the system tracked them (package_followups).
    foreach ($conn->query("
        SELECT f.*, s.name AS service_name, s.session_time,
               (SELECT d.duration_minutes FROM service_durations d WHERE d.service_id = f.service_id ORDER BY d.session_count, d.duration_minutes LIMIT 1) AS dmin
        FROM package_followups f JOIN services s ON s.id = f.service_id AND s.deleted_at IS NULL
        WHERE f.status = 'open' AND f.sessions_done < f.sessions_total
        ORDER BY f.customer_name
    ")->fetch_all(MYSQLI_ASSOC) as $f) {
        [, , $due] = book_now_followup_split($f);
        $followups[] = ['kind' => 'followup', 'id' => (int)$f['id'], 'who' => $f['customer_name'],
            'service_id' => (int)$f['service_id'], 'service' => $f['service_name'],
            'next' => (int)$f['sessions_done'] + 1, 'total' => (int)$f['sessions_total'],
            'm' => (int)($f['dmin'] ?: $f['session_time'] ?: 30), 'due' => $due,
            'paid_note' => 'Paid ₱' . number_format((float)$f['amount_paid'], 2) . ' of ₱' . number_format((float)$f['package_price'], 2),
            'last' => trim(($f['last_date'] ? date('M j', strtotime($f['last_date'])) : '') . ($f['last_therapist'] ? ' · ' . $f['last_therapist'] : ''))];
    }

    return [
        'date'      => $day,
        'today'     => $is_today,
        'now'       => $is_today ? (int)date('G') * 60 + (int)date('i') : 10 * 60,
        'followups' => $followups,
        'services'  => $out_services,
        'therapists'=> array_map(fn($t) => [
            'id'    => (int)$t['id'],
            'name'  => $t['full_name'],
            'on'    => (bool)$t['on_duty'],
            'brk'   => (bool)$t['on_break'],
        ], $therapists),
        'resources' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'],
        ], $resources),
        'busy'      => array_map(fn($b) => [
            'r'   => $b['resource_id'] !== null ? (int)$b['resource_id'] : null,
            't'   => $b['therapist_ids'] ? array_map('intval', explode(',', $b['therapist_ids'])) : [],
            's'   => (int)$b['start_min'],
            'd'   => (int)$b['dur'],
            'who' => $b['who'] ?: 'Booked',
        ], $busy),
    ];
}
}

if (!function_exists('book_now_therapist_free')) {
// No booking today that holds this therapist inside [start, start+dur).
function book_now_therapist_free(mysqli $conn, int $therapist_id, string $start, int $dur): bool {
    $statuses = "'" . implode("','", RESOURCE_OCCUPYING_STATUSES) . "'";
    $stmt = $conn->prepare("
        SELECT a.id
        FROM appointments a
        JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN services s ON s.id = a.service_id
        WHERE at2.therapist_id = ?
          AND a.status IN ($statuses)
          AND a.appointment_date < DATE_ADD(?, INTERVAL ? MINUTE)
          AND DATE_ADD(a.appointment_date, INTERVAL COALESCE(a.duration_minutes, s.session_time, 60) MINUTE) > ?
        LIMIT 1
    ");
    $stmt->bind_param("isis", $therapist_id, $start, $dur, $start);
    $stmt->execute();
    $busy = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $busy === null;
}
}

if (!function_exists('book_now_save')) {
/**
 * $guests: [ ['name' => 'Carla', 'items' => [ ['service_id', 'duration_id',
 * 'therapist_id', 'resource_id', 'start' (minutes since midnight),
 * 'kind' => 'session'|'followup' + 'ref_id' for "Continue a package"], ... ]], ... ]
 * Everything is re-checked here (price, duration, qualification, duty,
 * clashes) — the planner's choices are suggestions, never trusted as-is.
 *
 * $date: the booking day (today or later). Another day is a reservation: an
 * optional advance payment ($dp = ['amount', 'method']) is spread over the
 * booking's orders as advance_payment, the same fields the old calendar
 * Quick Book used, so the Daily Report counts it today and deducts it on
 * the appointment day.
 */
function book_now_save(mysqli $conn, array $guests, ?string $date = null, array $dp = []): array {
    if (count($guests) < 1 || count($guests) > 4) return ['ok' => false, 'message' => 'Add between 1 and 4 guests.'];
    book_now_ensure_schema($conn);

    $now_min   = (int)date('G') * 60 + (int)date('i');
    $today     = date('Y-m-d');
    $day       = $date ?: $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < $today) return ['ok' => false, 'message' => 'Pick today or a later date.'];
    $is_today  = ($day === $today);
    $lines     = [];   // validated, ready to insert
    $planned   = [];   // this batch's own holds, for clashes inside the booking

    foreach ($guests as $gi => $g) {
        $gname = trim(sanitize_input((string)($g['name'] ?? '')));
        if ($gname === '') $gname = 'Guest ' . ($gi + 1);
        $gslip     = mb_substr(trim(sanitize_input((string)($g['slip'] ?? ''))), 0, 50);
        $gslip_aes = mb_substr(trim(sanitize_input((string)($g['slip_aes'] ?? ''))), 0, 50);
        $items = $g['items'] ?? [];
        if (!is_array($items) || !$items) return ['ok' => false, 'message' => "$gname has no service."];

        foreach ($items as $it) {
            $service_id   = (int)($it['service_id'] ?? 0);
            $duration_id  = isset($it['duration_id']) && $it['duration_id'] !== null && $it['duration_id'] !== '' ? (int)$it['duration_id'] : null;
            $therapist_id = (int)($it['therapist_id'] ?? 0);
            // override: the receptionist knowingly picked a therapist who isn't
            // trained for this service ("pick anyway"). 0 = assign later.
            $override     = !empty($it['override']);
            $resource_id  = (int)($it['resource_id'] ?? 0);
            $start        = (int)($it['start'] ?? -1);
            $kind         = in_array($it['kind'] ?? '', ['session', 'followup'], true) ? $it['kind'] : 'new';
            $ref_id       = (int)($it['ref_id'] ?? 0);
            $ref          = null;

            $st = $conn->prepare("SELECT s.id, s.name, s.price, s.session_time, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id = s.category_id WHERE s.id = ? AND s.deleted_at IS NULL");
            $st->bind_param("i", $service_id); $st->execute();
            $svc = $st->get_result()->fetch_assoc(); $st->close();
            if (!$svc) return ['ok' => false, 'message' => 'A service in this booking is no longer available.'];

            if ($kind === 'session') {
                // Next session of a package already in the system: paid with
                // the package, so this only schedules it.
                $rq = $conn->prepare("SELECT x.id, x.session_number, x.duration_minutes, x.appointment_id FROM appointment_sessions x JOIN appointments a ON a.id = x.appointment_id WHERE x.id = ? AND a.service_id = ? AND x.status = 'not_scheduled'");
                $rq->bind_param("ii", $ref_id, $service_id); $rq->execute();
                $ref = $rq->get_result()->fetch_assoc(); $rq->close();
                if (!$ref) return ['ok' => false, 'message' => "$gname's {$svc['name']} session is already scheduled. Reopen Book Now."];
                $sessions = 1; $duration_id = null;
                $minutes  = max(1, (int)$ref['duration_minutes']);
                $price    = 0.0;
            } elseif ($kind === 'followup') {
                // Package started before the system tracked it: this session
                // costs what is left of the package, split over the sessions left.
                $rq = $conn->prepare("SELECT * FROM package_followups WHERE id = ? AND service_id = ? AND status = 'open' AND sessions_done < sessions_total");
                $rq->bind_param("ii", $ref_id, $service_id); $rq->execute();
                $ref = $rq->get_result()->fetch_assoc(); $rq->close();
                if (!$ref) return ['ok' => false, 'message' => "$gname's {$svc['name']} package was already booked. Reopen Book Now."];
                $sessions = 1; $duration_id = null;
                $dm = $conn->prepare("SELECT duration_minutes FROM service_durations WHERE service_id = ? ORDER BY session_count, duration_minutes LIMIT 1");
                $dm->bind_param("i", $service_id); $dm->execute();
                $minutes  = max(1, (int)($dm->get_result()->fetch_assoc()['duration_minutes'] ?? $svc['session_time'] ?: 30)); $dm->close();
                [$price, $pre] = book_now_followup_split($ref);
            } elseif ($duration_id) {
                $sd = $conn->prepare("SELECT * FROM service_durations WHERE id = ? AND service_id = ?");
                $sd->bind_param("ii", $duration_id, $service_id); $sd->execute();
                $dur_row = $sd->get_result()->fetch_assoc(); $sd->close();
                if (!$dur_row) return ['ok' => false, 'message' => "{$svc['name']}: that duration option is no longer available."];
                $sessions = max(1, (int)($dur_row['session_count'] ?? 1));
                $minutes = (int)$dur_row['duration_minutes'];
                $price   = (float)get_active_duration_price($dur_row)['price'];
            } else {
                $sessions = 1;
                $minutes = max(1, (int)$svc['session_time']);
                $price   = (float)$svc['price'];
            }

            if ($start < ($is_today ? $now_min - 10 : 0) || $start + $minutes > 24 * 60) {
                return ['ok' => false, 'message' => "{$svc['name']} for $gname: that start time has passed or runs past closing. Reopen Book Now to re-plan."];
            }
            $start_dt = $day . ' ' . sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);

            // Doctor / Nurse service: no therapist at all (see service_is_medical()).
            $is_med = service_is_medical($conn, $service_id);
            $later  = !$is_med && $therapist_id <= 0; // booked now, therapist chosen in Appointments
            if ($is_med || $later) {
                $therapist_id = 0;
            } else {
                if (!$override && !in_array($therapist_id, get_booking_qualified_therapist_ids($conn, $service_id), true)) {
                    return ['ok' => false, 'message' => "The therapist picked for $gname's {$svc['name']} isn't trained for it."];
                }
                // Today: must be timed in. Another day: nobody has timed in yet.
                $td = $conn->prepare($is_today
                    ? "SELECT t.full_name FROM therapists t JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE() WHERE t.id = ? AND (ta.time_out IS NULL OR ta.time_out = '')"
                    : "SELECT t.full_name FROM therapists t WHERE t.id = ?");
                $td->bind_param("i", $therapist_id); $td->execute();
                $th = $td->get_result()->fetch_assoc(); $td->close();
                if (!$th) return ['ok' => false, 'message' => "The therapist picked for $gname's {$svc['name']} is not on duty."];
            }

            $rs = $conn->prepare("SELECT name FROM service_resources WHERE id = ? AND is_active = 1");
            $rs->bind_param("i", $resource_id); $rs->execute();
            $room = $rs->get_result()->fetch_assoc(); $rs->close();
            if (!$room) return ['ok' => false, 'message' => "Pick a room for $gname's {$svc['name']}."];

            if ($therapist_id > 0 && !book_now_therapist_free($conn, $therapist_id, $start_dt, $minutes)) {
                return ['ok' => false, 'message' => "{$th['full_name']} was just booked at " . date('g:i A', strtotime($start_dt)) . ". Reopen Book Now to re-plan."];
            }
            if (!is_resource_available($resource_id, $start_dt, $minutes)) {
                return ['ok' => false, 'message' => "{$room['name']} was just booked at " . date('g:i A', strtotime($start_dt)) . ". Reopen Book Now to re-plan."];
            }
            foreach ($planned as $p) {
                if ($start < $p['end'] && $p['start'] < $start + $minutes
                    && (($therapist_id > 0 && $p['therapist_id'] === $therapist_id) || $p['resource_id'] === $resource_id)) {
                    return ['ok' => false, 'message' => 'Two services in this booking overlap on the same therapist or room.'];
                }
            }
            $planned[] = ['start' => $start, 'end' => $start + $minutes, 'therapist_id' => $therapist_id, 'resource_id' => $resource_id];

            $lines[] = [
                'guest' => $gname,
                'slip' => service_uses_aesthetic_slip($svc['category_name'], $svc['name']) ? $gslip_aes : $gslip,
                'service_id' => $service_id, 'duration_id' => $duration_id,
                'therapist_id' => $therapist_id, 'resource_id' => $resource_id,
                'start_dt' => $start_dt, 'minutes' => $minutes, 'price' => $price,
                'sessions' => $sessions,
                'kind' => $kind, 'ref' => $ref, 'pre' => $kind === 'followup' ? $pre : 0.0,
            ];
        }
    }

    // Down payment (another day only), spread over the lines with something
    // to pay in proportion to their price; the last line takes the rounding.
    // A package session already paid in part keeps that earlier payment as
    // its advance and takes no share of today's.
    $dp_amount = 0.0; $dp_method = 'cash';
    $dp_lines  = fn($L) => $L['kind'] !== 'session' && $L['pre'] <= 0 && $L['price'] > 0;
    $billable  = array_sum(array_map(fn($L) => $dp_lines($L) ? $L['price'] : 0.0, $lines));
    if (!$is_today && $billable > 0) {
        $dp_amount = min($billable, max(0.0, round((float)($dp['amount'] ?? 0), 2)));
        $dp_method = in_array($dp['method'] ?? '', ['cash', 'gcash', 'maya', 'qrph', 'card', 'swiper'], true) ? $dp['method'] : 'cash';
        $left = $dp_amount; $paid_lines = array_keys(array_filter($lines, $dp_lines));
        foreach ($paid_lines as $n => $k) {
            $share = ($n === count($paid_lines) - 1) ? $left : round($dp_amount * $lines[$k]['price'] / $billable, 2);
            $share = min($share, $left, $lines[$k]['price']);
            $lines[$k]['adv'] = $share; $left = round($left - $share, 2);
        }
    }

    $group = 'BN' . date('ymdHis') . bin2hex(random_bytes(2));
    $walkin_user_id = get_walkin_customer_id();
    $adv_date = $dp_amount > 0 ? $today : null;

    $conn->begin_transaction();
    try {
        foreach ($lines as $L) {
            if ($L['kind'] === 'session') {
                // Same update as assign_session in appointments.php.
                $tid = $L['therapist_id'] > 0 ? $L['therapist_id'] : null;
                $stmt = $conn->prepare("UPDATE appointment_sessions SET session_date = ?, therapist_id = ?, status = 'scheduled' WHERE id = ? AND status = 'not_scheduled'");
                $stmt->bind_param("sii", $L['start_dt'], $tid, $L['ref']['id']);
                $stmt->execute(); $stmt->close();
                continue;
            }
            // Unpaid, method 'onsite' — the same shape Walk-in's pay-later
            // bookings use. Payment and discount happen in appointments.php.
            // Another day: the down payment's method, and paid if it covers it.
            // A package session paid on an earlier visit: that payment is its
            // advance, dated the day it was collected (Daily Report deducts it
            // as ADVANCE PAYMENT on this session's day).
            $adv   = (float)($L['adv'] ?? 0);
            $adv_m = $dp_method; $adv_d = $adv_date;
            if ($L['pre'] > 0) { $adv = $L['pre']; $adv_m = 'cash'; $adv_d = $L['ref']['last_date'] ?: $today; }
            $final = max(0.0, round($L['price'] - $adv, 2));
            $pm    = $adv > 0 ? $adv_m : 'onsite';
            $pstat = ($adv > 0 && $final <= 0) ? 'paid' : 'unpaid';
            $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, booking_date, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number, booking_group) VALUES (?, ?, '', ?, ?, ?, ?, 'approved', 'none', 0, ?, ?, ?)");
            $_slip = $L['slip'] !== '' ? $L['slip'] : null;
            $stmt->bind_param("issdssdss", $walkin_user_id, $L['guest'], $L['start_dt'], $L['price'], $pm, $pstat, $final, $_slip, $group);
            $stmt->execute(); $order_id = $stmt->insert_id; $stmt->close();

            $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
            $stmt->bind_param("iidd", $order_id, $L['service_id'], $L['price'], $L['price']);
            $stmt->execute(); $order_item_id = $stmt->insert_id; $stmt->close();

            $note   = $L['kind'] === 'followup'
                ? 'Session ' . ((int)$L['ref']['sessions_done'] + 1) . ' of ' . (int)$L['ref']['sessions_total'] . ' (package from ' . ($L['ref']['last_date'] ? date('M j', strtotime($L['ref']['last_date'])) : 'before') . ')'
                : '';
            $adv_pm = $adv > 0 ? $adv_m : 'cash';
            $_advd  = $adv > 0 ? $adv_d : null;
            $stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count, service_type, rate_type, partner_id, charged_price, customer_note, advance_payment, advance_payment_date, advance_payment_method, resource_id, duration_minutes, service_duration_id) VALUES (?, ?, ?, ?, 'assigned', 1, 'onsite', 'regular', NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiisdsdssiii", $walkin_user_id, $L['service_id'], $order_item_id, $L['start_dt'], $L['price'], $note, $adv, $_advd, $adv_pm, $L['resource_id'], $L['minutes'], $L['duration_id']);
            $stmt->execute(); $appointment_id = $stmt->insert_id; $stmt->close();

            // amount_paid adds what this session still costs (collected with
            // this order), so the next session's prepaid part stays right.
            if ($L['kind'] === 'followup') {
                $stmt = $conn->prepare("UPDATE appointments SET package_followup_id = ? WHERE id = ?");
                $stmt->bind_param("ii", $L['ref']['id'], $appointment_id); $stmt->execute(); $stmt->close();
                $stmt = $conn->prepare("UPDATE package_followups SET sessions_done = sessions_done + 1, amount_paid = amount_paid + ?, last_date = ?, status = IF(sessions_done >= sessions_total, 'closed', 'open') WHERE id = ?");
                $_d = substr($L['start_dt'], 0, 10); $_due = round($L['price'] - $L['pre'], 2);
                $stmt->bind_param("dsi", $_due, $_d, $L['ref']['id']);
                $stmt->execute(); $stmt->close();
            }

            // Multi-session package: Session 1 is scheduled now with this
            // booking's time and therapist; the other sessions wait to be
            // scheduled from Appointments (same rows Walk-in creates).
            if ($L['sessions'] > 1) {
                $s1_tid = $L['therapist_id'] > 0 ? $L['therapist_id'] : null;
                $ss = $conn->prepare("INSERT INTO appointment_sessions (appointment_id, session_number, session_date, therapist_id, duration_minutes, status) VALUES (?, 1, ?, ?, ?, 'scheduled')");
                $ss->bind_param("isii", $appointment_id, $L['start_dt'], $s1_tid, $L['minutes']);
                $ss->execute(); $ss->close();
                for ($sn = 2; $sn <= $L['sessions']; $sn++) {
                    $ss = $conn->prepare("INSERT INTO appointment_sessions (appointment_id, session_number, duration_minutes, status) VALUES (?, ?, ?, 'not_scheduled')");
                    $ss->bind_param("iii", $appointment_id, $sn, $L['minutes']);
                    $ss->execute(); $ss->close();
                }
            }

            // Commission: same rules as the classic Quick Book (regular rate,
            // no booking-time discount). Packages sum their components. A
            // multi-session package starts at ₱0: each session adds its own
            // commission when it's completed (complete_session).
            $commission = 0.00;
            if ($L['sessions'] > 1) {
                // nothing yet
            } elseif ($L['kind'] === 'followup') {
                // One session's share of the package price, like complete_session.
                $cm = $conn->prepare("SELECT commission_percent FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
                $cm->bind_param("ii", $L['therapist_id'], $L['service_id']); $cm->execute();
                $cm_row = $cm->get_result()->fetch_assoc(); $cm->close();
                if ($cm_row) {
                    $base = (float)$L['ref']['package_price'] / max(1, (int)$L['ref']['sessions_total']);
                    $commission = round($base * (float)$cm_row['commission_percent'] / 100, 2);
                }
            } elseif (!empty(get_package_components($conn, $L['service_id']))) {
                $commission = compute_package_commission($conn, $L['service_id'], $L['therapist_id'])['total'];
            } else {
                $cm = $conn->prepare("SELECT commission_percent FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
                $cm->bind_param("ii", $L['therapist_id'], $L['service_id']); $cm->execute();
                $cm_row = $cm->get_result()->fetch_assoc(); $cm->close();
                if ($cm_row) {
                    $base = get_commission_base_price($L['service_id'], $L['duration_id'], $L['price']);
                    $commission = round($base * (float)$cm_row['commission_percent'] / 100, 2);
                }
            }
            // Doctor / Nurse service: no therapist row and no commission.
            if ($L['therapist_id'] > 0 && !service_is_medical($conn, $L['service_id'])) {
                $stmt = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, commission, people_handled, notes) VALUES (?, ?, ?, 1, '')");
                $stmt->bind_param("iid", $appointment_id, $L['therapist_id'], $commission);
                $stmt->execute(); $stmt->close();
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Could not save the booking. Please try again.'];
    }

    require_once __DIR__ . '/../notify.php';
    if (function_exists('log_activity')) {
        log_activity($conn, 'book_now', 'Book Now: ' . count($guests) . ' guest(s), ' . count($lines) . ' service(s), ' . date('M j', strtotime($day)) . ($dp_amount > 0 ? ', advance payment ' . number_format($dp_amount, 2) . ' ' . $dp_method : '') . ', group ' . $group, 'order', null, null);
    }
    return ['ok' => true, 'group' => $group, 'count' => count($lines),
            'message' => 'Booked ' . count($guests) . ' guest' . (count($guests) > 1 ? 's' : '') . ' · ' . count($lines) . ' service' . (count($lines) > 1 ? 's' : '')
                . ($is_today ? '' : ' for ' . date('M j', strtotime($day)))
                . ($dp_amount > 0 ? '. Advance payment ₱' . number_format($dp_amount, 2) . ' received; the balance is collected at check-in.' : '. Collect payment in Appointments at check-in or when done.')];
}
}

if (!function_exists('book_now_group_bill')) {
/**
 * Unpaid part of a booking group, per guest: each guest's services (one
 * order each) with the unpaid extras added to them since booking.
 */
function book_now_group_bill(mysqli $conn, string $group): array {
    $stmt = $conn->prepare("
        SELECT o.id AS order_id, o.customer_name, o.total_amount, (o.payment_status = 'paid') AS order_paid,
               a.id AS appt_id, a.appointment_date, s.name AS service_name, IFNULL(a.advance_payment, 0) AS adv,
               (SELECT COALESCE(SUM(aes.charged_price), 0) FROM appointment_extra_services aes
                WHERE aes.appointment_id = a.id AND aes.payment_status != 'paid') AS extras
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN appointments a ON a.order_item_id = oi.id
        JOIN services s ON s.id = a.service_id
        WHERE o.booking_group = ?
          AND a.status NOT IN ('cancelled', 'declined', 'refunded')
        HAVING order_paid = 0 OR extras > 0   -- a paid guest's later add-ons still count
        ORDER BY o.customer_name, a.appointment_date
    ");
    $stmt->bind_param("s", $group); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $guests = [];
    foreach ($rows as $r) {
        $name = $r['customer_name'] ?: 'Guest';
        if (!isset($guests[$name])) $guests[$name] = ['name' => $name, 'services' => [], 'subtotal' => 0.0, 'advance' => 0.0, 'eligible' => 0.0,
                                                      'elig' => array_fill_keys(array_keys(VOUCHER_SCOPES), 0.0)];
        // What each discount can apply to: Senior/PWD/Staff, and each voucher kind.
        if (!$r['order_paid']) {
            $guests[$name]['eligible'] += appt_discount_eligible($conn, (int)$r['appt_id'], (float)$r['total_amount'], true, 0, 'senior');
            foreach (array_keys(VOUCHER_SCOPES) as $vs) $guests[$name]['elig'][$vs] += appt_discount_eligible($conn, (int)$r['appt_id'], (float)$r['total_amount'], true, 0, 'voucher', $vs);
        }
        $amt = ($r['order_paid'] ? 0.0 : (float)$r['total_amount']) + (float)$r['extras'];
        // An advance paid at booking (calendar) is already collected: the
        // discount is on the full price, then the advance comes off.
        if (!$r['order_paid']) $guests[$name]['advance'] += (float)$r['adv'];
        $label = $r['order_paid'] ? $r['service_name'] . ' — added services' : $r['service_name'] . ((float)$r['extras'] > 0 ? ' + added services' : '');
        $guests[$name]['services'][] = ['name' => $label, 'amount' => $amt];
        $guests[$name]['subtotal'] += $amt;
    }
    return array_values($guests);
}
}

if (!function_exists('book_now_pay_group')) {
/**
 * Pay the selected guests of a booking group in one go. $discounts maps
 * guest name => either a discount key (BOOK_NOW_DISCOUNTS) or
 * ['type' => 'voucher'|'celebration'|key, 'value' => n, 'vtype' => 'fixed'|'percent'].
 * Only guests listed are paid. Percent discounts apply to each of the
 * guest's orders; a fixed ₱ voucher is split across them by price. Same
 * order fields check-in's Pay Now writes, so Mark Complete sees them as paid.
 */
function book_now_pay_group(mysqli $conn, string $group, array $discounts, string $method): array {
    if (!in_array($method, ['cash', 'gcash', 'maya', 'qrph', 'card', 'swiper'], true)) return ['ok' => false, 'message' => 'Choose a payment method.'];
    if (!$discounts) return ['ok' => false, 'message' => 'Choose at least one guest to pay for.'];

    $stmt = $conn->prepare("
        SELECT o.id AS order_id, o.customer_name, o.total_amount, (o.payment_status = 'paid') AS order_paid, a.id AS appt_id,
               IFNULL(a.advance_payment, 0) AS adv,
               (SELECT COALESCE(SUM(aes.charged_price), 0) FROM appointment_extra_services aes
                WHERE aes.appointment_id = a.id AND aes.payment_status != 'paid') AS extras
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN appointments a ON a.order_item_id = oi.id
        WHERE o.booking_group = ?
          AND a.status NOT IN ('cancelled', 'declined', 'refunded')
        HAVING order_paid = 0 OR extras > 0
    ");
    $stmt->bind_param("s", $group); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    // Normalise each guest's discount to [type, percent, fixed ₱].
    $spec = []; $vlabel = []; $vscope = [];
    foreach ($discounts as $name => $d) {
        $type = is_array($d) ? (string)($d['type'] ?? 'none') : (string)$d;
        $vscope[$name] = is_array($d) && array_key_exists((string)($d['scope'] ?? ''), VOUCHER_SCOPES) ? (string)$d['scope'] : 'all';
        $val  = is_array($d) ? max(0.0, (float)($d['value'] ?? 0)) : 0.0;
        $vt   = is_array($d) && ($d['vtype'] ?? '') === 'percent' ? 'percent' : 'fixed';
        $vlabel[$name] = is_array($d) && $type === 'voucher' ? (trim((string)($d['label'] ?? '')) ?: VOUCHER_SCOPES[$vscope[$name]]) : '';
        if (array_key_exists($type, BOOK_NOW_DISCOUNTS))      $spec[$name] = [$type, BOOK_NOW_DISCOUNTS[$type], 0.0];
        elseif ($type === 'celebration' && $val > 0)          $spec[$name] = ['celebration', min(100.0, $val), 0.0];
        elseif ($type === 'voucher' && $val > 0)              $spec[$name] = $vt === 'percent' ? ['voucher', min(100.0, $val), 0.0] : ['voucher', 0.0, $val];
        else                                                  $spec[$name] = ['none', 0, 0.0];
    }
    $guest_base = []; $elig = [];
    // An order already paid only owes its unpaid add-ons; its own payment
    // and discount stay as recorded (no new discount on those add-ons).
    // Senior / PWD / Staff leave out excluded services, a voucher counts only
    // the services of its kind (config.php).
    foreach ($rows as $r) {
        if ($r['order_paid']) continue;
        $n = $r['customer_name'] ?: 'Guest';
        $elig[$r['appt_id']] = appt_discount_eligible($conn, (int)$r['appt_id'], (float)$r['total_amount'], true, 0, $spec[$n][0] ?? 'none', $vscope[$n] ?? 'all');
        $guest_base[$n] = ($guest_base[$n] ?? 0) + $elig[$r['appt_id']];
    }

    $paid_total = 0.0; $paid_guests = [];
    $conn->begin_transaction();
    try {
        foreach ($rows as $r) {
            $name = $r['customer_name'] ?: 'Guest';
            if (!isset($spec[$name])) continue;
            [$dkey, $pct, $fixed] = $spec[$name];
            if ($r['order_paid']) {
                $ue = $conn->prepare("UPDATE appointment_extra_services SET payment_status='paid', payment_method=? WHERE appointment_id=? AND payment_status != 'paid'");
                $ue->bind_param("si", $method, $r['appt_id']);
                $ue->execute(); $ue->close();
                $paid_total += (float)$r['extras']; $paid_guests[$name] = true;
                continue;
            }
            $base = (float)$r['total_amount'] + (float)$r['extras'];
            $eb   = $dkey === 'celebration' ? $base : min($base, $elig[$r['appt_id']] ?? $base);
            $disc = $fixed > 0
                ? round(min($fixed, $guest_base[$name]) * ($guest_base[$name] > 0 ? $eb / $guest_base[$name] : 0), 2)
                : round($eb * $pct / 100, 2);
            $disc = min($disc, $base);
            $final = max(0.0, $base - $disc);

            $u = $conn->prepare("UPDATE orders SET payment_status='paid', payment_method=?, discount_type=?, discount_amount=?, final_amount=? WHERE id=? AND payment_status != 'paid'");
            $u->bind_param("ssddi", $method, $dkey, $disc, $final, $r['order_id']);
            $u->execute(); $u->close();
            if ($dkey === 'voucher') save_voucher_label($conn, (int)$r['order_id'], $vlabel[$name] ?? '');

            if ((float)$r['extras'] > 0) {
                $ue = $conn->prepare("UPDATE appointment_extra_services SET payment_status='paid', payment_method=? WHERE appointment_id=? AND payment_status != 'paid'");
                $ue->bind_param("si", $method, $r['appt_id']);
                $ue->execute(); $ue->close();
            }
            // Same as check-in: final_amount is the sale after discount; the
            // advance paid at booking is already in, so less is collected now.
            $paid_total += max(0.0, round($final - (float)$r['adv'], 2)); $paid_guests[$name] = true;
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Could not record the payment. Please try again.'];
    }
    if (!$paid_guests) return ['ok' => false, 'message' => 'Nothing left to pay for those guests.'];
    return ['ok' => true, 'total' => $paid_total, 'guests' => array_keys($paid_guests)];
}
}
