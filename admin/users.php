<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$message = ''; $message_type = '';

// ── SOFT DELETE — hindi na ginagamit ang DELETE FROM users ────────────────────
// FIX: Instead of permanently deleting, i-mark lang as deleted
// Para hindi mawala ang revenue history sa analytics
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Preserve customer name sa orders bago i-soft delete
    $snap = $conn->prepare("
        UPDATE orders o
        JOIN users u ON o.user_id = u.id
        SET o.customer_name_snapshot = u.full_name
        WHERE o.user_id = ?
          AND o.customer_name_snapshot IS NULL
    ");
    $snap->bind_param("i", $id);
    $snap->execute();
    $snap->close();

    // Soft delete — i-set ang deleted_at, huwag mag-DELETE
    $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ? AND role = 'user'");
    $stmt->bind_param("i", $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message      = "User account deactivated. Their order and revenue history is preserved.";
        $message_type = "success";
    } else {
        $message      = "Error deactivating user.";
        $message_type = "danger";
    }
    $stmt->close();
}

// ── RESTORE deleted user ───────────────────────────────────────────────────────
if (isset($_GET['restore'])) {
    $id = intval($_GET['restore']);
    $stmt = $conn->prepare("UPDATE users SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    $message      = $stmt->execute() ? "User account restored." : "Error restoring user.";
    $message_type = $stmt->execute() ? "success" : "danger";
    $stmt->close();
}

// ── FETCH ACTIVE users (walang deleted_at) ────────────────────────────────────
$users = [];
$result = $conn->query("
    SELECT * FROM users 
    WHERE role='user' 
      AND username != 'walkin_customer'
      AND deleted_at IS NULL
    ORDER BY created_at DESC
");
while ($row = $result->fetch_assoc()) $users[] = $row;

// ── FETCH DELETED users (para ipakita sa admin kung gusto) ───────────────────
$deleted_users = [];
$result = $conn->query("
    SELECT * FROM users 
    WHERE role='user' 
      AND username != 'walkin_customer'
      AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
");
while ($row = $result->fetch_assoc()) $deleted_users[] = $row;

// ── VIEW USER ──────────────────────────────────────────────────────────────────
$view_user = null;
if (isset($_GET['view'])) {
    $id = intval($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='user'");
    $stmt->bind_param("i",$id); $stmt->execute();
    $view_user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($view_user) {
        $stmt = $conn->prepare("
            SELECT a.*, s.name as service_name, s.price 
            FROM appointments a 
            JOIN services s ON a.service_id=s.id 
            WHERE a.user_id=? 
            ORDER BY a.appointment_date DESC
        ");
        $stmt->bind_param("i",$id); $stmt->execute();
        $view_user['appointments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("
            SELECT o.*, COUNT(oi.id) as item_count 
            FROM orders o 
            LEFT JOIN order_items oi ON o.id=oi.order_id 
            WHERE o.user_id=? 
            GROUP BY o.id 
            ORDER BY o.created_at DESC LIMIT 5
        ");
        $stmt->bind_param("i",$id); $stmt->execute();
        $view_user['orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

$page_title  = $view_user ? 'User: '.htmlspecialchars($view_user['full_name']) : 'Users';
$page_icon   = '👥';
$active_page = 'users';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<?php if ($view_user): ?>
<div style="margin-bottom:1rem;">
    <a href="users.php" class="btn btn-secondary">← Back to Users</a>
</div>

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
                    <?php if (!empty($view_user['deleted_at'])): ?>
                    <div style="font-size:0.72rem;background:#FEE2E2;color:#991B1B;padding:0.2rem 0.5rem;border-radius:20px;display:inline-block;margin-top:0.3rem;">
                        ⚠️ Deactivated <?php echo date('M d, Y', strtotime($view_user['deleted_at'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <table style="width:100%;font-size:0.85rem;">
                <tr><td style="color:var(--gray);padding:0.3rem 0;">Email</td><td><?php echo htmlspecialchars($view_user['email']); ?></td></tr>
                <tr><td style="color:var(--gray);padding:0.3rem 0;">Phone</td><td><?php echo htmlspecialchars($view_user['phone']); ?></td></tr>
                <tr><td style="color:var(--gray);padding:0.3rem 0;">Address</td><td><?php echo htmlspecialchars($view_user['address']); ?></td></tr>
                <tr><td style="color:var(--gray);padding:0.3rem 0;">Joined</td><td><?php echo date('M d, Y', strtotime($view_user['created_at'])); ?></td></tr>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><span class="panel-title">📅 Recent Appointments</span></div>
        <div class="panel-body" style="padding:0;">
            <?php if (!empty($view_user['appointments'])): ?>
            <?php foreach (array_slice($view_user['appointments'], 0, 5) as $a): ?>
            <div style="padding:0.75rem 1rem;border-bottom:1px solid var(--border2);">
                <div style="font-weight:600;font-size:0.85rem;color:var(--cream);"><?php echo htmlspecialchars($a['service_name']); ?></div>
                <div style="font-size:0.75rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?> · <?php echo ucfirst($a['status']); ?></div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="padding:1.5rem;text-align:center;color:var(--gray);font-size:0.85rem;">No appointments yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>

<!-- ── ACTIVE USERS TABLE ─────────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">👥 Active Users (<?php echo count($users); ?>)</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Name</th><th>Username</th><th>Email</th>
                    <th>Phone</th><th>Joined</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($u['full_name']); ?></strong></td>
                <td style="color:var(--gray);">@<?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><?php echo htmlspecialchars($u['phone']); ?></td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td>
                    <a href="users.php?view=<?php echo $u['id']; ?>" class="btn btn-secondary btn-sm">👁 View</a>
                    <a href="users.php?delete=<?php echo $u['id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Deactivate this user? Their order history and revenue data will be preserved.')">
                       🚫 Deactivate
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:2rem;">No active users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── DEACTIVATED USERS TABLE ───────────────────────────────────────────── -->
<?php if (!empty($deleted_users)): ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🗄️ Deactivated Users (<?php echo count($deleted_users); ?>) — Revenue History Preserved</span>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Name</th><th>Username</th><th>Email</th>
                    <th>Deactivated</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deleted_users as $u): ?>
            <tr style="opacity:0.7;">
                <td><strong><?php echo htmlspecialchars($u['full_name']); ?></strong></td>
                <td style="color:var(--gray);">@<?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td style="font-size:0.78rem;color:var(--gray);"><?php echo date('M d, Y', strtotime($u['deleted_at'])); ?></td>
                <td>
                    <a href="users.php?view=<?php echo $u['id']; ?>" class="btn btn-secondary btn-sm">👁 View</a>
                    <a href="users.php?restore=<?php echo $u['id']; ?>"
                       class="btn btn-success btn-sm"
                       onclick="return confirm('Restore this user account?')">
                       ♻️ Restore
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>