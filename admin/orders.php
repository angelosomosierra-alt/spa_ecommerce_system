<?php
require_once '../config.php';
redirect_if_not_admin();

// ── AJAX: VERIFY PIN ──────────────────────────────────────────────────────────
if (isset($_POST['verify_pin_only'])) {
    header('Content-Type: application/json');
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['admin_role'] ?? '';
    if ($role !== 'cashier') {
        echo json_encode(['ok' => true, 'name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin']);
        exit();
    }
    $entered = trim($_POST['pin'] ?? '');
    if (!ctype_digit($entered) || strlen($entered) !== 4) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PIN format.']); exit();
    }
    // FIXED: Bug 1 — query receptionist_pins by entered PIN, not users.cashier_pin
    $stmt = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
    $stmt->bind_param("s", $entered); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect PIN. Try again.']); exit();
    }
    echo json_encode(['ok' => true, 'name' => $row['full_name']]);
    exit();
}

// Cashier access denied to non-approval pages — redirect to orders
if (is_cashier() && isset($_GET['access_denied'])) {
    // just fall through to show orders page
}

// ─── APPROVE ──────────────────────────────────────────────────────────────────
if (isset($_GET['approve_order'])) {
    $id          = intval($_GET['approve_order']);
    $approver_id = (int)$_SESSION['user_id'];
    $approver_nm = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by_name VARCHAR(120) NULL");

    $stmt = $conn->prepare("SELECT payment_method, payment_status, user_id, total_amount FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($o) {
        $stmt = $conn->prepare("UPDATE orders SET approval_status='approved', approved_by=?, approved_by_name=? WHERE id=?");
        $stmt->bind_param("isi", $approver_id, $approver_nm, $id); $stmt->execute(); $stmt->close();

        if (in_array($o['payment_method'], ['onsite','cash','gcash','maya','qrph','bank','card'])) {
            $stmt = $conn->prepare("UPDATE orders SET payment_status='paid' WHERE id=?");
            $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='approved', a.approved_by=?, a.approved_by_name=? WHERE oi.order_id=?");
        $stmt->bind_param("isi", $approver_id, $approver_nm, $id); $stmt->execute(); $stmt->close();

        require_once __DIR__ . '/../notify.php';
        add_notification($conn, $o['user_id'], 'status',
            '📦 Order Ready for Pick Up!',
            'Your order #' . $id . ' (₱' . number_format($o['total_amount'],2) . ') has been approved and is ready for pick up!',
            'appointments.php#orders'
        );
    }
    header("Location: orders.php?msg=approved"); exit();
}

// ─── COMPLETE ─────────────────────────────────────────────────────────────────
if (isset($_GET['complete_order'])) {
    $id         = intval($_GET['complete_order']);
    $updater_nm = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by_name VARCHAR(120) NULL");

    $stmt = $conn->prepare("SELECT user_id, total_amount FROM orders WHERE id=?");
    $stmt->bind_param("i", $id); $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($o) {
        $stmt = $conn->prepare("UPDATE orders SET approval_status='completed', payment_status='paid', updated_by_name=? WHERE id=? AND approval_status='approved'");
        $stmt->bind_param("si", $updater_nm, $id); $stmt->execute(); $stmt->close();

        require_once __DIR__ . '/../notify.php';
        add_notification($conn, $o['user_id'], 'status',
            '🎉 Order Completed!',
            'Your order #' . $id . ' (₱' . number_format($o['total_amount'],2) . ') has been marked as completed. Thank you!',
            'appointments.php#orders'
        );
    }
    header("Location: orders.php?msg=completed"); exit();
}

// ─── DECLINE (onsite) ─────────────────────────────────────────────────────────
if (isset($_GET['reject_order'])) {
    $id         = intval($_GET['reject_order']);
    $updater_nm = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by_name VARCHAR(120) NULL");

    $stmt = $conn->prepare("SELECT user_id, total_amount FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc(); $stmt->close();

    $stmt = $conn->prepare("UPDATE orders SET payment_status='rejected', approval_status='declined', updated_by_name=? WHERE id=?");
    $stmt->bind_param("si",$updater_nm,$id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='declined' WHERE oi.order_id=?");
    $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();

    if ($o) {
        require_once __DIR__ . '/../notify.php';
        add_notification($conn, $o['user_id'], 'status',
            '❌ Order Declined',
            'Your order #' . $id . ' has been declined by the admin.',
            'appointments.php#orders'
        );
    }
    header("Location: orders.php?msg=rejected"); exit();
}

// ─── DECLINE + REFUND (online) ────────────────────────────────────────────────
if (isset($_GET['decline_online'])) {
    $id = intval($_GET['decline_online']);

    $stmt = $conn->prepare("SELECT paymongo_link_id, total_amount, payment_status, user_id FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc(); $stmt->close();

    $refund_attempted = false;
    $refund_success   = false;
    $refund_error     = '';

    if ($order && !empty($order['paymongo_link_id']) && $order['payment_status'] === 'paid') {
        $refund_attempted = true;

        $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($order['paymongo_link_id']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ]]);
        $link_res   = json_decode(curl_exec($ch), true); curl_close($ch);
        $payment_id = $link_res['data']['attributes']['payments'][0]['id'] ?? null;

        if ($payment_id) {
            $amount_cents   = intval($order['total_amount'] * 100);
            $refund_payload = json_encode(['data'=>['attributes'=>[
                'amount'     => $amount_cents,
                'payment_id' => $payment_id,
                'reason'     => 'others',
                'notes'      => 'Declined by Recovery Iloilo admin.',
            ]]]);
            $ch = curl_init('https://api.paymongo.com/v1/refunds');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$refund_payload,
                CURLOPT_HTTPHEADER=>[
                    'Content-Type: application/json','Accept: application/json',
                    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                ],
            ]);
            $refund_res  = json_decode(curl_exec($ch), true);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

            if ($http_status === 200) {
                $refund_success = true;
            } else {
                $refund_error = $refund_res['errors'][0]['detail'] ?? 'Refund API error (HTTP ' . $http_status . ').';
            }
        } else {
            $refund_error = 'Could not retrieve payment ID from PayMongo.';
        }
    }

    $new_pay_status = $refund_success ? 'refunded' : $order['payment_status'];
    $stmt = $conn->prepare("UPDATE orders SET payment_status=?, approval_status='declined' WHERE id=?");
    $stmt->bind_param("si", $new_pay_status, $id); $stmt->execute(); $stmt->close();

    // Restore product stock
    $items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
    $items->bind_param("i",$id); $items->execute();
    foreach ($items->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
        $upd = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id=?");
        $upd->bind_param("ii", $item['quantity'], $item['product_id']); $upd->execute(); $upd->close();
    }
    $items->close();

    $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='declined' WHERE oi.order_id=?");
    $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();

    if (!empty($order['user_id'])) {
        require_once __DIR__ . '/../notify.php';
        $notif_msg = $refund_success
            ? 'Your order #' . $id . ' was declined and ₱' . number_format($order['total_amount'],2) . ' has been refunded.'
            : 'Your order #' . $id . ' has been declined by the admin.';
        add_notification($conn, $order['user_id'], 'status',
            $refund_success ? '↩️ Order Declined & Refunded' : '❌ Order Declined',
            $notif_msg,
            'appointments.php#orders'
        );
    }

    if (!$refund_attempted)   { header("Location: orders.php?msg=declined_no_refund"); exit(); }
    elseif ($refund_success)  { header("Location: orders.php?msg=refunded");           exit(); }
    else                      { header("Location: orders.php?msg=declined_refund_failed&err=" . urlencode($refund_error)); exit(); }
}

