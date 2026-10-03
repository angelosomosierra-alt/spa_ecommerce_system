<?php
require_once '../config.php';

$flash_message = '';

// ── add_to_cart: this button previously posted here with NO server-side
// handler anywhere in the codebase (dead markup) — implemented for real here,
// matching user/cart.php's own $_SESSION['cart'] shape and DB sync. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    verify_csrf_token();
    if (!is_logged_in()) {
        header("Location: auth.php");
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
        $flash_message = "That product is no longer available.";
    } elseif ($quantity > (int)$product['stock']) {
        $flash_message = "Only {$product['stock']} of \"{$product['name']}\" left in stock.";
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
        $flash_message = "✅ Added \"{$product['name']}\" to your cart.";
    }
    $_SESSION['products_flash'] = $flash_message;
    header("Location: products.php?category=" . intval($_POST['redirect_category'] ?? 0) . "#prd-{$product_id}");
    exit();
}

if (!empty($_SESSION['products_flash'])) {
    $flash_message = $_SESSION['products_flash'];
    unset($_SESSION['products_flash']);
}

$products = [];
$res_prd = $conn->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.deleted_at IS NULL ORDER BY c.name, p.name");
while ($row = $res_prd->fetch_assoc()) $products[] = $row;

$products_by_cat = [];
foreach ($products as $prd) {
    $key = $prd['category_id'] ? (int)$prd['category_id'] : 0;
    if (!isset($products_by_cat[$key])) {
        $products_by_cat[$key] = ['label' => $prd['category_name'] ?: 'Other', 'items' => []];
    }
    $products_by_cat[$key]['items'][] = $prd;
}

$cat_icons = ['Skincare' => '🧴', 'Bath & Body' => '🧼', 'Lotions & Oils' => '💧', 'oils' => '💧'];
function prd_cat_icon($name, $icons) { return $icons[$name] ?? '🛍️'; }

$requested_category = intval($_GET['category'] ?? 0);
$requested_item      = $_GET['item'] ?? '';

