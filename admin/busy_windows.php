<?php
require_once '../config.php';
require_once __DIR__ . '/availability.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$service_id   = intval($_GET['service_id']   ?? 0);
$date         = $_GET['date'] ?? date('Y-m-d');
$rate_type    = in_array($_GET['rate_type'] ?? '', ['regular','home','hotel','influencer'])
                    ? $_GET['rate_type'] : 'regular';
$therapist_id = intval($_GET['therapist_id'] ?? 0);
$people       = max(1, intval($_GET['people'] ?? 1));

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

if ($service_id <= 0) {
    echo json_encode(['error' => 'Missing service_id']);
    exit;
}

global $conn;
$av = new AvailabilityEngine($conn);

$svc = $av->getService($service_id);
if (!$svc) {
    echo json_encode(['error' => 'Service not found']);
    exit;
}

$session_time = intval($svc['session_time']);
$buffer       = ($rate_type === 'home') ? 30 : 0;
$open_hour    = AvailabilityEngine::OPEN_HOUR;
$close_hour   = AvailabilityEngine::CLOSE_HOUR;

// Determine today / now_minutes in Manila timezone
$tz         = new DateTimeZone('Asia/Manila');
$now        = new DateTime('now', $tz);
$today_str  = $now->format('Y-m-d');
$is_today   = ($date === $today_str);
$now_min         = $is_today ? ((int)$now->format('H') * 60 + (int)$now->format('i')) : 0;
$min_start_min   = $is_today ? ($now_min + 120) : 0; // 2-hour advance minimum for checkout

$on_duty     = false;
$message     = '';
$reason_code = '';
$busy        = [];

if ($therapist_id > 0) {
    // ── Specific therapist ────────────────────────────────────────────────────
    if (!$av->serviceHasTherapist($service_id, $therapist_id)) {
        $message     = 'Selected therapist is not qualified for this service.';
        $reason_code = 'NOT_QUALIFIED';
        echo json_encode([
            'session_time'      => $session_time * $people,
            'buffer'            => $buffer,
            'open_hour'         => $open_hour,
            'close_hour'        => $close_hour,
            'busy'              => [],
            'on_duty'           => false,
            'is_today'          => $is_today,
            'now_minutes'       => $now_min,
            'min_start_minutes' => $min_start_min,
            'message'           => $message,
            'reason_code'       => $reason_code,
        ]);
        exit;
    }

    // Check on-duty for today
    if ($is_today) {
        $duty_ids = $av->getOnDutyQualifiedIds($service_id);
        $on_duty  = in_array($therapist_id, $duty_ids);
        if (!$on_duty) {
            $message     = 'Selected therapist is not on duty today.';
            $reason_code = 'NOT_ON_DUTY';
        }
    } else {
        $on_duty = true; // future date — assume schedulable
    }

    $busy = $av->getBusyWindowsForTherapist($therapist_id, $date);

} else {
    // ── Any available ─────────────────────────────────────────────────────────
    if ($is_today) {
        $therapist_ids = $av->getOnDutyQualifiedIds($service_id);
        $on_duty       = !empty($therapist_ids);
        if (!$on_duty) {
            $message     = 'No qualified therapists are on duty today.';
            $reason_code = 'NO_THERAPIST_ON_DUTY';
        }
    } else {
        $therapist_ids = $av->getQualifiedTherapistIds($service_id);
        $on_duty       = !empty($therapist_ids);
        if (!$on_duty) {
            $message     = 'No qualified therapists available for this service.';
            $reason_code = 'NO_THERAPIST';
        }
    }

    if (!empty($therapist_ids)) {
        $busy = $av->getBusyWindowsAnyAvailable(
            $therapist_ids, $date, $session_time * $people, $buffer
        );
    }
}

echo json_encode([
    'session_time'      => $session_time * $people,
    'buffer'            => $buffer,
    'open_hour'         => $open_hour,
    'close_hour'        => $close_hour,
    'busy'              => $busy,
    'on_duty'           => $on_duty,
    'is_today'          => $is_today,
    'now_minutes'       => $now_min,
    'min_start_minutes' => $min_start_min,
    'message'           => $message,
    'reason_code'       => $reason_code,
]);
