<?php
// Shared by services.php and user/services.php (which just requires this
// file), so every path here is absolute (__DIR__ / BASE_URL).
require_once __DIR__ . '/config.php';

// ── book_service: the sheet's Book now button posts here; checkout picks
// the date, time and place. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service'])) {
    verify_csrf_token();
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'user/auth.php');
        exit();
    }
    $service_id = intval($_POST['service_id'] ?? 0);
    if ($service_id > 0) {
        $_SESSION['service_booking'] = ['service_id' => $service_id];
        header('Location: ' . BASE_URL . 'user/checkout.php');
        exit();
    }
}

$svc_base = (is_logged_in() && !is_admin()) ? BASE_URL . 'user/' : BASE_URL;

$services = [];
$res_svc = $conn->query("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON s.category_id = c.id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name");
while ($row = $res_svc->fetch_assoc()) $services[] = $row;

// Duration options (one row per length) — same pricing Book Now uses.
$durs = [];
$t = $conn->query("SHOW TABLES LIKE 'service_durations'");
if ($t && $t->num_rows) {
    $res = $conn->query("SELECT * FROM service_durations ORDER BY duration_minutes");
    while ($r = $res->fetch_assoc()) {
        if ((int)($r['session_count'] ?? 1) > 1) continue; // multi-session packages aren't a "length"
        $durs[(int)$r['service_id']][] = $r;
    }
}

