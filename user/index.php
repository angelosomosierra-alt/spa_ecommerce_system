<?php
require_once '../config.php';

// 1. SESSION & LOGOUT LOGIC
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . 'index.php');
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
        $conn->query("CREATE TABLE IF NOT EXISTS `contact_messages` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `email` VARCHAR(150) NOT NULL, `subject` VARCHAR(200) NOT NULL DEFAULT '', `message` TEXT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, '', ?)");
        $stmt->bind_param("sss", $cf_name, $cf_email, $cf_message);
        $stmt->execute();
        $stmt->close();
        $contact_sent = true;
    }
}

// 3. DATA — best sellers + category showcase (Phase 1 helpers in config.php)
$best_services = get_best_selling_services($conn, 10, 90);
$best_products = get_best_selling_products($conn, 10, 90);

$svc_categories = get_customer_categories_with_counts($conn, 'service');
$prd_categories = get_customer_categories_with_counts($conn, 'product');
$cat_icons = [
    'Nail Care' => '💅', 'Nail Extension' => '💅', 'Hair Services' => '💇',
    'Brows Services' => '👁️', 'Facial' => '🧖', 'Japanese Head Spa' => '🧴',
    'Lashes' => '👁️', 'Massage Service' => '💆', 'Body Treatment' => '🧴',
    'Body Scrub' => '🫧', 'Foot Services' => '🦶', 'Waxing Service' => '🪒',
    'Packages' => '🎁', 'Drip Packages' => '💧', 'Other Services' => '✨',
    'Skincare' => '🧴', 'Bath & Body' => '🧼', 'Lotions & Oils' => '💧', 'oils' => '💧',
];
function showcase_icon($name, $icons) { return $icons[$name] ?? '✨'; }

$page_title  = 'Recovery Iloilo — Home';
$active_page = 'home';
require_once 'header.php';
?>
<style>
.hero-scroll-hint { display:none; } /* replaced hero-scroll anchor target below */

.homepage-section .section-header { margin-bottom: 2rem; }

/* ── About / Values / Contact — restored (this CSS lived only in the old
   index.php's inline block, which the redesign's rewrite deleted without
   re-adding it anywhere; these selectors had no base rules at all until
   now, hence Bugs 1/2/3). Page-specific, so kept here rather than in the
   shared stylesheet used by services.php/products.php too. ──────────── */
