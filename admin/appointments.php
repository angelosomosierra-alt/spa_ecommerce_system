<?php
require_once '../config.php';
redirect_if_not_admin();
require_once __DIR__ . '/../notify.php';

// ── Ensure PayMongo columns exist on appointment_extra_services ───────────────
foreach ([
    "paymongo_reference VARCHAR(100) NULL DEFAULT NULL",
    "paymongo_method    VARCHAR(20)  NULL DEFAULT NULL",
] as $_aes_col) {
    $_col_name = explode(' ', trim($_aes_col))[0];
    $_chk = $conn->query("SHOW COLUMNS FROM appointment_extra_services LIKE '$_col_name'");
    if ($_chk && $_chk->num_rows === 0) {
        $conn->query("ALTER TABLE appointment_extra_services ADD COLUMN $_aes_col");
    }
}
unset($_aes_col, $_col_name, $_chk);

// ── Ensure action tracking columns exist ──────────────────────────────────────
foreach ([
    "completed_by      INT NULL DEFAULT NULL",
    "completed_by_name VARCHAR(120) NULL DEFAULT NULL",
    "declined_by       INT NULL DEFAULT NULL",
    "declined_by_name  VARCHAR(120) NULL DEFAULT NULL",
    "cancelled_by      INT NULL DEFAULT NULL",
    "cancelled_by_name VARCHAR(120) NULL DEFAULT NULL",
    "rescheduled_by    INT NULL DEFAULT NULL",
    "rescheduled_by_name VARCHAR(120) NULL DEFAULT NULL",
    "rescheduled_at    DATETIME NULL DEFAULT NULL",
] as $col_def) {
    $col_name = explode(' ', trim($col_def))[0];
    $chk = $conn->query("SHOW COLUMNS FROM appointments LIKE '$col_name'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE appointments ADD COLUMN $col_def");
    }
}

// ── AJAX: VERIFY PIN (for approve modal) ─────────────────────────────────────
if (isset($_POST['verify_pin_only'])) {
    header('Content-Type: application/json');
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['admin_role'] ?? '';
    if ($role !== 'cashier') {
        echo json_encode(['ok' => true, 'name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin']);
        exit();
    }
    $entered = trim($_POST['pin'] ?? '');
    if (!ctype_digit($entered) || strlen($entered) !== 4) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PIN format.']); exit();
    }
    // FIXED: Bug 1 — query receptionist_pins by entered PIN, not users.cashier_pin
    $stmt = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
    $stmt->bind_param("s", $entered); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect PIN. Try again.']); exit();
    }
    echo json_encode(['ok' => true, 'name' => $row['full_name']]);
    exit();
}

