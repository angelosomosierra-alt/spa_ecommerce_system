<?php
/**
 * slots.php — AJAX endpoint for availability
 * Returns available time slots for a given service + date + people count
 *
 * GET params:
 *   service_id   INT
 *   date         Y-m-d
 *   people       INT  (default 1)
 *   rate_type    string (regular|home|hotel)
 *
 * Returns JSON array of slot objects
 */
require_once '../config.php';
require_once __DIR__ . '/availability.php';

header('Content-Type: application/json');

// ── Auth check — must be logged in (customer or admin) ───────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$service_id   = intval($_GET['service_id']   ?? 0);
$date         = $_GET['date']              ?? date('Y-m-d');
$people       = max(1, intval($_GET['people'] ?? 1));
$rate_type    = in_array($_GET['rate_type'] ?? '', ['regular','home','hotel','influencer'])
                ? $_GET['rate_type'] : 'regular';
$therapist_id = intval($_GET['therapist_id'] ?? 0);

if (!$service_id) {
    echo json_encode(['error' => 'Missing service_id']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid date format']);
    exit;
}

$engine = new AvailabilityEngine($conn);

// Check if service has any qualified therapist at all
if (!$engine->serviceHasTherapist($service_id)) {
    echo json_encode([
        'slots'            => [],
        'service_status'   => 'unavailable',
        'message'          => 'This service is currently unavailable — no qualified therapist assigned.',
    ]);
    exit;
}

$slots = $engine->getAvailableSlots($service_id, $date, $people, $rate_type, $therapist_id);

// Count available slots
$available_count = count(array_filter($slots, fn($s) => in_array($s['status'], ['available','warning'])));
$total_count     = count($slots);

// Contextual "why" message for zero-availability responses
// (serviceHasTherapist() already passed above, so therapists exist for this service)
$message     = null;
$reason_code = null;
if ($available_count === 0) {
    if ($date === date('Y-m-d')) {
        $message     = 'No therapists are on duty for the rest of today. Please select a future date to see available times.';
        $reason_code = 'no_duty_today';
    } else {
        $message     = 'All time slots are fully booked on this date. Please try another date.';
        $reason_code = 'fully_booked';
    }
}

echo json_encode([
    'slots'          => $slots,
    'date'           => $date,
    'service_id'     => $service_id,
    'people'         => $people,
    'available_count'=> $available_count,
    'total_count'    => $total_count,
    'service_status' => $available_count > 0 ? 'available' : 'unavailable',
    'message'        => $message,
    'reason_code'    => $reason_code,
]);