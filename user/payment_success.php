<?php
/**
 * payment_success.php — HARDENED + RECEIPT + REFERENCE NUMBER VERSION
 *
 * Key addition: captures payments[0].attributes.reference_number from the
 * PayMongo Checkout Sessions API and stores it in orders.paymongo_reference.
 * Displayed prominently on the on-screen receipt and included in the email.
 *
 * DB prerequisite (run once in phpMyAdmin):
 *   ALTER TABLE orders ADD COLUMN paymongo_reference VARCHAR(100) DEFAULT NULL AFTER paymongo_link_id;
 */

require_once '../config.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../send_receipt.php';

header('ngrok-skip-browser-warning: true');

// ─────────────────────────────────────────────────────────────────────────────
// STEP 1 — Resolve order_id
// ─────────────────────────────────────────────────────────────────────────────
$order_id = intval($_GET['order_id'] ?? $_SESSION['paymongo_order_id'] ?? 0);
if (!$order_id) {
    header("Location: " . BASE_URL . "user/auth.php");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 2 — Session restore with HMAC (IDOR fix)
// ─────────────────────────────────────────────────────────────────────────────
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        header("Location: " . BASE_URL . "user/auth.php?reason=session_expired");
        exit();
    }
    $stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { header("Location: " . BASE_URL . "user/auth.php"); exit(); }

    $candidate_user_id = (int) $row['user_id'];
    $expected_token    = hash_hmac('sha256', $order_id . '|' . $candidate_user_id, APP_SECRET);

    if (!hash_equals($expected_token, $token)) {
        http_response_code(403);
        die("Access denied: invalid payment token.");
    }
    $user_id             = $candidate_user_id;
    $_SESSION['user_id'] = $user_id;
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 3 — Ownership gate + fetch order and items
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, payment_status, payment_method, paymongo_method,
           paymongo_link_id, paymongo_reference, total_amount,
           discount_amount, discount_type, final_amount,
           customer_name, email, phone, address, approval_status, created_at
    FROM   orders
    WHERE  id = ? AND user_id = ?
");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(403);
    die("Access denied: this order does not belong to your account.");
}

