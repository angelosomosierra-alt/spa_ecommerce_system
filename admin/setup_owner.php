<?php
require_once '../config.php';

// SAFETY: If ANY admin account already exists, block access entirely.
// This prevents abuse after the initial setup.
$check = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='admin'");
$count = $check ? (int)$check->fetch_assoc()['c'] : 0;
if ($count > 0) {
    die('<!DOCTYPE html><html><head><title>Setup Complete</title>
    <style>body{font-family:sans-serif;background:#FAF3E8;display:flex;
    align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{background:#fff;padding:2rem;border-radius:14px;max-width:420px;
    text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.1);}
    h2{color:#3B2A1A;}p{color:#666;}</style></head><body>
    <div class="box"><h2>⚠️ Setup Already Complete</h2>
    <p>An admin account already exists.<br>
    <strong>Delete this file for security:</strong><br>
    <code>admin/setup_owner.php</code></p></div></body></html>');
}

// Handle form submission
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username']         ?? '');
    $email     = trim($_POST['email']            ?? '');
    $full_name = trim($_POST['full_name']        ?? '');
    $phone     = trim($_POST['phone']            ?? '');
    $password  = $_POST['password']              ?? '';
    $confirm   = $_POST['confirm_password']      ?? '';

    // Validation — same rules as staff.php create account
    if (empty($username))  $errors[] = 'Username is required.';
    if (empty($email))     $errors[] = 'Email is required.';
    if (empty($full_name)) $errors[] = 'Full name is required.';
    if ($password !== $confirm)               $errors[] = 'Passwords do not match.';
    if (strlen($password) < 8)               $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password))   $errors[] = 'Password needs an uppercase letter.';
    if (!preg_match('/[0-9]/', $password))   $errors[] = 'Password needs a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password needs a special character.';

    // Check duplicate username/email
    if (empty($errors)) {
        $dup = $conn->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $dup->bind_param("ss", $username, $email);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = 'Username or email already exists.';
        }
        $dup->close();
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users
            (username, email, password, full_name, phone, address, role, admin_role)
            VALUES (?, ?, ?, ?, ?, 'Admin Office', 'admin', 'owner')");
        $stmt->bind_param("sssss", $username, $email, $hashed, $full_name, $phone);
        $stmt->execute();
        $stmt->close();
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Initial Owner Setup — Recovery Iloilo Spa</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #FAF3E8;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}

.brand {
    text-align: center;
    margin-bottom: 1.5rem;
}
.brand-name {
    font-size: 1.25rem;
    font-weight: 800;
    color: #3B2A1A;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.brand-sub {
    font-size: 0.78rem;
    color: #9a7c68;
    margin-top: 2px;
    letter-spacing: 0.04em;
}

.card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.10);
    padding: 2rem 2.25rem;
    width: 100%;
    max-width: 480px;
}

.card-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #3B2A1A;
    margin-bottom: 0.25rem;
}
.card-desc {
    font-size: 0.82rem;
    color: #9a7c68;
    margin-bottom: 1.5rem;
    line-height: 1.5;
}

/* Errors */
.alert-error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    color: #b91c1c;
    font-size: 0.83rem;
    line-height: 1.6;
}
.alert-error ul { padding-left: 1.2rem; margin: 0; }

/* Success */
.success-box {
    text-align: center;
    padding: 0.5rem 0;
}
.success-icon { font-size: 3rem; margin-bottom: 0.75rem; }
.success-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #3B2A1A;
    margin-bottom: 0.5rem;
}
.success-msg {
    font-size: 0.87rem;
    color: #666;
    line-height: 1.6;
    margin-bottom: 1.25rem;
}
.delete-warning {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    margin-bottom: 1.25rem;
    font-size: 0.83rem;
    color: #92400e;
    line-height: 1.6;
    text-align: left;
}
.delete-warning strong { display: block; margin-bottom: 0.3rem; font-size: 0.88rem; }
.delete-warning code {
    background: #fef3c7;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-size: 0.82rem;
    color: #78350f;
}

/* Form fields */
.field { margin-bottom: 1rem; }
.field label {
    display: block;
    font-size: 0.83rem;
    font-weight: 600;
    color: #3B2A1A;
    margin-bottom: 0.35rem;
}
.field input {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1.5px solid #EAD8C0;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #3B2A1A;
    background: #FDFAF6;
    transition: border-color 0.15s;
    outline: none;
}
.field input:focus { border-color: #C96A2C; background: #fff; }

/* Password wrapper */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 2.8rem; }
.pw-eye {
    position: absolute;
    right: 0.7rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    color: #A07850;
    padding: 0;
    line-height: 1;
}