// Bookings in the last 90 days, for "Most popular" sorting and badges.
$booked = [];
try {
    $res = $conn->query("SELECT service_id, COUNT(*) AS c FROM appointments
                         WHERE status NOT IN ('cancelled','declined') AND appointment_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                         GROUP BY service_id");
    while ($r = $res->fetch_assoc()) $booked[(int)$r['service_id']] = (int)$r['c'];
} catch (Throwable $e) {}
$top_ids = $booked;
arsort($top_ids);
$top_ids = array_slice(array_keys(array_filter($top_ids)), 0, 3);

// One plain list the page script renders from.
$items = [];
$cats  = [];
foreach ($services as $s) {
    $id  = (int)$s['id'];
    $cid = (int)($s['category_id'] ?? 0);
    $opts = [];
    $promo = false;
    if (!empty($durs[$id])) {
        foreach ($durs[$id] as $d) {
            $p = get_active_duration_price($d);
            $opts[] = ['m' => (int)$d['duration_minutes'], 'p' => (float)$p['price'],
                       'was' => $p['is_promo_active'] ? (float)$d['regular_price'] : 0];
            if ($p['is_promo_active']) $promo = true;
        }
    } else {
        $p = _resolve_active_price((float)$s['price'], (float)($s['promo_price'] ?? 0), (string)($s['price_mode'] ?? 'regular'),
                                   $s['promo_start_time'] ?? null, $s['promo_end_time'] ?? null);
        $opts[] = ['m' => (int)($s['session_time'] ?? 0), 'p' => (float)$p['price'],
                   'was' => $p['is_promo_active'] ? (float)$s['price'] : 0];
        $promo = $p['is_promo_active'];
    }
    $home_price = !empty($s['is_home_service']) ? (float)($s['home_service_price'] ?? 0) : 0;
    $items[] = [
        'id' => $id, 'cat' => $cid, 'name' => $s['name'], 'desc' => trim((string)($s['description'] ?? '')),
        'img' => !empty($s['image']) ? BASE_URL . 'uploads/services/' . rawurlencode($s['image']) : '',
        'opts' => $opts, 'promo' => $promo, 'home' => !empty($s['is_home_service']), 'homePrice' => $home_price,
        'pop' => $booked[$id] ?? 0, 'top' => in_array($id, $top_ids, true),
    ];
    if (!isset($cats[$cid])) $cats[$cid] = ['id' => $cid, 'name' => $s['category_name'] ?: 'Other', 'img' => '', 'n' => 0];
    $cats[$cid]['n']++;
    if ($cats[$cid]['img'] === '' && !empty($s['image'])) $cats[$cid]['img'] = BASE_URL . 'uploads/services/' . rawurlencode($s['image']);
}
// Biggest categories first.
$cats = array_values($cats);
usort($cats, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']); });

$requested_category = intval($_GET['category'] ?? 0);
$requested_item     = (string)($_GET['item'] ?? '');

$page_title  = 'Services — Recovery Iloilo';
$active_page = 'services';
require_once defined('HOME_ACCOUNT_AREA') ? __DIR__ . '/user/header.php' : __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<style>
/* ── Services menu: one scrolling page. Search, a sticky category bar that
   follows the scroll, quick filters, category sections of service cards
   (rows on phones, a grid on desktop) and a booking sheet per service.
   Every class is sv- prefixed so nothing collides with style.css. ───── */
:root {
  --sv-bg: #FBF7F1; --sv-panel: #FFFFFF; --sv-sunk: #F2E8DA; --sv-line: #E6D6BF;
  --sv-ink: #2A1C11; --sv-ink-2: #6B4C30; --sv-muted: #7E6650;
  --sv-espresso: #24170E; --sv-espresso-2: #4A2F1C; --sv-gold: #C9963A; --sv-gold-hi: #F0CF8A; --sv-gold-dark: #8A6A22; --sv-rust: #B5532A; --sv-ok: #2F7D4F;
  --sv-on-dark: #FBF3E8; --sv-on-dark-2: #E3CFB4;
  --sv-display: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
  --sv-body: 'DM Sans', 'Segoe UI', Tahoma, sans-serif;
}
[class^="sv-"], [class^="sv-"] *, [class*=" sv-"] { box-sizing: border-box; }
/* responsive.css sets overflow-x:hidden on html/body for phones, which turns
   body into a scroll box and stops the category bar from sticking. clip
   hides sideways overflow the same way without breaking sticky. */
@supports (overflow: clip) { html, body { overflow-x: clip !important; } }
.sv-page { background: var(--sv-bg); color: var(--sv-ink); font-family: var(--sv-body); }
.sv-wrap { max-width: 1160px; margin: 0 auto; padding-inline: 16px; }
.sv-page button, .sv-page input, .sv-page select { font-family: var(--sv-body); }

.sv-top { background: radial-gradient(120% 120% at 90% 0%, var(--sv-espresso-2), var(--sv-espresso) 65%); color: var(--sv-on-dark); padding-block: 28px 26px; }
.sv-eyebrow { display: block; font-size: 0.75rem; font-weight: 800; letter-spacing: 0.18em; text-transform: uppercase; color: var(--sv-gold); }
.sv-top h1 { font-family: var(--sv-display); font-weight: 700; font-size: clamp(2.4rem, 7vw, 3.6rem); line-height: 1; margin: 6px 0; color: var(--sv-on-dark); }
.sv-top h1 em { color: var(--sv-gold-hi); }
.sv-top p { margin: 0 0 18px; color: var(--sv-on-dark-2); }
.sv-search { position: relative; max-width: 560px; }
.sv-search svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: var(--sv-muted); pointer-events: none; }
.sv-search input { width: 100%; min-height: 54px; border-radius: 999px; border: 0; padding: 0 48px; background: var(--sv-panel); color: var(--sv-ink); font-size: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,.25); outline: none; }
.sv-search input:focus { box-shadow: 0 0 0 3px var(--sv-gold), 0 10px 30px rgba(0,0,0,.25); }
.sv-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border-radius: 50%; border: 0; background: var(--sv-sunk); color: var(--sv-ink); font-weight: 800; cursor: pointer; }

