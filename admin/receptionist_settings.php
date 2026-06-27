<?php
/**
 * receptionist_settings.php
 * Owner-only page to configure receptionist login restrictions:
 *   - Login time window (start & end)
 *   - Clear active session (force logout)
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
redirect_if_not_owner();

$msg = ''; $msg_type = 'success';

// ── Save time window settings ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    verify_csrf_token();
    $start = $_POST['login_start'] ?? '07:00';
    $end   = $_POST['login_end']   ?? '23:59';
    $tz    = $_POST['timezone']    ?? 'Asia/Manila';

    // Validate time format HH:MM
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        $msg = '❌ Invalid time format.'; $msg_type = 'danger';
    } else {
        $uid = (int)$_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');
        $settings = [
            'receptionist_login_start' => $start,
            'receptionist_login_end'   => $end,
            'receptionist_timezone'    => $tz,
        ];
        foreach ($settings as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
                                    VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)");
            $stmt->bind_param("ssis", $key, $val, $uid, $now);
            $stmt->execute(); $stmt->close();
        }
        $msg = '✅ Receptionist settings saved.';
    }
}

// ── Clear receptionist session (force logout) ─────────────────────────────────
if (isset($_GET['clear_session'])) {
    $rid = intval($_GET['clear_session']);
    $stmt = $conn->prepare("UPDATE users SET session_token=NULL, session_started=NULL WHERE id=? AND admin_role='cashier'");
    $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
    $msg = '✅ Receptionist session cleared. They can now log in again.';
}

// ── Fetch current settings ────────────────────────────────────────────────────
$settings_rows = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'receptionist_%'")->fetch_all(MYSQLI_ASSOC);
$settings = [];
foreach ($settings_rows as $row) $settings[$row['setting_key']] = $row['setting_value'];

$login_start = $settings['receptionist_login_start'] ?? '07:00';
$login_end   = $settings['receptionist_login_end']   ?? '23:59';
$timezone    = $settings['receptionist_timezone']    ?? 'Asia/Manila';

// ── Fetch receptionist accounts ───────────────────────────────────────────────
$receptionists = $conn->query("
    SELECT id, username, full_name, email, session_token, session_started, deleted_at
    FROM users
    WHERE admin_role = 'cashier' AND role = 'admin'
    ORDER BY created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$page_title  = 'Receptionist Settings';
$page_icon   = '⚙️';
$active_page = 'receptionist_settings';
require_once 'admin_header.php';
?>

<div class="admin-content">
<div class="page-header">
    <h1 class="page-title"><?php echo $page_icon; ?> <?php echo $page_title; ?></h1>
    <p class="page-subtitle">Configure receptionist login restrictions and manage active sessions.</p>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom:1.5rem;">
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

<!-- ── Login Time Window ──────────────────────────────────────────────────── -->
<div class="card" style="padding:1.5rem;">
    <h3 style="margin:0 0 1rem;font-size:1rem;color:var(--gold);">🕐 Login Time Window</h3>
    <p style="font-size:0.85rem;color:#666;margin-bottom:1.25rem;">
        Receptionist accounts can only log in within this time window.<br>
        Outside this window, login will be blocked automatically.
    </p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div>
                <label style="font-size:0.8rem;font-weight:600;color:#555;display:block;margin-bottom:0.4rem;">
                    Login Start Time
                </label>
                <input type="time" name="login_start"
                       value="<?php echo htmlspecialchars($login_start); ?>"
                       style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:8px;font-size:0.9rem;">
            </div>
            <div>
                <label style="font-size:0.8rem;font-weight:600;color:#555;display:block;margin-bottom:0.4rem;">
                    Login End Time
                </label>
                <input type="time" name="login_end"
                       value="<?php echo htmlspecialchars($login_end); ?>"
                       style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:8px;font-size:0.9rem;">
            </div>
        </div>
        <div style="margin-bottom:1rem;">
            <label style="font-size:0.8rem;font-weight:600;color:#555;display:block;margin-bottom:0.4rem;">
                Timezone
            </label>
            <select name="timezone"
                    style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:8px;font-size:0.9rem;">
                <?php
                $timezones = ['Asia/Manila' => 'Asia/Manila (PHT, UTC+8)', 'UTC' => 'UTC'];
                foreach ($timezones as $tz_val => $tz_label):
                ?>
                <option value="<?php echo $tz_val; ?>" <?php echo $timezone === $tz_val ? 'selected' : ''; ?>>
                    <?php echo $tz_label; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="background:#fff8f0;border:1px solid #ead8c0;border-radius:8px;padding:0.75rem;margin-bottom:1rem;font-size:0.8rem;color:#7a5c2e;">
            📌 Current setting: Receptionist can log in from
            <strong><?php echo date('h:i A', strtotime($login_start)); ?></strong>
            to <strong><?php echo date('h:i A', strtotime($login_end)); ?></strong>
            (<?php echo htmlspecialchars($timezone); ?>)
        </div>
        <button type="submit" name="save_settings"
                style="width:100%;padding:0.7rem;background:var(--gold);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
            💾 Save Settings
        </button>
    </form>
</div>

<!-- ── Active Receptionist Accounts ──────────────────────────────────────── -->
<div class="card" style="padding:1.5rem;">
    <h3 style="margin:0 0 1rem;font-size:1rem;color:var(--gold);">🪪 Receptionist Accounts</h3>
    <?php if (empty($receptionists)): ?>
        <p style="color:#888;font-size:0.85rem;">No receptionist accounts found.</p>
    <?php else: ?>
        <?php foreach ($receptionists as $r):
            $is_active  = !empty($r['session_token']) && !empty($r['session_started']);
            $is_deleted = !empty($r['deleted_at']);
            $age_hrs    = $is_active ? round((time() - strtotime($r['session_started'])) / 3600, 1) : null;
            // Auto-expired sessions (> 8 hours)
            if ($is_active && $age_hrs > 8) $is_active = false;
        ?>
        <div style="border:1px solid #eee;border-radius:10px;padding:1rem;margin-bottom:0.75rem;
                    background:<?php echo $is_deleted ? '#fef2f2' : ($is_active ? '#f0fdf4' : '#fff'); ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                <div>
                    <div style="font-weight:700;font-size:0.9rem;">
                        <?php echo htmlspecialchars($r['full_name']); ?>
                        <?php if ($is_deleted): ?>
                            <span style="background:#fee2e2;color:#b91c1c;font-size:0.7rem;padding:0.15rem 0.5rem;border-radius:999px;margin-left:0.4rem;">Deactivated</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.78rem;color:#888;">@<?php echo htmlspecialchars($r['username']); ?> · <?php echo htmlspecialchars($r['email']); ?></div>
                    <?php if ($is_active): ?>
                        <div style="font-size:0.78rem;color:#15803d;margin-top:0.25rem;">
                            🟢 Active session — logged in <?php echo $age_hrs; ?> hour(s) ago
                            (<?php echo date('M d, h:i A', strtotime($r['session_started'])); ?>)
                        </div>
                    <?php else: ?>
                        <div style="font-size:0.78rem;color:#888;margin-top:0.25rem;">⚪ No active session</div>
                    <?php endif; ?>
                </div>
                <?php if ($is_active): ?>
                <a href="receptionist_settings.php?clear_session=<?php echo $r['id']; ?>"
                   onclick="return confirm('Force logout this receptionist?')"
                   style="white-space:nowrap;background:#fee2e2;color:#b91c1c;border:none;padding:0.4rem 0.75rem;
                          border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer;text-decoration:none;">
                    🔴 Force Logout
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:1rem;padding:0.75rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:0.78rem;color:#0369a1;">
        💡 <strong>Rules enforced:</strong><br>
        • Only <strong>1 receptionist account</strong> can exist at a time<br>
        • Only <strong>1 active session</strong> allowed simultaneously<br>
        • Login only allowed within the configured time window<br>
        • Sessions auto-expire after 8 hours of inactivity
    </div>
</div>

</div><!-- end grid -->
</div><!-- end admin-content -->

<?php require_once 'admin_footer.php'; ?>