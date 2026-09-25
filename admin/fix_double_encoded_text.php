<?php
/**
 * fix_double_encoded_text.php — ONE-TIME cleanup tool.
 *
 * config.php's sanitize_input() used to call htmlspecialchars() at SAVE time,
 * on top of every display point already calling htmlspecialchars() again at
 * OUTPUT time. That double-escaped text like "&" into "&amp;" in storage,
 * which then rendered as the literal string "&amp;" once displayed. That bug
 * is fixed (sanitize_input() no longer escapes at save time), but every row
 * saved BEFORE the fix still has the stale escaped text sitting in the
 * database. This page fixes those existing rows, once.
 *
 * Restricted to owner/IT (see admin_page_roles() in admin_access.php). Not
 * run automatically — requires an explicit "Run Cleanup" button click.
 * Safe to re-run: a row is only updated if it still round-trips differently
 * through html_entity_decode(), so already-clean rows are left untouched.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$message      = '';
$message_type = '';
$results      = null; // populated only after the cleanup actually runs

// Every [table, column] pair that was ever populated through sanitize_input()
// across the codebase (services.php, products.php, categories.php,
// partners.php, therapists.php/staff.php, appointments.php, checkout.php,
// profile.php, daily_report.php, expenses_widget.php, walkin.php,
// walkin_payment.php, index.php, auth.php, admin_login.php,
// paymongo_intent.php) — i.e. every free-text field that could contain
// stale double-encoded HTML entities from before the fix.
$targets = [
    ['services', 'name'],
    ['services', 'description'],
    ['products', 'name'],
    ['products', 'description'],
    ['categories', 'name'],
    ['partners', 'name'],
    ['partners', 'contact'],
    ['partners', 'notes'],
    ['therapists', 'full_name'],
    ['therapists', 'phone'],
    ['therapists', 'specialties'],
    ['therapist_deductions', 'label'],
    ['therapist_deductions', 'notes'],
    ['appointment_therapists', 'notes'],
    ['appointment_extra_services', 'person_label'],
    ['appointment_extra_services', 'notes'],
    ['appointments', 'cancel_reason'],
    ['appointments', 'customer_note'],
    ['appointments', 'home_address'],
    ['appointments', 'home_notes'],
    ['orders', 'customer_name'],
    ['orders', 'email'],
    ['orders', 'phone'],
    ['orders', 'address'],
    ['orders', 'slip_number'],
    ['users', 'full_name'],
    ['users', 'email'],
    ['users', 'phone'],
    ['users', 'address'],
    ['daily_reports', 'opening_cashier'],
    ['daily_reports', 'closing_cashier'],
    ['daily_reports', 'notes'],
    ['gift_certificates', 'client_name'],
    ['gift_certificates', 'series'],
    ['gift_certificates', 'voucher_code'],
    ['gift_certificates', 'remarks'],
    ['unpaids_corp', 'client_name'],
    ['unpaids_corp', 'series'],
    ['unpaids_corp', 'notes'],
    ['daily_product_sales', 'particular'],
    ['daily_report_spreadsheet_rows', 'time_in'],
    ['daily_report_spreadsheet_rows', 'time_out'],
    ['daily_report_spreadsheet_rows', 'slip_no'],
    ['daily_report_spreadsheet_rows', 'client_name'],
    ['daily_report_spreadsheet_rows', 'service_name'],
    ['daily_report_spreadsheet_rows', 'stylist'],
    ['daily_report_spreadsheet_rows', 'mode_of_payment'],
    ['daily_report_spreadsheet_rows', 'remarks'],
    ['business_expenses', 'label'],
    ['business_expenses', 'notes'],
    ['contact_messages', 'name'],
    ['contact_messages', 'email'],
    ['contact_messages', 'message'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cleanup'])) {
    verify_csrf_token();

    $results = [];
    foreach ($targets as [$table, $column]) {
        // Defensive: skip silently if this install's schema doesn't have the
        // column (older/newer branch) instead of a hard SQL error.
        $chk = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$chk || $chk->num_rows === 0) continue;

        $rows = $conn->query("SELECT `id`, `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != ''");
        if (!$rows) continue;

        $updated = 0;
        $stmt = $conn->prepare("UPDATE `$table` SET `$column` = ? WHERE `id` = ?");
        while ($row = $rows->fetch_assoc()) {
            $current = $row[$column];

            // Decode repeatedly until the value stops changing, not just
            // once — a row edited multiple times before the sanitize_input()
            // root-cause fix can carry 2, 3+ stacked layers of encoding
            // (e.g. "&amp;amp;amp;"), and a single pass only strips one
            // layer, leaving visible "&amp;" behind. Capped at 10 iterations
            // as a safety guard against pathological input; real corruption
            // from repeated edits should never realistically go that deep.
            $decoded    = $current;
            $iterations = 0;
            do {
                $next       = html_entity_decode($decoded, ENT_QUOTES, 'UTF-8');
                $changed    = ($next !== $decoded);
                $decoded    = $next;
                $iterations++;
            } while ($changed && $iterations < 10);

            // Only touch rows that actually round-trip differently — leaves
            // already-clean data untouched, so this script is safe to re-run.
            if ($decoded !== $current) {
                $stmt->bind_param("si", $decoded, $row['id']);
                $stmt->execute();
                $updated++;
            }
        }
        $stmt->close();

        if ($updated > 0) {
            $results["{$table}.{$column}"] = $updated;
        }
    }

    $total = array_sum($results);
    $message = $total > 0
        ? "✅ Cleanup complete — {$total} row(s) fixed across " . count($results) . " column(s)."
        : "✅ Cleanup complete — nothing needed fixing (all data already clean).";
    $message_type = 'success';

    // Audit trail — so "was this ever run, and when" is answerable later.
    $_log_detail = empty($results)
        ? 'no rows needed fixing'
        : implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($results), array_values($results)));
    log_activity($conn, 'double_encoded_text_cleanup',
        "Ran double-encoded text cleanup — {$total} row(s) fixed across " . count($results) . " column(s) ({$_log_detail})");
}

$page_title  = 'Fix Double-Encoded Text';
$page_icon   = '🧹';
$active_page = 'fix_double_encoded_text';
require_once 'admin_header.php';
?>

<div class="admin-content">
<div class="page-header">
    <h1 class="page-title"><?php echo $page_icon; ?> <?php echo $page_title; ?></h1>
    <p class="page-subtitle">One-time cleanup for rows saved before the sanitize_input() double-escaping fix (e.g. a name stored as "M &amp;amp; M Massage" instead of "M &amp; M Massage").</p>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($results !== null): ?>
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header"><span class="panel-title">📋 Rows updated per column</span></div>
    <div class="panel-body">
        <?php if (empty($results)): ?>
        <p style="color:var(--gray);">No rows needed fixing.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Table.Column</th><th>Rows Fixed</th></tr></thead>
            <tbody>
            <?php foreach ($results as $key => $count): ?>
                <tr><td><?php echo htmlspecialchars($key); ?></td><td><?php echo (int)$count; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header"><span class="panel-title">⚠️ Run Cleanup</span></div>
    <div class="panel-body">
        <p>This scans <?php echo count($targets); ?> table.column pairs (every free-text field written through <code>sanitize_input()</code>) and fixes any value that still contains a stale HTML entity from before the save-time-escaping fix. Rows that are already clean are left untouched — safe to run more than once.</p>
        <form method="POST" onsubmit="return confirm('Run the one-time double-encoding cleanup now? This will UPDATE any row still containing stale HTML entities.');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="run_cleanup" value="1">
            <button type="submit" class="btn btn-primary">🧹 Run Cleanup</button>
        </form>
    </div>
</div>

</div>
<?php require_once 'admin_footer.php'; ?>
