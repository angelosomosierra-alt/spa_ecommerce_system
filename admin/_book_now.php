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
}
}

// Discounts a group payment can apply, per guest. Same rates as check-in.
if (!defined('BOOK_NOW_DISCOUNTS')) {
    define('BOOK_NOW_DISCOUNTS', ['none' => 0, 'senior' => 20, 'pwd' => 20, 'employee' => 50]);
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

if (!function_exists('book_now_data')) {
function book_now_data(mysqli $conn): array {
    $services = $conn->query("
        SELECT s.id, s.name, s.price, s.session_time, s.category_id, c.name AS category_name
        FROM services s
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE s.deleted_at IS NULL
        ORDER BY c.name, s.name
    ")->fetch_all(MYSQLI_ASSOC);

    // Duration options. Multi-session packages (session_count > 1) are left
    // out: Book Now is for a visit happening now, not a package to schedule.
    $durations_by_svc = [];
    if ($conn->query("SHOW TABLES LIKE 'service_durations'")->num_rows > 0) {
        $has_sc = $conn->query("SHOW COLUMNS FROM service_durations LIKE 'session_count'")->num_rows > 0;
        $rows = $conn->query("SELECT * FROM service_durations"
            . ($has_sc ? " WHERE session_count <= 1" : "")
            . " ORDER BY service_id, duration_minutes")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $d) {
            $active = get_active_duration_price($d);
            $durations_by_svc[(int)$d['service_id']][] = [
                'id'    => (int)$d['id'],
                'm'     => (int)$d['duration_minutes'],
                'p'     => (float)$active['price'],
                'promo' => (bool)$active['is_promo_active'],
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
        ];
    }

    // Everyone, on-duty first in today's rotation order. Not timed in, or
    // already timed out → off duty (shown, never picked).
    $therapists = $conn->query("
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

    // Today's bookings that hold a therapist and/or a room. Times are minutes
    // since midnight, the unit the planner works in.
    $statuses = "'" . implode("','", RESOURCE_OCCUPYING_STATUSES) . "'";
    $busy = $conn->query("
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
        WHERE DATE(a.appointment_date) = CURDATE()
          AND a.status IN ($statuses)
    ")->fetch_all(MYSQLI_ASSOC);

    return [
        'now'       => (int)date('G') * 60 + (int)date('i'),
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
 * 'therapist_id', 'resource_id', 'start' (minutes since midnight)], ... ]], ... ]
 * Everything is re-checked here (price, duration, qualification, duty,
 * clashes) — the planner's choices are suggestions, never trusted as-is.
 */
function book_now_save(mysqli $conn, array $guests): array {
    if (count($guests) < 1 || count($guests) > 4) return ['ok' => false, 'message' => 'Add between 1 and 4 guests.'];

    $now_min   = (int)date('G') * 60 + (int)date('i');
    $today     = date('Y-m-d');
    $lines     = [];   // validated, ready to insert
    $planned   = [];   // this batch's own holds, for clashes inside the booking

    foreach ($guests as $gi => $g) {
        $gname = trim(sanitize_input((string)($g['name'] ?? '')));
        if ($gname === '') $gname = 'Guest ' . ($gi + 1);
        $items = $g['items'] ?? [];
        if (!is_array($items) || !$items) return ['ok' => false, 'message' => "$gname has no service."];

        foreach ($items as $it) {
            $service_id   = (int)($it['service_id'] ?? 0);
            $duration_id  = isset($it['duration_id']) && $it['duration_id'] !== null && $it['duration_id'] !== '' ? (int)$it['duration_id'] : null;
            $therapist_id = (int)($it['therapist_id'] ?? 0);
            $resource_id  = (int)($it['resource_id'] ?? 0);
            $start        = (int)($it['start'] ?? -1);

            $st = $conn->prepare("SELECT id, name, price, session_time FROM services WHERE id = ? AND deleted_at IS NULL");
            $st->bind_param("i", $service_id); $st->execute();
            $svc = $st->get_result()->fetch_assoc(); $st->close();
            if (!$svc) return ['ok' => false, 'message' => 'A service in this booking is no longer available.'];

            if ($duration_id) {
                $sd = $conn->prepare("SELECT * FROM service_durations WHERE id = ? AND service_id = ?");
                $sd->bind_param("ii", $duration_id, $service_id); $sd->execute();
                $dur_row = $sd->get_result()->fetch_assoc(); $sd->close();
                if (!$dur_row || (int)($dur_row['session_count'] ?? 1) > 1) return ['ok' => false, 'message' => "{$svc['name']}: that duration option is no longer available."];
                $minutes = (int)$dur_row['duration_minutes'];
                $price   = (float)get_active_duration_price($dur_row)['price'];
            } else {
                $minutes = max(1, (int)$svc['session_time']);
                $price   = (float)$svc['price'];
            }

            if ($start < $now_min - 10 || $start + $minutes > 24 * 60) {
                return ['ok' => false, 'message' => "{$svc['name']} for $gname: that start time has passed or runs past closing. Reopen Book Now to re-plan."];
            }
            $start_dt = $today . ' ' . sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);

            if (!in_array($therapist_id, get_booking_qualified_therapist_ids($conn, $service_id), true)) {
                return ['ok' => false, 'message' => "The therapist picked for $gname's {$svc['name']} isn't trained for it."];
            }
            $td = $conn->prepare("SELECT t.full_name FROM therapists t JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE() WHERE t.id = ? AND (ta.time_out IS NULL OR ta.time_out = '')");
            $td->bind_param("i", $therapist_id); $td->execute();
            $th = $td->get_result()->fetch_assoc(); $td->close();
            if (!$th) return ['ok' => false, 'message' => "The therapist picked for $gname's {$svc['name']} is not on duty."];

            $rs = $conn->prepare("SELECT name FROM service_resources WHERE id = ? AND is_active = 1");
            $rs->bind_param("i", $resource_id); $rs->execute();
            $room = $rs->get_result()->fetch_assoc(); $rs->close();
            if (!$room) return ['ok' => false, 'message' => "Pick a room for $gname's {$svc['name']}."];

            if (!book_now_therapist_free($conn, $therapist_id, $start_dt, $minutes)) {
                return ['ok' => false, 'message' => "{$th['full_name']} was just booked at " . date('g:i A', strtotime($start_dt)) . ". Reopen Book Now to re-plan."];
            }
            if (!is_resource_available($resource_id, $start_dt, $minutes)) {
                return ['ok' => false, 'message' => "{$room['name']} was just booked at " . date('g:i A', strtotime($start_dt)) . ". Reopen Book Now to re-plan."];
            }
            foreach ($planned as $p) {
                if ($start < $p['end'] && $p['start'] < $start + $minutes
                    && ($p['therapist_id'] === $therapist_id || $p['resource_id'] === $resource_id)) {
                    return ['ok' => false, 'message' => 'Two services in this booking overlap on the same therapist or room.'];
                }
            }
            $planned[] = ['start' => $start, 'end' => $start + $minutes, 'therapist_id' => $therapist_id, 'resource_id' => $resource_id];

            $lines[] = [
                'guest' => $gname, 'service_id' => $service_id, 'duration_id' => $duration_id,
                'therapist_id' => $therapist_id, 'resource_id' => $resource_id,
                'start_dt' => $start_dt, 'minutes' => $minutes, 'price' => $price,
            ];
        }
    }

    book_now_ensure_schema($conn);
    $group = 'BN' . date('ymdHis') . bin2hex(random_bytes(2));
    $walkin_user_id = get_walkin_customer_id();

    $conn->begin_transaction();
    try {
        foreach ($lines as $L) {
            // Unpaid, method 'onsite' — the same shape Walk-in's pay-later
            // bookings use. Payment and discount happen in appointments.php.
            $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, booking_date, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number, booking_group) VALUES (?, ?, '', ?, ?, 'onsite', 'unpaid', 'approved', 'none', 0, ?, NULL, ?)");
            $stmt->bind_param("issdds", $walkin_user_id, $L['guest'], $L['start_dt'], $L['price'], $L['price'], $group);
            $stmt->execute(); $order_id = $stmt->insert_id; $stmt->close();

            $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
            $stmt->bind_param("iidd", $order_id, $L['service_id'], $L['price'], $L['price']);
            $stmt->execute(); $order_item_id = $stmt->insert_id; $stmt->close();

            $stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count, service_type, rate_type, partner_id, charged_price, customer_note, advance_payment, advance_payment_date, advance_payment_method, resource_id, duration_minutes, service_duration_id) VALUES (?, ?, ?, ?, 'assigned', 1, 'onsite', 'regular', NULL, ?, '', 0, NULL, 'cash', ?, ?, ?)");
            $stmt->bind_param("iiisdiii", $walkin_user_id, $L['service_id'], $order_item_id, $L['start_dt'], $L['price'], $L['resource_id'], $L['minutes'], $L['duration_id']);
            $stmt->execute(); $appointment_id = $stmt->insert_id; $stmt->close();

            // Commission: same rules as the classic Quick Book (regular rate,
            // no booking-time discount). Packages sum their components.
            $commission = 0.00;
            if (!empty(get_package_components($conn, $L['service_id']))) {
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
            $stmt = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, commission, people_handled, notes) VALUES (?, ?, ?, 1, '')");
            $stmt->bind_param("iid", $appointment_id, $L['therapist_id'], $commission);
            $stmt->execute(); $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Could not save the booking. Please try again.'];
    }

    require_once __DIR__ . '/../notify.php';
    if (function_exists('log_activity')) {
        log_activity($conn, 'book_now', 'Book Now: ' . count($guests) . ' guest(s), ' . count($lines) . ' service(s), group ' . $group, 'order', null, null);
    }
    return ['ok' => true, 'group' => $group, 'count' => count($lines),
            'message' => 'Booked ' . count($guests) . ' guest' . (count($guests) > 1 ? 's' : '') . ' · ' . count($lines) . ' service' . (count($lines) > 1 ? 's' : '') . '. Collect payment in Appointments at check-in or when done.'];
}
}

