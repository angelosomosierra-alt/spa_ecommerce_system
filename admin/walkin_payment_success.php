<?php
/**
 * walkin_payment_success.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Shown INSIDE the PayMongo popup window after a walk-in payment succeeds.
 *
 * This page:
 *   1. Verifies the HMAC token (same pattern as payment_success.php)
 *   2. Marks the order as 'paid' and appointment as 'approved'
 *   3. Shows a brief success screen
 *   4. Signals the PARENT window (walkin.php) to refresh via postMessage
 *   5. Closes itself after 3 seconds
 */

require_once '../config.php';
redirect_if_not_admin();

$order_id = intval($_GET['order_id'] ?? 0);
$token    = $_GET['token'] ?? '';

if (!$order_id || empty($token)) {
    die('<p style="font-family:sans-serif;padding:2rem;color:red;">Invalid payment confirmation link.</p>');
}

// ── Fetch order & verify HMAC token ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, user_id, payment_status, customer_name, final_amount, payment_method FROM orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    die('<p style="font-family:sans-serif;padding:2rem;color:red;">Order not found.</p>');
}

$expected_token = hash_hmac('sha256', $order_id . '|' . $order['user_id'], APP_SECRET);
if (!hash_equals($expected_token, $token)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem;color:red;">Access denied.</p>');
}

