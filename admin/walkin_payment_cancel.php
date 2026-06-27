<?php
/**
 * walkin_payment_cancel.php
 * Shown inside the popup if the customer cancels or the payment fails.
 * Signals the parent window and closes the popup.
 */
require_once '../config.php';
redirect_if_not_admin();

$order_id = intval($_GET['order_id'] ?? 0);

// Clean up the pending order so stock/appointments don't stay reserved
if ($order_id) {
    // Delete related appointment_therapists
    $conn->query("DELETE at FROM appointment_therapists at
                  INNER JOIN appointments a ON at.appointment_id = a.order_item_id
                  INNER JOIN order_items oi ON a.order_item_id = oi.id
                  WHERE oi.order_id = $order_id");
    // Delete appointments
    $conn->query("DELETE a FROM appointments a
                  INNER JOIN order_items oi ON a.order_item_id = oi.id
                  WHERE oi.order_id = $order_id");
    // Delete order items
    $conn->query("DELETE FROM order_items WHERE order_id = $order_id");
    // Delete order itself (only if still pending)
    $conn->query("DELETE FROM orders WHERE id = $order_id AND payment_status = 'pending_payment'");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Cancelled</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #fff7f7 0%, #fee2e2 100%);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 2rem;
  }
  .card {
    background: #fff; border-radius: 20px; padding: 2.5rem 2rem;
    max-width: 380px; width: 100%; text-align: center;
    box-shadow: 0 8px 40px rgba(0,0,0,0.10);
  }
  .x-circle {
    width: 80px; height: 80px; background: #ef4444; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.5rem;
    animation: pop 0.4s cubic-bezier(0.34,1.56,0.64,1);
  }
  @keyframes pop { from { transform:scale(0); opacity:0; } to { transform:scale(1); opacity:1; } }
  .x-circle svg { width:38px; height:38px; stroke:#fff; fill:none; stroke-width:3; stroke-linecap:round; }
  h1 { font-size:1.4rem; color:#991b1b; margin-bottom:0.5rem; }
  p  { color:#666; font-size:0.9rem; line-height:1.6; margin-bottom:1.25rem; }
  .close-note { font-size:0.78rem; color:#9ca3af; }
  .progress-bar { height:3px; background:#fecaca; border-radius:2px; margin-top:0.6rem; overflow:hidden; }
  .progress-fill { height:100%; background:#ef4444; animation:shrink 3s linear forwards; }
  @keyframes shrink { from{width:100%} to{width:0%} }
</style>
</head>
<body>
<div class="card">
  <div class="x-circle">
    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </div>
  <h1>Payment Cancelled</h1>
  <p>The payment was not completed. The order has been removed.<br>You can try again with a different payment method.</p>
  <p class="close-note">This window will close automatically…</p>
  <div class="progress-bar"><div class="progress-fill"></div></div>
</div>
<script>
if (window.opener && !window.opener.closed) {
    window.opener.postMessage({
        type: 'walkin_payment_cancelled',
        order_id: <?php echo $order_id; ?>
    }, '*');
}
setTimeout(() => { window.close(); }, 3000);
</script>
</body>
</html>