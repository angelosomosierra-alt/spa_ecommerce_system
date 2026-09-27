<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in() || !is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$type             = $_GET['type'] ?? '';
$start_datetime   = $_GET['start'] ?? '';
$duration_minutes = intval($_GET['duration'] ?? 0);
$exclude_appt_id  = intval($_GET['exclude_appt_id'] ?? 0);

if (!in_array($type, ['room', 'chair', 'head_spa'], true)) {
    echo json_encode(['error' => 'Invalid type']); exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $start_datetime)) {
    echo json_encode(['error' => 'Invalid start datetime']); exit;
}
if ($duration_minutes <= 0) {
    echo json_encode(['error' => 'Invalid duration']); exit;
}

// Reuse the exact same shared helper the server-side save validation uses —
// this endpoint is a live preview of that same check, never a separate rule.
$resources = get_available_resources($type, $start_datetime, $duration_minutes);

// If the caller is re-checking for an appointment that ALREADY holds one of
// these resources (editing/approving), that resource would otherwise show as
// conflicting with itself — add it back in if it's excluded and still valid.
if ($exclude_appt_id > 0) {
    $stmt = $conn->prepare("SELECT resource_id FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $exclude_appt_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc()['resource_id'] ?? null;
    $stmt->close();
    if ($current && is_resource_available((int)$current, $start_datetime, $duration_minutes, $exclude_appt_id)) {
        $already_listed = false;
        foreach ($resources as $r) { if ((int)$r['id'] === (int)$current) { $already_listed = true; break; } }
        if (!$already_listed) {
            $rs = $conn->prepare("SELECT * FROM service_resources WHERE id = ? AND is_active = 1");
            $rs->bind_param("i", $current);
            $rs->execute();
            $row = $rs->get_result()->fetch_assoc();
            $rs->close();
            if ($row) $resources[] = $row;
        }
    }
}

echo json_encode(['resources' => $resources]);
