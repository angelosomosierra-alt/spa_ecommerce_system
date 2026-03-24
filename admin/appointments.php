<?php
require_once '../config.php';
redirect_if_not_admin();

function get_available_slots($conn, $service_id, $date, $total_slots) {
    $stmt = $conn->prepare("SELECT IFNULL(SUM(people_count),0) as b FROM appointments WHERE service_id=? AND DATE(appointment_date)=? AND status IN ('pending','approved')");
    $stmt->bind_param("is",$service_id,$date); $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc()['b']; $stmt->close();
    return max($total_slots - $b, 0);
}

function get_next_slots($conn, $service_id, $days=7) {
    $stmt = $conn->prepare("SELECT slots FROM services WHERE id=?");
    $stmt->bind_param("i",$service_id); $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['slots'] ?? 5; $stmt->close();
    $data = [];
    for ($i=0; $i<$days; $i++) {
        $date = date('Y-m-d', strtotime("+$i day"));
        $stmt = $conn->prepare("SELECT IFNULL(SUM(people_count),0) as b FROM appointments WHERE service_id=? AND DATE(appointment_date)=? AND status IN ('pending','approved')");
        $stmt->bind_param("is",$service_id,$date); $stmt->execute();
        $b = $stmt->get_result()->fetch_assoc()['b']; $stmt->close();
        $data[] = ['date'=>$date,'available'=>max($total-$b,0)];
    }
    return $data;
}

$message = ''; $message_type = '';

