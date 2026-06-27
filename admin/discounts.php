<?php
/**
 * discounts.php — Discount & Complimentary Tracking
 * Shows all transactions with any discount kind: voucher, senior, PWD, employee, influencer.
 * Full-access roles only (owner, it, marketing).
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
redirect_if_not_owner();

// Ensure discount columns exist on orders
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_type   VARCHAR(20)   DEFAULT 'none'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0.00");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS final_amount    DECIMAL(10,2) DEFAULT 0.00");
// Ensure influencer columns exist on appointments
$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS rate_type     VARCHAR(20)    DEFAULT 'regular'");
$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS charged_price DECIMAL(10,2)  DEFAULT NULL");

// ── Date filter ───────────────────────────────────────────────────────────────
$from        = $_GET['from']  ?? date('Y-m-01');
$to          = $_GET['to']    ?? date('Y-m-d');
$type_filter = $_GET['dtype'] ?? 'all';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   || !strtotime($to))   $to   = date('Y-m-d');
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; unset($tmp); }

$allowed_types = ['all', 'voucher', 'senior', 'pwd', 'employee', 'influencer'];
if (!in_array($type_filter, $allowed_types, true)) $type_filter = 'all';

// ── Query 1: orders with standard discounts (voucher/senior/pwd/employee) ────
// Skip entirely when the filter is influencer-only.
$discounted_orders = [];
if ($type_filter !== 'influencer') {
    // $type_filter is whitelisted above — safe to interpolate
    $type_where = ($type_filter !== 'all') ? "AND o.discount_type = '$type_filter'" : '';
    $_dq = $conn->prepare("
        SELECT
            o.id             AS order_id,
            o.customer_name,
            o.phone,
            o.discount_type,
            o.discount_amount,
            o.total_amount   AS original_amount,
            o.final_amount,
            o.payment_method,
            o.approval_status,
            o.created_at,
            o.approved_by_name,
            a.id             AS appt_id,
            a.appointment_date,
            a.status         AS appt_status,
            s.name           AS service_name,
            GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS product_names
        FROM orders o
        LEFT JOIN order_items   oi ON oi.order_id     = o.id
        LEFT JOIN appointments  a  ON a.order_item_id = oi.id
        LEFT JOIN services      s  ON s.id            = a.service_id
        LEFT JOIN products      p  ON p.id            = oi.product_id
        WHERE o.discount_type != 'none'
          AND o.discount_type IS NOT NULL
          AND DATE(o.created_at) BETWEEN ? AND ?
          $type_where
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $_dq->bind_param("ss", $from, $to);
    $_dq->execute();
    $discounted_orders = $_dq->get_result()->fetch_all(MYSQLI_ASSOC);
    $_dq->close();
}

// ── Query 2: influencer / complimentary appointments ─────────────────────────
// Skip when the filter is set to a non-influencer type.
$influencer_rows = [];
if ($type_filter === 'all' || $type_filter === 'influencer') {
    $_iq = $conn->prepare("
        SELECT
            NULL                                  AS order_id,
            u.full_name                           AS customer_name,
            u.phone                               AS phone,
            'influencer'                          AS discount_type,
            s.price                               AS discount_amount,
            s.price                               AS original_amount,
            COALESCE(a.charged_price, 0)          AS final_amount,
            NULL                                  AS payment_method,
            a.status                              AS approval_status,
            a.created_at,
            NULL                                  AS approved_by_name,
            a.id                                  AS appt_id,
            a.appointment_date,
            a.status                              AS appt_status,
            s.name                                AS service_name,
            NULL                                  AS product_names
        FROM appointments a
        JOIN users    u ON u.id  = a.user_id
        JOIN services s ON s.id  = a.service_id
        WHERE a.rate_type = 'influencer'
          AND DATE(a.created_at) BETWEEN ? AND ?
        ORDER BY a.created_at DESC
    ");
    $_iq->bind_param("ss", $from, $to);
    $_iq->execute();
    $influencer_rows = $_iq->get_result()->fetch_all(MYSQLI_ASSOC);
    $_iq->close();
}

// ── Merge both sources, sort by date desc ─────────────────────────────────────
$all_rows = array_merge($discounted_orders, $influencer_rows);
usort($all_rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

// ── KPI stats ─────────────────────────────────────────────────────────────────
$total_discounted  = count($all_rows);
$total_disc_amount = array_sum(array_column($all_rows, 'discount_amount'));
$total_final       = array_sum(array_column($all_rows, 'final_amount'));
$total_original    = array_sum(array_column($all_rows, 'original_amount'));

$by_type = ['voucher' => 0, 'senior' => 0, 'pwd' => 0, 'employee' => 0, 'influencer' => 0];
foreach ($all_rows as $d) {
    if (isset($by_type[$d['discount_type']])) $by_type[$d['discount_type']]++;
}

// ── Discount display maps ─────────────────────────────────────────────────────
$disc_icons  = [
    'voucher'    => '🎟️',
    'senior'     => '👴',
    'pwd'        => '♿',
    'employee'   => '🪪',
    'influencer' => '🌟',
];
$disc_labels = [
    'voucher'    => 'Voucher',
    'senior'     => 'Senior (20%)',
    'pwd'        => 'PWD (20%)',
    'employee'   => 'Employee (50%)',
    'influencer' => 'Influencer (Comp)',
];
$disc_colors = [
    'voucher'    => 'rgba(245,158,11,0.15)',
    'senior'     => 'rgba(59,130,246,0.12)',
    'pwd'        => 'rgba(139,92,246,0.12)',
    'employee'   => 'rgba(201,106,44,0.12)',
    'influencer' => 'rgba(16,185,129,0.13)',
];
$disc_text = [
    'voucher'    => '#92400e',
    'senior'     => '#1e40af',
    'pwd'        => '#6d28d9',
    'employee'   => '#7c2d12',
    'influencer' => '#065f46',
];

$status_colors = [
    'approved'  => ['#d1e7dd', '#0a3622'],
    'completed' => ['#cff4fc', '#055160'],
    'pending'   => ['#fff3cd', '#664d03'],
    'declined'  => ['#f8d7da', '#842029'],
];

$page_title  = 'Discounts';
$page_icon   = '🎟️';
$active_page = 'discounts';
require_once 'admin_header.php';
?>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon">🎟️</div>
        <div class="stat-number"><?php echo $total_discounted; ?></div>
        <div class="stat-label">Total Discounted</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">💰</div>
        <div class="stat-number">₱<?php echo number_format($total_disc_amount, 2); ?></div>
        <div class="stat-label">Total Discounts Given</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">✅</div>
        <div class="stat-number">₱<?php echo number_format($total_final, 2); ?></div>
        <div class="stat-label">Revenue After Discount</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon">👴</div>
        <div class="stat-number"><?php echo $by_type['senior']; ?></div>
        <div class="stat-label">Senior Citizen</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">♿</div>
        <div class="stat-number"><?php echo $by_type['pwd']; ?></div>
        <div class="stat-label">PWD</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🎟️</div>
        <div class="stat-number"><?php echo $by_type['voucher']; ?></div>
        <div class="stat-label">Voucher</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">🪪</div>
        <div class="stat-number"><?php echo $by_type['employee']; ?></div>
        <div class="stat-label">Employee (50%)</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">🌟</div>
        <div class="stat-number"><?php echo $by_type['influencer']; ?></div>
        <div class="stat-label">Influencer / Comp</div>
    </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem;">
    <div class="panel-body" style="padding:1rem 1.25rem;">
        <form method="GET" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <label style="font-size:0.78rem;color:var(--gray);font-weight:600;">From</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;
                              background:var(--bg3);color:var(--brown);font-size:0.82rem;">
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <label style="font-size:0.78rem;color:var(--gray);font-weight:600;">To</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>"
                       style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;
                              background:var(--bg3);color:var(--brown);font-size:0.82rem;">
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <label style="font-size:0.78rem;color:var(--gray);font-weight:600;">Type</label>
                <select name="dtype"
                        style="padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;
                               background:var(--bg3);color:var(--brown);font-size:0.82rem;">
                    <option value="all"        <?php echo $type_filter==='all'        ?'selected':''; ?>>All Types</option>
                    <option value="voucher"    <?php echo $type_filter==='voucher'    ?'selected':''; ?>>🎟️ Voucher</option>
                    <option value="senior"     <?php echo $type_filter==='senior'     ?'selected':''; ?>>👴 Senior Citizen</option>
                    <option value="pwd"        <?php echo $type_filter==='pwd'        ?'selected':''; ?>>♿ PWD</option>
                    <option value="employee"   <?php echo $type_filter==='employee'   ?'selected':''; ?>>🪪 Employee (50%)</option>
                    <option value="influencer" <?php echo $type_filter==='influencer' ?'selected':''; ?>>🌟 Influencer / Marketing</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>

            <!-- Quick ranges -->
            <div style="display:flex;gap:0.3rem;flex-wrap:wrap;margin-left:0.5rem;">
                <?php
                $ranges = [
                    'Today'      => [date('Y-m-d'),   date('Y-m-d')],
                    'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                    'This Month' => [date('Y-m-01'),  date('Y-m-d')],
                ];
                foreach ($ranges as $lbl => [$rf, $rt]):
                    $active = ($from === $rf && $to === $rt && $type_filter === 'all');
                ?>
                <a href="?from=<?php echo $rf; ?>&to=<?php echo $rt; ?>&dtype=all"
                   style="padding:0.3rem 0.65rem;border-radius:20px;font-size:0.7rem;font-weight:700;
                          text-decoration:none;white-space:nowrap;
                          background:<?php echo $active ? 'var(--gold)' : 'var(--bg3)'; ?>;
                          color:<?php echo $active ? '#fff' : 'var(--brown)'; ?>;
                          border:1px solid <?php echo $active ? 'var(--gold)' : 'var(--border2)'; ?>;">
                    <?php echo $lbl; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Discounted Transactions Table ──────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🎟️ Discounted Transactions</span>
        <span style="font-size:0.78rem;color:var(--gray);">
            <?php echo $total_discounted; ?> record<?php echo $total_discounted !== 1 ? 's' : ''; ?>
            · <?php echo date('M d', strtotime($from)); ?> – <?php echo date('M d, Y', strtotime($to)); ?>
        </span>
    </div>

    <?php if (empty($all_rows)): ?>
    <div class="panel-body" style="text-align:center;padding:3rem;color:var(--gray);">
        <div style="font-size:2.5rem;margin-bottom:0.5rem;">🎟️</div>
        No discounted transactions in this period.
    </div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Service / Product</th>
                    <th style="text-align:center;">Discount Type</th>
                    <th style="text-align:right;">Original</th>
                    <th style="text-align:right;">Discount</th>
                    <th style="text-align:right;">Final Paid</th>
                    <th>Payment</th>
                    <th>Approved By</th>
                    <th style="text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($all_rows as $d):
                $dtype     = $d['discount_type'];
                $item_name = $d['service_name'] ?: ($d['product_names'] ?: '—');
                [$sbg, $sfg] = $status_colors[$d['approval_status']] ?? ['#e2e3e5', '#41464b'];
            ?>
            <tr>
                <td style="white-space:nowrap;font-size:0.82rem;color:var(--gray);">
                    <?php echo date('M d, Y', strtotime($d['created_at'])); ?>
                    <div style="font-size:0.7rem;"><?php echo date('h:i A', strtotime($d['created_at'])); ?></div>
                </td>
                <td>
                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($d['customer_name']); ?></div>
                    <?php if ($d['phone']): ?>
                    <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($d['phone']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.85rem;">
                    <?php echo htmlspecialchars($item_name); ?>
                    <?php if ($d['appt_id']): ?>
                    <div style="font-size:0.7rem;color:var(--gray);">
                        Appt #<?php echo $d['appt_id']; ?>
                        <?php if ($d['appointment_date']): ?>
                         · <?php echo date('M d', strtotime($d['appointment_date'])); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <span style="padding:0.2rem 0.65rem;border-radius:20px;font-size:0.75rem;font-weight:700;
                                 background:<?php echo $disc_colors[$dtype] ?? '#f3f4f6'; ?>;
                                 color:<?php echo $disc_text[$dtype]   ?? '#374151'; ?>;">
                        <?php echo ($disc_icons[$dtype] ?? '🎟️') . ' ' . ($disc_labels[$dtype] ?? ucfirst($dtype)); ?>
                    </span>
                </td>
                <td style="text-align:right;color:var(--gray);font-size:0.85rem;">
                    ₱<?php echo number_format($d['original_amount'], 2); ?>
                </td>
                <td style="text-align:right;font-weight:700;color:var(--rust);">
                    −₱<?php echo number_format($d['discount_amount'], 2); ?>
                </td>
                <td style="text-align:right;font-weight:800;color:var(--green);font-size:0.95rem;">
                    ₱<?php echo number_format($d['final_amount'] ?: ($d['original_amount'] - $d['discount_amount']), 2); ?>
                </td>
                <td style="font-size:0.82rem;">
                    <?php echo $d['payment_method'] ? strtoupper($d['payment_method']) : '—'; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--gray);">
                    <?php echo htmlspecialchars($d['approved_by_name'] ?? '—'); ?>
                </td>
                <td style="text-align:center;">
                    <span style="background:<?php echo $sbg; ?>;color:<?php echo $sfg; ?>;
                                 padding:0.15rem 0.6rem;border-radius:20px;
                                 font-size:0.72rem;font-weight:600;">
                        <?php echo ucfirst($d['approval_status']); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(201,106,44,0.05);font-weight:700;">
                    <td colspan="4" style="padding:0.65rem 0.75rem;color:var(--brown);">
                        Totals — <?php echo $total_discounted; ?> transactions
                    </td>
                    <td style="text-align:right;padding:0.65rem 0.75rem;color:var(--gray);">
                        ₱<?php echo number_format($total_original, 2); ?>
                    </td>
                    <td style="text-align:right;padding:0.65rem 0.75rem;color:var(--rust);">
                        −₱<?php echo number_format($total_disc_amount, 2); ?>
                    </td>
                    <td style="text-align:right;padding:0.65rem 0.75rem;color:var(--green);font-size:1rem;">
                        ₱<?php echo number_format($total_final, 2); ?>
                    </td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>
