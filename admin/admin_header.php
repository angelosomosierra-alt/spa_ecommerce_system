<?php
/**
 * Admin Shared Header & Sidebar
 * Include this at the top of every admin page AFTER PHP logic.
 *
 * Required variables before including:
 *   $page_title  — e.g. "Dashboard"
 *   $page_icon   — e.g. "📊"
 *   $active_page — e.g. "index" | "services" | "products" | etc.
 */

$admin_username = $_SESSION['username'] ?? 'Admin';
$admin_initial  = strtoupper(substr($admin_username, 0, 1));

$nav_items = [
    ['file' => 'index',        'icon' => '🏠', 'label' => 'Dashboard'],
    ['file' => 'services',     'icon' => '💆', 'label' => 'Services'],
    ['file' => 'products',     'icon' => '🛍️', 'label' => 'Products'],
    ['file' => 'categories',   'icon' => '🏷️', 'label' => 'Categories'],
    ['file' => 'users',        'icon' => '👥', 'label' => 'Users'],
    ['file' => 'appointments', 'icon' => '📅', 'label' => 'Appointments'],
    ['file' => 'orders',       'icon' => '📦', 'label' => 'Orders'],
    ['file' => 'analytics',    'icon' => '📊', 'label' => 'Analytics'],
    ['file' => 'walkin',       'icon' => '🏪', 'label' => 'Walk-in'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Admin'); ?> — Spa Admin</title>
    <link rel="stylesheet" href="../assets/admin.css?v=<?php echo filemtime('../assets/admin.css'); ?>">
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="admin-shell">

<!-- ── SIDEBAR ─────────────────────────────────────────────── -->
<aside class="admin-sidebar">

    <div class="sidebar-logo">
        <span class="sidebar-logo-text">Serenity Spa</span>
        <span class="sidebar-logo-sub">Admin Panel</span>
    </div>

    <span class="sidebar-section-label">Main Menu</span>
    <ul class="admin-menu">
        <?php foreach ($nav_items as $item): ?>
        <li>
            <a href="<?php echo $item['file']; ?>.php"
               class="<?php echo ($active_page ?? '') === $item['file'] ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo $item['icon']; ?></span>
                <?php echo $item['label']; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?php echo $admin_initial; ?></div>
            <div>
                <div class="sidebar-user-name"><?php echo htmlspecialchars($admin_username); ?></div>
                <div class="sidebar-user-role">Administrator</div>
            </div>
        </div>
        <a href="index.php?logout=1" class="sidebar-logout">🚪 Logout</a>
    </div>

</aside>

<!-- ── MAIN ────────────────────────────────────────────────── -->
<div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
        <div class="topbar-title">
            <span class="page-icon"><?php echo $page_icon ?? '⚙️'; ?></span>
            <?php echo htmlspecialchars($page_title ?? 'Admin'); ?>
        </div>
        <div class="topbar-right">
            <span class="topbar-time"><?php echo date('M d, Y — h:i A'); ?></span>
            <span class="topbar-badge">Admin</span>
            <?php if (isset($topbar_actions)) echo $topbar_actions; ?>
        </div>
    </div>

    <!-- Content -->
    <div class="admin-content">