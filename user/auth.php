<?php
require_once '../config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/index.php");
    } else {
        header("Location: ../user/index.php");
    }
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['cart'])) {
        save_cart_to_db($conn, $_SESSION['user_id'], $_SESSION['cart']);
    }
    session_unset();
    session_destroy();
    header('Location: auth.php');
    exit();
}

$login_error    = '';
$register_error = '';
$register_success = '';

// ─── LOGIN ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $login_error = "Please enter your username and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            // ✅ Set session variables
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role']; // 'admin' or 'user'

            // Restore cart from DB for regular users
            if ($user['role'] === 'user') {
                $_SESSION['cart'] = load_cart_from_db($conn, $user['id']);
            }

            // ✅ Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: ../admin/index.php");
            } else {
                header("Location: ../user/index.php");
            }
            exit();
        } else {
            $login_error = "Invalid username or password.";
        }
    }
}

// ─── REGISTER ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username  = sanitize_input($_POST['username']);
    $email     = sanitize_input($_POST['email']);
    $password  = $_POST['password'];
    $full_name = sanitize_input($_POST['full_name']);
    $phone     = sanitize_input($_POST['phone'] ?? '');
    $address   = sanitize_input($_POST['address'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $register_error = "All fields are required.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $register_error = "Username or email already exists.";
            $stmt->close();
        } else {
            $stmt->close();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                INSERT INTO users (username, password, email, full_name, phone, address, role)
                VALUES (?, ?, ?, ?, ?, ?, 'user')
            ");
            $stmt->bind_param("ssssss", $username, $hashed, $email, $full_name, $phone, $address);

            if ($stmt->execute()) {
                $register_success = "Registration successful! You can now log in.";
            } else {
                $register_error = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Register - Spa Ecommerce</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container" style="max-width:480px; margin:5rem auto;">

    <?php if (!isset($_GET['register'])): ?>
    <!-- LOGIN FORM -->
    <div class="card" style="padding:2rem; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.1);">
        <h2 style="color:#3B2A1A; text-align:center; margin-bottom:1.5rem;">Welcome Back</h2>

        <?php if ($login_error): ?>
            <div style="background:#f8d7da; color:#842029; padding:0.8rem 1rem;
                        border-radius:8px; margin-bottom:1rem; border-left:4px solid #dc3545;">
                <?php echo $login_error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                       placeholder="Enter your username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary" style="width:100%; padding:0.9rem;">
                Login
            </button>
        </form>

        <p style="text-align:center; margin-top:1rem; color:#666;">
            Don't have an account?
            <a href="auth.php?register=1" style="color:#C96A2C; font-weight:bold;">Register here</a>
        </p>
    </div>

    <?php else: ?>
    <!-- REGISTER FORM -->
    <div class="card" style="padding:2rem; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.1);">
        <h2 style="color:#3B2A1A; text-align:center; margin-bottom:1.5rem;">Create Account</h2>

        <?php if ($register_error): ?>
            <div style="background:#f8d7da; color:#842029; padding:0.8rem 1rem;
                        border-radius:8px; margin-bottom:1rem; border-left:4px solid #dc3545;">
                <?php echo $register_error; ?>
            </div>
        <?php endif; ?>
        <?php if ($register_success): ?>
            <div style="background:#d1e7dd; color:#0a3622; padding:0.8rem 1rem;
                        border-radius:8px; margin-bottom:1rem; border-left:4px solid #198754;">
                <?php echo $register_success; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Choose a username" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="Enter your full name" required>
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="tel" name="phone" placeholder="e.g. 09123456789">
            </div>
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" placeholder="Enter your address"></textarea>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Create a password" required>
            </div>
            <button type="submit" name="register" class="btn btn-primary" style="width:100%; padding:0.9rem;">
                Register
            </button>
        </form>

        <p style="text-align:center; margin-top:1rem; color:#666;">
            Already have an account?
            <a href="auth.php" style="color:#C96A2C; font-weight:bold;">Login here</a>
        </p>
    </div>
    <?php endif; ?>

</div>
</body>
</html>