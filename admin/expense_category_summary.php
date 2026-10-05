<?php
/**
 * expense_category_summary.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Date-range filtered expense breakdown by category, with a click-through
 * detail modal per category. Include wherever business_expenses should be
 * summarized across a range (Dashboard, Daily Report's Expenses tab).
 *
 * Usage: <?php require 'expense_category_summary.php'; ?>
 * Requires: $conn (DB), config.php already loaded.
 *
 * The date-range form resubmits via GET using the page's own URL, carrying
 * forward any other query params already on it (e.g. Daily Report's
 * tab=expenses&date=...) so switching ranges doesn't lose that context.
 * ─────────────────────────────────────────────────────────────────────────────
 */

$exp_range = $_GET['exp_range'] ?? 'today';
if (!in_array($exp_range, ['today', '7d', '30d', 'custom'], true)) $exp_range = 'today';
if ($exp_range === 'custom') {
    $exp_from = trim($_GET['exp_from'] ?? '');
    $exp_to   = trim($_GET['exp_to']   ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_from)) $exp_from = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_to))   $exp_to   = date('Y-m-d');
} elseif ($exp_range === '7d') {
    $exp_from = date('Y-m-d', strtotime('-6 days'));
    $exp_to   = date('Y-m-d');
} elseif ($exp_range === '30d') {
    $exp_from = date('Y-m-d', strtotime('-29 days'));
    $exp_to   = date('Y-m-d');
} else {
    $exp_from = date('Y-m-d');
    $exp_to   = date('Y-m-d');
}

$exp_cat_colors = ['water' => '#0070f3', 'laundry' => '#8b5cf6', 'supplies' => '#2d8a4e', 'utilities' => '#f59e0b',
                    'food' => '#e8590c', 'transport' => '#0ca5ac', 'maintenance' => '#842029', 'misc' => '#6b7280'];

$exp_range_stmt = $conn->prepare("
    SELECT be.*, u.full_name AS by_name
    FROM business_expenses be
    LEFT JOIN users u ON be.added_by = u.id
    WHERE be.expense_date BETWEEN ? AND ?
    ORDER BY be.expense_date DESC, be.created_at DESC
");
$exp_range_stmt->bind_param("ss", $exp_from, $exp_to);
$exp_range_stmt->execute();
$exp_range_rows = $exp_range_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exp_range_stmt->close();

$exp_cat_summary = []; // category => ['total'=>, 'count'=>, 'entries'=>[]]
foreach ($exp_range_rows as $re) {
    $c = $re['category'];
    if (!isset($exp_cat_summary[$c])) $exp_cat_summary[$c] = ['total' => 0.0, 'count' => 0, 'entries' => []];
    $exp_cat_summary[$c]['total']  += (float)$re['amount'];
    $exp_cat_summary[$c]['count']  += 1;
    $exp_cat_summary[$c]['entries'][] = [
        'date'   => $re['expense_date'],
        'label'  => $re['label'],
        'amount' => (float)$re['amount'],
        'by'     => $re['by_name'] ?? 'System',
        'notes'  => $re['notes'] ?? '',
    ];
}
uasort($exp_cat_summary, fn($a, $b) => $b['total'] <=> $a['total']);
$exp_range_total = array_sum(array_column($exp_cat_summary, 'total'));

// Unique id suffix in case this partial is ever included more than once on
// the same page, so element ids and the JS data variable never collide.
$exp_uid = 'ecs' . substr(md5(uniqid('', true)), 0, 6);
?>
<div class="panel" style="margin-top:1.5rem;">
    <div class="panel-header">
        <span class="panel-title">Expense Category Summary</span>
        <span style="background:var(--rust);color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
            ₱<?php echo number_format($exp_range_total, 2); ?> for period
        </span>
    </div>
    <div class="panel-body" style="padding:1rem;">
        <form method="GET" style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;margin-bottom:0.9rem;">
            <?php foreach ($_GET as $_k => $_v):
                if (in_array($_k, ['exp_range', 'exp_from', 'exp_to'], true) || is_array($_v)) continue;
            ?>
            <input type="hidden" name="<?php echo htmlspecialchars($_k); ?>" value="<?php echo htmlspecialchars($_v); ?>">
            <?php endforeach; ?>
            <?php foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $rk => $rl): ?>
            <button type="submit" name="exp_range" value="<?php echo $rk; ?>"
                    class="btn btn-sm <?php echo $exp_range === $rk ? 'btn-primary' : 'btn-secondary'; ?>"
                    style="font-size:0.72rem;padding:0.3rem 0.6rem;"><?php echo $rl; ?></button>
            <?php endforeach; ?>
            <input type="date" name="exp_from" value="<?php echo htmlspecialchars($exp_range === 'custom' ? $exp_from : ''); ?>"
                   style="padding:0.3rem 0.5rem;border:1px solid var(--border2);border-radius:6px;background:var(--bg3);color:var(--brown);font-size:0.74rem;">
            <span style="font-size:0.72rem;color:var(--gray);">to</span>
            <input type="date" name="exp_to" value="<?php echo htmlspecialchars($exp_range === 'custom' ? $exp_to : ''); ?>"
                   style="padding:0.3rem 0.5rem;border:1px solid var(--border2);border-radius:6px;background:var(--bg3);color:var(--brown);font-size:0.74rem;">
            <button type="submit" name="exp_range" value="custom" class="btn btn-secondary btn-sm" style="font-size:0.72rem;padding:0.3rem 0.6rem;">Apply</button>
        </form>

        <?php if (empty($exp_cat_summary)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--gray);font-size:0.82rem;
                    background:var(--bg3);border-radius:8px;border:1px solid var(--border2);">
            No expenses recorded for this period.
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.6rem;">
            <?php foreach ($exp_cat_summary as $cat => $cs):
                $cat_color = $exp_cat_colors[$cat] ?? '#6b7280';
            ?>
            <div onclick="openExpCatModal_<?php echo $exp_uid; ?>('<?php echo htmlspecialchars($cat, ENT_QUOTES); ?>')"
                 style="cursor:pointer;padding:0.75rem;border-radius:9px;background:var(--bg3);
                        border:1px solid var(--border2);border-left:4px solid <?php echo $cat_color; ?>;
                        transition:box-shadow 0.15s;"
                 onmouseover="this.style.boxShadow='0 2px 10px rgba(0,0,0,0.08)'"
                 onmouseout="this.style.boxShadow='none'">
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.3rem;">
                    <?php echo ucfirst($cat); ?>
                </div>
                <div style="font-size:1.05rem;font-weight:700;color:<?php echo $cat_color; ?>;">
                    ₱<?php echo number_format($cs['total'], 2); ?>
                </div>
                <div style="font-size:0.68rem;color:var(--gray);"><?php echo $cs['count']; ?> entr<?php echo $cs['count'] === 1 ? 'y' : 'ies'; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:0.6rem;text-align:right;font-size:0.82rem;font-weight:700;color:var(--rust);">
            Period Total: ₱<?php echo number_format($exp_range_total, 2); ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Expense Category Detail Modal -->