// ─── VIEW ORDER ───────────────────────────────────────────────────────────────
$view_order = null;
if (isset($_GET['view'])) {
    $id   = intval($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $view_order = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($view_order) {
        $stmt = $conn->prepare("SELECT oi.*, p.name as item_name, p.image as item_image FROM order_items oi INNER JOIN products p ON oi.product_id=p.id WHERE oi.order_id=? AND oi.product_id IS NOT NULL");
        $stmt->bind_param("i",$id); $stmt->execute();
        $view_order['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

// ─── STATS ────────────────────────────────────────────────────────────────────
$paid_count            = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.payment_status='paid' AND o.approval_status='approved'")->fetch_assoc()['c'] ?? 0;
$pending_count         = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.approval_status='pending' AND o.payment_status != 'pending_payment'")->fetch_assoc()['c'] ?? 0;
$rejected_count        = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.approval_status='declined'")->fetch_assoc()['c'] ?? 0;
$completed_count       = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.approval_status='completed'")->fetch_assoc()['c'] ?? 0;
$pending_payment_count = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.payment_status='pending_payment'")->fetch_assoc()['c'] ?? 0;
$total_revenue         = $conn->query("SELECT IFNULL(SUM(o.total_amount),0) as t FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.payment_status='paid' AND o.approval_status IN ('approved','completed')")->fetch_assoc()['t'] ?? 0;

// ─── FETCH ORDERS ─────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$orders = [];
$result = $conn->query("
    SELECT DISTINCT o.* FROM orders o
    INNER JOIN order_items oi ON o.id = oi.order_id
    WHERE oi.product_id IS NOT NULL
      AND o.payment_status != 'pending_payment'
    ORDER BY o.created_at DESC
");
while ($row = $result->fetch_assoc()) $orders[] = $row;

// ─── LABEL HELPERS ────────────────────────────────────────────────────────────
$status_labels = [
    'paid'            => '✅ Paid',
    'unpaid'          => '⏳ Unpaid',
    'rejected'        => '❌ Rejected',
    'refunded'        => '↩️ Refunded',
    'pending_payment' => '💳 Awaiting Payment',
    'cancelled'       => '🚫 Cancelled',
];
$approval_labels = [
    'pending'   => '🕐 Pending Approval',
    'approved'  => '📦 Ready for Pick Up',
    'declined'  => '❌ Declined',
    'completed' => '🎉 Completed',
];

$page_title  = $view_order ? 'Order #' . $view_order['id'] : 'Orders';
$page_icon   = '📦';
$active_page = 'orders';
require_once 'admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?php echo in_array($_GET['msg'],['approved','refunded','completed'])?'success':(str_contains($_GET['msg'],'failed')?'danger':'warning'); ?>" style="margin-bottom:1.5rem;">
    <?php
    $msgs = [
        'approved'               => '📦 Order approved! Customer has been notified that their order is ready for pick up.',
        'completed'              => '🎉 Order marked as completed! Transaction is done.',
        'rejected'               => '❌ Order declined.',
        'refunded'               => '↩️ Order declined and full refund issued to customer via PayMongo.',
        'declined_no_refund'     => '❌ Order declined. No payment was collected so no refund was needed.',
        'declined_refund_failed' => '⚠️ Order declined, but the automatic refund failed: <strong>' . htmlspecialchars($_GET['err'] ?? '') . '</strong>. Please issue the refund manually in your <a href="https://dashboard.paymongo.com" target="_blank" style="color:inherit;font-weight:bold;">PayMongo dashboard</a>.',
    ];
    echo $msgs[$_GET['msg']] ?? '';
    ?>
</div>
<?php endif; ?>

<?php if ($view_order): ?>
<!-- ── ORDER DETAIL ───────────────────────────────────────────────────────── -->
<div style="margin-bottom:1rem;">
    <a href="orders.php" class="btn btn-secondary">← Back to Orders</a>
</div>

<?php
$vps = $view_order['payment_status']  ?? 'unpaid';
$vpm = $view_order['payment_method']  ?? 'onsite';
$vas = $view_order['approval_status'] ?? 'pending';
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
    <div class="panel">
        <div class="panel-header"><span class="panel-title">👤 Customer</span></div>
        <div class="panel-body">
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                <?php foreach(['customer_name'=>'Name','email'=>'Email','phone'=>'Phone','address'=>'Address'] as $k=>$l): ?>
                <div style="display:flex;gap:0.75rem;">
                    <span style="color:var(--gray);font-size:0.78rem;width:60px;flex-shrink:0;padding-top:2px;"><?php echo $l; ?></span>
                    <span><?php echo htmlspecialchars($view_order[$k]??'—'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🧾 Order Info</span>
        </div>
        <div class="panel-body">
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Order ID</span>
                    <strong style="color:var(--gold);">#<?php echo $view_order['id']; ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Date</span>
                    <span style="font-size:0.85rem;"><?php echo date('M d, Y h:i A', strtotime($view_order['created_at'])); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Total</span>
                    <strong style="color:var(--rust);font-size:1.2rem;">₱<?php echo number_format($view_order['total_amount'],2); ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Payment</span>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                        <?php
                        $vpm_icons = ['cash'=>'💵 Cash','gcash'=>'📱 GCash','maya'=>'💜 Maya',
                                      'qrph'=>'📷 QRPH','bank'=>'🏦 Bank','card'=>'💳 Card',
                                      'online'=>'💳 Online','onsite'=>'🏪 Onsite'];
                        $vpm_class = isset($vpm_icons[$vpm]) ? $vpm : 'onsite';
                        ?>
                        <span class="badge badge-<?php echo $vpm_class; ?>">
                            <?php echo $vpm_icons[$vpm] ?? ('💰 '.ucfirst($vpm)); ?>
                        </span>
                        <span class="badge badge-<?php echo $vps; ?>" style="font-size:0.7rem;">
                            <?php echo $status_labels[$vps] ?? ucfirst($vps); ?>
                        </span>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Status</span>
                    <span class="badge badge-<?php echo $vas; ?>">
                        <?php echo $approval_labels[$vas] ?? ucfirst($vas); ?>
                    </span>
                </div>
            </div>

            <!-- ── ACTION BUTTONS ──────────────────────────────────────────── -->
            <div style="display:flex;flex-direction:column;gap:0.6rem;margin-top:1.25rem;">

            <?php if ($vas === 'pending'): ?>

                <?php if (in_array($vpm, ['onsite','cash','gcash','maya','qrph','bank','card']) && $vps === 'unpaid'): ?>
                <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:var(--gray);margin-bottom:0.25rem;">
                    🏪 Onsite payment — approve to mark as ready for pick up. Payment collected when customer arrives.
                </div>
                <button type="button" class="btn btn-success btn-full"
                        onclick="openOrderPinModal('approve_order','<?php echo $view_order['id']; ?>','📦 Approve Order #<?php echo $view_order['id']; ?> — mark ready for pick up?')">
                    📦 Approve — Mark as Ready for Pick Up
                </button>
                <button type="button" class="btn btn-danger btn-full"
                        onclick="openOrderPinModal('reject_order','<?php echo $view_order['id']; ?>','❌ Decline Order #<?php echo $view_order['id']; ?>?')">
                    ❌ Decline Order
                </button>

                <?php elseif ($vpm === 'online' && $vps === 'paid'): ?>
                <div style="background:var(--gold-dim);border:1px solid var(--gold);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:var(--brown);margin-bottom:0.25rem;">
                    💳 Customer has paid <strong>₱<?php echo number_format($view_order['total_amount'],2); ?></strong> via PayMongo.
                </div>
                <button type="button" class="btn btn-success btn-full"
                        onclick="openOrderPinModal('approve_order','<?php echo $view_order['id']; ?>','📦 Approve Order #<?php echo $view_order['id']; ?> — notify customer it\'s ready?')">
                    📦 Approve — Mark as Ready for Pick Up
                </button>
                <button type="button" class="btn btn-danger btn-full"
                        onclick="openOrderPinModal('decline_online','<?php echo $view_order['id']; ?>','↩️ Decline & REFUND ₱<?php echo number_format($view_order['total_amount'],2); ?> via PayMongo? Cannot be undone.')">
                    ↩️ Decline & Refund via PayMongo
                </button>
                <?php endif; ?>

            <?php elseif ($vas === 'approved'): ?>

                <div style="background:#d1e7dd;border:1px solid #198754;border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#0a3622;margin-bottom:0.25rem;">
                    📦 Order is ready for pick up. Mark complete once the customer has picked up.
                </div>
                <button type="button" class="btn btn-primary btn-full"
                        onclick="openOrderPinModal('complete_order','<?php echo $view_order['id']; ?>','🎉 Mark Order #<?php echo $view_order['id']; ?> as completed — customer picked up?')">
                    🎉 Mark as Completed — Customer Picked Up
                </button>
                <button type="button" class="btn btn-danger btn-full"
                        onclick="openOrderPinModal('reject_order','<?php echo $view_order['id']; ?>','❌ Decline this approved order?')">
                    ❌ Decline Order
                </button>

            <?php elseif ($vas === 'completed'): ?>
                <div style="background:#cff4fc;border:1px solid #0891b2;border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#055160;margin-top:0.5rem;">
                    🎉 Order completed.
                    <?php if (!empty($view_order['updated_by_name'])): ?>
                    <strong>by <?php echo htmlspecialchars($view_order['updated_by_name']); ?></strong>
                    <?php elseif (!empty($view_order['approved_by_name'])): ?>
                    <strong>by <?php echo htmlspecialchars($view_order['approved_by_name']); ?></strong>
                    <?php endif; ?>
                </div>

            <?php elseif ($vas === 'declined'): ?>
                <div style="background:var(--red-dim);border:1px solid var(--red);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#842029;margin-top:0.5rem;">
                    ❌ Declined<?php echo $vps==='refunded' ? ' and refunded.' : '.'; ?>
                    <?php if (!empty($view_order['updated_by_name'])): ?>
                    <strong>by <?php echo htmlspecialchars($view_order['updated_by_name']); ?></strong>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><span class="panel-title">🛍️ Ordered Products</span></div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
            <tbody>
                <?php foreach ($view_order['items'] as $item): ?>
                <tr>
                    <td style="display:flex;align-items:center;gap:0.75rem;">
                        <img src="../uploads/products/<?php echo htmlspecialchars($item['item_image']); ?>" class="thumb" alt="">
                        <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                    </td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>₱<?php echo number_format($item['price'],2); ?></td>
                    <td><strong style="color:var(--rust);">₱<?php echo number_format($item['subtotal'],2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3" style="text-align:right;padding-top:1rem;color:var(--gray);">Total</td>
                    <td style="padding-top:1rem;"><strong style="color:var(--gold);font-size:1.1rem;">₱<?php echo number_format($view_order['total_amount'],2); ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ── ORDERS LIST ────────────────────────────────────────────────────────── -->

<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:1.5rem;">
    <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-number"><?php echo count($orders); ?></div><div class="stat-label">Total Orders</div></div>
    <div class="stat-card amber"><div class="stat-icon">🕐</div><div class="stat-number"><?php echo $pending_count; ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card green"><div class="stat-icon">📦</div><div class="stat-number"><?php echo $paid_count; ?></div><div class="stat-label">Ready for Pick Up</div></div>
    <div class="stat-card"><div class="stat-icon">🎉</div><div class="stat-number"><?php echo $completed_count; ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-card red"><div class="stat-icon">❌</div><div class="stat-number"><?php echo $rejected_count; ?></div><div class="stat-label">Declined</div></div>
    <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-number">₱<?php echo number_format($total_revenue,0); ?></div><div class="stat-label">Revenue</div></div>
</div>

<div class="filter-tabs">
    <a href="orders.php?filter=all"       class="filter-tab <?php echo $filter==='all'?'active':''; ?>">All</a>
    <a href="orders.php?filter=pending"   class="filter-tab <?php echo $filter==='pending'?'active':''; ?>">🕐 Pending</a>
    <a href="orders.php?filter=approved"  class="filter-tab <?php echo $filter==='approved'?'active':''; ?>">📦 Ready for Pick Up</a>
    <a href="orders.php?filter=completed" class="filter-tab <?php echo $filter==='completed'?'active':''; ?>">🎉 Completed</a>
    <a href="orders.php?filter=declined"  class="filter-tab <?php echo $filter==='declined'?'active':''; ?>">❌ Declined</a>
    <a href="orders.php?filter=walkin"    class="filter-tab <?php echo $filter==='walkin'?'active':''; ?>">🏪 Walk-in</a>
    <a href="orders.php?filter=online"    class="filter-tab <?php echo $filter==='online'?'active':''; ?>">💳 Online</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php $has = false; foreach ($orders as $o):
                $ps = $o['payment_status']  ?? 'unpaid';
                $pm = $o['payment_method']  ?? 'onsite';
                $as = $o['approval_status'] ?? 'pending';

                if ($filter==='pending'   && $as!=='pending')   continue;
                if ($filter==='approved'  && $as!=='approved')  continue;
                if ($filter==='completed' && $as!=='completed') continue;
                if ($filter==='declined'  && $as!=='declined')  continue;
                if ($filter==='walkin'    && !in_array($pm, ['onsite','cash','gcash','maya','qrph','bank','card'])) continue;
                if ($filter==='online'    && $pm!=='online')    continue;
                $has = true;

                $stmt = $conn->prepare("SELECT COUNT(*) as c FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
                $stmt->bind_param("i",$o['id']); $stmt->execute();
                $ic = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();
            ?>
            <tr>
                <td><strong style="color:var(--gold);">#<?php echo $o['id']; ?></strong></td>
                <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                <td><span class="badge badge-approved"><?php echo $ic; ?> item<?php echo $ic!=1?'s':''; ?></span></td>
                <td><strong style="color:var(--rust);">₱<?php echo number_format($o['total_amount'],2); ?></strong></td>
                <td>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <?php
                        $pm_icons  = ['cash'=>'💵 Cash','gcash'=>'📱 GCash','maya'=>'💜 Maya',
                                      'qrph'=>'📷 QRPH','bank'=>'🏦 Bank','card'=>'💳 Card',
                                      'online'=>'💳 Online','onsite'=>'🏪 Onsite'];
                        $pm_class  = isset($pm_icons[$pm]) ? $pm : 'onsite';
                        ?>
                        <span class="badge badge-<?php echo $pm_class; ?>">
                            <?php echo $pm_icons[$pm] ?? ('💰 '.ucfirst($pm)); ?>
                        </span>
                        <span class="badge badge-<?php echo $ps; ?>" style="font-size:0.7rem;">
                            <?php echo $status_labels[$ps] ?? ucfirst($ps); ?>
                        </span>
                    </div>
                </td>
                <td>
                    <span class="badge badge-<?php echo $as; ?>">
                        <?php echo $approval_labels[$as] ?? ucfirst($as); ?>
                    </span>
                    <?php if (!empty($o['approved_by_name']) && in_array($as, ['approved','completed'])): ?>
                    <div style="font-size:0.72rem;color:var(--green);margin-top:3px;font-weight:600;">
                        ✅ by <?php echo htmlspecialchars($o['approved_by_name']); ?>
                    </div>
                    <?php elseif (!empty($o['updated_by_name']) && in_array($as, ['declined','completed'])): ?>
                    <div style="font-size:0.72rem;color:var(--gray);margin-top:3px;">
                        <?php echo $as === 'completed' ? '🎉' : '❌'; ?> by <?php echo htmlspecialchars($o['updated_by_name']); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                <td>
                    <?php if (!is_cashier()): ?>
                    <a href="orders.php?view=<?php echo $o['id']; ?>" class="btn btn-info btn-sm">View</a>
                    <?php endif; ?>

                    <?php if ($as === 'pending'): ?>
                        <button type="button" class="btn btn-success btn-sm"
                                onclick="openOrderPinModal('approve_order','<?php echo $o['id']; ?>','📦 Approve Order #<?php echo $o['id']; ?> for <?php echo htmlspecialchars(addslashes($o['customer_name'])); ?>?')">
                            📦 Approve
                        </button>
                        <?php if (!is_cashier()): ?>
                            <?php if (in_array($pm, ['onsite','cash','gcash','maya','qrph','bank','card'])): ?>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="openOrderPinModal('reject_order','<?php echo $o['id']; ?>','❌ Decline Order #<?php echo $o['id']; ?>?')">
                                ❌ Decline
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="openOrderPinModal('decline_online','<?php echo $o['id']; ?>','↩️ Decline & refund ₱<?php echo number_format($o['total_amount'],2); ?> via PayMongo?')">
                                ↩️ Decline & Refund
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($as === 'approved'): ?>
                        <button type="button" class="btn btn-primary btn-sm"
                                onclick="openOrderPinModal('complete_order','<?php echo $o['id']; ?>','🎉 Mark Order #<?php echo $o['id']; ?> as completed?')">
                            🎉 Complete
                        </button>
                        <?php if (!is_cashier()): ?>
                        <button type="button" class="btn btn-danger btn-sm"
                                onclick="openOrderPinModal('reject_order','<?php echo $o['id']; ?>','❌ Decline Order #<?php echo $o['id']; ?>?')">
                            ❌ Decline
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; if (!$has): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--gray);padding:2rem;">No orders found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ═══════════════════ PIN MODAL ═══════════════════════════════════════════ -->
<div id="orderPinModal" style="display:none;position:fixed;inset:0;z-index:9999;
     background:rgba(30,20,10,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem 1.75rem;
                max-width:380px;width:92vw;box-shadow:0 24px 60px rgba(0,0,0,0.22);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;border-radius:50%;
                        background:linear-gradient(135deg,var(--rust),var(--brown));
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.4rem;margin:0 auto 0.75rem;">🔐</div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--brown);" id="orderPinTitle">Confirm Action</div>
        </div>
        <div id="orderPinFullNotice" style="display:none;background:rgba(25,135,84,0.08);
             border:1px solid rgba(25,135,84,0.2);border-radius:10px;padding:0.85rem 1rem;
             text-align:center;font-size:0.85rem;color:#0a3622;margin-bottom:1rem;">
            👤 Logged in as <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin'); ?></strong>. No PIN required.
        </div>
        <div id="orderPinInputArea">
            <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;
                          text-align:center;margin-bottom:0.65rem;text-transform:uppercase;letter-spacing:0.05em;">
                Enter your 4-digit PIN
            </label>
            <input type="password" id="orderPinInput" maxlength="4" inputmode="numeric" placeholder="• • • •"
                   style="width:100%;padding:0.85rem;border:2px solid var(--border2);border-radius:10px;
                          font-size:1.6rem;text-align:center;letter-spacing:0.4em;color:var(--brown);
                          background:var(--bg3);box-sizing:border-box;font-family:monospace;transition:border-color .15s;"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <div id="orderPinError" style="color:#dc2626;font-size:0.78rem;text-align:center;
                                            margin-top:0.5rem;min-height:1.1em;"></div>
        </div>
        <div style="display:flex;gap:0.65rem;margin-top:1.1rem;">
            <button type="button" onclick="closeOrderPinModal()" class="btn btn-secondary" style="flex:1;">Cancel</button>
            <button type="button" id="orderPinConfirmBtn" onclick="submitOrderPinAction()" class="btn btn-primary" style="flex:2;">✅ Confirm</button>
        </div>
        <div style="margin-top:0.85rem;text-align:center;font-size:0.72rem;color:var(--gray);">
            🔒 Action recorded under your name for accountability.
        </div>
    </div>
</div>

<style>
@keyframes popIn { from { transform:scale(.85);opacity:0; } to { transform:scale(1);opacity:1; } }
#orderPinInput:focus { outline:none;border-color:var(--rust); }
</style>

<script>
let _orderPinAction = '', _orderPinId = '';
const _orderIsFullAccess = <?php echo is_cashier() ? 'false' : 'true'; ?>;

function openOrderPinModal(action, orderId, desc) {
    _orderPinAction = action; _orderPinId = orderId;
    document.getElementById('orderPinTitle').textContent  = desc || 'Confirm Action';
    document.getElementById('orderPinError').textContent  = '';
    document.getElementById('orderPinInput').value        = '';
    if (_orderIsFullAccess) {
        document.getElementById('orderPinFullNotice').style.display  = '';
        document.getElementById('orderPinInputArea').style.display   = 'none';
    } else {
        document.getElementById('orderPinFullNotice').style.display  = 'none';
        document.getElementById('orderPinInputArea').style.display   = '';
        setTimeout(() => document.getElementById('orderPinInput').focus(), 120);
    }
    document.getElementById('orderPinModal').style.display = 'flex';
}
function closeOrderPinModal() {
    document.getElementById('orderPinModal').style.display = 'none';
}
async function submitOrderPinAction() {
    const btn   = document.getElementById('orderPinConfirmBtn');
    const errEl = document.getElementById('orderPinError');
    errEl.textContent = '';
    const pin = _orderIsFullAccess ? '' : document.getElementById('orderPinInput').value.trim();
    if (!_orderIsFullAccess && !/^\d{4}$/.test(pin)) {
        errEl.textContent = 'Please enter your 4-digit PIN.';
        document.getElementById('orderPinInput').focus(); return;
    }
    btn.disabled = true; btn.textContent = 'Verifying…';
    try {
        const fd = new FormData();
        fd.append('verify_pin_only', '1'); fd.append('pin', pin);
        const res  = await fetch('orders.php', { method:'POST', body:fd });
        const data = await res.json();
        if (!data.ok) {
            errEl.textContent = data.error || 'Incorrect PIN.';
            document.getElementById('orderPinInput').value = '';
            document.getElementById('orderPinInput').focus();
            btn.disabled = false; btn.textContent = '✅ Confirm'; return;
        }
        closeOrderPinModal();
        window.location.href = 'orders.php?' + _orderPinAction + '=' + _orderPinId;
    } catch(e) {
        errEl.textContent = 'Network error. Try again.';
        btn.disabled = false; btn.textContent = '✅ Confirm';
    }
}
document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && document.getElementById('orderPinModal').style.display === 'flex') submitOrderPinAction();
    if (e.key === 'Escape') closeOrderPinModal();
});
</script>

<?php require_once 'admin_footer.php'; ?>