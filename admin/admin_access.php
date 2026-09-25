<?php
/**
 * admin_access.php
 * Single source of truth for admin page roles and access enforcement.
 *
 * Usage in every protected admin page, right after require_once '../config.php':
 *   require_once __DIR__ . '/admin_access.php';
 *   enforce_page_access();
 *
 * admin_header.php also requires this file so admin_nav_items() is always available.
 */

/**
 * Full role map — matches the sidebar in admin_header.php plus sub-pages.
 * Key  : PHP file name without .php extension (basename of __FILE__).
 * Value: admin_role values that are permitted on that page.
 */
function admin_page_roles(): array {
    return [
        // ── Main nav (all roles present in the sidebar) ───────────────────────
        'index'                 => ['owner','it','hr','marketing','cashier'],
        'services'              => ['owner','it','hr','marketing'],
        'products'              => ['owner','it','hr','marketing'],
        'inventory'             => ['owner','it','hr','marketing'],
        'categories'            => ['owner','it','hr','marketing'],
        'users'                 => ['owner','it','hr','marketing'],
        'staff'                 => ['owner','it','hr','marketing'],
        'appointments'          => ['owner','it','hr','marketing','cashier'],
        'orders'                => ['owner','it','hr','marketing','cashier'],
        'therapists'            => ['owner','it','hr','marketing','cashier'],
        'analytics'             => ['owner','it','hr','marketing'],
        'walkin'                => ['owner','it','hr','cashier','marketing'],
        'partners'              => ['owner','it','hr','marketing'],
        'vouchers'              => ['owner','it','hr','marketing'],
        'discounts'             => ['owner','it','hr','marketing'],
        'daily_report'          => ['owner','it','hr','marketing','cashier'],
        'activity'              => ['owner','it','hr','marketing'],
        'help'                  => ['owner','it','hr','marketing','cashier'],
        // ── Sub-pages (no sidebar entry) ─────────────────────────────────────
        'assign_therapist'      => ['owner','it','hr','marketing','cashier'],
        'feedback'              => ['owner','it','hr','marketing'],
        'export_sales'          => ['owner','it','hr','marketing'],
        'receptionist_settings' => ['owner'],
        'fix_double_encoded_text' => ['owner','it'],
    ];
}

/**
 * Return the full nav definition, filtered for display by the caller.
 * Replaces the hardcoded $all_nav array in admin_header.php.
 */
function admin_nav_items(): array {
    return [
        ['file' => 'index',        'icon' => '🏠', 'label' => 'Dashboard',    'roles' => ['owner','it','hr','marketing','cashier']],
        ['file' => 'services',     'icon' => '💆', 'label' => 'Services',     'roles' => ['owner','it','hr','marketing']],
        ['file' => 'products',     'icon' => '🛍️', 'label' => 'Products',     'roles' => ['owner','it','hr','marketing']],
        ['file' => 'inventory',    'icon' => '🧴',  'label' => 'Inventory',    'roles' => ['owner','it','hr','marketing']],
        ['file' => 'categories',   'icon' => '🏷️', 'label' => 'Categories',   'roles' => ['owner','it','hr','marketing']],
        ['file' => 'users',        'icon' => '👥', 'label' => 'Users',        'roles' => ['owner','it','hr','marketing']],
        ['file' => 'staff',        'icon' => '🪪', 'label' => 'Staff',        'roles' => ['owner','it','hr','marketing']],
        ['file' => 'appointments', 'icon' => '📅', 'label' => 'Appointments', 'roles' => ['owner','it','hr','marketing','cashier']],
        ['file' => 'orders',       'icon' => '📦', 'label' => 'Orders',       'roles' => ['owner','it','hr','marketing','cashier']],
        ['file' => 'therapists',   'icon' => '💆', 'label' => 'Therapists',   'roles' => ['owner','it','hr','marketing','cashier']],
        ['file' => 'analytics',    'icon' => '📊', 'label' => 'Analytics',    'roles' => ['owner','it','hr','marketing']],
        ['file' => 'walkin',       'icon' => '🏪', 'label' => 'Walk-in',      'roles' => ['owner','it','hr','cashier','marketing']],
        ['file' => 'partners',     'icon' => '🤝', 'label' => 'Partners',     'roles' => ['owner','it','hr','marketing']],
        ['file' => 'discounts',    'icon' => '🎟️', 'label' => 'Discounts',    'roles' => ['owner','it','hr','marketing']],
        ['file' => 'daily_report', 'icon' => '📋', 'label' => 'Daily Report', 'roles' => ['owner','it','hr','marketing','cashier']],
        ['file' => 'activity',    'icon' => '🕐', 'label' => 'Activity Log', 'roles' => ['owner','it','hr','marketing']],
        ['file' => 'help',        'icon' => '❓', 'label' => 'Help / Guide', 'roles' => ['owner','it','hr','marketing','cashier']],
    ];
}

/**
 * Returns the list of admin_role values that $creator_role is permitted to create.
 * 'owner' is intentionally absent from every list — no UI path should create owners.
 */
function creatable_roles(string $creator_role): array {
    if ($creator_role === 'owner') return ['cashier', 'marketing', 'it', 'hr'];
    return []; // only owner may create accounts
}

/**
 * Abort with a redirect if the current admin's role is not in the allowed list
 * for this page. Unknown pages default to owner-only.
 *
 * Must be called after config.php has been loaded (session is started there).
 */
function enforce_page_access(): void {
    if (!is_logged_in() || !is_admin()) {
        header('Location: ' . BASE_URL . 'admin/admin_login.php');
        exit();
    }

    $page    = basename($_SERVER['SCRIPT_FILENAME'], '.php');
    $role    = current_admin_role();
    $allowed = admin_page_roles()[$page] ?? ['owner'];

    if (!in_array($role, $allowed, true)) {
        header('Location: ' . BASE_URL . 'admin/appointments.php?access_denied=1');
        exit();
    }

    // Per-request re-validation for cashier: session token + login hours
    if ($role === 'cashier') {
        global $conn;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $tok = $_SESSION['session_token'] ?? '';

        $s = $conn->prepare("SELECT session_token, session_started FROM users WHERE id=? AND role='admin'");
        $s->bind_param("i", $uid);
        $s->execute();
        $db_row = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$db_row || $db_row['session_token'] !== $tok) {
            session_unset();
            session_destroy();
            header('Location: ' . BASE_URL . 'admin/admin_login.php?ended=1');
            exit();
        }

        $tz_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_timezone' LIMIT 1")->fetch_assoc();
        $st_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_login_start' LIMIT 1")->fetch_assoc();
        $en_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_login_end' LIMIT 1")->fetch_assoc();
        date_default_timezone_set($tz_r['setting_value'] ?? 'Asia/Manila');
        $now  = date('H:i');
        $tst  = $st_r['setting_value'] ?? '07:00';
        $tend = $en_r['setting_value'] ?? '23:59';

        if ($now < $tst || $now > $tend) {
            $clr = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=?");
            $clr->bind_param("i", $uid);
            $clr->execute();
            $clr->close();
            session_unset();
            session_destroy();
            header('Location: ' . BASE_URL . 'admin/admin_login.php?hours_ended=1');
            exit();
        }
    }
}
