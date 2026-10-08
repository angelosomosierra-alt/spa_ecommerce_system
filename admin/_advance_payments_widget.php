<?php
/**
 * _advance_payments_widget.php
 * ─────────────────────────────────────────────────────────────────────────────
 * One entry point for logging any money collected today for a service that
 * hasn't happened yet. Three types, each landing where the Daily Report
 * already expects it so nothing double-counts:
 *   - Voucher / GC sale  → gift_certificates (type='sold'), same table and
 *     the same Gross Sales line (Sold GC) the Daily Report's GC & Unpaids
 *     tab has always used. Entering it here instead of there is the only
 *     difference.
 *   - Other Deposit      → manual_advance_payments (new), folded into
 *     $advances_received / Advance Payment the same way a session-package
 *     advance already is — see _daily_report_data.php.
 *   - Session Package     → read-only here. Created automatically at
 *     check-in (see checkin_appointment in appointments.php); shown so
 *     every advance is visible in one place, not to be entered by hand.
 *     Also lists session-count packages with a session completed today:
 *     the paid value of the sessions still left (Dashboard only).
 *
 * Usage: <?php require_once 'admin/_advance_payments_widget.php'; ?>
 * Requires: $conn (DB), session with user_id, config.php already loaded.
 * ─────────────────────────────────────────────────────────────────────────────
 */

