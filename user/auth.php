<?php
ob_start();

require_once '../config.php';

$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) {
    $autoload_path = $_SERVER['DOCUMENT_ROOT'] . '/spa_ecommerce_system/vendor/autoload.php';
}
require_once $autoload_path;
require_once __DIR__ . '/../_mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─── LOGOUT ───────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $logout_is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    // Clear receptionist session token so they can log in again on another device
    if (isset($_SESSION['session_token']) && isset($_SESSION['user_id'])) {
        $clr = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=? AND session_token=?");
        $clr->bind_param("is", $_SESSION['user_id'], $_SESSION['session_token']);
        $clr->execute(); $clr->close();
    }
    session_unset();
    session_destroy();
    header($logout_is_admin ? 'Location: ../admin/admin_login.php' : 'Location: auth.php');
    exit();
}

if (isset($_SESSION['user_id'])) {
    header($_SESSION['role'] === 'admin' ? "Location: ../admin/index.php" : "Location: ../user/index.php");
    exit();
}

$login_error      = '';
$register_error   = '';
$register_success = '';
$fp_error         = '';
$fp_success       = '';

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: VERIFY ADMIN GATE CODE
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_gate_code') {
    ob_clean();
    header('Content-Type: application/json');
    $entered = trim($_POST['code'] ?? '');
    if ($entered === ADMIN_GATE_CODE) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Incorrect code.']);
    }
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: SEND OTP (registration)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    ob_clean();
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    // Only block if the existing account is ACTIVE (not soft-deleted)
    $stmt = $conn->prepare("SELECT id, deleted_at FROM users WHERE email = ?");
    $stmt->bind_param("s", $email); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($existing && empty($existing['deleted_at'])) {
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        exit;
    }
    if (isset($_SESSION['otp_sent_at']) && (time() - $_SESSION['otp_sent_at']) < 60) {
        $wait = 60 - (time() - $_SESSION['otp_sent_at']);
        echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another OTP."]);
        exit;
    }
    $otp        = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $_SESSION['otp']          = $otp;
    $_SESSION['otp_expiry']   = $otp_expiry;
    $_SESSION['otp_email']    = $email;
    $_SESSION['otp_sent_at']  = time();
    $_SESSION['otp_attempts'] = 0;
    $_SESSION['otp_verified'] = false;
    $pending_name = $_SESSION['pending_reg']['full_name'] ?? 'User';
    try {
        $mail = make_mailer();
        $mail->addAddress($email, $pending_name);
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Recovery Spa Account';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;'>
                <div style='background:linear-gradient(135deg,#3B2A1A,#6B4C30);padding:25px;text-align:center;border-radius:12px 12px 0 0;'>
                    <span style='font-size:2.5rem;'>💆</span>
                    <h2 style='color:#FAF3E8;margin:8px 0 4px;letter-spacing:1px;'>RECOVERY</h2>
                    <p style='color:#C8A46B;font-size:0.85rem;margin:0;'>Email Verification</p>
                </div>
                <div style='background:white;padding:30px;border:1px solid #EAD8C0;border-radius:0 0 12px 12px;'>
                    <p style='color:#3B2A1A;'>Hello <strong>" . htmlspecialchars($pending_name) . "</strong>,</p>
                    <p style='color:#555;'>Your OTP verification code is:</p>
                    <div style='background:#fff8f3;border:2px dashed #EAD8C0;border-radius:10px;padding:24px;text-align:center;margin:20px 0;'>
                        <span style='font-size:42px;font-weight:900;color:#C96A2C;letter-spacing:14px;'>{$otp}</span>
                    </div>
                    <p style='color:#888;font-size:13px;'>This code expires in <strong>10 minutes</strong>. If you did not register, please ignore this email.</p>
                </div>
            </div>";
        $mail->AltBody = "Your OTP verification code is: {$otp} (expires in 10 minutes).";
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent! Check your Gmail inbox.']);
    } catch (\Exception $e) {
        error_log('[MAIL] Registration OTP failed for ' . $email . ': '
            . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        echo json_encode(['success' => false, 'message' => 'Could not send verification email. Please try again.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: VERIFY OTP (registration)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    ob_clean();
    header('Content-Type: application/json');
    $input = trim($_POST['otp'] ?? '');
    if (!isset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit;
    }
    if (strtotime($_SESSION['otp_expiry']) < time()) {
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit;
    }
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    if ($_SESSION['otp_attempts'] > 5) {
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'], $_SESSION['otp_attempts']);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
        exit;
    }
    if ($input !== $_SESSION['otp']) {
        $remaining = 5 - $_SESSION['otp_attempts'];
        echo json_encode(['success' => false, 'message' => "Incorrect OTP. {$remaining} attempt(s) remaining."]);
        exit;
    }
    $_SESSION['otp_verified']       = true;
    $_SESSION['otp_verified_email'] = $_SESSION['otp_email'];
    unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
    echo json_encode(['success' => true, 'message' => 'Email verified! Completing your registration…']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: SEND FORGOT PASSWORD OTP
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_fp_otp') {
    ob_clean();
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    // Check email EXISTS and is not deactivated
    $stmt = $conn->prepare("SELECT id, full_name, deleted_at FROM users WHERE email = ?");
    $stmt->bind_param("s", $email); $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user_row) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
        exit;
    }
    if (!empty($user_row['deleted_at'])) {
        echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Please create a new account instead.']);
        exit;
    }
    // Rate limit
    if (isset($_SESSION['fp_otp_sent_at']) && (time() - $_SESSION['fp_otp_sent_at']) < 60) {
        $wait = 60 - (time() - $_SESSION['fp_otp_sent_at']);
        echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another OTP."]);
        exit;
    }
    $otp        = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $_SESSION['fp_otp']          = $otp;
    $_SESSION['fp_otp_expiry']   = $otp_expiry;
    $_SESSION['fp_otp_email']    = $email;
    $_SESSION['fp_otp_sent_at']  = time();
    $_SESSION['fp_otp_attempts'] = 0;
    $_SESSION['fp_otp_verified'] = false;
    try {
        $mail = make_mailer();
        $mail->addAddress($email, $user_row['full_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Recovery Spa — Password Reset OTP';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;'>
                <div style='background:linear-gradient(135deg,#3B2A1A,#6B4C30);padding:25px;text-align:center;border-radius:12px 12px 0 0;'>
                    <span style='font-size:2.5rem;'>🔑</span>
                    <h2 style='color:#FAF3E8;margin:8px 0 4px;letter-spacing:1px;'>RECOVERY</h2>
                    <p style='color:#C8A46B;font-size:0.85rem;margin:0;'>Password Reset</p>
                </div>
                <div style='background:white;padding:30px;border:1px solid #EAD8C0;border-radius:0 0 12px 12px;'>
                    <p style='color:#3B2A1A;'>Hello <strong>" . htmlspecialchars($user_row['full_name']) . "</strong>,</p>
                    <p style='color:#555;'>We received a request to reset your password. Your OTP code is:</p>
                    <div style='background:#fff8f3;border:2px dashed #EAD8C0;border-radius:10px;padding:24px;text-align:center;margin:20px 0;'>
                        <span style='font-size:42px;font-weight:900;color:#C96A2C;letter-spacing:14px;'>{$otp}</span>
                    </div>
                    <p style='color:#888;font-size:13px;'>This code expires in <strong>10 minutes</strong>. If you did not request a password reset, you can safely ignore this email.</p>
                </div>
            </div>";
        $mail->AltBody = "Your password reset OTP is: {$otp} (expires in 10 minutes).";
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent! Check your email inbox.']);
    } catch (\Exception $e) {
        error_log('[MAIL] Password-reset OTP failed for ' . $email . ': '
            . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        echo json_encode(['success' => false, 'message' => 'Could not send email. Please try again.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: VERIFY FORGOT PASSWORD OTP
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_fp_otp') {
    ob_clean();
    header('Content-Type: application/json');
    $input = trim($_POST['otp'] ?? '');
    if (!isset($_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], $_SESSION['fp_otp_email'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit;
    }
    if (strtotime($_SESSION['fp_otp_expiry']) < time()) {
        unset($_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], $_SESSION['fp_otp_email']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit;
    }
    $_SESSION['fp_otp_attempts'] = ($_SESSION['fp_otp_attempts'] ?? 0) + 1;
    if ($_SESSION['fp_otp_attempts'] > 5) {
        unset($_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], $_SESSION['fp_otp_email'], $_SESSION['fp_otp_attempts']);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
        exit;
    }
    if ($input !== $_SESSION['fp_otp']) {
        $remaining = 5 - $_SESSION['fp_otp_attempts'];
        echo json_encode(['success' => false, 'message' => "Incorrect OTP. {$remaining} attempt(s) remaining."]);
        exit;
    }
    $_SESSION['fp_otp_verified'] = true;
    unset($_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], $_SESSION['fp_otp_attempts']);
    echo json_encode(['success' => true, 'message' => 'OTP verified! You can now set your new password.']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: CHANGE PASSWORD (after OTP verified)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf_token();
    $new_pass    = $_POST['new_password']     ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    if (empty($new_pass) || strlen($new_pass) < 6) {
        $fp_error = "Password must be at least 6 characters.";
    } elseif ($new_pass !== $confirm_pass) {
        $fp_error = "Passwords do not match.";
    } elseif (empty($_SESSION['fp_otp_verified']) || empty($_SESSION['fp_otp_email'])) {
        $fp_error = "Session expired. Please start over.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $email  = $_SESSION['fp_otp_email'];
        $stmt   = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed, $email); $stmt->execute(); $stmt->close();
        // Clean up
        unset($_SESSION['fp_otp_verified'], $_SESSION['fp_otp_email'],
              $_SESSION['fp_otp_sent_at'], $_SESSION['fp_otp']);
        $_SESSION['reg_success'] = "✅ Password changed successfully! You can now log in.";
        header('Location: auth.php');
        exit();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    verify_csrf_token();
    $email    = sanitize_input($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($email) || empty($password)) {
        $login_error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();

        // Only authenticate customer accounts here — admin accounts use admin/admin_login.php
        $password_ok = $user ? password_verify($password, $user['password']) : false;
        if ($user && $password_ok && $user['role'] === 'user') {
            if (!empty($user['deleted_at'])) {
                $login_error = "This account has been deactivated. Please contact us or create a new account.";
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['cart']      = load_cart_from_db($conn, $user['id']);
                header("Location: ../user/index.php");
                exit();
            }
        } else {
            // Generic message — do not reveal whether an admin account exists
            $login_error = "Invalid email or password.";
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — REGISTER
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_register'])) {
    verify_csrf_token();
    $username  = sanitize_input($_POST['username']  ?? '');
    $email     = sanitize_input($_POST['email']     ?? '');
    $password  = $_POST['password']                 ?? '';
    $confirm   = $_POST['confirm_password']         ?? '';
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $phone = preg_replace('/\D/', '', sanitize_input($_POST['phone'] ?? ''));
    $address   = sanitize_input($_POST['address']   ?? '');
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $register_error = "All required fields must be filled in.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $register_error = "Password must be at least 8 characters.";
    }elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $register_error = "Password must include at least one letter, one number, and one special character.";
    } elseif ($password !== $confirm) {
        $register_error = "Passwords do not match.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
    $register_error = "Phone number must be 11 digits and start with 09.";
    } else {
        // Only block if an ACTIVE (non-deleted) account uses this username or email
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL");
        $stmt->bind_param("ss", $username, $email); $stmt->execute(); $stmt->store_result();
        $duplicate = $stmt->num_rows > 0; $stmt->close();
        if ($duplicate) {
            $register_error = "Username or email is already taken.";
        } else {
            $_SESSION['pending_reg'] = [
                'username'  => $username,
                'email'     => $email,
                'password'  => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $full_name,
                'phone'     => $phone,
                'address'   => $address,
            ];
            unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'],
                  $_SESSION['otp_sent_at'], $_SESSION['otp_attempts'],
                  $_SESSION['otp_verified'], $_SESSION['otp_verified_email']);
            header('Location: auth.php?step=otp');
        }
    }
    
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — COMPLETE REGISTRATION
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_register'])) {
    verify_csrf_token();
    $pending = $_SESSION['pending_reg'] ?? null;
    if (!$pending) {
        $register_error = "Session expired. Please register again.";
    } elseif (empty($_SESSION['otp_verified']) || $_SESSION['otp_verified_email'] !== $pending['email']) {
        $register_error = "Email not verified. Please verify your OTP first.";
    } else {
        // Check if an ACTIVE account already exists (race condition guard)
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL");
        $stmt->bind_param("ss", $pending['username'], $pending['email']);
        $stmt->execute(); $stmt->store_result();
        $duplicate = $stmt->num_rows > 0; $stmt->close();

        if ($duplicate) {
            $register_error = "Username or email was already taken. Please register again.";
            unset($_SESSION['pending_reg']);
        } else {
            // Check if a SOFT-DELETED account exists with the same email — restore it
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NOT NULL");
            $stmt->bind_param("s", $pending['email']); $stmt->execute();
            $deleted_row = $stmt->get_result()->fetch_assoc(); $stmt->close();

            if ($deleted_row) {
                // Restore the old account with new credentials — history preserved
                $stmt = $conn->prepare("
                    UPDATE users
                    SET username   = ?,
                        password   = ?,
                        full_name  = ?,
                        phone      = ?,
                        address    = ?,
                        deleted_at = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssi",
                    $pending['username'], $pending['password'],
                    $pending['full_name'], $pending['phone'],
                    $pending['address'], $deleted_row['id']
                );
                $ok = $stmt->execute(); $stmt->close();
                if ($ok) {
                    unset($_SESSION['pending_reg'], $_SESSION['otp_verified'],
                          $_SESSION['otp_verified_email'], $_SESSION['otp_sent_at'], $_SESSION['otp_email']);
                    $_SESSION['reg_success'] = "Welcome back! Your account has been restored. You can now log in.";
                    header('Location: auth.php');
                    exit();
                } else {
                    $register_error = "Account restoration failed. Please try again.";
                }
            } else {
                // Fresh registration — insert new row
                $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES (?, ?, ?, ?, ?, ?, 'user')");
                $stmt->bind_param("ssssss", $pending['username'], $pending['password'], $pending['email'], $pending['full_name'], $pending['phone'], $pending['address']);
                if ($stmt->execute()) {
                    unset($_SESSION['pending_reg'], $_SESSION['otp_verified'], $_SESSION['otp_verified_email'], $_SESSION['otp_sent_at'], $_SESSION['otp_email']);
                    $_SESSION['reg_success'] = "Registration successful! You can now log in.";
                    header('Location: auth.php');
                    exit();
                } else {
                    $register_error = "Registration failed. Please try again.";
                }
                $stmt->close();
            }
        }
    }
}

// Flash message
if (isset($_SESSION['reg_success'])) {
    $register_success = $_SESSION['reg_success'];
    unset($_SESSION['reg_success']);
}

// ─── Which view to show ───────────────────────────────────────────────────────
$show_otp_step  = (isset($_GET['step']) && $_GET['step'] === 'otp'      && !empty($_SESSION['pending_reg']));
$show_fp        = (isset($_GET['step']) && $_GET['step'] === 'forgot');
$show_fp_otp    = (isset($_GET['step']) && $_GET['step'] === 'fp_otp'   && !empty($_SESSION['fp_otp_email']));
$show_fp_reset  = (isset($_GET['step']) && $_GET['step'] === 'fp_reset' && !empty($_SESSION['fp_otp_verified']));
$show_register  = isset($_GET['register']) && !$show_otp_step;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        if ($show_otp_step)  echo 'Verify Email';
        elseif ($show_fp || $show_fp_otp || $show_fp_reset) echo 'Forgot Password';
        elseif ($show_register) echo 'Register';
        else echo 'Login';
        ?> — Recovery Spa
    </title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
.auth-wrap { 
    min-height: 100vh; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    padding: 2rem 1rem; 
    /* Edit the URL below to your image path */
    background: linear-gradient(rgba(59, 42, 26, 0.6), rgba(59, 42, 26, 0.6)), 
                url('../img/login.jpg') no-repeat center center fixed; 
    background-size: cover; 
}        .auth-card { width:100%; max-width:480px; background:#fff; border-radius:16px; box-shadow:0 8px 40px rgba(59,42,26,0.12); overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#3B2A1A,#6B4C30); padding:2rem; text-align:center; color:#FAF3E8; }
        .auth-header .logo-icon { font-size:2.5rem; display:block; margin-bottom:0.5rem; }
        .auth-header h1 { font-size:1.4rem; margin:0 0 4px; letter-spacing:1px; }
        .auth-header p  { font-size:0.8rem; opacity:0.6; margin:0; }
        .auth-body { padding:2rem; }
        .steps { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:1.75rem; }
        .step-item { display:flex; flex-direction:column; align-items:center; gap:4px; }
        .step-circle { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.78rem; font-weight:700; background:#EAD8C0; color:#A07850; border:2px solid #EAD8C0; transition:all 0.2s; }
        .step-circle.active { background:#C96A2C; color:#fff; border-color:#C96A2C; }
        .step-circle.done   { background:#198754; color:#fff; border-color:#198754; }
        .step-label { font-size:0.68rem; color:#A07850; font-weight:600; white-space:nowrap; }
        .step-label.active { color:#C96A2C; }
        .step-label.done   { color:#198754; }
        .step-line { width:48px; height:2px; background:#EAD8C0; margin:0 4px; margin-bottom:20px; flex-shrink:0; }
        .step-line.done { background:#198754; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:0.82rem; font-weight:600; color:#3B2A1A; margin-bottom:0.35rem; }
        .form-group input, .form-group textarea, .form-group select { width:100%; padding:0.7rem 0.9rem; border:2px solid #EAD8C0; border-radius:8px; font-family:inherit; font-size:0.9rem; color:#3B2A1A; background:#FDFAF6; transition:border-color 0.18s; box-sizing:border-box; }
        .form-group input:focus, .form-group textarea:focus { outline:none; border-color:#C96A2C; }
        .form-group textarea { resize:vertical; min-height:70px; }
        .required { color:#dc3545; margin-left:2px; }
        .otp-box { display:flex; justify-content:center; gap:10px; margin:1.5rem 0; }
        .otp-box input { width:48px; height:56px; border:2px solid #EAD8C0; border-radius:10px; text-align:center; font-size:1.4rem; font-weight:700; color:#3B2A1A; background:#FDFAF6; transition:border-color 0.18s; }
        .otp-box input:focus { outline:none; border-color:#C96A2C; }
        .otp-box input.filled { border-color:#C96A2C; background:#fff8f3; }
        .otp-info-card { background:#fff8f3; border:1px solid #EAD8C0; border-radius:10px; padding:1rem 1.2rem; margin-bottom:1.5rem; font-size:0.85rem; color:#6B4C30; }
        .otp-info-card strong { color:#3B2A1A; }
        .otp-email-tag { display:inline-block; background:#FAF3E8; border:1px solid #EAD8C0; border-radius:20px; padding:2px 10px; font-weight:700; color:#C96A2C; font-size:0.88rem; }
        .btn-primary { width:100%; padding:0.85rem; background:linear-gradient(135deg,#C96A2C,#A94F1D); color:#fff; border:none; border-radius:8px; font-size:0.95rem; font-weight:700; cursor:pointer; transition:opacity 0.18s; }
        .btn-primary:hover   { opacity:0.9; }
        .btn-primary:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-secondary { padding:0.6rem 1.1rem; background:#FAF3E8; color:#3B2A1A; border:2px solid #EAD8C0; border-radius:8px; font-size:0.82rem; font-weight:600; cursor:pointer; transition:all 0.18s; white-space:nowrap; }
        .btn-secondary:hover { border-color:#C96A2C; color:#C96A2C; }
        .btn-secondary:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-link { background:none; border:none; color:#C96A2C; font-weight:600; cursor:pointer; font-size:0.85rem; padding:0; text-decoration:underline; }
        .alert { padding:0.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:0.875rem; border-left:4px solid; }
        .alert-danger  { background:#f8d7da; color:#842029; border-color:#dc3545; }
        .alert-success { background:#d1e7dd; color:#0a3622; border-color:#198754; }
        .alert-info    { background:#fff8f3; color:#6B4C30; border-color:#C96A2C; }
        #otpMsg  { font-size:0.85rem; margin-top:0.6rem; padding:0.6rem 0.9rem; border-radius:8px; display:none; }
        #otpMsg.success, #fpOtpMsg.success { background:#d1e7dd; color:#0a3622; }
        #otpMsg.error,   #fpOtpMsg.error   { background:#f8d7da; color:#842029; }
        #fpOtpMsg { font-size:0.85rem; margin-top:0.6rem; padding:0.6rem 0.9rem; border-radius:8px; display:none; }
        #otpTimer, #fpOtpTimer { font-size:0.8rem; color:#888; margin-top:6px; text-align:center; }
        .verified-badge { display:inline-flex; align-items:center; gap:6px; background:#d1e7dd; color:#0a3622; font-size:0.8rem; padding:4px 12px; border-radius:99px; font-weight:600; }
        .divider { text-align:center; color:#bbb; font-size:0.8rem; margin:1.2rem 0; }
        .form-row { display:flex; gap:0.75rem; }
        .form-row .form-group { flex:1; }
        .otp-progress { height:4px; background:#EAD8C0; border-radius:99px; margin-bottom:1.5rem; overflow:hidden; }
        .otp-progress-bar { height:100%; width:0%; background:linear-gradient(90deg,#C96A2C,#E8903A); border-radius:99px; transition:width 0.3s; }
        .pwd-strength { height:3px; border-radius:99px; margin-top:4px; transition:all 0.3s; }
    </style>
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">

    <div class="auth-header">
        <span class="logo-icon">
        </span>

        <h1 id="secret-admin-trigger"
            style="cursor:default;user-select:none;">RECOVERY ILOILO</h1>
        <p>
            <?php
            if ($show_otp_step)    echo 'Verify your email to complete registration';
            elseif ($show_fp)      echo 'Reset your password';
            elseif ($show_fp_otp)  echo 'Enter the OTP sent to your email';
            elseif ($show_fp_reset)echo 'Set your new password';
            elseif ($show_register)echo 'Create your account';
            else                   echo 'Welcome back';
            ?>
        </p>
    </div>

    <div class="auth-body">

    <?php if ($show_otp_step): ?>
    <!-- ══════════════ REGISTRATION OTP STEP ══════════════ -->
    <div class="steps">
        <div class="step-item"><div class="step-circle done">✓</div><div class="step-label done">Details</div></div>
        <div class="step-line done"></div>
        <div class="step-item"><div class="step-circle active">2</div><div class="step-label active">Verify Email</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">3</div><div class="step-label">Done</div></div>
    </div>
    <?php if ($register_error): ?><div class="alert alert-danger"><?php echo $register_error; ?></div><?php endif; ?>
    <?php $pending = $_SESSION['pending_reg'] ?? []; $pending_email = $pending['email'] ?? ''; ?>
    <div class="otp-info-card">
        📧 We'll send a 6-digit code to<br>
        <span class="otp-email-tag"><?php echo htmlspecialchars($pending_email); ?></span><br>
        <small style="color:#999;font-size:0.78rem;">Code expires in 10 minutes.</small>
    </div>
    <div class="otp-box" id="otpBox">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
    </div>
    <div id="otpMsg"></div>
    <div id="otpTimer"></div>
    <div id="verifiedSection" style="display:none;text-align:center;margin:1rem 0;">
        <span class="verified-badge">✔ Email Verified!</span>
        <p style="font-size:0.82rem;color:#555;margin-top:0.6rem;">Completing your registration…</p>
    </div>
    <div id="otpActions" style="display:flex;flex-direction:column;gap:0.75rem;margin-top:1.25rem;">
        <button type="button" id="sendOtpBtn" class="btn-primary" onclick="sendOTP()">📨 Send OTP to My Email</button>
        <button type="button" id="verifyOtpBtn" class="btn-primary" onclick="verifyOTP()" style="display:none;" disabled>✅ Verify OTP</button>
    </div>
    <form method="POST" id="completeRegForm" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="complete_register" value="1">
    </form>
    <div style="text-align:center;margin-top:1.25rem;">
        <a href="auth.php?register=1" style="color:#888;font-size:0.82rem;">← Go back and change details</a>
    </div>

    <?php elseif ($show_fp): ?>
    <!-- ══════════════ FORGOT PASSWORD — STEP 1: Enter Email ══════════════ -->
    <div class="steps">
        <div class="step-item"><div class="step-circle active">1</div><div class="step-label active">Email</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">2</div><div class="step-label">Verify OTP</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">3</div><div class="step-label">New Password</div></div>
    </div>

    <?php if ($fp_error): ?><div class="alert alert-danger"><?php echo $fp_error; ?></div><?php endif; ?>

    <p style="font-size:0.88rem;color:#6B4C30;margin-bottom:1.25rem;line-height:1.6;">
        Enter the email address associated with your account and we'll send you a 6-digit OTP to reset your password.
    </p>

    <form method="POST" action="auth.php?step=fp_otp" id="fpEmailForm">
        <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <input type="email" name="fp_email" id="fpEmailInput"
                   placeholder="your@email.com" required
                   value="<?php echo htmlspecialchars($_POST['fp_email'] ?? ''); ?>">
        </div>
        <button type="button" id="fpSendOtpBtn" class="btn-primary" onclick="sendFpOTP()">
            📨 Send OTP to My Email
        </button>
    </form>

    <div id="fpSendMsg" style="font-size:0.85rem;margin-top:0.75rem;padding:0.6rem 0.9rem;border-radius:8px;display:none;"></div>

    <div style="text-align:center;margin-top:1.5rem;">
        <a href="auth.php" style="color:#888;font-size:0.82rem;">← Back to Login</a>
    </div>

    <?php elseif ($show_fp_otp): ?>
    <!-- ══════════════ FORGOT PASSWORD — STEP 2: OTP ══════════════ -->
    <div class="steps">
        <div class="step-item"><div class="step-circle done">✓</div><div class="step-label done">Email</div></div>
        <div class="step-line done"></div>
        <div class="step-item"><div class="step-circle active">2</div><div class="step-label active">Verify OTP</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">3</div><div class="step-label">New Password</div></div>
    </div>

    <div class="otp-info-card">
        🔑 Enter the 6-digit OTP sent to<br>
        <span class="otp-email-tag"><?php echo htmlspecialchars($_SESSION['fp_otp_email'] ?? ''); ?></span><br>
        <small style="color:#999;font-size:0.78rem;">Code expires in 10 minutes.</small>
    </div>

    <div class="otp-box" id="fpOtpBox">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]">
        <input type="text" maxlength="1" class="fp-otp-digit" inputmode="numeric" pattern="[0-9]">
    </div>

    <div id="fpOtpMsg"></div>
    <div id="fpOtpTimer"></div>

    <div id="fpVerifiedSection" style="display:none;text-align:center;margin:1rem 0;">
        <span class="verified-badge">✔ OTP Verified! Redirecting…</span>
    </div>

    <div id="fpOtpActions" style="display:flex;flex-direction:column;gap:0.75rem;margin-top:1.25rem;">
        <button type="button" id="fpVerifyOtpBtn" class="btn-primary" onclick="verifyFpOTP()" disabled>
            ✅ Verify OTP
        </button>
        <button type="button" id="fpResendBtn" class="btn-secondary" onclick="resendFpOTP()" disabled>
            🔄 Resend OTP
        </button>
    </div>

    <div style="text-align:center;margin-top:1.25rem;">
        <a href="auth.php?step=forgot" style="color:#888;font-size:0.82rem;">← Use a different email</a>
    </div>

    <?php elseif ($show_fp_reset): ?>
    <!-- ══════════════ FORGOT PASSWORD — STEP 3: New Password ══════════════ -->
    <div class="steps">
        <div class="step-item"><div class="step-circle done">✓</div><div class="step-label done">Email</div></div>
        <div class="step-line done"></div>
        <div class="step-item"><div class="step-circle done">✓</div><div class="step-label done">Verify OTP</div></div>
        <div class="step-line done"></div>
        <div class="step-item"><div class="step-circle active">3</div><div class="step-label active">New Password</div></div>
    </div>

    <?php if ($fp_error): ?><div class="alert alert-danger"><?php echo $fp_error; ?></div><?php endif; ?>

    <p style="font-size:0.88rem;color:#6B4C30;margin-bottom:1.25rem;">
        Set a new password for <strong style="color:#C96A2C;"><?php echo htmlspecialchars($_SESSION['fp_otp_email'] ?? ''); ?></strong>
    </p>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>New Password <span class="required">*</span></label>
            <input type="password" name="new_password" id="newPwd"
                   placeholder="Min. 8 characters" required
                   oninput="checkPwdStrength(this.value)">
            <div class="pwd-strength" id="pwdStrengthBar"></div>
        </div>
        <div class="form-group">
            <label>Confirm New Password <span class="required">*</span></label>
            <input type="password" name="confirm_password" id="confirmNewPwd"
                   placeholder="Re-enter new password" required
                   oninput="checkConfirm()">
        </div>
        <button type="submit" name="change_password" class="btn-primary">
            🔒 Change Password
        </button>
    </form>

    <?php elseif ($show_register): ?>
    <!-- ══════════════ REGISTER STEP 1 ══════════════ -->
    <div class="steps">
        <div class="step-item"><div class="step-circle active">1</div><div class="step-label active">Details</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">2</div><div class="step-label">Verify Email</div></div>
        <div class="step-line"></div>
        <div class="step-item"><div class="step-circle">3</div><div class="step-label">Done</div></div>
    </div>
    <?php if ($register_error): ?><div class="alert alert-danger"><?php echo $register_error; ?></div><?php endif; ?>
    <form method="POST" id="registerForm">
        <?php echo csrf_field(); ?>
        <div class="form-row">
            <div class="form-group">
                <label>Username <span class="required">*</span></label>
                <input type="text" name="username" placeholder="Choose a username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" placeholder="Your full name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <input type="email" name="email" placeholder="your@gmail.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <input type="password" name="password" placeholder="Min. 8 characters" required id="pwdInput">
            </div>
            <div class="form-group">
                <label>Confirm Password <span class="required">*</span></label>
                <input type="password" name="confirm_password" placeholder="Re-enter password" required id="confirmInput">
            </div>
        </div>
        <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="e.g. 09123456789" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Address</label>
            <textarea name="address" placeholder="Enter your address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
        </div>
        <button type="submit" name="step1_register" class="btn-primary">Continue to Email Verification →</button>
    </form>
    <div class="divider">— or —</div>
    <p style="text-align:center;font-size:0.88rem;color:#666;">
        Already have an account? <a href="auth.php" style="color:#C96A2C;font-weight:bold;">Login here</a>
    </p>

    <?php else: ?>
    <!-- ══════════════ LOGIN ══════════════ -->
    <?php if ($register_success): ?><div class="alert alert-success">🎉 <?php echo $register_success; ?></div><?php endif; ?>
    <?php if ($login_error): ?><div class="alert alert-danger"><?php echo $login_error; ?></div><?php endif; ?>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>email</label>
            <input type="text" name="email" placeholder="Enter your email" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Password</label>
            <div style="position:relative;">
                <input type="password" name="password" id="loginPwd"
                       placeholder="Enter your password" required
                       style="width:100%;padding:0.7rem 2.8rem 0.7rem 0.9rem;
                              border:2px solid #EAD8C0;border-radius:8px;
                              font-size:0.9rem;color:#3B2A1A;background:#FDFAF6;
                              box-sizing:border-box;transition:border-color 0.18s;">
                <button type="button" onclick="toggleLoginPwd()"
                        id="loginPwdToggle"
                        style="position:absolute;right:0.75rem;top:50%;
                               transform:translateY(-50%);background:none;
                               border:none;cursor:pointer;font-size:1rem;
                               color:#A07850;padding:0;line-height:1;"
                        aria-label="Show password">👁</button>
            </div>
        </div>
        <button type="submit" name="login" class="btn-primary">Login</button>
    </form>

    <!-- Forgot Password link -->
    <div style="text-align:right;margin-top:0.5rem;">
        <a href="auth.php?step=forgot" style="color:#C96A2C;font-size:0.83rem;font-weight:600;">
            Forgot password?
        </a>
    </div>

    <div class="divider">— or —</div>
    <p style="text-align:center;font-size:0.88rem;color:#666;">
        Don't have an account? <a href="auth.php?register=1" style="color:#C96A2C;font-weight:bold;">Register here</a>
    </p>
    <?php endif; ?>

    </div>
</div>
</div>

<?php if ($show_otp_step): ?>
<script>
let timerInterval = null;
let otpSent = false;
const digits = document.querySelectorAll('.otp-digit');
const sendBtn = document.getElementById('sendOtpBtn');
const verifyBtn = document.getElementById('verifyOtpBtn');
const otpMsg = document.getElementById('otpMsg');
const timerEl = document.getElementById('otpTimer');
const pendingEmail = <?php echo json_encode($pending_email); ?>;

digits.forEach((input, i) => {
    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '');
        if (input.value && i < digits.length - 1) digits[i + 1].focus();
        updateDigitStyles(); toggleVerifyBtn();
    });
    input.addEventListener('keydown', e => { if (e.key === 'Backspace' && !input.value && i > 0) digits[i - 1].focus(); });
    input.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, j) => { if (digits[j]) digits[j].value = ch; });
        updateDigitStyles(); toggleVerifyBtn();
        if (pasted.length > 0) digits[Math.min(pasted.length, 5)].focus();
    });
});
function updateDigitStyles() { digits.forEach(d => d.classList.toggle('filled', d.value.length === 1)); }
function getOTP() { return Array.from(digits).map(d => d.value).join(''); }
function toggleVerifyBtn() { if (otpSent) verifyBtn.disabled = getOTP().length !== 6; }
function showMsg(msg, type) { otpMsg.textContent = msg; otpMsg.className = type; otpMsg.style.display = 'block'; }

function sendOTP() {
    sendBtn.disabled = true; sendBtn.textContent = '⏳ Sending…'; showMsg('', '');
    fetch('auth.php?step=otp', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=send_otp&email=' + encodeURIComponent(pendingEmail) })
    .then(r => r.text()).then(text => {
        try {
            const data = JSON.parse(text);
            showMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) { otpSent = true; document.getElementById('otpBox').style.display = 'flex'; verifyBtn.style.display = 'block'; startTimer(60); digits[0].focus(); }
            else { sendBtn.disabled = false; sendBtn.textContent = '📨 Send OTP to My Email'; }
        } catch(e) { showMsg('Server error. Check console.', 'error'); console.error(text); sendBtn.disabled = false; sendBtn.textContent = '📨 Send OTP to My Email'; }
    }).catch(() => { showMsg('Could not reach server.', 'error'); sendBtn.disabled = false; sendBtn.textContent = '📨 Send OTP to My Email'; });
}

function verifyOTP() {
    const otp = getOTP();
    if (otp.length !== 6) { showMsg('Please enter all 6 digits.', 'error'); return; }
    verifyBtn.disabled = true; verifyBtn.textContent = '⏳ Verifying…';
    fetch('auth.php?step=otp', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=verify_otp&otp=' + encodeURIComponent(otp) })
    .then(r => r.text()).then(text => {
        try {
            const data = JSON.parse(text);
            showMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                clearInterval(timerInterval);
                document.getElementById('otpBox').style.display = 'none';
                document.getElementById('otpActions').style.display = 'none';
                timerEl.textContent = '';
                document.getElementById('verifiedSection').style.display = 'block';
                setTimeout(() => { document.getElementById('completeRegForm').submit(); }, 1200);
            } else { verifyBtn.disabled = false; verifyBtn.textContent = '✅ Verify OTP'; }
        } catch(e) { showMsg('Server error.', 'error'); verifyBtn.disabled = false; verifyBtn.textContent = '✅ Verify OTP'; }
    }).catch(() => { showMsg('Could not reach server.', 'error'); verifyBtn.disabled = false; verifyBtn.textContent = '✅ Verify OTP'; });
}

function startTimer(seconds) {
    clearInterval(timerInterval); let remaining = seconds;
    sendBtn.textContent = 'Resend OTP';
    timerInterval = setInterval(() => {
        timerEl.textContent = `Resend available in ${remaining}s`; remaining--;
        if (remaining < 0) { clearInterval(timerInterval); timerEl.textContent = ''; sendBtn.disabled = false; sendBtn.textContent = '🔄 Resend OTP'; }
    }, 1000);
}
document.getElementById('otpBox').style.display = 'none';
</script>

<?php elseif ($show_fp): ?>
<script>
function sendFpOTP() {
    const email = document.getElementById('fpEmailInput').value.trim();
    if (!email) { alert('Please enter your email address.'); return; }
    const btn = document.getElementById('fpSendOtpBtn');
    const msg = document.getElementById('fpSendMsg');
    btn.disabled = true; btn.textContent = '⏳ Sending…';
    msg.style.display = 'none';
    fetch('auth.php?step=forgot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_fp_otp&email=' + encodeURIComponent(email)
    })
    .then(r => r.text()).then(text => {
        try {
            const data = JSON.parse(text);
            msg.textContent = data.message;
            msg.style.background = data.success ? '#d1e7dd' : '#f8d7da';
            msg.style.color      = data.success ? '#0a3622' : '#842029';
            msg.style.display    = 'block';
            if (data.success) {
                // Save email to session via hidden redirect
                window.location.href = 'auth.php?step=fp_otp&email=' + encodeURIComponent(email);
            } else {
                btn.disabled = false; btn.textContent = '📨 Send OTP to My Email';
            }
        } catch(e) { msg.textContent = 'Server error.'; msg.style.display = 'block'; btn.disabled = false; btn.textContent = '📨 Send OTP to My Email'; }
    }).catch(() => { btn.disabled = false; btn.textContent = '📨 Send OTP to My Email'; });
}
</script>

<?php elseif ($show_fp_otp): ?>
<script>
let fpTimerInterval = null;
const fpDigits  = document.querySelectorAll('.fp-otp-digit');
const fpVerBtn  = document.getElementById('fpVerifyOtpBtn');
const fpResBtn  = document.getElementById('fpResendBtn');
const fpOtpMsg  = document.getElementById('fpOtpMsg');
const fpTimerEl = document.getElementById('fpOtpTimer');
const fpEmail   = <?php echo json_encode($_SESSION['fp_otp_email'] ?? ''); ?>;

fpDigits.forEach((input, i) => {
    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '');
        if (input.value && i < fpDigits.length - 1) fpDigits[i + 1].focus();
        fpDigits.forEach(d => d.classList.toggle('filled', d.value.length === 1));
        fpVerBtn.disabled = getFpOTP().length !== 6;
    });
    input.addEventListener('keydown', e => { if (e.key === 'Backspace' && !input.value && i > 0) fpDigits[i - 1].focus(); });
    input.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, j) => { if (fpDigits[j]) fpDigits[j].value = ch; });
        fpDigits.forEach(d => d.classList.toggle('filled', d.value.length === 1));
        fpVerBtn.disabled = getFpOTP().length !== 6;
        if (pasted.length > 0) fpDigits[Math.min(pasted.length, 5)].focus();
    });
});

function getFpOTP() { return Array.from(fpDigits).map(d => d.value).join(''); }

function showFpMsg(msg, type) { fpOtpMsg.textContent = msg; fpOtpMsg.className = type; fpOtpMsg.style.display = 'block'; }

function verifyFpOTP() {
    const otp = getFpOTP();
    if (otp.length !== 6) { showFpMsg('Please enter all 6 digits.', 'error'); return; }
    fpVerBtn.disabled = true; fpVerBtn.textContent = '⏳ Verifying…';
    fetch('auth.php?step=fp_otp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=verify_fp_otp&otp=' + encodeURIComponent(otp)
    })
    .then(r => r.text()).then(text => {
        try {
            const data = JSON.parse(text);
            showFpMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                clearInterval(fpTimerInterval);
                document.getElementById('fpOtpBox').style.display    = 'none';
                document.getElementById('fpOtpActions').style.display = 'none';
                fpTimerEl.textContent = '';
                document.getElementById('fpVerifiedSection').style.display = 'block';
                setTimeout(() => { window.location.href = 'auth.php?step=fp_reset'; }, 1000);
            } else { fpVerBtn.disabled = false; fpVerBtn.textContent = '✅ Verify OTP'; }
        } catch(e) { showFpMsg('Server error.', 'error'); fpVerBtn.disabled = false; fpVerBtn.textContent = '✅ Verify OTP'; }
    }).catch(() => { showFpMsg('Could not reach server.', 'error'); fpVerBtn.disabled = false; fpVerBtn.textContent = '✅ Verify OTP'; });
}

function resendFpOTP() {
    fpResBtn.disabled = true; fpResBtn.textContent = '⏳ Sending…';
    fetch('auth.php?step=forgot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_fp_otp&email=' + encodeURIComponent(fpEmail)
    })
    .then(r => r.text()).then(text => {
        try {
            const data = JSON.parse(text);
            showFpMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) { startFpTimer(60); fpDigits.forEach(d => { d.value = ''; d.classList.remove('filled'); }); fpVerBtn.disabled = true; fpDigits[0].focus(); }
            else { fpResBtn.disabled = false; fpResBtn.textContent = '🔄 Resend OTP'; }
        } catch(e) { fpResBtn.disabled = false; fpResBtn.textContent = '🔄 Resend OTP'; }
    }).catch(() => { fpResBtn.disabled = false; fpResBtn.textContent = '🔄 Resend OTP'; });
}

function startFpTimer(seconds) {
    clearInterval(fpTimerInterval); let remaining = seconds;
    fpResBtn.disabled = true;
    fpTimerInterval = setInterval(() => {
        fpTimerEl.textContent = `Resend available in ${remaining}s`; remaining--;
        if (remaining < 0) { clearInterval(fpTimerInterval); fpTimerEl.textContent = ''; fpResBtn.disabled = false; fpResBtn.textContent = '🔄 Resend OTP'; }
    }, 1000);
}

// Auto-start timer on page load (OTP was already sent on redirect)
startFpTimer(60);
fpDigits[0].focus();
</script>

<?php elseif ($show_fp_reset): ?>
<script>
function checkPwdStrength(val) {
    const bar = document.getElementById('pwdStrengthBar');
    if (!val) { bar.style.width = '0'; return; }
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['#dc3545','#fd7e14','#ffc107','#20c997','#198754'];
    const widths  = ['20%','40%','60%','80%','100%'];
    bar.style.background = colors[score - 1] || '#EAD8C0';
    bar.style.width      = widths[score - 1] || '0%';
}
function checkConfirm() {
    const p = document.getElementById('newPwd').value;
    const c = document.getElementById('confirmNewPwd');
    c.style.borderColor = (c.value && c.value !== p) ? '#dc3545' : '';
}
</script>

<?php elseif ($show_register): ?>
<script>
document.getElementById('confirmInput').addEventListener('input', function() {
    const pwd = document.getElementById('pwdInput').value;
    this.style.borderColor = (this.value && pwd !== this.value) ? '#dc3545' : '';
});
</script>
<?php endif; ?>

<script>
(function() {
    var clicks = 0;
    var timer  = null;
    var trigger = document.getElementById('secret-admin-trigger');
    if (!trigger) return;
    trigger.addEventListener('click', function() {
        clicks++;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() { clicks = 0; }, 2000);
        if (clicks >= 3) {
            clicks = 0;
            clearTimeout(timer);
            openGateModal();
        }
    });
})();

function openGateModal() {
    document.getElementById('gateModal').style.display = 'flex';
    var inp = document.getElementById('gateCodeInput');
    inp.value = '';
    document.getElementById('gateError').textContent = '';
    setTimeout(function(){ inp.focus(); }, 120);
}

function closeGateModal() {
    document.getElementById('gateModal').style.display = 'none';
}

async function submitGateCode() {
    var btn   = document.getElementById('gateSubmitBtn');
    var errEl = document.getElementById('gateError');
    var code  = document.getElementById('gateCodeInput').value.trim();
    errEl.textContent = '';
    if (!/^\d{4}$/.test(code)) {
        errEl.textContent = 'Please enter a 4-digit code.';
        return;
    }
    btn.disabled = true; btn.textContent = 'Checking…';
    try {
        var fd = new FormData();
        fd.append('action', 'verify_gate_code');
        fd.append('code', code);
        var res  = await fetch(window.location.pathname, { method:'POST', body:fd });
        var data = await res.json();
        if (data.ok) {
            window.location.href = '../admin/admin_login.php';
        } else {
            errEl.textContent = data.error || 'Incorrect code.';
            document.getElementById('gateCodeInput').value = '';
            document.getElementById('gateCodeInput').focus();
            btn.disabled = false; btn.textContent = 'Continue →';
        }
    } catch(e) {
        errEl.textContent = 'Network error. Try again.';
        btn.disabled = false; btn.textContent = 'Continue →';
    }
}

document.addEventListener('keydown', function(e) {
    var modal = document.getElementById('gateModal');
    if (modal && modal.style.display === 'flex' && e.key === 'Enter') {
        submitGateCode();
    }
    if (modal && modal.style.display === 'flex' && e.key === 'Escape') {
        closeGateModal();
    }
});
</script>

<script>
function toggleLoginPwd() {
    const input  = document.getElementById('loginPwd');
    const btn    = document.getElementById('loginPwdToggle');
    const isHide = input.type === 'password';
    input.type   = isHide ? 'text' : 'password';
    btn.textContent = isHide ? '🙈' : '👁';
    btn.setAttribute('aria-label', isHide ? 'Hide password' : 'Show password');
}
</script>

<style>
@keyframes popIn {
    from { opacity:0; transform:scale(0.88); }
    to   { opacity:1; transform:scale(1); }
}
</style>

<div id="gateModal" style="display:none;position:fixed;inset:0;
     z-index:99999;background:rgba(30,20,10,0.65);
     backdrop-filter:blur(6px);align-items:center;
     justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:16px;
                padding:2rem 1.75rem;max-width:340px;width:100%;
                box-shadow:0 24px 60px rgba(0,0,0,0.3);
                animation:popIn .3s cubic-bezier(.34,1.56,.64,1);
                text-align:center;">
        <div style="width:52px;height:52px;border-radius:50%;
                    background:linear-gradient(135deg,#3B2A1A,#6B4C30);
                    display:flex;align-items:center;justify-content:center;
                    font-size:1.4rem;margin:0 auto 0.85rem;">🔒</div>
        <div style="font-size:1.05rem;font-weight:700;
                    color:#3B2A1A;margin-bottom:0.35rem;">
            Access Code Required
        </div>
        <div style="font-size:0.8rem;color:#A07850;margin-bottom:1.25rem;">
            Enter the 4-digit code to continue
        </div>
        <input type="password" id="gateCodeInput" maxlength="4"
               inputmode="numeric" placeholder="• • • •"
               style="width:100%;padding:0.85rem;border:2px solid #EAD8C0;
                      border-radius:10px;font-size:1.6rem;text-align:center;
                      letter-spacing:0.5em;color:#3B2A1A;background:#FDFAF6;
                      box-sizing:border-box;font-family:monospace;"
               oninput="this.value=this.value.replace(/\D/g,'')">
        <div id="gateError" style="color:#dc2626;font-size:0.78rem;
             margin-top:0.5rem;min-height:1.1em;"></div>
        <div style="display:flex;gap:0.65rem;margin-top:1rem;">
            <button type="button" onclick="closeGateModal()"
                    style="flex:1;padding:0.7rem;background:#FAF3E8;
                           color:#3B2A1A;border:2px solid #EAD8C0;
                           border-radius:8px;font-weight:600;
                           cursor:pointer;">Cancel</button>
            <button type="button" onclick="submitGateCode()"
                    id="gateSubmitBtn"
                    style="flex:2;padding:0.7rem;
                           background:linear-gradient(135deg,#C96A2C,#A94F1D);
                           color:#fff;border:none;border-radius:8px;
                           font-weight:700;cursor:pointer;">
                Continue →</button>
        </div>
    </div>
</div>

</body>
</html>