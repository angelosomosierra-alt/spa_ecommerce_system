<?php
/**
 * _appt_desk_data.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Read-only data for the Appointments desk view (_appt_desk.php): one JSON
 * snapshot of the bookings on the board, with everything the side panel needs
 * (services + add-ons + package sessions, therapist per person slot, room,
 * bill). The desk never saves anything itself — every change is posted to the
 * existing actions in appointments.php, so payment, commission and package
 * rules stay exactly where they are.
 *
 * Requires: config.php loaded; appointments.php's self-healing schema already
 * run (appointment_sessions, booking_group, session_group_id, resource_id).
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!function_exists('appt_desk_data')) {
function appt_desk_data(mysqli $conn, string $range): array {
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $walkin_uid = get_walkin_customer_id();

    // Which bookings are on the board:
    //   today    → today's bookings, packages with a session today, and every
    //              unfinished booking from an earlier day (flagged overdue)
    //   tomorrow → tomorrow's bookings and package sessions
    //   upcoming → every unfinished booking, any date
    // Nothing is ever left off just because its date has passed: an open
    // booking stays on the board until it's completed, cancelled or declined.
    // "Done" only ever shows bookings completed for the chosen day.
    if ($range === 'tomorrow') { $from = $tomorrow; $to = $tomorrow; }
    elseif ($range === 'upcoming') { $from = $today; $to = '2999-12-31'; }
    else { $range = 'today'; $from = $today; $to = $today; }

    $statuses = "'pending','assigned','approved','completed'";
    $stmt = $conn->prepare("
        SELECT a.id, a.user_id, a.status, a.appointment_date, a.people_count, a.customer_note,
               a.charged_price, a.advance_payment, a.advance_payment_date, a.advance_payment_method,
               a.resource_id, a.duration_minutes, a.service_id, a.order_item_id, a.rate_type,
               a.session_group_id,
               s.name AS service_name, s.session_time, c.name AS category_name,
               IFNULL(sd.session_count, 1) AS session_count,
               u.full_name AS user_name, u.phone AS user_phone,
               o.id AS order_id, o.customer_name, o.phone AS order_phone, o.total_amount,
               o.discount_type, o.discount_amount, o.final_amount, o.payment_status,
               o.payment_method, o.paymongo_method, o.booking_group
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN categories c ON c.id = s.category_id
        LEFT JOIN service_durations sd ON sd.id = a.service_duration_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        WHERE a.status IN ($statuses)
          AND (o.id IS NULL OR o.payment_status != 'pending_payment')
          AND (a.session_group_id IS NULL OR a.id = a.session_group_id)
          AND (
                (a.status != 'completed' AND (
                    DATE(a.appointment_date) BETWEEN ? AND ?
                    OR EXISTS (SELECT 1 FROM appointment_sessions x
                               WHERE x.appointment_id = a.id AND x.status != 'completed'
                                 AND DATE(x.session_date) BETWEEN ? AND ?)
                    OR (? != 'tomorrow' AND DATE(a.appointment_date) < ?)
                ))
             OR (a.status = 'completed' AND DATE(a.appointment_date) = ?)
          )
        ORDER BY a.appointment_date ASC
    ");
    $done_day = $range === 'tomorrow' ? $tomorrow : $today;
    $stmt->bind_param("sssssss", $from, $to, $from, $to, $range, $today, $done_day);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $slots = $extras = $sessions = [];
    if ($ids) {
        $in = implode(',', $ids); // ints only
        foreach ($conn->query("SELECT appointment_id, person_slot, therapist_id FROM appointment_therapists WHERE appointment_id IN ($in) ORDER BY person_slot")->fetch_all(MYSQLI_ASSOC) as $r) {
            $slots[(int)$r['appointment_id']][] = ['slot' => (int)$r['person_slot'], 'therapist_id' => $r['therapist_id'] !== null ? (int)$r['therapist_id'] : null];
        }
        foreach ($conn->query("
            SELECT aes.id, aes.appointment_id, aes.service_id, aes.therapist_id, aes.person_label, aes.charged_price,
                   aes.payment_status, aes.payment_method, aes.session_number, aes.total_sessions, aes.extra_group_id,
                   aes.session_status, aes.therapist_selected, s.name, c.name AS category_name
            FROM appointment_extra_services aes
            JOIN services s ON s.id = aes.service_id
            LEFT JOIN categories c ON c.id = s.category_id
            WHERE aes.appointment_id IN ($in)
            ORDER BY aes.id")->fetch_all(MYSQLI_ASSOC) as $r) {
            $extras[(int)$r['appointment_id']][] = $r;
        }
        foreach ($conn->query("SELECT id, appointment_id, session_number, session_date, therapist_id, status FROM appointment_sessions WHERE appointment_id IN ($in) ORDER BY session_number")->fetch_all(MYSQLI_ASSOC) as $r) {
            $sessions[(int)$r['appointment_id']][] = [
                'id' => (int)$r['id'], 'n' => (int)$r['session_number'], 'status' => $r['status'],
                'date' => $r['session_date'], 'therapist_id' => $r['therapist_id'] !== null ? (int)$r['therapist_id'] : null,
            ];
        }
    }

    $qual_cache = [];
    $qual = function (int $sid) use ($conn, &$qual_cache) {
        if (!isset($qual_cache[$sid])) $qual_cache[$sid] = get_booking_qualified_therapist_ids($conn, $sid);
        return $qual_cache[$sid];
    };

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $is_walkin = (int)$r['user_id'] === $walkin_uid;
        $name = $is_walkin ? ($r['customer_name'] ?: 'Walk-in') : ($r['user_name'] ?: ($r['customer_name'] ?: 'Customer'));
        $adv  = (float)$r['advance_payment'];
        $source = !$is_walkin ? 'Online' : (!empty($r['booking_group']) ? 'Book Now' : ($adv > 0 ? 'Calendar' : 'Walk-in'));

        // Add-ons: a multi-session add-on is one row per session sharing an
        // extra_group_id. Shown as one add-on with its sessions.
        $addons = []; $extras_unpaid = 0.0; $extras_all = 0.0;
        foreach ($extras[$id] ?? [] as $e) {
            $extras_all += (float)$e['charged_price'];
            if ($e['payment_status'] !== 'paid') $extras_unpaid += (float)$e['charged_price'];
            $key = ((int)$e['total_sessions'] > 1 && $e['extra_group_id']) ? 'g' . $e['extra_group_id'] : 'e' . $e['id'];
            if (!isset($addons[$key])) {
                $addons[$key] = [
                    'name' => $e['name'], 'service_id' => (int)$e['service_id'], 'price' => 0.0,
                    'res' => book_now_resource_type($e['category_name']), 'qual' => $qual((int)$e['service_id']),
                    'person' => $e['person_label'], 'rows' => [],
                ];
            }
            $addons[$key]['price'] += (float)$e['charged_price'];
            $addons[$key]['rows'][] = [
                'id' => (int)$e['id'], 'therapist_id' => $e['therapist_id'] !== null ? (int)$e['therapist_id'] : null,
                'n' => (int)($e['session_number'] ?: 1), 'of' => (int)($e['total_sessions'] ?: 1),
                'status' => $e['session_status'] ?: 'pending', 'paid' => $e['payment_status'] === 'paid',
                'selected' => (bool)$e['therapist_selected'],
            ];
        }

        // Bill, same arithmetic as the Mark Complete window: once the order is
        // paid, only still-unpaid add-ons remain to collect.
        $order_total = (float)$r['total_amount'];
        $bdisc       = (float)$r['discount_amount'];
        $paid        = ($r['payment_status'] ?? '') === 'paid';
        $due = $paid ? $extras_unpaid : max(0.0, $order_total + $extras_all - $bdisc - $adv);

        $out[] = [
            'id' => $id,
            'status' => $r['status'],
            'date' => substr($r['appointment_date'], 0, 10),
            'overdue' => $r['status'] !== 'completed' && substr($r['appointment_date'], 0, 10) < $today,
            'start' => $r['appointment_date'],
            'name' => $name,
            'phone' => $r['order_phone'] ?: ($is_walkin ? '' : ($r['user_phone'] ?? '')),
            'note' => $r['customer_note'] ?? '',
            'source' => $source,
            'online' => !$is_walkin,
            'people' => max(1, (int)$r['people_count']),
            'service' => [
                'id' => (int)$r['service_id'], 'name' => $r['service_name'],
                'mins' => (int)($r['duration_minutes'] ?: $r['session_time'] ?: 60),
                'price' => (float)$r['charged_price'],
                'res' => book_now_resource_type($r['category_name']),
                'qual' => $qual((int)$r['service_id']),
            ],
            'slots' => $slots[$id] ?? [],
            'resource_id' => $r['resource_id'] !== null ? (int)$r['resource_id'] : null,
            'addons' => array_values($addons),
            'sessions' => ((int)$r['session_count'] > 1) ? ($sessions[$id] ?? []) : [],
            'legacy_two_session' => !empty($r['session_group_id']),
            'group' => $r['booking_group'] ?: null,
            'bill' => [
                'order_total' => $order_total, 'addons' => $extras_all, 'addons_unpaid' => $extras_unpaid,
                'discount' => $bdisc, 'discount_type' => $r['discount_type'] ?: 'none',
                'advance' => $adv, 'advance_method' => $r['advance_payment_method'],
                'advance_today' => $r['advance_payment_date'] === $today,
                'paid' => $paid, 'paid_amount' => $paid ? (float)$r['final_amount'] : 0.0,
                'method' => $r['paymongo_method'] ?: $r['payment_method'],
                'due' => round($due, 2),
            ],
        ];
    }

    $flash = null;
    if (!empty($_SESSION['flash_message'])) {
        $flash = ['message' => $_SESSION['flash_message'], 'type' => $_SESSION['flash_message_type'] ?? 'success'];
        unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
    }

    return [
        'range' => $range,
        'today' => $today,
        'now' => date('Y-m-d H:i:s'),
        'appointments' => $out,
        'therapists' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['full_name'], 'on' => (bool)$t['on_duty']],
            $conn->query("
                SELECT t.id, t.full_name, (ta.id IS NOT NULL AND (ta.time_out IS NULL OR ta.time_out = '')) AS on_duty
                FROM therapists t
                LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
                ORDER BY on_duty DESC, ta.rotation_order IS NULL, ta.rotation_order, t.full_name
            ")->fetch_all(MYSQLI_ASSOC)),
        'resources' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'type' => $x['type']],
            $conn->query("SELECT id, name, type FROM service_resources WHERE is_active = 1 ORDER BY FIELD(type,'room','chair','head_spa'), sort_order, name")->fetch_all(MYSQLI_ASSOC)),
        'flash' => $flash,
        'cashier' => is_cashier(),
        'full_access' => is_full_access(),
    ];
}
}
