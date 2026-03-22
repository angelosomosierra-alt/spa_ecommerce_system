<?php
/**
 * search.php
 * Returns JSON: { products: [...], services: [...] }
 * Called by the header search bar via fetch.
 */
require_once '../config.php';
redirect_if_not_user();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 1) {
    echo json_encode(['products' => [], 'services' => []]);
    exit();
}

$like   = '%' . $q . '%';
$result = ['products' => [], 'services' => []];

// ── Search products ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, name, price, image, stock
    FROM products
    WHERE name LIKE ? OR description LIKE ?
    ORDER BY name ASC
    LIMIT 8
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) {
    $result['products'][] = $row;
}
$stmt->close();

// ── Search services ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, name, price, image, session_time
    FROM services
    WHERE name LIKE ? OR description LIKE ?
    ORDER BY name ASC
    LIMIT 8
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) {
    $result['services'][] = $row;
}
$stmt->close();

echo json_encode($result);