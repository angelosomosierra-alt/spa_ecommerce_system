<?php
/**
 * send_receipt.php — BIR-compliant receipt email helper
 * Usage: send_order_receipt($conn, $order_id);
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
        SELECT oi.*,
               p.name  AS product_name,
               s.name  AS service_name, s.session_time,
               a.appointment_date, a.service_type, a.people_count
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

    // ── 3. Meta ───────────────────────────────────────────────────────────────
    $is_service    = !empty($items[0]['service_id']);
    $receipt_to    = $order['email'] ?: $order['user_email'];
    $customer_name = $order['customer_name'] ?: $order['full_name'];
    $order_date    = date('F d, Y \a\t h:i A', strtotime($order['created_at']));
    $invoice_no    = 'N°' . str_pad($order_id, 6, '0', STR_PAD_LEFT);

    // ── 4. VAT Computation (12% VAT inclusive) ────────────────────────────────
    $gross_amount   = floatval($order['total_amount']);
    $discount_amount = floatval($order['discount_amount'] ?? 0);
    $discount_type  = $order['discount_type'] ?? 'none';
    $final_amount   = floatval($order['final_amount'] ?? $gross_amount);

    // VAT-inclusive breakdown
    $vat_rate       = 0.12;
    $net_of_vat     = round($final_amount / (1 + $vat_rate), 2);
    $vat_amount     = round($final_amount - $net_of_vat, 2);

    // VATable vs Zero-rated vs VAT-Exempt
    // SC/PWD are VAT exempt on their purchases
    $is_vat_exempt  = in_array($discount_type, ['senior', 'pwd']);
    $vatable_sales  = $is_vat_exempt ? 0 : $net_of_vat;
    $exempt_sales   = $is_vat_exempt ? $final_amount : 0;
    $zero_rated     = 0;
    $vat_display    = $is_vat_exempt ? 0 : $vat_amount;

    // Payment method label
    $pm_labels = [
        'cash'    => 'Cash',
        'gcash'   => 'GCash',
        'paymaya' => 'Maya',
        'maya'    => 'Maya',
        'card'    => 'Credit/Debit Card',
        'qrph'    => 'QR Ph',
        'bank'    => 'Bank Transfer',
        'online'  => 'Online (PayMongo)',
    ];
    $pay_method = $pm_labels[$order['payment_method']] ?? ucfirst($order['payment_method']);

    // Discount label
    $disc_labels = [
        'senior'   => 'Senior Citizen (SC) 20%',
        'pwd'      => 'PWD 20%',
        'employee' => 'Employee Discount',
        'voucher'  => 'Voucher',
        'none'     => '',
    ];
    $disc_label = $disc_labels[$discount_type] ?? '';

    // Reference
    $reference_display = !empty($order['paymongo_reference'])
        ? htmlspecialchars($order['paymongo_reference'])
        : ($order['payment_method'] === 'online' ? '—' : 'N/A');

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
                <td style='padding:8px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;font-size:13px;'>{$name}</td>
                <td style='padding:8px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;font-size:13px;'>{$qty}</td>
                <td style='padding:8px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;font-size:13px;'>₱{$price}</td>
                <td style='padding:8px 16px;text-align:right;font-weight:600;color:#3B2A1A;border-bottom:1px solid #EAD8C0;font-size:13px;'>₱{$subtotal}</td>
            </tr>";
        } elseif (!empty($item['service_id'])) {
            $name    = htmlspecialchars($item['service_name']);
            $price   = number_format($item['price'], 2);
            $appt_dt = !empty($item['appointment_date'])
                       ? date('M d, Y h:i A', strtotime($item['appointment_date']))
                       : 'TBD';
            $svc_type = ucfirst($item['service_type'] ?? 'onsite');
            $people   = (int)($item['people_count'] ?? 1);
            $duration = $item['session_time'] ? $item['session_time'] . ' mins' : '';
            $items_html .= "
            <tr>
                <td style='padding:8px 16px;color:#3B2A1A;border-bottom:1px solid #EAD8C0;font-size:13px;'>
                    {$name}
                    <div style='font-size:11px;color:#888;margin-top:2px;'>
                        📅 {$appt_dt} &nbsp;·&nbsp; {$svc_type} &nbsp;·&nbsp; {$people} pax
                        " . ($duration ? "&nbsp;·&nbsp; {$duration}" : "") . "
                    </div>
                </td>
                <td style='padding:8px 16px;text-align:center;color:#555;border-bottom:1px solid #EAD8C0;font-size:13px;'>1</td>
                <td style='padding:8px 16px;text-align:right;color:#555;border-bottom:1px solid #EAD8C0;font-size:13px;'>₱{$price}</td>
                <td style='padding:8px 16px;text-align:right;font-weight:600;color:#3B2A1A;border-bottom:1px solid #EAD8C0;font-size:13px;'>₱{$price}</td>
            </tr>";
        }
    }

    // ── 6. Full BIR-Compliant Email HTML ──────────────────────────────────────
    $html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#F4E7D3;font-family:Arial,sans-serif;'>
<div style='max-width:620px;margin:30px auto;background:#fff;border-radius:12px;
            overflow:hidden;box-shadow:0 4px 24px rgba(59,42,26,0.12);border:1px solid #EAD8C0;'>

    <!-- ── HEADER ── -->
    <div style='background:#3B2A1A;padding:28px 24px;text-align:center;'>
        <h1 style='color:#C8A46B;margin:0 0 2px;font-size:1.4rem;letter-spacing:3px;font-weight:700;'>RECOVERY SPA &amp; MASSAGE</h1>
        <p style='color:rgba(250,243,232,0.7);margin:0;font-size:11px;letter-spacing:1px;'>Operated by: Memory Corp.</p>
        <p style='color:rgba(250,243,232,0.6);margin:2px 0 0;font-size:11px;'>M.H. Del Pilar St., Tael, Molo, Iloilo City &nbsp;·&nbsp; 0965-335-9998</p>
        <p style='color:rgba(200,164,107,0.8);margin:4px 0 0;font-size:11px;letter-spacing:0.5px;'>VAT Reg. TIN: 522-978-781-00001</p>
    </div>

    <!-- ── INVOICE LABEL ── -->
    <div style='background:#C96A2C;padding:10px 24px;display:flex;justify-content:space-between;align-items:center;'>
        <span style='color:#fff;font-weight:700;font-size:0.95rem;letter-spacing:1px;'>SERVICE INVOICE</span>
        <span style='color:#fff;font-weight:700;font-size:0.95rem;font-family:monospace;'>{$invoice_no}</span>
    </div>

    <!-- ── SOLD TO / DATE ── -->
    <div style='padding:16px 24px;background:#FAF3E8;border-bottom:1px solid #EAD8C0;'>
        <table style='width:100%;font-size:12px;'>
            <tr>
                <td style='width:50%;vertical-align:top;'>
                    <div style='color:#888;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;'>Sold To</div>
                    <div style='color:#3B2A1A;font-weight:700;font-size:14px;'>" . htmlspecialchars($customer_name) . "</div>
                    " . (!empty($order['phone']) ? "<div style='color:#555;font-size:12px;margin-top:2px;'>" . htmlspecialchars($order['phone']) . "</div>" : "") . "
                    " . ($disc_label ? "<div style='margin-top:4px;'><span style='background:#fff3cd;color:#856404;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid #ffc107;'>{$disc_label}</span></div>" : "") . "
                </td>
                <td style='width:50%;text-align:right;vertical-align:top;'>
                    <div style='color:#888;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;'>Date</div>
                    <div style='color:#3B2A1A;font-weight:600;font-size:13px;'>" . date('M d, Y', strtotime($order['created_at'])) . "</div>
                    <div style='color:#555;font-size:11px;margin-top:2px;'>" . date('h:i A', strtotime($order['created_at'])) . "</div>
                    <div style='margin-top:6px;'>
                        <span style='background:#" . ($order['payment_status']==='paid' ? 'd4edda;color:#155724' : 'fff3cd;color:#856404') . ";font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;'>
                            " . ($order['payment_status']==='paid' ? '✅ PAID' : '🏪 PAY AT SPA') . "
                        </span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- ── ITEMS TABLE ── -->
    <table style='width:100%;border-collapse:collapse;font-size:13px;'>
        <thead>
            <tr style='background:#EAD8C0;'>
                <th style='padding:10px 16px;text-align:left;color:#3B2A1A;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;'>Item / Nature of Service</th>
                <th style='padding:10px 16px;text-align:center;color:#3B2A1A;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;'>Qty</th>
                <th style='padding:10px 16px;text-align:right;color:#3B2A1A;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;'>Unit Price</th>
                <th style='padding:10px 16px;text-align:right;color:#3B2A1A;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;'>Amount</th>
            </tr>
        </thead>
        <tbody>{$items_html}</tbody>
    </table>

    <!-- ── VAT BREAKDOWN ── -->
    <div style='padding:16px 24px;background:#FAF3E8;border-top:2px solid #EAD8C0;'>
        <table style='width:100%;font-size:12px;'>
            <tr>
                <td style='color:#888;padding:3px 0;'>Total Sales (VAT Inclusive)</td>
                <td style='color:#3B2A1A;font-weight:600;text-align:right;padding:3px 0;'>₱" . number_format($final_amount, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:3px 0;'>Less: VAT</td>
                <td style='color:#3B2A1A;text-align:right;padding:3px 0;'>₱" . number_format($vat_display, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:3px 0;'>Amount Net of VAT</td>
                <td style='color:#3B2A1A;text-align:right;padding:3px 0;'>₱" . number_format($is_vat_exempt ? $final_amount : $net_of_vat, 2) . "</td>
            </tr>
            " . ($discount_amount > 0 ? "
            <tr>
                <td style='color:#888;padding:3px 0;'>Less: Discount ({$disc_label})</td>
                <td style='color:#C96A2C;text-align:right;font-weight:600;padding:3px 0;'>- ₱" . number_format($discount_amount, 2) . "</td>
            </tr>" : "") . "
            <tr style='border-top:2px solid #EAD8C0;'>
                <td style='color:#3B2A1A;font-weight:700;font-size:14px;padding:8px 0 3px;'>TOTAL AMOUNT DUE</td>
                <td style='color:#C96A2C;font-weight:700;font-size:18px;text-align:right;padding:8px 0 3px;'>₱" . number_format($final_amount, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;font-size:11px;padding:2px 0;'>Payment Method</td>
                <td style='color:#3B2A1A;font-size:11px;font-weight:600;text-align:right;padding:2px 0;'>{$pay_method}</td>
            </tr>
        </table>
    </div>

    <!-- ── VAT SUMMARY BOX ── -->
    <div style='margin:0 24px 16px;padding:10px 14px;background:#fff;border:1px solid #EAD8C0;border-radius:8px;'>
        <div style='font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;'>VAT Summary</div>
        <table style='width:100%;font-size:11px;'>
            <tr>
                <td style='color:#888;padding:2px 0;'>VATable Sales</td>
                <td style='color:#3B2A1A;text-align:right;'>₱" . number_format($vatable_sales, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:2px 0;'>VAT-Exempt Sales</td>
                <td style='color:#3B2A1A;text-align:right;'>₱" . number_format($exempt_sales, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:2px 0;'>Zero-Rated Sales</td>
                <td style='color:#3B2A1A;text-align:right;'>₱" . number_format($zero_rated, 2) . "</td>
            </tr>
            <tr>
                <td style='color:#888;padding:2px 0;'>Total VAT (12%)</td>
                <td style='color:#3B2A1A;font-weight:700;text-align:right;'>₱" . number_format($vat_display, 2) . "</td>
            </tr>
        </table>
    </div>

    <!-- ── REFERENCE ── -->
    " . (!empty($order['paymongo_reference']) ? "
    <div style='margin:0 24px 16px;padding:8px 14px;background:#EAF4FF;border:1px solid #bae6fd;border-radius:8px;font-size:11px;color:#0369a1;'>
        💳 Payment Reference: <strong style='font-family:monospace;'>{$reference_display}</strong>
    </div>" : "") . "

    <!-- ── NEXT STEPS ── -->
    <div style='padding:14px 24px;font-size:12px;color:#888;border-top:1px solid #EAD8C0;'>
        " . ($is_service
            ? "<p style='margin:0;line-height:1.7;'>📅 Your appointment is <strong>awaiting confirmation</strong>. Please arrive 10 minutes early. Keep this receipt for your records.</p>"
            : "<p style='margin:0;line-height:1.7;'>🛍️ Your order is pending confirmation. You will be notified when it is ready for pick-up.</p>") . "
    </div>

    <!-- ── FOOTER ── -->
    <div style='background:#3B2A1A;padding:16px 24px;text-align:center;'>
        <p style='color:#C8A46B;margin:0;font-size:11px;letter-spacing:1px;'>Thank you for choosing Recovery Spa &amp; Massage</p>
        <p style='color:rgba(107,76,48,0.8);margin:4px 0 0;font-size:10px;'>This is an official system-generated receipt.</p>
    </div>
</div>
</body></html>";

    // ── 7. Plain text ─────────────────────────────────────────────────────────
    $plain  = "RECOVERY SPA & MASSAGE — OFFICIAL RECEIPT\n";
    $plain .= "VAT Reg. TIN: 522-978-781-00001\n";
    $plain .= "M.H. Del Pilar St., Tael, Molo, Iloilo City\n";
    $plain .= str_repeat('-', 42) . "\n";
    $plain .= "Invoice No.: {$invoice_no}\n";
    $plain .= "Customer: {$customer_name}\n";
    $plain .= "Date: {$order_date}\n";
    $plain .= "Payment: {$pay_method}\n";
    $plain .= str_repeat('-', 42) . "\n";
    $plain .= "Total Sales (VAT Incl.): ₱" . number_format($final_amount, 2) . "\n";
    $plain .= "Less VAT: ₱" . number_format($vat_display, 2) . "\n";
    if ($discount_amount > 0) $plain .= "Less Discount ({$disc_label}): ₱" . number_format($discount_amount, 2) . "\n";
    $plain .= "TOTAL AMOUNT DUE: ₱" . number_format($final_amount, 2) . "\n";
    $plain .= str_repeat('-', 42) . "\nThank you for choosing Recovery Spa!\n";

    // ── 8. Send via PHPMailer ─────────────────────────────────────────────────
    require_once __DIR__ . '/../_mailer.php';
    try {
        $mail = make_mailer();
        $mail->addAddress($receipt_to, $customer_name);
        $mail->isHTML(true);
        $mail->Subject = "Recovery Spa — Official Receipt {$invoice_no}";
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