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
// Flipping this to true restores full PayMongo functionality with no other changes.
define('ONLINE_PAYMENT_ENABLED', false);

// GCash/Maya direct integration requires separate PayMongo Business approvals
// (not yet active). QR Ph already covers GCash/Maya/all bank apps via a single
// universal QR code. Set to true once GCash/Maya Business accounts are approved
// and linked.
define('SHOW_GCASH_MAYA', false);

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

// ─── BASE URL ─────────────────────────────────────────────────────────────────
$protocol      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host          = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_is_hostinger = strpos($host, 'hostingersite.com') !== false;
$folder        = $_is_hostinger ? '/' : (getenv('APP_SUBFOLDER') ?: '/spa_ecommerce_system/');
define('BASE_URL', $protocol . '://' . $host . $folder);
unset($_is_hostinger);

// ─── UPLOAD DIRECTORIES ───────────────────────────────────────────────────────
define('UPLOAD_DIR_SERVICES', __DIR__ . '/uploads/services/');
define('UPLOAD_DIR_PRODUCTS', __DIR__ . '/uploads/products/');

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
    if ($mode !== 'promo' || empty($promoStart) || empty($promoEnd)) {
        return ['price' => $regular, 'is_promo_active' => false];
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