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

// Handle logout
if (isset($_GET['logout'])) {
    logout();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
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
                    } else if (strlen($new_password) < 6) {
                        $message = "New password must be at least 6 characters long.";
                        $message_type = "danger";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Spa Ecommerce</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <!-- Navigation -->
    

    <div class="container">
        <div class="profile-container">
            <div class="profile-header">
                <h1 class="profile-name"><?php echo $user['full_name']; ?></h1>
                <p class="profile-email"><?php echo $user['email']; ?></p>
                <p style="color: #999; font-size: 0.9rem;">Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom: 2rem;"><?php echo $message; ?></div>
            <?php endif; ?>

            <form method="POST">
                <h3 style="color: #3B2A1A; margin-bottom: 1.5rem;">Personal Information</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo $user['full_name']; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo $user['email']; ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo $user['phone']; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?php echo $user['username']; ?>" disabled style="background-color: #f0f0f0;">
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" required><?php echo $user['address']; ?></textarea>
                </div>

                <hr style="margin: 2rem 0; border: none; border-top: 2px solid #EAD8C0;">

                <h3 style="color: #3B2A1A; margin-bottom: 1.5rem;">Change Password (Optional)</h3>

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" placeholder="Leave empty if not changing password">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Leave empty if not changing password">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Leave empty if not changing password">
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    <a href="profile.php?logout=1" class="btn btn-danger">Logout</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
