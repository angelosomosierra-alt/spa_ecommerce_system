<?php
/**
 * paymongo_webhook.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PayMongo sends POST requests here when payment events happen.
 * Register this URL in your PayMongo Dashboard → Developers → Webhooks:
 *   http://yourdomain.com/spa_ecommerce_system/user/paymongo_webhook.php
 *
 * Events to listen for: checkout_session.payment.paid (customer checkout and
 * walk-in kiosk), link.payment.paid (older Payment Links orders).
 * The webhook is the backup: if the customer pays but never comes back to
 * payment_success.php (closed the tab, lost signal), the order still gets
 * marked paid here.
 */

require_once '../config.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../send_receipt.php';

// Read raw POST body
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// ── Verify webhook signature (always enforced) ────────────────────────────
// Reject immediately if no signature header is present
if (empty($signature)) {
    paymongo_log($conn, null, 'webhook', 'rejected', 'error', 'No PayMongo signature header (not from PayMongo).');
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
    paymongo_log($conn, null, 'webhook', 'rejected', 'error', 'Malformed PayMongo signature header.');
    http_response_code(401);
    echo json_encode(['error' => 'Malformed signature']);
    exit;
}

// Reject stale / replayed webhooks — signed timestamp must be within ±5 minutes
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(401);
    error_log('[WEBHOOK] Stale timestamp rejected: ' . $timestamp);
    paymongo_log($conn, null, 'webhook', 'rejected', 'error', 'Signed time is more than 5 minutes off (replay, or server clock wrong).');
    echo json_encode(['error' => 'Timestamp out of acceptable range']);
    exit;
}

$signed_payload = $timestamp . '.' . $payload;
$expected       = hash_hmac('sha256', $signed_payload, PAYMONGO_WEBHOOK_SECRET);

// Accept either test or live signature
if (!hash_equals($expected, $test_sig) && !hash_equals($expected, $live_sig)) {
    http_response_code(401);
    error_log('[WEBHOOK] Signature mismatch — possible spoofed request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    paymongo_log($conn, null, 'webhook', 'rejected', 'error',
        PAYMONGO_WEBHOOK_SECRET === '' ? 'Webhook secret is not set in config — cannot verify PayMongo.'
                                       : 'Signature does not match the webhook secret (wrong secret, or fake request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ').');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';
$_wh_live = $event['data']['attributes']['livemode'] ?? null;
if (!in_array($type, ['checkout_session.payment.paid', 'link.payment.paid'], true)) {
    paymongo_log($conn, null, 'webhook', 'ignored', 'info', 'Event not used by the system: ' . ($type ?: '(none)'), ['livemode' => $_wh_live]);
}

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
                $_link_changed = $rows_changed;

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
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'ok',
                    !empty($_link_changed) ? 'PayMongo confirmed payment (link). Order marked paid.'
                                           : 'PayMongo confirmed payment (link); order was already paid.',
                    ['method' => $paymongo_method, 'reference' => $paymongo_ref, 'session_id' => $link_id, 'livemode' => $_wh_live]);
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[WEBHOOK] Transaction failed for order ' . $order_id . ': ' . $e->getMessage());
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'error', 'PayMongo says paid but saving failed: ' . $e->getMessage(),
                    ['reference' => $paymongo_ref, 'session_id' => $link_id]);
            }
        } else {
            paymongo_log($conn, null, 'webhook', 'paid', 'error', 'Paid link ' . $link_id . ' does not match any order.',
                ['method' => $paymongo_method, 'reference' => $paymongo_ref, 'session_id' => $link_id, 'livemode' => $_wh_live]);
        }
    }
}

