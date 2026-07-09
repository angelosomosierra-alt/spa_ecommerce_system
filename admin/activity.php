<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();

// Only owner / it / marketing — cashier is excluded
$viewer_role = $_SESSION['admin_role'] ?? '';
if (!in_array($viewer_role, ['owner', 'it', 'marketing'], true)) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../notify.php';

// ─── FILTERS ─────────────────────────────────────────────────────────────────
$filter_actor  = intval($_GET['actor']     ?? 0);
$filter_action = trim($_GET['action']      ?? '');
$filter_from   = trim($_GET['date_from']   ?? '');
$filter_to     = trim($_GET['date_to']     ?? '');
$page          = max(1, intval($_GET['p']  ?? 1));
$per_page      = 30;
$offset        = ($page - 1) * $per_page;

// Default date range: last 7 days
if ($filter_from === '' && $filter_to === '') {
    $filter_from = date('Y-m-d', strtotime('-7 days'));
    $filter_to   = date('Y-m-d');
}

// ─── BUILD WHERE ──────────────────────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filter_actor > 0) {
    $where[]  = 'actor_id = ?';
    $params[] = $filter_actor;
    $types   .= 'i';
}
if ($filter_action !== '') {
    $where[]  = 'action_type = ?';
    $params[] = $filter_action;
    $types   .= 's';
}
if ($filter_from !== '') {
    $where[]  = 'DATE(created_at) >= ?';
    $params[] = $filter_from;
    $types   .= 's';
}
if ($filter_to !== '') {
    $where[]  = 'DATE(created_at) <= ?';
    $params[] = $filter_to;
    $types   .= 's';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ─── COUNT ────────────────────────────────────────────────────────────────────
$count_sql  = "SELECT COUNT(*) AS c FROM activity_logs $where_sql";
if ($params) {
    $cs = $conn->prepare($count_sql);
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['c'];
    $cs->close();
} else {
    $total = (int)$conn->query($count_sql)->fetch_assoc()['c'];
}
$total_pages = max(1, ceil($total / $per_page));

// ─── FETCH LOGS ───────────────────────────────────────────────────────────────
$log_sql = "SELECT * FROM activity_logs $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?";
$log_params = array_merge($params, [$per_page, $offset]);
$log_types  = $types . 'ii';
$ls = $conn->prepare($log_sql);
$ls->bind_param($log_types, ...$log_params);
$ls->execute();
$logs = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

// ─── FILTER DROPDOWNS ─────────────────────────────────────────────────────────
// All distinct actors (admins who have ever logged)
$actor_rows = $conn->query("
    SELECT DISTINCT actor_id, actor_name
    FROM activity_logs
    WHERE actor_id IS NOT NULL
    ORDER BY actor_name ASC
")->fetch_all(MYSQLI_ASSOC);

// All distinct action types
$action_rows = $conn->query("
    SELECT DISTINCT action_type
    FROM activity_logs
    ORDER BY action_type ASC
")->fetch_all(MYSQLI_ASSOC);

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function al_icon(string $action): string {
    return match(true) {
        str_contains($action, 'approved')    => '✅',
        str_contains($action, 'completed')   => '🎉',
        str_contains($action, 'declined')    => '❌',
        str_contains($action, 'cancelled')   => '🚫',
        str_contains($action, 'checkedin')   => '📋',
        str_contains($action, 'assigned') || str_contains($action, 'assign') => '💆',
        str_contains($action, 'edited') || str_contains($action, 'updated') || str_contains($action, 'rescheduled') => '✏️',
        str_contains($action, 'created') || str_contains($action, 'added')  => '➕',
        str_contains($action, 'deleted') || str_contains($action, 'removed')=> '🗑️',
        str_contains($action, 'login') || str_contains($action, 'clockin')  => '🔑',
        str_contains($action, 'logout') || str_contains($action, 'clockout')=> '🚪',
        str_contains($action, 'commission')  => '💰',
        str_contains($action, 'stock')       => '📦',
        default                              => '📋',
    };
}

function al_color(string $action): string {
    return match(true) {
        str_contains($action, 'approved') || str_contains($action, 'completed') || str_contains($action, 'created') || str_contains($action, 'added') => '#16a34a',
        str_contains($action, 'declined') || str_contains($action, 'cancelled') || str_contains($action, 'deleted') => '#dc2626',
        str_contains($action, 'assigned') || str_contains($action, 'commission') => '#7c3aed',
        str_contains($action, 'edited')   || str_contains($action, 'updated') || str_contains($action, 'rescheduled') || str_contains($action, 'stock') => '#d97706',
        str_contains($action, 'login')    || str_contains($action, 'clockin')  => '#6b7280',
        str_contains($action, 'logout')   || str_contains($action, 'clockout') => '#9ca3af',
        str_contains($action, 'checkedin') => '#0284c7',
        default => '#6b7280',
    };
}

function al_bg(string $action): string {
    return match(true) {
        str_contains($action, 'approved') || str_contains($action, 'completed') || str_contains($action, 'created') || str_contains($action, 'added') => '#f0fdf4',
        str_contains($action, 'declined') || str_contains($action, 'cancelled') || str_contains($action, 'deleted') => '#fef2f2',
        str_contains($action, 'assigned') || str_contains($action, 'commission') => '#faf5ff',
        str_contains($action, 'edited')   || str_contains($action, 'updated') || str_contains($action, 'rescheduled') || str_contains($action, 'stock') => '#fffbeb',
        str_contains($action, 'login')    || str_contains($action, 'clockin')  => '#f9fafb',
        str_contains($action, 'checkedin') => '#eff6ff',
        default => '#f9fafb',
    };
}

function al_role_badge(string $role): string {
    [$bg, $color] = match($role) {
        'owner'     => ['#92400e', '#fef3c7'],
        'it'        => ['#1e40af', '#dbeafe'],
        'marketing' => ['#6b21a8', '#f3e8ff'],
        'cashier'   => ['#3B2A1A', '#EAD8C0'],
        default     => ['#374151', '#f3f4f6'],
    };
    $label = match($role) {
        'owner'     => '👑 Owner',
        'it'        => '💻 IT',
        'marketing' => '📣 Marketing',
        'cashier', 'receptionist' => '🏪 Receptionist',
        'staff'     => '🪪 Staff',
        default     => ucfirst($role),
    };
    return "<span style=\"background:{$bg};color:{$color};padding:1px 7px;border-radius:20px;font-size:0.68rem;font-weight:700;\">{$label}</span>";
}

function al_time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff / 60) . 'm ago';
    if ($diff < 86400)     return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)    return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($dt));
}

