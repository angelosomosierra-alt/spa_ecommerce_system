<?php
/**
 * reset_data.php — ONE-TIME owner-only utility to wipe all appointment,
 * commission, and order history for a fresh production start.
 *
 * Not linked from the admin nav — reached only by typing its URL directly.
 * DELETE THIS FILE after running it once. Leaving a data-wipe endpoint
 * live indefinitely, even behind a login + confirmation phrase, is not
 * something to keep around longer than it takes to use it.
 *
 * Clears (TRUNCATE — also resets AUTO_INCREMENT back to 1):
 *   appointment_extra_services, appointment_sessions, appointment_therapists,
 *   therapist_ratings, appointments, order_items, orders
 *
 * Does NOT touch: therapist_commission (the %/rate configuration matrix),
 * therapist_deductions (CA/deductions), services, products, therapists,
 * staff/customer accounts, or anything else.
 */

require_once '../config.php';
require_once '../notify.php';
redirect_if_not_admin();

if (!is_owner()) {
    http_response_code(403);
    die('Only the owner account can run this.');
}

const RESET_PHRASE = 'RESET APPOINTMENTS';
const RESET_TABLES = [
    'appointment_extra_services',
    'appointment_sessions',
    'appointment_therapists',
    'therapist_ratings',
    'appointments',
    'order_items',
    'orders',
];

$done   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $confirm = trim($_POST['confirm'] ?? '');
    if ($confirm !== RESET_PHRASE) {
        $errors[] = 'Confirmation phrase did not match exactly — nothing was deleted.';
    } else {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        foreach (RESET_TABLES as $t) {
            if (!$conn->query("TRUNCATE TABLE `$t`")) {
                $errors[] = "Failed to clear `$t`: " . $conn->error;
            }
        }
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        if (empty($errors)) {
            log_activity($conn, 'data_reset',
                'Reset all appointments, orders, and commission/ratings history (owner-triggered, one-time)',
                'system', null);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Appointment Data</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#FAF3E8; color:#3B2A1A; padding:2rem; max-width:560px; margin:2rem auto; }
    .box { background:#fff; border:1px solid #EAD8C0; border-radius:12px; padding:1.5rem; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
    h1 { font-size:1.2rem; margin-top:0; }
    .warn { background:#fdecea; border:1px solid #f5c2c7; color:#842029; border-radius:8px; padding:0.85rem 1rem; font-size:0.88rem; margin-bottom:1.2rem; }
    .ok { background:#d4edda; border:1px solid #badbcc; color:#0f5132; border-radius:8px; padding:0.85rem 1rem; font-size:0.88rem; margin-bottom:1.2rem; }
    .err { background:#fdecea; border:1px solid #f5c2c7; color:#842029; border-radius:8px; padding:0.6rem 0.9rem; font-size:0.85rem; margin-bottom:0.6rem; }
    ul { font-size:0.88rem; line-height:1.6; }
    label { display:block; font-size:0.85rem; font-weight:600; margin:0.75rem 0 0.3rem; }
    input[type=text] { width:100%; padding:0.6rem 0.7rem; border:1px solid #EAD8C0; border-radius:8px; box-sizing:border-box; font-size:0.95rem; }
    button { margin-top:1.2rem; padding:0.7rem 1.4rem; background:#C96A2C; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.9rem; cursor:pointer; }
    button:hover { background:#A94F1D; }
    code { background:#f1e7da; padding:0.15rem 0.4rem; border-radius:4px; }
</style>
</head>
<body>
<div class="box">
    <h1>Reset Appointment &amp; Order Data</h1>

    <?php if ($done): ?>
        <div class="ok">Done. All appointments, extra services, sessions, ratings, and orders have been cleared and IDs reset to start from 1 again.</div>
        <p style="font-size:0.85rem;">Delete this file (<code>admin/reset_data.php</code>) now — it has no further use and shouldn't stay reachable.</p>
    <?php else: ?>
        <?php foreach ($errors as $e): ?>
        <div class="err"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>

        <div class="warn">
            This permanently deletes, for every customer, every appointment:
            <ul>
                <li>appointments, their assigned therapists, extra add-on services, and package sessions</li>
                <li>therapist ratings/reviews left on those appointments</li>
                <li>all orders and order items (product purchases, walk-in and online)</li>
            </ul>
            There is no undo. Commission <em>rates</em> (the Commission Matrix), services, products, therapists, and staff/customer accounts are not affected.
        </div>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <label for="confirm">Type <code><?php echo RESET_PHRASE; ?></code> to confirm:</label>
            <input type="text" name="confirm" id="confirm" autocomplete="off" required placeholder="<?php echo RESET_PHRASE; ?>">
            <button type="submit">Permanently Delete This Data</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
