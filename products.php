<?php
// Shared by products.php and user/products.php (which just requires this
// file), so every path here is absolute (__DIR__ / BASE_URL).
require_once __DIR__ . '/config.php';

$prd_page = (is_logged_in() && !is_admin()) ? BASE_URL . 'user/products.php' : BASE_URL . 'products.php';

// ── add_to_cart: the product sheet posts here; same $_SESSION['cart'] shape
// and DB sync as user/cart.php. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    verify_csrf_token();
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'user/auth.php');
        exit();
    }
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity   = max(1, intval($_POST['quantity'] ?? 1));

    $stmt = $conn->prepare("SELECT id, name, price, image, stock FROM products WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        $flash = "That product is no longer available.";
    } elseif ($quantity > (int)$product['stock']) {
        $flash = "Only {$product['stock']} of \"{$product['name']}\" left in stock.";
    } else {
        if (empty($_SESSION['cart'])) {
            $_SESSION['cart'] = load_cart_from_db($conn, $_SESSION['user_id']);
        }
        $existing_qty = isset($_SESSION['cart'][$product_id]) ? (int)$_SESSION['cart'][$product_id]['quantity'] : 0;
        $new_qty      = min((int)$product['stock'], $existing_qty + $quantity);
        $_SESSION['cart'][$product_id] = [
            'quantity' => $new_qty,
            'price'    => $product['price'],
            'name'     => $product['name'],
            'image'    => $product['image'],
        ];
        sync_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
        $flash = "Added \"{$product['name']}\" to your cart.";
    }
    $_SESSION['products_flash'] = $flash;
    header('Location: ' . (strpos($_SERVER['HTTP_REFERER'] ?? '', '/user/') !== false ? BASE_URL . 'user/products.php' : $prd_page));
    exit();
}

$flash_message = '';
if (!empty($_SESSION['products_flash'])) {
    $flash_message = $_SESSION['products_flash'];
    unset($_SESSION['products_flash']);
}

$products = [];
$res_prd = $conn->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.deleted_at IS NULL ORDER BY c.name, p.name");
while ($row = $res_prd->fetch_assoc()) $products[] = $row;

