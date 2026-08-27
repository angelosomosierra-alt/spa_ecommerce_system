<?php
require_once '../config.php';

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
