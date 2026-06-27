<?php
/**
 * send_receipt.php — Reusable receipt email helper
 * Place at: spa_ecommerce_system/send_receipt.php  (project root)
 *
 * Usage:
 *   require_once __DIR__ . '/../send_receipt.php';
 *   send_order_receipt($conn, $order_id);
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_order_receipt($conn, $order_id) {

    // ── 1. Fetch order ────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT o.*, u.full_name, u.email AS user_email
        FROM   orders o
        JOIN   users  u ON o.user_id = u.id
        WHERE  o.id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) return false;

    // ── 2. Fetch order items ──────────────────────────────────────────────────
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
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($items)) return false;

    // ── 3. Reference number ───────────────────────────────────────────────────
    // paymongo_reference is stored in the orders table after payment confirmation.
    // Cash orders show "N/A — Cash Payment" instead.
    $reference_display = !empty($order['paymongo_reference'])
        ? htmlspecialchars($order['paymongo_reference'])
        : ($order['payment_method'] === 'online' ? '—' : 'N/A — Cash Payment');

    // ── 4. Meta ───────────────────────────────────────────────────────────────
    $is_service    = !empty($items[0]['service_id']);
    $receipt_to    = $order['email'] ?: $order['user_email'];
    $customer_name = $order['customer_name'] ?: $order['full_name'];
    $order_date    = date('F d, Y \a\t h:i A', strtotime($order['created_at']));
    $total         = number_format($order['total_amount'], 2);
    $pay_method    = $order['payment_method'] === 'online' ? '💳 Online (PayMongo)' : '🏪 Onsite / Cash';

    $pay_status_html = match($order['payment_status']) {
        'paid'   => '<span style="color:#198754;font-weight:bold;">✅ Paid</span>',
        'unpaid' => '<span style="color:#C96A2C;font-weight:bold;">🏪 Pay at Spa</span>',
        default  => htmlspecialchars($order['payment_status']),
    };

    // ── 5. Build item rows ────────────────────────────────────────────────────
    $items_html = '';
    foreach ($items as $item) {
        if (!empty($item['product_id'])) {
            $name     = htmlspecialchars($item['product_name']);
            $qty      = (int)$item['quantity'];
            $price    = number_format($item['price'], 2);
            $subtotal = number_format($item['subtotal'], 2);
            $items_html .= "
            <tr>
                <td style='padding:10px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;'>{$name}</td>
                <td style='padding:10px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;'>{$qty}</td>
                <td style='padding:10px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;'>₱{$price}</td>
                <td style='padding:10px 16px;text-align:right;font-weight:bold;color:#3B2A1A;border-bottom:1px solid #EAD8C0;'>₱{$subtotal}</td>
            </tr>";
        } elseif (!empty($item['service_id'])) {
            $name     = htmlspecialchars($item['service_name']);
            $price    = number_format($item['price'], 2);
            $appt_dt  = !empty($item['appointment_date'])
                        ? date('F d, Y \a\t h:i A', strtotime($item['appointment_date']))
                        : 'TBD';
            $svc_type = ucfirst($item['service_type'] ?? 'onsite');
            $people   = (int)($item['people_count'] ?? 1);
            $duration = $item['session_time'] ? $item['session_time'] . ' mins' : '';
            $items_html .= "
            <tr>
                <td style='padding:10px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;'>
                    {$name}
                    <div style='font-size:11px;color:#888;margin-top:3px;'>
                        📅 {$appt_dt} &nbsp;·&nbsp; {$svc_type} &nbsp;·&nbsp; {$people} pax
                        " . ($duration ? "&nbsp;·&nbsp; {$duration}" : "") . "
                    </div>
                </td>
                <td style='padding:10px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;'>1</td>
                <td style='padding:10px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;'>₱{$price}</td>
                <td style='padding:10px 16px;text-align:right;font-weight:bold;color:#3B2A1A;border-bottom:1px solid #EAD8C0;'>₱{$price}</td>
            </tr>";
        }
    }

    // ── 6. Full email HTML ────────────────────────────────────────────────────
    $html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#F4E7D3;font-family:Arial,sans-serif;'>
<div style='max-width:600px;margin:30px auto;background:#fff;border-radius:16px;
            overflow:hidden;box-shadow:0 4px 24px rgba(59,42,26,0.12);'>

    <div style='background:linear-gradient(135deg,#3B2A1A,#6B4C30);padding:32px 24px;text-align:center;'>
        <div style='font-size:2.2rem;margin-bottom:6px;'>💆</div>
        <h1 style='color:#FAF3E8;margin:0;font-size:1.6rem;letter-spacing:2px;font-weight:300;'>RECOVERY SPA</h1>
        <p style='color:#C8A46B;margin:4px 0 0;font-size:0.85rem;letter-spacing:1px;'>Official Receipt</p>
    </div>

    <div style='background:#FAF3E8;padding:20px 24px;border-bottom:1px solid #EAD8C0;'>
        <table style='width:100%;font-size:13px;'>
            <tr>
                <td style='color:#888;padding:5px 0;'>Receipt for</td>
                <td style='color:#3B2A1A;font-weight:bold;text-align:right;'>" . htmlspecialchars($customer_name) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:5px 0;'>Reference no.</td>
                <td style='color:#C96A2C;font-weight:bold;text-align:right;font-family:monospace;font-size:14px;'>
                    {$reference_display}
                </td>
            </tr>
            <tr>
                <td style='color:#888;padding:5px 0;'>Order type</td>
                <td style='color:#3B2A1A;text-align:right;'>" . ($is_service ? '📅 Service Appointment' : '🛍️ Product Order') . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:5px 0;'>Date placed</td>
                <td style='color:#3B2A1A;text-align:right;'>{$order_date}</td>
            </tr>
            <tr>
                <td style='color:#888;padding:5px 0;'>Payment method</td>
                <td style='color:#3B2A1A;text-align:right;'>{$pay_method}</td>
            </tr>
            <tr>
                <td style='color:#888;padding:5px 0;'>Payment status</td>
                <td style='text-align:right;'>{$pay_status_html}</td>
            </tr>
        </table>
    </div>

    <table style='width:100%;border-collapse:collapse;font-size:13px;'>
        <thead>
            <tr style='background:#EAD8C0;'>
                <th style='padding:10px 16px;text-align:left;color:#3B2A1A;'>Item</th>
                <th style='padding:10px 16px;text-align:center;color:#3B2A1A;'>Qty</th>
                <th style='padding:10px 16px;text-align:right;color:#3B2A1A;'>Unit Price</th>
                <th style='padding:10px 16px;text-align:right;color:#3B2A1A;'>Subtotal</th>
            </tr>
        </thead>
        <tbody>{$items_html}</tbody>
    </table>

    <div style='padding:16px 24px;background:#FAF3E8;border-top:2px solid #EAD8C0;'>
        <table style='width:100%;'>
            <tr>
                <td style='font-size:1rem;color:#3B2A1A;font-weight:bold;'>Total Amount</td>
                <td style='font-size:1.3rem;color:#C96A2C;font-weight:bold;text-align:right;'>₱{$total}</td>
            </tr>
        </table>
    </div>

    <div style='padding:20px 24px;font-size:12px;color:#888;border-top:1px solid #EAD8C0;'>
        <strong style='color:#3B2A1A;display:block;margin-bottom:8px;'>What happens next?</strong>
        " . ($is_service
            ? "<p style='margin:0;line-height:1.7;'>Your appointment is awaiting confirmation. Please arrive 10 minutes early.</p>"
            : "<p style='margin:0;line-height:1.7;'>Your order is pending admin approval. You'll be notified when it is ready for pick-up.</p>") . "
    </div>

    <div style='background:#3B2A1A;padding:20px 24px;text-align:center;'>
        <p style='color:#C8A46B;margin:0;font-size:12px;letter-spacing:1px;'>Thank you for choosing Recovery Spa</p>
        <p style='color:#6B4C30;margin:6px 0 0;font-size:11px;'>This is an automated receipt. Please keep it for your records.</p>
    </div>
</div>
</body></html>";

    // ── 7. Plain text fallback ────────────────────────────────────────────────
    $plain  = "Recovery Spa — Official Receipt\n" . str_repeat('-', 40) . "\n";
    $plain .= "Reference No.: {$reference_display}\n";
    $plain .= "Customer: {$customer_name}\n";
    $plain .= "Date: {$order_date}\n";
    $plain .= "Payment: {$pay_method}\n";
    $plain .= "Total: ₱{$total}\n";
    $plain .= str_repeat('-', 40) . "\nThank you for choosing Recovery Spa!\n";

    // ── 8. Send via PHPMailer ─────────────────────────────────────────────────
    require_once __DIR__ . '/_mailer.php';
    try {
        $mail = make_mailer();
        $mail->addAddress($receipt_to, $customer_name);
        $mail->isHTML(true);
        $mail->Subject = "Recovery Spa — Receipt (Ref: {$reference_display})";
        $mail->Body    = $html;
        $mail->AltBody = $plain;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('[MAIL] Receipt failed for order #' . $order_id . ': '
            . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}