// Units sold in paid orders over the last 90 days, for sorting and badges.
$sold = [];
try {
    $res = $conn->query("SELECT oi.product_id, SUM(oi.quantity) AS q FROM order_items oi JOIN orders o ON o.id = oi.order_id
                         WHERE oi.product_id IS NOT NULL AND o.payment_status = 'paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                         GROUP BY oi.product_id");
    while ($r = $res->fetch_assoc()) $sold[(int)$r['product_id']] = (int)$r['q'];
} catch (Throwable $e) {}
$top_ids = $sold;
arsort($top_ids);
$top_ids = array_slice(array_keys(array_filter($top_ids)), 0, 3);

$items = [];
$cats  = [];
foreach ($products as $p) {
    $id  = (int)$p['id'];
    $cid = (int)($p['category_id'] ?? 0);
    $items[] = [
        'id' => $id, 'cat' => $cid, 'name' => $p['name'], 'desc' => trim((string)($p['description'] ?? '')),
        'img' => !empty($p['image']) ? BASE_URL . 'uploads/products/' . rawurlencode($p['image']) : '',
        'price' => (float)$p['price'], 'stock' => max(0, (int)$p['stock']),
        'pop' => $sold[$id] ?? 0, 'top' => in_array($id, $top_ids, true),
    ];
    if (!isset($cats[$cid])) $cats[$cid] = ['id' => $cid, 'name' => $p['category_name'] ?: 'Other', 'img' => '', 'n' => 0];
    $cats[$cid]['n']++;
    if ($cats[$cid]['img'] === '' && !empty($p['image'])) $cats[$cid]['img'] = BASE_URL . 'uploads/products/' . rawurlencode($p['image']);
}
$cats = array_values($cats);
usort($cats, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']); });

$requested_category = intval($_GET['category'] ?? 0);
$requested_item     = (string)($_GET['item'] ?? '');

$page_title  = 'Products — Recovery Iloilo';
$page_description = 'Skincare, bath and body products and gift vouchers from Recovery Iloilo, Molo, Iloilo City.';
$active_page = 'products';
require_once defined('HOME_ACCOUNT_AREA') ? __DIR__ . '/user/header.php' : __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/menu.css?v=<?php echo filemtime(__DIR__ . '/assets/menu.css'); ?>">
<style>
/* Products: a photo grid (2 across on phones) instead of the service rows. */
.sv-pgrid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 980px) { .sv-pgrid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .sv-pgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
  .sv-pgrid .sv-card { flex-direction: column; }
  .sv-pgrid .sv-ph { width: auto; min-height: 0; aspect-ratio: 1 / 1; }
  .sv-pgrid .sv-bd { padding: 10px 12px 12px; }
  .sv-pgrid .sv-row { flex-direction: column; align-items: stretch; gap: 8px; }
  .sv-pgrid .sv-go { justify-content: center; }
}
.sv-pgrid .sv-ph { aspect-ratio: 1 / 1; }
.sv-oos { position: absolute; inset: 0; z-index: 1; background: rgba(20,12,6,.55); color: #fff; display: grid; place-items: center; font-weight: 800; }
.sv-stock { font-size: 0.82rem; color: var(--sv-muted); }
.sv-stock.low { color: var(--sv-rust); font-weight: 700; }
.sv-qty { display: flex; align-items: center; gap: 12px; }
.sv-qty button { width: 48px; height: 48px; border-radius: 14px; border: 2px solid var(--sv-line); background: var(--sv-panel); font-size: 1.4rem; font-weight: 800; color: var(--sv-ink); cursor: pointer; }
.sv-qty button:disabled { opacity: .4; cursor: default; }
.sv-qty output { font-size: 1.5rem; font-weight: 800; min-width: 2.2rem; text-align: center; }
.sv-flashmsg { position: fixed; left: 50%; bottom: calc(24px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); z-index: 1100; background: var(--sv-espresso); color: var(--sv-on-dark); padding: 12px 18px; border-radius: 14px; font-weight: 700; max-width: calc(100% - 32px); box-shadow: 0 14px 30px rgba(0,0,0,.3); font-family: var(--sv-body); display: flex; gap: 12px; align-items: center; }
.sv-flashmsg a { color: var(--sv-gold-hi); font-weight: 800; white-space: nowrap; }
</style>

<div class="sv-page">
<div class="sv-top">
    <div class="sv-wrap">
        <span class="sv-eyebrow">Our shop</span>
        <h1>Take the spa <em>home</em></h1>
        <p><?php echo count($items); ?> products in <?php echo count($cats); ?> categories. The same products we use in our treatments.</p>
        <div class="sv-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="svQ" type="search" placeholder="Search a product, e.g. oil" aria-label="Search products" autocomplete="off">
            <button type="button" class="sv-clear" id="svQClear" aria-label="Clear search" hidden>✕</button>
        </div>
    </div>
</div>

<div class="sv-catbar" id="svCatbar">
    <div class="sv-wrap">
        <div class="sv-chips" id="svChips" role="navigation" aria-label="Categories"></div>
        <button type="button" class="sv-allbtn" id="svAllCats" aria-label="Show all categories"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg><span>All</span></button>
    </div>
</div>

<div class="sv-wrap">
    <div class="sv-filters" role="group" aria-label="Filters">
        <button type="button" class="sv-fchip" data-f="instock" aria-pressed="false">In stock</button>
        <button type="button" class="sv-fchip" data-f="cheap" aria-pressed="false">Under ₱300</button>
        <label class="sv-sort">Sort <select id="svSort"><option value="pop">Best sellers</option><option value="low">Price: low to high</option><option value="high">Price: high to low</option><option value="az">Name A–Z</option></select></label>
    </div>
    <div class="sv-count" id="svCount" aria-live="polite"></div>
    <div id="svSections"></div>

    <div class="sv-help">
        <div><h2>Questions about a product?</h2><p>Call or message us and our staff will help you.</p></div>
        <div><p>Phone / Viber</p><a href="tel:+639853359998">+63 985 335 9998</a></div>
    </div>
</div>
</div>

<button type="button" class="sv-totop" id="svTop" aria-label="Back to top" hidden>↑</button>
<div class="sv-scrim" id="svScrim" hidden></div>
<div class="sv-sheet" id="svSheet" role="dialog" aria-modal="true" aria-labelledby="svShTitle" hidden></div>
<?php if ($flash_message !== ''): ?>
<div class="sv-flashmsg" id="svFlash" role="status"><span><?php echo htmlspecialchars($flash_message); ?></span><?php if (is_logged_in()): ?><a href="<?php echo BASE_URL; ?>user/cart.php">View cart</a><?php endif; ?></div>
<?php endif; ?>

<template id="svCartTpl">
<?php if (is_logged_in() && !is_admin()): ?>
    <form method="POST" action="<?php echo BASE_URL; ?>products.php"><?php echo csrf_field(); ?><input type="hidden" name="product_id" value=""><input type="hidden" name="quantity" value="1"><button type="submit" name="add_to_cart" class="sv-book">Add to cart</button></form>
<?php else: ?>
    <a class="sv-book" href="<?php echo BASE_URL; ?>user/auth.php">Log in to buy</a>
<?php endif; ?>
</template>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
(function () {
    var CATS  = <?php echo json_encode($cats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var PRD   = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var REQ_CAT  = <?php echo (int)$requested_category; ?>;
    var REQ_ITEM = <?php echo json_encode($requested_item, JSON_HEX_TAG); ?>;
    var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    var byId = {}; PRD.forEach(function (p) { byId[p.id] = p; });
    var catName = {}; CATS.forEach(function (c) { catName[c.id] = c.name; });

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function stockText(p) { return p.stock <= 0 ? 'Out of stock' : p.stock <= 5 ? 'Only ' + p.stock + ' left' : 'In stock'; }

    var state = { q: '', f: { instock: false, cheap: false }, sort: 'pop' };
    function filtered() { return state.q || state.f.instock || state.f.cheap; }
    function matches(p) {
        var q = state.q.trim().toLowerCase();
        if (q && (p.name + ' ' + (catName[p.cat] || '') + ' ' + p.desc).toLowerCase().indexOf(q) < 0) return false;
        if (state.f.instock && p.stock <= 0) return false;
        if (state.f.cheap && p.price >= 300) return false;
        return true;
    }
    function card(p) {
        var oos = p.stock <= 0;
        return '<button type="button" class="sv-card" id="prd-' + p.id + '" data-id="' + p.id + '"><span class="sv-ph">' +
            (p.img ? '<img src="' + esc(p.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '<span class="sv-nopic" aria-hidden="true">🧴</span>') +
            (oos ? '<span class="sv-oos">Out of stock</span>' : p.top ? '<span class="sv-ribbon">Best seller</span>' : '') + '</span>' +
            '<span class="sv-bd"><span class="sv-name">' + esc(p.name) + '</span>' +
            '<span class="sv-stock' + (!oos && p.stock <= 5 ? ' low' : '') + '">' + stockText(p) + '</span>' +
            '<span class="sv-row"><span class="sv-pr">' + peso(p.price) + '</span><span class="sv-go">' + (oos ? 'View' : 'Buy') + '</span></span></span></button>';
    }
    function render() {
        var list = PRD.filter(matches);
        if (state.sort === 'low') list.sort(function (a, b) { return a.price - b.price; });
        else if (state.sort === 'high') list.sort(function (a, b) { return b.price - a.price; });
        else if (state.sort === 'az') list.sort(function (a, b) { return a.name.localeCompare(b.name); });
        else list.sort(function (a, b) { return (b.stock > 0) - (a.stock > 0) || b.pop - a.pop || a.name.localeCompare(b.name); });
        var html = '', shown = 0;
        CATS.forEach(function (c) {
            var items = list.filter(function (p) { return p.cat === c.id; });
            if (!items.length) return;
            shown += items.length;
            html += '<section class="sv-sec" id="cat-' + c.id + '"><div class="sv-sec-head">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '') +
                '<div><h2>' + esc(c.name) + '</h2><span>' + items.length + ' product' + (items.length > 1 ? 's' : '') + '</span></div></div><div class="sv-grid sv-pgrid">' + items.map(card).join('') + '</div></section>';
        });
        $('svSections').innerHTML = html || '<div class="sv-empty"><b>No products found' + (state.q ? ' for “' + esc(state.q) + '”' : '') + '</b>Try another word or remove a filter.<br><button type="button" id="svReset">Show all products</button></div>';
        $('svCount').textContent = filtered() ? shown + ' of ' + PRD.length + ' products shown' : '';
        drawChips(list);
        spy();
    }
    function drawChips(list) {
        var cur = document.querySelector('.sv-chip[aria-current="true"]');
        var curId = cur ? cur.dataset.go : '';
        $('svChips').innerHTML = CATS.map(function (c) {
            var n = list.filter(function (p) { return p.cat === c.id; }).length;
            if (!n) return '';
            return '<button type="button" class="sv-chip" data-go="cat-' + c.id + '" aria-current="' + (curId === 'cat-' + c.id) + '">' +
                (c.img ? '<img src="' + esc(c.img) + '" alt="" onerror="this.remove()">' : '<i aria-hidden="true">✦</i>') + esc(c.name) + ' <small>' + n + '</small></button>';
        }).join('');
    }

    var hdr = document.querySelector('header');
    function headerH() { var s = hdr ? getComputedStyle(hdr).position : ''; return hdr && (s === 'sticky' || s === 'fixed') ? hdr.offsetHeight : 0; }
    function placeBar() { $('svCatbar').style.top = headerH() + 'px'; }
    placeBar(); addEventListener('resize', placeBar);
    function offset() { return headerH() + $('svCatbar').offsetHeight + 12; }

    var spying = true;
    function jump(id, smooth) {
        var el = $(id); if (!el) return;
        spying = false; setActive(id);
        scrollTo({ top: el.getBoundingClientRect().top + scrollY - offset(), behavior: smooth && !reduce ? 'smooth' : 'auto' });
        setTimeout(function () { spying = true; }, 800);
    }
    function setActive(id) {
        var bar = $('svChips');
        document.querySelectorAll('.sv-chip').forEach(function (c) {
            var on = c.dataset.go === id; c.setAttribute('aria-current', on);
            if (on) bar.scrollTo({ left: c.offsetLeft - bar.offsetLeft - 8, behavior: reduce ? 'auto' : 'smooth' });
        });
    }
    function spy() {
        $('svTop').hidden = scrollY < 900;
        if (!spying) return;
        var secs = document.querySelectorAll('.sv-sec'), id = '', lim = offset() + 20;
        secs.forEach(function (s) { if (s.getBoundingClientRect().top < lim) id = s.id; });
        if (!id && secs[0]) id = secs[0].id;
        var cur = document.querySelector('.sv-chip[aria-current="true"]');
        if (id && (!cur || cur.dataset.go !== id)) setActive(id);
    }
    addEventListener('scroll', function () { requestAnimationFrame(spy); }, { passive: true });

    function resetAll() {
        state.q = ''; state.f = { instock: false, cheap: false };
        $('svQ').value = ''; $('svQClear').hidden = true;
        document.querySelectorAll('.sv-fchip').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
        render();
    }
    $('svChips').addEventListener('click', function (e) { var b = e.target.closest('.sv-chip'); if (b) jump(b.dataset.go, true); });
    $('svQ').addEventListener('input', function () { state.q = this.value; $('svQClear').hidden = !this.value; render(); });
    $('svQClear').addEventListener('click', function () { $('svQ').value = ''; state.q = ''; this.hidden = true; render(); $('svQ').focus(); });
    document.querySelector('.sv-filters').addEventListener('click', function (e) {
        var b = e.target.closest('.sv-fchip'); if (!b) return;
        state.f[b.dataset.f] = !state.f[b.dataset.f]; b.setAttribute('aria-pressed', state.f[b.dataset.f]); render();
    });
    $('svSort').addEventListener('change', function () { state.sort = this.value; render(); });
    $('svTop').addEventListener('click', function () { scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
    $('svSections').addEventListener('click', function (e) {
        if (e.target.id === 'svReset') { resetAll(); return; }
        var c = e.target.closest('.sv-card'); if (c) openPrd(byId[+c.dataset.id]);
    });

    var lastFocus = null;
    function openSheet(html) {
        lastFocus = document.activeElement;
        $('svSheet').innerHTML = html; $('svSheet').hidden = false; $('svScrim').hidden = false;
        document.body.style.overflow = 'hidden';
        var x = $('svSheet').querySelector('.sv-x'); if (x) x.focus();
    }
    function closeSheet() { $('svSheet').hidden = true; $('svScrim').hidden = true; document.body.style.overflow = ''; if (lastFocus) lastFocus.focus(); }
    $('svScrim').addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('svSheet').hidden) closeSheet(); });

    var cur = null, qty = 1;
    function openPrd(p) {
        cur = p; qty = 1;
        var oos = p.stock <= 0;
        openSheet('<div class="sv-hero" style="aspect-ratio:1/1;max-height:52vh">' + (p.img ? '<img src="' + esc(p.img) + '" alt="' + esc(p.name) + '" onerror="this.remove()">' : '') +
            '<button type="button" class="sv-x" aria-label="Close">✕</button></div>' +
            '<div class="sv-in"><span class="sv-cat">' + esc(catName[p.cat] || '') + '</span><h2 id="svShTitle">' + esc(p.name) + '</h2>' +
            (p.desc ? '<p>' + esc(p.desc) + '</p>' : '') +
            '<span class="sv-stock' + (!oos && p.stock <= 5 ? ' low' : '') + '">' + stockText(p) + (p.stock > 5 ? ' · ' + p.stock + ' available' : '') + '</span>' +
            (oos ? '' : '<div><div class="sv-lbl">How many?</div><div class="sv-qty"><button type="button" data-q="-1" aria-label="One less">−</button><output id="svQty" aria-live="polite">1</output><button type="button" data-q="1" aria-label="One more">+</button></div></div>') +
            '</div><div class="sv-foot"><div class="sv-tot"><small>' + (oos ? 'Price' : 'Total') + '</small><b id="svTot">' + peso(p.price) + '</b></div><span id="svCartSlot"></span></div>');
        if (!oos) {
            $('svCartSlot').appendChild($('svCartTpl').content.cloneNode(true));
            var idIn = $('svCartSlot').querySelector('input[name="product_id"]'); if (idIn) idIn.value = p.id;
        }
        syncQty();
    }
    function syncQty() {
        if (!cur || cur.stock <= 0) return;
        $('svQty').textContent = qty;
        $('svTot').textContent = peso(cur.price * qty);
        var q = $('svCartSlot').querySelector('input[name="quantity"]'); if (q) q.value = qty;
        $('svSheet').querySelector('[data-q="-1"]').disabled = qty <= 1;
        $('svSheet').querySelector('[data-q="1"]').disabled = qty >= cur.stock;
    }
    function openCats() {
        openSheet('<div class="sv-stitle"><h2 id="svShTitle">All categories</h2><button type="button" class="sv-x" aria-label="Close">✕</button></div><div class="sv-catgrid">' +
            CATS.map(function (c) { return '<button type="button" data-cat="cat-' + c.id + '">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" onerror="this.remove()">' : '') + '<span>' + esc(c.name) + '<small>' + c.n + ' product' + (c.n > 1 ? 's' : '') + '</small></span></button>'; }).join('') + '</div>');
    }
    $('svAllCats').addEventListener('click', openCats);
    $('svSheet').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.classList.contains('sv-x')) { closeSheet(); return; }
        if (b.dataset.q) { qty = Math.max(1, Math.min(cur.stock, qty + +b.dataset.q)); syncQty(); return; }
        if (b.dataset.cat) { closeSheet(); if (filtered()) resetAll(); setTimeout(function () { jump(b.dataset.cat, true); }, 30); }
    });

    render();

    var fl = $('svFlash'); if (fl) setTimeout(function () { fl.hidden = true; }, 5000);

    (function () {
        var m = /^prd-(\d+)$/.exec(REQ_ITEM || '');
        if (m && byId[+m[1]]) {
            var card = $('prd-' + m[1]);
            if (card) {
                scrollTo({ top: card.getBoundingClientRect().top + scrollY - offset() - 40, behavior: 'auto' });
                card.classList.add('sv-flash'); setTimeout(function () { card.classList.remove('sv-flash'); }, 2600);
                openPrd(byId[+m[1]]);
            }
        } else if (REQ_CAT && $('cat-' + REQ_CAT)) {
            jump('cat-' + REQ_CAT, false);
        }
    })();
})();
</script>
</body>
</html>
