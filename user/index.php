<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

// ─── ADD TO CART ──────────────────────────────────────────────────────────────
if (isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity   = max(1, intval($_POST['quantity']));

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product) {
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = [
                'type'     => 'product',
                'id'       => $product_id,
                'name'     => $product['name'],
                'image'    => $product['image'],
                'price'    => $product['price'],
                'quantity' => $quantity
            ];
        }
        sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
    }
}

// ─── DIRECT CHECKOUT ──────────────────────────────────────────────────────────
if (isset($_POST['direct_checkout'])) {
    $product_id = intval($_POST['product_id']);
    $quantity   = max(1, intval($_POST['quantity']));
    $_SESSION['direct_checkout'] = ['product_id' => $product_id, 'quantity' => $quantity];
    header("Location: checkout.php");
    exit();
}

// ─── BOOK SERVICE ─────────────────────────────────────────────────────────────
if (isset($_POST['book_service'])) {
    $service_id = intval($_POST['service_id']);
    $_SESSION['service_booking'] = ['service_id' => $service_id];
    header("Location: checkout.php");
    exit();
}

// ─── FETCH SERVICES WITH CATEGORIES ──────────────────────────────────────────
$services           = [];
$service_categories = ['All'];
$result = $conn->query("
    SELECT s.*, c.name as category_name
    FROM services s
    LEFT JOIN categories c ON s.category_id = c.id
    ORDER BY c.name, s.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
        // Only add real categories to dropdown, skip uncategorized
        if (!empty($row['category_name'])) {
            $cat = $row['category_name'];
            if (!in_array($cat, $service_categories)) {
                $service_categories[] = $cat;
            }
        }
    }
}

// ─── FETCH PRODUCTS WITH CATEGORIES ──────────────────────────────────────────
$products           = [];
$product_categories = ['All'];
$result = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY c.name, p.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
        // Only add real categories to dropdown, skip uncategorized
        if (!empty($row['category_name'])) {
            $cat = $row['category_name'];
            if (!in_array($cat, $product_categories)) {
                $product_categories[] = $cat;
            }
        }
    }
}

$page_title = 'Home';
require_once 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serenity Spa — Home</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo filemtime('../assets/style.css'); ?>">
</head>
<body>

<!-- ══════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ -->
<section class="hero">
    <p class="hero-eyebrow">Welcome to Serenity Spa</p>
    <h1>Relax. <em>Refresh.</em><br>Rejuvenate.</h1>
    <p>Experience the ultimate spa and wellness journey — where every treatment is a ritual of renewal.</p>
    <div class="hero-ctas">
        <a href="#services" class="hero-btn-primary">Book a Service</a>
        <a href="#products" class="hero-btn-outline">Shop Products</a>
    </div>
    <a href="#services" class="hero-scroll">Discover</a>
</section>

