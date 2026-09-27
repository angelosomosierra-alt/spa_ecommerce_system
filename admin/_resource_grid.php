<?php
/**
 * Slotting and Rotation — live resource grid (time × resource).
 * Single source of truth for both embed points (admin/index.php dashboard and
 * admin/therapists.php "Today's Rotation") and for the AJAX refresh endpoint
 * (admin/resource_grid_ajax.php) — none of them may duplicate this query or
 * markup, they only call render_resource_grid_html($conn).
 *
 * Self-heals the same idempotent schema as admin/resources.php / admin/walkin.php
 * so this include is safe to drop into any admin page on its own.
 */

// Hourly rows, 9:00 AM through 11:00 PM–12:00 MN (the last row), today only.
if (!defined('RESOURCE_GRID_START_HOUR')) define('RESOURCE_GRID_START_HOUR', 9);
if (!defined('RESOURCE_GRID_END_HOUR'))   define('RESOURCE_GRID_END_HOUR', 24); // exclusive — last row covers 23:00–24:00

if (!function_exists('render_resource_grid_html')) {

function _resource_grid_self_heal(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS service_resources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        type ENUM('room','chair','head_spa') NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS resource_id INT NULL AFTER service_duration_id");
    $fk_chk = $conn->query("SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND CONSTRAINT_NAME='fk_appointments_resource'")->fetch_assoc()['c'];
    if ($fk_chk == 0) {
        $conn->query("ALTER TABLE appointments ADD CONSTRAINT fk_appointments_resource FOREIGN KEY (resource_id) REFERENCES service_resources(id) ON DELETE SET NULL");
    }
}

function render_resource_grid_html(mysqli $conn): string {
    _resource_grid_self_heal($conn);

    $type_labels = ['room' => '🚪 Room', 'chair' => '💺 Chair', 'head_spa' => '🧖 Head Spa'];

    $resources = $conn->query("
        SELECT * FROM service_resources
        WHERE is_active = 1
        ORDER BY FIELD(type,'room','chair','head_spa'), sort_order, name
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($resources)) {
        return '<div class="panel"><div class="panel-header"><span class="panel-title">🛎️ Live Resource Grid</span></div>'
             . '<div style="padding:1.5rem;text-align:center;color:var(--gray);font-size:0.85rem;">'
             . 'No active resources configured yet. Add some in <a href="resources.php">Resources</a>.</div></div>';
    }

    $statuses = "'" . implode("','", RESOURCE_OCCUPYING_STATUSES) . "'";
    $occ_rows = $conn->query("
        SELECT a.id, a.resource_id, a.appointment_date,
               COALESCE(a.duration_minutes, s.session_time, 60) AS eff_duration,
               s.name AS service_name,
               a.status,
               GROUP_CONCAT(DISTINCT t.full_name SEPARATOR ', ') AS therapist_names
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
        LEFT JOIN therapists t ON t.id = at2.therapist_id
        WHERE a.resource_id IS NOT NULL
          AND a.status IN ($statuses)
          AND DATE(a.appointment_date) = CURDATE()
        GROUP BY a.id
    ")->fetch_all(MYSQLI_ASSOC);

    $today9am = strtotime(date('Y-m-d') . ' ' . RESOURCE_GRID_START_HOUR . ':00:00');
    $num_rows = RESOURCE_GRID_END_HOUR - RESOURCE_GRID_START_HOUR;

    // occupancy[resource_id][row_index] = null | 'skip' | ['span'=>n, ...cell data]
    $occupancy = [];
    foreach ($resources as $r) $occupancy[$r['id']] = array_fill(0, $num_rows, null);

    foreach ($occ_rows as $o) {
        $rid = (int)$o['resource_id'];
        if (!isset($occupancy[$rid])) continue; // resource deactivated since booking — skip, don't crash

        $start_ts = strtotime($o['appointment_date']);
        $end_ts   = $start_ts + ((int)$o['eff_duration'] * 60);

        $display_start = max($start_ts, $today9am);
        $display_end   = min($end_ts, $today9am + $num_rows * 3600);
        if ($display_start >= $display_end) continue; // entirely outside today's 9AM–12MN window

        $start_row = (int)floor(($display_start - $today9am) / 3600);
        $end_row   = (int)ceil(($display_end - $today9am) / 3600) - 1;
        $span      = $end_row - $start_row + 1;
        if ($span < 1) continue;

        $label_time = date('g:i A', $start_ts) . ' – ' . date('g:i A', $end_ts);
        $occupancy[$rid][$start_row] = [
            'span'       => $span,
            'therapist'  => $o['therapist_names'] ?: 'Unassigned',
            'service'    => $o['service_name'],
            'status'     => $o['status'],
            'time_label' => $label_time,
        ];
        for ($i = $start_row + 1; $i <= $end_row; $i++) $occupancy[$rid][$i] = 'skip';
    }

    $status_colors = [
        'pending'  => ['#FEF3C7', '#92400E'],
        'assigned' => ['#cfe2ff', '#084298'],
        'approved' => ['#D1FAE5', '#065F46'],
    ];

    ob_start();
    ?>
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🛎️ Live Resource Grid — <?php echo date('F j, Y'); ?></span>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0;overflow-x:auto;">
            <table style="min-width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="min-width:80px;">Time</th>
                        <?php foreach ($resources as $r): ?>
                        <th style="min-width:110px;"><?php echo $type_labels[$r['type']] ?? ucfirst($r['type']); ?><br>
                            <span style="font-weight:400;font-size:0.75rem;color:var(--gray);"><?php echo htmlspecialchars($r['name']); ?></span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($row = 0; $row < $num_rows; $row++):
                        $hour = RESOURCE_GRID_START_HOUR + $row;
                        $row_label = date('g:i A', strtotime(date('Y-m-d') . " {$hour}:00:00"));
                    ?>
                    <tr>
                        <td style="font-size:0.78rem;color:var(--gray);white-space:nowrap;"><?php echo $row_label; ?></td>
                        <?php foreach ($resources as $r):
                            $cell = $occupancy[$r['id']][$row];
                            if ($cell === 'skip') continue; // covered by a rowspan from an earlier row
                            if ($cell === null): ?>
                        <td style="background:var(--bg3);text-align:center;color:var(--gray);font-size:0.75rem;padding:0.5rem;">Open</td>
                            <?php else:
                                [$cbg, $cfg] = $status_colors[$cell['status']] ?? ['#e2e3e5', '#41464b']; ?>
                        <td rowspan="<?php echo $cell['span']; ?>" style="background:<?php echo $cbg; ?>;color:<?php echo $cfg; ?>;padding:0.5rem 0.6rem;font-size:0.78rem;vertical-align:top;border-left:3px solid <?php echo $cfg; ?>;">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($cell['therapist']); ?></div>
                            <div style="font-size:0.72rem;"><?php echo htmlspecialchars($cell['service']); ?></div>
                            <div style="font-size:0.68rem;opacity:0.8;"><?php echo $cell['time_label']; ?> · <?php echo ucfirst($cell['status']); ?></div>
                        </td>
                            <?php endif;
                        endforeach; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

}