// ── Handle checkout_session.payment.paid ───────────────────────────────────
// Checkout Sessions (cs_...) are what user/checkout.php and
// admin/walkin_payment.php create. Same end state as the matching success page:
//   customer order (payment_method 'online') → paid, still waits for admin approval
//   walk-in order (gcash/card/qrph at the desk) → paid and approved
if ($type === 'checkout_session.payment.paid') {
    $cs_id    = $event['data']['attributes']['data']['id']                     ?? '';
    $payments = $event['data']['attributes']['data']['attributes']['payments'] ?? [];
    $paid     = !empty($payments) && ($payments[0]['attributes']['status'] ?? '') === 'paid';

    if ($cs_id && $paid) {
        $pm_method_map   = ['gcash'=>'gcash','paymaya'=>'maya','card'=>'card',
                            'dob_ubp'=>'bank','dob'=>'bank','billease'=>'online'];
        $raw_pm_method   = $payments[0]['attributes']['source']['type']
                        ?? $payments[0]['attributes']['payment_method_used']
                        ?? null;
        $paymongo_method = $pm_method_map[$raw_pm_method] ?? $raw_pm_method ?? null;
        $paymongo_ref    = $payments[0]['attributes']['reference_number'] ?? ($payments[0]['id'] ?? $cs_id);

        ensure_online_dp_column($conn);
        $stmt = $conn->prepare("SELECT id, user_id, payment_method, total_amount, final_amount, dp_amount FROM orders WHERE paymongo_link_id = ?");
        $stmt->bind_param("s", $cs_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order && (float)($order['dp_amount'] ?? 0) > 0) {
            // Down payment for a customer's service booking (see settle_online_down_payment).
            $order_id = (int)$order['id'];
            try {
                $conn->begin_transaction();
                $rows_changed = settle_online_down_payment($conn, $order_id, $paymongo_method, $paymongo_ref);
                $conn->commit();
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'ok',
                    $rows_changed > 0 ? 'PayMongo confirmed the down payment. Saved on the booking; balance due at the spa.'
                                      : 'PayMongo confirmed the down payment; it was already saved.',
                    ['amount' => $order['dp_amount'], 'method' => $paymongo_method, 'reference' => $paymongo_ref,
                     'session_id' => $cs_id, 'livemode' => $_wh_live]);
                if ($rows_changed > 0) {
                    add_notification($conn, (int)$order['user_id'], 'appointment', 'Down Payment Received',
                        'We received your ₱' . number_format($order['dp_amount'], 2) . ' down payment for booking #' . $order_id . '. Awaiting confirmation from the spa.',
                        'appointments.php');
                    add_notification($conn, null, 'appointment', 'Online Down Payment',
                        'Booking #' . $order_id . ': ₱' . number_format($order['dp_amount'], 2) . ' down payment paid online. Ref: ' . $paymongo_ref,
                        'appointments.php');
                    send_order_receipt($conn, $order_id);
                }
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[WEBHOOK] Down payment save failed for order ' . $order_id . ': ' . $e->getMessage());
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'error', 'PayMongo says the down payment is paid but saving failed: ' . $e->getMessage(),
                    ['reference' => $paymongo_ref, 'session_id' => $cs_id, 'livemode' => $_wh_live]);
            }
        } elseif ($order) {
            $order_id    = (int)$order['id'];
            $is_customer = ($order['payment_method'] === 'online');
            $rows_changed = 0;

            $conn->begin_transaction();
            try {
                // Only the first of webhook / success page wins (pending_payment guard).
                $approval = $is_customer ? 'pending' : 'approved';
                $upd = $conn->prepare("
                    UPDATE orders
                    SET payment_status     = 'paid',
                        approval_status    = ?,
                        paymongo_method    = COALESCE(paymongo_method, ?),
                        paymongo_reference = COALESCE(paymongo_reference, ?)
                    WHERE id             = ?
                      AND payment_status = 'pending_payment'
                ");
                $upd->bind_param("sssi", $approval, $paymongo_method, $paymongo_ref, $order_id);
                $upd->execute();
                $rows_changed = $upd->affected_rows;
                $upd->close();

                if ($rows_changed > 0) {
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

                    if ($is_customer) {
                        // Paid items leave the saved cart (same as payment_success.php)
                        foreach ($products as $item) {
                            remove_cart_item_from_db($conn, (int)$order['user_id'], (int)$item['product_id']);
                        }
                    } else {
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
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $rows_changed = 0;
                error_log('[WEBHOOK] Checkout session transaction failed for order ' . $order_id . ': ' . $e->getMessage());
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'error', 'PayMongo says paid but saving failed: ' . $e->getMessage(),
                    ['reference' => $paymongo_ref, 'session_id' => $cs_id, 'livemode' => $_wh_live]);
                $_cs_failed = true;
            }
            if (empty($_cs_failed)) {
                $_charged = isset($payments[0]['attributes']['amount']) ? $payments[0]['attributes']['amount'] / 100 : null;
                paymongo_log($conn, $order_id, 'webhook', 'paid', 'ok',
                    ($rows_changed > 0 ? 'PayMongo confirmed payment. Order marked paid' . ($is_customer ? ' (waiting for admin approval).' : ' and approved.')
                                       : 'PayMongo confirmed payment; order was already marked paid.')
                    . (($_charged !== null && abs($_charged - (float)$order['final_amount']) >= 0.01)
                        ? ' Charged ₱' . number_format($_charged, 2) . ' but order total is ₱' . number_format($order['final_amount'], 2) . '.' : ''),
                    ['amount' => $_charged, 'method' => $paymongo_method, 'reference' => $paymongo_ref,
                     'session_id' => $cs_id, 'livemode' => $_wh_live]);
            }

            // Customer never came back to payment_success.php — tell them and the desk.
            if ($rows_changed > 0 && $is_customer) {
                try {
                    $svc = $conn->prepare("SELECT COUNT(*) c FROM order_items WHERE order_id = ? AND service_id IS NOT NULL");
                    $svc->bind_param("i", $order_id);
                    $svc->execute();
                    $is_svc = (int)$svc->get_result()->fetch_assoc()['c'] > 0;
                    $svc->close();

                    add_notification($conn, (int)$order['user_id'], $is_svc ? 'appointment' : 'order',
                        'Payment Confirmed!',
                        'Your online payment for order #' . $order_id . ' was successful. Awaiting admin approval.',
                        $is_svc ? 'appointments.php' : 'appointments.php#orders'
                    );
                    add_notification($conn, null, $is_svc ? 'appointment' : 'order',
                        'New Online Payment',
                        'Order #' . $order_id . ' (₱' . number_format($order['total_amount'], 2) . ') paid online. Ref: ' . $paymongo_ref,
                        $is_svc ? 'appointments.php' : 'orders.php'
                    );
                    send_order_receipt($conn, $order_id);
                } catch (Throwable $e) {
                    error_log('[WEBHOOK] Notify/receipt failed for order ' . $order_id . ': ' . $e->getMessage());
                }
            }
        } else {
            paymongo_log($conn, null, 'webhook', 'paid', 'error', 'Paid checkout ' . $cs_id . ' does not match any order.',
                ['method' => $paymongo_method, 'reference' => $paymongo_ref, 'session_id' => $cs_id, 'livemode' => $_wh_live]);
        }
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
exit;