<?php
require_once '../config.php';
redirect_if_not_admin();

$message = ''; $message_type = '';

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i",$id);
    $message = $stmt->execute() ? "User deleted." : "Error deleting user.";
    $message_type = $stmt->execute() ? "success" : "danger";
    $stmt->close();
}

$users = [];
$result = $conn->query("SELECT * FROM users WHERE role='user' AND username!='walkin_customer' ORDER BY created_at DESC");
while ($row = $result->fetch_assoc()) $users[] = $row;

$view_user = null;
if (isset($_GET['view'])) {
    $id = intval($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='user'");
    $stmt->bind_param("i",$id); $stmt->execute();
    $view_user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($view_user) {
        $stmt = $conn->prepare("SELECT a.*, s.name as service_name, s.price FROM appointments a JOIN services s ON a.service_id=s.id WHERE a.user_id=? ORDER BY a.appointment_date DESC");
        $stmt->bind_param("i",$id); $stmt->execute();
        $view_user['appointments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("SELECT o.*, COUNT(oi.id) as item_count FROM orders o LEFT JOIN order_items oi ON o.id=oi.order_id WHERE o.user_id=? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 5");
        $stmt->bind_param("i",$id); $stmt->execute();
        $view_user['orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

$page_title = $view_user ? 'User: '.htmlspecialchars($view_user['full_name']) : 'Users';
$page_icon = '👥'; $active_page = 'users';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($view_user): ?>
<div style="margin-bottom:1rem;"><a href="users.php" class="btn btn-secondary">← Back to Users</a></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
    <div class="panel">
        <div class="panel-header"><span class="panel-title">👤 Profile</span></div>
        <div class="panel-body">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
                <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--rust));display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;color:#fff;flex-shrink:0;">
                    <?php echo strtoupper(substr($view_user['full_name'],0,1)); ?>
                </div>
                <div>
                    <div style="font-size:1rem;font-weight:600;color:var(--cream);"><?php echo htmlspecialchars($view_user['full_name']); ?></div>
                    <div style="font-size:0.78rem;color:var(--gray);">@<?php echo htmlspecialchars($view_user['username']); ?></div>
                </div>
            </div>
            <?php foreach (['email'=>'Email','phone'=>'Phone','address'=>'Address'] as $k=>$l): ?>
            <div style="display:flex;gap:0.75rem;margin-bottom:0.6rem;">
                <span style="color:var(--gray);font-size:0.75rem;width:56px;flex-shrink:0;padding-top:2px;"><?php echo $l; ?></span>
                <span style="color:var(--cream2);font-size:0.875rem;"><?php echo htmlspecialchars($view_user[$k]??'—'); ?></span>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;gap:0.75rem;margin-bottom:0.6rem;">
                <span style="color:var(--gray);font-size:0.75rem;width:56px;flex-shrink:0;padding-top:2px;">Joined</span>
                <span style="color:var(--cream2);font-size:0.875rem;"><?php echo date('M d, Y', strtotime($view_user['created_at'])); ?></span>
            </div>
            <div style="margin-top:1.25rem;">
                <a href="users.php?delete=<?php echo $view_user['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this user?')">🗑️ Delete User</a>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">📊 Stats</span></div>
        <div class="panel-body">
            <div class="stats-grid" style="grid-template-columns:1fr 1fr;">
                <div class="stat-card"><div class="stat-number"><?php echo count($view_user['appointments']); ?></div><div class="stat-label">Appointments</div></div>
                <div class="stat-card green"><div class="stat-number"><?php echo count(array_filter($view_user['appointments'],fn($a)=>$a['status']==='completed')); ?></div><div class="stat-label">Completed</div></div>
                <div class="stat-card amber"><div class="stat-number"><?php echo count(array_filter($view_user['appointments'],fn($a)=>$a['status']==='pending')); ?></div><div class="stat-label">Pending</div></div>
                <div class="stat-card blue"><div class="stat-number"><?php echo count($view_user['orders']); ?></div><div class="stat-label">Orders</div></div>
            </div>
        </div>
    </div>
</div>

<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header"><span class="panel-title">📅 Appointment History</span></div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>Service</th><th>Date</th><th>Price</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (!empty($view_user['appointments'])): foreach ($view_user['appointments'] as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                    <td style="font-size:0.82rem;color:var(--gray);"><?php echo date('M d, Y H:i', strtotime($a['appointment_date'])); ?></td>
                    <td style="color:var(--rust);">₱<?php echo number_format($a['price'],2); ?></td>
                    <td><span class="badge badge-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center;color:var(--gray);padding:1.5rem;">No appointments.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- Users Table -->
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (!empty($users)): foreach ($users as $u): ?>
            <tr>
                <td><strong style="color:var(--gold);">#<?php echo $u['id']; ?></strong></td>
                <td>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--rust));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;color:#fff;flex-shrink:0;">
                            <?php echo strtoupper(substr($u['full_name'],0,1)); ?>
                        </div>
                        <span style="color:var(--amber);"><?php echo htmlspecialchars($u['full_name']); ?></span>
                    </div>
                </td>
                <td style="color:var(--gray);">@<?php echo htmlspecialchars($u['username']); ?></td>
                <td style="color:var(--cream2);"><?php echo htmlspecialchars($u['email']); ?></td>
                <td style="color:var(--gray);"><?php echo htmlspecialchars($u['phone']); ?></td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td>
                    <a href="users.php?view=<?php echo $u['id']; ?>"   class="btn btn-info btn-sm">View</a>
                    <a href="users.php?delete=<?php echo $u['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" style="text-align:center;color:var(--gray);padding:2rem;">No users found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>