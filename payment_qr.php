<?php
// payment_qr.php — the spa's GCash QR image for the checkout page (public; it
// is the spa's own payment QR). Set in Admin → Payment Log. See gcash_settings().
require_once __DIR__ . '/config.php';
$g = gcash_settings($conn);
$file = basename($g['gcash_qr_file'] ?? '');
$path = $file !== '' ? manual_payment_dir() . $file : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=300');
readfile($path);
