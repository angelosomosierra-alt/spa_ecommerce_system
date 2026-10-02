<?php
/**
 * search.php — public product/service search, used by the shared header.php
 * search bar on index.php/services.php/products.php. No login required
 * (unlike user/search.php, which is for the logged-in account area and
 * redirects anonymous visitors — not usable here).
 * Returns JSON: { products: [...], services: [...] }
 */
require_once 'config.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode(['products' => [], 'services' => []]);
    exit();
}

$like   = '%' . $q . '%';
$result = ['products' => [], 'services' => []];

$stmt = $conn->prepare("
    SELECT id, name, price, image, stock, category_id
    FROM products
    WHERE (name LIKE ? OR description LIKE ?) AND deleted_at IS NULL
    ORDER BY name ASC LIMIT 8
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) $result['products'][] = $row;
$stmt->close();

$stmt = $conn->prepare("
    SELECT id, name, price, image, session_time, category_id
    FROM services
    WHERE (name LIKE ? OR description LIKE ?) AND deleted_at IS NULL
    ORDER BY name ASC LIMIT 8
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) $result['services'][] = $row;
$stmt->close();

echo json_encode($result);
