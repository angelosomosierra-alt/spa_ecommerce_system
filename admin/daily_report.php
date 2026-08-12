<?php
/**
 * daily_report.php — Daily Sales Report
 * Mirrors the client's Google Sheets format.
 * Access: all admins (owner, marketing, IT, cashier/receptionist)
 */
require_once '../config.php';
redirect_if_not_admin();

$msg      = '';
$msg_type = 'success';

// ── FEATURE FLAG ──────────────────────────────────────────────────────────────
// Set to true to restore full locking behaviour; false disables all lock checks.
$LOCK_FEATURE_ENABLED = true;

// Receptionist PIN gate — session-based so only prompted once per session
if (is_cashier() && empty($_SESSION['report_pin_ok'])) {
    $pin_error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_pin'])) {
        $entered = trim($_POST['report_pin']);
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered); $ps->execute();
        $pr_row = $ps->get_result()->fetch_assoc(); $ps->close();
        if ($pr_row) {
            $_SESSION['report_pin_ok'] = true;
            $_SESSION['report_receptionist_name'] = $pr_row['full_name'];
            header("Location: daily_report.php?date=" . urlencode($_GET['date'] ?? date('Y-m-d'))); exit();
        } else {
            $pin_error = 'Incorrect PIN. Please try again.';
        }
    }
    // Render PIN gate page
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daily Report — PIN Required</title>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f5f0eb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.gate-box{background:#fff;border-radius:16px;padding:2.5rem 2rem;max-width:380px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.12);text-align:center;}
.gate-box h2{margin:0 0 0.5rem;font-size:1.25rem;color:#5c3a1e;}
.gate-box p{color:#888;font-size:0.9rem;margin:0 0 1.5rem;}
.gate-box input{width:100%;padding:0.65rem;border:1px solid #ddd;border-radius:10px;font-size:1.4rem;letter-spacing:0.5em;text-align:center;box-sizing:border-box;color:#5c3a1e;margin-bottom:1rem;}
.gate-box button{width:100%;padding:0.75rem;background:#c96a2c;color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;}
.gate-box button:hover{background:#a85520;}
.err{color:#dc2626;font-size:0.85rem;margin-bottom:0.75rem;}
</style>
</head>
<body>
<div class="gate-box">
    <h2>Daily Report</h2>
    <p>Enter your 4-digit PIN to access the report.</p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="password" name="report_pin" maxlength="4" placeholder="••••" autofocus>
        <?php if ($pin_error): ?><div class="err"><?php echo htmlspecialchars($pin_error); ?></div><?php endif; ?>
        <button type="submit">Unlock Report</button>
    </form>
</div>
</body>
</html><?php
    exit();
}

// Validate and sanitize the date parameter — prevents SQL injection via $report_date
$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || !strtotime($report_date)) {
    $report_date = date('Y-m-d');
}

// Receptionist can only view today's report — show notice instead of silently redirecting
if (is_cashier() && $report_date !== date('Y-m-d')) {
    $report_date = date('Y-m-d');
    $msg      = '⚠️ Receptionists can only view today\'s report. Showing today\'s data.';
    $msg_type = 'warning';
}

$active_tab = $_GET['tab'] ?? 'log';

// ── Fetch daily report header ─────────────────────────────────────────────────
$_rpt_s = $conn->prepare("SELECT * FROM daily_reports WHERE report_date = ? LIMIT 1");
$_rpt_s->bind_param("s", $report_date);
$_rpt_s->execute();
$rpt = $_rpt_s->get_result()->fetch_assoc();
$_rpt_s->close();

// ── LOCK / UNLOCK ─────────────────────────────────────────────────────────────
if (isset($_GET['lock']) && is_full_access()) {
    if ($rpt) {
        $lock = intval($_GET['lock']);
        $by   = $lock ? (int)$_SESSION['user_id'] : null;
        $at   = $lock ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare("UPDATE daily_reports SET is_locked=?, locked_at=?, locked_by=? WHERE id=?");
        $stmt->bind_param("isii", $lock, $at, $by, $rpt['id']); $stmt->execute(); $stmt->close();
        header("Location: daily_report.php?date=$report_date&tab=$active_tab"); exit();
    }
}

// ── SUBMIT REPORT ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    verify_csrf_token();
    require_once __DIR__ . '/../notify.php';

    $fails = [];

    if (!$rpt) {
        $fails[] = 'Save the report header first.';
    } else {
        if ((float)($rpt['cash_on_hand'] ?? 0) <= 0)
            $fails[] = 'Cash on Hand must be entered and greater than zero.';
        if (empty(trim($rpt['opening_cashier'] ?? '')))
            $fails[] = 'Opening cashier name is missing.';
        if (empty(trim($rpt['closing_cashier'] ?? '')))
            $fails[] = 'Closing cashier name is missing.';
        $dq = $conn->prepare("SELECT COUNT(*) AS c FROM daily_report_denominations WHERE report_id=? AND quantity>0");
        $dq->bind_param("i", $rpt['id']); $dq->execute();
        if ((int)$dq->get_result()->fetch_assoc()['c'] === 0) $fails[] = 'Cash denominations are empty — enter at least one.';
        $dq->close();
    }

    $sq = $conn->prepare("SELECT COUNT(*) AS c FROM daily_report_spreadsheet_rows WHERE report_date=?");
    $sq->bind_param("s", $report_date); $sq->execute();
    if ((int)$sq->get_result()->fetch_assoc()['c'] === 0) $fails[] = 'No service entries in the Spreadsheet tab — add at least one row.';
    $sq->close();

    if ($fails) {
        $msg_type = 'warning';
        $msg = '<strong>Cannot submit — please fix the following:</strong><ul style="margin:0.4rem 0 0 1.2rem;">'
             . implode('', array_map(fn($f) => '<li>' . htmlspecialchars($f) . '</li>', $fails)) . '</ul>';
    } else {
        $uid = (int)$_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');
        $st  = $conn->prepare("UPDATE daily_reports SET is_locked=1, locked_at=?, locked_by=? WHERE id=?");
        $st->bind_param("sii", $now, $uid, $rpt['id']); $st->execute(); $st->close();

        $submitter = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
        $nice_date = date('F j, Y', strtotime($report_date));
        $notif_link = "daily_report.php?date=$report_date";
        $notif_msg  = "Daily report for $nice_date submitted by $submitter.";
        add_notification($conn, null, 'report_submitted', '📋 Daily Report Submitted', $notif_msg, $notif_link);

        // Best-effort email to owners — failure does not block submission
        try {
            require_once __DIR__ . '/../_mailer.php';
            $mail = make_mailer();
            $mail->isHTML(false);
            $mail->Subject = "Daily Report Submitted – $nice_date";
            $mail->Body    = "The daily report for $nice_date has been submitted by $submitter.\n\n"
                           . "Log in to the admin panel to view or unlock it.";
            $sent_to = 0;
            $ownerQ = $conn->query("SELECT email, full_name FROM users WHERE admin_role='owner' AND role='admin' AND deleted_at IS NULL AND email IS NOT NULL AND email != ''");
            if ($ownerQ) {
                foreach ($ownerQ->fetch_all(MYSQLI_ASSOC) as $ow) {
                    $mail->addAddress($ow['email'], $ow['full_name']);
                    $sent_to++;
                }
            }
            if ($sent_to > 0) $mail->send();
        } catch (\Throwable $e) {
            // intentionally silent — email is best-effort
        }

        log_activity($conn, 'submit_report', "Submitted daily report for $report_date", 'daily_report', $rpt['id'], null);

        // Re-fetch so the page renders as locked
        $r2 = $conn->prepare("SELECT * FROM daily_reports WHERE report_date=?");
        $r2->bind_param("s", $report_date); $r2->execute();
        $rpt = $r2->get_result()->fetch_assoc(); $r2->close();

        $msg_type = 'success';
        $msg = "✅ Daily report for <strong>$nice_date</strong> submitted and locked successfully.";
    }
}

// ── SAVE REPORT HEADER ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_header'])) {
    verify_csrf_token();
    $oc    = sanitize_input($_POST['opening_cashier'] ?? '');
    $cc    = sanitize_input($_POST['closing_cashier'] ?? '');
    $coh   = floatval($_POST['cash_on_hand'] ?? 0);
    $pos   = floatval($_POST['pos_reading']  ?? 0);
    $mdp   = floatval($_POST['maya_dp']      ?? 0);
    $notes = sanitize_input($_POST['notes'] ?? '');
    if ($rpt) {
        if (!$LOCK_FEATURE_ENABLED || !$rpt['is_locked']) {
            $stmt = $conn->prepare("UPDATE daily_reports SET opening_cashier=?,closing_cashier=?,cash_on_hand=?,pos_reading=?,notes=?,maya_dp=? WHERE id=?");
            $stmt->bind_param("ssddsdi", $oc, $cc, $coh, $pos, $notes, $mdp, $rpt['id']); $stmt->execute(); $stmt->close();
            $msg = "✅ Report header saved.";
        } else { $msg = "⚠️ Report is locked."; $msg_type = 'warning'; }
    } else {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO daily_reports (report_date,opening_cashier,closing_cashier,cash_on_hand,pos_reading,notes,maya_dp,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssddsdi", $report_date, $oc, $cc, $coh, $pos, $notes, $mdp, $uid); $stmt->execute(); $stmt->close();
        $msg = "✅ Daily report created.";
    }
    // Re-fetch after save using prepared statement
    $_rs2 = $conn->prepare("SELECT * FROM daily_reports WHERE report_date = ? LIMIT 1");
    $_rs2->bind_param("s", $report_date); $_rs2->execute();
    $rpt = $_rs2->get_result()->fetch_assoc(); $_rs2->close();
}

// ── SAVE DENOMINATIONS ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_denoms'])) {
    verify_csrf_token();
    if ($rpt && (!$LOCK_FEATURE_ENABLED || !$rpt['is_locked'])) {
        $denoms = [1000,500,200,100,50,20,10,5,1];
        $stmt = $conn->prepare("
            INSERT INTO daily_report_denominations (report_id, denomination, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        foreach ($denoms as $d) {
            $qty = intval($_POST['denom_'.$d] ?? 0);
            $stmt->bind_param("iii", $rpt['id'], $d, $qty);
            $stmt->execute();
        }
        $stmt->close();
        $msg = "✅ Cash denominations saved.";
    } elseif ($rpt && $LOCK_FEATURE_ENABLED && $rpt['is_locked']) { $msg = "⚠️ Report is locked."; $msg_type = 'warning'; }
    else { $msg = "⚠️ Save the report header first."; $msg_type = 'warning'; }
}

// ── SAVE GC ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gc'])) {
    verify_csrf_token();
    if (!$rpt || ($LOCK_FEATURE_ENABLED && $rpt['is_locked'])) { $msg = $rpt ? "⚠️ Report is locked." : "⚠️ Save header first."; $msg_type='warning'; }
    else {
        $gc_type   = in_array($_POST['gc_type']??'', ['sold','redeemed']) ? $_POST['gc_type'] : 'sold';
        $gc_client = sanitize_input($_POST['gc_client'] ?? '');
        $gc_series = sanitize_input($_POST['gc_series'] ?? '');
        $gc_voucher= sanitize_input($_POST['gc_voucher'] ?? '');
        $gc_qty    = max(1, intval($_POST['gc_qty'] ?? 1));
        $gc_amount = floatval($_POST['gc_amount'] ?? 0);
        $gc_remark = sanitize_input($_POST['gc_remarks'] ?? '');
        $uid = (int)$_SESSION['user_id'];
        if (!empty($gc_client) && $gc_amount > 0) {
            $stmt = $conn->prepare("INSERT INTO gift_certificates (report_date,type,series,client_name,voucher_code,qty,amount,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssidsi", $report_date, $gc_type, $gc_series, $gc_client, $gc_voucher, $gc_qty, $gc_amount, $gc_remark, $uid);
            $stmt->execute(); $stmt->close();
            $msg = "✅ Gift certificate entry saved.";
        } else { $msg = "Client name and amount are required."; $msg_type='danger'; }
    }
}
if (isset($_GET['del_gc']) && is_full_access()) {
    $stmt = $conn->prepare("DELETE FROM gift_certificates WHERE id=? AND report_date=?");
    $stmt->bind_param("is", intval($_GET['del_gc']), $report_date); $stmt->execute(); $stmt->close();
    header("Location: daily_report.php?date=$report_date&tab=gc"); exit();
}

// ── SAVE UNPAID CORP ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_unpaid'])) {
    verify_csrf_token();
    if (!$rpt || ($LOCK_FEATURE_ENABLED && $rpt['is_locked'])) { $msg = $rpt ? "⚠️ Report is locked." : "⚠️ Save header first."; $msg_type='warning'; }
    else {
        $up_name   = sanitize_input($_POST['up_name']   ?? '');
        $up_amount = floatval($_POST['up_amount'] ?? 0);
        $up_series = sanitize_input($_POST['up_series'] ?? '');
        $uid = (int)$_SESSION['user_id'];
        if (!empty($up_name) && $up_amount > 0) {
            $stmt = $conn->prepare("INSERT INTO unpaids_corp (report_date,client_name,amount,series,created_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param("ssdsi", $report_date, $up_name, $up_amount, $up_series, $uid);
            $stmt->execute(); $stmt->close();
            $msg = "✅ Unpaid entry saved.";
        } else { $msg = "Name and amount required."; $msg_type='danger'; }
    }
}
if (isset($_GET['del_unpaid']) && is_full_access()) {
    $stmt = $conn->prepare("DELETE FROM unpaids_corp WHERE id=? AND report_date=?");
    $stmt->bind_param("is", intval($_GET['del_unpaid']), $report_date); $stmt->execute(); $stmt->close();
    header("Location: daily_report.php?date=$report_date&tab=gc"); exit();
}

// ── SAVE PRODUCT SALE ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prodsale'])) {
    verify_csrf_token();
    if (!$rpt || ($LOCK_FEATURE_ENABLED && $rpt['is_locked'])) { $msg = $rpt ? "⚠️ Report is locked." : "⚠️ Save header first."; $msg_type='warning'; }
    else {
        $ps_particular = sanitize_input($_POST['ps_particular'] ?? '');
        $ps_qty        = max(1, intval($_POST['ps_qty'] ?? 1));
        $ps_price      = floatval($_POST['ps_price'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        if (!empty($ps_particular) && $ps_price > 0) {
            $stmt = $conn->prepare("INSERT INTO daily_product_sales (report_date,particular,qty,price,created_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param("ssidi", $report_date, $ps_particular, $ps_qty, $ps_price, $uid);
            $stmt->execute(); $stmt->close();
            $msg = "✅ Product sale saved.";
        } else { $msg = "Particular and price are required."; $msg_type='danger'; }
    }
}
if (isset($_GET['del_prodsale']) && is_full_access()) {
    $stmt = $conn->prepare("DELETE FROM daily_product_sales WHERE id=? AND report_date=?");
    $stmt->bind_param("is", intval($_GET['del_prodsale']), $report_date); $stmt->execute(); $stmt->close();
    header("Location: daily_report.php?date=$report_date&tab=products"); exit();
}

// ── AJAX: Unlock spreadsheet editing ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_ss_edit') {
    header('Content-Type: application/json');
    if (!verify_csrf_token_ajax()) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }
    if ($LOCK_FEATURE_ENABLED && $rpt && $rpt['is_locked']) { echo json_encode(['ok'=>false,'msg'=>'Report is locked.']); exit; }
    $pin_input = trim($_POST['pin'] ?? '');
    if (empty($pin_input)) { echo json_encode(['ok'=>false,'msg'=>'PIN is required.']); exit; }
    $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
    $ps->bind_param("s", $pin_input); $ps->execute();
    $pr = $ps->get_result()->fetch_assoc(); $ps->close();
    if (!$pr) { echo json_encode(['ok'=>false,'msg'=>'Incorrect PIN.']); exit; }
    $editor_name = $pr['full_name'];
    $_SESSION['ss_edit_unlocked'] = true;
    $_SESSION['ss_edit_person']   = $editor_name;
    $_SESSION['ss_edit_date']     = $report_date;
    $_SESSION['ss_edit_expires']  = time() + 1800;
    log_activity($conn, 'ss_edit_unlock',
        "Spreadsheet edit unlocked for {$report_date} by {$editor_name}",
        'daily_report', $rpt['id'] ?? null,
        ['id' => null, 'name' => $editor_name, 'role' => 'cashier']);
    echo json_encode(['ok'=>true,'person'=>$editor_name]); exit;
}

// ── AJAX: Save spreadsheet row ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ss_row') {
    header('Content-Type: application/json');
    if (!verify_csrf_token_ajax()) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }
    if ($LOCK_FEATURE_ENABLED && $rpt && $rpt['is_locked']) { echo json_encode(['ok'=>false,'msg'=>'Report is locked.']); exit; }
    $ss_unlocked_ajax = !empty($_SESSION['ss_edit_unlocked'])
        && ($_SESSION['ss_edit_date'] ?? '') === $report_date
        && ($_SESSION['ss_edit_expires'] ?? 0) > time();
    if (is_cashier() && !$ss_unlocked_ajax) { echo json_encode(['ok'=>false,'msg'=>'Edit not unlocked. Please enter PIN first.']); exit; }
    $ss_editor     = is_cashier()
        ? ($_SESSION['ss_edit_person'] ?? '')
        : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
    $row_id        = intval($_POST['row_id'] ?? 0);
    $row_order     = intval($_POST['row_order'] ?? 0);
    $time_in       = sanitize_input($_POST['time_in']        ?? '');
    $time_out      = sanitize_input($_POST['time_out']       ?? '');
    $slip_no       = sanitize_input($_POST['slip_no']        ?? '');
    $client_name   = sanitize_input($_POST['client_name']    ?? '');
    $service_name  = sanitize_input($_POST['service_name']   ?? '');
    $service_id    = intval($_POST['service_id']   ?? 0);
    $stylist       = sanitize_input($_POST['stylist']        ?? '');
    $therapist_id  = intval($_POST['therapist_id'] ?? 0);
    $regular_price = floatval($_POST['regular_price'] ?? 0);
    $promo_price   = floatval($_POST['promo_price']   ?? 0);
    $celeb_10      = floatval($_POST['celeb_10']      ?? 0);
    $disc_20_pwd   = floatval($_POST['disc_20_pwd']   ?? 0);
    $comm_30       = floatval($_POST['comm_30']       ?? 0);
    $comm_20       = floatval($_POST['comm_20']       ?? 0);
    $comm_15       = floatval($_POST['comm_15']       ?? 0);
    $disc_50_staff = floatval($_POST['disc_50_staff'] ?? 0);
    $net_sales     = floatval($_POST['net_sales']     ?? 0);
    $mode_of_pay   = sanitize_input($_POST['mode_of_payment'] ?? '');
    $remarks       = sanitize_input($_POST['remarks']         ?? '');
    $is_refund     = intval($_POST['is_refund'] ?? 0) ? 1 : 0;
    if ($row_id > 0) {
        // Reject editing an imported appointment row unless the user is full-access
        $src_chk = $conn->prepare("SELECT source_appointment_id FROM daily_report_spreadsheet_rows WHERE id=? AND report_date=? LIMIT 1");
        $src_chk->bind_param("is", $row_id, $report_date); $src_chk->execute();
        $src_row = $src_chk->get_result()->fetch_assoc(); $src_chk->close();
        if ($src_row && !is_null($src_row['source_appointment_id']) && !is_full_access()) {
            echo json_encode(['ok'=>false,'msg'=>'Imported appointment rows can only be edited by full-access users.']); exit;
        }
        $stmt = $conn->prepare("
            UPDATE daily_report_spreadsheet_rows SET
                row_order=?,time_in=?,time_out=?,slip_no=?,client_name=?,
                service_name=?,service_id=?,stylist=?,therapist_id=?,
                regular_price=?,promo_price=?,celeb_10=?,
                disc_20_pwd=?,comm_30=?,comm_20=?,comm_15=?,disc_50_staff=?,
                net_sales=?,mode_of_payment=?,remarks=?,is_refund=?,updated_by_name=?
            WHERE id=? AND report_date=?
        ");
        $stmt->bind_param("isssssisidddddddddssisis",
            $row_order,$time_in,$time_out,$slip_no,$client_name,
            $service_name,$service_id,$stylist,$therapist_id,
            $regular_price,$promo_price,$celeb_10,
            $disc_20_pwd,$comm_30,$comm_20,$comm_15,$disc_50_staff,
            $net_sales,$mode_of_pay,$remarks,$is_refund,$ss_editor,
            $row_id,$report_date);
        $stmt->execute(); $stmt->close();
        echo json_encode(['ok'=>true,'row_id'=>$row_id]); exit;
    } else {
        $stmt = $conn->prepare("
            INSERT INTO daily_report_spreadsheet_rows
                (report_date,row_order,time_in,time_out,slip_no,client_name,
                 service_name,service_id,stylist,therapist_id,
                 regular_price,promo_price,celeb_10,
                 disc_20_pwd,comm_30,comm_20,comm_15,disc_50_staff,
                 net_sales,mode_of_payment,remarks,is_refund,created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param("sisssssisidddddddddssis",
            $report_date,$row_order,$time_in,$time_out,$slip_no,$client_name,
            $service_name,$service_id,$stylist,$therapist_id,
            $regular_price,$promo_price,$celeb_10,
            $disc_20_pwd,$comm_30,$comm_20,$comm_15,$disc_50_staff,
            $net_sales,$mode_of_pay,$remarks,$is_refund,$ss_editor);
        $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();
        echo json_encode(['ok'=>true,'row_id'=>$new_id]); exit;
    }
}

// ── AJAX: Delete spreadsheet row ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ss_row') {
    header('Content-Type: application/json');
    if (!verify_csrf_token_ajax()) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }
    if ($LOCK_FEATURE_ENABLED && $rpt && $rpt['is_locked']) { echo json_encode(['ok'=>false,'msg'=>'Report is locked.']); exit; }
    $ss_unlocked_ajax = !empty($_SESSION['ss_edit_unlocked'])
        && ($_SESSION['ss_edit_date'] ?? '') === $report_date
        && ($_SESSION['ss_edit_expires'] ?? 0) > time();
    if (is_cashier() && !$ss_unlocked_ajax) { echo json_encode(['ok'=>false,'msg'=>'Edit not unlocked.']); exit; }
    $row_id = intval($_POST['row_id'] ?? 0);
    if ($row_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid row.']); exit; }
    // Reject deleting an imported appointment row unless the user is full-access
    $src_chk2 = $conn->prepare("SELECT source_appointment_id FROM daily_report_spreadsheet_rows WHERE id=? AND report_date=? LIMIT 1");
    $src_chk2->bind_param("is", $row_id, $report_date); $src_chk2->execute();
    $src_row2 = $src_chk2->get_result()->fetch_assoc(); $src_chk2->close();
    if ($src_row2 && !is_null($src_row2['source_appointment_id']) && !is_full_access()) {
        echo json_encode(['ok'=>false,'msg'=>'Imported appointment rows can only be deleted by full-access users.']); exit;
    }
    $stmt = $conn->prepare("DELETE FROM daily_report_spreadsheet_rows WHERE id=? AND report_date=?");
    $stmt->bind_param("is", $row_id, $report_date); $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>true]); exit;
}

// ── AUTO-IMPORT: Completed appointments → spreadsheet rows ────────────────────
// Idempotent — safe on every page load. Skips any appointment already present
// via source_appointment_id. Never modifies live appointment records.
(function() use ($conn, $report_date) {
    // Pre-load commission percents (therapist_id_service_id → percent) for column routing
    $imp_cm = [];
    $cq = $conn->query("SELECT therapist_id, service_id, commission_percent FROM therapist_commission");
    if ($cq) foreach ($cq->fetch_all(MYSQLI_ASSOC) as $_c)
        $imp_cm[(int)$_c['therapist_id'] . '_' . (int)$_c['service_id']] = (float)$_c['commission_percent'];

    // Fetch completed non-influencer appointments for this date
    $aq = $conn->prepare("
        SELECT
            a.id                      AS appt_id,
            a.appointment_date,
            a.duration_minutes,
            a.charged_price,
            a.celebration_discount,
            a.therapist_id            AS appt_therapist_id,
            o.customer_name,
            o.slip_number,
            o.payment_method,
            oi.service_id,
            COALESCE(s.name, '[Deleted Service]') AS service_name,
            s.price                   AS regular_price,
            GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', ') AS therapists,
            IFNULL(SUM(at2.commission), 0)        AS total_commission
        FROM orders o
        JOIN order_items oi  ON oi.order_id      = o.id
        JOIN services    s   ON s.id             = oi.service_id
        JOIN appointments a  ON a.order_item_id  = oi.id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists t ON t.id = at2.therapist_id
        WHERE DATE(a.appointment_date) = ?
          AND a.status     = 'completed'
          AND a.rate_type != 'influencer'
        GROUP BY a.id
        ORDER BY a.appointment_date ASC
    ");
    $aq->bind_param("s", $report_date); $aq->execute();
    $imp_appts = $aq->get_result()->fetch_all(MYSQLI_ASSOC); $aq->close();
    if (empty($imp_appts)) return;

    // Collect already-imported appointment IDs for this date (O(1) lookup)
    $eq = $conn->prepare("SELECT source_appointment_id FROM daily_report_spreadsheet_rows WHERE report_date=? AND source_appointment_id IS NOT NULL");
    $eq->bind_param("s", $report_date); $eq->execute();
    $already = array_flip(array_column($eq->get_result()->fetch_all(MYSQLI_ASSOC), 'source_appointment_id'));
    $eq->close();

    $mop_map = [
        'cash'   => 'Cash',  'gcash'  => 'GCash', 'maya'   => 'Maya',
        'card'   => 'Card',  'swiper' => 'Card',   'qr_ph'  => 'QR PH',
        'qrph'   => 'QR PH', 'online' => 'GCash',
    ];

    $ins = $conn->prepare("
        INSERT INTO daily_report_spreadsheet_rows
            (report_date, row_order, time_in, time_out, slip_no, client_name,
             service_name, service_id, stylist, therapist_id,
             regular_price, promo_price, celeb_10,
             disc_20_pwd, comm_30, comm_20, comm_15, disc_50_staff,
             net_sales, mode_of_payment, remarks, is_refund, created_by_name,
             source_appointment_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($imp_appts as $ap) {
        $appt_id = (int)$ap['appt_id'];
        if (isset($already[$appt_id])) continue; // already imported — skip

        $time_in  = date('h:i A', strtotime($ap['appointment_date']));
        $time_out = (int)$ap['duration_minutes'] > 0
            ? date('h:i A', strtotime($ap['appointment_date']) + (int)$ap['duration_minutes'] * 60)
            : '';

        $service_id    = (int)$ap['service_id'];
        $therapist_id  = (int)($ap['appt_therapist_id'] ?? 0);
        $regular_price = (float)($ap['regular_price'] ?? 0);
        $promo_price   = (float)($ap['charged_price']  ?? 0);
        $celeb_10      = (float)($ap['celebration_discount'] ?? 0);
        $disc_20_pwd   = 0.0; $disc_50_staff = 0.0;
        $total_comm    = (float)($ap['total_commission'] ?? 0);

        // Route commission to the column matching the therapist+service percent
        $pct_key = $therapist_id . '_' . $service_id;
        $pct     = $imp_cm[$pct_key] ?? 0;
        $comm_30 = $comm_20 = $comm_15 = 0.0;
        if     ($pct >= 28 && $pct <= 32) $comm_30 = $total_comm;
        elseif ($pct >= 18 && $pct <= 22) $comm_20 = $total_comm;
        elseif ($pct >= 13 && $pct <= 17) $comm_15 = $total_comm;
        else                               $comm_30 = $total_comm; // fallback

        $net_sales   = $promo_price - $total_comm;
        $raw_mop     = strtolower(trim($ap['payment_method'] ?? ''));
        $mode_of_pay = $mop_map[$raw_mop] ?? 'Cash';
        $row_order   = 0;
        $slip_no     = $ap['slip_number']   ?? '';
        $client_name = $ap['customer_name'] ?? '';
        $svc_name    = $ap['service_name']  ?? '';
        $stylist     = $ap['therapists']    ?? '';
        $remarks     = '';
        $is_refund   = 0;
        $created_by  = 'import';

        $ins->bind_param("sisssssisidddddddddssisi",
            $report_date, $row_order, $time_in, $time_out, $slip_no, $client_name,
            $svc_name, $service_id, $stylist, $therapist_id,
            $regular_price, $promo_price, $celeb_10,
            $disc_20_pwd, $comm_30, $comm_20, $comm_15, $disc_50_staff,
            $net_sales, $mode_of_pay, $remarks, $is_refund, $created_by,
            $appt_id);
        $ins->execute();
    }
    $ins->close();
})();

require_once __DIR__ . '/_daily_report_data.php';

// ─────────────────────────────────────────────────────────────────────────────
// PAGE
// ─────────────────────────────────────────────────────────────────────────────
$page_title  = 'Daily Report';
$page_icon   = '📋';
$active_page = 'daily_report';
require_once 'admin_header.php';

$locked = !empty($rpt['is_locked']);
if (!$LOCK_FEATURE_ENABLED) $locked = false;
?>
<?php if (!empty($msg)): ?>
<div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom:1rem;"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- ── DATE PICKER + LOCK STATUS ──────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
    <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
        <label style="font-size:0.82rem;font-weight:600;color:var(--brown);">📅 Report Date:</label>
        <input type="date" name="date" value="<?php echo $report_date; ?>"
               style="padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;
                      background:var(--bg3);color:var(--brown);font-size:0.85rem;">
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
    </form>

    <?php if ($locked): ?>
    <span style="background:#d4edda;color:#155724;padding:0.3rem 0.85rem;border-radius:20px;
                 font-size:0.78rem;font-weight:700;">🔒 Locked — <?php echo date('M d, Y h:i A', strtotime($rpt['locked_at'])); ?></span>
    <?php if (is_full_access()): ?>
    <a href="daily_report.php?date=<?php echo $report_date; ?>&tab=<?php echo $active_tab; ?>&lock=0"
       class="btn btn-secondary btn-sm"
       onclick="var _h=this.href;event.preventDefault();uiConfirm('Unlock this report?').then(ok=>{if(ok)window.location.href=_h;})">🔓 Unlock</a>
    <?php endif; ?>
    <?php elseif ($rpt): ?>
    <form method="POST" id="submit-report-form" style="display:inline;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="submit_report">
        <button type="button" class="btn btn-primary btn-sm"
                onclick="uiConfirm('Submit and lock this report? It will become read-only.').then(ok=>{if(ok)document.getElementById('submit-report-form').submit();})">📤 Submit Daily Report</button>
    </form>
    <?php endif; ?>

    <div style="display:flex;gap:0.5rem;margin-left:auto;">
        <a href="export_daily_report.php?date=<?php echo $report_date; ?>"
           class="btn btn-secondary btn-sm">📊 Export Excel</a>
        <a href="export_daily_report_pdf.php?date=<?php echo $report_date; ?>"
           class="btn btn-secondary btn-sm">📄 Export PDF</a>
    </div>
</div>

<!-- ── TAB NAV ────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:0.4rem;margin-bottom:1.5rem;border-bottom:2px solid var(--border2);
            padding-bottom:0;flex-wrap:wrap;">
    <?php
    $tabs = [
        'log'         => '📋 Service Log',
        'influencer'  => '🎯 Influencer',
        'summary'     => '💰 Cash & Summary',
        'expenses'    => '🧾 Expenses',
        'gc'          => '🎁 GC & Unpaids',
        'products'    => '🛍️ Products Sold',
        'spreadsheet' => '🧮 Spreadsheet',
        'analysis'    => '📊 Analysis (Internal)',
    ];
    foreach ($tabs as $tk => $tl):
        $is_a = $active_tab === $tk;
    ?>
    <a href="daily_report.php?date=<?php echo $report_date; ?>&tab=<?php echo $tk; ?>"
       style="padding:0.55rem 1rem;font-size:0.82rem;font-weight:700;text-decoration:none;
              border-radius:8px 8px 0 0;border:2px solid var(--border2);border-bottom:none;
              background:<?php echo $is_a ? 'var(--brown)' : 'var(--bg3)'; ?>;
              color:<?php echo $is_a ? '#fff' : 'var(--brown)'; ?>;
              margin-bottom:-2px;">
        <?php echo $tl; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: SERVICE LOG
══════════════════════════════════════════════════════════════════════════ -->
<?php if ($active_tab === 'log'): ?>

<div style="display:grid;grid-template-columns:1fr 260px;gap:1.25rem;align-items:start;">

    <!-- Service transactions table -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">📋 Sales Services — <?php echo date('F d, Y', strtotime($report_date)); ?></span>
            <span style="font-size:0.75rem;color:var(--gray);"><?php echo count($service_rows); ?> transactions</span>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0;overflow-x:auto;">
        <?php
        // ── Per-row commission-tier & totals accumulators ─────────────────────
        $pm_icons = ['cash'=>'💵','gcash'=>'📱','maya'=>'💜','qrph'=>'📷','bank'=>'🏦','card'=>'💳','online'=>'💳'];
        $t_reg=$t_promo=$t_celeb=$t_dpwd=$t_c30=$t_c20=$t_c15=$t_d50=$t_net=0;
        ?>
            <table style="min-width:1900px;font-size:0.78rem;">
                <thead>
                    <tr>
                        <th style="width:68px;">Time In</th>
                        <th style="width:68px;">Time Out</th>
                        <th style="width:85px;">Service Slip No.</th>
                        <th>Client Name</th>
                        <th>Services</th>
                        <th>Stylist</th>
                        <th style="text-align:right;">Regular<br>Price</th>
                        <th style="text-align:right;">Promo<br>Price</th>
                        <th style="text-align:right;">Celeb<br>10%</th>
                        <th style="text-align:right;">Disc 20%<br>(PWD/SNR)</th>
                        <th style="text-align:right;">30%<br>Commission Fee</th>
                        <th style="text-align:right;">20%<br>Commission Fee</th>
                        <th style="text-align:right;">15%<br>Commission Fee</th>
                        <th style="text-align:right;">50% Disc.<br>for Staff</th>
                        <th style="text-align:right;">Net Sales</th>
                        <th style="text-align:center;">Mode of<br>Payment</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($service_rows)): ?>
                <tr><td colspan="17" style="text-align:center;color:var(--gray);padding:2rem;">
                    No service transactions for <?php echo date('M d, Y', strtotime($report_date)); ?>.
                </td></tr>
                <?php else:
                $prev_order = null;
                foreach ($service_rows as $row):
                    $is_same_order = $prev_order === $row['order_id'];
                    // Time In / Time Out
                    $ts_in    = strtotime($row['appointment_date']);
                    $time_in  = date('h:i A', $ts_in);
                    $time_out = date('h:i A', $ts_in + ((int)($row['duration_minutes'] ?? 0)) * 60);
                    // Commission tier from rate_type (regular=30%, home=20%, hotel=15%)
                    $tier_pct = match($row['rate_type'] ?? 'regular') {
                        'home' => 20, 'hotel' => 15, default => 30
                    };
                    $c30 = ($tier_pct === 30) ? (float)$row['total_commission'] : 0;
                    $c20 = ($tier_pct === 20) ? (float)$row['total_commission'] : 0;
                    $c15 = ($tier_pct === 15) ? (float)$row['total_commission'] : 0;
                    // Discount columns
                    $disc_pwd  = in_array($row['discount_type'], ['senior','pwd']) ? (float)$row['discount_amount'] : 0;
                    $disc_50   = ($row['discount_type'] === 'employee')            ? (float)$row['discount_amount'] : 0;
                    $disc_celeb= floatval($row['celebration_discount'] ?? 0);
                    $net       = (float)$row['charged_price'] - (float)$row['total_commission'];
                    // Accumulate totals
                    $t_reg   += (float)$row['regular_price'];
                    $t_promo += (float)$row['charged_price'];
                    $t_celeb += $disc_celeb;
                    $t_dpwd  += $disc_pwd; $t_c30 += $c30; $t_c20 += $c20; $t_c15 += $c15;
                    $t_d50   += $disc_50;  $t_net += $net;
                    // Payment display
                    $display_pm = !empty($row['paymongo_method']) ? $row['paymongo_method'] : ($row['payment_method'] ?? 'cash');
                    $status_color = match($row['appt_status']) {
                        'completed' => '#198754', 'approved', 'assigned' => '#0070f3',
                        'cancelled', 'declined'  => '#dc3545', default   => '#f59e0b'
                    };
                    $prev_order = $row['order_id'];
                ?>
                <tr style="<?php echo $is_same_order ? 'background:rgba(200,164,107,0.06);' : ''; ?>">
                    <td style="white-space:nowrap;"><?php echo $time_in; ?></td>
                    <td style="white-space:nowrap;"><?php echo $time_out; ?></td>
                    <td style="font-family:monospace;font-weight:600;color:var(--gold);">
                        <?php echo $is_same_order ? '' : htmlspecialchars($row['slip_number'] ?? '—'); ?>
                    </td>
                    <td style="font-weight:<?php echo $is_same_order ? '400' : '600'; ?>;white-space:nowrap;">
                        <?php echo $is_same_order ? '<span style="color:var(--gray);">↳ same</span>' : htmlspecialchars($row['customer_name']); ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                    <td style="color:var(--gray);white-space:nowrap;"><?php echo htmlspecialchars($row['therapists'] ?? '—'); ?></td>
                    <td style="text-align:right;color:var(--gray);">₱<?php echo number_format($row['regular_price'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--brown);">₱<?php echo number_format($row['charged_price'], 2); ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $disc_celeb > 0 ? '₱'.number_format($disc_celeb, 2) : '—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $disc_pwd > 0 ? '₱'.number_format($disc_pwd, 2) : '—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $c30 > 0 ? '₱'.number_format($c30, 2) : '—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $c20 > 0 ? '₱'.number_format($c20, 2) : '—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $c15 > 0 ? '₱'.number_format($c15, 2) : '—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $disc_50 > 0 ? '₱'.number_format($disc_50, 2) : '—'; ?></td>
                    <td style="text-align:right;font-weight:700;color:#198754;">₱<?php echo number_format($net, 2); ?></td>
                    <td style="text-align:center;"><?php echo ($pm_icons[$display_pm] ?? '💰').' '.strtoupper($display_pm); ?></td>
                    <td style="color:var(--gray);">
                        <span style="color:<?php echo $status_color; ?>;font-weight:600;"><?php echo ucfirst($row['appt_status']); ?></span>
                        · <?php echo strtoupper($row['rate_type']); ?>
                    </td>
                </tr>
                <?php if (!empty($addons_by_appt[$row['appt_id']])): ?>
                <?php foreach ($addons_by_appt[$row['appt_id']] as $addon):
                    $a_tier = match($addon['rate_type'] ?? 'regular') { 'home'=>20,'hotel'=>15,default=>30 };
                    $a_c30  = ($a_tier===30) ? (float)$addon['commission'] : 0;
                    $a_c20  = ($a_tier===20) ? (float)$addon['commission'] : 0;
                    $a_c15  = ($a_tier===15) ? (float)$addon['commission'] : 0;
                    $a_net  = (float)$addon['charged_price'] - (float)$addon['commission'];
                    $t_promo += (float)$addon['charged_price'];
                    $t_c30 += $a_c30; $t_c20 += $a_c20; $t_c15 += $a_c15; $t_net += $a_net;
                    $a_pm = $addon['payment_method'] ?? 'cash';
                ?>
                <tr style="background:rgba(200,164,107,0.04);border-left:3px solid var(--gold);">
                    <td colspan="2" style="text-align:center;color:var(--gray);font-size:0.72rem;">➕ add-on</td>
                    <td></td>
                    <td style="color:var(--gray);">↳ add-on</td>
                    <td style="color:var(--brown);">
                        <?php echo htmlspecialchars($addon['service_name']); ?>
                        <span style="font-size:0.7rem;color:var(--gray);"> · <?php echo htmlspecialchars($addon['person_label']); ?></span>
                    </td>
                    <td style="color:var(--gray);"><?php echo htmlspecialchars($addon['therapist_name'] ?? '—'); ?></td>
                    <td></td>
                    <td style="text-align:right;font-weight:700;color:var(--brown);">₱<?php echo number_format($addon['charged_price'], 2); ?></td>
                    <td></td>
                    <td></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $a_c30>0?'₱'.number_format($a_c30,2):'—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $a_c20>0?'₱'.number_format($a_c20,2):'—'; ?></td>
                    <td style="text-align:right;color:var(--rust);"><?php echo $a_c15>0?'₱'.number_format($a_c15,2):'—'; ?></td>
                    <td></td>
                    <td style="text-align:right;font-weight:700;color:#198754;">₱<?php echo number_format($a_net, 2); ?></td>
                    <td style="text-align:center;"><?php echo ($pm_icons[$a_pm]??'💰').' '.strtoupper($a_pm); ?></td>
                    <td style="color:var(--gray);font-size:0.72rem;">ADD-ON</td>
                </tr>
                <?php endforeach; endif; ?>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg3);font-weight:700;border-top:2px solid var(--border2);">
                        <td colspan="6" style="text-align:right;font-size:0.8rem;padding-right:0.5rem;">TOTALS →</td>
                        <td style="text-align:right;">₱<?php echo number_format($t_reg, 2); ?></td>
                        <td style="text-align:right;color:var(--brown);">₱<?php echo number_format($t_promo, 2); ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_celeb>0?'₱'.number_format($t_celeb,2):'—'; ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_dpwd>0?'₱'.number_format($t_dpwd,2):'—'; ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_c30>0?'₱'.number_format($t_c30,2):'—'; ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_c20>0?'₱'.number_format($t_c20,2):'—'; ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_c15>0?'₱'.number_format($t_c15,2):'—'; ?></td>
                        <td style="text-align:right;color:var(--rust);"><?php echo $t_d50>0?'₱'.number_format($t_d50,2):'—'; ?></td>
                        <td style="text-align:right;color:#198754;">₱<?php echo number_format($t_net, 2); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Report header sidebar -->
    <div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">📄 Report Header</span></div>
            <div class="panel-body" style="padding:1rem;">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="save_header" value="1">
                    <div style="margin-bottom:0.65rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Opening Cashier</label>
                        <input type="text" name="opening_cashier"
                               value="<?php echo htmlspecialchars($rpt['opening_cashier'] ?? ''); ?>"
                               placeholder="Name" <?php echo $locked ? 'disabled' : ''; ?>
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:0.65rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Closing Cashier</label>
                        <input type="text" name="closing_cashier"
                               value="<?php echo htmlspecialchars($rpt['closing_cashier'] ?? ''); ?>"
                               placeholder="Name" <?php echo $locked ? 'disabled' : ''; ?>
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:0.65rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">POS Machine Reading (₱)</label>
                        <input type="number" name="pos_reading" step="0.01" min="0"
                               value="<?php echo floatval($rpt['pos_reading'] ?? 0); ?>"
                               <?php echo $locked ? 'disabled' : ''; ?>
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                        <?php if (isset($pos_reading_computed)): ?>
                        <div style="font-size:0.7rem;color:<?php echo abs($pos_variance ?? 0) > 0.01 ? '#dc3545' : 'var(--gray)'; ?>;margin-top:2px;">
                            Computed: ₱<?php echo number_format($pos_reading_computed, 2); ?>
                            <?php if (abs($pos_variance ?? 0) > 0.01): ?>
                            — Variance: <?php echo ($pos_variance > 0 ? '+' : ''); ?>₱<?php echo number_format($pos_variance, 2); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-bottom:0.65rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Maya (DP) (₱)</label>
                        <input type="number" name="maya_dp" step="0.01" min="0"
                               value="<?php echo floatval($rpt['maya_dp'] ?? 0); ?>"
                               <?php echo $locked ? 'disabled' : ''; ?>
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                        <div style="font-size:0.7rem;color:var(--gray);margin-top:2px;">
                            Advance payments via Maya DP (deducted from Net Cash)
                        </div>
                    </div>
                    <div style="margin-bottom:0.65rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Cash on Hand / COH (₱)</label>
                        <input type="number" name="cash_on_hand" id="coh-input" step="0.01" min="0"
                               value="<?php echo $denom_total; ?>"
                               readonly
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg2);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;cursor:not-allowed;opacity:0.75;">
                        <div style="font-size:0.7rem;color:var(--gray);margin-top:2px;">
                            Auto-calculated from denomination count — update the Denomination Breakdown to change this.
                        </div>
                    </div>
                    <div style="margin-bottom:0.75rem;">
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Notes</label>
                        <textarea name="notes" rows="2" <?php echo $locked ? 'disabled' : ''; ?>
                                  style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                         border-radius:7px;background:var(--bg3);color:var(--brown);
                                         font-size:0.82rem;box-sizing:border-box;resize:vertical;"
                                  placeholder="Optional notes..."><?php echo htmlspecialchars($rpt['notes'] ?? ''); ?></textarea>
                    </div>
                    <?php if (!$locked): ?>
                    <button type="submit" class="btn btn-primary" style="width:100%;font-size:0.82rem;">💾 Save Header</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: INFLUENCER / MARKETING
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'influencer'): ?>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🎯 Sales Services — Influencer / Marketing</span>
        <span style="font-size:0.75rem;color:var(--gray);"><?php echo count($influencer_rows); ?> transactions</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;overflow-x:auto;">
        <table style="min-width:700px;">
            <thead>
                <tr>
                    <th>Slip No.</th>
                    <th>Client Name</th>
                    <th>Service</th>
                    <th>Therapist</th>
                    <th style="text-align:right;">Regular Price</th>
                    <th style="text-align:right;">Comp. (At Cost)</th>
                    <th style="text-align:right;">Commission Fee</th>
                    <th style="text-align:right;">Total Mktg Exp.</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($influencer_rows)): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--gray);padding:2rem;">
                No influencer/marketing transactions for <?php echo date('M d, Y', strtotime($report_date)); ?>.
            </td></tr>
            <?php else: foreach ($influencer_rows as $row): ?>
            <tr>
                <td style="font-family:monospace;font-size:0.82rem;font-weight:600;color:var(--gold);">
                    <?php echo htmlspecialchars($row['slip_number'] ?? '—'); ?>
                </td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($row['service_name']); ?></td>
                <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($row['therapists'] ?? '—'); ?></td>
                <td style="text-align:right;font-size:0.82rem;color:var(--gray);">₱<?php echo number_format(floatval($row['regular_price'] ?? 0),2); ?></td>
                <td style="text-align:right;font-size:0.82rem;">₱<?php echo number_format(floatval($row['at_cost']??0),2); ?></td>
                <td style="text-align:right;color:var(--rust);">₱<?php echo number_format(floatval($row['commission']??0),2); ?></td>
                <td style="text-align:right;font-weight:700;color:var(--rust);">₱<?php echo number_format(floatval($row['at_cost']??0)+floatval($row['commission']??0),2); ?></td>
                <td style="font-size:0.78rem;background:rgba(201,106,44,0.08);color:var(--rust);font-weight:600;">INFLUENCER</td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg3);font-weight:700;border-top:2px solid var(--border2);">
                    <td colspan="4" style="text-align:right;font-size:0.82rem;padding-right:0.5rem;">Totals →</td>
                    <td style="text-align:right;color:var(--gray);">₱<?php echo number_format(array_sum(array_column($influencer_rows,'regular_price')),2); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format($influencer_at_cost_total,2); ?></td>
                    <td style="text-align:right;color:var(--rust);">₱<?php echo number_format(array_sum(array_column($influencer_rows,'commission')),2); ?></td>
                    <td style="text-align:right;color:var(--rust);">₱<?php echo number_format($mktg_expense,2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: CASH & SUMMARY
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'summary'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

    <!-- Cash Denomination Breakdown -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">💵 Cash Denomination Breakdown</span></div>
        <div class="panel-body" style="padding:1rem;">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_denoms" value="1">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:0.4rem 0.5rem;font-size:0.78rem;color:var(--gray);border-bottom:1px solid var(--border2);">Denomination</th>
                            <th style="text-align:center;padding:0.4rem 0.5rem;font-size:0.78rem;color:var(--gray);border-bottom:1px solid var(--border2);">Qty</th>
                            <th style="text-align:right;padding:0.4rem 0.5rem;font-size:0.78rem;color:var(--gray);border-bottom:1px solid var(--border2);">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($denom_list as $d):
                        $saved_qty = intval($denoms_saved[$d]['quantity'] ?? 0);
                        $saved_tot = floatval($denoms_saved[$d]['total']    ?? 0);
                    ?>
                    <tr style="border-bottom:1px solid var(--border2);">
                        <td style="padding:0.35rem 0.5rem;font-weight:600;color:var(--brown);">₱<?php echo number_format($d, $d < 1 ? 2 : 0); ?></td>
                        <td style="padding:0.35rem 0.5rem;text-align:center;">
                            <input type="number" name="denom_<?php echo $d; ?>"
                                   value="<?php echo $saved_qty; ?>" min="0"
                                   <?php echo $locked ? 'disabled' : ''; ?>
                                   oninput="updateDenomTotal(this, <?php echo $d; ?>)"
                                   style="width:70px;padding:0.3rem;border:1px solid var(--border2);
                                          border-radius:6px;background:var(--bg3);color:var(--brown);
                                          font-size:0.85rem;text-align:center;">
                        </td>
                        <td id="denom-total-<?php echo $d; ?>" style="padding:0.35rem 0.5rem;text-align:right;font-family:monospace;font-size:0.85rem;color:var(--brown);">
                            ₱<?php echo number_format($saved_tot,2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--bg3);border-top:2px solid var(--border2);">
                            <td colspan="2" style="padding:0.5rem;font-weight:700;font-size:0.9rem;color:var(--brown);">TOTAL</td>
                            <td id="denom-grand-total" style="padding:0.5rem;text-align:right;font-weight:700;font-size:0.9rem;color:var(--gold);">
                                ₱<?php echo number_format($denom_total,2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php if (!$locked): ?>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.75rem;font-size:0.82rem;">💾 Save Denominations</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Upcoming Paid Appointments (paid today, future service date) -->
    <?php if (!empty($upcoming_paid)): ?>
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">📅 Upcoming Paid (paid today, future service)</span>
            <span style="background:#0070f3;color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
                ₱<?php echo number_format(array_sum(array_column($upcoming_paid, 'final_amount')), 2); ?>
            </span>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Service</th>
                        <th>Appt Date</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($upcoming_paid as $up): ?>
                <tr>
                    <td><?php echo htmlspecialchars($up['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($up['service_name']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($up['appointment_date'])); ?></td>
                    <td style="text-align:right;font-weight:600;">₱<?php echo number_format($up['final_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($up['payment_method'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:0.5rem 1rem;font-size:0.72rem;color:var(--gray);border-top:1px solid var(--border2);">
            Cash from these bookings is already counted in CASH RECEIVED TODAY. Each service will appear in sales on its appointment date when marked complete.
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Report -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">📊 Summary Report</span></div>
        <div class="panel-body" style="padding:0;">
            <?php
            // Exact Excel summary order
            $summary_rows = [
                ['label' => 'GROSS SALES',            'val' => $gross_sales,          'color' => '#198754', 'bold' => true,
                 'note' => 'POS Reading + Sold GC − Mktg Expense'],
                ['label' => 'STAFF CF',               'val' => $staff_cf,             'color' => 'var(--rust)'],
                ['label' => 'SOLD GC',                'val' => $gc_sold_total,        'color' => 'var(--brown)'],
                ['label' => 'POS READING',            'val' => $pos_reading,          'color' => 'var(--brown)',
                 'note' => 'Manual entry from POS machine'],
                ['label' => 'DISCOUNTS',              'val' => $total_discounts,      'color' => 'var(--rust)'],
                ['label' => 'CELEB. DISCOUNTS 10%',  'val' => $celeb_discount,       'color' => 'var(--rust)'],
                ['label' => 'REDEEMED GC',            'val' => $gc_redeem_total,      'color' => 'var(--rust)'],
                ['label' => 'SWIPER',                 'val' => $card_total,           'color' => 'var(--brown)',
                 'note' => 'Card / bank swiper'],
                ['label' => 'GCASH',                  'val' => $gcash_total,          'color' => 'var(--brown)'],
                ['label' => 'MAYA',                   'val' => $maya_total,           'color' => 'var(--brown)'],
                ['label' => 'QRPH',                   'val' => $qrph_total,           'color' => 'var(--brown)'],
                ['label' => 'UNPAIDS',                'val' => $unpaids_total,        'color' => 'var(--rust)'],
                ['label' => 'MARKETING EXPENSE',      'val' => $mktg_expense,         'color' => 'var(--rust)'],
                ['label' => 'ADVANCE PAYMENT',        'val' => $advance_payment_total,'color' => 'var(--rust)'],
                ['label' => 'MAYA (DP)',              'val' => $maya_dp_total,        'color' => 'var(--rust)'],
                ['label' => 'PRODUCT SOLD',           'val' => $prod_sold_total,      'color' => '#198754'],
                ['label' => 'EXPENSES',               'val' => $expenses_total,       'color' => 'var(--rust)'],
                ['label' => 'SPREADSHEET NET SALES',  'val' => $spreadsheet_net_total,    'color' => '#198754',
                 'note' => 'Non-refund rows from Spreadsheet tab — added to Gross & Net Cash'],
                ['label' => 'SPREADSHEET REFUNDS',   'val' => $spreadsheet_refund_total, 'color' => 'var(--rust)',
                 'note' => 'Refund rows from Spreadsheet tab — deducted from Net Cash'],
                ['label' => 'NET CASH',               'val' => $net_cash,             'color' => '#198754', 'bold' => true,
                 'bg' => 'rgba(25,135,84,0.07)',
                 'note' => 'POS Reading − payments − discounts − unpaids − advance − expenses − mktg ± spreadsheet'],
                ['label' => 'COH (Cash on Hand)',     'val' => $cash_on_hand,         'color' => '#0070f3', 'bold' => true,
                 'id' => 'live-coh'],
                ['label' => '(SHORT) / OVER',         'val' => $short_over,
                 'color' => $short_over >= 0 ? '#198754' : '#dc3545', 'bold' => true,
                 'bg' => $short_over >= 0 ? 'rgba(25,135,84,0.1)' : 'rgba(220,53,69,0.1)',
                 'note' => 'COH − Net Cash', 'id' => 'live-short-over'],
            ];
            ?>
            <?php foreach ($summary_rows as $sr): ?>
            <?php if (!empty($sr['is_separator'])): ?>
            <div style="padding:0.4rem 1.1rem;background:var(--bg3);border-bottom:1px solid var(--border2);">
                <span style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.06em;"><?php echo $sr['label']; ?></span>
                <?php if (!empty($sr['note'])): ?><span style="font-size:0.65rem;color:var(--gray);margin-left:0.5rem;"><?php echo $sr['note']; ?></span><?php endif; ?>
            </div>
            <?php else: ?>
            <div <?php if (!empty($sr['id'])): ?>id="<?php echo $sr['id']; ?>"<?php endif; ?>
                 style="display:flex;justify-content:space-between;align-items:center;
                        padding:0.5rem 1.1rem;border-bottom:1px solid var(--border2);
                        background:<?php echo $sr['bg'] ?? 'transparent'; ?>;">
                <div>
                    <span style="font-size:0.82rem;font-weight:<?php echo !empty($sr['bold']) ? '700' : '500'; ?>;
                                 color:var(--brown);"><?php echo $sr['label']; ?></span>
                    <?php if (!empty($sr['note'])): ?>
                    <div style="font-size:0.68rem;color:var(--gray);"><?php echo $sr['note']; ?></div>
                    <?php endif; ?>
                </div>
                <span <?php if (!empty($sr['id'])): ?>id="<?php echo $sr['id']; ?>-val"<?php endif; ?>
                      style="font-family:monospace;font-size:0.88rem;font-weight:<?php echo !empty($sr['bold']) ? '700' : '500'; ?>;
                             color:<?php echo $sr['color']; ?>;">
                    <?php echo $sr['val'] < 0 ? '(' : ''; ?>₱<?php echo number_format(abs($sr['val']),2); ?><?php echo $sr['val'] < 0 ? ')' : ''; ?>
                </span>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: EXPENSES
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'expenses'): ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start;">
    <div class="panel">
        <div class="panel-header"><span class="panel-title">➕ Add Expense</span></div>
        <div class="panel-body" style="padding:1rem;">
            <?php if ($locked): ?>
            <div class="alert alert-warning">🔒 Report is locked. Cannot add expenses.</div>
            <?php else:
                $GLOBALS['daily_report_locked'] = false;
                require_once 'expenses_widget.php';
            endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🧾 Expenses — <?php echo date('M d, Y', strtotime($report_date)); ?></span>
            <span style="background:var(--rust);color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
                ₱<?php echo number_format($expenses_total,2); ?> total
            </span>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead>
                    <tr><th>Category</th><th>Description</th><th style="text-align:right;">Amount</th><th>Logged by</th><th>Time</th></tr>
                </thead>
                <tbody>
                <?php if (empty($expenses)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--gray);padding:2rem;">No expenses logged for this date.</td></tr>
                <?php else: foreach ($expenses as $e):
                    $cat_icons = ['water'=>'💧','laundry'=>'🧺','supplies'=>'🛒','utilities'=>'💡','food'=>'🍱','transport'=>'🚗','maintenance'=>'🔧','misc'=>'📦'];
                ?>
                <tr>
                    <td><span style="font-size:0.9rem;"><?php echo $cat_icons[$e['category']] ?? '📦'; ?></span> <?php echo ucfirst($e['category']); ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($e['label']); ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);">₱<?php echo number_format($e['amount'],2); ?></td>
                    <td style="font-size:0.78rem;color:var(--gray);">
                        <?php
                        $by = $conn->query("SELECT full_name FROM users WHERE id={$e['added_by']}")->fetch_assoc();
                        echo htmlspecialchars($by['full_name'] ?? 'System');
                        ?>
                    </td>
                    <td style="font-size:0.75rem;color:var(--gray);"><?php echo date('h:i A', strtotime($e['created_at'])); ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg3);font-weight:700;">
                        <td colspan="2" style="text-align:right;">Total Expenses</td>
                        <td style="text-align:right;color:var(--rust);">₱<?php echo number_format($expenses_total,2); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: GC & UNPAIDS
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'gc'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

    <!-- GC Sold -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🎁 Service GC Sold</span>
            <span style="font-size:0.75rem;color:var(--gray);">Total: ₱<?php echo number_format($gc_sold_total,2); ?></span>
        </div>
        <?php if (!$locked): ?>
        <div style="padding:0.75rem 1rem;border-bottom:1px solid var(--border2);background:var(--bg3);">
            <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_gc" value="1">
                <input type="hidden" name="gc_type" value="sold">
                <input type="text" name="gc_client" placeholder="Client name *" required
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="text" name="gc_series" placeholder="Series (e.g. GC-001)"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="text" name="gc_voucher" placeholder="Voucher code"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="number" name="gc_amount" placeholder="Amount (₱) *" required step="0.01" min="1"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <button type="submit" class="btn btn-primary btn-sm" style="grid-column:1/-1;">➕ Add Sold GC</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead><tr><th>Series</th><th>Client</th><th style="text-align:right;">Amount</th><?php if (!$locked): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($gc_sold)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--gray);padding:1.5rem;">No GC sold today.</td></tr>
                <?php else: foreach ($gc_sold as $gc): ?>
                <tr>
                    <td style="font-size:0.78rem;font-family:monospace;"><?php echo htmlspecialchars($gc['series'] ?: '—'); ?></td>
                    <td style="font-weight:600;font-size:0.85rem;"><?php echo htmlspecialchars($gc['client_name']); ?></td>
                    <td style="text-align:right;font-weight:700;color:#198754;">₱<?php echo number_format($gc['amount'],2); ?></td>
                    <?php if (!$locked): ?>
                    <td><a href="daily_report.php?date=<?php echo $report_date; ?>&tab=gc&del_gc=<?php echo $gc['id']; ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.7rem;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this GC entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot><tr style="background:var(--bg3);font-weight:700;"><td colspan="2">Total</td><td style="text-align:right;color:#198754;">₱<?php echo number_format($gc_sold_total,2); ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr></tfoot>
            </table>
        </div>
    </div>

    <!-- GC Redeemed -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🎟️ Paid / Redeemed GC</span>
            <span style="font-size:0.75rem;color:var(--gray);">Total: ₱<?php echo number_format($gc_redeem_total,2); ?></span>
        </div>
        <?php if (!$locked): ?>
        <div style="padding:0.75rem 1rem;border-bottom:1px solid var(--border2);background:var(--bg3);">
            <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_gc" value="1">
                <input type="hidden" name="gc_type" value="redeemed">
                <input type="text" name="gc_client" placeholder="Client name *" required
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="text" name="gc_series" placeholder="Series"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="text" name="gc_voucher" placeholder="Voucher code"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <input type="number" name="gc_amount" placeholder="Amount (₱) *" required step="0.01" min="1"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);">
                <button type="submit" class="btn btn-primary btn-sm" style="grid-column:1/-1;">➕ Add Redeemed GC</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead><tr><th>Series</th><th>Client</th><th style="text-align:right;">Amount</th><?php if (!$locked): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($gc_redeemed)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--gray);padding:1.5rem;">No GC redeemed today.</td></tr>
                <?php else: foreach ($gc_redeemed as $gc): ?>
                <tr>
                    <td style="font-size:0.78rem;font-family:monospace;"><?php echo htmlspecialchars($gc['series'] ?: '—'); ?></td>
                    <td style="font-weight:600;font-size:0.85rem;"><?php echo htmlspecialchars($gc['client_name']); ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);">₱<?php echo number_format($gc['amount'],2); ?></td>
                    <?php if (!$locked): ?>
                    <td><a href="daily_report.php?date=<?php echo $report_date; ?>&tab=gc&del_gc=<?php echo $gc['id']; ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.7rem;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot><tr style="background:var(--bg3);font-weight:700;"><td colspan="2">Total</td><td style="text-align:right;color:var(--rust);">₱<?php echo number_format($gc_redeem_total,2); ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr></tfoot>
            </table>
        </div>
    </div>

    <!-- Unpaids Corp -->
    <div class="panel" style="grid-column:1/-1;">
        <div class="panel-header">
            <span class="panel-title">🏢 Unpaids Corp.</span>
            <span style="font-size:0.75rem;color:var(--gray);">Total: ₱<?php echo number_format($unpaids_total,2); ?></span>
        </div>
        <?php if (!$locked): ?>
        <div style="padding:0.75rem 1rem;border-bottom:1px solid var(--border2);background:var(--bg3);">
            <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_unpaid" value="1">
                <div>
                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:2px;">Client Name *</label>
                    <input type="text" name="up_name" placeholder="Company or person" required
                           style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);width:180px;">
                </div>
                <div>
                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:2px;">Amount (₱) *</label>
                    <input type="number" name="up_amount" placeholder="0.00" required step="0.01" min="1"
                           style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);width:120px;">
                </div>
                <div>
                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:2px;">Series / Invoice</label>
                    <input type="text" name="up_series" placeholder="e.g. INV-001"
                           style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:0.82rem;color:var(--brown);width:130px;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">➕ Add</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead><tr><th>Client Name</th><th>Series / Invoice</th><th style="text-align:right;">Amount</th><?php if (!$locked): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($unpaids)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--gray);padding:1.5rem;">No unpaid corporate accounts today.</td></tr>
                <?php else: foreach ($unpaids as $up): ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($up['client_name']); ?></td>
                    <td style="font-family:monospace;font-size:0.82rem;"><?php echo htmlspecialchars($up['series'] ?: '—'); ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);">₱<?php echo number_format($up['amount'],2); ?></td>
                    <?php if (!$locked): ?>
                    <td><a href="daily_report.php?date=<?php echo $report_date; ?>&tab=gc&del_unpaid=<?php echo $up['id']; ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.7rem;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this unpaid entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot><tr style="background:var(--bg3);font-weight:700;"><td colspan="2">Total Unpaids</td><td style="text-align:right;color:var(--rust);">₱<?php echo number_format($unpaids_total,2); ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr></tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: PRODUCTS SOLD
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'products'): ?>

