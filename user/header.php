<?php

require_once '../config.php';
redirect_if_not_user();
// ─── CART COUNT ───────────────────────────────────────────────────────────────
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    if (empty($_SESSION['cart']) && isset($conn)) {
        $_SESSION['cart'] = load_cart_from_db($conn, $_SESSION['user_id']);
    }
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item) {
            $cart_count += isset($item['quantity']) ? intval($item['quantity']) : 1;
        }
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Spa Ecommerce' : 'Spa Ecommerce'; ?></title>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo filemtime('../assets/style.css'); ?>">
    <style>
        /* ── Cart icon button ── */
        .cart-icon-btn {
            position: relative;
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.18);
            border: none; cursor: pointer;
            font-size: 1.1rem; text-decoration: none; color: inherit;
            transition: background .2s, transform .2s;
            vertical-align: middle;
        }
        .cart-icon-btn:hover {
            background: rgba(255,255,255,0.32);
            transform: scale(1.1);
        }
        .cart-icon-badge {
            position: absolute; top: -4px; right: -4px;
            background: #e74c3c; color: #fff;
            font-size: .6rem; font-weight: 700;
            min-width: 17px; height: 17px; padding: 0 3px;
            border-radius: 50px;
            display: flex; align-items: center; justify-content: center;
            line-height: 1;
        }

        /* ── Overlay ── */
        #cartSlideOverlay {
            display: none;
            position: fixed; inset: 0; z-index: 9998;
            background: rgba(0,0,0,.45);
            backdrop-filter: blur(2px);
            animation: fadeIn .3s ease;
        }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }

        /* ── Slide-in drawer ── */
        #cartSlideDrawer {
            display: none;
            position: fixed; top: 0; right: 0; bottom: 0; z-index: 9999;
            width: 520px; max-width: 100vw;
            background: #fff;
            box-shadow: -6px 0 40px rgba(0,0,0,.15);
            flex-direction: column;
            animation: slideIn .38s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        #cartSlideDrawer.open {
            display: flex;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); }
            to   { transform: translateX(0); }
        }

        /* ── Drawer header bar ── */
        .csd-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.4rem;
            background: #433724;
            border-bottom: 2px solid #EAD8C0;
            flex-shrink: 0;
        }
        .csd-header h2 {
            font-size: 1.05rem; font-weight: 700;
            color: #3B2A1A; margin: 0;
        }
        .csd-close {
            width: 32px; height: 32px; border-radius: 50%;
            border: 1px solid #EAD8C0; background: #fff;
            cursor: pointer; font-size: .95rem; color: #5a4030;
            display: flex; align-items: center; justify-content: center;
            transition: all .2s;
        }
        .csd-close:hover { background: #fde8e8; color: #c0392b; transform: rotate(90deg); }

        /* ── Loading spinner ── */
        .csd-loading {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 1rem; color: #9a7c68; font-size: .9rem;
        }
        .csd-spinner {
            width: 36px; height: 36px; border-radius: 50%;
            border: 3px solid #EAD8C0;
            border-top-color: #C96A2C;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Cart content inside drawer ── */
        #cartSlideContent {
            flex: 1; overflow-y: auto;
            padding: 1.25rem 1.4rem;
        }
        #cartSlideContent::-webkit-scrollbar { width: 5px; }
        #cartSlideContent::-webkit-scrollbar-thumb { background: rgba(201,106,44,.25); border-radius: 4px; }

        /* Scope cart.php styles inside drawer — override full-page layout */
        #cartSlideContent .container {
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        #cartSlideContent h1 {
            font-size: 1rem !important;
            margin: 0 0 1rem 0 !important;
        }
        #cartSlideContent .cart-container {
            flex-direction: column !important;
            gap: 1.25rem !important;
        }
        #cartSlideContent .cart-items { flex: unset !important; }
        #cartSlideContent .cart-summary {
            position: static !important;
            flex: unset !important;
        }
        /* Make table scrollable on small screens */
        #cartSlideContent table {
            font-size: .82rem !important;
        }
        #cartSlideContent table img {
            width: 48px !important; height: 48px !important;
        }
        /* Fix remove button redirecting to cart.php — keep in drawer */
        #cartSlideContent .btn-danger { font-size: .78rem !important; }

        /* ── Profile avatar button ── */
        .profile-avatar-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.22);
            border: 2px solid rgba(255,255,255,0.4);
            cursor: pointer; font-size: 1rem; color: inherit;
            font-weight: 700; text-decoration: none;
            transition: background .2s, transform .2s;
            flex-shrink: 0; overflow: hidden;
        }
        .profile-avatar-btn:hover {
            background: rgba(255,255,255,0.38);
            transform: scale(1.08);
        }
        .profile-avatar-btn img {
            width: 100%; height: 100%; object-fit: cover;
        }

        /* ── Profile sidebar overlay ── */
        #profileSideOverlay {
            display: none;
            position: fixed; inset: 0; z-index: 10998;
            background: rgba(0,0,0,.4);
            backdrop-filter: blur(2px);
            animation: fadeIn .25s ease;
        }

        /* ── Profile sidebar panel (slides from LEFT) ── */
        #profileSidePanel {
            display: none;
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 10999;
            width: 280px; max-width: 85vw;
            background: #fff;
            box-shadow: 4px 0 32px rgba(0,0,0,.14);
            flex-direction: column;
            justify-content: space-between;
            animation: slideInLeft .35s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        #profileSidePanel.open { display: flex; }
        @keyframes slideInLeft {
            from { transform: translateX(-100%); }
            to   { transform: translateX(0); }
        }

        /* Sidebar header */
        .psb-head {
            padding: 1.1rem 1.25rem 1rem;
            background: linear-gradient(135deg, #3B2A1A, #5a4030);
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; flex-shrink: 0;
        }
        .psb-user-info { flex: 1; min-width: 0; }
        .psb-username {
            font-size: .95rem; font-weight: 700; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .psb-email {
            font-size: .72rem; color: rgba(255,255,255,.6);
            margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .psb-close {
            width: 28px; height: 28px; border-radius: 50%; border: none;
            background: rgba(255,255,255,.15); color: #fff;
            cursor: pointer; font-size: .85rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: background .2s, transform .2s;
        }
        .psb-close:hover { background: rgba(255,255,255,.28); transform: rotate(90deg); }

        /* Sidebar nav links */
        .psb-nav {
            flex: 1; overflow-y: auto; padding: .75rem 0;
            display: flex; flex-direction: column;
        }
        .psb-nav a {
            display: flex; align-items: center; gap: .75rem;
            padding: .85rem 1.4rem;
            font-size: .88rem; font-weight: 500; color: #3B2A1A;
            text-decoration: none;
            transition: background .15s, color .15s;
            border-left: 3px solid transparent;
            width: 100%;
        }
        .psb-nav a:hover {
            background: #fdf5ec;
            color: #C96A2C;
            border-left-color: #C96A2C;
        }
        .psb-nav a.active {
            background: #fdf5ec; color: #C96A2C;
            border-left-color: #C96A2C; font-weight: 600;
        }
        .psb-nav a .psb-ico { font-size: 1.05rem; width: 22px; text-align: center; flex-shrink: 0; }
        .psb-divider { height: 1px; background: #f0e8de; margin: .4rem 1.25rem; }

        /* Logout at bottom */
        .psb-footer { padding: .75rem .85rem 1.25rem; flex-shrink: 0; }
        .psb-logout {
            display: flex; align-items: center; gap: .75rem;
            width: 100%; padding: .75rem 1rem;
            background: #fdecea; color: #c0392b;
            border: 1px solid #f5c2c7; border-radius: 8px;
            font-size: .88rem; font-weight: 600; cursor: pointer;
            text-decoration: none;
            transition: background .2s;
        }
        .psb-logout:hover { background: #f8d7da; }

        /* auth-links alignment */
        .auth-links {
            display: flex !important;
            align-items: center;
            gap: .75rem;
        }

        /* ── Search bar ── */
        .header-search {
            position: relative;
            display: flex; align-items: center;
            flex: 1; max-width: 320px; min-width: 0;
        }
        .header-search input {
            width: 100%; padding: .42rem .42rem .42rem 2.2rem;
            border: 1.5px solid rgba(255,255,255,.35);
            border-radius: 20px;
            background: rgba(255,255,255,.18);
            color: inherit; font-size: .85rem;
            outline: none; font-family: inherit;
            transition: background .2s, border-color .2s;
        }
        .header-search input::placeholder { color: rgba(255, 255, 255, 0.6); }
        .header-search input:focus {
            background: rgba(255,255,255,.28);
            border-color: rgba(255,255,255,.7);
        }
        .header-search .search-icon {
            position: absolute; left: .65rem;
            font-size: .85rem; pointer-events: none;
            opacity: .7;
        }
        .header-search .search-clear {
            position: absolute; right: .6rem;
            background: none; border: none; cursor: pointer;
            font-size: .8rem; color: rgba(255,255,255,.7);
            display: none; padding: 0; line-height: 1;
        }
        .header-search .search-clear:hover { color: #fff; }
        .header-search .search-icon {
    position: absolute; 
    left: .65rem;
    pointer-events: none;
    opacity: .7;
    /* --- Add these for the SVG --- */
    display: flex;
    align-items: center;
    top: 50%;
    transform: translateY(-50%); /* This keeps it exactly in the middle vertically */
}

/* This ensures the SVG color inherits the white/glassy look of your header */
.header-search .search-icon svg {
    display: block;
    color: #fff; 
}

        /* ── Search results dropdown ── */
        .search-results {
            position: absolute; top: calc(100% + 8px); right: 0; left: auto;
            min-width: 340px;
            max-width: min(480px, calc(100vw - 1rem));
            background: #fff; border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.15);
            overflow: hidden; z-index: 8000;
            display: none; max-height: 420px; overflow-y: auto;
        }
        .search-results.open { display: block; }
        .search-results::-webkit-scrollbar { width: 4px; }
        .search-results::-webkit-scrollbar-thumb { background: #EAD8C0; border-radius: 4px; }

        .search-results-label {
            padding: .5rem 1rem .3rem;
            font-size: .7rem; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: #9a7c68;
        }
        /* ── Search result rows ──────────────────────────────────────────────────
           .search-result-item is an <a> inside .auth-links, so assets/style.css
           rule ".auth-links a { background:#C96A2C; color:#FAF3E8 }" would win.
           We bump specificity with .auth-links .search-result-item to override it.
        ── */
        .search-result-item,
        .auth-links .search-result-item,
        .auth-links .search-result-item:visited {
            display: flex; align-items: center; gap: .75rem;
            padding: .7rem 1rem; text-decoration: none;
            color: #3B2A1A; background: transparent;
            border-radius: 0;
            transition: background .15s;
            border-bottom: 1px solid #f5ede4;
            font-weight: normal;
        }
        .search-result-item:last-child { border-bottom: none; }

        /* Light cream hover — override .auth-links a:hover { background:#A94F1D } */
        .search-result-item:hover,
        .auth-links .search-result-item:hover {
            background: #fdf5ec;
            color: #3B2A1A;
        }

        .search-result-img {
            width: 48px; height: 48px; border-radius: 8px;
            object-fit: cover; flex-shrink: 0;
            border: 1px solid #EAD8C0;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .search-result-noimg {
            width: 48px; height: 48px; border-radius: 8px;
            background: linear-gradient(135deg, #fdf5ec, #EAD8C0);
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
        }
        .search-result-info { flex: 1 1 auto; min-width: 0; overflow: hidden; }
        .search-result-name {
            font-size: .85rem; font-weight: 600; color: #3B2A1A;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            background: none;
        }
        .search-result-meta {
            font-size: .72rem; color: #9a7c68; margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .search-result-price {
            font-size: .82rem; font-weight: 700; color: #C96A2C;
            flex-shrink: 0; white-space: nowrap; text-align: right;
            padding-left: .75rem;
        }

        /* Soft yellow marker — inline only, must not push the ellipsis in early */
        .search-results mark {
            background: #fff3cd; color: #3B2A1A;
            padding: 0 2px; border-radius: 3px; font-weight: 700;
            display: inline;
        }

        .search-no-results {
            padding: 1.5rem 1rem; text-align: center;
            font-size: .85rem; color: #9a7c68;
        }
        .search-loading {
            padding: 1rem; text-align: center;
            font-size: .8rem; color: #9a7c68;
        }

        @media (max-width: 680px) {
            .header-search { max-width: 160px; }
            .search-results { min-width: min(92vw, 340px); }
            .search-result-img,
            .search-result-noimg { width: 40px; height: 40px; }
            .search-result-name  { font-size: .82rem; }
            .search-result-price { font-size: .78rem; }
        }
        @media (max-width: 480px) {
            .header-search { display: none; }
        }

        .logo {
    display: flex;           /* Turns on the flexbox layout */
    align-items: center;     /* Aligns logo and text vertically in the middle */
    justify-content: center; /* Centers the whole group horizontally */
    gap: 10px;               /* Adds a little space between the image and the text */
    font-weight: bold;       /* Optional: makes the name stand out */
    font-size: 1.5rem;       /* Optional: adjusts text size */
}

/* Ensure the image doesn't have weird bottom margins */
.logo img {
    display: block;
}
    </style>
</head>
<body>

<?php
// Fetch user info for profile sidebar
$_header_user = null;
if (isset($_SESSION['user_id']) && isset($conn)) {
    $__s = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    $__s->bind_param("i", $_SESSION['user_id']);
    $__s->execute();
    $_header_user = $__s->get_result()->fetch_assoc();
    $__s->close();
}
$_display_name  = $_header_user['username'] ?? 'User';
$_display_email = $_header_user['email'] ?? '';
$_avatar_letter = strtoupper(substr($_display_name, 0, 1));

// ── Notification setup ────────────────────────────────────────────────────────
if (isset($_SESSION['user_id']) && isset($conn)) {
    require_once __DIR__ . '/../notify.php';
    $notif_user_id  = $_SESSION['user_id'];
    $notif_unread   = get_unread_count($conn, $notif_user_id);
    $notif_list     = get_notifications($conn, $notif_user_id, 15);
    // Mark all read if requested
    if (isset($_GET['mark_notif_read'])) {
        mark_all_read($conn, $notif_user_id);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit();
    }
}
?>

<!-- ── MOBILE NAV OVERLAY ──────────────────────────────────── -->
<div class="nav-mobile-overlay" id="navMobileOverlay" onclick="closeNavDrawer()"></div>

<!-- ── MOBILE NAV DRAWER ───────────────────────────────────── -->
<div class="nav-mobile-drawer" id="navMobileDrawer">
    <div class="nav-drawer-header">
        <span class="nav-drawer-logo">Recovery Iloilo</span>
        <button class="nav-drawer-close" onclick="closeNavDrawer()">✕</button>
    </div>
    <div class="nav-drawer-links">
        <a href="index.php" <?php echo $current_page==='index.php' ? 'class="active"' : ''; ?>>🏠 Home</a>
        <a href="index.php#about">ℹ️ About Us</a>
        <a href="index.php#services">💆 Services</a>
        <a href="index.php#products">🛍️ Products</a>
        <a href="index.php#contact">📞 Contact</a>
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="nav-drawer-divider"></div>
        <a href="appointments.php" <?php echo $current_page==='appointments.php' ? 'class="active"' : ''; ?>>📅 Appointments</a>
        <a href="profile.php" <?php echo $current_page==='profile.php' ? 'class="active"' : ''; ?>>👤 My Profile</a>
        <div class="nav-drawer-divider"></div>
        <a href="auth.php?logout=1" style="color:#ff8a8a;">🚪 Logout</a>
        <?php else: ?>
        <div class="nav-drawer-divider"></div>
        <a href="auth.php">🔑 Login / Register</a>
        <?php endif; ?>
    </div>
</div>

<header>
    <nav>

        <?php if (isset($_SESSION['user_id'])): ?>
        <!-- Profile avatar — BEFORE the logo — opens sidebar -->
        <a href="#" class="profile-avatar-btn"
           onclick="event.preventDefault(); openProfileSide();"
           title="My Account">
            <?php echo $_avatar_letter; ?>
        </a>
        <?php endif; ?>

<div class="logo">
    <img src="../img/logo.png" width="75" height="60" alt="Logo">
    <span>RECOVERY ILOILO</span>
</div>
        <ul class="nav-links">
            <li><a href="index.php"           <?php echo $current_page==='index.php'        ?'class="active"':''; ?>>Home</a></li>
            <li><a href="index.php#about"            <?php echo $current_page==='about.php'        ?'class="active"':''; ?>>About Us</a></li>
            <li><a href="index.php#services"   <?php echo $current_page==='services.php'     ?'class="active"':''; ?>>Services</a></li>
            <li><a href="index.php#products"   <?php echo $current_page==='products.php'     ?'class="active"':''; ?>>Products</a></li>
            <li><a href="index.php#contact"          <?php echo $current_page==='contact.php'      ?'class="active"':''; ?>>Contact</a></li>
            <?php if (isset($_SESSION['user_id'])): ?>
                <li><a href="appointments.php" <?php echo $current_page==='appointments.php' ?'class="active"':''; ?>>Appointments</a></li>
            <?php endif; ?>
        </ul>

        <div class="auth-links">
            <!-- Hamburger — mobile only -->
            <button class="nav-hamburger" id="navHamburger" onclick="toggleNavDrawer()" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>

            <?php if (isset($_SESSION['user_id'])): ?>
                <!-- Search bar -->
                <div class="header-search" id="headerSearch">
                    <span class="search-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    </span>
                    <input type="text"
                           id="headerSearchInput"
                           placeholder="Search products & services…"
                           autocomplete="off"
                           oninput="headerSearchQuery(this.value)"
                           onfocus="if(this.value.trim()) headerSearchQuery(this.value)"
                           onkeydown="if(event.key==='Escape'){clearHeaderSearch();this.blur();}">
                    <button class="search-clear" id="searchClearBtn" onclick="clearHeaderSearch()" title="Clear">✕</button>
                    <div class="search-results" id="searchResults"></div>
                </div>

                <!-- Cart icon only — logout moved to sidebar -->
                <a href="cart.php"
                   class="cart-icon-btn"
                   id="cartIconBtn"
                   title="View Cart">
                    🛒
                    <span class="cart-icon-badge" id="cartIconBadge"
                          style="<?php echo $cart_count===0 ? 'display:none' : ''; ?>">
                        <?php echo $cart_count > 99 ? '99+' : $cart_count; ?>
                    </span>
                </a>

                <!-- Notification Bell -->
                <?php if (isset($notif_user_id)): ?>
                <div class="notif-wrap" id="notifWrap" style="position:relative;display:inline-block;">
                    <button class="notif-bell" onclick="toggleNotif(event)"
                            style="background:none;border:none;cursor:pointer;font-size:1.1rem;
                                   padding:0.35rem 0.55rem;border-radius:8px;position:relative;
                                   color:inherit;transition:background 0.15s;"
                            title="Notifications">
                        🔔
                        <?php if ($notif_unread > 0): ?>
                        <span style="position:absolute;top:-2px;right:-4px;background:#dc3545;
                                     color:#fff;font-size:0.6rem;font-weight:700;min-width:16px;
                                     height:16px;border-radius:8px;display:flex;align-items:center;
                                     justify-content:center;padding:0 3px;line-height:1;">
                            <?php echo $notif_unread > 99 ? '99+' : $notif_unread; ?>
                        </span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-panel" id="notifPanel"
                         style="display:none;position:absolute;right:0;top:calc(100% + 8px);
                                width:300px;background:#fff;border-radius:12px;
                                box-shadow:0 8px 32px rgba(0,0,0,0.15);
                                border:1px solid #EAD8C0;z-index:9999;overflow:hidden;">
                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:0.65rem 1rem;background:#3B2A1A;color:#FAF3E8;">
                            <span style="font-weight:600;font-size:0.85rem;">🔔 Notifications</span>
                            <?php if ($notif_unread > 0): ?>
                            <a href="?mark_notif_read=1"
                               style="font-size:0.72rem;color:#C8A46B;text-decoration:none;">
                                Mark all read
                            </a>
                            <?php endif; ?>
                        </div>
                        <div style="max-height:340px;overflow-y:auto;">
                            <?php if (empty($notif_list)): ?>
                            <div style="padding:1.5rem;text-align:center;color:#aaa;font-size:0.82rem;">No notifications yet</div>
                            <?php else: ?>
                            <?php
                            $n_icons = ['order'=>'🛍️','appointment'=>'📅','status'=>'🔔','general'=>'💬'];
                            foreach ($notif_list as $n):
                                $diff = time() - strtotime($n['created_at']);
                                if ($diff < 60)        $n_time = 'Just now';
                                elseif ($diff < 3600)  $n_time = floor($diff/60) . 'm ago';
                                elseif ($diff < 86400) $n_time = floor($diff/3600) . 'h ago';
                                else                   $n_time = date('M d', strtotime($n['created_at']));
                            ?>
                            <a href="<?php echo htmlspecialchars($n['link']); ?>"
                               style="display:flex;align-items:flex-start;gap:0.65rem;
                                      padding:0.65rem 0.9rem;border-bottom:1px solid #EAD8C0;
                                      text-decoration:none;color:inherit;
                                      background:<?php echo $n['is_read'] ? '#fff' : '#fff8f3'; ?>;
                                      transition:background 0.15s;">
                                <span style="font-size:1.2rem;flex-shrink:0;margin-top:1px;">
                                    <?php echo $n_icons[$n['type']] ?? '🔔'; ?>
                                </span>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;font-size:0.82rem;color:#3B2A1A;
                                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo htmlspecialchars($n['title']); ?>
                                    </div>
                                    <div style="font-size:0.75rem;color:#666;line-height:1.4;
                                                display:-webkit-box;-webkit-line-clamp:2;
                                                -webkit-box-orient:vertical;overflow:hidden;">
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
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <script>
                function toggleNotif(e) {
                    e.stopPropagation();
                    const p = document.getElementById('notifPanel');
                    p.style.display = p.style.display === 'block' ? 'none' : 'block';
                }
                document.addEventListener('click', function(e) {
                    const w = document.getElementById('notifWrap');
                    const p = document.getElementById('notifPanel');
                    if (p && w && !w.contains(e.target)) p.style.display = 'none';
                });
                </script>
                <?php endif; ?>
            <?php else: ?>
                <a href="auth.php">Login</a>
                <a href="auth.php?register=1">Register</a>
            <?php endif; ?>
        </div>

    </nav>
</header>

<!-- ══════════════════════════════════════
     PROFILE SIDEBAR (slides from LEFT)
══════════════════════════════════════ -->
<div id="profileSideOverlay" onclick="closeProfileSide()"></div>

<div id="profileSidePanel" role="dialog" aria-label="My Account">

    <!-- Top block: user info + nav links -->
    <div>
        <!-- Minimal header: name + email + close -->
        <div class="psb-head">
            <div class="psb-user-info">
                <div class="psb-username"><?php echo htmlspecialchars($_display_name); ?></div>
                <div class="psb-email"><?php echo htmlspecialchars($_display_email); ?></div>
            </div>
            <button class="psb-close" onclick="closeProfileSide()">✕</button>
        </div>

        <!-- Nav links stacked vertically -->
        <nav class="psb-nav">
            <a href="profile.php" <?php echo $current_page==='profile.php' ? 'class="active"':''; ?>>
                <span class="psb-ico">✏️</span> Edit Profile
            </a>
            <a href="appointments.php" <?php echo $current_page==='appointments.php' ? 'class="active"':''; ?>>
                <span class="psb-ico">📅</span> Appointments
            </a>
        </nav>
    </div>

    <!-- Logout at bottom -->
    <?php
// Works whether header is included from /user/ or root /
$_logout_url = (strpos($_SERVER['PHP_SELF'], '/user/') !== false)
    ? 'auth.php?logout=1'
    : 'user/auth.php?logout=1';
?>
<a href="<?php echo BASE_URL; ?>user/auth.php?logout=1" class="psb-logout">
    <span>🚪</span> Log Out
</a>


</div>

<!-- ══════════════════════════════════════
     SLIDE-IN DRAWER (loads cart.php)
══════════════════════════════════════ -->
<div id="cartSlideOverlay" onclick="closeCartSlide()"></div>

<div id="cartSlideDrawer" role="dialog" aria-label="Shopping Cart">
    <div class="csd-header">
        <h2>🛒 Shopping Cart</h2>
        <button class="csd-close" onclick="closeCartSlide()" title="Close">✕</button>
    </div>
    <div id="cartSlideContent"></div>
</div>

<script>
// ── Profile Sidebar ───────────────────────────────────────────────────────────
function openProfileSide() {
    document.getElementById('profileSideOverlay').style.display = 'block';
    document.getElementById('profileSidePanel').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeProfileSide() {
    document.getElementById('profileSideOverlay').style.display = 'none';
    document.getElementById('profileSidePanel').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeProfileSide(); closeCartSlide(); }
});

// ════════════════════════════════════════════════════════
//  CART SLIDE DRAWER
//  Fully self-contained — does NOT rely on cart.php's JS
// ════════════════════════════════════════════════════════

// ── Open / Close ──────────────────────────────────────────────────────────────
function openCartSlide() {
    document.getElementById('cartSlideOverlay').style.display = 'block';
    document.getElementById('cartSlideDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    loadCartContent();
}
function closeCartSlide() {
    document.getElementById('cartSlideOverlay').style.display = 'none';
    document.getElementById('cartSlideDrawer').classList.remove('open');
    document.body.style.overflow = '';
    // Clear injected cart styles so they don't affect the rest of the page
    document.getElementById('cartSlideContent').innerHTML = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCartSlide(); });

// ── Load cart.php HTML (styles only, NO scripts) ──────────────────────────────
function loadCartContent() {
    const content = document.getElementById('cartSlideContent');

    // Show spinner
    content.innerHTML = '<div class="csd-loading"><div class="csd-spinner"></div>Loading your cart…</div>';

    fetch('cart.php', { credentials: 'same-origin' })
    .then(r => r.text())
    .then(html => {
        const parser = new DOMParser();
        const doc    = parser.parseFromString(html, 'text/html');

        // Always grab .cart-wrap specifically — never fall back to body
        // (falling back to body would pull in the <header> and its CSS)
        const wrap = doc.querySelector('.cart-wrap');

        if (!wrap) {
            content.innerHTML = `<div style="text-align:center;padding:3rem;color:#9a7c68;">
                <p>Could not load cart. <a href="cart.php" style="color:#C96A2C;">Open full page →</a></p></div>`;
            return;
        }

        // Inject cart.php styles BUT strip the header { display:none } rule
        // so it never bleeds into the parent page and hides our real header
        let styleHtml = '';
        doc.querySelectorAll('style').forEach(s => {
            const cleaned = s.textContent
                .replace(/header\s*\{[^}]*display\s*:\s*none[^}]*\}/gi, '')
                .replace(/header\s*\{[^}]*\}/gi, '');
            if (cleaned.trim()) styleHtml += `<style>${cleaned}</style>`;
        });

        content.innerHTML = styleHtml + wrap.outerHTML;

        // Remove any <script> tags — drawer manages all JS itself
        content.querySelectorAll('script').forEach(s => s.remove());

        // Boot drawer-owned interactivity
        drawerBind();
    })
    .catch(() => {
        content.innerHTML = `<div style="text-align:center;padding:3rem;color:#9a7c68;">
            <p>Could not load cart. <a href="cart.php" style="color:#C96A2C;">Open full page →</a></p></div>`;
    });
}

// ── Bind ALL interactivity after HTML is injected ─────────────────────────────
function drawerBind() {
    const c = document.getElementById('cartSlideContent');
    const form = c.querySelector('#cartForm');
    if (!c || !form) return;

    // 1. Handle Stepper Buttons (+ / -)
    c.querySelectorAll('.cart-stepper button').forEach(btn => {
        btn.type = "button"; // Force type to prevent reload
        btn.onclick = function(e) {
            e.preventDefault();
            const input = this.parentElement.querySelector('input');
            const delta = this.textContent === '+' ? 1 : -1;
            let val = parseInt(input.value) + delta;
            if (val < 1) val = 1;
            input.value = val;
            
            // Trigger the math update
            updateDrawerMath(input);
        };
    });

    // 2. Handle Manual Input
    c.querySelectorAll('.cart-stepper input').forEach(input => {
        input.oninput = function() { updateDrawerMath(this); };
    });

    // 3. Handle Checkbox Changes
    c.querySelectorAll('.item-checkbox').forEach(cb => {
        cb.onchange = function() {
            updateDrawerMath(null);
            const card = document.getElementById('card_' + this.dataset.pid);
            if (card) card.style.opacity = this.checked ? "1" : "0.4";
        };
    });

    // 4. Handle AJAX Save (The "Update Cart" button)
    form.onsubmit = function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnUpdate');
        btn.textContent = '⏳ Saving...';

        const formData = new FormData(this);
        formData.append('update_quantities', '1');

        fetch('cart.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                btn.textContent = '✅ Saved';
                btn.disabled = true;
                btn.classList.remove('pulse');
                document.getElementById('qtyNotice').style.display = 'none';
                document.getElementById('checkoutBtn').disabled = false;
                document.getElementById('checkoutBtn').textContent = 'Proceed to Checkout';
                setTimeout(() => { btn.textContent = '🔄 Update Cart'; }, 2000);
            }
        });
    };
}
function updateDrawerMath(inputEl) {
    const c = document.getElementById('cartSlideContent');
    
    // Update individual subtotal if an input was changed
    if (inputEl) {
        const pid = inputEl.dataset.pid;
        const price = parseFloat(inputEl.dataset.price);
        const sub = price * parseInt(inputEl.value || 0);
        const subDisplay = document.getElementById('sub_' + pid);
        if (subDisplay) {
            subDisplay.textContent = '₱' + sub.toLocaleString('en-PH', {minimumFractionDigits:2});
        }
    }

    // Update Grand Total
    let grandTotal = 0;
    c.querySelectorAll('.item-checkbox').forEach(cb => {
        if (cb.checked) {
            const pid = cb.dataset.pid;
            const input = document.getElementById('qty_' + pid);
            grandTotal += parseFloat(input.dataset.price) * parseInt(input.value);
        }
    });

    document.getElementById('summary-total').textContent = '₱' + grandTotal.toLocaleString('en-PH', {minimumFractionDigits:2});

    // Show notice and Lock Checkout
    document.getElementById('qtyNotice').style.display = 'block';
    const btnUp = document.getElementById('btnUpdate');
    btnUp.disabled = false;
    btnUp.classList.add('pulse');
    
    const btnCheck = document.getElementById('checkoutBtn');
    btnCheck.disabled = true;
    btnCheck.textContent = '⚠️ Update Cart First';
}
// ── Track dirty qty state ─────────────────────────────────────────────────────
let drawerQtyDirty = false;

function drawerOnQtyChange(input) {
    const c      = document.getElementById('cartSlideContent');
    const pid    = input.dataset ? input.dataset.pid : null;
    const stock  = parseInt(input.max) || 9999;
    const price  = parseFloat(input.dataset ? input.dataset.price : 0) || 0;

    // Clamp to stock
    if (parseInt(input.value) > stock) {
        input.value = stock;
        const warn = pid ? c.querySelector('#warn_' + pid) : null;
        if (warn) warn.style.display = 'block';
    } else {
        const warn = pid ? c.querySelector('#warn_' + pid) : null;
        if (warn) warn.style.display = 'none';
    }

    // Update this item's subtotal
    if (pid && price) {
        const sub = c.querySelector('#sub_' + pid);
        if (sub) sub.textContent = '₱' + (price * parseInt(input.value || 1))
            .toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    // Flag dirty + show notice + enable update button
    drawerQtyDirty = true;
    const notice = c.querySelector('#qtyNotice');
    const upBtn  = c.querySelector('button[name="update_quantities"]');
    if (notice) notice.style.display = 'block';
    if (upBtn)  upBtn.disabled = false;

    drawerUpdateTotals(c);
}

// ── Sync master checkbox indeterminate state ──────────────────────────────────
function drawerSyncMaster(c) {
    const all    = [...c.querySelectorAll('.item-checkbox')];
    const master = c.querySelector('#selectAll');
    if (!master) return;
    const allOn  = all.every(cb => cb.checked);
    const noneOn = all.every(cb => !cb.checked);
    master.checked       = allOn;
    master.indeterminate = !allOn && !noneOn;
}

// ── Recalculate and display totals ────────────────────────────────────────────
function drawerUpdateTotals(c) {
    let total = 0, sel = 0;
    const totalItems = c.querySelectorAll('.item-checkbox').length;

    c.querySelectorAll('.item-checkbox').forEach(cb => {
        const pid   = cb.dataset.pid;
        const input = c.querySelector('#qty_' + pid);
        const price = input ? parseFloat(input.dataset.price || 0) : 0;
        const qty   = input ? parseInt(input.value || 1) : 1;
        if (cb.checked) { total += price * qty; sel++; }
    });

    const fmt = n => '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    const elSub   = c.querySelector('#summary-subtotal');
    const elTotal = c.querySelector('#summary-total');
    const elSel   = c.querySelector('#selCount');
    const elBar   = c.querySelector('#selBarCount');

    if (elSub)   elSub.textContent   = fmt(total);
    if (elTotal) elTotal.textContent = fmt(total);
    if (elSel)   elSel.textContent   = sel;
    if (elBar)   elBar.textContent   = sel + ' / ' + totalItems + ' selected';

    const btn = c.querySelector('button[name="checkout_selected"]');
    if (btn) {
        btn.disabled    = sel === 0;
        btn.textContent = sel === 0
            ? 'Select items to checkout'
            : 'Proceed to Checkout (' + sel + ') →';
    }
}

// ── Refresh navbar badge ──────────────────────────────────────────────────────
function updateBadge() {
    fetch('cart.php', { credentials: 'same-origin' })
    .then(r => r.text())
    .then(html => {
        const doc   = new DOMParser().parseFromString(html, 'text/html');
        const items = doc.querySelectorAll('.item-checkbox');
        const badge = document.getElementById('cartIconBadge');
        if (!badge) return;
        badge.textContent   = items.length;
        badge.style.display = items.length > 0 ? 'flex' : 'none';
    });
}

// ════════════════════════════════════════════════════════
//  HEADER SEARCH
// ════════════════════════════════════════════════════════
let _searchTimer = null;

function headerSearchQuery(val) {
    const results  = document.getElementById('searchResults');
    const clearBtn = document.getElementById('searchClearBtn');

    clearBtn.style.display = val.trim() ? 'block' : 'none';

    if (!val.trim()) {
        results.classList.remove('open');
        results.innerHTML = '';
        return;
    }

    // Debounce 280ms
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        results.innerHTML = '<div class="search-loading">Searching…</div>';
        results.classList.add('open');

        fetch('search.php?q=' + encodeURIComponent(val.trim()), {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => renderSearchResults(data, val.trim()))
        .catch(() => {
            results.innerHTML = '<div class="search-no-results">Could not load results.</div>';
        });
    }, 280);
}

function renderSearchResults(data, query) {
    const results = document.getElementById('searchResults');
    const products = data.products || [];
    const services = data.services || [];

    if (products.length === 0 && services.length === 0) {
        results.innerHTML = `<div class="search-no-results">No results for "<strong>${escHtml(query)}</strong>"</div>`;
        return;
    }

    let html = '';

    if (products.length > 0) {
        html += `<div class="search-results-label">🛍️ Products</div>`;
        products.forEach(p => {
            const img = p.image
                ? `<img class="search-result-img" src="../uploads/products/${escHtml(p.image)}" alt="${escHtml(p.name)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
                : '';
            html += `
            <a href="index.php#products" class="search-result-item" onclick="closeSearchResults()">
                ${img}
                <div class="search-result-noimg" ${p.image ? 'style="display:none"' : ''}>🧴</div>
                <div class="search-result-info">
                    <div class="search-result-name">${highlight(escHtml(p.name), query)}</div>
                    <div class="search-result-meta">Product ${p.stock > 0 ? '· In stock' : '· Out of stock'}</div>
                </div>
                <div class="search-result-price">₱${parseFloat(p.price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            </a>`;
        });
    }

    if (services.length > 0) {
        html += `<div class="search-results-label">💆 Services</div>`;
        services.forEach(s => {
            const img = s.image
                ? `<img class="search-result-img" src="../uploads/services/${escHtml(s.image)}" alt="${escHtml(s.name)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
                : '';
            html += `
            <a href="index.php#services" class="search-result-item" onclick="closeSearchResults()">
                ${img}
                <div class="search-result-noimg" ${s.image ? 'style="display:none"' : ''}>💆</div>
                <div class="search-result-info">
                    <div class="search-result-name">${highlight(escHtml(s.name), query)}</div>
                    <div class="search-result-meta">Service${s.session_time ? ` · ${s.session_time} min` : ''}</div>
                </div>
                <div class="search-result-price">₱${parseFloat(s.price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            </a>`;
        });
    }

    results.innerHTML = html;
}

function highlight(text, query) {
    const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return text.replace(re, '<mark>$1</mark>');
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function clearHeaderSearch() {
    const input = document.getElementById('headerSearchInput');
    if (input) input.value = '';
    document.getElementById('searchClearBtn').style.display = 'none';
    closeSearchResults();
}

function closeSearchResults() {
    const r = document.getElementById('searchResults');
    if (r) { r.classList.remove('open'); r.innerHTML = ''; }
}

// ════════════════════════════════════════════════════════
//  MOBILE NAV DRAWER
// ════════════════════════════════════════════════════════
function toggleNavDrawer() {
    const drawer   = document.getElementById('navMobileDrawer');
    const overlay  = document.getElementById('navMobileOverlay');
    const hamburger = document.getElementById('navHamburger');
    const isOpen   = drawer.classList.contains('open');
    if (isOpen) {
        closeNavDrawer();
    } else {
        drawer.classList.add('open');
        overlay.classList.add('active');
        hamburger.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeNavDrawer() {
    const drawer    = document.getElementById('navMobileDrawer');
    const overlay   = document.getElementById('navMobileOverlay');
    const hamburger = document.getElementById('navHamburger');
    drawer.classList.remove('open');
    overlay.classList.remove('active');
    if (hamburger) hamburger.classList.remove('open');
    document.body.style.overflow = '';
}

// Close on ESC
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeNavDrawer();
});
</script>