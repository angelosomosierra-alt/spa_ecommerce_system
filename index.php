<?php
// Shared by index.php and user/index.php (which just requires this file),
// so every path here is absolute (__DIR__ / BASE_URL).
require_once __DIR__ . '/config.php';

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

// 3. DATA — category picture tiles, most-booked services, shop teaser
// A logged-in customer browses the account-area copies (user/*.php), same
// rule footer.php uses.
$home_base = (is_logged_in() && !is_admin()) ? BASE_URL . 'user/' : BASE_URL;

// Category tiles: the 5 biggest service categories, then "See all".
$svc_categories = get_customer_categories_with_counts($conn, 'service');
usort($svc_categories, function ($a, $b) { return (int)$b['item_count'] <=> (int)$a['item_count']; });
$home_tiles = array_slice($svc_categories, 0, 5);

$best_services = get_best_selling_services($conn, 4, 90);
$best_products = get_best_selling_products($conn, 4, 90);

// "1 hour 30 minutes" reads easier than "90 min".
function home_minutes_label(int $m): string {
    if ($m <= 0) return '';
    $h = intdiv($m, 60); $r = $m % 60; $out = [];
    if ($h) $out[] = $h . ($h === 1 ? ' hour' : ' hours');
    if ($r) $out[] = $r . ' minutes';
    return implode(' ', $out);
}

// Price, length and description for each most-booked service. Duration
// options (service_durations) win over the service's own price, the same
// way Book Now prices them; promos use the shared active-price resolver.
$home_cards = [];
if ($best_services) {
    $ids = implode(',', array_map(function ($s) { return (int)$s['id']; }, $best_services));
    $info = [];
    $res = $conn->query("SELECT * FROM services WHERE id IN ($ids)");
    while ($r = $res->fetch_assoc()) $info[(int)$r['id']] = $r;

    $durs = [];
    $has_durs = $conn->query("SHOW TABLES LIKE 'service_durations'");
    if ($has_durs && $has_durs->num_rows) {
        $res = $conn->query("SELECT * FROM service_durations WHERE service_id IN ($ids) ORDER BY duration_minutes");
        while ($r = $res->fetch_assoc()) {
            if ((int)($r['session_count'] ?? 1) > 1) continue; // multi-session packages aren't a "length"
            $durs[(int)$r['service_id']][] = $r;
        }
    }

    foreach ($best_services as $s) {
        $id  = (int)$s['id'];
        $row = $info[$id] ?? $s;
        $card = [
            'id' => $id, 'name' => $s['name'], 'image' => $s['image'] ?? '',
            'category_id' => (int)($s['category_id'] ?? 0), 'category' => $s['category_name'] ?? '',
            'desc' => trim((string)($row['description'] ?? '')),
            'home' => !empty($row['is_home_service']),
            'from' => false, 'was' => 0, 'promo' => false, 'lengths' => '',
        ];
        if (!empty($durs[$id])) {
            $min = null; $labels = [];
            foreach ($durs[$id] as $d) {
                $p = get_active_duration_price($d);
                if ($min === null || $p['price'] < $min) $min = $p['price'];
                if ($p['is_promo_active']) $card['promo'] = true;
                $labels[] = home_minutes_label((int)$d['duration_minutes']);
            }
            $card['price']   = (float)$min;
            $card['from']    = count($durs[$id]) > 1;
            $card['lengths'] = implode(' or ', array_slice(array_unique(array_filter($labels)), 0, 3));
        } else {
            $p = _resolve_active_price((float)$row['price'], (float)($row['promo_price'] ?? 0), (string)($row['price_mode'] ?? 'regular'),
                                       $row['promo_start_time'] ?? null, $row['promo_end_time'] ?? null);
            $card['price'] = (float)$p['price'];
            if ($p['is_promo_active']) { $card['promo'] = true; $card['was'] = (float)$row['price']; }
            $card['lengths'] = home_minutes_label((int)($row['session_time'] ?? 0));
        }
        $home_cards[] = $card;
    }
}