if (!function_exists('book_now_group_bill')) {
/**
 * Unpaid part of a booking group, per guest: each guest's services (one
 * order each) with the unpaid extras added to them since booking.
 */
function book_now_group_bill(mysqli $conn, string $group): array {
    $stmt = $conn->prepare("
        SELECT o.id AS order_id, o.customer_name, o.total_amount, a.id AS appt_id, a.appointment_date,
               s.name AS service_name,
               (SELECT COALESCE(SUM(aes.charged_price), 0) FROM appointment_extra_services aes
                WHERE aes.appointment_id = a.id AND aes.payment_status != 'paid') AS extras
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN appointments a ON a.order_item_id = oi.id
        JOIN services s ON s.id = a.service_id
        WHERE o.booking_group = ?
          AND o.payment_status != 'paid'
          AND a.status NOT IN ('cancelled', 'declined', 'refunded')
        ORDER BY o.customer_name, a.appointment_date
    ");
    $stmt->bind_param("s", $group); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $guests = [];
    foreach ($rows as $r) {
        $name = $r['customer_name'] ?: 'Guest';
        if (!isset($guests[$name])) $guests[$name] = ['name' => $name, 'services' => [], 'subtotal' => 0.0];
        $amt = (float)$r['total_amount'] + (float)$r['extras'];
        $guests[$name]['services'][] = ['name' => $r['service_name'] . ((float)$r['extras'] > 0 ? ' + extras' : ''), 'amount' => $amt];
        $guests[$name]['subtotal'] += $amt;
    }
    return array_values($guests);
}
}

if (!function_exists('book_now_pay_group')) {
/**
 * Pay the selected guests of a booking group in one go. $discounts maps
 * guest name => discount key (BOOK_NOW_DISCOUNTS); only guests listed are
 * paid. Each order gets its own share of its guest's discount — the same
 * fields check-in's Pay Now writes, so Mark Complete sees them as paid.
 */
function book_now_pay_group(mysqli $conn, string $group, array $discounts, string $method): array {
    if (!in_array($method, ['cash', 'gcash', 'maya', 'qrph', 'card', 'swiper'], true)) return ['ok' => false, 'message' => 'Choose a payment method.'];
    if (!$discounts) return ['ok' => false, 'message' => 'Choose at least one guest to pay for.'];

    $stmt = $conn->prepare("
        SELECT o.id AS order_id, o.customer_name, o.total_amount, a.id AS appt_id,
               (SELECT COALESCE(SUM(aes.charged_price), 0) FROM appointment_extra_services aes
                WHERE aes.appointment_id = a.id AND aes.payment_status != 'paid') AS extras
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN appointments a ON a.order_item_id = oi.id
        WHERE o.booking_group = ?
          AND o.payment_status != 'paid'
          AND a.status NOT IN ('cancelled', 'declined', 'refunded')
    ");
    $stmt->bind_param("s", $group); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $paid_total = 0.0; $paid_guests = [];
    $conn->begin_transaction();
    try {
        foreach ($rows as $r) {
            $name = $r['customer_name'] ?: 'Guest';
            if (!array_key_exists($name, $discounts)) continue;
            $dkey = array_key_exists($discounts[$name], BOOK_NOW_DISCOUNTS) ? $discounts[$name] : 'none';
            $base = (float)$r['total_amount'] + (float)$r['extras'];
            $disc = round($base * BOOK_NOW_DISCOUNTS[$dkey] / 100, 2);
            $final = max(0.0, $base - $disc);

            $u = $conn->prepare("UPDATE orders SET payment_status='paid', payment_method=?, discount_type=?, discount_amount=?, final_amount=? WHERE id=? AND payment_status != 'paid'");
            $u->bind_param("ssddi", $method, $dkey, $disc, $final, $r['order_id']);
            $u->execute(); $u->close();

            if ((float)$r['extras'] > 0) {
                $ue = $conn->prepare("UPDATE appointment_extra_services SET payment_status='paid', payment_method=? WHERE appointment_id=? AND payment_status != 'paid'");
                $ue->bind_param("si", $method, $r['appt_id']);
                $ue->execute(); $ue->close();
            }
            $paid_total += $final; $paid_guests[$name] = true;
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
