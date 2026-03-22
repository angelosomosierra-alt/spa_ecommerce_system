<?php
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
            background: #FAF3E8;
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
        .header-search input::placeholder { color: rgba(255,255,255,.6); }
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

        /* ── Search results dropdown ── */
        .search-results {
            position: absolute; top: calc(100% + 8px); left: 0; right: 0;
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
        .search-result-item {
            display: flex; align-items: center; gap: .75rem;
            padding: .65rem 1rem; text-decoration: none; color: #3B2A1A;
            transition: background .15s;
            border-bottom: 1px solid #f5ede4;
        }
        .search-result-item:last-child { border-bottom: none; }
        .search-result-item:hover { background: #fdf5ec; }
        .search-result-img {
            width: 42px; height: 42px; border-radius: 7px;
            object-fit: cover; flex-shrink: 0;
            border: 1px solid #EAD8C0;
        }
        .search-result-noimg {
            width: 42px; height: 42px; border-radius: 7px;
            background: #fdf5ec; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
        }
        .search-result-info { flex: 1; min-width: 0; }
        .search-result-name {
            font-size: .85rem; font-weight: 600; color: #3B2A1A;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .search-result-meta {
            font-size: .72rem; color: #9a7c68; margin-top: 1px;
        }
        .search-result-price {
            font-size: .82rem; font-weight: 700; color: #C96A2C;
            flex-shrink: 0; white-space: nowrap;
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
        }
        @media (max-width: 480px) {
            .header-search { display: none; }
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
?>

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

        <div class="logo">R E C O V E R Y</div>

        <ul class="nav-links">
            <li><a href="index.php"          <?php echo $current_page==='index.php'        ?'class="active"':''; ?>>Home</a></li>
            <li><a href="index.php#services"  <?php echo $current_page==='services.php'     ?'class="active"':''; ?>>Services</a></li>
            <li><a href="index.php#products"  <?php echo $current_page==='products.php'     ?'class="active"':''; ?>>Products</a></li>
            <?php if (isset($_SESSION['user_id'])): ?>
                <li><a href="appointments.php" <?php echo $current_page==='appointments.php' ?'class="active"':''; ?>>Appointments</a></li>
            <?php endif; ?>
        </ul>

        <div class="auth-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <!-- Search bar -->
                <div class="header-search" id="headerSearch">
                    <span class="search-icon">🔍</span>
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
                <a href="#"
                   class="cart-icon-btn"
                   id="cartIconBtn"
                   onclick="event.preventDefault(); openCartSlide();"
                   title="View Cart">
                    🛒
                    <span class="cart-icon-badge" id="cartIconBadge"
                          style="<?php echo $cart_count===0 ? 'display:none' : ''; ?>">
                        <?php echo $cart_count > 99 ? '99+' : $cart_count; ?>
                    </span>
                </a>
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
    <div class="psb-footer">
        <a href="auth.php?logout=1" class="psb-logout">
            <span>🚪</span> Log Out
        </a>
    </div>

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

    // ── 1. Qty stepper buttons (− / +) ───────────────────────────────────────
    c.querySelectorAll('.cart-stepper button').forEach(btn => {
        btn.addEventListener('click', function() {
            // Find the input sibling inside the same .cart-stepper
            const stepper = this.closest('.cart-stepper');
            const input   = stepper.querySelector('input[type="number"]');
            if (!input) return;
            const max = parseInt(input.max) || 9999;
            const val = parseInt(input.value) || 1;
            input.value = this.textContent.trim() === '−'
                ? Math.max(1, val - 1)
                : Math.min(max, val + 1);
            drawerOnQtyChange(input);
        });
    });

    // ── 2. Qty number inputs (direct typing) ─────────────────────────────────
    c.querySelectorAll('.cart-stepper input[type="number"]').forEach(input => {
        input.addEventListener('input', function() { drawerOnQtyChange(this); });
        // Allow focus / click so user can type
        input.removeAttribute('readonly');
        input.style.pointerEvents = 'auto';
        input.style.userSelect    = 'text';
    });

    // ── 3. Checkboxes ────────────────────────────────────────────────────────
    const masterCb = c.querySelector('#selectAll');
    c.querySelectorAll('.item-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            const pid  = this.dataset.pid;
            const card = c.querySelector('#card_' + pid);
            if (card) card.classList.toggle('dimmed', !this.checked);
            drawerSyncMaster(c);
            drawerUpdateTotals(c);
        });
    });
    if (masterCb) {
        masterCb.addEventListener('change', function() {
            c.querySelectorAll('.item-checkbox').forEach(cb => {
                cb.checked = this.checked;
                const card = c.querySelector('#card_' + cb.dataset.pid);
                if (card) card.classList.toggle('dimmed', !this.checked);
            });
            drawerUpdateTotals(c);
        });
    }

    // ── 4. Remove links ───────────────────────────────────────────────────────
    c.querySelectorAll('a.cart-item-del').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Remove this item from your cart?')) return;
            fetch(this.href, { credentials: 'same-origin' })
            .then(() => { loadCartContent(); updateBadge(); });
        });
    });

    // ── 5. Continue Shopping ──────────────────────────────────────────────────
    c.querySelectorAll('a.btn-continue').forEach(a => {
        a.addEventListener('click', function(e) { e.preventDefault(); closeCartSlide(); });
    });

    // ── 6. Update Cart button ─────────────────────────────────────────────────
    const updateBtn = c.querySelector('button[name="update_quantities"]');
    if (updateBtn) {
        updateBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const form     = c.querySelector('#cartForm');
            const formData = new FormData(form);
            formData.append('update_quantities', '1');

            // Show loading state on button
            this.textContent = '⏳ Updating…';
            this.disabled    = true;

            fetch('cart.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(() => {
                drawerQtyDirty = false; // ← allow checkout now
                loadCartContent();
                updateBadge();
            })
            .catch(() => { loadCartContent(); });
        });
    }

    // ── 7. Checkout button ────────────────────────────────────────────────────
    const checkoutBtn = c.querySelector('button[name="checkout_selected"]');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function(e) {
            e.preventDefault();

            // Validate: at least one item checked
            const anyChecked = [...c.querySelectorAll('.item-checkbox')].some(cb => cb.checked);
            if (!anyChecked) {
                alert('Please select at least one item to checkout.');
                return;
            }

            // Validate: no dirty quantities
            if (drawerQtyDirty) {
                alert("You changed some quantities. Please click 'Update Cart' first.");
                return;
            }

            // Build a real form and submit normally for full-page navigation
            const form     = c.querySelector('#cartForm');
            const formData = new FormData(form);
            formData.append('checkout_selected', '1');

            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'cart.php';
            for (const [k, v] of formData.entries()) {
                const i   = document.createElement('input');
                i.type    = 'hidden';
                i.name    = k;
                i.value   = v;
                f.appendChild(i);
            }
            document.body.appendChild(f);
            f.submit();
        });
    }

    // Init totals
    drawerUpdateTotals(c);
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
                    <div class="search-result-meta">Service · ${s.session_time ?? ''}min</div>
                </div>
                <div class="search-result-price">₱${parseFloat(s.price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            </a>`;
        });
    }

    results.innerHTML = html;
}

function highlight(text, query) {
    const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return text.replace(re, '<mark style="background:#fff3cd;color:#3B2A1A;border-radius:2px;padding:0 1px">$1</mark>');
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

// Close search on outside click
document.addEventListener('click', function(e) {
    const search = document.getElementById('headerSearch');
    if (search && !search.contains(e.target)) closeSearchResults();
});
</script>