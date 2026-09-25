<?php
require_once '../config.php';

// This endpoint's query references session_group_id directly — self-heal it
// here too, since this AJAX endpoint can be hit independently of admin/appointments.php.
$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS session_group_id INT NULL DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_session_group (session_group_id)");

header('Content-Type: application/json');

if (!is_logged_in() || !is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$since_id = max(0, intval($_GET['since_id'] ?? 0));

// Replicate range/filter_date resolution from appointments.php verbatim
$range       = $_GET['range'] ?? '';
$filter_date = $_GET['filter_date'] ?? ($_GET['appt_date'] ?? '');
if (in_array($range, ['yesterday', 'today', 'tomorrow'])) {
    $filter_date = match($range) {
        'yesterday' => date('Y-m-d', strtotime('-1 day')),
        'today'     => date('Y-m-d'),
        'tomorrow'  => date('Y-m-d', strtotime('+1 day')),
    };
} elseif ($range === 'all') {
    $filter_date = '';
}
if ($filter_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date))
    $filter_date = '';

// WHERE: same payment-status exclusion as main listing + since_id + optional date
$where_parts = [
    "(a.order_item_id IS NULL OR EXISTS (
        SELECT 1 FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE oi.id = a.order_item_id AND o.payment_status != 'pending_payment'
    ))",
    // 2-session packages: Session 2's row is never its own standalone card on
    // appointments.php (it's nested inside Session 1's), so it must never be
    // counted here either — otherwise its id can never be "seen" (no .appt-card
    // of its own advances lastSeenId) and this popup fires forever. Mirrors the
    // same exclusion applied to appointments.php's main query and stats loop.
    "(a.session_group_id IS NULL OR a.id = a.session_group_id)",
    "a.id > ?"
];
$bind_types = 'i';
$bind_vals  = [$since_id];

if (!empty($filter_date)) {
    $where_parts[] = "DATE(a.appointment_date) = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_date;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c, COALESCE(MAX(a.id),0) AS max_id
     FROM appointments a
     $where_sql"
);
$stmt->bind_param($bind_types, ...$bind_vals);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode(['new_count' => (int)$row['c'], 'max_id' => (int)$row['max_id']]);
