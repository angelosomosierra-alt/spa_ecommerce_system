<?php
require_once 'config.php';

// ── book_service: identical to index.php's old handler, moved here since
// booking now happens from this page's item cards, not the homepage. ──
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

$services = [];
$res_svc = $conn->query("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON s.category_id = c.id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name");
while ($row = $res_svc->fetch_assoc()) $services[] = $row;

$services_by_cat = [];
foreach ($services as $svc) {
    $key = $svc['category_id'] ? (int)$svc['category_id'] : 0;
    if (!isset($services_by_cat[$key])) {
        $services_by_cat[$key] = ['label' => $svc['category_name'] ?: 'Other', 'items' => []];
    }
    $services_by_cat[$key]['items'][] = $svc;
}

$requested_category = intval($_GET['category'] ?? 0);
$requested_item      = $_GET['item'] ?? '';

$page_title  = 'Services — Recovery Iloilo';
$active_page = 'services';
require_once 'header.php';
?>
<style>
.services-hero { padding: 2.5rem 0 1rem; text-align: center; }
.services-hero h1 { font-family:'Cormorant Garamond',serif; font-size: 2.4rem; color: var(--brown); margin-bottom: 0.5rem; }
.services-hero p { color: var(--gray); font-size: 1.1rem; }

/* ── Menu-style browsing cards (page-scoped override of the shared
   .kiosk-item-card shell) — modeled on a reference "menu grid" layout:
   photo, then name + price on one row, nothing else. No per-card
   description or button; the whole card is the tap target and opens the
   existing booking modal (same modal, just triggered from the card
   instead of a button inside it), matching how a browsing menu reads at
   a glance rather than like a product landing page. ─────────────────── */
