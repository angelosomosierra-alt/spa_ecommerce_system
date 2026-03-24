<?php
require_once '../config.php';
redirect_if_not_user();

$user_id  = $_SESSION['user_id'];
$order_id = intval($_GET['order_id'] ?? $_SESSION['paymongo_order_id'] ?? 0);

$payment_confirmed = false;
$already_paid      = false;

if ($order_id) {
    // Fetch the order and its paymongo_link_id
    $stmt = $conn->prepare("SELECT payment_status, paymongo_link_id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($order) {
        // Already confirmed on a previous visit
        if ($order['payment_status'] === 'paid') {
            $payment_confirmed = true;
            $already_paid      = true;

        } elseif ($order['payment_status'] === 'pending_payment' && $order['paymongo_link_id']) {
            // Verify with PayMongo API using the stored link ID
            $link_id = $order['paymongo_link_id'];
            $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($link_id));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                ],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response, true);
            $status = $result['data']['attributes']['status'] ?? '';

            if ($status === 'paid') {
                $payment_confirmed = true;

                // Mark order as paid — approval_status stays 'pending' for admin to review
                $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', approval_status = 'pending' WHERE id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();

                // Do NOT auto-approve appointments — admin must approve the order first

                // Deduct stock now that payment is confirmed
                $checkout_items = $_SESSION['paymongo_checkout_items'] ?? [];
                foreach ($checkout_items as $item) {
                    if (($item['type'] ?? '') === 'product') {
                        $upd = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                        $upd->bind_param("ii", $item['quantity'], $item['id']);
                        $upd->execute();
                        $upd->close();
                    }
                }

                // Clear cart now that payment is confirmed
                $checkout_type = $_SESSION['paymongo_checkout_type'] ?? '';
                if ($checkout_type === 'cart' && !empty($_SESSION['checkout_item_ids'])) {
                    foreach ($_SESSION['checkout_item_ids'] as $pid) {
                        unset($_SESSION['cart'][$pid]);
                        remove_cart_item_from_db($conn, $user_id, $pid);
                    }
                }
                unset(
                    $_SESSION['direct_checkout'],
                    $_SESSION['service_booking'],
                    $_SESSION['checkout_items'],
                    $_SESSION['checkout_item_ids']
                );
                if (!empty($_SESSION['cart'])) {
                    sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
                }

                // Clean up PayMongo session vars
                unset(
                    $_SESSION['paymongo_order_id'],
                    $_SESSION['paymongo_checkout_type'],
                    $_SESSION['paymongo_checkout_items']
                );
            }
        }
    }
}

$page_title = 'Payment Successful';
require_once 'header.php';
?>

<div class="container" style="max-width:560px; margin:5rem auto; text-align:center;">
    <div style="background:#fff; border-radius:16px; padding:3rem 2rem; box-shadow:0 8px 32px rgba(0,0,0,0.08);">

        <?php if ($payment_confirmed): ?>
            <div style="font-size:4rem; margin-bottom:1rem;">🎉</div>
            <h2 style="color:#198754; margin-bottom:0.5rem;">Payment Successful!</h2>
            <p style="color:#555; margin-bottom:0.5rem;">
                Your order <strong style="color:#3B2A1A;">#<?php echo $order_id; ?></strong>
                has been paid successfully.
            </p>
            <p style="color:#888; font-size:0.9rem; margin-bottom:2rem;">
                🕐 Your order is now <strong>pending admin approval</strong>. You will be notified once it is confirmed.
            </p>
        <?php else: ?>
            <div style="font-size:4rem; margin-bottom:1rem;">⏳</div>
            <h2 style="color:#C96A2C; margin-bottom:0.5rem;">Payment Pending</h2>
            <p style="color:#555; margin-bottom:0.5rem;">
                We could not confirm your payment yet.
            </p>
            <p style="color:#888; font-size:0.9rem; margin-bottom:2rem;">
                If you completed the GCash/Maya payment, please wait a moment and
                <a href="payment_success.php?order_id=<?php echo $order_id; ?>" style="color:#C96A2C; font-weight:bold;">click here to check again</a>.
            </p>
        <?php endif; ?>

        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
            <a href="appointments.php" class="btn btn-primary" style="padding:0.75rem 1.5rem;">
                📅 View Appointments
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding:0.75rem 1.5rem;">
                🏠 Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>