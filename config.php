<?php
ob_start();

// ─── LOAD .env FILE ───────────────────────────────────────────────────────────
// Reads key=value pairs from .env in the same directory as this file.
// Skip lines that are blank or start with #.
$_env_file = __DIR__ . '/.env';
if (file_exists($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k); $_v = trim($_v);
        if (!array_key_exists($_k, $_SERVER) && !array_key_exists($_k, $_ENV)) {
            putenv("$_k=$_v");
            $_ENV[$_k] = $_v;
        }
    }
    unset($_env_file, $_line, $_k, $_v);
}

// ─── ENVIRONMENT ──────────────────────────────────────────────────────────────
$_APP_ENV = getenv('APP_ENV') ?: 'production';
define('APP_ENV', $_APP_ENV);
unset($_APP_ENV);

// ─── ERROR HANDLING ───────────────────────────────────────────────────────────
// Never show raw PHP errors to end users. Always log them securely.
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors',     '1');
ini_set('error_log',      __DIR__ . '/logs/app_errors.log');

set_exception_handler(function (Throwable $e) {
    error_log('[EXCEPTION] ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
        . "\nTrace: " . $e->getTraceAsString());
    if (!headers_sent()) http_response_code(500);
    if (APP_ENV === 'development') {
        echo '<pre style="color:red">[DEV] '
            . htmlspecialchars($e->getMessage())
            . "\n" . htmlspecialchars($e->getTraceAsString())
            . '</pre>';
    } else {
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>'
            . '<h2>Something went wrong.</h2>'
            . '<p>Please try again or contact support.</p>'
            . '</body></html>';
    }
    exit();
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    error_log("[PHP ERROR $errno] $errstr in $errfile:$errline");
    return true; // suppress default PHP output
});

// ─── APPLICATION SECRET ───────────────────────────────────────────────────────
// Used for HMAC tokens (e.g. payment_success.php IDOR fix).
// MUST be set in .env. The dev fallback is intentionally useless so it is obvious
// when the secret has not been configured.
$_secret = getenv('APP_SECRET');
if (empty($_secret) || $_secret === 'replace-with-a-long-random-string-min-32-chars') {
    error_log('[CONFIG ERROR] APP_SECRET is not set. Set a 32+ char random string in .env before deploying.');
    die('Server configuration error. Contact administrator.');
}
define('APP_SECRET', $_secret);
unset($_secret);

// ─── DATABASE CREDENTIALS ────────────────────────────────────────────────────
$_db_server = getenv('DB_SERVER');   if (empty($_db_server))   $_db_server   = 'localhost';
$_db_user   = getenv('DB_USERNAME'); if ($_db_user   === false) $_db_user    = 'root';
$_db_pass   = getenv('DB_PASSWORD'); if ($_db_pass   === false) $_db_pass    = '';
$_db_name   = getenv('DB_NAME');     if (empty($_db_name))     $_db_name     = 'spa_ecommerce_db';
define('DB_SERVER',   $_db_server); unset($_db_server);
define('DB_USERNAME', $_db_user);   unset($_db_user);
define('DB_PASSWORD', $_db_pass);   unset($_db_pass);
define('DB_NAME',     $_db_name);   unset($_db_name);

// ─── MAIL ─────────────────────────────────────────────────────────────────────
// All mail credentials come from .env. No secrets are hardcoded here.
// Host/port have safe non-secret defaults; username/password do NOT.
$_mail_host = getenv('MAIL_HOST'); if (empty($_mail_host)) $_mail_host = 'smtp.gmail.com';
define('MAIL_HOST', $_mail_host); unset($_mail_host);

$_mail_user = getenv('MAIL_USERNAME') ?: '';
define('MAIL_USERNAME', $_mail_user);

$_mail_pass = getenv('MAIL_PASSWORD') ?: '';
define('MAIL_PASSWORD', $_mail_pass);

$_mail_port = (int)getenv('MAIL_PORT'); if (empty($_mail_port)) $_mail_port = 587;
define('MAIL_PORT', $_mail_port); unset($_mail_port);

