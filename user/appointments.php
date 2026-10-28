<?php
require_once '../config.php';
redirect_if_not_user();

// ── AJAX: must be BEFORE any HTML output (kept for anything still polling it)
if (isset($_GET['ajax_check_status'])) {
    header('Content-Type: application/json');
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT id, approval_status, payment_status, payment_method
        FROM orders
        WHERE user_id = ?
          AND payment_status != 'pending_payment'
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['orders' => $orders]);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: CUSTOMER CANCEL ORDER (product order still waiting for approval)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    verify_csrf_token();
    $order_id      = intval($_POST['order_id'] ?? 0);
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    $chk = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? AND approval_status = 'pending'");
    $chk->bind_param("ii", $order_id, $user_id);
    $chk->execute();
    $ord = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($ord) {
        $has_reason_col = $conn->query("SHOW COLUMNS FROM orders LIKE 'cancel_reason'")->num_rows > 0;
        if ($has_reason_col && $cancel_reason !== '') {
            $upd = $conn->prepare("UPDATE orders SET approval_status = 'cancelled', cancel_reason = ? WHERE id = ?");
            $upd->bind_param("si", $cancel_reason, $order_id);
        } else {
            $upd = $conn->prepare("UPDATE orders SET approval_status = 'cancelled' WHERE id = ?");
            $upd->bind_param("i", $order_id);
        }
        $upd->execute(); $upd->close();
        // Restock products — undo the stock deduction made at checkout/payment
        $restock = $conn->prepare("
            UPDATE products p
            JOIN order_items oi ON oi.product_id = p.id
            SET p.stock = p.stock + oi.quantity
            WHERE oi.order_id = ? AND oi.product_id IS NOT NULL
        ");
        $restock->bind_param("i", $order_id);
        $restock->execute(); $restock->close();
        $_SESSION['flash_success'] = "Order cancelled.";
    } else {
        $_SESSION['flash_error'] = "This order can no longer be cancelled. Please message us.";
    }
    header("Location: appointments.php#orders");
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: CUSTOMER CANCEL APPOINTMENT — only while it is still waiting for
// confirmation, not paid, and in the future. Anything else goes through
// staff (Message us), so paid bookings and refunds stay with the desk.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_appointment') {
    verify_csrf_token();
    $appt_id = intval($_POST['appt_id'] ?? 0);
    $reason  = trim(mb_substr((string)($_POST['cancel_reason'] ?? ''), 0, 200));

    $q = $conn->prepare("
        SELECT a.id, a.status, a.appointment_date, a.order_item_id, s.name AS service_name,
               o.id AS order_id, o.payment_status
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN order_items oi ON oi.id = a.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        WHERE a.id = ? AND a.user_id = ?
    ");
    $q->bind_param("ii", $appt_id, $user_id); $q->execute();
    $row = $q->get_result()->fetch_assoc(); $q->close();

    $can = $row && $row['status'] === 'pending'
        && ($row['payment_status'] ?? '') !== 'paid'
        && strtotime($row['appointment_date']) > time();

    if ($can) {
        $wn = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $wn->bind_param("i", $user_id); $wn->execute();
        $who_name = ($wn->get_result()->fetch_assoc()['full_name'] ?? '') ?: 'Customer'; $wn->close();
        $full_reason = 'Cancelled by customer' . ($reason !== '' ? ': ' . $reason : '');
        $upd = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_by_name = ? WHERE id = ? AND status = 'pending'");
        $upd->bind_param("sisi", $full_reason, $user_id, $who_name, $appt_id);
        $upd->execute(); $upd->close();

        // The booking's own order goes too when this appointment was its only item.
        if (!empty($row['order_id'])) {
            $c = $conn->prepare("SELECT COUNT(*) AS n FROM order_items WHERE order_id = ?");
            $c->bind_param("i", $row['order_id']); $c->execute();
            $n = (int)$c->get_result()->fetch_assoc()['n']; $c->close();
            if ($n === 1) {
                $oc = $conn->prepare("UPDATE orders SET approval_status = 'cancelled' WHERE id = ? AND user_id = ? AND payment_status <> 'paid'");
                $oc->bind_param("ii", $row['order_id'], $user_id); $oc->execute(); $oc->close();
            }
        }
        require_once __DIR__ . '/../notify.php';
        if (function_exists('log_activity')) {
            log_activity($conn, 'appointment_cancelled', "Customer cancelled appointment #{$appt_id} — {$row['service_name']}" . ($reason !== '' ? " ({$reason})" : ''), 'appointment', $appt_id);
        }
        $_SESSION['flash_success'] = "Your {$row['service_name']} booking was cancelled.";
    } else {
        $_SESSION['flash_error'] = "This booking can no longer be cancelled online. Please message us and we'll help you.";
    }
    header("Location: appointments.php");
    exit();
}

// ── Appointments (bookings still in online checkout are left out) ────────────
$appointments = [];
$stmt = $conn->prepare("
    SELECT a.*, s.name AS service_name, s.price, s.session_time, s.image, s.category_id
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    WHERE a.user_id = ?
      AND (
          a.order_item_id IS NULL
          OR EXISTS (
              SELECT 1 FROM order_items oi
              JOIN orders o ON oi.order_id = o.id
              WHERE oi.id = a.order_item_id
                AND o.payment_status != 'pending_payment'
          )
      )
    ORDER BY a.appointment_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $appointments[] = $row;
$stmt->close();

$has_sessions = false;
$t = $conn->query("SHOW TABLES LIKE 'appointment_sessions'");
if ($t && $t->num_rows) $has_sessions = true;

foreach ($appointments as &$appt) {
    $ts = $conn->prepare("
        SELECT t.full_name
        FROM appointment_therapists at2
        JOIN therapists t ON at2.therapist_id = t.id
        WHERE at2.appointment_id = ?
    ");
    $ts->bind_param("i", $appt['id']); $ts->execute();
    $appt['therapists'] = array_column($ts->get_result()->fetch_all(MYSQLI_ASSOC), 'full_name');
    $ts->close();

    $es = $conn->prepare("
        SELECT aes.charged_price AS price, s.name AS svc_name
        FROM appointment_extra_services aes
        JOIN services s ON s.id = aes.service_id
        WHERE aes.appointment_id = ?
    ");
    $es->bind_param("i", $appt['id']); $es->execute();
    $appt['extras'] = $es->get_result()->fetch_all(MYSQLI_ASSOC);
    $es->close();

    $appt['order'] = null;
    if (!empty($appt['order_item_id'])) {
        $pm = $conn->prepare("
            SELECT o.id, o.payment_method, o.payment_status, o.approval_status, o.paymongo_method,
                   o.total_amount, o.discount_type, o.discount_amount, o.final_amount,
                   COALESCE(o.completion_discount_amount, 0) AS completion_discount_amount
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.id = ? LIMIT 1
        ");
        $pm->bind_param("i", $appt['order_item_id']); $pm->execute();
        $appt['order'] = $pm->get_result()->fetch_assoc() ?: null; $pm->close();
        if ($appt['order']) {
            // The whole bill: the order (main service) plus its add-ons, less
            // the discounts. final_amount alone leaves out add-ons until completion.
            $_o = &$appt['order'];
            $_o['bill_full']  = (float)($_o['total_amount'] ?: $appt['price']) + array_sum(array_map(fn($x) => (float)$x['price'], $appt['extras']));
            $_o['bill_total'] = max(0.0, $_o['bill_full'] - (float)$_o['discount_amount'] - (float)$_o['completion_discount_amount']);
            unset($_o);
            // GCash QR payment sent at checkout (latest), see ac_pay().
            ensure_manual_payments_table($conn);
            $gq = $conn->prepare("SELECT amount, kind, status, amount_received, reject_reason FROM manual_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $gq->bind_param("i", $appt['order']['id']); $gq->execute();
            $appt['order']['gcash'] = $gq->get_result()->fetch_assoc() ?: null; $gq->close();
        }
    }

    $appt['sessions'] = null;
    if ($has_sessions) {
        $ss = $conn->prepare("SELECT COUNT(*) AS n, SUM(status = 'completed') AS done FROM appointment_sessions WHERE appointment_id = ?");
        $ss->bind_param("i", $appt['id']); $ss->execute();
        $sr = $ss->get_result()->fetch_assoc(); $ss->close();
        if ((int)$sr['n'] > 1) $appt['sessions'] = ['n' => (int)$sr['n'], 'done' => (int)$sr['done']];
    }

    $appt['rating'] = 0;
    if ($appt['status'] === 'completed') {
        try {
            $fb = $conn->prepare("SELECT rating FROM feedback WHERE appointment_id = ? AND user_id = ?");
            $fb->bind_param("ii", $appt['id'], $user_id); $fb->execute();
            $appt['rating'] = (int)($fb->get_result()->fetch_assoc()['rating'] ?? 0); $fb->close();
        } catch (Throwable $e) {}
    }
}
unset($appt);

// Upcoming = still active and not yet past; the soonest one is "Your next visit".
$upcoming = []; $past = []; $now = time();
foreach ($appointments as $a) {
    $done = in_array($a['status'], ['completed', 'declined', 'cancelled'], true);
    $ts   = strtotime($a['appointment_date']);
    // Checked in today stays upcoming until it's completed.
    if (!$done && ($ts >= $now - 4 * 3600 || $a['status'] === 'approved')) $upcoming[] = $a;
    else $past[] = $a;
}
usort($upcoming, function ($x, $y) { return strtotime($x['appointment_date']) <=> strtotime($y['appointment_date']); });
$next = $upcoming[0] ?? null;
$upcoming_rest = array_slice($upcoming, 1);

// ── Product orders ────────────────────────────────────────────────────────────
$grouped_orders = [];
$stmt = $conn->prepare("
    SELECT o.id AS order_id, o.created_at AS order_date, o.total_amount,
           o.payment_method, o.payment_status, o.approval_status,
           o.discount_type, o.discount_amount, o.final_amount, o.paymongo_method,
           oi.quantity, oi.price, oi.subtotal,
           p.name AS product_name, p.image AS product_image
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p     ON oi.product_id = p.id
    WHERE o.user_id = ?
      AND oi.product_id IS NOT NULL
      AND o.payment_status != 'pending_payment'
    ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $oid = $row['order_id'];
    if (!isset($grouped_orders[$oid])) {
        $grouped_orders[$oid] = $row + ['items' => []];
    }
    $grouped_orders[$oid]['items'][] = ['name' => $row['product_name'], 'image' => $row['product_image'], 'qty' => (int)$row['quantity']];
}
$stmt->close();
foreach ($grouped_orders as &$o) {
    $o['rating'] = 0;
    if (($o['approval_status'] ?? '') === 'completed') {
        try {
            $fb = $conn->prepare("SELECT rating FROM feedback WHERE order_id = ? AND user_id = ? AND appointment_id IS NULL");
            $fb->bind_param("ii", $o['order_id'], $user_id); $fb->execute();
            $o['rating'] = (int)($fb->get_result()->fetch_assoc()['rating'] ?? 0); $fb->close();
        } catch (Throwable $e) {}
    }
}
unset($o);

$me = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$me->bind_param("i", $user_id); $me->execute();
$first_name = trim(strtok((string)($me->get_result()->fetch_assoc()['full_name'] ?? ''), ' ')) ?: 'there';
$me->close();

// ── Display helpers ──────────────────────────────────────────────────────────
function ac_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ac_peso($n) { return '₱' . number_format((float)$n, 2); }
function ac_img($file, $dir) { return $file ? BASE_URL . 'uploads/' . $dir . '/' . rawurlencode($file) : ''; }
function ac_len($a) {
    $m = (int)($a['duration_minutes'] ?? 0) ?: (int)($a['session_time'] ?? 0);
    if ($m <= 0) return '';
    $h = intdiv($m, 60); $r = $m % 60;
    return trim(($h ? $h . ' hr ' : '') . ($r ? $r . ' min' : ''));
}
function ac_status($s) {
    $map = [
        'pending'          => ['warn', 'Waiting for confirmation'],
        'assigned'         => ['ok',   'Confirmed'],
        'approved'         => ['ok',   'Checked in'],
        'completed'        => ['ok',   'Completed'],
        'declined'         => ['bad',  'Declined'],
        'cancelled'        => ['grey', 'Cancelled'],
        'refund_requested' => ['warn', 'Refund pending'],
    ];
    return $map[$s] ?? ['grey', ucfirst(str_replace('_', ' ', $s))];
}
function ac_method($o) {
    $labels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bpi_debit' => 'BPI Debit', 'bpi_credit' => 'BPI Credit',
               'qrph' => 'QRPH', 'bank' => 'Bank transfer', 'card' => 'Card', 'online' => 'Online', 'onsite' => 'At the spa'];
    $m = $o['paymongo_method'] ?: $o['payment_method'];
    return $labels[$m] ?? ucfirst((string)$m);
}
function ac_pay($o) {
    if (!$o) return null;
    $ps = $o['payment_status'] ?? '';
    if ($ps === 'paid')     return ['ok', 'Paid · ' . ac_method($o)];
    if ($ps === 'refunded') return ['grey', 'Refunded'];
    if ($ps === 'rejected') return ['bad', 'Payment rejected'];
    $g = $o['gcash'] ?? null;
    if ($g && $g['status'] === 'pending')  return ['warn', 'GCash ' . ac_peso($g['amount']) . ' · being checked'];
    if ($g && $g['status'] === 'rejected') return ['bad', 'GCash not accepted' . ($g['reject_reason'] ? ': ' . $g['reject_reason'] : '')];
    if ($g && $g['status'] === 'verified') {
        $got = (float)$g['amount_received'];
        return ['ok', $got >= (float)$o['bill_total'] - 0.005 ? 'Paid in full · GCash' : 'Down payment ' . ac_peso($got) . ' received · balance at the spa'];
    }
    return ['grey', 'Pay at the spa'];
}
function ac_total($a) {
    $o = $a['order'];
    $extras = array_sum(array_map(function ($x) { return (float)$x['price']; }, $a['extras']));
    if ($o) {
        return [$o['bill_total'], $o['bill_total'] < $o['bill_full'] ? $o['bill_full'] : 0];
    }
    $base = (float)($a['charged_price'] ?? 0) ?: (float)$a['price'];
    return [$base + $extras, 0];
}
function ac_where($a) { return ($a['service_type'] ?? '') === 'home' ? 'Home service' : 'Recovery Iloilo, Molo'; }
function ac_can_cancel($a) {
    return $a['status'] === 'pending' && (($a['order']['payment_status'] ?? '') !== 'paid') && strtotime($a['appointment_date']) > time();
}
function ac_rel_day($ts) {
    $d = (int)floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d'))) / 86400);
    if ($d < 0) return 'today';
    if ($d === 0) return 'today';
    if ($d === 1) return 'tomorrow';
    return 'in ' . $d . ' days';
}
function ac_book_again($a) { return 'services.php?category=' . (int)$a['category_id'] . '&item=svc-' . (int)$a['service_id']; }

function ac_rate_html($rating, $link) {
    $h = '<div class="ac-rate">';
    if ($rating) {
        $h .= '<span>Your rating</span>';
        for ($i = 1; $i <= 5; $i++) $h .= '<b class="' . ($i <= $rating ? 'on' : '') . '" aria-hidden="true">★</b>';
        $h .= '<span class="sr-only" style="position:absolute;left:-9999px">' . $rating . ' out of 5</span>';
    } else {
        $h .= '<span>How was it?</span>';
        for ($i = 1; $i <= 5; $i++) $h .= '<a href="' . ac_h($link) . '" aria-label="Rate ' . $i . ' star' . ($i > 1 ? 's' : '') . '">★</a>';
    }
    return $h . '</div>';
}

function ac_appt_card($a) {
    [$cls, $label] = ac_status($a['status']);
    $pay = ac_pay($a['order']);
    [$total, $was] = ac_total($a);
    $ts  = strtotime($a['appointment_date']);
    $sub = date('D, M j', $ts) . ' · ' . date('g:i A', $ts) . (ac_len($a) ? ' · ' . ac_len($a) : '');
    $faded = in_array($a['status'], ['declined', 'cancelled'], true);
    ob_start(); ?>
    <article class="ac-card<?php echo $faded ? ' faded' : ''; ?>">
        <div class="ac-main">
            <div class="ac-th"><?php if ($a['image']): ?><img src="<?php echo ac_h(ac_img($a['image'], 'services')); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
            <div>
                <h3><?php echo ac_h($a['service_name']); ?></h3>
                <div class="ac-sub"><?php echo ac_h($sub); ?><?php echo ($a['service_type'] ?? '') === 'home' ? ' · Home service' : ''; ?><?php echo (int)$a['people_count'] > 1 ? ' · ' . (int)$a['people_count'] . ' people' : ''; ?></div>
                <div class="ac-meta">
                    <span class="ac-pill <?php echo $cls; ?>"><?php echo ac_h($label); ?></span>
                    <?php if ($pay): ?><span class="ac-pill <?php echo $pay[0]; ?>"><?php echo ac_h($pay[1]); ?></span><?php endif; ?>
                </div>
                <?php if ($a['sessions']): ?>
                <div class="ac-sess"><?php for ($i = 0; $i < $a['sessions']['n']; $i++): ?><i class="<?php echo $i < $a['sessions']['done'] ? 'on' : ''; ?>"></i><?php endfor; ?><span><?php echo $a['sessions']['done']; ?> of <?php echo $a['sessions']['n']; ?> sessions done</span></div>
                <?php endif; ?>
                <?php if ($a['therapists']): ?><div class="ac-sub" style="margin-top:6px">Therapist: <?php echo ac_h(implode(', ', $a['therapists'])); ?></div>
                <?php elseif (!in_array($a['status'], ['completed', 'declined', 'cancelled'], true)): ?><div class="ac-sub" style="margin-top:6px">Therapist will be assigned</div><?php endif; ?>
                <?php if ($a['extras']): ?><div class="ac-extras">Added: <?php echo ac_h(implode(', ', array_column($a['extras'], 'svc_name'))); ?></div><?php endif; ?>
            </div>
            <div class="ac-amt"><?php if ($was): ?><s><?php echo ac_peso($was); ?></s><?php endif; ?><?php echo ac_peso($total); ?><small>Total</small></div>
        </div>
        <?php if ($a['status'] === 'declined' || $a['status'] === 'cancelled' || $a['status'] === 'refund_requested'): ?>
            <?php if (!empty($a['cancel_reason'])): ?><div class="ac-reason<?php echo $a['status'] === 'declined' ? '' : ' grey'; ?>"><b>Why:</b> <?php echo ac_h($a['cancel_reason']); ?></div><?php endif; ?>
        <?php endif; ?>
        <div class="ac-more">
            <?php if ($a['status'] === 'completed'): ?>
                <?php echo ac_rate_html($a['rating'], 'feedback.php?type=appointment&id=' . (int)$a['id']); ?>
            <?php endif; ?>
            <span class="ac-sp"></span>
            <?php if (($a['order']['payment_status'] ?? '') === 'paid'): ?><a class="ac-btn ac-btn-line ac-btn-sm" href="payment_success.php?order_id=<?php echo (int)$a['order']['id']; ?>">Receipt</a><?php endif; ?>
            <?php if (ac_can_cancel($a)): ?>
                <button type="button" class="ac-btn ac-btn-bad ac-btn-sm" data-cancel-appt="<?php echo (int)$a['id']; ?>" data-name="<?php echo ac_h($a['service_name']); ?>">Cancel</button>
            <?php elseif (in_array($a['status'], ['completed', 'declined', 'cancelled'], true)): ?>
                <a class="ac-btn ac-btn-dark ac-btn-sm" href="<?php echo ac_h(ac_book_again($a)); ?>">Book again</a>
            <?php else: ?>
                <button type="button" class="ac-btn ac-btn-line ac-btn-sm" data-chat>Change or cancel</button>
            <?php endif; ?>
        </div>
    </article>
    <?php return ob_get_clean();
}

$page_title = 'My Bookings';
require_once 'header.php';
$csrf = csrf_field();
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/account.css?v=<?php echo filemtime(__DIR__ . '/../assets/account.css'); ?>">

<div class="ac-page">
<div class="ac-wrap">
    <div class="ac-hello">
        <div><span class="ac-eyebrow">My bookings</span><h1>Hi, <em><?php echo ac_h($first_name); ?></em></h1></div>
        <a class="ac-btn ac-btn-gold" href="services.php">+ Book a service</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?><div class="ac-flash ok" role="status"><?php echo ac_h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?><div class="ac-flash bad" role="alert"><?php echo ac_h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="ac-flash ok" role="status">Your appointment has been cancelled.</div><?php endif; ?>

    <?php if ($next):
        $ts = strtotime($next['appointment_date']);
        $pay = ac_pay($next['order']);
        [$total] = ac_total($next);
        $steps = ['pending' => 1, 'assigned' => 2, 'approved' => 3, 'completed' => 4];
        $step = $steps[$next['status']] ?? 1;
        $tips = [
            'pending'  => "We'll confirm your booking and assign a therapist soon. You'll get a notification.",
            'assigned' => 'Please arrive 15–20 minutes before your time.',
            'approved' => "You're checked in. Your session will start shortly.",
            'refund_requested' => 'Your refund request is being reviewed.',
        ];
    ?>
    <article class="ac-next" aria-label="Your next visit">
        <div class="ac-next-top">
            <div class="ac-next-ph"><?php if ($next['image']): ?><img src="<?php echo ac_h(ac_img($next['image'], 'services')); ?>" alt="" onerror="this.remove()"><?php endif; ?></div>
            <div>
                <span class="ac-eyebrow">Your next visit · <?php echo ac_rel_day($ts); ?></span>
                <h2><?php echo ac_h($next['service_name']); ?></h2>
                <div class="ac-when"><?php echo date('l, M j', $ts) . ' · ' . date('g:i A', $ts); ?><small><?php echo ac_h(trim(ac_len($next) . ' · ' . ac_where($next), ' ·')); ?></small></div>
            </div>
        </div>
        <div class="ac-track" aria-label="Booking progress">
            <?php foreach (['Requested', 'Confirmed', 'Checked in', 'Done'] as $i => $lbl): ?><div class="<?php echo $i < $step ? 'done' : ''; ?>"><?php echo $lbl; ?></div><?php endforeach; ?>
        </div>
        <?php if (!empty($tips[$next['status']])): ?><div class="ac-tip"><?php echo ac_h($tips[$next['status']]); ?></div><?php endif; ?>
        <div class="ac-facts">
            <div><span>Therapist</span><b><?php echo $next['therapists'] ? ac_h(implode(', ', $next['therapists'])) : 'To be assigned'; ?></b></div>
            <div><span>Payment</span><b><?php echo $pay ? ac_h($pay[1]) : 'Pay at the spa'; ?></b></div>
            <div><span>Total</span><b><?php echo ac_peso($total); ?></b></div>
        </div>
        <div class="ac-next-acts">
            <?php if (($next['order']['payment_status'] ?? '') === 'paid'): ?><a class="ac-btn ac-btn-gold ac-btn-sm" href="payment_success.php?order_id=<?php echo (int)$next['order']['id']; ?>">View receipt</a><?php endif; ?>
            <button type="button" class="ac-btn ac-btn-ghost ac-btn-sm" data-chat>Message us</button>
            <?php if (ac_can_cancel($next)): ?><button type="button" class="ac-btn ac-btn-ghost ac-btn-sm" data-cancel-appt="<?php echo (int)$next['id']; ?>" data-name="<?php echo ac_h($next['service_name']); ?>">Cancel</button><?php endif; ?>
        </div>
    </article>
    <?php endif; ?>

    <div class="ac-tabs" role="tablist" aria-label="Bookings">
        <button type="button" role="tab" data-tab="up" aria-selected="true">Upcoming<span><?php echo count($upcoming_rest); ?></span></button>
        <button type="button" role="tab" data-tab="past" aria-selected="false">Past<span><?php echo count($past); ?></span></button>
        <button type="button" role="tab" data-tab="orders" aria-selected="false">Orders<span><?php echo count($grouped_orders); ?></span></button>
    </div>

    <div class="ac-list" data-panel="up">
        <?php if ($upcoming_rest): foreach ($upcoming_rest as $a) echo ac_appt_card($a); else: ?>
        <div class="ac-empty"><b><?php echo $next ? 'No other upcoming bookings' : 'No upcoming bookings'; ?></b>When you book a service, it shows up here.<br><a class="ac-btn ac-btn-gold" href="services.php">Browse services</a></div>
        <?php endif; ?>
    </div>
    <div class="ac-list" data-panel="past" hidden>
        <?php if ($past): foreach ($past as $a) echo ac_appt_card($a); else: ?>
        <div class="ac-empty"><b>No past visits yet</b>Your finished and cancelled bookings will show here.</div>
        <?php endif; ?>
    </div>
    <div class="ac-list" data-panel="orders" hidden>
        <?php if ($grouped_orders): foreach ($grouped_orders as $o):
            $apvl = $o['approval_status'] ?? ''; $ps = $o['payment_status'] ?? 'unpaid'; $method = $o['payment_method'] ?? 'onsite';
            if ($apvl === 'completed') $st = ['ok', 'Picked up'];
            elseif ($apvl === 'approved') $st = $ps === 'paid' ? ['ok', 'Ready for pick-up'] : ['warn', 'Ready · pay at the spa'];
            elseif ($apvl === 'cancelled') $st = ['grey', 'Cancelled'];
            elseif ($apvl === 'declined' || $ps === 'refunded') $st = ['bad', $ps === 'refunded' ? 'Refunded' : 'Declined'];
            elseif ($ps === 'paid') $st = ['warn', 'Paid · waiting for approval'];
            else $st = ['warn', 'Waiting for approval'];
            $pay = $ps === 'paid' ? ['ok', 'Paid · ' . ac_method($o)] : ($ps === 'refunded' ? ['grey', 'Refunded'] : ['grey', 'Pay on pick-up']);
            $n = count($o['items']);
            $title = $n === 1 ? $o['items'][0]['name'] : $o['items'][0]['name'] . ' + ' . ($n - 1) . ' more';
            $qty = array_sum(array_column($o['items'], 'qty'));
            $full = (float)$o['total_amount']; $final = (float)($o['final_amount'] ?: $full);
        ?>
        <article class="ac-card<?php echo in_array($apvl, ['cancelled', 'declined'], true) ? ' faded' : ''; ?>">
            <div class="ac-main">
                <div class="ac-th"><?php if ($o['items'][0]['image']): ?><img src="<?php echo ac_h(ac_img($o['items'][0]['image'], 'products')); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
                <div>
                    <h3><?php echo ac_h($title); ?></h3>
                    <div class="ac-sub">Ordered <?php echo date('M j, Y', strtotime($o['order_date'])); ?> · <?php echo $qty; ?> item<?php echo $qty > 1 ? 's' : ''; ?></div>
                    <div class="ac-meta"><span class="ac-pill <?php echo $st[0]; ?>"><?php echo ac_h($st[1]); ?></span><span class="ac-pill <?php echo $pay[0]; ?>"><?php echo ac_h($pay[1]); ?></span></div>
                    <?php if ($n > 1): ?><div class="ac-items"><?php foreach ($o['items'] as $it): if ($it['image']): ?><img src="<?php echo ac_h(ac_img($it['image'], 'products')); ?>" alt="<?php echo ac_h($it['name']); ?>" title="<?php echo ac_h($it['name'] . ' ×' . $it['qty']); ?>" loading="lazy" onerror="this.remove()"><?php endif; endforeach; ?></div><?php endif; ?>
                </div>
                <div class="ac-amt"><?php if ($final < $full): ?><s><?php echo ac_peso($full); ?></s><?php endif; ?><?php echo ac_peso($final); ?><small>Total</small></div>
            </div>
            <div class="ac-more">
                <?php if ($apvl === 'pending'): ?>
                    <button type="button" class="ac-btn ac-btn-bad ac-btn-sm" data-cancel-order="<?php echo (int)$o['order_id']; ?>" data-name="<?php echo ac_h($title); ?>">Cancel order</button>
                <?php elseif ($apvl === 'completed'): ?>
                    <?php echo ac_rate_html($o['rating'], 'feedback.php?type=order&id=' . (int)$o['order_id']); ?>
                <?php endif; ?>
                <span class="ac-sp"></span>
                <?php if ($ps === 'paid'): ?><a class="ac-btn ac-btn-line ac-btn-sm" href="payment_success.php?order_id=<?php echo (int)$o['order_id']; ?>">Receipt</a><?php endif; ?>
            </div>
        </article>
        <?php endforeach; else: ?>
        <div class="ac-empty"><b>No product orders yet</b>Products you buy from our shop show up here.<br><a class="ac-btn ac-btn-gold" href="products.php">Browse products</a></div>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="ac-scrim" id="acScrim" hidden></div>
<div class="ac-sheet" id="acSheet" role="dialog" aria-modal="true" aria-labelledby="acShT" hidden>
    <h2 id="acShT">Cancel this booking?</h2>
    <p id="acShName"></p>
    <form method="POST" action="appointments.php" id="acCancelForm">
        <?php echo $csrf; ?>
        <input type="hidden" name="action" id="acAction" value="cancel_appointment">
        <input type="hidden" name="appt_id" id="acApptId" value="">
        <input type="hidden" name="order_id" id="acOrderId" value="">
        <input type="hidden" name="cancel_reason" id="acReason" value="">
        <div><div style="font-weight:800;margin-bottom:6px">Reason (optional)</div>
            <div class="ac-chips" id="acChips">
                <button type="button" aria-pressed="false">Change of schedule</button>
                <button type="button" aria-pressed="false">Not feeling well</button>
                <button type="button" aria-pressed="false">Booked by mistake</button>
                <button type="button" aria-pressed="false">Other</button>
            </div>
        </div>
        <div class="ac-acts">
            <button type="button" class="ac-btn ac-btn-line" id="acKeep">Keep it</button>
            <button type="submit" class="ac-btn ac-btn-dark" style="background:var(--ac-bad)" id="acYes">Yes, cancel</button>
        </div>
    </form>
</div>

<script>
(function () {
    var panels = document.querySelectorAll('[data-panel]');
    function show(tab) {
        panels.forEach(function (p) { p.hidden = p.dataset.panel !== tab; });
        document.querySelectorAll('.ac-tabs button').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === tab); });
    }
    document.querySelector('.ac-tabs').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        show(b.dataset.tab);
        history.replaceState(null, '', b.dataset.tab === 'up' ? location.pathname : '#' + b.dataset.tab);
    });
    if (location.hash === '#orders' || location.hash === '#past') show(location.hash.slice(1));

    // Message us: opens the chat drawer from the account header when it's there.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-chat]')) return;
        if (typeof window.openChatDrawer === 'function') window.openChatDrawer();
        else location.href = '<?php echo BASE_URL; ?>index.php#contact';
    });

    var sheet = document.getElementById('acSheet'), scrim = document.getElementById('acScrim'), last = null;
    function open(kind, id, name) {
        last = document.activeElement;
        document.getElementById('acAction').value = kind === 'order' ? 'cancel_order' : 'cancel_appointment';
        document.getElementById('acApptId').value = kind === 'order' ? '' : id;
        document.getElementById('acOrderId').value = kind === 'order' ? id : '';
        document.getElementById('acReason').value = '';
        document.getElementById('acShT').textContent = kind === 'order' ? 'Cancel this order?' : 'Cancel this booking?';
        document.getElementById('acShName').innerHTML = '<b></b>';
        document.querySelector('#acShName b').textContent = name;
        document.getElementById('acYes').textContent = kind === 'order' ? 'Yes, cancel order' : 'Yes, cancel';
        document.querySelectorAll('#acChips button').forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
        sheet.hidden = false; scrim.hidden = false;
        document.getElementById('acKeep').focus();
    }
    function close() { sheet.hidden = true; scrim.hidden = true; if (last) last.focus(); }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-cancel-appt]'); if (a) { open('appt', a.dataset.cancelAppt, a.dataset.name); return; }
        var o = e.target.closest('[data-cancel-order]'); if (o) open('order', o.dataset.cancelOrder, o.dataset.name);
    });
    document.getElementById('acKeep').addEventListener('click', close);
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });
    document.getElementById('acChips').addEventListener('click', function (e) {
        var c = e.target.closest('button'); if (!c) return;
        this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === c); });
        document.getElementById('acReason').value = c.textContent;
    });
})();
</script>
<?php require_once __DIR__ . '/../footer.php'; ?>
