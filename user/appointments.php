<?php
require_once '../config.php';
redirect_if_not_user();

// ── AJAX: must be BEFORE any HTML output ─────────────────────────────────────
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

$user_id = $_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: CUSTOMER CANCEL APPOINTMENT
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'customer_cancel') {
    verify_csrf_token();
    $appt_id       = intval($_POST['appt_id'] ?? 0);
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    $chk = $conn->prepare("
        SELECT a.id, a.status, a.order_item_id, s.name AS service_name
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.id = ? AND a.user_id = ?
        LIMIT 1
    ");
    $chk->bind_param("ii", $appt_id, $user_id);
    $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    $chk->close();

    $allowed_statuses = ['pending', 'approved', 'assigned'];

    if ($chk_row && in_array($chk_row['status'], $allowed_statuses)) {
        $is_paid_online = false;
        $order_id       = null;
        $paid_amount    = 0;
        $pm_payment_id  = null;

        if (!empty($chk_row['order_item_id'])) {
            $om = $conn->prepare("
                SELECT o.id, o.payment_method, o.payment_status,
                       o.total_amount, o.paymongo_payment_id
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE oi.id = ? LIMIT 1
            ");
            $om->bind_param("i", $chk_row['order_item_id']);
            $om->execute();
            $om_row = $om->get_result()->fetch_assoc();
            $om->close();

            if ($om_row &&
                $om_row['payment_method'] === 'online' &&
                $om_row['payment_status'] === 'paid') {
                $is_paid_online = true;
                $order_id       = $om_row['id'];
                $paid_amount    = floatval($om_row['total_amount']);
                $pm_payment_id  = $om_row['paymongo_payment_id'] ?? null;
            }
        }

        require_once '../notify.php';

        if ($is_paid_online) {
            $ins = $conn->prepare("
                INSERT INTO refund_requests
                    (appointment_id, order_id, user_id, amount,
                     reason, status, paymongo_payment_id)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
            ");
            $ins->bind_param("iiidss",
                $appt_id, $order_id, $user_id,
                $paid_amount, $cancel_reason, $pm_payment_id
            );
            $ins->execute();
            $ins->close();

            $upd = $conn->prepare("
                UPDATE appointments
                SET status = 'refund_requested', cancel_reason = ?
                WHERE id = ? AND user_id = ?
            ");
            $upd->bind_param("sii", $cancel_reason, $appt_id, $user_id);
            $upd->execute(); $upd->close();

            add_notification($conn, $user_id, 'appointment',
                '⏳ Refund Request Submitted',
                'Your cancellation and refund request for ' . $chk_row['service_name'] .
                ' has been submitted. The owner will process your refund shortly.',
                'appointments.php'
            );
            header("Location: appointments.php?refund_requested=1"); exit();

        } else {
            $upd = $conn->prepare("
                UPDATE appointments
                SET status = 'cancelled', cancel_reason = ?
                WHERE id = ? AND user_id = ?
            ");
            $upd->bind_param("sii", $cancel_reason, $appt_id, $user_id);
            $upd->execute(); $upd->close();

            add_notification($conn, $user_id, 'appointment',
                '🚫 Appointment Cancelled',
                'Your ' . $chk_row['service_name'] . ' appointment has been cancelled.' .
                ($cancel_reason ? ' Reason: ' . $cancel_reason : ''),
                'appointments.php'
            );
            header("Location: appointments.php?cancelled=1"); exit();
        }

    } else {
        $cancel_error = "This appointment cannot be cancelled.";
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: CUSTOMER CANCEL ORDER
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    verify_csrf_token();
    $order_id      = intval($_POST['order_id'] ?? 0);
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    $chk = $conn->prepare("
        SELECT id FROM orders
        WHERE id = ? AND user_id = ? AND approval_status = 'pending'
    ");
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
        $_SESSION['flash_success'] = "Order cancelled successfully.";
    } else {
        $_SESSION['flash_error'] = "Cannot cancel this order.";
    }
    header("Location: appointments.php#orders");
    exit();
}

$filter_status = isset($_GET['filter']) ? sanitize_input($_GET['filter']) : '';

// ── Fetch appointments ────────────────────────────────────────────────────────
$status_options = ['pending', 'approved', 'assigned', 'declined', 'completed', 'cancelled', 'refund_requested'];
$appointments   = [];

if ($filter_status && in_array($filter_status, $status_options)) {
    $stmt = $conn->prepare("
        SELECT a.*, s.name AS service_name, s.price, s.session_time, s.image
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.user_id = ?
          AND a.status = ?
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
    $stmt->bind_param("is", $user_id, $filter_status);
} else {
    $stmt = $conn->prepare("
        SELECT a.*, s.name AS service_name, s.price, s.session_time, s.image
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
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $appointments[] = $row;
$stmt->close();

// ── Fetch assigned therapists & extra services per appointment ────────────────
foreach ($appointments as &$appt) {
    $ts = $conn->prepare("
        SELECT t.full_name, t.specialties, at2.notes
        FROM appointment_therapists at2
        JOIN therapists t ON at2.therapist_id = t.id
        WHERE at2.appointment_id = ?
        ORDER BY at2.assigned_at ASC
    ");
    $ts->bind_param("i", $appt['id']); $ts->execute();
    $appt['assigned_therapists'] = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
    $ts->close();

    $es = $conn->prepare("
        SELECT aes.charged_price AS price, s.name AS svc_name, t.full_name AS therapist_name
        FROM appointment_extra_services aes
        JOIN services s ON s.id = aes.service_id
        LEFT JOIN therapists t ON t.id = aes.therapist_id
        WHERE aes.appointment_id = ?
    ");
    $es->bind_param("i", $appt['id']); $es->execute();
    $appt['extra_services'] = $es->get_result()->fetch_all(MYSQLI_ASSOC);
    $es->close();
}
unset($appt);

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = [];
foreach ($status_options as $status) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count FROM appointments a
        WHERE a.user_id = ? AND a.status = ?
          AND (a.order_item_id IS NULL OR EXISTS (
              SELECT 1 FROM order_items oi JOIN orders o ON oi.order_id = o.id
              WHERE oi.id = a.order_item_id AND o.payment_status != 'pending_payment'
          ))
    ");
    $stmt->bind_param("is", $user_id, $status);
    $stmt->execute();
    $stats[$status] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// ── Split upcoming vs history ─────────────────────────────────────────────────
$upcoming = []; $history = []; $now = new DateTime();
foreach ($appointments as $appt) {
    $appt_date = new DateTime($appt['appointment_date']);
    if (in_array($appt['status'], ['completed','declined','cancelled','refund_requested']) || $appt_date < $now)
        $history[] = $appt;
    else
        $upcoming[] = $appt;
}

// ── Product orders ────────────────────────────────────────────────────────────
$product_orders = [];
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
while ($row = $result->fetch_assoc()) $product_orders[] = $row;
$stmt->close();

$grouped_orders = [];
foreach ($product_orders as $row) {
    $oid = $row['order_id'];
    if (!isset($grouped_orders[$oid])) {
        $grouped_orders[$oid] = [
            'order_id' => $oid, 'order_date' => $row['order_date'],
            'total_amount' => $row['total_amount'], 'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'], 'approval_status' => $row['approval_status'],
            'discount_type' => $row['discount_type'] ?? 'none',
            'discount_amount' => floatval($row['discount_amount'] ?? 0),
            'final_amount' => floatval($row['final_amount'] ?? 0),
            'paymongo_method' => $row['paymongo_method'] ?? null,
            'items' => [],
        ];
    }
    $grouped_orders[$oid]['items'][] = [
        'product_name' => $row['product_name'], 'product_image' => $row['product_image'],
        'quantity' => $row['quantity'], 'price' => $row['price'], 'subtotal' => $row['subtotal'],
    ];
}

// ── Fetch logged-in user info (shown in "Your Information" collapsible) ───────
$ui_stmt = $conn->prepare("SELECT full_name, email, phone, address FROM users WHERE id = ? LIMIT 1");
$ui_stmt->bind_param("i", $user_id); $ui_stmt->execute();
$user_info = $ui_stmt->get_result()->fetch_assoc() ?? [];
$ui_stmt->close();

$page_title = 'My Appointments & Orders';
require_once 'header.php';

// ── Shared helper: render price cell with discount breakdown ─────────────────
function render_price_cell(float $service_price, array $pm_row): void {
    $orig      = floatval($pm_row['total_amount'] ?? $service_price) ?: $service_price;
    $disc_type = $pm_row['discount_type']   ?? 'none';
    $disc_amt  = floatval($pm_row['discount_amount'] ?? 0);
    $final_amt = floatval($pm_row['final_amount']    ?? 0);

    $has_disc  = ($disc_type !== 'none' && $disc_type !== '');
    $confirmed = $has_disc && $disc_amt > 0;   // admin has set a real amount
    $pending   = $has_disc && $disc_amt === 0; // declared but not yet confirmed onsite

    $disc_labels = [
        'senior'   => ['👴', 'Senior (20%)'],
        'pwd'      => ['♿', 'PWD (20%)'],
        'voucher'  => ['🎟️', 'Voucher'],
        'employee' => ['🪪', 'Employee'],
    ];
    [$dico, $dlbl] = $disc_labels[$disc_type] ?? ['🎟️', ucfirst($disc_type)];

    echo '<p><strong>💰 Price:</strong><br>';
    if ($confirmed) {
        // Strikethrough original + green final + discount chip
        echo '<span style="text-decoration:line-through;color:#9ca3af;font-size:0.85rem;">₱' . number_format($orig, 2) . '</span> ';
        echo '<span style="color:#15803d;font-weight:800;">₱' . number_format($final_amt, 2) . '</span><br>';
        echo '<span style="display:inline-flex;align-items:center;gap:0.2rem;margin-top:0.2rem;'
           . 'font-size:0.72rem;font-weight:700;padding:0.1rem 0.5rem;border-radius:20px;'
           . 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;">'
           . $dico . ' −₱' . number_format($disc_amt, 2) . '</span>';
    } elseif ($pending) {
        // Full price shown; discount declared but not yet applied
        echo '₱' . number_format($orig, 2) . '<br>';
        echo '<span style="display:inline-flex;align-items:center;gap:0.2rem;margin-top:0.2rem;'
           . 'font-size:0.72rem;font-weight:700;padding:0.1rem 0.5rem;border-radius:20px;'
           . 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;">'
           . $dico . ' ' . $dlbl . ' — pending</span>';
    } else {
        echo '₱' . number_format($orig, 2);
    }
    echo '</p>';
}

// ── Shared helper: render payment badge ──────────────────────────────────────
function render_payment_badge(string $pay_method, string $pay_status, string $apvl_status, ?string $pm_actual): void {
    // Resolve the actual method name (PayMongo fills paymongo_method after payment)
    $method_labels = [
        'cash'       => ['🏪 Onsite', '💵 Cash'],
        'gcash'      => ['🌐 Online', '📱 GCash'],
        'maya'       => ['🌐 Online', '💜 Maya'],
        'bpi_debit'  => ['🌐 Online', '🏦 BPI Debit'],
        'bpi_credit' => ['🌐 Online', '💳 BPI Credit'],
        'qrph'       => ['🌐 Online', '📷 QRPH'],
        'bank'       => ['🏪 Onsite', '🏦 Bank Transfer'],
        'card'       => ['🏪 Onsite', '💳 Card'],
        'online'     => ['🌐 Online', '💳 Online'],
        'onsite'     => ['🏪 Onsite', '🏪 Onsite'],
    ];

    $display = $pm_actual ?: $pay_method;
    [$channel, $specific] = $method_labels[$display] ?? ['🏪 Onsite', ucfirst($display)];

    echo '<span class="payment-badge method-' . ($pay_method === 'online' ? 'online' : 'onsite') . '">'
       . $channel . ' · ' . $specific . '</span>';
}
?>

<style>
.tab-nav { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:2rem; }
.tab-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.3rem; padding:1.1rem 1rem; border:2px solid #EAD8C0; border-radius:14px; background:#fff; color:#9a7c68; cursor:pointer; font-weight:700; transition:all 0.2s; position:relative; width:100%; box-shadow:0 2px 6px rgba(0,0,0,.04); }
.tab-btn .tab-icon  { font-size:1.6rem; line-height:1; }
.tab-btn .tab-label { font-size:0.9rem; font-weight:700; }
.tab-btn .tab-count { display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:22px; padding:0 8px; border-radius:20px; font-size:0.78rem; font-weight:800; background:#EAD8C0; color:#3B2A1A; transition:all 0.2s; }
.tab-btn.active { background:#3B2A1A; border-color:#3B2A1A; color:#FAF3E8; box-shadow:0 4px 14px rgba(59,42,26,.25); }
.tab-btn.active .tab-count { background:#C96A2C; color:#fff; }
.tab-btn:not(.active):hover { border-color:#C96A2C; color:#C96A2C; background:#FFF8F3; }
.tab-btn:not(.active):hover .tab-count { background:#C96A2C; color:#fff; }
.tab-notify-dot { position:absolute; top:10px; right:10px; width:10px; height:10px; background:#C96A2C; border-radius:50%; border:2px solid #fff; animation:pulse-dot 1.8s infinite; }
@keyframes pulse-dot { 0%,100%{transform:scale(1);opacity:1;} 50%{transform:scale(1.3);opacity:.7;} }
@media(max-width:480px) { .tab-btn { padding:0.85rem 0.5rem; } .tab-btn .tab-icon { font-size:1.3rem; } .tab-btn .tab-label { font-size:0.78rem; } }
.tab-panel { display:none; }
.tab-panel.active { display:block; }
.appointment-card { background:#fff; border-radius:12px; padding:1.5rem; margin-bottom:1rem; box-shadow:0 2px 10px rgba(0,0,0,0.07); border-left:4px solid #C96A2C; }
.order-card { background:#fff; border-radius:12px; padding:1.5rem; margin-bottom:1rem; box-shadow:0 2px 10px rgba(0,0,0,0.07); border-left:4px solid #C96A2C; }
.appointment-status, .payment-badge { padding:0.3rem 0.8rem; border-radius:20px; font-size:0.82rem; font-weight:bold; }
.status-pending          { background:#fff3cd; color:#664d03; }
.status-approved         { background:#d1e7dd; color:#0a3622; }
.status-assigned         { background:#cfe2ff; color:#084298; }
.status-declined         { background:#f8d7da; color:#842029; }
.status-completed        { background:#cff4fc; color:#055160; }
.status-cancelled        { background:#f3f4f6; color:#374151; }
.status-refund_requested { background:#fef9c3; color:#854d0e; border:1px solid #fde047; }
.method-onsite { background:#e2e3e5; color:#41464b; }
.method-online { background:#cfe2ff; color:#084298; }
.order-item-row { display:flex; align-items:center; gap:1rem; padding:0.75rem 0; border-bottom:1px solid #EAD8C0; }
.order-item-row:last-child { border-bottom:none; }
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:#fff; border-radius:10px; padding:1rem; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.07); }
.stat-number { font-size:1.8rem; font-weight:bold; color:#C96A2C; }
.stat-label { font-size:0.85rem; color:#888; margin-top:0.3rem; }
.filter-bar { display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.section-title { color:#3B2A1A; margin:1.5rem 0 1rem 0; font-size:1.2rem; }
.feedback-btn { display:inline-block; padding:0.45rem 1rem; background:#C96A2C; color:#fff; border-radius:8px; font-size:0.82rem; font-weight:600; text-decoration:none; transition:background 0.2s; }
.feedback-btn:hover { background:#A94F1D; }
.receipt-btn { display:inline-block; padding:0.45rem 1rem; background:#FAF3E8; color:#3B2A1A; border:1px solid #C8A46B; border-radius:8px; font-size:0.82rem; font-weight:600; text-decoration:none; transition:all 0.2s; }
.receipt-btn:hover { background:#C8A46B; color:#3B2A1A; }
.cancel-btn { display:inline-block; padding:0.45rem 1rem; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-size:0.82rem; font-weight:600; cursor:pointer; transition:all 0.2s; }
.cancel-btn:hover { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
.feedback-stars { display:flex; align-items:center; gap:0.4rem; }
.therapist-pill { display:inline-flex; align-items:center; gap:0.35rem; background:#FFF3E8; border:1px solid #EAD8C0; color:#A94F1D; border-radius:20px; padding:0.25rem 0.7rem; font-size:0.8rem; font-weight:600; }
.therapist-pill .avatar { width:20px; height:20px; border-radius:50%; background:linear-gradient(135deg,#C96A2C,#A94F1D); color:#fff; font-size:0.65rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.spa-footer { margin-top:8rem; }
.modal-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); backdrop-filter:blur(3px); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; padding:2rem; width:min(480px, 92vw); box-shadow:0 24px 60px rgba(0,0,0,0.2); animation: modalIn 0.2s ease; }
@keyframes modalIn { from { transform:scale(0.92); opacity:0; } to { transform:scale(1); opacity:1; } }
details.card-section { border-top:1px solid #EAD8C0; margin-top:0.85rem; padding-top:0.85rem; }
details.card-section > summary { font-weight:600; color:#3B2A1A; cursor:pointer; font-size:0.88rem; list-style:none; display:flex; align-items:center; justify-content:space-between; padding:0.1rem 0; }
details.card-section > summary::after { content:'▸'; font-size:0.75rem; color:#9a7c68; margin-left:0.5rem; flex-shrink:0; }
details.card-section[open] > summary::after { content:'▾'; }
details.card-section summary::-webkit-details-marker { display:none; }
.info-grid { display:grid; grid-template-columns:max-content 1fr; gap:0.35rem 1.25rem; margin-top:0.6rem; font-size:0.87rem; }
.info-grid .lbl { color:#9a7c68; font-weight:600; white-space:nowrap; }
.info-grid .val { color:#3B2A1A; word-break:break-word; }
</style>

<div class="container">
<h1 style="color:#3B2A1A; margin:2rem 0;">My Appointments & Orders</h1>

<?php if (isset($_GET['cancelled'])): ?>
<div style="background:#d1e7dd;color:#0a3622;padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;border:1px solid #a3cfbb;">
    ✅ Your appointment has been successfully cancelled.
</div>
<?php endif; ?>

<?php if (isset($_GET['refund_requested'])): ?>
<div style="background:#fef9c3;color:#854d0e;padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;border:1px solid #fde047;">
    💸 Your cancellation and refund request has been submitted. The owner will review and process your refund shortly.
</div>
<?php endif; ?>

<?php if (!empty($cancel_error)): ?>
<div style="background:#f8d7da;color:#842029;padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;border:1px solid #f1aeb5;">
    ❌ <?php echo htmlspecialchars($cancel_error); ?>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div style="background:#d1e7dd;color:#0a3622;padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;border:1px solid #a3cfbb;">
    ✅ <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div style="background:#f8d7da;color:#842029;padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;border:1px solid #f1aeb5;">
    ❌ <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
</div>
<?php endif; ?>

<div class="tab-nav">
    <button class="tab-btn active" id="tab-btn-appointments" onclick="switchTab('appointments', this)">
        <span class="tab-icon">📅</span>
        <span class="tab-label">My Appointments</span>
        <span class="tab-count"><?php echo count($appointments); ?></span>
    </button>
    <button class="tab-btn" id="tab-btn-orders" onclick="switchTab('orders', this)">
        <?php if (count($grouped_orders) > 0): ?>
        <span class="tab-notify-dot" id="orders-notify-dot"></span>
        <?php endif; ?>
        <span class="tab-icon">🛍️</span>
        <span class="tab-label">My Product Orders</span>
        <span class="tab-count"><?php echo count($grouped_orders); ?></span>
    </button>
</div>

<!-- ── APPOINTMENTS TAB ───────────────────────────────────────────────────── -->
<div class="tab-panel active" id="tab-appointments">

    <div class="stats-grid">
        <?php
        $stat_labels = [
            'pending'          => ['⏳', 'Pending Confirmation'],
            'approved'         => ['🏃', 'Checked In'],
            'assigned'         => ['💆', 'Confirmed'],
            'declined'         => ['❌', 'Declined'],
            'completed'        => ['✔', 'Completed'],
            'cancelled'        => ['🚫', 'Cancelled'],
            'refund_requested' => ['💸', 'Refund Pending'],
        ];
        foreach ($status_options as $status):
            [$icon, $label] = $stat_labels[$status];
        ?>
        <div class="stat-card">
            <div style="font-size:1.3rem;"><?php echo $icon; ?></div>
            <div class="stat-number"><?php echo $stats[$status]; ?></div>
            <div class="stat-label"><?php echo $label; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="filter-bar">
        <a href="appointments.php" class="btn <?php echo !$filter_status ? 'btn-primary' : 'btn-secondary'; ?>">All</a>
        <?php foreach ($status_options as $status): ?>
        <a href="appointments.php?filter=<?php echo $status; ?>"
           class="btn <?php echo $filter_status === $status ? 'btn-primary' : 'btn-secondary'; ?>">
            <?php echo $stat_labels[$status][0] . ' ' . $stat_labels[$status][1]; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php
    $status_labels_map = [
        'pending'          => '⏳ Pending Confirmation',
        'approved'         => '🏃 Checked In',
        'assigned'         => '💆 Confirmed',
        'declined'         => '❌ Declined',
        'completed'        => '✔ Completed',
        'cancelled'        => '🚫 Cancelled',
        'refund_requested' => '💸 Refund Pending',
    ];

    function render_therapists(array $appt): void {
        $therapists = $appt['assigned_therapists'] ?? [];
        echo '<div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.5rem;">';
        if (empty($therapists)) {
            echo '<span style="font-size:0.82rem;color:#9a7c68;font-style:italic;">💆 Therapist to be assigned</span>';
        } else {
            foreach ($therapists as $t) {
                $initials = strtoupper(substr($t['full_name'], 0, 1));
                echo '<span class="therapist-pill"><span class="avatar">' . $initials . '</span>';
                echo htmlspecialchars($t['full_name']);
                if (!empty($t['specialties'])) echo ' <span style="opacity:0.7;">· ' . htmlspecialchars($t['specialties']) . '</span>';
                echo '</span>';
            }
        }
        echo '</div>';
    }

    // ── Shared: fetch full order row (price + discount + payment) for an appointment
    function fetch_order_row($conn, $order_item_id): array {
        $default = [
            'id' => null, 'payment_method' => '', 'payment_status' => '',
            'approval_status' => '', 'paymongo_method' => null,
            'total_amount' => 0, 'discount_type' => 'none',
            'discount_amount' => 0, 'final_amount' => 0,
        ];
        if (!$order_item_id) return $default;
        $pm = $GLOBALS['conn']->prepare("
            SELECT o.id, o.payment_method, o.payment_status, o.approval_status,
                   o.paymongo_method,
                   o.total_amount, o.discount_type, o.discount_amount, o.final_amount
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.id = ? LIMIT 1
        ");
        $pm->bind_param("i", $order_item_id); $pm->execute();
        $row = $pm->get_result()->fetch_assoc(); $pm->close();
        return $row ?: $default;
    }
    ?>

    <?php if (!empty($upcoming)): ?>
    <h2 class="section-title">📌 Upcoming Appointments</h2>
    <?php foreach ($upcoming as $appt):
        $border_colors = ['pending'=>'#C96A2C','approved'=>'#198754','assigned'=>'#0070f3','declined'=>'#dc3545','completed'=>'#0F6E56','cancelled'=>'#6b7280','refund_requested'=>'#f59e0b'];
        $border   = $border_colors[$appt['status']] ?? '#C96A2C';
        $orow     = fetch_order_row($conn, $appt['order_item_id'] ?? null);
        $appt_order_id = $orow['id'];
        $pay_method    = $orow['payment_method'];
        $pay_status    = $orow['payment_status'];
        $apvl_status   = $orow['approval_status'];
        $pm_actual     = $orow['paymongo_method'];
    ?>
    <div class="appointment-card" style="border-left-color:<?php echo $border; ?>;">
        <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
            <img src="../uploads/services/<?php echo htmlspecialchars($appt['image']); ?>"
                 style="width:110px;height:110px;object-fit:cover;border-radius:10px;flex-shrink:0;">
            <div style="flex:1;min-width:220px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
                    <h3 style="color:#3B2A1A;margin:0;"><?php echo htmlspecialchars($appt['service_name']); ?></h3>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                        <span class="appointment-status status-<?php echo $appt['status']; ?>">
                            <?php echo $status_labels_map[$appt['status']] ?? ucfirst($appt['status']); ?>
                        </span>
                        <?php if ($pay_method): ?>
                        <?php render_payment_badge($pay_method, $pay_status, $apvl_status, $pm_actual); ?>
                        <span class="payment-badge" style="<?php
                            if ($appt['status'] === 'assigned') echo 'background:#fff3cd;color:#664d03;border:1px solid #ffe69c;';
                            elseif ($appt['status'] === 'approved') echo 'background:#d1e7dd;color:#0a3622;border:1px solid #86efac;';
                            elseif ($pay_status === 'paid' && $apvl_status === 'approved') echo 'background:#d1e7dd;color:#0a3622;';
                            else echo 'background:#e2e3e5;color:#41464b;';
                        ?>">
                            <?php
                            if ($appt['status'] === 'assigned') echo '⏳ Arrive 15-20 mins early';
                            elseif ($appt['status'] === 'approved') echo '✅ Checked in';
                            elseif ($pay_status === 'paid' && $apvl_status === 'pending') echo '⏳ Paid — Awaiting Approval';
                            elseif ($pay_status === 'unpaid') echo '⏳ Pay at counter';
                            else echo ucfirst($pay_status);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.3rem 2rem;font-size:0.92rem;color:#444;">
                    <p><strong>📅 Date & Time:</strong><br><?php echo date('F d, Y — h:i A', strtotime($appt['appointment_date'])); ?></p>
                    <p><strong>⏱ Duration:</strong><br><?php echo $appt['session_time']; ?> minutes</p>
                    <?php render_price_cell(floatval($appt['price']), $orow); ?>
                    <p><strong>👥 People:</strong><br><?php echo $appt['people_count']; ?></p>
                </div>

                <?php render_therapists($appt); ?>

                <!-- Meta pills: booked date, service type, rate -->
                <div style="display:flex;flex-wrap:wrap;gap:0.3rem 1.5rem;margin-top:0.55rem;font-size:0.82rem;color:#6b7280;">
                    <span>📆 Booked <?php echo date('M d, Y', strtotime($appt['created_at'])); ?></span>
                    <?php if (!empty($appt['service_type'])): ?><span>🏠 <?php echo ucfirst(str_replace('_',' ',$appt['service_type'])); ?></span><?php endif; ?>
                    <?php if (!empty($appt['rate_type'])): ?><span>💲 <?php echo ucfirst($appt['rate_type']); ?> rate</span><?php endif; ?>
                </div>

                <details class="card-section">
                    <summary>👤 Your Information</summary>
                    <div class="info-grid">
                        <span class="lbl">Full Name</span><span class="val"><?php echo htmlspecialchars($user_info['full_name'] ?? '—'); ?></span>
                        <span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($user_info['email'] ?? '—'); ?></span>
                        <span class="lbl">Phone</span><span class="val"><?php echo !empty($user_info['phone']) ? htmlspecialchars($user_info['phone']) : '—'; ?></span>
                        <span class="lbl">Address</span><span class="val"><?php echo !empty($user_info['address']) ? htmlspecialchars($user_info['address']) : '—'; ?></span>
                    </div>
                </details>

                <?php if ($pay_method): ?>
                <details class="card-section">
                    <summary>💰 Payment & Pricing</summary>
                    <div class="info-grid">
                        <span class="lbl">Original Price</span>
                        <span class="val">₱<?php echo number_format(floatval($orow['total_amount'] ?: $appt['price']), 2); ?></span>
                        <?php if (!empty($orow['discount_type']) && $orow['discount_type'] !== 'none'): ?>
                        <span class="lbl">Discount</span>
                        <span class="val"><?php echo ucfirst($orow['discount_type']); echo floatval($orow['discount_amount']) > 0 ? ' — ₱'.number_format(floatval($orow['discount_amount']),2) : ' (pending confirmation)'; ?></span>
                        <?php endif; ?>
                        <span class="lbl">Final Amount</span>
                        <span class="val" style="font-weight:700;color:#15803d;">₱<?php echo number_format(floatval($orow['final_amount'] ?: ($orow['total_amount'] ?: $appt['price'])), 2); ?></span>
                        <span class="lbl">Payment Method</span>
                        <span class="val"><?php echo $pm_actual ? ucfirst($pm_actual) : ucfirst($pay_method); ?></span>
                        <span class="lbl">Payment Status</span>
                        <span class="val"><?php echo $pay_status ? ucfirst($pay_status) : '—'; ?></span>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($appt['extra_services'])): ?>
                <details class="card-section">
                    <summary>✨ Extra Services (<?php echo count($appt['extra_services']); ?>)</summary>
                    <div style="margin-top:0.5rem;">
                        <?php foreach ($appt['extra_services'] as $xsvc): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px solid #f3f4f6;font-size:0.87rem;">
                            <div>
                                <span style="color:#3B2A1A;font-weight:600;"><?php echo htmlspecialchars($xsvc['svc_name']); ?></span>
                                <?php if (!empty($xsvc['therapist_name'])): ?><span style="color:#9a7c68;"> · <?php echo htmlspecialchars($xsvc['therapist_name']); ?></span><?php endif; ?>
                            </div>
                            <span style="color:#C96A2C;font-weight:700;">₱<?php echo number_format(floatval($xsvc['price']), 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($appt['status'] === 'pending'): ?>
                <div style="margin-top:0.75rem;padding:0.6rem 0.9rem;background:#fff8f2;border-left:3px solid #f59e0b;border-radius:6px;font-size:0.82rem;color:#92400e;">
                    ⏳ Your appointment is awaiting confirmation. We'll notify you once a therapist is assigned.
                </div>
                <?php elseif ($appt['status'] === 'assigned'): ?>
                <div style="margin-top:0.75rem;padding:0.6rem 0.9rem;background:#fff3cd;border-left:3px solid #ffc107;border-radius:6px;font-size:0.82rem;color:#664d03;">
                    📍 <strong>Reminder:</strong> Please arrive at least 15–20 minutes before your scheduled time.
                </div>
                <?php elseif ($appt['status'] === 'approved'): ?>
                <div style="margin-top:0.75rem;padding:0.6rem 0.9rem;background:#d1e7dd;border-left:3px solid #16a34a;border-radius:6px;font-size:0.82rem;color:#065f46;">
                    🏃 You have been checked in. Your session will begin shortly.
                </div>
                <?php elseif ($appt['status'] === 'completed'): ?>
                <div style="margin-top:0.75rem;padding:0.6rem 0.9rem;background:#f0f9ff;border-left:3px solid #0891b2;border-radius:6px;font-size:0.82rem;color:#0c4a6e;">
                    ✔ Your session is complete. Thank you for visiting Recovery Spa!
                </div>
                <?php endif; ?>

                <?php if ($pay_status === 'paid' && $appt_order_id): ?>
                <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #EAD8C0;">
                    <a href="payment_success.php?order_id=<?php echo $appt_order_id; ?>" class="receipt-btn">🧾 View Receipt</a>
                </div>
                <?php endif; ?>

                <!-- Cancel Button -->
                <?php if (in_array($appt['status'], ['pending','approved','assigned'])): ?>
                <div style="margin-top:0.85rem;padding-top:0.85rem;border-top:1px solid #EAD8C0;">
                    <?php $is_online_paid = ($pay_method === 'online' && $pay_status === 'paid'); ?>
                    <button type="button" class="cancel-btn"
                            onclick="openCancelModal(<?php echo $appt['id']; ?>,'<?php echo htmlspecialchars(addslashes($appt['service_name'])); ?>','<?php echo date('F d, Y h:i A', strtotime($appt['appointment_date'])); ?>',<?php echo $is_online_paid ? 'true' : 'false'; ?>)">
                        🚫 Cancel Appointment
                    </button>
                    <?php if ($is_online_paid): ?>
                    <span style="font-size:0.75rem;color:#854d0e;margin-left:0.5rem;">💸 A refund request will be submitted for owner approval.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($appt['status'] === 'refund_requested'): ?>
                <div style="margin-top:0.75rem;padding:0.65rem 0.9rem;background:#fef9c3;border-radius:8px;border:1px solid #fde047;font-size:0.82rem;color:#854d0e;">
                    💸 <strong>Refund request submitted.</strong> The owner is reviewing your refund. You'll be notified once it's processed.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <h2 class="section-title">🕐 Appointment History</h2>
    <?php foreach ($history as $appt):
        $border_colors = ['pending'=>'#C96A2C','approved'=>'#198754','assigned'=>'#0070f3','declined'=>'#dc3545','completed'=>'#0F6E56','cancelled'=>'#6b7280','refund_requested'=>'#f59e0b'];
        $border   = $border_colors[$appt['status']] ?? '#adb5bd';
        $orow     = fetch_order_row($conn, $appt['order_item_id'] ?? null);
        $appt_order_id = $orow['id'];
        $pay_method    = $orow['payment_method'];
        $pay_status    = $orow['payment_status'];
        $apvl_status   = $orow['approval_status'];
        $pm_actual     = $orow['paymongo_method'];
    ?>
    <div class="appointment-card" style="opacity:0.88;border-left-color:<?php echo $border; ?>;">
        <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
            <img src="../uploads/services/<?php echo htmlspecialchars($appt['image']); ?>"
                 style="width:110px;height:110px;object-fit:cover;border-radius:10px;flex-shrink:0;filter:grayscale(15%);">
            <div style="flex:1;min-width:220px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
                    <h3 style="color:#3B2A1A;margin:0;"><?php echo htmlspecialchars($appt['service_name']); ?></h3>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                        <span class="appointment-status status-<?php echo $appt['status']; ?>"><?php echo $status_labels_map[$appt['status']] ?? ucfirst($appt['status']); ?></span>
                        <?php if ($pay_method): ?>
                        <?php render_payment_badge($pay_method, $pay_status, $apvl_status, $pm_actual); ?>
                        <span class="payment-badge" style="<?php
                            if ($pay_status === 'paid' && $apvl_status === 'approved') echo 'background:#d1e7dd;color:#0a3622;';
                            elseif ($pay_status === 'refunded') echo 'background:#f8d7da;color:#842029;';
                            elseif ($pay_status === 'rejected') echo 'background:#f8d7da;color:#842029;';
                            else echo 'background:#e2e3e5;color:#41464b;';
                        ?>">
                            <?php
                            if ($pay_status === 'paid' && $apvl_status === 'approved') echo '✅ Paid';
                            elseif ($pay_status === 'refunded') echo '↩️ Refunded';
                            elseif ($pay_status === 'rejected') echo '❌ Rejected';
                            elseif ($pay_status === 'unpaid') echo '⏳ Unpaid';
                            else echo ucfirst($pay_status);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.3rem 2rem;font-size:0.92rem;color:#444;">
                    <p><strong>📅 Date & Time:</strong><br><?php echo date('F d, Y — h:i A', strtotime($appt['appointment_date'])); ?></p>
                    <p><strong>⏱ Duration:</strong><br><?php echo $appt['session_time']; ?> minutes</p>
                    <?php render_price_cell(floatval($appt['price']), $orow); ?>
                    <p><strong>👥 People:</strong><br><?php echo $appt['people_count']; ?></p>
                    <p><strong>📆 Booked on:</strong><br><?php echo date('F d, Y', strtotime($appt['created_at'])); ?></p>
                </div>

                <?php render_therapists($appt); ?>

                <!-- Meta pills: service type, rate (booked date already in grid above) -->
                <div style="display:flex;flex-wrap:wrap;gap:0.3rem 1.5rem;margin-top:0.55rem;font-size:0.82rem;color:#6b7280;">
                    <?php if (!empty($appt['service_type'])): ?><span>🏠 <?php echo ucfirst(str_replace('_',' ',$appt['service_type'])); ?></span><?php endif; ?>
                    <?php if (!empty($appt['rate_type'])): ?><span>💲 <?php echo ucfirst($appt['rate_type']); ?> rate</span><?php endif; ?>
                </div>

                <details class="card-section">
                    <summary>👤 Your Information</summary>
                    <div class="info-grid">
                        <span class="lbl">Full Name</span><span class="val"><?php echo htmlspecialchars($user_info['full_name'] ?? '—'); ?></span>
                        <span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($user_info['email'] ?? '—'); ?></span>
                        <span class="lbl">Phone</span><span class="val"><?php echo !empty($user_info['phone']) ? htmlspecialchars($user_info['phone']) : '—'; ?></span>
                        <span class="lbl">Address</span><span class="val"><?php echo !empty($user_info['address']) ? htmlspecialchars($user_info['address']) : '—'; ?></span>
                    </div>
                </details>

                <?php if ($pay_method): ?>
                <details class="card-section">
                    <summary>💰 Payment & Pricing</summary>
                    <div class="info-grid">
                        <span class="lbl">Original Price</span>
                        <span class="val">₱<?php echo number_format(floatval($orow['total_amount'] ?: $appt['price']), 2); ?></span>
                        <?php if (!empty($orow['discount_type']) && $orow['discount_type'] !== 'none'): ?>
                        <span class="lbl">Discount</span>
                        <span class="val"><?php echo ucfirst($orow['discount_type']); echo floatval($orow['discount_amount']) > 0 ? ' — ₱'.number_format(floatval($orow['discount_amount']),2) : ' (pending confirmation)'; ?></span>
                        <?php endif; ?>
                        <span class="lbl">Final Amount</span>
                        <span class="val" style="font-weight:700;color:#15803d;">₱<?php echo number_format(floatval($orow['final_amount'] ?: ($orow['total_amount'] ?: $appt['price'])), 2); ?></span>
                        <span class="lbl">Payment Method</span>
                        <span class="val"><?php echo $pm_actual ? ucfirst($pm_actual) : ucfirst($pay_method); ?></span>
                        <span class="lbl">Payment Status</span>
                        <span class="val"><?php echo $pay_status ? ucfirst($pay_status) : '—'; ?></span>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($appt['extra_services'])): ?>
                <details class="card-section">
                    <summary>✨ Extra Services (<?php echo count($appt['extra_services']); ?>)</summary>
                    <div style="margin-top:0.5rem;">
                        <?php foreach ($appt['extra_services'] as $xsvc): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px solid #f3f4f6;font-size:0.87rem;">
                            <div>
                                <span style="color:#3B2A1A;font-weight:600;"><?php echo htmlspecialchars($xsvc['svc_name']); ?></span>
                                <?php if (!empty($xsvc['therapist_name'])): ?><span style="color:#9a7c68;"> · <?php echo htmlspecialchars($xsvc['therapist_name']); ?></span><?php endif; ?>
                            </div>
                            <span style="color:#C96A2C;font-weight:700;">₱<?php echo number_format(floatval($xsvc['price']), 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($appt['status'] === 'cancelled' && !empty($appt['cancel_reason'])): ?>
                <div style="margin-top:0.65rem;padding:0.55rem 0.85rem;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:0.82rem;color:#374151;">
                    🚫 <strong>Cancellation reason:</strong> <?php echo htmlspecialchars($appt['cancel_reason']); ?>
                </div>
                <?php endif; ?>

                <?php if ($appt['status'] === 'refund_requested'): ?>
                <div style="margin-top:0.65rem;padding:0.55rem 0.85rem;background:#fef9c3;border-radius:8px;border:1px solid #fde047;font-size:0.82rem;color:#854d0e;">
                    💸 <strong>Refund pending.</strong> Owner is reviewing your request.
                    <?php if (!empty($appt['cancel_reason'])): ?> Reason: <?php echo htmlspecialchars($appt['cancel_reason']); ?><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($appt['status'] === 'completed'):
                    $fb = $conn->prepare("SELECT rating FROM feedback WHERE appointment_id = ? AND user_id = ?");
                    $fb->bind_param("ii", $appt['id'], $user_id); $fb->execute();
                    $fb_row = $fb->get_result()->fetch_assoc(); $fb->close();
                ?>
                <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #EAD8C0;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                    <?php if ($fb_row): ?>
                    <div class="feedback-stars">
                        <span style="font-size:0.82rem;color:#888;">Your rating:</span>
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <span style="font-size:1.15rem;color:<?php echo $s <= $fb_row['rating'] ? '#f59e0b' : '#e5e7eb'; ?>;">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <?php else: ?>
                    <a href="feedback.php?type=appointment&id=<?php echo $appt['id']; ?>" class="feedback-btn">⭐ Leave Feedback</a>
                    <?php endif; ?>
                    <?php if ($pay_status === 'paid' && $appt_order_id): ?>
                    <a href="payment_success.php?order_id=<?php echo $appt_order_id; ?>" class="receipt-btn">🧾 View Receipt</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($pay_status === 'paid' && $appt_order_id && $appt['status'] !== 'completed'): ?>
                <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #EAD8C0;">
                    <a href="payment_success.php?order_id=<?php echo $appt_order_id; ?>" class="receipt-btn">🧾 View Receipt</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <?php if (empty($appointments)): ?>
    <div style="text-align:center;padding:3rem;background:#fff;border-radius:10px;">
        <div style="font-size:3rem;margin-bottom:1rem;">📅</div>
        <h2 style="color:#3B2A1A;margin-bottom:0.5rem;">No Appointments Yet</h2>
        <p style="color:#666;margin-bottom:1.5rem;">You haven't booked any services yet.</p>
        <a href="index.php#services" class="btn btn-primary">Browse Services</a>
    </div>
    <?php endif; ?>
</div>

<!-- ── PRODUCT ORDERS TAB ─────────────────────────────────────────────────── -->
<div class="tab-panel" id="tab-orders">
    <?php if (!empty($grouped_orders)):
        $paid_count = 0; $unpaid_count = 0; $total_spent = 0;
        foreach ($grouped_orders as $o) {
            if (in_array($o['approval_status'] ?? '', ['approved','completed'])) $paid_count++;
            else $unpaid_count++;
            $total_spent += $o['total_amount'];
        }
    ?>
    <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
        <div class="stat-card"><div class="stat-number"><?php echo count($grouped_orders); ?></div><div class="stat-label">Total Orders</div></div>
        <div class="stat-card"><div class="stat-number" style="color:#198754;"><?php echo $paid_count; ?></div><div class="stat-label">✅ Confirmed</div></div>
        <div class="stat-card"><div class="stat-number" style="color:#664d03;"><?php echo $unpaid_count; ?></div><div class="stat-label">⏳ Pending</div></div>
        <div class="stat-card"><div class="stat-number" style="font-size:1.3rem;">₱<?php echo number_format($total_spent, 2); ?></div><div class="stat-label">💰 Total Spent</div></div>
    </div>

    <?php foreach ($grouped_orders as $order):
        $pstatus = $order['payment_status']  ?? 'unpaid';
        $apvl    = $order['approval_status'] ?? '';
        $method  = $order['payment_method']  ?? 'onsite';
        if ($apvl === 'completed') { $badge_style = 'background:#0dcaf0;color:#fff;'; $badge_label = '📦 Completed — Item Picked Up'; }
        elseif ($apvl === 'approved') {
            if ($method === 'online') { $badge_style = 'background:#d1e7dd;color:#0a3622;'; $badge_label = '✅ Paid — Ready for Pickup'; }
            else { $badge_style = 'background:#fff3cd;color:#664d03;'; $badge_label = '✅ Ready for Pickup — 🏪 Pay at Shop'; }
        } elseif ($pstatus === 'paid' && $apvl === 'pending') { $badge_style = 'background:#fff3cd;color:#664d03;'; $badge_label = '⏳ Paid — Awaiting Approval'; }
        elseif ($apvl === 'declined' || $pstatus === 'refunded') { $badge_style = 'background:#f8d7da;color:#842029;'; $badge_label = ($pstatus === 'refunded') ? '↩️ Refunded' : '❌ Declined'; }
        elseif ($apvl === 'cancelled') { $badge_style = 'background:#f3f4f6;color:#374151;'; $badge_label = '🚫 Cancelled'; }
        else { $badge_style = 'background:#fff3cd;color:#664d03;'; $badge_label = ($method === 'online') ? '⏳ Awaiting Online Payment' : '⏳ Unpaid — Pay at counter'; }
        $order_border = in_array($apvl, ['approved','completed']) ? '#198754' : ($apvl === 'cancelled' ? '#6b7280' : '#C96A2C');
        $first_image  = $order['items'][0]['product_image'] ?? null;
        $item_count   = count($order['items']);
        $card_title   = $item_count === 1 ? $order['items'][0]['product_name'] : $item_count . ' Products';
    ?>
    <div class="order-card" style="border-left-color:<?php echo $order_border; ?>;">
        <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
            <!-- Card image: first product -->
            <?php if ($first_image): ?>
            <img src="../uploads/products/<?php echo htmlspecialchars($first_image); ?>"
                 style="width:110px;height:110px;object-fit:cover;border-radius:10px;flex-shrink:0;">
            <?php else: ?>
            <div style="width:110px;height:110px;border-radius:10px;flex-shrink:0;background:#EAD8C0;display:flex;align-items:center;justify-content:center;font-size:2.5rem;">🛍️</div>
            <?php endif; ?>

            <div style="flex:1;min-width:220px;">
                <!-- Header row: title + status badge -->
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
                    <div>
                        <h3 style="color:#3B2A1A;margin:0;font-size:1rem;"><?php echo htmlspecialchars($card_title); ?></h3>
                        <span style="font-size:0.82rem;color:#9a7c68;">Ordered <?php echo date('F d, Y h:i A', strtotime($order['order_date'])); ?></span>
                    </div>
                    <span id="order-status-<?php echo $order['order_id']; ?>" class="payment-badge" style="<?php echo $badge_style; ?>"><?php echo $badge_label; ?></span>
                </div>

                <!-- Quick info row -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.3rem 2rem;font-size:0.92rem;color:#444;margin-bottom:0.25rem;">
                    <p><strong>💳 Payment:</strong><br>
                        <?php echo $method === 'online' ? '🌐 Online' : '🏪 Onsite'; ?>
                        <?php
                        $pm_icon_map = ['cash'=>'💵 Cash','gcash'=>'📱 GCash','maya'=>'💜 Maya','qrph'=>'📷 QRPH','bank'=>'🏦 Bank'];
                        if ($method !== 'online' && isset($pm_icon_map[$method])) echo ' · ' . $pm_icon_map[$method];
                        ?>
                    </p>
                    <p><strong>💰 Total:</strong><br>
                        <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none' && $order['discount_amount'] > 0): ?>
                        <span style="text-decoration:line-through;color:#9ca3af;font-size:0.85rem;">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                        <span style="color:#15803d;font-weight:800;"> ₱<?php echo number_format($order['final_amount'] ?: $order['total_amount'], 2); ?></span>
                        <?php else: ?>
                        ₱<?php echo number_format($order['total_amount'], 2); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Products collapsible -->
                <details class="card-section">
                    <summary>🛍️ Products in this order (<?php echo $item_count; ?>)</summary>
                    <div style="margin-top:0.5rem;">
                        <?php foreach ($order['items'] as $item): ?>
                        <div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem 0;border-bottom:1px solid #f3f4f6;">
                            <?php if ($item['product_image']): ?>
                            <img src="../uploads/products/<?php echo htmlspecialchars($item['product_image']); ?>"
                                 style="width:60px;height:60px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                            <?php else: ?>
                            <div style="width:60px;height:60px;border-radius:8px;flex-shrink:0;background:#EAD8C0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">📦</div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;color:#3B2A1A;font-size:0.9rem;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                <div style="font-size:0.82rem;color:#9a7c68;">×<?php echo $item['quantity']; ?> &nbsp;·&nbsp; ₱<?php echo number_format($item['price'], 2); ?> each</div>
                            </div>
                            <div style="font-weight:700;color:#C96A2C;white-space:nowrap;font-size:0.92rem;">₱<?php echo number_format($item['subtotal'], 2); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <div style="display:flex;justify-content:flex-end;align-items:baseline;gap:0.4rem;padding-top:0.5rem;font-size:0.9rem;font-weight:700;color:#3B2A1A;">
                            Total:
                            <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none' && $order['discount_amount'] > 0): ?>
                            <span style="text-decoration:line-through;color:#9ca3af;font-size:0.82rem;">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                            <span style="color:#15803d;">₱<?php echo number_format($order['final_amount'] ?: $order['total_amount'], 2); ?></span>
                            <span style="font-size:0.72rem;color:#92400e;">−₱<?php echo number_format($order['discount_amount'], 2); ?> <?php echo ucfirst($order['discount_type']); ?></span>
                            <?php else: ?>
                            <span>₱<?php echo number_format($order['total_amount'], 2); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

                <!-- Your Information collapsible -->
                <details class="card-section">
                    <summary>👤 Your Information</summary>
                    <div class="info-grid">
                        <span class="lbl">Full Name</span><span class="val"><?php echo htmlspecialchars($user_info['full_name'] ?? '—'); ?></span>
                        <span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($user_info['email'] ?? '—'); ?></span>
                        <span class="lbl">Phone</span><span class="val"><?php echo !empty($user_info['phone']) ? htmlspecialchars($user_info['phone']) : '—'; ?></span>
                        <span class="lbl">Address</span><span class="val"><?php echo !empty($user_info['address']) ? htmlspecialchars($user_info['address']) : '—'; ?></span>
                    </div>
                </details>

                <!-- Footer: cancel + feedback + receipt -->
                <div style="margin-top:0.85rem;padding-top:0.75rem;border-top:1px solid #EAD8C0;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between;">
                    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;">
                        <?php if ($apvl === 'pending'): ?>
                        <button type="button" class="cancel-btn" onclick="openCancelOrderModal(<?php echo $order['order_id']; ?>)">
                            🚫 Cancel Order
                        </button>
                        <?php endif; ?>

                        <?php if ($apvl === 'completed'):
                            $fb = $conn->prepare("SELECT rating FROM feedback WHERE order_id = ? AND user_id = ? AND appointment_id IS NULL");
                            $fb->bind_param("ii", $order['order_id'], $user_id); $fb->execute();
                            $fb_row = $fb->get_result()->fetch_assoc(); $fb->close();
                        ?>
                            <?php if ($fb_row): ?>
                            <div class="feedback-stars">
                                <span style="font-size:0.82rem;color:#888;">Your rating:</span>
                                <?php for ($s = 1; $s <= 5; $s++): ?><span style="font-size:1.15rem;color:<?php echo $s <= $fb_row['rating'] ? '#f59e0b' : '#e5e7eb'; ?>;">&#9733;</span><?php endfor; ?>
                            </div>
                            <?php else: ?>
                            <a href="feedback.php?type=order&id=<?php echo $order['order_id']; ?>" class="feedback-btn">⭐ Leave Feedback</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($pstatus === 'paid'): ?>
                    <a href="payment_success.php?order_id=<?php echo $order['order_id']; ?>" class="receipt-btn">🧾 View Receipt</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <div style="text-align:center;padding:3rem;background:#fff;border-radius:10px;">
        <div style="font-size:3rem;margin-bottom:1rem;">🛍️</div>
        <h2 style="color:#3B2A1A;">No Orders Yet</h2>
        <p style="color:#666;margin-bottom:1.5rem;">You haven't purchased any products yet.</p>
        <a href="index.php#products" class="btn btn-primary">Browse Products</a>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Cancel Order Modal -->
<div class="modal-overlay" id="cancelOrderModal">
    <div class="modal-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <h3 style="margin:0;color:#991b1b;font-size:1.1rem;">🚫 Cancel Order</h3>
            <button onclick="closeCancelOrderModal()" style="border:none;background:none;font-size:1.3rem;cursor:pointer;color:#9ca3af;line-height:1;">✕</button>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.83rem;color:#991b1b;">
            ⚠️ <strong>Are you sure?</strong> This action cannot be undone.
        </div>
        <form method="POST" id="cancelOrderForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"   value="cancel_order">
            <input type="hidden" name="order_id" id="cancel-order-id" value="">
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Reason <span style="font-weight:400;color:#9ca3af;">(optional)</span>
                </label>
                <textarea name="cancel_reason" id="cancel-order-reason" rows="3"
                          placeholder="e.g. Changed mind, wrong item..."
                          style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;color:#374151;resize:vertical;box-sizing:border-box;font-family:inherit;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="closeCancelOrderModal()" style="padding:0.55rem 1.25rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:0.88rem;font-weight:600;cursor:pointer;">Keep Order</button>
                <button type="submit" style="padding:0.55rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;font-size:0.88rem;font-weight:600;cursor:pointer;">🚫 Yes, Cancel Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <h3 style="margin:0;color:#991b1b;font-size:1.1rem;">🚫 Cancel Appointment</h3>
            <button onclick="closeCancelModal()" style="border:none;background:none;font-size:1.3rem;cursor:pointer;color:#9ca3af;line-height:1;">✕</button>
        </div>
        <div style="background:#f9fafb;border-radius:10px;padding:1rem;margin-bottom:1.25rem;border:1px solid #e5e7eb;font-size:0.9rem;color:#374151;">
            <div style="font-weight:700;color:#3B2A1A;margin-bottom:0.3rem;" id="modal-service-name"></div>
            <div style="color:#6b7280;" id="modal-appt-date"></div>
        </div>
        <div id="modal-warning" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.83rem;color:#991b1b;">
            ⚠️ <strong>Are you sure?</strong> This action cannot be undone.
        </div>
        <form method="POST" id="cancelForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"  value="customer_cancel">
            <input type="hidden" name="appt_id" id="modal-appt-id" value="">
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Reason <span style="font-weight:400;color:#9ca3af;">(optional)</span>
                </label>
                <textarea name="cancel_reason" id="modal-cancel-reason" rows="3"
                          placeholder="e.g. Change of plans, emergency..."
                          style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;color:#374151;resize:vertical;box-sizing:border-box;font-family:inherit;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="closeCancelModal()" style="padding:0.55rem 1.25rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:0.88rem;font-weight:600;cursor:pointer;">Keep Appointment</button>
                <button type="submit" style="padding:0.55rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;font-size:0.88rem;font-weight:600;cursor:pointer;">🚫 Yes, Cancel It</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal(apptId, serviceName, apptDate, isPaidOnline = false) {
    document.getElementById('modal-appt-id').value            = apptId;
    document.getElementById('modal-service-name').textContent = serviceName;
    document.getElementById('modal-appt-date').textContent    = '📅 ' + apptDate;
    document.getElementById('modal-cancel-reason').value      = '';
    const warning = document.getElementById('modal-warning');
    if (isPaidOnline) {
        warning.innerHTML = '💸 <strong>This appointment was paid online.</strong> Cancelling will submit a refund request to the owner.';
        warning.style.cssText = 'background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.83rem;color:#854d0e;';
    } else {
        warning.innerHTML = '⚠️ <strong>Are you sure?</strong> This action cannot be undone.';
        warning.style.cssText = 'background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.83rem;color:#991b1b;';
    }
    document.getElementById('cancelModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('cancelModal').addEventListener('click', function(e) { if (e.target === this) closeCancelModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeCancelModal(); });

window.addEventListener('DOMContentLoaded', () => {
    const savedTab = localStorage.getItem('activeTab');
    if (savedTab) {
        const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick')?.includes(savedTab));
        if (btn) switchTab(savedTab, btn);
    }
});
function switchTab(tabName, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    btn.classList.add('active');
    localStorage.setItem('activeTab', tabName);
    // Dismiss the pulse dot once the user actually views product orders
    if (tabName === 'orders') {
        const dot = document.getElementById('orders-notify-dot');
        if (dot) dot.style.display = 'none';
    }
}
function updateOrderStatuses() {
    fetch('appointments.php?ajax_check_status=1').then(r => r.json()).then(data => {
        if (!data.orders) return;
        data.orders.forEach(order => {
            const badge = document.getElementById('order-status-' + order.id);
            if (!badge) return;
            let newText = '', newStyle = '';
            if (order.approval_status === 'completed') { newText = '📦 Completed — Item Picked Up'; newStyle = 'background:#0dcaf0;color:#fff;'; }
            else if (order.approval_status === 'approved') {
                if (order.payment_method === 'online') { newText = '✅ Paid — Ready for Pickup'; newStyle = 'background:#d1e7dd;color:#0a3622;'; }
                else { newText = '✅ Ready for Pickup — 🏪 Pay at Shop'; newStyle = 'background:#fff3cd;color:#664d03;'; }
            } else if (order.approval_status === 'declined' || order.payment_status === 'refunded') {
                newText = order.payment_status === 'refunded' ? '↩️ Refunded' : '❌ Declined'; newStyle = 'background:#f8d7da;color:#842029;';
            } else if (order.payment_status === 'paid' && order.approval_status === 'pending') {
                newText = '⏳ Paid — Awaiting Approval'; newStyle = 'background:#fff3cd;color:#664d03;';
            } else {
                newText = order.payment_method === 'online' ? '⏳ Awaiting Online Payment' : '⏳ Unpaid — Pay at counter';
                newStyle = 'background:#fff3cd;color:#664d03;';
            }
            if (badge.textContent.trim() !== newText) {
                badge.textContent = newText;
                badge.style.cssText = newStyle + 'padding:0.3rem 0.8rem;border-radius:20px;font-size:0.82rem;font-weight:bold;';
                const card = badge.closest('.order-card');
                if (card) { card.style.transition = 'background-color 0.5s'; card.style.backgroundColor = '#fff9db'; setTimeout(() => card.style.backgroundColor = '#fff', 2000); }
            }
        });
    }).catch(err => console.error('Real-time update error:', err));
}
setInterval(updateOrderStatuses, 3000);

function openCancelOrderModal(orderId) {
    document.getElementById('cancel-order-id').value    = orderId;
    document.getElementById('cancel-order-reason').value = '';
    document.getElementById('cancelOrderModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCancelOrderModal() {
    document.getElementById('cancelOrderModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('cancelOrderModal').addEventListener('click', function(e) { if (e.target === this) closeCancelOrderModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeCancelOrderModal(); });
</script>

<footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand"><div class="ft-logo">RECOVERY ILOILO</div><p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p></div>
        <div class="footer-col"><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="#services">Services</a></li><li><a href="#products">Products</a></li><li><a href="#about">About Us</a></li><li><a href="#contact">Contact</a></li></ul></div>
        <div class="footer-col"><h4>Services</h4><ul><li><a href="#services">Massage Therapy</a></li><li><a href="#services">Nail Care</a></li><li><a href="#services">Lash Services</a></li><li><a href="#services">Facial Treatments</a></li><li><a href="#services">Body Scrubs</a></li></ul></div>
        <div class="footer-col"><h4>Contact</h4><ul><li><a href="#contact">Iloilo City, Philippines</a></li><li><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></li><li><a href="tel:+639853359998">+639853359998</a></li><li><a href="#contact">Mon – Sun: 10AM – 10PM</a></li></ul></div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>