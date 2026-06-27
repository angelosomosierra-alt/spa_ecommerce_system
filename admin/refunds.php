<?php
/**
 * refunds.php — Admin Refund Management (Owner only)
 * Processes customer refund requests via PayMongo or marks as manual.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
redirect_if_not_owner();
require_once __DIR__ . '/../notify.php';

$message = ''; $message_type = '';

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: PROCESS REFUND
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_refund') {
    verify_csrf_token();
    $rr_id  = intval($_POST['rr_id']  ?? 0);
    $notes  = sanitize_input($_POST['refund_notes'] ?? '');

    // Fetch the refund request
    $stmt = $conn->prepare("
        SELECT rr.*, u.full_name AS customer_name, u.email,
               s.name AS service_name, a.appointment_date
        FROM refund_requests rr
        JOIN users u        ON u.id  = rr.user_id
        JOIN appointments a ON a.id  = rr.appointment_id
        JOIN services s     ON s.id  = a.service_id
        WHERE rr.id = ? AND rr.status = 'pending'
        LIMIT 1
    ");
    $stmt->bind_param("i", $rr_id);
    $stmt->execute();
    $rr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rr) {
        $message = "Refund request not found or already processed.";
        $message_type = "danger";
    } else {
        $refunded      = false;
        $refund_method = 'manual';
        $paymongo_ref  = null;

        // ── Try PayMongo Refund API first ─────────────────────────────────
        if (!empty($rr['paymongo_payment_id'])) {
            $amount_cents = intval(round($rr['amount'] * 100)); // PayMongo uses centavos

            $payload = json_encode([
                'data' => [
                    'attributes' => [
                        'amount'     => $amount_cents,
                        'payment_id' => $rr['paymongo_payment_id'],
                        'reason'     => 'others',
                        'notes'      => $notes ?: 'Customer requested cancellation and refund',
                    ]
                ]
            ]);

            $ch = curl_init('https://api.paymongo.com/v1/refunds');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                ],
            ]);
            $raw  = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if (!$errno && $raw) {
                $result = json_decode($raw, true);
                $status = $result['data']['attributes']['status'] ?? '';

                if (in_array($status, ['pending','succeeded'])) {
                    $refunded     = true;
                    $refund_method = 'paymongo';
                    $paymongo_ref  = $result['data']['id']; // ref_xxx
                } else {
                    // PayMongo returned an error
                    $pm_error = $result['errors'][0]['detail'] ?? 'Unknown PayMongo error';
                    $message  = "⚠️ PayMongo refund failed: {$pm_error}. You can mark it as manually refunded below.";
                    $message_type = "danger";
                }
            } else {
                $message = "⚠️ Could not reach PayMongo. Mark as manually refunded below.";
                $message_type = "danger";
            }
        }

        // ── If PayMongo worked OR no payment ID (mark refunded) ───────────
        if ($refunded || empty($rr['paymongo_payment_id'])) {
            $new_rr_status = $refunded ? 'refunded' : 'manually_refunded';
            $processor_id  = intval($_SESSION['user_id']);

            // Update refund_requests
            $upd = $conn->prepare("
                UPDATE refund_requests
                SET status             = ?,
                    paymongo_refund_id = ?,
                    refund_notes       = ?,
                    processed_by       = ?
                WHERE id = ?
            ");
            $upd->bind_param("sssii", $new_rr_status, $paymongo_ref, $notes, $processor_id, $rr_id);
            $upd->execute();
            $upd->close();

            // Update order payment_status
            $upd2 = $conn->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?");
            $upd2->bind_param("i", $rr['order_id']); $upd2->execute(); $upd2->close();

            // Update appointment to cancelled
            $upd3 = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
            $upd3->bind_param("i", $rr['appointment_id']); $upd3->execute(); $upd3->close();

            // Notify customer
            $method_label = $refunded ? 'via PayMongo (card/GCash)' : 'manually by the owner';
            add_notification($conn, $rr['user_id'], 'appointment',
                '✅ Refund Processed',
                'Your refund of ₱' . number_format($rr['amount'], 2) .
                ' for ' . $rr['service_name'] .
                ' has been processed ' . $method_label . '.' .
                ($notes ? ' Note: ' . $notes : ''),
                'appointments.php'
            );

            $message = $refunded
                ? "✅ PayMongo refund processed successfully. Refund ID: {$paymongo_ref}"
                : "✅ Marked as manually refunded. Remember to transfer ₱" . number_format($rr['amount'], 2) . " to the customer.";
            $message_type = "success";
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: MARK MANUALLY REFUNDED (when PayMongo failed)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_manual_refund') {
    verify_csrf_token();
    $rr_id = intval($_POST['rr_id'] ?? 0);
    $notes = sanitize_input($_POST['refund_notes'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM refund_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $rr_id); $stmt->execute();
    $rr = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($rr) {
        $processor_id = intval($_SESSION['user_id']);

        $upd = $conn->prepare("
            UPDATE refund_requests
            SET status = 'manually_refunded', refund_notes = ?, processed_by = ?
            WHERE id = ?
        ");
        $upd->bind_param("sii", $notes, $processor_id, $rr_id);
        $upd->execute(); $upd->close();

        $conn->prepare("UPDATE orders SET payment_status='refunded' WHERE id=?")
             ->bind_param("i", $rr['order_id']);
        $conn->prepare("UPDATE orders SET payment_status='refunded' WHERE id=?")->execute();

        $u = $conn->prepare("UPDATE orders SET payment_status='refunded' WHERE id=?");
        $u->bind_param("i", $rr['order_id']); $u->execute(); $u->close();

        $u2 = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");
        $u2->bind_param("i", $rr['appointment_id']); $u2->execute(); $u2->close();

        add_notification($conn, $rr['user_id'], 'appointment',
            '✅ Refund Processed',
            'Your refund of ₱' . number_format($rr['amount'], 2) .
            ' has been processed manually by the owner.' .
            ($notes ? ' Note: ' . $notes : ''),
            'appointments.php'
        );

        $message = "✅ Marked as manually refunded. Remember to transfer ₱" . number_format($rr['amount'], 2) . " to the customer.";
        $message_type = "success";
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: REJECT REFUND
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_refund') {
    verify_csrf_token();
    $rr_id = intval($_POST['rr_id'] ?? 0);
    $notes = sanitize_input($_POST['refund_notes'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM refund_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $rr_id); $stmt->execute();
    $rr = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($rr) {
        $processor_id = intval($_SESSION['user_id']);
        $upd = $conn->prepare("UPDATE refund_requests SET status='rejected', refund_notes=?, processed_by=? WHERE id=?");
        $upd->bind_param("sii", $notes, $processor_id, $rr_id); $upd->execute(); $upd->close();

        // Revert appointment back to cancelled (no refund)
        $upd2 = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");
        $upd2->bind_param("i", $rr['appointment_id']); $upd2->execute(); $upd2->close();

        add_notification($conn, $rr['user_id'], 'appointment',
            '❌ Refund Request Rejected',
            'Your refund request has been reviewed and rejected.' .
            ($notes ? ' Reason: ' . $notes : ' Please contact us for more information.'),
            'appointments.php'
        );

        $message = "Refund request #$rr_id rejected.";
        $message_type = "danger";
    }
}

// ── Fetch all refund requests ─────────────────────────────────────────────────
$pending_refunds = $conn->query("
    SELECT rr.*, u.full_name AS customer_name, u.email, u.phone,
           s.name AS service_name, a.appointment_date,
           o.total_amount AS order_amount, o.paymongo_reference
    FROM refund_requests rr
    JOIN users u        ON u.id  = rr.user_id
    JOIN appointments a ON a.id  = rr.appointment_id
    JOIN services s     ON s.id  = a.service_id
    JOIN orders o       ON o.id  = rr.order_id
    WHERE rr.status = 'pending'
    ORDER BY rr.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

$processed_refunds = $conn->query("
    SELECT rr.*, u.full_name AS customer_name,
           s.name AS service_name, a.appointment_date
    FROM refund_requests rr
    JOIN users u        ON u.id  = rr.user_id
    JOIN appointments a ON a.id  = rr.appointment_id
    JOIN services s     ON s.id  = a.service_id
    WHERE rr.status != 'pending'
    ORDER BY rr.updated_at DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

$page_title  = 'Refund Requests';
$page_icon   = '💸';
$active_page = 'refunds';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.5rem;">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- ── PENDING REFUNDS ────────────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:2rem;">
    <div class="panel-header">
        <span class="panel-title">💸 Pending Refund Requests
            <?php if (!empty($pending_refunds)): ?>
            <span style="background:#dc3545;color:#fff;border-radius:20px;
                         padding:0.1rem 0.55rem;font-size:0.72rem;margin-left:0.4rem;">
                <?php echo count($pending_refunds); ?>
            </span>
            <?php endif; ?>
        </span>
    </div>
    <div class="panel-body" style="padding:1.25rem;">

        <?php if (empty($pending_refunds)): ?>
        <div style="text-align:center;padding:2.5rem;color:var(--gray);">
            <div style="font-size:2rem;margin-bottom:0.5rem;">✅</div>
            No pending refund requests.
        </div>

        <?php else: foreach ($pending_refunds as $rr): ?>
        <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:12px;
                    padding:1.25rem;margin-bottom:1rem;border-left:4px solid #f59e0b;">

            <!-- Header row -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;
                        flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
                <div>
                    <div style="font-size:1rem;font-weight:700;color:var(--brown);">
                        <?php echo htmlspecialchars($rr['customer_name']); ?>
                        <span style="font-size:0.78rem;color:var(--gray);font-weight:400;margin-left:0.5rem;">
                            <?php echo htmlspecialchars($rr['email']); ?>
                            <?php if ($rr['phone']): ?>
                             · <?php echo htmlspecialchars($rr['phone']); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="font-size:0.82rem;color:var(--gray);margin-top:0.2rem;">
                        Request #<?php echo $rr['id']; ?> ·
                        Submitted <?php echo date('M d, Y h:i A', strtotime($rr['created_at'])); ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.3rem;font-weight:800;color:#dc2626;">
                        ₱<?php echo number_format($rr['amount'], 2); ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);">Refund Amount</div>
                </div>
            </div>

            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                        gap:0.5rem 1.5rem;padding:0.85rem;background:var(--bg2);
                        border-radius:8px;margin-bottom:1rem;font-size:0.83rem;">
                <div>
                    <div style="font-size:0.68rem;color:var(--gray);font-weight:700;
                                text-transform:uppercase;margin-bottom:0.2rem;">Service</div>
                    <div style="color:var(--brown);font-weight:600;">
                        <?php echo htmlspecialchars($rr['service_name']); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.68rem;color:var(--gray);font-weight:700;
                                text-transform:uppercase;margin-bottom:0.2rem;">Appointment Date</div>
                    <div style="color:var(--brown);">
                        <?php echo date('M d, Y h:i A', strtotime($rr['appointment_date'])); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.68rem;color:var(--gray);font-weight:700;
                                text-transform:uppercase;margin-bottom:0.2rem;">PayMongo Reference</div>
                    <div style="color:var(--brown);font-size:0.78rem;font-family:monospace;">
                        <?php echo htmlspecialchars($rr['paymongo_reference'] ?: '—'); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.68rem;color:var(--gray);font-weight:700;
                                text-transform:uppercase;margin-bottom:0.2rem;">Payment ID</div>
                    <div style="color:var(--brown);font-size:0.78rem;font-family:monospace;">
                        <?php echo htmlspecialchars($rr['paymongo_payment_id'] ?: '—'); ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($rr['reason'])): ?>
            <div style="padding:0.65rem 0.85rem;background:#fffbeb;border-radius:8px;
                        border:1px solid #fde68a;font-size:0.83rem;color:#78350f;margin-bottom:1rem;">
                💬 <strong>Customer reason:</strong> <?php echo htmlspecialchars($rr['reason']); ?>
            </div>
            <?php endif; ?>

            <?php if (empty($rr['paymongo_payment_id'])): ?>
            <div style="padding:0.6rem 0.85rem;background:#fef2f2;border-radius:8px;
                        border:1px solid #fecaca;font-size:0.78rem;color:#991b1b;margin-bottom:1rem;">
                ⚠️ No PayMongo payment ID found for this order. You will need to refund this customer manually.
            </div>
            <?php endif; ?>

            <!-- Action forms -->
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">

                <!-- Process Refund (tries PayMongo) -->
                <form method="POST" style="flex:1;min-width:220px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="process_refund">
                    <input type="hidden" name="rr_id"  value="<?php echo $rr['id']; ?>">
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.73rem;color:var(--gray);display:block;margin-bottom:3px;">
                            Notes (sent to customer)
                        </label>
                        <input type="text" name="refund_notes"
                               placeholder="e.g. Refund processed via GCash"
                               style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg2);color:var(--brown);
                                      font-size:0.83rem;box-sizing:border-box;">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm"
                            onclick="return confirm('Process refund of ₱<?php echo number_format($rr['amount'],2); ?> to <?php echo htmlspecialchars(addslashes($rr['customer_name'])); ?>?')">
                        <?php echo !empty($rr['paymongo_payment_id']) ? '💸 Process via PayMongo' : '✅ Mark as Manually Refunded'; ?>
                    </button>
                </form>

                <!-- Mark Manual (if PayMongo already failed) -->
                <?php if (!empty($rr['paymongo_payment_id'])): ?>
                <form method="POST" style="display:flex;flex-direction:column;gap:0.4rem;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="mark_manual_refund">
                    <input type="hidden" name="rr_id"  value="<?php echo $rr['id']; ?>">
                    <input type="text" name="refund_notes"
                           placeholder="Manual transfer notes..."
                           style="padding:0.4rem 0.65rem;border:1px solid var(--border2);
                                  border-radius:7px;background:var(--bg2);color:var(--brown);
                                  font-size:0.83rem;width:200px;">
                    <button type="submit" class="btn btn-secondary btn-sm"
                            onclick="return confirm('Mark as manually refunded?')">
                        🏦 Mark Manual Refund
                    </button>
                </form>
                <?php endif; ?>

                <!-- Reject -->
                <form method="POST" style="display:flex;flex-direction:column;gap:0.4rem;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reject_refund">
                    <input type="hidden" name="rr_id"  value="<?php echo $rr['id']; ?>">
                    <input type="text" name="refund_notes"
                           placeholder="Reason for rejection..."
                           style="padding:0.4rem 0.65rem;border:1px solid var(--border2);
                                  border-radius:7px;background:var(--bg2);color:var(--brown);
                                  font-size:0.83rem;width:200px;">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Reject this refund request?')">
                        ❌ Reject Request
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ── PROCESSED REFUNDS HISTORY ─────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">📋 Processed Refunds</span>
    </div>
    <?php if (empty($processed_refunds)): ?>
    <div class="panel-body" style="text-align:center;padding:2rem;color:var(--gray);">
        No processed refunds yet.
    </div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Refund ID</th>
                    <th>Notes</th>
                    <th>Processed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($processed_refunds as $rr):
                $sbg = match($rr['status']) {
                    'refunded'          => ['#d1e7dd','#0a3622','✅ Refunded (PayMongo)'],
                    'manually_refunded' => ['#dbeafe','#1e40af','🏦 Manual Refund'],
                    'rejected'          => ['#fef2f2','#991b1b','❌ Rejected'],
                    default             => ['#e2e3e5','#41464b', ucfirst($rr['status'])],
                };
            ?>
            <tr>
                <td style="font-size:0.78rem;color:var(--gray);">#<?php echo $rr['id']; ?></td>
                <td style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($rr['customer_name']); ?></td>
                <td style="font-size:0.83rem;"><?php echo htmlspecialchars($rr['service_name']); ?></td>
                <td style="font-weight:700;color:#dc2626;">₱<?php echo number_format($rr['amount'],2); ?></td>
                <td>
                    <span style="background:<?php echo $sbg[0]; ?>;color:<?php echo $sbg[1]; ?>;
                                 padding:0.2rem 0.65rem;border-radius:20px;font-size:0.75rem;font-weight:600;">
                        <?php echo $sbg[2]; ?>
                    </span>
                </td>
                <td style="font-size:0.75rem;color:var(--gray);font-family:monospace;">
                    <?php echo htmlspecialchars($rr['paymongo_refund_id'] ?: '—'); ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);">
                    <?php echo htmlspecialchars($rr['refund_notes'] ?: '—'); ?>
                </td>
                <td style="font-size:0.75rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($rr['updated_at'])); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>