$conn->query("CREATE TABLE IF NOT EXISTS manual_advance_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL,
    client_name VARCHAR(120) NOT NULL,
    service_label VARCHAR(255) NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    remarks VARCHAR(255) NULL,
    added_by INT NULL,
    verified_by_pin VARCHAR(10) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_map_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Read below for session-count packages. Normally created by walkin.php /
// appointments.php, but the Dashboard may be the first page opened on a
// fresh database — same self-healing schema convention as those pages.
$conn->query("CREATE TABLE IF NOT EXISTS appointment_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    session_number INT NOT NULL,
    session_date DATETIME NULL,
    therapist_id INT NULL,
    duration_minutes INT NOT NULL,
    status ENUM('not_scheduled','scheduled','checked_in','completed') NOT NULL DEFAULT 'not_scheduled',
    commission DECIMAL(10,2) NULL,
    checked_in_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    completed_by_name VARCHAR(120) NULL,
    UNIQUE KEY uq_appt_session (appointment_id, session_number),
    CONSTRAINT fk_appt_sessions_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_appt_sessions_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$apw_msg = ''; $apw_msg_type = 'success';

// A voucher sale still needs today's daily_reports header row to exist (same
// requirement the GC & Unpaids tab's own form has) — self-heal a blank one
// instead of blocking the quick-entry widget on someone having opened the
// full Daily Report page first. function_exists guards let this file be
// require_once'd safely even if something else ever pulls it in twice.
if (!function_exists('apw_ensure_report_header')) {
function apw_ensure_report_header($conn) {
    $chk = $conn->prepare("SELECT id, is_locked FROM daily_reports WHERE report_date = CURDATE() LIMIT 1");
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc(); $chk->close();
    if ($row) return $row;
    $ins = $conn->prepare("INSERT INTO daily_reports (report_date, created_by) VALUES (CURDATE(), ?)");
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $ins->bind_param("i", $uid); $ins->execute(); $ins->close();
    return ['id' => $conn->insert_id, 'is_locked' => 0];
}
}

// Cashier accountability — same PIN-verification pattern as expenses_widget.php.
if (!function_exists('apw_verify_pin')) {
function apw_verify_pin($conn) {
    if (($_SESSION['admin_role'] ?? '') !== 'cashier') return [true, null];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $entered_pin = trim($_POST['apw_pin'] ?? '');
    $pin_check = $conn->prepare("SELECT id FROM users WHERE id=? AND cashier_pin=?");
    $pin_check->bind_param("is", $uid, $entered_pin);
    $pin_check->execute();
    $ok = $pin_check->get_result()->num_rows > 0;
    $pin_check->close();
    return [$ok, $ok ? $entered_pin : null];
}
}

// ── ADD entry ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apw_add'])) {
    verify_csrf_token();
    $apw_type = in_array($_POST['apw_type'] ?? '', ['voucher', 'deposit']) ? $_POST['apw_type'] : 'deposit';
    $apw_name   = sanitize_input($_POST['apw_name']    ?? '');
    $apw_amount = floatval($_POST['apw_amount']         ?? 0);
    $apw_mop    = sanitize_input($_POST['apw_mop']      ?? 'Cash');
    $apw_remark = sanitize_input($_POST['apw_remarks']  ?? '');
    $uid        = (int)($_SESSION['user_id'] ?? 0);

    list($pin_ok, $pin_used) = apw_verify_pin($conn);
    if (!$pin_ok) {
        $apw_msg = 'Incorrect PIN. Entry not saved.'; $apw_msg_type = 'danger';
    } elseif (empty($apw_name) || $apw_amount <= 0) {
        $apw_msg = 'Name and amount are required.'; $apw_msg_type = 'danger';
    } elseif ($apw_type === 'voucher') {
        $hdr = apw_ensure_report_header($conn);
        if ($hdr['is_locked']) {
            $apw_msg = "Today's report is already locked/submitted."; $apw_msg_type = 'warning';
        } else {
            $apw_series = sanitize_input($_POST['apw_series'] ?? '');
            $apw_code   = sanitize_input($_POST['apw_code']   ?? '');
            $stmt = $conn->prepare("INSERT INTO gift_certificates (report_date,type,series,client_name,voucher_code,qty,amount,remarks,created_by) VALUES (CURDATE(),'sold',?,?,?,1,?,?,?)");
            $stmt->bind_param("sssdsi", $apw_series, $apw_name, $apw_code, $apw_amount, $apw_remark, $uid);
            $ok = $stmt->execute(); $stmt->close();
            $apw_msg = $ok ? 'Voucher / GC sale recorded.' : 'Database error.';
            $apw_msg_type = $ok ? 'success' : 'danger';
        }
    } else {
        $apw_service = sanitize_input($_POST['apw_service'] ?? '');
        $stmt = $conn->prepare("INSERT INTO manual_advance_payments (report_date, client_name, service_label, amount, payment_method, remarks, added_by, verified_by_pin) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdssis", $apw_name, $apw_service, $apw_amount, $apw_mop, $apw_remark, $uid, $pin_used);
        $ok = $stmt->execute(); $stmt->close();
        $apw_msg = $ok ? 'Advance payment recorded.' : 'Database error.';
        $apw_msg_type = $ok ? 'success' : 'danger';
    }
}

// ── DELETE (owner/full-access only, today's entries only) ──────────────────
if (isset($_GET['del_voucher']) && is_full_access()) {
    $stmt = $conn->prepare("DELETE FROM gift_certificates WHERE id=? AND report_date=CURDATE() AND type='sold'");
    $stmt->bind_param("i", intval($_GET['del_voucher'])); $stmt->execute(); $stmt->close();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit();
}
if (isset($_GET['del_deposit']) && is_full_access()) {
    $stmt = $conn->prepare("DELETE FROM manual_advance_payments WHERE id=? AND report_date=CURDATE()");
    $stmt->bind_param("i", intval($_GET['del_deposit'])); $stmt->execute(); $stmt->close();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit();
}

// ── Fetch today's entries (all three types) ─────────────────────────────────
$apw_vouchers = $conn->query("
    SELECT gc.id, gc.client_name, gc.series, gc.voucher_code, gc.amount, gc.remarks, gc.created_at,
           u.full_name AS by_name
    FROM gift_certificates gc
    LEFT JOIN users u ON u.id = gc.created_by
    WHERE gc.report_date = CURDATE() AND gc.type = 'sold'
    ORDER BY gc.id DESC
")->fetch_all(MYSQLI_ASSOC);

$apw_deposits = $conn->query("
    SELECT m.id, m.client_name, m.service_label, m.amount, m.payment_method, m.remarks, m.created_at,
           u.full_name AS by_name
    FROM manual_advance_payments m
    LEFT JOIN users u ON u.id = m.added_by
    WHERE m.report_date = CURDATE()
    ORDER BY m.id DESC
")->fetch_all(MYSQLI_ASSOC);

$apw_sessions = $conn->query("
    SELECT a.id, a.advance_payment, a.advance_payment_method, a.advance_payment_date,
           s.name AS service_name, IFNULL(sd.session_count, 1) AS session_count,
           COALESCE(o.customer_name, u.full_name) AS customer_name
    FROM appointments a
    JOIN services s ON s.id = a.service_id
    LEFT JOIN service_durations sd ON sd.id = a.service_duration_id
    LEFT JOIN order_items oi ON oi.id = a.order_item_id
    LEFT JOIN orders o ON o.id = oi.order_id
    LEFT JOIN users u ON u.id = a.user_id
    WHERE a.advance_payment > 0 AND a.advance_payment_date = CURDATE()
    ORDER BY a.id DESC
")->fetch_all(MYSQLI_ASSOC);

// Session-count packages (service_durations.session_count > 1) are one
// appointment paid in full upfront, with progress tracked in
// appointment_sessions — they never set appointments.advance_payment, so the
// query above misses them. Once a session is completed today and the order is
// paid, the sessions still left are money already collected for service not
// yet rendered: list that balance here. Dashboard visibility only — the Daily
// Report already recognizes the full package price on its booking date, so
// this is deliberately NOT fed into _daily_report_data.php's advance totals.
$apw_pkg_remaining = $conn->query("
    SELECT a.id, a.charged_price, s.name AS service_name,
           COUNT(*) AS total_sessions,
           SUM(aps.status = 'completed') AS done_sessions,
           COALESCE(NULLIF(o.paymongo_method, ''), o.payment_method) AS pay_method,
           COALESCE(o.customer_name, u.full_name) AS customer_name
    FROM appointments a
    JOIN appointment_sessions aps ON aps.appointment_id = a.id
    JOIN services s ON s.id = a.service_id
    JOIN order_items oi ON oi.id = a.order_item_id
    JOIN orders o ON o.id = oi.order_id
    LEFT JOIN users u ON u.id = a.user_id
    WHERE o.payment_status = 'paid'
      AND a.status != 'cancelled'
    GROUP BY a.id
    HAVING SUM(aps.status = 'completed' AND DATE(aps.completed_at) = CURDATE()) > 0
       AND done_sessions < total_sessions
    ORDER BY a.id DESC
")->fetch_all(MYSQLI_ASSOC);
foreach ($apw_pkg_remaining as &$_pr) {
    $_left = (int)$_pr['total_sessions'] - (int)$_pr['done_sessions'];
    $_pr['remaining_sessions'] = $_left;
    $_pr['remaining_amount']   = round((float)$_pr['charged_price'] * $_left / max(1, (int)$_pr['total_sessions']), 2);
}
unset($_pr);

$apw_voucher_total = array_sum(array_column($apw_vouchers, 'amount'));
// Advance Payment (deposits for a future visit) and Multi-session Paid
// (sessions already paid for but not yet rendered) are shown and totalled
// separately; the grand total still covers everything.
$apw_deposit_total = array_sum(array_column($apw_deposits, 'amount'));
$apw_session_total = array_sum(array_column($apw_sessions, 'advance_payment'))
                    + array_sum(array_column($apw_pkg_remaining, 'remaining_amount'));
$apw_grand_total    = $apw_voucher_total + $apw_deposit_total + $apw_session_total;
?>
<div class="panel" style="margin-top:1.5rem;" id="advance-payments-widget">
    <div class="panel-header">
        <span class="panel-title">Advance Payments &amp; Vouchers</span>
        <span style="background:var(--gold);color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
            ₱<?php echo number_format($apw_grand_total, 2); ?> today
        </span>
    </div>

    <?php if ($apw_msg): ?>
    <div class="alert alert-<?php echo $apw_msg_type; ?>" style="margin:0.75rem 1rem 0;"><?php echo htmlspecialchars($apw_msg); ?></div>
    <?php endif; ?>

    <div class="panel-body" style="padding:1rem;">
        <div style="display:grid;grid-template-columns:340px 1fr;gap:1.5rem;">

            <!-- ── Add form ──────────────────────────────────────────────── -->
            <div>
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.65rem;">
                    Record an entry
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.4rem;margin-bottom:0.8rem;">
                    <button type="button" class="apw-type-btn active" data-type="voucher" onclick="apwSetType('voucher')"
                            style="border:1.5px solid var(--gold);background:rgba(201,106,44,0.1);border-radius:8px;padding:0.5rem 0.3rem;cursor:pointer;font-size:0.72rem;font-weight:700;color:var(--brown);text-align:center;">
                        Voucher / GC
                    </button>
                    <button type="button" class="apw-type-btn" data-type="deposit" onclick="apwSetType('deposit')"
                            style="border:1.5px solid var(--border2);background:var(--bg3);border-radius:8px;padding:0.5rem 0.3rem;cursor:pointer;font-size:0.72rem;font-weight:700;color:var(--brown);text-align:center;">
                        Other Deposit
                    </button>
                    <button type="button" class="apw-type-btn" data-type="session" onclick="apwSetType('session')"
                            title="Multi-session paid entries are created automatically"
                            style="border:1.5px solid var(--border2);background:var(--bg3);border-radius:8px;padding:0.5rem 0.3rem;cursor:pointer;font-size:0.72rem;font-weight:700;color:var(--brown);text-align:center;">
                        Multi-session*
                    </button>
                </div>

                <form method="POST" id="apwForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="apw_add" value="1">
                    <input type="hidden" name="apw_type" id="apw_type" value="voucher">

                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Customer name *</label>
                        <input type="text" name="apw_name" id="apw_name" required placeholder="e.g. Kenneth Joy Ong"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>

                    <div id="apw-field-series" style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Series</label>
                        <input type="text" name="apw_series" placeholder="e.g. GC-001"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>
                    <div id="apw-field-code" style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Voucher code</label>
                        <input type="text" name="apw_code" placeholder="e.g. GC-1108"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>
                    <div id="apw-field-service" style="margin-bottom:0.5rem;display:none;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">For service / package</label>
                        <input type="text" name="apw_service" placeholder="e.g. Deposit for custom package"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
                        <div>
                            <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Amount (₱) *</label>
                            <input type="number" name="apw_amount" id="apw_amount" step="0.01" min="0.01" required placeholder="0.00"
                                   style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Mode of payment</label>
                            <select name="apw_mop" id="apw_mop" style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                                <option>Cash</option><option>GCash</option><option>Maya</option><option>Card</option><option>QR PH</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Remarks</label>
                        <input type="text" name="apw_remarks" placeholder="Optional"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>

                    <?php if (($_SESSION['admin_role'] ?? '') === 'cashier'): ?>
                    <div style="margin-bottom:0.75rem;padding:0.65rem;background:rgba(0,112,243,0.07);border:1px solid rgba(0,112,243,0.2);border-radius:8px;">
                        <label style="font-size:0.72rem;color:#0070f3;font-weight:700;display:block;margin-bottom:4px;">Enter your 4-digit PIN to confirm</label>
                        <input type="password" name="apw_pin" maxlength="4" pattern="\d{4}" required inputmode="numeric" placeholder="••••"
                               style="width:80px;padding:0.4rem 0.6rem;border:1px solid rgba(0,112,243,0.3);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:1rem;text-align:center;letter-spacing:0.25em;">
                    </div>
                    <?php endif; ?>

                    <p id="apw-type-hint" style="font-size:0.7rem;color:var(--gray);margin:0 0 0.5rem;">
                        Also appears automatically on GC &amp; Unpaids → Sold — no need to enter it twice.
                    </p>

                    <button type="submit" class="btn btn-primary btn-sm" id="apwSubmitBtn" style="width:100%;font-size:0.82rem;">Add Entry</button>
                </form>
            </div>

            <!-- ── Today's ledger ───────────────────────────────────────── -->
            <div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.6rem;">
                    <div style="font-size:0.78rem;font-weight:700;color:var(--brown);">Today's Log (<?php echo date('M d, Y'); ?>)</div>
                    <div style="font-size:0.78rem;font-weight:700;" id="apw-filter-total">
                        Total: ₱<?php echo number_format($apw_grand_total, 2); ?>
                    </div>
                </div>

                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.7rem;">
                    <button type="button" class="apw-filter-btn active" data-filter="all" onclick="apwSetFilter('all')"
                            style="border:1.5px solid var(--gold);background:rgba(201,106,44,0.1);border-radius:20px;padding:0.3rem 0.75rem;font-size:0.74rem;font-weight:600;color:var(--brown);cursor:pointer;">
                        All
                    </button>
                    <button type="button" class="apw-filter-btn" data-filter="voucher" onclick="apwSetFilter('voucher')"
                            style="border:1.5px solid var(--border2);background:var(--bg3);border-radius:20px;padding:0.3rem 0.75rem;font-size:0.74rem;font-weight:600;color:var(--gray);cursor:pointer;">
                        Voucher / GC Sold · ₱<?php echo number_format($apw_voucher_total, 2); ?>
                    </button>
                    <button type="button" class="apw-filter-btn" data-filter="advance" onclick="apwSetFilter('advance')"
                            style="border:1.5px solid var(--border2);background:var(--bg3);border-radius:20px;padding:0.3rem 0.75rem;font-size:0.74rem;font-weight:600;color:var(--gray);cursor:pointer;">
                        Advance Payment · ₱<?php echo number_format($apw_deposit_total, 2); ?>
                    </button>
                    <button type="button" class="apw-filter-btn" data-filter="session" onclick="apwSetFilter('session')"
                            style="border:1.5px solid var(--border2);background:var(--bg3);border-radius:20px;padding:0.3rem 0.75rem;font-size:0.74rem;font-weight:600;color:var(--gray);cursor:pointer;">
                        Multi-session Paid · ₱<?php echo number_format($apw_session_total, 2); ?>
                    </button>
                </div>

                <?php if (empty($apw_vouchers) && empty($apw_deposits) && empty($apw_sessions) && empty($apw_pkg_remaining)): ?>
                <div id="apw-empty" style="text-align:center;padding:1.5rem;color:var(--gray);font-size:0.82rem;background:var(--bg3);border-radius:8px;border:1px solid var(--border2);">
                    No entries yet today.
                </div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:0.35rem;max-height:340px;overflow-y:auto;" id="apw-list">
                    <?php foreach ($apw_vouchers as $v): ?>
                    <div class="apw-entry" data-type="voucher" style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.6rem;background:var(--bg3);border-radius:7px;border:1px solid var(--border2);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($v['client_name']); ?></div>
                            <div style="font-size:0.68rem;color:var(--gray);">
                                <span style="color:var(--gold);font-weight:700;">Voucher/GC</span>
                                <?php if ($v['series']): ?>&nbsp;·&nbsp;<?php echo htmlspecialchars($v['series']); ?><?php endif; ?>
                                <?php if ($v['voucher_code']): ?>&nbsp;·&nbsp;Code: <?php echo htmlspecialchars($v['voucher_code']); ?><?php endif; ?>
                                &nbsp;·&nbsp;by <?php echo htmlspecialchars($v['by_name'] ?? '?'); ?>
                                &nbsp;·&nbsp;<?php echo date('h:i A', strtotime($v['created_at'])); ?>
                            </div>
                        </div>
                        <span class="apw-amt" style="font-weight:700;color:#198754;font-size:0.85rem;white-space:nowrap;">₱<?php echo number_format($v['amount'],2); ?></span>
                        <?php if (is_full_access()): ?>
                        <a href="?del_voucher=<?php echo $v['id']; ?>" title="Remove"
                           style="color:var(--rust);font-size:0.78rem;text-decoration:none;padding:0.15rem 0.4rem;border-radius:4px;border:1px solid var(--rust);flex-shrink:0;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this voucher/GC entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($apw_deposits as $d): ?>
                    <div class="apw-entry" data-type="advance" style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.6rem;background:var(--bg3);border-radius:7px;border:1px solid var(--border2);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($d['client_name']); ?></div>
                            <div style="font-size:0.68rem;color:var(--gray);">
                                <span style="color:#2d8a4e;font-weight:700;">Advance Payment</span>
                                <?php if ($d['service_label']): ?>&nbsp;·&nbsp;<?php echo htmlspecialchars($d['service_label']); ?><?php endif; ?>
                                &nbsp;·&nbsp;<?php echo htmlspecialchars($d['payment_method']); ?>
                                &nbsp;·&nbsp;by <?php echo htmlspecialchars($d['by_name'] ?? '?'); ?>
                                &nbsp;·&nbsp;<?php echo date('h:i A', strtotime($d['created_at'])); ?>
                            </div>
                        </div>
                        <span class="apw-amt" style="font-weight:700;color:var(--brown);font-size:0.85rem;white-space:nowrap;">₱<?php echo number_format($d['amount'],2); ?></span>
                        <?php if (is_full_access()): ?>
                        <a href="?del_deposit=<?php echo $d['id']; ?>" title="Remove"
                           style="color:var(--rust);font-size:0.78rem;text-decoration:none;padding:0.15rem 0.4rem;border-radius:4px;border:1px solid var(--rust);flex-shrink:0;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this advance payment entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($apw_sessions as $s):
                        $_lbl = $s['service_name'] . ((int)$s['session_count'] > 1 ? ' — Session 2 of ' . (int)$s['session_count'] . ' (not yet rendered)' : '');
                    ?>
                    <div class="apw-entry" data-type="session" style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.6rem;background:var(--bg3);border-radius:7px;border:1px solid var(--border2);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['customer_name'] ?? ''); ?></div>
                            <div style="font-size:0.68rem;color:var(--gray);">
                                <span style="color:#9333a6;font-weight:700;">Auto · Multi-session Paid</span>
                                &nbsp;·&nbsp;<?php echo htmlspecialchars($_lbl); ?>
                                &nbsp;·&nbsp;<?php echo htmlspecialchars(ucfirst($s['advance_payment_method'] ?? 'cash')); ?>
                            </div>
                        </div>
                        <span class="apw-amt" style="font-weight:700;color:var(--brown);font-size:0.85rem;white-space:nowrap;">₱<?php echo number_format($s['advance_payment'],2); ?></span>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($apw_pkg_remaining as $p):
                        $_done = (int)$p['done_sessions']; $_tot = (int)$p['total_sessions']; $_left = (int)$p['remaining_sessions'];
                        $_lbl = $p['service_name'] . ' — ' . $_done . ' of ' . $_tot . ' done, ' . $_left . ' session' . ($_left > 1 ? 's' : '') . ' remaining';
                    ?>
                    <div class="apw-entry" data-type="session" style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.6rem;background:var(--bg3);border-radius:7px;border:1px solid var(--border2);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.82rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($p['customer_name'] ?? ''); ?></div>
                            <div style="font-size:0.68rem;color:var(--gray);">
                                <span style="color:#9333a6;font-weight:700;">Auto · Multi-session Paid</span>
                                &nbsp;·&nbsp;<?php echo htmlspecialchars($_lbl); ?>
                                &nbsp;·&nbsp;<?php echo htmlspecialchars(ucfirst($p['pay_method'] ?? 'cash')); ?>
                            </div>
                        </div>
                        <span class="apw-amt" style="font-weight:700;color:var(--brown);font-size:0.85rem;white-space:nowrap;">₱<?php echo number_format($p['remaining_amount'],2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="apw-empty" style="display:none;text-align:center;padding:1.5rem;color:var(--gray);font-size:0.82rem;background:var(--bg3);border-radius:8px;border:1px solid var(--border2);">
                    No entries match this filter.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <details style="margin-top:1rem;">
            <summary style="cursor:pointer;font-size:0.76rem;font-weight:700;color:var(--gray);">Where each type lands in the Sales Report</summary>
            <div style="font-size:0.76rem;color:var(--gray);margin-top:0.5rem;line-height:1.6;">
                <strong style="color:var(--brown);">Voucher / GC</strong> — also appears on GC &amp; Unpaids → Sold, counted once in Sold GC (Gross Sales). Shown here for visibility only.<br>
                <strong style="color:var(--brown);">Other Deposit</strong> — listed under Advance Payment here, and counted in Advance Payment on the Summary Report.<br>
                <strong style="color:var(--brown);">Multi-session Paid</strong> — created automatically and listed separately from Advance Payment. When a session of a paid multi-session package is completed, the value of the sessions still left shows here. Dashboard only: the Sales Report already counts the full package price on its booking date.
            </div>
        </details>
    </div>
</div>

<script>
(function(){
    var TYPE_HINTS = {
        voucher: 'Also appears automatically on GC &amp; Unpaids → Sold — no need to enter it twice.',
        deposit: 'Listed under Advance Payment, and counted in Advance Payment on the Summary Report.',
        session: 'Multi-session Paid entries are created automatically when a session of a paid package is done. This form is disabled for reference only.'
    };

    window.apwSetType = function(type){
        document.querySelectorAll('.apw-type-btn').forEach(function(b){
            var active = b.dataset.type === type;
            b.style.borderColor = active ? 'var(--gold)' : 'var(--border2)';
            b.style.background  = active ? 'rgba(201,106,44,0.1)' : 'var(--bg3)';
        });
        document.getElementById('apw_type').value = type;
        document.getElementById('apw-field-series').style.display = type === 'voucher' ? '' : 'none';
        document.getElementById('apw-field-code').style.display   = type === 'voucher' ? '' : 'none';
        document.getElementById('apw-field-service').style.display = type === 'voucher' ? 'none' : '';
        document.getElementById('apw-type-hint').innerHTML = TYPE_HINTS[type];

        var form = document.getElementById('apwForm');
        var submitBtn = document.getElementById('apwSubmitBtn');
        var disable = (type === 'session');
        form.querySelectorAll('input,select').forEach(function(el){
            if (el.type === 'hidden') return;
            el.disabled = disable;
        });
        submitBtn.disabled = disable;
        submitBtn.textContent = disable ? 'Created automatically' : 'Add Entry';
    };

    window.apwSetFilter = function(filter){
        document.querySelectorAll('.apw-filter-btn').forEach(function(b){
            var active = b.dataset.filter === filter;
            b.style.borderColor = active ? 'var(--gold)' : 'var(--border2)';
            b.style.background  = active ? 'rgba(201,106,44,0.1)' : 'var(--bg3)';
            b.style.color       = active ? 'var(--brown)' : 'var(--gray)';
        });
        var entries = document.querySelectorAll('.apw-entry');
        var visibleCount = 0, visibleTotal = 0;
        entries.forEach(function(e){
            var match = filter === 'all' || e.dataset.type === filter;
            e.style.display = match ? '' : 'none';
            if (match) {
                visibleCount++;
                var amtEl = e.querySelector('.apw-amt');
                if (amtEl) visibleTotal += parseFloat(amtEl.textContent.replace(/[^0-9.]/g, '')) || 0;
            }
        });
        var list = document.getElementById('apw-list');
        var empty = document.getElementById('apw-empty');
        if (list && empty) empty.style.display = (visibleCount === 0 && entries.length > 0) ? '' : 'none';
        var totalLabel = { all: 'Total', voucher: 'Voucher / GC Total', advance: 'Advance Payment Total', session: 'Multi-session Paid Total' }[filter] || 'Total';
        var totalEl = document.getElementById('apw-filter-total');
        if (totalEl) totalEl.textContent = totalLabel + ': ₱' + visibleTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    };
})();
</script>