$page_title  = 'Products — Recovery Iloilo';
$active_page = 'products';
require_once 'header.php';
?>
<style>
.services-hero { padding: 2.5rem 0 1rem; text-align: center; }
.services-hero h1 { font-family:'Cormorant Garamond',serif; font-size: 2.4rem; color: var(--brown); margin-bottom: 0.5rem; }
.services-hero p { color: var(--gray); font-size: 1.1rem; }
.flash-banner { max-width:700px; margin:0 auto 1.5rem; padding:0.9rem 1.25rem; background:#e7f6ec; color:#0a3622; border:1px solid #b7e4c7; border-radius:10px; font-size:1rem; font-weight:600; text-align:center; }
.prd-img-wrap { position:relative; aspect-ratio:1/1; overflow:hidden; background:var(--warm); }
.prd-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.img-placeholder { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:linear-gradient(135deg,var(--warm),#EAD8C0); color:var(--brown-md); font-size:2.2rem; gap:.3rem; }
.img-placeholder small { font-size:.75rem; letter-spacing:.08em; text-transform:uppercase; color:var(--brown-lt); }
.prd-body { padding:1.35rem; display:flex; flex-direction:column; }
.prd-name { font-family:'Cormorant Garamond',serif; font-size:1.2rem; font-weight:700; color:var(--brown); margin-bottom:.35rem; }
.prd-desc { font-size:1rem; color:var(--gray); line-height:1.6; margin-bottom:.85rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.prd-price-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:.8rem; }
.prd-price { font-size:1.3rem; font-weight:700; color:var(--rust-dark); }
.prd-stock { font-size:.85rem; color:var(--gray); }
.btn-cart-grid { width:100%; padding:.8rem; background:transparent; color:var(--brown); border:2px solid var(--brown); border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer; transition:all .2s; }
.btn-cart-grid:hover { background:var(--brown); color:var(--cream); }
.btn-cart-grid:disabled { opacity:.5; cursor:not-allowed; }
</style>

<div class="spa-container" style="padding-top:0;">
<div class="services-hero">
    <h1>Our <em>Products</em></h1>
    <p>Take the spa experience home — browse by category using the sidebar.</p>
</div>

<?php if ($flash_message): ?><div class="flash-banner"><?php echo htmlspecialchars($flash_message); ?></div><?php endif; ?>

<div class="kiosk-layout">
    <nav class="kiosk-sidebar" aria-label="Product categories">
        <?php foreach ($products_by_cat as $cat_id => $cat_data): ?>
        <a href="#cat-<?php echo $cat_id; ?>" class="kiosk-cat-btn" data-cat-id="<?php echo $cat_id; ?>">
            <span class="kiosk-cat-icon" aria-hidden="true"><?php echo prd_cat_icon($cat_data['label'], $cat_icons); ?></span>
            <span><?php echo htmlspecialchars($cat_data['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="kiosk-content">
        <?php if (empty($products_by_cat)): ?>
        <div class="empty-state"><div class="icon">🛍️</div><p>No products available yet.</p></div>
        <?php endif; ?>
        <?php foreach ($products_by_cat as $cat_id => $cat_data): ?>
        <section id="cat-<?php echo $cat_id; ?>" data-cat-section="<?php echo $cat_id; ?>">
            <h2 class="kiosk-content-title"><?php echo prd_cat_icon($cat_data['label'], $cat_icons); ?> <?php echo htmlspecialchars($cat_data['label']); ?></h2>
            <div class="kiosk-item-grid">
                <?php foreach ($cat_data['items'] as $prd): $oos = $prd['stock'] <= 0; ?>
                <div class="kiosk-item-card" id="prd-<?php echo $prd['id']; ?>">
                    <div class="prd-img-wrap">
                        <?php if (!empty($prd['image'])): ?>
                        <img src="<?php echo BASE_URL; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>" alt="<?php echo htmlspecialchars($prd['name']); ?>" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="img-placeholder" style="display:none">🧴<small>Spa Product</small></div>
                        <?php else: ?>
                        <div class="img-placeholder">🧴<small>Spa Product</small></div>
                        <?php endif; ?>
                        <?php if ($oos): ?><div class="oos-overlay"><span class="oos-text">Out of Stock</span></div><?php endif; ?>
                    </div>
                    <div class="prd-body">
                        <h3 class="prd-name"><?php echo htmlspecialchars($prd['name']); ?></h3>
                        <p class="prd-desc"><?php echo htmlspecialchars($prd['description']); ?></p>
                        <div class="prd-price-row">
                            <span class="prd-price">₱<?php echo number_format($prd['price'],2); ?></span>
                            <span class="prd-stock"><?php echo $oos ? '❌ Out of stock' : '✓ '.$prd['stock'].' left'; ?></span>
                        </div>
                        <button class="btn-cart-grid" <?php echo $oos ? 'disabled' : ''; ?> onclick="openPrdModal(<?php echo $prd['id']; ?>)">
                            <?php echo $oos ? 'Unavailable' : 'View Details'; ?>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</div>
</div>

<?php require_once '../footer.php'; ?>

<?php foreach ($products as $prd): $oos = $prd['stock'] <= 0; ?>
<div class="spa-modal" id="prdModal<?php echo $prd['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn" onclick="closePrdModal(<?php echo $prd['id']; ?>)" aria-label="Close">✕</button>
        <img class="modal-img" src="<?php echo BASE_URL; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>" alt="<?php echo htmlspecialchars($prd['name']); ?>"
             onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>uploads/products/default.png';">
        <div class="modal-body-inner">
            <?php if (!empty($prd['category_name'])): ?><span class="modal-cat-badge">🏷 <?php echo htmlspecialchars($prd['category_name']); ?></span><?php endif; ?>
            <h2 class="modal-title"><?php echo htmlspecialchars($prd['name']); ?></h2>
            <p class="modal-desc"><?php echo htmlspecialchars($prd['description']); ?></p>
            <div class="modal-price-row">
                <span class="modal-price">₱<?php echo number_format($prd['price'],2); ?></span>
                <span class="modal-meta">📦 <?php echo intval($prd['stock']); ?> in stock</span>
            </div>
            <?php if (!$oos): ?>
            <div class="modal-qty-row">
                <span class="qty-label">Quantity:</span>
                <input type="number" class="qty-input" id="qty<?php echo $prd['id']; ?>" value="1" min="1" max="<?php echo intval($prd['stock']); ?>"
                       oninput="syncQty(<?php echo $prd['id']; ?>, <?php echo intval($prd['stock']); ?>)">
            </div>
            <div class="stock-warn" id="stockWarn<?php echo $prd['id']; ?>">⚠️ Only <strong><?php echo intval($prd['stock']); ?></strong> item(s) available.</div>
            <div class="modal-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" action="products.php" style="flex:1;" id="addCartForm<?php echo $prd['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="product_id" value="<?php echo $prd['id']; ?>">
                    <input type="hidden" name="add_to_cart" value="1">
                    <input type="hidden" name="redirect_category" value="<?php echo (int)($prd['category_id'] ?? 0); ?>">
                    <input type="hidden" name="quantity" id="cartQty<?php echo $prd['id']; ?>" value="1">
                    <button type="submit" id="addCartBtn<?php echo $prd['id']; ?>"
                            onclick="return validateQty(<?php echo $prd['id']; ?>, <?php echo intval($prd['stock']); ?>)"
                            class="btn-modal-primary" style="width:100%;">🛒 Add to Cart</button>
                </form>
                <?php else: ?>
                <a href="auth.php" class="btn-modal-primary" style="text-align:center;text-decoration:none;display:block;padding:0.85rem;flex:1;">Login to Shop</a>
                <?php endif; ?>
                <button class="btn-modal-secondary" onclick="closePrdModal(<?php echo $prd['id']; ?>)">✕</button>
            </div>
            <?php else: ?>
            <div style="background:#f8d7da;color:#842029;padding:0.75rem 1rem;border-radius:10px;font-size:0.95rem;margin-bottom:1rem;">❌ This product is currently out of stock.</div>
            <div class="modal-actions"><button class="btn-modal-secondary" style="flex:1;" onclick="closePrdModal(<?php echo $prd['id']; ?>)">Close</button></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function openPrdModal(id)  { document.getElementById('prdModal'+id).classList.add('active'); }
function closePrdModal(id) { document.getElementById('prdModal'+id).classList.remove('active'); }
document.querySelectorAll('.spa-modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.spa-modal.active').forEach(function(m) { m.classList.remove('active'); });
});
function syncQty(id, maxStock) {
    var qtyInput = document.getElementById('qty'+id);
    var cartQty  = document.getElementById('cartQty'+id);
    var warnEl   = document.getElementById('stockWarn'+id);
    var addBtn   = document.getElementById('addCartBtn'+id);
    if (!qtyInput) return;
    var val = parseInt(qtyInput.value) || 1;
    if (val < 1) { val = 1; qtyInput.value = 1; }
    var isOver = val > maxStock;
    if (warnEl) warnEl.style.display = isOver ? 'block' : 'none';
    if (addBtn) { addBtn.disabled = isOver; addBtn.style.opacity = isOver ? '0.5' : '1'; }
    if (cartQty) cartQty.value = val;
}
function validateQty(id, maxStock) {
    var qtyInput = document.getElementById('qty'+id);
    var cartQty  = document.getElementById('cartQty'+id);
    if (!qtyInput) return false;
    var val = parseInt(qtyInput.value) || 1;
    if (val < 1) { uiAlert('Quantity must be at least 1.'); qtyInput.value = 1; syncQty(id, maxStock); return false; }
    if (val > maxStock) { uiAlert('Only '+maxStock+' item(s) left in stock.'); qtyInput.value = maxStock; syncQty(id, maxStock); return false; }
    if (cartQty) cartQty.value = val;
    return true;
}

/* ── Kiosk sidebar: click = smooth scroll, scroll-spy highlights active ── */
var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
var catButtons = document.querySelectorAll('.kiosk-cat-btn');
var catSections = document.querySelectorAll('[data-cat-section]');
function setActiveCat(catId) {
    catButtons.forEach(function(b) { b.classList.toggle('active', b.dataset.catId === String(catId)); });
}
catButtons.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var target = document.getElementById('cat-' + btn.dataset.catId);
        if (target) target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        setActiveCat(btn.dataset.catId);
        history.replaceState(null, '', '?category=' + btn.dataset.catId);
    });
});
if ('IntersectionObserver' in window && catSections.length) {
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) { if (entry.isIntersecting) setActiveCat(entry.target.dataset.catSection); });
    }, { rootMargin: '-100px 0px -70% 0px', threshold: 0 });
    catSections.forEach(function(s) { observer.observe(s); });
}
(function() {
    var reqCat  = <?php echo json_encode($requested_category); ?>;
    var reqItem = <?php echo json_encode($requested_item); ?>;
    function goDeepLink() {
        if (reqItem && reqItem.indexOf('prd-') === 0) {
            var card = document.getElementById(reqItem);
            if (card) {
                card.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
                card.classList.add('flash-highlight');
                setTimeout(function() { card.classList.remove('flash-highlight'); }, 2600);
                var sec = card.closest('[data-cat-section]');
                if (sec) setActiveCat(sec.dataset.catSection);
                return;
            }
        }
        if (reqCat) {
            var target = document.getElementById('cat-' + reqCat);
            if (target) { target.scrollIntoView({ behavior: 'auto', block: 'start' }); setActiveCat(reqCat); }
        } else if (catButtons.length) {
            setActiveCat(catButtons[0].dataset.catId);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', goDeepLink);
    else goDeepLink();
})();
</script>
</body>
</html>