// ── APPROVAL EMAIL FUNCTION ───────────────────────────────────────────────────
function send_approval_email($conn, $appt_id) {
    $stmt = $conn->prepare("
        SELECT a.*, s.name AS service_name, s.session_time,
               u.full_name, u.email,
               t.full_name AS therapist_name
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        JOIN users u    ON u.id = a.user_id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists t ON t.id = at2.therapist_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $appt_id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt || empty($appt['email'])) return false;

    try {
        require_once __DIR__ . '/../_mailer.php';
        $mail = make_mailer();
        $mail->addAddress($appt['email'], $appt['full_name']);
        $mail->isHTML(true);

        $appt_date     = date('F d, Y', strtotime($appt['appointment_date']));
        $appt_time     = date('h:i A',  strtotime($appt['appointment_date']));
        $therapist_txt = $appt['therapist_name'] ? htmlspecialchars($appt['therapist_name']) : 'To be assigned';
        $svc_type      = $appt['service_type'] === 'home' ? '🏠 Home Service' : '🏢 On-site';

        $mail->Subject = '✅ Your Appointment is Confirmed — Recovery Iloilo';
        $mail->Body    = "
        <div style='font-family:Georgia,serif;max-width:580px;margin:0 auto;background:#fff;'>
            <div style='background:linear-gradient(135deg,#3B2A1A,#C96A2C);padding:2rem;text-align:center;'>
                <h1 style='color:#FAF3E8;margin:0;font-size:1.6rem;letter-spacing:0.05em;'>RECOVERY ILOILO</h1>
                <p style='color:#EAD8C0;margin:0.4rem 0 0;font-size:0.9rem;letter-spacing:0.1em;'>MASSAGE · THERAPY · PAMPER</p>
            </div>
            <div style='padding:2rem;'>
                <h2 style='color:#C96A2C;margin:0 0 0.5rem;font-size:1.3rem;'>✅ Appointment Confirmed!</h2>
                <p style='color:#3B2A1A;margin:0 0 1.5rem;'>
                    Hi <strong>" . htmlspecialchars($appt['full_name']) . "</strong>,<br>
                    Your appointment has been approved. You may now proceed to the spa.
                </p>
                <div style='background:#FAF3E8;border-radius:12px;padding:1.25rem;border:1px solid #EAD8C0;margin-bottom:1.5rem;'>
                    <table style='width:100%;border-collapse:collapse;'>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;width:40%;'>📋 Service</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;'>" . htmlspecialchars($appt['service_name']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;border-top:1px solid #EAD8C0;'>📅 Date</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;border-top:1px solid #EAD8C0;'>{$appt_date}</td>
                        </tr>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;border-top:1px solid #EAD8C0;'>🕐 Time</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;border-top:1px solid #EAD8C0;'>{$appt_time}</td>
                        </tr>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;border-top:1px solid #EAD8C0;'>⏱ Duration</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;border-top:1px solid #EAD8C0;'>" . $appt['session_time'] . " minutes</td>
                        </tr>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;border-top:1px solid #EAD8C0;'>💆 Therapist</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;border-top:1px solid #EAD8C0;'>{$therapist_txt}</td>
                        </tr>
                        <tr>
                            <td style='padding:0.5rem 0;color:#888;font-size:0.85rem;border-top:1px solid #EAD8C0;'>🏷️ Type</td>
                            <td style='padding:0.5rem 0;color:#3B2A1A;font-weight:600;border-top:1px solid #EAD8C0;'>{$svc_type}</td>
                        </tr>
                    </table>
                </div>
                <div style='background:#fff8f2;border-left:4px solid #C96A2C;padding:1rem;border-radius:0 8px 8px 0;margin-bottom:1.5rem;'>
                    <strong style='color:#C96A2C;'>📍 Important Reminders:</strong>
                    <ul style='color:#3B2A1A;margin:0.5rem 0 0;padding-left:1.2rem;font-size:0.88rem;line-height:1.8;'>
                        <li>Please arrive <strong>15–20 minutes early</strong> to prepare for your session</li>
                        <li>Wear comfortable, loose-fitting clothing</li>
                        <li>Keep your phone on silent during your session</li>
                        <li>Stay well-hydrated before your appointment</li>
                    </ul>
                </div>
                <p style='color:#888;font-size:0.82rem;text-align:center;'>
                    Questions? Call us at <strong>09853359998</strong><br>
                    G&amp;R Bldg., M.H Del Pilar, Molo, Iloilo City
                </p>
            </div>
            <div style='background:#3B2A1A;padding:1rem;text-align:center;'>
                <p style='color:#EAD8C0;margin:0;font-size:0.78rem;'>© " . date('Y') . " Recovery Iloilo. All rights reserved.</p>
            </div>
        </div>";

        $mail->AltBody = "Your " . $appt['service_name'] . " appointment on {$appt_date} at {$appt_time} has been confirmed. Please arrive 15-20 minutes early. See you soon!";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('[MAIL] Approval email failed for appt #' . $appt_id . ': '
            . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}

$message = ''; $message_type = '';

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: ASSIGN THERAPIST
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_therapist') {
    verify_csrf_token();
    $appt_id        = intval($_POST['appt_id']);
    $therapist_id   = intval($_POST['therapist_id']);
    $commission     = floatval($_POST['commission'] ?? 0);
    $notes          = sanitize_input($_POST['notes'] ?? '');
    $people_handled = max(1, intval($_POST['people_handled'] ?? 1));

    $s = $conn->prepare("SELECT people_count FROM appointments WHERE id=?");
    $s->bind_param("i",$appt_id); $s->execute();
    $people = (int)($s->get_result()->fetch_assoc()['people_count'] ?? 1); $s->close();

    $d = $conn->prepare("SELECT id FROM appointment_therapists WHERE appointment_id=? AND therapist_id=?");
    $d->bind_param("ii",$appt_id,$therapist_id); $d->execute();
    $is_dup = $d->get_result()->num_rows > 0; $d->close();

    // Use SUM(people_handled) for the people-budget check, not COUNT(*)
    $c = $conn->prepare("SELECT COALESCE(SUM(people_handled), 0) AS sum_ph FROM appointment_therapists WHERE appointment_id=?");
    $c->bind_param("i",$appt_id); $c->execute();
    $filled_sum = (int)$c->get_result()->fetch_assoc()['sum_ph']; $c->close();
    $budget_remaining = $people - $filled_sum;

    if ($is_dup) {
        $message = "This therapist is already assigned."; $message_type = "danger";
    } elseif ($people_handled > $budget_remaining) {
        $message = $budget_remaining <= 0
            ? "All {$people} people are already covered for this appointment."
            : "Cannot assign {$people_handled} — only {$budget_remaining} person(s) unassigned.";
        $message_type = "danger";
    } else {
        // ── Proper overlap conflict check ─────────────────────────────────────
        $appt_info = $conn->prepare("
            SELECT a.appointment_date, a.service_type, s.session_time
            FROM appointments a JOIN services s ON s.id = a.service_id
            WHERE a.id = ?
        ");
        $appt_info->bind_param("i", $appt_id); $appt_info->execute();
        $ai = $appt_info->get_result()->fetch_assoc(); $appt_info->close();

        $ai_buffer  = ($ai['service_type'] ?? '') === 'home' ? 30 : 0;
        $ai_session = intval($ai['session_time'] ?? 60);
        $ai_date    = $ai['appointment_date'];

        $conf = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM appointment_therapists at2
            JOIN appointments a2 ON at2.appointment_id = a2.id
            JOIN services     s2 ON a2.service_id = s2.id
            WHERE at2.therapist_id = ?
              AND a2.id     != ?
              AND a2.status IN ('approved','assigned','pending')
              AND (a2.appointment_date - INTERVAL IF(a2.service_type='home',30,0) MINUTE)
                    < (? + INTERVAL ? MINUTE)
              AND (a2.appointment_date + INTERVAL (s2.session_time * IFNULL(at2.people_handled,1) + IF(a2.service_type='home',30,0)) MINUTE)
                    > (? - INTERVAL ? MINUTE)
        ");
        // new slot duration = session_time × people_handled (back-to-back model)
        $ai_end_mins = ($ai_session * $people_handled) + $ai_buffer;
        $conf->bind_param("iisisi",
            $therapist_id, $appt_id,
            $ai_date, $ai_end_mins,
            $ai_date, $ai_buffer
        );
        $conf->execute();
        $conf_count = (int)$conf->get_result()->fetch_assoc()['cnt']; $conf->close();

        if ($conf_count > 0) {
            $t_end   = date('h:i A', strtotime($ai_date . ' +' . $ai_end_mins . ' minutes'));
            $t_start = date('h:i A', strtotime($ai_date . ' -' . $ai_buffer . ' minutes'));
            $message = "⚠️ Therapist is already booked during {$t_start}–{$t_end}" . ($ai_buffer ? " (incl. travel buffer)" : "") . ".";
            $message_type = "danger";
        } else {
            $ins = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, notes, commission, people_handled) VALUES (?,?,?,?,?)");
            $ins->bind_param("iisdi",$appt_id,$therapist_id,$notes,$commission,$people_handled);
            $ok = $ins->execute(); $ins->close();
            $message = $ok ? "✅ Therapist assigned." : "Failed.";
            $message_type = $ok ? "success" : "danger";
        }
    }
}

// ── REMOVE THERAPIST ──────────────────────────────────────────────────────────
if (isset($_GET['remove_assign'])) {
    $at_id = intval($_GET['remove_assign']);
    $r = $conn->prepare("DELETE FROM appointment_therapists WHERE id=?");
    $r->bind_param("i",$at_id); $r->execute(); $r->close();
    $message = "Therapist removed."; $message_type = "success";
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: ADD EXTRA SERVICE
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_extra_service') {
    verify_csrf_token();
    $es_appt_id      = intval($_POST['appt_id']        ?? 0);
    $es_service_id   = intval($_POST['extra_svc_id']    ?? 0);
    $es_therapist_id = intval($_POST['extra_therapist'] ?? 0);
    $es_person_label = sanitize_input($_POST['person_label']  ?? 'Person 1');
    $extra_pm        = sanitize_input($_POST['extra_payment_method'] ?? 'cash');
    if (!in_array($extra_pm, ['cash','gcash','maya','qrph','card','bank'])) $extra_pm = 'cash';
    $es_pm_ref       = sanitize_input($_POST['paymongo_reference'] ?? '');
    $es_pm_method    = sanitize_input($_POST['paymongo_method']    ?? '');
    $es_notes        = sanitize_input($_POST['extra_notes']   ?? '');
    $es_added_by     = intval($_SESSION['user_id']            ?? 0);
    $es_price_raw    = $_POST['extra_price']                  ?? '';

    // Load appointment (status, rate_type, partner_id)
    $es_chk = $conn->prepare("
        SELECT a.status, a.rate_type, a.partner_id
        FROM appointments a
        WHERE a.id = ?
    ");
    $es_chk->bind_param("i", $es_appt_id); $es_chk->execute();
    $es_appt = $es_chk->get_result()->fetch_assoc(); $es_chk->close();

    $es_ok = true;
    $blocked_statuses = ['completed','declined','cancelled','refund_requested'];
    if (!$es_appt || in_array($es_appt['status'], $blocked_statuses)) {
        $message = "❌ Cannot add services to a " . ($es_appt ? $es_appt['status'] : 'unknown') . " appointment.";
        $message_type = "danger"; $es_ok = false;
    } elseif (!$es_service_id) {
        $message = "Please select a service."; $message_type = "danger"; $es_ok = false;
    } elseif ($es_therapist_id <= 0) {
        $message = "Please select a therapist for the extra service."; $message_type = "danger"; $es_ok = false;
    }

    if ($es_ok) {
        $es_svc_s = $conn->prepare("SELECT id, name, price FROM services WHERE id = ?");
        $es_svc_s->bind_param("i", $es_service_id); $es_svc_s->execute();
        $es_svc = $es_svc_s->get_result()->fetch_assoc(); $es_svc_s->close();
        if (!$es_svc) { $message = "Service not found."; $message_type = "danger"; $es_ok = false; }
    }

    if ($es_ok) {
        // Compute base price from appointment's rate_type
        $es_rate_type  = $es_appt['rate_type']  ?? 'regular';
        $es_partner_id = intval($es_appt['partner_id'] ?? 0);
        $es_reg_price  = floatval($es_svc['price']);
        switch ($es_rate_type) {
            case 'home':       $es_base = ($es_reg_price * 2) + 300; break;
            case 'influencer': $es_base = 0.00; break;
            case 'hotel':
                $es_base = $es_reg_price;
                if ($es_partner_id > 0) {
                    $es_pr = $conn->prepare("SELECT price FROM partner_rates WHERE partner_id=? AND service_id=?");
                    $es_pr->bind_param("ii", $es_partner_id, $es_service_id); $es_pr->execute();
                    $es_pr_row = $es_pr->get_result()->fetch_assoc(); $es_pr->close();
                    if ($es_pr_row) $es_base = floatval($es_pr_row['price']);
                }
                break;
            default: $es_base = $es_reg_price;
        }
        // Use admin-supplied price if valid, else fall back to rate-computed base
        $es_charged = (is_numeric($es_price_raw) && floatval($es_price_raw) >= 0)
            ? round(floatval($es_price_raw), 2)
            : $es_base;
    }

    // ── Qualification check ───────────────────────────────────────────────────
    if ($es_ok && $es_therapist_id > 0) {
        $es_qual = $conn->prepare("
            SELECT (SELECT COUNT(*) FROM therapist_specialty_services
                    WHERE therapist_id=? AND service_id=?)
                 + (SELECT COUNT(*) FROM therapist_specialties ts
                    JOIN services sv ON sv.category_id = ts.category_id
                    WHERE ts.therapist_id=? AND sv.id=?)
                 + IFNULL((SELECT is_generalist FROM therapists WHERE id=?), 0)
                 AS total
        ");
        $es_qual->bind_param("iiiii",
            $es_therapist_id, $es_service_id,
            $es_therapist_id, $es_service_id,
            $es_therapist_id);
        $es_qual->execute();
        $es_qual_count = (int)$es_qual->get_result()->fetch_assoc()['total'];
        $es_qual->close();
        if ($es_qual_count === 0) {
            $message = "⚠️ The selected therapist is not qualified for this service.";
            $message_type = "danger"; $es_ok = false;
        }
    }

    if ($es_ok) {
        // ── Commission auto-calc (walkin.php formula) ─────────────────────────
        $es_commission = 0.00;
        if ($es_therapist_id > 0) {
            $es_cm = $conn->prepare("SELECT commission_percent, influencer_flat_rate
                                     FROM therapist_commission
                                     WHERE therapist_id=? AND service_id=? LIMIT 1");
            $es_cm->bind_param("ii", $es_therapist_id, $es_service_id); $es_cm->execute();
            $es_cm_row = $es_cm->get_result()->fetch_assoc(); $es_cm->close();
            if ($es_cm_row) {
                $es_commission = $es_rate_type === 'influencer'
                    ? floatval($es_cm_row['influencer_flat_rate'])
                    : round($es_charged * floatval($es_cm_row['commission_percent']) / 100, 2);
            }
        }

        // ── INSERT — all bind_param values are plain local variables ──────────
        // Columns: appointment_id(i) service_id(i) therapist_id(i) person_label(s)
        //          charged_price(d) commission(d) rate_type(s) payment_method(s)
        //          payment_status(s) notes(s) added_by(i) paymongo_reference(s) paymongo_method(s)
        $es_pay_status = 'unpaid';
        $es_ins = $conn->prepare("
            INSERT INTO appointment_extra_services
                (appointment_id, service_id, therapist_id, person_label, charged_price,
                 commission, rate_type, payment_method, payment_status, notes, added_by,
                 paymongo_reference, paymongo_method)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $es_ins->bind_param("iiisddssssiss",
            $es_appt_id, $es_service_id, $es_therapist_id,
            $es_person_label, $es_charged, $es_commission,
            $es_rate_type, $extra_pm, $es_pay_status, $es_notes, $es_added_by,
            $es_pm_ref, $es_pm_method);
        $es_ins->execute(); $es_ins->close();

        $message = "✅ Extra service added for {$es_person_label}.";
        $message_type = "success";
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: REMOVE EXTRA SERVICE
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['remove_extra'])) {
    $extra_id = intval($_GET['remove_extra']);
    $redir_filter = in_array($_GET['filter'] ?? '', ['all','pending','assigned',
        'approved','completed','declined','cancelled'])
        ? $_GET['filter'] : 'all';
    $del = $conn->prepare("DELETE FROM appointment_extra_services WHERE id=?");
    $del->bind_param("i", $extra_id);
    $del->execute();
    $del->close();
    header("Location: appointments.php?filter={$redir_filter}&range=" . urlencode($_GET['range'] ?? ''));
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: RESCHEDULE
// ═══════════════════════════════════════════════════════════════════════════
// ── EDIT APPOINTMENT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_appointment') {
    verify_csrf_token();

    // Receptionist PIN check for edit
    $pr_edit = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_edit = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_edit) {
            $message = "⚠️ Incorrect PIN. Edit action cancelled."; $message_type = "danger";
            goto skip_edit;
        }
    }

    $appt_id      = intval($_POST['appt_id'] ?? 0);
    $booking_date = sanitize_input($_POST['booking_date'] ?? '');
    $service_type = in_array($_POST['service_type']??'',['regular','home','hotel','influencer'])
                    ? $_POST['service_type'] : 'regular';
    $people_count = max(1, intval($_POST['people_count'] ?? 1));
    $notes        = sanitize_input($_POST['notes'] ?? '');

    if ($appt_id && $booking_date) {
        // Fetch current appointment to get service_id
        $cur = $conn->prepare("SELECT service_id FROM appointments WHERE id=?");
        $cur->bind_param("i", $appt_id); $cur->execute();
        $cur_row = $cur->get_result()->fetch_assoc(); $cur->close();

        // Validate availability using engine
        if ($cur_row) {
            require_once __DIR__ . '/../availability.php';
            $engine = new AvailabilityEngine($conn);
            $check  = $engine->checkSlot(
                $cur_row['service_id'], $booking_date,
                $people_count, $service_type, $appt_id
            );
            if (!$check['available']) {
                $message = '❌ Cannot edit: ' . ($check['reason'] ?? 'Slot not available.');
                $message_type = 'danger';
                goto skip_edit;
            }
        }

        $editor_name = (is_cashier() && !empty($pr_edit['full_name']))
            ? $pr_edit['full_name']
            : ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
        $editor_id   = (int)$_SESSION['user_id'];
        $upd = $conn->prepare("UPDATE appointments SET appointment_date=?, service_type=?, people_count=?, customer_note=?, rescheduled_by=?, rescheduled_by_name=?, rescheduled_at=NOW() WHERE id=?");
        $upd->bind_param("ssissii", $booking_date, $service_type, $people_count, $notes, $editor_id, $editor_name, $appt_id);
        $upd->execute(); $upd->close();

        // Therapist reassignment (optional)
        $reassign_id = intval($_POST['reassign_therapist_id'] ?? 0);
        if ($reassign_id > 0) {
            $del_at = $conn->prepare("DELETE FROM appointment_therapists WHERE appointment_id=?");
            $del_at->bind_param("i", $appt_id); $del_at->execute(); $del_at->close();

            $ra_people = max(1, $people_count);
            $ra_notes  = 'Reassigned by admin';
            $ra_comm   = 0.00;
            $ins_at = $conn->prepare("INSERT INTO appointment_therapists
                (appointment_id, therapist_id, notes, commission, people_handled)
                VALUES (?, ?, ?, ?, ?)");
            $ins_at->bind_param("iisdi", $appt_id, $reassign_id, $ra_notes, $ra_comm, $ra_people);
            $ins_at->execute(); $ins_at->close();

            $conn->query("UPDATE appointments SET status='pending'
                WHERE id={$appt_id} AND status='assigned'");
        }

        $message = '✅ Appointment updated.'; $message_type = 'success';
        $_actor_edit = (is_cashier() && !empty($pr_edit['full_name']))
            ? ['id' => null, 'name' => $pr_edit['full_name'], 'role' => 'receptionist']
            : null;
        log_activity($conn, 'appointment_edited',
            "Edited appointment #{$appt_id}",
            'appointment', $appt_id, $_actor_edit);
    }
    skip_edit:;
}

// ── ADD SERVICE TO EXISTING ORDER ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service_to_order') {
    verify_csrf_token();
    $order_id     = intval($_POST['order_id'] ?? 0);
    $service_id   = intval($_POST['service_id'] ?? 0);
    $booking_date = sanitize_input($_POST['booking_date'] ?? '');
    $people_count = max(1, intval($_POST['people_count'] ?? 1));

    if ($order_id && $service_id && $booking_date) {
        $svc_s = $conn->prepare("SELECT * FROM services WHERE id=?");
        $svc_s->bind_param("i",$service_id); $svc_s->execute();
        $svc = $svc_s->get_result()->fetch_assoc(); $svc_s->close();
        $ord_s = $conn->prepare("SELECT user_id FROM orders WHERE id=?");
        $ord_s->bind_param("i",$order_id); $ord_s->execute();
        $ord = $ord_s->get_result()->fetch_assoc(); $ord_s->close();

        if ($svc && $ord) {
            $conn->begin_transaction();
            try {
                $oi = $conn->prepare("INSERT INTO order_items (order_id,service_id,quantity,price,subtotal) VALUES (?,?,1,?,?)");
                $oi->bind_param("iidd",$order_id,$service_id,$svc['price'],$svc['price']); $oi->execute();
                $new_oi_id = $oi->insert_id; $oi->close();
                $conn->query("UPDATE orders SET total_amount=total_amount+{$svc['price']}, final_amount=final_amount+{$svc['price']} WHERE id=$order_id");
                $appt_ins = $conn->prepare("INSERT INTO appointments (user_id,service_id,order_item_id,appointment_date,status,people_count,service_type,charged_price) VALUES (?,?,?,?,'pending',?,'regular',?)");
                $appt_ins->bind_param("iiisid",$ord['user_id'],$service_id,$new_oi_id,$booking_date,$people_count,$svc['price']);
                $appt_ins->execute(); $appt_ins->close();
                $conn->commit();
                $message = '✅ Service added to order.'; $message_type = 'success';
            } catch (Throwable $e) {
                $conn->rollback();
                $message = '❌ Failed: ' . $e->getMessage(); $message_type = 'danger';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reschedule') {
    verify_csrf_token();

    // Receptionist PIN check for reschedule
    $pr_rs = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_rs = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_rs) {
            $message = "⚠️ Incorrect PIN. Reschedule action cancelled."; $message_type = "danger";
            goto skip_reschedule;
        }
    }

    $appt_id  = intval($_POST['appt_id'] ?? 0);
    $new_date = sanitize_input($_POST['new_date'] ?? '');
    $new_time = sanitize_input($_POST['new_time'] ?? '');

    if ($appt_id && $new_date && $new_time) {
        $new_datetime = $new_date . ' ' . $new_time . ':00';

        $chk = $conn->prepare("SELECT user_id, status FROM appointments WHERE id=?");
        $chk->bind_param("i", $appt_id); $chk->execute();
        $chk_row = $chk->get_result()->fetch_assoc(); $chk->close();

        if ($chk_row && in_array($chk_row['status'], ['pending','approved','assigned'])) {
            $rs_by   = (int)$_SESSION['user_id'];
            $rs_name = (is_cashier() && !empty($pr_rs['full_name']))
                ? $pr_rs['full_name']
                : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
            $upd = $conn->prepare("UPDATE appointments SET appointment_date=?, rescheduled_by=?, rescheduled_by_name=?, rescheduled_at=NOW() WHERE id=?");
            $upd->bind_param("sisi", $new_datetime, $rs_by, $rs_name, $appt_id);
            $upd->execute(); $upd->close();

            add_notification($conn, $chk_row['user_id'], 'appointment',
                '📅 Appointment Rescheduled',
                'Your appointment has been rescheduled to ' . date('F d, Y h:i A', strtotime($new_datetime)) . '.',
                'appointments.php'
            );
            $message = "📅 Appointment #$appt_id rescheduled to " . date('F d, Y h:i A', strtotime($new_datetime)) . ".";
            $message_type = "success";
            $_actor_rs = (is_cashier() && !empty($pr_rs['full_name']))
                ? ['id' => null, 'name' => $pr_rs['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'appointment_rescheduled',
                "Rescheduled appointment #{$appt_id} to " . date('M j, Y g:i A', strtotime($new_datetime)),
                'appointment', $appt_id, $_actor_rs);
        } else {
            $message = "Cannot reschedule — appointment not found or status not allowed.";
            $message_type = "danger";
        }
    } else {
        $message = "Please provide both a new date and time.";
        $message_type = "danger";
    }
    skip_reschedule:;
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: CANCEL (customer-initiated, recorded by admin)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    verify_csrf_token();

    // Receptionist PIN check for cancel
    $pr_cn = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_cn = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_cn) {
            $message = "⚠️ Incorrect PIN. Cancel action cancelled."; $message_type = "danger";
            goto skip_cancel;
        }
    }

    $appt_id      = intval($_POST['appt_id'] ?? 0);
    $cancel_reason = sanitize_input($_POST['cancel_reason'] ?? '');

    $chk = $conn->prepare("SELECT a.user_id, a.status, s.name AS service_name FROM appointments a JOIN services s ON a.service_id=s.id WHERE a.id=?");
    $chk->bind_param("i", $appt_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc(); $chk->close();

    if ($chk_row && in_array($chk_row['status'], ['pending','approved','assigned'])) {
        $cn_by   = (int)$_SESSION['user_id'];
        $cn_name = (is_cashier() && !empty($pr_cn['full_name']))
            ? $pr_cn['full_name']
            : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
        $upd = $conn->prepare("UPDATE appointments SET status='cancelled', cancel_reason=?, cancelled_by=?, cancelled_by_name=? WHERE id=?");
        $upd->bind_param("sisi", $cancel_reason, $cn_by, $cn_name, $appt_id); $upd->execute(); $upd->close();

        add_notification($conn, $chk_row['user_id'], 'appointment',
            '🚫 Appointment Cancelled',
            'Your ' . $chk_row['service_name'] . ' appointment has been cancelled.' . ($cancel_reason ? ' Reason: ' . $cancel_reason : ''),
            'appointments.php'
        );
        $message = "🚫 Appointment #$appt_id marked as cancelled.";
        $message_type = "success";
        $_actor_cn = (is_cashier() && !empty($pr_cn['full_name']))
            ? ['id' => null, 'name' => $pr_cn['full_name'], 'role' => 'receptionist']
            : null;
        log_activity($conn, 'appointment_cancelled',
            "Cancelled appointment #{$appt_id} — {$chk_row['service_name']}",
            'appointment', $appt_id, $_actor_cn);
    } else {
        $message = "Cannot cancel — appointment not found or already completed/declined.";
        $message_type = "danger";
    }
    skip_cancel:;
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTIONS — approve / decline / complete
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] !== 'assign_therapist') {
    verify_csrf_token();
    $action  = $_POST['action'];
    $appt_id = intval($_POST['appt_id'] ?? 0);

    $stmt = $conn->prepare("SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON a.service_id=s.id WHERE a.id=?");
    $stmt->bind_param("i",$appt_id); $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$appt) {
        $message = "Appointment not found."; $message_type = "danger";
    } else {

        // ── APPROVE ──────────────────────────────────────────────────────────
        if ($action === 'approve' && $appt['status'] === 'pending') {

            // Receptionist PIN check for approve
            $pr_approve = null;
            if (is_cashier()) {
                $entered_pin = trim($_POST['pin'] ?? '');
                $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
                $ps->bind_param("s", $entered_pin); $ps->execute();
                $pr_approve = $ps->get_result()->fetch_assoc(); $ps->close();
                if (!$pr_approve) {
                    $message = "⚠️ Incorrect PIN. Approve action cancelled."; $message_type = "danger";
                    goto end_action;
                }
            }

            // Count total people covered (sum of people_handled, not row count)
            $chk = $conn->prepare("SELECT COALESCE(SUM(IFNULL(people_handled,1)),0) AS covered FROM appointment_therapists WHERE appointment_id=?");
            $chk->bind_param("i",$appt_id); $chk->execute();
            $assigned_count = (int)$chk->get_result()->fetch_assoc()['covered']; $chk->close();

            $people_needed = (int)($appt['people_count'] ?? 1);

            if ($assigned_count === 0) {
                $message = "Cannot approve — assign at least one therapist first.";
                $message_type = "danger";
            } elseif ($assigned_count < $people_needed) {
                $message = "Cannot approve — this is a {$people_needed}-person booking. Only {$assigned_count} of {$people_needed} people have a therapist assigned.";
                $message_type = "danger";
            } else {
                $approver_id = (int)$_SESSION['user_id'];
                $approver_nm = (is_cashier() && !empty($pr_approve['full_name']))
                    ? $pr_approve['full_name']
                    : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));

                // ── Discount (submitted from PIN modal form) ──────────────────
                $disc_type         = in_array($_POST['appt_discount_type'] ?? '', ['none','voucher','gift_card','senior','pwd','employee'])
                                     ? $_POST['appt_discount_type'] : 'none';
                $disc_voucher_type = $_POST['appt_voucher_type']   ?? 'cash';
                $disc_value        = floatval($_POST['appt_discount_value'] ?? 0);

                // ── Payment method chosen at approval ─────────────────────────
                $appt_pay_method = in_array($_POST['appt_payment_method'] ?? '', ['cash','qrph','gcash','maya','bpi_debit','bpi_credit'])
                                   ? $_POST['appt_payment_method'] : 'cash';

                // Save discount to the linked order
                if ($disc_type !== 'none' && !empty($appt['order_item_id'])) {
                    $o_stmt = $conn->prepare("SELECT o.id, o.total_amount FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=?");
                    $o_stmt->bind_param("i", $appt['order_item_id']); $o_stmt->execute();
                    $o_row = $o_stmt->get_result()->fetch_assoc(); $o_stmt->close();

                    if ($o_row) {
                        $orig = floatval($o_row['total_amount']);
                        if ($disc_type === 'senior' || $disc_type === 'pwd') {
                            $disc_amt = round($orig * 0.20, 2);
                        } elseif ($disc_type === 'employee') {
                            $disc_amt = round($orig * 0.50, 2);
                        } elseif ($disc_type === 'voucher' && $disc_value > 0) {
                            $disc_amt = $disc_voucher_type === 'percent'
                                ? round($orig * ($disc_value / 100), 2)
                                : min($disc_value, $orig);
                        } else {
                            $disc_amt = 0.00;
                        }
                        $final_amt = max(0.00, $orig - $disc_amt);
                        $u = $conn->prepare("UPDATE orders SET discount_type=?, discount_amount=?, final_amount=? WHERE id=?");
                        $u->bind_param("sddi", $disc_type, $disc_amt, $final_amt, $o_row['id']); $u->execute(); $u->close();
                    }
                }

                $upd = $conn->prepare("UPDATE appointments SET status='assigned', approved_by=?, approved_by_name=? WHERE id=?");
                $upd->bind_param("isi",$approver_id,$approver_nm,$appt_id); $upd->execute(); $upd->close();

                if (!empty($appt['order_item_id'])) {
                    $po_s = $conn->prepare("SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=? LIMIT 1");
                    $po_s->bind_param("i", $appt['order_item_id']); $po_s->execute();
                    $po_r = $po_s->get_result()->fetch_assoc(); $po_s->close();
                    if (!empty($po_r['id'])) {
                        $po_id = (int)$po_r['id'];
                        $ord = $conn->prepare("UPDATE orders SET approval_status='approved', approved_by=?, approved_by_name=? WHERE id=? AND approval_status='pending'");
                        $ord->bind_param("isi", $approver_id, $approver_nm, $po_id); $ord->execute(); $ord->close();
                    }
                }

                $tn_s = $conn->prepare("SELECT t.full_name FROM appointment_therapists at2 JOIN therapists t ON t.id = at2.therapist_id WHERE at2.appointment_id = ? ORDER BY at2.id DESC LIMIT 1");
                $tn_s->bind_param("i", $appt_id); $tn_s->execute();
                $tn_row = $tn_s->get_result()->fetch_assoc(); $tn_s->close();
                $notif_therapist = $tn_row['full_name'] ?? 'your therapist';
                $notif_appt_time = date('F j, Y g:i A', strtotime($appt['appointment_date']));
                $notif_body = 'Your '.$appt['service_name'].' appointment has been confirmed! '
                            . htmlspecialchars($notif_therapist).' will be your therapist. '
                            . 'Please arrive by '.$notif_appt_time.'.';

                add_notification($conn,$appt['user_id'],'appointment','✅ Appointment Confirmed!',
                    $notif_body, 'appointments.php');

                send_approval_email($conn, $appt_id);

                $message = "Appointment #$appt_id confirmed. Therapist: $notif_therapist."; $message_type = "success";
                $_actor_ap = (is_cashier() && !empty($pr_approve['full_name']))
                    ? ['id' => null, 'name' => $pr_approve['full_name'], 'role' => 'receptionist']
                    : null;
                log_activity($conn, 'appointment_approved',
                    "Approved appointment #{$appt_id} — {$appt['service_name']} (therapist: {$notif_therapist})",
                    'appointment', $appt_id, $_actor_ap);
            }

        // ── DECLINE ──────────────────────────────────────────────────────────
        } elseif ($action === 'decline' && in_array($appt['status'],['pending','approved','assigned'])) {

            // Receptionist PIN check for decline
            $pr = null;
            if (is_cashier()) {
                $entered_pin = trim($_POST['pin'] ?? '');
                $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
                $ps->bind_param("s", $entered_pin); $ps->execute();
                $pr = $ps->get_result()->fetch_assoc(); $ps->close();
                if (!$pr) {
                    $message = "⚠️ Incorrect PIN. Decline action cancelled."; $message_type = "danger";
                    goto end_action;
                }
            }

            $dc_by   = (int)$_SESSION['user_id'];
            $dc_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');
            $upd = $conn->prepare("UPDATE appointments SET status='declined', declined_by=?, declined_by_name=? WHERE id=?");
            $upd->bind_param("isi",$dc_by,$dc_name,$appt_id); $upd->execute(); $upd->close();

            if (!empty($appt['order_item_id'])) {
                $oi_stmt = $conn->prepare("SELECT order_id FROM order_items WHERE id=?");
                $oi_stmt->bind_param("i", $appt['order_item_id']); $oi_stmt->execute();
                $oi_row = $oi_stmt->get_result()->fetch_assoc(); $oi_stmt->close();
                if ($oi_row && $oi_row['order_id']) {
                    $upd_ord = $conn->prepare("UPDATE orders SET approval_status='declined' WHERE id=?");
                    $upd_ord->bind_param("i", $oi_row['order_id']); $upd_ord->execute(); $upd_ord->close();
                }
            }
            add_notification($conn,$appt['user_id'],'appointment','❌ Appointment Declined',
                'Your '.$appt['service_name'].' appointment has been declined.','appointments.php');
            $message = "Appointment #$appt_id declined."; $message_type = "danger";
            $_actor_dc = (is_cashier() && !empty($pr['full_name']))
                ? ['id' => null, 'name' => $pr['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'appointment_declined',
                "Declined appointment #{$appt_id} — {$appt['service_name']}",
                'appointment', $appt_id, $_actor_dc);

        // ── COMPLETE ─────────────────────────────────────────────────────────
        // ── CHECK IN (assigned → approved) ───────────────────────────────────
        } elseif ($action === 'checkin_appointment' && $appt['status'] === 'assigned') {

            // Receptionist PIN check for check-in
            $pr_ci = null;
            if (is_cashier()) {
                $entered_pin = trim($_POST['pin'] ?? '');
                $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
                $ps->bind_param("s", $entered_pin); $ps->execute();
                $pr_ci = $ps->get_result()->fetch_assoc(); $ps->close();
                if (!$pr_ci) {
                    $message = "⚠️ Incorrect PIN. Check-in action cancelled."; $message_type = "danger";
                    goto end_action;
                }
            }

            $ci_pay_method = sanitize_input($_POST['pay_method'] ?? 'cash');
            $ci_allowed_pm = ['cash','qrph','gcash','maya','bank','bpi_debit','bpi_credit'];
            if (!in_array($ci_pay_method, $ci_allowed_pm)) $ci_pay_method = 'cash';
            $ci_pm_ref  = sanitize_input($_POST['paymongo_reference'] ?? '');
            $ci_pm_meth = sanitize_input($_POST['paymongo_method']    ?? '');

            // Discount fields (validated server-side — never trust client amounts for % discounts)
            $ci_disc_type  = sanitize_input($_POST['ci_discount_type'] ?? 'none');
            $ci_disc_allow = ['none','voucher','gift_card','senior','pwd','employee'];
            if (!in_array($ci_disc_type, $ci_disc_allow)) $ci_disc_type = 'none';
            $ci_disc_amt_raw = floatval($_POST['ci_discount_amount'] ?? 0);

            if (!empty($appt['order_item_id'])) {
                $po_chk = $conn->prepare("SELECT o.id, o.total_amount, o.payment_status FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=? LIMIT 1");
                $po_chk->bind_param("i", $appt['order_item_id']); $po_chk->execute();
                $po_row = $po_chk->get_result()->fetch_assoc(); $po_chk->close();
                if (!empty($po_row) && $po_row['payment_status'] !== 'paid') {
                    $base_total = floatval($po_row['total_amount']);
                    // Recalculate server-side (% discounts — never trust client amount)
                    $sv_disc = 0.00;
                    if ($ci_disc_type === 'senior' || $ci_disc_type === 'pwd') {
                        $sv_disc = round($base_total * 0.20, 2);
                    } elseif ($ci_disc_type === 'employee') {
                        $sv_disc = round($base_total * 0.50, 2);
                    } elseif ($ci_disc_type === 'voucher' || $ci_disc_type === 'gift_card') {
                        $sv_disc = min($ci_disc_amt_raw, $base_total); // cap at total
                    }
                    $sv_final = max(0.00, $base_total - $sv_disc);
                    $po_id    = (int)$po_row['id'];

                    $ci_upd = $conn->prepare("
                        UPDATE orders
                        SET payment_status  = 'paid',
                            payment_method  = ?,
                            paymongo_reference = ?,
                            paymongo_method = ?,
                            discount_type   = ?,
                            discount_amount = ?,
                            final_amount    = ?
                        WHERE id = ?
                          AND payment_status != 'paid'
                    ");
                    $ci_upd->bind_param("ssssddi",
                        $ci_pay_method, $ci_pm_ref, $ci_pm_meth,
                        $ci_disc_type, $sv_disc, $sv_final, $po_id);
                    $ci_upd->execute(); $ci_upd->close();
                }
            }

            $ci_by   = (int)$_SESSION['user_id'];
            $ci_name = (is_cashier() && !empty($pr_ci['full_name']))
                ? $pr_ci['full_name']
                : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
            $ci_stmt = $conn->prepare("UPDATE appointments SET status='approved', approved_by=?, approved_by_name=? WHERE id=? AND status='assigned'");
            $ci_stmt->bind_param("isi", $ci_by, $ci_name, $appt_id);
            $ci_stmt->execute(); $ci_stmt->close();

            add_notification($conn, $appt['user_id'], 'appointment', '✅ You\'ve been checked in!',
                'You have been checked in for your '.$appt['service_name'].' appointment. Your session will begin shortly.',
                'appointments.php');

            $message = "Customer checked in for Appointment #$appt_id."; $message_type = "success";
            $_actor_ci = (is_cashier() && !empty($pr_ci['full_name']))
                ? ['id' => null, 'name' => $pr_ci['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'appointment_checkedin',
                "Checked in customer for appointment #{$appt_id} — {$appt['service_name']}",
                'appointment', $appt_id, $_actor_ci);

        // ── COMPLETE (approved → completed) ──────────────────────────────────
        } elseif ($action === 'complete' && $appt['status'] === 'approved') {

            // Receptionist PIN check for complete
            $pr = null;
            if (is_cashier()) {
                $entered_pin = trim($_POST['pin'] ?? '');
                $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
                $ps->bind_param("s", $entered_pin); $ps->execute();
                $pr = $ps->get_result()->fetch_assoc(); $ps->close();
                if (!$pr) {
                    $message = "⚠️ Incorrect PIN. Complete action cancelled."; $message_type = "danger";
                    goto end_action;
                }
            }
            $cp_by   = (int)$_SESSION['user_id'];
            $cp_name = (is_cashier() && !empty($pr['full_name']))
                ? $pr['full_name']
                : ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
            $cp_pay_method   = sanitize_input($_POST['complete_pay_method'] ?? 'cash');
            if (!in_array($cp_pay_method, ['cash','gcash','maya','qrph','card','bank'])) $cp_pay_method = 'cash';
            $celeb_disc_val  = max(0.0, floatval($_POST['celebration_discount'] ?? 0));
            $advance_pay_val = max(0.0, floatval($_POST['advance_payment']      ?? 0));
            // Mark base order paid if still unpaid (e.g. check-in was skipped)
            if (!empty($appt['order_item_id'])) {
                $oi_s2 = $conn->prepare("SELECT o.id, o.payment_status FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=? LIMIT 1");
                $oi_s2->bind_param("i", $appt['order_item_id']); $oi_s2->execute();
                $oi_r2 = $oi_s2->get_result()->fetch_assoc(); $oi_s2->close();
                if ($oi_r2 && $oi_r2['payment_status'] !== 'paid') {
                    $upd_ord_pay = $conn->prepare("UPDATE orders SET payment_status='paid', payment_method=? WHERE id=?");
                    $upd_ord_pay->bind_param("si", $cp_pay_method, $oi_r2['id']); $upd_ord_pay->execute(); $upd_ord_pay->close();
                }
            }
            // Mark unpaid extra services as paid with the collected method
            $es_paid_upd = $conn->prepare("UPDATE appointment_extra_services SET payment_status='paid', payment_method=? WHERE appointment_id=? AND payment_status='unpaid'");
            $es_paid_upd->bind_param("si", $cp_pay_method, $appt_id); $es_paid_upd->execute(); $es_paid_upd->close();
            $upd = $conn->prepare("UPDATE appointments SET status='completed', completed_by=?, completed_by_name=?, celebration_discount=?, advance_payment=? WHERE id=?");
            $upd->bind_param("isddi", $cp_by, $cp_name, $celeb_disc_val, $advance_pay_val, $appt_id);
            $upd->execute(); $upd->close();

            if (!empty($appt['order_item_id'])) {
                $oi_stmt = $conn->prepare("SELECT order_id FROM order_items WHERE id=?");
                $oi_stmt->bind_param("i", $appt['order_item_id']); $oi_stmt->execute();
                $oi_row = $oi_stmt->get_result()->fetch_assoc(); $oi_stmt->close();
                if ($oi_row && $oi_row['order_id']) {
                    $upd_ord = $conn->prepare("UPDATE orders SET approval_status='completed' WHERE id=?");
                    $upd_ord->bind_param("i", $oi_row['order_id']); $upd_ord->execute(); $upd_ord->close();
                }
            }

            // ── Calculate and save commission for each assigned therapist ────
            // charged_price is on the appointment row; fallback to order total_amount
            $charged_for_commission = floatval($appt['charged_price'] ?? 0);
            if ($charged_for_commission <= 0 && !empty($appt['order_item_id'])) {
                $cp_s = $conn->prepare("SELECT o.total_amount FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=?");
                $cp_s->bind_param("i", $appt['order_item_id']); $cp_s->execute();
                $cp_r = $cp_s->get_result()->fetch_assoc(); $cp_s->close();
                $charged_for_commission = floatval($cp_r['total_amount'] ?? 0);
            }

            $at_list = $conn->prepare("SELECT id, therapist_id, IFNULL(people_handled, 1) AS people_handled FROM appointment_therapists WHERE appointment_id=?");
            $at_list->bind_param("i", $appt_id); $at_list->execute();
            $done_therapists = $at_list->get_result()->fetch_all(MYSQLI_ASSOC); $at_list->close();

            $svc_id = $appt['service_id'] ?? null;

            foreach ($done_therapists as $dt) {
                $tid = $dt['therapist_id'];
                $ph  = max(1, intval($dt['people_handled']));  // people this therapist handled
                $commission_amt = 0.00;

                if ($svc_id && $charged_for_commission > 0) {
                    $cm = $conn->prepare("
                        SELECT commission_percent, influencer_flat_rate
                        FROM therapist_commission
                        WHERE therapist_id = ? AND service_id = ?
                        LIMIT 1
                    ");
                    $cm->bind_param("ii", $tid, $svc_id); $cm->execute();
                    $cm_row = $cm->get_result()->fetch_assoc(); $cm->close();

                    if ($cm_row) {
                        $rate_type_appt = $appt['rate_type'] ?? 'regular';
                        // FIXED: Bug 2 — charged_for_commission is now the TOTAL for all people;
                        // divide by people_count to get per-person price, then multiply by people_handled
                        $people_total    = max(1, intval($appt['people_count'] ?? 1));
                        $per_person_price = $charged_for_commission / $people_total;
                        $commission_amt = ($rate_type_appt === 'influencer')
                            ? floatval($cm_row['influencer_flat_rate']) * $ph
                            : round($per_person_price * $ph * floatval($cm_row['commission_percent']) / 100, 2);
                    }
                }

                // Write commission back to appointment_therapists
                $cu = $conn->prepare("UPDATE appointment_therapists SET commission=? WHERE id=?");
                $cu->bind_param("di", $commission_amt, $dt['id']); $cu->execute(); $cu->close();
            }

            // Move therapists to back of rotation queue
            $max_rot = $conn->query("
                SELECT IFNULL(MAX(rotation_order), 0) AS m
                FROM therapist_attendance WHERE duty_date = CURDATE()
            ")->fetch_assoc()['m'];

            foreach ($done_therapists as $idx => $dt) {
                $new_order = $max_rot + $idx + 1;
                $upd2 = $conn->prepare("UPDATE therapist_attendance SET rotation_order=? WHERE therapist_id=? AND duty_date=CURDATE()");
                $upd2->bind_param("ii", $new_order, $dt['therapist_id']); $upd2->execute(); $upd2->close();
            }

            add_notification($conn,$appt['user_id'],'appointment','🎉 Session Completed!',
                'Your '.$appt['service_name'].' session is done. Thank you for visiting! Please leave a feedback.',
                'appointments.php');
            // Send completion receipt email with full breakdown
            if (!empty($appt['order_item_id'])) {
                $_ord_id_stmt = $conn->prepare("SELECT order_id FROM order_items WHERE id=? LIMIT 1");
                $_ord_id_stmt->bind_param("i", $appt['order_item_id']); $_ord_id_stmt->execute();
                $_ord_id_row = $_ord_id_stmt->get_result()->fetch_assoc(); $_ord_id_stmt->close();
                if ($_ord_id_row) {
                    require_once __DIR__ . '/../send_receipt.php';
                    send_completion_receipt($conn, $appt_id, (int)$_ord_id_row['order_id']);
                }
            }
            $message = "🎉 Appointment #$appt_id completed. Therapist(s) returned to rotation."; $message_type = "success";
            $_actor_cp = (is_cashier() && !empty($pr['full_name']))
                ? ['id' => null, 'name' => $pr['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'appointment_completed',
                "Completed appointment #{$appt_id} — {$appt['service_name']}",
                'appointment', $appt_id, $_actor_cp);

        } elseif ($action === 'save_per_person_inline') {

            // Receptionist PIN check for per-person therapist assignment
            $pr_spi = null;
            if (is_cashier()) {
                $entered_pin = trim($_POST['pin'] ?? '');
                $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
                $ps->bind_param("s", $entered_pin); $ps->execute();
                $pr_spi = $ps->get_result()->fetch_assoc(); $ps->close();
                if (!$pr_spi) {
                    $message = "⚠️ Incorrect PIN. Action cancelled."; $message_type = "danger";
                    goto end_action;
                }
            }

            $therapist_ids = array_map('intval', (array)($_POST['therapist_ids'] ?? []));
            $people_needed = max(1, (int)($appt['people_count'] ?? 1));
            $svc_id_ai     = (int)$appt['service_id'];

            if (count($therapist_ids) !== $people_needed || in_array(0, $therapist_ids, true)) {
                $message = "Please select a therapist for every person."; $message_type = "danger";
                goto end_action;
            }

            $grouped = array_count_values($therapist_ids);
            $errors  = [];

            foreach ($grouped as $tid_key => $ph) {
                $tid = (int)$tid_key;
                $sp  = $conn->prepare("
                    SELECT (SELECT COUNT(*) FROM therapist_specialty_services WHERE therapist_id=? AND service_id=?)
                         + (SELECT COUNT(*) FROM therapist_specialties ts
                            JOIN services s ON s.category_id=ts.category_id
                            WHERE ts.therapist_id=? AND s.id=?) AS total
                ");
                $sp->bind_param("iiii", $tid, $svc_id_ai, $tid, $svc_id_ai); $sp->execute();
                $sp_count = (int)$sp->get_result()->fetch_assoc()['total']; $sp->close();
                if ($sp_count === 0) {
                    $tn = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
                    $tn->bind_param("i", $tid); $tn->execute();
                    $tn_name = htmlspecialchars($tn->get_result()->fetch_assoc()['full_name'] ?? 'Therapist'); $tn->close();
                    $errors[] = "{$tn_name} is not qualified for this service.";
                }
            }

            if (!empty($errors)) {
                $message = implode(' ', $errors); $message_type = "danger"; goto end_action;
            }

            $conn->begin_transaction();
            try {
                $del = $conn->prepare("DELETE FROM appointment_therapists WHERE appointment_id=?");
                $del->bind_param("i", $appt_id); $del->execute(); $del->close();

                $commission = 0.00; $notes = '';
                foreach ($grouped as $tid_key => $ph) {
                    $tid = (int)$tid_key;
                    $ins = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, notes, commission, people_handled) VALUES (?,?,?,?,?)");
                    $ins->bind_param("iisdi", $appt_id, $tid, $notes, $commission, $ph);
                    $ins->execute(); $ins->close();
                }

                $conn->query("UPDATE appointments SET status='assigned' WHERE id={$appt_id} AND status='pending'");

                $conn->commit();

                // Notify customer + send approval email + approve linked order
                add_notification($conn, $appt['user_id'], 'appointment',
                    '💆 Therapist Assigned — Appointment Confirmed!',
                    'Your ' . $appt['service_name'] . ' appointment on ' .
                    date('F j, Y g:i A', strtotime($appt['appointment_date'])) .
                    ' has been confirmed.',
                    'appointments.php');

                send_approval_email($conn, $appt_id);

                if (!empty($appt['order_item_id'])) {
                    $po = $conn->prepare("SELECT o.id FROM orders o
                        JOIN order_items oi ON oi.order_id=o.id
                        WHERE oi.id=? LIMIT 1");
                    $po->bind_param("i", $appt['order_item_id']);
                    $po->execute();
                    $po_r = $po->get_result()->fetch_assoc(); $po->close();
                    if (!empty($po_r['id'])) {
                        $conn->query("UPDATE orders SET approval_status='approved'
                            WHERE id={$po_r['id']} AND approval_status='pending'");
                    }
                }

                $message = "✅ Therapists assigned successfully."; $message_type = "success";
                $_actor_spi = (is_cashier() && !empty($pr_spi['full_name']))
                    ? ['id' => null, 'name' => $pr_spi['full_name'], 'role' => 'receptionist']
                    : null;
                log_activity($conn, 'therapist_assigned',
                    "Assigned therapist(s) to appointment #{$appt_id} — {$appt['service_name']}",
                    'appointment', $appt_id, $_actor_spi);
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Failed to save assignments. Please try again."; $message_type = "danger";
            }

        } else {
            $message = "Action not allowed for current status."; $message_type = "danger";
        }
    }
    end_action:;
}

// ═══════════════════════════════════════════════════════════════════════════
// FETCH
// ═══════════════════════════════════════════════════════════════════════════
$filter      = $_GET['filter']    ?? 'all';

$allowed = ['all','pending','assigned','approved','completed','declined','cancelled'];
if (!in_array($filter, $allowed)) $filter = 'all';

$range       = $_GET['range'] ?? '';
$filter_date = $_GET['filter_date'] ?? ($_GET['appt_date'] ?? '');
if (in_array($range, ['yesterday', 'today', 'tomorrow'])) {
    $filter_date = match($range) {
        'yesterday' => date('Y-m-d', strtotime('-1 day')),
        'today'     => date('Y-m-d'),
        'tomorrow'  => date('Y-m-d', strtotime('+1 day')),
    };
} elseif ($range === 'all') {
    $filter_date = '';
}
if ($filter_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date))
    $filter_date = '';

// Build WHERE clause using prepared statement values
$where_parts = ["(a.order_item_id IS NULL OR EXISTS (
    SELECT 1 FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE oi.id = a.order_item_id AND o.payment_status != 'pending_payment'
))"];
$bind_types = '';
$bind_vals  = [];


if (!empty($filter_date)) {
    $where_parts[] = "DATE(a.appointment_date) = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_date;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$appt_sql = "
    SELECT a.*, s.name AS service_name, s.price AS service_price, s.session_time,
           u.full_name, u.email, u.phone,
           a.approved_by_name, a.completed_by_name, a.declined_by_name,
           a.cancelled_by_name, a.rescheduled_by_name, a.rescheduled_at,
           (SELECT COALESCE(SUM(IFNULL(at3.people_handled,1)),0) FROM appointment_therapists at3 WHERE at3.appointment_id=a.id) AS therapist_count,
           (SELECT oi.order_id FROM order_items oi WHERE oi.id = a.order_item_id LIMIT 1) AS order_id
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    JOIN users u    ON a.user_id    = u.id
    $where_sql
    ORDER BY
        CASE a.status WHEN 'pending' THEN 0 WHEN 'assigned' THEN 1 ELSE 2 END ASC,
        a.appointment_date DESC
";

if (!empty($bind_vals)) {
    $stmt = $conn->prepare($appt_sql);
    $stmt->bind_param($bind_types, ...$bind_vals);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $appointments = $conn->query($appt_sql)->fetch_all(MYSQLI_ASSOC);
}

// Stats — use prepared statements
$stats = [];
foreach (['pending','assigned','approved','completed','declined','cancelled'] as $st) {
    $s = $conn->prepare("
        SELECT COUNT(*) AS c FROM appointments a
        WHERE a.status = ?
          AND (a.order_item_id IS NULL OR EXISTS (
              SELECT 1 FROM order_items oi JOIN orders o ON oi.order_id=o.id
              WHERE oi.id=a.order_item_id AND o.payment_status != 'pending_payment'
          ))
    ");
    $s->bind_param("s", $st); $s->execute();
    $stats[$st] = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();
}

// Time conflict detection
$conflict_appt_ids = [];
$conflict_result = $conn->query("
    SELECT a1.id AS id1, a2.id AS id2
    FROM appointments a1
    JOIN appointments a2
        ON  a1.service_id = a2.service_id
        AND a1.id < a2.id
        AND a1.status = 'pending'
        AND a2.status = 'pending'
        AND ABS(TIMESTAMPDIFF(MINUTE, a1.appointment_date, a2.appointment_date)) <= 30
");
while ($cr = $conflict_result->fetch_assoc()) {
    $conflict_appt_ids[$cr['id1']] = true;
    $conflict_appt_ids[$cr['id2']] = true;
}

$on_duty_therapists = $conn->query("
    SELECT t.id, t.full_name, t.specialties, ta.is_on_break, ta.time_out
    FROM therapists t
    JOIN therapist_attendance ta ON ta.therapist_id = t.id
    WHERE ta.duty_date = CURDATE()
    ORDER BY ta.rotation_order ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Qualified-therapist lookup for FUTURE appointments ──────────────────────
// Cached per service_id so repeated services on the same page don't re-query.
$_qualified_cache = [];
$get_qualified = function(int $service_id) use ($conn, &$_qualified_cache): array {
    if (isset($_qualified_cache[$service_id])) return $_qualified_cache[$service_id];
    $sid = intval($service_id);
    $result = $conn->query("
        SELECT DISTINCT t.id, t.full_name, t.specialties,
               ta.is_on_break, ta.time_out
        FROM therapists t
        LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
        WHERE t.is_generalist = 1
           OR EXISTS (SELECT 1 FROM therapist_specialty_services
                      WHERE therapist_id = t.id AND service_id = $sid)
           OR EXISTS (SELECT 1 FROM therapist_specialties ts
                      JOIN services s ON s.category_id = ts.category_id
                      WHERE ts.therapist_id = t.id AND s.id = $sid)
        ORDER BY t.full_name ASC
    ");
    $_qualified_cache[$service_id] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    return $_qualified_cache[$service_id];
};

$all_services_list = $conn->query("
    SELECT s.id, s.name, s.price,
           IFNULL(c.name,'Uncategorized') AS category_name
    FROM services s
    LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY c.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);
$services_by_cat = [];
foreach ($all_services_list as $sv) {
    $services_by_cat[$sv['category_name']][] = $sv;
}

// ── Service → qualified therapist map (drives Add Service therapist dropdown)
// Uses the existing $get_qualified closure + cache: at most 1 query per unique service_id.
$svc_qualified_map = [];
foreach ($all_services_list as $_sv) {
    $svc_qualified_map[(int)$_sv['id']] = array_values(array_map(
        fn($t) => [
            'id'       => (int)$t['id'],
            'name'     => $t['full_name'],
            'on_break' => (bool)$t['is_on_break'],
            'out'      => !empty($t['time_out']),
        ],
        $get_qualified((int)$_sv['id'])
    ));
}

$page_title  = 'Appointments';
$page_icon   = '📅';
$active_page = 'appointments';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" id="flash-msg" style="margin-bottom:1.5rem;">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.5rem;">
<?php $sdefs=['pending'=>['⏳','amber','Pending'],'assigned'=>['💆','','Assigned'],'approved'=>['✅','green','Approved'],'completed'=>['🎉','','Completed'],'declined'=>['❌','red','Declined']];
foreach ($sdefs as $st=>[$icon,$cls,$lbl]): ?>
<a href="appointments.php?filter=<?php echo $st; ?>" style="text-decoration:none;color:inherit;">
    <div class="stat-card <?php echo $cls; ?> <?php echo $filter===$st?'active':''; ?>">
        <div class="stat-icon"><?php echo $icon; ?></div>
        <div class="stat-number"><?php echo $stats[$st]; ?></div>
        <div class="stat-label"><?php echo $lbl; ?></div>
    </div>
</a>
<?php endforeach; ?>
</div>

<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
<?php $tabs=['all'=>'All','pending'=>'⏳ Pending','assigned'=>'💆 Assigned','approved'=>'✅ Approved','completed'=>'🎉 Completed','declined'=>'❌ Declined','cancelled'=>'🚫 Cancelled'];
foreach ($tabs as $val=>$label): ?>
<a href="appointments.php?filter=<?php echo $val; ?><?php echo $filter_date ? '&appt_date='.urlencode($filter_date) : ''; ?>"
   style="padding:0.4rem 1.1rem;border-radius:20px;font-size:0.82rem;font-weight:600;text-decoration:none;transition:all 0.15s;
          background:<?php echo $filter===$val?'var(--rust)':'var(--bg3)'; ?>;
          color:<?php echo $filter===$val?'#fff':'var(--gray)'; ?>;
          border:1px solid <?php echo $filter===$val?'var(--rust)':'var(--border2)'; ?>;">
    <?php echo $label; ?>
    <?php if ($val!=='all' && isset($stats[$val])): ?>
    <span style="background:rgba(255,255,255,0.22);padding:0.05rem 0.45rem;border-radius:20px;font-size:0.7rem;margin-left:0.2rem;"><?php echo $stats[$val]; ?></span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- ── DATE FILTER ─────────────────────────────────────────────────────────── -->
<form method="GET" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <label style="font-size:0.82rem;color:var(--gray);font-weight:500;white-space:nowrap;">📅 Appointment Date:</label>
    <?php
    $rd_today     = date('Y-m-d');
    $rd_yesterday = date('Y-m-d', strtotime('-1 day'));
    $rd_tomorrow  = date('Y-m-d', strtotime('+1 day'));
    ?>
    <a href="appointments.php?range=yesterday&filter=<?php echo htmlspecialchars($filter); ?>"
       class="btn btn-sm <?php echo $filter_date === $rd_yesterday ? 'btn-primary' : 'btn-secondary'; ?>"
       style="text-decoration:none;font-size:0.78rem;">Yesterday</a>
    <a href="appointments.php?range=today&filter=<?php echo htmlspecialchars($filter); ?>"
       class="btn btn-sm <?php echo $filter_date === $rd_today ? 'btn-primary' : 'btn-secondary'; ?>"
       style="text-decoration:none;font-size:0.78rem;">Today</a>
    <a href="appointments.php?range=tomorrow&filter=<?php echo htmlspecialchars($filter); ?>"
       class="btn btn-sm <?php echo $filter_date === $rd_tomorrow ? 'btn-primary' : 'btn-secondary'; ?>"
       style="text-decoration:none;font-size:0.78rem;">Tomorrow</a>
    <a href="appointments.php?range=all&filter=<?php echo htmlspecialchars($filter); ?>"
       class="btn btn-sm <?php echo empty($filter_date) ? 'btn-primary' : 'btn-secondary'; ?>"
       style="text-decoration:none;font-size:0.78rem;">All</a>
    <input type="date" name="appt_date"
           value="<?php echo htmlspecialchars($filter_date); ?>"
           style="padding:0.4rem 0.75rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($filter_date): ?>
    <a href="appointments.php?filter=<?php echo htmlspecialchars($filter); ?>" class="btn btn-secondary btn-sm">✕ Clear Date</a>
    <span style="font-size:0.8rem;color:var(--gold);font-weight:600;">Showing: <?php echo date('F d, Y', strtotime($filter_date)); ?></span>
    <?php endif; ?>
</form>

<?php
// ── Split fetched appointments into kanban groups ─────────────────────────────
$pending_rows  = array_values(array_filter($appointments, fn($a) => $a['status'] === 'pending'));
$assigned_rows = array_values(array_filter($appointments, fn($a) => $a['status'] === 'assigned'));
$approved_rows = array_values(array_filter($appointments, fn($a) => $a['status'] === 'approved'));
$history_rows  = array_values(array_filter($appointments, fn($a) => in_array($a['status'], ['completed','declined','cancelled'])));

// ── Card renderer closure ─────────────────────────────────────────────────────
$render_card = function(array $a) use ($conn, $on_duty_therapists, $services_by_cat, $get_qualified, $filter, $conflict_appt_ids): void {
    $status  = $a['status'];
    $people  = max(1,intval($a['people_count']));
    $appt_id = $a['id'];
    $t_count          = (int)$a['therapist_count'];
    $slots_left_inline = max(0, $people - $t_count);
    $needs_therapist  = ($status === 'pending' && $t_count < $people);

    $badge=['pending'=>['#FEF3C7','#92400E','⏳ Pending'],'assigned'=>['#cfe2ff','#084298','💆 Assigned'],'approved'=>['#D1FAE5','#065F46','✅ Approved'],'completed'=>['#E0F2FE','#0C4A6E','🎉 Completed'],'declined'=>['#FEE2E2','#991B1B','❌ Declined'],'cancelled'=>['#F3F4F6','#374151','🚫 Cancelled']];
    [$bbg,$bfg,$blabel]=$badge[$status]??['#e2e3e5','#41464b',ucfirst($status)];
    $bdr=['pending'=>'#f59e0b','assigned'=>'#0d6efd','approved'=>'var(--green)','completed'=>'#0891b2','declined'=>'#dc3545','cancelled'=>'#6b7280'][$status]??'var(--border2)';

    $pm_row=['order_id'=>0,'payment_method'=>'onsite','payment_status'=>'unpaid','paymongo_method'=>null,
             'total_amount'=>0,'discount_type'=>'none','discount_amount'=>0,'final_amount'=>0];
    if (!empty($a['order_item_id'])) {
        $pm=$conn->prepare("
            SELECT o.id AS order_id, o.payment_method, o.payment_status, o.paymongo_method,
                   o.total_amount, o.discount_type, o.discount_amount, o.final_amount
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.id = ? LIMIT 1
        ");
        $pm->bind_param("i",$a['order_item_id']); $pm->execute();
        $pm_row=$pm->get_result()->fetch_assoc()??$pm_row; $pm->close();
    }

    $ts=$conn->prepare("SELECT at2.id AS at_id,at2.commission,at2.notes,t.id AS therapist_id,t.full_name,t.specialties FROM appointment_therapists at2 JOIN therapists t ON at2.therapist_id=t.id WHERE at2.appointment_id=? ORDER BY at2.assigned_at ASC");
    $ts->bind_param("i",$appt_id); $ts->execute();
    $assigned_therapists=$ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close();

    $ex=$conn->prepare("SELECT aes.*, s.name AS svc_name, t.full_name AS therapist_name FROM appointment_extra_services aes JOIN services s ON s.id = aes.service_id LEFT JOIN therapists t ON t.id = aes.therapist_id WHERE aes.appointment_id = ? ORDER BY aes.created_at ASC");
    $ex->bind_param("i",$appt_id); $ex->execute();
    $extra_services=$ex->get_result()->fetch_all(MYSQLI_ASSOC); $ex->close();

    $assigned_ids  = array_column($assigned_therapists, 'therapist_id') ?: [0];
    $appt_is_today = (date('Y-m-d', strtotime($a['appointment_date'])) === date('Y-m-d'));
    if ($appt_is_today) {
        $available = array_filter($on_duty_therapists, fn($t) => !in_array($t['id'], $assigned_ids));
    } else {
        $available = array_filter($get_qualified((int)$a['service_id']), fn($t) => !in_array($t['id'], $assigned_ids));
    }
?>

<div class="appt-card"
     data-appt-id="<?php echo $appt_id; ?>"
     data-search="<?php echo htmlspecialchars(strtolower($a['full_name'].' '.$a['service_name'].' '.date('M j Y g:i a', strtotime($a['appointment_date'])))); ?>"
     style="border-left:3.5px solid <?php echo $bdr; ?>;">

    <!-- ── CARD HEADER (always visible, click to expand) ─────────────────── -->
    <div class="appt-card-header" onclick="toggleApptCard(this)">
        <div style="min-width:0;flex:1;">
            <p class="appt-name">
                <?php echo htmlspecialchars($a['full_name']); ?>
                <span style="background:<?php echo $bbg;?>;color:<?php echo $bfg;?>;padding:0.15rem 0.5rem;border-radius:20px;font-size:0.67rem;font-weight:700;vertical-align:middle;margin-left:0.35rem;"><?php echo $blabel; ?></span>
                <?php if (isset($conflict_appt_ids[$appt_id])): ?><span style="background:#fef3c7;color:#92400e;padding:0.1rem 0.4rem;border-radius:20px;font-size:0.64rem;font-weight:700;border:1px solid #fbbf24;margin-left:0.2rem;animation:pulse 2s infinite;">⚠️ Conflict</span><?php endif; ?>
            </p>
            <p class="appt-svc"><?php echo htmlspecialchars($a['service_name']); ?></p>
            <p class="appt-time">
                🕐 <?php echo date('M j, g:i A', strtotime($a['appointment_date'])); ?>
                <?php if (!empty($assigned_therapists)): ?> &middot; 💆 <?php echo htmlspecialchars($assigned_therapists[0]['full_name']); ?><?php if (count($assigned_therapists) > 1): ?> +<?php echo count($assigned_therapists)-1; ?> more<?php endif; ?><?php endif; ?>
                <?php if ($needs_therapist): ?> &middot; <span style="color:#92400e;font-weight:700;">⚠️ Needs therapist</span><?php endif; ?>
            </p>
        </div>
        <i class="appt-chev">▾</i>
    </div>

    <!-- ── CARD DETAIL (hidden until expanded) ───────────────────────────── -->
    <div class="appt-card-detail">

    <!-- Booking info & status badges -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
        <div>
            <div style="font-size:1.05rem;font-weight:800;color:var(--gold);"><?php echo htmlspecialchars($a['service_name']); ?></div>
            <div style="font-size:0.74rem;color:var(--gray);margin-top:0.18rem;">
                Booked <?php echo date('M d, Y — h:i A',strtotime($a['created_at'])); ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;flex-wrap:wrap;">
            <?php if (isset($conflict_appt_ids[$appt_id])): ?>
            <span style="background:#fef3c7;color:#92400e;padding:0.2rem 0.75rem;border-radius:20px;font-size:0.72rem;font-weight:700;border:1px solid #fbbf24;animation:pulse 2s infinite;">⚠️ Time Conflict</span>
            <?php endif; ?>
            <?php if ($needs_therapist): ?>
            <span style="background:#fff3cd;color:#664d03;padding:0.2rem 0.75rem;border-radius:20px;font-size:0.72rem;font-weight:700;border:1px solid #ffc107;">⚠️ Assign <?php echo $people; ?> therapist<?php echo $people > 1 ? 's' : ''; ?> first</span>
            <?php elseif ($status==='pending' && $t_count > 0 && $t_count < $people): ?>
            <span style="background:#fff3cd;color:#664d03;padding:0.2rem 0.75rem;border-radius:20px;font-size:0.72rem;font-weight:700;border:1px solid #ffc107;">⚠️ <?php echo $t_count; ?>/<?php echo $people; ?> therapists — need <?php echo $people - $t_count; ?> more</span>
            <?php elseif ($status==='pending' && $t_count>=$people): ?>
            <span style="background:#d1e7dd;color:#0a3622;padding:0.2rem 0.75rem;border-radius:20px;font-size:0.72rem;font-weight:700;">💆 All <?php echo $people; ?> therapist<?php echo $people>1?'s':''; ?> assigned — Ready to approve</span>
            <?php endif; ?>
            <span style="background:<?php echo $bbg; ?>;color:<?php echo $bfg; ?>;padding:0.28rem 0.9rem;border-radius:20px;font-size:0.78rem;font-weight:700;"><?php echo $blabel; ?></span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:0.8rem 1.5rem;padding:1rem;background:var(--bg3);border-radius:10px;margin-bottom:1rem;border:1px solid var(--border2);">
        <div>
            <div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">👤 Customer</div>
            <div style="font-size:0.88rem;color:var(--brown);font-weight:600;"><?php echo htmlspecialchars($a['full_name']); ?></div>
            <div style="font-size:0.74rem;color:var(--gray);"><?php echo htmlspecialchars($a['email']); ?></div>
            <?php if (!empty($a['phone'])): ?>
            <div style="font-size:0.74rem;color:var(--brown);margin-top:0.1rem;">
                <a href="tel:<?php echo htmlspecialchars($a['phone']); ?>"
                   style="color:var(--brown);text-decoration:none;font-weight:600;">
                    📞 <?php echo htmlspecialchars($a['phone']); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div><div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">📅 Date</div><div style="font-size:0.88rem;color:var(--brown);font-weight:600;"><?php echo date('F d, Y',strtotime($a['appointment_date'])); ?></div><div style="font-size:0.74rem;color:var(--gray);"><?php echo date('h:i A',strtotime($a['appointment_date'])); ?></div></div>
        <div><div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">👥 People</div><div style="font-size:0.88rem;color:var(--brown);font-weight:600;"><?php echo $people; ?> person<?php echo $people>1?'s':''; ?></div></div>
        <div><div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">⏱ Duration</div><div style="font-size:0.88rem;color:var(--brown);font-weight:600;"><?php echo $a['session_time']; ?> mins</div></div>
        <?php
        // ── Pricing breakdown ─────────────────────────────────────────────────
        $_orig_price    = floatval($pm_row['total_amount'] ?: $a['service_price']);
        $_disc_type     = $pm_row['discount_type']   ?? 'none';
        $_disc_amt      = floatval($pm_row['discount_amount'] ?? 0);
        $_final_amt     = floatval($pm_row['final_amount']   ?? 0);
        $_has_discount  = ($_disc_type !== 'none' && $_disc_type !== '');
        // If final_amount not yet set (pending approval) show full price
        $_show_final    = $_has_discount && $_disc_amt > 0;

        $_disc_labels = [
            'senior'  => ['👴', 'Senior (20%)'],
            'pwd'     => ['♿', 'PWD (20%)'],
            'voucher' => ['🎟️', 'Voucher'],
            'employee'=> ['🪪', 'Employee'],
        ];
        [$_dico, $_dlbl] = $_disc_labels[$_disc_type] ?? ['🎟️', ucfirst($_disc_type)];
        ?>
        <div>
            <div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">💰 Price</div>
            <?php if ($_has_discount): ?>
                <?php if ($_show_final): ?>
                <!-- Has discount with known amount -->
                <div style="font-size:0.78rem;color:var(--gray);text-decoration:line-through;line-height:1.3;">
                    ₱<?php echo number_format($_orig_price, 2); ?>
                </div>
                <div style="font-size:1rem;color:var(--green);font-weight:800;line-height:1.3;">
                    ₱<?php echo number_format($_final_amt, 2); ?>
                </div>
                <span style="display:inline-flex;align-items:center;gap:0.2rem;margin-top:0.2rem;
                             font-size:0.68rem;font-weight:700;padding:0.1rem 0.5rem;
                             border-radius:20px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
                    <?php echo $_dico . ' −₱' . number_format($_disc_amt, 2); ?>
                </span>
                <?php else: ?>
                <!-- Discount type declared but amount not yet applied (pending onsite confirmation) -->
                <div style="font-size:0.88rem;color:var(--gold);font-weight:700;line-height:1.3;">
                    ₱<?php echo number_format($_orig_price, 2); ?>
                </div>
                <span style="display:inline-flex;align-items:center;gap:0.2rem;margin-top:0.2rem;
                             font-size:0.68rem;font-weight:700;padding:0.1rem 0.5rem;
                             border-radius:20px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
                    <?php echo $_dico . ' ' . $_dlbl; ?> — TBD
                </span>
                <?php endif; ?>
            <?php else: ?>
                <!-- No discount -->
                <div style="font-size:0.88rem;color:var(--gold);font-weight:700;">
                    ₱<?php echo number_format($_orig_price, 2); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // Resolve the actual payment method label for display
        $_raw_method  = $pm_row['payment_method']  ?? 'onsite';
        $_raw_pm      = $pm_row['paymongo_method']  ?? null;
        $_pay_status  = $pm_row['payment_status']   ?? 'unpaid';

        // If paid via PayMongo, the actual method (gcash/maya/card) is in paymongo_method
        $_display_method = $_raw_pm ?: $_raw_method;

        $_method_labels = [
            'cash'       => ['💵', 'Cash'],
            'gcash'      => ['📱', 'GCash'],
            'maya'       => ['💜', 'Maya'],
            'bpi_debit'  => ['🏦', 'BPI Debit'],
            'bpi_credit' => ['💳', 'BPI Credit'],
            'qrph'       => ['📷', 'QRPH'],
            'bank'       => ['🏦', 'Bank Transfer'],
            'card'       => ['💳', 'Card'],
            'online'     => ['💳', 'Online'],
            'onsite'     => ['🏪', 'Onsite'],
        ];
        [$_mico, $_mlbl] = $_method_labels[$_display_method] ?? ['💰', ucfirst($_display_method)];

        $_channel_label = ($_raw_method === 'online') ? '🌐 Online' : '🏪 Onsite';
        $_channel_color = ($_raw_method === 'online') ? '#0070f3' : '#3B2A1A';

        $_status_map = [
            'paid'            => ['✅ Paid',            '#0a3622','#d1e7dd'],
            'unpaid'          => ['⏳ Unpaid',           '#664d03','#fff3cd'],
            'pending_payment' => ['💳 Awaiting Payment', '#084298','#cfe2ff'],
            'refunded'        => ['↩️ Refunded',         '#842029','#f8d7da'],
        ];
        [$_slbl, $_sfg, $_sbg] = $_status_map[$_pay_status] ?? [ucfirst($_pay_status), '#555', '#eee'];
        ?>
        <div>
            <div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">💳 Payment</div>
            <!-- Channel: Onsite / Online -->
            <div style="font-size:0.72rem;font-weight:600;color:<?php echo $_channel_color; ?>;margin-bottom:0.18rem;"><?php echo $_channel_label; ?></div>
            <!-- Specific method -->
            <div style="font-size:0.88rem;color:var(--brown);font-weight:700;"><?php echo $_mico . ' ' . $_mlbl; ?></div>
            <!-- Payment status badge -->
            <span style="display:inline-block;margin-top:0.22rem;font-size:0.68rem;font-weight:700;
                         padding:0.1rem 0.5rem;border-radius:20px;
                         background:<?php echo $_sbg; ?>;color:<?php echo $_sfg; ?>;">
                <?php echo $_slbl; ?>
            </span>
        </div>
        <div><div style="font-size:0.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.22rem;">🏷️ Type</div><div style="font-size:0.88rem;color:var(--brown);font-weight:600;"><?php echo $a['service_type']==='home'?'🏠 Home':'🏢 On-site'; ?></div><?php if($a['service_type']==='home'&&!empty($a['home_address'])): ?><div style="font-size:0.74rem;color:var(--gray);"><?php echo htmlspecialchars($a['home_address']); ?></div><?php endif; ?></div>
    </div>

    <!-- ══ THERAPIST SECTION ══════════════════════════════════════════════ -->
    <?php if (in_array($status,['pending','assigned','approved'])): ?>
    <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:1rem 1.1rem;margin-bottom:1rem;">
        <div style="font-size:0.78rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
            💆 Therapist Assignment
            <span style="font-weight:400;color:<?php echo $t_count>=$people?'var(--green)':'var(--gold)'; ?>;">
                — <?php echo $t_count; ?>/<?php echo $people; ?> filled
            </span>
        </div>

        <?php if (!empty($assigned_therapists)): ?>
        <div style="display:flex;flex-direction:column;gap:0.45rem;margin-bottom:0.85rem;">
        <?php foreach ($assigned_therapists as $at): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.6rem;padding:0.5rem 0.75rem;background:var(--bg2);border-radius:8px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:0.55rem;">
                    <div style="width:30px;height:30px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--rust));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:0.82rem;"><?php echo strtoupper(substr($at['full_name'],0,1)); ?></div>
                    <div>
                        <div style="font-size:0.85rem;font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($at['full_name']); ?></div>
                        <div style="font-size:0.7rem;color:var(--gray);"><?php echo htmlspecialchars($at['specialties']?:'General'); ?><?php if($at['notes']): ?> &nbsp;·&nbsp; 📝 <?php echo htmlspecialchars($at['notes']); ?><?php endif; ?></div>
                    </div>
                </div>
                <?php if (in_array($status,['pending','assigned'])): ?>
                <a href="appointments.php?remove_assign=<?php echo $at['at_id']; ?>&filter=<?php echo $filter; ?>"
                   onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($at['full_name'])); ?>?')"
                   style="font-size:0.72rem;padding:0.22rem 0.55rem;background:rgba(220,53,69,0.15);color:#ff6b7a;border-radius:6px;text-decoration:none;border:1px solid rgba(220,53,69,0.25);flex-shrink:0;">✕</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // Preferred therapist notice
        $pref_th_name = null;
        if (!empty($a['preferred_therapist_id'])) {
            $pref_stmt = $conn->prepare("SELECT full_name FROM therapists WHERE id = ? LIMIT 1");
            $pref_stmt->bind_param("i", $a['preferred_therapist_id']);
            $pref_stmt->execute();
            $pref_row = $pref_stmt->get_result()->fetch_assoc();
            $pref_stmt->close();
            $pref_th_name = $pref_row['full_name'] ?? null;
        }
        ?>
        <?php if ($pref_th_name): ?>
        <div style="margin-top:0.75rem;padding:0.6rem 0.9rem;background:#fefce8;border:1px solid #fde68a;
                    border-radius:9px;font-size:0.8rem;color:#78350f;font-family:'DM Sans',sans-serif;">
            ⭐ Customer's preferred therapist: <strong><?php echo htmlspecialchars($pref_th_name); ?></strong>
        </div>
        <?php endif; ?>

        <?php if ($status === 'pending'): ?>
        <div style="margin-top:0.85rem;padding:1rem;background:rgba(201,106,44,0.06);
                    border:1px solid rgba(201,106,44,0.2);border-radius:10px;">
            <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.75rem;
                        display:flex;align-items:center;justify-content:space-between;">
                <span>💆 Therapist Assignment — <?php echo $t_count; ?>/<?php echo $people; ?> covered</span>
                <?php if ($t_count < $people): ?>
                <span style="font-size:0.7rem;background:#fff3cd;color:#664d03;padding:0.15rem 0.5rem;
                             border-radius:20px;font-weight:700;">Required before approving</span>
                <?php endif; ?>
            </div>

            <form method="POST" style="display:grid;gap:0.55rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"  value="save_per_person_inline">
                <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">

                <?php
                $inline_date_esc = $conn->real_escape_string($a['appointment_date']);
                $inline_svc_id   = (int)$a['service_id'];
                $inline_appt_id  = (int)$appt_id;

                $inline_therapists_res = $conn->query("
                    SELECT t.id, t.full_name,
                        (SELECT COUNT(*) FROM therapist_specialty_services
                         WHERE therapist_id=t.id AND service_id={$inline_svc_id}) +
                        (SELECT COUNT(*) FROM therapist_specialties ts2
                         JOIN services s2 ON s2.category_id=ts2.category_id
                         WHERE ts2.therapist_id=t.id AND s2.id={$inline_svc_id}) AS has_specialty,
                        (SELECT COUNT(*) FROM therapist_attendance ta
                         WHERE ta.therapist_id=t.id
                           AND ta.duty_date=DATE('{$inline_date_esc}')
                           AND ta.time_out IS NULL) AS on_duty
                    FROM therapists t
                    ORDER BY has_specialty DESC, t.full_name ASC
                ");
                $inline_therapists = $inline_therapists_res ? $inline_therapists_res->fetch_all(MYSQLI_ASSOC) : [];

                $ias = $conn->prepare("SELECT therapist_id, IFNULL(people_handled,1) AS ph FROM appointment_therapists WHERE appointment_id=? ORDER BY id ASC");
                $ias->bind_param("i", $inline_appt_id); $ias->execute();
                $inline_assigned = $ias->get_result()->fetch_all(MYSQLI_ASSOC); $ias->close();

                $prefill_map = []; $idx = 1;
                foreach ($inline_assigned as $row) {
                    for ($x = 0; $x < (int)$row['ph']; $x++) {
                        $prefill_map[$idx++] = (int)$row['therapist_id'];
                    }
                }

                for ($p = 1; $p <= $people; $p++):
                    $pre = $prefill_map[$p] ?? 0;
                ?>
                <div style="display:grid;grid-template-columns:72px 1fr;align-items:center;gap:0.5rem;">
                    <span style="font-size:0.78rem;font-weight:600;color:var(--brown);">👤 Person <?php echo $p; ?></span>
                    <select name="therapist_ids[]" required
                            style="width:100%;padding:0.4rem 0.6rem;border:1px solid #c8a46e;
                                   border-radius:7px;background:#ffffff;color:#1a1a1a;font-size:0.82rem;">
                        <option value="" style="color:#1a1a1a;">— Select therapist —</option>
                        <?php foreach ($inline_therapists as $t):
                            $selected  = ($t['id'] == $pre) ? 'selected' : '';
                            $qualified = $t['has_specialty'] > 0;
                            $on_duty_t = $t['on_duty'] > 0;
                            $label     = htmlspecialchars($t['full_name']);
                            if (!$qualified)    $label .= ' — ❌ Not qualified';
                            elseif (!$on_duty_t) $label .= ' — Off duty';
                        ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $selected; ?>
                                style="color:<?php echo $qualified ? '#1a1a1a' : '#999999'; ?>;">
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endfor; ?>

                <?php if (is_cashier()): ?>
                <div style="margin-top:0.4rem;">
                    <label style="font-size:0.75rem;color:var(--gray);display:block;margin-bottom:3px;">Your 4-digit PIN</label>
                    <input type="password" name="pin" maxlength="4" placeholder="••••" required
                           style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;
                                  background:var(--bg2);color:var(--brown);font-size:0.9rem;letter-spacing:0.25em;text-align:center;box-sizing:border-box;">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm"
                        style="margin-top:0.25rem;font-size:0.8rem;padding:0.4rem 0.9rem;width:100%;">
                    💾 Save Therapist Assignment
                </button>
            </form>

            <a href="assign_therapist.php?appt_id=<?php echo $appt_id; ?>"
               style="display:block;margin-top:0.6rem;font-size:0.72rem;color:var(--gold);
                      text-align:center;text-decoration:none;">
                ↗ Advanced assignment (separate page)
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($status, ['completed','declined']) && !empty($assigned_therapists)): ?>
    <div style="margin-bottom:1rem;padding:0.85rem 1rem;background:var(--bg3);border-radius:10px;border:1px solid var(--border2);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <!-- Therapists -->
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.78rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
                    💆 Therapist<?php echo count($assigned_therapists)>1?'s':''; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                <?php foreach ($assigned_therapists as $at): ?>
                    <div style="display:flex;align-items:center;gap:0.55rem;padding:0.45rem 0.75rem;background:var(--bg2);border-radius:8px;border:1px solid var(--border);">
                        <div style="width:26px;height:26px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--rust));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:0.78rem;"><?php echo strtoupper(substr($at['full_name'],0,1)); ?></div>
                        <span style="font-size:0.85rem;font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($at['full_name']); ?></span>
                        <?php if ($at['notes']): ?><span style="font-size:0.7rem;color:var(--gray);">— <?php echo htmlspecialchars($at['notes']); ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <!-- Audit Trail -->
            <div style="flex-shrink:0;min-width:180px;">
                <div style="font-size:0.78rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.6rem;">📋 Action Log</div>
                <div style="display:flex;flex-direction:column;gap:0.35rem;">
                    <?php if (!empty($a['rescheduled_by_name'])): ?>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <span style="font-weight:700;color:#0891b2;">📅 Rescheduled by:</span><br>
                        <span style="color:var(--brown);"><?php echo htmlspecialchars($a['rescheduled_by_name']); ?></span>
                        <?php if (!empty($a['rescheduled_at'])): ?>
                        <span style="color:var(--gray);font-size:0.68rem;"> · <?php echo date('M d, h:i A', strtotime($a['rescheduled_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($a['approved_by_name'])): ?>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <span style="font-weight:700;color:var(--green);">✅ Approved by:</span><br>
                        <span style="color:var(--brown);"><?php echo htmlspecialchars($a['approved_by_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($a['completed_by_name'])): ?>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <span style="font-weight:700;color:#0d6efd;">🎉 Completed by:</span><br>
                        <span style="color:var(--brown);"><?php echo htmlspecialchars($a['completed_by_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($a['declined_by_name'])): ?>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <span style="font-weight:700;color:#dc3545;">❌ Declined by:</span><br>
                        <span style="color:var(--brown);"><?php echo htmlspecialchars($a['declined_by_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($a['cancelled_by_name'])): ?>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <span style="font-weight:700;color:#6b7280;">🚫 Cancelled by:</span><br>
                        <span style="color:var(--brown);"><?php echo htmlspecialchars($a['cancelled_by_name']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit trail for active appointments (approved/assigned) -->
    <?php
    $has_audit = !empty($a['rescheduled_by_name']) || !empty($a['approved_by_name']);
    if (in_array($status, ['approved','assigned']) && $has_audit):
    ?>
    <div style="margin-bottom:1rem;padding:0.6rem 1rem;background:var(--bg3);border-radius:8px;border:1px solid var(--border2);display:flex;flex-wrap:wrap;gap:1rem;">
        <div style="font-size:0.72rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;width:100%;margin-bottom:0.1rem;">📋 Action Log</div>
        <?php if (!empty($a['rescheduled_by_name'])): ?>
        <div style="font-size:0.75rem;">
            <span style="font-weight:700;color:#0891b2;">📅 Rescheduled by:</span>
            <span style="color:var(--brown);margin-left:0.3rem;"><?php echo htmlspecialchars($a['rescheduled_by_name']); ?></span>
            <?php if (!empty($a['rescheduled_at'])): ?>
            <span style="color:var(--gray);font-size:0.68rem;"> · <?php echo date('M d, h:i A', strtotime($a['rescheduled_at'])); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($a['approved_by_name'])): ?>
        <div style="font-size:0.75rem;">
            <span style="font-weight:700;color:var(--green);">✅ Approved by:</span>
            <span style="color:var(--brown);margin-left:0.3rem;"><?php echo htmlspecialchars($a['approved_by_name']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Audit trail for cancelled appointments -->
    <?php if ($status === 'cancelled'): ?>
    <div style="margin-bottom:1rem;padding:0.6rem 1rem;background:var(--bg3);border-radius:8px;border:1px solid var(--border2);display:flex;flex-wrap:wrap;gap:1rem;">
        <div style="font-size:0.72rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;width:100%;margin-bottom:0.1rem;">📋 Action Log</div>
        <?php if (!empty($a['rescheduled_by_name'])): ?>
        <div style="font-size:0.75rem;">
            <span style="font-weight:700;color:#0891b2;">📅 Rescheduled by:</span>
            <span style="color:var(--brown);margin-left:0.3rem;"><?php echo htmlspecialchars($a['rescheduled_by_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($a['approved_by_name'])): ?>
        <div style="font-size:0.75rem;">
            <span style="font-weight:700;color:var(--green);">✅ Approved by:</span>
            <span style="color:var(--brown);margin-left:0.3rem;"><?php echo htmlspecialchars($a['approved_by_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($a['cancelled_by_name'])): ?>
        <div style="font-size:0.75rem;">
            <span style="font-weight:700;color:#6b7280;">🚫 Cancelled by:</span>
            <span style="color:var(--brown);margin-left:0.3rem;"><?php echo htmlspecialchars($a['cancelled_by_name']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ EXTRA SERVICES ════════════════════════════════════════════════ -->
    <?php if (in_array($status, ['pending','approved','assigned','completed'])): ?>
    <div style="margin-bottom:1rem;">
        <?php if (!empty($extra_services)): ?>
        <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:0.85rem 1rem;margin-bottom:0.6rem;">
            <div style="font-size:0.75rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.65rem;">➕ Extra Services (<?php echo count($extra_services); ?>)</div>
            <?php $extra_total = 0; foreach ($extra_services as $es): $extra_total += floatval($es['charged_price']); $pay_icons = ['cash'=>'💵','card'=>'💳','qrph'=>'📱']; ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.5rem 0.6rem;margin-bottom:0.3rem;background:var(--bg2);border-radius:8px;border:1px solid var(--border);flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:0.55rem;flex:1;min-width:0;">
                    <div style="width:28px;height:28px;border-radius:8px;flex-shrink:0;background:linear-gradient(135deg,#0d6efd22,#0d6efd44);display:flex;align-items:center;justify-content:center;font-size:0.75rem;">➕</div>
                    <div style="min-width:0;">
                        <div style="font-size:0.85rem;font-weight:600;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($es['svc_name']); ?></div>
                        <div style="font-size:0.7rem;color:var(--gray);">
                        <?php echo htmlspecialchars($es['person_label']); ?>
                        <?php if (!empty($es['therapist_name'])): ?> &nbsp;·&nbsp; 💆 <?php echo htmlspecialchars($es['therapist_name']); ?><?php endif; ?>
                        &nbsp;·&nbsp; <?php echo $pay_icons[$es['payment_method']] ?? '💵'; ?> <?php echo ucfirst($es['payment_method']); ?>
                        <?php if (!empty($es['commission']) && floatval($es['commission']) > 0): ?> &nbsp;·&nbsp; Comm: <strong>₱<?php echo number_format(floatval($es['commission']), 2); ?></strong><?php endif; ?>
                        <?php if ($es['notes']): ?> &nbsp;·&nbsp; 📝 <?php echo htmlspecialchars($es['notes']); ?><?php endif; ?>
                    </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                    <span style="font-weight:700;color:var(--gold);font-size:0.9rem;">₱<?php echo number_format($es['charged_price'],2); ?></span>
                    <?php if (in_array($status,['approved','assigned'])): ?>
                    <a href="appointments.php?remove_extra=<?php echo $es['id']; ?>&filter=<?php echo $filter; ?>"
                       onclick="return confirm('Remove this extra service?')"
                       style="font-size:0.68rem;padding:0.15rem 0.45rem;background:rgba(220,53,69,0.12);color:#ff6b7a;border-radius:5px;text-decoration:none;border:1px solid rgba(220,53,69,0.2);">✕</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="text-align:right;font-size:0.78rem;color:var(--gray);padding-top:0.5rem;border-top:1px solid var(--border2);margin-top:0.3rem;">Extra services total: <strong style="color:var(--gold);">₱<?php echo number_format($extra_total,2); ?></strong></div>
        </div>
        <?php endif; ?>

        <?php if ($status === 'approved'): ?>
        <button type="button" onclick="toggleAddService(<?php echo $appt_id; ?>)"
                style="padding:0.38rem 0.9rem;border-radius:7px;border:1.5px dashed var(--gold);background:rgba(201,106,44,0.06);color:var(--gold);font-size:0.82rem;font-weight:700;cursor:pointer;transition:all .15s;"
                onmouseover="this.style.background='rgba(201,106,44,0.12)'"
                onmouseout="this.style.background='rgba(201,106,44,0.06)'">
            ➕ Add Service
        </button>

        <div id="addservice-<?php echo $appt_id; ?>" style="display:none;margin-top:0.75rem;padding:1.1rem;background:var(--bg3);border-radius:10px;border:1px solid var(--border2);">
            <div style="font-size:0.78rem;font-weight:700;color:var(--brown);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.85rem;display:flex;justify-content:space-between;">
                <span>➕ Add Extra Service</span>
                <?php $rate_labels = ['regular'=>'Regular','home'=>'Home','hotel'=>'Hotel','influencer'=>'Influencer']; $rt = $a['rate_type'] ?? 'regular'; ?>
                <span style="font-size:0.7rem;background:var(--bg2);padding:0.15rem 0.55rem;border-radius:20px;color:var(--gray);font-weight:400;text-transform:none;">Rate: <?php echo $rate_labels[$rt] ?? 'Regular'; ?></span>
            </div>
            <form method="POST" data-extra-svc="1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"  value="add_extra_service">
                <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.73rem;color:var(--gray);font-weight:600;display:block;margin-bottom:3px;">For which person?</label>
                    <select name="person_label" style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.85rem;">
                        <?php for ($pi = 1; $pi <= $people; $pi++): ?>
                        <option value="Person <?php echo $pi; ?>">Person <?php echo $pi; ?><?php if ($pi === 1): ?> (Primary)<?php endif; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.73rem;color:var(--gray);font-weight:600;display:block;margin-bottom:4px;">Select Service <span style="color:var(--rust);">*</span></label>
                    <select name="extra_svc_id" id="extra-svc-<?php echo $appt_id; ?>" required onchange="previewExtraPrice(this, <?php echo $appt_id; ?>)"
                            style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.85rem;">
                        <option value="">— Select a service —</option>
                        <?php foreach ($services_by_cat as $cat => $svcs): ?>
                        <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                            <?php foreach ($svcs as $sv): ?>
                            <option value="<?php echo $sv['id']; ?>" data-price="<?php echo $sv['price']; ?>"><?php echo htmlspecialchars($sv['name']); ?> — ₱<?php echo number_format($sv['price'],2); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span id="extra-svc-err-<?php echo $appt_id; ?>" style="font-size:0.68rem;color:var(--rust);margin-top:2px;display:none;"></span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.73rem;color:var(--gray);font-weight:600;display:block;margin-bottom:3px;">Charged Price (₱) <span style="color:var(--rust);">*</span></label>
                        <input type="number" name="extra_price" id="extra-price-input-<?php echo $appt_id; ?>"
                               step="0.01" min="0" placeholder="0.00" required
                               oninput="validateExtraForm(<?php echo $appt_id; ?>)"
                               style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                        <div id="extra-price-hint-<?php echo $appt_id; ?>" style="font-size:0.68rem;color:var(--gray);margin-top:2px;"></div>
                        <span id="extra-price-err-<?php echo $appt_id; ?>" style="font-size:0.68rem;color:var(--rust);margin-top:2px;display:none;"></span>
                    </div>
                    <div>
                        <label style="font-size:0.73rem;color:var(--gray);font-weight:600;display:block;margin-bottom:3px;">Therapist (optional)</label>
                        <select name="extra_therapist" id="extra-therapist-<?php echo $appt_id; ?>"
                                required onchange="validateExtraForm(<?php echo $appt_id; ?>)"
                                style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.83rem;">
                        </select>
                        <div id="extra-therapist-note-<?php echo $appt_id; ?>" style="font-size:0.68rem;color:var(--gray);margin-top:2px;display:none;"></div>
                        <span id="extra-therapist-err-<?php echo $appt_id; ?>" style="font-size:0.68rem;color:var(--rust);margin-top:2px;display:none;"></span>
                    </div>
                </div>
                <div style="margin-bottom:0.85rem;">
                    <label style="font-size:0.73rem;color:var(--gray);font-weight:600;display:block;margin-bottom:3px;">Notes (optional)</label>
                    <input type="text" name="extra_notes" placeholder="e.g. VIP guest, specific request..."
                           style="width:100%;padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.83rem;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:0.85rem;padding:0.6rem 0.8rem;background:rgba(201,106,44,0.06);border:1px dashed var(--gold);border-radius:8px;font-size:0.75rem;color:#78350f;">
                    💡 Payment for extra services is collected when you mark the session complete.
                </div>
                <div style="display:flex;gap:0.6rem;">
                    <button type="submit" id="extra-confirm-<?php echo $appt_id; ?>" class="btn btn-primary btn-sm" disabled style="opacity:0.5;cursor:not-allowed;">✅ Confirm Add Service</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddService(<?php echo $appt_id; ?>)">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ACTION BUTTONS ═════════════════════════════════════════════════ -->
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;">
        <?php if ($status==='pending'): ?>
            <?php if ($t_count < $people): ?>
            <button disabled title="Assign <?php echo $people; ?> therapist<?php echo $people>1?'s':''; ?> first"
                    style="opacity:0.4;cursor:not-allowed;padding:0.4rem 0.9rem;background:#198754;color:#fff;border:none;border-radius:7px;font-size:0.82rem;font-weight:600;">
                Assign Therapist (<?php echo $t_count; ?>/<?php echo $people; ?> therapists assigned)
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success btn-sm"
                    onclick="openDiscountModal(<?php echo $appt_id; ?>,'<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>',<?php echo ($pm_row['payment_method']!=='onsite'&&$pm_row['payment_status']==='paid')?'true':'false'; ?>,'<?php echo htmlspecialchars(addslashes($pm_row['payment_method']??'onsite')); ?>')">
                Assign Therapist
            </button>
            <?php endif; ?>
            <form method="POST" style="margin:0;display:flex;align-items:center;gap:0.4rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"  value="decline">
                <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
                <?php if (is_cashier()): ?>
                <input type="password" name="pin" maxlength="4" inputmode="numeric"
                       placeholder="PIN" required
                       style="width:60px;padding:0.32rem 0.4rem;border:1px solid var(--border2);
                              border-radius:6px;font-size:0.88rem;text-align:center;
                              letter-spacing:0.2em;color:var(--brown);background:var(--bg3);
                              font-family:monospace;">
                <?php endif; ?>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Decline this appointment?')">❌ Decline</button>
            </form>

        <?php elseif ($status === 'assigned'): ?>
            <button type="button" class="btn btn-success btn-sm"
                    onclick="openCheckinModal(
                        <?php echo $appt_id; ?>,
                        '<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>',
                        <?php echo floatval($pm_row['total_amount'] ?? 0); ?>,
                        '<?php echo htmlspecialchars(addslashes($pm_row['discount_type'] ?? 'none')); ?>',
                        <?php echo floatval($pm_row['discount_amount'] ?? 0); ?>,
                        <?php echo floatval(($pm_row['final_amount'] ?? 0) > 0 ? $pm_row['final_amount'] : ($pm_row['total_amount'] ?? 0)); ?>,
                        <?php echo intval($pm_row['order_id'] ?? 0); ?>
                    )">✅ Check In</button>
            <form method="POST" style="margin:0;display:flex;align-items:center;gap:0.4rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"  value="decline">
                <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
                <?php if (is_cashier()): ?>
                <input type="password" name="pin" maxlength="4" inputmode="numeric"
                       placeholder="PIN" required
                       style="width:60px;padding:0.32rem 0.4rem;border:1px solid var(--border2);
                              border-radius:6px;font-size:0.88rem;text-align:center;
                              letter-spacing:0.2em;color:var(--brown);background:var(--bg3);
                              font-family:monospace;">
                <?php endif; ?>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Decline this appointment?')">❌ Decline</button>
            </form>

        <?php elseif ($status === 'approved'): ?>
            <!-- Hidden form submitted by the Complete Payment modal -->
            <form id="complete-form-<?php echo $appt_id; ?>" method="POST" style="display:none;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"              value="complete">
                <input type="hidden" name="appt_id"             value="<?php echo $appt_id; ?>">
                <input type="hidden" name="complete_pay_method" id="cp-method-<?php echo $appt_id; ?>" value="cash">
                <input type="hidden" name="celebration_discount" id="cp-celeb-<?php echo $appt_id; ?>"   value="0">
                <input type="hidden" name="advance_payment"      id="cp-advance-<?php echo $appt_id; ?>" value="0">
                <?php if (is_cashier()): ?>
                <input type="hidden" name="pin" id="cp-pin-<?php echo $appt_id; ?>" value="">
                <?php endif; ?>
            </form>
            <?php
            $order_is_paid     = (($pm_row['payment_status'] ?? '') === 'paid');
            $order_owed        = $order_is_paid ? 0.0 : floatval(max($pm_row['final_amount'] ?? 0, $pm_row['total_amount'] ?? 0));
            $unpaid_extras_sum = 0.0;
            foreach ($extra_services as $_ces) {
                if (($_ces['payment_status'] ?? '') !== 'paid') $unpaid_extras_sum += floatval($_ces['charged_price']);
            }
            ?>
            <button type="button" class="btn btn-primary btn-sm"
                    onclick="openCompleteModal(
                        <?php echo $appt_id; ?>,
                        '<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>',
                        <?php echo $order_is_paid ? 'true' : 'false'; ?>,
                        <?php echo $order_owed; ?>,
                        <?php echo $unpaid_extras_sum; ?>
                    )">🎉 Mark Complete</button>
            <form method="POST" style="margin:0;display:flex;align-items:center;gap:0.4rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"  value="decline">
                <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
                <?php if (is_cashier()): ?>
                <input type="password" name="pin" maxlength="4" inputmode="numeric"
                       placeholder="PIN" required
                       style="width:60px;padding:0.32rem 0.4rem;border:1px solid var(--border2);
                              border-radius:6px;font-size:0.88rem;text-align:center;
                              letter-spacing:0.2em;color:var(--brown);background:var(--bg3);
                              font-family:monospace;">
                <?php endif; ?>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Decline this appointment?')">❌ Decline</button>
            </form>
        <?php endif; ?>

        <?php if (in_array($status, ['pending','assigned'])): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleReschedule(<?php echo $appt_id; ?>)">📅 Reschedule</button>
        <button type="button"
                onclick="openEditModal(
                    <?php echo $appt_id; ?>,
                    <?php echo $a['service_id']; ?>,
                    '<?php echo $a['appointment_date']; ?>',
                    '<?php echo $a['service_type']; ?>',
                    <?php echo $a['people_count']??1; ?>,
                    '<?php echo addslashes($a['customer_note']??''); ?>',
                    '<?php echo addslashes(implode(', ', array_column($assigned_therapists, 'full_name'))); ?>'
                )"
                class="btn btn-secondary btn-sm">✏️ Edit</button>
        <button type="button"
                onclick="toggleCancel(<?php echo $appt_id; ?>)"
                style="padding:0.35rem 0.8rem;border-radius:7px;border:1px solid #6b7280;background:transparent;color:#6b7280;font-size:0.82rem;font-weight:600;cursor:pointer;">
            🚫 Customer Cancel
        </button>
        <?php endif; ?>
    </div>

    <?php if (in_array($status, ['pending','assigned'])): ?>
    <!-- Reschedule inline form -->
    <div id="reschedule-<?php echo $appt_id; ?>" style="display:none;margin-top:0.85rem;padding:1rem 1.1rem;background:var(--bg3);border-radius:10px;border:1px solid var(--border2);">
        <div style="font-size:0.78rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.65rem;">📅 Reschedule Appointment</div>
        <form method="POST" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"  value="reschedule">
            <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
            <div>
                <label style="font-size:0.75rem;color:var(--gray);display:block;margin-bottom:3px;">New Date</label>
                <input type="date" name="new_date" required value="<?php echo date('Y-m-d', strtotime($a['appointment_date'])); ?>" min="<?php echo date('Y-m-d'); ?>"
                       style="padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.85rem;">
            </div>
            <div>
                <label style="font-size:0.75rem;color:var(--gray);display:block;margin-bottom:3px;">New Time</label>
                <input type="time" name="new_time" required value="<?php echo date('H:i', strtotime($a['appointment_date'])); ?>"
                       style="padding:0.4rem 0.65rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.85rem;">
            </div>
            <?php if (is_cashier()): ?>
            <div>
                <label style="font-size:0.75rem;color:var(--gray);display:block;margin-bottom:3px;">Your PIN</label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required
                       style="width:70px;padding:0.4rem 0.5rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg2);color:var(--brown);font-size:0.9rem;letter-spacing:0.2em;text-align:center;">
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Reschedule this appointment?')">💾 Confirm Reschedule</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleReschedule(<?php echo $appt_id; ?>)">Cancel</button>
        </form>
    </div>

    <!-- Cancel inline form -->
    <div id="cancel-<?php echo $appt_id; ?>" style="display:none;margin-top:0.85rem;padding:1rem 1.1rem;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;">
        <div style="font-size:0.78rem;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.65rem;">🚫 Cancel Appointment — Customer Request</div>
        <form method="POST" style="display:flex;flex-direction:column;gap:0.6rem;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"  value="cancel">
            <input type="hidden" name="appt_id" value="<?php echo $appt_id; ?>">
            <div>
                <label style="font-size:0.75rem;color:#991b1b;display:block;margin-bottom:3px;">Reason for cancellation <span style="font-weight:400;color:var(--gray);">(optional)</span></label>
                <input type="text" name="cancel_reason" placeholder="e.g. Customer called to cancel, personal reason..."
                       style="width:100%;padding:0.4rem 0.65rem;border:1px solid #fecaca;border-radius:7px;background:#fff;color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
            </div>
            <?php if (is_cashier()): ?>
            <div>
                <label style="font-size:0.75rem;color:#991b1b;display:block;margin-bottom:3px;">Your 4-digit PIN</label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required
                       style="padding:0.4rem 0.65rem;border:1px solid #fecaca;border-radius:7px;background:#fff;color:var(--brown);font-size:0.9rem;letter-spacing:0.2em;text-align:center;width:90px;">
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:0.6rem;">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Mark this appointment as cancelled by customer?')">🚫 Confirm Cancellation</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleCancel(<?php echo $appt_id; ?>)">Back</button>
            </div>
        </form>
    </div>


    <?php endif; ?>

    <?php if ($status === 'cancelled' && !empty($a['cancel_reason'])): ?>
    <div style="margin-top:0.75rem;padding:0.6rem 0.85rem;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:0.8rem;color:#374151;">
        🚫 <strong>Cancellation reason:</strong> <?php echo htmlspecialchars($a['cancel_reason']); ?>
    </div>
    <?php endif; ?>

</div>
</div>

<?php
}; // end $render_card

if (empty($appointments)): ?>
<div class="panel"><div class="panel-body" style="text-align:center;padding:3rem;color:var(--gray);">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">📅</div>
    <p>No appointments found<?php echo $filter_date ? ' on '.date('F d, Y', strtotime($filter_date)) : ''; ?>.</p>
</div></div>
<?php else: ?>

<!-- ── CUSTOMER SEARCH ────────────────────────────────────────────────────── -->
<div class="appt-search-wrap">
    <span class="appt-search-icon">🔍</span>
    <input type="search" id="apptSearchInput" class="appt-search-input"
           placeholder="Search by name, service, or date…"
           oninput="filterAdminAppointments(this.value)"
           autocomplete="off">
    <button type="button" id="apptSearchClear" class="appt-search-clear" onclick="clearApptSearch()" style="display:none;">✕</button>
</div>
<div id="appt-no-results" style="display:none;text-align:center;padding:2rem 1rem;color:var(--gray);font-size:0.9rem;background:var(--bg3);border-radius:12px;margin-bottom:1rem;">
    No appointments match your search.
</div>

<!-- ══ KANBAN BOARD ═══════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.25rem;margin-bottom:2rem;align-items:start;">

    <!-- ── PENDING ──────────────────────────────────────────────────────────── -->
    <div data-kanban-col="pending" style="background:var(--bg3);border-radius:14px;border:1.5px solid #f59e0b55;overflow:hidden;">
        <div style="padding:0.85rem 1.1rem;background:#fef9f0;border-bottom:1.5px solid #f59e0b55;display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1rem;">⏳</span>
            <div>
                <span style="font-weight:800;color:#92400e;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.06em;">Pending</span>
                <p style="font-size:0.72rem;color:var(--gray);margin:2px 0 0;font-weight:400;">Awaiting therapist assignment</p>
            </div>
            <span style="margin-left:auto;background:#f59e0b;color:#fff;border-radius:20px;padding:0.1rem 0.55rem;font-size:0.72rem;font-weight:700;"><?php echo count($pending_rows); ?></span>
        </div>
        <div style="padding:0.75rem;display:flex;flex-direction:column;gap:0.75rem;min-height:80px;">
            <?php if (empty($pending_rows)): ?>
            <p style="color:var(--gray);font-size:0.82rem;text-align:center;padding:1.5rem 0;margin:0;">No pending appointments</p>
            <?php else: foreach ($pending_rows as $a) { $render_card($a); } endif; ?>
        </div>
    </div>

    <!-- ── ASSIGNED ─────────────────────────────────────────────────────────── -->
    <div data-kanban-col="assigned" style="background:var(--bg3);border-radius:14px;border:1.5px solid #0d6efd55;overflow:hidden;">
        <div style="padding:0.85rem 1.1rem;background:#f0f4ff;border-bottom:1.5px solid #0d6efd55;display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1rem;">💆</span>
            <div>
                <span style="font-weight:800;color:#084298;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.06em;">Assigned</span>
                <p style="font-size:0.72rem;color:var(--gray);margin:2px 0 0;font-weight:400;">Awaiting customer arrival</p>
            </div>
            <span style="margin-left:auto;background:#0d6efd;color:#fff;border-radius:20px;padding:0.1rem 0.55rem;font-size:0.72rem;font-weight:700;"><?php echo count($assigned_rows); ?></span>
        </div>
        <div style="padding:0.75rem;display:flex;flex-direction:column;gap:0.75rem;min-height:80px;">
            <?php if (empty($assigned_rows)): ?>
            <p style="color:var(--gray);font-size:0.82rem;text-align:center;padding:1.5rem 0;margin:0;">No assigned appointments</p>
            <?php else: foreach ($assigned_rows as $a) { $render_card($a); } endif; ?>
        </div>
    </div>

    <!-- ── APPROVED ─────────────────────────────────────────────────────────── -->
    <div data-kanban-col="approved" style="background:var(--bg3);border-radius:14px;border:1.5px solid #16a34a55;overflow:hidden;">
        <div style="padding:0.85rem 1.1rem;background:#f0fff4;border-bottom:1.5px solid #16a34a55;display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1rem;">✅</span>
            <div>
                <span style="font-weight:800;color:#065f46;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.06em;">Approved</span>
                <p style="font-size:0.72rem;color:var(--gray);margin:2px 0 0;font-weight:400;">Customer checked in</p>
            </div>
            <span style="margin-left:auto;background:#16a34a;color:#fff;border-radius:20px;padding:0.1rem 0.55rem;font-size:0.72rem;font-weight:700;"><?php echo count($approved_rows); ?></span>
        </div>
        <div style="padding:0.75rem;display:flex;flex-direction:column;gap:0.75rem;min-height:80px;">
            <?php if (empty($approved_rows)): ?>
            <p style="color:var(--gray);font-size:0.82rem;text-align:center;padding:1.5rem 0;margin:0;">No approved appointments</p>
            <?php else: foreach ($approved_rows as $a) { $render_card($a); } endif; ?>
        </div>
    </div>

</div><!-- end kanban grid -->

<!-- ══ HISTORY ════════════════════════════════════════════════════════════════ -->
<?php if (!empty($history_rows)): ?>
<details id="history-section" style="margin-bottom:1.5rem;" <?php echo in_array($filter, ['completed','declined','cancelled']) ? 'open' : ''; ?>>
    <summary style="cursor:pointer;padding:0.85rem 1.1rem;background:var(--bg3);border-radius:12px;border:1px solid var(--border2);display:flex;align-items:center;gap:0.6rem;list-style:none;font-weight:700;color:var(--brown);font-size:0.9rem;user-select:none;">
        📋 History — completed, declined &amp; cancelled
        <span style="margin-left:0.4rem;background:#6b7280;color:#fff;border-radius:20px;padding:0.1rem 0.55rem;font-size:0.72rem;font-weight:700;"><?php echo count($history_rows); ?></span>
        <span style="margin-left:auto;font-size:0.75rem;font-weight:400;color:var(--gray);">click to toggle</span>
    </summary>
    <div style="margin-top:0.75rem;display:flex;flex-direction:column;gap:0.75rem;">
        <?php foreach ($history_rows as $a) { $render_card($a); } ?>
    </div>
</details>
<?php endif; ?>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const msg = document.getElementById('flash-msg');
    if (msg) msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

const style = document.createElement('style');
style.textContent = `@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }`;
document.head.appendChild(style);

function toggleReschedule(apptId) {
    const el = document.getElementById('reschedule-' + apptId);
    const cancelEl = document.getElementById('cancel-' + apptId);
    const addEl = document.getElementById('addservice-' + apptId);
    if (cancelEl) cancelEl.style.display = 'none';
    if (addEl) addEl.style.display = 'none';
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}

function toggleCancel(apptId) {
    const el = document.getElementById('cancel-' + apptId);
    const reschedEl = document.getElementById('reschedule-' + apptId);
    const addEl = document.getElementById('addservice-' + apptId);
    if (reschedEl) reschedEl.style.display = 'none';
    if (addEl) addEl.style.display = 'none';
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}


function highlightPaymentMethod() {
    document.querySelectorAll('[id^="pm-label-"]').forEach(function(lbl) {
        var radio = lbl.querySelector('input[type="radio"]');
        lbl.style.borderColor = radio.checked ? 'var(--brown)' : 'var(--border2)';
        lbl.style.background  = radio.checked ? 'var(--bg3)'   : '';
        lbl.style.fontWeight  = radio.checked ? '700'          : '400';
    });
}
document.addEventListener('DOMContentLoaded', highlightPaymentMethod);

function toggleAddService(apptId) {
    const el = document.getElementById('addservice-' + apptId);
    const reschedEl = document.getElementById('reschedule-' + apptId);
    const cancelEl  = document.getElementById('cancel-' + apptId);
    if (reschedEl) reschedEl.style.display = 'none';
    if (cancelEl)  cancelEl.style.display  = 'none';
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
    if (el && el.style.display !== 'none') validateExtraForm(apptId);
}

const apptRateConfig = <?php
    $rate_configs = [];
    foreach ($appointments as $a) {
        $rate_configs[$a['id']] = [
            'rate_type'  => $a['rate_type']  ?? 'regular',
            'partner_id' => $a['partner_id'] ?? null,
        ];
    }
    echo json_encode($rate_configs);
?>;

const partnerRatesData = <?php
    $pr_data = [];
    $pr_res = $conn->query("SELECT partner_id, service_id, price FROM partner_rates");
    while ($pr_r = $pr_res->fetch_assoc()) {
        $pr_data[$pr_r['partner_id']][$pr_r['service_id']] = $pr_r['price'];
    }
    echo json_encode($pr_data);
?>;

const svcQualifiedMap = <?php echo json_encode((object)$svc_qualified_map); ?>;

function validateExtraForm(apptId) {
    const svcSel     = document.getElementById('extra-svc-' + apptId);
    const therapSel  = document.getElementById('extra-therapist-' + apptId);
    const priceInp   = document.getElementById('extra-price-input-' + apptId);
    const confirmBtn = document.getElementById('extra-confirm-' + apptId);
    if (!svcSel || !therapSel || !priceInp || !confirmBtn) return;

    const svcErr    = document.getElementById('extra-svc-err-' + apptId);
    const therapErr = document.getElementById('extra-therapist-err-' + apptId);
    const priceErr  = document.getElementById('extra-price-err-' + apptId);

    let valid = true;

    const svcOk = parseInt(svcSel.value) > 0;
    if (svcErr) { svcErr.textContent = svcOk ? '' : 'Select a service'; svcErr.style.display = svcOk ? 'none' : ''; }
    if (!svcOk) valid = false;

    const therapOk = parseInt(therapSel.value) > 0;
    if (therapErr) { therapErr.textContent = therapOk ? '' : 'Select a therapist'; therapErr.style.display = therapOk ? 'none' : ''; }
    if (!therapOk) valid = false;

    const pv = priceInp.value.trim();
    const pn = parseFloat(pv);
    const priceOk = pv !== '' && !isNaN(pn) && pn >= 0;
    if (priceErr) { priceErr.textContent = priceOk ? '' : 'Enter a valid price'; priceErr.style.display = priceOk ? 'none' : ''; }
    if (!priceOk) valid = false;

    confirmBtn.disabled = !valid;
    confirmBtn.style.opacity = valid ? '' : '0.5';
    confirmBtn.style.cursor  = valid ? '' : 'not-allowed';
}

function updateExtraTherapist(apptId, svcId) {
    const sel  = document.getElementById('extra-therapist-' + apptId);
    const note = document.getElementById('extra-therapist-note-' + apptId);
    if (!sel) return;
    const list = (svcQualifiedMap && svcQualifiedMap[svcId]) ? svcQualifiedMap[svcId] : [];
    sel.innerHTML = '';
    if (svcId && list.length === 0) {
        if (note) { note.textContent = 'No qualified therapists for this service.'; note.style.display = ''; }
    } else {
        if (note) note.style.display = 'none';
        list.forEach(function(t) {
            const o = document.createElement('option');
            o.value = t.id;
            let lbl = t.name;
            if (t.on_break) lbl += ' ☕ On break';
            else if (t.out) lbl += ' — Checked out';
            o.textContent = lbl;
            o.disabled = t.on_break || t.out;
            sel.appendChild(o);
        });
    }
}

function previewExtraPrice(selectEl, apptId) {
    const opt       = selectEl.options[selectEl.selectedIndex];
    const regPrice  = parseFloat(opt.dataset.price || 0);
    const svcId     = parseInt(selectEl.value) || 0;
    const cfg       = apptRateConfig[apptId] || {};
    const rateType  = cfg.rate_type  || 'regular';
    const partnerId = cfg.partner_id || null;
    const priceInp  = document.getElementById('extra-price-input-' + apptId);
    const hintEl    = document.getElementById('extra-price-hint-'  + apptId);

    if (!svcId) {
        if (priceInp) priceInp.value = '';
        if (hintEl)  hintEl.textContent = '';
        updateExtraTherapist(apptId, 0);
        validateExtraForm(apptId);
        return;
    }
    let charged = regPrice, formula = '';
    switch (rateType) {
        case 'home':
            charged = (regPrice * 2) + 300;
            formula = `(₱${regPrice.toFixed(2)} × 2) + ₱300`;
            break;
        case 'hotel':
            if (partnerId && partnerRatesData[partnerId]?.[svcId]) {
                charged = parseFloat(partnerRatesData[partnerId][svcId]);
                formula = 'Partner rate';
            } else {
                charged = regPrice;
                formula = partnerId ? '⚠️ No partner rate — using regular' : 'Regular price';
            }
            break;
        case 'influencer':
            charged = 0;
            formula = 'Complimentary — ₱0';
            break;
        default:
            charged = regPrice;
            formula = 'Regular price';
    }
    if (priceInp) priceInp.value = charged.toFixed(2);
    if (hintEl)  hintEl.textContent = formula;
    updateExtraTherapist(apptId, svcId);
    validateExtraForm(apptId);
}

// ── Collapse / Expand card (smooth max-height transition + localStorage) ──────
function toggleApptCard(headerEl) {
    var card   = headerEl.closest('.appt-card');
    var detail = card ? card.querySelector('.appt-card-detail') : null;
    if (!detail) return;
    var apptId = card.dataset.apptId;
    var isOpen = detail.classList.contains('is-open');
    if (isOpen) {
        detail.classList.remove('is-open');
        headerEl.classList.remove('is-open');
        try { if (apptId) localStorage.removeItem('appt-open-' + apptId); } catch(e) {}
    } else {
        detail.classList.add('is-open');
        headerEl.classList.add('is-open');
        try { if (apptId) localStorage.setItem('appt-open-' + apptId, '1'); } catch(e) {}
    }
}

// Restore previously-open cards on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.appt-card').forEach(function(card) {
        var apptId = card.dataset.apptId;
        if (!apptId) return;
        try {
            if (localStorage.getItem('appt-open-' + apptId) === '1') {
                var detail = card.querySelector('.appt-card-detail');
                var header = card.querySelector('.appt-card-header');
                if (detail) detail.classList.add('is-open');
                if (header) header.classList.add('is-open');
            }
        } catch(e) {}
    });
});

// ── Search / Filter ───────────────────────────────────────────────────────────
function filterAdminAppointments(query) {
    var q        = (query || '').trim().toLowerCase();
    var cards    = document.querySelectorAll('.appt-card');
    var clearBtn = document.getElementById('apptSearchClear');
    if (clearBtn) clearBtn.style.display = q ? '' : 'none';

    var totalVisible = 0;

    cards.forEach(function(card) {
        var search = (card.dataset.search || '').toLowerCase();
        var match  = !q || search.indexOf(q) !== -1;
        card.style.display = match ? '' : 'none';
        if (match) totalVisible++;
    });

    // Show/hide kanban columns based on whether they have visible cards
    document.querySelectorAll('[data-kanban-col]').forEach(function(col) {
        var visibleInCol = 0;
        col.querySelectorAll('.appt-card').forEach(function(c) {
            if (c.style.display !== 'none') visibleInCol++;
        });
        col.style.opacity = (q && visibleInCol === 0) ? '0.4' : '';
    });

    // Show/hide history section
    var historySec = document.getElementById('history-section');
    if (historySec) {
        var visibleInHistory = 0;
        historySec.querySelectorAll('.appt-card').forEach(function(c) {
            if (c.style.display !== 'none') visibleInHistory++;
        });
        historySec.style.opacity = (q && visibleInHistory === 0) ? '0.4' : '';
    }

    // Global "no results" message
    var noResults = document.getElementById('appt-no-results');
    if (noResults) noResults.style.display = (q && totalVisible === 0) ? '' : 'none';
}

function clearApptSearch() {
    var inp = document.getElementById('apptSearchInput');
    if (inp) { inp.value = ''; inp.focus(); }
    filterAdminAppointments('');
}

// ── COMPLETE PAYMENT MODAL ────────────────────────────────────────────────────
var cmState = { apptId: 0, payMethod: 'cash' };

function openCompleteModal(apptId, customerName, orderIsPaid, orderOwed, unpaidExtrasTotal) {
    cmState.apptId    = apptId;
    cmState.payMethod = 'cash';
    document.getElementById('cm-customer-name').textContent = customerName;
    var totalOwed = (orderIsPaid ? 0 : orderOwed) + unpaidExtrasTotal;
    var fmt = function(n) { return n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); };
    var noPayEl  = document.getElementById('cm-no-payment');
    var paySecEl = document.getElementById('cm-payment-section');
    if (totalOwed <= 0) {
        noPayEl.style.display  = 'block';
        paySecEl.style.display = 'none';
    } else {
        noPayEl.style.display  = 'none';
        paySecEl.style.display = 'block';
        var baseRow    = document.getElementById('cm-base-row');
        var extrasRow  = document.getElementById('cm-extras-row');
        var baseAmt    = document.getElementById('cm-base-amt');
        var extrasAmt  = document.getElementById('cm-extras-amt');
        var totalEl    = document.getElementById('cm-total-amt');
        if (!orderIsPaid && orderOwed > 0) {
            baseRow.style.display = 'flex'; baseAmt.textContent = fmt(orderOwed);
        } else {
            baseRow.style.display = 'none';
        }
        if (unpaidExtrasTotal > 0) {
            extrasRow.style.display = 'flex'; extrasAmt.textContent = fmt(unpaidExtrasTotal);
        } else {
            extrasRow.style.display = 'none';
        }
        totalEl.textContent = fmt(totalOwed);
        cmSelectPayment('cash');
    }
    var pinErr = document.getElementById('cm-pin-error');
    if (pinErr) pinErr.textContent = '';
    var pinInp = document.getElementById('cm-pin-input');
    if (pinInp) pinInp.value = '';
    var cmCelebEl   = document.getElementById('cm-celeb-disc');
    var cmAdvanceEl = document.getElementById('cm-advance-pay');
    if (cmCelebEl)   cmCelebEl.value   = '0';
    if (cmAdvanceEl) cmAdvanceEl.value = '0';
    document.getElementById('completeModal').style.display = 'flex';
    if (pinInp) setTimeout(function() { pinInp.focus(); }, 120);
}

function closeCompleteModal() {
    document.getElementById('completeModal').style.display = 'none';
}

function cmSelectPayment(method) {
    cmState.payMethod = method;
    ['cash','qrph','bank'].forEach(function(m) {
        var btn = document.getElementById('cm-pay-' + m);
        if (!btn) return;
        btn.style.borderColor = m === method ? '#C96A2C' : '#e5e7eb';
        btn.style.background  = m === method ? '#fff8f2' : '';
    });
}

function submitComplete() {
    var pinInp = document.getElementById('cm-pin-input');
    var pinErr = document.getElementById('cm-pin-error');
    if (pinErr) pinErr.textContent = '';
    if (_isCashier && pinInp) {
        var pin = pinInp.value.trim();
        if (!/^\d{4}$/.test(pin)) {
            if (pinErr) pinErr.textContent = 'Please enter your 4-digit PIN.';
            pinInp.focus(); return;
        }
        var hiddenPin = document.getElementById('cp-pin-' + cmState.apptId);
        if (hiddenPin) hiddenPin.value = pin;
    }
    var hiddenMethod = document.getElementById('cp-method-' + cmState.apptId);
    if (hiddenMethod) hiddenMethod.value = cmState.payMethod;
    var cmCeleb   = document.getElementById('cm-celeb-disc');
    var cmAdvance = document.getElementById('cm-advance-pay');
    var hiddenCeleb   = document.getElementById('cp-celeb-'   + cmState.apptId);
    var hiddenAdvance = document.getElementById('cp-advance-' + cmState.apptId);
    if (hiddenCeleb)   hiddenCeleb.value   = parseFloat(cmCeleb   ? cmCeleb.value   : 0) || 0;
    if (hiddenAdvance) hiddenAdvance.value = parseFloat(cmAdvance ? cmAdvance.value : 0) || 0;
    closeCompleteModal();
    var form = document.getElementById('complete-form-' + cmState.apptId);
    if (form) form.submit();
}
</script>

<!-- ══ APPROVE + DISCOUNT FLOW ═══════════════════════════════════════════════ -->

<!-- Hidden approve form submitted after both discount + PIN verified -->
<form method="POST" id="appt-approve-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action"              value="approve">
    <input type="hidden" name="appt_id"             id="approve-appt-id"         value="">
    <input type="hidden" name="appt_discount_type"  id="approve-discount-type"   value="none">
    <input type="hidden" name="appt_voucher_type"   id="approve-voucher-type"    value="cash">
    <input type="hidden" name="appt_discount_value" id="approve-discount-value"  value="0">
    <input type="hidden" name="appt_payment_method" id="approve-payment-method"  value="cash">
    <?php if (is_cashier()): ?><input type="hidden" name="pin" id="approve-pin-input" value=""><?php endif; ?>
</form>

<!-- STEP 1: Discount Modal -->
<div id="discountModal" style="display:none;position:fixed;inset:0;z-index:9998;
     background:rgba(30,20,10,0.5);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem 1.75rem;
                max-width:420px;width:92vw;box-shadow:0 24px 60px rgba(0,0,0,0.2);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 0.75rem;">🎟️</div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--brown);">Assign Therapist</div>
            <div style="font-size:0.8rem;color:var(--gray);margin-top:0.3rem;" id="discModalCustomer"></div>
        </div>
        <div id="modal-online-paid-info" style="display:none;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);border-radius:10px;padding:0.85rem;text-align:center;font-size:0.85rem;color:#0a3622;margin-bottom:1rem;">
            ✅ This appointment was paid online via <strong id="modal-online-method-label"></strong>. Verify and assign the therapist.
        </div>
        <div id="modal-discount-section" style="display:none;">
        <p style="font-size:0.82rem;color:var(--gray);text-align:center;margin-bottom:1rem;">Does this customer have a discount or voucher?</p>
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:0.4rem;margin-bottom:1rem;">
            <?php foreach (['none'=>['🚫','None',''],'senior'=>['👴','Senior','20% off'],'pwd'=>['♿','PWD','20% off'],'employee'=>['🪪','Staff','50% off']] as $dtype => [$dico, $dlbl, $dsub]): ?>
            <div id="dmod-btn-<?php echo $dtype; ?>"
                 onclick="selectDiscModal('<?php echo $dtype; ?>')"
                 style="padding:0.6rem 0.4rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;<?php echo $dtype==='none'?'border-color:#C96A2C;background:#fff8f2;':''; ?>">
                <div style="font-size:1rem;"><?php echo $dico; ?></div>
                <div style="font-size:0.72rem;font-weight:700;margin-top:2px;"><?php echo $dlbl; ?></div>
                <?php if ($dsub): ?><div style="font-size:0.65rem;color:#6b7280;"><?php echo $dsub; ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="button" id="dmod-btn-voucher" onclick="selectDiscModal('voucher')"
                    style="padding:0.55rem 0.4rem;border:2px solid #e5e7eb;border-radius:8px;
                           background:#fff;cursor:pointer;font-size:0.75rem;font-weight:600;
                           color:#374151;text-align:center;transition:all .15s;">
                🎟️<br>Voucher
            </button>
            <button type="button" id="dmod-btn-gift_card" onclick="selectDiscModal('gift_card')"
                    style="padding:0.55rem 0.4rem;border:2px solid #e5e7eb;border-radius:8px;
                           background:#fff;cursor:pointer;font-size:0.75rem;font-weight:600;
                           color:#374151;text-align:center;transition:all .15s;">
                🎁<br>Gift Card
            </button>
        </div>
        <div id="dmod-voucher-area" style="display:none;background:#fef9f0;
             border:1px solid #f59e0b;border-radius:10px;padding:0.85rem;margin-bottom:0.85rem;">
            <div style="font-size:0.8rem;font-weight:600;color:#92400e;margin-bottom:0.5rem;"
                 id="dmod-voucher-label">Enter Discount Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                    <label style="font-size:0.72rem;color:#6b7280;display:block;margin-bottom:3px;">Type</label>
                    <select id="dmod-voucher-type" onchange="updateDiscModalPreview()"
                            style="width:100%;padding:0.45rem 0.6rem;border:1px solid #e5e7eb;
                                   border-radius:7px;font-size:0.82rem;color:#1a1a1a;background:#fff;">
                        <option value="cash">₱ Fixed Amount Off</option>
                        <option value="percent">% Percentage Off</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.72rem;color:#6b7280;display:block;margin-bottom:3px;">Amount</label>
                    <input type="number" id="dmod-voucher-value" min="0" step="0.01"
                           placeholder="e.g. 100 or 30"
                           oninput="updateDiscModalPreview()"
                           style="width:100%;padding:0.45rem 0.6rem;border:1px solid #e5e7eb;
                                  border-radius:7px;font-size:0.82rem;box-sizing:border-box;
                                  color:#1a1a1a;background:#fff;">
                </div>
            </div>
            <div style="font-size:0.72rem;color:#6b7280;margin-top:0.4rem;" id="dmod-voucher-hint">
                Enter ₱ amount or % to deduct from the total.
            </div>
        </div>
        </div><!-- /modal-discount-section -->

        <!-- Payment method selection (hidden — payment collected at check-in) -->
        <div id="modal-payment-section" style="display:none;margin-bottom:1rem;">
            <div style="font-size:0.78rem;font-weight:700;color:var(--brown);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">💳 Payment Method</div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:0.4rem;">
                <div id="dmod-pay-cash"
                     onclick="selectPayModal('cash')"
                     style="padding:0.6rem 0.3rem;border:2px solid #C96A2C;background:#fff8f2;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">💵</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Cash</div>
                </div>
                <div id="dmod-pay-qrph"
                     onclick="selectPayModal('qrph')"
                     style="padding:0.6rem 0.3rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">📷</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">QR Ph</div>
                </div>
                <div id="dmod-pay-gcash"
                     onclick="selectPayModal('gcash')"
                     style="padding:0.6rem 0.3rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">📱</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">GCash</div>
                </div>
                <div id="dmod-pay-maya"
                     onclick="selectPayModal('maya')"
                     style="padding:0.6rem 0.3rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">💜</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Maya</div>
                </div>
                <div id="dmod-pay-bpi_debit"
                     onclick="selectPayModal('bpi_debit')"
                     style="padding:0.6rem 0.3rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">🏦</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">BPI Debit</div>
                </div>
                <div id="dmod-pay-bpi_credit"
                     onclick="selectPayModal('bpi_credit')"
                     style="padding:0.6rem 0.3rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">💳</div>
                    <div style="font-size:0.68rem;font-weight:700;margin-top:2px;color:#3B2A1A;">BPI Credit</div>
                </div>
            </div>
        </div>
        <p id="modal-checkin-note"
           style="font-size:0.83rem;color:var(--gray);text-align:center;
                  margin:0 0 1rem;padding:0.6rem;
                  background:var(--bg3,#f5f5f5);border-radius:8px;">
            💡 Discount and payment will be collected when the customer arrives for Check In.
        </p>
        <div id="dmod-preview" style="display:none;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.3);border-radius:8px;padding:0.6rem 0.85rem;font-size:0.82rem;color:#15803d;margin-bottom:0.85rem;"></div>
        <div style="display:flex;gap:0.65rem;margin-top:0.5rem;">
            <button type="button" onclick="closeDiscountModal()" class="btn btn-secondary" style="flex:1;">Cancel</button>
            <button type="button" onclick="proceedToPIN()" class="btn btn-primary" style="flex:2;">Next: Enter PIN →</button>
        </div>
    </div>
</div>

<!-- STEP 2: PIN Modal -->
<div id="pinModal" style="display:none;position:fixed;inset:0;z-index:9999;
     background:rgba(30,20,10,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem 1.75rem;
                max-width:380px;width:92vw;box-shadow:0 24px 60px rgba(0,0,0,0.22);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--rust),var(--brown));display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 0.75rem;">🔐</div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--brown);">Confirm Approval</div>
            <div style="font-size:0.8rem;color:var(--gray);margin-top:0.3rem;" id="pinModalDesc"></div>
        </div>
        <?php if (!is_cashier()): ?>
        <div style="background:rgba(25,135,84,0.08);border:1px solid rgba(25,135,84,0.2);border-radius:10px;padding:0.85rem;text-align:center;font-size:0.85rem;color:#0a3622;margin-bottom:1rem;">
            👑 Logged in as <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin'); ?></strong>. No PIN required.
        </div>
        <?php else: ?>
        <div id="pinInputArea">
            <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;text-align:center;margin-bottom:0.65rem;text-transform:uppercase;letter-spacing:.05em;">Enter your 4-digit PIN</label>
            <input type="password" id="pinInput" maxlength="4" inputmode="numeric" placeholder="• • • •"
                   style="width:100%;padding:0.85rem;border:2px solid var(--border2);border-radius:10px;font-size:1.6rem;text-align:center;letter-spacing:0.4em;color:var(--brown);background:var(--bg3);box-sizing:border-box;font-family:monospace;transition:border-color .15s;"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <div id="pinError" style="color:#dc2626;font-size:0.78rem;text-align:center;margin-top:0.5rem;min-height:1.1em;"></div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:0.65rem;margin-top:1.1rem;">
            <button type="button" onclick="closePinModal()" class="btn btn-secondary" style="flex:1;">← Back</button>
            <button type="button" id="pinConfirmBtn" onclick="submitApproval()" class="btn btn-primary" style="flex:2;">✅ Approve</button>
        </div>
        <div style="margin-top:0.85rem;text-align:center;font-size:0.72rem;color:var(--gray);">🔒 Action recorded under your name for accountability.</div>
    </div>
</div>

<!-- ══ CHECK-IN MODAL ═════════════════════════════════════════════════════════ -->
<div id="checkinModal" style="display:none;position:fixed;inset:0;z-index:9998;
     background:rgba(30,20,10,0.5);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem 1.75rem;
                max-width:440px;width:92vw;box-shadow:0 24px 60px rgba(0,0,0,0.2);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 0.75rem;">✅</div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--brown);">Customer Check-In</div>
            <div style="font-size:0.8rem;color:var(--gray);margin-top:0.3rem;" id="ciCustomerName"></div>
        </div>

        <!-- Discount selector -->
        <div style="margin-bottom:1rem;">
            <p style="font-size:0.8rem;font-weight:700;color:var(--brown);margin:0 0 0.5rem;">
                Does the customer have a discount?
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(70px,1fr));gap:0.35rem;">
                <?php foreach ([
                    'none'     => ['🚫','None',''],
                    'voucher'  => ['🎟️','Voucher',''],
                    'senior'   => ['👴','Senior','20% off'],
                    'pwd'      => ['♿','PWD','20% off'],
                    'employee' => ['🪪','Staff','50% off'],
                ] as $ci_dv => $ci_dd): ?>
                <label id="ci-disc-label-<?php echo $ci_dv; ?>"
                       style="display:flex;flex-direction:column;align-items:center;
                              padding:0.45rem 0.3rem;
                              border:2px solid <?php echo $ci_dv==='none' ? 'var(--brown)' : 'var(--border2)'; ?>;
                              background:<?php echo $ci_dv==='none' ? 'var(--bg3,#f5f5f5)' : ''; ?>;
                              border-radius:8px;cursor:pointer;
                              font-size:0.72rem;gap:2px;text-align:center;">
                    <input type="radio" name="ci_discount" value="<?php echo $ci_dv; ?>"
                           <?php echo $ci_dv==='none'?'checked':''; ?>
                           style="display:none;"
                           onchange="ciSelectDiscount('<?php echo $ci_dv; ?>')">
                    <span style="font-size:1rem;"><?php echo $ci_dd[0]; ?></span>
                    <span style="font-weight:600;"><?php echo $ci_dd[1]; ?></span>
                    <?php if ($ci_dd[2]): ?>
                    <span style="color:var(--gray);"><?php echo $ci_dd[2]; ?></span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Voucher / Gift Card input -->
        <div id="ci-voucher-area" style="display:none;margin-top:0.65rem;background:#fef9f0;
             border:1px solid #f59e0b;border-radius:8px;padding:0.75rem;">
            <div style="font-size:0.78rem;font-weight:600;color:#92400e;margin-bottom:0.5rem;"
                 id="ci-voucher-label">Voucher Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                    <label style="font-size:0.72rem;color:#6b7280;display:block;margin-bottom:3px;">Type</label>
                    <select id="ci-voucher-type" onchange="ciUpdateVoucher()"
                            style="width:100%;padding:0.4rem 0.55rem;border:1px solid #e5e7eb;
                                   border-radius:7px;font-size:0.82rem;color:#1a1a1a;background:#fff;">
                        <option value="cash">₱ Fixed Amount Off</option>
                        <option value="percent">% Percentage Off</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.72rem;color:#6b7280;display:block;margin-bottom:3px;">Amount</label>
                    <input type="number" id="ci-voucher-value" min="0" step="0.01"
                           placeholder="e.g. 100 or 30"
                           oninput="ciUpdateVoucher()"
                           style="width:100%;padding:0.4rem 0.55rem;border:1px solid #e5e7eb;
                                  border-radius:7px;font-size:0.82rem;color:#1a1a1a;background:#fff;
                                  box-sizing:border-box;">
                </div>
            </div>
        </div>

        <!-- ID/voucher verification -->
        <div id="ci-verify-section" style="display:none;background:#FEF3CD;border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:0.85rem;">
            <label style="font-size:0.82rem;color:#7D5A00;display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="ci-id-verified" style="width:15px;height:15px;">
                <span>ID / Voucher card presented and verified ✓</span>
            </label>
        </div>

        <!-- Price breakdown -->
        <div style="background:var(--bg3);border-radius:10px;padding:0.85rem 1rem;margin-bottom:1rem;font-size:0.85rem;">
            <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;">
                <span style="color:var(--gray);">Original</span>
                <span>₱<span id="ci-orig-amount">0.00</span></span>
            </div>
            <div id="ci-discount-row" style="display:none;justify-content:space-between;margin-bottom:0.35rem;color:#b45309;">
                <span>Discount (<span id="ci-discount-label"></span>)</span>
                <span>−₱<span id="ci-discount-amt">0.00</span></span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border2);padding-top:0.45rem;font-weight:700;font-size:0.9rem;">
                <span>Total Due</span>
                <span style="color:var(--brown);">₱<span id="ci-final-amount">0.00</span></span>
            </div>
        </div>

        <!-- Payment method -->
        <div style="margin-bottom:1rem;">
            <div style="font-size:0.78rem;font-weight:700;color:var(--brown);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">💳 Collect Payment</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;">
                <div id="ci-pay-btn-cash" onclick="ciSelectPayment('cash')"
                     style="padding:0.7rem 0.4rem;border:2px solid #C96A2C;background:#fff8f2;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">💵</div>
                    <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Cash</div>
                </div>
                <div id="ci-pay-btn-qrph" onclick="ciSelectPayment('qrph')"
                     style="padding:0.7rem 0.4rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">📷</div>
                    <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">QR Ph</div>
                </div>
                <div id="ci-pay-btn-bank" onclick="ciSelectPayment('bank')"
                     style="padding:0.7rem 0.4rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                    <div style="font-size:1rem;">🏦</div>
                    <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Bank Transfer</div>
                </div>
            </div>
        </div>

        <form id="checkinForm" method="POST" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"            value="checkin_appointment">
            <input type="hidden" name="appt_id"           id="ci-appt-id"          value="">
            <input type="hidden" name="order_id"          id="ci-order-id"         value="">
            <input type="hidden" name="pay_method"        id="ci-pay-method"       value="cash">
            <input type="hidden" name="ci_discount_type"  id="ci-discount-type"    value="none">
            <input type="hidden" name="ci_discount_amount" id="ci-discount-amount" value="0">
            <input type="hidden" name="ci_final_amount"   id="ci-final-amount-input" value="">
            <?php if (is_cashier()): ?><input type="hidden" name="pin" id="ci-pin-hidden" value=""><?php endif; ?>
        </form>

        <?php if (is_cashier()): ?>
        <div style="margin-top:0.75rem;">
            <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Your 4-digit PIN</label>
            <input type="password" id="ci-pin-input" maxlength="4" placeholder="••••"
                   style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                          background:var(--bg3);color:var(--brown);font-size:1rem;box-sizing:border-box;letter-spacing:0.3em;text-align:center;">
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:0.65rem;margin-top:0.5rem;">
            <button type="button" onclick="closeCheckinModal()" class="btn btn-secondary" style="flex:1;">Cancel</button>
            <button type="button" onclick="submitCheckin()" class="btn btn-success" style="flex:2;">✅ Confirm Check-In</button>
        </div>
    </div>
</div>

<!-- ══ COMPLETE PAYMENT MODAL ══════════════════════════════════════════════ -->
<div id="completeModal" style="display:none;position:fixed;inset:0;z-index:9998;
     background:rgba(30,20,10,0.5);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem 1.75rem;
                max-width:440px;width:92vw;box-shadow:0 24px 60px rgba(0,0,0,0.2);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 0.75rem;">🎉</div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--brown);">Mark Session Complete</div>
            <div style="font-size:0.8rem;color:var(--gray);margin-top:0.3rem;" id="cm-customer-name"></div>
        </div>

        <!-- No payment needed -->
        <div id="cm-no-payment" style="display:none;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);border-radius:10px;padding:0.85rem;text-align:center;font-size:0.85rem;color:#0a3622;margin-bottom:1rem;">
            ✅ All payments already collected. No outstanding balance.
        </div>

        <!-- Payment breakdown -->
        <div id="cm-payment-section" style="display:none;">
            <div style="background:var(--bg3);border-radius:10px;padding:0.85rem 1rem;margin-bottom:1rem;font-size:0.85rem;">
                <div id="cm-base-row" style="display:none;justify-content:space-between;margin-bottom:0.35rem;">
                    <span style="color:var(--gray);">Base session (unpaid)</span>
                    <span>₱<span id="cm-base-amt">0.00</span></span>
                </div>
                <div id="cm-extras-row" style="display:none;justify-content:space-between;margin-bottom:0.35rem;">
                    <span style="color:var(--gray);">Extra services (unpaid)</span>
                    <span>₱<span id="cm-extras-amt">0.00</span></span>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border2);padding-top:0.45rem;font-weight:700;font-size:0.9rem;">
                    <span>Total to Collect</span>
                    <span style="color:var(--brown);">₱<span id="cm-total-amt">0.00</span></span>
                </div>
            </div>
            <div style="margin-bottom:1rem;">
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">💳 Payment Method</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;">
                    <div id="cm-pay-cash" onclick="cmSelectPayment('cash')"
                         style="padding:0.7rem 0.4rem;border:2px solid #C96A2C;background:#fff8f2;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1rem;">💵</div>
                        <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Cash</div>
                    </div>
                    <div id="cm-pay-qrph" onclick="cmSelectPayment('qrph')"
                         style="padding:0.7rem 0.4rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1rem;">📷</div>
                        <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">QR Ph</div>
                    </div>
                    <div id="cm-pay-bank" onclick="cmSelectPayment('bank')"
                         style="padding:0.7rem 0.4rem;border:2px solid #e5e7eb;border-radius:10px;text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1rem;">🏦</div>
                        <div style="font-size:0.72rem;font-weight:700;margin-top:2px;color:#3B2A1A;">Bank Transfer</div>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-bottom:1rem;border-top:1px solid var(--border2);padding-top:0.85rem;">
            <div style="font-size:0.78rem;font-weight:700;color:var(--brown);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.6rem;">Discounts &amp; Adjustments</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                    <label style="font-size:0.73rem;color:var(--gray);display:block;margin-bottom:2px;">Celeb. Discount 10% (₱)</label>
                    <input type="number" id="cm-celeb-disc" step="0.01" min="0" value="0" placeholder="0.00"
                           style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:0.73rem;color:var(--gray);display:block;margin-bottom:2px;">Advance Payment (₱)</label>
                    <input type="number" id="cm-advance-pay" step="0.01" min="0" value="0" placeholder="0.00"
                           style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);font-size:0.85rem;box-sizing:border-box;">
                </div>
            </div>
        </div>

        <?php if (is_cashier()): ?>
        <div style="margin-bottom:1rem;">
            <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Your 4-digit PIN</label>
            <input type="password" id="cm-pin-input" maxlength="4" placeholder="••••"
                   style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                          background:var(--bg3);color:var(--brown);font-size:1rem;box-sizing:border-box;
                          letter-spacing:0.3em;text-align:center;">
            <div id="cm-pin-error" style="color:#dc2626;font-size:0.78rem;margin-top:0.4rem;min-height:1.1em;"></div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:0.65rem;margin-top:0.5rem;">
            <button type="button" onclick="closeCompleteModal()" class="btn btn-secondary" style="flex:1;">Cancel</button>
            <button type="button" onclick="submitComplete()" class="btn btn-primary" style="flex:2;" id="cm-confirm-btn">🎉 Complete Session</button>
        </div>
        <div style="margin-top:0.85rem;text-align:center;font-size:0.72rem;color:var(--gray);">🔒 Action recorded under your name for accountability.</div>
    </div>
</div>

<style>
@keyframes popIn { from { transform:scale(.85);opacity:0; } to { transform:scale(1);opacity:1; } }
#pinInput:focus { outline:none;border-color:var(--rust); }

/* ── Appointment Cards ────────────────────────────────────────────────────── */
.appt-card {
    background:#fff;
    border-radius:10px;
    box-shadow:0 1px 5px rgba(0,0,0,0.07);
    margin-bottom:0.55rem;
    overflow:hidden;
    transition:box-shadow .15s;
}
.appt-card:last-child { margin-bottom:0; }
.appt-card:hover { box-shadow:0 3px 10px rgba(0,0,0,0.11); }

.appt-card-header {
    display:flex;
    align-items:center;
    gap:0.6rem;
    padding:0.6rem 0.85rem;
    cursor:pointer;
    user-select:none;
    background:#fff;
    border-bottom:1px solid transparent;
    transition:background .12s;
}
.appt-card-header:hover { background:var(--bg3,#f7f5f2); }
.appt-card-header.is-open { border-bottom-color:var(--border2,#e5e0d8); }

.appt-name {
    font-size:0.875rem;
    font-weight:700;
    color:var(--brown,#3B2A1A);
    margin:0 0 0.15rem;
    line-height:1.3;
}
.appt-svc {
    font-size:0.75rem;
    color:var(--gray,#6b7280);
    margin:0 0 0.12rem;
    font-weight:500;
}
.appt-time {
    font-size:0.73rem;
    color:var(--gray,#6b7280);
    margin:0;
}
.appt-chev {
    font-style:normal;
    font-size:0.85rem;
    color:var(--gray,#6b7280);
    transition:transform .15s;
    flex-shrink:0;
}
.appt-card-header.is-open .appt-chev { transform:rotate(180deg); }

.appt-card-detail {
    max-height:0;
    overflow:hidden;
    opacity:0;
    padding:0 1rem;
    border-top:1px solid transparent;
    transition:max-height 0.38s ease, opacity 0.25s ease, padding 0.28s ease, border-color 0.28s ease;
}
.appt-card-detail.is-open {
    max-height:2200px;
    opacity:1;
    padding:0.85rem 1rem 1rem;
    border-top-color:var(--border2,#e5e0d8);
}

/* ── Search bar ───────────────────────────────────────────────────────────── */
.appt-search-wrap {
    position:relative;
    margin-bottom:1.1rem;
    display:flex;
    align-items:center;
}
.appt-search-icon {
    position:absolute;
    left:0.85rem;
    font-size:0.9rem;
    pointer-events:none;
    opacity:0.55;
}
.appt-search-input {
    width:100%;
    padding:0.55rem 2.5rem 0.55rem 2.4rem;
    border:1.5px solid var(--border2,#e5e0d8);
    border-radius:10px;
    background:var(--bg3,#f7f5f2);
    color:var(--brown,#3B2A1A);
    font-size:0.9rem;
    outline:none;
    box-sizing:border-box;
    transition:border-color .15s, box-shadow .15s;
}
.appt-search-input:focus {
    border-color:var(--rust,#A94F1D);
    box-shadow:0 0 0 3px rgba(169,79,29,0.12);
    background:#fff;
}
.appt-search-clear {
    position:absolute;
    right:0.75rem;
    background:none;
    border:none;
    color:var(--gray,#6b7280);
    cursor:pointer;
    font-size:0.85rem;
    padding:0.2rem 0.3rem;
    border-radius:4px;
    line-height:1;
}
.appt-search-clear:hover { color:var(--rust,#A94F1D); }

</style>

<script>
let _approveApptId = 0;
let _discountType  = 'none';
let _paymentMethod = 'cash';
const _isCashier   = <?php echo is_cashier() ? 'true' : 'false'; ?>;
let _apptOrderAmounts = {};
<?php
$oa_map = [];
foreach ($appointments as $appt_row) {
    if (!empty($appt_row['order_item_id'])) {
        $oa_stmt = $conn->prepare("SELECT o.total_amount FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=?");
        $oa_stmt->bind_param("i", $appt_row['order_item_id']); $oa_stmt->execute();
        $oa_r = $oa_stmt->get_result()->fetch_assoc(); $oa_stmt->close();
        if ($oa_r) $oa_map[$appt_row['id']] = floatval($oa_r['total_amount']);
    }
}
echo '_apptOrderAmounts = ' . json_encode($oa_map) . ';';
?>

function openDiscountModal(apptId, customerName, isOnlinePaid, onlineMethod) {
    _approveApptId = apptId;
    _discountType  = 'none';
    _paymentMethod = 'cash';
    document.getElementById('approve-appt-id').value = apptId;
    document.getElementById('discModalCustomer').textContent = 'Customer: ' + customerName;
    document.getElementById('dmod-preview').style.display = 'none';
    document.getElementById('dmod-voucher-area').style.display = 'none';
    document.getElementById('dmod-voucher-value').value = '';
    selectDiscModal('none');
    selectPayModal('cash');

    var discSec     = document.getElementById('modal-discount-section');
    var onlineSec   = document.getElementById('modal-online-paid-info');
    var methLabel   = document.getElementById('modal-online-method-label');
    var checkinNote = document.getElementById('modal-checkin-note');
    if (isOnlinePaid) {
        if (discSec)     discSec.style.display     = 'none';
        if (onlineSec)   onlineSec.style.display    = 'block';
        if (checkinNote) checkinNote.style.display  = 'none';
        if (methLabel) {
            var _ml = {cash:'Cash',qrph:'QR Ph',gcash:'GCash',maya:'Maya',bpi_debit:'BPI Debit',bpi_credit:'BPI Credit'};
            methLabel.textContent = _ml[onlineMethod] || onlineMethod;
        }
    } else {
        if (discSec)     discSec.style.display     = 'block';
        if (onlineSec)   onlineSec.style.display    = 'none';
        if (checkinNote) checkinNote.style.display  = 'block';
    }

    document.getElementById('discountModal').style.display = 'flex';
}
function closeDiscountModal() {
    document.getElementById('discountModal').style.display = 'none';
}

function selectPayModal(method) {
    _paymentMethod = method;
    ['cash','qrph','gcash','maya','bpi_debit','bpi_credit'].forEach(m => {
        const btn = document.getElementById('dmod-pay-' + m);
        if (!btn) return;
        btn.style.borderColor = m === method ? '#C96A2C' : '#e5e7eb';
        btn.style.background  = m === method ? '#fff8f2' : '';
    });
}

function selectDiscModal(type) {
    _discountType = type;
    ['none','voucher','gift_card','senior','pwd','employee'].forEach(t => {
        const btn = document.getElementById('dmod-btn-' + t);
        if (!btn) return;
        btn.style.borderColor = t === type ? '#C96A2C' : '#e5e7eb';
        btn.style.background  = t === type ? '#fff8f2' : '';
    });
    const isVoucherLike = (type === 'voucher' || type === 'gift_card');
    document.getElementById('dmod-voucher-area').style.display = isVoucherLike ? 'block' : 'none';
    if (isVoucherLike) {
        const label = type === 'gift_card' ? '🎁 Gift Card Details' : '🎟️ Voucher Details';
        document.getElementById('dmod-voucher-label').textContent = label;
        const hint = type === 'gift_card'
            ? 'Enter the gift card value to deduct from total.'
            : 'Enter voucher discount amount or percentage.';
        document.getElementById('dmod-voucher-hint').textContent = hint;
    }
    updateDiscModalPreview();
}

function updateDiscModalPreview() {
    const preview = document.getElementById('dmod-preview');
    const orig    = _apptOrderAmounts[_approveApptId] || 0;
    if (!orig || _discountType === 'none') { preview.style.display = 'none'; return; }
    let discAmt = 0, label = '';
    if (_discountType === 'senior') {
        discAmt = orig * 0.20; label = '👴 Senior Citizen Discount (20%)';
    } else if (_discountType === 'pwd') {
        discAmt = orig * 0.20; label = '♿ PWD Discount (20%)';
    } else if (_discountType === 'employee') {
        discAmt = orig * 0.50; label = '🪪 Employee Discount (50%)';
    } else if (_discountType === 'voucher' || _discountType === 'gift_card') {
        const vType = document.getElementById('dmod-voucher-type').value;
        const vVal  = parseFloat(document.getElementById('dmod-voucher-value').value || 0);
        discAmt = vType === 'percent' ? orig * (vVal / 100) : Math.min(vVal, orig);
        const icon = _discountType === 'gift_card' ? '🎁 Gift Card' : '🎟️ Voucher';
        label = vType === 'percent' ? `${icon} (${vVal}% off)` : `${icon} (₱${vVal} off)`;
    }
    const final = Math.max(0, orig - discAmt);
    if (discAmt > 0) {
        preview.style.display = 'block';
        preview.innerHTML = `${label}<br>Original: ₱${orig.toLocaleString('en-PH',{minimumFractionDigits:2})} → <strong>Discount: −₱${discAmt.toLocaleString('en-PH',{minimumFractionDigits:2})}</strong> → <strong>Final: ₱${final.toLocaleString('en-PH',{minimumFractionDigits:2})}</strong>`;
    } else {
        preview.style.display = 'none';
    }
}

function proceedToPIN() {
    const vType = document.getElementById('dmod-voucher-type')?.value || 'cash';
    const vVal  = document.getElementById('dmod-voucher-value')?.value || '0';
    document.getElementById('approve-discount-type').value  = _discountType;
    document.getElementById('approve-voucher-type').value   = vType;
    document.getElementById('approve-discount-value').value =
        (_discountType === 'voucher' || _discountType === 'gift_card') ? vVal : '0';
    document.getElementById('approve-payment-method').value = _paymentMethod;
    closeDiscountModal();

    const orig    = _apptOrderAmounts[_approveApptId] || 0;
    const _payLabels = {cash:'💵 Cash', gcash:'📱 GCash', maya:'💜 Maya', bpi_debit:'🏦 BPI Debit', bpi_credit:'💳 BPI Credit'};
    const payLabel = _payLabels[_paymentMethod] || '💵 Cash';
    let desc = `Approving · ${payLabel}`;
    if (_discountType !== 'none') {
        const vv = parseFloat(vVal || 0);
        const vt = vType;
        let discAmt = 0;
        if (_discountType === 'senior' || _discountType === 'pwd') discAmt = orig * 0.20;
        else if (_discountType === 'employee') discAmt = orig * 0.50;
        else if (_discountType === 'voucher' || _discountType === 'gift_card')
            discAmt = vt === 'percent' ? orig * (vv / 100) : Math.min(vv, orig);
        const final = Math.max(0, orig - discAmt);
        const icon = _discountType === 'gift_card' ? '🎁 Gift Card' : _discountType === 'voucher' ? '🎟️ Voucher' : _discountType;
        desc = `Approving · ${payLabel} · ${icon} discount — Final: ₱${final.toLocaleString('en-PH',{minimumFractionDigits:2})}`;
    }
    document.getElementById('pinModalDesc').textContent = desc;

    if (_isCashier && document.getElementById('pinInput')) {
        document.getElementById('pinInput').value = '';
        document.getElementById('pinError').textContent = '';
    }
    document.getElementById('pinModal').style.display = 'flex';
    if (_isCashier) setTimeout(() => document.getElementById('pinInput')?.focus(), 120);
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
}

// ── THE FIX: fetch() now posts to THIS page (window.location.pathname)
// instead of the hardcoded 'appointments.php' which pointed to the wrong file.
async function submitApproval() {
    const btn   = document.getElementById('pinConfirmBtn');
    const errEl = document.getElementById('pinError');
    if (errEl) errEl.textContent = '';

    const pin = _isCashier ? (document.getElementById('pinInput')?.value.trim() || '') : '';
    if (_isCashier && !/^\d{4}$/.test(pin)) {
        if (errEl) errEl.textContent = 'Please enter your 4-digit PIN.';
        document.getElementById('pinInput')?.focus(); return;
    }

    btn.disabled = true; btn.textContent = 'Verifying…';
    try {
        const fd = new FormData();
        fd.append('verify_pin_only', '1');
        fd.append('pin', pin);
        // POST to THIS file — window.location.pathname resolves correctly
        // regardless of what this admin page is named
        const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
            if (errEl) errEl.textContent = data.error || 'Incorrect PIN.';
            if (document.getElementById('pinInput')) document.getElementById('pinInput').value = '';
            document.getElementById('pinInput')?.focus();
            btn.disabled = false; btn.textContent = '✅ Approve'; return;
        }
        closePinModal();
        // Pass PIN to hidden form field for server-side re-verification (cashier only)
        const approvePin = document.getElementById('approve-pin-input');
        if (approvePin) approvePin.value = pin;
        document.getElementById('appt-approve-form').submit();
    } catch(e) {
        if (errEl) errEl.textContent = 'Network error. Try again.';
        btn.disabled = false; btn.textContent = '✅ Assign Therapist';
    }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeDiscountModal(); closePinModal();
        closeCheckinModal(); closeCompleteModal();
        closeEditModal(); closeAddServiceModal();
    }
    if (e.key === 'Enter' && document.getElementById('pinModal').style.display === 'flex') submitApproval();
});

// ── CHECK-IN MODAL JS ─────────────────────────────────────────────────────────
var ciState = {
    apptId: 0, customerName: '', orderId: 0,
    totalAmount: 0, discountType: 'none', discountAmount: 0, finalAmount: 0,
    payMethod: 'cash'
};

function openCheckinModal(apptId, customerName, totalAmount, discountType, discountAmount, finalAmount, orderId) {
    ciState.apptId         = apptId;
    ciState.customerName   = customerName;
    ciState.orderId        = orderId;
    ciState.totalAmount    = parseFloat(totalAmount) || 0;
    ciState.discountType   = 'none';
    ciState.discountAmount = 0;
    ciState.finalAmount    = ciState.totalAmount;

    document.getElementById('ciCustomerName').textContent = customerName;
    document.getElementById('ci-appt-id').value  = apptId;
    document.getElementById('ci-order-id').value = orderId;

    // Reset discount selector to none
    var noneRadio = document.querySelector('[name="ci_discount"][value="none"]');
    if (noneRadio) noneRadio.checked = true;
    ciSelectDiscount('none');

    // Reset ID-verified checkbox
    var idChk = document.getElementById('ci-id-verified');
    if (idChk) idChk.checked = false;

    ciSelectPayment('cash');
    document.getElementById('checkinModal').style.display = 'flex';
}

function closeCheckinModal() {
    document.getElementById('checkinModal').style.display = 'none';
}

function ciSelectPayment(method) {
    ciState.payMethod = method;
    document.getElementById('ci-pay-method').value = method;
    ['cash','qrph','bank'].forEach(function(m) {
        var btn = document.getElementById('ci-pay-btn-' + m);
        if (!btn) return;
        btn.style.borderColor = m === method ? '#C96A2C' : '#e5e7eb';
        btn.style.background  = m === method ? '#fff8f2' : '';
    });
}

function ciSelectDiscount(type) {
    ciState.discountType = type;

    // Always reset voucher area first
    var vArea = document.getElementById('ci-voucher-area');
    if (vArea) vArea.style.display = 'none';
    var vVal = document.getElementById('ci-voucher-value');
    if (vVal) vVal.value = '';

    ['none','voucher','senior','pwd','employee'].forEach(function(t) {
        var lbl = document.getElementById('ci-disc-label-' + t);
        if (lbl) {
            lbl.style.borderColor = t === type ? 'var(--brown)' : 'var(--border2)';
            lbl.style.background  = t === type ? 'var(--bg3,#f5f5f5)' : '';
        }
    });

    var base = ciState.totalAmount;
    var disc = 0;
    if (type === 'senior' || type === 'pwd') {
        disc = Math.round(base * 0.20 * 100) / 100;
    } else if (type === 'employee') {
        disc = Math.round(base * 0.50 * 100) / 100;
    } else if (type === 'voucher' || type === 'gift_card') {
        // Show voucher input area; disc stays 0 until user enters amount
        if (vArea) {
            vArea.style.display = 'block';
            var lbl = document.getElementById('ci-voucher-label');
            if (lbl) lbl.textContent = type === 'gift_card' ? '🎁 Gift Card Details' : '🎟️ Voucher Details';
        }
        disc = 0;
    }
    ciState.discountAmount = disc;
    ciState.finalAmount    = Math.max(0, base - disc);

    var fmt = function(n) { return n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); };

    var discRow = document.getElementById('ci-discount-row');
    if (discRow) {
        if (disc > 0) {
            discRow.style.display = 'flex';
            var lblEl = document.getElementById('ci-discount-label');
            if (lblEl) lblEl.textContent = type.charAt(0).toUpperCase() + type.slice(1) + ' discount';
            var amtEl = document.getElementById('ci-discount-amt');
            if (amtEl) amtEl.textContent = fmt(disc);
        } else {
            discRow.style.display = 'none';
        }
    }

    var finalEl = document.getElementById('ci-final-amount');
    if (finalEl) finalEl.textContent = fmt(ciState.finalAmount);
    var origEl  = document.getElementById('ci-orig-amount');
    if (origEl)  origEl.textContent  = fmt(ciState.totalAmount);

    // Show/hide ID verification
    var verSec = document.getElementById('ci-verify-section');
    if (verSec) verSec.style.display = (type !== 'none') ? 'block' : 'none';

    // Sync hidden form fields
    var discInput    = document.getElementById('ci-discount-type');
    var discAmtInput = document.getElementById('ci-discount-amount');
    if (discInput)    discInput.value    = type;
    if (discAmtInput) discAmtInput.value = disc.toFixed(2);
}

function ciUpdateVoucher() {
    var vType = document.getElementById('ci-voucher-type').value;
    var vVal  = parseFloat(document.getElementById('ci-voucher-value').value || 0);
    var base  = ciState.totalAmount;
    var disc  = vType === 'percent'
        ? Math.round(base * (vVal / 100) * 100) / 100
        : Math.min(vVal, base);
    ciState.discountAmount = disc;
    ciState.finalAmount    = Math.max(0, base - disc);

    var fmt = function(n) { return n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); };

    var discRow = document.getElementById('ci-discount-row');
    if (discRow) {
        if (disc > 0) {
            discRow.style.display = 'flex';
            var lblEl = document.getElementById('ci-discount-label');
            if (lblEl) lblEl.textContent = (ciState.discountType === 'gift_card' ? 'Gift Card' : 'Voucher') + ' discount';
            var amtEl = document.getElementById('ci-discount-amt');
            if (amtEl) amtEl.textContent = fmt(disc);
        } else {
            discRow.style.display = 'none';
        }
    }

    var finalEl = document.getElementById('ci-final-amount');
    if (finalEl) finalEl.textContent = fmt(ciState.finalAmount);

    var discInput    = document.getElementById('ci-discount-type');
    var discAmtInput = document.getElementById('ci-discount-amount');
    if (discInput)    discInput.value    = ciState.discountType;
    if (discAmtInput) discAmtInput.value = disc.toFixed(2);
}

function submitCheckin() {
    // Validate ID/voucher verification when discount is applied
    if (ciState.discountType !== 'none') {
        var idChk = document.getElementById('ci-id-verified');
        if (idChk && !idChk.checked) {
            alert('Please verify the customer\'s ID or voucher card before proceeding.');
            return;
        }
    }
    // Stamp final amount into hidden input before submit
    var finInput = document.getElementById('ci-final-amount-input');
    if (finInput) finInput.value = ciState.finalAmount.toFixed(2);

    if (ciState.payMethod === 'qrph') {
        var form = document.getElementById('checkinForm');
        var amt  = ciState.finalAmount > 0 ? ciState.finalAmount : ciState.totalAmount;
        openAddonQrphModal(form, amt);
        return;
    }
    // Copy PIN to hidden form field (cashier only)
    const ciPinVisible = document.getElementById('ci-pin-input');
    const ciPinHidden  = document.getElementById('ci-pin-hidden');
    if (ciPinVisible && ciPinHidden) ciPinHidden.value = ciPinVisible.value;
    document.getElementById('checkinForm').submit();
}

// ── FULL EDIT APPOINTMENT ─────────────────────────────────────────────────────
function openEditModal(apptId, serviceId, currentDate, serviceType, peopleCount, notes, currentTherapistNames) {
    document.getElementById('edit_appt_id').value      = apptId;
    document.getElementById('edit_service_id').value   = serviceId;
    document.getElementById('edit_date_picker').value  = currentDate ? currentDate.substring(0,10) : '';
    document.getElementById('edit_service_type').value = serviceType || 'regular';
    document.getElementById('edit_people_count').value = peopleCount || 1;
    document.getElementById('edit_notes').value        = notes || '';
    document.getElementById('edit_booking_date').value = currentDate || '';

    const thWrap = document.getElementById('edit_current_therapists_wrap');
    const thDisp = document.getElementById('edit_current_therapists_display');
    if (currentTherapistNames) {
        thDisp.textContent  = currentTherapistNames;
        thWrap.style.display = 'block';
    } else {
        thWrap.style.display = 'none';
    }
    document.getElementById('edit_reassign_therapist').value = '0';

    document.getElementById('editModal').style.display = 'flex';
    if (currentDate) loadEditSlots(currentDate.substring(0,10), serviceId, parseInt(peopleCount)||1);
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
function loadEditSlots(date, serviceId, people) {
    if (!date || !serviceId) return;
    const apptId   = document.getElementById('edit_appt_id').value;
    const rateType = document.getElementById('edit_service_type').value;
    const grid     = document.getElementById('edit-slot-grid');
    const loading  = document.getElementById('edit-slot-loading');
    grid.innerHTML = ''; loading.style.display = 'block';
    fetch(`slots.php?service_id=${serviceId}&date=${date}&people=${people}&rate_type=${rateType}`)
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            if (!data.slots) return;
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = slot.time_label;
                const isCurrentTime = document.getElementById('edit_booking_date').value?.includes(slot.datetime.substring(11,16));
                if (slot.status === 'unavailable' || slot.is_past) {
                    btn.disabled = true;
                    btn.style.cssText = 'padding:0.5rem;border-radius:8px;font-size:0.78rem;font-weight:600;border:1.5px solid #e5e7eb;background:#f9fafb;color:#9ca3af;cursor:not-allowed;opacity:0.6;';
                } else {
                    btn.style.cssText = 'padding:0.5rem;border-radius:8px;font-size:0.78rem;font-weight:600;cursor:pointer;transition:all .15s;border:1.5px solid ' + (isCurrentTime ? '#C96A2C' : 'var(--border2)') + ';background:' + (isCurrentTime ? '#C96A2C' : 'var(--bg3)') + ';color:' + (isCurrentTime ? '#fff' : 'var(--brown)') + ';';
                    btn.onclick = () => {
                        grid.querySelectorAll('button:not([disabled])').forEach(b => { b.style.background='var(--bg3)'; b.style.borderColor='var(--border2)'; b.style.color='var(--brown)'; });
                        btn.style.background = '#C96A2C'; btn.style.borderColor = '#C96A2C'; btn.style.color = '#fff';
                        document.getElementById('edit_booking_date').value = slot.datetime;
                    };
                }
                grid.appendChild(btn);
            });
        });
}

// ── ADD SERVICE TO EXISTING ORDER ─────────────────────────────────────────────
function openAddServiceModal(orderId, customerName) {
    document.getElementById('add_svc_order_id').value = orderId;
    document.getElementById('addSvcCustomerName').textContent = customerName;
    document.getElementById('add_svc_booking_date').value = '';
    document.getElementById('add-svc-slot-grid').innerHTML = '';
    document.getElementById('addServiceModal').style.display = 'flex';
}
function closeAddServiceModal() {
    document.getElementById('addServiceModal').style.display = 'none';
}
function loadAddSvcSlots() {
    const svcId  = document.getElementById('add_svc_service_id').value;
    const date   = document.getElementById('add_svc_date_picker').value;
    const people = parseInt(document.getElementById('add_svc_people').value)||1;
    if (!svcId || !date) return;
    const grid = document.getElementById('add-svc-slot-grid');
    grid.innerHTML = '<div style="color:var(--gray);font-size:0.82rem;">⏳ Loading...</div>';
    fetch(`slots.php?service_id=${svcId}&date=${date}&people=${people}`)
        .then(r => r.json())
        .then(data => {
            grid.innerHTML = '';
            if (!data.slots?.length) { grid.innerHTML = '<div style="color:#888;font-size:0.82rem;">No slots available</div>'; return; }
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button'; btn.textContent = slot.time_label;
                if (slot.status === 'unavailable' || slot.is_past) {
                    btn.disabled = true;
                    btn.style.cssText = 'padding:0.5rem;border-radius:8px;font-size:0.78rem;border:1.5px solid #e5e7eb;background:#f9fafb;color:#9ca3af;cursor:not-allowed;opacity:0.6;';
                } else {
                    btn.style.cssText = 'padding:0.5rem;border-radius:8px;font-size:0.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border2);background:var(--bg3);color:var(--brown);transition:all .15s;';
                    btn.onclick = () => {
                        grid.querySelectorAll('button:not([disabled])').forEach(b => { b.style.background='var(--bg3)'; b.style.borderColor='var(--border2)'; b.style.color='var(--brown)'; });
                        btn.style.background='#C96A2C'; btn.style.borderColor='#C96A2C'; btn.style.color='#fff';
                        document.getElementById('add_svc_booking_date').value = slot.datetime;
                    };
                }
                grid.appendChild(btn);
            });
        });
}
</script>

<!-- ── EDIT APPOINTMENT MODAL ─────────────────────────────────────────────── -->
<?php $all_therapists_modal = $conn->query("SELECT id, full_name FROM therapists ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC); ?>
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:var(--bg2);border-radius:16px;padding:1.5rem;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <span style="font-weight:800;font-size:1rem;color:var(--brown);">✏️ Edit Appointment</span>
            <button onclick="closeEditModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--gray);">✕</button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"          value="edit_appointment">
            <input type="hidden" name="appt_id"         id="edit_appt_id">
            <input type="hidden" name="service_id"      id="edit_service_id">
            <input type="hidden" name="booking_date"    id="edit_booking_date">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.85rem;margin-bottom:0.85rem;">
                <div>
                    <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Service Type</label>
                    <select name="service_type" id="edit_service_type"
                            style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                            onchange="loadEditSlots(document.getElementById('edit_date_picker').value, document.getElementById('edit_service_id').value, parseInt(document.getElementById('edit_people_count').value)||1)">
                        <option value="regular">Regular (On-site)</option>
                        <option value="home">Home Service</option>
                        <option value="hotel">Hotel/Partner</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Number of People</label>
                    <input type="number" name="people_count" id="edit_people_count" min="1" max="10" value="1"
                           style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                           onchange="loadEditSlots(document.getElementById('edit_date_picker').value, document.getElementById('edit_service_id').value, parseInt(this.value)||1)">
                </div>
            </div>
            <!-- Current therapist(s) — shown when present -->
            <div id="edit_current_therapists_wrap" style="display:none;margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Current Therapist(s)</label>
                <div id="edit_current_therapists_display"
                     style="padding:0.5rem 0.75rem;background:var(--bg3);border:1px solid var(--border2);
                            border-radius:8px;font-size:0.85rem;color:var(--brown);"></div>
            </div>
            <!-- Reassign therapist -->
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Reassign Therapist <span style="font-weight:400;color:var(--gray);">(optional)</span></label>
                <select name="reassign_therapist_id" id="edit_reassign_therapist"
                        style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                    <option value="0">— Keep current assignment —</option>
                    <?php foreach ($all_therapists_modal as $tm): ?>
                    <option value="<?php echo intval($tm['id']); ?>">
                        <?php echo htmlspecialchars($tm['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:0.72rem;color:var(--gray);margin-top:3px;">
                    Selecting a therapist clears the current assignment and sets status back to pending.
                </div>
            </div>
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Date</label>
                <input type="date" id="edit_date_picker"
                       min="<?php echo date('Y-m-d'); ?>"
                       style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                       onchange="loadEditSlots(this.value, document.getElementById('edit_service_id').value, parseInt(document.getElementById('edit_people_count').value)||1)">
            </div>
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Available Time Slots</label>
                <div id="edit-slot-loading" style="display:none;font-size:0.8rem;color:var(--gray);">⏳ Loading slots...</div>
                <div id="edit-slot-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.4rem;"></div>
            </div>
            <div style="margin-bottom:1rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Notes</label>
                <textarea name="notes" id="edit_notes" rows="2"
                          style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;resize:vertical;box-sizing:border-box;"
                          placeholder="Special requests, address for home service..."></textarea>
            </div>
            <?php if (is_cashier()): ?>
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Your 4-digit PIN</label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required
                       style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;
                              background:var(--bg3);color:var(--brown);font-size:1rem;letter-spacing:0.3em;text-align:center;box-sizing:border-box;">
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:0.75rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;">💾 Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── ADD SERVICE MODAL ──────────────────────────────────────────────────── -->
<div id="addServiceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:var(--bg2);border-radius:16px;padding:1.5rem;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <span style="font-weight:800;font-size:1rem;color:var(--brown);">➕ Add Service to Order</span>
            <button onclick="closeAddServiceModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--gray);">✕</button>
        </div>
        <div style="font-size:0.8rem;color:var(--gray);margin-bottom:1rem;" id="addSvcCustomerName"></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"    value="add_service_to_order">
            <input type="hidden" name="order_id"  id="add_svc_order_id">
            <input type="hidden" name="booking_date" id="add_svc_booking_date">
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Service</label>
                <select name="service_id" id="add_svc_service_id"
                        style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                        onchange="loadAddSvcSlots()">
                    <option value="">— Select service —</option>
                    <?php
                    $all_svcs = $conn->query("SELECT id, name, price, session_time FROM services ORDER BY name ASC");
                    while ($sv = $all_svcs->fetch_assoc()):
                    ?>
                    <option value="<?php echo $sv['id']; ?>" data-price="<?php echo $sv['price']; ?>">
                        <?php echo htmlspecialchars($sv['name']); ?> — ₱<?php echo number_format($sv['price'],2); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.85rem;margin-bottom:0.85rem;">
                <div>
                    <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Date</label>
                    <input type="date" id="add_svc_date_picker"
                           min="<?php echo date('Y-m-d'); ?>"
                           style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                           onchange="loadAddSvcSlots()">
                </div>
                <div>
                    <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">People</label>
                    <input type="number" name="people_count" id="add_svc_people" min="1" max="10" value="1"
                           style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;"
                           onchange="loadAddSvcSlots()">
                </div>
            </div>
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Available Slots</label>
                <div id="add-svc-slot-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.4rem;"></div>
            </div>
            <div style="margin-bottom:0.85rem;">
                <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:4px;">Payment for Additional Service</label>
                <select name="add_svc_payment" style="width:100%;padding:0.55rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                    <option value="cash">Cash (pay at counter)</option>
                    <option value="gcash">GCash</option>
                    <option value="maya">Maya</option>
                </select>
                <div style="font-size:0.72rem;color:var(--gray);margin-top:4px;">Added to existing order record</div>
            </div>
            <div style="display:flex;gap:0.75rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;">➕ Add Service</button>
                <button type="button" onclick="closeAddServiceModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── PayMongo OTP / Intent Modal (add-on payments) ──────────────────────── -->
<div id="pmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:420px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.28);max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <span id="pmModalTitle" style="font-weight:800;font-size:1rem;color:#3B2A1A;">💳 Online Payment</span>
      <button onclick="closePmModal()" id="pmModalCloseBtn" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
    </div>
    <div id="pm-step-1">
      <div style="text-align:center;margin-bottom:1.1rem;">
        <div id="pm-method-icon" style="font-size:2.5rem;margin-bottom:0.4rem;"></div>
        <div style="font-weight:700;color:#3B2A1A;font-size:1.15rem;" id="pm-amount-display"></div>
        <div style="color:#6b7280;font-size:0.82rem;margin-top:3px;" id="pm-method-label"></div>
      </div>
      <div id="pm-step1-gcash-maya">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Mobile Number</label>
        <input type="tel" id="pm-phone-input" placeholder="09XXXXXXXXX"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.65rem;">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Customer Email <span style="color:red;">*</span></label>
        <input type="email" id="pm-email-input" placeholder="customer@email.com"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.35rem;">
        <p style="font-size:0.72rem;color:#6b7280;margin:0 0 0.85rem;">Required by PayMongo for digital payments.</p>
      </div>
      <div id="pm-step1-card" style="display:none;">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Card Number</label>
        <input type="text" id="pm-card-number" placeholder="0000 0000 0000 0000" maxlength="19"
               oninput="formatCardNumber(this)"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.65rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;margin-bottom:0.65rem;">
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">Exp Month</label>
            <input type="number" id="pm-card-exp-month" min="1" max="12" placeholder="MM"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">Exp Year</label>
            <input type="number" id="pm-card-exp-year" min="2024" max="2040" placeholder="YYYY"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">CVV</label>
            <input type="password" id="pm-card-cvv" maxlength="4" placeholder="•••"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
        </div>
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Customer Email <span style="color:red;">*</span></label>
        <input type="email" id="pm-email-card" placeholder="customer@email.com"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.85rem;">
      </div>
      <button onclick="pmSendOtp()" id="pmSendBtn"
              style="width:100%;padding:0.75rem;background:#C96A2C;color:#fff;border:none;border-radius:10px;font-size:0.95rem;font-weight:700;cursor:pointer;">
        ▶ Proceed to Payment
      </button>
      <div style="text-align:center;margin-top:0.6rem;">
        <button onclick="closePmModal()" style="background:none;border:none;color:#6b7280;font-size:0.82rem;cursor:pointer;text-decoration:underline;">Cancel</button>
      </div>
    </div>
    <div id="pm-step-2" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:2.5rem;margin-bottom:0.65rem;">⏳</div>
      <div style="font-weight:700;color:#3B2A1A;margin-bottom:0.5rem;">Awaiting Authorization</div>
      <div id="pm-step2-msg" style="color:#6b7280;font-size:0.85rem;line-height:1.5;margin-bottom:1rem;"></div>
      <a id="pm-redirect-link" href="#" target="_blank"
         style="display:inline-block;padding:0.65rem 1.25rem;background:#C96A2C;color:#fff;border-radius:9px;font-size:0.88rem;font-weight:700;text-decoration:none;margin-bottom:1rem;">Open Payment</a>
      <div style="font-size:0.75rem;color:#6b7280;margin-bottom:1rem;">
        Waiting for confirmation… <span id="pm-poll-counter" style="font-weight:600;"></span>
      </div>
      <button onclick="pmBackToStep1()" style="width:100%;padding:0.6rem;background:transparent;border:1px solid #d1d5db;color:#6b7280;border-radius:9px;font-size:0.82rem;cursor:pointer;">
        ← Change Payment Details
      </button>
    </div>
    <div id="pm-step-3" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:2.5rem;margin-bottom:0.65rem;">❌</div>
      <div style="font-weight:700;color:#b91c1c;margin-bottom:0.5rem;" id="pm-err-title">Payment Failed</div>
      <div style="color:#6b7280;font-size:0.85rem;" id="pm-err-msg"></div>
      <button onclick="pmBackToStep1()" style="width:100%;padding:0.6rem;background:transparent;border:1px solid #d1d5db;color:#6b7280;border-radius:9px;font-size:0.82rem;cursor:pointer;margin-top:1rem;">← Try Again</button>
    </div>
    <div id="pm-step-4" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:3rem;margin-bottom:0.65rem;">✅</div>
      <div style="font-weight:800;color:#15803d;font-size:1.1rem;margin-bottom:0.5rem;">Payment Confirmed!</div>
      <div id="pm-done-msg" style="color:#6b7280;font-size:0.85rem;"></div>
    </div>
    <div id="pm-error-banner" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.65rem 0.85rem;margin-top:0.75rem;font-size:0.82rem;color:#b91c1c;"></div>
  </div>
</div>

<!-- ── QR Ph Payment Modal ──────────────────────────────────────────────────── -->
<div id="qrphModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:320px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.28);text-align:center;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <span style="font-weight:800;font-size:1rem;color:#3B2A1A;">📷 QR Ph Payment</span>
      <button onclick="closeQrphModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
    </div>
    <p style="font-size:0.82rem;color:#6b7280;margin-bottom:0.85rem;line-height:1.5;">
      Customer scans with any Philippine banking app<br>(GCash, Maya, BPI, BDO, UnionBank, etc.)
    </p>
    <div id="qrphImageContainer" style="margin:0 auto 0.85rem;width:280px;height:auto;min-height:180px;display:flex;align-items:center;justify-content:center;background:#f5f5f5;border-radius:10px;border:1px solid #EAD8C0;">
      <span style="color:#6b7280;font-size:0.82rem;">⏳ Generating…</span>
    </div>
    <div id="qrphAmount" style="font-size:1.3rem;font-weight:800;color:#3B2A1A;margin-bottom:0.4rem;"></div>
    <div id="qrphStatus" style="font-size:0.85rem;color:#C96A2C;margin-bottom:1rem;">⏳ Waiting for payment…</div>
    <button onclick="closeQrphModal()" style="padding:0.55rem 1.5rem;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#6b7280;font-size:0.85rem;cursor:pointer;font-family:inherit;">Cancel</button>
  </div>
</div>

<script>
// ── Add-on OTP payment flow ────────────────────────────────────────────────────
var pmState = {
    intentId:'', pmId:'', method:'', amount:0,
    pollTimer:null, pollCount:0, addonForm:null
};

// Intercept add-on form submissions for online payment methods
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-extra-svc]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var pmRadio = form.querySelector('[name="extra_payment_method"]:checked');
            var method  = pmRadio ? pmRadio.value : 'cash';
            if (method === 'qrph') {
                e.preventDefault();
                var priceInput = form.querySelector('[name="extra_price"]');
                var amount = parseFloat(priceInput ? priceInput.value : 0) || 0;
                if (amount <= 0) { alert('Please enter a valid price.'); return; }
                openAddonQrphModal(form, amount);
                return;
            }
            if (!['gcash','maya','card'].includes(method)) return; // cash/bank: normal submit
            e.preventDefault();
            var priceInput = form.querySelector('[name="extra_price"]');
            var amount = parseFloat(priceInput ? priceInput.value : 0) || 0;
            if (amount <= 0) { alert('Please enter a valid price.'); return; }
            openAddonPaymentModal(form, method, amount);
        });
    });
});

function openAddonPaymentModal(form, method, amount) {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    pmState.intentId = ''; pmState.pmId = ''; pmState.pollCount = 0;
    pmState.method   = method;
    pmState.amount   = parseFloat(amount) || 0;
    pmState.addonForm = form;
    var icons  = {gcash:'📱', maya:'💜', card:'💳'};
    var labels = {gcash:'GCash', maya:'Maya', card:'Credit/Debit Card'};
    document.getElementById('pm-method-icon').textContent    = icons[method]  || '💳';
    document.getElementById('pm-amount-display').textContent = '₱' + pmState.amount.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('pm-method-label').textContent   = labels[method] || method;
    var isCard = method === 'card';
    document.getElementById('pm-step1-gcash-maya').style.display = isCard ? 'none' : '';
    document.getElementById('pm-step1-card').style.display        = isCard ? ''     : 'none';
    var phoneEl = document.getElementById('pm-phone-input');
    if (phoneEl) phoneEl.value = '';
    document.getElementById('pmModalCloseBtn').style.display = '';
    pmShowStep(1);
    document.getElementById('pmModal').style.display = 'flex';
}

function closePmModal() {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    document.getElementById('pmModal').style.display = 'none';
}

function pmShowStep(n) {
    [1,2,3,4].forEach(function(i) {
        var el = document.getElementById('pm-step-' + i);
        if (el) el.style.display = (i === n) ? '' : 'none';
    });
    document.getElementById('pm-error-banner').style.display = 'none';
}

function pmError(msg) {
    var el = document.getElementById('pm-error-banner');
    el.textContent = '⚠️ ' + msg; el.style.display = '';
    var btn = document.getElementById('pmSendBtn');
    if (btn) { btn.disabled = false; btn.textContent = '▶ Proceed to Payment'; }
}

function pmGetCsrf() {
    var el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
}

function pmSendOtp() {
    var btn = document.getElementById('pmSendBtn');
    btn.disabled = true; btn.textContent = '⏳ Processing…';
    document.getElementById('pm-error-banner').style.display = 'none';
    var phone = (document.getElementById('pm-phone-input')?.value || '').trim();
    var email = '';
    if (pmState.method === 'gcash' || pmState.method === 'maya') {
        email = (document.getElementById('pm-email-input')?.value || '').trim();
        if (!phone) { pmError('Please enter a mobile number.'); return; }
        if (!email) { pmError('Customer email is required for GCash/Maya payment.'); return; }
    }
    if (pmState.method === 'card') {
        var num  = (document.getElementById('pm-card-number')?.value || '').replace(/\s/g,'');
        var expM = document.getElementById('pm-card-exp-month')?.value || '';
        var expY = document.getElementById('pm-card-exp-year')?.value  || '';
        var cvv  = document.getElementById('pm-card-cvv')?.value || '';
        email    = (document.getElementById('pm-email-card')?.value || '').trim();
        if (!num || !expM || !expY || !cvv) { pmError('Please fill in all card details.'); return; }
    }
    // Step 1: Create intent
    var amountCentavos = Math.round(pmState.amount * 100);
    var fd1 = new FormData();
    fd1.append('action', 'create_intent');
    fd1.append('csrf_token', pmGetCsrf());
    fd1.append('amount_centavos', amountCentavos);
    fd1.append('pm_method', pmState.method);
    fd1.append('description', 'Add-on service payment');
    fetch('paymongo_intent.php', {method:'POST', body:fd1, credentials:'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { pmError(data.error); return; }
            pmState.intentId = data.intent_id;
            // Step 2: Attach method
            var fd2 = new FormData();
            fd2.append('action', 'attach_method');
            fd2.append('csrf_token', pmGetCsrf());
            fd2.append('intent_id', pmState.intentId);
            fd2.append('pm_method', pmState.method);
            fd2.append('phone', phone);
            fd2.append('name', '');
            fd2.append('email', email);
            if (pmState.method === 'card') {
                fd2.append('card_number',    document.getElementById('pm-card-number')?.value || '');
                fd2.append('card_exp_month', document.getElementById('pm-card-exp-month')?.value || '');
                fd2.append('card_exp_year',  document.getElementById('pm-card-exp-year')?.value  || '');
                fd2.append('card_cvv',       document.getElementById('pm-card-cvv')?.value || '');
            }
            return fetch('paymongo_intent.php', {method:'POST', body:fd2, credentials:'same-origin'});
        })
        .then(function(r) { return r ? r.json() : null; })
        .then(function(data) {
            if (!data) return;
            if (data.error) { pmError(data.error); return; }
            pmState.pmId = data.pm_id || '';
            if (data.status === 'succeeded') {
                pmDoneAddon(data.reference || pmState.intentId);
                return;
            }
            var na = data.next_action;
            var redirectUrl = (na && na.redirect && na.redirect.url) ? na.redirect.url : '';
            pmShowStep(2);
            var mname = {gcash:'GCash',maya:'Maya',card:'3D Secure'}[pmState.method] || '';
            document.getElementById('pm-step2-msg').textContent =
                'Please authorize the payment in your ' + mname + ' app, then return here.';
            var linkEl = document.getElementById('pm-redirect-link');
            if (redirectUrl) {
                linkEl.href = redirectUrl;
                linkEl.textContent = 'Open ' + mname;
                linkEl.style.display = 'inline-block';
                window.open(redirectUrl, '_blank');
            } else {
                linkEl.style.display = 'none';
            }
            pmState.pollCount = 0;
            pmState.pollTimer = setInterval(pmPollStatus, 3000);
        })
        .catch(function(e) { pmError(e.message); });
}

function pmBackToStep1() {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    pmShowStep(1);
    var btn = document.getElementById('pmSendBtn');
    if (btn) { btn.disabled = false; btn.textContent = '▶ Proceed to Payment'; }
}

function pmPollStatus() {
    pmState.pollCount++;
    var counter = document.getElementById('pm-poll-counter');
    if (counter) counter.textContent = '(check ' + pmState.pollCount + ' of 40)';
    if (pmState.pollCount > 40) {
        clearInterval(pmState.pollTimer);
        pmShowStep(3);
        document.getElementById('pm-err-title').textContent = 'Payment Timed Out';
        document.getElementById('pm-err-msg').textContent   = 'We couldn\'t confirm your payment. Please try again or use cash.';
        return;
    }
    var fd = new FormData();
    fd.append('action', 'check_intent');
    fd.append('csrf_token', pmGetCsrf());
    fd.append('intent_id', pmState.intentId);
    fetch('paymongo_intent.php', {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) return;
            if (data.status === 'succeeded') {
                clearInterval(pmState.pollTimer);
                pmDoneAddon(data.reference || pmState.intentId);
            } else if (data.status === 'payment_error' || data.status === 'failed') {
                clearInterval(pmState.pollTimer);
                pmShowStep(3);
                document.getElementById('pm-err-title').textContent = 'Payment Failed';
                document.getElementById('pm-err-msg').textContent   = 'Payment was not completed. Please try again.';
            }
        })
        .catch(function() {});
}

function pmDoneAddon(reference) {
    var form = pmState.addonForm;
    if (!form) { location.reload(); return; }
    function injectHidden(name, val) {
        var inp = form.querySelector('[name="' + name + '"]');
        if (!inp) { inp = document.createElement('input'); inp.type='hidden'; inp.name=name; form.appendChild(inp); }
        inp.value = val;
    }
    injectHidden('paymongo_reference', reference || pmState.intentId);
    injectHidden('paymongo_method',    pmState.method);
    document.getElementById('pmModalCloseBtn').style.display = 'none';
    pmShowStep(4);
    var mnames = {gcash:'GCash', maya:'Maya', card:'Card'};
    document.getElementById('pm-done-msg').textContent =
        'Payment confirmed via ' + (mnames[pmState.method]||pmState.method) + '. Submitting…';
    setTimeout(function() { form.submit(); }, 1200);
}

function formatCardNumber(input) {
    var v = input.value.replace(/\s+/g,'').replace(/\D/g,'');
    var m = v.match(/\d{1,4}/g);
    input.value = m ? m.join(' ') : '';
}

// ── QR Ph add-on flow ─────────────────────────────────────────────────────────
var qrphAddonState = { sessionId: '', checkoutUrl: '', pollTimer: null, addonForm: null, amount: 0 };

function openAddonQrphModal(form, amount) {
    qrphAddonState.addonForm = form;
    qrphAddonState.amount    = parseFloat(amount) || 0;
    qrphAddonState.sessionId = '';
    if (qrphAddonState.pollTimer) clearInterval(qrphAddonState.pollTimer);

    var modal = document.getElementById('qrphModal');
    modal.style.display = 'flex';
    document.getElementById('qrphAmount').textContent =
        '₱' + qrphAddonState.amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('qrphImageContainer').innerHTML =
        '<span style="color:#6b7280;font-size:0.82rem;">⏳ Generating QR code…</span>';
    document.getElementById('qrphStatus').textContent = '⏳ Waiting for payment…';
    document.getElementById('qrphStatus').style.color = '#C96A2C';

    var _qrOid = '0';
    if (form) {
        var _oidInp = form.querySelector('[name="order_id"]');
        if (_oidInp && _oidInp.value) _qrOid = _oidInp.value;
    }
    var fd = new FormData();
    fd.append('action',      'create_qrph');
    fd.append('csrf_token',  pmGetCsrf());
    fd.append('order_id',    _qrOid);
    fd.append('amount',      qrphAddonState.amount.toFixed(2));
    fd.append('description', 'Recovery Spa Service');
    fetch('paymongo_intent.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('qrphImageContainer').innerHTML =
                    '<span style="color:#b91c1c;font-size:0.82rem;">❌ ' + (data.error || 'Failed to generate QR code') + '</span>';
                return;
            }
            qrphAddonState.sessionId   = data.session_id;
            qrphAddonState.checkoutUrl = data.checkout_url;
            var cont = document.getElementById('qrphImageContainer');
            cont.style.width     = '280px';
            cont.style.height    = 'auto';
            cont.style.minHeight = '180px';
            cont.innerHTML =
                '<div style="text-align:center;padding:1.5rem;">'
              + '<div style="font-size:2.5rem;margin-bottom:0.75rem;">📷</div>'
              + '<p style="font-size:0.85rem;color:#3B2A1A;margin-bottom:1rem;line-height:1.5;">'
              + 'Click below to open the QR Ph payment page in a new window.</p>'
              + '<button type="button" onclick="window.open(qrphAddonState.checkoutUrl,\'paymongo_qrph\',\'width=420,height=700\')" '
              + 'style="padding:0.7rem 1.5rem;background:#C96A2C;color:#fff;'
              + 'border:none;border-radius:8px;font-weight:700;font-size:0.9rem;cursor:pointer;">Open QR Code</button>'
              + '</div>';
            var popup = window.open(data.checkout_url, 'paymongo_qrph', 'width=420,height=700');
            if (!popup || popup.closed || typeof popup.closed === 'undefined') {
                cont.innerHTML =
                    '<div style="text-align:center;padding:1.5rem;">'
                  + '<p style="font-size:0.85rem;color:#b91c1c;margin-bottom:1rem;">'
                  + '⚠️ Popup blocked by browser. Click below to open manually:</p>'
                  + '<button type="button" onclick="window.open(qrphAddonState.checkoutUrl,\'_blank\')" '
                  + 'style="padding:0.7rem 1.5rem;background:#C96A2C;color:#fff;'
                  + 'border:none;border-radius:8px;font-weight:700;cursor:pointer;">Open Payment Page</button>'
                  + '</div>';
            }
            startAddonQrphPolling();
        })
        .catch(function(err) {
            document.getElementById('qrphImageContainer').innerHTML =
                '<span style="color:#b91c1c;font-size:0.82rem;">❌ ' + err.message + '</span>';
        });
}

function startAddonQrphPolling() {
    var pollCount = 0;
    qrphAddonState.pollTimer = setInterval(function() {
        pollCount++;
        if (pollCount > 60) {
            clearInterval(qrphAddonState.pollTimer);
            document.getElementById('qrphStatus').textContent = '⏰ Timed out. Please cancel and try again.';
            document.getElementById('qrphStatus').style.color = '#b91c1c';
            return;
        }
        var fd = new FormData();
        fd.append('action',     'check_qrph');
        fd.append('csrf_token', pmGetCsrf());
        fd.append('session_id', qrphAddonState.sessionId);
        fd.append('order_id',   '0');
        fetch('paymongo_intent.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.paid) {
                    clearInterval(qrphAddonState.pollTimer);
                    document.getElementById('qrphStatus').textContent = '✅ Payment received!';
                    document.getElementById('qrphStatus').style.color = '#15803d';
                    qrphDoneAddon(data.reference || qrphAddonState.sessionId);
                }
            })
            .catch(function() {});
    }, 3000);
}

function qrphDoneAddon(reference) {
    var form = qrphAddonState.addonForm;
    if (!form) { location.reload(); return; }
    function injectHidden(name, val) {
        var inp = form.querySelector('[name="' + name + '"]');
        if (!inp) { inp = document.createElement('input'); inp.type = 'hidden'; inp.name = name; form.appendChild(inp); }
        inp.value = val;
    }
    injectHidden('paymongo_reference', reference);
    injectHidden('paymongo_method',    'qrph');
    document.getElementById('qrphStatus').textContent = '✅ Confirmed! Submitting…';
    setTimeout(function() {
        document.getElementById('qrphModal').style.display = 'none';
        form.submit();
    }, 1000);
}

function closeQrphModal() {
    if (qrphAddonState.pollTimer) clearInterval(qrphAddonState.pollTimer);
    document.getElementById('qrphModal').style.display = 'none';
}
</script>

<?php require_once 'admin_footer.php'; ?>