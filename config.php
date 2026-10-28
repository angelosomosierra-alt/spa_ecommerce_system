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

// ─── TIMEZONE ─────────────────────────────────────────────────────────────────
// Applies to every role/page so PHP's date()/time() always agree on "today".
// Role-gated date_default_timezone_set() calls elsewhere (admin_access.php,
// admin_login.php) reapply the same or an admin-configured zone on top of this
// for the cashier login-hour restriction feature — harmless.
date_default_timezone_set('Asia/Manila');

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
// config.local.php (gitignored, never committed) must define:
//   $_db_server, $_db_user, $_db_pass, $_db_name
// Use it on hosts where getenv() cannot be set (e.g. Hostinger shared PHP).
// Falls back to .env via getenv() on local XAMPP and similar environments.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    $_db_server = getenv('DB_SERVER');   if (empty($_db_server))   $_db_server = 'localhost';
    $_db_user   = getenv('DB_USERNAME'); if ($_db_user   === false) $_db_user   = 'root';
    $_db_pass   = getenv('DB_PASSWORD'); if ($_db_pass   === false) $_db_pass   = '';
    $_db_name   = getenv('DB_NAME');     if (empty($_db_name))      $_db_name   = 'spa_ecommerce_db';
}
define('DB_SERVER',   $_db_server); unset($_db_server);
define('DB_USERNAME', $_db_user);   unset($_db_user);
define('DB_PASSWORD', $_db_pass);   unset($_db_pass);
define('DB_NAME',     $_db_name);   unset($_db_name);

// ─── MAIL ─────────────────────────────────────────────────────────────────────
// Credentials come from config.local.php (preferred on Hostinger) or .env.
// config.local.php may define $_mail_user and $_mail_pass — if set, they win.
// Host/port have safe non-secret defaults; username/password do NOT.
$_mail_host = getenv('MAIL_HOST'); if (empty($_mail_host)) $_mail_host = 'smtp.gmail.com';
define('MAIL_HOST', $_mail_host); unset($_mail_host);

$_mail_user = $_mail_user ?? (getenv('MAIL_USERNAME') ?: '');
define('MAIL_USERNAME', $_mail_user);

$_mail_pass = $_mail_pass ?? (getenv('MAIL_PASSWORD') ?: '');
define('MAIL_PASSWORD', $_mail_pass);

$_mail_port = (int)getenv('MAIL_PORT'); if (empty($_mail_port)) $_mail_port = 587;
define('MAIL_PORT', $_mail_port); unset($_mail_port);

// MAIL_FROM falls back to the SMTP username so you only have to set one value.
$_mail_from = getenv('MAIL_FROM'); if (empty($_mail_from)) $_mail_from = $_mail_user;
define('MAIL_FROM', $_mail_from); unset($_mail_from);

$_mail_name = getenv('MAIL_NAME'); if (empty($_mail_name)) $_mail_name = 'Recovery Spa';
define('MAIL_NAME', $_mail_name); unset($_mail_name);

if (MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
    error_log('[CONFIG WARNING] MAIL_USERNAME / MAIL_PASSWORD are not set. Outgoing email (OTP, receipts) will fail until configured.');
}
unset($_mail_user, $_mail_pass);

// ─── PAYMONGO ─────────────────────────────────────────────────────────────────
// Keys come from config.local.php (preferred on Hostinger) or .env.
// config.local.php may define $_pm_secret, $_pm_public, $_pm_webhook — if set, they win.
$_pm_secret  = $_pm_secret  ?? (getenv('PAYMONGO_SECRET_KEY')      ?: '');
define('PAYMONGO_SECRET_KEY', $_pm_secret);
unset($_pm_secret);

$_pm_public  = $_pm_public  ?? (getenv('PAYMONGO_PUBLIC_KEY')       ?: '');
define('PAYMONGO_PUBLIC_KEY', $_pm_public);
unset($_pm_public);

$_pm_webhook = $_pm_webhook ?? (getenv('PAYMONGO_WEBHOOK_SECRET')   ?: '');
define('PAYMONGO_WEBHOOK_SECRET', $_pm_webhook);
unset($_pm_webhook);

if (PAYMONGO_SECRET_KEY === '') {
    error_log('[CONFIG WARNING] PAYMONGO_SECRET_KEY is not set. Online payment will fail until configured.');
}

