<?php
/**
 * staff.php — Staff & Therapist Management
 * Full-access roles (owner, IT, marketing) only.
 * Cashiers are redirected — they have no staff management access.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
redirect_if_not_owner(); // owners + full-access staff only
require_once __DIR__ . '/../notify.php';

$comm_from = $_GET['comm_from'] ?? date('Y-m-d');
$comm_to   = $_GET['comm_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $comm_from) || !strtotime($comm_from))
    $comm_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $comm_to) || !strtotime($comm_to))
    $comm_to = date('Y-m-d');
if ($comm_from > $comm_to) { $tmp = $comm_from; $comm_from = $comm_to; $comm_to = $tmp; unset($tmp); }

// ── Pay-period for CA / Deductions tab — semi-monthly smart default ───────────
// Default: day 1–15 → 1st–15th of this month; day 16+ → 16th–last day.
$_day = (int) date('j');
$_ps_default = $_day <= 15 ? date('Y-m-01') : date('Y-m-16');
$_pe_default = $_day <= 15 ? date('Y-m-15') : date('Y-m-t');
$period_start = $_GET['period_start'] ?? $_ps_default;
$period_end   = $_GET['period_end']   ?? $_pe_default;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_start) || !strtotime($period_start))
    $period_start = $_ps_default;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end) || !strtotime($period_end))
    $period_end = $_pe_default;
if ($period_start > $period_end) { $tmp = $period_start; $period_start = $period_end; $period_end = $tmp; unset($tmp); }
unset($_day, $_ps_default, $_pe_default);

$msg = ''; $msg_type = 'success';

$conn->query("ALTER TABLE therapists ADD COLUMN IF NOT EXISTS is_generalist TINYINT(1) NOT NULL DEFAULT 0");

// ═══════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

// ── CREATE STAFF ACCOUNT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cashier'])) {
    verify_csrf_token();
    $username      = trim($_POST['username']        ?? '');
    $email         = trim($_POST['email']           ?? '');
    $full_name     = trim($_POST['full_name']       ?? '');
    $phone         = trim($_POST['phone']           ?? '');
    $password      = $_POST['password']             ?? '';
    $confirm       = $_POST['confirm_password']     ?? '';
    $role_type     = trim($_POST['role_type']       ?? 'cashier');

    // Map button value → admin_role column value
    $valid_roles   = ['cashier' => 'cashier', 'marketing' => 'marketing', 'it' => 'it'];
    $admin_role_val = $valid_roles[$role_type] ?? 'cashier';
    $role_labels   = ['cashier' => 'Receptionist', 'marketing' => 'Marketing', 'it' => 'IT Support'];
    $role_label    = $role_labels[$admin_role_val] ?? 'Staff';

    $creator_role = current_admin_role();
    $errors = [];
    // [PERMISSION] Server-side gate — never trust the form submission
    if (!in_array($admin_role_val, creatable_roles($creator_role), true)) {
        $errors[] = '⚠️ You are not permitted to create that account type.';
    }
    if (empty($username) || empty($email) || empty($password))
        $errors[] = 'Username, email and password are required.';
    if ($admin_role_val !== 'cashier' && empty($full_name))
        $errors[] = 'Full name is required.';
    if ($password !== $confirm)
        $errors[] = 'Passwords do not match.';
    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password))
        $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password))
        $errors[] = 'Password must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password))
        $errors[] = 'Password must contain at least one special character.';

    if (empty($errors)) {
        // [R1] Enforce max 1 receptionist account
        if ($admin_role_val === 'cashier') {
            $chk_r = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE admin_role='cashier' AND role='admin' AND deleted_at IS NULL");
            $chk_r->execute();
            $existing_count = intval($chk_r->get_result()->fetch_assoc()['c']);
            $chk_r->close();
            if ($existing_count >= 1) {
                $errors[] = '⚠️ Only 1 Receptionist account is allowed. Please deactivate the existing one before creating a new one.';
            }
        }
    }

    if (empty($errors)) {
        $chk = $conn->prepare("SELECT id FROM users WHERE email=? OR username=?");
        $chk->bind_param("ss", $email, $username); $chk->execute();
        if ($chk->get_result()->num_rows > 0)
            $errors[] = 'Email or username already exists.';
        $chk->close();
    }

    if (empty($errors)) {
        // Receptionist is a shared account — no personal name on the account itself
        if ($admin_role_val === 'cashier') $full_name = 'Receptionist';
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("
            INSERT INTO users (username, email, password, full_name, phone, address, role, admin_role, created_at)
            VALUES (?, ?, ?, ?, ?, 'Admin Office', 'admin', ?, NOW())
        ");
        $stmt->bind_param("ssssss", $username, $email, $hashed, $full_name, $phone, $admin_role_val);
        $stmt->execute(); $stmt->close();
        $msg = $admin_role_val === 'cashier'
            ? "✅ Receptionist account created."
            : "✅ {$role_label} account for <strong>{$full_name}</strong> created.";
    } else {
        $msg = implode('<br>', $errors); $msg_type = 'danger';
    }
}

// ── SAVE RECEPTIONIST LOGIN SETTINGS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_receptionist_settings'])) {
    verify_csrf_token();
    $r_start = $_POST['r_login_start'] ?? '07:00';
    $r_end   = $_POST['r_login_end']   ?? '23:59';
    if (preg_match('/^\d{2}:\d{2}$/', $r_start) && preg_match('/^\d{2}:\d{2}$/', $r_end)) {
        $uid = (int)$_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');
        foreach (['receptionist_login_start' => $r_start, 'receptionist_login_end' => $r_end] as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
                                    VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)");
            $stmt->bind_param("ssis", $key, $val, $uid, $now);
            $stmt->execute(); $stmt->close();
        }
        $msg = '✅ Receptionist login time window saved.';
    } else {
        $msg = '❌ Invalid time format.'; $msg_type = 'danger';
    }
}

// ── FORCE LOGOUT RECEPTIONIST SESSION ────────────────────────────────────────
if (isset($_GET['clear_r_session'])) {
    $rid  = intval($_GET['clear_r_session']);
    $stmt = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=? AND admin_role='cashier'");
    $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
    $msg = '✅ Receptionist session cleared. They can now log in again.';
    header("Location: staff.php?tab=cashiers"); exit();
}

// ── UPDATE CASHIER PIN ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pin'])) {
    verify_csrf_token();
    $uid = intval($_POST['pin_user_id'] ?? 0);
    $pin = trim($_POST['new_pin'] ?? '');
    if ($uid > 0 && ctype_digit($pin) && strlen($pin) === 4) {
        $stmt = $conn->prepare("UPDATE users SET cashier_pin=? WHERE id=? AND admin_role='cashier'");
        $stmt->bind_param("si", $pin, $uid); $stmt->execute(); $stmt->close();
        $msg = "✅ PIN updated.";
    } else {
        $msg = 'PIN must be exactly 4 digits.'; $msg_type = 'danger';
    }
}

// ── DELETE STAFF ACCOUNT ──────────────────────────────────────────────────────
if (isset($_GET['delete_cashier'])) {
    $del_id      = intval($_GET['delete_cashier']);
    $chk         = $conn->prepare("SELECT admin_role FROM users WHERE id=?");
    $chk->bind_param("i", $del_id); $chk->execute();
    $row         = $chk->get_result()->fetch_assoc(); $chk->close();
    $target_role = $row['admin_role'] ?? '';
    $del_creator = current_admin_role();

    if ($del_id === (int)$_SESSION['user_id']) {
        $msg = '⚠️ You cannot delete your own account.'; $msg_type = 'warning';
    } elseif ($target_role === 'owner') {
        $msg = '⚠️ Cannot delete an owner account.'; $msg_type = 'warning';
    } elseif (!in_array($target_role, creatable_roles($del_creator), true)) {
        $msg = '⚠️ You are not permitted to delete that account type.'; $msg_type = 'warning';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND admin_role=?");
        $stmt->bind_param("is", $del_id, $target_role); $stmt->execute(); $stmt->close();
        $role_labels_del = ['cashier' => 'Receptionist', 'marketing' => 'Marketing', 'it' => 'IT Support'];
        $msg = '🗑️ ' . ($role_labels_del[$target_role] ?? ucfirst($target_role)) . ' account removed.';
    }
}

// ── ADD / EDIT THERAPIST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_therapist'])) {
    verify_csrf_token();
    $tid              = intval($_POST['therapist_id']    ?? 0);
    $full_name        = sanitize_input($_POST['th_full_name']   ?? '');
    $phone            = sanitize_input($_POST['th_phone']        ?? '');
    $specialties_text = sanitize_input($_POST['th_specialties']  ?? '');
    $sel_cat_ids      = array_map('intval', $_POST['th_cat_ids'] ?? []);
    $sel_svc_ids      = array_map('intval', $_POST['th_svc_ids'] ?? []);
    $is_generalist    = isset($_POST['th_is_generalist']) ? 1 : 0;

    if (empty($full_name)) {
        $msg = 'Therapist name is required.'; $msg_type = 'danger';
    } else {
        if ($tid > 0) {
            $stmt = $conn->prepare("UPDATE therapists SET full_name=?, phone=?, specialties=?, is_generalist=? WHERE id=?");
            $stmt->bind_param("sssii", $full_name, $phone, $specialties_text, $is_generalist, $tid);
            $stmt->execute(); $stmt->close();
            $msg = "✅ Therapist <strong>{$full_name}</strong> updated.";
            log_activity($conn, 'therapist_updated', "Updated therapist: {$full_name}", 'therapist', $tid);
        } else {
            $chk = $conn->prepare("SELECT id FROM therapists WHERE LOWER(full_name)=LOWER(?)");
            $chk->bind_param("s", $full_name); $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $msg = "⚠️ A therapist named \"{$full_name}\" already exists.";
                $msg_type = 'danger'; $tid = -1;
            } else {
                $stmt = $conn->prepare("INSERT INTO therapists (full_name, phone, specialties, is_generalist) VALUES (?,?,?,?)");
                $stmt->bind_param("sssi", $full_name, $phone, $specialties_text, $is_generalist);
                $stmt->execute(); $tid = $conn->insert_id; $stmt->close();
                $msg = "✅ Therapist <strong>{$full_name}</strong> added.";
                log_activity($conn, 'therapist_added', "Added therapist: {$full_name}", 'therapist', (int)$tid);
            }
            $chk->close();
        }

        if ($tid > 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS therapist_specialty_services (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                therapist_id INT NOT NULL,
                service_id   INT NOT NULL,
                UNIQUE KEY uq_th_svc (therapist_id, service_id)
            )");

            $del = $conn->prepare("DELETE FROM therapist_specialties WHERE therapist_id=?");
            $del->bind_param("i", $tid); $del->execute(); $del->close();
            foreach ($sel_cat_ids as $cid) {
                if ($cid <= 0) continue;
                $ins = $conn->prepare("INSERT IGNORE INTO therapist_specialties (therapist_id, category_id) VALUES (?,?)");
                $ins->bind_param("ii", $tid, $cid); $ins->execute(); $ins->close();
            }

            $del2 = $conn->prepare("DELETE FROM therapist_specialty_services WHERE therapist_id=?");
            $del2->bind_param("i", $tid); $del2->execute(); $del2->close();

            // Save explicitly selected services
            foreach ($sel_svc_ids as $sid) {
                if ($sid <= 0) continue;
                $ins2 = $conn->prepare("INSERT IGNORE INTO therapist_specialty_services (therapist_id, service_id) VALUES (?,?)");
                $ins2->bind_param("ii", $tid, $sid); $ins2->execute(); $ins2->close();
            }

            // Also save all services belonging to ticked categories
            foreach ($sel_cat_ids as $cid) {
                if ($cid <= 0) continue;
                $cat_svcs = $conn->prepare("SELECT id FROM services WHERE category_id=?");
                $cat_svcs->bind_param("i", $cid); $cat_svcs->execute();
                $cat_svc_rows = $cat_svcs->get_result()->fetch_all(MYSQLI_ASSOC); $cat_svcs->close();
                foreach ($cat_svc_rows as $cs) {
                    $ins3 = $conn->prepare("INSERT IGNORE INTO therapist_specialty_services (therapist_id, service_id) VALUES (?,?)");
                    $ins3->bind_param("ii", $tid, $cs['id']); $ins3->execute(); $ins3->close();
                }
            }

            // Rebuild sel_svc_ids to include category services for missing commission check
            $all_svc_ids_stmt = $conn->prepare("SELECT service_id FROM therapist_specialty_services WHERE therapist_id=?");
            $all_svc_ids_stmt->bind_param("i", $tid); $all_svc_ids_stmt->execute();
            $sel_svc_ids = array_column($all_svc_ids_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'service_id');
            $all_svc_ids_stmt->close();

            // ── Check for missing commissions on newly assigned specialties ────
            if (!empty($sel_svc_ids)) {
                $svc_ids_str = implode(',', array_map('intval', $sel_svc_ids));
                $missing_comm = $conn->query("
                    SELECT s.name
                    FROM services s
                    WHERE s.id IN ($svc_ids_str)
                      AND s.id NOT IN (
                          SELECT service_id FROM therapist_commission
                          WHERE therapist_id = $tid
                            AND (commission_percent > 0 OR influencer_flat_rate > 0)
                      )
                ")->fetch_all(MYSQLI_ASSOC);

                if (!empty($missing_comm)) {
                    $missing_names = implode(', ', array_column($missing_comm, 'name'));
                    $msg .= "<br>⚠️ <strong>Commission not set</strong> for: <em>{$missing_names}</em>. "
                          . "Please go to the <a href='staff.php?tab=commission' style='color:var(--gold);font-weight:700;'>Commission Matrix</a> to set rates.";
                    $msg_type = 'warning';
                }
            }
        }
    }
    $active_tab = 'therapists';
}

// ── DELETE THERAPIST ──────────────────────────────────────────────────────────
if (isset($_GET['delete_therapist'])) {
    $did     = intval($_GET['delete_therapist']);
    $del_row = $conn->query("SELECT full_name FROM therapists WHERE id={$did} LIMIT 1")->fetch_assoc();
    $conn->query("DELETE FROM therapist_specialty_services WHERE therapist_id=$did");
    $stmt = $conn->prepare("DELETE FROM therapists WHERE id=?");
    $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
    if ($del_row) log_activity($conn, 'therapist_deleted', "Deleted therapist: {$del_row['full_name']}", 'therapist', $did);
    header("Location: staff.php?tab=therapists&deleted=therapist"); exit();
}

// ── ADD THERAPIST DEDUCTION ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_therapist_ded'])) {
    verify_csrf_token();
    $therapist_id = intval($_POST['ded_therapist_id'] ?? 0);
    $type         = in_array($_POST['ded_type'] ?? '', ['ca','expense']) ? $_POST['ded_type'] : 'ca';
    $label        = sanitize_input($_POST['ded_label']  ?? '');
    $amount       = floatval($_POST['ded_amount'] ?? 0);
    $notes        = sanitize_input($_POST['ded_notes']  ?? '');
    $ded_date     = $_POST['ded_date'] ?? date('Y-m-d');
    $added_by     = $_SESSION['user_id'] ?? null;

    if ($therapist_id > 0 && $amount > 0) {
        $stmt = $conn->prepare("
            INSERT INTO therapist_deductions (therapist_id, deduction_date, type, label, amount, notes, added_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssdsi", $therapist_id, $ded_date, $type, $label, $amount, $notes, $added_by);
        $ok       = $stmt->execute(); $stmt->close();
        $msg      = $ok ? "✅ Deduction recorded." : "Error saving deduction.";
        $msg_type = $ok ? "success" : "danger";
    } else {
        $msg = "Select a therapist and enter a valid amount."; $msg_type = "danger";
    }
}

// ── DELETE THERAPIST DEDUCTION ────────────────────────────────────────────────
if (isset($_GET['del_ded'])) {
    $did = intval($_GET['del_ded']);
    $stmt = $conn->prepare("DELETE FROM therapist_deductions WHERE id=?");
    $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
    header("Location: staff.php?tab=deductions"); exit();
}

// ── SAVE COMMISSION MATRIX ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_commission'])) {
    verify_csrf_token();
    $t_id    = intval($_POST['commission_therapist_id'] ?? 0);
    $svc_ids = $_POST['svc_id']      ?? [];
    $pcts    = $_POST['svc_percent'] ?? [];
    $flats   = $_POST['svc_flat']    ?? [];
    if ($t_id > 0) {
        foreach ($svc_ids as $i => $sid) {
            $sid  = intval($sid);
            $pct  = floatval($pcts[$i]  ?? 0);
            $flat = floatval($flats[$i] ?? 0);
            if ($sid <= 0) continue;
            $stmt = $conn->prepare("
                INSERT INTO therapist_commission (therapist_id, service_id, commission_percent, influencer_flat_rate)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    commission_percent   = VALUES(commission_percent),
                    influencer_flat_rate = VALUES(influencer_flat_rate)
            ");
            $stmt->bind_param("iidd", $t_id, $sid, $pct, $flat);
            $stmt->execute(); $stmt->close();
        }
        $msg = "✅ Commission rates saved.";
    }
}

// ── RECEPTIONIST PIN TABLE ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS receptionist_pins (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    full_name    VARCHAR(120) NOT NULL,
    phone        VARCHAR(20)  DEFAULT NULL,
    pin          CHAR(4)      NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rpin (pin)
)");

// ── CREATE RECEPTIONIST PIN ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_receptionist_pin'])) {
    verify_csrf_token();
    $rp_name  = trim($_POST['rp_full_name'] ?? '');
    $rp_phone = trim($_POST['rp_phone']     ?? '');
    $rp_pin   = trim($_POST['rp_pin']       ?? '');
    $r_errors = [];
    if (empty($rp_name))  $r_errors[] = 'Full name is required.';
    if (empty($rp_pin) || !ctype_digit($rp_pin) || strlen($rp_pin) !== 4)
        $r_errors[] = 'PIN must be exactly 4 digits.';
    if (empty($r_errors)) {
        $chk = $conn->prepare("SELECT id FROM receptionist_pins WHERE pin=?");
        $chk->bind_param("s", $rp_pin); $chk->execute();
        if ($chk->get_result()->num_rows > 0) $r_errors[] = 'That PIN is already taken. Choose another.';
        $chk->close();
    }
    if (empty($r_errors)) {
        $stmt = $conn->prepare("INSERT INTO receptionist_pins (full_name, phone, pin) VALUES (?,?,?)");
        $stmt->bind_param("sss", $rp_name, $rp_phone, $rp_pin);
        $stmt->execute(); $stmt->close();
        $msg = "✅ Receptionist PIN created for <strong>{$rp_name}</strong>.";
    } else {
        $msg = implode('<br>', $r_errors); $msg_type = 'danger';
    }
}

// ── UPDATE RECEPTIONIST PIN ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_receptionist_pin'])) {
    verify_csrf_token();
    $rp_id    = intval($_POST['rp_id']      ?? 0);
    $rp_name  = trim($_POST['rp_full_name'] ?? '');
    $rp_phone = trim($_POST['rp_phone']     ?? '');
    $rp_pin   = trim($_POST['rp_pin']       ?? '');
    if ($rp_id > 0 && !empty($rp_name) && ctype_digit($rp_pin) && strlen($rp_pin) === 4) {
        $chk = $conn->prepare("SELECT id FROM receptionist_pins WHERE pin=? AND id != ?");
        $chk->bind_param("si", $rp_pin, $rp_id); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $msg = 'That PIN is already used by another receptionist.'; $msg_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE receptionist_pins SET full_name=?, phone=?, pin=? WHERE id=?");
            $stmt->bind_param("sssi", $rp_name, $rp_phone, $rp_pin, $rp_id);
            $stmt->execute(); $stmt->close();
            $msg = "✅ Receptionist PIN updated.";
        }
        $chk->close();
    } else {
        $msg = 'Invalid data. Ensure PIN is exactly 4 digits.'; $msg_type = 'danger';
    }
}

// ── DELETE RECEPTIONIST PIN ───────────────────────────────────────────────────
if (isset($_GET['delete_receptionist'])) {
    $del_rp = intval($_GET['delete_receptionist']);
    $stmt   = $conn->prepare("DELETE FROM receptionist_pins WHERE id=?");
    $stmt->bind_param("i", $del_rp); $stmt->execute(); $stmt->close();
    $msg = '🗑️ Receptionist PIN removed.';
}

// ═══════════════════════════════════════════════════════════════════════════════
// FETCH DATA
// ═══════════════════════════════════════════════════════════════════════════════

// ── Ensure system_settings table exists (safe even if migration not run yet) ──
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT          NULL,
    updated_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Ensure session columns exist on users table (MySQL 5.7 safe) ─────────────
$col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'session_token'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN session_token   VARCHAR(64) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN session_started DATETIME    NULL DEFAULT NULL");
}

$staff_list = $conn->query("
    SELECT id, username, email, full_name, phone, admin_role, cashier_pin, created_at
    FROM users WHERE role='admin'
    ORDER BY admin_role ASC, created_at ASC
")->fetch_all(MYSQLI_ASSOC);

$owners     = array_filter($staff_list, fn($s) => $s['admin_role'] === 'owner');
$cashiers   = array_filter($staff_list, fn($s) => $s['admin_role'] === 'cashier');
$marketings = array_filter($staff_list, fn($s) => $s['admin_role'] === 'marketing');
$it_staff   = array_filter($staff_list, fn($s) => $s['admin_role'] === 'it');

// Receptionist PIN profiles
$receptionist_pins = $conn->query("SELECT * FROM receptionist_pins ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// All therapists with date-range commission + ratings
$_at = $conn->prepare("
    SELECT t.id, t.full_name, t.phone, t.specialties,
           IFNULL(AVG(tr.rating), 0) AS avg_rating,
           COUNT(tr.id)              AS total_ratings,
           IFNULL((
               SELECT SUM(at2.commission)
               FROM appointment_therapists at2
               JOIN appointments ap ON at2.appointment_id = ap.id
               WHERE at2.therapist_id = t.id
                 AND ap.status = 'completed'
                 AND DATE(ap.appointment_date) BETWEEN ? AND ?
           ), 0) AS base_commission,
           IFNULL((
               SELECT SUM(aes.commission)
               FROM appointment_extra_services aes
               JOIN appointments ap2 ON aes.appointment_id = ap2.id
               WHERE aes.therapist_id = t.id
                 AND ap2.status = 'completed'
                 AND DATE(ap2.appointment_date) BETWEEN ? AND ?
           ), 0) AS addon_commission
    FROM therapists t
    LEFT JOIN therapist_ratings tr ON tr.therapist_id = t.id
    GROUP BY t.id
    ORDER BY t.full_name ASC
");
$cf = $comm_from; $ct = $comm_to;
$_at->bind_param("ssss", $cf, $ct, $cf, $ct);
$_at->execute();
$all_therapists = $_at->get_result()->fetch_all(MYSQLI_ASSOC);
$_at->close();
foreach ($all_therapists as &$_th) {
    $_th['period_commission'] = (float)$_th['base_commission'] + (float)$_th['addon_commission'];
}
unset($_th);

// Recent deductions log — pay period
$_dq = $conn->prepare("
    SELECT td.*, t.full_name
    FROM therapist_deductions td
    JOIN therapists t ON td.therapist_id = t.id
    WHERE td.deduction_date BETWEEN ? AND ?
    ORDER BY td.deduction_date DESC, td.created_at DESC
    LIMIT 100
");
$_dq->bind_param("ss", $period_start, $period_end);
$_dq->execute();
$recent_deds = $_dq->get_result()->fetch_all(MYSQLI_ASSOC);
$_dq->close();

// Per-therapist CA totals — pay period
$ded_totals_by_therapist = [];
if (!empty($all_therapists)) {
    // IDs are INT-cast from DB rows; IN clause with intval() is safe
    $tids_for_deds = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists));
    $_dt = $conn->prepare("
        SELECT therapist_id,
               SUM(amount)                                           AS total_all,
               SUM(CASE WHEN type='ca'      THEN amount ELSE 0 END) AS total_ca,
               SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
        FROM therapist_deductions
        WHERE therapist_id IN ($tids_for_deds)
          AND deduction_date BETWEEN ? AND ?
        GROUP BY therapist_id
    ");
    $_dt->bind_param("ss", $period_start, $period_end);
    $_dt->execute();
    $_dt_rows = $_dt->get_result();
    while ($r = $_dt_rows->fetch_assoc()) {
        $ded_totals_by_therapist[$r['therapist_id']] = $r;
    }
    $_dt->close();
}

// Per-therapist commission for pay period — pre-built to avoid N+1 in the HTML loop
// Two aggregate queries (base + add-on) instead of one correlated query per therapist.
$ded_commission_by_therapist = [];
if (!empty($all_therapists)) {
    $tids_for_comm = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists));

    $_bc = $conn->prepare("
        SELECT at2.therapist_id, IFNULL(SUM(at2.commission), 0) AS base_comm
        FROM appointment_therapists at2
        JOIN appointments ap ON at2.appointment_id = ap.id
        WHERE at2.therapist_id IN ($tids_for_comm)
          AND ap.status = 'completed'
          AND DATE(ap.appointment_date) BETWEEN ? AND ?
        GROUP BY at2.therapist_id
    ");
    $_bc->bind_param("ss", $period_start, $period_end);
    $_bc->execute();
    $_bc_rows = $_bc->get_result();
    while ($r = $_bc_rows->fetch_assoc()) {
        $ded_commission_by_therapist[$r['therapist_id']] = (float)$r['base_comm'];
    }
    $_bc->close();

    $_ac = $conn->prepare("
        SELECT aes.therapist_id, IFNULL(SUM(aes.commission), 0) AS addon_comm
        FROM appointment_extra_services aes
        JOIN appointments ap ON aes.appointment_id = ap.id
        WHERE aes.therapist_id IN ($tids_for_comm)
          AND ap.status = 'completed'
          AND DATE(ap.appointment_date) BETWEEN ? AND ?
        GROUP BY aes.therapist_id
    ");
    $_ac->bind_param("ss", $period_start, $period_end);
    $_ac->execute();
    $_ac_rows = $_ac->get_result();
    while ($r = $_ac_rows->fetch_assoc()) {
        $ded_commission_by_therapist[$r['therapist_id']] =
            ($ded_commission_by_therapist[$r['therapist_id']] ?? 0) + (float)$r['addon_comm'];
    }
    $_ac->close();
}

// Commission matrix data
$commission_services = $conn->query("
    SELECT s.id, s.name, s.price, IFNULL(c.name,'Uncategorized') AS category_name
    FROM services s
    LEFT JOIN categories c ON s.category_id = c.id
    ORDER BY c.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$svc_by_cat = [];
foreach ($commission_services as $svc) $svc_by_cat[$svc['category_name']][] = $svc;

$saved_commissions = [];
if (!empty($all_therapists)) {
    $tids = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists));
    $rows = $conn->query("
        SELECT therapist_id, service_id, commission_percent, influencer_flat_rate
        FROM therapist_commission WHERE therapist_id IN ($tids)
    ");
    while ($r = $rows->fetch_assoc()) {
        $saved_commissions[$r['therapist_id']][$r['service_id']] = [
            'pct'  => $r['commission_percent'],
            'flat' => $r['influencer_flat_rate'],
        ];
    }
}

// ── Determine active tab ──────────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? (isset($_GET['edit_therapist']) ? 'therapists' : 'cashiers');

// ── Ensure specialty tables exist ────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS therapist_specialties (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id INT NOT NULL,
    category_id  INT NOT NULL,
    UNIQUE KEY uq_th_cat (therapist_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS therapist_specialty_services (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id INT NOT NULL,
    service_id   INT NOT NULL,
    UNIQUE KEY uq_th_svc (therapist_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS therapist_ratings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id INT NOT NULL,
    rating       DECIMAL(3,1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS therapist_deductions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id    INT NOT NULL,
    type            ENUM('ca','expense') NOT NULL DEFAULT 'ca',
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
    label           VARCHAR(255) NULL,
    notes           TEXT NULL,
    deduction_date  DATE NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS therapist_commission (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id        INT NOT NULL,
    service_id          INT NOT NULL,
    commission_percent  DECIMAL(5,2) NOT NULL DEFAULT 0,
    influencer_flat_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_tc (therapist_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS therapist_attendance (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    therapist_id   INT NOT NULL,
    duty_date      DATE NOT NULL,
    time_in        TIME NULL,
    time_out       TIME NULL,
    rotation_order INT NOT NULL DEFAULT 1,
    is_on_break    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_ta (therapist_id, duty_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Categories with services (for specialty accordion) ────────────────────────
$cats_with_services = [];
$cat_res = $conn->query("
    SELECT c.id AS cat_id, c.name AS cat_name,
           s.id AS svc_id, s.name AS svc_name
    FROM categories c
    JOIN services s ON s.category_id = c.id
    WHERE c.type = 'service'
    ORDER BY c.name ASC, s.name ASC
");
while ($row = $cat_res->fetch_assoc()) {
    $cats_with_services[$row['cat_id']]['name']       = $row['cat_name'];
    $cats_with_services[$row['cat_id']]['services'][] = [
        'id'   => $row['svc_id'],
        'name' => $row['svc_name'],
    ];
}

// ── Therapist specialty maps ──────────────────────────────────────────────────
$th_cat_map = [];
$th_svc_map = [];
if (!empty($all_therapists)) {
    $tids_str = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists));

    $cr = $conn->query("SELECT therapist_id, category_id FROM therapist_specialties WHERE therapist_id IN ($tids_str)");
    while ($r = $cr->fetch_assoc()) $th_cat_map[$r['therapist_id']][] = (int)$r['category_id'];

    $sr = $conn->query("SELECT therapist_id, service_id FROM therapist_specialty_services WHERE therapist_id IN ($tids_str)");
    while ($r = $sr->fetch_assoc()) $th_svc_map[$r['therapist_id']][] = (int)$r['service_id'];
}

// ── Edit therapist (GET) ──────────────────────────────────────────────────────
$edit_therapist = null;
if (isset($_GET['edit_therapist'])) {
    $edit_therapist_id = intval($_GET['edit_therapist']);
    $stmt = $conn->prepare("SELECT * FROM therapists WHERE id=?");
    $stmt->bind_param("i", $edit_therapist_id); $stmt->execute();
    $edit_therapist = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $active_tab = 'therapists';
}

if (isset($_GET['deleted']) && $_GET['deleted'] === 'therapist') {
    $msg = '🗑️ Therapist removed.'; $active_tab = 'therapists';
}
if (isset($_GET['delete_receptionist'])) {
    $active_tab = 'receptionists';
}

$page_title  = 'Staff';
$page_icon   = '🪪';
$active_page = 'staff';
require_once 'admin_header.php';
?>

<?php if (!empty($msg)): ?>
<div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom:1.5rem;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- ── TAB NAV ────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:0.5rem;margin-bottom:1.5rem;
            border-bottom:2px solid var(--border2);padding-bottom:0;flex-wrap:wrap;">
    <?php
    $tabs = [
        'cashiers'      => ['🏪 Staff Accounts',        count($cashiers) + count($marketings) + count($it_staff)],
        'receptionists' => ['📋 Receptionists',        count($receptionist_pins)],
        'therapists'    => ['💆 Therapists',           count($all_therapists)],
        'commission'    => ['💰 Commission Matrix',    null],
        'deductions'    => ['💸 CA & Deductions',      null],
    ];
    foreach ($tabs as $tab_key => [$tab_label, $count]):
        $is_active = $active_tab === $tab_key;
    ?>
    <a href="staff.php?tab=<?php echo $tab_key; ?>"
       style="padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:700;text-decoration:none;
              border-radius:8px 8px 0 0;border:2px solid var(--border2);border-bottom:none;
              background:<?php echo $is_active ? 'var(--brown)' : 'var(--bg3)'; ?>;
              color:<?php echo $is_active ? '#fff' : 'var(--brown)'; ?>;
              margin-bottom:-2px;">
        <?php echo $tab_label; ?>
        <?php if ($count !== null): ?>
        <span style="background:<?php echo $is_active ? 'rgba(255,255,255,0.25)' : 'var(--border2)'; ?>;
                     padding:0.05rem 0.45rem;border-radius:20px;font-size:0.72rem;margin-left:0.3rem;">
            <?php echo $count; ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: CASHIERS
══════════════════════════════════════════════════════════════════════════ -->
<?php if ($active_tab === 'cashiers'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.5rem;align-items:start;">

    <!-- Create Account Form -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">➕ Create Account</span>
        </div>
        <div class="panel-body" style="padding:1.25rem;">
            <?php $_allowed_create = creatable_roles(current_admin_role()); ?>
            <?php if (!empty($_allowed_create)): ?>
            <?php $_first_create_role = $_allowed_create[0]; ?>
            <form method="POST" id="cashierForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="role_type" id="role_type_input" value="<?php echo htmlspecialchars($_first_create_role); ?>">

                <!-- Role selector — only shows roles the current admin may create -->
                <?php
                $_role_defs = [
                    'cashier' => [
                        'icon' => '📋 Receptionist', 'sub' => 'Limited access',
                        'sub_color'   => 'var(--rust)',
                        'note_bg'     => 'rgba(201,106,44,0.08)', 'note_border' => 'var(--rust)',
                        'note_color'  => 'var(--brown)',
                        'note_text'   => '📋 <strong>Receptionist:</strong> Appointments, orders, walk-in, and expenses. Cannot access analytics, products, services, or staff management.',
                    ],
                    'marketing' => [
                        'icon' => '📣 Marketing', 'sub' => 'Full access',
                        'sub_color'   => 'var(--gray)',
                        'note_bg'     => 'rgba(22,163,74,0.07)', 'note_border' => '#16a34a',
                        'note_color'  => '#14532d',
                        'note_text'   => '📣 <strong>Marketing:</strong> Full access to all pages — dashboard, analytics, services, products, users, staff, discounts, and reports.',
                    ],
                    'it' => [
                        'icon' => '💻 IT Support', 'sub' => 'Full access',
                        'sub_color'   => 'var(--gray)',
                        'note_bg'     => 'rgba(37,99,235,0.07)', 'note_border' => '#2563eb',
                        'note_color'  => '#1e3a8a',
                        'note_text'   => '💻 <strong>IT Support:</strong> Full access to all pages — same as Marketing. Intended for system maintenance and technical management.',
                    ],
                ];
                $_col_str = implode(' ', array_fill(0, count($_allowed_create), '1fr'));
                ?>
                <div style="margin-bottom:1rem;">
                    <label style="font-size:0.78rem;font-weight:700;color:var(--brown);display:block;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.05em;">Account Type *</label>
                    <div style="display:grid;grid-template-columns:<?php echo $_col_str; ?>;gap:0.5rem;">
                        <?php foreach ($_allowed_create as $_ri => $_rk):
                            $_rd = $_role_defs[$_rk];
                            $_active = ($_ri === 0);
                        ?>
                        <button type="button" id="role-btn-<?php echo $_rk; ?>"
                                onclick="selectRole('<?php echo $_rk; ?>')"
                                style="padding:0.6rem 0.4rem;border:2px solid <?php echo $_active ? 'var(--gold)' : 'var(--border2)'; ?>;border-radius:8px;
                                       background:<?php echo $_active ? '#fff8f2' : 'var(--bg3)'; ?>;cursor:pointer;text-align:center;font-size:0.78rem;font-weight:700;
                                       color:var(--brown);line-height:1.4;">
                            <?php echo $_rd['icon']; ?><br>
                            <span style="font-size:0.68rem;font-weight:400;color:<?php echo $_rd['sub_color']; ?>;"><?php echo $_rd['sub']; ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Access notes — only for allowed roles; first one visible by default -->
                <?php foreach ($_allowed_create as $_ri => $_rk):
                    $_rd = $_role_defs[$_rk];
                ?>
                <div id="access-note-<?php echo $_rk; ?>"
                     style="<?php echo $_ri > 0 ? 'display:none;' : ''; ?>background:<?php echo $_rd['note_bg']; ?>;border-left:3px solid <?php echo $_rd['note_border']; ?>;
                            padding:0.65rem 1rem;border-radius:6px;font-size:0.78rem;color:<?php echo $_rd['note_color']; ?>;margin-bottom:1rem;">
                    <?php echo $_rd['note_text']; ?>
                </div>
                <?php endforeach; ?>
                <div id="fullname-field" style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Full Name *</label>
                    <input type="text" name="full_name" id="fullname_input" required placeholder="e.g. Maria Santos"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Username *</label>
                        <input type="text" name="username" required placeholder="cashier_maria"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                      background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Phone</label>
                        <input type="text" name="phone" placeholder="09XXXXXXXXX"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                      background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                    </div>
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Email *</label>
                    <input type="email" name="email" required placeholder="cashier@recoveryiloilo.com"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Password *</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="pwdInput" required
                               style="width:100%;padding:0.5rem 2.5rem 0.5rem 0.75rem;border:1px solid var(--border2);
                                      border-radius:8px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;"
                               oninput="checkPwd(this.value)">
                        <button type="button" onclick="togglePwd('pwdInput')"
                                style="position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);
                                       background:none;border:none;cursor:pointer;color:var(--gray);font-size:0.9rem;">👁</button>
                    </div>
                    <div style="margin-top:0.45rem;display:grid;grid-template-columns:1fr 1fr;gap:0.2rem;">
                        <div id="chk-len"   style="font-size:0.72rem;color:var(--gray);">✗ 8+ characters</div>
                        <div id="chk-upper" style="font-size:0.72rem;color:var(--gray);">✗ Uppercase</div>
                        <div id="chk-num"   style="font-size:0.72rem;color:var(--gray);">✗ Number</div>
                        <div id="chk-spec"  style="font-size:0.72rem;color:var(--gray);">✗ Special char</div>
                    </div>
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Confirm Password *</label>
                    <input type="password" name="confirm_password" required
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <button type="submit" name="create_cashier" class="btn btn-primary" style="width:100%;">
                    ➕ Create Account
                </button>
            </form>
            <?php else: ?>
            <div style="text-align:center;padding:2rem;color:var(--gray);">
                <div style="font-size:2rem;margin-bottom:0.5rem;">🔒</div>
                You do not have permission to create admin accounts.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Staff list -->
    <div style="display:flex;flex-direction:column;gap:1.25rem;">

        <!-- Owner accounts -->
        <div class="panel">
            <div class="panel-header"><span class="panel-title">👑 Owner Accounts</span></div>
            <div class="table-wrap" style="border:none;border-radius:0;">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Email</th><th>Since</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($owners as $s): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:linear-gradient(135deg,#C96A2C,#3B2A1A);
                                            color:#fff;display:flex;align-items:center;justify-content:center;
                                            font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                    <?php echo strtoupper(substr($s['full_name'],0,1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($s['phone'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars($s['username']); ?></td>
                        <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($s['email']); ?></td>
                        <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Receptionist accounts -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">📋 Receptionist Accounts (<?php echo count($cashiers); ?>)</span>
            </div>
            <?php if (empty($cashiers)): ?>
            <div class="panel-body" style="text-align:center;padding:2rem;color:var(--gray);">
                <div style="font-size:2rem;margin-bottom:0.4rem;">📋</div>No receptionist accounts yet.
            </div>
            <?php else: ?>
            <div class="table-wrap" style="border:none;border-radius:0;">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Email</th><th>Since</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cashiers as $s): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:linear-gradient(135deg,#C96A2C,#92400e);
                                            color:#fff;display:flex;align-items:center;justify-content:center;
                                            font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                    <?php echo strtoupper(substr($s['full_name'],0,1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($s['phone'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars($s['username']); ?></td>
                        <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($s['email']); ?></td>
                        <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                        <td>
                            <?php if ($s['id'] !== (int)$_SESSION['user_id']): ?>
                            <a href="staff.php?delete_cashier=<?php echo $s['id']; ?>&tab=cashiers"
                               class="btn btn-danger btn-sm" style="font-size:0.72rem;"
                               onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($s['full_name'])); ?>?')">✕</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Marketing accounts -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">📣 Marketing Accounts (<?php echo count($marketings); ?>)</span>
            </div>
            <?php if (empty($marketings)): ?>
            <div class="panel-body" style="text-align:center;padding:1.5rem;color:var(--gray);">
                <div style="font-size:1.75rem;margin-bottom:0.3rem;">📣</div>No marketing accounts yet.
            </div>
            <?php else: ?>
            <div class="table-wrap" style="border:none;border-radius:0;">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Email</th><th>Since</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($marketings as $s): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:linear-gradient(135deg,#16a34a,#14532d);
                                            color:#fff;display:flex;align-items:center;justify-content:center;
                                            font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                    <?php echo strtoupper(substr($s['full_name'],0,1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($s['phone'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars($s['username']); ?></td>
                        <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($s['email']); ?></td>
                        <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                        <td>
                            <?php if ($s['id'] !== (int)$_SESSION['user_id']): ?>
                            <a href="staff.php?delete_cashier=<?php echo $s['id']; ?>&tab=cashiers"
                               class="btn btn-danger btn-sm" style="font-size:0.72rem;"
                               onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($s['full_name'])); ?>?')">✕</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- IT Support accounts -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">💻 IT Support Accounts (<?php echo count($it_staff); ?>)</span>
            </div>
            <?php if (empty($it_staff)): ?>
            <div class="panel-body" style="text-align:center;padding:1.5rem;color:var(--gray);">
                <div style="font-size:1.75rem;margin-bottom:0.3rem;">💻</div>No IT support accounts yet.
            </div>
            <?php else: ?>
            <div class="table-wrap" style="border:none;border-radius:0;">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Email</th><th>Since</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($it_staff as $s): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:linear-gradient(135deg,#2563eb,#1e3a8a);
                                            color:#fff;display:flex;align-items:center;justify-content:center;
                                            font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                    <?php echo strtoupper(substr($s['full_name'],0,1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($s['phone'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars($s['username']); ?></td>
                        <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($s['email']); ?></td>
                        <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                        <td>
                            <?php if ($s['id'] !== (int)$_SESSION['user_id']): ?>
                            <a href="staff.php?delete_cashier=<?php echo $s['id']; ?>&tab=cashiers"
                               class="btn btn-danger btn-sm" style="font-size:0.72rem;"
                               onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($s['full_name'])); ?>?')">✕</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Receptionist Login Settings (full-width, bottom of cashiers tab) ──── -->
<div class="panel" style="margin-top:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">⚙️ Receptionist Login Settings</span>
    </div>
    <div class="panel-body" style="padding:1.25rem;">
        <?php
        $rs = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'receptionist_%'");
        $rsettings = [];
        if ($rs) while ($r = $rs->fetch_assoc()) $rsettings[$r['setting_key']] = $r['setting_value'];
        $r_start = $rsettings['receptionist_login_start'] ?? '07:00';
        $r_end   = $rsettings['receptionist_login_end']   ?? '23:59';
        $r_tz    = $rsettings['receptionist_timezone']    ?? 'Asia/Manila';
        $r_acc   = $conn->query("SELECT id, full_name, session_token, session_started FROM users WHERE admin_role='cashier' AND role='admin' AND deleted_at IS NULL LIMIT 1")->fetch_assoc();
        $r_active = !empty($r_acc['session_token']) && !empty($r_acc['session_started'])
                    && ((time() - strtotime($r_acc['session_started'])) / 3600) < 8;
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

            <!-- Time window form -->
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">🕐 Login Start</label>
                        <input type="time" name="r_login_start" value="<?php echo htmlspecialchars($r_start); ?>"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">🕐 Login End</label>
                        <input type="time" name="r_login_end" value="<?php echo htmlspecialchars($r_end); ?>"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                    </div>
                </div>
                <div style="background:rgba(201,106,44,0.07);border-left:3px solid var(--gold);padding:0.6rem 0.85rem;border-radius:6px;font-size:0.78rem;color:var(--brown);margin-bottom:0.75rem;">
                    📌 Current window: <strong><?php echo date('h:i A', strtotime($r_start)); ?></strong> → <strong><?php echo date('h:i A', strtotime($r_end)); ?></strong> (<?php echo htmlspecialchars($r_tz); ?>)
                </div>
                <button type="submit" name="save_receptionist_settings" class="btn btn-primary" style="width:100%;">
                    💾 Save Login Settings
                </button>
            </form>

            <!-- Active session + rules -->
            <div>
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.5rem;">Active Session</div>
                <?php if ($r_acc): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;
                            background:<?php echo $r_active ? '#f0fdf4' : 'var(--bg3)'; ?>;
                            border:1px solid <?php echo $r_active ? '#86efac' : 'var(--border2)'; ?>;
                            border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:0.85rem;">
                    <div>
                        <div style="font-size:0.82rem;font-weight:600;color:var(--brown);">
                            <?php echo $r_active ? '🟢' : '⚪'; ?> <?php echo htmlspecialchars($r_acc['full_name']); ?>
                        </div>
                        <div style="font-size:0.72rem;color:var(--gray);margin-top:0.2rem;">
                            <?php if ($r_active): ?>
                                Logged in <?php echo round((time() - strtotime($r_acc['session_started'])) / 3600, 1); ?>h ago · <?php echo date('M d, h:i A', strtotime($r_acc['session_started'])); ?>
                            <?php else: ?>
                                No active session
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($r_active): ?>
                    <a href="staff.php?tab=cashiers&clear_r_session=<?php echo $r_acc['id']; ?>"
                       onclick="return confirm('Force logout the receptionist?')"
                       style="background:#fee2e2;color:#b91c1c;padding:0.35rem 0.7rem;border-radius:6px;font-size:0.75rem;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;">
                        🔴 Force Logout
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="font-size:0.8rem;color:var(--gray);margin-bottom:0.85rem;">No receptionist account exists yet.</div>
                <?php endif; ?>

                <div style="padding:0.65rem 0.85rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:0.75rem;color:#0369a1;line-height:1.7;">
                    💡 <strong>Rules enforced:</strong><br>
                    • Only <strong>1 receptionist account</strong> allowed<br>
                    • Only <strong>1 active session</strong> at a time<br>
                    • Login only within configured time window<br>
                    • Sessions auto-expire after <strong>8 hours</strong>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: RECEPTIONISTS
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'receptionists'): ?>
<div style="display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start;">

    <!-- ── CREATE FORM ───────────────────────────────────────────────────────── -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">➕ Add Receptionist PIN</span>
        </div>
        <div class="panel-body" style="padding:1.25rem;">
            <div style="background:rgba(59,42,26,0.06);border-left:3px solid var(--gold);
                        padding:0.75rem 1rem;border-radius:6px;font-size:0.82rem;color:var(--brown);margin-bottom:1rem;line-height:1.6;">
                📋 <strong>How it works:</strong> All receptionists share one login account for the PC.
                Each individual gets a personal 4-digit PIN used only for <em>accountability</em> —
                so when updating appointment or order status, the system knows <em>which receptionist</em>
                made the change. No re-login needed when swapping shifts.
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="create_receptionist_pin" value="1">
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Full Name *</label>
                    <input type="text" name="rp_full_name" required maxlength="120" placeholder="e.g. Ana Reyes"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Phone Number</label>
                    <input type="text" name="rp_phone" maxlength="20" placeholder="09XXXXXXXXX"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">
                        4-Digit PIN <span style="color:var(--rust);">*</span>
                    </label>
                    <input type="text" name="rp_pin" maxlength="4" pattern="\d{4}" required
                           placeholder="e.g. 2481" inputmode="numeric"
                           style="width:120px;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;
                                  background:var(--bg3);color:var(--brown);font-size:1.1rem;
                                  letter-spacing:0.3em;text-align:center;">
                    <div style="font-size:0.72rem;color:var(--gray);margin-top:0.25rem;">
                        Must be unique across all receptionists.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    ➕ Create Receptionist PIN
                </button>
            </form>
        </div>
    </div>

    <!-- ── RECEPTIONIST PIN LIST ──────────────────────────────────────────── -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">📋 Receptionist PINs (<?php echo count($receptionist_pins); ?>)</span>
        </div>
        <?php if (empty($receptionist_pins)): ?>
        <div class="panel-body" style="text-align:center;padding:2.5rem;color:var(--gray);">
            <div style="font-size:2rem;margin-bottom:0.4rem;">📋</div>
            No receptionist PINs yet.<br>
            <span style="font-size:0.8rem;">Add one using the form on the left.</span>
        </div>
        <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead>
                    <tr>
                        <th>Receptionist</th>
                        <th>Phone</th>
                        <th style="text-align:center;">PIN</th>
                        <th>Since</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($receptionist_pins as $rp): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.6rem;">
                            <div style="width:34px;height:34px;border-radius:50%;
                                        background:linear-gradient(135deg,#6d28d9,#4c1d95);
                                        color:#fff;display:flex;align-items:center;justify-content:center;
                                        font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                <?php echo strtoupper(substr($rp['full_name'],0,1)); ?>
                            </div>
                            <span style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($rp['full_name']); ?></span>
                        </div>
                    </td>
                    <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($rp['phone'] ?? '—'); ?></td>
                    <td style="text-align:center;">
                        <form method="POST" style="display:flex;align-items:center;gap:0.35rem;justify-content:center;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="rp_id" value="<?php echo $rp['id']; ?>">
                            <input type="hidden" name="rp_full_name" value="<?php echo htmlspecialchars($rp['full_name']); ?>">
                            <input type="hidden" name="rp_phone"     value="<?php echo htmlspecialchars($rp['phone'] ?? ''); ?>">
                            <input type="text" name="rp_pin" maxlength="4" pattern="\d{4}"
                                   value="<?php echo htmlspecialchars($rp['pin']); ?>"
                                   inputmode="numeric"
                                   style="width:64px;padding:0.3rem 0.5rem;border:1px solid var(--border2);
                                          border-radius:6px;background:var(--bg3);color:var(--brown);
                                          font-size:0.9rem;text-align:center;letter-spacing:0.25em;">
                            <button type="submit" name="update_receptionist_pin" class="btn btn-secondary btn-sm"
                                    style="font-size:0.72rem;padding:0.25rem 0.5rem;">💾</button>
                        </form>
                    </td>
                    <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($rp['created_at'])); ?></td>
                    <td>
                        <a href="staff.php?delete_receptionist=<?php echo $rp['id']; ?>&tab=receptionists"
                           class="btn btn-danger btn-sm" style="font-size:0.72rem;"
                           onclick="return confirm('Remove PIN for <?php echo htmlspecialchars(addslashes($rp['full_name'])); ?>?')">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- How-to box -->
        <div style="padding:1rem 1.25rem;border-top:1px solid var(--border2);
                    background:rgba(109,40,217,0.04);">
            <div style="font-size:0.78rem;color:var(--brown);font-weight:700;margin-bottom:0.4rem;">
                🔐 Receptionist Shift Flow
            </div>
            <div style="font-size:0.78rem;color:var(--gray);line-height:1.7;">
                1. All receptionists log in using the shared <strong>Cashier/Receptionist account</strong> — one login for the front desk PC.<br>
                2. When updating an appointment or order status, a <strong>PIN prompt</strong> appears.<br>
                3. The receptionist on duty enters their personal 4-digit PIN for accountability.<br>
                4. When swapping shifts, no re-login needed — next receptionist simply uses their PIN.
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: THERAPISTS
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'therapists'): ?>
<?php if ($edit_therapist): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('therapist-form-panel');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>
<?php endif; ?>
<div style="display:grid;grid-template-columns:400px 1fr;gap:1.5rem;align-items:start;">

    <!-- ── ADD / EDIT FORM ────────────────────────────────────────────────── -->
    <div class="panel" id="therapist-form-panel"
         style="<?php echo $edit_therapist ? 'border:2px solid var(--gold);' : ''; ?>">
        <div class="panel-header"
             style="<?php echo $edit_therapist ? 'background:linear-gradient(135deg,var(--brown),var(--rust));' : ''; ?>">
            <span class="panel-title" style="<?php echo $edit_therapist ? 'color:#fff;' : ''; ?>">
                <?php echo $edit_therapist ? '✏️ Edit Therapist' : '➕ Add Therapist'; ?>
            </span>
            <?php if ($edit_therapist): ?>
            <a href="staff.php?tab=therapists"
               style="font-size:0.78rem;color:rgba(255,255,255,0.8);text-decoration:none;
                      border:1px solid rgba(255,255,255,0.4);padding:0.2rem 0.6rem;border-radius:6px;">
                ✕ Cancel
            </a>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="padding:1.25rem;">
            <?php
            $et_id   = $edit_therapist['id'] ?? 0;
            $et_cats = $et_id ? ($th_cat_map[$et_id] ?? []) : [];
            $et_svcs = $et_id ? ($th_svc_map[$et_id] ?? []) : [];
            ?>
            <?php if ($edit_therapist): ?>
            <div style="background:rgba(201,106,44,0.1);border:2px solid var(--gold);
                        border-radius:10px;padding:0.75rem 1rem;margin-bottom:1rem;
                        display:flex;align-items:center;gap:0.75rem;">
                <span style="font-size:1.5rem;">✏️</span>
                <div>
                    <div style="font-weight:800;color:var(--brown);font-size:0.9rem;">
                        Editing: <?php echo htmlspecialchars($edit_therapist['full_name']); ?>
                    </div>
                    <div style="font-size:0.75rem;color:var(--rust);">
                        Update the fields below then click 💾 Save Changes.
                        <a href="staff.php?tab=therapists" style="color:var(--gray);margin-left:0.5rem;">✕ Cancel edit</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:0.85rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_therapist" value="1">
                <input type="hidden" name="therapist_id"  value="<?php echo $et_id; ?>">

                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">
                        Full Name <span style="color:var(--rust);">*</span>
                    </label>
                    <input type="text" name="th_full_name" required maxlength="120"
                           placeholder="e.g. Maria Santos"
                           value="<?php echo htmlspecialchars($edit_therapist['full_name'] ?? ''); ?>"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--brown);
                                  font-size:0.85rem;box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Phone</label>
                    <input type="text" name="th_phone" maxlength="30" placeholder="09XX-XXX-XXXX"
                           value="<?php echo htmlspecialchars($edit_therapist['phone'] ?? ''); ?>"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--brown);
                                  font-size:0.85rem;box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Notes / Experience</label>
                    <input type="text" name="th_specialties" maxlength="255"
                           placeholder="e.g. 5 years experience"
                           value="<?php echo htmlspecialchars($edit_therapist['specialties'] ?? ''); ?>"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--brown);
                                  font-size:0.85rem;box-sizing:border-box;">
                </div>

                <!-- ── Specialty Accordion ──────────────────────────────── -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:6px;">
                        💆 Service Specialties
                        <span style="font-weight:400;color:var(--gray);font-size:0.72rem;">
                            — tick a category for all services, or expand to pick specific ones
                        </span>
                    </label>

                    <?php if (empty($cats_with_services)): ?>
                    <div style="font-size:0.8rem;color:var(--gray);padding:0.65rem;
                                background:var(--bg3);border-radius:8px;border:1px solid var(--border2);">
                        No service categories yet. <a href="categories.php" style="color:var(--gold);">Add categories →</a>
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;">
                        <?php foreach ($cats_with_services as $cid => $cdata):
                            $cat_checked      = in_array($cid, $et_cats);
                            $svc_ids_in_cat   = array_column($cdata['services'], 'id');
                            $svcs_selected    = array_intersect($et_svcs, $svc_ids_in_cat);
                            $all_svcs_checked = !empty($svc_ids_in_cat) && count($svcs_selected) === count($svc_ids_in_cat);
                            $cat_box_checked  = $cat_checked || $all_svcs_checked;
                            $panel_open       = $cat_checked || !empty($svcs_selected);
                        ?>
                        <div class="sp-cat-block"
                             style="border:1px solid var(--border2);border-radius:10px;overflow:hidden;">

                            <!-- Category header -->
                            <div style="display:flex;align-items:center;gap:0.6rem;padding:0.6rem 0.85rem;
                                        background:var(--bg3);cursor:pointer;user-select:none;"
                                 onclick="toggleCatPanel(<?php echo $cid; ?>)">
                                <input type="checkbox"
                                       id="cat_cb_<?php echo $cid; ?>"
                                       name="th_cat_ids[]"
                                       value="<?php echo $cid; ?>"
                                       <?php echo $cat_box_checked ? 'checked' : ''; ?>
                                       onclick="event.stopPropagation(); catCheckboxChanged(<?php echo $cid; ?>)"
                                       style="accent-color:var(--gold);width:16px;height:16px;flex-shrink:0;cursor:pointer;">
                                <span style="font-size:0.85rem;font-weight:700;color:var(--brown);flex:1;">
                                    <?php echo htmlspecialchars($cdata['name']); ?>
                                </span>
                                <span style="font-size:0.7rem;color:var(--gray);">
                                    <?php echo count($cdata['services']); ?> service<?php echo count($cdata['services']) !== 1 ? 's' : ''; ?>
                                </span>
                                <span id="sp_arrow_<?php echo $cid; ?>"
                                      style="font-size:0.75rem;color:var(--gray);transition:transform .2s;
                                             transform:<?php echo $panel_open ? 'rotate(180deg)' : 'rotate(0)'; ?>;">
                                    ▾
                                </span>
                            </div>

                            <!-- Services panel -->
                            <div id="sp_panel_<?php echo $cid; ?>"
                                 style="display:<?php echo $panel_open ? 'block' : 'none'; ?>;
                                        padding:0.5rem 0.85rem 0.75rem;
                                        background:var(--bg2);border-top:1px solid var(--border2);">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.3rem;margin-top:0.35rem;">
                                    <?php foreach ($cdata['services'] as $svc):
                                        $svc_checked = in_array($svc['id'], $et_svcs) || $cat_box_checked;
                                    ?>
                                    <label style="display:flex;align-items:center;gap:0.45rem;cursor:pointer;
                                                  padding:0.35rem 0.5rem;border-radius:6px;
                                                  border:1px solid var(--border2);background:var(--bg3);
                                                  font-size:0.78rem;color:var(--brown);">
                                        <input type="checkbox"
                                               class="svc-cb svc-cb-<?php echo $cid; ?>"
                                               name="th_svc_ids[]"
                                               value="<?php echo $svc['id']; ?>"
                                               data-cat="<?php echo $cid; ?>"
                                               <?php echo $svc_checked ? 'checked' : ''; ?>
                                               onchange="svcCheckboxChanged(<?php echo $cid; ?>)"
                                               style="accent-color:var(--gold);width:13px;height:13px;flex-shrink:0;">
                                        <?php echo htmlspecialchars($svc['name']); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                                    <button type="button" onclick="selectAllInCat(<?php echo $cid; ?>, true)"
                                            style="font-size:0.68rem;padding:0.2rem 0.55rem;border-radius:6px;
                                                   border:1px solid var(--border2);background:var(--bg3);
                                                   color:var(--brown);cursor:pointer;">✓ All</button>
                                    <button type="button" onclick="selectAllInCat(<?php echo $cid; ?>, false)"
                                            style="font-size:0.68rem;padding:0.2rem 0.55rem;border-radius:6px;
                                                   border:1px solid var(--border2);background:var(--bg3);
                                                   color:var(--brown);cursor:pointer;">✕ None</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.25rem;">
                    <?php echo $edit_therapist ? '💾 Save Changes' : '➕ Add Therapist'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- ── THERAPISTS TABLE ────────────────────────────────────────────────── -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">💆 All Therapists (<?php echo count($all_therapists); ?>)</span>
            <a href="therapists.php" class="btn btn-secondary btn-sm" style="font-size:0.78rem;">
                → Today's Roster
            </a>
        </div>
        <?php
        $td_s    = date('Y-m-d');
        $last7_s = date('Y-m-d', strtotime('-6 days'));
        $month1_s = date('Y-m-01');
        $p_today  = ($comm_from === $td_s    && $comm_to === $td_s);
        $p_last7  = ($comm_from === $last7_s && $comm_to === $td_s);
        $p_month  = ($comm_from === $month1_s && $comm_to === $td_s);
        ?>
        <div style="padding:0.65rem 1rem;background:var(--bg3);border-bottom:1px solid var(--border2);
                    display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <span style="font-size:0.75rem;font-weight:700;color:var(--brown);">Commission period:</span>
            <a href="staff.php?tab=therapists&comm_from=<?php echo $td_s; ?>&comm_to=<?php echo $td_s; ?>"
               class="btn btn-sm <?php echo $p_today ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size:0.73rem;">Today</a>
            <a href="staff.php?tab=therapists&comm_from=<?php echo $last7_s; ?>&comm_to=<?php echo $td_s; ?>"
               class="btn btn-sm <?php echo $p_last7 ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size:0.73rem;">Last 7 Days</a>
            <a href="staff.php?tab=therapists&comm_from=<?php echo $month1_s; ?>&comm_to=<?php echo $td_s; ?>"
               class="btn btn-sm <?php echo $p_month ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size:0.73rem;">This Month</a>
            <form method="GET" style="display:flex;align-items:center;gap:0.3rem;margin-left:0.25rem;">
                <input type="hidden" name="tab" value="therapists">
                <label style="font-size:0.72rem;color:var(--gray);">From:</label>
                <input type="date" name="comm_from" value="<?php echo htmlspecialchars($comm_from); ?>"
                       style="padding:0.25rem 0.5rem;border:1px solid var(--border2);border-radius:6px;background:#fff;color:var(--brown);font-size:0.77rem;">
                <label style="font-size:0.72rem;color:var(--gray);">To:</label>
                <input type="date" name="comm_to" value="<?php echo htmlspecialchars($comm_to); ?>"
                       style="padding:0.25rem 0.5rem;border:1px solid var(--border2);border-radius:6px;background:#fff;color:var(--brown);font-size:0.77rem;">
                <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.73rem;">Apply</button>
            </form>
        </div>
        <?php if (empty($all_therapists)): ?>
        <div class="panel-body" style="text-align:center;padding:2.5rem;color:var(--gray);">
            <div style="font-size:2.5rem;margin-bottom:0.5rem;">💆</div>
            No therapists yet. Add one using the form on the left.
        </div>
        <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th style="text-align:center;">Rating</th>
                        <th style="text-align:right;">
                            Commission
                            <div style="font-size:0.66rem;font-weight:400;color:var(--gray);">
                                <?php echo ($comm_from === $comm_to)
                                    ? date('M j', strtotime($comm_from))
                                    : date('M j', strtotime($comm_from)) . ' – ' . date('M j', strtotime($comm_to)); ?>
                            </div>
                        </th>
                        <th>Specialties</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_therapists as $t):
                    $tid       = $t['id'];
                    $t_cat_ids = $th_cat_map[$tid] ?? [];
                    $t_svc_ids = $th_svc_map[$tid] ?? [];

                    $display_items = [];
                    foreach ($cats_with_services as $cid => $cdata) {
                        if (in_array($cid, $t_cat_ids)) {
                            $display_items[] = ['label' => $cdata['name'], 'type' => 'cat'];
                        } else {
                            foreach ($cdata['services'] as $svc) {
                                if (in_array($svc['id'], $t_svc_ids))
                                    $display_items[] = ['label' => $svc['name'], 'type' => 'svc'];
                            }
                        }
                    }
                    $is_editing = ($edit_therapist['id'] ?? 0) == $tid;
                ?>
                <tr style="<?php echo $is_editing ? 'background:rgba(201,106,44,0.05);' : ''; ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:0.6rem;">
                            <div style="width:34px;height:34px;border-radius:50%;
                                        background:linear-gradient(135deg,var(--gold),var(--rust));
                                        color:#fff;display:flex;align-items:center;justify-content:center;
                                        font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                <?php echo strtoupper(substr($t['full_name'],0,1)); ?>
                            </div>
                            <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($t['full_name']); ?></div>
                        </div>
                    </td>
                    <td style="font-size:0.82rem;color:var(--gray);"><?php echo htmlspecialchars($t['phone'] ?: '—'); ?></td>
                    <td style="text-align:center;">
                        <?php if ($t['avg_rating'] > 0): ?>
                        <span style="color:#f59e0b;font-weight:700;"><?php echo number_format($t['avg_rating'],1); ?>★</span>
                        <div style="font-size:0.68rem;color:var(--gray);"><?php echo $t['total_ratings']; ?> reviews</div>
                        <?php else: ?>
                        <span style="color:var(--gray);font-size:0.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;color:var(--green);font-weight:700;">
                        ₱<?php echo number_format($t['period_commission'],2); ?>
                        <?php if ($t['addon_commission'] > 0): ?>
                        <div style="font-size:0.68rem;font-weight:400;color:var(--gray);">
                            +₱<?php echo number_format($t['addon_commission'],2); ?> add-ons
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.78rem;max-width:200px;">
                        <?php if (empty($display_items)): ?>
                        <span style="color:var(--gray);">—</span>
                        <?php else: ?>
                        <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
                            <?php foreach ($display_items as $di): ?>
                            <span style="padding:0.1rem 0.45rem;border-radius:10px;font-size:0.68rem;font-weight:600;
                                         <?php echo $di['type']==='cat'
                                             ? 'background:var(--gold-dim);color:var(--brown-md);border:1px solid var(--gold);'
                                             : 'background:var(--bg3);color:var(--brown);border:1px solid var(--border2);'; ?>">
                                <?php echo htmlspecialchars($di['label']); ?><?php echo $di['type']==='cat' ? ' ★' : ''; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($t['specialties']): ?>
                        <div style="font-size:0.72rem;color:var(--gray);margin-top:0.2rem;">
                            <?php echo htmlspecialchars($t['specialties']); ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <a href="therapists.php?history=<?php echo $tid; ?>"
                           class="btn btn-secondary btn-sm" style="font-size:0.72rem;">📋 History</a>
                        <a href="staff.php?tab=commission#t<?php echo $tid; ?>"
                           class="btn btn-secondary btn-sm" style="font-size:0.72rem;">💰 Commission</a>
                        <a href="staff.php?tab=therapists&edit_therapist=<?php echo $tid; ?>"
                           class="btn btn-secondary btn-sm" style="font-size:0.72rem;">✏️ Edit</a>
                        <a href="staff.php?delete_therapist=<?php echo $tid; ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.72rem;"
                           onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($t['full_name'])); ?>? This cannot be undone.')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /therapists grid -->

<style>
.sp-cat-block:hover > div:first-child { background:var(--bg2); }
</style>

<script>
function toggleCatPanel(cid) {
    const panel = document.getElementById('sp_panel_' + cid);
    const arrow = document.getElementById('sp_arrow_' + cid);
    const open  = panel.style.display === 'block';
    panel.style.display   = open ? 'none' : 'block';
    arrow.style.transform = open ? 'rotate(0)' : 'rotate(180deg)';
}
function catCheckboxChanged(cid) {
    const catCb  = document.getElementById('cat_cb_' + cid);
    const svcCbs = document.querySelectorAll('.svc-cb-' + cid);
    svcCbs.forEach(cb => { cb.checked = catCb.checked; });
    if (catCb.checked) {
        document.getElementById('sp_panel_' + cid).style.display   = 'block';
        document.getElementById('sp_arrow_' + cid).style.transform  = 'rotate(180deg)';
    }
}
function svcCheckboxChanged(cid) {
    const svcCbs     = document.querySelectorAll('.svc-cb-' + cid);
    const allChecked = Array.from(svcCbs).every(cb => cb.checked);
    const anyChecked = Array.from(svcCbs).some(cb => cb.checked);
    const catCb = document.getElementById('cat_cb_' + cid);
    catCb.checked       = allChecked;
    catCb.indeterminate = anyChecked && !allChecked;
}
function selectAllInCat(cid, checked) {
    document.querySelectorAll('.svc-cb-' + cid).forEach(cb => { cb.checked = checked; });
    const catCb = document.getElementById('cat_cb_' + cid);
    catCb.checked = checked; catCb.indeterminate = false;
    if (!checked) svcCheckboxChanged(cid);
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[id^="cat_cb_"]').forEach(catCb => {
        const cid    = catCb.id.replace('cat_cb_', '');
        const svcCbs = document.querySelectorAll('.svc-cb-' + cid);
        if (!svcCbs.length) return;
        const n = Array.from(svcCbs).filter(cb => cb.checked).length;
        if (n > 0 && n < svcCbs.length) { catCb.indeterminate = true; catCb.checked = false; }
    });
});
</script>


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: COMMISSION MATRIX
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'commission'): ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">💰 Commission Matrix</span>
        <small style="color:var(--gray);font-size:0.73rem;">Sets % earned per therapist per service</small>
    </div>
    <div class="panel-body" style="padding:1.25rem;">
        <?php if (empty($all_therapists)): ?>
        <div style="text-align:center;padding:2rem;color:var(--gray);">No therapists found.</div>
        <?php else: ?>

        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
            <?php foreach ($all_therapists as $t):
                // Count how many specialty services have no commission set
                $missing_count = 0;
                $t_svc_ids_all = [];

                // From direct service specialties
                $ts1 = $conn->prepare("SELECT service_id FROM therapist_specialty_services WHERE therapist_id=?");
                $ts1->bind_param("i", $t['id']); $ts1->execute();
                foreach ($ts1->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $t_svc_ids_all[] = $r['service_id'];
                $ts1->close();

                // From category specialties
                $ts2 = $conn->prepare("SELECT s.id FROM therapist_specialties tsp JOIN services s ON s.category_id=tsp.category_id WHERE tsp.therapist_id=?");
                $ts2->bind_param("i", $t['id']); $ts2->execute();
                foreach ($ts2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $t_svc_ids_all[] = $r['id'];
                $ts2->close();

                $t_svc_ids_all = array_unique($t_svc_ids_all);

                if (!empty($t_svc_ids_all)) {
                    foreach ($t_svc_ids_all as $sid) {
                        $has_comm = isset($saved_commissions[$t['id']][$sid])
                            && ($saved_commissions[$t['id']][$sid]['pct'] > 0 || $saved_commissions[$t['id']][$sid]['flat'] > 0);
                        if (!$has_comm) $missing_count++;
                    }
                }
            ?>
            <button type="button" id="tab-<?php echo $t['id']; ?>"
                    data-has-missing="<?php echo $missing_count > 0 ? '1' : '0'; ?>"
                    onclick="showCommission(<?php echo $t['id']; ?>)"
                    style="position:relative;padding:0.4rem 1.1rem;border-radius:20px;
                           border:2px solid <?php echo $missing_count > 0 ? 'rgba(234,179,8,0.6)' : 'var(--border2)'; ?>;
                           background:<?php echo $missing_count > 0 ? 'rgba(234,179,8,0.08)' : 'var(--bg3)'; ?>;
                           color:var(--brown);font-size:0.82rem;font-weight:600;cursor:pointer;transition:all .15s;">
                <?php echo htmlspecialchars($t['full_name']); ?>
                <?php if ($missing_count > 0): ?>
                <span style="position:absolute;top:-6px;right:-6px;
                             background:#ef4444;color:#fff;
                             font-size:0.6rem;font-weight:800;
                             width:16px;height:16px;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;
                             line-height:1;border:2px solid #fff;">
                    <?php echo $missing_count; ?>
                </span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div id="commission-placeholder"
             style="text-align:center;padding:2rem;color:var(--gray);font-size:0.88rem;
                    background:var(--bg3);border-radius:10px;border:1px solid var(--border2);">
            👆 Select a therapist above to view and set their commission rates.
        </div>

        <form method="POST" id="commissionForm" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="save_commission" value="1">
            <input type="hidden" name="commission_therapist_id" id="commission_therapist_id" value="">

            <div id="commission-therapist-name"
                 style="font-size:1rem;font-weight:700;color:var(--brown);
                        margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:2px solid var(--border2);">
            </div>

            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.5rem;
                        padding:0.5rem 0.75rem;background:var(--bg3);border-radius:8px;
                        margin-bottom:0.5rem;font-size:0.73rem;font-weight:700;
                        color:var(--gray);border:1px solid var(--border2);">
                <div>Service (Regular Price)</div>
                <div style="text-align:center;">
                    Commission %<br>
                    <span style="font-weight:400;font-size:0.68rem;">Regular / Home / Hotel</span>
                </div>
                <div style="text-align:center;">
                    Influencer ₱<br>
                    <span style="font-weight:400;font-size:0.68rem;">Fixed flat rate</span>
                </div>
            </div>

            <div id="commission-rows"></div>

            <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">💾 Save Commission Rates</button>
                <span style="font-size:0.78rem;color:var(--gray);">Applies to future completed appointments only.</span>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
const svcByCategory    = <?php echo json_encode($svc_by_cat,       JSON_HEX_TAG); ?>;
const savedCommissions = <?php echo json_encode($saved_commissions, JSON_HEX_TAG); ?>;
const therapistNames   = <?php
    $tnames = [];
    foreach ($all_therapists as $t) $tnames[$t['id']] = $t['full_name'];
    echo json_encode($tnames, JSON_HEX_TAG);
?>;

// Map: therapist_id → array of service_ids they have specialty for
// Combines both direct service specialties AND all services under their specialty categories
const therapistSpecialtyServices = <?php
    $tss_map = [];
    if (!empty($all_therapists)) {
        $tids_str2 = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists));

        // Direct service specialties
        $tss_rows = $conn->query("SELECT therapist_id, service_id FROM therapist_specialty_services WHERE therapist_id IN ($tids_str2)");
        if ($tss_rows) while ($r = $tss_rows->fetch_assoc()) {
            $tss_map[$r['therapist_id']][] = intval($r['service_id']);
        }

        // Also include services from category-level specialties (for therapists saved before the fix)
        $cat_svc_rows = $conn->query("
            SELECT ts.therapist_id, s.id AS service_id
            FROM therapist_specialties ts
            JOIN services s ON s.category_id = ts.category_id
            WHERE ts.therapist_id IN ($tids_str2)
        ");
        if ($cat_svc_rows) while ($r = $cat_svc_rows->fetch_assoc()) {
            $tid_key = intval($r['therapist_id']);
            $sid_val = intval($r['service_id']);
            if (!isset($tss_map[$tid_key]) || !in_array($sid_val, $tss_map[$tid_key])) {
                $tss_map[$tid_key][] = $sid_val;
            }
        }
    }
    echo json_encode($tss_map, JSON_HEX_TAG);
?>;

function showCommission(therapistId) {
    document.querySelectorAll('[id^="tab-"]').forEach(btn => {
        btn.style.background  = btn.dataset.hasMissing === '1' ? 'rgba(234,179,8,0.08)' : 'var(--bg3)';
        btn.style.borderColor = btn.dataset.hasMissing === '1' ? 'rgba(234,179,8,0.6)' : 'var(--border2)';
        btn.style.color       = 'var(--brown)';
    });
    const tab = document.getElementById('tab-' + therapistId);
    if (tab) {
        tab.style.background  = 'var(--gold)';
        tab.style.borderColor = 'var(--gold)';
        tab.style.color       = '#fff';
    }

    document.getElementById('commission-placeholder').style.display  = 'none';
    document.getElementById('commissionForm').style.display          = 'block';
    document.getElementById('commission_therapist_id').value         = therapistId;
    document.getElementById('commission-therapist-name').textContent =
        '💆 ' + (therapistNames[therapistId] || '') + ' — Commission Rates';

    const saved         = savedCommissions[therapistId] || {};
    const mySpecialties = therapistSpecialtyServices[therapistId] || [];
    const rowsEl        = document.getElementById('commission-rows');
    rowsEl.innerHTML    = '';
    const iStyle        = 'width:100%;padding:0.35rem 0.5rem;border:1px solid var(--border2);border-radius:6px;background:var(--bg2);color:var(--brown);font-size:0.85rem;text-align:center;box-sizing:border-box;';

    let missingCount  = 0;
    let totalShown    = 0;

    Object.entries(svcByCategory).forEach(([cat, svcs]) => {
        // Only include services this therapist has specialty for
        const qualifiedSvcs = svcs.filter(svc => mySpecialties.includes(parseInt(svc.id)));
        if (qualifiedSvcs.length === 0) return; // skip category entirely if no qualified services

        const catEl = document.createElement('div');
        catEl.style.cssText = 'font-size:0.72rem;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:0.06em;padding:0.65rem 0 0.3rem;margin-top:0.4rem;border-top:1px solid var(--border2);';
        catEl.textContent = cat;
        rowsEl.appendChild(catEl);

        qualifiedSvcs.forEach(svc => {
            const s           = saved[svc.id] || { pct: '', flat: '' };
            const missingComm = !s.pct && !s.flat;
            if (missingComm) missingCount++;
            totalShown++;

            const row = document.createElement('div');
            row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.5rem;align-items:center;padding:0.45rem 0.75rem;border-radius:8px;margin-bottom:0.3rem;'
                + (missingComm
                    ? 'background:rgba(234,179,8,0.1);border:1.5px solid rgba(234,179,8,0.5);'
                    : 'background:var(--bg3);border:1px solid var(--border2);');
            const price = parseFloat(svc.price).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
            row.innerHTML = `
                <div>
                    <input type="hidden" name="svc_id[]" value="${svc.id}">
                    <div style="font-size:0.83rem;font-weight:600;color:var(--brown);">
                        ${svc.name}
                        ${missingComm ? '<span style="background:rgba(234,179,8,0.15);color:#92400e;font-size:0.62rem;padding:0.1rem 0.4rem;border-radius:20px;margin-left:0.3rem;font-weight:700;">⚠️ NO COMMISSION</span>' : ''}
                    </div>
                    <div style="font-size:0.7rem;color:var(--gray);">Regular: ₱${price}</div>
                </div>
                <div>
                    <input type="number" name="svc_percent[]" value="${s.pct !== '' ? s.pct : ''}"
                           min="0" max="100" step="0.01" placeholder="e.g. 35" style="${iStyle}${missingComm ? 'border-color:rgba(234,179,8,0.6);' : ''}">
                    <div style="font-size:0.66rem;color:var(--gray);text-align:center;margin-top:2px;">%</div>
                </div>
                <div>
                    <input type="number" name="svc_flat[]" value="${s.flat !== '' ? s.flat : ''}"
                           min="0" step="0.01" placeholder="e.g. 200" style="${iStyle}${missingComm ? 'border-color:rgba(234,179,8,0.6);' : ''}">
                    <div style="font-size:0.66rem;color:var(--gray);text-align:center;margin-top:2px;">₱ flat</div>
                </div>`;
            rowsEl.appendChild(row);
        });
    });

    // No specialties assigned yet
    if (totalShown === 0) {
        const empty = document.createElement('div');
        empty.style.cssText = 'text-align:center;padding:2rem;color:var(--gray);font-size:0.85rem;background:var(--bg3);border-radius:10px;border:1px solid var(--border2);';
        empty.innerHTML = '⚠️ No specialties assigned to this therapist yet.<br><small>Go to the <strong>Therapists tab</strong> → Edit → assign service specialties first.</small>';
        rowsEl.appendChild(empty);
    }

    // Warning banner for missing commissions
    const existingWarn = document.getElementById('commission-missing-warn');
    if (existingWarn) existingWarn.remove();
    if (missingCount > 0) {
        const warn = document.createElement('div');
        warn.id = 'commission-missing-warn';
        warn.style.cssText = 'margin-bottom:1rem;padding:0.75rem 1rem;background:rgba(234,179,8,0.1);border:1.5px solid rgba(234,179,8,0.5);border-radius:8px;font-size:0.82rem;color:#92400e;';
        warn.innerHTML = `⚠️ <strong>${missingCount} service(s)</strong> have no commission rate set yet. They are highlighted below.`;
        document.getElementById('commission-therapist-name').after(warn);
    }
}
</script>


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: CA & DEDUCTIONS
══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($active_tab === 'deductions'): ?>

<!-- ── Pay-Period selector ───────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem;">
    <div class="panel-body" style="padding:0.85rem 1.1rem;">
        <form method="GET" style="display:flex;gap:0.65rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="deductions">
            <div>
                <label style="font-size:0.72rem;font-weight:700;color:var(--brown);display:block;margin-bottom:3px;">From</label>
                <input type="date" name="period_start" value="<?php echo $period_start; ?>"
                       style="padding:0.42rem 0.65rem;border:1px solid var(--border2);border-radius:8px;
                              background:var(--bg3);color:var(--brown);font-size:0.82rem;">
            </div>
            <div>
                <label style="font-size:0.72rem;font-weight:700;color:var(--brown);display:block;margin-bottom:3px;">To</label>
                <input type="date" name="period_end" value="<?php echo $period_end; ?>"
                       style="padding:0.42rem 0.65rem;border:1px solid var(--border2);border-radius:8px;
                              background:var(--bg3);color:var(--brown);font-size:0.82rem;">
            </div>
            <button type="submit" class="btn btn-primary" style="padding:0.42rem 1rem;font-size:0.82rem;">
                🔍 Apply
            </button>
            <?php
            $presets = [
                ['1st–15th',   date('Y-m-01'),                                       date('Y-m-15')],
                ['16th–end',   date('Y-m-16'),                                       date('Y-m-t')],
                ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            ];
            foreach ($presets as [$plabel, $ps, $pe]):
                $is_preset_active = ($period_start === $ps && $period_end === $pe);
            ?>
            <a href="?tab=deductions&period_start=<?php echo $ps; ?>&period_end=<?php echo $pe; ?>"
               class="btn btn-secondary"
               style="padding:0.42rem 0.75rem;font-size:0.78rem;white-space:nowrap;
                      <?php echo $is_preset_active ? 'border-color:var(--gold);background:rgba(200,164,107,.15);font-weight:700;' : ''; ?>">
                <?php echo $plabel; ?>
            </a>
            <?php endforeach; unset($presets,$plabel,$ps,$pe,$is_preset_active); ?>
        </form>
    </div>
</div>

<!-- ── Per-therapist CA Summary table ────────────────────────────────────── -->
<?php if (!empty($all_therapists)): ?>
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">
            💸 CA Summary
            <span style="font-size:0.78rem;font-weight:400;color:var(--gray);margin-left:0.35rem;">
                Pay Period: <?php echo date('M d', strtotime($period_start)); ?> – <?php echo date('M d, Y', strtotime($period_end)); ?>
            </span>
        </span>
        <span style="font-size:0.72rem;color:var(--gray);">
            Per therapist · Click name for full history
        </span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Therapist</th>
                    <th style="text-align:right;">Commission</th>
                    <th style="text-align:right;">Cash Advance</th>
                    <th style="text-align:right;">Expenses</th>
                    <th style="text-align:right;">Total Deductions</th>
                    <th style="text-align:right;">Est. Net Pay</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($all_therapists as $t):
                $deds        = $ded_totals_by_therapist[$t['id']] ?? ['total_all'=>0,'total_ca'=>0,'total_expense'=>0];
                $total_ded   = floatval($deds['total_all']);
                $comm_period = $ded_commission_by_therapist[$t['id']] ?? 0.0;
                $net_period  = $comm_period - $total_ded;
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;
                                    background:linear-gradient(135deg,var(--gold),var(--rust));
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:0.82rem;">
                            <?php echo strtoupper(substr($t['full_name'],0,1)); ?>
                        </div>
                        <a href="therapists.php?history=<?php echo $t['id']; ?>"
                           style="font-weight:600;color:var(--brown);text-decoration:none;font-size:0.88rem;">
                            <?php echo htmlspecialchars($t['full_name']); ?>
                        </a>
                    </div>
                </td>
                <td style="text-align:right;font-weight:700;color:var(--green);">
                    ₱<?php echo number_format($comm_period, 2); ?>
                </td>
                <td style="text-align:right;
                           color:<?php echo $deds['total_ca']>0?'var(--rust)':'var(--gray)'; ?>;
                           font-weight:<?php echo $deds['total_ca']>0?'700':'400'; ?>;">
                    <?php echo $deds['total_ca']>0 ? '−₱'.number_format($deds['total_ca'],2) : '—'; ?>
                </td>
                <td style="text-align:right;
                           color:<?php echo $deds['total_expense']>0?'var(--rust)':'var(--gray)'; ?>;
                           font-weight:<?php echo $deds['total_expense']>0?'700':'400'; ?>;">
                    <?php echo $deds['total_expense']>0 ? '−₱'.number_format($deds['total_expense'],2) : '—'; ?>
                </td>
                <td style="text-align:right;font-weight:700;
                           color:<?php echo $total_ded>0?'var(--rust)':'var(--gray)'; ?>;">
                    <?php echo $total_ded>0 ? '−₱'.number_format($total_ded,2) : '—'; ?>
                </td>
                <td style="text-align:right;">
                    <span style="font-weight:800;font-size:0.92rem;
                                 color:<?php echo $net_period>=0?'var(--green)':'var(--rust)'; ?>;">
                        ₱<?php echo number_format($net_period, 2); ?>
                    </span>
                </td>
                <td>
                    <a href="therapists.php?history=<?php echo $t['id']; ?>"
                       class="btn btn-secondary btn-sm" style="font-size:0.68rem;white-space:nowrap;">
                        📋 Full History
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(201,106,44,0.05);">
                    <td style="font-weight:700;color:var(--brown);padding:0.6rem 0.75rem;">Totals</td>
                    <td style="padding:0.6rem 0.75rem;"></td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);padding:0.6rem 0.75rem;">
                        −₱<?php echo number_format(array_sum(array_map(fn($d) => floatval($d['total_ca'] ?? 0), $ded_totals_by_therapist)), 2); ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);padding:0.6rem 0.75rem;">
                        −₱<?php echo number_format(array_sum(array_map(fn($d) => floatval($d['total_expense'] ?? 0), $ded_totals_by_therapist)), 2); ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);padding:0.6rem 0.75rem;">
                        −₱<?php echo number_format(array_sum(array_map(fn($d) => floatval($d['total_all'] ?? 0), $ded_totals_by_therapist)), 2); ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:350px 1fr;gap:1.5rem;align-items:start;">

    <!-- Add Deduction Form -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">➕ Add CA / Deduction</span></div>
        <div class="panel-body" style="padding:1.25rem;">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Therapist *</label>
                    <select name="ded_therapist_id" required
                            style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                   border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                        <option value="">— Select Therapist —</option>
                        <?php foreach ($all_therapists as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Type</label>
                        <select name="ded_type"
                                style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                       border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                            <option value="ca">💳 Cash Advance (CA)</option>
                            <option value="expense">🧾 Personal Expense</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Amount (₱) *</label>
                        <input type="number" name="ded_amount" step="0.01" min="0.01" required placeholder="0.00"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                      border-radius:8px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                </div>
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Description</label>
                    <input type="text" name="ded_label" placeholder="e.g. Water, Cash advance"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--brown);
                                  font-size:0.85rem;box-sizing:border-box;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:1rem;">
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Date</label>
                        <input type="date" name="ded_date" value="<?php echo date('Y-m-d'); ?>"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                      border-radius:8px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.78rem;font-weight:600;color:var(--brown);display:block;margin-bottom:4px;">Notes</label>
                        <input type="text" name="ded_notes" placeholder="Optional"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);
                                      border-radius:8px;background:var(--bg3);color:var(--brown);
                                      font-size:0.85rem;box-sizing:border-box;">
                    </div>
                </div>
                <button type="submit" name="add_therapist_ded" class="btn btn-primary" style="width:100%;">
                    ➕ Record Deduction
                </button>
            </form>
        </div>
    </div>

    <!-- Recent deductions log -->
    <div class="panel" id="deductions">
        <div class="panel-header">
            <span class="panel-title">
                💸 Recent Log
                <span style="font-size:0.78rem;font-weight:400;color:var(--gray);margin-left:0.35rem;">
                    Pay Period: <?php echo date('M d', strtotime($period_start)); ?> – <?php echo date('M d, Y', strtotime($period_end)); ?>
                </span>
            </span>
            <span style="background:var(--red-dim);color:var(--rust);font-size:0.72rem;
                         padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
                −₱<?php echo number_format(array_sum(array_column($recent_deds,'amount')),2); ?> total
            </span>
        </div>
        <?php if (empty($recent_deds)): ?>
        <div class="panel-body" style="text-align:center;padding:2.5rem;color:var(--gray);">
            <div style="font-size:2rem;margin-bottom:0.4rem;">💸</div>
            No deductions recorded in this pay period.
        </div>
        <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0;">
            <table>
                <thead>
                    <tr>
                        <th>Date</th><th>Therapist</th><th>Type</th>
                        <th>Description</th><th style="text-align:right;">Amount</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_deds as $d): ?>
                <tr id="ded<?php echo $d['therapist_id']; ?>">
                    <td style="font-size:0.78rem;color:var(--gray);white-space:nowrap;">
                        <?php echo date('M d, Y', strtotime($d['deduction_date'])); ?>
                    </td>
                    <td style="font-weight:600;color:var(--brown);">
                        <a href="therapists.php?history=<?php echo $d['therapist_id']; ?>"
                           style="color:var(--brown);text-decoration:none;">
                            <?php echo htmlspecialchars($d['full_name']); ?>
                        </a>
                    </td>
                    <td>
                        <span style="font-size:0.75rem;font-weight:700;padding:0.15rem 0.55rem;border-radius:20px;
                                     background:<?php echo $d['type']==='ca'?'rgba(0,112,243,0.12)':'rgba(201,106,44,0.12)'; ?>;
                                     color:<?php echo $d['type']==='ca'?'#0070f3':'var(--rust)'; ?>;">
                            <?php echo $d['type']==='ca'?'💳 CA':'🧾 Expense'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="color:var(--brown);"><?php echo $d['label'] ? htmlspecialchars($d['label']) : '—'; ?></div>
                        <?php if ($d['notes']): ?>
                        <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($d['notes']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:var(--rust);">
                        −₱<?php echo number_format($d['amount'],2); ?>
                    </td>
                    <td>
                        <a href="staff.php?del_ded=<?php echo $d['id']; ?>&tab=deductions&period_start=<?php echo urlencode($period_start); ?>&period_end=<?php echo urlencode($period_end); ?>"
                           class="btn btn-danger btn-sm" style="font-size:0.68rem;padding:0.2rem 0.45rem;"
                           onclick="return confirm('Delete this deduction entry?')">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /deductions grid -->

<?php endif; ?>

<script>
function selectRole(role) {
    document.getElementById('role_type_input').value = role;

    // Update button styles
    ['cashier','marketing','it'].forEach(r => {
        const btn = document.getElementById('role-btn-' + r);
        if (!btn) return;
        const isActive = r === role;
        btn.style.borderColor = isActive ? 'var(--gold)' : 'var(--border2)';
        btn.style.background  = isActive ? '#fff8f2'    : 'var(--bg3)';
    });

    // Show correct access note
    ['cashier','marketing','it'].forEach(r => {
        const note = document.getElementById('access-note-' + r);
        if (note) note.style.display = r === role ? 'block' : 'none';
    });

    // Toggle Full Name field — not needed for receptionist (shared account)
    const ffDiv   = document.getElementById('fullname-field');
    const ffInput = document.getElementById('fullname_input');
    if (ffDiv && ffInput) {
        const show = role !== 'cashier';
        ffDiv.style.display  = show ? '' : 'none';
        ffInput.required     = show;
    }
}
(function(){ const ri = document.getElementById('role_type_input'); if (ri) selectRole(ri.value); })();

function checkPwd(val) {
    const set = (id, ok) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.color = ok ? 'var(--green)' : 'var(--gray)';
        el.textContent = (ok ? '✓ ' : '✗ ') + el.textContent.slice(2);
    };
    set('chk-len',   val.length >= 8);
    set('chk-upper', /[A-Z]/.test(val));
    set('chk-num',   /[0-9]/.test(val));
    set('chk-spec',  /[^A-Za-z0-9]/.test(val));
}
function togglePwd(id) {
    const el = document.getElementById(id);
    if (el) el.type = el.type === 'password' ? 'text' : 'password';
}
</script>

<?php require_once 'admin_footer.php'; ?>