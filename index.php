<?php
require_once 'config.php';

// 1. SESSION & LOGOUT LOGIC
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

// 2. CONTACT FORM HANDLER
$contact_sent = false;
$contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $cf_name    = sanitize_input($_POST['cf_name'] ?? '');
    $cf_email   = sanitize_input($_POST['cf_email'] ?? '');
    $cf_message = sanitize_input($_POST['cf_message'] ?? '');
    
    if (empty($cf_name) || empty($cf_email) || empty($cf_message)) {
        $contact_error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (NULL, 'general', ?, ?)");
        $title = "Message from: $cf_name ($cf_email)";
        $stmt->bind_param("ss", $title, $cf_message);
        $stmt->execute();
        $stmt->close();
        $contact_sent = true;
    }
}

// ── book_service: store selected service in session and redirect to checkout ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service'])) {
    verify_csrf_token();
    if (!is_logged_in()) {
        header("Location: user/auth.php");
        exit();
    }
    $service_id = intval($_POST['service_id'] ?? 0);
    if ($service_id > 0) {
        $_SESSION['service_booking'] = ['service_id' => $service_id];
        header("Location: user/checkout.php");
        exit();
    }
}

$cart_count = (isset($_SESSION['user_id']) && isset($_SESSION['cart'])) ? count($_SESSION['cart']) : 0;
$base_path  = BASE_URL;

// 3. DATA FETCHING
$services = []; $service_categories = ['All'];
$res_svc = $conn->query("SELECT s.*, c.name as category_name FROM services s LEFT JOIN categories c ON s.category_id = c.id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name");
while ($row = $res_svc->fetch_assoc()) {
    $services[] = $row;
    if (!empty($row['category_name']) && !in_array($row['category_name'], $service_categories)) $service_categories[] = $row['category_name'];
}