// Operating hours — one list used by the hero status line and the
// Operating Hours box in Contact, so the two always match.
// [label, first weekday (1=Mon), last weekday, opens, closes (24 = midnight), shown text]
$home_hours = [
    ['Monday – Friday',   1, 5, 9, 22, '9:00 AM – 10:00 PM'],
    ['Saturday – Sunday', 6, 7, 9, 24, '9:00 AM – 12:00 AM'],
];
$home_dow   = (int)date('N');
$home_hour  = (int)date('G');
$home_today = null;
foreach ($home_hours as $hh) {
    if ($home_dow >= $hh[1] && $home_dow <= $hh[2]) { $home_today = $hh; break; }
}
$home_open   = $home_today && $home_hour >= $home_today[3] && $home_hour < $home_today[4];
$home_status = ($home_open ? 'Open now' : 'Closed now') . ($home_today ? ' · Today ' . $home_today[5] : '');

$page_title  = 'Recovery Iloilo — Home';
$active_page = 'home';
// user/index.php (logged-in customers) keeps the account header with
// Appointments, chat and notifications; the public home uses header.php.
require_once defined('HOME_ACCOUNT_AREA') ? __DIR__ . '/user/header.php' : __DIR__ . '/header.php';
?>
<style>
.homepage-section .section-header { margin-bottom: 2rem; }

/* ── Home: simple, senior-friendly layout — one main action in the hero,
   big picture tiles per category, and photo cards for services and
   products (no carousels, nothing that moves by itself). ─────────────── */
