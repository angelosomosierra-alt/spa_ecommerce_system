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
// 3. DATA — everything on the home page comes from the live tables; a
// section with nothing real to show is left out instead of faked.
// A logged-in customer browses the account-area copies (user/*.php), same
// rule footer.php uses.
$home_base = (is_logged_in() && !is_admin()) ? BASE_URL . 'user/' : BASE_URL;
function home_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Category mosaic: the 6 biggest service categories that have a photo.
$svc_categories = get_customer_categories_with_counts($conn, 'service');
usort($svc_categories, function ($a, $b) { return (int)$b['item_count'] <=> (int)$a['item_count']; });
$home_tiles = array_slice(array_values(array_filter($svc_categories, function ($c) { return !empty($c['sample_image']); })), 0, 6);
$home_svc_total = array_sum(array_map(function ($c) { return (int)$c['item_count']; }, $svc_categories));

$best_services = get_best_selling_services($conn, 6, 90);
$best_products = get_best_selling_products($conn, 4, 90);

// "1 hour 30 minutes" reads easier than "90 min".
function home_minutes_label(int $m): string {
    if ($m <= 0) return '';
    $h = intdiv($m, 60); $r = $m % 60; $out = [];
    if ($h) $out[] = $h . ($h === 1 ? ' hour' : ' hours');
    if ($r) $out[] = $r . ' min';
    return implode(' ', $out);
}

// Seconds until a promo's daily window closes (null = promo runs all day).
function home_promo_left(?string $start, ?string $end): ?int {
    if (empty($start) || empty($end)) return null;
    $now = time();
    $endTs = strtotime(date('Y-m-d') . ' ' . $end);
    if (date('H:i:s', strtotime($start)) > date('H:i:s', strtotime($end)) && date('H:i:s') >= date('H:i:s', strtotime($start))) {
        $endTs += 86400; // overnight window, e.g. 22:00–02:00
    }
    return max(0, $endTs - $now);
}