<div id="expCatModal_<?php echo $exp_uid; ?>" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(30,20,10,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:520px;max-height:85vh;overflow-y:auto;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,0.22);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <span id="expCatModalTitle_<?php echo $exp_uid; ?>" style="font-weight:700;font-size:1rem;color:var(--brown);"></span>
            <button type="button" onclick="closeExpCatModal_<?php echo $exp_uid; ?>()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--gray);">✕</button>
        </div>
        <div id="expCatModalRange_<?php echo $exp_uid; ?>" style="font-size:0.74rem;color:var(--gray);margin-bottom:0.75rem;"></div>
        <div id="expCatModalList_<?php echo $exp_uid; ?>" style="display:flex;flex-direction:column;gap:0.4rem;"></div>
        <div style="margin-top:0.75rem;padding-top:0.6rem;border-top:1px solid var(--border2);
                    display:flex;justify-content:space-between;font-weight:700;color:var(--rust);">
            <span>Total</span>
            <span id="expCatModalTotal_<?php echo $exp_uid; ?>"></span>
        </div>
    </div>
</div>
<script>
(function() {
    const data  = <?php echo json_encode($exp_cat_summary, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const range = <?php echo json_encode(date('M d, Y', strtotime($exp_from)) . ($exp_from !== $exp_to ? ' – ' . date('M d, Y', strtotime($exp_to)) : ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    window['openExpCatModal_<?php echo $exp_uid; ?>'] = function(cat) {
        const cs = data[cat];
        if (!cs) return;
        document.getElementById('expCatModalTitle_<?php echo $exp_uid; ?>').textContent = cat.charAt(0).toUpperCase() + cat.slice(1) + ' Expenses';
        document.getElementById('expCatModalRange_<?php echo $exp_uid; ?>').textContent = range;
        const list = document.getElementById('expCatModalList_<?php echo $exp_uid; ?>');
        list.innerHTML = '';
        cs.entries.forEach(function(e) {
            const row = document.createElement('div');
            row.style = 'display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0.65rem;background:var(--bg3);border-radius:7px;border:1px solid var(--border2);';
            row.innerHTML =
                '<div style="flex:1;min-width:0;">' +
                    '<div style="font-size:0.82rem;font-weight:600;color:var(--brown);">' + escapeHtml(e.label) + '</div>' +
                    '<div style="font-size:0.68rem;color:var(--gray);">' + e.date + ' &middot; by ' + escapeHtml(e.by) +
                        (e.notes ? ' &middot; ' + escapeHtml(e.notes) : '') + '</div>' +
                '</div>' +
                '<span style="font-weight:700;color:var(--rust);font-size:0.85rem;white-space:nowrap;">₱' + e.amount.toFixed(2) + '</span>';
            list.appendChild(row);
        });
        document.getElementById('expCatModalTotal_<?php echo $exp_uid; ?>').textContent = '₱' + cs.total.toFixed(2);
        document.getElementById('expCatModal_<?php echo $exp_uid; ?>').style.display = 'flex';
    };
    window['closeExpCatModal_<?php echo $exp_uid; ?>'] = function() {
        document.getElementById('expCatModal_<?php echo $exp_uid; ?>').style.display = 'none';
    };
})();
</script>
