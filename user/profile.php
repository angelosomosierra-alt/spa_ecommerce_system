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
        $message = "All fields are required.";
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

$page_title = 'My Profile';
require_once __DIR__ . '/header.php';
?>

<style>
.pf-wrap { max-width: 760px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem; }
.pf-title { font-family:'Cormorant Garamond',serif; font-size: 2rem; color: var(--brown); margin-bottom: 0.3rem; }
.pf-subtitle { color: var(--gray); font-size: 1rem; margin-bottom: 2rem; }

.pf-head-card {
    background: #fff; border-radius: 16px; border: 1px solid var(--cream2, #EAD8C0);
    box-shadow: 0 2px 16px rgba(59,42,26,0.06); padding: 1.75rem; margin-bottom: 1.5rem;
    display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;
}
.pf-avatar {
    width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--gold), #a94f1d); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 700; font-family:'Cormorant Garamond',serif;
}
.pf-head-name { font-size: 1.3rem; font-weight: 700; color: var(--brown); }
.pf-head-email { color: var(--gray); font-size: 0.95rem; margin-top: 0.15rem; }
.pf-head-since { color: var(--gray); font-size: 0.85rem; margin-top: 0.25rem; }

.pf-card { background: #fff; border-radius: 16px; border: 1px solid var(--cream2, #EAD8C0); box-shadow: 0 2px 16px rgba(59,42,26,0.06); overflow: hidden; margin-bottom: 1.5rem; }
.pf-card-head { padding: 1.1rem 1.5rem; border-bottom: 1px solid var(--cream2, #EAD8C0); background: var(--cream); }
.pf-card-head h3 { font-size: 1.05rem; font-weight: 700; color: var(--brown); margin: 0; }
.pf-card-body { padding: 1.5rem; }

.pf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
.pf-field { margin-bottom: 1.25rem; }
.pf-field:last-child { margin-bottom: 0; }
.pf-field label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--brown-md); margin-bottom: 0.4rem; }
.pf-field input, .pf-field textarea {
    width: 100%; padding: 0.75rem 0.9rem; border: 1.5px solid var(--cream2, #EAD8C0); border-radius: 10px;
    font-family: inherit; font-size: 1rem; color: var(--brown); background: var(--cream); box-sizing: border-box;
}
.pf-field input:focus, .pf-field textarea:focus { outline: none; border-color: var(--gold); background: #fff; }
.pf-field input:disabled { background: #eee; color: var(--gray); }
.pf-field textarea { resize: vertical; min-height: 80px; }
.pf-hint { font-size: 0.8rem; color: var(--gray); margin-top: 0.3rem; }

.pf-actions { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; }
.pf-actions .btn { font-size: 1rem; padding: 0.85rem 1.75rem; }

@media (max-width: 600px) {
    .pf-row { grid-template-columns: 1fr; gap: 0; }
    .pf-actions { flex-direction: column; }
    .pf-actions .btn, .pf-actions a { width: 100%; }
}
</style>

<div class="pf-wrap">
    <h1 class="pf-title">My Profile</h1>
    <p class="pf-subtitle">Manage your personal information and password</p>

    <div class="pf-head-card">
        <div class="pf-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
        <div>
            <div class="pf-head-name"><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="pf-head-email"><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="pf-head-since">Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrf_field(); ?>

        <div class="pf-card">
            <div class="pf-card-head"><h3>Personal Information</h3></div>
            <div class="pf-card-body">
                <div class="pf-row">
                    <div class="pf-field">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="pf-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>
                <div class="pf-row">
                    <div class="pf-field">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="pf-field">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    </div>
                </div>
                <div class="pf-field">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" required><?php echo htmlspecialchars($user['address'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>
        </div>

        <div class="pf-card">
            <div class="pf-card-head"><h3>Change Password</h3></div>
            <div class="pf-card-body">
                <div class="pf-field">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" placeholder="Leave empty if not changing password">
                </div>
                <div class="pf-row">
                    <div class="pf-field">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Leave empty if not changing password">
                    </div>
                    <div class="pf-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Leave empty if not changing password">
                    </div>
                </div>
                <div class="pf-hint">At least 8 characters, with an uppercase letter, a number, and a special character.</div>
            </div>
        </div>

        <div class="pf-actions">
            <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
            <a href="index.php" class="btn btn-secondary">Home</a>
            <a href="profile.php?logout=1" class="btn btn-danger">Logout</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
</body>
</html>