// Fetch items for on-screen receipt
$stmt = $conn->prepare("
    SELECT
        oi.*,
        p.name        AS product_name,
        s.name        AS service_name,
        s.session_time,
        a.appointment_date,
        a.service_type,
        a.people_count
    FROM order_items oi
    LEFT JOIN products     p ON oi.product_id = p.id
    LEFT JOIN services     s ON oi.service_id = s.id
    LEFT JOIN appointments a ON a.order_item_id = oi.id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$is_service = !empty($order_items) && !empty($order_items[0]['service_id']);

// ── Fetch refund record (used to display refund details on the receipt) ───────
$refund_info = null;
if (in_array($order['payment_status'], ['refunded', 'partially_refunded'])) {
    $rq = $conn->prepare("
        SELECT amount, updated_at AS refund_date, paymongo_refund_id, status, reason, refund_notes
        FROM   refund_requests
        WHERE  order_id = ? AND status IN ('refunded', 'manually_refunded')
        ORDER  BY updated_at DESC
        LIMIT  1
    ");
    $rq->bind_param("i", $order_id);
    $rq->execute();
    $refund_info = $rq->get_result()->fetch_assoc();
    $rq->close();
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 4 — Payment confirmation with idempotency + reference capture
// ─────────────────────────────────────────────────────────────────────────────
$payment_confirmed  = false;
$already_paid       = false;
$paymongo_error     = null;
$receipt_emailed    = false;
$paymongo_reference = $order['paymongo_reference'] ?? '';

if ($order['payment_status'] === 'paid') {
    // Branch A — already processed; reference already stored
    $payment_confirmed = true;
    $already_paid      = true;

} elseif ($order['payment_status'] === 'pending_payment' && !empty($order['paymongo_link_id'])) {
    // Branch B — first-time online confirmation
    $link_id = $order['paymongo_link_id'];
    $api_url = str_starts_with($link_id, 'cs_')
        ? 'https://api.paymongo.com/v1/checkout_sessions/' . urlencode($link_id)
        : 'https://api.paymongo.com/v1/links/'             . urlencode($link_id);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $raw_response = curl_exec($ch);
    $curl_errno   = curl_errno($ch);
    curl_close($ch);

    if ($curl_errno || !$raw_response) {
        $paymongo_error = 'Could not reach PayMongo. Please try again in a moment.';
    } else {
        $result   = json_decode($raw_response, true);
        $attrs    = $result['data']['attributes'] ?? [];
        $payments = $attrs['payments'] ?? [];

        // Determine paid status
        $pm_status = str_starts_with($link_id, 'cs_')
            ? ((!empty($payments) && ($payments[0]['attributes']['status'] ?? '') === 'paid') ? 'paid' : '')
            : ($attrs['status'] ?? '');

        // ── Extract reference number ──────────────────────────────────────────
        // Checkout Sessions: payments[0].attributes.reference_number  e.g. "CS-XXXXXX"
        // Links API:         data.attributes.reference_number
        // Map PayMongo source types to our payment method values
        $pm_method_map = ['gcash'=>'gcash','paymaya'=>'maya','card'=>'card',
                          'dob_ubp'=>'bank','dob'=>'bank','billease'=>'online'];

        if (str_starts_with($link_id, 'cs_')) {
            $paymongo_payment_id = $payments[0]['id'] ?? null;
            $paymongo_reference  = $payments[0]['attributes']['reference_number']
                                   ?? $paymongo_payment_id
                                   ?? $link_id;
            // Extract actual payment source (gcash / card / paymaya)
            $raw_pm_method  = $payments[0]['attributes']['source']['type']
                           ?? $payments[0]['attributes']['payment_method_used']
                           ?? null;
            $paymongo_method = $pm_method_map[$raw_pm_method] ?? $raw_pm_method ?? null;
        } else {
            $paymongo_reference  = $attrs['reference_number'] ?? $link_id;
            $raw_pm_method       = $attrs['payments'][0]['attributes']['source']['type']
                                ?? $attrs['payments'][0]['attributes']['payment_method_used']
                                ?? null;
            $paymongo_method = $pm_method_map[$raw_pm_method] ?? $raw_pm_method ?? null;
        }

        if ($pm_status === 'paid') {
            // Idempotent UPDATE — includes reference number
            $upd = $conn->prepare("
                UPDATE orders
                SET    payment_status      = 'paid',
                       approval_status     = 'pending',
                       paymongo_reference  = ?,
                       paymongo_method     = ?
                WHERE  id                 = ?
                  AND  user_id            = ?
                  AND  payment_status     = 'pending_payment'
            ");
            $upd->bind_param("ssii", $paymongo_reference, $paymongo_method, $order_id, $user_id);
            $upd->execute();
            $rows_changed = $upd->affected_rows;
            $upd->close();

            $payment_confirmed = true;

            if ($rows_changed > 0) {
                // Stock deduction
                $stmt = $conn->prepare("
                    SELECT product_id, quantity FROM order_items
                    WHERE order_id = ? AND product_id IS NOT NULL
                ");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $order_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($order_products as $item) {
                    $upd = $conn->prepare("
                        UPDATE products SET stock = stock - ?
                        WHERE id = ? AND stock >= ?
                    ");
                    $upd->bind_param("iii", $item['quantity'], $item['product_id'], $item['quantity']);
                    $upd->execute();
                    $upd->close();
                }

                // Clear cart
                foreach ($order_products as $item) {
                    $pid = $item['product_id'];
                    if (isset($_SESSION['cart'][$pid])) unset($_SESSION['cart'][$pid]);
                    remove_cart_item_from_db($conn, $user_id, $pid);
                }
                $remaining_cart   = $_SESSION['cart'] ?? [];
                $_SESSION['cart'] = $remaining_cart;
                sync_cart_to_db($conn, $user_id, $remaining_cart);

                // Notifications
                $checkout_type = $_SESSION['paymongo_checkout_type'] ?? 'product';
                $notif_is_svc  = ($checkout_type === 'service');
                add_notification($conn, $user_id, $notif_is_svc ? 'appointment' : 'order',
                    '💳 Payment Confirmed!',
                    'Your online payment for order #' . $order_id . ' was successful. Awaiting admin approval.',
                    $notif_is_svc ? 'appointments.php' : 'appointments.php#orders'
                );
                add_notification($conn, null, $notif_is_svc ? 'appointment' : 'order',
                    '💳 New Online Payment',
                    'Order #' . $order_id . ' (₱' . number_format($order['total_amount'], 2) . ') paid online. Ref: ' . $paymongo_reference,
                    $notif_is_svc ? 'appointments.php' : 'orders.php'
                );

                // Send receipt email
                $receipt_emailed = send_order_receipt($conn, $order_id);

                // Clean up session
                unset(
                    $_SESSION['direct_checkout'],         $_SESSION['service_booking'],
                    $_SESSION['checkout_items'],          $_SESSION['checkout_item_ids'],
                    $_SESSION['paymongo_order_id'],       $_SESSION['paymongo_checkout_type'],
                    $_SESSION['paymongo_checkout_items'], $_SESSION['paymongo_checkout_ids']
                );
            }
        }
    }

} elseif ($order['payment_status'] === 'unpaid') {
    // Branch C — cash order; show receipt, no PayMongo reference
    $payment_confirmed  = true;
    $already_paid       = false;
    $paymongo_reference = ''; // cash has none

    if (empty($_SESSION['receipt_sent_' . $order_id])) {
        $receipt_emailed = send_order_receipt($conn, $order_id);
        $_SESSION['receipt_sent_' . $order_id] = true;
    }

} elseif (in_array($order['payment_status'], ['refunded', 'partially_refunded'])) {
    // Branch D — previously paid, now refunded; render receipt in voided state
    $payment_confirmed = true;
    $already_paid      = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 5 — Render
// ─────────────────────────────────────────────────────────────────────────────
$is_refunded = in_array($order['payment_status'], ['refunded', 'partially_refunded']);
$page_title  = 'Payment Receipt';
require_once 'header.php';

$pay_badge = match($order['payment_status']) {
    'paid'               => '<span style="background:#d1e7dd;color:#0a3622;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:bold;">✅ Paid</span>',
    'unpaid'             => '<span style="background:#fff3cd;color:#664d03;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:bold;">🏪 Pay at Spa</span>',
    'refunded'           => '<span style="background:#f8d7da;color:#842029;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:bold;">↩️ Refunded</span>',
    'partially_refunded' => '<span style="background:#fff3cd;color:#856404;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:bold;">↩️ Partially Refunded</span>',
    default              => '<span style="background:#e2e3e5;color:#41464b;padding:3px 12px;border-radius:20px;font-size:12px;">' . htmlspecialchars($order['payment_status']) . '</span>',
};

// Reference display: prominent for online, softer for cash
$ref_display = !empty($paymongo_reference)
    ? htmlspecialchars($paymongo_reference)
    : ($order['payment_method'] === 'online' ? '—' : 'N/A — Cash Payment');
?>

<div class="container" style="max-width:680px;margin:3rem auto;padding:0 1rem;">

<?php if ($paymongo_error): ?>
    <div style="background:#fff;border-radius:20px;padding:3rem 2rem;text-align:center;
                box-shadow:0 8px 40px rgba(59,42,26,0.10);border:1px solid #EAD8C0;">
        <div style="font-size:3rem;margin-bottom:1rem;">⚠️</div>
        <h2 style="color:#C96A2C;font-family:'Cormorant Garamond',serif;font-weight:400;font-size:2rem;">
            Connection Error
        </h2>
        <p style="color:#555;margin-bottom:2rem;"><?php echo htmlspecialchars($paymongo_error); ?></p>
        <a href="payment_success.php?order_id=<?php echo $order_id;
            ?>&token=<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>"
           class="btn btn-primary" style="padding:0.75rem 1.5rem;">🔄 Try Again</a>
    </div>

<?php elseif ($payment_confirmed): ?>

<?php if ($is_refunded): ?>
    <!-- REFUND ALERT BANNER — replaces the success banner for voided receipts -->
    <div style="background:repeating-linear-gradient(-45deg,#dc2626,#dc2626 10px,#b91c1c 10px,#b91c1c 20px);
                color:#fff;border-radius:16px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;
                display:flex;align-items:flex-start;gap:1rem;box-shadow:0 4px 16px rgba(220,38,38,.35);">
        <div style="font-size:2rem;flex-shrink:0;line-height:1;">⛔</div>
        <div>
            <div style="font-size:1rem;font-weight:800;letter-spacing:.5px;text-shadow:0 1px 2px rgba(0,0,0,.3);">
                THIS ORDER HAS BEEN <?php echo $order['payment_status'] === 'partially_refunded' ? 'PARTIALLY REFUNDED' : 'REFUNDED'; ?>
            </div>
            <div style="font-size:.85rem;margin-top:.3rem;opacity:.92;">
                This receipt is no longer valid for service or redemption.
            </div>
            <?php if ($refund_info): ?>
            <div style="font-size:.82rem;margin-top:.6rem;background:rgba(0,0,0,.18);
                        border-radius:8px;padding:.5rem .75rem;line-height:1.8;">
                <?php if (!empty($refund_info['refund_date'])): ?>
                Refunded on: <strong><?php echo date('F d, Y \a\t h:i A', strtotime($refund_info['refund_date'])); ?></strong><br>
                <?php endif; ?>
                <?php if (!empty($refund_info['amount'])): ?>
                Refund amount: <strong>₱<?php echo number_format((float)$refund_info['amount'], 2); ?></strong><br>
                <?php endif; ?>
                <?php if (!empty($refund_info['paymongo_refund_id'])): ?>
                Refund ref.: <span style="font-family:monospace;"><?php echo htmlspecialchars($refund_info['paymongo_refund_id']); ?></span>
                <?php endif; ?>
                <?php if (!empty($refund_info['refund_notes']) && $refund_info['status'] === 'manually_refunded'): ?>
                <br>Note: <?php echo htmlspecialchars($refund_info['refund_notes']); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- SUCCESS BANNER -->
    <div style="background:#fff;border-radius:20px;padding:2rem;text-align:center;
                box-shadow:0 8px 40px rgba(59,42,26,0.10);border:1px solid #EAD8C0;
                margin-bottom:1.5rem;">
        <div style="font-size:3.5rem;margin-bottom:.5rem;">
            <?php echo $is_service ? '📅' : '🎉'; ?>
        </div>
        <h2 style="color:#198754;margin-bottom:.4rem;
                   font-family:'Cormorant Garamond',serif;font-weight:400;font-size:2rem;">
            <?php echo $order['payment_method'] === 'online' ? 'Payment Successful!' : 'Order Placed!'; ?>
        </h2>
        <p style="color:#555;margin-bottom:.3rem;">
            <?php echo $pay_badge; ?>
            <?php if ($already_paid): ?>
                &nbsp;<em style="color:#888;font-size:.8rem;">(Already recorded — safe to refresh ✓)</em>
            <?php endif; ?>
        </p>
        <?php if ($receipt_emailed): ?>
        <p style="color:#198754;font-size:.82rem;margin-top:.5rem;">
            📧 Receipt sent to <strong><?php echo htmlspecialchars($order['email']); ?></strong>
        </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <!-- RECEIPT CARD -->
    <div style="background:#fff;border-radius:20px;overflow:hidden;position:relative;
                box-shadow:0 8px 40px rgba(59,42,26,0.10);
                border:<?php echo $is_refunded ? '2px solid #dc2626' : '1px solid #EAD8C0'; ?>;
                margin-bottom:1.5rem;" id="receipt-card">

        <?php if ($is_refunded): ?>
        <!-- Faint watermark — positioned before card content so it renders behind -->
        <div style="position:absolute;inset:0;pointer-events:none;
                    display:flex;align-items:center;justify-content:center;overflow:hidden;">
            <div style="font-size:5.5rem;font-weight:900;color:rgba(220,38,38,.07);
                        transform:rotate(-30deg);letter-spacing:.2em;white-space:nowrap;
                        user-select:none;font-family:Arial,sans-serif;">REFUNDED</div>
        </div>
        <?php endif; ?>

        <!-- Header bar -->
        <div style="background:linear-gradient(135deg,#3B2A1A,#6B4C30);
                    padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;">
            <div style="font-size:1.8rem;">🧾</div>
            <div>
                <div style="color:#FAF3E8;font-size:1rem;font-weight:600;letter-spacing:1px;">RECOVERY SPA</div>
                <div style="color:#C8A46B;font-size:.75rem;letter-spacing:.5px;">Official Receipt</div>
            </div>
        </div>

        <!-- Reference number — hero element -->
        <div style="background:#FAF3E8;padding:1.1rem 1.5rem;border-bottom:1px solid #EAD8C0;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
            <div>
                <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;
                            letter-spacing:.08em;color:#888;margin-bottom:.2rem;">
                    <?php echo $order['payment_method'] === 'online' ? 'PayMongo Reference No.' : 'Payment Reference'; ?>
                </div>
                <div style="font-size:1.25rem;font-weight:bold;color:#C96A2C;
                            font-family:monospace;letter-spacing:.05em;">
                    <?php echo $ref_display; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;
                            letter-spacing:.08em;color:#888;margin-bottom:.2rem;">Date</div>
                <div style="font-size:.85rem;color:#3B2A1A;">
                    <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?>
                </div>
            </div>
        </div>

        <!-- Meta grid -->
        <div style="padding:1rem 1.5rem;display:grid;grid-template-columns:1fr 1fr;
                    gap:.5rem .75rem;font-size:.82rem;border-bottom:1px solid #EAD8C0;">
            <div>
                <div style="color:#888;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;">Customer</div>
                <div style="color:#3B2A1A;font-weight:600;"><?php echo htmlspecialchars($order['customer_name']); ?></div>
            </div>
            <div>
                <div style="color:#888;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;">Email</div>
                <div style="color:#3B2A1A;"><?php echo htmlspecialchars($order['email']); ?></div>
            </div>
            <div>
                <div style="color:#888;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;">Phone</div>
                <div style="color:#3B2A1A;"><?php echo htmlspecialchars($order['phone']); ?></div>
            </div>
            <div>
                <div style="color:#888;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;">Payment</div>
                <div style="color:#3B2A1A;">
                    <?php
                    $pm_labels = ['gcash'=>'📱 GCash (PayMongo)','maya'=>'💜 Maya (PayMongo)',
                                  'card'=>'💳 Card (PayMongo)','bank'=>'🏦 Bank (PayMongo)',
                                  'online'=>'💳 Online (PayMongo)','cash'=>'💵 Cash',
                                  'onsite'=>'🏪 Onsite / Cash'];
                    $display_pm = $order['paymongo_method'] ?? $order['payment_method'] ?? 'online';
                    echo $pm_labels[$display_pm] ?? ('💳 '.ucfirst($display_pm));
                    ?>
                    &nbsp; <?php echo $pay_badge; ?>
                </div>
            </div>
        </div>

        <!-- Line items -->
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <thead>
                <tr style="background:#EAD8C0;">
                    <th style="padding:10px 16px;text-align:left;color:#3B2A1A;font-weight:600;">Item</th>
                    <th style="padding:10px 16px;text-align:center;color:#3B2A1A;font-weight:600;">Qty</th>
                    <th style="padding:10px 16px;text-align:right;color:#3B2A1A;font-weight:600;">Price</th>
                    <th style="padding:10px 16px;text-align:right;color:#3B2A1A;font-weight:600;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($order_items as $item): ?>
                <?php if (!empty($item['product_id'])): ?>
                <tr>
                    <td style="padding:12px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;">
                        <?php echo htmlspecialchars($item['product_name']); ?>
                    </td>
                    <td style="padding:12px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;">
                        <?php echo (int)$item['quantity']; ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;">
                        ₱<?php echo number_format($item['price'], 2); ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;font-weight:bold;color:#3B2A1A;border-bottom:1px solid #EAD8C0;">
                        ₱<?php echo number_format($item['subtotal'], 2); ?>
                    </td>
                </tr>
                <?php elseif (!empty($item['service_id'])): ?>
                <tr>
                    <td style="padding:12px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;">
                        <div style="font-weight:600;"><?php echo htmlspecialchars($item['service_name']); ?></div>
                        <?php if (!empty($item['appointment_date'])): ?>
                        <div style="font-size:.76rem;color:#888;margin-top:3px;">
                            📅 <?php echo date('M d, Y h:i A', strtotime($item['appointment_date'])); ?>
                            &nbsp;·&nbsp; <?php echo ucfirst($item['service_type'] ?? 'onsite'); ?>
                            &nbsp;·&nbsp; <?php echo (int)($item['people_count'] ?? 1); ?> pax
                            <?php if ($item['session_time']): ?>
                                &nbsp;·&nbsp; <?php echo $item['session_time']; ?> mins
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;">1</td>
                    <td style="padding:12px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;">
                        ₱<?php echo number_format($item['price'], 2); ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;font-weight:bold;color:#3B2A1A;border-bottom:1px solid #EAD8C0;">
                        ₱<?php echo number_format($item['subtotal'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- VAT Breakdown + Total -->
        <?php
        $final_amt    = floatval($order['final_amount'] ?? $order['total_amount']);
        $disc_amt     = floatval($order['discount_amount'] ?? 0);
        $disc_type    = $order['discount_type'] ?? 'none';
        $is_exempt    = in_array($disc_type, ['senior','pwd']);
        $net_of_vat   = round($final_amt / 1.12, 2);
        $vat_amt      = round($final_amt - $net_of_vat, 2);
        $vatable       = $is_exempt ? 0 : $net_of_vat;
        $exempt_sales = $is_exempt ? $final_amt : 0;
        $disc_labels  = ['senior'=>'Senior Citizen (SC) 20%','pwd'=>'PWD 20%','employee'=>'Employee','voucher'=>'Voucher','none'=>''];
        $disc_label   = $disc_labels[$disc_type] ?? '';
        $invoice_no   = 'N°' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        ?>
        <div style="padding:1rem 1.5rem;background:#FAF3E8;border-top:2px solid #EAD8C0;">

            <!-- Invoice No -->
            <div style="display:flex;justify-content:space-between;align-items:center;
                        margin-bottom:0.85rem;padding-bottom:0.75rem;border-bottom:1px solid #EAD8C0;">
                <span style="font-size:0.78rem;color:#888;text-transform:uppercase;letter-spacing:0.05em;">Invoice No.</span>
                <span style="font-weight:700;color:#C96A2C;font-family:monospace;font-size:0.9rem;"><?php echo $invoice_no; ?></span>
            </div>

            <!-- Amount breakdown -->
            <div style="display:flex;flex-direction:column;gap:0.3rem;font-size:0.85rem;">
                <div style="display:flex;justify-content:space-between;color:#666;">
                    <span>Total Sales (VAT Inclusive)</span>
                    <span>₱<?php echo number_format($final_amt, 2); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;color:#666;">
                    <span>Less: VAT (12%)</span>
                    <span>₱<?php echo number_format($is_exempt ? 0 : $vat_amt, 2); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;color:#666;">
                    <span>Amount Net of VAT</span>
                    <span>₱<?php echo number_format($is_exempt ? $final_amt : $net_of_vat, 2); ?></span>
                </div>
                <?php if ($disc_amt > 0): ?>
                <div style="display:flex;justify-content:space-between;color:#C96A2C;">
                    <span>Less: Discount (<?php echo htmlspecialchars($disc_label); ?>)</span>
                    <span>− ₱<?php echo number_format($disc_amt, 2); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Grand Total -->
            <div style="display:flex;justify-content:space-between;align-items:center;
                        margin-top:0.85rem;padding-top:0.75rem;border-top:2px solid #EAD8C0;">
                <span style="font-size:1rem;color:#3B2A1A;font-weight:700;">TOTAL AMOUNT DUE</span>
                <span style="font-size:1.4rem;color:#C96A2C;font-weight:800;">
                    ₱<?php echo number_format($final_amt, 2); ?>
                </span>
            </div>

            <!-- VAT Summary box -->
            <div style="margin-top:0.85rem;padding:0.65rem 0.85rem;background:#fff;
                        border:1px solid #EAD8C0;border-radius:8px;">
                <div style="font-size:9px;font-weight:700;color:#888;text-transform:uppercase;
                            letter-spacing:0.5px;margin-bottom:6px;padding-bottom:4px;
                            border-bottom:1px solid #eee;">VAT Summary</div>
                <div style="display:grid;grid-template-columns:1fr auto;gap:2px 1rem;font-size:11px;color:#555;">
                    <span>VATable Sales</span>
                    <span style="text-align:right;color:#3B2A1A;">₱<?php echo number_format($vatable, 2); ?></span>
                    <span>VAT-Exempt Sales</span>
                    <span style="text-align:right;color:#3B2A1A;">₱<?php echo number_format($exempt_sales, 2); ?></span>
                    <span>Zero-Rated Sales</span>
                    <span style="text-align:right;color:#3B2A1A;">₱0.00</span>
                    <span style="font-weight:700;color:#3B2A1A;">Total VAT (12%)</span>
                    <span style="text-align:right;font-weight:700;color:#3B2A1A;">₱<?php echo number_format($is_exempt ? 0 : $vat_amt, 2); ?></span>
                </div>
            </div>

            <!-- BIR note -->
            <div style="margin-top:0.65rem;font-size:9px;color:#aaa;text-align:center;line-height:1.5;">
                Recovery Spa &amp; Massage · VAT Reg. TIN: 522-978-781-00001<br>
                M.H. Del Pilar St., Tael, Molo, Iloilo City · 0965-335-9998
            </div>
        </div>

        <!-- Print -->
        <div style="padding:.75rem 1.5rem;text-align:right;border-top:1px solid #EAD8C0;background:#fff;">
            <button onclick="window.print()"
                    style="background:transparent;border:1px solid #C96A2C;color:#C96A2C;
                           padding:.45rem 1.1rem;border-radius:8px;cursor:pointer;font-size:.85rem;">
                🖨️ Print Receipt
            </button>
        </div>
    </div>

    <?php if (!$is_refunded): ?>
    <!-- Next steps — suppressed for refunded orders (appointment already cancelled) -->
    <div style="background:#fff;border-radius:16px;padding:1.25rem 1.5rem;
                box-shadow:0 4px 20px rgba(59,42,26,0.07);border:1px solid #EAD8C0;
                margin-bottom:1.5rem;font-size:.85rem;color:#6B4C30;">
        <strong style="color:#3B2A1A;display:block;margin-bottom:.5rem;">What happens next?</strong>
        <?php if ($is_service): ?>
            <ul style="margin:.25rem 0 0 1.2rem;padding:0;line-height:1.9;">
                <li>Admin reviews and approves your appointment</li>
                <li>A therapist will be assigned to your session</li>
                <li>You'll receive a notification when approved</li>
                <li>Please arrive 10 minutes before your scheduled time</li>
            </ul>
        <?php else: ?>
            <ul style="margin:.25rem 0 0 1.2rem;padding:0;line-height:1.9;">
                <li>Admin reviews and approves your order</li>
                <li>You'll receive a notification when it's ready for pick-up</li>
                <li><?php echo $order['payment_method'] === 'online'
                        ? 'Pick up your items at the spa'
                        : 'Bring payment when you pick up your items'; ?></li>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;margin-bottom:3rem;">
        <a href="appointments.php" class="btn btn-primary" style="padding:.75rem 1.5rem;">
            📅 View My Orders
        </a>
        <a href="index.php" class="btn btn-secondary" style="padding:.75rem 1.5rem;">
            🏠 Back to Home
        </a>
    </div>

<?php else: ?>
    <!-- Pending -->
    <div style="background:#fff;border-radius:20px;padding:3rem 2rem;text-align:center;
                box-shadow:0 8px 40px rgba(59,42,26,0.10);border:1px solid #EAD8C0;">
        <div style="font-size:3rem;margin-bottom:1rem;">⏳</div>
        <h2 style="color:#C96A2C;font-family:'Cormorant Garamond',serif;font-weight:400;font-size:2rem;">
            Payment Pending
        </h2>
        <p style="color:#555;margin-bottom:.5rem;">
            We could not confirm payment for this order yet.
        </p>
        <p style="color:#888;font-size:.9rem;margin-bottom:2rem;">
            If you completed your GCash/Maya payment, please wait a moment then
            <a href="payment_success.php?order_id=<?php echo $order_id;
                ?>&token=<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>"
               style="color:#C96A2C;font-weight:bold;">click here to check again</a>.
        </p>
        <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
            <a href="appointments.php" class="btn btn-primary" style="padding:.75rem 1.5rem;">📅 View My Orders</a>
            <a href="index.php" class="btn btn-secondary" style="padding:.75rem 1.5rem;">🏠 Back to Home</a>
        </div>
    </div>
<?php endif; ?>
</div>

<style>
@media print {
    nav, header, .btn, button, footer,
    div[style*="justify-content:center"] { display: none !important; }
    body { background: white !important; }
    .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
    #receipt-card { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>