/* Password checklist */
.pw-checklist {
    margin-top: 0.55rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.25rem 0.5rem;
}
.pw-rule {
    font-size: 0.75rem;
    color: #aaa;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    transition: color 0.2s;
}
.pw-rule.ok { color: #16a34a; }
.pw-rule::before { content: '○'; font-size: 0.65rem; flex-shrink: 0; }
.pw-rule.ok::before { content: '✓'; }

/* Divider */
.divider {
    border: none;
    border-top: 1px solid #EAD8C0;
    margin: 1.25rem 0;
}

/* Submit */
.btn-submit {
    width: 100%;
    padding: 0.75rem;
    background: #C96A2C;
    color: #fff;
    font-size: 0.97rem;
    font-weight: 700;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    margin-top: 0.25rem;
}
.btn-submit:hover { background: #a8551f; }
.btn-submit:active { transform: scale(0.98); }

.btn-login {
    display: inline-block;
    padding: 0.7rem 1.5rem;
    background: #C96A2C;
    color: #fff;
    font-size: 0.92rem;
    font-weight: 700;
    border-radius: 8px;
    text-decoration: none;
    transition: background 0.15s;
}
.btn-login:hover { background: #a8551f; }

.footer-note {
    margin-top: 1.5rem;
    font-size: 0.75rem;
    color: #bbb;
    text-align: center;
}
</style>
</head>
<body>

<div class="brand">
    <div class="brand-name">Recovery Iloilo</div>
    <div class="brand-sub">Admin Panel — Initial Setup</div>
</div>

<div class="card">

<?php if ($success): ?>

    <div class="success-box">
        <div class="success-icon">✅</div>
        <div class="success-title">Owner Account Created!</div>
        <p class="success-msg">
            Your owner account is ready. You can now log in to the admin panel.
        </p>

        <div class="delete-warning">
            <strong>⚠️ Security: Delete this file immediately.</strong>
            This setup page is no longer needed and leaving it on the server
            is a security risk. Remove the file at:<br><br>
            <code>admin/setup_owner.php</code>
        </div>

        <a href="admin_login.php" class="btn-login">→ Go to Admin Login</a>
    </div>

<?php else: ?>

    <div class="card-title">Create Owner Account</div>
    <p class="card-desc">
        This page is only accessible when no admin accounts exist.
        Fill in the details below to create the initial owner account.
    </p>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <ul>
            <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>

        <div class="field">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name"
                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                   placeholder="e.g. Maria Santos" autocomplete="name" required>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   placeholder="e.g. owner_maria" autocomplete="username" required>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                   placeholder="owner@recoveryiloilo.com" autocomplete="email" required>
        </div>

        <div class="field">
            <label for="phone">Phone <span style="font-weight:400;color:#aaa;">(optional)</span></label>
            <input type="tel" id="phone" name="phone"
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                   placeholder="e.g. 09xxxxxxxxx" autocomplete="tel">
        </div>

        <hr class="divider">

        <div class="field">
            <label for="password">Password</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password"
                       placeholder="Create a strong password"
                       autocomplete="new-password"
                       oninput="checkPwStrength(this.value)">
                <button type="button" class="pw-eye" onclick="togglePw('password', this)" aria-label="Show password">👁</button>
            </div>
            <div class="pw-checklist">
                <div class="pw-rule" id="rule-len">8+ characters</div>
                <div class="pw-rule" id="rule-upper">Uppercase letter</div>
                <div class="pw-rule" id="rule-num">Number</div>
                <div class="pw-rule" id="rule-special">Special character</div>
            </div>
        </div>

        <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <div class="pw-wrap">
                <input type="password" id="confirm_password" name="confirm_password"
                       placeholder="Re-enter your password"
                       autocomplete="new-password">
                <button type="button" class="pw-eye" onclick="togglePw('confirm_password', this)" aria-label="Show password">👁</button>
            </div>
        </div>

        <button type="submit" class="btn-submit">Create Owner Account →</button>

    </form>

<?php endif; ?>

</div>

<p class="footer-note">Recovery Iloilo Spa · Admin Setup</p>

<script>
function togglePw(id, btn) {
    var inp = document.getElementById(id);
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁';
}

function checkPwStrength(val) {
    setRule('rule-len',     val.length >= 8);
    setRule('rule-upper',   /[A-Z]/.test(val));
    setRule('rule-num',     /[0-9]/.test(val));
    setRule('rule-special', /[^A-Za-z0-9]/.test(val));
}

function setRule(id, ok) {
    var el = document.getElementById(id);
    if (el) { ok ? el.classList.add('ok') : el.classList.remove('ok'); }
}

// Prevent double-submit
document.querySelector('form') && document.querySelector('form').addEventListener('submit', function(e) {
    if (e.defaultPrevented) return;
    var btn = this.querySelector('button[type="submit"]');
    if (!btn || btn.disabled) return;
    setTimeout(function() { btn.disabled = true; btn.textContent = '⏳ Creating account…'; }, 10);
});
</script>

</body>
</html>
