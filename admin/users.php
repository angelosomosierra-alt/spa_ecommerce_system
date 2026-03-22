<?php
/**
 * Admin Users Management
 * 
 * This file handles:
 * - Display all users
 * - View user details
 * - Delete user
 * - User statistics
 */

require_once '../config.php';

// Verify admin access
redirect_if_not_admin();

$message = '';
$message_type = '';

// Handle delete user
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Delete user and related data
    $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $delete_stmt->bind_param("i", $id);
    if ($delete_stmt->execute()) {
        $message = "User deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting user.";
        $message_type = "danger";
    }
    $delete_stmt->close();
}

// Fetch all users (excluding admins)
$users = [];
$result = $conn->query("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch user for viewing details
$view_user = null;
if (isset($_GET['view'])) {
    $id = intval($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'user'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $view_user = $result->fetch_assoc();
        
        // Get user's appointments
        $appt_stmt = $conn->prepare("
            SELECT a.*, s.name as service_name, s.price 
            FROM appointments a 
            JOIN services s ON a.service_id = s.id 
            WHERE a.user_id = ? 
            ORDER BY a.appointment_date DESC
        ");
        $appt_stmt->bind_param("i", $id);
        $appt_stmt->execute();
        $appt_result = $appt_stmt->get_result();
        $view_user['appointments'] = [];
        while ($appt = $appt_result->fetch_assoc()) {
            $view_user['appointments'][] = $appt;
        }
        $appt_stmt->close();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <!-- Navigation -->
    <header>
        <nav>
            <div class="logo">Spa Admin</div>
            <ul class="nav-links">
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="users.php" class="active">Users</a></li>
                <li><a href="appointments.php">Appointments</a></li>
                <li><a href="orders.php">Orders</a></li>
            </ul>
            <div class="auth-links">
                <a href="index.php?logout=1">Logout</a>
            </div>
        </nav>
    </header>

    <div class="container">
        <div class="admin-container">
            <!-- Sidebar Menu -->
            <aside class="admin-sidebar">
                <ul class="admin-menu">
                    <li><a href="index.php">Dashboard</a></li>
                    <li><a href="services.php">Services</a></li>
                    <li><a href="products.php">Products</a></li>
                    <li><a href="users.php" class="active">Users</a></li>
                    <li><a href="appointments.php">Appointments</a></li>
                    <li><a href="orders.php">Orders</a></li>
                </ul>
            </aside>

            <!-- Main Content -->
            <main class="admin-content">
                <div class="admin-header">
                    <h2><?php echo $view_user ? "User Details" : "Users Management"; ?></h2>
                    <?php if ($view_user): ?>
                        <a href="users.php" class="btn btn-secondary">Back to Users</a>
                    <?php endif; ?>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
                <?php endif; ?>

                <?php if ($view_user): ?>
                    <!-- User Details View -->
                    <div style="background-color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                            <div>
                                <h3 style="color: #3B2A1A; margin-bottom: 1rem;">Personal Information</h3>
                                <p><strong>Full Name:</strong> <?php echo $view_user['full_name']; ?></p>
                                <p><strong>Username:</strong> <?php echo $view_user['username']; ?></p>
                                <p><strong>Email:</strong> <?php echo $view_user['email']; ?></p>
                                <p><strong>Phone:</strong> <?php echo $view_user['phone']; ?></p>
                                <p><strong>Address:</strong> <?php echo $view_user['address']; ?></p>
                                <p><strong>Member Since:</strong> <?php echo date('Y-m-d', strtotime($view_user['created_at'])); ?></p>
                            </div>
                            <div>
                                <h3 style="color: #3B2A1A; margin-bottom: 1rem;">Statistics</h3>
                                <p><strong>Total Appointments:</strong> <?php echo count($view_user['appointments']); ?></p>
                                <p><strong>Pending Appointments:</strong> 
                                    <?php 
                                    $pending = array_filter($view_user['appointments'], function($a) { return $a['status'] === 'pending'; });
                                    echo count($pending);
                                    ?>
                                </p>
                                <p><strong>Completed Appointments:</strong> 
                                    <?php 
                                    $completed = array_filter($view_user['appointments'], function($a) { return $a['status'] === 'completed'; });
                                    echo count($completed);
                                    ?>
                                </p>
                            </div>
                        </div>

                        <hr style="margin: 2rem 0; border: none; border-top: 2px solid #EAD8C0;">

                        <h3 style="color: #3B2A1A; margin-bottom: 1rem;">Appointment History</h3>
                        <?php if (count($view_user['appointments']) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th>Date</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($view_user['appointments'] as $appt): ?>
                                        <tr>
                                            <td><?php echo $appt['service_name']; ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($appt['appointment_date'])); ?></td>
                                            <td>$<?php echo number_format($appt['price'], 2); ?></td>
                                            <td>
                                                <span class="appointment-status status-<?php echo $appt['status']; ?>">
                                                    <?php echo ucfirst($appt['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; color: #666;">No appointments found.</p>
                        <?php endif; ?>

                        <div style="margin-top: 2rem;">
                            <a href="users.php?delete=<?php echo $view_user['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this user?');">Delete User</a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Users Table -->
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($users) > 0): ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td><?php echo $user['username']; ?></td>
                                            <td><?php echo $user['full_name']; ?></td>
                                            <td><?php echo $user['email']; ?></td>
                                            <td><?php echo $user['phone']; ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <a href="users.php?view=<?php echo $user['id']; ?>" class="btn btn-info" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">View</a>
                                                <a href="users.php?delete=<?php echo $user['id']; ?>" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Are you sure?');">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;">No users found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