.hm-hero { background: linear-gradient(160deg, #5A3C26, #3B2A1A 75%); color: #FAF3E8; padding: 3rem 0 3.25rem; }
.hm-hero .spa-container { max-width: 900px; }
.hm-eyebrow { font-size: 0.85rem; letter-spacing: 0.2em; text-transform: uppercase; color: #E8C87A; font-weight: 700; margin-bottom: 0.75rem; }
.hm-hero h1 em { color: #E8C87A; }
.hm-hero h1 { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: clamp(2.3rem, 7vw, 3.6rem); line-height: 1.08; color: #FAF3E8; margin-bottom: 0.9rem; }
.hm-hero p { font-size: 1.15rem; color: #F0E2CF; line-height: 1.6; max-width: 34ch; margin-bottom: 1.5rem; }
[class^="hm-"], [class^="hm-"] *, .hm-btn { box-sizing: border-box; }
.hm-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem; min-height: 3.4rem; padding: 0 1.6rem; border-radius: 14px; font-weight: 700; font-size: 1.1rem; text-decoration: none; text-align: center; border: 2px solid transparent; transition: filter .2s; }
.hm-btn:hover { filter: brightness(1.07); }
.hm-btn-gold { background: #C9963A; color: #1C120A; }
.hm-btn-dark { background: #3B2A1A; color: #FAF3E8; }
.hm-btn-line { background: #fff; color: var(--brown); border-color: #D9C6A8; }
.hm-hero .hm-btn { width: 100%; max-width: 420px; min-height: 3.7rem; font-size: 1.2rem; }
.hm-hero .hm-alt { margin: 1.1rem 0 0; font-size: 1.05rem; }
.hm-hero .hm-alt a { color: #E8C87A; font-weight: 700; white-space: nowrap; }
.hm-status { display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1rem; font-weight: 700; }
.hm-status i { width: 12px; height: 12px; border-radius: 50%; background: #7FD6A3; display: inline-block; }
.hm-status.closed i { background: #E8A07A; }

.hm-section { padding: 2.75rem 0 0.5rem; }
.hm-h2 { font-family: 'Cormorant Garamond', serif; font-size: clamp(1.9rem, 5vw, 2.5rem); font-weight: 700; color: var(--brown); line-height: 1.15; margin-bottom: 0.3rem; }
.hm-sub { color: var(--brown-md); font-size: 1.05rem; margin-bottom: 1.25rem; }
.hm-more { display: block; text-align: center; margin-top: 1.25rem; color: var(--brown); font-weight: 700; font-size: 1.1rem; text-decoration: underline; text-underline-offset: 4px; padding: 0.75rem; }

.hm-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
@media (min-width: 760px) { .hm-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.hm-tile { position: relative; display: block; border-radius: 18px; overflow: hidden; aspect-ratio: 1 / 1; background: #F3EADC; text-decoration: none; }
.hm-tile img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s; }
.hm-tile:hover img { transform: scale(1.04); }
.hm-tile .hm-tile-ph { width: 100%; height: 100%; display: grid; place-items: center; font-size: 3rem; }
.hm-tile span { position: absolute; left: 0; right: 0; bottom: 0; padding: 2.2rem 0.9rem 0.8rem; background: linear-gradient(transparent, rgba(20,12,6,0.85)); color: #fff; font-weight: 700; font-size: 1.15rem; line-height: 1.25; }
.hm-tile span small { display: block; font-weight: 400; font-size: 0.9rem; color: #F0E2CF; }
.hm-tile.hm-all { background: #3B2A1A; display: grid; place-items: center; color: #E8C87A; font-weight: 700; font-size: 1.2rem; text-align: center; padding: 1rem; }

.hm-list { display: grid; gap: 18px; }
@media (min-width: 760px) { .hm-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.hm-card { background: #fff; border: 2px solid #E8D9C3; border-radius: 20px; overflow: hidden; display: flex; flex-direction: column; }
/* Every photo box has a fixed shape; the photo is cropped to fill it, so a
   tall upload can't make one card bigger than its neighbours. */
.hm-pic { position: relative; aspect-ratio: 16 / 10; background: #F3EADC; overflow: hidden; min-height: 0; }
.hm-pic img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; display: block; }
.hm-nopic { position: absolute; inset: 0; width: 100%; height: 100%; display: grid; place-items: center; align-content: center; gap: 0.3rem; color: var(--brown-md); font-weight: 700; text-align: center; font-size: 2.4rem;
    background: repeating-linear-gradient(45deg, #F3EADC, #F3EADC 14px, #F6EBD3 14px, #F6EBD3 28px); }
.hm-nopic small { font-size: 0.95rem; }
.hm-ribbon { position: absolute; top: 12px; left: 12px; background: #9C3F17; color: #fff; font-weight: 700; font-size: 0.95rem; border-radius: 10px; padding: 3px 12px; }
.hm-oos { position: absolute; inset: 0; background: rgba(20,12,6,0.55); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: 1.1rem; }
.hm-cbody { padding: 1.1rem 1.2rem 1.2rem; display: flex; flex-direction: column; gap: 0.45rem; flex: 1; }
.hm-cat { font-size: 0.9rem; font-weight: 700; color: var(--gold-dark); text-transform: uppercase; letter-spacing: 0.06em; }
.hm-card h3 { font-size: 1.35rem; color: var(--brown); line-height: 1.25; }
.hm-card p { color: var(--brown-md); font-size: 1.02rem; line-height: 1.55; margin: 0; }
.hm-facts { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; border-top: 2px dashed #E8D9C3; padding-top: 0.65rem; margin-top: auto; }
.hm-len { color: var(--brown-md); font-weight: 700; }
.hm-price { font-size: 1.6rem; font-weight: 800; color: var(--brown); line-height: 1.1; }
.hm-price small { font-size: 0.95rem; font-weight: 400; }
.hm-price s { font-size: 1rem; color: var(--gray); font-weight: 400; margin-left: 6px; }
.hm-card .hm-btn { margin-top: 0.5rem; width: 100%; }

.hm-pgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
@media (min-width: 760px) { .hm-pgrid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.hm-pcard .hm-pic { aspect-ratio: 1 / 1; }
.hm-pcard .hm-cbody { padding: 0.85rem 0.95rem 0.95rem; gap: 0.3rem; }
.hm-pcard h3 { font-size: 1.1rem; }
.hm-pcard .hm-price { font-size: 1.35rem; margin-bottom: 0.4rem; }
.hm-pcard .hm-btn { margin-top: auto; min-height: 3rem; font-size: 1rem; padding: 0 0.6rem; }

/* Phones: 3 big labelled tabs at the bottom (Home, Services, Products). */
.hm-tabs { display: none; }
@media (max-width: 760px) {
    .hm-tabs { display: grid; grid-template-columns: repeat(3, 1fr); position: fixed; left: 0; right: 0; bottom: 0; z-index: 90; background: #fff; border-top: 2px solid #E8D9C3; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .hm-tabs a { min-height: 4.2rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; font-weight: 700; font-size: 0.95rem; color: var(--brown-md); text-decoration: none; border-top: 5px solid transparent; }
    .hm-tabs a svg { width: 1.6rem; height: 1.6rem; }
    .hm-tabs a[aria-current="page"] { color: var(--brown); border-top-color: #C9963A; background: #F6EBD3; }
    body { padding-bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px)); }
    .hm-hero { padding: 2.25rem 0 2.5rem; }
}

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
</style>

<section class="hm-hero" id="index">
    <div class="spa-container">
        <p class="hm-eyebrow">Welcome to Recovery Iloilo</p>
        <h1>Skin <em>and</em> Wellness</h1>
        <p>Gentle wellness massage and spa care in Iloilo City.</p>
        <a href="<?php echo $home_base; ?>services.php" class="hm-btn hm-btn-gold">See our services</a>
        <p class="hm-alt">Prefer to talk? Call <a href="tel:+639853359998">+63 985 335 9998</a> and we'll book for you.</p>
        <div class="hm-status<?php echo $home_open ? '' : ' closed'; ?>"><i aria-hidden="true"></i> <?php echo htmlspecialchars($home_status); ?></div>
    </div>
</section>

<div class="spa-container">

<!-- ── CATEGORY PICTURE TILES ─────────────────────────────────────────────── -->
<?php if (!empty($home_tiles)): ?>
<section class="hm-section" id="categories" aria-labelledby="hm-what">
    <h2 class="hm-h2" id="hm-what">What would you like today?</h2>
    <p class="hm-sub">Tap a picture.</p>
    <div class="hm-tiles">
        <?php foreach ($home_tiles as $cat): ?>
        <a class="hm-tile" href="<?php echo $home_base; ?>services.php?category=<?php echo (int)$cat['id']; ?>">
            <?php if (!empty($cat['sample_image'])): ?>
            <img src="<?php echo BASE_URL; ?>uploads/services/<?php echo htmlspecialchars($cat['sample_image']); ?>" alt="" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
            <div class="hm-tile-ph" style="display:none" aria-hidden="true">💆</div>
            <?php else: ?>
            <div class="hm-tile-ph" aria-hidden="true">💆</div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($cat['name']); ?><small><?php echo (int)$cat['item_count']; ?> service<?php echo $cat['item_count'] == 1 ? '' : 's'; ?></small></span>
        </a>
        <?php endforeach; ?>
        <a class="hm-tile hm-all" href="<?php echo $home_base; ?>services.php">See all services →</a>
    </div>
</section>
<?php endif; ?>

<!-- ── MOST BOOKED SERVICES ───────────────────────────────────────────────── -->
<?php if (!empty($home_cards)): ?>
<section class="hm-section" id="best-services" aria-labelledby="hm-pop">
    <h2 class="hm-h2" id="hm-pop">Most booked</h2>
    <p class="hm-sub">Our guests' favorites.</p>
    <div class="hm-list">
        <?php foreach ($home_cards as $c): ?>
        <article class="hm-card">
            <div class="hm-pic">
                <?php if ($c['image'] !== ''): ?>
                <img src="<?php echo BASE_URL; ?>uploads/services/<?php echo htmlspecialchars($c['image']); ?>" alt="<?php echo htmlspecialchars($c['name']); ?>" loading="lazy"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                <div class="hm-nopic" style="display:none">💆<small>Photo coming soon</small></div>
                <?php else: ?>
                <div class="hm-nopic">💆<small>Photo coming soon</small></div>
                <?php endif; ?>
                <?php if ($c['promo']): ?><span class="hm-ribbon">Promo</span><?php endif; ?>
            </div>
            <div class="hm-cbody">
                <?php if ($c['category'] !== ''): ?><span class="hm-cat"><?php echo htmlspecialchars($c['category']); ?></span><?php endif; ?>
                <h3><?php echo htmlspecialchars($c['name']); ?></h3>
                <?php if ($c['desc'] !== ''): ?><p><?php echo htmlspecialchars(mb_strimwidth($c['desc'], 0, 130, '…')); ?></p><?php endif; ?>
                <?php if ($c['home']): ?><p><b>Home service</b> available</p><?php endif; ?>
                <div class="hm-facts">
                    <span class="hm-len"><?php echo htmlspecialchars($c['lengths']); ?></span>
                    <span class="hm-price"><?php if ($c['from']): ?><small>from </small><?php endif; ?>₱<?php echo number_format($c['price'], 2); ?><?php if ($c['was'] > $c['price']): ?><s>₱<?php echo number_format($c['was'], 2); ?></s><?php endif; ?></span>
                </div>
                <a class="hm-btn hm-btn-dark" href="<?php echo $home_base; ?>services.php?category=<?php echo $c['category_id']; ?>&amp;item=svc-<?php echo $c['id']; ?>">Book this</a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <a class="hm-more" href="<?php echo $home_base; ?>services.php">See all services →</a>
</section>
<?php endif; ?>

<!-- ── FROM OUR SHOP ──────────────────────────────────────────────────────── -->
<?php if (!empty($best_products)): ?>
<section class="hm-section" id="best-products" aria-labelledby="hm-shop">
    <h2 class="hm-h2" id="hm-shop">From our shop</h2>
    <p class="hm-sub">The same products we use in the spa.</p>
    <div class="hm-pgrid">
        <?php foreach ($best_products as $prd): $oos = (int)$prd['stock'] <= 0; ?>
        <article class="hm-card hm-pcard">
            <div class="hm-pic">
                <?php if (!empty($prd['image'])): ?>
                <img src="<?php echo BASE_URL; ?>uploads/products/<?php echo htmlspecialchars($prd['image']); ?>" alt="<?php echo htmlspecialchars($prd['name']); ?>" loading="lazy"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                <div class="hm-nopic" style="display:none">🧴<small>Photo coming soon</small></div>
                <?php else: ?>
                <div class="hm-nopic">🧴<small>Photo coming soon</small></div>
                <?php endif; ?>
                <?php if ($oos): ?><div class="hm-oos">Out of stock</div><?php endif; ?>
            </div>
            <div class="hm-cbody">
                <h3><?php echo htmlspecialchars($prd['name']); ?></h3>
                <span class="hm-price">₱<?php echo number_format($prd['price'], 2); ?></span>
                <a class="hm-btn hm-btn-line" href="<?php echo $home_base; ?>products.php?category=<?php echo (int)($prd['category_id'] ?? 0); ?>&amp;item=prd-<?php echo (int)$prd['id']; ?>"><?php echo $oos ? 'View' : 'View &amp; buy'; ?></a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <a class="hm-more" href="<?php echo $home_base; ?>products.php">See all products →</a>
</section>
<?php endif; ?>

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
                    <?php foreach ($home_hours as $hh): ?>
                    <div class="hours-row"><span><?php echo htmlspecialchars($hh[0]); ?></span><span><?php echo htmlspecialchars($hh[5]); ?></span></div>
                    <?php endforeach; ?>
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

<nav class="hm-tabs" aria-label="Pages">
    <a href="<?php echo $home_base; ?>index.php" aria-current="page">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V21H3z"/></svg>Home
    </a>
    <a href="<?php echo $home_base; ?>services.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/></svg>Services
    </a>
    <a href="<?php echo $home_base; ?>products.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>Products
    </a>
</nav>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
