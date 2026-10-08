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
 * Saving a booking is not wired yet; the modal is front end only for now.
 * Requires: config.php loaded (get_booking_qualified_therapist_ids,
 * get_active_duration_price, RESOURCE_OCCUPYING_STATUSES).
 * ─────────────────────────────────────────────────────────────────────────────
 */

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
               GROUP_CONCAT(DISTINCT at2.therapist_id) AS therapist_ids,
               COALESCE(o.customer_name, u.full_name) AS who
        FROM appointments a
        LEFT JOIN services s ON s.id = a.service_id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE DATE(a.appointment_date) = CURDATE()
          AND a.status IN ($statuses)
        GROUP BY a.id
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