// ─── FEATURE FLAGS ────────────────────────────────────────────────────────────
// Master switch: set to true once PayMongo account is verified for live payments.
// When false: all online payment options (GCash, Maya, Card, QR Ph) are hidden
// from the UI and rejected server-side. Cash/Onsite remains available.
// Turn it on from config.local.php ($_online_payment = true;) or .env
// (ONLINE_PAYMENT_ENABLED=true) once the PayMongo keys are filled in.
// It stays off when there is no secret key, so checkout can't break.
$_online_payment = $_online_payment ?? filter_var(getenv('ONLINE_PAYMENT_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
define('ONLINE_PAYMENT_ENABLED', (bool)$_online_payment && PAYMONGO_SECRET_KEY !== '');
unset($_online_payment);

// GCash/Maya direct integration requires separate PayMongo Business approvals
// (not yet active). QR Ph already covers GCash/Maya/all bank apps via a single
// universal QR code. Set to true once GCash/Maya Business accounts are approved
// and linked. Override with $_show_gcash_maya = true; in config.local.php.
$_show_gcash_maya = $_show_gcash_maya ?? filter_var(getenv('SHOW_GCASH_MAYA') ?: 'false', FILTER_VALIDATE_BOOLEAN);
define('SHOW_GCASH_MAYA', (bool)$_show_gcash_maya);
unset($_show_gcash_maya);

// Online service bookings are paid as a non-refundable down payment of this
// share of the total (the rest is paid at the spa). Override with
// $_online_dp_percent in config.local.php or ONLINE_DP_PERCENT in .env.
$_online_dp_percent = $_online_dp_percent ?? (getenv('ONLINE_DP_PERCENT') !== false ? (float)getenv('ONLINE_DP_PERCENT') : 50.0);
define('ONLINE_DP_PERCENT', max(1.0, min(100.0, (float)$_online_dp_percent)));
unset($_online_dp_percent);

// Automatic per-appointment stock deduction (service_supply_usage "recipes",
// admin/appointments.php completion handler). Off because exact per-appointment
// usage formulas aren't known yet — inventory tracking relies on Deliveries
// (stock in) + Physical Count (variance vs. actual) instead, so guessed recipes
// don't silently corrupt that baseline. Flip to true once real recipes (set in
// admin/services.php) are confirmed accurate — no other code changes needed.
define('AUTO_SUPPLY_DEDUCTION_ENABLED', false);

// ── Secret gate code for reaching the admin login page ───────────────────────
define('ADMIN_GATE_CODE', '2024');

// ─── DATABASE CONNECTION ──────────────────────────────────────────────────────
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->query("SET time_zone = '+08:00'");

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

// ─── PAYMENT LOG (PayMongo trail, auto-create once if missing) ──────────────
// One row per step of an online payment: checkout session created, customer
// came back, PayMongo confirmed, webhook received/rejected. Viewed in
// admin/payment_log.php. Never stores keys or card details.
$conn->query("CREATE TABLE IF NOT EXISTS payment_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT          NULL,
    source      VARCHAR(30)  NOT NULL,
    step        VARCHAR(40)  NOT NULL,
    result      VARCHAR(10)  NOT NULL,
    amount      DECIMAL(10,2) NULL,
    method      VARCHAR(30)  NULL,
    reference   VARCHAR(100) NULL,
    session_id  VARCHAR(100) NULL,
    detail      VARCHAR(500) NULL,
    livemode    TINYINT(1)   NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order   (order_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * Record one PayMongo step. $result is 'ok', 'info' or 'error'.
 * $extra keys: amount, method, reference, session_id, livemode.
 * Call it after a rollback (not inside a transaction that may roll back),
 * or the row is lost with it. Never throws.
 */
function paymongo_log($conn, $order_id, string $source, string $step, string $result,
                      string $detail = '', array $extra = []): void {
    try {
        $oid    = $order_id ? (int)$order_id : null;
        $amount = isset($extra['amount']) ? (float)$extra['amount'] : null;
        $method = $extra['method']     ?? null;
        $ref    = $extra['reference']  ?? null;
        $sess   = $extra['session_id'] ?? null;
        $live   = isset($extra['livemode']) ? (int)(bool)$extra['livemode']
                : (str_starts_with(PAYMONGO_SECRET_KEY, 'sk_live_') ? 1 : 0);
        $detail = mb_substr($detail, 0, 500);
        $stmt = $conn->prepare("INSERT INTO payment_logs
            (order_id, source, step, result, amount, method, reference, session_id, detail, livemode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssdssssi", $oid, $source, $step, $result, $amount,
                          $method, $ref, $sess, $detail, $live);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[PAYMENT LOG] ' . $e->getMessage());
    }
}

// ─── AESTHETIC SLIP ──────────────────────────────────────────────────────────
// The spa writes aesthetic treatments on their own paper slip series (the
// aesthetics menu: Diode, Exilis RF, HIFU, Pico, Carbon Glow, boosters / Botox
// / fillers, drips and the aesthetic combos). Facials use the spa slip.
function service_uses_aesthetic_slip(?string $category_name, ?string $service_name = ''): bool {
    $cat = strtolower((string)$category_name);
    if (preg_match('/diode|exilis|hifu|pico|carbon|drip/', $cat)) return true;
    if (in_array(trim($cat), ['aesthetics', 'aesthetic combos'], true)) return true;
    return (bool)preg_match('/full face tightening|exilis/i', (string)$service_name);
}

// ─── PERSON DISCOUNTS ────────────────────────────────────────────────────────
// Senior 20%, PWD 20%, Staff and Voucher are for one person: in a booking for
// several people only the ones who qualify get it. Senior, PWD and Staff are
// never on the services below (spa rule): every Daytime promo, nail services
// (Nail Care, Nail Care - Promo, Design) and lash services. A voucher is for
// the services of its kind (VOUCHER_SCOPES); a Gift card / GC is for all.
// Celebration stays on the whole bill.
const PERSON_DISCOUNT_TYPES = ['senior', 'pwd', 'employee', 'voucher'];
const VOUCHER_SCOPES = [
    'all'     => 'Gift card / GC',
    'nails'   => 'Nails voucher',
    'lash'    => 'Lash voucher',
    'headspa' => 'Head spa voucher',
    'massage' => 'Body massage voucher',
];
function service_discount_excluded(?string $category_name, ?string $service_name = ''): bool {
    $cat = strtolower(trim((string)$category_name));
    if (stripos((string)$service_name, 'daytime') !== false) return true;
    return str_contains($cat, 'nail') || str_contains($cat, 'lash') || $cat === 'design';
}
function service_in_voucher_scope(?string $category_name, ?string $service_name, string $scope): bool {
    $cat = strtolower(trim((string)$category_name)); $name = strtolower((string)$service_name);
    switch ($scope) {
        case 'nails':   return str_contains($cat, 'nail') || $cat === 'design';
        case 'lash':    return str_contains($cat, 'lash');
        case 'headspa': return str_contains($cat, 'head spa') || (bool)preg_match('/head ?spa/', $name);
        case 'massage': return $cat === 'massage services' || (bool)preg_match('/body massage|daytime massage/', $name);
    }
    return true; // 'all': gift card / GC
}
// Can this discount apply to this service? $scope is the voucher's kind.
function service_takes_discount(?string $category_name, ?string $service_name, string $type, string $scope = 'all'): bool {
    if ($type === 'voucher') return service_in_voucher_scope($category_name, $service_name, $scope);
    if (in_array($type, ['senior', 'pwd', 'employee'], true)) return !service_discount_excluded($category_name, $service_name);
    return true;
}
// What a discount can apply to on one appointment: its order total plus its
// add-ons (unpaid ones only, or all), leaving out services it isn't for; for
// a booking of several people, only $people of them (0 = all of them).
function appt_discount_eligible(mysqli $conn, int $appt_id, float $order_total, bool $unpaid_extras_only = true, int $people = 0, string $type = 'senior', string $scope = 'all'): float {
    $q = $conn->prepare("SELECT a.people_count, s.name, c.name AS cat FROM appointments a JOIN services s ON s.id = a.service_id LEFT JOIN categories c ON c.id = s.category_id WHERE a.id = ?");
    $q->bind_param("i", $appt_id); $q->execute();
    $a = $q->get_result()->fetch_assoc(); $q->close();
    if (!$a) return $order_total;
    if (!in_array($type, PERSON_DISCOUNT_TYPES, true)) return $order_total; // whole bill
    $elig = service_takes_discount($a['cat'], $a['name'], $type, $scope) ? $order_total : 0.0;
    $q = $conn->prepare("SELECT aes.charged_price, aes.payment_status, s.name, c.name AS cat FROM appointment_extra_services aes JOIN services s ON s.id = aes.service_id LEFT JOIN categories c ON c.id = s.category_id WHERE aes.appointment_id = ?");
    $q->bind_param("i", $appt_id); $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
        if ($unpaid_extras_only && $e['payment_status'] === 'paid') continue;
        if (service_takes_discount($e['cat'], $e['name'], $type, $scope)) $elig += (float)$e['charged_price'];
    }
    $q->close();
    $n = max(1, (int)$a['people_count']);
    if ($people > 0 && $people < $n) $elig = $elig * $people / $n;
    return round($elig, 2);
}
function voucher_scope_from_post(string $key = 'voucher_scope'): string {
    $s = (string)($_POST[$key] ?? 'all');
    return array_key_exists($s, VOUCHER_SCOPES) ? $s : 'all';
}
// The amount of a discount on a bill: person discounts on the eligible part,
// Celebration on the whole bill.
function person_discount_amount(string $type, float $base, float $eligible, float $value = 0, string $vtype = 'percent'): float {
    $eligible = min($eligible, $base);
    switch ($type) {
        case 'senior': case 'pwd': return round($eligible * 0.20, 2);
        case 'employee':           return round($eligible * STAFF_DISCOUNT_PCT / 100, 2);
        case 'voucher':            return $value <= 0 ? 0.0 : ($vtype === 'percent' ? round($eligible * min($value, 100) / 100, 2) : round(min($value, $eligible), 2));
        case 'celebration':        return $value <= 0 ? 0.0 : round($base * min($value, 100) / 100, 2);
    }
    return 0.0;
}

// ─── ONLINE DOWN PAYMENT ─────────────────────────────────────────────────────
// orders.dp_amount is set when a customer books a service online: PayMongo
// then charges only that down payment, and the rest is paid at the spa.
function ensure_online_dp_column($conn): void {
    static $done = false;
    if ($done) return;
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS dp_amount DECIMAL(10,2) NULL DEFAULT NULL");
    $done = true;
}

// Called once PayMongo says a down-payment order is paid (from the success
// page or the webhook, whichever comes first). The order goes back to
// 'unpaid' (the balance is still due) and the down payment is saved on its
// appointments exactly like a down payment typed in by staff, so it shows in
// that day's Daily Report and is taken off the bill at completion.
// Returns 1 if this call settled it, 0 if it was already settled / not a DP order.
function settle_online_down_payment($conn, int $order_id, ?string $method, ?string $reference): int {
    ensure_online_dp_column($conn);
    $q = $conn->prepare("SELECT dp_amount FROM orders WHERE id = ? AND payment_status = 'pending_payment' AND dp_amount > 0");
    $q->bind_param("i", $order_id); $q->execute();
    $row = $q->get_result()->fetch_assoc(); $q->close();
    if (!$row) return 0;
    $dp = round((float)$row['dp_amount'], 2);

    $upd = $conn->prepare("
        UPDATE orders
        SET payment_status     = 'unpaid',
            paymongo_method    = COALESCE(paymongo_method, ?),
            paymongo_reference = COALESCE(paymongo_reference, ?)
        WHERE id = ? AND payment_status = 'pending_payment'");
    $upd->bind_param("ssi", $method, $reference, $order_id); $upd->execute();
    $changed = $upd->affected_rows; $upd->close();
    if ($changed < 1) return 0;

    // Same method names the Daily Report's DP lines use.
    $adv_method = in_array($method, ['gcash', 'maya', 'card', 'qrph'], true) ? $method : 'card';
    $ap = $conn->prepare("
        SELECT a.id, a.charged_price FROM appointments a
        JOIN order_items oi ON oi.id = a.order_item_id
        WHERE oi.order_id = ? ORDER BY a.id");
    $ap->bind_param("i", $order_id); $ap->execute();
    $appts = $ap->get_result()->fetch_all(MYSQLI_ASSOC); $ap->close();
    if (!$appts) return 1;
    // Split across the order's appointments by price; the last one takes
    // the rounding remainder so the parts add up to the amount paid.
    $sum = array_sum(array_map(fn($a) => (float)$a['charged_price'], $appts));
    $left = $dp;
    $set = $conn->prepare("UPDATE appointments SET advance_payment = ?, advance_payment_date = CURDATE(), advance_payment_method = ? WHERE id = ?");
    foreach ($appts as $i => $a) {
        $part = ($i === count($appts) - 1) ? $left
              : round($sum > 0 ? $dp * (float)$a['charged_price'] / $sum : $dp / count($appts), 2);
        $left = round($left - $part, 2);
        $aid = (int)$a['id'];
        $set->bind_param("dsi", $part, $adv_method, $aid); $set->execute();
    }
    $set->close();
    return 1;
}

// ─── GCASH QR PAYMENTS (manual) ──────────────────────────────────────────────
// While PayMongo is off, an online service booking can be paid to the spa's
// GCash QR: the customer sends the 50% down payment or the full amount, types
// the GCash reference number and uploads the receipt; the receptionist checks
// it in Appointments and verifies it (recorded as a GCash advance payment,
// like a Calendar down payment) or rejects it with a reason.
// Settings (Admin → Payment Log): gcash_qr_enabled, gcash_name, gcash_number,
// gcash_qr_file — stored in system_settings.
function gcash_settings($conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value VARCHAR(255) NOT NULL, updated_by INT NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cache = ['gcash_qr_enabled' => '0', 'gcash_name' => '', 'gcash_number' => '', 'gcash_qr_file' => ''];
    $r = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('gcash_qr_enabled','gcash_name','gcash_number','gcash_qr_file')");
    if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) $cache[$row['setting_key']] = $row['setting_value'];
    return $cache;
}
// On, and set up enough for a customer to pay (number and QR image).
function gcash_qr_ready($conn): bool {
    $g = gcash_settings($conn);
    return $g['gcash_qr_enabled'] === '1' && $g['gcash_number'] !== '' && $g['gcash_qr_file'] !== '';
}
function save_gcash_setting($conn, string $key, string $value, int $by): void {
    gcash_settings($conn);
    $s = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");
    $s->bind_param("ssi", $key, $value, $by); $s->execute(); $s->close();
}
function ensure_manual_payments_table($conn): void {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS manual_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        user_id INT NULL,
        amount DECIMAL(10,2) NOT NULL,
        kind ENUM('dp','full') NOT NULL DEFAULT 'dp',
        reference_no VARCHAR(40) NOT NULL,
        receipt_file VARCHAR(120) NOT NULL,
        status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
        amount_received DECIMAL(10,2) NULL,
        reject_reason VARCHAR(255) NULL,
        checked_by_name VARCHAR(100) NULL,
        checked_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mp_order (order_id), INDEX idx_mp_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}
// Receipts and the QR live outside the deploy path on Hostinger (a deploy
// would delete them) and are only served through admin/payment_file.php.
function manual_payment_dir(): string {
    $dir = PERSISTENT_UPLOADS_ENABLED ? '/home/u655952816/persistent_uploads/payments/' : __DIR__ . '/uploads/payments/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!PERSISTENT_UPLOADS_ENABLED && !is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}
// Saves an uploaded image (JPG/PNG/WEBP, up to 8 MB). Returns the stored file
// name, or an error message starting with '!'.
function store_payment_image(array $file, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '!Please add the screenshot of your receipt.';
    if ($file['size'] > 8 * 1024 * 1024) return '!The image is too big (8 MB at most).';
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) return '!The receipt must be a JPG, PNG or WEBP image.';
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], manual_payment_dir() . $name)) return '!Could not save the image. Please try again.';
    return $name;
}
// Splits an amount paid in advance over an order's appointments by price
// (the last one takes the rounding), as each appointment's advance payment.
function record_order_advance($conn, int $order_id, float $amount, string $method): void {
    $ap = $conn->prepare("SELECT a.id, a.charged_price FROM appointments a JOIN order_items oi ON oi.id = a.order_item_id WHERE oi.order_id = ? ORDER BY a.id");
    $ap->bind_param("i", $order_id); $ap->execute();
    $appts = $ap->get_result()->fetch_all(MYSQLI_ASSOC); $ap->close();
    if (!$appts) return;
    $sum = array_sum(array_map(fn($a) => (float)$a['charged_price'], $appts));
    $left = round($amount, 2);
    $set = $conn->prepare("UPDATE appointments SET advance_payment = ?, advance_payment_date = CURDATE(), advance_payment_method = ? WHERE id = ?");
    foreach ($appts as $i => $a) {
        $part = ($i === count($appts) - 1) ? $left
              : round($sum > 0 ? $amount * (float)$a['charged_price'] / $sum : $amount / count($appts), 2);
        $left = round($left - $part, 2);
        $aid = (int)$a['id'];
        $set->bind_param("dsi", $part, $method, $aid); $set->execute();
    }
    $set->close();
}

// ─── BASE URL ─────────────────────────────────────────────────────────────────
$protocol      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host          = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Hostinger: the temporary *.hostingersite.com address, or any domain pointed at
// this hosting account (e.g. recoveryiloilo.com) -- the app then lives in the
// account's home folder and is served from the site root.
$_is_hostinger = strpos($host, 'hostingersite.com') !== false || strpos(__DIR__, '/home/u655952816/') === 0;
$folder        = $_is_hostinger ? '/' : (getenv('APP_SUBFOLDER') ?: '/spa_ecommerce_system/');
define('BASE_URL', $protocol . '://' . $host . $folder);
// The site's public address (search engines, links people share). The
// temporary *.hostingersite.com address sends visitors here (header.php).
if (!defined('SITE_URL')) define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'https://recoveryiloilo.com', '/'));