<div style="display:grid;grid-template-columns:340px 1fr;gap:1.5rem;align-items:start;">

    <!-- Add form -->
    <?php if (!$locked): ?>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">➕ Add Product Sale</span></div>
        <div class="panel-body" style="padding:1rem;">
            <form method="POST" style="display:flex;flex-direction:column;gap:0.65rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_prodsale" value="1">
                <div>
                    <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Product / Particular *</label>
                    <input type="text" name="ps_particular" required placeholder="e.g. Massage Oil 100ml"
                           style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                  border-radius:7px;background:var(--bg3);color:var(--brown);
                                  font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Qty</label>
                        <input type="number" name="ps_qty" value="1" min="1" required
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--brown);display:block;margin-bottom:3px;">Unit Price (₱) *</label>
                        <input type="number" name="ps_price" step="0.01" min="0.01" required placeholder="0.00"
                               style="width:100%;padding:0.45rem 0.65rem;border:1px solid var(--border2);
                                      border-radius:7px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                </div>
                <div style="background:rgba(25,135,84,0.07);border-left:3px solid #198754;
                            padding:0.5rem 0.75rem;border-radius:6px;font-size:0.78rem;color:var(--brown);">
                    💡 For manual/over-the-counter sales not processed through the system.
                </div>
                <button type="submit" class="btn btn-primary">➕ Add Product Sale</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Product sales list -->
    <div class="panel" style="<?php echo $locked ? 'grid-column:1/-1;' : ''; ?>">
        <div class="panel-header">
            <span class="panel-title">🛍️ Product Sales — <?php echo date('M d, Y', strtotime($report_date)); ?></span>
            <span style="background:#198754;color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
                ₱<?php echo number_format($prod_sold_total,2); ?> total
            </span>
        </div>
        <div style="padding:0.75rem 1rem;">
            <?php $has_any_products = !empty($product_sales) || !empty($system_product_sales); ?>
            <?php if (!$has_any_products): ?>
            <p style="color:var(--gray);font-size:0.85rem;padding:0.5rem 0;">No product sales today.</p>

            <?php else: ?>

            <?php if (!empty($system_product_sales)): ?>
            <p style="font-size:0.75rem;font-weight:600;color:var(--brown);margin-bottom:0.4rem;">System Orders</p>
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem;margin-bottom:1rem;">
                <thead>
                    <tr style="background:var(--bg3);">
                        <th style="padding:5px 8px;text-align:left;">Customer</th>
                        <th style="padding:5px 8px;text-align:left;">Product</th>
                        <th style="padding:5px 8px;text-align:center;">Qty</th>
                        <th style="padding:5px 8px;text-align:right;">Unit Price</th>
                        <th style="padding:5px 8px;text-align:right;">Total</th>
                        <th style="padding:5px 8px;text-align:center;">Payment</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($system_product_sales as $_sp):
                    $_sp_pm = !empty($_sp['paymongo_method'])
                            ? $_sp['paymongo_method']
                            : ($_sp['payment_method'] ?? 'cash');
                    $_sp_icons = ['cash'=>'💵','gcash'=>'📱','maya'=>'💜',
                                  'qrph'=>'📷','card'=>'💳','bank'=>'🏦'];
                    $_sp_icon = $_sp_icons[$_sp_pm] ?? '💰';
                ?>
                <tr style="border-bottom:0.5px solid var(--border2);">
                    <td style="padding:5px 8px;"><?php echo htmlspecialchars($_sp['customer_name']); ?></td>
                    <td style="padding:5px 8px;"><?php echo htmlspecialchars($_sp['particular']); ?></td>
                    <td style="padding:5px 8px;text-align:center;"><?php echo intval($_sp['qty']); ?></td>
                    <td style="padding:5px 8px;text-align:right;">₱<?php echo number_format($_sp['price'],2); ?></td>
                    <td style="padding:5px 8px;text-align:right;font-weight:600;color:#198754;">₱<?php echo number_format($_sp['amount'],2); ?></td>
                    <td style="padding:5px 8px;text-align:center;font-size:0.78rem;"><?php echo $_sp_icon . ' ' . strtoupper($_sp_pm); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($product_sales)): ?>
            <p style="font-size:0.75rem;font-weight:600;color:var(--brown);margin-bottom:0.4rem;">Manual Entries</p>
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                <thead>
                    <tr style="background:var(--bg3);">
                        <th style="padding:5px 8px;text-align:left;">Particular</th>
                        <th style="padding:5px 8px;text-align:center;">Qty</th>
                        <th style="padding:5px 8px;text-align:right;">Unit Price</th>
                        <th style="padding:5px 8px;text-align:right;">Total</th>
                        <th style="padding:5px 8px;text-align:right;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($product_sales as $ps): ?>
                <tr style="border-bottom:0.5px solid var(--border2);">
                    <td style="padding:5px 8px;"><?php echo htmlspecialchars($ps['particular']); ?></td>
                    <td style="padding:5px 8px;text-align:center;"><?php echo intval($ps['qty']); ?></td>
                    <td style="padding:5px 8px;text-align:right;">₱<?php echo number_format($ps['price'],2); ?></td>
                    <td style="padding:5px 8px;text-align:right;font-weight:600;color:#198754;">₱<?php echo number_format($ps['qty'] * $ps['price'],2); ?></td>
                    <td style="padding:5px 8px;text-align:right;">
                        <?php if (!$locked): ?>
                        <a href="daily_report.php?date=<?php echo $report_date; ?>&tab=products&del_prodsale=<?php echo $ps['id']; ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.7rem;"
                           onclick="var _h=this.href;event.preventDefault();uiConfirm('Remove this entry?').then(ok=>{if(ok)window.location.href=_h;})">✕</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div style="margin-top:0.75rem;padding:0.5rem 0.75rem;background:var(--bg3);
                        border-radius:6px;display:flex;justify-content:space-between;
                        font-weight:700;font-size:0.85rem;">
                <span>Total Product Sales</span>
                <span style="color:#198754;">₱<?php echo number_format($prod_sold_total,2); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: SPREADSHEET
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'spreadsheet'):
$ss_unlocked = !$locked
    && !empty($_SESSION['ss_edit_unlocked'])
    && ($_SESSION['ss_edit_date'] ?? '') === $report_date
    && ($_SESSION['ss_edit_expires'] ?? 0) > time();
