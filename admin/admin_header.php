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

$admin_username   = $_SESSION['username']   ?? 'Admin';
$admin_initial    = strtoupper(substr($admin_username, 0, 1));
$admin_role       = $_SESSION['admin_role'] ?? 'owner';
$admin_role_label = match($admin_role) {
    'cashier'   => '🏪 Receptionist',
    'marketing' => '📣 Marketing',
    'it'        => '💻 IT Support',
    default     => '👑 Owner',
};

// ── Notification setup (admin = user_id IS NULL) ──────────────────────────────
require_once __DIR__ . '/../notify.php';
if (isset($_GET['mark_notif_read'])) {
    mark_all_read($conn, null);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit();
}
$admin_notif_unread = get_unread_count($conn, null);
$admin_notif_list   = get_notifications($conn, null, 15);

require_once __DIR__ . '/admin_access.php';
$all_nav   = admin_nav_items();
$nav_items = array_filter($all_nav, fn($item) => in_array($admin_role, $item['roles']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Admin'); ?> — Spa Admin</title>
    <link rel="stylesheet" href="admin.css?v=<?php echo filemtime('admin.css'); ?>">
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="admin-shell">

<!-- ── SIDEBAR OVERLAY (mobile) ─────────────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ─────────────────────────────────────────────── -->
<aside class="admin-sidebar" id="adminSidebar">

    <div class="sidebar-logo">
        <span class="sidebar-logo-text">RECOVERY ILOILO</span>
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
                <div class="sidebar-user-role"><?php echo $admin_role_label; ?></div>
            </div>
        </div>
        <a href="index.php?logout=1" class="sidebar-logout">🚪 Logout</a>
    </div>

</aside>

<!-- ── MAIN ────────────────────────────────────────────────── -->
<div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
        <div style="display:flex;align-items:center;gap:0;">
            <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
            <div class="topbar-title">
                <span class="page-icon"><?php echo $page_icon ?? '⚙️'; ?></span>
                <?php echo htmlspecialchars($page_title ?? 'Admin'); ?>
            </div>
        </div>
        <div class="topbar-right" style="display:flex;align-items:center;gap:0.75rem;">
            <span class="topbar-time"><?php echo date('M d, Y — h:i A'); ?></span>
            <span class="topbar-badge"><?php echo $admin_role_label; ?></span>

            <!-- Admin Notification Bell -->
            <div style="position:relative;display:inline-block;" id="adminNotifWrap">
                <button onclick="toggleAdminNotif(event)"
                        style="background:none;border:none;cursor:pointer;font-size:1.1rem;
                               padding:0.35rem 0.55rem;border-radius:8px;position:relative;
                               color:var(--cream);transition:background 0.15s;"
                        title="Notifications">
                    🔔
                    <?php if ($admin_notif_unread > 0): ?>
                    <span style="position:absolute;top:-2px;right:-4px;background:#dc3545;
                                 color:#fff;font-size:0.6rem;font-weight:700;min-width:16px;
                                 height:16px;border-radius:8px;display:flex;align-items:center;
                                 justify-content:center;padding:0 3px;line-height:1;">
                        <?php echo $admin_notif_unread > 99 ? '99+' : $admin_notif_unread; ?>
                    </span>
                    <?php endif; ?>
                </button>
                <div id="adminNotifPanel"
                     style="display:none;position:absolute;right:0;top:calc(100% + 8px);
                            width:310px;background:#fff;border-radius:12px;
                            box-shadow:0 8px 32px rgba(0,0,0,0.18);
                            border:1px solid #EAD8C0;z-index:9999;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;
                                padding:0.65rem 1rem;background:#3B2A1A;color:#FAF3E8;">
                        <span style="font-weight:600;font-size:0.85rem;">🔔 Notifications</span>
                        <?php if ($admin_notif_unread > 0): ?>
                        <a href="?mark_notif_read=1"
                           style="font-size:0.72rem;color:#C8A46B;text-decoration:none;">
                            Mark all read
                        </a>
                        <?php endif; ?>
                    </div>
                    <div style="max-height:360px;overflow-y:auto;">
                        <?php if (empty($admin_notif_list)): ?>
                        <div style="padding:1.5rem;text-align:center;color:#aaa;font-size:0.82rem;">No notifications yet</div>
                        <?php else: ?>
                        <?php
                        $an_icons = ['order'=>'🛍️','appointment'=>'📅','status'=>'🔔','general'=>'💬'];
                        foreach ($admin_notif_list as $n):
                            $diff = time() - strtotime($n['created_at']);
                            if ($diff < 60)        $n_time = 'Just now';
                            elseif ($diff < 3600)  $n_time = floor($diff/60) . 'm ago';
                            elseif ($diff < 86400) $n_time = floor($diff/3600) . 'h ago';
                            else                   $n_time = date('M d', strtotime($n['created_at']));
                        ?>
                        <a href="<?php echo htmlspecialchars($n['link']); ?>"
                           style="display:flex;align-items:flex-start;gap:0.65rem;
                                  padding:0.65rem 0.9rem;border-bottom:1px solid #EAD8C0;
                                  text-decoration:none;color:#3B2A1A;
                                  background:<?php echo $n['is_read'] ? '#fff' : '#fff8f3'; ?>;
                                  transition:background 0.15s;">
                            <span style="font-size:1.2rem;flex-shrink:0;margin-top:1px;">
                                <?php echo $an_icons[$n['type']] ?? '🔔'; ?>
                            </span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;font-size:0.82rem;color:#3B2A1A;
                                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($n['title']); ?>
                                </div>
                                <div style="font-size:0.75rem;color:#666;line-height:1.4;">
                                    <?php echo htmlspecialchars($n['message']); ?>
                                </div>
                                <div style="font-size:0.7rem;color:#aaa;margin-top:2px;">
                                    <?php echo $n_time; ?>
                                </div>
                            </div>
                            <?php if (!$n['is_read']): ?>
                            <div style="width:7px;height:7px;border-radius:50%;
                                        background:#C96A2C;flex-shrink:0;margin-top:5px;"></div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            <script>
            function toggleAdminNotif(e) {
                e.stopPropagation();
                const p = document.getElementById('adminNotifPanel');
                p.style.display = p.style.display === 'block' ? 'none' : 'block';
            }
            document.addEventListener('click', function(e) {
                const w = document.getElementById('adminNotifWrap');
                const p = document.getElementById('adminNotifPanel');
                if (p && w && !w.contains(e.target)) p.style.display = 'none';
            });
            </script>

            <?php if (isset($topbar_actions)) echo $topbar_actions; ?>
        </div>
    </div>

    <!-- Content -->
    <div class="admin-content">

<script>
function toggleSidebar() {
    const sidebar  = document.getElementById('adminSidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const isOpen   = sidebar.classList.contains('open');
    if (isOpen) {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    } else {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Close sidebar on escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSidebar();
});

// Close sidebar when a nav link is clicked on mobile
document.querySelectorAll('.admin-menu a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) closeSidebar();
    });
});
</script>