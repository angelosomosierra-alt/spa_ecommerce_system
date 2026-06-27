<?php
require_once '../config.php';
redirect_if_not_user();

$current_folder = basename(dirname($_SERVER['SCRIPT_NAME']));
$base_path = ($current_folder == 'user') ? '../' : '';

$user_id = $_SESSION['user_id'];

// ── ADD TO CART ───────────────────────────────────────────────────────────────
if (isset($_POST['add_to_cart'])) {
    verify_csrf_token();
    $product_id = intval($_POST['product_id']);
    $quantity   = max(1, intval($_POST['quantity']));
    $stmt = $conn->prepare("SELECT id, name, price, stock, image FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id); $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($product) {
        if (empty($_SESSION['cart'])) $_SESSION['cart'] = load_cart_from_db($conn, $user_id);
        $existing_qty = isset($_SESSION['cart'][$product_id]) ? intval($_SESSION['cart'][$product_id]['quantity']) : 0;
        $new_qty = $existing_qty + $quantity;
        if ($new_qty > intval($product['stock'])) {
            $_SESSION['flash_error'] = "Cannot add {$quantity} more of '{$product['name']}'. Only {$product['stock']} in stock" . ($existing_qty > 0 ? " (you already have {$existing_qty} in cart)." : ".");
        } else {
            $_SESSION['cart'][$product_id] = ['type'=>'product','id'=>$product_id,'name'=>$product['name'],'image'=>$product['image'],'price'=>$product['price'],'quantity'=>$new_qty];
            sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
            $_SESSION['flash_success'] = "'{$product['name']}' added to cart!";
        }
    }
    header("Location: index.php#products"); exit();
}

// ── BUY NOW ───────────────────────────────────────────────────────────────────
if (isset($_POST['direct_checkout'])) {
    verify_csrf_token();
    $product_id = intval($_POST['product_id']);
    $quantity   = max(1, intval($_POST['quantity']));
    $stmt = $conn->prepare("SELECT id, name, price, stock, image FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id); $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($product && $quantity <= intval($product['stock'])) {
        unset($_SESSION['service_booking'], $_SESSION['checkout_items']);
        $_SESSION['direct_checkout'] = ['product_id'=>$product_id,'quantity'=>$quantity];
        header("Location: checkout.php"); exit();
    } else {
        $_SESSION['flash_error'] = $product ? "Only {$product['stock']} item(s) available." : "Product not found.";
        header("Location: index.php#products"); exit();
    }
}

// ── BOOK SERVICE ──────────────────────────────────────────────────────────────
if (isset($_POST['book_service'])) {
    verify_csrf_token();
    unset($_SESSION['direct_checkout'], $_SESSION['checkout_items']);
    $_SESSION['service_booking'] = ['service_id' => intval($_POST['service_id'])];
    header("Location: checkout.php"); exit();
}

// ── FETCH SERVICES ────────────────────────────────────────────────────────────
$services = []; $service_categories = ['All'];
$result = $conn->query("
    SELECT s.id, s.category_id, s.name, s.description, s.price, s.session_time, s.image,
           s.is_home_service, s.home_service_fee,
           c.name as category_name
    FROM services s
    LEFT JOIN categories c ON s.category_id = c.id
    WHERE s.deleted_at IS NULL
    ORDER BY c.name ASC, s.name ASC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
        if (!empty($row['category_name']) && !in_array($row['category_name'], $service_categories))
            $service_categories[] = $row['category_name'];
    }
}

// ── GROUP SERVICES BY CATEGORY ───────────────────────────────────────────────
$services_by_cat = [];
foreach ($services as $svc) {
    $key = $svc['category_id'] ? 'cat_' . $svc['category_id'] : 'cat_other';
    $services_by_cat[$key]['label'] = $svc['category_name'] ?: 'Other';
    $services_by_cat[$key]['items'][] = $svc;
}

// ── FETCH PRODUCTS ────────────────────────────────────────────────────────────
$products = []; $product_categories = ['All'];
$result = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.deleted_at IS NULL
    ORDER BY c.name, p.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
        if (!empty($row['category_name']) && !in_array($row['category_name'], $product_categories))
            $product_categories[] = $row['category_name'];
    }
}

