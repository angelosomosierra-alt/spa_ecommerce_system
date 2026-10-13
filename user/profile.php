<?php
/**
 * User Profile Management
 * 
 * This file handles:
 * - Display user profile
 * - Edit user information
 * - Update profile
 * - Logout
 */
require_once '../config.php';

// Verify user access
redirect_if_not_user();

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get user info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle logout — save cart before destroying session
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['cart']) && isset($_SESSION['user_id'])) {
        sync_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    logout();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    verify_csrf_token();
    $full_name = sanitize_input($_POST['full_name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone']);
    $address = sanitize_input($_POST['address']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate input
    if (empty($full_name) || empty($email) || empty($phone) || empty($address)) {
        $message = "Please fill in your name, email, mobile number and address.";
        $message_type = "danger";
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $message_type = "danger";
    } else {
        // Check if email is already taken by another user
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = "Email is already taken.";
            $message_type = "danger";
        } else {
            // Update profile
            $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $update_stmt->bind_param("ssssi", $full_name, $email, $phone, $address, $user_id);

            if ($update_stmt->execute()) {
                // Handle password change if provided
                if (!empty($new_password)) {
                    if (empty($current_password)) {
                        $message = "Current password is required to change password.";
                        $message_type = "danger";
                    } else if (!password_verify($current_password, $user['password'])) {
                        $message = "Current password is incorrect.";
                        $message_type = "danger";
                    } else if ($new_password !== $confirm_password) {
                        $message = "New passwords do not match.";
                        $message_type = "danger";
                    } elseif (strlen($new_password) < 8) {
                        $message = 'Password must be at least 8 characters.';
                        $message_type = 'danger';
                    } elseif (!preg_match('/[A-Z]/', $new_password)) {
                        $message = 'Password must contain at least one uppercase letter.';
                        $message_type = 'danger';
                    } elseif (!preg_match('/[0-9]/', $new_password)) {
                        $message = 'Password must contain at least one number.';
                        $message_type = 'danger';
                    } elseif (!preg_match('/[\W_]/', $new_password)) {
                        $message = 'Password must contain at least one special character.';
                        $message_type = 'danger';
                    } else {
                        // Update password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $pwd_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $pwd_stmt->bind_param("si", $hashed_password, $user_id);
                        $pwd_stmt->execute();
                        $pwd_stmt->close();
                        $message = "Profile and password updated successfully!";
                        $message_type = "success";
                    }
                } else {
                    $message = "Profile updated successfully!";
                    $message_type = "success";
                }

                // Refresh user data
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $message = "Error updating profile.";
                $message_type = "danger";
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}

// Small facts for the header line: completed visits.
$visits = 0;
try {
    $vc = $conn->prepare("SELECT COUNT(*) AS n FROM appointments WHERE user_id = ? AND status = 'completed'");
    $vc->bind_param("i", $user_id); $vc->execute();
    $visits = (int)$vc->get_result()->fetch_assoc()['n']; $vc->close();
} catch (Throwable $e) {}

// Keep what the customer typed if saving failed, so nothing is lost.
$val = function ($k) use ($user, $message_type) {
    if ($message_type === 'danger' && isset($_POST[$k])) return htmlspecialchars((string)$_POST[$k], ENT_QUOTES, 'UTF-8');
    return htmlspecialchars((string)($user[$k] ?? ''), ENT_QUOTES, 'UTF-8');
};
$pw_open = $message_type === 'danger' && (($_POST['new_password'] ?? '') !== '' || ($_POST['current_password'] ?? '') !== '');

$page_title = 'My Profile';
require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=DM+Sans:wght@400;500;700;800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/account.css?v=<?php echo filemtime(__DIR__ . '/../assets/account.css'); ?>">

<div class="ac-page">
<div class="ac-wrap">
    <div class="ac-hello" style="padding-bottom:0">
        <div><span class="ac-eyebrow">My account</span><h1>My <em>profile</em></h1></div>
        <a class="ac-btn ac-btn-line" href="appointments.php">My bookings</a>
    </div>

    <?php if ($message): ?>
    <div class="ac-flash <?php echo $message_type === 'success' ? 'ok' : 'bad'; ?>" role="<?php echo $message_type === 'success' ? 'status' : 'alert'; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST" id="pform" novalidate>
        <?php echo csrf_field(); ?>
        <div class="ac-pcard">
            <div class="ac-who">
                <span class="ac-av" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($user['full_name'] ?: '?', 0, 1))); ?></span>
                <div>
                    <h1><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Member since <?php echo date('F Y', strtotime($user['created_at'])); ?><?php echo $visits ? ' · ' . $visits . ' visit' . ($visits > 1 ? 's' : '') : ''; ?></p>
                </div>
            </div>
            <label class="ac-fld"><span>Full name</span><input id="full_name" name="full_name" autocomplete="name" value="<?php echo $val('full_name'); ?>" required></label>
            <div class="ac-two">
                <label class="ac-fld"><span>Mobile number</span><em>We text you booking updates here.</em><input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="<?php echo $val('phone'); ?>" required></label>
                <label class="ac-fld"><span>Email</span><em>For receipts and password reset.</em><input id="email" name="email" type="email" autocomplete="email" value="<?php echo $val('email'); ?>" required></label>
            </div>
            <label class="ac-fld"><span>Address</span><em>Used for home service visits.</em><textarea id="address" name="address" autocomplete="street-address" required><?php echo $val('address'); ?></textarea></label>
            <label class="ac-fld"><span>Username</span><input id="username" value="<?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?>" readonly></label>
        </div>

        <div class="ac-pcard">
            <button type="button" class="ac-rowbtn" id="pwToggle" aria-expanded="<?php echo $pw_open ? 'true' : 'false'; ?>" aria-controls="pwBox"><span>Change password<small>Leave closed if you don't want to change it.</small></span><span class="ac-chev" aria-hidden="true">›</span></button>
            <div id="pwBox"<?php echo $pw_open ? '' : ' hidden'; ?>>
                <label class="ac-fld"><span>Current password</span><input id="current_password" name="current_password" type="password" autocomplete="current-password"></label>
                <div class="ac-two">
                    <label class="ac-fld"><span>New password</span><em>8+ characters with a capital letter, a number and a symbol.</em><input id="new_password" name="new_password" type="password" autocomplete="new-password"></label>
                    <label class="ac-fld"><span>Repeat new password</span><em>&nbsp;</em><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password"></label>
                </div>
            </div>
        </div>

        <div class="ac-savebar">
            <a class="ac-btn ac-btn-line" href="profile.php">Undo changes</a>
            <button type="submit" name="update_profile" value="1" class="ac-btn ac-btn-dark">Save changes</button>
        </div>
        <p style="text-align:center;margin:8px 0 0"><a href="profile.php?logout=1" style="color:var(--ac-bad);font-weight:700">Log out</a></p>
    </form>
</div>
</div>

<script>
(function () {
    var t = document.getElementById('pwToggle'), box = document.getElementById('pwBox');
    t.addEventListener('click', function () {
        var o = t.getAttribute('aria-expanded') !== 'true';
        t.setAttribute('aria-expanded', o); box.hidden = !o;
        if (!o) box.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        else document.getElementById('current_password').focus();
    });
    // Friendly checks before sending; the server checks again.
    function clear() { document.querySelectorAll('.ac-fld.err').forEach(function (f) { f.classList.remove('err'); var m = f.querySelector('.ac-msg'); if (m) m.remove(); }); }
    function err(id, msg) { var f = document.getElementById(id).closest('.ac-fld'); f.classList.add('err'); var m = document.createElement('span'); m.className = 'ac-msg'; m.textContent = msg; f.appendChild(m); return id; }
    document.getElementById('pform').addEventListener('submit', function (e) {
        clear();
        var bad = [], v = function (id) { return document.getElementById(id).value.trim(); };
        if (!v('full_name')) bad.push(err('full_name', 'Please type your name.'));
        if (v('phone').replace(/\D/g, '').length < 10) bad.push(err('phone', 'Please type a mobile number, e.g. 0917 123 4567.'));
        if (!/^\S+@\S+\.\S+$/.test(v('email'))) bad.push(err('email', 'Please type a valid email.'));
        if (!v('address')) bad.push(err('address', 'Please type your address.'));
        if (!box.hidden && (v('new_password') || v('current_password'))) {
            var np = document.getElementById('new_password').value;
            if (!v('current_password')) bad.push(err('current_password', 'Type your current password.'));
            if (np.length < 8 || !/[A-Z]/.test(np) || !/[0-9]/.test(np) || !/[\W_]/.test(np)) bad.push(err('new_password', 'Use 8+ characters with a capital letter, a number and a symbol.'));
            else if (np !== document.getElementById('confirm_password').value) bad.push(err('confirm_password', 'The two passwords do not match.'));
        }
        if (bad.length) { e.preventDefault(); document.getElementById(bad[0]).focus(); }
    });
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
</body>
</html>