function al_action_label(string $action): string {
    return ucwords(str_replace('_', ' ', $action));
}

function al_date_header(string $dt): string {
    $date = date('Y-m-d', strtotime($dt));
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today)     return 'Today';
    if ($date === $yesterday) return 'Yesterday';
    return date('F j, Y', strtotime($dt));
}

// ─── PAGE SETUP ───────────────────────────────────────────────────────────────
$page_title  = 'Activity Log';
$page_icon   = '📋';
$active_page = 'activity';
require_once __DIR__ . '/admin_header.php';
?>

<style>
.al-filters { display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1.25rem;align-items:flex-end; }
.al-filters select, .al-filters input[type=date] {
    padding:.45rem .7rem;border:1.5px solid var(--border2);border-radius:8px;
    background:var(--bg2);color:var(--brown);font-size:.82rem;min-width:140px;
}
.al-filters select:focus, .al-filters input:focus { outline:none;border-color:var(--rust); }
.al-date-sep { font-size:.78rem;color:var(--gray);align-self:center;margin:0 .15rem; }
.al-date-header { font-size:.72rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.08em;color:var(--gray);margin:1.1rem 0 .5rem;padding-left:2px; }
.al-card {
    display:flex;gap:.9rem;align-items:flex-start;
    padding:.85rem 1rem;border-radius:12px;border:1px solid #e5e7eb;
    margin-bottom:.45rem;transition:box-shadow .15s;
}
.al-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); }
.al-icon-wrap {
    width:36px;height:36px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;font-size:1rem;flex-shrink:0;margin-top:1px;
}
.al-body { flex:1;min-width:0; }
.al-title { font-size:.85rem;font-weight:700;color:#111;margin-bottom:.15rem; }
.al-meta  { font-size:.75rem;color:#6b7280;margin-bottom:.2rem;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center; }
.al-desc  { font-size:.8rem;color:#374151;line-height:1.45; }
.al-time  { font-size:.72rem;color:#9ca3af;white-space:nowrap;flex-shrink:0;margin-top:2px; }
.al-empty { text-align:center;padding:3rem 1rem;color:#9ca3af;font-size:.88rem; }
.al-pagination { display:flex;justify-content:center;gap:.4rem;margin-top:1.25rem;flex-wrap:wrap; }
.al-pagination a, .al-pagination span {
    display:inline-flex;align-items:center;justify-content:center;
    width:32px;height:32px;border-radius:8px;font-size:.82rem;text-decoration:none;
    border:1.5px solid #e5e7eb;color:#374151;font-weight:600;
}
.al-pagination a:hover  { background:var(--bg2);border-color:var(--rust); }
.al-pagination span.cur { background:var(--rust);color:#fff;border-color:var(--rust); }
.al-pagination span.dots { border:none;color:#9ca3af; }
</style>

<div style="max-width:860px;margin:0 auto;">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
    <div>
        <div style="font-size:1.1rem;font-weight:700;color:var(--brown);">Activity Log</div>
        <div style="font-size:.78rem;color:var(--gray);">Audit trail of all admin actions</div>
    </div>
    <div style="font-size:.78rem;color:var(--gray);"><?php echo number_format($total); ?> record<?php echo $total === 1 ? '' : 's'; ?></div>
</div>

<!-- Filters -->
<form method="GET" action="activity.php" class="al-filters">
    <div>
        <div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">Actor</div>
        <select name="actor">
            <option value="0">All actors</option>
            <?php foreach ($actor_rows as $ar): ?>
            <option value="<?php echo intval($ar['actor_id']); ?>"
                <?php if ($filter_actor === intval($ar['actor_id'])) echo 'selected'; ?>>
                <?php echo htmlspecialchars($ar['actor_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">Action</div>
        <select name="action">
            <option value="">All actions</option>
            <?php foreach ($action_rows as $ar): ?>
            <option value="<?php echo htmlspecialchars($ar['action_type']); ?>"
                <?php if ($filter_action === $ar['action_type']) echo 'selected'; ?>>
                <?php echo htmlspecialchars(al_action_label($ar['action_type'])); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;align-items:flex-end;gap:.35rem;">
        <div>
            <div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">From</div>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_from); ?>">
        </div>
        <span class="al-date-sep">→</span>
        <div>
            <div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">To</div>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_to); ?>">
        </div>
    </div>
    <div style="display:flex;gap:.4rem;align-self:flex-end;">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="activity.php" class="btn btn-sm" style="background:var(--bg2);color:var(--brown);">Reset</a>
    </div>
</form>

<!-- Log Cards -->
<?php if (empty($logs)): ?>
<div class="al-empty">
    📋 No activity found for the selected filters.<br>
    <span style="font-size:.75rem;">Try expanding the date range.</span>
</div>
<?php else: ?>

<?php
$last_date_header = null;
foreach ($logs as $log):
    $action   = $log['action_type'];
    $icon     = al_icon($action);
    $color    = al_color($action);
    $bg       = al_bg($action);
    $dh       = al_date_header($log['created_at']);
    $time_ago = al_time_ago($log['created_at']);
    $exact    = date('M j, Y g:i A', strtotime($log['created_at']));
    if ($dh !== $last_date_header):
        $last_date_header = $dh;
?>
<div class="al-date-header"><?php echo htmlspecialchars($dh); ?></div>
<?php endif; ?>

<div class="al-card" style="background:<?php echo $bg; ?>;">
    <div class="al-icon-wrap" style="background:<?php echo $color; ?>22;">
        <span><?php echo $icon; ?></span>
    </div>
    <div class="al-body">
        <div class="al-title"><?php echo htmlspecialchars(al_action_label($action)); ?></div>
        <div class="al-meta">
            <span>by <strong><?php echo htmlspecialchars($log['actor_name']); ?></strong></span>
            <?php echo al_role_badge($log['actor_role']); ?>
            <?php if ($log['target_type'] && $log['target_id']): ?>
            <span style="color:#9ca3af;">· <?php echo htmlspecialchars(ucfirst($log['target_type'])); ?> #<?php echo intval($log['target_id']); ?></span>
            <?php endif; ?>
        </div>
        <div class="al-desc"><?php echo htmlspecialchars($log['description']); ?></div>
    </div>
    <div class="al-time" title="<?php echo htmlspecialchars($exact); ?>"><?php echo $time_ago; ?></div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="al-pagination">
<?php
$qs = http_build_query(array_filter([
    'actor'     => $filter_actor ?: null,
    'action'    => $filter_action ?: null,
    'date_from' => $filter_from,
    'date_to'   => $filter_to,
]));
$base = 'activity.php?' . ($qs ? $qs . '&' : '');

for ($i = 1; $i <= $total_pages; $i++):
    if ($i === $page):
        echo "<span class=\"cur\">{$i}</span>";
    elseif ($i === 1 || $i === $total_pages || abs($i - $page) <= 2):
        echo "<a href=\"{$base}p={$i}\">{$i}</a>";
    elseif (abs($i - $page) === 3):
        echo "<span class=\"dots\">…</span>";
    endif;
endfor;
?>
</div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