// ── CONTACT FORM ──────────────────────────────────────────────────────────────
$contact_sent = false; $contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    verify_csrf_token();
    $cf_name    = sanitize_input($_POST['cf_name']    ?? '');
    $cf_email   = sanitize_input($_POST['cf_email']   ?? '');
    $cf_subject = sanitize_input($_POST['cf_subject'] ?? '');
    $cf_message = sanitize_input($_POST['cf_message'] ?? '');
    if (empty($cf_name) || empty($cf_email) || empty($cf_message)) {
        $contact_error = 'Please fill in all required fields.';
    } elseif (!filter_var($cf_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (NULL, 'general', ?, ?, 'index.php#contact')");
        $title = '📩 Contact: ' . $cf_name . ' <' . $cf_email . '>' . ($cf_subject ? ' — ' . $cf_subject : '');
        $stmt->bind_param("ss", $title, $cf_message); $stmt->execute(); $stmt->close();
        $contact_sent = true;
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
    <title>Recovery Spa — Home</title>
<?php
$css_file = $base_path . 'assets/style.css';
$css_version = (file_exists($css_file)) ? filemtime($css_file) : '1';
?>
<link rel="stylesheet" href="<?php echo $css_file; ?>?v=<?php echo $css_version; ?>">
<style>
    /* ── RESPONSIVE SERVICE / PRODUCT GRIDS ───────────────────────────── */
    .svc-grid, .prd-grid {
        display:grid; grid-template-columns:repeat(3,1fr); gap:2rem; }
    @media(max-width:960px){ .svc-grid,.prd-grid{ grid-template-columns:repeat(2,1fr); } }
    @media(max-width:580px){ .svc-grid,.prd-grid{ grid-template-columns:1fr; } }

    /* ── SERVICE CARD ──────────────────────────────────────────────────── */
    .svc-card {
        background:var(--white); border-radius:var(--radius);
        overflow:hidden; border:1px solid var(--border);
        box-shadow:0 4px 20px rgba(59,42,26,.06);
        display:flex; flex-direction:column;
        transition:transform .3s ease, box-shadow .3s ease; }
    .svc-card:hover { transform:translateY(-6px); box-shadow:0 18px 50px rgba(59,42,26,.14); }

    .svc-img-wrap {
        position:relative; aspect-ratio:4/3; overflow:hidden; background:var(--warm); }
    .svc-img-wrap img {
        width:100%; height:100%; object-fit:cover;
        transition:transform .5s ease; display:block; }
    .svc-card:hover .svc-img-wrap img { transform:scale(1.05); }

    /* Placeholder for missing / broken images */
    .img-placeholder {
        width:100%; height:100%; display:flex; flex-direction:column;
        align-items:center; justify-content:center;
        background:linear-gradient(135deg,var(--warm),#EAD8C0);
        color:var(--brown-md); font-size:2.5rem; gap:.35rem; }
    .img-placeholder small {
        font-size:.7rem; letter-spacing:.1em; text-transform:uppercase;
        color:var(--brown-lt); }

    .svc-cat-badge {
        position:absolute; top:.85rem; left:.85rem;
        background:rgba(59,42,26,.72); backdrop-filter:blur(6px);
        color:var(--gold-lt); font-size:.65rem; font-weight:500;
        letter-spacing:.12em; text-transform:uppercase;
        padding:.25rem .75rem; border-radius:50px; }

    .svc-body { padding:1.5rem; display:flex; flex-direction:column; flex:1; }
    .svc-name {
        font-family:'Cormorant Garamond',serif; font-size:1.3rem; font-weight:600;
        color:var(--brown); line-height:1.2; margin-bottom:.4rem; }
    .svc-desc {
        font-size:.83rem; color:var(--gray); line-height:1.65; flex:1;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
        overflow:hidden; margin-bottom:1rem; }
    .svc-meta {
        display:flex; align-items:center;
        justify-content:space-between; margin-bottom:.9rem; }
    .svc-price {
        font-family:'Cormorant Garamond',serif;
        font-size:1.5rem; font-weight:600; color:var(--rust); }
    .svc-duration {
        font-size:.75rem; color:var(--gray);
        background:var(--warm); padding:.2rem .75rem; border-radius:50px; }
    .btn-book-grid {
        width:100%; padding:.75rem;
        background:var(--brown); color:var(--cream);
        border:none; border-radius:10px;
        font-family:'DM Sans',sans-serif; font-size:.88rem; font-weight:500;
        letter-spacing:.04em; cursor:pointer; transition:all .22s; }
    .btn-book-grid:hover { background:var(--rust); transform:translateY(-1px); }

    /* ── PRODUCT CARD ──────────────────────────────────────────────────── */
    .prd-card {
        background:var(--white); border-radius:var(--radius);
        overflow:hidden; border:1px solid var(--border);
        box-shadow:0 4px 20px rgba(59,42,26,.06);
        display:flex; flex-direction:column;
        transition:transform .3s ease, box-shadow .3s ease; }
    .prd-card:hover { transform:translateY(-6px); box-shadow:0 18px 50px rgba(59,42,26,.14); }

    .prd-img-wrap {
        position:relative; aspect-ratio:1/1; overflow:hidden; background:var(--warm); }
    .prd-img-wrap img {
        width:100%; height:100%; object-fit:cover;
        transition:transform .5s ease; display:block; }
    .prd-card:hover .prd-img-wrap img { transform:scale(1.06); }

    .prd-body { padding:1.35rem; display:flex; flex-direction:column; flex:1; }
    .prd-cat-badge {
        font-size:.65rem; font-weight:500; letter-spacing:.1em;
        text-transform:uppercase; color:var(--gold); margin-bottom:.3rem;
        display:inline-block; }
    .prd-name {
        font-family:'Cormorant Garamond',serif; font-size:1.2rem; font-weight:600;
        color:var(--brown); line-height:1.2; margin-bottom:.35rem; }
    .prd-desc {
        font-size:.8rem; color:var(--gray); line-height:1.6; flex:1;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
        overflow:hidden; margin-bottom:.85rem; }
    .prd-price-row {
        display:flex; align-items:center;
        justify-content:space-between; margin-bottom:.8rem; }
    .prd-price {
        font-family:'Cormorant Garamond',serif;
        font-size:1.4rem; font-weight:600; color:var(--rust); }
    .prd-stock { font-size:.72rem; color:var(--gray); }
    .btn-cart-grid {
        width:100%; padding:.7rem;
        background:transparent; color:var(--brown);
        border:1.5px solid var(--brown); border-radius:10px;
        font-family:'DM Sans',sans-serif; font-size:.85rem; font-weight:500;
        cursor:pointer; transition:all .22s; }
    .btn-cart-grid:hover { background:var(--brown); color:var(--cream); }
    .btn-cart-grid:disabled { opacity:.4; cursor:not-allowed; }
    .flash-toast { position:fixed; top:20px; right:20px; z-index:99999; padding:0.85rem 1.25rem; border-radius:12px; font-size:0.9rem; font-weight:600; box-shadow:0 4px 20px rgba(0,0,0,0.15); animation: slideInRight 0.3s ease; max-width:320px; }
    .flash-toast.success { background:#D1FAE5; color:#065F46; border-left:4px solid #10B981; }
    .flash-toast.error   { background:#FEE2E2; color:#991B1B; border-left:4px solid #EF4444; }
    @keyframes slideInRight { from { transform:translateX(120px); opacity:0; } to { transform:translateX(0); opacity:1; } }
    .stock-warn { display:none; background:#FEF3C7; color:#92400E; padding:0.5rem 0.75rem; border-radius:8px; font-size:0.82rem; margin-bottom:0.75rem; border-left:3px solid #F59E0B; }
    .about-inner { display:grid; grid-template-columns:1fr 1fr; gap:4rem; align-items:center; }
    .about-text p { color:var(--brown-md); font-size:0.97rem; line-height:1.85; margin-bottom:1rem; }
    .about-visual { border-radius:0px; aspect-ratio:4/5; display:flex; align-items:center; justify-content:center; text-align:center; color:#3B2A1A; padding:0rem; }
    .stats-bar { display:grid; grid-template-columns:repeat(4,1fr); background:#3B2A1A; border-radius:16px; overflow:hidden; margin-top:2rem; }
    .stat-item { padding:1.5rem 1rem; text-align:center; border-right:1px solid rgba(200,164,107,0.2); }
    .stat-item:last-child { border-right:none; }
    .stat-num { font-family:'Cormorant Garamond',serif; font-size:2rem; color:#C8A46B; line-height:1; }
    .stat-lbl { font-size:0.72rem; color:#EAD8C0; margin-top:0.3rem; text-transform:uppercase; letter-spacing:0.05em; }
    .values-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.25rem; margin-top:2.5rem; }
    .value-card { background:var(--warm); border-radius:14px; padding:1.75rem 1.25rem; text-align:center; border:1px solid var(--border); transition:transform 0.2s,box-shadow 0.2s; }
    .value-card:hover { transform:translateY(-4px); box-shadow:var(--shadow); }
    .value-card .vi { font-size:2rem; margin-bottom:0.75rem; display:block; }
    .value-card h3 { color:var(--brown); font-size:0.95rem; font-weight:700; margin-bottom:0.4rem; }
    .value-card p  { color:var(--gray); font-size:0.83rem; line-height:1.6; }
    .contact-inner { display:grid; grid-template-columns:1fr 1.3fr; gap:3.5rem; align-items:start; }
    .contact-info-block { display:flex; flex-direction:column; gap:1.25rem; }
    .contact-detail { display:flex; align-items:flex-start; gap:1rem; }
    .contact-icon { width:42px; height:42px; border-radius:10px; background:var(--warm); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
    .contact-detail h4 { font-size:0.82rem; font-weight:700; color:var(--brown); margin-bottom:0.15rem; }
    .contact-detail p, .contact-detail a { font-size:0.85rem; color:var(--gray); line-height:1.5; text-decoration:none; }
    .contact-detail a:hover { color:var(--rust); }
    .hours-box { background:#3B2A1A; border-radius:14px; padding:1.25rem 1.5rem; margin-top:5.5rem; }
    .hours-box h4 { font-size:0.72rem; letter-spacing:0.12em; text-transform:uppercase; color:#C8A46B; font-weight:700; margin-bottom:0.85rem; }
    .hours-row { display:flex; justify-content:space-between; font-size:0.82rem; padding:0.35rem 0; border-bottom:1px solid rgba(200,164,107,0.15); }
    .hours-row:last-child { border-bottom:none; }
    .hours-row span:first-child { color:#A07850; }
    .hours-row span:last-child  { color:#EAD8C0; font-weight:500; }
    .contact-form-card { background:var(--white); border-radius:18px; padding:2rem; box-shadow:var(--shadow); border:1px solid var(--border); }
    .contact-form-card h3 { font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-weight:400; color:var(--brown); margin-bottom:0.3rem; }
    .contact-form-card > p { font-size:0.85rem; color:var(--gray); margin-bottom:1.5rem; }
    .cf-group { margin-bottom:1rem; }
    .cf-group label { display:block; font-size:0.72rem; font-weight:700; color:var(--brown-md); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.4rem; }
    .cf-group input,.cf-group select,.cf-group textarea { width:100%; padding:0.7rem 0.9rem; border:1.5px solid var(--border); border-radius:10px; font-family:'DM Sans',sans-serif; font-size:0.88rem; color:var(--brown); background:var(--warm); outline:none; transition:border-color 0.2s,box-shadow 0.2s; }
    .cf-group input:focus,.cf-group select:focus,.cf-group textarea:focus { border-color:var(--rust); box-shadow:0 0 0 3px rgba(201,106,44,0.1); background:#fff; }
    .cf-group textarea { resize:vertical; min-height:110px; }
    .cf-row { display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; }
    .btn-send { width:100%; padding:0.85rem; background:linear-gradient(135deg,#C96A2C,#A94F1D); color:#fff; border:none; border-radius:12px; font-size:0.95rem; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; transition:opacity 0.2s,transform 0.2s; margin-top:0.5rem; }
    .btn-send:hover { opacity:0.9; transform:translateY(-1px); }
    .contact-success { text-align:center; padding:2rem 1rem; }
    .contact-success span { font-size:3rem; display:block; margin-bottom:0.75rem; }
    .contact-success h3 { color:var(--brown); font-size:1.2rem; margin-bottom:0.5rem; }
    .contact-success p  { color:var(--gray); font-size:0.88rem; }
    .alert-form-error { background:#FEE2E2; color:#991B1B; border-radius:8px; padding:0.65rem 0.9rem; font-size:0.85rem; margin-bottom:1rem; border-left:3px solid #dc3545; }
    .spa-footer { background:#3B2A1A; color:#EAD8C0; padding:3.5rem 2rem 2rem; }
    .footer-inner { max-width:1200px; margin:0 auto; display:grid; grid-template-columns:1.6fr 1fr 1fr 1fr; gap:2.5rem; margin-bottom:2.5rem; }
    .footer-brand .ft-logo { font-family:'Cormorant Garamond',serif; font-size:1.6rem; color:#C8A46B; font-weight:400; margin-bottom:0.5rem; }
    .footer-brand p { font-size:0.84rem; color:#A07850; line-height:1.7; }
    .footer-col h4 { font-size:0.72rem; letter-spacing:0.12em; text-transform:uppercase; color:#C8A46B; font-weight:700; margin-bottom:1rem; }
    .footer-col ul { list-style:none; }
    .footer-col ul li { margin-bottom:0.5rem; }
    .footer-col ul li a { color:#A07850; font-size:0.85rem; transition:color 0.2s; text-decoration:none; }
    .footer-col ul li a:hover { color:#EAD8C0; }
    .footer-bottom { max-width:1200px; margin:0 auto; padding-top:1.5rem; border-top:1px solid rgba(200,164,107,0.2); text-align:center; font-size:0.8rem; color:#6B4C30; }
    @media(max-width:900px){ .about-inner,.contact-inner { grid-template-columns:1fr; gap:2rem; } .stats-bar { grid-template-columns:repeat(2,1fr); } .footer-inner { grid-template-columns:1fr 1fr; } }
    @media(max-width:560px){ .cf-row { grid-template-columns:1fr; } .footer-inner { grid-template-columns:1fr; } }
    /* ── PER-CATEGORY SERVICE SLIDER ─────────────────────────────────── */
    .cat-section-heading {
        font-family:'Cormorant Garamond',serif; font-size:1.4rem; font-weight:600;
        color:var(--brown); margin:0.5rem 0 1.1rem; padding-bottom:0.5rem;
        border-bottom:2px solid var(--border); }
    .slider-outer { position:relative; padding:0 28px; }
    .slider-overflow { overflow:hidden; }
    .slider-track { display:flex; gap:2rem; transition:transform .4s ease; will-change:transform; }
    .service-slide { flex:0 0 calc(33.333% - 1.35rem); min-width:0; }
    @media(max-width:960px){ .service-slide{ flex:0 0 calc(50% - 1rem); } }
    @media(max-width:580px){ .service-slide{ flex:0 0 100%; } }
    .slider-arrow {
        position:absolute; top:40%; transform:translateY(-50%);
        background:var(--brown); color:var(--cream); border:none; border-radius:50%;
        width:38px; height:38px; cursor:pointer; font-size:1.1rem; z-index:10;
        box-shadow:0 2px 8px rgba(0,0,0,.18); transition:background .2s;
        display:flex; align-items:center; justify-content:center; }
    .slider-arrow:hover { background:var(--rust); }
    .slider-arrow.prev-btn { left:0; }
    .slider-arrow.next-btn { right:0; }
    .slider-dots { display:flex; justify-content:center; gap:0.45rem; margin-top:1rem; }
    .dot { width:8px; height:8px; border-radius:50%; background:var(--border); cursor:pointer; transition:background .2s,transform .2s; }
    .dot.active { background:var(--brown); transform:scale(1.3); }
</style>
</head>
<body>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="flash-toast success" id="flashToast">✅ <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php elseif (!empty($_SESSION['flash_error'])): ?>
<div class="flash-toast error" id="flashToast">❌ <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<section class="hero">
    <p class="hero-eyebrow">Welcome to RECOVERY ILOILO</p>
    <h1>Massage <em>Therapy</em><br>Pamper</h1>
    <p>Experience the ultimate spa and wellness journey — where every treatment is a ritual of renewal.</p>
    <div class="hero-ctas">
        <a href="#services" class="hero-btn-primary">Book a Service</a>
        <a href="#products" class="hero-btn-outline">Shop Products</a>
    </div>
    <a href="#services" class="hero-scroll">Discover</a>
</section>

<div class="spa-container">

<!-- ── SERVICES SECTION ───────────────────────────────────────────────────── -->
<section class="spa-section" id="services">
    <div class="section-header">
        <div>
            <p class="section-label">Our Treatments</p>
            <h2 class="section-title-spa">Spa <em>Services</em></h2>
        </div>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <?php if (!empty($services_by_cat)): ?>
        <?php foreach ($services_by_cat as $cat_key => $cat_data):
              $cat_items  = $cat_data['items'];
              $cat_count  = count($cat_items);
              $has_slider = $cat_count > 3; ?>
        <div style="margin-bottom:2.5rem;">
            <h3 class="cat-section-heading">💆 <?php echo htmlspecialchars($cat_data['label']); ?></h3>
            <div class="slider-outer">
                <?php if ($has_slider): ?>
                <button class="slider-arrow prev-btn" id="prev-<?php echo $cat_key; ?>"
                        onclick="slideMove('<?php echo $cat_key; ?>',-1)"
                        aria-label="Previous" style="display:none;">‹</button>
                <?php endif; ?>
                <div class="slider-overflow">
                    <div class="slider-track" id="track-<?php echo $cat_key; ?>">
                    <?php foreach ($cat_items as $svc): ?>
                    <div class="service-slide">
                    <div class="svc-card">
                        <div class="svc-img-wrap">
                            <?php if (!empty($svc['image'])): ?>
                            <img src="<?php echo $base_path; ?>uploads/services/<?php echo htmlspecialchars($svc['image']); ?>"
                                 alt="<?php echo htmlspecialchars($svc['name']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="img-placeholder" style="display:none">💆<small>Spa Service</small></div>
                            <?php else: ?>
                            <div class="img-placeholder">💆<small>Spa Service</small></div>
                            <?php endif; ?>
                            <?php if (!empty($svc['category_name'])): ?>
                            <span class="svc-cat-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="svc-body">
                            <h3 class="svc-name"><?php echo htmlspecialchars($svc['name']); ?></h3>
                            <p class="svc-desc"><?php echo htmlspecialchars($svc['description']); ?></p>
                            <div class="svc-meta">
                                <span class="svc-price">₱<?php echo number_format($svc['price'],2); ?></span>
                                <span class="svc-duration">⏱ <?php echo $svc['session_time']; ?> min</span>
                            </div>
                            <button class="btn-book-grid" onclick="openSvcModal(<?php echo $svc['id']; ?>)">View &amp; Book</button>
                        </div>
                    </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($has_slider): ?>
                <button class="slider-arrow next-btn" id="next-<?php echo $cat_key; ?>"
                        onclick="slideMove('<?php echo $cat_key; ?>',1)"
                        aria-label="Next">›</button>
                <?php endif; ?>
            </div>
            <?php if ($has_slider): ?><div class="slider-dots" id="dots-<?php echo $cat_key; ?>"></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?><div class="empty-state"><div class="icon">💆</div><p>No services available yet.</p></div><?php endif; ?>
    </div></div>
</section>

<!-- ── PRODUCTS SECTION ───────────────────────────────────────────────────── -->
<section class="spa-section" id="products">
    <div class="section-header">
        <div>
            <p class="section-label">Our Collection</p>
            <h2 class="section-title-spa">Spa <em>Products</em></h2>
        </div>
        <?php if (count($product_categories) > 1): ?>
        <div class="cat-dropdown-wrap">
            <select class="cat-dropdown" id="prdCatDropdown" onchange="filterGrid('prdGrid', this.value)">
                <?php foreach ($product_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <?php if (!empty($products)): ?>
        <div class="prd-grid" id="prdGrid">
            <?php foreach ($products as $prd): $oos = $prd['stock'] <= 0; ?>
            <div class="prd-card" data-category="<?php echo htmlspecialchars($prd['category_name']??''); ?>">
                <div class="prd-img-wrap">
                    <?php if (!empty($prd['image'])): ?>
                    <img src="<?php echo $base_path; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>"
                         alt="<?php echo htmlspecialchars($prd['name']); ?>"
                         loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="img-placeholder" style="display:none">🧴<small>Spa Product</small></div>
                    <?php else: ?>
                    <div class="img-placeholder">🧴<small>Spa Product</small></div>
                    <?php endif; ?>
                    <?php if ($oos): ?><div class="oos-overlay"><span class="oos-text">Out of Stock</span></div><?php endif; ?>
                </div>
                <div class="prd-body">
                    <?php if (!empty($prd['category_name'])): ?>
                    <span class="prd-cat-badge"><?php echo htmlspecialchars($prd['category_name']); ?></span>
                    <?php endif; ?>
                    <h3 class="prd-name"><?php echo htmlspecialchars($prd['name']); ?></h3>
                    <p class="prd-desc"><?php echo htmlspecialchars($prd['description']); ?></p>
                    <div class="prd-price-row">
                        <span class="prd-price">₱<?php echo number_format($prd['price'],2); ?></span>
                        <span class="prd-stock"><?php echo $oos ? '❌ Out of stock' : '✓ '.$prd['stock'].' left'; ?></span>
                    </div>
                    <button class="btn-cart-grid" <?php echo $oos?'disabled':''; ?>
                            onclick="openPrdModal(<?php echo $prd['id']; ?>)">
                        <?php echo $oos ? 'Unavailable' : 'View Details'; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="empty-state"><div class="icon">🛍️</div><p>No products available yet.</p></div><?php endif; ?>
    </div></div>
</section>

<!-- ── ABOUT SECTION ──────────────────────────────────────────────────────── -->
<section class="spa-section" id="about">
    <div class="section-header">
        <div>
            <p class="section-label">Who We Are</p>
            <h2 class="section-title-spa">About <em>Us</em></h2>
        </div>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <div class="about-inner">
            <div class="about-text">
                <p class="section-label" style="display:block;margin-bottom:0.6rem;">Our Story</p>
                <h2 class="section-title-spa" style="margin-bottom:1.25rem;">Rooted in <em>Iloilo,</em><br>Driven by Wellness</h2>
                <p>Recovery was founded with a single belief — that everyone deserves a moment to pause, breathe, and be restored. Nestled in the heart of Iloilo City, we offer a full range of spa and wellness services crafted for both body and soul.</p>
                <p>From our signature massage therapies to nail care, lash services, and body treatments, every session is performed by trained professionals who genuinely care about your well-being.</p>
                <p>We use only premium, skin-safe products and maintain the highest standards of hygiene and comfort — because you deserve nothing less.</p>
            </div>
            <div class="about-visual">
                <div style="text-align:center; margin-bottom:2rem;">
                    <video autoplay loop muted playsinline
                           style="width:70%; max-width:430px; border-radius:15px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <source src="/spa_ecommerce_system/img/for_contactus.mp4" type="video/mp4">
                    </video>
                </div>
            </div>
        </div>
        <div class="stats-bar">
            <div class="stat-item"><div class="stat-num">5+</div><div class="stat-lbl">Years of Service</div></div>
            <div class="stat-item"><div class="stat-num">2,000+</div><div class="stat-lbl">Happy Clients</div></div>
            <div class="stat-item"><div class="stat-num">30+</div><div class="stat-lbl">Services Offered</div></div>
            <div class="stat-item"><div class="stat-num">100%</div><div class="stat-lbl">All-Natural Products</div></div>
        </div>
        <div style="margin-top:3rem;text-align:center;">
            <p class="section-label" style="display:inline-block;">What We Stand For</p>
            <h3 class="section-title-spa" style="margin-bottom:0;">Our <em>Values</em></h3>
        </div>
        <div class="values-grid">
            <div class="value-card"><span class="vi">🌿</span><h3>Natural Wellness</h3><p>We use all-natural, skin-safe ingredients in every treatment and product we offer.</p></div>
            <div class="value-card"><span class="vi">🤝</span><h3>Genuine Care</h3><p>Our team treats every client like family — with warmth, patience, and personal attention.</p></div>
            <div class="value-card"><span class="vi">✨</span><h3>Excellence</h3><p>From the ambiance to the techniques we use, we hold ourselves to the highest standards.</p></div>
            <div class="value-card"><span class="vi">🔒</span><h3>Trust &amp; Safety</h3><p>Your comfort and safety are always our top priority — in every session, every visit.</p></div>
        </div>
    </div></div>
</section>

<!-- ── CONTACT SECTION ────────────────────────────────────────────────────── -->
<section class="spa-section" id="contact">
    <div class="section-header">
        <div>
            <p class="section-label">Get in Touch</p>
            <h2 class="section-title-spa">Contact <em>Us</em></h2>
        </div>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <div class="contact-inner">
            <div>
                <div class="contact-info-block">
                    <h3 class="section-title-spa" style="font-size:1.6rem;margin-bottom:0.5rem;">Visit Us or<br><em>Send a Message</em></h3>
                    <p style="color:var(--brown-md);font-size:0.93rem;line-height:1.7;margin-bottom:0.5rem;">We're located in the heart of Iloilo City.</p>
                    <div class="contact-detail"><div class="contact-icon">📍</div><div><h4>Our Location</h4><p>G&R Building, M.H. Del Pilar Street, Molo, Iloilo City</p></div></div>
                    <div class="contact-detail"><div class="contact-icon">📞</div><div><h4>Phone / Viber</h4><a href="tel:+639853359998">+639853359998</a></div></div>
                    <div class="contact-detail"><div class="contact-icon">✉️</div><div><h4>Email</h4><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></div></div>
                    <div class="contact-detail"><div class="contact-icon">📱</div><div><h4>Social Media</h4><p>Facebook: Recovery Spa Iloilo<br>Instagram: @recoveryspa</p></div></div>
                </div>
                <div class="hours-box">
                    <h4>Operating Hours</h4>
                    <div class="hours-row"><span>Monday – Sunday</span><span>10:00 AM – 10:00 PM</span></div>
                    <div class="hours-row"><span>Holidays</span><span>10:00 AM – 10:00 PM</span></div>
                </div>
            </div>
            <div class="contact-form-card">
                <?php if ($contact_sent): ?>
                <div class="contact-success">
                    <span>✅</span>
                    <h3>Message Sent!</h3>
                    <p>Thank you for reaching out. We'll get back to you shortly.</p>
                </div>
                <?php else: ?>
                <h3>Send Us a Message</h3>
                <p>Have questions? We'd love to hear from you.</p>
                <?php if ($contact_error): ?><div class="alert-form-error">⚠️ <?php echo htmlspecialchars($contact_error); ?></div><?php endif; ?>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="cf-row">
                        <div class="cf-group"><label>Your Name *</label><input type="text" name="cf_name" required placeholder="Juan dela Cruz"></div>
                        <div class="cf-group"><label>Email *</label><input type="email" name="cf_email" required placeholder="you@email.com"></div>
                    </div>
                    <div class="cf-group"><label>Subject</label><input type="text" name="cf_subject" placeholder="How can we help?"></div>
                    <div class="cf-group"><label>Message *</label><textarea name="cf_message" required placeholder="Write your message here..."></textarea></div>
                    <button type="submit" name="send_message" class="btn-send">✉️ Send Message</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div></div>
</section>

</div>

<!-- ── FOOTER ─────────────────────────────────────────────────────────────── -->
<footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand"><div class="ft-logo">RECOVERY</div><p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p></div>
        <div class="footer-col"><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="#services">Services</a></li><li><a href="#products">Products</a></li><li><a href="#about">About Us</a></li><li><a href="#contact">Contact</a></li></ul></div>
        <div class="footer-col"><h4>Services</h4><ul><li><a href="#services">Massage Therapy</a></li><li><a href="#services">Nail Care</a></li><li><a href="#services">Lash Services</a></li><li><a href="#services">Facial Treatments</a></li><li><a href="#services">Body Scrubs</a></li></ul></div>
        <div class="footer-col"><h4>Contact</h4><ul><li><a href="#contact">G&R Bldg., M.H. Del Pilar, Molo, Iloilo City</a></li><li><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></li><li><a href="tel:+639853359998">+639853359998</a></li><li><a href="#contact">Mon – Sun: 10AM – 10PM</a></li></ul></div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>

<!-- ── SERVICE MODALS ─────────────────────────────────────────────────────── -->
<?php foreach ($services as $svc): ?>
<div class="spa-modal" id="svcModal<?php echo $svc['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn" onclick="closeSvcModal(<?php echo $svc['id']; ?>)">✕</button>
        <img class="modal-img"
             src="<?php echo $base_path; ?>uploads/services/<?php echo htmlspecialchars($svc['image']); ?>"
             alt="<?php echo htmlspecialchars($svc['name']); ?>"
             onerror="this.onerror=null; this.src='<?php echo $base_path; ?>uploads/products/default.png';">
        <div class="modal-body-inner">
            <?php if (!empty($svc['category_name'])): ?><span class="modal-cat-badge">🏷 <?php echo htmlspecialchars($svc['category_name']); ?></span><?php endif; ?>
            <h2 class="modal-title"><?php echo htmlspecialchars($svc['name']); ?></h2>
            <p class="modal-desc"><?php echo htmlspecialchars($svc['description']); ?></p>
            <div class="modal-price-row">
                <span class="modal-price">₱<?php echo number_format($svc['price'],2); ?></span>
                <span class="modal-meta">⏱ <?php echo $svc['session_time']; ?> minutes</span>
                <?php if (!empty($svc['is_home_service'])): ?>
                <span class="modal-meta">🏠 Home service available</span>
                <?php endif; ?>
            </div>
            <div class="modal-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" action="index.php" style="flex:1;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="service_id" value="<?php echo $svc['id']; ?>">
                    <button type="submit" name="book_service" class="btn-modal-primary" style="width:100%;">Book Now</button>
                </form>
                <?php else: ?>
                <a href="auth.php" class="btn-modal-primary" style="text-align:center;text-decoration:none;display:block;padding:0.85rem;flex:1;">Login to Book</a>
                <?php endif; ?>
                <button class="btn-modal-secondary" onclick="closeSvcModal(<?php echo $svc['id']; ?>)">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── PRODUCT MODALS ─────────────────────────────────────────────────────── -->
<?php foreach ($products as $prd): $oos = $prd['stock'] <= 0; ?>
<div class="spa-modal" id="prdModal<?php echo $prd['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn" onclick="closePrdModal(<?php echo $prd['id']; ?>)">✕</button>
        <img class="modal-img"
             src="<?php echo $base_path; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>"
             alt="<?php echo htmlspecialchars($prd['name']); ?>"
             onerror="this.onerror=null; this.src='<?php echo $base_path; ?>uploads/products/default.png';">
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
                <input type="number" class="qty-input" id="qty<?php echo $prd['id']; ?>"
                       value="1" min="1" max="<?php echo intval($prd['stock']); ?>"
                       oninput="syncQty(<?php echo $prd['id']; ?>, <?php echo intval($prd['stock']); ?>)">
            </div>
            <div class="stock-warn" id="stockWarn<?php echo $prd['id']; ?>">
                ⚠️ Only <strong><?php echo intval($prd['stock']); ?></strong> item(s) available.
            </div>
            <div class="modal-actions">
                <form method="POST" action="index.php" style="flex:1;" id="addCartForm<?php echo $prd['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="product_id" value="<?php echo $prd['id']; ?>">
                    <input type="hidden" name="add_to_cart" value="1">
                    <input type="hidden" name="quantity" id="cartQty<?php echo $prd['id']; ?>" value="1">
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button type="submit" id="addCartBtn<?php echo $prd['id']; ?>"
                            onclick="return validateQty(<?php echo $prd['id']; ?>, <?php echo intval($prd['stock']); ?>)"
                            class="btn-modal-primary" style="width:100%;">🛒 Add to Cart</button>
                    <?php else: ?>
                    <a href="auth.php" class="btn-modal-primary" style="text-align:center;text-decoration:none;display:block;padding:0.85rem;">Login to Shop</a>
                    <?php endif; ?>
                </form>
                <?php if (isset($_SESSION['user_id'])): ?>
                <button class="btn-modal-secondary" id="buyNowBtn<?php echo $prd['id']; ?>"
                        onclick="handleBuyNow(<?php echo $prd['id']; ?>, <?php echo intval($prd['stock']); ?>)">Buy Now</button>
                <?php endif; ?>
                <button class="btn-modal-secondary" onclick="closePrdModal(<?php echo $prd['id']; ?>)">✕</button>
            </div>
            <?php else: ?>
            <div style="background:#f8d7da;color:#842029;padding:0.75rem 1rem;border-radius:10px;font-size:0.88rem;margin-bottom:1rem;">❌ Out of stock.</div>
            <div class="modal-actions"><button class="btn-modal-secondary" style="flex:1;" onclick="closePrdModal(<?php echo $prd['id']; ?>)">Close</button></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
/* ── Per-category service sliders ───────────────────────────────────── */
var sliders = {};
function getVisibleCount() {
    return window.innerWidth <= 580 ? 1 : (window.innerWidth <= 960 ? 2 : 3);
}
function setWidths(key) {
    var track = document.getElementById('track-' + key);
    if (!track) return;
    var vis = getVisibleCount();
    var gap  = vis === 1 ? '0px' : vis === 2 ? '1rem' : '1.35rem';
    track.querySelectorAll('.service-slide').forEach(function(s) {
        s.style.flex = '0 0 calc(' + (100 / vis) + '% - ' + gap + ')';
    });
}
function buildDots(key) {
    var dotsEl = document.getElementById('dots-' + key);
    if (!dotsEl) return;
    var track = document.getElementById('track-' + key);
    var count = track ? track.querySelectorAll('.service-slide').length : 0;
    var vis   = getVisibleCount();
    var pages = Math.max(0, count - vis + 1);
    dotsEl.innerHTML = '';
    for (var i = 0; i < pages; i++) {
        var d = document.createElement('span');
        d.className = 'dot' + (i === 0 ? ' active' : '');
        (function(k, idx) { d.addEventListener('click', function() { go(k, idx); }); })(key, i);
        dotsEl.appendChild(d);
    }
}
function go(key, newPos) {
    var sl = sliders[key]; if (!sl) return;
    var vis    = getVisibleCount();
    var maxPos = Math.max(0, sl.count - vis);
    newPos = Math.max(0, Math.min(newPos, maxPos)); sl.pos = newPos;
    var track = document.getElementById('track-' + key);
    if (track) track.style.transform = 'translateX(-' + (newPos * 100 / vis) + '%)';
    var prev = document.getElementById('prev-' + key);
    var next = document.getElementById('next-' + key);
    if (prev) prev.style.display = newPos === 0 ? 'none' : '';
    if (next) next.style.display = newPos >= maxPos ? 'none' : '';
    document.querySelectorAll('#dots-' + key + ' .dot').forEach(function(d, i) {
        d.classList.toggle('active', i === newPos);
    });
}
function slideMove(key, dir) { if (sliders[key]) go(key, sliders[key].pos + dir); }
function initSlider(key) {
    var track = document.getElementById('track-' + key); if (!track) return;
    sliders[key] = { pos: 0, count: track.querySelectorAll('.service-slide').length };
    setWidths(key); buildDots(key); go(key, 0);
}
window.addEventListener('resize', function() {
    Object.keys(sliders).forEach(function(key) {
        setWidths(key); buildDots(key); go(key, sliders[key].pos);
    });
});

/* ── Category filter for product grids ──────────────────────────────── */
function filterGrid(gridId, category) {
    document.querySelectorAll('#' + gridId + ' [data-category]').forEach(function(card) {
        card.style.display = (category === 'All' || card.dataset.category === category) ? '' : 'none';
    });
}

/* ── Modal open / close ─────────────────────────────────────────────── */
function openSvcModal(id)  { document.getElementById('svcModal'+id).classList.add('active'); }
function closeSvcModal(id) { document.getElementById('svcModal'+id).classList.remove('active'); }
function openPrdModal(id)  { document.getElementById('prdModal'+id).classList.add('active'); }
function closePrdModal(id) { document.getElementById('prdModal'+id).classList.remove('active'); }
document.querySelectorAll('.spa-modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.spa-modal.active').forEach(function(m) { m.classList.remove('active'); });
});
function syncQty(id, maxStock) {
    const qtyInput=document.getElementById('qty'+id); const cartQty=document.getElementById('cartQty'+id);
    const warnEl=document.getElementById('stockWarn'+id); const addBtn=document.getElementById('addCartBtn'+id); const buyBtn=document.getElementById('buyNowBtn'+id);
    if(!qtyInput)return;
    let val=parseInt(qtyInput.value)||1; if(val<1){val=1;qtyInput.value=1;}
    const isOver=val>maxStock;
    if(warnEl)warnEl.style.display=isOver?'block':'none';
    if(addBtn){addBtn.disabled=isOver;addBtn.style.opacity=isOver?'0.5':'1';}
    if(buyBtn){buyBtn.disabled=isOver;buyBtn.style.opacity=isOver?'0.5':'1';}
    if(cartQty)cartQty.value=val;
}
function validateQty(id, maxStock) {
    const qtyInput=document.getElementById('qty'+id); const cartQty=document.getElementById('cartQty'+id);
    if(!qtyInput)return false;
    const val=parseInt(qtyInput.value)||1;
    if(val<1){alert('Quantity must be at least 1.');qtyInput.value=1;syncQty(id,maxStock);return false;}
    if(val>maxStock){alert('Only '+maxStock+' item(s) left in stock.');qtyInput.value=maxStock;syncQty(id,maxStock);return false;}
    if(cartQty)cartQty.value=val;
    return true;
}
function handleBuyNow(productId, maxStock) {
    const qtyInput=document.getElementById('qty'+productId);
    const qty=parseInt(qtyInput?qtyInput.value:1)||1;
    if(qty<1){alert('Quantity must be at least 1.');if(qtyInput)qtyInput.value=1;return;}
    if(qty>maxStock){alert('Only '+maxStock+' item(s) left in stock.');if(qtyInput)qtyInput.value=maxStock;syncQty(productId,maxStock);return;}
    const form=document.createElement('form'); form.method='POST'; form.action='index.php';
    [['product_id',productId],['quantity',qty],['direct_checkout','1'],['csrf_token',CSRF_TOKEN]].forEach(([name,value])=>{
        const input=document.createElement('input'); input.type='hidden'; input.name=name; input.value=value; form.appendChild(input);
    });
    document.body.appendChild(form); form.submit();
}
// Init category sliders
(function(){var keys=<?php echo json_encode(array_keys($services_by_cat)); ?>;keys.forEach(function(k){initSlider(k);});}());
const toast=document.getElementById('flashToast');
if(toast)setTimeout(()=>{toast.style.opacity='0';toast.style.transition='opacity 0.5s';setTimeout(()=>toast.remove(),500);},3500);
</script>
</body>
</html>