// MAIL_FROM falls back to the SMTP username so you only have to set one value.
$_mail_from = getenv('MAIL_FROM'); if (empty($_mail_from)) $_mail_from = $_mail_user;
define('MAIL_FROM', $_mail_from); unset($_mail_from);

$_mail_name = getenv('MAIL_NAME'); if (empty($_mail_name)) $_mail_name = 'Recovery Spa';
define('MAIL_NAME', $_mail_name); unset($_mail_name);

if (MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
    error_log('[CONFIG WARNING] MAIL_USERNAME / MAIL_PASSWORD are not set in .env. Outgoing email (OTP, receipts) will fail until configured.');
}
unset($_mail_user, $_mail_pass);

// ─── PAYMONGO ─────────────────────────────────────────────────────────────────
// All PayMongo keys come from .env. No keys are hardcoded here.
$_pm_secret = getenv('PAYMONGO_SECRET_KEY') ?: '';
define('PAYMONGO_SECRET_KEY', $_pm_secret);
unset($_pm_secret);

$_pm_public = getenv('PAYMONGO_PUBLIC_KEY') ?: '';
define('PAYMONGO_PUBLIC_KEY', $_pm_public);
unset($_pm_public);

$_pm_webhook = getenv('PAYMONGO_WEBHOOK_SECRET') ?: '';
define('PAYMONGO_WEBHOOK_SECRET', $_pm_webhook);
unset($_pm_webhook);

if (PAYMONGO_SECRET_KEY === '') {
    error_log('[CONFIG WARNING] PAYMONGO_SECRET_KEY is not set in .env. Online payment will fail until configured.');
}

// ─── FEATURE FLAGS ────────────────────────────────────────────────────────────
// GCash/Maya direct integration requires separate PayMongo Business approvals
// (not yet active). QR Ph already covers GCash/Maya/all bank apps via a single
// universal QR code. Set to true once GCash/Maya Business accounts are approved
// and linked.
define('SHOW_GCASH_MAYA', false);

// ── Secret gate code for reaching the admin login page ───────────────────────
define('ADMIN_GATE_CODE', '2024');

// ─── DATABASE CONNECTION ──────────────────────────────────────────────────────
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    // Log the real error; show nothing sensitive to the browser
    error_log('[DB] Connection failed: ' . $conn->connect_error);
    http_response_code(503);
    die('Service temporarily unavailable. Please try again later.');
}

$conn->set_charset('utf8mb4');

// ─── ACTIVITY LOG TABLE (auto-create once if missing) ────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT NULL,
    actor_name  VARCHAR(150) NOT NULL,
    actor_role  VARCHAR(50)  NOT NULL,
    action_type VARCHAR(60)  NOT NULL,
    target_type VARCHAR(60)  NULL,
    target_id   INT          NULL,
    description VARCHAR(500) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_actor   (actor_id),
    INDEX idx_action  (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── BASE URL ─────────────────────────────────────────────────────────────────
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$folder   = getenv('APP_SUBFOLDER') ?: '/spa_ecommerce_system/';
define('BASE_URL', $protocol . '://' . $host . $folder);

// ─── UPLOAD DIRECTORIES ───────────────────────────────────────────────────────
define('UPLOAD_DIR_SERVICES', __DIR__ . '/uploads/services/');
define('UPLOAD_DIR_PRODUCTS', __DIR__ . '/uploads/products/');

// ─── SESSION ──────────────────────────────────────────────────────────────────
// SameSite=None + Secure keeps the cookie alive after PayMongo cross-site redirect.
// On non-HTTPS (local dev), SameSite falls back to Lax so the cookie is not dropped.
if (session_status() === PHP_SESSION_NONE) {
    $is_prod = (APP_ENV === 'production');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_prod,
        'httponly' => true,
        'samesite' => $is_prod ? 'None' : 'Lax',
    ]);
    session_start();
    unset($is_prod);
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

/**
 * Return (and lazily create) the CSRF token for this session.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Echo a ready-to-use hidden CSRF input. Call this inside every <form>.
 *   <?php echo csrf_field(); ?>
 */