// ─── UPLOAD DIRECTORIES ───────────────────────────────────────────────────────
define('UPLOAD_DIR_SERVICES', __DIR__ . '/uploads/services/');
define('UPLOAD_DIR_PRODUCTS', __DIR__ . '/uploads/products/');

// ─── PERSISTENT UPLOAD STORAGE (Hostinger) ────────────────────────────────────
// Hostinger's git deploy wipes any file in uploads/services or uploads/products
// that isn't tracked in git, on every single push -- it used to silently delete
// every photo uploaded directly on the live site this way. A copy of each upload
// is also kept in ~/persistent_uploads/, outside the deploy path, so a deploy can
// never reach it. restore_persistent_uploads() below copies anything missing
// back into the live folder on the first request after a deploy.
// (An earlier attempt used PHP's symlink() for this; that function is disabled
// on this hosting plan and took the whole site down, so this copies files
// instead -- copy()/scandir()/touch() are never in a host's disable_functions list.)
define('PERSISTENT_DIR_SERVICES', '/home/u655952816/persistent_uploads/services/');
define('PERSISTENT_DIR_PRODUCTS', '/home/u655952816/persistent_uploads/products/');
define('PERSISTENT_UPLOADS_ENABLED', $_is_hostinger);
unset($_is_hostinger);