.kiosk-item-card { cursor: pointer; border-radius: 50px; size: 100%; overflow: hidden; display:flex; flex-direction:column; background:var(--warm); box-shadow:0 1px 3px rgba(0,0,0,.1); transition:transform .2s ease,box-shadow .2s ease; width:90%; height:90%; }
.svc-body { padding: 0.9rem 1rem 1rem; display:flex; flex-direction:column; }
.svc-img-wrap { position:relative; aspect-ratio:4/3; overflow:hidden; background:var(--warm); }
.svc-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.img-placeholder { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:linear-gradient(135deg,var(--warm),#EAD8C0); color:var(--brown-md); font-size:2.2rem; gap:.3rem; }
.img-placeholder small { font-size:.75rem; letter-spacing:.08em; text-transform:uppercase; color:var(--brown-lt); }
.svc-row { display:flex; align-items:baseline; justify-content:space-between; gap:.75rem; }
.svc-name { font-family:'Cormorant Garamond',serif; font-size:1.15rem; font-weight:700; color:var(--brown); line-height:1.25; }
.svc-price { font-size:1.1rem; font-weight:700; color:var(--rust-dark); white-space:nowrap; flex-shrink:0; }
.svc-duration-mini { font-size:.82rem; color:var(--gray); margin-top:.3rem; }

/* ── Formal, icon-free sidebar (page-scoped override of the shared
   .kiosk-sidebar/.kiosk-cat-btn chip styling) — a plain text list with a
   thin divider between rows and a left accent bar on the active category,
   instead of colored emoji + tinted pill backgrounds. Matches the
   requested plainer, more formal look. ──────────────────────────────── */
.kiosk-sidebar { background: transparent; border: none; box-shadow: none; padding: 0; gap: 0;
text-align: left; 

    /* If the sidebar uses flexbox layout */
    display: flex;
    flex-direction: column;
    align-items: flex-start;  /* Aligns flex children items to the left */
    justify-content: flex-start; }
.kiosk-cat-btn {
    background: transparent; border: none; border-radius: 0;
    border-bottom: 1px solid var(--border); border-left: 3px solid transparent;
    padding: 0.9rem 1rem; font-weight: 600; color: var(--brown);
}
.kiosk-cat-btn:hover { background: var(--warm); transform: none; }
.kiosk-cat-btn.active { background: var(--brown-lt); color: var(--brown); border-left-color: var(--rust-dark); font-weight: 700; }
.kiosk-cat-btn.active::before { content: none; }
@media (max-width: 860px) {
    .kiosk-cat-btn { border-bottom: none; border-left: none; border-right: 1px solid var(--border); }
    .kiosk-cat-btn.active { border-left: none; border-bottom: 3px solid var(--rust-dark); }
}

/* Category section headings, icon-free */
.kiosk-content-title { font-weight: 700; }

/* ── Full-bleed layout — the reference sits the sidebar right at the
   browser edge with only a small gutter, not inset behind the site's
   normal ~1200px centered container. Breaks out to the viewport edges
   (same negative-margin trick used elsewhere on this site) so the
   sidebar and item grid both get the full screen width to work with
   instead of losing ~150px+ of it to centering on a wide screen. ────── */
.services-fullbleed {
    position: relative; left: 50%; right: 50%;
    margin-left: -50vw; margin-right: -50vw; width: 100vw;
    padding: 0 2rem;
    box-sizing: border-box;
}
@media (max-width: 620px) { .services-fullbleed { padding: 0 1.25rem; } }
</style>

<div class="spa-container" style="padding-top:0;">
<div class="services-hero">
    <h1>Our <em>Services</em></h1>
    <p>Browse by category using the sidebar — every treatment we offer, in one place.</p>
</div>
</div>

<div class="services-fullbleed">
<div class="kiosk-layout">
    <nav class="kiosk-sidebar" aria-label="Service categories">
        <?php foreach ($services_by_cat as $cat_id => $cat_data): ?>
        <a href="#cat-<?php echo $cat_id; ?>" class="kiosk-cat-btn" data-cat-id="<?php echo $cat_id; ?>">
            <span><?php echo htmlspecialchars($cat_data['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="kiosk-content">
        <?php if (empty($services_by_cat)): ?>
        <div class="empty-state"><p>No services available yet.</p></div>
        <?php endif; ?>
        <?php foreach ($services_by_cat as $cat_id => $cat_data): ?>
        <section id="cat-<?php echo $cat_id; ?>" data-cat-section="<?php echo $cat_id; ?>">
            <h2 class="kiosk-content-title"><?php echo htmlspecialchars($cat_data['label']); ?></h2>
            <div class="kiosk-item-grid">
                <?php foreach ($cat_data['items'] as $svc): ?>
                <div class="kiosk-item-card" id="svc-<?php echo $svc['id']; ?>" onclick="openSvcModal(<?php echo $svc['id']; ?>)" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openSvcModal(<?php echo $svc['id']; ?>);}">
                    <div class="svc-img-wrap">
                        <?php if (!empty($svc['image'])): ?>
                        <img src="uploads/services/<?php echo htmlspecialchars($svc['image']); ?>" alt="<?php echo htmlspecialchars($svc['name']); ?>" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="img-placeholder" style="display:none"><small>Spa Service</small></div>
                        <?php else: ?>
                        <div class="img-placeholder"><small>Spa Service</small></div>
                        <?php endif; ?>
                    </div>
                    <div class="svc-body">
                        <div class="svc-row">
                            <h3 class="svc-name"><?php echo htmlspecialchars($svc['name']); ?></h3>
                            <span class="svc-price">₱<?php echo number_format($svc['price'],2); ?></span>
                        </div>
                        <span class="svc-duration-mini"><?php echo $svc['session_time']; ?> min</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</div>
</div>

<?php require_once 'footer.php'; ?>

<?php foreach ($services as $svc): ?>
<div class="spa-modal" id="svcModal<?php echo $svc['id']; ?>">
    <div class="modal-box" style="position:relative;">
        <button class="modal-close-btn" onclick="closeSvcModal(<?php echo $svc['id']; ?>)" aria-label="Close">✕</button>
        <img class="modal-img" src="uploads/services/<?php echo htmlspecialchars($svc['image']); ?>" alt="<?php echo htmlspecialchars($svc['name']); ?>"
             onerror="this.onerror=null; this.src='uploads/products/default.png';">
        <div class="modal-body-inner">
            <?php if (!empty($svc['category_name'])): ?><span class="modal-cat-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span><?php endif; ?>
            <h2 class="modal-title"><?php echo htmlspecialchars($svc['name']); ?></h2>
            <p class="modal-desc"><?php echo htmlspecialchars($svc['description']); ?></p>
            <div class="modal-price-row">
                <span class="modal-price">₱<?php echo number_format($svc['price'],2); ?></span>
                <span class="modal-meta"><?php echo $svc['session_time']; ?> minutes</span>
            </div>
            <div class="modal-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" action="services.php" style="flex:1;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="service_id" value="<?php echo $svc['id']; ?>">
                    <button type="submit" name="book_service" class="btn-modal-primary" style="width:100%;">Book Now</button>
                </form>
                <?php else: ?>
                <a href="user/auth.php" class="btn-modal-primary" style="text-align:center;text-decoration:none;display:block;padding:0.85rem;flex:1;">Login to Book</a>
                <?php endif; ?>
                <button class="btn-modal-secondary" onclick="closeSvcModal(<?php echo $svc['id']; ?>)">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function openSvcModal(id)  { document.getElementById('svcModal'+id).classList.add('active'); }
function closeSvcModal(id) { document.getElementById('svcModal'+id).classList.remove('active'); }
document.querySelectorAll('.spa-modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.spa-modal.active').forEach(function(m) { m.classList.remove('active'); });
});

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
        entries.forEach(function(entry) {
            if (entry.isIntersecting) setActiveCat(entry.target.dataset.catSection);
        });
    }, { rootMargin: '-100px 0px -70% 0px', threshold: 0 });
    catSections.forEach(function(s) { observer.observe(s); });
}

/* ── Deep link: ?category=X (sidebar) and ?item=svc-Y (specific card) ──── */
(function() {
    var reqCat  = <?php echo json_encode($requested_category); ?>;
    var reqItem = <?php echo json_encode($requested_item); ?>;
    function goDeepLink() {
        if (reqItem && reqItem.indexOf('svc-') === 0) {
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