.about-inner { display:grid; grid-template-columns:1fr 1fr; gap:4rem; align-items:center; }
.about-text p { color:var(--brown-md); font-size:1rem; line-height:1.85; margin-bottom:1rem; }
.about-visual { border-radius:0; aspect-ratio:4/5; display:flex; align-items:center; justify-content:center; text-align:center; padding:0; }
.stats-bar { display:grid; grid-template-columns:repeat(4,1fr); background:#3B2A1A; border-radius:16px; overflow:hidden; margin-top:2rem; }
.stat-item { padding:1.5rem 1rem; text-align:center; border-right:1px solid rgba(200,164,107,0.2); }
.stat-item:last-child { border-right:none; }
.stat-num { font-family:'Cormorant Garamond',serif; font-size:2.1rem; color:#C8A46B; line-height:1; }
.stat-lbl { font-size:0.85rem; color:#EAD8C0; margin-top:0.35rem; text-transform:uppercase; letter-spacing:0.05em; }
.values-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1.25rem; margin-top:2.5rem; }
.value-card { background:var(--warm); border-radius:14px; padding:1.75rem 1.25rem; text-align:center; border:1px solid var(--border); transition:transform 0.2s,box-shadow 0.2s; }
.value-card:hover { transform:translateY(-4px); box-shadow:var(--shadow); }
.value-card .vi { font-size:2.1rem; margin-bottom:0.75rem; display:block; }
.value-card h3  { color:var(--brown); font-size:1.05rem; font-weight:700; margin-bottom:0.4rem; }
.value-card p   { color:var(--gray); font-size:0.92rem; line-height:1.6; }

.contact-inner { display:grid; grid-template-columns:1fr 1.3fr; gap:3.5rem; align-items:start; }
.contact-info-block { display:flex; flex-direction:column; gap:1.25rem; }
.contact-detail { display:flex; align-items:flex-start; gap:1rem; }
.contact-icon { width:44px; height:44px; border-radius:10px; background:var(--warm); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.25rem; flex-shrink:0; }
.contact-detail h4 { font-size:0.9rem; font-weight:700; color:var(--brown); margin-bottom:0.2rem; }
.contact-detail p, .contact-detail a { font-size:0.95rem; color:var(--gray); line-height:1.5; text-decoration:none; }
.contact-detail a:hover { color:var(--rust-dark); }
.hours-box { background:#3B2A1A; border-radius:14px; padding:1.25rem 1.5rem; margin-top:1rem; }
.hours-box h4 { font-size:0.78rem; letter-spacing:0.12em; text-transform:uppercase; color:#C8A46B; font-weight:700; margin-bottom:0.85rem; }
.hours-row { display:flex; justify-content:space-between; font-size:0.92rem; padding:0.4rem 0; border-bottom:1px solid rgba(200,164,107,0.15); }
.hours-row:last-child { border-bottom:none; }
.hours-row span:first-child { color:#C8A46B; }
.hours-row span:last-child  { color:#EAD8C0; font-weight:500; }
.contact-form-card { background:var(--white); border-radius:18px; padding:2rem; box-shadow:var(--shadow); border:1px solid var(--border); }
.contact-form-card h3 { font-family:'Cormorant Garamond',serif; font-size:1.6rem; font-weight:400; color:var(--brown); margin-bottom:0.3rem; }
.contact-form-card > p { font-size:0.95rem; color:var(--gray); margin-bottom:1.5rem; }
.cf-group { margin-bottom:1.1rem; }
.cf-group label { display:block; font-size:0.82rem; font-weight:700; color:var(--brown-md); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.5rem; }
.cf-group input,.cf-group select,.cf-group textarea { width:100%; padding:0.8rem 0.95rem; border:1.5px solid var(--border); border-radius:10px; font-family:'DM Sans',sans-serif; font-size:1rem; color:var(--brown); background:var(--warm); outline:none; transition:border-color 0.2s,box-shadow 0.2s; }
.cf-group input:focus,.cf-group select:focus,.cf-group textarea:focus { border-color:var(--rust-dark); box-shadow:0 0 0 3px rgba(169,79,29,0.15); background:#fff; }
.cf-group textarea { resize:vertical; min-height:120px; }
.cf-row { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }
.btn-send { width:100%; padding:0.95rem; background:linear-gradient(135deg,#C96A2C,#A94F1D); color:#fff; border:none; border-radius:12px; font-size:1.02rem; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; transition:opacity 0.2s,transform 0.2s; margin-top:0.5rem; }
.btn-send:hover { opacity:0.9; transform:translateY(-1px); }
.contact-success { text-align:center; padding:2rem 1rem; }
.contact-success span { font-size:3rem; display:block; margin-bottom:0.75rem; }
.contact-success h3 { color:var(--brown); font-size:1.3rem; margin-bottom:0.5rem; }
.contact-success p  { color:var(--gray); font-size:0.95rem; }
.alert-form-error { background:#FEE2E2; color:#991B1B; border-radius:8px; padding:0.7rem 0.95rem; font-size:0.92rem; margin-bottom:1rem; border-left:3px solid #dc3545; }

@media (max-width: 900px) {
    .about-inner, .contact-inner { grid-template-columns: 1fr; gap: 2rem; }
    .stats-bar { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 560px) {
    .cf-row { grid-template-columns: 1fr; }
}

/* ── Best-Sellers carousel (services + products) ─────────────────────────
   Structure/JS pattern adapted from the old per-category service slider
   this codebase used to have (track + translateX, arrows, dots) rather
   than inventing a new mechanism — extended here with autoplay, swipe,
   hover-pause and prefers-reduced-motion support none of the old sliders
   needed. ────────────────────────────────────────────────────────────── */
.bs-carousel { position: relative; display: flex; align-items: center; gap: 0.85rem; margin-top: 1.5rem; }
.bs-viewport { overflow: hidden; flex: 1; min-width: 0; }
/* ── Fanned/overlapping card stack — like a hand of playing cards laid out
   sideways: each card overlaps the next (negative margin instead of a
   gap), so more/bigger cards fit in the same width, and the centered card
   sits on top of its neighbors (z-index + slight scale-up in JS) rather
   than every card sitting flat in its own separate slot. ──────────────── */
.bs-track { display: flex; gap: 0; transition: transform 0.55s cubic-bezier(.4,0,.2,1), opacity 0.35s ease; will-change: transform, opacity; }
.bs-track.bs-fading { opacity: 0.25; }
.bs-slide {
    flex: 0 0 28%; display: block; position: relative;
    margin-left: -3rem; /* the overlap — how much of the previous card this one covers */
    /* Flex items default to min-width:auto, so a long single-line product
       name (white-space:nowrap below) could force THAT ONE slide wider
       than its flex-basis instead of truncating — exactly what made one
       card visibly wider than its neighbors and threw the centered/
       highlighted card out of alignment. */
    min-width: 0;
    /* Spotlight: opacity/scale/z-index all recalculated in JS on every
       render/resize so the centered card reads as "on top" of the fan. */
    opacity: 1; transition: opacity 0.4s ease, transform 0.4s ease;
}
.bs-slide:first-child { margin-left: 0; }
@media (max-width: 900px) { .bs-slide { flex: 0 0 58%; margin-left: -1.75rem; } } /* bigger cards on mobile too, still overlapping */
/* Center card reads as the clear focal point — stronger lift + a thin gold
   edge, not just the scale-up JS already applies to every card by distance,
   matching the reference mockup where the middle card is unmistakably "it". */
.bs-slide.bs-active .bs-photo-card {
    box-shadow: 0 26px 50px rgba(20,12,6,0.4);
    outline: 3px solid var(--gold);
    outline-offset: -3px;
}

/* ── Photo card: image on top, white body below with price/name/short
   description/action button — the "ELIZA"-style reference layout, not the
   text-on-image treatment this carousel used before. ────────────────────── */
.bs-photo-card {
    background: var(--white); border-radius: 14px; overflow: hidden;
    box-shadow: 0 14px 34px rgba(20,12,6,0.22);
    transition: transform 0.35s cubic-bezier(.4,0,.2,1), box-shadow 0.35s;
    display: flex; flex-direction: column; height: 100%;
}
.bs-slide:hover .bs-photo-card { transform: translateY(-6px); box-shadow: 0 22px 46px rgba(20,12,6,0.3); }
.bs-photo-img-wrap { position: relative; aspect-ratio: 4/3; overflow: hidden; background: var(--warm); }
.bs-photo-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.6s ease; }
.bs-slide:hover .bs-photo-img-wrap img { transform: scale(1.07); }
.bs-photo-badge {
    position: absolute; top: 0.75rem; left: 0.75rem; z-index: 2;
    background: rgba(59,42,26,0.8); backdrop-filter: blur(6px);
    color: #fff; font-size: 1.15rem; font-weight: 600; letter-spacing: 0.08em;
    text-transform: uppercase; padding: 0.3rem 0.7rem; border-radius: 50px;
    max-width: calc(100% - 1.5rem); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bs-photo-body { padding: 1.2rem 1.3rem 1.3rem; display: flex; flex-direction: column; flex: 1; }
/* Enlarged for senior/low-vision readability — price and name are the two
   things a customer actually needs to read at a glance, so both get a
   meaningfully bigger, bolder treatment than the rest of the card. */
.bs-photo-price { font-size: 1.35rem; font-weight: 800; color: var(--rust-dark); margin-bottom: 0.3rem; }
.bs-photo-name {
    font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 1.4rem;
    color: var(--brown); line-height: 1.2; margin-bottom: 0.5rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* Description removed from these cards entirely (per request) — price,
   name, and the action button are enough; .bs-photo-desc markup is gone
   from the two carousels below, this selector is kept only in case
   something else still references it. */
.bs-photo-desc { display: none; }
.bs-photo-btn {
    display: block; text-align: center; padding: 0.8rem 0.5rem;
    background: linear-gradient(135deg, var(--gold), var(--gold)); color: #2b1c0d;
    border-radius: 8px; font-weight: 700; font-size: 0.85rem; letter-spacing: 0.05em;
    text-transform: uppercase; text-decoration: none; transition: filter 0.2s, transform 0.15s;
}
.bs-photo-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }
.bs-photo-img-wrap .oos-overlay { z-index: 3; }
@media (max-width: 900px) {
    /* 3 narrow cards leaves very little width each — the 1.75rem track gap
       alone was eating a big share of it (confirmed by measuring: only
       ~90px per card at 375px width, causing even short 5-letter names
       like "Chinn" to hit the ellipsis). Tighten the gap and simplify each
       card's content instead of shrinking text past legibility. */
    .bs-track { gap: 0.45rem; }
    /* Unusually long names (e.g. "The Pamper Set (Holiday Gift Bag)...")
       otherwise wrap unbounded here and make one card much taller than its
       row-mates — 2-line clamp keeps card heights consistent. Sizes bumped
       up from 0.88rem for the same senior/low-vision readability request,
       balanced against the tighter gap above so cards don't shrink further. */
    .bs-photo-name {
        font-size: 1.02rem; line-height: 1.15;
        white-space: normal; display: -webkit-box; width: 100%;
        -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .bs-photo-price { font-size: 1.02rem; }
    .bs-photo-badge { font-size: 0.58rem; padding: 0.22rem 0.55rem; top: 0.5rem; left: 0.5rem; }
    .bs-photo-body { padding: 0.65rem 0.6rem 0.7rem; }
    .bs-photo-btn { font-size: 0.64rem; padding: 0.55rem 0.3rem; }
}
/* Arrows/dots/CTA now sit on the dark photo band, so they use gold tones
   (matching the reference) rather than the brown used when this carousel
   sat on the plain page background. */
.bs-arrow {
    flex-shrink: 0; width: 46px; height: 46px; border-radius: 50%;
    background: linear-gradient(135deg, var(--gold), var(--gold-dark)); color: #2b1c0d; border: none; cursor: pointer;
    font-size: 1.4rem; line-height: 1; display: flex; align-items: center; justify-content: center;
    transition: filter 0.2s, transform 0.15s; box-shadow: 0 4px 14px rgba(0,0,0,0.35);
}
.bs-arrow:hover { filter: brightness(1.08); transform: scale(1.07); }
.bs-arrow:disabled { opacity: 0.4; cursor: default; transform: none; }

.bs-dots { display: flex; justify-content: center; align-items: center; gap: 0.6rem; margin-top: 1.5rem; }
.bs-dot {
    width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,0.4);
    border: none; cursor: pointer; padding: 0; transition: background 0.2s, width 0.2s, height 0.2s, box-shadow 0.2s;
}
/* assets/responsive.css has a mobile "44px touch target minimum" rule
   (`.btn, button:not([style*="width:28px"])...{ min-height: 44px }`) whose
   compounded :not() selectors outrank a plain `.bs-dot` class on
   specificity alone, so it kept winning and stretching these dots into
   tall ovals on phone widths even with min-height set. Scoped selector +
   !important to reliably beat it — dots are a decorative index, not a
   primary touch target. */
.bs-dots .bs-dot { min-height: 0 !important; min-width: 0 !important; }
/* Small circle throughout (not an elongated pill) — active state still
   changes more than color alone: it's a touch larger with a soft gold ring
   around it, not just a different fill. */
.bs-dot.active { background: var(--gold); width: 11px; height: 11px; box-shadow: 0 0 0 3px rgba(240,201,135,0.4); }

.bs-cta-wrap { text-align: center; margin-top: 2rem; }
.bs-cta-btn {
    display: inline-block; padding: 1rem 2.75rem; background: linear-gradient(135deg, var(--gold), var(--gold-dark)); color: #2b1c0d;
    border-radius: 50px; font-weight: 700; font-size: 1.05rem; text-decoration: none;
    transition: filter 0.2s, transform 0.2s; box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}
.bs-cta-btn:hover { filter: brightness(1.08); transform: translateY(-2px); }

@media (max-width: 900px) {
    /* Arrows beside the viewport eat a big share of a narrow screen's width
       (confirmed by measuring the live rendered card during testing — with
       3 cards now visible on mobile too, the same crowding risk applies).
       Overlay them on the photo band instead; swipe/dots remain the
       primary controls. */
    .bs-carousel { gap: 0; }
    .bs-arrow {
        position: absolute; top: 50%; transform: translateY(-50%); z-index: 4;
        width: 36px; height: 36px; font-size: 1.1rem;
        background: rgba(20,12,6,0.55); backdrop-filter: blur(3px);
    }
    .bs-arrow:hover { transform: translateY(-50%) scale(1.07); }
    .bs-prev { left: 0.5rem; }
    .bs-next { right: 0.5rem; }
}

/* ── Full-bleed photo band behind each carousel — like a mini hero banner,
   matching the reference design, instead of the flat page background.
   Breaks out to the viewport edges via the negative-margin trick (safe:
   scoped to this element only, nothing else on the page is affected).
   Same base photo as .hero for zero extra asset risk, but a different
   color-wash overlay per section (warm gold vs. terracotta) so Services
   and Products stay visually distinguishable as requested. ─────────────── */
.bs-panel {
    position: relative; left: 50%; right: 50%; margin-left: -50vw; margin-right: -50vw;
    width: 100vw; padding: 3.5rem 1.5rem 3rem;
    background-size: cover; background-position: center;
}
/* An even, uniformly dark wash — the source photo has a bright patch (the
   oil-pouring hand) that sat off-center and made the left side of the band
   read as "highlighted"/lopsided against the cards. A flat, strong overlay
   (instead of the lighter vertical-only gradient this had before) hides the
   photo's own uneven lighting so the band reads as a calm, even backdrop. */
.bs-panel-services { background-image:
    linear-gradient(rgba(37,22,8,0.86), rgba(37,22,8,0.86)),
    url('https://images.unsplash.com/photo-1544161515-4ab6ce6db874?w=1600&q=80'); }
.bs-panel-products { background-image:
    linear-gradient(rgba(42,18,8,0.86), rgba(42,18,8,0.86)),
    url('https://images.unsplash.com/photo-1526947425960-945c6e72858f?w=1600&q=80'); }
.bs-panel .section-header .section-label { color: #F0C987; }
.bs-panel .section-header .section-title-spa { color: #fff; }
.bs-panel .section-header .section-title-spa em { color: #F0C987; }
@media (max-width: 620px) { .bs-panel { padding: 2.5rem 1rem 2rem; } }

/* ── Ornamental divider between the Services and Products carousels —
   a thin gold gradient rule with a centered mark, blending with the
   site's existing brown/gold palette rather than a plain <hr>. ────────── */
.theme-divider {
    display: flex; align-items: center; gap: 1.25rem;
    max-width: 100%;
    padding: 0 1rem;
    background: linear-gradient(160deg, var(--white), var(--gold), var(--white));


}
.theme-divider::before, .theme-divider::after {
    content: ""; flex: 1; height: 1px;
    background: linear-gradient(to right, transparent, var(--gold) 50%, transparent);
}
.theme-divider::after { background: linear-gradient(to left, transparent, var(--gold) 50%, transparent); }
.theme-divider-mark { display: none; }
</style>

<section class="hero" id="index">
    <p class="hero-eyebrow">Welcome to RECOVERY ILOILO</p>
    <h1>Skin <em>and</em> Wellness</h1>
    <p>Experience the ultimate spa and wellness journey — where every treatment is a ritual of renewal.</p>
    <div class="hero-ctas">
        <a href="services.php" class="hero-btn-primary">Book a Service</a>
        <a href="products.php" class="hero-btn-outline">Shop Products</a>
    </div>
</section>

<div class="spa-container">
<div class="theme-divider" role="separator" aria-hidden="true"><span class="theme-divider-mark">✦</span></div>

<!-- ── BEST SELLERS: SERVICES ─────────────────────────────────────────────── -->
<section class="homepage-section" id="best-services">
    <div class="bs-panel bs-panel-services">
    <div class="section-header">
        <div>
            <p class="section-label">Customer Favorites</p>
            <h2 class="section-title-spa">Best-Selling <em>Services</em></h2>
        </div>
    </div>
    <?php if (!empty($best_services)): ?>
    <div class="bs-carousel" data-bs-key="services">
        <button type="button" class="bs-arrow bs-prev" aria-label="Previous service">‹</button>
        <div class="bs-viewport">
            <div class="bs-track">
                <?php foreach ($best_services as $svc): ?>
                <div class="bs-slide">
                <div class="bs-photo-card">
                    <div class="bs-photo-img-wrap">
                        <?php if (!empty($svc['image'])): ?>
                        <img src="<?php echo BASE_URL; ?>uploads/services/<?php echo htmlspecialchars($svc['image']); ?>" alt="<?php echo htmlspecialchars($svc['name']); ?>" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="img-placeholder" style="display:none">💆<small>Spa Service</small></div>
                        <?php else: ?>
                        <div class="img-placeholder">💆<small>Spa Service</small></div>
                        <?php endif; ?>
                        <?php if (!empty($svc['category_name'])): ?><span class="bs-photo-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span><?php endif; ?>
                    </div>
                    <div class="bs-photo-body">
                        <div class="bs-photo-price">₱<?php echo number_format($svc['price'],2); ?></div>
                        <h3 class="bs-photo-name"><?php echo htmlspecialchars($svc['name']); ?></h3>
                        <a class="bs-photo-btn" href="services.php?category=<?php echo (int)($svc['category_id'] ?? 0); ?>&item=svc-<?php echo $svc['id']; ?>">Book Service</a>
                    </div>
                </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="bs-arrow bs-next" aria-label="Next service">›</button>
    </div>
    <div class="bs-dots" data-bs-dots="services"></div>
    <?php endif; ?>
    <div class="bs-cta-wrap"><a href="services.php" class="bs-cta-btn">View Services</a></div>
    </div>
</section>

<!-- ── DIVIDER ──────────────────────────────────────────────────────────────── -->
<div class="theme-divider" role="separator" aria-hidden="true"><span class="theme-divider-mark">✦</span></div>

<!-- ── BEST SELLERS: PRODUCTS ──────────────────────────────────────────────── -->
<section class="homepage-section" id="best-products">
    <div class="bs-panel bs-panel-products">
    <div class="section-header">
        <div>
            <p class="section-label">Take It Home</p>
            <h2 class="section-title-spa">Best-Selling <em>Products</em></h2>
        </div>
    </div>
    <?php if (!empty($best_products)): ?>
    <div class="bs-carousel" data-bs-key="products">
        <button type="button" class="bs-arrow bs-prev" aria-label="Previous product">‹</button>
        <div class="bs-viewport">
            <div class="bs-track">
                <?php foreach ($best_products as $prd): $oos = $prd['stock'] <= 0; ?>
                <div class="bs-slide">
                <div class="bs-photo-card">
                    <div class="bs-photo-img-wrap">
                        <?php if (!empty($prd['image'])): ?>
                        <img src="<?php echo BASE_URL; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>" alt="<?php echo htmlspecialchars($prd['name']); ?>" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="img-placeholder" style="display:none">🧴<small>Spa Product</small></div>
                        <?php else: ?>
                        <div class="img-placeholder">🧴<small>Spa Product</small></div>
                        <?php endif; ?>
                        <?php if ($oos): ?><div class="oos-overlay"><span class="oos-text">Out of Stock</span></div><?php endif; ?>
                        <?php if (!empty($prd['category_name'])): ?><span class="bs-photo-badge"><?php echo htmlspecialchars($prd['category_name']); ?></span><?php endif; ?>
                    </div>
                    <div class="bs-photo-body">
                        <div class="bs-photo-price">₱<?php echo number_format($prd['price'],2); ?></div>
                        <h3 class="bs-photo-name"><?php echo htmlspecialchars($prd['name']); ?></h3>
                        <a class="bs-photo-btn" href="products.php?category=<?php echo (int)($prd['category_id'] ?? 0); ?>&item=prd-<?php echo $prd['id']; ?>"><?php echo $oos ? 'Out of Stock' : 'Shop Now'; ?></a>
                    </div>
                </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="bs-arrow bs-next" aria-label="Next product">›</button>
    </div>
    <div class="bs-dots" data-bs-dots="products"></div>
    <?php endif; ?>
    <div class="bs-cta-wrap"><a href="products.php" class="bs-cta-btn">View Products</a></div>
    </div>
</section>

<!-- ── EXPLORE CATEGORIES ──────────────────────────────────────────────────── -->
<section class="homepage-section" id="categories">
    <div class="section-header">
        <div>
            <p class="section-label">The Full Menu</p>
            <h2 class="section-title-spa">Explore Our <em>Categories</em></h2>
        </div>
    </div>
    <div class="category-showcase-grid">
        <?php foreach ($svc_categories as $cat): ?>
        <a class="category-tile" href="services.php?category=<?php echo (int)$cat['id']; ?>">
            <?php if (!empty($cat['sample_image'])): ?>
            <img class="category-tile-img" src="<?php echo BASE_URL; ?>uploads/services/<?php echo htmlspecialchars($cat['sample_image']); ?>" alt="" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="category-tile-icon" style="display:none;"><?php echo showcase_icon($cat['name'], $cat_icons); ?></div>
            <?php else: ?>
            <div class="category-tile-icon"><?php echo showcase_icon($cat['name'], $cat_icons); ?></div>
            <?php endif; ?>
            <span class="category-tile-name"><?php echo htmlspecialchars($cat['name']); ?></span>
            <span class="category-tile-count"><?php echo (int)$cat['item_count']; ?> service<?php echo $cat['item_count'] == 1 ? '' : 's'; ?></span>
        </a>
        <?php endforeach; ?>
        <?php foreach ($prd_categories as $cat): ?>
        <a class="category-tile" href="products.php?category=<?php echo (int)$cat['id']; ?>">
            <?php if (!empty($cat['sample_image'])): ?>
            <img class="category-tile-img" src="<?php echo BASE_URL; ?>uploads/products/<?php echo htmlspecialchars($cat['sample_image']); ?>" alt="" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="category-tile-icon" style="display:none;"><?php echo showcase_icon($cat['name'], $cat_icons); ?></div>
            <?php else: ?>
            <div class="category-tile-icon"><?php echo showcase_icon($cat['name'], $cat_icons); ?></div>
            <?php endif; ?>
            <span class="category-tile-name"><?php echo htmlspecialchars($cat['name']); ?></span>
            <span class="category-tile-count"><?php echo (int)$cat['item_count']; ?> product<?php echo $cat['item_count'] == 1 ? '' : 's'; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
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
                <div style="text-align:center;">
                    <video autoplay loop muted playsinline
                           style="width:70%; max-width:430px; border-radius:15px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <source src="<?php echo BASE_URL; ?>img/for_contactus.mp4" type="video/mp4">
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

<!-- ── CONTACT SECTION ─────────────────────────────────────────────────────── -->
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
                    <p style="color:var(--brown-md);font-size:1rem;line-height:1.7;margin-bottom:0.5rem;">We're located in the heart of Iloilo City. Walk in anytime, or send us a message and we'll get back to you.</p>
                    <div class="contact-detail"><div class="contact-icon">📍</div><div><h4>Our Location</h4><p>G&amp;R Building, M.H. Del Pilar Street, Molo, Iloilo City</p></div></div>
                    <div class="contact-detail"><div class="contact-icon">📞</div><div><h4>Phone / Viber</h4><a href="tel:+639853359998">+639853359998</a></div></div>
                    <div class="contact-detail"><div class="contact-icon">✉️</div><div><h4>Email</h4><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></div></div>
                    <div class="contact-detail"><div class="contact-icon">📱</div><div><h4>Social Media</h4><p><a class="social-link" href="https://www.facebook.com/RecoveryIloilo" target="_blank" rel="noopener noreferrer">Facebook: Recovery Spa Iloilo</a><br><a class="social-link" href="https://www.instagram.com/recoveryiloilo/" target="_blank" rel="noopener noreferrer">Instagram: @recoveryiloilo</a></p></div></div>
                </div>
                <div class="hours-box">
                    <h4>Operating Hours</h4>
                    <div class="hours-row"><span>Monday – Friday</span><span>9:00 AM – 10:00 PM</span></div>
                    <div class="hours-row"><span>Saturday – Sunday</span><span>9:00 AM – 12:00 AM</span></div>
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
                    <div class="cf-row">
                        <div class="cf-group"><label>Your Name *</label><input type="text" name="cf_name" required placeholder="Juan dela Cruz"></div>
                        <div class="cf-group"><label>Email *</label><input type="email" name="cf_email" required placeholder="you@email.com"></div>
                    </div>
                    <div class="cf-group"><label>Message *</label><textarea name="cf_message" required placeholder="Write your message here..."></textarea></div>
                    <button type="submit" name="send_message" class="btn-send">✉️ Send Message</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div></div>
</section>

</div>

<?php require_once '../footer.php'; ?>
<script>
/* ── Best-Sellers carousel: autoplay + arrows + dots + swipe + hover-pause,
   with prefers-reduced-motion disabling autoplay (manual controls still
   work). One instance per data-bs-key carousel on the page. ──────────── */
(function () {
    "use strict";
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var AUTOPLAY_MS = 4000; // ~4s per card, as requested

    function initCarousel(root) {
        var track      = root.querySelector('.bs-track');
        var realSlides = Array.prototype.slice.call(track.children);
        var prevBtn    = root.querySelector('.bs-prev');
        var nextBtn    = root.querySelector('.bs-next');
        var key        = root.dataset.bsKey;
        var dotsWrap   = document.querySelector('[data-bs-dots="' + key + '"]');
        if (!realSlides.length) return;

        // True infinite loop: a full clone of the set is appended before AND
        // after the real slides (so track = [clone][real][clone]). Moving
        // "past" the real set just scrolls into a clone that looks identical
        // to the real start/end — once the slide transition finishes we
        // silently snap the position back by one real-set-length with
        // transitions off, invisibly, so it can keep going forever without
        // ever visibly resetting to card #1. ─────────────────────────────
        var REAL = realSlides.length;
        realSlides.forEach(function (s) { track.appendChild(s.cloneNode(true)); });      // trailing clone
        // Build in reverse before inserting: each insertBefore(..., firstChild) puts
        // the new node at position 0, so inserting [1,2,3,4,5] in that order ends up
        // reversed to [5,4,3,2,1] — making the card immediately before slide #1 a
        // clone of #1 itself instead of #5. Reversing the array first corrects this.
        var leadingClones = realSlides.map(function (s) { return s.cloneNode(true); }).reverse();
        leadingClones.forEach(function (s) { track.insertBefore(s, track.firstChild); }); // leading clone
        var slides = Array.prototype.slice.call(track.children); // length = 3 * REAL

        var pos = REAL; // start on the real set's first card
        var timer = null;
        var resumeTimer = null;
        var viewportEl = root.querySelector('.bs-viewport');

        // Real measurements from the DOM (not a fixed 5/3 guess) — the old
        // constant assumed the CSS flex-basis and the guess were kept in
        // sync, which broke the moment cards became wider (30%) than the
        // guess implied for this overlapping "fan" layout.
        //
        // step is read from two real adjacent slides' actual offsetLeft
        // (not computed as cardW + marginLeft): the flex layout algorithm
        // distributes sub-pixel rounding across items when a percentage
        // flex-basis doesn't divide the container evenly, so an item's
        // rendered width/step can be a fraction of a pixel off the
        // theoretical cardW+margin value. That per-card drift compounded
        // over a dozen-plus slides threw the "centered" card off by 100px+
        // — reading the real gap between two live slides sidesteps it.
        function measure() {
            var slide = slides[1] || slides[0];
            var cardW = slide.offsetWidth;
            var next = slides[2] || slide;
            var step = (next.offsetLeft - slide.offsetLeft) || cardW || 1;
            var vpWidth = viewportEl ? viewportEl.getBoundingClientRect().width : 0;
            return { cardW: cardW, step: step, vpWidth: vpWidth };
        }

        function applySpotlight() {
            // Cards nearer the active card are full opacity AND sit on top of
            // the overlapping fan (higher z-index) at full scale; ones toward
            // either edge dim, shrink more, and tuck underneath — like the
            // reference mockup, where only the center card reads clearly and
            // its neighbors are visibly smaller, muted, half-hidden behind it.
            // centerIndex is simply `pos` now: applyTransform() below places
            // slide `pos`'s center exactly on the viewport's center by
            // construction, so there's no separate formula to keep in sync
            // (the earlier derived-formula approach left a small residual
            // offset whenever the ideal center index wasn't an integer).
            var m = measure();
            var vis = m.step ? m.vpWidth / m.step : 3;
            var maxDist = Math.max(1, vis) / 2;
            slides.forEach(function (slide, i) {
                var dist = Math.min(1, Math.abs(i - pos) / maxDist);
                slide.style.opacity = String(1 - dist * 0.55);
                slide.style.zIndex = String(Math.round((1 - dist) * 100));
                slide.style.transform = 'scale(' + (1 - dist * 0.22) + ')';
                slide.classList.toggle('bs-active', i === pos);
            });
        }

        function activeDotIndex() {
            // Normalize pos (which can drift into clone territory before a
            // silent snap-back) into the 0..REAL-1 range dots represent.
            return ((pos - REAL) % REAL + REAL) % REAL;
        }

        function applyTransform() {
            // Step distance is card width MINUS the overlap (a negative
            // margin-left on every slide but the first) — cards no longer
            // sit in a gapped row, so the old columnGap-based math would
            // step by a full card width and skip past the fanned overlap.
            // measure() uses offsetWidth (not getBoundingClientRect, which
            // reflects the scale() transform applySpotlight() applies per
            // card) — mixing a post-scale measurement into this math made
            // the step size drift depending on which slide happened to get
            // measured and its current spotlight scale, throwing the
            // centered card's actual screen position off from where the
            // spotlight math thought it was.
            //
            // Positioned so the ACTIVE slide's own center lands exactly on
            // the viewport's center. Uses the active slide's REAL offsetLeft
            // (not pos*step) — with 30 slides in the track, per-card
            // sub-pixel rounding from the flex-basis percentage layout
            // compounds across that many items and made a pos*step estimate
            // drift by 100px+ from the slide's true rendered position.
            // Reading the live layout value directly has no such drift.
            var m = measure();
            var activeSlide = slides[pos] || slides[0];
            var offset = activeSlide.offsetLeft + activeSlide.offsetWidth / 2 - m.vpWidth / 2;
            track.style.transform = 'translateX(-' + offset + 'px)';
            if (dotsWrap) {
                var active = activeDotIndex();
                Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
                    d.classList.toggle('active', i === active);
                });
            }
            applySpotlight();
        }

        // After the slide settles, if we've drifted a full lap into either
        // clone zone, jump back by one real-set-length with transitions
        // suspended — imperceptible, since the clone is pixel-identical to
        // the real set it stands in for.
        function correctLoopPosition() {
            if (pos >= REAL * 2 || pos < REAL) {
                var wasFading = track.classList.contains('bs-fading');
                track.style.transition = 'none';
                pos = ((pos - REAL) % REAL + REAL) % REAL + REAL;
                applyTransform();
                // Force layout so the transition:none actually takes effect
                // before restoring it, or the snap itself would animate.
                void track.offsetHeight;
                track.style.transition = '';
                if (wasFading) track.classList.remove('bs-fading');
            }
        }

        // Cards fade out briefly, reposition, then fade back in — a calm
        // crossfade on every advance rather than a flat slide. Skipped for
        // the very first paint and (per prefers-reduced-motion) reduced to
        // an instant position change with no fade.
        function render(animate) {
            if (!animate || reduceMotion) { applyTransform(); correctLoopPosition(); return; }
            track.classList.add('bs-fading');
            setTimeout(function () {
                applyTransform();
                setTimeout(function () { track.classList.remove('bs-fading'); }, 30);
                // Wait for the FULL 0.55s transform transition to actually
                // finish before silently snapping the loop position back —
                // firing this early (it used to run only 30ms after the
                // transform started) cut the slide animation short right in
                // the middle whenever a wrap-around happened, which is
                // exactly what looked "not smooth" going from the last card
                // back to the first.
                setTimeout(correctLoopPosition, 580);
            }, 260);
        }

        function buildDots() {
            if (!dotsWrap) return;
            dotsWrap.innerHTML = '';
            for (var i = 0; i < REAL; i++) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'bs-dot' + (i === 0 ? ' active' : '');
                b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                (function (idx) { b.addEventListener('click', function () { goTo(REAL + idx); restartAutoplay(); }); })(i);
                dotsWrap.appendChild(b);
            }
        }

        function goTo(newPos, animate) {
            pos = newPos;
            render(animate !== false);
        }
        function next() { goTo(pos + 1); } // always advances — wraps forever, never stops at the last card
        function prev() { goTo(pos - 1); } // always retreats — wraps forever, never stops at the first card

        function startAutoplay() {
            if (reduceMotion) return; // disabled entirely, not just slowed — manual controls only
            stopAutoplay();
            timer = setInterval(next, AUTOPLAY_MS);
        }
        function stopAutoplay() { if (timer) { clearInterval(timer); timer = null; } }
        function restartAutoplay() {
            stopAutoplay();
            clearTimeout(resumeTimer);
            resumeTimer = setTimeout(startAutoplay, AUTOPLAY_MS);
        }

        if (prevBtn) prevBtn.addEventListener('click', function () { prev(); restartAutoplay(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { next(); restartAutoplay(); });

        // Pause on hover (desktop) — reading a card shouldn't be interrupted.
        root.addEventListener('mouseenter', stopAutoplay);
        root.addEventListener('mouseleave', startAutoplay);

        // Touch/swipe — swiping overrides autoplay briefly, then resumes.
        var touchStartX = null;
        track.addEventListener('touchstart', function (e) {
            touchStartX = e.touches[0].clientX;
            stopAutoplay();
        }, { passive: true });
        track.addEventListener('touchend', function (e) {
            if (touchStartX === null) return;
            var dx = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); }
            touchStartX = null;
            restartAutoplay();
        }, { passive: true });

        window.addEventListener('resize', function () { render(false); });

        buildDots();
        render(false);
        startAutoplay();
    }

    document.querySelectorAll('.bs-carousel').forEach(initCarousel);
}());
</script>
</body>
</html>