function mirror_upload_to_persistent(string $uploadDir, string $persistentDir, string $filename): void {
    if (!PERSISTENT_UPLOADS_ENABLED) {
        return;
    }
    if (!is_dir($persistentDir)) {
        @mkdir($persistentDir, 0755, true);
    }
    @copy($uploadDir . $filename, $persistentDir . $filename);
}

function remove_persistent_upload(string $persistentDir, string $filename): void {
    if (!PERSISTENT_UPLOADS_ENABLED) {
        return;
    }
    $path = $persistentDir . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function restore_persistent_uploads(string $uploadDir, string $persistentDir): void {
    if (!PERSISTENT_UPLOADS_ENABLED) {
        return;
    }
    $sentinel = $uploadDir . '.restored';
    if (file_exists($sentinel)) {
        return;
    }
    if (is_dir($persistentDir)) {
        $files = @scandir($persistentDir);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $src = $persistentDir . $f;
                $dst = $uploadDir . $f;
                if (is_file($src) && !file_exists($dst)) {
                    @copy($src, $dst);
                }
            }
        }
    }
    @touch($sentinel);
}

restore_persistent_uploads(UPLOAD_DIR_SERVICES, PERSISTENT_DIR_SERVICES);
restore_persistent_uploads(UPLOAD_DIR_PRODUCTS, PERSISTENT_DIR_PRODUCTS);

// ─── SESSION ──────────────────────────────────────────────────────────────────
// SameSite=None + Secure keeps the cookie alive after PayMongo cross-site redirect.
// On non-HTTPS (local dev / HTTP), SameSite falls back to Lax so the cookie is not dropped.
// Detected from the actual connection, not APP_ENV, so localhost always works regardless
// of the environment setting.
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => $is_https ? 'None' : 'Lax',
    ]);
    session_start();
    unset($is_https);
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
    return trim(stripslashes($data));
}

// ─── ACTIVE PRICE RESOLUTION ────────────────────────────────────────────────
// Core "what's active right now" resolver, shared by every price-with-
// scheduled-promo feature (Duration Options' service_durations rows, and
// classic single-price services' own promo columns). Evaluated fresh on
// every call — no cron job, no stored "is promo active" flag. Reads the
// current time via the timezone already set globally above
// (date_default_timezone_set('Asia/Manila')).
function _resolve_active_price(float $regular, float $promo, string $mode, ?string $promoStart, ?string $promoEnd): array {
    if ($mode !== 'promo' || $promo <= 0) {
        return ['price' => $regular, 'is_promo_active' => false];
    }
    // Promo with no time window: it applies all day.
    if (empty($promoStart) || empty($promoEnd)) {
        return ['price' => $promo, 'is_promo_active' => true];
    }

    $now   = date('H:i:s');
    $start = date('H:i:s', strtotime($promoStart));
    $end   = date('H:i:s', strtotime($promoEnd));

    if ($start <= $end) {
        // Normal same-day window, e.g. 10:00–16:00
        $active = ($now >= $start && $now < $end);
    } else {
        // End earlier than start — overnight window spanning midnight,
        // e.g. 22:00–02:00. Active from start through midnight, then from
        // midnight up to (not including) end.
        $active = ($now >= $start || $now < $end);
    }

    return $active
        ? ['price' => $promo,   'is_promo_active' => true]
        : ['price' => $regular, 'is_promo_active' => false];
}

// ─── DURATION VARIANT PRICING ─────────────────────────────────────────────────
function get_active_duration_price(array $durationRow): array {
    return _resolve_active_price(
        (float)($durationRow['regular_price'] ?? 0),
        (float)($durationRow['promo_price']   ?? 0),
        $durationRow['price_mode'] ?? 'regular',
        $durationRow['promo_start_time'] ?? null,
        $durationRow['promo_end_time']   ?? null
    );
}

// ─── THERAPIST COMMISSION EVENTS VIEW ───────────────────────────────────────
// Self-heals appointment_sessions + v_therapist_commission_events (see
// database/migrations/2026_09_27_create_therapist_commission_events_view.sql).
function ensure_commission_events_view($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS appointment_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        session_number INT NOT NULL,
        session_date DATETIME NULL,
        therapist_id INT NULL,
        duration_minutes INT NOT NULL,
        status ENUM('not_scheduled','scheduled','checked_in','completed') NOT NULL DEFAULT 'not_scheduled',
        commission DECIMAL(10,2) NULL,
        checked_in_at DATETIME NULL,
        completed_at DATETIME NULL,
        completed_by INT NULL,
        completed_by_name VARCHAR(120) NULL,
        UNIQUE KEY uq_appt_session (appointment_id, session_number),
        CONSTRAINT fk_appt_sessions_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
        CONSTRAINT fk_appt_sessions_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE OR REPLACE VIEW v_therapist_commission_events AS
        SELECT at2.id AS event_id, at2.therapist_id AS therapist_id, at2.commission AS commission,
               DATE(ap.appointment_date) AS event_date, ap.id AS appointment_id, 'regular' AS source
        FROM appointment_therapists at2 JOIN appointments ap ON at2.appointment_id = ap.id
        WHERE ap.status = 'completed'
          AND NOT EXISTS (SELECT 1 FROM appointment_sessions aps2 WHERE aps2.appointment_id = ap.id)
        UNION ALL
        SELECT aps.id, aps.therapist_id, aps.commission,
               DATE(COALESCE(aps.completed_at, aps.session_date)), aps.appointment_id, 'package_session'
        FROM appointment_sessions aps
        WHERE aps.status = 'completed' AND aps.commission IS NOT NULL AND aps.therapist_id IS NOT NULL");
}