$products = []; $product_categories = ['All'];
$res_prd = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.deleted_at IS NULL ORDER BY c.name, p.name");
while ($row = $res_prd->fetch_assoc()) {
    $products[] = $row;
    if (!empty($row['category_name']) && !in_array($row['category_name'], $product_categories)) $product_categories[] = $row['category_name'];
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recovery Spa — Home</title>
    

<link rel="stylesheet" href="assets/style.css">    <style>
    .slider-wrapper   { position:relative; margin:0 30px; }
    .slider-arrow.prev{ left:-34px; }
    .slider-arrow.next{ right:-34px; }
    .slider-track-outer{ overflow:hidden; width:100%; }
    .slider-track { display:flex; gap:1.5rem; transition:transform 0.4s ease; }
    .service-slide { flex:0 0 calc((100% - 3rem)/3); min-width:0; }
    .product-slide { flex:0 0 calc((100% - 3rem)/3); min-width:0; }
    @media(max-width:900px){ .service-slide,.product-slide{ flex:0 0 calc((100% - 1.5rem)/2); } }
    @media(max-width:600px){ .service-slide,.product-slide{ flex:0 0 100%; } }

    /* About */
    .about-inner { display:grid; grid-template-columns:1fr 1fr; gap:4rem; align-items:center; }
    .about-text p { color:var(--brown-md); font-size:0.97rem; line-height:1.85; margin-bottom:1rem; }
    .about-visual { border-radius:20px; aspect-ratio:4/5; background:linear-gradient(160deg,#EAD8C0,#C8A46B 60%,#A07850); display:flex; align-items:center; justify-content:center; text-align:center; color:#3B2A1A; padding:2rem; }
    .about-visual span { font-size:4rem; display:block; margin-bottom:1rem; }
    .about-visual p { font-family:'Cormorant Garamond',serif; font-size:1.3rem; }
    .stats-bar { display:grid; grid-template-columns:repeat(4,1fr); background:#3B2A1A; border-radius:16px; overflow:hidden; margin-top:2rem; }
    .stat-item { padding:1.5rem 1rem; text-align:center; border-right:1px solid rgba(200,164,107,0.2); }
    .stat-item:last-child { border-right:none; }
    .stat-num { font-family:'Cormorant Garamond',serif; font-size:2rem; color:#C8A46B; line-height:1; }
    .stat-lbl { font-size:0.72rem; color:#EAD8C0; margin-top:0.3rem; text-transform:uppercase; letter-spacing:0.05em; }
    .values-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.25rem; margin-top:2.5rem; }
    .value-card { background:var(--warm); border-radius:14px; padding:1.75rem 1.25rem; text-align:center; border:1px solid var(--border); transition:transform 0.2s,box-shadow 0.2s; }
    .value-card:hover { transform:translateY(-4px); box-shadow:var(--shadow); }
    .value-card .vi { font-size:2rem; margin-bottom:0.75rem; display:block; }
    .value-card h3  { color:var(--brown); font-size:0.95rem; font-weight:700; margin-bottom:0.4rem; }
    .value-card p   { color:var(--gray); font-size:0.83rem; line-height:1.6; }

    /* Contact */
    .contact-inner { display:grid; grid-template-columns:1fr 1.3fr; gap:3.5rem; align-items:start; }
    .contact-info-block { display:flex; flex-direction:column; gap:1.25rem; }
    .contact-detail { display:flex; align-items:flex-start; gap:1rem; }
    .contact-icon { width:42px; height:42px; border-radius:10px; background:var(--warm); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
    .contact-detail h4 { font-size:0.82rem; font-weight:700; color:var(--brown); margin-bottom:0.15rem; }
    .contact-detail p, .contact-detail a { font-size:0.85rem; color:var(--gray); line-height:1.5; text-decoration:none; }
    .contact-detail a:hover { color:var(--rust); }
    .hours-box { background:#3B2A1A; border-radius:14px; padding:1.25rem 1.5rem; margin-top:0.5rem; }
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

    /* Footer */
    .spa-footer { background:#3B2A1A; color:#EAD8C0; padding:3.5rem 2rem 2rem; }
    .footer-inner { max-width:1200px; margin:0 auto; display:grid; grid-template-columns:1.6fr 1fr 1fr 1fr; gap:2.5rem; margin-bottom:2.5rem; }
    .footer-brand .ft-logo { font-family:'Cormorant Garamond',serif; font-size:1.6rem; color:#C8A46B; font-weight:400; margin-bottom:0.5rem; }
    .footer-brand p { font-size:0.84rem; color:#A07850; line-height:1.7; }
    .footer-col h4  { font-size:0.72rem; letter-spacing:0.12em; text-transform:uppercase; color:#C8A46B; font-weight:700; margin-bottom:1rem; }
    .footer-col ul  { list-style:none; }
    .footer-col ul li { margin-bottom:0.5rem; }
    .footer-col ul li a { color:#A07850; font-size:0.85rem; transition:color 0.2s; text-decoration:none; }
    .footer-col ul li a:hover { color:#EAD8C0; }
    .footer-bottom { max-width:1200px; margin:0 auto; padding-top:1.5rem; border-top:1px solid rgba(200,164,107,0.2); text-align:center; font-size:0.8rem; color:#6B4C30; }

    @media(max-width:900px){
        .about-inner,.contact-inner { grid-template-columns:1fr; gap:2rem; }
        .stats-bar { grid-template-columns:repeat(2,1fr); }
        .footer-inner { grid-template-columns:1fr 1fr; }
    }
    @media(max-width:560px){
        .cf-row { grid-template-columns:1fr; }
        .footer-inner { grid-template-columns:1fr; }
    }
    </style>
</head>
<header>
    <nav>
        <div class="logo">RECOVERY</div>
        <ul class="nav-links">
            <li><a href="#index">Home</a></li>
            <li><a href="#services">Services</a></li>
            <li><a href="#products">Products</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
        <div class="auth-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="user/cart.php" class="cart-btn">Cart (<?= $cart_count ?>)</a>
                <a href="?logout=1">Logout</a>
            <?php else: ?>
                <a href="user/auth.php">Login</a>
                <a href="user/auth.php?register=1" class="hero-btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem;">Register</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<body>

<section class="hero" id="index">
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

<section class="spa-section" id="services">
    <div class="section-header">
        <div>
            <p class="section-label">Our Treatments</p>
            <h2 class="section-title-spa">Spa <em>Services</em></h2>
        </div>
        <?php if (count($service_categories) > 1): ?>
        <div class="cat-dropdown-wrap">
            <select class="cat-dropdown" id="svcCatDropdown" onchange="filterSliderDropdown('svc', this.value)">
                <?php foreach ($service_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <?php if (!empty($services)): ?>
        <div class="slider-wrapper" id="svcSliderWrap">
            <button class="slider-arrow prev" id="svcPrev" onclick="slideMove('svc',-1)">&#8592;</button>
            <div class="slider-track-outer"><div class="slider-track" id="svcTrack">
                <?php foreach ($services as $svc): ?>
                <div class="service-slide" data-category="<?php echo htmlspecialchars($svc['category_name']??''); ?>">
                    <div class="service-img-wrap">
                        <img src="uploads/services/<?php echo htmlspecialchars($svc['image']); ?>" 
                        alt="<?php echo htmlspecialchars($svc['name']); ?>" 
                        onerror="this.onerror=null; this.src='uploads/products/default.png';">
                        <?php if (!empty($svc['category_name'])): ?><span class="service-cat-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span><?php endif; ?>
                    </div>
                    <div class="service-body">
                        <h3 class="service-name"><?php echo htmlspecialchars($svc['name']); ?></h3>
                        <p class="service-desc"><?php echo htmlspecialchars(substr($svc['description'],0,90)).'...'; ?></p>
                        <div class="service-meta">
                            <span class="service-price">₱<?php echo number_format($svc['price'],2); ?></span>
                            <span class="service-duration">⏱ <?php echo $svc['session_time']; ?> min</span>
                        </div>
                        <button class="btn-book" onclick="openSvcModal(<?php echo $svc['id']; ?>)">View & Book</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div></div>
            <button class="slider-arrow next" id="svcNext" onclick="slideMove('svc',1)">&#8594;</button>
        </div>
        <div class="slider-dots" id="svcDots"></div>
        <?php else: ?><div class="empty-state"><div class="icon">💆</div><p>No services available yet.</p></div><?php endif; ?>
    </div></div>
</section>

<section class="spa-section" id="products">
    <div class="section-header">
        <div>
            <p class="section-label">Our Collection</p>
            <h2 class="section-title-spa">Spa <em>Products</em></h2>
        </div>
        <?php if (count($product_categories) > 1): ?>
        <div class="cat-dropdown-wrap">
            <select class="cat-dropdown" id="prdCatDropdown" onchange="filterSliderDropdown('prd', this.value)">
                <?php foreach ($product_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <?php if (!empty($products)): ?>
        <div class="slider-wrapper" id="prdSliderWrap">
            <button class="slider-arrow prev" id="prdPrev" onclick="slideMove('prd',-1)">&#8592;</button>
            <div class="slider-track-outer"><div class="slider-track" id="prdTrack">
                <?php foreach ($products as $prd): $oos = $prd['stock'] <= 0; ?>
                <div class="product-slide" data-category="<?php echo htmlspecialchars($prd['category_name']??''); ?>">
                    <div class="product-img-oval">
                        <img src="uploads/products/<?php echo htmlspecialchars($prd['image']); ?>" 
                        alt="<?php echo htmlspecialchars($prd['name']); ?>" 
                        onerror="this.onerror=null; this.src='uploads/products/default.png';">
                        <?php if ($oos): ?><div class="oos-overlay"><span class="oos-text">Out of Stock</span></div><?php endif; ?>
                    </div>
                    <div class="product-info">
                        <?php if (!empty($prd['category_name'])): ?><span class="product-cat-badge"><?php echo htmlspecialchars($prd['category_name']); ?></span><?php endif; ?>
                        <h3 class="product-name"><?php echo htmlspecialchars($prd['name']); ?></h3>
                        <p class="product-desc"><?php echo htmlspecialchars(substr($prd['description'],0,75)).'...'; ?></p>
                        <div class="product-price-row">
                            <span class="product-price">₱<?php echo number_format($prd['price'],2); ?></span>
                            <span class="product-stock"><?php echo $oos ? '❌ Out of stock' : '✓ '.$prd['stock'].' left'; ?></span>
                        </div>
                        <button class="btn-add-cart" <?php echo $oos?'disabled':''; ?> onclick="openPrdModal(<?php echo $prd['id']; ?>)"><?php echo $oos?'Unavailable':'View Details'; ?></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div></div>
            <button class="slider-arrow next" id="prdNext" onclick="slideMove('prd',1)">&#8594;</button>
        </div>
        <div class="slider-dots" id="prdDots"></div>
        <?php else: ?><div class="empty-state"><div class="icon">🛍️</div><p>No products available yet.</p></div><?php endif; ?>
    </div></div>
</section>

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
                <div><span>💆</span><p>Restore · Renew · Recover</p></div>
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

<section class="spa-section" id="contact">
    <div class="section-header">
        <div>
            <p class="section-label">Get in Touch</p>
            <h2 class="section-title-spa">Contact <em>Us</em></h2>
        </div>
    </div>
    <div class="section-panel"><div class="panel-inner">
        <div class="contact-inner">
            <div class="contact-info-block">
                <h3 class="section-title-spa" style="font-size:1.6rem;margin-bottom:0.5rem;">Visit Us or<br><em>Send a Message</em></h3>
                <p style="color:var(--brown-md);font-size:0.93rem;line-height:1.7;margin-bottom:0.5rem;">We're located in the heart of Iloilo City. Walk in anytime, or send us a message and we'll get back to you.</p>
                <div class="contact-detail"><div class="contact-icon">📍</div><div><h4>Our Location</h4><p>Iloilo City, Philippines<br>Western Visayas</p></div></div>
                <div class="contact-detail"><div class="contact-icon">📞</div><div><h4>Phone / Viber</h4><a href="tel:+639000000000">+63 900 000 0000</a></div></div>
                <div class="contact-detail"><div class="contact-icon">✉️</div><div><h4>Email</h4><a href="mailto:recovery@spa.com">recovery@spa.com</a></div></div>
                <div class="contact-detail"><div class="contact-icon">📱</div><div><h4>Social Media</h4><p>Facebook: Recovery Spa Iloilo<br>Instagram: @recoveryspa</p></div></div>
            </div>
            <div class="hours-box">
                <h4>Operating Hours</h4>
                <div class="hours-row"><span>Monday – Friday</span><span>9:00 AM – 8:00 PM</span></div>
                <div class="hours-row"><span>Saturday</span><span>9:00 AM – 9:00 PM</span></div>
                <div class="hours-row"><span>Sunday</span><span>10:00 AM – 7:00 PM</span></div>
                <div class="hours-row"><span>Holidays</span><span>10:00 AM – 6:00 PM</span></div>
            </div>
        </div>
    </div></div>
</section>

</div><footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <div class="ft-logo">RECOVERY</div>
            <p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#products">Products</a></li>
                <li><a href="#about">About Us</a></li>
                <li><a href="#contact">Contact</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Services</h4>
            <ul>
                <li><a href="#services">Massage Therapy</a></li>
                <li><a href="#services">Nail Care</a></li>
                <li><a href="#services">Lash Services</a></li>
                <li><a href="#services">Facial Treatments</a></li>
                <li><a href="#services">Body Scrubs</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Contact</h4>
            <ul>
                <li><a href="#contact">Iloilo City, Philippines</a></li>
                <li><a href="mailto:recovery@spa.com">recovery@spa.com</a></li>
                <li><a href="tel:+639000000000">+63 900 000 0000</a></li>
                <li><a href="#contact">Mon – Sun: 9AM – 8PM</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>

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
                <span class="modal-meta">📅 <?php echo $svc['slots']; ?> slots/day</span>
            </div>
            <div class="modal-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" style="flex:1;"><?php echo csrf_field(); ?><input type="hidden" name="service_id" value="<?php echo $svc['id']; ?>"><button type="submit" name="book_service" class="btn-modal-primary" style="width:100%;">Book Now</button></form>
                <?php else: ?><a href="user/auth.php" class="btn-modal-primary" style="text-align:center;text-decoration:none;display:block;padding:0.85rem;flex:1;">Login to Book</a><?php endif; ?>
                <button class="btn-modal-secondary" onclick="closeSvcModal(<?php echo $svc['id']; ?>)">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

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
            <div class="modal-price-row"><span class="modal-price">₱<?php echo number_format($prd['price'],2); ?></span><span class="modal-meta">📦 <?php echo $prd['stock']; ?> in stock</span></div>
            <?php if (!$oos): ?>
            <div class="modal-qty-row"><span class="qty-label">Quantity:</span><input type="number" class="qty-input" id="qty<?php echo $prd['id']; ?>" value="1" min="1" max="<?php echo $prd['stock']; ?>" oninput="syncQty(<?php echo $prd['id']; ?>)"></div>
            <div class="modal-actions">
                <form method="POST" style="flex:1;" id="addCartForm<?php echo $prd['id']; ?>"><input type="hidden" name="product_id"><a href="user/auth.php" class="btn-modal-primary" style="width:100%; text-align:center; text-decoration:none; display:block;">🛒 Add to Cart</a>
                                <form method="POST" style="flex:1;" id="addCartForm<?php echo $prd['id']; ?>"><input type="hidden" name="product_id"><a href="user/auth.php" class="btn-modal-primary" style="width:100%; text-align:center; text-decoration:none; display:block;">🛒 Check out</a>

            </div>
            <?php else: ?>
            <div style="background:#f8d7da;color:#842029;padding:0.75rem 1rem;border-radius:10px;font-size:0.88rem;margin-bottom:1rem;">❌ This product is currently out of stock.</div>
            <div class="modal-actions"><button class="btn-modal-secondary" style="flex:1;" onclick="closePrdModal(<?php echo $prd['id']; ?>)">Close</button></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
const sliders = {};
function initSlider(key) {
    const track = document.getElementById(key+'Track');
    const dotsEl = document.getElementById(key+'Dots');
    if (!track) return;
    const outer = track.parentElement;
    sliders[key] = { outer, track, dotsEl, currentPage:0, perPage:getPerPage() };
    setWidths(key); buildDots(key); go(key, 0);
}
function getPerPage() { return window.innerWidth<=600?1:window.innerWidth<=900?2:3; }
function getSlideWidth(key) { const s=sliders[key]; return (s.outer.offsetWidth - 24*(s.perPage-1))/s.perPage; }
function setWidths(key) { const s=sliders[key]; const sw=getSlideWidth(key); if(sw<=0)return; Array.from(s.track.children).forEach(sl=>{ if(sl.style.display!=='none') sl.style.width=sw+'px'; }); }
function visibleSlides(key) { return Array.from(sliders[key].track.children).filter(sl=>sl.style.display!=='none'); }
function totalPages(key) { return Math.ceil(visibleSlides(key).length/sliders[key].perPage); }
function buildDots(key) {
    const s=sliders[key]; const tp=totalPages(key); if(!s.dotsEl)return; s.dotsEl.innerHTML=''; if(tp<=1)return;
    for(let i=0;i<tp;i++){ const d=document.createElement('div'); d.className='dot'+(i===s.currentPage?' active':''); d.onclick=()=>go(key,i); s.dotsEl.appendChild(d); }
}
function go(key, page) {
    const s=sliders[key]; if(!s)return; const tp=totalPages(key);
    s.currentPage=Math.max(0,Math.min(page,tp-1));
    s.track.style.transform=`translateX(-${s.currentPage*s.perPage*(getSlideWidth(key)+24)}px)`;
    if(s.dotsEl) s.dotsEl.querySelectorAll('.dot').forEach((d,i)=>d.classList.toggle('active',i===s.currentPage));
    const prev=document.getElementById(key+'Prev'); const next=document.getElementById(key+'Next');
    if(prev) prev.disabled=s.currentPage===0; if(next) next.disabled=s.currentPage>=tp-1;
}
function slideMove(key,dir) { if(sliders[key]) go(key,sliders[key].currentPage+dir); }
function filterSliderDropdown(key,category) {
    const s=sliders[key]; if(!s)return;
    Array.from(s.track.children).forEach(sl=>{ const cat=sl.getAttribute('data-category')||''; sl.style.display=(category==='All'||cat===category)?'':'none'; });
    s.currentPage=0; setWidths(key); buildDots(key); go(key,0);
}
function openSvcModal(id)  { document.getElementById('svcModal'+id).classList.add('active'); }
function closeSvcModal(id) { document.getElementById('svcModal'+id).classList.remove('active'); }
function openPrdModal(id)  { document.getElementById('prdModal'+id).classList.add('active'); }
function closePrdModal(id) { document.getElementById('prdModal'+id).classList.remove('active'); }
document.querySelectorAll('.spa-modal').forEach(m=>m.addEventListener('click',e=>{ if(e.target===m) m.classList.remove('active'); }));
function syncQty(id) { const h=document.getElementById('cartQty'+id); if(h) h.value=document.getElementById('qty'+id)?.value||1; }
window.addEventListener('load', () => { initSlider('svc'); initSlider('prd'); });
window.addEventListener('resize', () => { ['svc','prd'].forEach(k=>{ if(sliders[k]){ sliders[k].perPage=getPerPage(); setWidths(k); buildDots(k); go(k,sliders[k].currentPage); } }); });
</script>
</body>
</html>