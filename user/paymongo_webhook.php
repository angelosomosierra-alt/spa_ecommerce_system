<?php
/**
 * paymongo_webhook.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PayMongo sends POST requests here when payment events happen.
 * Register this URL in your PayMongo Dashboard → Developers → Webhooks:
 *   http://yourdomain.com/spa_ecommerce_system/user/paymongo_webhook.php
 *
 * Events to listen for: link.payment.paid
 */

require_once '../config.php';

// Read raw POST body
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// ── Verify webhook signature ───────────────────────────────────────────────
if (!empty(PAYMONGO_WEBHOOK_SECRET) && !empty($signature)) {
    // PayMongo signature format: t=timestamp,te=test_sig,li=live_sig
    parse_str(str_replace(',', '&', $signature), $sig_parts);
    $timestamp   = $sig_parts['t']  ?? '';
    $test_sig    = $sig_parts['te'] ?? '';
    $live_sig    = $sig_parts['li'] ?? '';

    $signed_payload  = $timestamp . '.' . $payload;
    $expected        = hash_hmac('sha256', $signed_payload, PAYMONGO_WEBHOOK_SECRET);

    // Accept either test or live signature
    if ($expected !== $test_sig && $expected !== $live_sig) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';

// ── Handle link.payment.paid ───────────────────────────────────────────────
if ($type === 'link.payment.paid') {
    $link_id = $event['data']['attributes']['data']['id']                        ?? '';
    $status  = $event['data']['attributes']['data']['attributes']['status']      ?? '';

    if ($status === 'paid' && $link_id) {
        // Find the order by paymongo_link_id
        $stmt = $conn->prepare("SELECT id FROM orders WHERE paymongo_link_id = ?");
        $stmt->bind_param("s", $link_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order) {
            $order_id = $order['id'];

            // Mark order as paid
            $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();

            // Approve linked appointments
            $stmt = $conn->prepare("
                UPDATE appointments a
                INNER JOIN order_items oi ON a.order_item_id = oi.id
                SET a.status = 'approved'
                WHERE oi.order_id = ?
            ");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
exit;