// ─── SLOTTING & ROTATION: PHYSICAL RESOURCES ─────────────────────────────────
// Occupying statuses: an appointment holds its resource from the moment it's
// booked (pending — a walk-in may optionally pick a resource before a
// therapist is even assigned) through being staffed (assigned) and checked in
// (approved — this codebase's "checked in" state; see admin/appointments.php's
// checkin_appointment action). completed/declined/cancelled free it up.
// Scoped to the parent appointment's own appointment_date/duration_minutes —
// a session_count>1 package's later sessions (their own dates, tracked in
// appointment_sessions) are not individually resource-tracked in this pass.
const RESOURCE_OCCUPYING_STATUSES = ['pending', 'assigned', 'approved'];

/**
 * Is $resource_id free for the given [start, start+duration) window?
 * Mirrors the interval-overlap pattern already used for therapist conflict
 * checks (admin/walkin.php, admin/appointments.php): an existing occupant
 * conflicts when its start is before the new window's end AND its own end
 * is after the new window's start. duration_minutes falls back to the
 * service's session_time when NULL — online (checkout.php) bookings never
 * set appointments.duration_minutes directly, only walk-in bookings do.
 */
function is_resource_available(int $resource_id, string $start_datetime, int $duration_minutes, ?int $exclude_appointment_id = null): bool {
    global $conn;
    if ($resource_id <= 0 || $duration_minutes <= 0) return false;

    $statuses = "'" . implode("','", RESOURCE_OCCUPYING_STATUSES) . "'";
    $sql = "
        SELECT a.id
        FROM appointments a
        LEFT JOIN services s ON s.id = a.service_id
        WHERE a.resource_id = ?
          AND a.status IN ($statuses)
          AND a.appointment_date < DATE_ADD(?, INTERVAL ? MINUTE)
          AND DATE_ADD(a.appointment_date, INTERVAL COALESCE(a.duration_minutes, s.session_time, 60) MINUTE) > ?
    ";
    $types  = "isis";
    $params = [$resource_id, $start_datetime, $duration_minutes, $start_datetime];
    if ($exclude_appointment_id) {
        $sql   .= " AND a.id != ?";
        $types .= "i";
        $params[] = $exclude_appointment_id;
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $conflict === null;
}

/**
 * Active resources of $type with no conflicting occupant in the given window.
 * Returns full service_resources rows, ordered by sort_order.
 */
function get_available_resources(string $type, string $start_datetime, int $duration_minutes): array {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM service_resources WHERE type = ? AND is_active = 1 ORDER BY sort_order");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_filter(
        $all,
        fn($r) => is_resource_available((int)$r['id'], $start_datetime, $duration_minutes)
    ));
}

/**
 * Suggest which resource type a service's category most likely needs.
 * Checked against this database's real category names (no literal "Facial"
 * or "Aesthetic" category exists here) — mapped by keyword, defaulting to
 * 'room' for anything unmatched (Massage Service, Body Scrub, Waxing
 * Service, Other Services, Packages, and uncategorized services). This is a
 * suggestion only; the picker (Phase 4/5) always lets staff pick any type.
 */
