<?php
require_once '../config.php';
redirect_if_not_admin();

$message = '';
$message_type = '';

/**
 * Get available slots for a service for the next $days days
 */
function get_next_slots($conn, $service_id, $days = 7) {
    $slots_data = [];

    // Get total slots for the service
    $stmt = $conn->prepare("SELECT slots FROM services WHERE id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $service = $result->fetch_assoc();
    $total_slots = $service['slots'] ?? 5; // default 5 if not set
    $stmt->close();

    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+$i day"));

        // Count booked appointments for this date
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(people_count),0) as booked_count 
            FROM appointments 
            WHERE service_id = ? 
            AND DATE(appointment_date) = ? 
            AND status IN ('pending','approved')
        ");
        $stmt->bind_param("is", $service_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $booked = $result->fetch_assoc()['booked_count'];
        $stmt->close();

        $available = max($total_slots - $booked, 0);

        $slots_data[] = [
            'date' => $date,
            'available' => $available
        ];
    }

    return $slots_data;
}

// Handle status update
if (isset($_GET['update_status'])) {
    $id = intval($_GET['update_status']);
    $status = sanitize_input($_GET['status']);

    if (in_array($status, ['pending', 'approved', 'declined', 'completed'])) {
        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            $message = "Appointment status updated to " . ucfirst($status) . "!";
            $message_type = "success";
        } else {
            $message = "Error updating appointment.";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// Get filter status
$filter_status = isset($_GET['filter']) ? sanitize_input($_GET['filter']) : '';

// Fetch appointments with optional filter
$appointments = [];
$status_options = ['pending', 'approved', 'declined', 'completed'];

if ($filter_status && in_array($filter_status, $status_options)) {
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.email, u.phone, s.name as service_name, s.price, s.session_time, s.slots
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN services s ON a.service_id = s.id
        WHERE a.status = ?
        ORDER BY a.appointment_date ASC
    ");
    $stmt->bind_param("s", $filter_status);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query("
        SELECT a.*, u.full_name, u.email, u.phone, s.name as service_name, s.price, s.session_time, s.slots
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN services s ON a.service_id = s.id
        ORDER BY a.appointment_date ASC
    ");
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
}

// Get appointment statistics
$stats = [];
foreach ($status_options as $status) {
    $result = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE status = '$status'");
    $stats[$status] = $result->fetch_assoc()['count'];
}

// Function to calculate available slots
function get_available_slots($conn, $service_id, $date, $total_slots) {
    $stmt = $conn->prepare("
        SELECT IFNULL(SUM(people_count),0) as booked_count 
        FROM appointments 
        WHERE service_id = ? AND DATE(appointment_date) = ? AND status IN ('pending','approved')
    ");
    $stmt->bind_param("is", $service_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $booked = $result->fetch_assoc()['booked_count'];
    $stmt->close();

    $available = $total_slots - $booked;
    return $available >= 0 ? $available : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments Management - Admin</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<header>
<nav>
<div class="logo">Spa Admin</div>
<ul class="nav-links">
<li><a href="index.php">Dashboard</a></li>
<li><a href="services.php">Services</a></li>
<li><a href="products.php">Products</a></li>
<li><a href="users.php">Users</a></li>
<li><a href="appointments.php" class="active">Appointments</a></li>
<li><a href="orders.php">Orders</a></li>
</ul>
<div class="auth-links">
<a href="index.php?logout=1">Logout</a>
</div>
</nav>
</header>

<div class="container">
<div class="admin-container">
<aside class="admin-sidebar">
<ul class="admin-menu">
<li><a href="index.php">Dashboard</a></li>
<li><a href="services.php">Services</a></li>
<li><a href="products.php">Products</a></li>
<li><a href="users.php">Users</a></li>
<li><a href="appointments.php" class="active">Appointments</a></li>
<li><a href="orders.php">Orders</a></li>
</ul>
</aside>

<main class="admin-content">
<div class="admin-header">
<h2>Appointments Management</h2>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid" style="margin-bottom: 2rem;">
<?php foreach ($stats as $key => $val): ?>
<div class="stat-card">
<div class="stat-number"><?php echo $val; ?></div>
<div class="stat-label"><?php echo ucfirst($key); ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Filter Buttons -->
<div style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
<a href="appointments.php" class="btn <?php echo !$filter_status ? 'btn-primary' : 'btn-secondary'; ?>">All</a>
<?php foreach ($status_options as $s): ?>
<a href="appointments.php?filter=<?php echo $s; ?>" class="btn <?php echo $filter_status === $s ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo ucfirst($s); ?></a>
<?php endforeach; ?>
</div>

<!-- Appointments Table -->
<div style="overflow-x: auto;">
<table>
<thead>
<tr>
<th>ID</th>
<th>Customer</th>
<th>Service</th>
<th>Date & Time</th>
<th>Price</th>
<th>Status</th>
<th>People</th>
<th>Available Slots</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (count($appointments) > 0): ?>
<?php foreach ($appointments as $appt): ?>
<tr>
<td><?php echo $appt['id']; ?></td>
<td>
<strong><?php echo $appt['full_name']; ?></strong><br>
<small><?php echo $appt['email']; ?></small><br>
<small><?php echo $appt['phone']; ?></small>
</td>
<td><?php echo $appt['service_name']; ?> (<?php echo $appt['session_time']; ?> min)</td>
<td><?php echo date('Y-m-d H:i', strtotime($appt['appointment_date'])); ?></td>
<td>$<?php echo number_format($appt['price'], 2); ?></td>
<td>
<span class="appointment-status status-<?php echo $appt['status']; ?>">
<?php echo ucfirst($appt['status']); ?>
</span>
</td>
<td><?php echo $appt['people_count']; ?></td>
<td>
<?php
$available = get_available_slots(
$conn,
$appt['service_id'],
date('Y-m-d', strtotime($appt['appointment_date'])),
$appt['slots']
);
echo $available;
?>
</td>
<td>
<?php if ($appt['status'] === 'pending'): ?>
<a href="appointments.php?update_status=<?php echo $appt['id']; ?>&status=approved" class="btn btn-success" style="padding:0.4rem 0.6rem;font-size:0.8rem;">Approve</a>
<a href="appointments.php?update_status=<?php echo $appt['id']; ?>&status=declined" class="btn btn-danger" style="padding:0.4rem 0.6rem;font-size:0.8rem;">Decline</a>
<?php elseif ($appt['status'] === 'approved'): ?>
<a href="appointments.php?update_status=<?php echo $appt['id']; ?>&status=completed" class="btn btn-info" style="padding:0.4rem 0.6rem;font-size:0.8rem;">Complete</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="9" style="text-align:center; padding:2rem;">No appointments found.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Next 7-Day Slot Tracker -->
<h3>Next 7-Day Slot Tracker</h3>
<?php
$services_result = $conn->query("SELECT id, name FROM services ORDER BY name ASC");
while ($service = $services_result->fetch_assoc()):
$slots = get_next_slots($conn, $service['id'], 7);
?>
<h4><?php echo $service['name']; ?></h4>
<table>
<tr>
<?php foreach ($slots as $s): ?>
<th><?php echo date('D, M d', strtotime($s['date'])); ?></th>
<?php endforeach; ?>
</tr>
<tr>
<?php foreach ($slots as $s): ?>
<td style="text-align:center; <?php echo $s['available'] == 0 ? 'color:red;' : 'color:green;'; ?>">
<?php echo $s['available']; ?> slots
</td>
<?php endforeach; ?>
</tr>
</table>
<?php endwhile; ?>

</main>
</div>
</div>
</body>
</html>