$ss_editor = $ss_unlocked ? ($_SESSION['ss_edit_person'] ?? '') : '';
$can_edit  = !$locked && (!is_cashier() || $ss_unlocked);
$_ss_svc_q = $conn->query("SELECT id, name, price FROM services ORDER BY name ASC");
$ss_services_list = $_ss_svc_q ? $_ss_svc_q->fetch_all(MYSQLI_ASSOC) : [];
$_ss_th_q = $conn->query("SELECT id, full_name FROM therapists ORDER BY full_name ASC");
$ss_therapists_list = $_ss_th_q ? $_ss_th_q->fetch_all(MYSQLI_ASSOC) : [];
$ss_mop_list = ['Cash','GCash','Maya','Card','QR PH','Unpaid'];
$_ss_comm_q = $conn->query("SELECT therapist_id, service_id, commission_percent FROM therapist_commission");
$ss_commissions = [];
if ($_ss_comm_q) { foreach ($_ss_comm_q->fetch_all(MYSQLI_ASSOC) as $_c) {
    $ss_commissions[(int)$_c['therapist_id'].'_'.(int)$_c['service_id']] = (float)$_c['commission_percent'];
} }
?>
<?php /* ── SPREADSHEET GRID ── */ ?>

<?php if (!$locked && !$can_edit): /* cashier — PIN required */ ?>
<div class="panel" style="margin-bottom:1rem;">
    <div class="panel-header" style="flex-wrap:wrap;gap:0.5rem;">
        <span class="panel-title">🔐 Edit Locked</span>
        <button id="ss-unlock-btn" class="btn btn-secondary btn-sm" style="font-size:0.78rem;">🔐 Unlock Edit</button>
        <span id="ss-edit-status" style="font-size:0.75rem;color:var(--gray);">PIN required to edit</span>
    </div>
    <div class="panel-body" style="padding:0.65rem 1rem;font-size:0.83rem;color:var(--gray);">
        Spreadsheet editing requires a PIN. Click <strong>Unlock Edit</strong> to continue.
    </div>