// ── Verify with PayMongo that payment is actually paid ────────────────────────
// (Fetch the order's paymongo_link_id and call the API to verify status)
$stmt = $conn->prepare("SELECT paymongo_link_id FROM orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$link_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$verified   = false;
$pm_method  = null;
$pm_ref     = null;

if (!empty($link_row['paymongo_link_id'])) {
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . urlencode($link_row['paymongo_link_id']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_SSL_VERIFYPEER => (APP_ENV === 'production'),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if ($resp) {
        $data   = json_decode($resp, true);
        $status = $data['data']['attributes']['status'] ?? '';
        if ($status === 'completed') {
            $verified  = true;
            $payments  = $data['data']['attributes']['payments'] ?? [];
            $pm_method_map = ['gcash'=>'gcash','paymaya'=>'maya','card'=>'card'];
            $raw_pm         = $payments[0]['attributes']['source']['type']
                           ?? $payments[0]['attributes']['payment_method_used'] ?? null;
            $pm_method = $pm_method_map[$raw_pm] ?? $raw_pm ?? null;
            $pm_ref    = $payments[0]['attributes']['reference_number'] ?? null;
        }
    }
}

// ── Update order & appointment if verified ────────────────────────────────────
$already_paid = ($order['payment_status'] === 'paid');

if ($verified && !$already_paid) {
    $conn->begin_transaction();
    try {
        // Mark order paid + record actual PayMongo method
        $upd = $conn->prepare("
            UPDATE orders
            SET payment_status     = 'paid',
                approval_status    = 'approved',
                paymongo_method    = COALESCE(paymongo_method, ?),
                paymongo_reference = COALESCE(paymongo_reference, ?)
            WHERE id = ?
        ");
        $upd->bind_param("ssi", $pm_method, $pm_ref, $order_id);
        $upd->execute(); $upd->close();

        // Approve linked appointments
        $conn->query("
            UPDATE appointments a
            INNER JOIN order_items oi ON a.order_item_id = oi.id
            SET a.status = 'approved'
            WHERE oi.order_id = $order_id
        ");

        // Deduct stock for products
        $conn->query("
            UPDATE products p
            INNER JOIN order_items oi ON oi.product_id = p.id
            SET p.stock = p.stock - oi.quantity
            WHERE oi.order_id = $order_id AND p.stock >= oi.quantity
        ");

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[WALKIN_SUCCESS] Failed to mark order paid: ' . $e->getMessage());
    }
}

$display_name   = htmlspecialchars($order['customer_name']);
$display_amount = '₱' . number_format($order['final_amount'], 2);
$method_labels  = ['gcash'=>'GCash','paymaya'=>'Maya','maya'=>'Maya','card'=>'Card'];
$method_label   = $method_labels[$pm_method ?? $order['payment_method']] ?? strtoupper($order['payment_method']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Confirmed</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
  }
  .card {
    background: #fff;
    border-radius: 20px;
    padding: 2.5rem 2rem;
    max-width: 400px;
    width: 100%;
    text-align: center;
    box-shadow: 0 8px 40px rgba(0,0,0,0.10);
  }
  .check-circle {
    width: 80px; height: 80px;
    background: #22c55e;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.5rem;
    animation: pop 0.4s cubic-bezier(0.34,1.56,0.64,1);
  }
  @keyframes pop { from { transform: scale(0); opacity:0; } to { transform: scale(1); opacity:1; } }
  .check-circle svg { width:40px; height:40px; stroke:#fff; fill:none; stroke-width:3; stroke-linecap:round; stroke-linejoin:round; }
  h1 { font-size: 1.5rem; color: #166534; margin-bottom: 0.5rem; }
  .subtitle { color: #4b7a5e; font-size: 0.92rem; margin-bottom: 1.5rem; }
  .amount {
    font-size: 2.2rem; font-weight: 800; color: #15803d;
    margin-bottom: 0.25rem;
  }
  .customer { font-size: 1rem; color: #555; margin-bottom: 1.5rem; }
  .detail-row {
    display: flex; justify-content: space-between;
    padding: 0.6rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.88rem;
    color: #555;
  }
  .detail-row:last-child { border-bottom: none; }
  .detail-row strong { color: #222; }
  .details-box { background: #f9fafb; border-radius: 12px; padding: 0.5rem 1rem; margin-bottom: 1.5rem; text-align:left; }
  .close-note {
    font-size: 0.78rem; color: #9ca3af; margin-top: 1.25rem;
  }
  .progress-bar {
    height: 3px; background: #e5e7eb; border-radius: 2px;
    margin-top: 0.75rem; overflow: hidden;
  }
  .progress-fill {
    height: 100%; background: #22c55e; border-radius: 2px;
    animation: shrink 3s linear forwards;
  }
  @keyframes shrink { from { width:100%; } to { width:0%; } }
</style>
</head>
<body>
<div class="card">
  <div class="check-circle">
    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  </div>
  <h1>Payment Confirmed!</h1>
  <p class="subtitle">Walk-in payment was processed successfully.</p>

  <div class="amount"><?php echo $display_amount; ?></div>
  <div class="customer"><?php echo $display_name; ?></div>

  <div class="details-box">
    <div class="detail-row">
      <span>Order #</span>
      <strong><?php echo $order_id; ?></strong>
    </div>
    <div class="detail-row">
      <span>Method</span>
      <strong><?php echo htmlspecialchars($method_label); ?></strong>
    </div>
    <?php if ($pm_ref): ?>
    <div class="detail-row">
      <span>Reference</span>
      <strong><?php echo htmlspecialchars($pm_ref); ?></strong>
    </div>
    <?php endif; ?>
    <div class="detail-row">
      <span>Status</span>
      <strong style="color:#15803d;">✅ Paid</strong>
    </div>
  </div>

  <p class="close-note">This window will close automatically…</p>
  <div class="progress-bar"><div class="progress-fill"></div></div>
</div>

<script>
// Tell the parent walkin.php window that payment succeeded
if (window.opener && !window.opener.closed) {
    window.opener.postMessage({
        type: 'walkin_payment_success',
        order_id: <?php echo $order_id; ?>,
        customer: <?php echo json_encode($order['customer_name']); ?>,
        amount: <?php echo json_encode($display_amount); ?>,
        method: <?php echo json_encode($method_label); ?>
    }, '*');
}

// Close popup after 3 seconds
setTimeout(() => {
    window.close();
    // Fallback if close() is blocked
    if (!window.closed) {
        document.body.innerHTML = '<div style="font-family:sans-serif;padding:2rem;text-align:center;">'
            + '<p style="color:#166534;font-size:1.1rem;">✅ Payment confirmed! You can close this window.</p></div>';
    }
}, 3000);
</script>
</body>
</html>