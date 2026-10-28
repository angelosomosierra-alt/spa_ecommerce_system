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
    $service_id  = intval($_POST['service_id'] ?? 0);
    $duration_id = intval($_POST['duration_id'] ?? 0);
    if ($service_id > 0) {
        // The length or package picked on the sheet; checkout can still change it.
        if ($duration_id > 0) {
            $dchk = $conn->prepare("SELECT id FROM service_durations WHERE id = ? AND service_id = ?");
            $dchk->bind_param("ii", $duration_id, $service_id); $dchk->execute();
            if (!$dchk->get_result()->fetch_assoc()) $duration_id = 0;
            $dchk->close();
        }
        $_SESSION['service_booking'] = ['service_id' => $service_id, 'duration_id' => $duration_id ?: null];
        header('Location: ' . BASE_URL . 'user/checkout.php');
        exit();
    }
}

$svc_base = (is_logged_in() && !is_admin()) ? BASE_URL . 'user/' : BASE_URL;

$services = [];
$res_svc = $conn->query("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON s.category_id = c.id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name");
while ($row = $res_svc->fetch_assoc()) $services[] = $row;

// Duration options (one row per length) and multi-session packages (paid in
// full; Session 1 is booked at checkout, the rest are scheduled by staff) —
// same pricing Book Now uses.
$durs = [];
$pkgs = [];
$t = $conn->query("SHOW TABLES LIKE 'service_durations'");
if ($t && $t->num_rows) {
    $res = $conn->query("SELECT * FROM service_durations ORDER BY session_count, duration_minutes");
    while ($r = $res->fetch_assoc()) {
        if ((int)($r['session_count'] ?? 1) > 1) $pkgs[(int)$r['service_id']][] = $r;
        else $durs[(int)$r['service_id']][] = $r;
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
            $opts[] = ['id' => (int)$d['id'], 'm' => (int)$d['duration_minutes'], 'p' => (float)$p['price'],
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
    $pk = [];
    foreach ($pkgs[$id] ?? [] as $d) {
        $p = get_active_duration_price($d);
        $pk[] = ['id' => (int)$d['id'], 'm' => (int)$d['duration_minutes'], 'n' => (int)$d['session_count'],
                 'p' => (float)$p['price'], 'was' => $p['is_promo_active'] ? (float)$d['regular_price'] : 0];
    }
    $home_price = !empty($s['is_home_service']) ? (float)($s['home_service_price'] ?? 0) : 0;
    $items[] = [
        'pkgs' => $pk,
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
$page_description = 'Services and prices at Recovery Iloilo, Molo, Iloilo City: massage, Japanese head spa, nails, lashes, brows, facials, laser and aesthetics.';
$active_page = 'services';
require_once defined('HOME_ACCOUNT_AREA') ? __DIR__ . '/user/header.php' : __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/menu.css?v=<?php echo filemtime(__DIR__ . '/assets/menu.css'); ?>">

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
    <form method="POST" action="<?php echo BASE_URL; ?>services.php"><?php echo csrf_field(); ?><input type="hidden" name="service_id" value=""><input type="hidden" name="duration_id" value=""><button type="submit" name="book_service" class="sv-book">Book now</button></form>
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
        // Multi-session packages are flagged on the photo, so they show at a glance.
        var sess = s.pkgs.length ? '1–' + s.pkgs[s.pkgs.length - 1].n + ' sessions' : '';
        var lens = s.opts.map(function (o) { return mins(o.m); }).filter(Boolean).join(' / ');
        return '<button type="button" class="sv-card" id="svc-' + s.id + '" data-id="' + s.id + '"><span class="sv-ph">' +
            (s.img ? '<img src="' + esc(s.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '<span class="sv-nopic" aria-hidden="true"><?php echo spa_icon('leaf'); ?></span>') +
            (s.top ? '<span class="sv-ribbon">Most booked</span>' : '') + (sess ? '<span class="sv-sess">' + sess + '</span>' : '') + '</span>' +
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

    // The sheet's options are choices: the one picked goes to checkout.
    function optBtn(o, label, sub) {
        return '<button type="button" class="sv-opt sv-pick" data-dur="' + (o.id || '') + '" data-p="' + o.p + '" data-l="' + esc(label) + '" aria-pressed="false"><b>' + label + '</b><span>' +
            peso(o.p) + (o.was > o.p ? ' <s>' + peso(o.was) + '</s>' : '') + '</span>' + (sub ? '<small>' + sub + '</small>' : '') + '</button>';
    }
    function choose(btn) {
        $('svSheet').querySelectorAll('.sv-pick').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        var tot = $('svSheet').querySelector('.sv-tot');
        if (tot) tot.innerHTML = '<small>' + esc(btn.dataset.l) + '</small><b>' + peso(+btn.dataset.p) + '</b>';
        var dur = $('svSheet').querySelector('input[name="duration_id"]'); if (dur) dur.value = btn.dataset.dur;
    }
    function openSvc(s) {
        var opts = s.opts.map(function (o) { return optBtn(o, mins(o.m) || 'Price', ''); }).join('');
        var pk = s.pkgs.map(function (o) {
            return optBtn(o, o.n + ' sessions', (o.m ? mins(o.m) + ' each · ' : '') + peso(Math.round(o.p / o.n * 100) / 100) + ' per session');
        }).join('');
        var lo = low(s);
        openSheet('<div class="sv-hero">' + (s.img ? '<img src="' + esc(s.img) + '" alt="' + esc(s.name) + '" onerror="this.remove()">' : '') +
            '<button type="button" class="sv-x" aria-label="Close">✕</button></div>' +
            '<div class="sv-in"><span class="sv-cat">' + esc(catName[s.cat] || '') + '</span><h2 id="svShTitle">' + esc(s.name) + '</h2>' +
            (s.desc ? '<p>' + esc(s.desc) + '</p>' : '') +
            '<div><div class="sv-lbl">' + (s.opts.length > 1 ? 'Lengths and prices' : 'Price') + '</div><div class="sv-opts">' + opts + '</div></div>' +
            (pk ? '<div><div class="sv-lbl">Packages</div><div class="sv-opts">' + pk + '</div>' +
                '<span class="sv-note">Paid in full for all sessions. You book Session 1 on the next step; our staff schedules the rest with you at your visit.</span></div>' : '') +
            (s.home ? '<div><div class="sv-lbl">Home service</div><div class="sv-opts"><div class="sv-opt"><b>At the spa or at your home</b><span>' +
                (s.homePrice > 0 ? 'Home service: ' + peso(s.homePrice) : 'Home service available') + '</span></div></div></div>' : '') +
            '<span class="sv-note">You choose the date, time' + (s.home ? ', place' : '') + ' on the next step' + (s.opts.length + s.pkgs.length > 1 ? ', and can still change the length or package there' : '') + '.</span></div>' +
            '<div class="sv-foot"><div class="sv-tot"><small>' + (s.opts.length > 1 ? 'Starts at' : 'Price') + '</small><b>' + peso(lo) + '</b></div><span id="svBookSlot"></span></div>');
        var tpl = $('svBookTpl').content.cloneNode(true);
        var idInput = tpl.querySelector('input[name="service_id"]'); if (idInput) idInput.value = s.id;
        $('svBookSlot').appendChild(tpl);
        var first = $('svSheet').querySelector('.sv-pick');
        if (first) choose(first);
    }
    function openCats() {
        openSheet('<div class="sv-stitle"><h2 id="svShTitle">All categories</h2><button type="button" class="sv-x" aria-label="Close">✕</button></div><div class="sv-catgrid">' +
            CATS.map(function (c) { return '<button type="button" data-cat="cat-' + c.id + '">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" onerror="this.remove()">' : '') + '<span>' + esc(c.name) + '<small>' + c.n + ' service' + (c.n > 1 ? 's' : '') + '</small></span></button>'; }).join('') + '</div>');
    }
    $('svAllCats').addEventListener('click', openCats);
    $('svSheet').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.classList.contains('sv-x')) { closeSheet(); return; }
        if (b.classList.contains('sv-pick')) { choose(b); return; }
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
