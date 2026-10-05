<?php
/**
 * Admin Shared Header & Sidebar
 * Include this at the top of every admin page AFTER PHP logic.
 *
 * Required variables before including:
 *   $page_title  — e.g. "Dashboard"
 *   $active_page — e.g. "index" | "services" | "products" | etc.
 */

$admin_username   = $_SESSION['username']   ?? 'Admin';
$admin_initial    = strtoupper(substr($admin_username, 0, 1));
$admin_role       = $_SESSION['admin_role'] ?? 'owner';
$admin_role_label = match($admin_role) {
    'cashier'   => 'Receptionist',
    'marketing' => 'Marketing',
    'it'        => 'IT Support',
    'hr'        => 'HR',
    default     => 'Owner',
};

// ── Notification setup (admin = user_id IS NULL) ──────────────────────────────
require_once __DIR__ . '/../notify.php';
if (isset($_GET['mark_notif_read'])) {
    mark_all_read($conn, null);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit();
}
$admin_notif_unread = get_unread_count($conn, null);
$admin_notif_list   = get_notifications($conn, null, 15);

// ── Chat (shared customer inbox) setup ─────────────────────────────────────────
require_once __DIR__ . '/../chat.php';
$admin_chat_unread = chat_unread_count_admin_total($conn);

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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/responsive.css?v=<?php echo filemtime(__DIR__ . '/../assets/responsive.css'); ?>">
    <style>
        /* ── Admin chat drawer (customer messages) ── */
        #adminChatOverlay {
            display: none; position: fixed; inset: 0; z-index: 10998;
            background: rgba(0,0,0,.45); backdrop-filter: blur(2px);
        }
        #adminChatOverlay.open { display: block; }
        #adminChatDrawer {
            display: none; position: fixed; top: 0; right: 0; bottom: 0; z-index: 10999;
            width: 420px; max-width: 100vw;
            background: var(--bg2); box-shadow: -6px 0 40px rgba(0,0,0,.18);
            flex-direction: column; overflow: hidden;
        }
        #adminChatDrawer.open { display: flex; }
        .acd-header {
            display: flex; align-items: center; gap: .6rem;
            padding: 1rem 1.25rem; background: var(--brown); color: var(--cream);
            flex-shrink: 0;
        }
        .acd-header h2 { font-size: 1rem; font-weight: 700; margin: 0; flex: 1; }
        .acd-back, .acd-close {
            width: 30px; height: 30px; border-radius: 50%; border: none;
            background: rgba(255,255,255,.12); color: var(--cream);
            cursor: pointer; font-size: .9rem; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: background .15s;
        }
        .acd-back:hover, .acd-close:hover { background: rgba(255,255,255,.25); }
        #adminChatListView, #adminChatThreadView { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        #adminChatThreadList { flex: 1; overflow-y: auto; }
        #adminChatMessages { flex: 1; overflow-y: auto; padding: 1.1rem 1rem; background: var(--bg); }
        #adminChatMessages::-webkit-scrollbar, #adminChatThreadList::-webkit-scrollbar { width: 5px; }
        #adminChatMessages::-webkit-scrollbar-thumb, #adminChatThreadList::-webkit-scrollbar-thumb { background: rgba(201,106,44,.25); border-radius: 4px; }
        .acd-send-bar {
            display: flex; gap: .5rem; align-items: flex-end;
            padding: .85rem 1rem; border-top: 1px solid var(--border2);
            background: var(--bg2); flex-shrink: 0;
        }
        .acd-attach-btn {
            width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px;
            border: 1px solid var(--border2); background: var(--bg3); color: var(--brown-md);
            font-size: 1.05rem; cursor: pointer; transition: background .15s;
            display: flex; align-items: center; justify-content: center;
        }
        .acd-attach-btn:hover { background: var(--bg4); }
        .acd-attach-preview {
            display: none; align-items: center; gap: .4rem;
            padding: .3rem .6rem; margin: 0 1rem .5rem; border-radius: 8px;
            background: var(--gold-dim); font-size: .75rem; color: var(--brown-md);
        }
        .acd-attach-preview.show { display: flex; }
        .acd-attach-preview button {
            margin-left: auto; background: none; border: none; cursor: pointer;
            color: var(--red); font-size: .85rem; padding: 0;
        }
        .acd-send-bar textarea {
            flex: 1; resize: none; max-height: 90px;
            padding: .55rem .75rem; border: 1px solid var(--border2); border-radius: 10px;
            font: inherit; font-size: .85rem; color: var(--brown); outline: none;
        }
        .acd-send-bar textarea:focus { border-color: var(--rust); }
        .acd-send-bar button[type="submit"] {
            padding: .6rem 1.1rem; border: none; border-radius: 10px;
            background: var(--rust); color: #fff; font-weight: 700; font-size: .85rem;
            cursor: pointer; transition: background .15s; flex-shrink: 0;
        }
        .acd-send-bar button[type="submit"]:hover { background: #A94F1D; }
        .acd-send-bar button:disabled { opacity: .6; cursor: default; }
    </style>
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
               class="<?php echo strtolower($active_page ?? '') === strtolower($item['file']) ? 'active' : ''; ?>">
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
        <a href="index.php?logout=1" class="sidebar-logout">Logout</a>
    </div>

</aside>

<!-- ── MAIN ────────────────────────────────────────────────── -->
<div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
        <div style="display:flex;align-items:center;gap:0;">
            <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
            <div class="topbar-title">
                <?php echo htmlspecialchars($page_title ?? 'Admin'); ?>
            </div>
        </div>
        <div class="topbar-right" style="display:flex;align-items:center;gap:0.75rem;">
            <span class="topbar-time"><?php echo date('M d, Y — h:i A'); ?></span>
            <span class="topbar-badge"><?php echo $admin_role_label; ?></span>

            <!-- Admin Chat (customer messages) -->
            <div style="position:relative;display:inline-block;">
                <button onclick="openAdminChat()"
                        style="background:none;border:none;cursor:pointer;font-size:1.1rem;
                               padding:0.35rem 0.55rem;border-radius:8px;position:relative;
                               color:var(--cream);transition:background 0.15s;"
                        title="Customer Messages">
                    💬
                    <span id="adminChatBadge" style="position:absolute;top:-2px;right:-4px;background:#dc3545;
                                 color:#fff;font-size:0.6rem;font-weight:700;min-width:16px;
                                 height:16px;border-radius:8px;display:<?php echo $admin_chat_unread > 0 ? 'flex' : 'none'; ?>;align-items:center;
                                 justify-content:center;padding:0 3px;line-height:1;">
                        <?php echo $admin_chat_unread > 99 ? '99+' : $admin_chat_unread; ?>
                    </span>
                </button>
            </div>

            <!-- Admin Notification Bell -->
            <div style="position:relative;display:inline-block;" id="adminNotifWrap">
                <button onclick="toggleAdminNotif(event)"
                        style="background:none;border:none;cursor:pointer;font-size:1.1rem;
                               padding:0.35rem 0.55rem;border-radius:8px;position:relative;
                               color:var(--cream);transition:background 0.15s;"
                        title="Notifications">
                    🔔
                    <span id="adminNotifBadge" style="position:absolute;top:-2px;right:-4px;background:#dc3545;
                                 color:#fff;font-size:0.6rem;font-weight:700;min-width:16px;
                                 height:16px;border-radius:8px;display:<?php echo $admin_notif_unread > 0 ? 'flex' : 'none'; ?>;align-items:center;
                                 justify-content:center;padding:0 3px;line-height:1;">
                        <?php echo $admin_notif_unread > 99 ? '99+' : $admin_notif_unread; ?>
                    </span>
                </button>
                <div id="adminNotifPanel"
                     style="display:none;position:absolute;right:0;top:calc(100% + 8px);
                            width:310px;background:#fff;border-radius:12px;
                            box-shadow:0 8px 32px rgba(0,0,0,0.18);
                            border:1px solid #EAD8C0;z-index:9999;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;
                                padding:0.65rem 1rem;background:#3B2A1A;color:#FAF3E8;">
                        <span style="font-weight:600;font-size:0.85rem;">Notifications</span>
                        <a id="adminMarkAllRead" href="?mark_notif_read=1"
                           style="font-size:0.72rem;color:#C8A46B;text-decoration:none;display:<?php echo $admin_notif_unread > 0 ? 'inline' : 'none'; ?>;">
                            Mark all read
                        </a>
                    </div>
                    <div id="adminNotifItems" style="max-height:360px;overflow-y:auto;">
                        <?php if (empty($admin_notif_list)): ?>
                        <div style="padding:1.5rem;text-align:center;color:#aaa;font-size:0.82rem;">No notifications yet</div>
                        <?php else: ?>
                        <?php
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
            function renderAdminNotif(data) {
              const badge = document.getElementById('adminNotifBadge');
              if (badge) {
                badge.textContent = data.unread > 99 ? '99+' : data.unread;
                badge.style.display = data.unread > 0 ? 'flex' : 'none';
              }
              const markLink = document.getElementById('adminMarkAllRead');
              if (markLink) markLink.style.display = data.unread > 0 ? 'inline' : 'none';
              const items = document.getElementById('adminNotifItems');
              if (items) items.innerHTML = data.list_html;
            }
            function pollAdminNotif() {
              fetch('notif_poll.php', { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : null)
                .then(data => { if (data) renderAdminNotif(data); })
                .catch(() => { /* silent — network hiccup, keep last known state */ });
            }
            setInterval(pollAdminNotif, 3000);
            </script>

            <!-- ══════════════════════════════════════
                 ADMIN CHAT DRAWER (shared customer inbox)
            ══════════════════════════════════════ -->
            <div id="adminChatOverlay" onclick="closeAdminChat()"></div>
            <div id="adminChatDrawer" role="dialog" aria-label="Customer Messages">

                <div id="adminChatListView">
                    <div class="acd-header">
                        <h2>Customer Messages</h2>
                        <button class="acd-close" onclick="closeAdminChat()" title="Close">✕</button>
                    </div>
                    <div id="adminChatThreadList"></div>
                </div>

                <div id="adminChatThreadView" style="display:none;">
                    <div class="acd-header">
                        <button class="acd-back" onclick="showAdminChatList()" title="Back">←</button>
                        <h2 id="adminChatThreadName">Customer</h2>
                        <button class="acd-close" onclick="closeAdminChat()" title="Close">✕</button>
                    </div>
                    <div id="adminChatMessages"></div>
                    <div id="adminChatAttachPreview" class="acd-attach-preview">
                        <span>📎</span>
                        <span id="adminChatAttachName"></span>
                        <button type="button" onclick="clearAdminChatAttachment()" title="Remove">✕</button>
                    </div>
                    <form id="adminChatSendForm" class="acd-send-bar" enctype="multipart/form-data">
                        <button type="button" class="acd-attach-btn" onclick="document.getElementById('adminChatAttachInput').click()" title="Attach file or photo">📎</button>
                        <input type="file" id="adminChatAttachInput" accept="image/*,.pdf,.doc,.docx,.xlsx,.xls,.txt" style="display:none;">
                        <textarea id="adminChatInput" placeholder="Type a reply…" rows="1"></textarea>
                        <button type="submit">Send</button>
                    </form>
                </div>

            </div>
            <script>
            (function() {
                const overlay    = document.getElementById('adminChatOverlay');
                const drawer     = document.getElementById('adminChatDrawer');
                const listView   = document.getElementById('adminChatListView');
                const threadView = document.getElementById('adminChatThreadView');
                const threadList = document.getElementById('adminChatThreadList');
                const msgs       = document.getElementById('adminChatMessages');
                const threadName = document.getElementById('adminChatThreadName');
                const form       = document.getElementById('adminChatSendForm');
                const input      = document.getElementById('adminChatInput');
                const badge      = document.getElementById('adminChatBadge');
                const attachInput   = document.getElementById('adminChatAttachInput');
                const attachPreview = document.getElementById('adminChatAttachPreview');
                const attachName    = document.getElementById('adminChatAttachName');

                let activeCustomerId = null;
                let pollTimer        = null;
                let drawerIsOpen     = false;

                window.clearAdminChatAttachment = function() {
                    attachInput.value = '';
                    attachPreview.classList.remove('show');
                };
                attachInput.addEventListener('change', function() {
                    if (attachInput.files.length) {
                        attachName.textContent = attachInput.files[0].name;
                        attachPreview.classList.add('show');
                    } else {
                        attachPreview.classList.remove('show');
                    }
                });

                window.openAdminChat = function() {
                    overlay.classList.add('open');
                    drawer.classList.add('open');
                    document.body.style.overflow = 'hidden';
                    drawerIsOpen = true;
                    showAdminChatList();
                };
                window.closeAdminChat = function() {
                    overlay.classList.remove('open');
                    drawer.classList.remove('open');
                    document.body.style.overflow = '';
                    drawerIsOpen = false;
                    activeCustomerId = null;
                    clearInterval(pollTimer);
                };
                document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && drawerIsOpen) closeAdminChat(); });

                window.showAdminChatList = function() {
                    activeCustomerId = null;
                    threadView.style.display = 'none';
                    listView.style.display = 'flex';
                    loadThreadList();
                    clearInterval(pollTimer);
                    pollTimer = setInterval(loadThreadList, 5000);
                };

                window.openChatThread = function(customerId, customerName) {
                    activeCustomerId = customerId;
                    listView.style.display = 'none';
                    threadView.style.display = 'flex';
                    threadName.textContent = customerName;
                    loadThread();
                    clearInterval(pollTimer);
                    pollTimer = setInterval(loadThread, 4000);
                };

                function loadThreadList() {
                    fetch('chat_api.php?action=threads', { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if (!data) return;
                            threadList.innerHTML = data.threads_html;
                            if (badge) {
                                badge.textContent = data.unread > 99 ? '99+' : data.unread;
                                badge.style.display = data.unread > 0 ? 'flex' : 'none';
                            }
                        })
                        .catch(() => {});
                }

                function loadThread() {
                    if (!activeCustomerId) return;
                    fetch('chat_api.php?action=thread&customer_id=' + activeCustomerId, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.messages_html) return;
                            msgs.innerHTML = data.messages_html;
                            msgs.scrollTop = msgs.scrollHeight;
                            if (badge) {
                                // Re-check the global count too, since reading this thread may have cleared it.
                                fetch('chat_api.php?action=badge', { credentials: 'same-origin' })
                                    .then(r => r.json())
                                    .then(d => {
                                        if (!d) return;
                                        badge.textContent = d.unread > 99 ? '99+' : d.unread;
                                        badge.style.display = d.unread > 0 ? 'flex' : 'none';
                                    })
                                    .catch(() => {});
                            }
                        })
                        .catch(() => {});
                }

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const text = input.value.trim();
                    const hasFile = attachInput.files.length > 0;
                    if ((!text && !hasFile) || !activeCustomerId) return;
                    const btn = form.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    input.disabled = true;
                    const fd = new FormData();
                    fd.append('customer_id', activeCustomerId);
                    fd.append('message', text);
                    if (hasFile) fd.append('attachment', attachInput.files[0]);
                    fetch('chat_api.php?action=send', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.ok) {
                            input.value = '';
                            clearAdminChatAttachment();
                            msgs.innerHTML = data.messages_html;
                            msgs.scrollTop = msgs.scrollHeight;
                        } else if (data && data.error) {
                            uiAlert ? uiAlert(data.error) : alert(data.error);
                        }
                    })
                    .catch(() => {})
                    .finally(() => {
                        btn.disabled = false;
                        input.disabled = false;
                        input.focus();
                    });
                });

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (form.requestSubmit) form.requestSubmit();
                        else form.dispatchEvent(new Event('submit', { cancelable: true }));
                    }
                });

                // Lightweight global badge poll, independent of drawer open state.
                function pollAdminChatBadge() {
                    if (drawerIsOpen) return; // the list/thread polls above already keep the badge live
                    fetch('chat_api.php?action=badge', { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !badge) return;
                            badge.textContent = data.unread > 99 ? '99+' : data.unread;
                            badge.style.display = data.unread > 0 ? 'flex' : 'none';
                        })
                        .catch(() => {});
                }
                setInterval(pollAdminChatBadge, 4000);
            })();
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