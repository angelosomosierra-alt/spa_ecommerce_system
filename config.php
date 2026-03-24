<?php

// Database credentials
define("DB_SERVER", "localhost");
define("DB_USERNAME", "root");
define("DB_PASSWORD", "");
define("DB_NAME", "spa_ecommerce_db");

define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'keer.gonzalez.ui@phinmaed.com');
define('MAIL_PASSWORD', 'dlptbvruzaswitny'); // Gmail App Password
define('MAIL_PORT',     587);
define('MAIL_FROM',     'keer.gonzalez.ui@phinmaed.com');
define('MAIL_NAME',     'Spa System');

// ─── PAYMONGO ─────────────────────────────────────────────────────────────────
// Get your keys from: https://dashboard.paymongo.com/developers
// Use TEST keys while developing (sk_test_...), switch to LIVE keys for production
define('PAYMONGO_SECRET_KEY',    'sk_test_EbjoHRbpFf5kkJGxAxTe2YaJ'); // Your PayMongo Secret Key
define('PAYMONGO_PUBLIC_KEY',    'pk_test_WKsTtXWAa8GspKAGSYs8ARKF'); // Your PayMongo Public Key
define('PAYMONGO_WEBHOOK_SECRET','whsk_xxxxxxxxxxxxxxxxxxxx');    // From Dashboard → Webhooks

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Base URL for the project
define("BASE_URL", "http://localhost/spa_ecommerce_system/");

// Upload directories
define("UPLOAD_DIR_SERVICES", __DIR__ . "/uploads/services/");
define("UPLOAD_DIR_PRODUCTS", __DIR__ . "/uploads/products/");

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ─── SANITIZE ─────────────────────────────────────────────────────────────────
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// ─── AUTH HELPERS ─────────────────────────────────────────────────────────────
function is_logged_in() {
    return isset($_SESSION["user_id"]);
}

function is_admin() {
    return isset($_SESSION["role"]) && $_SESSION["role"] === "admin";
}

function redirect_if_not_admin() {
    if (!is_logged_in() || !is_admin()) {
        header("Location: " . BASE_URL . "user/auth.php");
        exit();
    }
}

function redirect_if_not_user() {
    if (!is_logged_in() || is_admin()) {
        header("Location: " . BASE_URL . "user/auth.php");
        exit();
    }
}

function redirect_if_logged_in() {
    if (is_logged_in()) {
        if (is_admin()) {
            header("Location: " . BASE_URL . "admin/index.php");
        } else {
            header("Location: " . BASE_URL . "user/index.php");
        }
        exit();
    }
}


function logout($conn = null) {
    // Save cart before destroying session
    if ($conn && isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "user/auth.php");
    exit();
}

// ─── CART HELPERS ─────────────────────────────────────────────────────────────

/**
 * Sync the entire session cart to the database.
 * Called after add/update operations.
 */
function sync_cart_to_db($conn, $user_id, $cart) {
    if (!$user_id || empty($cart)) return;

    foreach ($cart as $product_id => $item) {
        $qty = intval($item['quantity']);
        $stmt = $conn->prepare("
            INSERT INTO cart (user_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ");
        $stmt->bind_param("iiii", $user_id, $product_id, $qty, $qty);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Remove a single item from the database cart.
 * Called after removing an item.
 */
function remove_cart_item_from_db($conn, $user_id, $product_id) {
    if (!$user_id) return;

    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear the entire cart from the database.
 * Called after placing an order.
 */
function clear_cart_from_db($conn, $user_id) {
    if (!$user_id) return;

    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Load cart from database into session.
 * Called after login.
 */
function load_cart_from_db($conn, $user_id) {
    if (!$user_id) return [];

    $stmt = $conn->prepare("
        SELECT c.product_id, c.quantity,
               p.name, p.price, p.image
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $cart = [];
    while ($row = $result->fetch_assoc()) {
        $cart[$row['product_id']] = [
            'type'     => 'product',
            'id'       => $row['product_id'],
            'name'     => $row['name'],
            'image'    => $row['image'],
            'price'    => $row['price'],
            'quantity' => $row['quantity']
        ];
    }
    return $cart;
}

/**
 * Save session cart to database.
 * Called before logout.
 */
function save_cart_to_db($conn, $user_id, $cart) {
    if (!$user_id || empty($cart)) return;

    foreach ($cart as $product_id => $item) {
        $qty = intval($item['quantity']);
        $stmt = $conn->prepare("
            INSERT INTO cart (user_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ");
        $stmt->bind_param("iiii", $user_id, $product_id, $qty, $qty);
        $stmt->execute();
        $stmt->close();
    }
}