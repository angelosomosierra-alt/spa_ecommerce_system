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

// ── Verify webhook signature (always enforced) ────────────────────────────
// Reject immediately if no signature header is present
if (empty($signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

// PayMongo signature format: t=timestamp,te=test_sig,li=live_sig
parse_str(str_replace(',', '&', $signature), $sig_parts);
$timestamp = $sig_parts['t']  ?? '';
$test_sig  = $sig_parts['te'] ?? '';
$live_sig  = $sig_parts['li'] ?? '';

if (empty($timestamp)) {
    http_response_code(401);
    echo json_encode(['error' => 'Malformed signature']);
    exit;
}

// Reject stale / replayed webhooks — signed timestamp must be within ±5 minutes
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(401);
    error_log('[WEBHOOK] Stale timestamp rejected: ' . $timestamp);
    echo json_encode(['error' => 'Timestamp out of acceptable range']);
    exit;
}

$signed_payload = $timestamp . '.' . $payload;
$expected       = hash_hmac('sha256', $signed_payload, PAYMONGO_WEBHOOK_SECRET);

// Accept either test or live signature
if (!hash_equals($expected, $test_sig) && !hash_equals($expected, $live_sig)) {
    http_response_code(401);
    error_log('[WEBHOOK] Signature mismatch — possible spoofed request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';

// ── Handle link.payment.paid ───────────────────────────────────────────────
if ($type === 'link.payment.paid') {
    $link_id   = $event['data']['attributes']['data']['id']                        ?? '';
    $status    = $event['data']['attributes']['data']['attributes']['status']      ?? '';
    $payments  = $event['data']['attributes']['data']['attributes']['payments']    ?? [];

    if ($status === 'paid' && $link_id) {
        // Extract actual payment method used (gcash / card / paymaya)
        $pm_method_map   = ['gcash'=>'gcash','paymaya'=>'maya','card'=>'card',
                            'dob_ubp'=>'bank','dob'=>'bank','billease'=>'online'];
        $raw_pm_method   = $payments[0]['attributes']['source']['type']
                        ?? $payments[0]['attributes']['payment_method_used']
                        ?? null;
        $paymongo_method = $pm_method_map[$raw_pm_method] ?? $raw_pm_method ?? null;
        $paymongo_ref    = $payments[0]['attributes']['reference_number'] ?? null;

        // Find the order by paymongo_link_id
        $stmt = $conn->prepare("SELECT id FROM orders WHERE paymongo_link_id = ?");
        $stmt->bind_param("s", $link_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order) {
            $order_id = $order['id'];

            $conn->begin_transaction();
            try {
                // Guard on payment_status = 'pending_payment' so only the first
                // request wins when the webhook and payment_success.php race.
                // Mirrors the pattern in payment_success.php.
                $upd = $conn->prepare("
                    UPDATE orders
                    SET payment_status     = 'paid',
                        paymongo_method    = COALESCE(paymongo_method, ?),
                        paymongo_reference = COALESCE(paymongo_reference, ?)
                    WHERE id             = ?
                      AND payment_status = 'pending_payment'
                ");
                $upd->bind_param("ssi", $paymongo_method, $paymongo_ref, $order_id);
                $upd->execute();
                $rows_changed = $upd->affected_rows;
                $upd->close();

                if ($rows_changed > 0) {
                    // Stock deduction — only the winning transition does this
                    $items = $conn->prepare("
                        SELECT product_id, quantity FROM order_items
                        WHERE order_id = ? AND product_id IS NOT NULL
                    ");
                    $items->bind_param("i", $order_id);
                    $items->execute();
                    $products = $items->get_result()->fetch_all(MYSQLI_ASSOC);
                    $items->close();

                    foreach ($products as $item) {
                        $upd2 = $conn->prepare("
                            UPDATE products SET stock = stock - ?
                            WHERE id = ? AND stock >= ?
                        ");
                        $upd2->bind_param("iii", $item['quantity'], $item['product_id'], $item['quantity']);
                        $upd2->execute();
                        $upd2->close();
                    }

                    // Approve linked appointments
                    $appt = $conn->prepare("
                        UPDATE appointments a
                        INNER JOIN order_items oi ON a.order_item_id = oi.id
                        SET a.status = 'approved'
                        WHERE oi.order_id = ?
                    ");
                    $appt->bind_param("i", $order_id);
                    $appt->execute();
                    $appt->close();
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[WEBHOOK] Transaction failed for order ' . $order_id . ': ' . $e->getMessage());
            }
        }
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
exit;