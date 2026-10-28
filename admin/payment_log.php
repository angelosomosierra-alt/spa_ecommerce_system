<?php
/**
 * payment_log.php — PayMongo setup check + step-by-step payment trail.
 * Read-only. Rows are written by paymongo_log() (config.php) from checkout,
 * the success/cancel pages and the webhook.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
require_once __DIR__ . '/../notify.php';
enforce_page_access();

// ─── GCASH QR (pay by scanning the spa's QR; checked at the desk) ────────────
$gc_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gcash') {
    verify_csrf_token();
    $by = (int)($_SESSION['user_id'] ?? 0);
    $gc_err = '';
    if (($_FILES['gcash_qr']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $qr = store_payment_image($_FILES['gcash_qr'], 'qr');
        if ($qr[0] === '!') $gc_err = substr($qr, 1);
        else save_gcash_setting($conn, 'gcash_qr_file', $qr, $by);
    }
    if ($gc_err === '') {
        save_gcash_setting($conn, 'gcash_name', mb_substr(trim((string)($_POST['gcash_name'] ?? '')), 0, 100), $by);
        save_gcash_setting($conn, 'gcash_number', preg_replace('/[^\d+ ]/', '', (string)($_POST['gcash_number'] ?? '')), $by);
        save_gcash_setting($conn, 'gcash_qr_enabled', !empty($_POST['gcash_qr_enabled']) ? '1' : '0', $by);
        log_activity($conn, 'payment_settings', 'Updated the GCash QR payment settings');
    }
    $_SESSION['gc_msg'] = $gc_err !== '' ? '!' . str_replace('receipt', 'QR', $gc_err) : 'GCash settings saved.';
    header('Location: payment_log.php'); exit;
}
if (!empty($_SESSION['gc_msg'])) { $gc_msg = $_SESSION['gc_msg']; unset($_SESSION['gc_msg']); }
$gc = gcash_settings($conn);

// ─── SETUP CHECK ─────────────────────────────────────────────────────────────
$pm_mode = PAYMONGO_SECRET_KEY === '' ? 'none'
         : (str_starts_with(PAYMONGO_SECRET_KEY, 'sk_live_') ? 'live'
         : (str_starts_with(PAYMONGO_SECRET_KEY, 'sk_test_') ? 'test' : 'unknown'));
$pub_ok  = PAYMONGO_PUBLIC_KEY !== '' && (
              ($pm_mode === 'live' && str_starts_with(PAYMONGO_PUBLIC_KEY, 'pk_live_')) ||
              ($pm_mode === 'test' && str_starts_with(PAYMONGO_PUBLIC_KEY, 'pk_test_')));
$webhook_url = BASE_URL . 'user/paymongo_webhook.php';

$last_hook = $conn->query("SELECT result, step, detail, created_at FROM payment_logs
                           WHERE source = 'webhook' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$last_good = $conn->query("SELECT created_at FROM payment_logs
                           WHERE source = 'webhook' AND result = 'ok' ORDER BY id DESC LIMIT 1")->fetch_assoc();

// Does orders.payment_status accept the values online payment writes?
$enum_ok = true; $enum_missing = [];
$col = $conn->query("SHOW COLUMNS FROM orders LIKE 'payment_status'")->fetch_assoc();
if ($col && stripos($col['Type'], 'enum(') === 0) {
    foreach (['pending_payment', 'cancelled'] as $v) {
        if (stripos($col['Type'], "'" . $v . "'") === false) { $enum_ok = false; $enum_missing[] = $v; }
    }
}

$checks = [
    ['PayMongo secret key',  $pm_mode === 'live' || $pm_mode === 'test',
        $pm_mode === 'live' ? 'Live key — real money goes to the spa\'s PayMongo account.'
        : ($pm_mode === 'test' ? 'Test key — safe for trial payments, no real money.'
        : ($pm_mode === 'none' ? 'Not set. Add $_pm_secret in config.local.php.' : 'Doesn\'t look like a PayMongo key (should start with sk_test_ or sk_live_).'))],
    ['PayMongo public key',  $pub_ok,
        $pub_ok ? 'Set, and matches the secret key.' : 'Missing, or test/live doesn\'t match the secret key. Add $_pm_public in config.local.php.'],
    ['Webhook secret',       PAYMONGO_WEBHOOK_SECRET !== '',
        PAYMONGO_WEBHOOK_SECRET !== '' ? 'Set.' : 'Not set. Add $_pm_webhook (from PayMongo → Developers → Webhooks) in config.local.php.'],
    ['Webhook reaching us',  (bool)$last_good,
        $last_good ? 'Last confirmed payment from PayMongo: ' . date('M j, Y g:i A', strtotime($last_good['created_at'])) . '.'
        : ($last_hook ? 'PayMongo calls arrive but none passed yet — see the latest red row below.' : 'No call from PayMongo yet. Make one test payment after registering the webhook.')],
    ['Order status values',  $enum_ok,
        $enum_ok ? 'Orders can be saved as "pending_payment" and "cancelled".'
        : 'The orders table is missing: ' . implode(', ', $enum_missing) . '. Online checkout will fail until it is added (see the note under this list).'],
    ['HTTPS',                str_starts_with(BASE_URL, 'https://'),
        str_starts_with(BASE_URL, 'https://') ? 'Site runs on HTTPS.' : 'PayMongo needs an https:// webhook URL. Turn on SSL in Hostinger.'],
];

// ─── FILTERS ─────────────────────────────────────────────────────────────────
$f_result = in_array($_GET['result'] ?? '', ['ok', 'info', 'error'], true) ? $_GET['result'] : '';
$f_order  = intval($_GET['order'] ?? 0);
$page_n   = max(1, intval($_GET['p'] ?? 1));
$per_page = 50;

$where = []; $types = ''; $params = [];
if ($f_result !== '') { $where[] = 'result = ?';   $types .= 's'; $params[] = $f_result; }
if ($f_order > 0)     { $where[] = 'order_id = ?'; $types .= 'i'; $params[] = $f_order; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $conn->prepare("SELECT COUNT(*) c FROM payment_logs $where_sql");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$offset = ($page_n - 1) * $per_page;
$stmt = $conn->prepare("SELECT * FROM payment_logs $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$pages = max(1, (int)ceil($total / $per_page));

$counts = $conn->query("SELECT result, COUNT(*) c FROM payment_logs
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY result")->fetch_all(MYSQLI_ASSOC);
$c7 = ['ok' => 0, 'info' => 0, 'error' => 0];
foreach ($counts as $c) $c7[$c['result']] = (int)$c['c'];

$step_labels = [
    'session_created' => 'Sent to PayMongo', 'session_create' => 'Could not start payment',
    'return' => 'Came back', 'confirmed' => 'Confirmed on return', 'cancelled' => 'Cancelled',
    'paid' => 'PayMongo confirmed', 'rejected' => 'Rejected call', 'ignored' => 'Ignored event',
];
$source_labels = ['checkout' => 'Customer checkout', 'customer' => 'Customer', 'walkin' => 'Walk-in desk', 'webhook' => 'PayMongo webhook'];

function pl_qs(array $over): string {
    $q = array_merge(['result' => $_GET['result'] ?? '', 'order' => $_GET['order'] ?? ''], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0));
}

$page_title  = 'Payment Log';
$page_icon   = '';
$active_page = 'payment_log';
require_once __DIR__ . '/admin_header.php';
?>
<style>
.pl-wrap { max-width:1000px; margin:0 auto; }
.pl-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
.pl-head h2 { font-size:1.15rem; margin:0; color:var(--brown, #2B1D12); }
.pl-head p { margin:2px 0 0; color:var(--gray, #8A7560); font-size:.82rem; }
.pl-state { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; font-weight:700; font-size:.8rem; }
.pl-state.off { background:#EEEAE4; color:#5E4733; }
.pl-state.on  { background:#DDF3E4; color:#14532D; }
.pl-card { background:#fff; border:1px solid var(--border, #EADFCF); border-radius:14px; padding:1rem 1.1rem; margin-bottom:1rem; }
.pl-card h3 { font-size:.95rem; margin:0 0 .6rem; color:var(--brown, #2B1D12); }
.pl-check { display:grid; grid-template-columns:26px minmax(0,1fr); gap:.2rem .6rem; padding:.55rem 0; border-top:1px solid #F3EBDF; }
.pl-check:first-of-type { border-top:0; }
.pl-dot { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:800; color:#fff; }
.pl-dot.y { background:#1F8A4C; } .pl-dot.n { background:#C2410C; }
.pl-check b { font-size:.86rem; color:var(--brown, #2B1D12); }
.pl-check span { display:block; font-size:.8rem; color:#5E4733; }
.pl-url { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-top:.6rem; font-size:.8rem; }
.pl-url code { background:#F7F1E8; border:1px solid #EADFCF; padding:6px 10px; border-radius:8px; word-break:break-all; }
.pl-url button { border:1px solid #D9C6A8; background:#fff; border-radius:8px; padding:6px 12px; font-weight:700; cursor:pointer; }
.pl-note { font-size:.78rem; color:#5E4733; background:#FFF7E6; border:1px solid #F3DDB0; border-radius:10px; padding:.6rem .8rem; margin-top:.6rem; }
.pl-note code { font-size:.75rem; }
.pl-tiles { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.6rem; margin-bottom:.8rem; }
.pl-tile { background:#fff; border:1px solid var(--border, #EADFCF); border-radius:12px; padding:.7rem .9rem; text-decoration:none; color:inherit; }
.pl-tile b { display:block; font-size:1.35rem; }
.pl-tile small { color:var(--gray, #8A7560); font-size:.75rem; }
.pl-tile.sel { outline:2px solid var(--brown, #2B1D12); }
.pl-tile.err b { color:#A3261B; } .pl-tile.ok b { color:#1F8A4C; }
.pl-filter { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.8rem; }
.pl-filter input { padding:.5rem .7rem; border:1px solid #D9C6A8; border-radius:10px; width:150px; font:inherit; }
.pl-filter button, .pl-filter a { padding:.5rem .9rem; border-radius:10px; border:1px solid #D9C6A8; background:#fff; font-weight:700; font-size:.82rem; text-decoration:none; color:#2B1D12; cursor:pointer; }
.pl-row { display:grid; grid-template-columns:10px 130px minmax(0,1fr) 120px; gap:.8rem; align-items:start; background:#fff; border:1px solid var(--border, #EADFCF); border-radius:12px; padding:.7rem .9rem; margin-bottom:.4rem; }
.pl-bar { width:6px; align-self:stretch; border-radius:3px; }
.pl-bar.ok { background:#1F8A4C; } .pl-bar.info { background:#C9963A; } .pl-bar.error { background:#C0261B; }
.pl-when { font-size:.78rem; color:#5E4733; }
.pl-when small { display:block; color:var(--gray, #8A7560); }
.pl-what b { font-size:.86rem; color:var(--brown, #2B1D12); }
.pl-what .src { font-size:.72rem; color:var(--gray, #8A7560); margin-left:.35rem; }
.pl-what p { margin:.2rem 0 0; font-size:.8rem; color:#374151; line-height:1.4; word-break:break-word; }
.pl-what .ids { margin-top:.25rem; font-size:.72rem; color:var(--gray, #8A7560); word-break:break-all; }
.pl-tag { display:inline-block; font-size:.68rem; font-weight:800; padding:1px 7px; border-radius:999px; margin-left:.3rem; }
.pl-tag.test { background:#EEEAE4; color:#5E4733; } .pl-tag.live { background:#DDF3E4; color:#14532D; }
.pl-amt { text-align:right; font-size:.86rem; font-weight:800; font-variant-numeric:tabular-nums; }
.pl-amt a { display:block; font-weight:600; font-size:.75rem; color:var(--rust, #C2410C); margin-top:2px; }
.pl-amt small { display:block; font-weight:600; font-size:.72rem; color:var(--gray, #8A7560); text-transform:uppercase; }
.pl-empty { text-align:center; padding:2.5rem 1rem; color:var(--gray, #8A7560); background:#fff; border:1px dashed #D9C6A8; border-radius:12px; }
.pl-pages { display:flex; gap:.4rem; justify-content:center; margin-top:1rem; }
.pl-pages a, .pl-pages span { padding:.4rem .75rem; border-radius:8px; border:1px solid #D9C6A8; text-decoration:none; color:#2B1D12; font-size:.82rem; font-weight:700; }
.pl-pages span { background:#2B1D12; color:#fff; border-color:#2B1D12; }
@media (max-width:640px) {
  .pl-row { grid-template-columns:6px minmax(0,1fr) auto; grid-template-areas:"bar what amt" "bar when when"; gap:.3rem .7rem; }
  .pl-bar { grid-area:bar; } .pl-what { grid-area:what; } .pl-amt { grid-area:amt; } .pl-when { grid-area:when; }
  .pl-when small { display:inline; margin-left:.4rem; }
  .pl-tiles { gap:.4rem; }
}
</style>

<div class="pl-wrap">
    <div class="pl-head">
        <div>
            <h2>Payment Log</h2>
            <p>Every step of an online (PayMongo) payment, newest first.</p>
        </div>
        <?php if (ONLINE_PAYMENT_ENABLED): ?>
            <span class="pl-state on">● Online payment is ON</span>
        <?php else: ?>
            <span class="pl-state off">○ Online payment is OFF — customers only see Pay at Spa</span>
        <?php endif; ?>
    </div>

    <form class="pl-card" method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_gcash">
        <h3>GCash QR payment (online bookings)</h3>
        <?php if ($gc_msg !== ''): ?><div class="pl-note" style="margin:0 0 .6rem;<?php echo $gc_msg[0] === '!' ? 'background:#FDECEA;border-color:#F5C2BD;' : 'background:#DDF3E4;border-color:#B7E3C5;'; ?>"><?php echo htmlspecialchars(ltrim($gc_msg, '!')); ?></div><?php endif; ?>
        <div class="pl-check">
            <div class="pl-dot <?php echo gcash_qr_ready($conn) ? 'y' : 'n'; ?>"><?php echo gcash_qr_ready($conn) ? '✓' : '!'; ?></div>
            <div><b><?php echo gcash_qr_ready($conn) ? 'Customers can pay by GCash QR' : 'Not shown to customers yet'; ?></b>
                <span>Customers scan this QR at checkout, pay the 50% down payment or the full amount, then send the reference number and a screenshot. The receptionist verifies it in Appointments.<?php echo ONLINE_PAYMENT_ENABLED ? ' (Hidden while PayMongo online payment is ON.)' : ''; ?></span></div>
        </div>
        <div class="pl-filter" style="margin:.6rem 0 0;align-items:center;">
            <input type="text" name="gcash_name" maxlength="100" placeholder="Account name" value="<?php echo htmlspecialchars($gc['gcash_name']); ?>" style="width:220px;">
            <input type="text" name="gcash_number" maxlength="20" placeholder="GCash number" value="<?php echo htmlspecialchars($gc['gcash_number']); ?>" style="width:170px;">
            <input type="file" name="gcash_qr" accept="image/jpeg,image/png,image/webp" style="width:auto;">
            <label style="font-size:.82rem;font-weight:700;display:flex;gap:.35rem;align-items:center;"><input type="checkbox" name="gcash_qr_enabled" value="1" style="width:auto;" <?php echo $gc['gcash_qr_enabled'] === '1' ? 'checked' : ''; ?>> Turn on</label>
            <button type="submit">Save</button>
        </div>
        <?php if ($gc['gcash_qr_file'] !== ''): ?>
        <div class="pl-url"><img src="<?php echo BASE_URL; ?>payment_qr.php?v=<?php echo urlencode($gc['gcash_qr_file']); ?>" alt="GCash QR" style="width:120px;height:120px;object-fit:contain;border:1px solid #EADFCF;border-radius:8px;background:#fff;"> Current QR. Upload a new image to replace it.</div>
        <?php endif; ?>
    </form>

    <div class="pl-card">
        <h3>PayMongo setup check</h3>
        <?php foreach ($checks as [$label, $ok, $msg]): ?>
        <div class="pl-check">
            <div class="pl-dot <?php echo $ok ? 'y' : 'n'; ?>"><?php echo $ok ? '✓' : '!'; ?></div>
            <div><b><?php echo htmlspecialchars($label); ?></b><span><?php echo htmlspecialchars($msg); ?></span></div>
        </div>
        <?php endforeach; ?>
        <div class="pl-url">
            Webhook URL to register in PayMongo (event: <b>checkout_session.payment.paid</b>):
            <code id="plHook"><?php echo htmlspecialchars($webhook_url); ?></code>
            <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('plHook').textContent).then(()=>{this.textContent='Copied'})">Copy</button>
        </div>
        <?php if (!$enum_ok): ?>
        <div class="pl-note">To add the missing order status values, run this once in phpMyAdmin (keep any other values your table already lists):<br>
            <code>ALTER TABLE orders MODIFY payment_status ENUM(<?php
                preg_match_all("/'([^']*)'/", $col['Type'], $m);
                echo htmlspecialchars(implode(',', array_map(fn($v) => "'" . $v . "'", array_unique(array_merge($m[1], $enum_missing)))));
            ?>) DEFAULT 'unpaid';</code></div>
        <?php endif; ?>
    </div>

    <div class="pl-tiles">
        <a class="pl-tile ok <?php echo $f_result === 'ok' ? 'sel' : ''; ?>" href="<?php echo pl_qs(['result' => $f_result === 'ok' ? '' : 'ok', 'p' => '']); ?>"><b><?php echo $c7['ok']; ?></b><small>Went through (7 days)</small></a>
        <a class="pl-tile <?php echo $f_result === 'info' ? 'sel' : ''; ?>" href="<?php echo pl_qs(['result' => $f_result === 'info' ? '' : 'info', 'p' => '']); ?>"><b><?php echo $c7['info']; ?></b><small>Notes / cancelled (7 days)</small></a>
        <a class="pl-tile err <?php echo $f_result === 'error' ? 'sel' : ''; ?>" href="<?php echo pl_qs(['result' => $f_result === 'error' ? '' : 'error', 'p' => '']); ?>"><b><?php echo $c7['error']; ?></b><small>Problems (7 days)</small></a>
    </div>

    <form class="pl-filter" method="GET">
        <?php if ($f_result !== ''): ?><input type="hidden" name="result" value="<?php echo htmlspecialchars($f_result); ?>"><?php endif; ?>
        <input type="number" name="order" min="1" placeholder="Order #" value="<?php echo $f_order ?: ''; ?>">
        <button type="submit">Find order</button>
        <?php if ($f_order || $f_result): ?><a href="payment_log.php">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="pl-empty">No payment activity yet<?php echo ($f_order || $f_result) ? ' for this filter' : ''; ?>.<br>
            Rows appear here as soon as someone pays online (test or live).</div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
    <div class="pl-row">
        <div class="pl-bar <?php echo htmlspecialchars($r['result']); ?>"></div>
        <div class="pl-when"><?php echo date('M j, Y', strtotime($r['created_at'])); ?><small><?php echo date('g:i:s A', strtotime($r['created_at'])); ?></small></div>
        <div class="pl-what">
            <b><?php echo htmlspecialchars($step_labels[$r['step']] ?? $r['step']); ?></b>
            <span class="src"><?php echo htmlspecialchars($source_labels[$r['source']] ?? $r['source']); ?></span>
            <?php if ($r['livemode'] !== null): ?><span class="pl-tag <?php echo $r['livemode'] ? 'live' : 'test'; ?>"><?php echo $r['livemode'] ? 'LIVE' : 'TEST'; ?></span><?php endif; ?>
            <?php if ($r['detail'] !== null && $r['detail'] !== ''): ?><p><?php echo htmlspecialchars($r['detail']); ?></p><?php endif; ?>
            <?php if ($r['reference'] || $r['session_id']): ?>
            <div class="ids"><?php
                $ids = [];
                if ($r['reference'])  $ids[] = 'Ref ' . htmlspecialchars($r['reference']);
                if ($r['session_id']) $ids[] = htmlspecialchars($r['session_id']);
                echo implode(' · ', $ids);
            ?></div>
            <?php endif; ?>
        </div>
        <div class="pl-amt">
            <?php if ($r['amount'] !== null): ?>₱<?php echo number_format($r['amount'], 2); ?><?php endif; ?>
            <?php if ($r['method']): ?><small><?php echo htmlspecialchars($r['method']); ?></small><?php endif; ?>
            <?php if ($r['order_id']): ?><a href="<?php echo pl_qs(['order' => $r['order_id'], 'p' => '']); ?>">Order #<?php echo (int)$r['order_id']; ?></a><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
    <div class="pl-pages">
        <?php for ($i = max(1, $page_n - 3); $i <= min($pages, $page_n + 3); $i++): ?>
            <?php if ($i === $page_n): ?><span><?php echo $i; ?></span>
            <?php else: ?><a href="<?php echo pl_qs(['p' => $i]); ?>"><?php echo $i; ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