function suggest_resource_type_for_service(int $service_id): string {
    global $conn;
    $stmt = $conn->prepare("SELECT c.name FROM services s LEFT JOIN categories c ON c.id = s.category_id WHERE s.id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $cat = strtolower($stmt->get_result()->fetch_assoc()['name'] ?? '');
    $stmt->close();

    if (str_contains($cat, 'head spa')) return 'head_spa';
    if (str_contains($cat, 'nail') || str_contains($cat, 'lash')
        || str_contains($cat, 'brow') || str_contains($cat, 'foot')) return 'chair';
    return 'room';
}

/**
 * Commission is always calculated off a service's promo price whenever one
 * is defined for it (service_durations.promo_price > 0) -- regardless of
 * whether that promo is currently scheduled/active, and regardless of what
 * the customer was actually charged. Services with no promo price set keep
 * computing commission on $fallback_price exactly as before (today's active/
 * regular price), so this only changes behavior for services that actually
 * have a promo defined.
 *
 * $service_duration_id: the specific duration/session-count row actually
 * booked, when known (appointments.service_duration_id). When null (booking
 * paths that don't track a specific duration selection), falls back to the
 * service's first/default service_durations row, matching the same
 * fallback convention used at booking time.
 */
function get_commission_base_price(int $service_id, ?int $service_duration_id, float $fallback_price): float {
    global $conn;
    if ($service_duration_id) {
        $stmt = $conn->prepare("SELECT promo_price FROM service_durations WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $service_duration_id);
    } else {
        $stmt = $conn->prepare("SELECT promo_price FROM service_durations WHERE service_id = ? ORDER BY duration_minutes, session_count LIMIT 1");
        $stmt->bind_param("i", $service_id);
    }
    $stmt->execute();
    $promo = floatval($stmt->get_result()->fetch_assoc()['promo_price'] ?? 0);
    $stmt->close();
    return $promo > 0 ? $promo : $fallback_price;
}

// ─── SERVICE UPGRADE / VOUCHER TYPE COLUMNS ──────────────────────────────────
// appointments.upgraded_from: the service first booked, when it was upgraded.
// appointment_extra_services.is_upgrade_topup: the unpaid price difference of
// an upgrade made after the order was paid (collected like an add-on, but
// left out of reports - the main service already carries the full price).
// orders.voucher_label: what kind of voucher was used (Gift Card, ...).
function ensure_upgrade_voucher_columns($conn): void {
    static $done = false;
    if ($done) return;
    $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS upgraded_from VARCHAR(150) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE appointment_extra_services ADD COLUMN IF NOT EXISTS is_upgrade_topup TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS voucher_label VARCHAR(100) NULL DEFAULT NULL");
    $done = true;
}

// Records what kind of voucher was used on an order (free text, e.g. "Gift
// Card", "Nails Voucher"). Called wherever a voucher discount is applied.
function save_voucher_label($conn, int $order_id, string $label): void {
    $label = trim(mb_substr(strip_tags($label), 0, 100));
    if ($order_id <= 0 || $label === '') return;
    ensure_upgrade_voucher_columns($conn);
    $u = $conn->prepare("UPDATE orders SET voucher_label = ? WHERE id = ?");
    $u->bind_param("si", $label, $order_id); $u->execute(); $u->close();
}

// ─── STAFF (EMPLOYEE) DISCOUNT ───────────────────────────────────────────────
// Percent off for the 'employee' discount type, everywhere it's applied.
if (!defined('STAFF_DISCOUNT_PCT')) define('STAFF_DISCOUNT_PCT', 25);

// ─── MULTI-SESSION PACKAGES IN THE DAILY REPORT ──────────────────────────────
// A session-count package (service_durations.session_count > 1) booked from
// PKG_SPLIT_FROM on is reported per session, like a Calendar down payment:
//   - each session's share of the price is a sale on the day that session is
//     completed (with that session's commission);
//   - on Session 1's day, the later sessions' share (already collected) is an
//     Advance Payment received, not a sale;
//   - on each later session's day, its share is deducted as ADVANCE PAYMENT.
// Packages booked earlier keep the old rule (full price on the booking date),
// so past reports don't change.
if (!defined('PKG_SPLIT_FROM')) define('PKG_SPLIT_FROM', '2026-10-12 00:00:00');

// Session $n's share of a package price $total split over $count sessions.
// The last session takes the rounding remainder so the shares add up exactly.
function pkg_session_share(float $total, int $count, int $n): float {
    $count = max(1, $count);
    $each  = round($total / $count, 2);
    return $n >= $count ? round($total - $each * ($count - 1), 2) : $each;
}

// What a paid multi-session package collected for its LATER sessions on
// Session 1's day: the later sessions' share net of the order's discounts,
// minus any part of an earlier Calendar down payment that went beyond
// Session 1 (that part was already counted on its own collection day).
// $a: charged_price, session_count, dp, dp_date, discount_amount,
// completion_discount_amount, and optionally same_day_done: a comma list
// of later session numbers also completed on $day (those are ordinary
// sales that day, not an advance).
function pkg_prepaid_later_sessions(array $a, string $day): float {
    $C = (float)$a['charged_price']; $N = max(1, (int)$a['session_count']);
    if ($C <= 0 || $N < 2) return 0.0;
    $disc  = (float)($a['discount_amount'] ?? 0) + (float)($a['completion_discount_amount'] ?? 0);
    $net   = fn(int $k) => pkg_session_share($C, $N, $k) - round($disc * pkg_session_share($C, $N, $k) / $C, 2);
    $p1net = $net(1);
    $later = ($C - $disc) - $p1net;
    foreach (array_filter(array_map('intval', explode(',', (string)($a['same_day_done'] ?? '')))) as $k) {
        if ($k >= 2 && $k <= $N) $later -= $net($k);
    }
    $dp    = (!empty($a['dp_date']) && $a['dp_date'] !== $day) ? (float)$a['dp'] : 0.0;
    return round(max(0.0, $later - max(0.0, $dp - $p1net)), 2);
}

// ─── DOCTOR / NURSE SERVICES ─────────────────────────────────────────────────
// A service ticked "Doctor / Nurse service" in Services is performed by a
// doctor or nurse who sets their own fee; accounting pays them outside this
// system. Such a service never stores therapist commission (₱0 everywhere),
// and the Daily Report leaves its commission columns blank so Net Sales is
// the full amount charged.
function service_is_medical($conn, int $service_id): bool {
    static $cache = [], $ready = false;
    if (!$ready) {
        $conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS is_medical TINYINT(1) NOT NULL DEFAULT 0");
        $ready = true;
    }
    if ($service_id <= 0) return false; // service_is_medical($conn, 0) = just ensure the column
    if (!array_key_exists($service_id, $cache)) {
        $q = $conn->prepare("SELECT is_medical FROM services WHERE id = ? LIMIT 1");
        $q->bind_param("i", $service_id); $q->execute();
        $cache[$service_id] = !empty($q->get_result()->fetch_assoc()['is_medical']);
        $q->close();
    }
    return $cache[$service_id];
}

// Bookings whose duration option was deleted (older Services saves deleted
// and re-inserted every option, and the FK sets the booking's id to NULL)
// lose their promo price, commission base and session count. Re-link each
// to the service's current option with the same minutes -- and the same
// session count, read from its appointment_sessions rows (1 when it has
// none). Only touches bookings with no option at all.
function relink_orphan_service_durations($conn, ?int $service_id = null): int {
    $where = $service_id ? ' AND a.service_id = ' . (int)$service_id : '';
    try {
    $conn->query("
        UPDATE appointments a
        JOIN service_durations sd
          ON sd.service_id = a.service_id
         AND sd.duration_minutes = a.duration_minutes
         AND IFNULL(sd.session_count, 1) = GREATEST(1, (SELECT COUNT(*) FROM appointment_sessions x WHERE x.appointment_id = a.id))
        LEFT JOIN service_durations cur ON cur.id = a.service_duration_id
        SET a.service_duration_id = sd.id
        WHERE cur.id IS NULL AND a.duration_minutes IS NOT NULL$where");
    return $conn->affected_rows;
    } catch (Throwable $e) {
        return 0; // appointment_sessions not created yet on this install
    }
}

// ─── PACKAGE SERVICES: commission-only components ────────────────────────────
// A "Package" service bundles 2+ real services sold together under one combined
// price (e.g. "Package 2" = Express Head Spa + Foot Massage). Therapist
// commission for it is NOT one rate applied to the package's own price -- it's
// the SUM of what each component service would pay on its own: that
// component's own commission % for this therapist, times that component's own
// get_commission_base_price(). charged_price/revenue bookkeeping is untouched
// (it stays the package's own regular price, exactly like any other service),
// so Net Sales = charged_price - total_package_commission falls out of the
// existing formula with no changes there. Services with no rows here are
// ordinary, unbundled services and behave exactly as before.
function ensure_service_package_components_table($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS service_package_components (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_service_id INT NOT NULL,
        component_service_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_pkg_component (package_service_id, component_service_id),
        KEY idx_package (package_service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Returns [] for an ordinary (non-package) service -- callers use an empty
// result as the signal to fall back to the normal single-rate commission calc.
function get_package_components($conn, int $package_service_id): array {
    ensure_service_package_components_table($conn);
    $stmt = $conn->prepare("SELECT spc.component_service_id, s.name, s.price
                             FROM service_package_components spc
                             JOIN services s ON s.id = spc.component_service_id
                             WHERE spc.package_service_id = ?
                             ORDER BY spc.sort_order, s.name");
    $stmt->bind_param("i", $package_service_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Sums each component's own standalone commission for $therapist_id -- same
// math as a normal single-service commission calc, just run once per
// component and added up. Returns total 0.00 (with components => []) when
// $package_service_id isn't actually a package, so callers can use an empty
// 'components' array as the "not a package" signal.
function compute_package_commission($conn, int $package_service_id, int $therapist_id): array {
    $components = get_package_components($conn, $package_service_id);
    $total = 0.00;
    $breakdown = [];
    foreach ($components as $comp) {
        $rate = 0.00;
        if ($therapist_id > 0) {
            $cm = $conn->prepare("SELECT commission_percent FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
            $cm->bind_param("ii", $therapist_id, $comp['component_service_id']);
            $cm->execute();
            $rate = floatval($cm->get_result()->fetch_assoc()['commission_percent'] ?? 0);
            $cm->close();
        }
        $base = get_commission_base_price((int)$comp['component_service_id'], null, (float)$comp['price']);
        $comm = round($base * $rate / 100, 2);
        $total += $comm;
        $breakdown[] = ['service_id' => (int)$comp['component_service_id'], 'name' => $comp['name'], 'base' => $base, 'rate' => $rate, 'commission' => $comm];
    }
    return ['total' => round($total, 2), 'components' => $breakdown];
}

// A therapist "qualified" for a Package service is NOT tested against the
// package's own id/category -- nobody is ever specialized in a bundle as
// such -- it's tested against EVERY real component, since the one therapist
// assigned will perform the whole thing. For an ordinary (non-package)
// service this is exactly the existing specialty check (is_generalist, or a
// therapist_specialty_services row, or a therapist_specialties category
// match), just centralized here instead of repeated inline per call site.
// Returns the qualified therapist ids as a plain array, for callers to use
// via "t.id IN (...)" or array membership.
function get_booking_qualified_therapist_ids($conn, int $service_id): array {
    $components = get_package_components($conn, $service_id);
    $check_ids = !empty($components)
        ? array_map(fn($c) => (int)$c['component_service_id'], $components)
        : [$service_id];

    $generalists = array_map('intval', array_column(
        $conn->query("SELECT id FROM therapists WHERE is_generalist = 1")->fetch_all(MYSQLI_ASSOC), 'id'
    ));

    $qualified_sets = [];
    foreach ($check_ids as $sid) {
        $stmt = $conn->prepare("
            SELECT DISTINCT t.id FROM therapists t
            WHERE EXISTS(SELECT 1 FROM therapist_specialty_services WHERE therapist_id = t.id AND service_id = ?)
               OR EXISTS(SELECT 1 FROM therapist_specialties ts JOIN services s ON s.category_id = ts.category_id WHERE ts.therapist_id = t.id AND s.id = ?)
        ");
        $stmt->bind_param("ii", $sid, $sid);
        $stmt->execute();
        $qualified_sets[] = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
    }
    $intersection = $qualified_sets ? array_shift($qualified_sets) : [];
    foreach ($qualified_sets as $set) {
        $intersection = array_intersect($intersection, $set);
    }

    return array_values(array_unique(array_merge($generalists, $intersection)));
}

// Which of the Daily Report's four commission-rate columns (30/20/15/25%)
// a given percent lands in -- same tier boundaries used everywhere this
// report buckets a rate. Anything outside 13-32% (including "no rate set")
// falls back to the 30% column, matching the long-standing behavior here.
function commission_report_bucket_key(float $pct): string {
    if ($pct >= 28 && $pct <= 32) return 'comm_30';
    if ($pct >= 23 && $pct <= 27) return 'comm_25';
    if ($pct >= 18 && $pct <= 22) return 'comm_20';
    if ($pct >= 13 && $pct <= 17) return 'comm_15';
    return 'comm_30';
}

// Splits a service's total commission across the Daily Report's four rate
// columns for one therapist. A Package service (see
// service_package_components) has no rate of its own -- each real
// component can carry a different rate, so its commission is split across
// however many buckets its components land in (e.g. Express Head Spa's
// 15% and Foot Massage's 25% land in two different columns, not blended
// into one). An ordinary service keeps the single-rate lookup it always
// had, routing $total_comm (the already-computed, authoritative amount)
// into that one bucket.
function route_commission_to_buckets($conn, int $service_id, int $therapist_id, float $total_comm): array {
    $buckets = ['comm_30' => 0.0, 'comm_20' => 0.0, 'comm_15' => 0.0, 'comm_25' => 0.0];
    $pkg = compute_package_commission($conn, $service_id, $therapist_id);
    if (!empty($pkg['components'])) {
        // The components give the SPLIT; $total_comm (what was actually
        // stored -- e.g. x2 when one therapist handled 2 people) gives the
        // AMOUNT, so the columns always add up to the row's commission.
        if ($pkg['total'] > 0) {
            $scale = $total_comm / $pkg['total'];
            foreach ($pkg['components'] as $comp) {
                $buckets[commission_report_bucket_key((float)$comp['rate'])] += round((float)$comp['commission'] * $scale, 2);
            }
            // Keep the rounding cent in the largest column.
            $diff = round($total_comm - array_sum($buckets), 2);
            if ($diff != 0.0) { arsort($buckets); $buckets[array_key_first($buckets)] += $diff; }
        } else {
            $buckets['comm_30'] += $total_comm;
        }
        return $buckets;
    }
    $pct = 0.0;
    if ($therapist_id > 0) {
        $stmt = $conn->prepare("SELECT commission_percent FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
        $stmt->bind_param("ii", $therapist_id, $service_id);
        $stmt->execute();
        $pct = (float)($stmt->get_result()->fetch_assoc()['commission_percent'] ?? 0);
        $stmt->close();
    }
    $buckets[commission_report_bucket_key($pct)] += $total_comm;
    return $buckets;
}

// Walk-in-sourced appointments are attributed to this account (see admin/walkin.php);
// online-sourced ones use the real customer's own user_id from user/checkout.php.
// Slotting and Rotation's approval flow (admin/appointments.php) uses this to tell
// the two origins apart. Lookup-or-create mirrors walkin.php's own inline logic.
function get_walkin_customer_id(): int {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'walkin_customer' LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];

    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES ('walkin_customer','N/A','walkin@spa.com','Walk-in Customer','N/A','Walk-in Customer','user')");
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return (int)$id;
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
    global $conn;
    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $chk->bind_param("i", $_SESSION['user_id']); $chk->execute();
    $exists = $chk->get_result()->num_rows > 0; $chk->close();
    if (!$exists) {
        session_unset(); session_destroy();
        header('Location: ' . BASE_URL . 'admin/admin_login.php');
        exit();
    }
}

function redirect_if_not_user(): void {
    if (!is_logged_in() || is_admin()) {
        header('Location: ' . BASE_URL . 'user/auth.php');
        exit();
    }
    global $conn;
    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $chk->bind_param("i", $_SESSION['user_id']); $chk->execute();
    $exists = $chk->get_result()->num_rows > 0; $chk->close();
    if (!$exists) {
        session_unset(); session_destroy();
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
    // Clear the single-active-session token so the account can log in again
    // elsewhere right away, instead of waiting out the stale-session timeout.
    if ($conn && isset($_SESSION['user_id'], $_SESSION['session_token'])) {
        $clr = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=? AND session_token=?");
        $clr->bind_param("is", $_SESSION['user_id'], $_SESSION['session_token']);
        $clr->execute(); $clr->close();
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

// ─── CUSTOMER STOREFRONT HELPERS ──────────────────────────────────────────────
// Used by index.php, services.php, products.php and footer.php. These were
// called by the storefront redesign but never actually defined anywhere in the
// codebase — any page calling get_best_selling_services()/get_best_selling_products()
// (index.php) fatal-errored immediately (before any HTML output, since the calls
// run at the top of the file), and any page including footer.php
// (get_customer_categories_with_counts()) fatal-errored once execution reached
// the footer at the bottom of the page.

function get_best_selling_services($conn, int $limit = 10, int $days = 90): array {
    $stmt = $conn->prepare("
        SELECT s.id, s.name, s.price, s.image, s.category_id, c.name AS category_name,
               COUNT(*) AS sold_count
        FROM appointments a
        JOIN services s   ON s.id = a.service_id
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE s.deleted_at IS NULL
          AND a.status NOT IN ('cancelled', 'declined')
          AND a.appointment_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY s.id, s.name, s.price, s.image, s.category_id, c.name
        ORDER BY sold_count DESC, s.name ASC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $days, $limit);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    // Fall back to any active services (newest first) if nothing sold yet in
    // the window — an empty "Best Sellers" carousel on a quiet/new store would
    // otherwise just render nothing.
    if (empty($rows)) {
        $res = $conn->query("
            SELECT s.id, s.name, s.price, s.image, s.category_id, c.name AS category_name
            FROM services s
            LEFT JOIN categories c ON c.id = s.category_id
            WHERE s.deleted_at IS NULL
            ORDER BY s.id DESC
            LIMIT " . (int)$limit
        );
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function get_best_selling_products($conn, int $limit = 10, int $days = 90): array {
    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.price, p.image, p.stock, p.category_id, c.name AS category_name,
               COALESCE(SUM(oi.quantity), 0) AS sold_count
        FROM order_items oi
        JOIN orders o     ON o.id = oi.order_id
        JOIN products p   ON p.id = oi.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.deleted_at IS NULL
          AND oi.product_id IS NOT NULL
          AND o.payment_status = 'paid'
          AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY p.id, p.name, p.price, p.image, p.stock, p.category_id, c.name
        ORDER BY sold_count DESC, p.name ASC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $days, $limit);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if (empty($rows)) {
        $res = $conn->query("
            SELECT p.id, p.name, p.price, p.image, p.stock, p.category_id, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.deleted_at IS NULL
            ORDER BY p.id DESC
            LIMIT " . (int)$limit
        );
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function get_customer_categories_with_counts($conn, string $type): array {
    $table = $type === 'product' ? 'products' : 'services';
    $stmt = $conn->prepare("
        SELECT c.id, c.name,
               COUNT(i.id) AS item_count,
               (SELECT i2.image FROM {$table} i2
                WHERE i2.category_id = c.id AND i2.deleted_at IS NULL
                  AND i2.image IS NOT NULL AND i2.image <> ''
                ORDER BY i2.id LIMIT 1) AS sample_image
        FROM categories c
        LEFT JOIN {$table} i ON i.category_id = c.id AND i.deleted_at IS NULL
        WHERE c.type = ?
        GROUP BY c.id, c.name
        HAVING item_count > 0
        ORDER BY c.name ASC
    ");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// ─── MONOCHROME ICONS ─────────────────────────────────────────────────────────
// Inline-SVG replacements for emoji that had no accompanying text label (so the
// icon itself was the only thing identifying what a control/indicator meant).
// All inherit color/size from their surrounding text via currentColor + 1em.
function spa_icon(string $name, string $class = ''): string {
    $paths = [
        'calendar'  => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'user'      => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c0-4.2 3.4-6.8 7.5-6.8s7.5 2.6 7.5 6.8"/>',
        'building'  => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7.5h2M14 7.5h2M8 12h2M14 12h2M8 16.5h2M14 16.5h2"/>',
        'home'      => '<path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>',
        'chat'      => '<path d="M4 5h16v11H9l-4 4v-4H4z"/>',
        'card'      => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10.5h18"/>',
        'store'     => '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5v10.5h16V9.5"/><path d="M4 9.5c0 1.8 1.6 3.2 3.4 3.2s3.4-1.4 3.4-3.2M10.8 9.5c0 1.8 1.6 3.2 3.4 3.2s3.4-1.4 3.4-3.2"/><path d="M9.5 20v-5h5v5"/>',
        'bag'       => '<path d="M6 8h12l1 12.5H5z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'cart'      => '<path d="M3 4h2.5l1.2 2M5.7 6h14.3l-1.8 8H8z"/><circle cx="9" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>',
        'leaf'      => '<path d="M19 4C9 4 5 10 5 16c0 2 1 3 3 3 6 0 12-4 12-14 0-.4 0-.7-.2-1z"/><path d="M8 19c2-4 5-8 10-12.5"/>',
        'handshake' => '<path d="M2.5 11.5 7 8l3.2 2.6 2-1.6a2 2 0 0 1 2.6.1l4.2 3.9"/><path d="M8.5 13.3 11 15.6a1.7 1.7 0 0 0 2.4-.1 1.7 1.7 0 0 0 0-2.4M12.2 16.9a1.7 1.7 0 0 0 2.4 0l.7-.7a1.7 1.7 0 0 0 0-2.4"/><path d="M19.5 14.3 21.5 12M2.5 11.5 4.3 13.6"/>',
        'sparkle'   => '<path d="M12 3v4M12 17v4M4.5 12h4M15.5 12h4M6.5 6.5l2.3 2.3M15.2 15.2l2.3 2.3M17.5 6.5l-2.3 2.3M8.8 15.2l-2.3 2.3"/><circle cx="12" cy="12" r="2"/>',
        'lock'      => '<rect x="5" y="11" width="14" height="10" rx="1.5"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/>',
        'pin'       => '<path d="M12 21s7-6.6 7-11.5A7 7 0 0 0 5 9.5C5 14.4 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'phone'     => '<path d="M5 4.5h3.5l1.5 4-2 1.6a12 12 0 0 0 6 6l1.6-2 4 1.5V19c0 1-.9 1.8-1.9 1.6C10.5 19.4 4.6 13.5 3.4 6.4 3.2 5.4 4 4.5 5 4.5z"/>',
        'mobile'    => '<rect x="6.5" y="2.5" width="11" height="19" rx="2"/><path d="M10.5 18.5h3"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 7 8.5-7"/>',
        'bell'      => '<path d="M6 10a6 6 0 1 1 12 0c0 3.2 1 5 2 6.5H4c1-1.5 2-3.3 2-6.5z"/><path d="M10 19.5a2 2 0 0 0 4 0"/>',
        'hourglass' => '<path d="M6 3h12M6 21h12"/><path d="M7 3c0 4 3 5 5 6-2 1-5 2-5 6M17 3c0 4-3 5-5 6 2 1 5 2 5 6"/>',
        'receipt'   => '<path d="M6 2.5h12v19l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5z"/><path d="M8.5 7h7M8.5 10.5h7M8.5 14h4.5"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16.5 6.5l2.5 2.5M13.5 9.5l2 2"/>',
        'eye'       => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'   => '<path d="M3.5 3.5l17 17"/><path d="M10.6 5.7c.45-.1.92-.2 1.4-.2 6 0 9.5 6.5 9.5 6.5s-1.1 2-3.1 3.7M6.8 7.1C4.6 8.7 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.3 0 2.5-.3 3.6-.8M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'x-circle'  => '<circle cx="12" cy="12" r="9.5"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9.5"/><path d="M8 12.5l2.8 2.8L16.5 9"/>',
        'door'      => '<rect x="5" y="2.5" width="14" height="19" rx="1"/><circle cx="14.5" cy="12" r="1"/>',
        'paperclip' => '<path d="M17 7.5 8.8 15.7a3.2 3.2 0 0 0 4.5 4.5l7-7a5 5 0 0 0-7-7l-7.3 7.3a6.8 6.8 0 0 0 9.6 9.6"/>',
        'bottle'    => '<path d="M10 2.5h4v3.2l1.8 2.3v12.5a1.5 1.5 0 0 1-1.5 1.5h-4.6A1.5 1.5 0 0 1 8.2 20.5V8l1.8-2.3z"/><path d="M8.2 11.5h7.6"/>',
    ];
    $inner = $paths[$name] ?? $paths['sparkle'];
    $cls = $class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" '
         . 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
         . 'style="vertical-align:-0.15em" aria-hidden="true" class="spa-icon' . $cls . '">' . $inner . '</svg>';
}