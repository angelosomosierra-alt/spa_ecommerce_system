<?php
/**
 * Slotting and Rotation — live resource grid (time × resource), rendered as
 * a real continuous timeline (minute-accurate blocks), not hour-rounded
 * table rows. Single source of truth for both embed points (admin/index.php
 * dashboard and admin/therapists.php "Today's Rotation") and for the AJAX
 * refresh endpoint (admin/resource_grid_ajax.php) — none of them may
 * duplicate this query or markup, they only call render_resource_grid_html($conn).
 *
 * Self-heals the same idempotent schema as admin/resources.php / admin/walkin.php
 * so this include is safe to drop into any admin page on its own.
 */

// Timeline window, 9:00 AM through 11:00 PM–12:00 MN, today only.
if (!defined('RESOURCE_GRID_START_HOUR')) define('RESOURCE_GRID_START_HOUR', 9);
if (!defined('RESOURCE_GRID_END_HOUR'))   define('RESOURCE_GRID_END_HOUR', 24); // exclusive
// Pixels per minute of the timeline. 2px/min = 120px/hour, so a 30-min
// appointment is a readable 60px-tall block; a full day is 1800px tall,
// shown inside a vertically-scrolling viewport (see $viewport_max_h below).
if (!defined('RESOURCE_GRID_PX_PER_MIN')) define('RESOURCE_GRID_PX_PER_MIN', 2);

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

    $type_labels = ['room' => 'Room', 'chair' => 'Chair', 'head_spa' => 'Head Spa'];

    $resources = $conn->query("
        SELECT * FROM service_resources
        WHERE is_active = 1
        ORDER BY FIELD(type,'room','chair','head_spa'), sort_order, name
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($resources)) {
        return '<div class="panel"><div class="panel-header"><span class="panel-title">Live Resource Grid</span></div>'
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

    $px_per_min   = RESOURCE_GRID_PX_PER_MIN;
    $window_start = strtotime(date('Y-m-d') . ' ' . RESOURCE_GRID_START_HOUR . ':00:00');
    $window_mins  = (RESOURCE_GRID_END_HOUR - RESOURCE_GRID_START_HOUR) * 60;
    $total_height = $window_mins * $px_per_min;

    // occupancy[resource_id] = flat list of blocks -- no per-hour slot to
    // collide over, so any number of back-to-back bookings just coexist.
    $occupancy = [];
    foreach ($resources as $r) $occupancy[$r['id']] = [];

    foreach ($occ_rows as $o) {
        $rid = (int)$o['resource_id'];
        if (!isset($occupancy[$rid])) continue; // resource deactivated since booking — skip, don't crash

        $start_ts = strtotime($o['appointment_date']);
        $end_ts   = $start_ts + ((int)$o['eff_duration'] * 60);

        $display_start = max($start_ts, $window_start);
        $display_end   = min($end_ts, $window_start + $window_mins * 60);
        if ($display_start >= $display_end) continue; // entirely outside today's display window

        $top_px    = (int)round((($display_start - $window_start) / 60) * $px_per_min);
        $height_px = max(18, (int)round((($display_end - $display_start) / 60) * $px_per_min));

        $occupancy[$rid][] = [
            'top'        => $top_px,
            'height'     => $height_px,
            'therapist'  => $o['therapist_names'] ?: 'Unassigned',
            'service'    => $o['service_name'],
            'status'     => $o['status'],
            'time_label' => date('g:i A', $start_ts) . ' – ' . date('g:i A', $end_ts),
        ];
    }

    $status_colors = [
        'pending'  => ['#FEF3C7', '#92400E'],
        'assigned' => ['#cfe2ff', '#084298'],
        'approved' => ['#D1FAE5', '#065F46'],
    ];

    // "Now" line — only meaningful within today's displayed window.
    $now_ts  = time();
    $now_top = null;
    if ($now_ts >= $window_start && $now_ts < $window_start + $window_mins * 60) {
        $now_top = (int)round((($now_ts - $window_start) / 60) * $px_per_min);
    }

    $viewport_max_h = 640; // scrollable viewport; full day is $total_height tall

    ob_start();
    ?>
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Live Resource Grid — <?php echo date('F j, Y'); ?></span>
        </div>
        <div style="overflow-x:auto;">
            <div style="min-width:<?php echo 80 + count($resources) * 150; ?>px;">
                <!-- Column headers -->
                <div style="display:flex;border-bottom:2px solid var(--border2);">
                    <div style="width:80px;flex-shrink:0;"></div>
                    <?php foreach ($resources as $r): ?>
                    <div style="flex:1;min-width:150px;text-align:center;padding:0.5rem 0.3rem;font-size:0.78rem;font-weight:700;color:var(--brown);">
                        <?php echo $type_labels[$r['type']] ?? ucfirst($r['type']); ?><br>
                        <span style="font-weight:400;font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($r['name']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Timeline: vertically scrollable, minute-accurate blocks -->
                <div id="resourceTimelineScroll"
                     data-start-hour="<?php echo RESOURCE_GRID_START_HOUR; ?>"
                     data-px-per-min="<?php echo $px_per_min; ?>"
                     style="max-height:<?php echo $viewport_max_h; ?>px;overflow-y:auto;position:relative;">
                    <div style="display:flex;position:relative;height:<?php echo $total_height; ?>px;">

                        <!-- Time axis -->
                        <div style="width:80px;flex-shrink:0;position:relative;">
                            <?php for ($h = RESOURCE_GRID_START_HOUR; $h < RESOURCE_GRID_END_HOUR; $h++):
                                $label_top = ($h - RESOURCE_GRID_START_HOUR) * 60 * $px_per_min;
                            ?>
                            <div style="position:absolute;top:<?php echo $label_top - 6; ?>px;left:0.4rem;font-size:0.7rem;color:var(--gray);font-weight:600;white-space:nowrap;">
                                <?php echo date('g:i A', strtotime(date('Y-m-d') . " {$h}:00:00")); ?>
                            </div>
                            <?php endfor; ?>
                        </div>

                        <!-- Resource columns -->
                        <?php foreach ($resources as $r):
                            $hour_px = 60 * $px_per_min;
                        ?>
                        <div style="flex:1;min-width:150px;position:relative;border-left:1px solid var(--border2);
                                    background-image:repeating-linear-gradient(to bottom, transparent, transparent <?php echo $hour_px - 1; ?>px, var(--border2) <?php echo $hour_px - 1; ?>px, var(--border2) <?php echo $hour_px; ?>px);">
                            <?php foreach ($occupancy[$r['id']] as $cell):
                                [$cbg, $cfg] = $status_colors[$cell['status']] ?? ['#e2e3e5', '#41464b']; ?>
                            <div style="position:absolute;top:<?php echo $cell['top']; ?>px;height:<?php echo $cell['height']; ?>px;left:4px;right:4px;
                                        background:<?php echo $cbg; ?>;color:<?php echo $cfg; ?>;border-left:3px solid <?php echo $cfg; ?>;
                                        border-radius:6px;padding:0.3rem 0.5rem;font-size:0.72rem;overflow:hidden;box-sizing:border-box;">
                                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($cell['therapist']); ?></div>
                                <div style="font-size:0.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($cell['service']); ?></div>
                                <div style="font-size:0.62rem;opacity:0.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo $cell['time_label']; ?> · <?php echo ucfirst($cell['status']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($now_top !== null): ?>
                        <!-- "Now" line, spanning the full width -->
                        <div style="position:absolute;top:<?php echo $now_top; ?>px;left:80px;right:0;height:0;border-top:2px solid #dc3545;z-index:5;pointer-events:none;">
                            <span style="position:absolute;left:-4px;top:-5px;width:8px;height:8px;border-radius:50%;background:#dc3545;"></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

}
