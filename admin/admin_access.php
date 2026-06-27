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
        'index'                 => ['owner','it','marketing','cashier'],
        'services'              => ['owner','it'],
        'products'              => ['owner','it'],
        'categories'            => ['owner','it'],
        'users'                 => ['owner','it'],
        'staff'                 => ['owner','it'],
        'appointments'          => ['owner','it','marketing','cashier'],
        'orders'                => ['owner','it','marketing','cashier'],
        'Therapists'            => ['owner','it','marketing','cashier'],
        'analytics'             => ['owner','it','marketing'],
        'walkin'                => ['owner','it','cashier'],
        'refunds'               => ['owner','it'],
        'partners'              => ['owner','it'],
        'vouchers'              => ['owner','it','marketing'],
        'discounts'             => ['owner','it','marketing'],
        'daily_report'          => ['owner','it','marketing','cashier'],
        // ── Sub-pages (no sidebar entry) ─────────────────────────────────────
        'assign_therapist'      => ['owner','it','marketing','cashier'],
        'feedback'              => ['owner','it','marketing'],
        'export_sales'          => ['owner','it'],
        'receptionist_settings' => ['owner'],
    ];
}

/**
 * Return the full nav definition, filtered for display by the caller.
 * Replaces the hardcoded $all_nav array in admin_header.php.
 */
function admin_nav_items(): array {
    return [
        ['file' => 'index',        'icon' => '🏠', 'label' => 'Dashboard',    'roles' => ['owner','it','marketing','cashier']],
        ['file' => 'services',     'icon' => '💆', 'label' => 'Services',     'roles' => ['owner','it']],
        ['file' => 'products',     'icon' => '🛍️', 'label' => 'Products',     'roles' => ['owner','it']],
        ['file' => 'categories',   'icon' => '🏷️', 'label' => 'Categories',   'roles' => ['owner','it']],
        ['file' => 'users',        'icon' => '👥', 'label' => 'Users',        'roles' => ['owner','it']],
        ['file' => 'staff',        'icon' => '🪪', 'label' => 'Staff',        'roles' => ['owner','it']],
        ['file' => 'appointments', 'icon' => '📅', 'label' => 'Appointments', 'roles' => ['owner','it','marketing','cashier']],
        ['file' => 'orders',       'icon' => '📦', 'label' => 'Orders',       'roles' => ['owner','it','marketing','cashier']],
        ['file' => 'Therapists',   'icon' => '💆', 'label' => 'Therapists',   'roles' => ['owner','it','marketing','cashier']],
        ['file' => 'analytics',    'icon' => '📊', 'label' => 'Analytics',    'roles' => ['owner','it','marketing']],
        ['file' => 'walkin',       'icon' => '🏪', 'label' => 'Walk-in',      'roles' => ['owner','it','cashier']],
        ['file' => 'refunds',      'icon' => '💸', 'label' => 'Refunds',      'roles' => ['owner','it']],
        ['file' => 'partners',     'icon' => '🤝', 'label' => 'Partners',     'roles' => ['owner','it']],
        ['file' => 'discounts',    'icon' => '🎟️', 'label' => 'Discounts',    'roles' => ['owner','it','marketing']],
        ['file' => 'daily_report', 'icon' => '📋', 'label' => 'Daily Report', 'roles' => ['owner','it','marketing','cashier']],
    ];
}

/**
 * Returns the list of admin_role values that $creator_role is permitted to create.
 * 'owner' is intentionally absent from every list — no UI path should create owners.
 */
function creatable_roles(string $creator_role): array {
    switch ($creator_role) {
        case 'it':        return ['cashier', 'marketing', 'it'];
        case 'owner':     return ['cashier', 'marketing'];
        case 'marketing': return ['cashier'];
        default:          return []; // cashier or unknown → none
    }
}

/**
 * Abort with a redirect if the current admin's role is not in the allowed list
 * for this page. Unknown pages default to owner-only.
 *
 * Must be called after config.php has been loaded (session is started there).
 */
function enforce_page_access(): void {
    if (!is_logged_in() || !is_admin()) {
        header('Location: ' . BASE_URL . 'user/auth.php');
        exit();
    }

    $page    = basename($_SERVER['SCRIPT_FILENAME'], '.php');
    $role    = current_admin_role();
    $allowed = admin_page_roles()[$page] ?? ['owner'];

    if (!in_array($role, $allowed, true)) {
        header('Location: ' . BASE_URL . 'admin/appointments.php?access_denied=1');
        exit();
    }
}