<div class="spa-container">

    <!-- ══════════════════════════════════════════════
         SERVICES SECTION
    ══════════════════════════════════════════════ -->
    <section class="spa-section" id="services">

        <div class="section-header">
            <div>
                <p class="section-label">Our Treatments</p>
                <h2 class="section-title-spa">Spa <em>Services</em></h2>
            </div>

            <!-- Category Dropdown -->
            <?php if (count($service_categories) > 1): ?>
            <div class="cat-dropdown-wrap">
                <select class="cat-dropdown" id="svcCatDropdown"
                        onchange="filterSliderDropdown('svc', this.value)">
                    <?php foreach ($service_categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-panel">
            <div class="panel-inner">
                <?php if (!empty($services)): ?>
                <div class="slider-wrapper" id="svcSliderWrap">
                    <button class="slider-arrow prev" id="svcPrev"
                            onclick="slideMove('svc', -1)">&#8592;</button>
                    <div class="slider-track-outer">
                        <div class="slider-track" id="svcTrack">
                            <?php foreach ($services as $svc): ?>
                            <div class="service-slide"
                                 data-category="<?php echo htmlspecialchars($svc['category_name'] ?? ''); ?>">
                                <div class="service-img-wrap">
                                    <img src="../uploads/services/<?php echo htmlspecialchars($svc['image']); ?>"
                                         alt="<?php echo htmlspecialchars($svc['name']); ?>"
                                         onerror="this.src='../uploads/products/default.png'">
                                    <?php if (!empty($svc['category_name'])): ?>
                                    <span class="service-cat-badge">
                                        <?php echo htmlspecialchars($svc['category_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="service-body">
                                    <h3 class="service-name">
                                        <?php echo htmlspecialchars($svc['name']); ?>
                                    </h3>
                                    <p class="service-desc">
                                        <?php echo htmlspecialchars(substr($svc['description'], 0, 90)) . '...'; ?>
                                    </p>
                                    <div class="service-meta">
                                        <span class="service-price">
                                            $<?php echo number_format($svc['price'], 2); ?>
                                        </span>
                                        <span class="service-duration">
                                            ⏱ <?php echo $svc['session_time']; ?> min
                                        </span>
                                    </div>
                                    <button class="btn-book"
                                            onclick="openSvcModal(<?php echo $svc['id']; ?>)">
                                        View & Book
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="slider-arrow next" id="svcNext"
                            onclick="slideMove('svc', 1)">&#8594;</button>
                </div>
                <div class="slider-dots" id="svcDots"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">💆</div>
                        <p>No services available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         PRODUCTS SECTION
    ══════════════════════════════════════════════ -->
    <section class="spa-section" id="products">

        <div class="section-header">
            <div>
                <p class="section-label">Our Collection</p>
                <h2 class="section-title-spa">Spa <em>Products</em></h2>
            </div>

            <!-- Category Dropdown -->
            <?php if (count($product_categories) > 1): ?>
            <div class="cat-dropdown-wrap">
                <select class="cat-dropdown" id="prdCatDropdown"
                        onchange="filterSliderDropdown('prd', this.value)">
                    <?php foreach ($product_categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-panel">
            <div class="panel-inner">
                <?php if (!empty($products)): ?>
                <div class="slider-wrapper" id="prdSliderWrap">
                    <button class="slider-arrow prev" id="prdPrev"
                            onclick="slideMove('prd', -1)">&#8592;</button>
                    <div class="slider-track-outer">
                        <div class="slider-track" id="prdTrack">
                            <?php foreach ($products as $prd):
                                $oos = $prd['stock'] <= 0;
                            ?>
                            <div class="product-slide"
                                 data-category="<?php echo htmlspecialchars($prd['category_name'] ?? ''); ?>">
                                <div class="product-img-oval">
                                    <img src="../uploads/products/<?php echo htmlspecialchars($prd['image']); ?>"
                                         alt="<?php echo htmlspecialchars($prd['name']); ?>"
                                         onerror="this.src='../uploads/products/default.png'">
                                    <?php if ($oos): ?>
                                        <div class="oos-overlay">
                                            <span class="oos-text">Out of Stock</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="product-info">
                                    <?php if (!empty($prd['category_name'])): ?>
                                    <span class="product-cat-badge">
                                        <?php echo htmlspecialchars($prd['category_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <h3 class="product-name">
                                        <?php echo htmlspecialchars($prd['name']); ?>
                                    </h3>
                                    <p class="product-desc">
                                        <?php echo htmlspecialchars(substr($prd['description'], 0, 75)) . '...'; ?>
                                    </p>
                                    <div class="product-price-row">
                                        <span class="product-price">
                                            $<?php echo number_format($prd['price'], 2); ?>
                                        </span>
                                        <span class="product-stock">
                                            <?php echo $oos
                                                ? '❌ Out of stock'
                                                : '✓ ' . $prd['stock'] . ' left'; ?>
                                        </span>
                                    </div>
                                    <button class="btn-add-cart"
                                            <?php echo $oos ? 'disabled' : ''; ?>
                                            onclick="openPrdModal(<?php echo $prd['id']; ?>)">
                                        <?php echo $oos ? 'Unavailable' : 'View Details'; ?>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="slider-arrow next" id="prdNext"
                            onclick="slideMove('prd', 1)">&#8594;</button>
                </div>
                <div class="slider-dots" id="prdDots"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">🛍️</div>
                        <p>No products available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

</div><!-- /spa-container -->

<!-- ══════════════════════════════════════════════════
     SERVICE MODALS
══════════════════════════════════════════════════ -->
<?php foreach ($services as $svc): ?>
<div class="spa-modal" id="svcModal<?php echo $svc['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn"
                onclick="closeSvcModal(<?php echo $svc['id']; ?>)">✕</button>
        <img class="modal-img"
             src="../uploads/services/<?php echo htmlspecialchars($svc['image']); ?>"
             alt="<?php echo htmlspecialchars($svc['name']); ?>"
             onerror="this.src='../uploads/products/default.png'">
        <div class="modal-body-inner">
            <?php if (!empty($svc['category_name'])): ?>
            <span class="modal-cat-badge">
                🏷 <?php echo htmlspecialchars($svc['category_name']); ?>
            </span>
            <?php endif; ?>
            <h2 class="modal-title">
                <?php echo htmlspecialchars($svc['name']); ?>
            </h2>
            <p class="modal-desc">
                <?php echo htmlspecialchars($svc['description']); ?>
            </p>
            <div class="modal-price-row">
                <span class="modal-price">
                    $<?php echo number_format($svc['price'], 2); ?>
                </span>
                <span class="modal-meta">
                    ⏱ <?php echo $svc['session_time']; ?> minutes
                </span>
                <span class="modal-meta">
                    📅 <?php echo $svc['slots']; ?> slots/day
                </span>
            </div>
            <div class="modal-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <form method="POST" style="flex:1;">
                        <input type="hidden" name="service_id"
                               value="<?php echo $svc['id']; ?>">
                        <button type="submit" name="book_service"
                                class="btn-modal-primary" style="width:100%;">
                            Book Now
                        </button>
                    </form>
                <?php else: ?>
                    <a href="auth.php" class="btn-modal-primary"
                       style="text-align:center; text-decoration:none;
                              display:block; padding:0.85rem; flex:1;">
                        Login to Book
                    </a>
                <?php endif; ?>
                <button class="btn-modal-secondary"
                        onclick="closeSvcModal(<?php echo $svc['id']; ?>)">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ══════════════════════════════════════════════════
     PRODUCT MODALS
══════════════════════════════════════════════════ -->
<?php foreach ($products as $prd):
    $oos = $prd['stock'] <= 0;
?>
<div class="spa-modal" id="prdModal<?php echo $prd['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn"
                onclick="closePrdModal(<?php echo $prd['id']; ?>)">✕</button>
        <img class="modal-img"
             src="../uploads/products/<?php echo htmlspecialchars($prd['image']); ?>"
             alt="<?php echo htmlspecialchars($prd['name']); ?>"
             onerror="this.src='../uploads/products/default.png'">
        <div class="modal-body-inner">
            <?php if (!empty($prd['category_name'])): ?>
            <span class="modal-cat-badge">
                🏷 <?php echo htmlspecialchars($prd['category_name']); ?>
            </span>
            <?php endif; ?>
            <h2 class="modal-title">
                <?php echo htmlspecialchars($prd['name']); ?>
            </h2>
            <p class="modal-desc">
                <?php echo htmlspecialchars($prd['description']); ?>
            </p>
            <div class="modal-price-row">
                <span class="modal-price">
                    $<?php echo number_format($prd['price'], 2); ?>
                </span>
                <span class="modal-meta">
                    📦 <?php echo $prd['stock']; ?> in stock
                </span>
            </div>

            <?php if (!$oos): ?>
            <div class="modal-qty-row">
                <span class="qty-label">Quantity:</span>
                <input type="number" class="qty-input"
                       id="qty<?php echo $prd['id']; ?>"
                       value="1" min="1"
                       max="<?php echo $prd['stock']; ?>"
                       oninput="syncQty(<?php echo $prd['id']; ?>)">
            </div>
            <div class="modal-actions">
                <form method="POST" style="flex:1;"
                      id="addCartForm<?php echo $prd['id']; ?>">
                    <input type="hidden" name="product_id"
                           value="<?php echo $prd['id']; ?>">
                    <input type="hidden" name="add_to_cart" value="1">
                    <input type="hidden" name="quantity"
                           id="cartQty<?php echo $prd['id']; ?>" value="1">
                    <button type="submit" class="btn-modal-primary"
                            style="width:100%;">
                        🛒 Add to Cart
                    </button>
                </form>
                <button class="btn-modal-secondary"
                        onclick="checkoutProduct(<?php echo $prd['id']; ?>)">
                    Buy Now
                </button>
                <button class="btn-modal-secondary"
                        onclick="closePrdModal(<?php echo $prd['id']; ?>)">
                    ✕
                </button>
            </div>
            <?php else: ?>
            <div style="background:#f8d7da; color:#842029; padding:0.75rem 1rem;
                        border-radius:10px; font-size:0.88rem; margin-bottom:1rem;">
                ❌ This product is currently out of stock.
            </div>
            <div class="modal-actions">
                <button class="btn-modal-secondary" style="flex:1;"
                        onclick="closePrdModal(<?php echo $prd['id']; ?>)">
                    Close
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
// ══════════════════════════════════════════════════
// SLIDER ENGINE
// ══════════════════════════════════════════════════
const sliders = {};

function initSlider(key) {
    const track  = document.getElementById(key + 'Track');
    const dotsEl = document.getElementById(key + 'Dots');
    if (!track) return;

    const allSlides = Array.from(track.children);
    sliders[key] = {
        track,
        dotsEl,
        allSlides,
        visibleSlides: [...allSlides],
        currentPage:   0,
        perPage:       getPerPage(),
    };
    buildDots(key);
    goToPage(key, 0);
}

function getPerPage() {
    return window.innerWidth <= 600 ? 1
         : window.innerWidth <= 900 ? 2
         : 3;
}

function buildDots(key) {
    const s     = sliders[key];
    const total = Math.ceil(s.visibleSlides.length / s.perPage);
    s.dotsEl.innerHTML = '';
    for (let i = 0; i < total; i++) {
        const dot     = document.createElement('div');
        dot.className = 'dot' + (i === 0 ? ' active' : '');
        dot.onclick   = () => goToPage(key, i);
        s.dotsEl.appendChild(dot);
    }
}

function goToPage(key, page) {
    const s = sliders[key];
    if (!s || s.visibleSlides.length === 0) return;

    const total = Math.ceil(s.visibleSlides.length / s.perPage);
    s.currentPage = Math.max(0, Math.min(page, total - 1));

    // Get width from first visible slide
    const firstVisible = s.visibleSlides[0];
    const slideWidth   = firstVisible
        ? firstVisible.offsetWidth + 24
        : 0;

    s.track.style.transform =
        `translateX(-${s.currentPage * s.perPage * slideWidth}px)`;

    // Update dots
    s.dotsEl.querySelectorAll('.dot').forEach((d, i) => {
        d.classList.toggle('active', i === s.currentPage);
    });

    // Update arrows
    const prev = document.getElementById(key + 'Prev');
    const next = document.getElementById(key + 'Next');
    if (prev) prev.disabled = s.currentPage === 0;
    if (next) next.disabled = s.currentPage >= total - 1;
}

function slideMove(key, dir) {
    const s = sliders[key];
    if (s) goToPage(key, s.currentPage + dir);
}

// ── CATEGORY DROPDOWN FILTER ──────────────────────────────────────
function filterSliderDropdown(key, category) {
    const s = sliders[key];
    if (!s) return;

    s.allSlides.forEach(slide => {
        const slideCat = slide.getAttribute('data-category') || '';
        // 'All' shows everything including uncategorized
        const match = category === 'All' || slideCat === category;
        slide.style.display = match ? '' : 'none';
    });

    s.visibleSlides = s.allSlides.filter(sl => sl.style.display !== 'none');
    s.currentPage   = 0;
    buildDots(key);
    goToPage(key, 0);
}

// ══════════════════════════════════════════════════
// MODALS
// ══════════════════════════════════════════════════
function openSvcModal(id)  {
    document.getElementById('svcModal' + id).classList.add('active');
}
function closeSvcModal(id) {
    document.getElementById('svcModal' + id).classList.remove('active');
}
function openPrdModal(id)  {
    document.getElementById('prdModal' + id).classList.add('active');
}
function closePrdModal(id) {
    document.getElementById('prdModal' + id).classList.remove('active');
}

// Close modal on backdrop click
document.querySelectorAll('.spa-modal').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) m.classList.remove('active');
    });
});

// Sync qty input to hidden form field
function syncQty(id) {
    const val    = document.getElementById('qty' + id)?.value || 1;
    const hidden = document.getElementById('cartQty' + id);
    if (hidden) hidden.value = val;
}

// Checkout now
function checkoutProduct(productId) {
    const qty  = document.getElementById('qty' + productId)?.value || 1;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="product_id"      value="${productId}">
        <input type="hidden" name="quantity"         value="${qty}">
        <input type="hidden" name="direct_checkout"  value="1">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ESC key closes modals
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.spa-modal.active').forEach(m => {
            m.classList.remove('active');
        });
    }
});

// ══════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════
window.addEventListener('load', () => {
    initSlider('svc');
    initSlider('prd');
});

window.addEventListener('resize', () => {
    ['svc', 'prd'].forEach(key => {
        if (sliders[key]) {
            sliders[key].perPage = getPerPage();
            buildDots(key);
            goToPage(key, 0);
        }
    });
});
</script>

</body>
</html>