.sv-catbar { position: sticky; top: 0; z-index: 50; background: rgba(251,247,241,.96); backdrop-filter: blur(8px); border-bottom: 1px solid var(--sv-line); }
.sv-catbar .sv-wrap { display: flex; align-items: center; gap: 8px; }
.sv-chips { display: flex; gap: 8px; overflow-x: auto; scroll-snap-type: x proximity; padding: 12px 2px; scrollbar-width: none; flex: 1; min-width: 0; -webkit-mask-image: linear-gradient(90deg, #000 92%, transparent); mask-image: linear-gradient(90deg, #000 92%, transparent); }
.sv-chips::-webkit-scrollbar { display: none; }
.sv-chip { flex: none; scroll-snap-align: start; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 4px 14px 4px 4px; border-radius: 999px; border: 1.5px solid var(--sv-line); background: var(--sv-panel); color: var(--sv-ink); font-weight: 700; font-size: 0.92rem; white-space: nowrap; cursor: pointer; }
.sv-chip img, .sv-chip i { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; flex: none; }
.sv-chip i { display: grid; place-items: center; background: var(--sv-sunk); font-style: normal; }
.sv-chip small { font-weight: 500; color: var(--sv-muted); }
.sv-chip[aria-current="true"] { background: var(--sv-espresso); border-color: var(--sv-espresso); color: var(--sv-gold-hi); }
.sv-chip[aria-current="true"] small { color: var(--sv-on-dark-2); }
.sv-allbtn { flex: none; min-height: 44px; padding: 0 14px; border-radius: 999px; border: 1.5px solid var(--sv-line); background: var(--sv-panel); color: var(--sv-ink); font-weight: 700; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.sv-allbtn svg { width: 18px; height: 18px; }

.sv-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding-block: 16px 4px; }
.sv-fchip { min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1.5px solid var(--sv-line); background: var(--sv-panel); color: var(--sv-ink); font-weight: 700; font-size: 0.9rem; cursor: pointer; }
.sv-fchip[aria-pressed="true"] { background: var(--sv-gold); border-color: var(--sv-gold); color: #1E130A; }
.sv-sort { margin-left: auto; display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: var(--sv-ink-2); }
.sv-sort select { min-height: 40px; border-radius: 12px; border: 1.5px solid var(--sv-line); background: var(--sv-panel); color: var(--sv-ink); padding: 0 10px; font-weight: 700; width: auto; }
.sv-count { font-size: 0.9rem; color: var(--sv-ink-2); padding-top: 6px; min-height: 1.4em; }

.sv-sec { padding-top: 30px; }
.sv-sec-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
.sv-sec-head img { width: 56px; height: 56px; border-radius: 16px; object-fit: cover; flex: none; }
.sv-sec-head h2 { font-family: var(--sv-display); font-weight: 700; font-size: 2rem; line-height: 1; color: var(--sv-ink); margin: 0; }
.sv-sec-head span { font-size: 0.9rem; color: var(--sv-ink-2); }

.sv-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 980px) { .sv-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.sv-card { position: relative; display: flex; flex-direction: column; text-align: left; background: var(--sv-panel); border: 1px solid var(--sv-line); border-radius: 22px; overflow: hidden; padding: 0; color: var(--sv-ink); cursor: pointer; transition: transform .25s, box-shadow .25s; width: 100%; }
.sv-card:hover { transform: translateY(-4px); box-shadow: 0 18px 38px rgba(42,28,17,.14); }
.sv-card.sv-flash { box-shadow: 0 0 0 4px var(--sv-gold); }
.sv-ph { position: relative; aspect-ratio: 4 / 3; background: var(--sv-sunk); overflow: hidden; }
.sv-ph img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; transition: transform .5s; }
.sv-card:hover .sv-ph img { transform: scale(1.05); }
.sv-nopic { position: absolute; inset: 0; display: grid; place-items: center; font-size: 2rem; background: linear-gradient(160deg, #F6EBD3, var(--sv-sunk)); }
.sv-bd { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 4px; flex: 1; width: 100%; }
.sv-name { display: block; font-size: 1.08rem; font-weight: 700; line-height: 1.3; color: var(--sv-ink); }
.sv-ds { color: var(--sv-ink-2); font-size: 0.9rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.sv-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 2px; }
.sv-tag { font-size: 0.75rem; font-weight: 800; border-radius: 999px; padding: 2px 9px; background: var(--sv-sunk); color: var(--sv-ink-2); }
.sv-tag.promo { background: var(--sv-rust); color: #fff; }
.sv-tag.home { background: #E3F0E7; color: var(--sv-ok); }
.sv-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; padding-top: 10px; }
.sv-pr { font-weight: 800; font-size: 1.25rem; }
.sv-pr small { font-size: 0.78rem; font-weight: 500; color: var(--sv-muted); }
.sv-pr s { font-size: 0.85rem; font-weight: 400; color: var(--sv-muted); margin-left: 4px; }
.sv-len { font-size: 0.85rem; color: var(--sv-muted); }
.sv-go { min-height: 40px; padding: 0 16px; border-radius: 999px; background: var(--sv-espresso); color: var(--sv-on-dark); font-weight: 800; font-size: 0.9rem; display: inline-flex; align-items: center; flex: none; }
.sv-ribbon { position: absolute; top: 12px; left: 12px; z-index: 1; background: var(--sv-rust); color: #fff; font-size: 0.78rem; font-weight: 800; border-radius: 999px; padding: 4px 10px; }
@media (max-width: 640px) {
  .sv-grid { grid-template-columns: minmax(0, 1fr); gap: 10px; }
  .sv-card { flex-direction: row; border-radius: 18px; }
  .sv-card:hover { transform: none; }
  .sv-ph { width: 104px; flex: none; aspect-ratio: auto; align-self: stretch; min-height: 116px; }
  .sv-bd { padding: 12px 12px 12px 14px; min-width: 0; }
  .sv-name { font-size: 1rem; }
  .sv-ds { -webkit-line-clamp: 1; font-size: 0.85rem; }
  .sv-row { padding-top: 6px; }
  .sv-pr { font-size: 1.1rem; }
  .sv-go { min-height: 36px; padding: 0 14px; }
  .sv-ribbon { top: 8px; left: 8px; font-size: 0.7rem; padding: 2px 8px; }
  .sv-sec-head h2 { font-size: 1.7rem; }
  .sv-sort { margin-left: 0; width: 100%; }
  .sv-sort select { flex: 1; }
}
.sv-empty { text-align: center; padding: 48px 16px; color: var(--sv-ink-2); }
.sv-empty b { display: block; font-size: 1.2rem; color: var(--sv-ink); margin-bottom: 6px; }
.sv-empty button { margin-top: 14px; min-height: 44px; padding: 0 20px; border-radius: 999px; border: 0; background: var(--sv-espresso); color: var(--sv-on-dark); font-weight: 800; cursor: pointer; }
.sv-help { margin-block: 48px 40px; background: var(--sv-espresso); color: var(--sv-on-dark); border-radius: 24px; padding: 26px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; }
.sv-help h2 { font-family: var(--sv-display); font-weight: 700; font-size: 1.9rem; color: var(--sv-gold-hi); margin: 0; }
.sv-help p { margin: 4px 0 0; color: var(--sv-on-dark-2); }
.sv-help a { color: var(--sv-on-dark); font-weight: 800; font-size: 1.15rem; }
.sv-totop { position: fixed; right: 16px; bottom: calc(18px + env(safe-area-inset-bottom, 0px)); z-index: 60; width: 48px; height: 48px; border-radius: 50%; border: 0; background: var(--sv-gold); color: #1E130A; font-weight: 800; font-size: 1.2rem; box-shadow: 0 10px 24px rgba(0,0,0,.25); cursor: pointer; }

.sv-scrim { position: fixed; inset: 0; background: rgba(20,12,6,.55); z-index: 1000; }
.sv-sheet { position: fixed; z-index: 1001; left: 50%; bottom: 0; transform: translateX(-50%); width: min(560px, 100%); max-height: 92vh; overflow-y: auto; background: var(--sv-panel); color: var(--sv-ink); border-radius: 26px 26px 0 0; box-shadow: 0 -16px 50px rgba(0,0,0,.3); font-family: var(--sv-body); animation: sv-up .28s cubic-bezier(.2,.8,.2,1); }
@media (min-width: 761px) { .sv-sheet { bottom: auto; top: 50%; transform: translate(-50%, -50%); border-radius: 26px; animation: none; } }
@keyframes sv-up { from { transform: translate(-50%, 40px); opacity: 0; } }
.sv-hero { position: relative; aspect-ratio: 16 / 10; background: var(--sv-sunk); }
.sv-hero img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; }
.sv-x { position: absolute; top: 12px; right: 12px; width: 44px; height: 44px; border-radius: 50%; border: 0; background: rgba(255,255,255,.95); color: var(--sv-ink); font-weight: 800; font-size: 1.1rem; cursor: pointer; }
.sv-in { padding: 18px 20px 20px; display: flex; flex-direction: column; gap: 14px; }
.sv-in .sv-cat { font-size: 0.75rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--sv-gold-dark); }
.sv-in h2 { font-family: var(--sv-display); font-weight: 700; font-size: 2.1rem; line-height: 1.05; margin: 0; color: var(--sv-ink); }
.sv-in p { margin: 0; color: var(--sv-ink-2); line-height: 1.55; }
.sv-lbl { font-weight: 800; font-size: 0.95rem; margin-bottom: 8px; }
.sv-opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; }
.sv-opt { min-height: 56px; border-radius: 16px; border: 2px solid var(--sv-line); background: var(--sv-panel); padding: 8px 12px; display: flex; flex-direction: column; justify-content: center; }
.sv-opt b { font-size: 1rem; }
.sv-opt span { font-size: 0.85rem; color: var(--sv-ink-2); }
.sv-note { font-size: 0.85rem; color: var(--sv-muted); }
.sv-foot { position: sticky; bottom: 0; background: var(--sv-panel); border-top: 1px solid var(--sv-line); padding: 12px 20px calc(12px + env(safe-area-inset-bottom, 0px)); display: flex; align-items: center; gap: 14px; }
.sv-foot .sv-tot { flex: 1; }
.sv-foot .sv-tot small { display: block; color: var(--sv-muted); font-size: 0.8rem; }
.sv-foot .sv-tot b { font-size: 1.5rem; }
.sv-foot form { margin: 0; }
.sv-book { min-height: 52px; padding: 0 26px; border-radius: 999px; border: 0; background: var(--sv-gold); color: #1E130A; font-weight: 800; font-size: 1rem; box-shadow: 0 10px 24px rgba(201,150,58,.35); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
.sv-catgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 0 20px 20px; }
.sv-catgrid button { position: relative; border: 0; padding: 0; border-radius: 18px; overflow: hidden; aspect-ratio: 4 / 3; background: var(--sv-sunk); color: #fff; text-align: left; cursor: pointer; }
.sv-catgrid img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none; object-fit: cover; }
.sv-catgrid button::after { content: ''; position: absolute; inset: 0; background: linear-gradient(transparent 35%, rgba(20,12,6,.85)); }
.sv-catgrid span { position: absolute; z-index: 1; left: 12px; right: 12px; bottom: 10px; font-weight: 800; line-height: 1.2; }
.sv-catgrid small { display: block; font-weight: 500; color: #EAD8C0; }
.sv-stitle { padding: 20px 20px 12px; display: flex; justify-content: space-between; align-items: center; }
.sv-stitle h2 { font-family: var(--sv-display); font-weight: 700; font-size: 1.8rem; margin: 0; }
.sv-stitle .sv-x { position: static; background: var(--sv-sunk); }
@media (prefers-reduced-motion: reduce) { .sv-card, .sv-ph img, .sv-sheet { transition: none !important; animation: none !important; } }
</style>

<div class="sv-page">
<div class="sv-top">
    <div class="sv-wrap">
        <span class="sv-eyebrow">Our menu</span>
        <h1>Services for <em>skin and wellness</em></h1>
        <p><?php echo count($items); ?> services in <?php echo count($cats); ?> categories. Tap a service to see prices and book.</p>
        <div class="sv-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="svQ" type="search" placeholder="Search a service, e.g. foot spa" aria-label="Search services" autocomplete="off">
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
        <button type="button" class="sv-fchip" data-f="promo" aria-pressed="false">On promo</button>
        <button type="button" class="sv-fchip" data-f="home" aria-pressed="false">Home service</button>
        <button type="button" class="sv-fchip" data-f="cheap" aria-pressed="false">Under ₱500</button>
        <label class="sv-sort">Sort <select id="svSort"><option value="pop">Most popular</option><option value="low">Price: low to high</option><option value="high">Price: high to low</option><option value="az">Name A–Z</option></select></label>
    </div>
    <div class="sv-count" id="svCount" aria-live="polite"></div>
    <div id="svSections"></div>

    <div class="sv-help">
        <div><h2>Not sure what to pick?</h2><p>Call or message us and our staff will help you choose.</p></div>
        <div><p>Phone / Viber</p><a href="tel:+639853359998">+63 985 335 9998</a></div>
    </div>
</div>
</div>

<button type="button" class="sv-totop" id="svTop" aria-label="Back to top" hidden>↑</button>
<div class="sv-scrim" id="svScrim" hidden></div>
<div class="sv-sheet" id="svSheet" role="dialog" aria-modal="true" aria-labelledby="svShTitle" hidden></div>

<!-- Booking form template: the sheet copies it and fills in the service. -->
<template id="svBookTpl">
<?php if (is_logged_in() && !is_admin()): ?>
    <form method="POST" action="<?php echo BASE_URL; ?>services.php"><?php echo csrf_field(); ?><input type="hidden" name="service_id" value=""><button type="submit" name="book_service" class="sv-book">Book now</button></form>
<?php else: ?>
    <a class="sv-book" href="<?php echo BASE_URL; ?>user/auth.php">Log in to book</a>
<?php endif; ?>
</template>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
(function () {
    var CATS  = <?php echo json_encode($cats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var SVC   = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var REQ_CAT  = <?php echo (int)$requested_category; ?>;
    var REQ_ITEM = <?php echo json_encode($requested_item, JSON_HEX_TAG); ?>;
    var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    var byId = {}; SVC.forEach(function (s) { byId[s.id] = s; });
    var catName = {}; CATS.forEach(function (c) { catName[c.id] = c.name; });

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function mins(m) { if (!m) return ''; var h = Math.floor(m / 60), r = m % 60; return (h ? h + ' hr' + (r ? ' ' : '') : '') + (r ? r + ' min' : ''); }
    function low(s) { return s.opts.reduce(function (a, o) { return Math.min(a, o.p); }, Infinity); }
    function short(n) { return n.replace(/ Services?$/i, ''); }

    var state = { q: '', f: { promo: false, home: false, cheap: false }, sort: 'pop' };
    function filtered() { return state.q || state.f.promo || state.f.home || state.f.cheap; }
    function matches(s) {
        var q = state.q.trim().toLowerCase();
        if (q && (s.name + ' ' + (catName[s.cat] || '') + ' ' + s.desc).toLowerCase().indexOf(q) < 0) return false;
        if (state.f.promo && !s.promo) return false;
        if (state.f.home && !s.home) return false;
        if (state.f.cheap && low(s) >= 500) return false;
        return true;
    }
    function card(s) {
        var lo = low(s), was = s.opts.length === 1 ? s.opts[0].was : 0;
        var tags = (s.promo ? '<span class="sv-tag promo">Promo</span>' : '') + (s.home ? '<span class="sv-tag home">Home service</span>' : '');
        var lens = s.opts.map(function (o) { return mins(o.m); }).filter(Boolean).join(' / ');
        return '<button type="button" class="sv-card" id="svc-' + s.id + '" data-id="' + s.id + '"><span class="sv-ph">' +
            (s.img ? '<img src="' + esc(s.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '<span class="sv-nopic" aria-hidden="true">💆</span>') +
            (s.top ? '<span class="sv-ribbon">Most booked</span>' : '') + '</span>' +
            '<span class="sv-bd"><span class="sv-name">' + esc(s.name) + '</span>' + (s.desc ? '<span class="sv-ds">' + esc(s.desc) + '</span>' : '') +
            (tags ? '<span class="sv-tags">' + tags + '</span>' : '') +
            '<span class="sv-row"><span><span class="sv-pr">' + (s.opts.length > 1 ? '<small>from </small>' : '') + peso(lo) + (was > lo ? '<s>' + peso(was) + '</s>' : '') + '</span>' +
            (lens ? '<br><span class="sv-len">' + esc(lens) + '</span>' : '') + '</span><span class="sv-go">Book</span></span></span></button>';
    }
    function render() {
        var list = SVC.filter(matches);
        if (state.sort === 'low') list.sort(function (a, b) { return low(a) - low(b); });
        else if (state.sort === 'high') list.sort(function (a, b) { return low(b) - low(a); });
        else if (state.sort === 'az') list.sort(function (a, b) { return a.name.localeCompare(b.name); });
        else list.sort(function (a, b) { return b.pop - a.pop || a.name.localeCompare(b.name); });
        var html = '', shown = 0;
        CATS.forEach(function (c) {
            var items = list.filter(function (s) { return s.cat === c.id; });
            if (!items.length) return;
            shown += items.length;
            html += '<section class="sv-sec" id="cat-' + c.id + '"><div class="sv-sec-head">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '') +
                '<div><h2>' + esc(c.name) + '</h2><span>' + items.length + ' service' + (items.length > 1 ? 's' : '') + '</span></div></div><div class="sv-grid">' + items.map(card).join('') + '</div></section>';
        });
        $('svSections').innerHTML = html || '<div class="sv-empty"><b>No services found' + (state.q ? ' for “' + esc(state.q) + '”' : '') + '</b>Try another word or remove a filter.<br><button type="button" id="svReset">Show all services</button></div>';
        $('svCount').textContent = filtered() ? shown + ' of ' + SVC.length + ' services shown' : '';
        drawChips(list);
        spy();
    }
    function drawChips(list) {
        var cur = document.querySelector('.sv-chip[aria-current="true"]');
        var curId = cur ? cur.dataset.go : '';
        $('svChips').innerHTML = CATS.map(function (c) {
            var n = list.filter(function (s) { return s.cat === c.id; }).length;
            if (!n) return '';
            return '<button type="button" class="sv-chip" data-go="cat-' + c.id + '" aria-current="' + (curId === 'cat-' + c.id) + '">' +
                (c.img ? '<img src="' + esc(c.img) + '" alt="" onerror="this.remove()">' : '<i aria-hidden="true">✦</i>') + esc(short(c.name)) + ' <small>' + n + '</small></button>';
        }).join('');
    }

    // The site header is sticky; the category bar sits right under it.
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
        state.q = ''; state.f = { promo: false, home: false, cheap: false };
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
        var c = e.target.closest('.sv-card'); if (c) openSvc(byId[+c.dataset.id]);
    });

    // Sheets
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

    function openSvc(s) {
        var opts = s.opts.map(function (o) {
            return '<div class="sv-opt"><b>' + (mins(o.m) || 'Price') + '</b><span>' + peso(o.p) + (o.was > o.p ? ' <s>' + peso(o.was) + '</s>' : '') + '</span></div>';
        }).join('');
        var lo = low(s);
        openSheet('<div class="sv-hero">' + (s.img ? '<img src="' + esc(s.img) + '" alt="' + esc(s.name) + '" onerror="this.remove()">' : '') +
            '<button type="button" class="sv-x" aria-label="Close">✕</button></div>' +
            '<div class="sv-in"><span class="sv-cat">' + esc(catName[s.cat] || '') + '</span><h2 id="svShTitle">' + esc(s.name) + '</h2>' +
            (s.desc ? '<p>' + esc(s.desc) + '</p>' : '') +
            '<div><div class="sv-lbl">' + (s.opts.length > 1 ? 'Lengths and prices' : 'Price') + '</div><div class="sv-opts">' + opts + '</div></div>' +
            (s.home ? '<div><div class="sv-lbl">Home service</div><div class="sv-opts"><div class="sv-opt"><b>At the spa or at your home</b><span>' +
                (s.homePrice > 0 ? 'Home service: ' + peso(s.homePrice) : 'Home service available') + '</span></div></div></div>' : '') +
            '<span class="sv-note">You choose the date, time' + (s.home ? ', place' : '') + (s.opts.length > 1 ? ' and length' : '') + ' on the next step.</span></div>' +
            '<div class="sv-foot"><div class="sv-tot"><small>' + (s.opts.length > 1 ? 'Starts at' : 'Price') + '</small><b>' + peso(lo) + '</b></div><span id="svBookSlot"></span></div>');
        var tpl = $('svBookTpl').content.cloneNode(true);
        var idInput = tpl.querySelector('input[name="service_id"]'); if (idInput) idInput.value = s.id;
        $('svBookSlot').appendChild(tpl);
    }
    function openCats() {
        openSheet('<div class="sv-stitle"><h2 id="svShTitle">All categories</h2><button type="button" class="sv-x" aria-label="Close">✕</button></div><div class="sv-catgrid">' +
            CATS.map(function (c) { return '<button type="button" data-cat="cat-' + c.id + '">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" onerror="this.remove()">' : '') + '<span>' + esc(c.name) + '<small>' + c.n + ' service' + (c.n > 1 ? 's' : '') + '</small></span></button>'; }).join('') + '</div>');
    }
    $('svAllCats').addEventListener('click', openCats);
    $('svSheet').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.classList.contains('sv-x')) { closeSheet(); return; }
        if (b.dataset.cat) { closeSheet(); if (filtered()) resetAll(); setTimeout(function () { jump(b.dataset.cat, true); }, 30); }
    });

    render();

    // Deep links from the home page and search: ?category=X and ?item=svc-Y
    (function () {
        var m = /^svc-(\d+)$/.exec(REQ_ITEM || '');
        if (m && byId[+m[1]]) {
            var card = $('svc-' + m[1]);
            if (card) {
                scrollTo({ top: card.getBoundingClientRect().top + scrollY - offset() - 40, behavior: 'auto' });
                card.classList.add('sv-flash'); setTimeout(function () { card.classList.remove('sv-flash'); }, 2600);
                openSvc(byId[+m[1]]);
            }
        } else if (REQ_CAT && $('cat-' + REQ_CAT)) {
            jump('cat-' + REQ_CAT, false);
        }
    })();
})();
</script>
</body>
</html>