function csrf_field(): string {
    $t = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Abort with 403 if the submitted CSRF token does not match the session token.
 * Call at the top of every state-changing POST handler.
 */
function verify_csrf_token(): void {
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        error_log('[CSRF] Token mismatch for ' . ($_SERVER['REQUEST_URI'] ?? ''));
        die('Invalid request. Please go back and try again.');
    }
}

function verify_csrf_token_ajax(): bool {
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';
    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ─── SANITIZE ─────────────────────────────────────────────────────────────────
function sanitize_input(string $data): string {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

// ─── AUTH HELPERS ─────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_owner(): bool {
    return is_admin() && ($_SESSION['admin_role'] ?? '') === 'owner';
}

function is_cashier(): bool {
    return is_admin() && ($_SESSION['admin_role'] ?? '') === 'cashier';
}

// Full access = owner, staff, IT, marketing — anyone who is NOT a cashier
function is_full_access(): bool {
    return is_admin() && !is_cashier();
}

/**
 * Return the current admin sub-role (owner|it|marketing|cashier).
 * Defaults to 'owner' for legacy admin accounts with no admin_role set.
 */
function current_admin_role(): string {
    return $_SESSION['admin_role'] ?? 'owner';
}

function redirect_if_not_owner(): void {
    if (!is_logged_in() || !is_full_access()) {
        header('Location: ' . BASE_URL . 'admin/appointments.php?access_denied=1');
        exit();
    }
}

function redirect_if_not_admin(): void {
    if (!is_logged_in() || !is_admin()) {
        header('Location: ' . BASE_URL . 'admin/admin_login.php');
        exit();
    }
}

function redirect_if_not_user(): void {
    if (!is_logged_in() || is_admin()) {
        header('Location: ' . BASE_URL . 'user/auth.php');
        exit();
    }
}

function redirect_if_logged_in(): void {
    if (is_logged_in()) {
        header('Location: ' . BASE_URL . (is_admin() ? 'admin/index.php' : 'user/index.php'));
        exit();
    }
}

function logout($conn = null): void {
    if ($conn && isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    $is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . ($is_admin ? 'admin/admin_login.php' : 'user/auth.php'));
    exit();
}

// ─── CART HELPERS ─────────────────────────────────────────────────────────────

/**
 * Sync the entire session cart to the database (delete-then-reinsert).
 * Uses a transaction and a single reusable prepared statement to avoid N×1 round-trips.
 */
function sync_cart_to_db($conn, int $user_id, array $cart): void {
    if (!$user_id) return;
    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();

        if (!empty($cart)) {
            $ins = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($cart as $id => $item) {
                $qty = intval($item['quantity']);
                $ins->bind_param("iii", $user_id, $id, $qty);
                $ins->execute();
            }
            $ins->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[CART] sync_cart_to_db failed: ' . $e->getMessage());
    }
}

/**
 * Remove a single item from the DB cart.
 */
function remove_cart_item_from_db($conn, int $user_id, int $product_id): void {
    if (!$user_id) return;
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear the entire cart from the database.
 */
function clear_cart_from_db($conn, int $user_id): void {
    if (!$user_id) return;
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Load cart from database into an array.  Called after login.
 */
function load_cart_from_db($conn, int $user_id): array {
    if (!$user_id) return [];
    $stmt = $conn->prepare("
        SELECT c.product_id, c.quantity, p.name, p.price, p.image
        FROM   cart c
        JOIN   products p ON c.product_id = p.id
        WHERE  c.user_id = ?
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
            'quantity' => $row['quantity'],
        ];
    }
    return $cart;
}

/**
 * Save session cart to database. Called before logout.
 * Uses a transaction and a single reusable prepared statement.
 */
function save_cart_to_db($conn, int $user_id, array $cart): void {
    if (!$user_id || empty($cart)) return;

    // Verify the user actually exists before writing
    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $user_id);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$exists) {
        // Ghost session — clear it so the loop doesn't repeat
        session_unset();
        session_destroy();
        return;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO cart (user_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        foreach ($cart as $product_id => $item) {
            $qty = intval($item['quantity']);
            $stmt->bind_param("iii", $user_id, $product_id, $qty);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[CART] save_cart_to_db failed: ' . $e->getMessage());
    }
}