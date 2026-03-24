<?php
ob_start(); // Buffer all output — prevents PHP notices/warnings from corrupting JSON responses

require_once '../config.php';

// Load PHPMailer via Composer autoloader
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) {
    // Fallback: try from document root
    $autoload_path = $_SERVER['DOCUMENT_ROOT'] . '/spa_ecommerce_system/vendor/autoload.php';
}
require_once $autoload_path;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─── LOGOUT ───────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header('Location: auth.php');
    exit();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header($_SESSION['role'] === 'admin' ? "Location: ../admin/index.php" : "Location: ../user/index.php");
    exit();
}

$login_error      = '';
$register_error   = '';
$register_success = '';

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: SEND OTP
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    ob_clean(); // Discard any stray output before sending JSON
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    // Check if email already registered
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Rate limiting — 60s cooldown
    if (isset($_SESSION['otp_sent_at']) && (time() - $_SESSION['otp_sent_at']) < 60) {
        $wait = 60 - (time() - $_SESSION['otp_sent_at']);
        echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another OTP."]);
        exit;
    }

    // Generate OTP — plain integer like the reference, zero-padded to 6 digits
    $otp        = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Store plain OTP in session (simple string comparison on verify)
    $_SESSION['otp']          = $otp;
    $_SESSION['otp_expiry']   = $otp_expiry;
    $_SESSION['otp_email']    = $email;
    $_SESSION['otp_sent_at']  = time();
    $_SESSION['otp_attempts'] = 0;
    $_SESSION['otp_verified'] = false;

    // Send email via PHPMailer
    $pending_name = $_SESSION['pending_reg']['full_name'] ?? 'User';
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_NAME);
        $mail->addAddress($email, $pending_name);
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Serenity Spa Account';
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;'>
                <div style='background:linear-gradient(135deg,#3B2A1A,#6B4C30);padding:25px;text-align:center;border-radius:12px 12px 0 0;'>
                    <span style='font-size:2.5rem;'>💆</span>
                    <h2 style='color:#FAF3E8;margin:8px 0 4px;letter-spacing:1px;'>SERENITY SPA</h2>
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
            </div>
        ";
        $mail->AltBody = "Your OTP verification code is: {$otp} (expires in 10 minutes).";
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent! Check your Gmail inbox.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Mailer error: ' . $mail->ErrorInfo]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: VERIFY OTP
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    ob_clean(); // Discard any stray output before sending JSON
    header('Content-Type: application/json');

    $input = trim($_POST['otp'] ?? '');

    if (!isset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit;
    }

    // Check expiry — stored as datetime string (Y-m-d H:i:s)
    if (strtotime($_SESSION['otp_expiry']) < time()) {
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit;
    }

    // Attempt limit
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    if ($_SESSION['otp_attempts'] > 5) {
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'], $_SESSION['otp_attempts']);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
        exit;
    }

    // Plain string comparison — same as the reference system
    if ($input !== $_SESSION['otp']) {
        $remaining = 5 - $_SESSION['otp_attempts'];
        echo json_encode(['success' => false, 'message' => "Incorrect OTP. {$remaining} attempt(s) remaining."]);
        exit;
    }

    // ✅ OTP matches — mark session as verified
    $_SESSION['otp_verified']       = true;
    $_SESSION['otp_verified_email'] = $_SESSION['otp_email'];
    unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
    echo json_encode(['success' => true, 'message' => 'Email verified! Completing your registration…']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $login_error = "Please enter your username and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            if ($user['role'] === 'user') {
                $_SESSION['cart'] = load_cart_from_db($conn, $user['id']);
            }

            header($user['role'] === 'admin' ? "Location: ../admin/index.php" : "Location: ../user/index.php");
            exit();
        } else {
            $login_error = "Invalid username or password.";
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — REGISTER FORM SUBMIT: Validate + store pending data, send OTP
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_register'])) {
    $username  = sanitize_input($_POST['username']  ?? '');
    $email     = sanitize_input($_POST['email']     ?? '');
    $password  = $_POST['password']                 ?? '';
    $confirm   = $_POST['confirm_password']         ?? '';
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $phone     = sanitize_input($_POST['phone']     ?? '');
    $address   = sanitize_input($_POST['address']   ?? '');

    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $register_error = "All required fields must be filled in.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $register_error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $register_error = "Passwords do not match.";
    } else {
        // Check duplicates
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();
        $duplicate = $stmt->num_rows > 0;
        $stmt->close();

        if ($duplicate) {
            $register_error = "Username or email is already taken.";
        } else {
            // Store pending registration in session — NOT yet in DB
            $_SESSION['pending_reg'] = [
                'username'  => $username,
                'email'     => $email,
                'password'  => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $full_name,
                'phone'     => $phone,
                'address'   => $address,
            ];

            // Clear any previous OTP state
            unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_email'],
                  $_SESSION['otp_sent_at'], $_SESSION['otp_attempts'],
                  $_SESSION['otp_verified'], $_SESSION['otp_verified_email']);

            // Redirect to OTP step
            header('Location: auth.php?step=otp');
            exit();
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — COMPLETE REGISTRATION: OTP verified → save to DB
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_register'])) {
    $pending = $_SESSION['pending_reg'] ?? null;

    if (!$pending) {
        $register_error = "Session expired. Please register again.";
    } elseif (empty($_SESSION['otp_verified']) || $_SESSION['otp_verified_email'] !== $pending['email']) {
        $register_error = "Email not verified. Please verify your OTP first.";
    } else {
        // Final duplicate check before inserting
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $pending['username'], $pending['email']);
        $stmt->execute();
        $stmt->store_result();
        $duplicate = $stmt->num_rows > 0;
        $stmt->close();

        if ($duplicate) {
            $register_error = "Username or email was already taken. Please register again.";
            unset($_SESSION['pending_reg']);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO users (username, password, email, full_name, phone, address, role)
                VALUES (?, ?, ?, ?, ?, ?, 'user')
            ");
            $stmt->bind_param(
                "ssssss",
                $pending['username'],
                $pending['password'],
                $pending['email'],
                $pending['full_name'],
                $pending['phone'],
                $pending['address']
            );

            if ($stmt->execute()) {
                // Clean up all OTP & pending data
                unset($_SESSION['pending_reg'],
                      $_SESSION['otp_verified'], $_SESSION['otp_verified_email'],
                      $_SESSION['otp_sent_at'], $_SESSION['otp_email']);

                // Set a success flash then go to login
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

// ─── Pick up flash message ────────────────────────────────────────────────────
if (isset($_SESSION['reg_success'])) {
    $register_success = $_SESSION['reg_success'];
    unset($_SESSION['reg_success']);
}

// ─── Determine which view to show ────────────────────────────────────────────
$show_otp_step = (isset($_GET['step']) && $_GET['step'] === 'otp' && !empty($_SESSION['pending_reg']));
$show_register = isset($_GET['register']) && !$show_otp_step;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $show_otp_step ? 'Verify Email' : ($show_register ? 'Register' : 'Login'); ?> — Serenity Spa</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        /* ── Layout ── */
        .auth-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: linear-gradient(135deg, #FAF3E8 0%, #F4E7D3 100%);
        }
        .auth-card {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(59,42,26,0.12);
            overflow: hidden;
        }
        .auth-header {
            background: linear-gradient(135deg, #3B2A1A, #6B4C30);
            padding: 2rem;
            text-align: center;
            color: #FAF3E8;
        }
        .auth-header .logo-icon { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; }
        .auth-header h1 { font-size: 1.4rem; margin: 0 0 4px; letter-spacing: 1px; }
        .auth-header p  { font-size: 0.8rem; opacity: 0.6; margin: 0; }
        .auth-body { padding: 2rem; }

        /* ── Steps indicator ── */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 1.75rem;
        }
        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .step-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 700;
            background: #EAD8C0; color: #A07850;
            border: 2px solid #EAD8C0;
            transition: all 0.2s;
        }
        .step-circle.active  { background: #C96A2C; color: #fff; border-color: #C96A2C; }
        .step-circle.done    { background: #198754; color: #fff; border-color: #198754; }
        .step-label { font-size: 0.68rem; color: #A07850; font-weight: 600; white-space: nowrap; }
        .step-label.active { color: #C96A2C; }
        .step-label.done   { color: #198754; }
        .step-line {
            width: 48px; height: 2px;
            background: #EAD8C0;
            margin: 0 4px;
            margin-bottom: 20px;
            flex-shrink: 0;
        }
        .step-line.done { background: #198754; }

        /* ── Form ── */
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block; font-size: 0.82rem; font-weight: 600;
            color: #3B2A1A; margin-bottom: 0.35rem;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%; padding: 0.7rem 0.9rem;
            border: 2px solid #EAD8C0; border-radius: 8px;
            font-family: inherit; font-size: 0.9rem; color: #3B2A1A;
            background: #FDFAF6; transition: border-color 0.18s;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group textarea:focus { outline: none; border-color: #C96A2C; }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .required { color: #dc3545; margin-left: 2px; }

        /* ── OTP specific ── */
        .otp-box {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 1.5rem 0;
        }
        .otp-box input {
            width: 48px; height: 56px;
            border: 2px solid #EAD8C0; border-radius: 10px;
            text-align: center; font-size: 1.4rem; font-weight: 700;
            color: #3B2A1A; background: #FDFAF6;
            transition: border-color 0.18s;
        }
        .otp-box input:focus { outline: none; border-color: #C96A2C; }
        .otp-box input.filled { border-color: #C96A2C; background: #fff8f3; }

        .otp-info-card {
            background: #fff8f3;
            border: 1px solid #EAD8C0;
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: #6B4C30;
        }
        .otp-info-card strong { color: #3B2A1A; }
        .otp-email-tag {
            display: inline-block;
            background: #FAF3E8;
            border: 1px solid #EAD8C0;
            border-radius: 20px;
            padding: 2px 10px;
            font-weight: 700;
            color: #C96A2C;
            font-size: 0.88rem;
        }

        /* ── Buttons ── */
        .btn-primary {
            width: 100%; padding: 0.85rem;
            background: linear-gradient(135deg, #C96A2C, #A94F1D);
            color: #fff; border: none; border-radius: 8px;
            font-size: 0.95rem; font-weight: 700; cursor: pointer;
            transition: opacity 0.18s;
        }
        .btn-primary:hover   { opacity: 0.9; }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary {
            padding: 0.6rem 1.1rem;
            background: #FAF3E8; color: #3B2A1A;
            border: 2px solid #EAD8C0; border-radius: 8px;
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
            transition: all 0.18s; white-space: nowrap;
        }
        .btn-secondary:hover    { border-color: #C96A2C; color: #C96A2C; }
        .btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-link {
            background: none; border: none; color: #C96A2C;
            font-weight: 600; cursor: pointer; font-size: 0.85rem;
            padding: 0; text-decoration: underline;
        }

        /* ── Alerts ── */
        .alert {
            padding: 0.75rem 1rem; border-radius: 8px;
            margin-bottom: 1rem; font-size: 0.875rem;
            border-left: 4px solid;
        }
        .alert-danger  { background: #f8d7da; color: #842029; border-color: #dc3545; }
        .alert-success { background: #d1e7dd; color: #0a3622; border-color: #198754; }
        .alert-info    { background: #fff8f3; color: #6B4C30; border-color: #C96A2C; }

        /* ── OTP status messages ── */
        #otpMsg { font-size: 0.85rem; margin-top: 0.6rem; padding: 0.6rem 0.9rem; border-radius: 8px; display: none; }
        #otpMsg.success { background: #d1e7dd; color: #0a3622; }
        #otpMsg.error   { background: #f8d7da; color: #842029; }
        #otpTimer { font-size: 0.8rem; color: #888; margin-top: 6px; text-align: center; }

        /* ── Verified badge ── */
        .verified-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #d1e7dd; color: #0a3622; font-size: 0.8rem;
            padding: 4px 12px; border-radius: 99px; font-weight: 600;
        }

        /* ── Divider ── */
        .divider { text-align: center; color: #bbb; font-size: 0.8rem; margin: 1.2rem 0; }

        /* ── 2-col grid ── */
        .form-row { display: flex; gap: 0.75rem; }
        .form-row .form-group { flex: 1; }

        /* ── Progress bar ── */
        .otp-progress {
            height: 4px; background: #EAD8C0; border-radius: 99px;
            margin-bottom: 1.5rem; overflow: hidden;
        }
        .otp-progress-bar {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, #C96A2C, #E8903A);
            border-radius: 99px; transition: width 0.3s;
        }
    </style>
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">

    <!-- Header -->
    <div class="auth-header">
        <span class="logo-icon">💆</span>
        <h1>SERENITY SPA</h1>
        <p>
            <?php
            if ($show_otp_step)   echo 'Verify your email to complete registration';
            elseif ($show_register) echo 'Create your account';
            else                    echo 'Welcome back';
            ?>
        </p>
    </div>

    <div class="auth-body">

    <?php if ($show_otp_step): ?>
    <!-- ════════════════════════════════════════════════════════
         STEP 2 — OTP VERIFICATION
    ════════════════════════════════════════════════════════ -->

    <!-- Steps indicator -->
    <div class="steps">
        <div class="step-item">
            <div class="step-circle done">✓</div>
            <div class="step-label done">Details</div>
        </div>
        <div class="step-line done"></div>
        <div class="step-item">
            <div class="step-circle active">2</div>
            <div class="step-label active">Verify Email</div>
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-circle">3</div>
            <div class="step-label">Done</div>
        </div>
    </div>

    <?php if ($register_error): ?>
        <div class="alert alert-danger"><?php echo $register_error; ?></div>
    <?php endif; ?>

    <?php
    $pending = $_SESSION['pending_reg'] ?? [];
    $pending_email = $pending['email'] ?? '';
    ?>

    <div class="otp-info-card">
        📧 We'll send a 6-digit code to<br>
        <span class="otp-email-tag"><?php echo htmlspecialchars($pending_email); ?></span><br>
        <small style="color:#999;font-size:0.78rem;">Code expires in 10 minutes.</small>
    </div>

    <!-- OTP digit inputs -->
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

    <div id="verifiedSection" style="display:none; text-align:center; margin: 1rem 0;">
        <span class="verified-badge">✔ Email Verified!</span>
        <p style="font-size:0.82rem;color:#555;margin-top:0.6rem;">Completing your registration…</p>
    </div>

    <!-- Action buttons -->
    <div id="otpActions" style="display:flex;flex-direction:column;gap:0.75rem;margin-top:1.25rem;">
        <button type="button" id="sendOtpBtn" class="btn-primary" onclick="sendOTP()">
            📨 Send OTP to My Email
        </button>
        <button type="button" id="verifyOtpBtn" class="btn-primary" onclick="verifyOTP()" style="display:none;" disabled>
            ✅ Verify OTP
        </button>
    </div>

    <!-- Hidden form to complete registration after OTP verified -->
    <form method="POST" id="completeRegForm" style="display:none;">
        <input type="hidden" name="complete_register" value="1">
    </form>

    <div style="text-align:center;margin-top:1.25rem;">
        <a href="auth.php?register=1" style="color:#888;font-size:0.82rem;">← Go back and change details</a>
    </div>

    <?php elseif ($show_register): ?>
    <!-- ════════════════════════════════════════════════════════
         STEP 1 — REGISTRATION FORM
    ════════════════════════════════════════════════════════ -->

    <!-- Steps indicator -->
    <div class="steps">
        <div class="step-item">
            <div class="step-circle active">1</div>
            <div class="step-label active">Details</div>
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-circle">2</div>
            <div class="step-label">Verify Email</div>
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-circle">3</div>
            <div class="step-label">Done</div>
        </div>
    </div>

    <?php if ($register_error): ?>
        <div class="alert alert-danger"><?php echo $register_error; ?></div>
    <?php endif; ?>

    <form method="POST" id="registerForm">
        <div class="form-row">
            <div class="form-group">
                <label>Username <span class="required">*</span></label>
                <input type="text" name="username" placeholder="Choose a username" required
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" placeholder="Your full name" required
                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <input type="email" name="email" placeholder="your@gmail.com" required
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <input type="password" name="password" placeholder="Min. 6 characters" required id="pwdInput">
            </div>
            <div class="form-group">
                <label>Confirm Password <span class="required">*</span></label>
                <input type="password" name="confirm_password" placeholder="Re-enter password" required id="confirmInput">
            </div>
        </div>

        <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="e.g. 09123456789"
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>Address</label>
            <textarea name="address" placeholder="Enter your address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
        </div>

        <button type="submit" name="step1_register" class="btn-primary">
            Continue to Email Verification →
        </button>
    </form>

    <div class="divider">— or —</div>
    <p style="text-align:center;font-size:0.88rem;color:#666;">
        Already have an account?
        <a href="auth.php" style="color:#C96A2C;font-weight:bold;">Login here</a>
    </p>

    <?php else: ?>
    <!-- ════════════════════════════════════════════════════════
         LOGIN FORM
    ════════════════════════════════════════════════════════ -->

    <?php if ($register_success): ?>
        <div class="alert alert-success">🎉 <?php echo $register_success; ?></div>
    <?php endif; ?>
    <?php if ($login_error): ?>
        <div class="alert alert-danger"><?php echo $login_error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Enter your username" required
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password" required>
        </div>
        <button type="submit" name="login" class="btn-primary">Login</button>
    </form>

    <div class="divider">— or —</div>
    <p style="text-align:center;font-size:0.88rem;color:#666;">
        Don't have an account?
        <a href="auth.php?register=1" style="color:#C96A2C;font-weight:bold;">Register here</a>
    </p>

    <?php endif; ?>

    </div><!-- /.auth-body -->
</div><!-- /.auth-card -->
</div><!-- /.auth-wrap -->

<?php if ($show_otp_step): ?>
<script>
let timerInterval = null;
let otpSent       = false;

const digits      = document.querySelectorAll('.otp-digit');
const sendBtn     = document.getElementById('sendOtpBtn');
const verifyBtn   = document.getElementById('verifyOtpBtn');
const otpMsg      = document.getElementById('otpMsg');
const timerEl     = document.getElementById('otpTimer');
const pendingEmail = <?php echo json_encode($pending_email); ?>;

// ── Auto-advance digit inputs ──────────────────────────────────────────────
digits.forEach((input, i) => {
    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '');
        if (input.value && i < digits.length - 1) digits[i + 1].focus();
        updateDigitStyles();
        toggleVerifyBtn();
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !input.value && i > 0) digits[i - 1].focus();
    });
    // Paste support
    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, j) => {
            if (digits[j]) digits[j].value = ch;
        });
        updateDigitStyles();
        toggleVerifyBtn();
        if (pasted.length > 0) digits[Math.min(pasted.length, 5)].focus();
    });
});

function updateDigitStyles() {
    digits.forEach(d => d.classList.toggle('filled', d.value.length === 1));
}

function getOTP() {
    return Array.from(digits).map(d => d.value).join('');
}

function toggleVerifyBtn() {
    if (otpSent) verifyBtn.disabled = getOTP().length !== 6;
}

function showMsg(msg, type) {
    otpMsg.textContent  = msg;
    otpMsg.className    = type;
    otpMsg.style.display = 'block';
}

// ── Send OTP ───────────────────────────────────────────────────────────────
function sendOTP() {
    sendBtn.disabled    = true;
    sendBtn.textContent = '⏳ Sending…';
    showMsg('', '');

    fetch('auth.php?step=otp', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'action=send_otp&email=' + encodeURIComponent(pendingEmail)
    })
    .then(r => {
        if (!r.ok) throw new Error('Server error: ' + r.status);
        return r.text(); // get as text first
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            showMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                otpSent = true;
                document.getElementById('otpBox').style.display = 'flex';
                verifyBtn.style.display = 'block';
                startTimer(60);
                digits[0].focus();
            } else {
                sendBtn.disabled    = false;
                sendBtn.textContent = '📨 Send OTP to My Email';
            }
        } catch (e) {
            // Show raw server response so you can see the actual PHP error
            showMsg('Server response error. Check console.', 'error');
            console.error('Raw server response:', text);
            sendBtn.disabled    = false;
            sendBtn.textContent = '📨 Send OTP to My Email';
        }
    })
    .catch(err => {
        showMsg('Could not reach server. Please try again.', 'error');
        console.error('Fetch error:', err);
        sendBtn.disabled    = false;
        sendBtn.textContent = '📨 Send OTP to My Email';
    });
}

// ── Verify OTP ────────────────────────────────────────────────────────────
function verifyOTP() {
    const otp = getOTP();
    if (otp.length !== 6) { showMsg('Please enter all 6 digits.', 'error'); return; }

    verifyBtn.disabled    = true;
    verifyBtn.textContent = '⏳ Verifying…';

    fetch('auth.php?step=otp', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'action=verify_otp&otp=' + encodeURIComponent(otp)
    })
    .then(r => {
        if (!r.ok) throw new Error('Server error: ' + r.status);
        return r.text(); // get as text first
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            showMsg(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                clearInterval(timerInterval);
                document.getElementById('otpBox').style.display     = 'none';
                document.getElementById('otpActions').style.display = 'none';
                timerEl.textContent = '';
                document.getElementById('verifiedSection').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('completeRegForm').submit();
                }, 1200);
            } else {
                verifyBtn.disabled    = false;
                verifyBtn.textContent = '✅ Verify OTP';
            }
        } catch (e) {
            showMsg('Server response error. Check console.', 'error');
            console.error('Raw server response:', text);
            verifyBtn.disabled    = false;
            verifyBtn.textContent = '✅ Verify OTP';
        }
    })
    .catch(err => {
        showMsg('Could not reach server. Please try again.', 'error');
        console.error('Fetch error:', err);
        verifyBtn.disabled    = false;
        verifyBtn.textContent = '✅ Verify OTP';
    });
}

// ── Countdown timer ────────────────────────────────────────────────────────
function startTimer(seconds) {
    clearInterval(timerInterval);
    let remaining = seconds;
    sendBtn.textContent = 'Resend OTP';

    timerInterval = setInterval(() => {
        timerEl.textContent = `Resend available in ${remaining}s`;
        remaining--;
        if (remaining < 0) {
            clearInterval(timerInterval);
            timerEl.textContent = '';
            sendBtn.disabled    = false;
            sendBtn.textContent = '🔄 Resend OTP';
        }
    }, 1000);
}

// Hide digit box until OTP is sent
document.getElementById('otpBox').style.display = 'none';
</script>
<?php endif; ?>

<?php if ($show_register): ?>
<script>
// Live password match check
document.getElementById('confirmInput').addEventListener('input', function() {
    const pwd  = document.getElementById('pwdInput').value;
    const conf = this.value;
    if (conf && pwd !== conf) {
        this.style.borderColor = '#dc3545';
    } else {
        this.style.borderColor = '';
    }
});
</script>
<?php endif; ?>

</body>
</html>