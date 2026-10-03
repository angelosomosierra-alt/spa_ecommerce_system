<?php
/**
 * homepage_data.php — shared data prep for the homepage, used by both
 * index.php (public) and user/index.php (same homepage, reached while
 * logged in). Keeping this in one place avoids the two pages' content
 * drifting apart, which is what happened before the redesign.
 * Expects $conn to already be available (config.php already required).
 */

// 1. SESSION & LOGOUT LOGIC
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . 'index.php');
    exit();
}

// 2. CONTACT FORM HANDLER
$contact_sent = false;
$contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $cf_name    = sanitize_input($_POST['cf_name'] ?? '');
    $cf_email   = sanitize_input($_POST['cf_email'] ?? '');
    $cf_message = sanitize_input($_POST['cf_message'] ?? '');

    if (empty($cf_name) || empty($cf_email) || empty($cf_message)) {
        $contact_error = 'Please fill in all fields.';
    } else {
        $conn->query("CREATE TABLE IF NOT EXISTS `contact_messages` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `email` VARCHAR(150) NOT NULL, `subject` VARCHAR(200) NOT NULL DEFAULT '', `message` TEXT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, '', ?)");
        $stmt->bind_param("sss", $cf_name, $cf_email, $cf_message);
        $stmt->execute();
        $stmt->close();
        $contact_sent = true;
    }
}

// 3. DATA — best sellers + category showcase (Phase 1 helpers in config.php)
$best_services = get_best_selling_services($conn, 10, 90);
$best_products = get_best_selling_products($conn, 10, 90);

$svc_categories = get_customer_categories_with_counts($conn, 'service');
$prd_categories = get_customer_categories_with_counts($conn, 'product');
$cat_icons = [
    'Nail Care' => '💅', 'Nail Extension' => '💅', 'Hair Services' => '💇',
    'Brows Services' => '👁️', 'Facial' => '🧖', 'Japanese Head Spa' => '🧴',
    'Lashes' => '👁️', 'Massage Service' => '💆', 'Body Treatment' => '🧴',
    'Body Scrub' => '🫧', 'Foot Services' => '🦶', 'Waxing Service' => '🪒',
    'Packages' => '🎁', 'Drip Packages' => '💧', 'Other Services' => '✨',
    'Skincare' => '🧴', 'Bath & Body' => '🧼', 'Lotions & Oils' => '💧', 'oils' => '💧',
];
function showcase_icon($name, $icons) { return $icons[$name] ?? '✨'; }
