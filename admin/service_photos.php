<?php
/**
 * Bulk service photos — a temporary owner-only helper for putting photos back
 * on many services at once after the uploads folder was lost.
 *
 *  - Drop many photos; each is matched to a service by its file name in the
 *    browser, checked by staff, then uploaded one at a time (so PHP's
 *    max_file_uploads / post_max_size limits never get in the way).
 *  - "Restore old photos" copies the photos kept in admin/photo_backup/
 *    (the ones that were in the repo before uploads stopped being tracked)
 *    back onto the services/products whose saved file name they match.
 *
 * Saving works like Edit Service: the file goes to uploads/services/, is
 * mirrored to persistent storage, and the old file is removed.
 *
 * Safe to delete when done: remove this file, admin/photo_backup/, and the
 * "Bulk photos" link in admin/services.php.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();          // not listed in admin_page_roles → owner only
redirect_if_not_admin();

$BACKUP_SERVICES = __DIR__ . '/photo_backup/services/';
$BACKUP_PRODUCTS = __DIR__ . '/photo_backup/products/';

function sp_json(array $a): void { header('Content-Type: application/json'); echo json_encode($a); exit(); }

// ── Upload one photo onto one service (AJAX) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_one') {
    if (!verify_csrf_token_ajax()) sp_json(['ok' => false, 'msg' => 'Your session expired. Reload the page and try again.']);
    $sid     = intval($_POST['service_id'] ?? 0);
    $replace = !empty($_POST['replace']);

    $st = $conn->prepare("SELECT id, name, image FROM services WHERE id = ? AND deleted_at IS NULL");
    $st->bind_param("i", $sid); $st->execute();
    $svc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$svc) sp_json(['ok' => false, 'msg' => 'Service not found.']);

    $has = !empty($svc['image']) && is_file(UPLOAD_DIR_SERVICES . $svc['image']);
    if ($has && !$replace) sp_json(['ok' => false, 'msg' => $svc['name'] . ' already has a photo.']);

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['photo']['error'] ?? -1;
        sp_json(['ok' => false, 'msg' => in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Photo is too big.' : 'Upload failed.']);
    }
    $tmp  = $_FILES['photo']['tmp_name'];
    $info = @getimagesize($tmp);
    $exts = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($exts[$info[2]])) sp_json(['ok' => false, 'msg' => 'That file is not a JPG, PNG, WebP or GIF photo.']);

    if (!is_dir(UPLOAD_DIR_SERVICES)) @mkdir(UPLOAD_DIR_SERVICES, 0755, true);
    $name = 'service_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $exts[$info[2]];
    if (!move_uploaded_file($tmp, UPLOAD_DIR_SERVICES . $name)) sp_json(['ok' => false, 'msg' => 'Could not save the file (folder permission).']);
    mirror_upload_to_persistent(UPLOAD_DIR_SERVICES, PERSISTENT_DIR_SERVICES, $name);

    $up = $conn->prepare("UPDATE services SET image = ? WHERE id = ?");
    $up->bind_param("si", $name, $sid); $up->execute(); $up->close();

    if (!empty($svc['image']) && $svc['image'] !== $name) {
        if (is_file(UPLOAD_DIR_SERVICES . $svc['image'])) @unlink(UPLOAD_DIR_SERVICES . $svc['image']);
        remove_persistent_upload(PERSISTENT_DIR_SERVICES, $svc['image']);
    }
    if (function_exists('log_activity') || (@include_once __DIR__ . '/../notify.php')) {
        if (function_exists('log_activity')) log_activity($conn, 'service_updated', "Photo added to {$svc['name']} (bulk photos)", 'service', $sid);
    }
    sp_json(['ok' => true, 'url' => BASE_URL . 'uploads/services/' . rawurlencode($name)]);
}

// ── Restore the old photos from admin/photo_backup/ (AJAX) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_old') {
    if (!verify_csrf_token_ajax()) sp_json(['ok' => false, 'msg' => 'Your session expired. Reload the page and try again.']);
    $done = ['services' => 0, 'products' => 0];
    $jobs = [
        ['services', "SELECT id, image FROM services WHERE image IS NOT NULL AND image <> ''", $BACKUP_SERVICES, UPLOAD_DIR_SERVICES, PERSISTENT_DIR_SERVICES],
        ['products', "SELECT id, image FROM products WHERE image IS NOT NULL AND image <> ''", $BACKUP_PRODUCTS, UPLOAD_DIR_PRODUCTS, PERSISTENT_DIR_PRODUCTS],
    ];
    foreach ($jobs as [$key, $sql, $from, $to, $persist]) {
        if (!is_dir($from)) continue;
        if (!is_dir($to)) @mkdir($to, 0755, true);
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) {
            $f = basename($r['image']);
            // Only put a file back where the record still points at that exact
            // name and the file is missing — never overwrite a newer photo.
            if (!is_file($to . $f) && is_file($from . $f) && @copy($from . $f, $to . $f)) {
                mirror_upload_to_persistent($to, $persist, $f);
                $done[$key]++;
            }
        }
    }
    sp_json(['ok' => true] + $done);
}

// ── Page data ────────────────────────────────────────────────────────────────
$svc_rows = [];
$res = $conn->query("SELECT s.id, s.name, s.image, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id = s.category_id WHERE s.deleted_at IS NULL ORDER BY c.name, s.name");
while ($r = $res->fetch_assoc()) {
    $has = !empty($r['image']) && is_file(UPLOAD_DIR_SERVICES . $r['image']);
    $svc_rows[] = ['id' => (int)$r['id'], 'n' => $r['name'], 'cat' => $r['category_name'] ?: '', 'has' => $has,
                   'img' => $has ? '../uploads/services/' . rawurlencode($r['image']) : ''];
}
// How many records the "Restore old photos" button could fix right now.
$restorable = 0;
foreach ([["SELECT image FROM services WHERE image IS NOT NULL AND image <> ''", $BACKUP_SERVICES, UPLOAD_DIR_SERVICES],
          ["SELECT image FROM products WHERE image IS NOT NULL AND image <> ''", $BACKUP_PRODUCTS, UPLOAD_DIR_PRODUCTS]] as [$sql, $from, $to]) {
    $res = $conn->query($sql);
    while ($r = $res->fetch_assoc()) { $f = basename($r['image']); if (!is_file($to . $f) && is_file($from . $f)) $restorable++; }
}

$page_title = 'Bulk Service Photos'; $page_icon = ''; $active_page = 'services';
require_once 'admin_header.php';
?>
<style>
.bp-grid { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:16px; align-items:start; }
@media (max-width:900px) { .bp-grid { grid-template-columns:minmax(0,1fr); } }
.bp-step { display:inline-grid; place-items:center; width:24px; height:24px; border-radius:50%; background:#3B2A1A; color:#C9963A; font-weight:800; font-size:0.8rem; margin-right:8px; }
.bp-drop { border:2px dashed var(--border2,#D9C6A8); border-radius:14px; background:var(--bg3,#F3EBDF); padding:28px 16px; text-align:center; transition:background .15s, border-color .15s; }
.bp-drop.over { background:#F6EBD3; border-color:#C9963A; }
.bp-drop b { display:block; font-size:1.05rem; margin-bottom:4px; color:var(--brown); }
.bp-drop p { margin:0 0 12px; color:var(--gray); }
.bp-opts { display:flex; flex-wrap:wrap; gap:14px; margin-top:12px; font-size:0.85rem; color:var(--brown); }
.bp-opts label { display:inline-flex; gap:6px; align-items:center; margin:0; }
.bp-opts input { width:auto; margin:0; }
.bp-summary { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
.bp-pill { font-size:0.78rem; font-weight:800; border-radius:999px; padding:4px 10px; white-space:nowrap; }
.bp-pill.ok { background:#DDF1E6; color:#198754; }
.bp-pill.warn { background:#FBF0D9; color:#8A5C0A; }
.bp-pill.bad { background:#FBE3E0; color:#A3261B; }
.bp-rows { display:flex; flex-direction:column; gap:8px; }
.bp-row { display:grid; grid-template-columns:64px minmax(0,1fr) minmax(0,1.2fr) 130px 36px; gap:12px; align-items:center; border:1px solid var(--border,#E8DCCB); border-radius:12px; padding:8px 10px; background:#FCFAF6; }
.bp-row img { width:64px; height:64px; object-fit:cover; border-radius:10px; display:block; background:var(--bg3); }
.bp-fn { min-width:0; font-size:0.82rem; color:var(--brown); overflow-wrap:anywhere; }
.bp-fn small { display:block; color:var(--gray); }
.bp-row select { width:100%; min-height:38px; border:1px solid var(--border2); border-radius:10px; padding:0 8px; background:#fff; font-weight:600; margin:0; }
.bp-x { width:36px; height:36px; border-radius:10px; border:1px solid var(--border2); background:#fff; font-weight:800; cursor:pointer; }
.bp-row.saved { background:#DDF1E6; border-color:#A9D8BD; }
.bp-row.failed { background:#FBE3E0; border-color:#EBC1BB; }
@media (max-width:640px) {
  .bp-row { grid-template-columns:56px minmax(0,1fr) 36px; grid-template-areas:"img fn x" "img sel sel" "img st st"; }
  .bp-row img { grid-area:img; width:56px; height:56px; }
  .bp-fn { grid-area:fn; } .bp-row select { grid-area:sel; } .bp-st { grid-area:st; } .bp-x { grid-area:x; }
}
.bp-foot { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-top:14px; padding-top:12px; border-top:1px solid var(--border); }
.bp-foot small { color:var(--gray); }
.bp-prog { height:10px; border-radius:5px; background:var(--bg3); overflow:hidden; margin:6px 0 4px; }
.bp-prog i { display:block; height:100%; width:0; background:#198754; transition:width .3s; }
.bp-miss { display:flex; flex-direction:column; gap:4px; max-height:460px; overflow-y:auto; }
.bp-miss div { display:flex; justify-content:space-between; gap:8px; font-size:0.82rem; padding:6px 8px; border-radius:8px; background:var(--bg3); color:var(--brown); }
.bp-miss div span { color:var(--gray); white-space:nowrap; }
.bp-miss div.ready span { color:#198754; font-weight:700; }
.bp-toast { position:fixed; left:50%; bottom:calc(20px + env(safe-area-inset-bottom, 0px)); transform:translateX(-50%); background:#3B2A1A; color:#fff; padding:10px 16px; border-radius:12px; font-weight:700; z-index:9000; max-width:calc(100% - 32px); }
.bp-page [hidden], .bp-toast[hidden] { display:none !important; }
</style>

<div class="bp-page">
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
    <div><h2 style="margin:0;font-size:1.35rem;">Bulk service photos</h2>
        <p style="margin:2px 0 0;color:var(--gray);font-size:0.85rem;">Drop all your photos at once. Each one is matched to a service by its file name. Check the matches, then save.</p></div>
    <a href="services.php" class="btn btn-secondary btn-sm">← Back to Services</a>
</div>

<div class="bp-grid">
    <div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title"><span class="bp-step">1</span>Add photos</span><small style="color:var(--gray);font-size:0.75rem;">JPG, PNG or WebP · many at once</small></div>
            <div class="panel-body" style="padding:1rem;">
                <div class="bp-drop" id="bpDrop">
                    <b>Drag photos here</b>
                    <p>or pick them from your computer or phone</p>
                    <label class="btn btn-primary" style="cursor:pointer;">Choose photos<input type="file" id="bpFiles" accept="image/*" multiple hidden></label>
                </div>
                <div class="bp-opts">
                    <label><input type="checkbox" id="bpReplace"> Allow replacing a photo a service already has</label>
                </div>
            </div>
        </div>

        <div class="panel" id="bpReview" style="margin-top:16px;" hidden>
            <div class="panel-header"><span class="panel-title"><span class="bp-step">2</span>Check the matches</span><small style="color:var(--gray);font-size:0.75rem;">Change any wrong one with the menu</small></div>
            <div class="panel-body" style="padding:1rem;">
                <div class="bp-summary" id="bpSummary"></div>
                <div class="bp-rows" id="bpRows"></div>
                <div class="bp-foot">
                    <small id="bpNote"></small>
                    <button type="button" class="btn btn-primary" id="bpSave">Save photos</button>
                </div>
                <div id="bpProgWrap" hidden><div class="bp-prog"><i id="bpProg"></i></div><small id="bpProgText" style="color:var(--gray);"></small></div>
            </div>
        </div>
    </div>

    <aside>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Services without a photo</span></div>
            <div class="panel-body" style="padding:1rem;">
                <p id="bpMissText" style="margin:0;color:var(--gray);font-size:0.82rem;"></p>
                <div class="bp-prog"><i id="bpMissBar"></i></div>
                <div class="bp-miss" id="bpMiss"></div>
            </div>
        </div>
        <div class="panel" style="margin-top:16px;">
            <div class="panel-header"><span class="panel-title">Restore the old photos</span></div>
            <div class="panel-body" style="padding:1rem;">
                <p style="margin:0 0 10px;color:var(--gray);font-size:0.82rem;line-height:1.5;">Puts back the photos that were in the system before (massage, head spa, nails, lashes, brows, foot, waxing, scrubs and the shop products) on the same services and products they belonged to. It never replaces a newer photo.</p>
                <button type="button" class="btn btn-secondary" id="bpRestore"<?php echo $restorable ? '' : ' disabled'; ?>><?php echo $restorable ? 'Restore ' . $restorable . ' old photo' . ($restorable > 1 ? 's' : '') : 'Nothing to restore'; ?></button>
            </div>
        </div>
    </aside>
</div>
<form id="bpCsrf" hidden><?php echo csrf_field(); ?></form>
</div>
<div class="bp-toast" id="bpToast" role="status" hidden></div>

<script>
(function () {
    var SVC = <?php echo json_encode($svc_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var byId = {}; SVC.forEach(function (s) { byId[s.id] = s; });
    var csrf = document.querySelector('#bpCsrf input[name="csrf_token"]').value;
    var items = [], busy = false;
    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var STOP = ['service', 'services', 'servie', 'img', 'image', 'photo', 'copy', 'final', 'jpg', 'jpeg', 'png', 'webp', 'and', 'the', 'with'];
    function words(s) {
        return s.toLowerCase().replace(/\.[a-z0-9]+$/, '').replace(/^(service|product)_\d+_/, '').replace(/[^a-z0-9]+/g, ' ').trim().split(' ')
            .filter(function (w) { return w && !/^\d+$/.test(w) && STOP.indexOf(w) < 0; });
    }
    function lev(a, b) { var m = [], i, j; for (i = 0; i <= a.length; i++) m[i] = [i]; for (j = 0; j <= b.length; j++) m[0][j] = j;
        for (i = 1; i <= a.length; i++) for (j = 1; j <= b.length; j++) m[i][j] = Math.min(m[i-1][j] + 1, m[i][j-1] + 1, m[i-1][j-1] + (a[i-1] === b[j-1] ? 0 : 1)); return m[a.length][b.length]; }
    function same(f, w) { return f === w || (w.length > 3 && f.length > 3 && (f.indexOf(w) === 0 || w.indexOf(f) === 0 || lev(f, w) <= 1)); }
    // Share of the service name's words found in the file name, nudged by category words.
    function score(file, s) {
        var fw = words(file), sw = words(s.n), cw = words(s.cat || '');
        if (!sw.length) return 0;
        var hit = sw.filter(function (w) { return fw.some(function (f) { return same(f, w); }); }).length;
        var sc = hit / sw.length;
        var extra = fw.filter(function (f) { return !sw.some(function (w) { return same(f, w); }) && !cw.some(function (w) { return same(f, w); }); }).length;
        return sc - extra * 0.04 + (cw.some(function (w) { return fw.some(function (f) { return same(f, w); }); }) ? 0.02 : 0);
    }
    function allowed(s) { return !s.has || $('bpReplace').checked; }
    function bestFor(name) {
        var top = 0, ts = 0;
        SVC.forEach(function (s) { if (!allowed(s)) return; var sc = score(name, s); if (sc > ts) { ts = sc; top = s.id; } });
        return ts >= 0.5 ? { id: top, sc: ts } : { id: 0, sc: ts };
    }
    function status(it) {
        if (it.done) return ['ok', 'Saved'];
        if (it.err) return ['bad', it.err];
        if (!it.svc) return ['bad', 'No match'];
        if (items.filter(function (x) { return x.svc === it.svc && !x.done; }).length > 1) return ['warn', 'Same service twice'];
        if (byId[it.svc] && byId[it.svc].has) return ['warn', 'Replaces photo'];
        return it.sc >= 0.95 ? ['ok', 'Matched'] : ['warn', 'Check this'];
    }
    function render() {
        $('bpReview').hidden = !items.length;
        var opts = '<option value="0">— Skip this photo —</option>' + SVC.map(function (s) {
            return '<option value="' + s.id + '">' + esc(s.n) + (s.cat ? ' · ' + esc(s.cat) : '') + (s.has ? ' (has photo)' : '') + '</option>';
        }).join('');
        $('bpRows').innerHTML = items.map(function (it, i) {
            var st = status(it);
            return '<div class="bp-row' + (it.done ? ' saved' : it.err ? ' failed' : '') + '"><img src="' + it.url + '" alt="">' +
                '<div class="bp-fn">' + esc(it.file.name) + '<small>' + Math.round(it.file.size / 1024) + ' KB</small></div>' +
                '<select data-i="' + i + '" aria-label="Service for ' + esc(it.file.name) + '"' + (it.done || busy ? ' disabled' : '') + '>' + opts.replace('value="' + it.svc + '"', 'value="' + it.svc + '" selected') + '</select>' +
                '<span class="bp-st"><span class="bp-pill ' + st[0] + '">' + esc(st[1]) + '</span></span>' +
                '<button type="button" class="bp-x" data-rm="' + i + '" aria-label="Remove ' + esc(it.file.name) + '"' + (it.done || busy ? ' disabled' : '') + '>✕</button></div>';
        }).join('');
        var c = { ok: 0, warn: 0, bad: 0 }; items.forEach(function (it) { if (!it.done) c[status(it)[0]]++; });
        var saved = items.filter(function (it) { return it.done; }).length;
        $('bpSummary').innerHTML = (saved ? '<span class="bp-pill ok">' + saved + ' saved</span>' : '') +
            '<span class="bp-pill ok">' + c.ok + ' matched</span><span class="bp-pill warn">' + c.warn + ' to check</span><span class="bp-pill bad">' + c.bad + ' no match</span>';
        var ready = items.filter(function (it) { return !it.done && it.svc; }).length;
        $('bpSave').disabled = !ready || busy;
        $('bpSave').textContent = busy ? 'Saving…' : 'Save ' + ready + ' photo' + (ready === 1 ? '' : 's');
        $('bpNote').textContent = c.bad ? 'Photos with "No match" are skipped unless you pick a service.' : 'Each photo goes onto its service, the same as uploading it in Edit Service.';
        var miss = SVC.filter(function (s) { return !s.has; });
        $('bpMissText').textContent = miss.length ? miss.length + ' of ' + SVC.length + ' services still need a photo.' : 'Every service has a photo.';
        $('bpMissBar').style.width = (SVC.length ? Math.round((SVC.length - miss.length) / SVC.length * 100) : 100) + '%';
        $('bpMiss').innerHTML = miss.map(function (s) {
            var ready = items.some(function (it) { return it.svc === s.id && !it.done; });
            return '<div class="' + (ready ? 'ready' : '') + '"><b style="font-weight:600">' + esc(s.n) + '</b><span>' + (ready ? 'photo ready' : esc(s.cat || 'no photo')) + '</span></div>';
        }).join('');
    }
    var tt; function toast(m) { $('bpToast').textContent = m; $('bpToast').hidden = false; clearTimeout(tt); tt = setTimeout(function () { $('bpToast').hidden = true; }, 3200); }

    function addFiles(list) {
        [].slice.call(list).forEach(function (f) {
            if (!/^image\//.test(f.type)) return;
            var m = bestFor(f.name);
            items.push({ file: f, url: URL.createObjectURL(f), svc: m.id, sc: m.sc, done: false, err: '' });
        });
        render();
    }
    $('bpFiles').addEventListener('change', function () { addFiles(this.files); this.value = ''; });
    var dz = $('bpDrop');
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('over'); }); });
    dz.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
    $('bpReplace').addEventListener('change', function () { items.forEach(function (it) { if (!it.done && (!it.svc || it.sc < 0.95)) { var m = bestFor(it.file.name); it.svc = m.id; it.sc = m.sc; } }); render(); });
    $('bpRows').addEventListener('change', function (e) { var s = e.target.closest('select'); if (!s) return; var it = items[+s.dataset.i]; it.svc = +s.value; it.sc = 1; it.err = ''; render(); });
    $('bpRows').addEventListener('click', function (e) { var b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });

    // Big phone photos are shrunk to 1600px JPEG before upload: faster, and well
    // above what the site ever shows. Small files are sent as they are.
    function shrink(file) {
        return new Promise(function (resolve) {
            if (file.size < 900 * 1024 || !/jpe?g|png|webp/i.test(file.type)) return resolve(file);
            var img = new Image();
            img.onload = function () {
                var max = 1600, w = img.naturalWidth, h = img.naturalHeight, r = Math.min(1, max / Math.max(w, h));
                var cv = document.createElement('canvas'); cv.width = Math.round(w * r); cv.height = Math.round(h * r);
                var g = cv.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height); g.drawImage(img, 0, 0, cv.width, cv.height);
                cv.toBlob(function (b) { resolve(b && b.size < file.size ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { resolve(file); };
            img.src = URL.createObjectURL(file);
        });
    }
    $('bpSave').addEventListener('click', function () {
        var queue = items.filter(function (it) { return !it.done && it.svc; });
        if (!queue.length) return;
        busy = true; render(); $('bpProgWrap').hidden = false;
        var n = 0, ok = 0;
        (function next() {
            if (n >= queue.length) {
                busy = false; render();
                $('bpProgText').textContent = ok + ' of ' + queue.length + ' saved.';
                toast(ok + ' photo' + (ok === 1 ? '' : 's') + ' saved' + (ok < queue.length ? ' · ' + (queue.length - ok) + ' need another try' : '') + '.');
                return;
            }
            var it = queue[n++];
            $('bpProg').style.width = Math.round((n - 1) / queue.length * 100) + '%';
            $('bpProgText').textContent = 'Saving ' + n + ' of ' + queue.length + '…';
            shrink(it.file).then(function (f) {
                var fd = new FormData();
                fd.append('action', 'upload_one'); fd.append('csrf_token', csrf); fd.append('service_id', it.svc);
                if ($('bpReplace').checked) fd.append('replace', '1');
                fd.append('photo', f, f.name);
                return fetch('service_photos.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
            }).then(function (res) {
                if (res && res.ok) { it.done = true; it.err = ''; ok++; var s = byId[it.svc]; if (s) { s.has = true; s.img = res.url; } }
                else it.err = (res && res.msg) || 'Not saved';
            }).catch(function () { it.err = 'No connection'; })
              .then(function () { render(); $('bpProg').style.width = Math.round(n / queue.length * 100) + '%'; next(); });
        })();
    });

    var rb = $('bpRestore');
    rb.addEventListener('click', function () {
        if (rb.disabled) return;
        rb.disabled = true; rb.textContent = 'Restoring…';
        var fd = new FormData(); fd.append('action', 'restore_old'); fd.append('csrf_token', csrf);
        fetch('service_photos.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { rb.disabled = false; rb.textContent = 'Try again'; toast(res.msg || 'Restore failed.'); return; }
                rb.textContent = 'Restored';
                toast(res.services + ' service photo' + (res.services === 1 ? '' : 's') + ' and ' + res.products + ' product photo' + (res.products === 1 ? '' : 's') + ' put back. Refreshing…');
                setTimeout(function () { location.reload(); }, 1600);
            })
            .catch(function () { rb.disabled = false; rb.textContent = 'Try again'; toast('No connection. Nothing was restored.'); });
    });
    render();
})();
</script>

<?php require_once 'admin_footer.php'; ?>
