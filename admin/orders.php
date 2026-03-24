<?php
require_once '../config.php';
redirect_if_not_admin();

// ─── ACTIONS ──────────────────────────────────────────────────────────────────

// APPROVE — works for both onsite and online paid orders
if (isset($_GET['approve_order'])) {
    $id   = intval($_GET['approve_order']);

    // Fetch payment method so we know how to handle it
    $stmt = $conn->prepare("SELECT payment_method, payment_status FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($o) {
        // Mark approval + always ensure payment_status = 'paid'
        // Onsite:  was 'unpaid'          → set paid now
        // Online:  was 'paid' already    → keep paid (no change needed)
        //          was 'pending_payment' → admin confirming means payment is
        //          accepted, force to 'paid' so analytics counts it correctly
        $stmt = $conn->prepare("UPDATE orders SET approval_status='approved', payment_status='paid' WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();

        // Approve linked appointments
        $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='approved' WHERE oi.order_id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
    }
    header("Location: orders.php?msg=approved"); exit();
}

// DECLINE (onsite) — just reject, no refund needed
if (isset($_GET['reject_order'])) {
    $id   = intval($_GET['reject_order']);
    $stmt = $conn->prepare("UPDATE orders SET payment_status='rejected', approval_status='declined' WHERE id=? AND payment_method='onsite'");
    $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='declined' WHERE oi.order_id=?");
    $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
    header("Location: orders.php?msg=rejected"); exit();
}

// DECLINE + REFUND (online) — always declines, attempts refund via PayMongo
if (isset($_GET['decline_online'])) {
    $id   = intval($_GET['decline_online']);

    // Fetch order — no strict conditions so it always finds the order
    $stmt = $conn->prepare("SELECT paymongo_link_id, total_amount, payment_status FROM orders WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc(); $stmt->close();

    $refund_attempted = false;
    $refund_success   = false;
    $refund_error     = '';

    // Attempt PayMongo refund only if order was actually paid online
    if ($order && !empty($order['paymongo_link_id']) && $order['payment_status'] === 'paid') {
        $refund_attempted = true;

        // Step 1: Get payment ID from the link
        $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($order['paymongo_link_id']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ]]);
        $link_res   = json_decode(curl_exec($ch), true); curl_close($ch);
        $payment_id = $link_res['data']['attributes']['payments'][0]['id'] ?? null;

        if ($payment_id) {
            // Step 2: Issue refund
            $amount_cents   = intval($order['total_amount'] * 100);
            $refund_payload = json_encode(['data'=>['attributes'=>[
                'amount'     => $amount_cents,
                'payment_id' => $payment_id,
                'reason'     => 'others',
                'notes'      => 'Declined by Serenity Spa admin.',
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

    // ── ALWAYS decline the order regardless of refund result ──────────────────
    $new_pay_status = $refund_success ? 'refunded' : $order['payment_status'];
    $stmt = $conn->prepare("UPDATE orders SET payment_status=?, approval_status='declined' WHERE id=?");
    $stmt->bind_param("si", $new_pay_status, $id); $stmt->execute(); $stmt->close();

    // Restore stock
    $items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL");
    $items->bind_param("i",$id); $items->execute();
    foreach ($items->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
        $upd = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id=?");
        $upd->bind_param("ii", $item['quantity'], $item['product_id']); $upd->execute(); $upd->close();
    }
    $items->close();

    // Decline appointments
    $stmt = $conn->prepare("UPDATE appointments a INNER JOIN order_items oi ON a.order_item_id=oi.id SET a.status='declined' WHERE oi.order_id=?");
    $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();

    // Redirect with appropriate message
    if (!$refund_attempted) {
        header("Location: orders.php?msg=declined_no_refund"); exit();
    } elseif ($refund_success) {
        header("Location: orders.php?msg=refunded"); exit();
    } else {
        header("Location: orders.php?msg=declined_refund_failed&err=" . urlencode($refund_error)); exit();
    }
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
$pending_count         = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.approval_status='pending'")->fetch_assoc()['c'] ?? 0;
$rejected_count        = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.approval_status='declined'")->fetch_assoc()['c'] ?? 0;
$pending_payment_count = $conn->query("SELECT COUNT(DISTINCT o.id) as c FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.payment_status='pending_payment'")->fetch_assoc()['c'] ?? 0;
$total_revenue         = $conn->query("SELECT IFNULL(SUM(o.total_amount),0) as t FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL AND o.payment_status='paid' AND o.approval_status='approved'")->fetch_assoc()['t'] ?? 0;

// ─── FETCH ORDERS ─────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$orders = [];
$result = $conn->query("SELECT DISTINCT o.* FROM orders o INNER JOIN order_items oi ON o.id=oi.order_id WHERE oi.product_id IS NOT NULL ORDER BY o.created_at DESC");
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
    'pending'  => '🕐 Pending Approval',
    'approved' => '✅ Approved',
    'declined' => '❌ Declined',
];

$page_title  = $view_order ? 'Order #' . $view_order['id'] : 'Orders';
$page_icon   = '📦';
$active_page = 'orders';
require_once 'admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?php echo in_array($_GET['msg'],['approved','refunded'])?'success':(str_contains($_GET['msg'],'failed')?'danger':'warning'); ?>" style="margin-bottom:1.5rem;">
    <?php
    $msgs = [
        'approved'              => '✅ Order approved and appointment confirmed.',
        'rejected'              => '❌ Order declined.',
        'refunded'              => '↩️ Order declined and full refund issued to customer via PayMongo.',
        'declined_no_refund'    => '❌ Order declined. No payment was collected so no refund was needed.',
        'declined_refund_failed'=> '⚠️ Order declined, but the automatic refund failed: <strong>' . htmlspecialchars($_GET['err'] ?? '') . '</strong>. Please issue the refund manually in your <a href="https://dashboard.paymongo.com" target="_blank" style="color:inherit;font-weight:bold;">PayMongo dashboard</a>.',
    ];
    echo $msgs[$_GET['msg']] ?? '';
    ?>
</div>
<?php endif; ?>

<?php if ($view_order): ?>
<!-- ── ORDER DETAIL ──────────────────────────────────────────────────────── -->
<div style="margin-bottom:1rem;">
    <a href="orders.php" class="btn btn-secondary">← Back to Orders</a>
</div>

<?php
$vps = $view_order['payment_status'] ?? 'unpaid';
$vpm = $view_order['payment_method'] ?? 'onsite';
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
        <div class="panel-header"><span class="panel-title">🧾 Order Info</span></div>
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
                        <span class="badge badge-<?php echo $vpm; ?>">
                            <?php echo $vpm === 'online' ? '💳 Online' : '🏪 Onsite'; ?>
                        </span>
                        <span class="badge badge-<?php echo $vps; ?>" style="font-size:0.7rem;">
                            <?php echo $status_labels[$vps] ?? ucfirst($vps); ?>
                        </span>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--gray);font-size:0.78rem;">Approval</span>
                    <span class="badge badge-<?php echo $vas; ?>"><?php echo $approval_labels[$vas] ?? ucfirst($vas); ?></span>
                </div>
            </div>

            <!-- ── ACTION BUTTONS ────────────────────────────── -->
            <?php if ($vas === 'pending'): ?>
            <div style="display:flex;flex-direction:column;gap:0.6rem;margin-top:1.25rem;">

                <?php if ($vpm === 'onsite' && $vps === 'unpaid'): ?>
                <!-- Onsite: Approve = mark paid + approve appointment -->
                <a href="orders.php?approve_order=<?php echo $view_order['id']; ?>"
                   class="btn btn-success btn-full"
                   onclick="return confirm('Approve this order and confirm the appointment?')">
                    ✅ Approve Order
                </a>
                <a href="orders.php?reject_order=<?php echo $view_order['id']; ?>"
                   class="btn btn-danger btn-full"
                   onclick="return confirm('Decline this order?')">
                    ❌ Decline Order
                </a>

                <?php elseif ($vpm === 'online' && $vps === 'paid'): ?>
                <!-- Online: Approve = confirm order, Decline = refund via PayMongo -->
                <div style="background:var(--gold-dim);border:1px solid var(--gold);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:var(--brown);margin-bottom:0.25rem;">
                    💳 Customer has paid <strong>₱<?php echo number_format($view_order['total_amount'],2); ?></strong> via PayMongo. Review the order then approve or decline.
                </div>
                <a href="orders.php?approve_order=<?php echo $view_order['id']; ?>"
                   class="btn btn-success btn-full"
                   onclick="return confirm('Approve this online order? The appointment will be confirmed.')">
                    ✅ Approve Order
                </a>
                <a href="orders.php?decline_online=<?php echo $view_order['id']; ?>"
                   class="btn btn-danger btn-full"
                   onclick="return confirm('Decline and REFUND ₱<?php echo number_format($view_order['total_amount'],2); ?> to the customer via PayMongo?\n\nThis cannot be undone.')">
                    ↩️ Decline & Refund via PayMongo
                </a>
                <?php endif; ?>

            </div>
            <?php elseif ($vas === 'approved'): ?>
            <div style="background:var(--green-dim);border:1px solid var(--green);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#155724;margin-top:1rem;">
                ✅ This order has been approved.
                <?php if ($vpm === 'online'): ?>
                <br><small>To issue a refund, use your <a href="https://dashboard.paymongo.com" target="_blank" style="color:#155724;font-weight:bold;">PayMongo dashboard</a>.</small>
                <?php endif; ?>
            </div>
            <?php elseif ($vas === 'declined'): ?>
            <div style="background:var(--red-dim);border:1px solid var(--red);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#842029;margin-top:1rem;">
                ❌ This order has been declined<?php echo $vps==='refunded' ? ' and refunded.' : '.'; ?>
            </div>
            <?php endif; ?>
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

<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.5rem;">
    <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-number"><?php echo count($orders); ?></div><div class="stat-label">Total Orders</div></div>
    <div class="stat-card amber"><div class="stat-icon">🕐</div><div class="stat-number"><?php echo $pending_count; ?></div><div class="stat-label">Pending Approval</div></div>
    <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-number"><?php echo $paid_count; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card red"><div class="stat-icon">❌</div><div class="stat-number"><?php echo $rejected_count; ?></div><div class="stat-label">Declined</div></div>
    <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-number">₱<?php echo number_format($total_revenue,0); ?></div><div class="stat-label">Revenue</div></div>
</div>

<div class="filter-tabs">
    <a href="orders.php?filter=all"             class="filter-tab <?php echo $filter==='all'?'active':''; ?>">All</a>
    <a href="orders.php?filter=pending"         class="filter-tab <?php echo $filter==='pending'?'active':''; ?>">🕐 Pending Approval</a>
    <a href="orders.php?filter=approved"        class="filter-tab <?php echo $filter==='approved'?'active':''; ?>">✅ Approved</a>
    <a href="orders.php?filter=declined"        class="filter-tab <?php echo $filter==='declined'?'active':''; ?>">❌ Declined</a>
    <a href="orders.php?filter=onsite"          class="filter-tab <?php echo $filter==='onsite'?'active':''; ?>">🏪 Onsite</a>
    <a href="orders.php?filter=online"          class="filter-tab <?php echo $filter==='online'?'active':''; ?>">💳 Online</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Approval</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php $has = false; foreach ($orders as $o):
                $ps  = $o['payment_status']  ?? 'unpaid';
                $pm  = $o['payment_method']  ?? 'onsite';
                $as  = $o['approval_status'] ?? 'pending';

                if ($filter==='pending'  && $as!=='pending')  continue;
                if ($filter==='approved' && $as!=='approved') continue;
                if ($filter==='declined' && $as!=='declined') continue;
                if ($filter==='onsite'   && $pm!=='onsite')   continue;
                if ($filter==='online'   && $pm!=='online')   continue;
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
                        <span class="badge badge-<?php echo $pm; ?>">
                            <?php echo $pm === 'online' ? '💳 Online' : '🏪 Onsite'; ?>
                        </span>
                        <span class="badge badge-<?php echo $ps; ?>" style="font-size:0.7rem;">
                            <?php echo $status_labels[$ps] ?? ucfirst($ps); ?>
                        </span>
                    </div>
                </td>
                <td><span class="badge badge-<?php echo $as; ?>"><?php echo $approval_labels[$as] ?? ucfirst($as); ?></span></td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                <td>
                    <a href="orders.php?view=<?php echo $o['id']; ?>" class="btn btn-info btn-sm">View</a>
                    <?php if ($as === 'pending'): ?>
                        <a href="orders.php?approve_order=<?php echo $o['id']; ?>"
                           class="btn btn-success btn-sm"
                           onclick="return confirm('Approve this order?')">✅ Approve</a>
                        <?php if ($pm === 'onsite'): ?>
                        <a href="orders.php?reject_order=<?php echo $o['id']; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Decline this order?')">❌ Decline</a>
                        <?php else: ?>
                        <a href="orders.php?decline_online=<?php echo $o['id']; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Decline and refund ₱<?php echo number_format($o['total_amount'],2); ?> via PayMongo?')">↩️ Decline & Refund</a>
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

<?php require_once 'admin_footer.php'; ?>