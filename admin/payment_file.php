<?php
// payment_file.php — a customer's GCash receipt screenshot (?id=manual payment
// id), for staff only. Receipts are kept outside the public folders; see
// manual_payment_dir() in config.php.
require_once '../config.php';
redirect_if_not_admin();
ensure_manual_payments_table($conn);
$id = (int)($_GET['id'] ?? 0);
$q = $conn->prepare("SELECT receipt_file FROM manual_payments WHERE id = ?");
$q->bind_param("i", $id); $q->execute();
$row = $q->get_result()->fetch_assoc(); $q->close();
$path = $row ? manual_payment_dir() . basename($row['receipt_file']) : '';
if ($path === '' || !is_file($path)) { http_response_code(404); echo 'Receipt not found.'; exit; }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=600');
readfile($path);
