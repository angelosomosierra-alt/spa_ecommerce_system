<?php
ob_start();
require_once '../config.php';

// ─── LOGOUT ───────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    if (isset($_SESSION['session_token'], $_SESSION['user_id'])) {
        $clr = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=? AND session_token=?");
        $clr->bind_param("is", $_SESSION['user_id'], $_SESSION['session_token']);
        $clr->execute(); $clr->close();
    }
    session_unset();
    session_destroy();
    header('Location: admin_login.php');
    exit();
}

// Already logged in — send to the right place
if (isset($_SESSION['user_id'])) {
    header($_SESSION['role'] === 'admin' ? 'Location: index.php' : 'Location: ../user/index.php');
    exit();
}

$login_error = '';

// ══════════════════════════════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    verify_csrf_token();
    $email    = sanitize_input($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $login_error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();

        // Always run password_verify even on miss to avoid timing leaks
        $password_ok = $user ? password_verify($password, $user['password']) : false;

        if (!$user || !$password_ok || $user['role'] !== 'admin') {
            // Generic message — do not reveal whether the account exists or is the wrong type
            $login_error = 'Invalid email or password.';
        } elseif (!empty($user['deleted_at'])) {
            $login_error = 'This account has been deactivated. Contact the system owner.';

        // ── Receptionist (cashier) restrictions ───────────────────────────────
        } elseif (($user['admin_role'] ?? '') === 'cashier') {

            // Ensure tables/columns exist (safe if migration not run yet)
            $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                setting_key   VARCHAR(100) NOT NULL UNIQUE,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT NULL, updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $col_chk = $conn->query("SHOW COLUMNS FROM users LIKE 'session_token'");
            if ($col_chk && $col_chk->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN session_token   VARCHAR(64) NULL DEFAULT NULL");
                $conn->query("ALTER TABLE users ADD COLUMN session_started DATETIME    NULL DEFAULT NULL");
            }

            // [R1] Time window restriction
            $tz_row  = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_timezone'    LIMIT 1")->fetch_assoc();
            $st_row  = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_login_start' LIMIT 1")->fetch_assoc();
            $en_row  = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='receptionist_login_end'   LIMIT 1")->fetch_assoc();

            $tz      = $tz_row['setting_value'] ?? 'Asia/Manila';
            $t_start = $st_row['setting_value'] ?? '07:00';
            $t_end   = $en_row['setting_value'] ?? '23:59';

            date_default_timezone_set($tz);
            $now_time  = date('H:i');
            $now_label = date('h:i A');

            if ($now_time < $t_start || $now_time > $t_end) {
                $start_label = date('h:i A', strtotime($t_start));
                $end_label   = date('h:i A', strtotime($t_end));
                $login_error = "Receptionist login is only allowed between {$start_label} and {$end_label}. Current time: {$now_label}.";
            } else {
                // [R2] Single active session check
                $existing_token  = $user['session_token']  ?? null;
                $session_started = $user['session_started'] ?? null;

                if (!empty($existing_token) && !empty($session_started)) {
                    $session_age_hours = (time() - strtotime($session_started)) / 3600;
                    if ($session_age_hours < 8) {
                        $login_error = '⚠️ Receptionist account is already logged in on another device. Please log out from the other session first, or contact the owner to clear it.';
                    } else {
                        $existing_token = null; // session expired — auto-clear
                    }
                }

                if (empty($login_error)) {
                    $new_token = bin2hex(random_bytes(32));
                    $now_dt    = date('Y-m-d H:i:s');
                    $upd = $conn->prepare("UPDATE users SET session_token=?, session_started=? WHERE id=?");
                    $upd->bind_param("ssi", $new_token, $now_dt, $user['id']); $upd->execute(); $upd->close();

                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['email']         = $user['email'];
                    $_SESSION['role']          = $user['role'];
                    $_SESSION['username']      = $user['username'];
                    $_SESSION['full_name']     = $user['full_name'];
                    $_SESSION['admin_role']    = 'cashier';
                    $_SESSION['session_token'] = $new_token;
                    header('Location: index.php');
                    exit();
                }
            }

        // ── All other admin roles (owner, it, marketing) ──────────────────────
        } else {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['admin_role'] = $user['admin_role'] ?? 'owner';
            header('Location: index.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Staff &amp; Admin Login — Recovery Iloilo</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .auth-wrap {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 2rem 1rem;
            background: linear-gradient(rgba(20,15,8,0.75), rgba(20,15,8,0.75)),
                        url('../img/login.jpg') no-repeat center center fixed;
            background-size: cover;
        }
        .auth-card { width:100%; max-width:420px; background:#fff; border-radius:16px; box-shadow:0 8px 40px rgba(0,0,0,0.3); overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#1a0e06,#3B2A1A); padding:2rem; text-align:center; color:#FAF3E8; border-bottom:3px solid #C96A2C; }
        .auth-header h1 { font-size:1.3rem; margin:0 0 4px; letter-spacing:2px; }
        .auth-header p  { font-size:0.78rem; opacity:0.55; margin:0; }
        .auth-body { padding:2rem; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; font-size:0.82rem; font-weight:600; color:#3B2A1A; margin-bottom:0.35rem; }
        .form-group input { width:100%; padding:0.7rem 0.9rem; border:2px solid #EAD8C0; border-radius:8px; font-family:inherit; font-size:0.9rem; color:#3B2A1A; background:#FDFAF6; transition:border-color 0.18s; box-sizing:border-box; }
        .form-group input:focus { outline:none; border-color:#C96A2C; }
        .btn-primary { width:100%; padding:0.85rem; background:linear-gradient(135deg,#C96A2C,#A94F1D); color:#fff; border:none; border-radius:8px; font-size:0.95rem; font-weight:700; cursor:pointer; transition:opacity 0.18s; }
        .btn-primary:hover { opacity:0.9; }
        .alert { padding:0.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:0.875rem; border-left:4px solid; }
        .alert-danger { background:#f8d7da; color:#842029; border-color:#dc3545; }
        .staff-badge { display:inline-block; background:#3B2A1A; color:#C8A46B; font-size:0.68rem; font-weight:700; padding:0.2rem 0.65rem; border-radius:20px; letter-spacing:0.1em; text-transform:uppercase; margin-bottom:0.75rem; }
    </style>
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">
    <div class="auth-header">
        <div style="font-size:1.8rem;margin-bottom:0.5rem;">🔐</div>
        <h1>RECOVERY ILOILO</h1>
        <p>Staff &amp; Admin Portal</p>
    </div>
    <div class="auth-body">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <span class="staff-badge">Authorized Personnel Only</span>
        </div>
        <?php if ($login_error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($login_error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="staff@recoveryspa.com" required
                       autocomplete="username"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div style="position:relative;">
                    <input type="password" name="password" id="adminLoginPwd"
                           placeholder="Enter your password" required
                           autocomplete="current-password"
                           style="padding-right:2.8rem;">
                    <button type="button" onclick="toggleAdminPwd()"
                            id="adminPwdToggle"
                            style="position:absolute;right:0.75rem;top:50%;
                                   transform:translateY(-50%);background:none;
                                   border:none;cursor:pointer;font-size:1rem;
                                   color:#A07850;padding:0;line-height:1;"
                            aria-label="Show password">👁</button>
                </div>
            </div>
            <button type="submit" name="login" class="btn-primary">Login to Admin Panel</button>
        </form>
        <div style="margin-top:1.25rem;text-align:center;font-size:0.75rem;color:#bbb;">
            For account issues, contact the system owner.
        </div>
    </div>
</div>
</div>
<script>
function toggleAdminPwd() {
    const input  = document.getElementById('adminLoginPwd');
    const btn    = document.getElementById('adminPwdToggle');
    const isHide = input.type === 'password';
    input.type   = isHide ? 'text' : 'password';
    btn.textContent = isHide ? '🙈' : '👁';
    btn.setAttribute('aria-label', isHide ? 'Hide password' : 'Show password');
}
</script>
</body>
</html>