if (isset($_GET['update_status'])) {
    $id     = intval($_GET['update_status']);
    $status = sanitize_input($_GET['status']);

    if (in_array($status, ['pending','approved','declined','completed'])) {

        // Update appointment status
        $stmt = $conn->prepare("UPDATE appointments SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $ok = $stmt->execute();
        $stmt->close();

        // ── When marked COMPLETED: update the linked order payment_status to 'paid'
        //    so the service revenue appears in analytics
        if ($ok && $status === 'completed') {
            $stmt = $conn->prepare("
                SELECT oi.order_id
                FROM appointments a
                LEFT JOIN order_items oi ON a.order_item_id = oi.id
                WHERE a.id = ?
            ");
            $stmt->bind_param("i", $id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

            if (!empty($row['order_id'])) {
                // Mark order as paid so it counts in analytics revenue
                $upd = $conn->prepare("UPDATE orders SET payment_status='paid' WHERE id=? AND payment_status != 'paid'");
                $upd->bind_param("i", $row['order_id']); $upd->execute(); $upd->close();
            }
        }

        // ── When marked DECLINED: restore stock if any products were in the order
        if ($ok && $status === 'declined') {
            $stmt = $conn->prepare("
                SELECT oi.order_id
                FROM appointments a
                LEFT JOIN order_items oi ON a.order_item_id = oi.id
                WHERE a.id = ?
            ");
            $stmt->bind_param("i", $id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

            if (!empty($row['order_id'])) {
                $stmt = $conn->prepare("UPDATE orders SET payment_status='rejected' WHERE id=? AND payment_status='unpaid'");
                $stmt->bind_param("i", $row['order_id']); $stmt->execute(); $stmt->close();
            }
        }

        $message      = $ok ? "Appointment " . ucfirst($status) . " successfully!" : "Error updating.";
        $message_type = $ok ? "success" : "danger";

        if ($ok && $status === 'completed') {
            $message = "🏁 Appointment completed! Revenue has been updated in Analytics.";
        }
    }
}

$filter_status = isset($_GET['filter']) ? sanitize_input($_GET['filter']) : '';
$status_opts   = ['pending','approved','declined','completed'];
$appointments  = [];

if ($filter_status && in_array($filter_status, $status_opts)) {
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.email, u.phone,
               s.name as service_name, s.price, s.session_time, s.slots
        FROM appointments a
        JOIN users    u ON a.user_id    = u.id
        JOIN services s ON a.service_id = s.id
        WHERE a.status = ?
        ORDER BY a.appointment_date ASC
    ");
    $stmt->bind_param("s", $filter_status); $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} else {
    $result = $conn->query("
        SELECT a.*, u.full_name, u.email, u.phone,
               s.name as service_name, s.price, s.session_time, s.slots
        FROM appointments a
        JOIN users    u ON a.user_id    = u.id
        JOIN services s ON a.service_id = s.id
        ORDER BY a.appointment_date ASC
    ");
    while ($row = $result->fetch_assoc()) $appointments[] = $row;
}

$stats = [];
foreach ($status_opts as $s) {
    $stats[$s] = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='$s'")->fetch_assoc()['c'];
}

$page_title = 'Appointments'; $page_icon = '📅'; $active_page = 'appointments';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-number"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-number"><?php echo $stats['approved']; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card red"><div class="stat-icon">❌</div><div class="stat-number"><?php echo $stats['declined']; ?></div><div class="stat-label">Declined</div></div>
    <div class="stat-card blue"><div class="stat-icon">🏁</div><div class="stat-number"><?php echo $stats['completed']; ?></div><div class="stat-label">Completed</div></div>
</div>

<!-- Filter -->
<div class="filter-tabs">
    <a href="appointments.php"                class="filter-tab <?php echo !$filter_status?'active':''; ?>">All</a>
    <?php foreach ($status_opts as $s): ?>
    <a href="appointments.php?filter=<?php echo $s; ?>" class="filter-tab <?php echo $filter_status===$s?'active':''; ?>"><?php echo ucfirst($s); ?></a>
    <?php endforeach; ?>
</div>

<!-- Table -->
<div class="table-wrap" style="margin-bottom:1.5rem;">
    <table>
        <thead>
            <tr><th>ID</th><th>Customer</th><th>Service</th><th>Date & Time</th><th>Price</th><th>Status</th><th>People</th><th>Slots Left</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php if (!empty($appointments)): foreach ($appointments as $a): ?>
            <tr>
                <td><strong style="color:var(--gold);">#<?php echo $a['id']; ?></strong></td>
                <td>
                    <div style="font-weight:600;color:var(--cream);"><?php echo htmlspecialchars($a['full_name']); ?></div>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($a['email']); ?></div>
                </td>
                <td>
                    <div style="color:var(--cream2);"><?php echo htmlspecialchars($a['service_name']); ?></div>
                    <div style="font-size:0.72rem;color:var(--gray);">⏱ <?php echo $a['session_time']; ?> min</div>
                </td>
                <td style="font-size:0.82rem;color:var(--cream2);"><?php echo date('M d, Y H:i', strtotime($a['appointment_date'])); ?></td>
                <td style="color:var(--rust);font-weight:600;">₱<?php echo number_format($a['price'],2); ?></td>
                <td><span class="badge badge-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                <td style="text-align:center;color:var(--cream2);"><?php echo $a['people_count']; ?></td>
                <td style="text-align:center;">
                    <?php $avail = get_available_slots($conn,$a['service_id'],date('Y-m-d',strtotime($a['appointment_date'])),$a['slots']); ?>
                    <span style="color:<?php echo $avail==0?'var(--red)':($avail<=2?'var(--amber)':'var(--green)'); ?>;font-weight:600;"><?php echo $avail; ?></span>
                </td>
                <td>
                    <?php if ($a['status']==='pending'): ?>
                        <a href="appointments.php?update_status=<?php echo $a['id']; ?>&status=approved"
                           class="btn btn-success btn-sm"
                           onclick="return confirm('Approve this appointment?')">Approve</a>
                        <a href="appointments.php?update_status=<?php echo $a['id']; ?>&status=declined"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Decline this appointment?')">Decline</a>
                    <?php elseif ($a['status']==='approved'): ?>
                        <a href="appointments.php?update_status=<?php echo $a['id']; ?>&status=completed"
                           class="btn btn-info btn-sm"
                           onclick="return confirm('Mark as completed? This will update the revenue in Analytics.')">🏁 Complete</a>
                        <a href="appointments.php?update_status=<?php echo $a['id']; ?>&status=declined"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Decline this appointment?')">Decline</a>
                    <?php else: ?>
                        <span style="color:var(--gray);font-size:0.78rem;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="9" style="text-align:center;color:var(--gray);padding:2rem;">No appointments found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 7-Day Slot Tracker -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">📅 7-Day Slot Tracker</span></div>
    <div class="panel-body" style="padding:0;">
        <?php
        $svc_result = $conn->query("SELECT id, name FROM services ORDER BY name ASC");
        while ($svc = $svc_result->fetch_assoc()):
            $slots = get_next_slots($conn, $svc['id'], 7);
        ?>
        <div class="slot-tracker-service">
            <div class="slot-tracker-name"><?php echo htmlspecialchars($svc['name']); ?></div>
            <div class="slot-days">
                <?php foreach ($slots as $s): $a = $s['available']; ?>
                <div class="slot-day">
                    <div class="slot-day-label"><?php echo date('D', strtotime($s['date'])); ?><br><?php echo date('M d', strtotime($s['date'])); ?></div>
                    <div class="slot-day-count <?php echo $a==0?'full':($a<=2?'low':'available'); ?>"><?php echo $a; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>