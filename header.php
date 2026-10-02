<?php
/**
 * header.php — shared PUBLIC customer header.
 * Included by index.php, services.php, products.php (all work whether the
 * visitor is logged in or not). This is intentionally separate from
 * user/header.php, which requires login (redirect_if_not_user()) and is used
 * only by the account-scoped pages (cart, checkout, profile, appointments) —
 * those are untouched by this redesign.
 *
 * Expects, set by the including page before require:
 *   $page_title      (string, optional)
 *   $active_page     ('home'|'services'|'products', optional, for nav highlight)
 *   $page_extra_css  (string, optional, raw CSS printed in a <style> tag)
 */
if (!isset($conn)) { require_once __DIR__ . '/config.php'; }

$page_title  = $page_title  ?? 'Recovery Iloilo';
$active_page = $active_page ?? '';

$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    if (empty($_SESSION['cart'])) {
        $_SESSION['cart'] = load_cart_from_db($conn, $_SESSION['user_id']);
    }
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += isset($item['quantity']) ? intval($item['quantity']) : 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <link rel="stylesheet" href="assets/responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/responsive.css'); ?>">
    <script src="assets/ui-modal.js?v=<?php echo filemtime(__DIR__ . '/assets/ui-modal.js'); ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;700&display=swap" rel="stylesheet">
    <style>
    .logo { display:flex; align-items:center; gap:0.75rem; }
    .logo span { font-family:'Cormorant Garamond',serif; font-size:1.2rem; color:#C8A46B; letter-spacing:0.08em; }
    .cart-icon-btn {
        position:relative; display:inline-flex; align-items:center; justify-content:center;
        width:40px; height:40px; border-radius:50%;
        background:rgba(255,255,255,0.12); color:#FAF3E8; text-decoration:none; font-size:1.1rem;
        transition:background .2s, transform .2s; vertical-align:middle;
    }
    .cart-icon-btn:hover { background:rgba(255,255,255,0.32); transform:scale(1.1); }
    .cart-icon-badge {
        position:absolute; top:-4px; right:-4px;
        background:#e74c3c; color:#fff; font-size:.68rem; font-weight:700;
        min-width:18px; height:18px; border-radius:9px; display:flex;
        align-items:center; justify-content:center; padding:0 3px;
    }
    .header-search { position:relative; display:flex; align-items:center; flex:1; max-width:300px; min-width:0; }
    .header-search input {
        width:100%; padding:.5rem .5rem .5rem 2.3rem;
        border:1.5px solid rgba(255,255,255,.35); border-radius:20px;
        background:rgba(255,255,255,.18); color:#fff; font-size:.95rem;
        outline:none; font-family:inherit; transition:background .2s, border-color .2s;
    }
    .header-search input::placeholder { color:rgba(255,255,255,.7); }
    .header-search input:focus { background:rgba(255,255,255,.28); border-color:rgba(255,255,255,.8); }
    .header-search .search-icon { position:absolute; left:.7rem; display:flex; align-items:center; opacity:.85; pointer-events:none; }
    .header-search .search-icon svg { color:#fff; }
    .header-search .search-clear {
        position:absolute; right:.6rem; background:none; border:none; cursor:pointer;
        font-size:.85rem; color:rgba(255,255,255,.8); display:none; padding:.3rem; line-height:1;
    }
    .search-results {
        position:absolute; top:calc(100% + 10px); left:0; right:0;
        background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,.2);
        overflow:hidden; z-index:8000; display:none; max-height:420px; overflow-y:auto;
    }
    .search-results.open { display:block; }
    .search-results-label { padding:.6rem 1rem .3rem; font-size:.78rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#8A6A22; }
    .search-result-item {
        display:flex; align-items:center; gap:.85rem; padding:.75rem 1rem;
        text-decoration:none; color:#3B2A1A; border-bottom:1px solid #f5ede4; transition:background .15s;
    }
    .search-result-item:last-child { border-bottom:none; }
    .search-result-item:hover { background:#fdf5ec; }
    .search-result-img, .search-result-noimg {
        width:52px; height:52px; border-radius:8px; object-fit:cover; flex-shrink:0; border:1px solid #EAD8C0;
    }
    .search-result-noimg { background:linear-gradient(135deg,#fdf5ec,#EAD8C0); display:flex; align-items:center; justify-content:center; font-size:1.4rem; }
    .search-result-info { flex:1 1 auto; min-width:0; }
    .search-result-name { font-size:.95rem; font-weight:700; color:#3B2A1A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .search-result-meta { font-size:.8rem; color:#5F5B52; margin-top:2px; }
    .search-result-price { font-size:.92rem; font-weight:700; color:#A94F1D; flex-shrink:0; padding-left:.75rem; }
    .search-no-results, .search-loading { padding:1.5rem 1rem; text-align:center; font-size:.9rem; color:#5F5B52; }
    /* Match this codebase's existing header-search breakpoints (assets/style.css
       already shrinks it at 960/768px and hides it only at 480px for
       user/header.php's pages) instead of inventing a different cutoff. */
    @media (max-width: 960px) { .header-search { max-width: 200px; } }
    @media (max-width: 768px) { .header-search { max-width: 150px; } }
    @media (max-width: 480px) { .header-search { display: none; } }
    /* ── Accessibility base: scoped to index.php/services.php/products.php
       only (this header), not the whole site — user/header.php's pages
       (cart, checkout, profile, appointments) are untouched by this
       redesign and must keep their own tuned sizing. ─────────────────── */
    html { font-size: 18px; } /* base 18px, not 14-16px — larger throughout via rem */
    body { line-height: 1.6; font-weight: 400; }
    h1, h2, h3, h4 { line-height: 1.25; }
    p, li, label, td, th { font-weight: 400; }
    .nav-links a, .footer-col ul li a { padding: 0.15rem 0; display: inline-block; }
    button, .btn-book-grid, .btn-cart-grid, .btn-send, .hero-btn-primary, .hero-btn-outline {
        min-height: 44px;
    }
    <?php if (!empty($page_extra_css)) echo $page_extra_css; ?>
    </style>
</head>
<body>

<div class="nav-mobile-overlay" id="navMobileOverlay" onclick="closeNavDrawer()"></div>

<div class="nav-mobile-drawer" id="navMobileDrawer">
    <div class="nav-drawer-header">
        <span class="nav-drawer-logo">Recovery Iloilo</span>
        <button class="nav-drawer-close" onclick="closeNavDrawer()" aria-label="Close menu">✕</button>
    </div>
    <div class="nav-drawer-links">
        <a href="index.php">🏠 Home</a>
        <a href="services.php">💆 Services</a>
        <a href="products.php">🛍️ Products</a>
        <a href="index.php#about">ℹ️ About Us</a>
        <a href="index.php#contact">📞 Contact</a>
        <div class="nav-drawer-divider"></div>
        <?php if (isset($_SESSION['user_id'])): ?>
        <a href="user/cart.php">🛒 Cart (<?php echo $cart_count; ?>)</a>
        <a href="user/appointments.php">📅 My Appointments</a>
        <a href="user/profile.php">👤 My Profile</a>
        <a href="user/auth.php?logout=1" style="color:#ff8a8a;">🚪 Logout</a>
        <?php else: ?>
        <a href="user/auth.php">🔑 Login / Register</a>
        <?php endif; ?>
    </div>
</div>

<header>
    <nav>
        <div class="logo">
            <img src="img/logo.png" width="60" height="48" alt="Recovery Iloilo logo">
            <span>RECOVERY ILOILO</span>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" <?php echo $active_page === 'home' ? 'class="active" aria-current="page"' : ''; ?>>Home</a></li>
            <li><a href="services.php" <?php echo $active_page === 'services' ? 'class="active" aria-current="page"' : ''; ?>>Services</a></li>
            <li><a href="products.php" <?php echo $active_page === 'products' ? 'class="active" aria-current="page"' : ''; ?>>Products</a></li>
            <li><a href="index.php#about">About</a></li>
            <li><a href="index.php#contact">Contact</a></li>
        </ul>
        <div class="auth-links">
            <div class="header-search" id="headerSearch">
                <span class="search-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </span>
                <input type="text" id="headerSearchInput" placeholder="Search services &amp; products…" autocomplete="off"
                       aria-label="Search services and products"
                       oninput="headerSearchQuery(this.value)"
                       onfocus="if(this.value.trim()) headerSearchQuery(this.value)"
                       onkeydown="if(event.key==='Escape'){clearHeaderSearch();this.blur();}">
                <button class="search-clear" id="searchClearBtn" onclick="clearHeaderSearch()" title="Clear search" aria-label="Clear search">✕</button>
                <div class="search-results" id="searchResults"></div>
            </div>
            <button class="nav-hamburger" id="navHamburger" onclick="toggleNavDrawer()" aria-label="Open menu">
                <span></span><span></span><span></span>
            </button>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="user/cart.php" class="cart-icon-btn" title="View Cart" aria-label="View cart, <?php echo $cart_count; ?> items">
                    🛒
                    <span class="cart-icon-badge" id="cartIconBadge" style="<?php echo $cart_count === 0 ? 'display:none' : ''; ?>">
                        <?php echo $cart_count > 99 ? '99+' : $cart_count; ?>
                    </span>
                </a>
                <a href="user/auth.php?logout=1" style="font-size:1rem;color:#FAF3E8;text-decoration:none;font-weight:600;">Logout</a>
            <?php else: ?>
                <a href="user/auth.php" style="font-size:1rem;color:#FAF3E8;text-decoration:none;font-weight:600;">Login</a>
                <a href="user/auth.php?register=1" class="hero-btn-primary" style="padding:0.5rem 1.1rem;font-size:0.9rem;">Register</a>
            <?php endif; ?>
        </div>
    </nav>
</header>

<script>
function toggleNavDrawer() {
    var drawer = document.getElementById('navMobileDrawer');
    var overlay = document.getElementById('navMobileOverlay');
    var burger  = document.getElementById('navHamburger');
    var open = drawer.classList.toggle('open');
    overlay.classList.toggle('active', open);
    if (burger) burger.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
}
function closeNavDrawer() {
    document.getElementById('navMobileDrawer').classList.remove('open');
    document.getElementById('navMobileOverlay').classList.remove('active');
    var burger = document.getElementById('navHamburger');
    if (burger) burger.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeNavDrawer(); });

/* ── Public header search (no login required) ─────────────────────────── */
let _searchTimer = null;
function headerSearchQuery(val) {
    var results  = document.getElementById('searchResults');
    var clearBtn = document.getElementById('searchClearBtn');
    clearBtn.style.display = val.trim() ? 'block' : 'none';
    if (!val.trim()) { results.classList.remove('open'); results.innerHTML = ''; return; }
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function() {
        results.innerHTML = '<div class="search-loading">Searching…</div>';
        results.classList.add('open');
        fetch('search.php?q=' + encodeURIComponent(val.trim()), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) { renderSearchResults(data, val.trim()); })
            .catch(function() { results.innerHTML = '<div class="search-no-results">Could not load results.</div>'; });
    }, 280);
}
function escHtml(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function highlight(text, query) {
    var re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return text.replace(re, '<mark>$1</mark>');
}
function renderSearchResults(data, query) {
    var results  = document.getElementById('searchResults');
    var products = data.products || [];
    var services = data.services || [];
    if (products.length === 0 && services.length === 0) {
        results.innerHTML = '<div class="search-no-results">No results for "<strong>' + escHtml(query) + '</strong>"</div>';
        return;
    }
    var html = '';
    if (services.length > 0) {
        html += '<div class="search-results-label">💆 Services</div>';
        services.forEach(function(s) {
            var img = s.image
                ? '<img class="search-result-img" src="uploads/services/' + escHtml(s.image) + '" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
                : '';
            html += '<a href="services.php?category=' + encodeURIComponent(s.category_id || '') + '&item=svc-' + s.id + '" class="search-result-item">'
                + img + '<div class="search-result-noimg" ' + (s.image ? 'style="display:none"' : '') + '>💆</div>'
                + '<div class="search-result-info"><div class="search-result-name">' + highlight(escHtml(s.name), query) + '</div>'
                + '<div class="search-result-meta">Service' + (s.session_time ? ' · ' + s.session_time + ' min' : '') + '</div></div>'
                + '<div class="search-result-price">₱' + parseFloat(s.price).toLocaleString('en-PH',{minimumFractionDigits:2}) + '</div></a>';
        });
    }
    if (products.length > 0) {
        html += '<div class="search-results-label">🛍️ Products</div>';
        products.forEach(function(p) {
            var img = p.image
                ? '<img class="search-result-img" src="uploads/products/' + escHtml(p.image) + '" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
                : '';
            html += '<a href="products.php?category=' + encodeURIComponent(p.category_id || '') + '&item=prd-' + p.id + '" class="search-result-item">'
                + img + '<div class="search-result-noimg" ' + (p.image ? 'style="display:none"' : '') + '>🧴</div>'
                + '<div class="search-result-info"><div class="search-result-name">' + highlight(escHtml(p.name), query) + '</div>'
                + '<div class="search-result-meta">Product' + (p.stock > 0 ? ' · In stock' : ' · Out of stock') + '</div></div>'
                + '<div class="search-result-price">₱' + parseFloat(p.price).toLocaleString('en-PH',{minimumFractionDigits:2}) + '</div></a>';
        });
    }
    results.innerHTML = html;
}
function clearHeaderSearch() {
    var input = document.getElementById('headerSearchInput');
    if (input) input.value = '';
    document.getElementById('searchClearBtn').style.display = 'none';
    var r = document.getElementById('searchResults');
    if (r) { r.classList.remove('open'); r.innerHTML = ''; }
}
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('headerSearch');
    var results = document.getElementById('searchResults');
    if (wrap && results && !wrap.contains(e.target)) { results.classList.remove('open'); }
});
</script>