</div>
<?php elseif ($locked): ?>
<div style="margin-bottom:0.75rem;">
    <span style="font-size:0.78rem;color:#856404;background:#fff3cd;padding:0.3rem 0.75rem;border-radius:20px;">🔒 Report is locked — no entries can be added or deleted.</span>
</div>
<?php endif; ?>

<?php /* hidden inputs for disc amounts — outside table */ ?>
<div style="display:none;">
    <input type="hidden" id="er-disc_20_pwd"   value="0">
    <input type="hidden" id="er-disc_50_staff"  value="0">
</div>

<div class="panel">
    <div class="panel-header" style="gap:0.75rem;flex-wrap:wrap;">
        <span class="panel-title">🧮 Spreadsheet — <?php echo date('M d, Y', strtotime($report_date)); ?></span>
        <?php if ($can_edit && $ss_editor): ?>
        <span style="font-size:0.75rem;color:#198754;">✏️ Editing as <?php echo htmlspecialchars($ss_editor); ?><?php if (is_cashier() && $ss_unlocked): ?> (expires <?php echo date('g:i A', (int)$_SESSION['ss_edit_expires']); ?>)<?php endif; ?></span>
        <?php endif; ?>
        <span id="ss-row-count" style="font-size:0.75rem;color:var(--gray);"><?php echo count($spreadsheet_rows); ?> rows &nbsp;|&nbsp; Net Sales: ₱<span id="ss-net-total"><?php echo number_format($spreadsheet_net_total,2); ?></span></span>
    </div>
    <div style="overflow-x:auto;">
        <table style="font-size:0.75rem;border-collapse:collapse;min-width:1640px;" id="ss-table">
            <thead>
                <tr style="background:#5c3a1e;color:#fff;">
                    <?php if (!$locked): ?><th style="width:36px;padding:0.4rem 0.3rem;"></th><?php endif; ?>
                    <th style="padding:0.4rem 0.5rem;text-align:center;width:70px;">Time In</th>
                    <th style="padding:0.4rem 0.5rem;text-align:center;width:70px;">Time Out</th>
                    <th style="padding:0.4rem 0.5rem;width:80px;">Slip No.</th>
                    <th style="padding:0.4rem 0.5rem;min-width:110px;">Client Name</th>
                    <th style="padding:0.4rem 0.5rem;min-width:130px;">Service Name</th>
                    <th style="padding:0.4rem 0.5rem;min-width:90px;">Stylist</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:82px;">Reg. Price</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:82px;">Promo Price</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:72px;">Celeb 10%</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:78px;">Disc 20% PWD</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:70px;">Comm 30%</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:70px;">Comm 20%</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:70px;">Comm 15%</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:78px;">Disc 50% Staff</th>
                    <th style="padding:0.4rem 0.5rem;text-align:right;width:82px;background:rgba(255,255,255,0.15);">Net Sales</th>
                    <th style="padding:0.4rem 0.5rem;width:95px;">Mode of Pay</th>
                    <th style="padding:0.4rem 0.5rem;min-width:130px;">Remarks</th>
                    <th style="padding:0.4rem 0.5rem;text-align:center;width:52px;">Refund?</th>
                </tr>
            </thead>
            <tbody id="ss-tbody">
            <?php if (empty($spreadsheet_rows)): ?>
            <tr id="ss-empty-row"><td colspan="<?php echo $locked ? 18 : 19; ?>" style="text-align:center;color:var(--gray);padding:2rem 1rem;font-size:0.85rem;">
                No rows yet.<?php echo $can_edit ? ' Use the entry row below and click <strong>➕ Add Row</strong>.' : ''; ?>
            </td></tr>
            <?php else: foreach ($spreadsheet_rows as $_ssr): ?>
            <?php $_is_imported = !empty($_ssr['source_appointment_id']); ?>
            <tr class="ss-row" data-row-id="<?php echo (int)$_ssr['id']; ?>"
                data-net-sales="<?php echo (float)($_ssr['net_sales'] ?? 0); ?>"
                data-is-refund="<?php echo (int)$_ssr['is_refund']; ?>"
                data-imported="<?php echo $_is_imported ? '1' : '0'; ?>"
                style="<?php echo $_ssr['is_refund'] ? 'background:rgba(220,53,69,0.04);' : ''; ?>border-bottom:1px solid var(--border2);">
                <?php if (!$locked): ?>
                <td style="padding:2px 4px;vertical-align:middle;">
                    <?php if (!$_is_imported || is_full_access()): ?>
                    <button class="btn btn-danger btn-sm ss-del-btn" style="font-size:0.65rem;padding:0.15rem 0.4rem;<?php echo $can_edit ? '' : 'display:none;'; ?>">✕</button>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td style="padding:0.3rem 0.5rem;white-space:nowrap;"><?php echo htmlspecialchars($_ssr['time_in'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;white-space:nowrap;"><?php echo htmlspecialchars($_ssr['time_out'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;font-family:monospace;"><?php echo htmlspecialchars($_ssr['slip_no'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;"><?php echo htmlspecialchars($_ssr['client_name'] ?? ''); ?>
                    <?php if ($_is_imported): ?><span style="font-size:0.62rem;background:rgba(13,110,253,0.1);color:#0d6efd;padding:1px 5px;border-radius:8px;margin-left:4px;white-space:nowrap;" title="Auto-imported from appointment #<?php echo (int)$_ssr['source_appointment_id']; ?>">📥 appt</span><?php endif; ?>
                </td>
                <td style="padding:0.3rem 0.5rem;"><?php echo htmlspecialchars($_ssr['service_name'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;color:var(--gray);"><?php echo htmlspecialchars($_ssr['stylist'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--gray);"><?php echo $_ssr['regular_price'] ? '₱'.number_format((float)$_ssr['regular_price'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;font-weight:700;color:var(--brown);"><?php echo $_ssr['promo_price'] ? '₱'.number_format((float)$_ssr['promo_price'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['celeb_10'] ? '₱'.number_format((float)$_ssr['celeb_10'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['disc_20_pwd'] ? '₱'.number_format((float)$_ssr['disc_20_pwd'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['comm_30'] ? '₱'.number_format((float)$_ssr['comm_30'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['comm_20'] ? '₱'.number_format((float)$_ssr['comm_20'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['comm_15'] ? '₱'.number_format((float)$_ssr['comm_15'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);"><?php echo $_ssr['disc_50_staff'] ? '₱'.number_format((float)$_ssr['disc_50_staff'],2) : ''; ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:right;font-weight:700;background:rgba(25,135,84,0.05);color:<?php echo $_ssr['is_refund'] ? '#dc3545' : '#198754'; ?>;">
                    <?php echo $_ssr['net_sales'] ? '₱'.number_format((float)$_ssr['net_sales'],2) : ''; ?>
                </td>
                <td style="padding:0.3rem 0.5rem;"><?php echo htmlspecialchars($_ssr['mode_of_payment'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;color:var(--gray);"><?php echo htmlspecialchars($_ssr['remarks'] ?? ''); ?></td>
                <td style="padding:0.3rem 0.5rem;text-align:center;"><?php echo $_ssr['is_refund'] ? '✓' : ''; ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if ($can_edit): ?>
            <tbody id="ss-entry-body">
                <tr id="ss-entry-row" style="background:#fdf8f0;border-top:2px solid var(--gold);">
                    <td style="padding:2px 4px;"></td>
                    <td style="padding:2px 3px;"><input type="text"   id="er-time_in"        class="ss-ec"               placeholder="Time In"></td>
                    <td style="padding:2px 3px;"><input type="text"   id="er-time_out"       class="ss-ec"               placeholder="Time Out"></td>
                    <td style="padding:2px 3px;"><input type="text"   id="er-slip_no"        class="ss-ec"               placeholder="Slip" style="font-family:monospace;"></td>
                    <td style="padding:2px 3px;"><input type="text"   id="er-client_name"    class="ss-ec"               placeholder="Client"></td>
                    <td style="padding:2px 3px;">
                        <select id="er-service" class="ss-ec ss-ec-sel">
                            <option value="">— Service * —</option>
                            <?php foreach ($ss_services_list as $_sv): ?>
                            <option value="<?php echo (int)$_sv['id']; ?>"
                                    data-price="<?php echo (float)$_sv['price']; ?>"
                                    data-name="<?php echo htmlspecialchars($_sv['name']); ?>">
                                <?php echo htmlspecialchars($_sv['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:2px 3px;">
                        <select id="er-stylist" class="ss-ec ss-ec-sel">
                            <option value="">— Stylist —</option>
                            <?php foreach ($ss_therapists_list as $_th): ?>
                            <option value="<?php echo (int)$_th['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($_th['full_name']); ?>">
                                <?php echo htmlspecialchars($_th['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:2px 3px;"><input type="number" id="er-regular_price"  class="ss-ec ss-ec-num"     step="0.01" min="0" placeholder="0.00"></td>
                    <td style="padding:2px 3px;"><input type="number" id="er-promo_price"    class="ss-ec ss-ec-num"     step="0.01" min="0" placeholder="0.00"></td>
                    <td style="padding:2px 3px;"><input type="number" id="er-celeb_10"       class="ss-ec ss-ec-num"     step="0.01" min="0" placeholder="0.00"></td>
                    <td style="padding:2px 3px;">
                        <select id="er-disc_20_pwd_sel" class="ss-ec ss-ec-sel">
                            <option value="none">None</option>
                            <option value="valid">Valid 20%</option>
                        </select>
                    </td>
                    <td style="padding:2px 3px;"><input type="number" id="er-comm_30"        class="ss-ec ss-ec-num ss-ec-ro" step="0.01" readonly placeholder="0.00"></td>
                    <td style="padding:2px 3px;"><input type="number" id="er-comm_20"        class="ss-ec ss-ec-num ss-ec-ro" step="0.01" readonly placeholder="0.00"></td>
                    <td style="padding:2px 3px;"><input type="number" id="er-comm_15"        class="ss-ec ss-ec-num ss-ec-ro" step="0.01" readonly placeholder="0.00"></td>
                    <td style="padding:2px 3px;">
                        <select id="er-disc_50_staff_sel" class="ss-ec ss-ec-sel">
                            <option value="none">None</option>
                            <option value="valid">Valid 50%</option>
                        </select>
                    </td>
                    <td style="padding:2px 3px;background:rgba(25,135,84,0.05);"><input type="number" id="er-net_sales" class="ss-ec ss-ec-num ss-ec-ro" step="0.01" readonly placeholder="0.00"></td>
                    <td style="padding:2px 3px;">
                        <select id="er-mop" class="ss-ec ss-ec-sel">
                            <option value="">— MOP —</option>
                            <?php foreach ($ss_mop_list as $_mop): ?>
                            <option value="<?php echo htmlspecialchars($_mop); ?>"><?php echo htmlspecialchars($_mop); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:2px 3px;"><input type="text"   id="er-remarks"        class="ss-ec"               placeholder="Remarks"></td>
                    <td style="padding:2px 3px;text-align:center;vertical-align:middle;">
                        <input type="checkbox" id="er-is_refund" style="width:14px;height:14px;cursor:pointer;">
                    </td>
                </tr>
                <tr style="background:#fdf8f0;border-bottom:2px solid var(--gold);">
                    <td colspan="19" style="padding:0.35rem 0.5rem;">
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <button id="ss-add-btn" class="btn btn-primary btn-sm" style="font-weight:700;padding:0.35rem 1.1rem;">➕ Add Row</button>
                            <span id="ss-comm-note" style="font-size:0.72rem;color:var(--gray);font-style:italic;"></span>
                            <span id="ss-form-err"  style="display:none;color:#dc3545;font-size:0.78rem;font-weight:600;"></span>
                        </div>
                    </td>
                </tr>
            </tbody>
            <?php endif; ?>
            <tfoot id="ss-tfoot">
                <tr style="background:#5c3a1e;color:#fff;font-weight:700;">
                    <?php if (!$locked): ?><td></td><?php endif; ?>
                    <td colspan="14" style="padding:0.5rem 0.75rem;font-size:0.8rem;">TOTALS</td>
                    <td id="ss-totals-net" style="padding:0.5rem 0.5rem;text-align:right;font-size:0.88rem;background:rgba(255,255,255,0.12);">
                        ₱<?php echo number_format($spreadsheet_net_total,2); ?>
                    </td>
                    <td colspan="3"></td>
                </tr>
                <?php if ($spreadsheet_refund_total > 0): ?>
                <tr id="ss-refund-row" style="background:rgba(220,53,69,0.08);border-top:1px solid var(--border2);">
                    <?php if (!$locked): ?><td></td><?php endif; ?>
                    <td colspan="14" style="padding:0.4rem 0.75rem;font-size:0.78rem;color:#dc3545;font-weight:600;">↩ Refund Rows (deducted from Net Cash)</td>
                    <td id="ss-refund-total" style="padding:0.4rem 0.5rem;text-align:right;font-size:0.82rem;font-weight:700;color:#dc3545;">
                        ₱<?php echo number_format($spreadsheet_refund_total,2); ?>
                    </td>
                    <td colspan="3"></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </div>
</div>

<style>
.ss-ec {
    width:100%; padding:0.22rem 0.3rem; border:1px solid transparent; border-radius:3px;
    background:transparent; color:var(--brown); font-size:0.74rem; box-sizing:border-box;
    transition:border-color 0.1s, background 0.1s;
}
.ss-ec:focus { border-color:var(--gold); outline:none; background:#fff; box-shadow:0 0 0 2px rgba(200,164,107,0.22); }
.ss-ec-num { text-align:right; font-family:monospace; }
.ss-ec-sel { cursor:pointer; }
.ss-ec-ro { opacity:0.65; cursor:not-allowed; background:rgba(0,0,0,0.03); }
.ss-ec-ro:focus { border-color:transparent; box-shadow:none; background:rgba(0,0,0,0.03); }
#ss-table thead th { color:#fff; font-weight:700; }
</style>
<script>
(function(){
    var CSRF       = <?php echo json_encode(generate_csrf_token()); ?>;
    var RDATE      = <?php echo json_encode($report_date); ?>;
    var IS_CASHIER = <?php echo is_cashier() ? 'true' : 'false'; ?>;
    var _rptLocked = <?php echo json_encode((bool)$locked); ?>;
    var _ssUnlocked= <?php echo json_encode($ss_unlocked); ?>;
    var _ssExpires = <?php echo json_encode((int)($_SESSION['ss_edit_expires'] ?? 0)); ?>;
    var SS_SERVICES    = <?php echo json_encode($ss_services_list,   JSON_HEX_TAG|JSON_HEX_APOS); ?>;
    var SS_THERAPISTS  = <?php echo json_encode($ss_therapists_list, JSON_HEX_TAG|JSON_HEX_APOS); ?>;
    var SS_MOP         = <?php echo json_encode($ss_mop_list,        JSON_HEX_TAG|JSON_HEX_APOS); ?>;
    var SS_COMMISSIONS = <?php echo json_encode($ss_commissions,     JSON_HEX_TAG|JSON_HEX_APOS); ?>;

    function canEdit() {
        if (_rptLocked) return false;
        if (!IS_CASHIER) return true;
        return _ssUnlocked && (Math.floor(Date.now()/1000) < _ssExpires);
    }

    // ── Entry row element refs ───────────────────────────────────────────────
    var sfSvc    = document.getElementById('er-service');
    var sfStylist= document.getElementById('er-stylist');
    var sfRp     = document.getElementById('er-regular_price');
    var sfPromo  = document.getElementById('er-promo_price');
    var sfPwdSel = document.getElementById('er-disc_20_pwd_sel');
    var sfStfSel = document.getElementById('er-disc_50_staff_sel');
    var sfPwdHid = document.getElementById('er-disc_20_pwd');
    var sfStfHid = document.getElementById('er-disc_50_staff');
    var sfC30    = document.getElementById('er-comm_30');
    var sfC20    = document.getElementById('er-comm_20');
    var sfC15    = document.getElementById('er-comm_15');
    var sfNet    = document.getElementById('er-net_sales');
    var commNote = document.getElementById('ss-comm-note');
    var _promoUserEdited = false;

    // ── Auto-compute ─────────────────────────────────────────────────────────
    function recompute() {
        if (!sfRp) return;
        var rp     = parseFloat(sfRp.value) || 0;
        var pwdAmt = (sfPwdSel && sfPwdSel.value === 'valid') ? rp * 0.20 : 0;
        var stfAmt = (sfStfSel && sfStfSel.value === 'valid') ? rp * 0.50 : 0;
        if (sfPwdHid) sfPwdHid.value = pwdAmt.toFixed(2);
        if (sfStfHid) sfStfHid.value = stfAmt.toFixed(2);

        // Promo Price = Regular − pwd_amount − staff_amount (or = Regular if no discounts)
        var hasDist = pwdAmt > 0 || stfAmt > 0;
        if (sfPromo) {
            if (hasDist) {
                sfPromo.value    = (rp - pwdAmt - stfAmt).toFixed(2);
                sfPromo.readOnly = true;
                sfPromo.classList.add('ss-ec-ro');
            } else {
                sfPromo.readOnly = false;
                sfPromo.classList.remove('ss-ec-ro');
                if (!_promoUserEdited) sfPromo.value = rp > 0 ? rp.toFixed(2) : '';
            }
        }
        var promo = sfPromo ? (parseFloat(sfPromo.value) || 0) : 0;

        // Commission = Promo × rate% for this stylist+service
        var svcId  = sfSvc     ? (parseInt(sfSvc.value)    || 0) : 0;
        var stylId = sfStylist ? (parseInt(sfStylist.value) || 0) : 0;
        var c30 = 0, c20 = 0, c15 = 0, noteText = '';
        if (svcId && stylId) {
            var key = stylId + '_' + svcId;
            if (Object.prototype.hasOwnProperty.call(SS_COMMISSIONS, key)) {
                var pct = SS_COMMISSIONS[key];
                var amt = promo * pct / 100;
                if      (pct === 30) { c30 = amt; }
                else if (pct === 20) { c20 = amt; }
                else if (pct === 15) { c15 = amt; }
                else                 { c30 = amt; noteText = 'rate ' + pct + '%'; }
            } else { noteText = 'no commission set'; }
        }
        if (sfC30) sfC30.value = c30.toFixed(2);
        if (sfC20) sfC20.value = c20.toFixed(2);
        if (sfC15) sfC15.value = c15.toFixed(2);
        if (commNote) commNote.textContent = noteText;

        // Net Sales = Promo − commission
        var net = promo - (c30 + c20 + c15);
        if (sfNet) sfNet.value = net.toFixed(2);
    }

    // ── Event wiring ─────────────────────────────────────────────────────────
    if (sfSvc) sfSvc.addEventListener('change', function() {
        _promoUserEdited = false;
        if (sfRp) {
            var opt = sfSvc.options[sfSvc.selectedIndex];
            var price = opt ? (parseFloat(opt.dataset.price) || 0) : 0;
            if (price > 0) sfRp.value = price.toFixed(2);
        }
        recompute();
    });
    if (sfStylist) sfStylist.addEventListener('change', recompute);
    if (sfRp) sfRp.addEventListener('input', function() { _promoUserEdited = false; recompute(); });
    if (sfPromo) sfPromo.addEventListener('input', function() { _promoUserEdited = true; recompute(); });
    if (sfPwdSel) sfPwdSel.addEventListener('change', function() { _promoUserEdited = false; recompute(); });
    if (sfStfSel) sfStfSel.addEventListener('change', function() { _promoUserEdited = false; recompute(); });

    // ── Helpers ──────────────────────────────────────────────────────────────
    function fmtNum(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function gv(id) { var el=document.getElementById(id); return el ? el.value.trim() : ''; }
    function eH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function peso(v) { var n=parseFloat(v)||0; return n ? '₱'+fmtNum(n) : ''; }

    // ── Recalculate totals row + header count ────────────────────────────────
    function recalcTotals() {
        var rows = document.querySelectorAll('#ss-tbody tr.ss-row');
        var net = 0, refund = 0;
        rows.forEach(function(tr) {
            var nv = parseFloat(tr.dataset.netSales || '0') || 0;
            net += nv;
            if (tr.dataset.isRefund === '1') refund += nv;
        });
        var ntEl = document.getElementById('ss-totals-net');
        if (ntEl) ntEl.textContent = '₱' + fmtNum(net);
        var ntH = document.getElementById('ss-net-total');
        if (ntH) ntH.textContent = fmtNum(net);
        var cntEl = document.getElementById('ss-row-count');
        if (cntEl) cntEl.innerHTML = rows.length + ' rows &nbsp;|&nbsp; Net Sales: ₱<span id="ss-net-total">' + fmtNum(net) + '</span>';
        var refRow = document.getElementById('ss-refund-row');
        if (refund > 0) {
            if (!refRow) {
                refRow = document.createElement('tr');
                refRow.id = 'ss-refund-row';
                refRow.style.cssText = 'background:rgba(220,53,69,0.08);border-top:1px solid var(--border2);';
                var blank = _rptLocked ? '' : '<td></td>';
                refRow.innerHTML = blank +
                    '<td colspan="14" style="padding:0.4rem 0.75rem;font-size:0.78rem;color:#dc3545;font-weight:600;">↩ Refund Rows (deducted from Net Cash)</td>' +
                    '<td id="ss-refund-total" style="padding:0.4rem 0.5rem;text-align:right;font-size:0.82rem;font-weight:700;color:#dc3545;"></td>' +
                    '<td colspan="3"></td>';
                document.getElementById('ss-tfoot').appendChild(refRow);
            }
            refRow.style.display = '';
            var rtEl = document.getElementById('ss-refund-total');
            if (rtEl) rtEl.textContent = '₱' + fmtNum(refund);
        } else if (refRow) {
            refRow.style.display = 'none';
        }
    }

    // ── Build read-only preview <tr> after successful save ───────────────────
    function buildPreviewRow(d, rowId) {
        var isRef = d.is_refund === '1' || d.is_refund === 1;
        var tr = document.createElement('tr');
        tr.className = 'ss-row';
        tr.dataset.rowId    = rowId;
        tr.dataset.netSales = parseFloat(d.net_sales) || 0;
        tr.dataset.isRefund = isRef ? '1' : '0';
        tr.style.cssText    = (isRef ? 'background:rgba(220,53,69,0.04);' : '') + 'border-bottom:1px solid var(--border2);';
        var delCell = _rptLocked ? '' :
            '<td style="padding:2px 4px;vertical-align:middle;">' +
            '<button class="btn btn-danger btn-sm ss-del-btn" style="font-size:0.65rem;padding:0.15rem 0.4rem;">✕</button></td>';
        tr.innerHTML = delCell +
            '<td style="padding:0.3rem 0.5rem;white-space:nowrap;">'  + eH(d.time_in)         + '</td>' +
            '<td style="padding:0.3rem 0.5rem;white-space:nowrap;">'  + eH(d.time_out)        + '</td>' +
            '<td style="padding:0.3rem 0.5rem;font-family:monospace;">'+ eH(d.slip_no)         + '</td>' +
            '<td style="padding:0.3rem 0.5rem;">'                      + eH(d.client_name)     + '</td>' +
            '<td style="padding:0.3rem 0.5rem;">'                      + eH(d.service_name)    + '</td>' +
            '<td style="padding:0.3rem 0.5rem;color:var(--gray);">'   + eH(d.stylist)         + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--gray);">'           + peso(d.regular_price)  + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;font-weight:700;color:var(--brown);">' + peso(d.promo_price) + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.celeb_10)       + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.disc_20_pwd)    + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.comm_30)        + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.comm_20)        + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.comm_15)        + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;color:var(--rust);">'           + peso(d.disc_50_staff)  + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:right;font-weight:700;background:rgba(25,135,84,0.05);color:' +
                (isRef ? '#dc3545' : '#198754') + ';">'                                         + peso(d.net_sales)      + '</td>' +
            '<td style="padding:0.3rem 0.5rem;">'                      + eH(d.mode_of_payment) + '</td>' +
            '<td style="padding:0.3rem 0.5rem;color:var(--gray);">'   + eH(d.remarks)         + '</td>' +
            '<td style="padding:0.3rem 0.5rem;text-align:center;">'   + (isRef ? '✓' : '')   + '</td>';
        if (!_rptLocked) attachDel(tr);
        return tr;
    }

    // ── Delete handler ───────────────────────────────────────────────────────
    function attachDel(tr) {
        var btn = tr.querySelector('.ss-del-btn');
        if (!btn) return;
        if (!canEdit()) { btn.style.display = 'none'; return; }
        // Imported rows: hide delete button for cashier/receptionist users
        if (tr.dataset.imported === '1' && IS_CASHIER) { btn.style.display = 'none'; return; }
        btn.addEventListener('click', function() {
            var rid = tr.dataset.rowId;
            uiConfirm('Delete this row?').then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action',     'delete_ss_row');
                fd.append('csrf_token', CSRF);
                fd.append('row_id',     rid);
                fetch('daily_report.php?date='+RDATE, {method:'POST',body:fd})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        if (j.ok) { tr.remove(); recalcTotals(); }
                        else if (window.uiAlert) uiAlert('❌ '+(j.msg||'Delete failed'));
                    });
            });
        });
    }
    document.querySelectorAll('#ss-tbody tr.ss-row').forEach(attachDel);

    // ── "Add Row" ────────────────────────────────────────────────────────────
    var addBtn = document.getElementById('ss-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            if (!canEdit()) return;
            var errEl = document.getElementById('ss-form-err');
            errEl.style.display = 'none';

            if (!sfSvc || !sfSvc.value) {
                errEl.textContent   = '⚠️ Please select a Service.';
                errEl.style.display = 'block';
                if (sfSvc) sfSvc.focus();
                return;
            }

            var svcOpt  = sfSvc.options[sfSvc.selectedIndex];
            var stylOpt = sfStylist && sfStylist.value ? sfStylist.options[sfStylist.selectedIndex] : null;
            var mopSel  = document.getElementById('er-mop');
            var rowCount= document.querySelectorAll('#ss-tbody tr.ss-row').length;

            var fd = new FormData();
            fd.append('action',          'save_ss_row');
            fd.append('csrf_token',      CSRF);
            fd.append('row_id',          '0');
            fd.append('row_order',       String(rowCount + 1));
            fd.append('time_in',         gv('er-time_in'));
            fd.append('time_out',        gv('er-time_out'));
            fd.append('slip_no',         gv('er-slip_no'));
            fd.append('client_name',     gv('er-client_name'));
            fd.append('service_name',    svcOpt.dataset.name || svcOpt.text);
            fd.append('service_id',      sfSvc.value);
            fd.append('stylist',         stylOpt ? (stylOpt.dataset.name || stylOpt.text) : '');
            fd.append('therapist_id',    sfStylist ? (sfStylist.value || '0') : '0');
            fd.append('regular_price',   gv('er-regular_price')  || '0');
            fd.append('promo_price',     gv('er-promo_price')    || '0');
            fd.append('celeb_10',        gv('er-celeb_10')       || '0');
            fd.append('disc_20_pwd',     gv('er-disc_20_pwd')    || '0');
            fd.append('comm_30',         gv('er-comm_30')        || '0');
            fd.append('comm_20',         gv('er-comm_20')        || '0');
            fd.append('comm_15',         gv('er-comm_15')        || '0');
            fd.append('disc_50_staff',   gv('er-disc_50_staff')  || '0');
            fd.append('net_sales',       gv('er-net_sales')      || '0');
            fd.append('mode_of_payment', mopSel ? mopSel.value : '');
            fd.append('remarks',         gv('er-remarks'));
            fd.append('is_refund',       document.getElementById('er-is_refund').checked ? '1' : '0');

            addBtn.disabled    = true;
            addBtn.textContent = 'Saving…';

            fetch('daily_report.php?date='+RDATE, {method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(j){
                    addBtn.disabled    = false;
                    addBtn.textContent = '➕ Add Row';
                    if (!j.ok) {
                        errEl.textContent   = '❌ '+(j.msg||'Save failed.');
                        errEl.style.display = 'block';
                        return;
                    }
                    var d = {
                        time_in:         gv('er-time_in'),
                        time_out:        gv('er-time_out'),
                        slip_no:         gv('er-slip_no'),
                        client_name:     gv('er-client_name'),
                        service_name:    svcOpt.dataset.name || svcOpt.text,
                        stylist:         stylOpt ? (stylOpt.dataset.name || stylOpt.text) : '',
                        regular_price:   gv('er-regular_price'),
                        promo_price:     gv('er-promo_price'),
                        celeb_10:        gv('er-celeb_10'),
                        disc_20_pwd:     gv('er-disc_20_pwd'),
                        comm_30:         gv('er-comm_30'),
                        comm_20:         gv('er-comm_20'),
                        comm_15:         gv('er-comm_15'),
                        disc_50_staff:   gv('er-disc_50_staff'),
                        net_sales:       gv('er-net_sales'),
                        mode_of_payment: mopSel ? mopSel.value : '',
                        remarks:         gv('er-remarks'),
                        is_refund:       document.getElementById('er-is_refund').checked ? '1' : '0'
                    };
                    var emptyRow = document.getElementById('ss-empty-row');
                    if (emptyRow) emptyRow.remove();
                    document.getElementById('ss-tbody').appendChild(buildPreviewRow(d, j.row_id));
                    recalcTotals();
                    // Clear entry row for next entry
                    ['er-time_in','er-time_out','er-slip_no','er-client_name',
                     'er-regular_price','er-celeb_10','er-remarks'].forEach(function(id){
                        var el=document.getElementById(id); if(el) el.value='';
                    });
                    if (sfSvc)    sfSvc.selectedIndex    = 0;
                    if (sfStylist)sfStylist.selectedIndex = 0;
                    if (mopSel)   mopSel.selectedIndex    = 0;
                    if (sfPwdSel) sfPwdSel.selectedIndex  = 0;
                    if (sfStfSel) sfStfSel.selectedIndex  = 0;
                    if (sfPwdHid) sfPwdHid.value = '0';
                    if (sfStfHid) sfStfHid.value = '0';
                    if (sfPromo)  { sfPromo.value=''; sfPromo.readOnly=false; sfPromo.classList.remove('ss-ec-ro'); }
                    if (sfC30) sfC30.value=''; if (sfC20) sfC20.value=''; if (sfC15) sfC15.value='';
                    if (sfNet)    sfNet.value='';
                    if (commNote) commNote.textContent='';
                    _promoUserEdited = false;
                    var refEl = document.getElementById('er-is_refund');
                    if (refEl) refEl.checked = false;
                    if (sfSvc) sfSvc.focus();
                })
                .catch(function(e){
                    addBtn.disabled    = false;
                    addBtn.textContent = '➕ Add Row';
                    errEl.textContent   = '❌ Network error.';
                    errEl.style.display = 'block';
                    console.error('SS add', e);
                });
        });
    }

    // ── PIN unlock (cashier path) ────────────────────────────────────────────
    var unlockBtn = document.getElementById('ss-unlock-btn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', function(){
            window._pgmCallback = function(pin){
                var fd=new FormData();
                fd.append('action',     'unlock_ss_edit');
                fd.append('csrf_token', CSRF);
                fd.append('pin',        pin);
                fetch('daily_report.php?date='+RDATE,{method:'POST',body:fd})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        if (j.ok) {
                            window.location.href='daily_report.php?date='+RDATE+'&tab=spreadsheet';
                        } else {
                            if(window.uiAlert) uiAlert('❌ '+(j.msg||'Invalid PIN'));
                        }
                    });
            };
            document.getElementById('pgm-label').textContent='Unlock Spreadsheet Editing';
            document.getElementById('pgm-pin').value='';
            document.getElementById('pgm-error').textContent='';
            document.getElementById('pinGateModal').style.display='flex';
            setTimeout(function(){document.getElementById('pgm-pin').focus();},80);
        });
    }
})();
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: ANALYSIS
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'analysis'):
// WoW helper closures
$_wpct = fn($cur,$last) => ($last != 0) ? round(($cur - $last) / abs($last) * 100, 1) : null;
$_warr = function($pct) {
    if ($pct === null) return '<span style="color:var(--gray);font-size:0.72rem;">— no data</span>';
    $color = $pct >= 0 ? '#198754' : '#dc3545';
    $arrow = $pct >= 0 ? '▲' : '▼';
    return "<span style=\"color:{$color};font-weight:700;font-size:0.78rem;\">{$arrow} " . abs($pct) . "%</span>";
};
?>

<!-- KPI Snapshot ─────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
<?php
$_kpis = [
    ['Gross Sales',        $gross_sales,      $wow['gross_sales'],       true,  '#198754'],
    ['Net Sales',          $net_sales,        $wow['net_sales'],         true,  'var(--brown)'],
    ['Transactions',       $transaction_count,$wow['transaction_count'], false, 'var(--brown)'],
    ['Avg. Check',         $avg_check,        $wow['avg_check'],         true,  'var(--brown)'],
    ['Guests Served',      $guests_served,    $wow['guests_served'],     false, 'var(--brown)'],
    ['Cash Over / Short',  $short_over,       null,                      true,  $short_over >= 0 ? '#198754' : '#dc3545'],
];
foreach ($_kpis as [$_kl, $_kv, $_kw, $_is_money, $_kc]):
    $_pct  = $_kw !== null ? $_wpct($_kv, $_kw) : null;
?>
<div style="background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:1.1rem 1.25rem;">
    <div style="font-size:0.68rem;font-weight:700;color:var(--gray);letter-spacing:0.06em;text-transform:uppercase;margin-bottom:0.3rem;"><?php echo $_kl; ?></div>
    <div style="font-size:1.55rem;font-weight:800;color:<?php echo $_kc; ?>;font-family:monospace;">
        <?php if ($_is_money): ?>
            <?php echo $_kv < 0 ? '(' : ''; ?>₱<?php echo number_format(abs($_kv), 2); ?><?php echo $_kv < 0 ? ')' : ''; ?>
        <?php else: ?>
            <?php echo number_format($_kv); ?>
        <?php endif; ?>
    </div>
    <div style="margin-top:0.3rem;display:flex;align-items:center;gap:0.4rem;">
        <?php echo $_warr($_pct); ?>
        <?php if ($_kw !== null): ?>
        <span style="color:var(--gray);font-size:0.68rem;">vs <?php echo date('M d', strtotime($wow_date)); ?><?php echo $_is_money ? ' ₱'.number_format($_kw,0) : ' '.number_format($_kw); ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

<!-- Sales Mix ─────────────────────────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">🥧 Sales Mix</span></div>
    <div class="panel-body" style="padding:1rem;">
    <?php
    $_total_gross = max(1, $gross_sales);
    $_mix = [
        ['Services',  $gross_sales - $addon_gross, 'var(--brown)'],
        ['Add-ons',   $addon_gross,                'var(--gold)'],
        ['Products',  $prod_sold_total,             '#198754'],
    ];
    foreach ($_mix as [$_ml, $_mv, $_mc]):
        $_pct_bar = min(100, round($_mv / $_total_gross * 100, 1));
    ?>
    <div style="margin-bottom:0.85rem;">
        <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:3px;">
            <span style="font-weight:600;color:var(--brown);"><?php echo $_ml; ?></span>
            <span style="color:<?php echo $_mc; ?>;font-weight:700;font-family:monospace;">₱<?php echo number_format($_mv,2); ?> <span style="color:var(--gray);font-weight:400;">(<?php echo $_pct_bar; ?>%)</span></span>
        </div>
        <div style="background:var(--border2);border-radius:6px;height:10px;overflow:hidden;">
            <div style="background:<?php echo $_mc; ?>;width:<?php echo $_pct_bar; ?>%;height:100%;border-radius:6px;transition:width 0.4s;"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Payment Mix ───────────────────────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">💳 Payment Mix</span></div>
    <div class="panel-body" style="padding:1rem;">
    <?php
    $_pm_display = [
        ['💵 Cash',        ($pm_totals['cash']  ?? 0), '#198754'],
        ['📱 GCash',       $gcash_total,               '#0070f3'],
        ['💜 Maya',        $maya_total,                '#9333ea'],
        ['📷 QR PH',       $qrph_total,                '#0891b2'],
        ['💳 Card / Bank', $card_total,                '#f59e0b'],
        ['🌐 Online',      $online_total,              '#6b7280'],
    ];
    $_pm_total = max(1, array_sum(array_column($_pm_display, 1)));
    foreach ($_pm_display as [$_pml, $_pmv, $_pmc]):
        if ($_pmv <= 0) continue;
        $_pct_bar = min(100, round($_pmv / $_pm_total * 100, 1));
    ?>
    <div style="margin-bottom:0.85rem;">
        <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:3px;">
            <span style="font-weight:600;color:var(--brown);"><?php echo $_pml; ?></span>
            <span style="color:<?php echo $_pmc; ?>;font-weight:700;font-family:monospace;">₱<?php echo number_format($_pmv,2); ?> <span style="color:var(--gray);font-weight:400;">(<?php echo $_pct_bar; ?>%)</span></span>
        </div>
        <div style="background:var(--border2);border-radius:6px;height:10px;overflow:hidden;">
            <div style="background:<?php echo $_pmc; ?>;width:<?php echo $_pct_bar; ?>%;height:100%;border-radius:6px;"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

<!-- Daypart Performance ───────────────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">⏰ Daypart Performance</span></div>
    <div class="panel-body" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead><tr style="background:var(--bg3);">
                <th style="padding:7px 10px;text-align:left;font-size:0.78rem;color:var(--gray);">Period</th>
                <th style="padding:7px 10px;text-align:left;font-size:0.78rem;color:var(--gray);">Time</th>
                <th style="padding:7px 10px;text-align:center;font-size:0.78rem;color:var(--gray);">Txns</th>
                <th style="padding:7px 10px;text-align:right;font-size:0.78rem;color:var(--gray);">Revenue</th>
            </tr></thead>
            <tbody>
            <?php $_dp_icons = ['Morning'=>'🌅','Midday'=>'☀️','Afternoon'=>'🌤️','Evening'=>'🌙']; ?>
            <?php foreach ($daypart_data as $_dpk => $_dpv): ?>
            <tr style="border-bottom:1px solid var(--border2);">
                <td style="padding:7px 10px;font-weight:600;"><?php echo $_dp_icons[$_dpk] ?? ''; ?> <?php echo $_dpk; ?></td>
                <td style="padding:7px 10px;font-size:0.75rem;color:var(--gray);"><?php echo $_dpv['label']; ?></td>
                <td style="padding:7px 10px;text-align:center;"><?php echo $_dpv['txn_count']; ?></td>
                <td style="padding:7px 10px;text-align:right;font-weight:700;color:var(--brown);font-family:monospace;">₱<?php echo number_format($_dpv['revenue'],2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top Services ──────────────────────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">🏆 Top Services</span></div>
    <div class="panel-body" style="padding:0;">
        <?php if (empty($top_services)): ?>
        <p style="padding:1rem;color:var(--gray);font-size:0.85rem;">No service data.</p>
        <?php else:
        $_top_max = max(1, floatval($top_services[0]['revenue']));
        foreach ($top_services as $_ti => $_ts_row):
            $_ts_pct = min(100, round(floatval($_ts_row['revenue']) / $_top_max * 100));
        ?>
        <div style="padding:0.65rem 1rem;border-bottom:1px solid var(--border2);">
            <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:3px;">
                <span style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($_ts_row['service_name']); ?></span>
                <span style="font-family:monospace;font-weight:700;color:#198754;">₱<?php echo number_format($_ts_row['revenue'],2); ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <div style="flex:1;background:var(--border2);border-radius:4px;height:6px;">
                    <div style="background:var(--brown);width:<?php echo $_ts_pct; ?>%;height:100%;border-radius:4px;"></div>
                </div>
                <span style="font-size:0.72rem;color:var(--gray);"><?php echo $_ts_row['txn_count']; ?> txns</span>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

</div>

<!-- Therapist Productivity ─────────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem;">
    <div class="panel-header"><span class="panel-title">💆 Therapist Productivity</span></div>
    <div class="panel-body" style="padding:0;">
        <?php if (empty($therapist_stats)): ?>
        <p style="padding:1rem;color:var(--gray);font-size:0.85rem;">No therapist data.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;min-width:500px;">
            <thead><tr style="background:var(--bg3);">
                <th style="padding:7px 10px;text-align:left;">Therapist</th>
                <th style="padding:7px 10px;text-align:center;">Services</th>
                <th style="padding:7px 10px;text-align:right;">Revenue</th>
                <th style="padding:7px 10px;text-align:right;">Commission</th>
                <th style="padding:7px 10px;text-align:right;">Net to Salon</th>
            </tr></thead>
            <tbody>
            <?php foreach ($therapist_stats as $_thr): ?>
            <tr style="border-bottom:1px solid var(--border2);">
                <td style="padding:7px 10px;font-weight:600;"><?php echo htmlspecialchars($_thr['full_name']); ?></td>
                <td style="padding:7px 10px;text-align:center;"><?php echo intval($_thr['svc_count']); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:700;color:var(--brown);">₱<?php echo number_format($_thr['revenue'],2); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;color:var(--rust);">₱<?php echo number_format($_thr['commission'],2); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:700;color:#198754;">₱<?php echo number_format($_thr['revenue'] - $_thr['commission'],2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:var(--bg3);font-weight:700;border-top:2px solid var(--border2);">
                <td style="padding:7px 10px;">Totals</td>
                <td style="padding:7px 10px;text-align:center;"><?php echo array_sum(array_column($therapist_stats,'svc_count')); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;color:var(--brown);">₱<?php echo number_format(array_sum(array_column($therapist_stats,'revenue')),2); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;color:var(--rust);">₱<?php echo number_format(array_sum(array_column($therapist_stats,'commission')),2); ?></td>
                <td style="padding:7px 10px;text-align:right;font-family:monospace;color:#198754;">₱<?php echo number_format(array_sum(array_column($therapist_stats,'revenue')) - array_sum(array_column($therapist_stats,'commission')),2); ?></td>
            </tr></tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Discount Impact ───────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
<?php
$_disc_kpis = [
    ['Total Discounts Given', '₱'.number_format($total_discounts,2), 'var(--rust)'],
    ['% of Gross Sales',      $discount_pct_of_gross.'%',             $discount_pct_of_gross > 10 ? '#dc3545' : 'var(--brown)'],
    ['Influencer Comp Cost',  '₱'.number_format($influencer_comp_total,2), 'var(--rust)'],
];
foreach ($_disc_kpis as [$_dl, $_dv, $_dc]):
?>
<div style="background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:1rem 1.25rem;text-align:center;">
    <div style="font-size:0.68rem;font-weight:700;color:var(--gray);letter-spacing:0.06em;text-transform:uppercase;margin-bottom:0.4rem;"><?php echo $_dl; ?></div>
    <div style="font-size:1.4rem;font-weight:800;color:<?php echo $_dc; ?>;font-family:monospace;"><?php echo $_dv; ?></div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<script>
(function () {
    // Server-computed cash received today; JS never recomputes earnings — reads only.
    var cashReceivedToday = <?php echo json_encode((float)$cash_received_today); ?>;
    var expensesTotal  = <?php echo json_encode((float)$expenses_total); ?>;
    var expectedDrawer = cashReceivedToday - expensesTotal;

    function fmtMoney(n) {
        return n.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    window.updateDenomTotal = function (input, denom) {
        // 1. Per-row total
        var qty     = Math.max(0, parseInt(input.value) || 0);
        var rowCell = document.getElementById('denom-total-' + denom);
        if (rowCell) rowCell.textContent = '₱' + fmtMoney(denom * qty);

        // 2. Grand total — sum from inputs directly to avoid floating-point drift
        var grandTotal = 0;
        document.querySelectorAll('input[name^="denom_"]').forEach(function (inp) {
            var d = parseFloat(inp.name.replace('denom_', '')) || 0;
            var q = Math.max(0, parseInt(inp.value) || 0);
            grandTotal += d * q;
        });

        var grandEl = document.getElementById('denom-grand-total');
        if (grandEl) grandEl.textContent = '₱' + fmtMoney(grandTotal);

        // 3. Keep read-only COH header input in sync so save_header submits correct value
        var cohInput = document.getElementById('coh-input');
        if (cohInput) cohInput.value = grandTotal.toFixed(2);

        // 4. COH row in the Summary panel
        var cohVal = document.getElementById('live-coh-val');
        if (cohVal) cohVal.textContent = '₱' + fmtMoney(grandTotal);

        // 5. SHORT / OVER — value, sign, and color
        var shortOver  = grandTotal - expectedDrawer;
        var isOver     = shortOver >= 0;
        var soRow      = document.getElementById('live-short-over');
        var soVal      = document.getElementById('live-short-over-val');
        if (soRow) soRow.style.background = isOver ? 'rgba(25,135,84,0.1)' : 'rgba(220,53,69,0.1)';
        if (soVal) {
            soVal.style.color  = isOver ? '#198754' : '#dc3545';
            soVal.textContent  = shortOver < 0
                ? '(₱' + fmtMoney(Math.abs(shortOver)) + ')'
                : '₱' + fmtMoney(shortOver);
        }
    };
}());
</script>

<?php require_once 'admin_footer.php'; ?>