$has_durs_tbl = false;
$t = $conn->query("SHOW TABLES LIKE 'service_durations'");
if ($t && $t->num_rows) $has_durs_tbl = true;

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
    if ($has_durs_tbl) {
        $res = $conn->query("SELECT * FROM service_durations WHERE service_id IN ($ids) ORDER BY duration_minutes");
        while ($r = $res->fetch_assoc()) {
            if ((int)($r['session_count'] ?? 1) > 1) continue; // multi-session packages aren't a "length"
            $durs[(int)$r['service_id']][] = $r;
        }
    }

    foreach ($best_services as $i => $s) {
        $id  = (int)$s['id'];
        $row = $info[$id] ?? $s;
        $card = [
            'id' => $id, 'name' => $s['name'], 'image' => $s['image'] ?? '',
            'category_id' => (int)($s['category_id'] ?? 0), 'category' => $s['category_name'] ?? '',
            'desc' => trim((string)($row['description'] ?? '')),
            'home' => !empty($row['is_home_service']),
            'from' => false, 'was' => 0, 'promo' => false, 'lengths' => '',
            // Only the real #1 by bookings gets the badge.
            'top' => $i === 0 && (int)($s['sold_count'] ?? 0) > 0,
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
            $card['lengths'] = implode(' / ', array_slice(array_unique(array_filter($labels)), 0, 3));
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

// Promos running right now — services on promo price and duration options
// on promo price. Biggest saving first; one entry per service.
$home_promos = [];
try {
    // A service with duration options is priced by those rows, not by its own promo columns.
    $with_durs = [];
    if ($has_durs_tbl) {
        $res = $conn->query("SELECT DISTINCT service_id FROM service_durations");
        while ($r = $res->fetch_assoc()) $with_durs[(int)$r['service_id']] = true;
    }
    $res = $conn->query("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id = s.category_id
                         WHERE s.deleted_at IS NULL AND s.price_mode = 'promo' AND s.promo_price > 0");
    while ($r = $res->fetch_assoc()) {
        if (isset($with_durs[(int)$r['id']])) continue;
        $p = _resolve_active_price((float)$r['price'], (float)$r['promo_price'], 'promo', $r['promo_start_time'] ?? null, $r['promo_end_time'] ?? null);
        if (!$p['is_promo_active'] || (float)$r['price'] <= $p['price']) continue;
        $home_promos[(int)$r['id']] = [
            'id' => (int)$r['id'], 'name' => $r['name'], 'image' => $r['image'] ?? '', 'category_id' => (int)$r['category_id'],
            'desc' => trim((string)($r['description'] ?? '')), 'price' => (float)$p['price'], 'was' => (float)$r['price'],
            'left' => home_promo_left($r['promo_start_time'] ?? null, $r['promo_end_time'] ?? null),
            'end_label' => !empty($r['promo_end_time']) ? date('g:i A', strtotime($r['promo_end_time'])) : '',
        ];
    }
    if ($has_durs_tbl) {
        $res = $conn->query("SELECT d.*, s.name, s.image, s.category_id, s.description FROM service_durations d
                             JOIN services s ON s.id = d.service_id
                             WHERE s.deleted_at IS NULL AND d.price_mode = 'promo' AND d.promo_price > 0");
        while ($r = $res->fetch_assoc()) {
            if ((int)($r['session_count'] ?? 1) > 1) continue;
            $p = get_active_duration_price($r);
            if (!$p['is_promo_active'] || (float)$r['regular_price'] <= $p['price']) continue;
            $sid = (int)$r['service_id'];
            $save = (float)$r['regular_price'] - $p['price'];
            if (isset($home_promos[$sid]) && $home_promos[$sid]['was'] - $home_promos[$sid]['price'] >= $save) continue;
            $home_promos[$sid] = [
                'id' => $sid, 'name' => $r['name'], 'image' => $r['image'] ?? '', 'category_id' => (int)$r['category_id'],
                'desc' => trim((string)($r['description'] ?? '')), 'price' => (float)$p['price'], 'was' => (float)$r['regular_price'],
                'left' => home_promo_left($r['promo_start_time'] ?? null, $r['promo_end_time'] ?? null),
                'end_label' => !empty($r['promo_end_time']) ? date('g:i A', strtotime($r['promo_end_time'])) : '',
                'minutes' => (int)$r['duration_minutes'],
            ];
        }
    }
} catch (Throwable $e) { /* older database without promo columns: no promos shown */ }
$home_promos = array_values($home_promos);
usort($home_promos, function ($a, $b) { return ($b['was'] - $b['price']) <=> ($a['was'] - $a['price']); });
$home_deal = $home_promos[0] ?? null;

$home_has_home_service = false;
try {
    $r = $conn->query("SELECT 1 FROM services WHERE deleted_at IS NULL AND is_home_service = 1 LIMIT 1");
    $home_has_home_service = $r && $r->num_rows > 0;
} catch (Throwable $e) {}

// Customer reviews: the overall rating (shown once there are enough) and
// the latest good reviews that have a written comment. First name only.
$home_rating = null;
$home_reviews = [];
try {
    $r = $conn->query("SELECT COUNT(*) AS c, AVG(rating) AS a FROM feedback")->fetch_assoc();
    if ((int)$r['c'] >= 5) $home_rating = ['count' => (int)$r['c'], 'avg' => round((float)$r['a'], 1)];
    $res = $conn->query("SELECT f.rating, f.comment, u.full_name, s.name AS service_name
                         FROM feedback f
                         JOIN users u ON u.id = f.user_id
                         LEFT JOIN appointments a ON a.id = f.appointment_id
                         LEFT JOIN services s ON s.id = a.service_id
                         WHERE f.rating >= 4 AND f.comment IS NOT NULL AND CHAR_LENGTH(TRIM(f.comment)) >= 15
                         ORDER BY f.created_at DESC LIMIT 3");
    while ($row = $res->fetch_assoc()) $home_reviews[] = $row;
} catch (Throwable $e) {}

// Gift banner: an in-stock gift / pamper set product, if there is one.
$home_gift = null;
try {
    $r = $conn->query("SELECT * FROM products WHERE deleted_at IS NULL AND stock > 0
                       AND (name LIKE '%gift%' OR name LIKE '%pamper%') ORDER BY price DESC LIMIT 1");
    $home_gift = $r ? $r->fetch_assoc() : null;
} catch (Throwable $e) {}

// Hero photo stack: three different service photos.
$home_stack = [];
foreach (array_merge(array_column($home_cards, 'image'), array_column($home_tiles, 'sample_image')) as $img) {
    if ($img && !in_array($img, $home_stack, true)) $home_stack[] = $img;
    if (count($home_stack) === 3) break;
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

/* ── Home showcase: espresso hero with a fanned photo stack, a running promo
   ribbon, today's best deal with a live countdown, a category photo mosaic,
   guest favorites, shop, reviews and a gift banner. Every class is rx-
   prefixed so nothing collides with assets/style.css. ────────────────── */
:root {
  --rx-panel: #FFFFFF; --rx-sunk: #F2E8DA; --rx-line: #E6D6BF;
  --rx-ink: #2A1C11; --rx-ink-2: #6B4C30;
  --rx-espresso: #24170E; --rx-espresso-2: #4A2F1C; --rx-gold: #C9963A; --rx-gold-hi: #F0CF8A; --rx-rust: #B5532A;
  --rx-on-dark: #FBF3E8; --rx-on-dark-2: #E3CFB4;
  --rx-display: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
  --rx-body: 'DM Sans', 'Segoe UI', Tahoma, sans-serif;
}
[class^="rx-"], [class^="rx-"] *, [class*=" rx-"] { box-sizing: border-box; }
.rx-wrap { max-width: 1160px; margin: 0 auto; padding-inline: 20px; }
.rx-eyebrow { display: block; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.18em; text-transform: uppercase; color: var(--rx-gold); }
.rx-h2 { font-family: var(--rx-display); font-weight: 700; font-size: clamp(2.1rem, 5vw, 3.1rem); line-height: 1.05; color: var(--rx-ink); margin: 0.2rem 0 0; }
.rx-h2 em { color: var(--rx-gold); }
.rx-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 52px; padding: 0 24px; border-radius: 999px; font-weight: 800; font-size: 1rem; text-decoration: none; border: 2px solid transparent; transition: transform .2s, filter .2s; font-family: var(--rx-body); }
.rx-btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
.rx-btn-gold { background: var(--rx-gold); color: #1E130A; box-shadow: 0 10px 28px rgba(201,150,58,.35); }
.rx-btn-ghost { border-color: rgba(240,207,138,.55); color: var(--rx-on-dark); background: transparent; }
.rx-btn-dark { background: var(--rx-espresso); color: var(--rx-on-dark); }
.rx-btn-line { border-color: var(--rx-line); background: var(--rx-panel); color: var(--rx-ink); }
.rx-sec { padding-block: 72px 0; }
.rx-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 26px; }
.rx-head > a { font-weight: 700; color: var(--rx-ink); text-underline-offset: 4px; }

/* Hero */
.rx-hero { position: relative; overflow: hidden; background: radial-gradient(120% 90% at 85% 10%, var(--rx-espresso-2), var(--rx-espresso) 60%); color: var(--rx-on-dark); padding-block: 56px 72px; }
.rx-hero::before { content: ''; position: absolute; right: -10%; bottom: -40%; width: 620px; height: 620px; border-radius: 50%; background: radial-gradient(circle, rgba(201,150,58,.22), transparent 65%); pointer-events: none; }
.rx-hero .rx-wrap { position: relative; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 40px; align-items: center; }
@media (max-width: 860px) { .rx-hero .rx-wrap { grid-template-columns: minmax(0, 1fr); } }
.rx-hero h1 { font-family: var(--rx-display); font-weight: 700; font-size: clamp(3.4rem, 10vw, 6.4rem); line-height: 0.92; color: var(--rx-on-dark); margin: 14px 0 18px; }
.rx-hero h1 em { display: block; font-style: italic; font-weight: 600; color: var(--rx-gold-hi); font-size: 0.6em; margin: 4px 0 2px; }
.rx-lead { font-size: 1.15rem; color: var(--rx-on-dark-2); max-width: 38ch; margin: 0 0 26px; line-height: 1.55; }
.rx-ctas { display: flex; flex-wrap: wrap; gap: 12px; }
.rx-trust { display: flex; flex-wrap: wrap; gap: 26px; margin-top: 34px; }
.rx-trust b { display: block; font-family: var(--rx-display); font-size: 2rem; color: var(--rx-gold-hi); line-height: 1; }
.rx-trust span { font-size: 0.85rem; color: var(--rx-on-dark-2); }
.rx-stars { color: var(--rx-gold-hi); letter-spacing: 2px; }
.rx-stack { position: relative; height: 470px; }
.rx-stack figure { position: absolute; margin: 0; border-radius: 22px; overflow: hidden; box-shadow: 0 30px 60px rgba(0,0,0,.45); border: 4px solid rgba(251,243,232,.9); transition: transform .5s cubic-bezier(.2,.8,.2,1); background: var(--rx-espresso-2); }
.rx-stack figure img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; }
.rx-f1 { width: 58%; height: 78%; left: 4%; top: 8%; transform: rotate(-7deg); }
.rx-f2 { width: 56%; height: 74%; right: 2%; top: 0; transform: rotate(5deg); z-index: 2; }
.rx-f3 { width: 46%; height: 52%; left: 28%; bottom: 0; transform: rotate(-1deg); z-index: 3; }
.rx-stack:hover .rx-f1 { transform: rotate(-11deg) translateX(-14px); }
.rx-stack:hover .rx-f2 { transform: rotate(9deg) translateX(14px); }
.rx-chip { position: absolute; z-index: 4; display: flex; align-items: center; gap: 10px; background: var(--rx-panel); color: var(--rx-ink); border-radius: 18px; padding: 10px 14px; box-shadow: 0 16px 34px rgba(0,0,0,.35); font-size: 0.9rem; text-decoration: none; line-height: 1.3; }
.rx-chip b { display: block; font-size: 1.02rem; }
.rx-dot { width: 10px; height: 10px; border-radius: 50%; background: #2F9E62; flex: none; animation: rx-pulse 1.8s infinite; }
.rx-dot.closed { background: #C0603A; animation: none; }
.rx-chip-a { left: -2%; bottom: 14%; }
.rx-chip-b { right: 0; top: 64%; }
.rx-tag { background: var(--rx-rust); color: #fff; font-weight: 800; border-radius: 10px; padding: 4px 8px; font-size: 0.8rem; }
@keyframes rx-pulse { 0% { box-shadow: 0 0 0 0 rgba(47,158,98,.6); } 70% { box-shadow: 0 0 0 10px rgba(47,158,98,0); } 100% { box-shadow: 0 0 0 0 rgba(47,158,98,0); } }
@media (max-width: 860px) { .rx-stack { height: 360px; } }
@media (max-width: 480px) { .rx-stack { height: 300px; } .rx-chip { font-size: 0.8rem; padding: 8px 10px; } .rx-chip-a { left: 0; top: 2%; bottom: auto; } .rx-chip-b { right: 0; top: auto; bottom: 4%; } }

/* Promo ribbon */
.rx-ribbon { background: var(--rx-gold); color: #1E130A; overflow: hidden; white-space: nowrap; font-weight: 800; }
.rx-track { display: inline-flex; gap: 40px; padding: 14px 0; animation: rx-slide 36s linear infinite; }
.rx-track span::after { content: '✦'; margin-left: 40px; opacity: .55; }
.rx-ribbon:hover .rx-track { animation-play-state: paused; }
@keyframes rx-slide { to { transform: translateX(-50%); } }

/* Deal */
.rx-deal { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr); border-radius: 28px; overflow: hidden; background: var(--rx-espresso); color: var(--rx-on-dark); }
@media (max-width: 820px) { .rx-deal { grid-template-columns: minmax(0, 1fr); } }
.rx-deal-ph { position: relative; min-height: 300px; background: var(--rx-espresso-2); }
.rx-deal-ph img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; }
.rx-save { position: absolute; top: 18px; left: 18px; background: var(--rx-rust); color: #fff; font-weight: 800; border-radius: 14px; padding: 8px 14px; }
.rx-deal-tx { padding: 36px; display: flex; flex-direction: column; gap: 14px; align-items: flex-start; }
.rx-deal h3 { font-family: var(--rx-display); font-size: clamp(2rem, 4.5vw, 2.8rem); line-height: 1.05; color: var(--rx-on-dark); margin: 0; }
.rx-deal p { margin: 0; color: var(--rx-on-dark-2); }
.rx-price-big { font-family: var(--rx-display); font-size: 3rem; font-weight: 700; color: var(--rx-gold-hi); line-height: 1; }
.rx-price-big s { font-family: var(--rx-body); font-size: 1.1rem; color: var(--rx-on-dark-2); font-weight: 400; margin-left: 10px; }
.rx-count { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.rx-count div { background: rgba(251,243,232,.08); border: 1px solid rgba(240,207,138,.25); border-radius: 14px; padding: 10px 0; width: 74px; text-align: center; }
.rx-count b { display: block; font-size: 1.7rem; font-variant-numeric: tabular-nums; line-height: 1.1; }
.rx-count span { font-size: 0.72rem; letter-spacing: .12em; text-transform: uppercase; color: var(--rx-on-dark-2); }
.rx-count em { font-style: normal; font-weight: 700; color: var(--rx-on-dark-2); }

/* Category mosaic */
.rx-mosaic { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); grid-auto-rows: 210px; gap: 14px; }
.rx-mosaic a { position: relative; border-radius: 22px; overflow: hidden; text-decoration: none; color: #fff; background: var(--rx-sunk); }
.rx-mosaic a img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; transition: transform .6s cubic-bezier(.2,.8,.2,1); }
.rx-mosaic a:hover img { transform: scale(1.07); }
.rx-mosaic a::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, transparent 40%, rgba(20,12,6,.85)); }
.rx-lbl { position: absolute; z-index: 1; left: 18px; right: 18px; bottom: 16px; padding-right: 48px; }
.rx-lbl b { display: block; font-family: var(--rx-display); font-size: 1.7rem; line-height: 1.05; }
.rx-lbl span { font-size: 0.9rem; color: #EAD8C0; }
.rx-go { position: absolute; right: 0; bottom: 2px; width: 40px; height: 40px; border-radius: 50%; background: var(--rx-gold); color: #1E130A; display: grid; place-items: center; font-weight: 800; transition: transform .3s; }
.rx-mosaic a:hover .rx-go { transform: translateX(4px); }
.rx-mosaic .rx-big { grid-column: span 2; grid-row: span 2; }
.rx-mosaic .rx-big .rx-lbl b { font-size: 2.4rem; }
.rx-mosaic .rx-wide { grid-column: span 2; }
@media (max-width: 820px) {
  .rx-mosaic { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: 170px; }
  .rx-mosaic .rx-wide { grid-column: span 1; }
  .rx-mosaic .rx-wide:nth-child(4) { grid-column: span 2; }
}

/* Cards */
.rx-svc { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
@media (max-width: 960px) { .rx-svc { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) { .rx-svc { grid-template-columns: minmax(0, 1fr); } }
.rx-card { background: var(--rx-panel); border-radius: 24px; overflow: hidden; display: flex; flex-direction: column; border: 1px solid var(--rx-line); transition: transform .3s, box-shadow .3s; }
.rx-card:hover { transform: translateY(-6px); box-shadow: 0 24px 48px rgba(42,28,17,.16); }
.rx-ph { position: relative; aspect-ratio: 4 / 3; overflow: hidden; background: var(--rx-sunk); }
.rx-ph img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; transition: transform .6s; }
.rx-card:hover .rx-ph img { transform: scale(1.06); }
.rx-nopic { position: absolute; inset: 0; display: grid; place-items: center; align-content: center; gap: 4px; font-size: 2.6rem; color: var(--rx-ink-2); background: linear-gradient(160deg, #F6EBD3, var(--rx-sunk)); }
.rx-nopic small { font-size: 0.85rem; font-weight: 700; }
.rx-badge { position: absolute; top: 14px; left: 14px; z-index: 1; border-radius: 999px; padding: 5px 12px; font-size: 0.8rem; font-weight: 800; background: var(--rx-panel); color: var(--rx-ink); }
.rx-badge.hot { background: var(--rx-rust); color: #fff; }
.rx-oos { position: absolute; inset: 0; z-index: 1; background: rgba(20,12,6,.55); color: #fff; display: grid; place-items: center; font-weight: 800; }
.rx-bd { padding: 18px 20px 20px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
.rx-cat { font-size: 0.75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #8A6A22; }
.rx-card h3 { font-size: 1.2rem; color: var(--rx-ink); margin: 0; line-height: 1.3; font-family: var(--rx-body); font-weight: 700; }
.rx-card p { margin: 0; color: var(--rx-ink-2); font-size: 0.95rem; line-height: 1.5; }
.rx-meta { font-size: 0.85rem; color: var(--rx-ink-2); display: flex; gap: 14px; flex-wrap: wrap; }
.rx-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; padding-top: 12px; }
.rx-pr { font-size: 1.4rem; font-weight: 800; color: var(--rx-ink); }
.rx-pr small { font-size: 0.8rem; font-weight: 500; color: var(--rx-ink-2); }
.rx-pr s { font-size: 0.9rem; font-weight: 400; color: var(--rx-ink-2); margin-left: 4px; }
.rx-card .rx-btn { min-height: 46px; padding: 0 18px; }
.rx-shop { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 900px) { .rx-shop { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.rx-shop .rx-ph { aspect-ratio: 1 / 1; }
.rx-shop .rx-card h3 { font-size: 1.02rem; }
.rx-shop .rx-pr { font-size: 1.2rem; }
.rx-shop .rx-btn { min-height: 42px; padding: 0 14px; font-size: 0.9rem; }
@media (max-width: 520px) { .rx-shop .rx-row { flex-direction: column; align-items: stretch; } }

/* Reviews */
.rx-reviews { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
@media (max-width: 860px) { .rx-reviews { grid-template-columns: minmax(0, 1fr); } }
.rx-rv { background: var(--rx-sunk); border-radius: 22px; padding: 24px; display: flex; flex-direction: column; gap: 12px; }
.rx-rv .rx-stars { color: var(--rx-gold); }
.rx-rv q { font-family: var(--rx-display); font-size: 1.35rem; line-height: 1.3; color: var(--rx-ink); }
.rx-who { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--rx-ink-2); }
.rx-av { width: 38px; height: 38px; border-radius: 50%; background: var(--rx-gold); color: #1E130A; display: grid; place-items: center; font-weight: 800; flex: none; }

/* Gift */
.rx-gift { position: relative; overflow: hidden; border-radius: 28px; background: linear-gradient(120deg, var(--rx-rust), #7A2F12); color: #fff; display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); align-items: center; }
@media (max-width: 760px) { .rx-gift { grid-template-columns: minmax(0, 1fr); } }
.rx-gift-tx { padding: 40px; display: flex; flex-direction: column; gap: 14px; align-items: flex-start; }
.rx-gift h2 { font-family: var(--rx-display); font-size: clamp(2.2rem, 5vw, 3.2rem); line-height: 1; color: #fff; margin: 0; }
.rx-gift p { margin: 0; color: #FBE4D6; max-width: 40ch; }
.rx-gift .rx-eyebrow { color: #FFE3C9; }
.rx-gift-ph { align-self: stretch; min-height: 280px; position: relative; }
.rx-gift-ph img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; }

/* Phone: Promos + Book now bar */
.rx-bookbar { display: none; }
@media (max-width: 700px) {
  .rx-bookbar { display: flex; gap: 10px; position: fixed; left: 0; right: 0; bottom: 0; z-index: 90; padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px)); background: var(--rx-panel); border-top: 1px solid var(--rx-line); }
  .rx-bookbar .rx-btn { flex: 1; min-height: 50px; }
  body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
}
@media (prefers-reduced-motion: reduce) {
  .rx-track, .rx-dot { animation: none !important; }
  .rx-btn, .rx-card, .rx-ph img, .rx-mosaic a img, .rx-stack figure { transition: none !important; }
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<?php
$svc_url = function ($c) use ($home_base) { return $home_base . 'services.php?category=' . (int)$c['category_id'] . '&item=svc-' . (int)$c['id']; };
$prd_url = function ($p) use ($home_base) { return $home_base . 'products.php?category=' . (int)($p['category_id'] ?? 0) . '&item=prd-' . (int)$p['id']; };
$img_svc = function ($f) { return BASE_URL . 'uploads/services/' . rawurlencode($f); };
$img_prd = function ($f) { return BASE_URL . 'uploads/products/' . rawurlencode($f); };
$peso    = function ($n) { return '₱' . number_format((float)$n, (floor($n) == $n) ? 0 : 2); };
$promo_href = $home_deal ? '#deal' : $home_base . 'services.php';
?>

<!-- ── HERO ─────────────────────────────────────────────────────────────── -->
<section class="rx-hero" id="index">
    <div class="rx-wrap">
        <div>
            <span class="rx-eyebrow">Recovery Iloilo · Molo, Iloilo City</span>
            <h1>Skin <em>and</em> Wellness</h1>
            <p class="rx-lead">Massage, Japanese head spa, nails and skin care by trained therapists. Walk in, book online<?php echo $home_has_home_service ? ', or we come to your home' : ''; ?>.</p>
            <div class="rx-ctas">
                <a class="rx-btn rx-btn-gold" href="<?php echo $home_base; ?>services.php">Book a session</a>
                <a class="rx-btn rx-btn-ghost" href="<?php echo $promo_href; ?>"><?php echo $home_deal ? "See today's promo" : 'Shop products'; ?></a>
            </div>
            <div class="rx-trust">
                <div><b>2,000+</b><span>Happy clients</span></div>
                <div><b>5+</b><span>Years in Iloilo</span></div>
                <?php if ($home_rating): ?>
                <div><b><span class="rx-stars" aria-hidden="true">★★★★★</span></b><span><?php echo number_format($home_rating['avg'], 1); ?> from <?php echo number_format($home_rating['count']); ?> reviews</span></div>
                <?php else: ?>
                <div><b><?php echo max(1, (int)$home_svc_total); ?></b><span>Services to choose from</span></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="rx-stack">
            <?php foreach ($home_stack as $k => $img): ?>
            <figure class="rx-f<?php echo $k + 1; ?>"><img src="<?php echo home_h($img_svc($img)); ?>" alt="" onerror="this.remove()"></figure>
            <?php endforeach; ?>
            <div class="rx-chip rx-chip-a"><span class="rx-dot<?php echo $home_open ? '' : ' closed'; ?>"></span><div><b><?php echo $home_open ? 'Open now' : 'Closed now'; ?></b><?php echo $home_today ? 'Today ' . home_h($home_today[5]) : ''; ?></div></div>
            <?php if ($home_deal): $pct = (int)round(($home_deal['was'] - $home_deal['price']) / $home_deal['was'] * 100); ?>
            <a class="rx-chip rx-chip-b" href="#deal"><span class="rx-tag">−<?php echo $pct; ?>%</span><div><b><?php echo home_h(mb_strimwidth($home_deal['name'], 0, 22, '…')) . ' ' . $peso($home_deal['price']); ?></b><?php echo $home_deal['left'] !== null ? 'Until ' . home_h($home_deal['end_label']) : 'Promo today'; ?></div></a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
// Ribbon: live promos, home service, today's hours. Repeated so the loop
// never shows a gap.
$rib = [];
foreach (array_slice($home_promos, 0, 6) as $p) $rib[] = $p['name'] . ' ' . $peso($p['price']);
if ($home_has_home_service) $rib[] = 'Home service available';
if ($home_today) $rib[] = 'Open today ' . $home_today[5];
foreach (array_slice($home_tiles, 0, 4) as $c) if (count($rib) < 5) $rib[] = $c['name'];
if ($rib) { while (count($rib) < 8) $rib = array_merge($rib, $rib); }
?>
<?php if ($rib): ?>
<div class="rx-ribbon" aria-hidden="true">
    <div class="rx-track">
        <?php foreach (array_merge($rib, $rib) as $txt): ?><span><?php echo home_h($txt); ?></span><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── TODAY'S PROMO ────────────────────────────────────────────────────── -->
<?php if ($home_deal): ?>
<section class="rx-sec" id="deal">
    <div class="rx-wrap">
        <div class="rx-deal">
            <div class="rx-deal-ph">
                <?php if ($home_deal['image']): ?><img src="<?php echo home_h($img_svc($home_deal['image'])); ?>" alt="<?php echo home_h($home_deal['name']); ?>" onerror="this.remove()"><?php endif; ?>
                <span class="rx-save">Save <?php echo $peso($home_deal['was'] - $home_deal['price']); ?></span>
            </div>
            <div class="rx-deal-tx">
                <span class="rx-eyebrow">Today's promo</span>
                <h3><?php echo home_h($home_deal['name']); ?></h3>
                <?php if ($home_deal['desc'] !== ''): ?><p><?php echo home_h(mb_strimwidth($home_deal['desc'], 0, 160, '…')); ?></p><?php endif; ?>
                <div class="rx-price-big"><?php echo $peso($home_deal['price']); ?><s><?php echo $peso($home_deal['was']); ?></s></div>
                <?php if ($home_deal['left'] !== null && $home_deal['left'] > 0): ?>
                <div class="rx-count" data-left="<?php echo (int)$home_deal['left']; ?>" aria-label="Time left for this promo price">
                    <div><b data-u="h">00</b><span>Hours</span></div>
                    <div><b data-u="m">00</b><span>Mins</span></div>
                    <div><b data-u="s">00</b><span>Secs</span></div>
                    <em>left · promo ends <?php echo home_h($home_deal['end_label']); ?></em>
                </div>
                <?php else: ?>
                <p><b>Promo price all day today.</b></p>
                <?php endif; ?>
                <div class="rx-ctas"><a class="rx-btn rx-btn-gold" href="<?php echo home_h($svc_url($home_deal)); ?>">Grab this promo</a></div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── CATEGORY MOSAIC ──────────────────────────────────────────────────── -->
<?php if ($home_tiles):
    $layouts = [1 => ['rx-wide'], 2 => ['rx-wide', 'rx-wide'], 3 => ['rx-big', 'rx-wide', 'rx-wide'], 4 => ['rx-big', '', '', 'rx-wide'],
                5 => ['rx-big', '', '', '', ''], 6 => ['rx-big', '', '', 'rx-wide', 'rx-wide', 'rx-wide']];
    $layout = $layouts[count($home_tiles)];
?>
<section class="rx-sec" id="categories">
    <div class="rx-wrap">
        <div class="rx-head">
            <div><span class="rx-eyebrow">Explore</span><h2 class="rx-h2">What does your body <em>need</em> today?</h2></div>
            <a href="<?php echo $home_base; ?>services.php">All <?php echo (int)$home_svc_total; ?> services →</a>
        </div>
        <div class="rx-mosaic">
            <?php foreach ($home_tiles as $k => $cat): ?>
            <a class="<?php echo $layout[$k]; ?>" href="<?php echo $home_base; ?>services.php?category=<?php echo (int)$cat['id']; ?>">
                <img src="<?php echo home_h($img_svc($cat['sample_image'])); ?>" alt="" onerror="this.remove()">
                <div class="rx-lbl"><b><?php echo home_h($cat['name']); ?></b><span><?php echo (int)$cat['item_count']; ?> service<?php echo $cat['item_count'] == 1 ? '' : 's'; ?></span><span class="rx-go" aria-hidden="true">→</span></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── GUEST FAVORITES ──────────────────────────────────────────────────── -->
<?php if ($home_cards): ?>
<section class="rx-sec" id="best-services">
    <div class="rx-wrap">
        <div class="rx-head">
            <div><span class="rx-eyebrow">Most booked lately</span><h2 class="rx-h2">Guest <em>favorites</em></h2></div>
            <a href="<?php echo $home_base; ?>services.php">See all services →</a>
        </div>
        <div class="rx-svc">
            <?php foreach ($home_cards as $c): ?>
            <article class="rx-card">
                <div class="rx-ph">
                    <?php if ($c['image'] !== ''): ?><img src="<?php echo home_h($img_svc($c['image'])); ?>" alt="<?php echo home_h($c['name']); ?>" loading="lazy" onerror="this.remove()"><?php endif; ?>
                    <?php if ($c['image'] === ''): ?><div class="rx-nopic">💆<small>Photo coming soon</small></div><?php endif; ?>
                    <?php if ($c['promo']): ?><span class="rx-badge hot">Promo</span><?php elseif ($c['top']): ?><span class="rx-badge hot">Most booked</span><?php endif; ?>
                </div>
                <div class="rx-bd">
                    <?php if ($c['category'] !== ''): ?><span class="rx-cat"><?php echo home_h($c['category']); ?></span><?php endif; ?>
                    <h3><?php echo home_h($c['name']); ?></h3>
                    <?php if ($c['desc'] !== ''): ?><p><?php echo home_h(mb_strimwidth($c['desc'], 0, 90, '…')); ?></p><?php endif; ?>
                    <div class="rx-meta"><?php if ($c['lengths'] !== ''): ?><span><?php echo home_h($c['lengths']); ?></span><?php endif; ?><?php if ($c['home']): ?><span>Home service</span><?php endif; ?></div>
                    <div class="rx-row">
                        <span class="rx-pr"><?php if ($c['from']): ?><small>from </small><?php endif; ?><?php echo $peso($c['price']); ?><?php if ($c['was'] > $c['price']): ?><s><?php echo $peso($c['was']); ?></s><?php endif; ?></span>
                        <a class="rx-btn rx-btn-dark" href="<?php echo home_h($svc_url($c)); ?>">Book</a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── SHOP ─────────────────────────────────────────────────────────────── -->
<?php if ($best_products): ?>
<section class="rx-sec" id="best-products">
    <div class="rx-wrap">
        <div class="rx-head">
            <div><span class="rx-eyebrow">Take the spa home</span><h2 class="rx-h2">From our <em>shop</em></h2></div>
            <a href="<?php echo $home_base; ?>products.php">See all products →</a>
        </div>
        <div class="rx-shop">
            <?php foreach ($best_products as $k => $prd): $oos = (int)$prd['stock'] <= 0; ?>
            <article class="rx-card">
                <div class="rx-ph">
                    <?php if (!empty($prd['image'])): ?><img src="<?php echo home_h($img_prd($prd['image'])); ?>" alt="<?php echo home_h($prd['name']); ?>" loading="lazy" onerror="this.remove()"><?php else: ?><div class="rx-nopic">🧴<small>Photo coming soon</small></div><?php endif; ?>
                    <?php if ($k === 0 && (int)($prd['sold_count'] ?? 0) > 0): ?><span class="rx-badge hot">Bestseller</span><?php endif; ?>
                    <?php if ($oos): ?><div class="rx-oos">Out of stock</div><?php endif; ?>
                </div>
                <div class="rx-bd">
                    <h3><?php echo home_h($prd['name']); ?></h3>
                    <div class="rx-row">
                        <span class="rx-pr"><?php echo $peso($prd['price']); ?></span>
                        <a class="rx-btn rx-btn-line" href="<?php echo home_h($prd_url($prd)); ?>"><?php echo $oos ? 'View' : 'Buy now'; ?></a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── REVIEWS (real feedback only) ─────────────────────────────────────── -->
<?php if ($home_reviews): ?>
<section class="rx-sec" id="reviews">
    <div class="rx-wrap">
        <div class="rx-head"><div><span class="rx-eyebrow">What our guests say</span><h2 class="rx-h2">Loved in <em>Iloilo</em></h2></div></div>
        <div class="rx-reviews">
            <?php foreach ($home_reviews as $rv): $first = trim(strtok((string)$rv['full_name'], ' ')) ?: 'Guest'; ?>
            <div class="rx-rv">
                <span class="rx-stars" aria-label="<?php echo (int)$rv['rating']; ?> out of 5 stars"><?php echo str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']); ?></span>
                <q><?php echo home_h(mb_strimwidth(trim($rv['comment']), 0, 180, '…')); ?></q>
                <div class="rx-who"><span class="rx-av"><?php echo home_h(mb_strtoupper(mb_substr($first, 0, 1))); ?></span><?php echo home_h($first); ?><?php echo !empty($rv['service_name']) ? ' · ' . home_h($rv['service_name']) : ''; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── GIFT BANNER ──────────────────────────────────────────────────────── -->
<?php if ($home_gift): ?>
<section class="rx-sec">
    <div class="rx-wrap">
        <div class="rx-gift">
            <div class="rx-gift-tx">
                <span class="rx-eyebrow">Gift idea</span>
                <h2>Give the gift of self-care</h2>
                <p><?php echo home_h($home_gift['name']); ?><?php echo !empty($home_gift['description']) ? ': ' . home_h(mb_strimwidth(trim($home_gift['description']), 0, 140, '…')) : ''; ?></p>
                <div class="rx-price-big" style="color:#fff"><?php echo $peso($home_gift['price']); ?></div>
                <a class="rx-btn rx-btn-dark" href="<?php echo home_h($prd_url($home_gift)); ?>">Get this gift</a>
            </div>
            <div class="rx-gift-ph"><?php if (!empty($home_gift['image'])): ?><img src="<?php echo home_h($img_prd($home_gift['image'])); ?>" alt="<?php echo home_h($home_gift['name']); ?>" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="spa-container" style="margin-top:2rem">

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

<div class="rx-bookbar">
    <a class="rx-btn rx-btn-line" href="<?php echo $promo_href; ?>"><?php echo $home_deal ? 'Promos' : 'Shop'; ?></a>
    <a class="rx-btn rx-btn-gold" href="<?php echo $home_base; ?>services.php">Book now</a>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
<script>
/* Today's promo countdown — counts down the seconds the server says are
   left in the promo window, so the customer's own clock doesn't matter. */
(function () {
    var box = document.querySelector('.rx-count[data-left]');
    if (!box) return;
    var end = Date.now() + parseInt(box.dataset.left, 10) * 1000;
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
        var s = Math.max(0, Math.round((end - Date.now()) / 1000));
        box.querySelector('[data-u="h"]').textContent = pad(Math.floor(s / 3600));
        box.querySelector('[data-u="m"]').textContent = pad(Math.floor(s % 3600 / 60));
        box.querySelector('[data-u="s"]').textContent = pad(s % 60);
        if (s === 0) { clearInterval(t); box.querySelector('em').textContent = 'promo ended'; }
    }
    var t = setInterval(tick, 1000); tick();
})();
</script>
</body>
</html>
