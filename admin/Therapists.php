<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$message = ''; $message_type = '';

// ── CHECK IN EXISTING THERAPIST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_today'])) {
    verify_csrf_token();
    $time_in = sanitize_input($_POST['time_in'] ?? date('H:i'));
    $tid     = intval($_POST['existing_therapist_id'] ?? 0);
    if ($tid <= 0) {
        $message = "Please select a therapist."; $message_type = "danger";
    } else {
        $chk = $conn->prepare("SELECT id, full_name FROM therapists WHERE id=?");
        $chk->bind_param("i", $tid); $chk->execute();
        $row = $chk->get_result()->fetch_assoc(); $chk->close();
        if (!$row) {
            $message = "Therapist not found."; $message_type = "danger";
        } else {
            $rot  = $conn->query("SELECT IFNULL(MAX(rotation_order),0)+1 AS next_order FROM therapist_attendance WHERE duty_date=CURDATE()")->fetch_assoc()['next_order'];
            $date = date('Y-m-d');
            $stmt = $conn->prepare("INSERT INTO therapist_attendance (therapist_id, duty_date, time_in, rotation_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in)");
            $stmt->bind_param("issi", $tid, $date, $time_in, $rot);
            $ok = $stmt->execute(); $stmt->close();
            $message      = $ok ? "✅ {$row['full_name']} checked in." : "Error checking in.";
            $message_type = $ok ? "success" : "danger";
        }
    }
}

// ── REORDER ROTATION ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    verify_csrf_token();
    $ids = $_POST['rotation_ids'] ?? [];
    foreach ($ids as $order => $aid) {
        $aid   = intval($aid);
        $order = intval($order) + 1;
        if ($aid <= 0) continue;
        $stmt = $conn->prepare("UPDATE therapist_attendance SET rotation_order=? WHERE id=? AND duty_date=CURDATE()");
        $stmt->bind_param("ii", $order, $aid); $stmt->execute(); $stmt->close();
    }
    header("Location: therapists.php?reordered=1"); exit();
}

if (isset($_GET['reordered'])) {
    $message = "✅ Rotation order saved."; $message_type = "success";
}

// ── CHECK OUT (GET — avoids nested form problem) ──────────────────────────────
if (isset($_GET['check_out'])) {
    $aid     = intval($_GET['check_out']);
    $timeout = date('H:i:s');
    $stmt    = $conn->prepare("UPDATE therapist_attendance SET time_out=? WHERE id=? AND duty_date=CURDATE()");
    $stmt->bind_param("si", $timeout, $aid); $stmt->execute(); $stmt->close();
    header("Location: therapists.php"); exit();
}

// ── TOGGLE BREAK ─────────────────────────────────────────────────────────────
if (isset($_GET['toggle_break'])) {
    $aid  = intval($_GET['toggle_break']);
    $stmt = $conn->prepare("UPDATE therapist_attendance SET is_on_break = !is_on_break WHERE id=? AND duty_date=CURDATE()");
    $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
    header("Location: therapists.php"); exit();
}

// ── CHECK OUT ────────────────────────────────────────────────────────────────


// ── REMOVE FROM TODAY ─────────────────────────────────────────────────────────
if (isset($_GET['remove_today'])) {
    $aid  = intval($_GET['remove_today']);
    $stmt = $conn->prepare("DELETE FROM therapist_attendance WHERE id=? AND duty_date=CURDATE()");
    $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
    header("Location: therapists.php"); exit();
}

// ── FETCH TODAY'S ROSTER ─────────────────────────────────────────────────────
// Uses appointment_therapists junction table (not the old therapist_id column)
$today_roster = $conn->query("
    SELECT
        ta.*,
        t.full_name,
        t.phone,
        t.specialties,

        -- Total active appointments today via junction table
        (SELECT COUNT(*)
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status NOT IN ('declined','completed')
        ) AS today_appts,

        -- is_assigned: currently handling an approved/assigned appointment
        (SELECT COUNT(*)
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
        ) AS is_assigned,

        -- Current appointment: service name
        (SELECT s.name
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         JOIN services s ON ap.service_id = s.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
         ORDER BY ap.appointment_date ASC
         LIMIT 1
        ) AS current_service,

        -- Current appointment: scheduled time
        (SELECT ap.appointment_date
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
         ORDER BY ap.appointment_date ASC
         LIMIT 1
        ) AS current_appt_time,

        -- Current appointment: customer name
        (SELECT u.username
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         JOIN users u ON ap.user_id = u.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
         ORDER BY ap.appointment_date ASC
         LIMIT 1
        ) AS current_customer,

        -- Current appointment: people count
        (SELECT ap.people_count
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
         ORDER BY ap.appointment_date ASC
         LIMIT 1
        ) AS current_people,

        -- Current appointment ID (for link)
        (SELECT ap.id
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = CURDATE()
           AND ap.status IN ('approved','assigned')
         ORDER BY ap.appointment_date ASC
         LIMIT 1
        ) AS current_appt_id,

        -- TODAY'S COMMISSION: sum from appointment_therapists (set in appointments.php after completion)
        (SELECT IFNULL(SUM(at2.commission), 0)
         FROM appointment_therapists at2
         JOIN appointments ap ON at2.appointment_id = ap.id
         WHERE at2.therapist_id = t.id
           AND DATE(ap.appointment_date) = ta.duty_date
           AND ap.status = 'completed'
        ) AS today_commission

    FROM therapist_attendance ta
    JOIN therapists t ON ta.therapist_id = t.id
    WHERE ta.duty_date = CURDATE()
    ORDER BY ta.rotation_order ASC, ta.time_in ASC
")->fetch_all(MYSQLI_ASSOC);

// ── STATS ─────────────────────────────────────────────────────────────────────
$on_duty     = count($today_roster);
$on_break    = count(array_filter($today_roster, fn($r) => $r['is_on_break']));
$serving_now = count(array_filter($today_roster, fn($r) => $r['is_assigned'] > 0));
$available   = count(array_filter($today_roster, fn($r) => !$r['is_on_break'] && !$r['is_assigned'] && empty($r['time_out'])));
$total_comm  = array_sum(array_column($today_roster, 'today_commission'));

// Next in rotation: first available (not on break, not assigned, not checked out)
$next = null;
foreach ($today_roster as $r) {
    if (!$r['is_on_break'] && !$r['is_assigned'] && empty($r['time_out'])) {
        $next = $r; break;
    }
}

// ── Fetch all therapists for the check-in dropdown ───────────────────────────
$all_therapists = $conn->query("
    SELECT t.id, t.full_name, t.specialties,
           (SELECT AVG(tr.rating) FROM therapist_ratings tr WHERE tr.therapist_id = t.id) AS avg_rating,
           (SELECT COUNT(*) FROM therapist_ratings tr WHERE tr.therapist_id = t.id) AS total_ratings,
           EXISTS(SELECT 1 FROM therapist_attendance ta WHERE ta.therapist_id=t.id AND ta.duty_date=CURDATE()) AS on_duty_today
    FROM therapists t
    ORDER BY t.full_name ASC
")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Therapists'; $page_icon = '💆'; $active_page = 'therapists';

// ── Pre-load specialties for all therapists on today's roster ─────────────────
$roster_ids      = array_column($today_roster, 'therapist_id');
$specialties_map = [];
if (!empty($roster_ids)) {
    $ids_in    = implode(',', array_map('intval', $roster_ids));
    $sp_result = $conn->query("
        SELECT ts.therapist_id, c.name AS category_name
        FROM therapist_specialties ts
        JOIN categories c ON ts.category_id = c.id
        WHERE ts.therapist_id IN ($ids_in)
        ORDER BY c.name ASC
    ");
    while ($sp_row = $sp_result->fetch_assoc()) {
        $specialties_map[$sp_row['therapist_id']][] = $sp_row['category_name'];
    }
}

// ── THERAPIST HISTORY ─────────────────────────────────────────────────────────
$history_therapist = null;
$history_records   = [];
if (isset($_GET['history'])) {
    $hist_id = intval($_GET['history']);
    $stmt = $conn->prepare("SELECT id, full_name, phone, specialties FROM therapists WHERE id=?");
    $stmt->bind_param("i", $hist_id); $stmt->execute();
    $history_therapist = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($history_therapist) {
        $stmt = $conn->prepare("
            SELECT
                ap.id            AS appt_id,
                ap.appointment_date,
                ap.status,
                ap.people_count,
                ap.service_type,
                s.name           AS service_name,
                s.session_time,
                at2.commission,
                at2.notes        AS therapist_notes,
                u.full_name      AS customer_name,
                tr.rating,
                tr.comment       AS feedback_comment
            FROM appointment_therapists at2
            JOIN appointments ap ON at2.appointment_id = ap.id
            JOIN services     s  ON ap.service_id      = s.id
            JOIN users        u  ON ap.user_id          = u.id
            LEFT JOIN therapist_ratings tr
                ON tr.therapist_id   = at2.therapist_id
               AND tr.appointment_id = ap.id
            WHERE at2.therapist_id = ?
            ORDER BY ap.appointment_date DESC
        ");
        $stmt->bind_param("i", $hist_id); $stmt->execute();
        $history_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($history_therapist):
    $total_sessions    = count($history_records);
    $completed_records = array_filter($history_records, fn($r) => $r['status'] === 'completed');
    $total_earned      = array_sum(array_column(iterator_to_array($completed_records), 'commission'));

    // Lifetime average rating from therapist_ratings table
    $avg_stmt = $conn->prepare("SELECT AVG(rating) AS avg_r, COUNT(*) AS total_r FROM therapist_ratings WHERE therapist_id=?");
    $avg_stmt->bind_param("i", $history_therapist['id']); $avg_stmt->execute();
    $avg_row   = $avg_stmt->get_result()->fetch_assoc(); $avg_stmt->close();
    $avg_rating   = $avg_row['avg_r'] ? floatval($avg_row['avg_r']) : null;
    $total_ratings = (int)$avg_row['total_r'];
?>
<div style="margin-bottom:1rem;">
    <a href="therapists.php" class="btn btn-secondary btn-sm">← Back to Roster</a>
</div>

<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-body" style="padding:1.25rem;">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div style="width:54px;height:54px;border-radius:50%;flex-shrink:0;
                        background:linear-gradient(135deg,var(--gold),var(--rust));
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.3rem;font-weight:700;color:#fff;">
                <?php echo strtoupper(substr($history_therapist['full_name'],0,1)); ?>
            </div>
            <div style="flex:1;">
                <div style="font-size:1.1rem;font-weight:700;color:var(--brown);">
                    <?php echo htmlspecialchars($history_therapist['full_name']); ?>
                </div>
                <?php if ($history_therapist['phone']): ?>
                <div style="font-size:0.78rem;color:var(--gray);">📞 <?php echo htmlspecialchars($history_therapist['phone']); ?></div>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:2rem;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:1.4rem;font-weight:700;color:var(--gold);"><?php echo $total_sessions; ?></div>
                    <div style="font-size:0.72rem;color:var(--gray);">Total Sessions</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.4rem;font-weight:700;color:#2d8a4e;">₱<?php echo number_format($total_earned,2); ?></div>
                    <div style="font-size:0.72rem;color:var(--gray);">Total Earned</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.4rem;font-weight:700;color:#f59e0b;">
                        <?php echo $avg_rating !== null ? number_format($avg_rating,1).' ★' : '—'; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);">
                        Avg Rating<?php echo $total_ratings > 0 ? ' ('.$total_ratings.' reviews)' : ''; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><span class="panel-title">📋 Session History (<?php echo $total_sessions; ?>)</span></div>
    <?php if (empty($history_records)): ?>
    <div class="panel-body" style="text-align:center;padding:2.5rem;color:var(--gray);">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📭</div>No sessions recorded yet.
    </div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Date & Time</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Commission</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history_records as $rec):
                $sc = ['completed'=>['#d1e7dd','#0a3622'],'assigned'=>['#cfe2ff','#084298'],
                       'declined'=>['#f8d7da','#842029'],'pending'=>['#fff3cd','#664d03']];
                [$sbg,$sfg] = $sc[$rec['status']] ?? ['#e2e3e5','#41464b'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:600;color:var(--brown);"><?php echo htmlspecialchars($rec['service_name']); ?></div>
                    <?php if ($rec['therapist_notes']): ?>
                    <div style="font-size:0.72rem;color:var(--gray);">📝 <?php echo htmlspecialchars($rec['therapist_notes']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <div style="color:var(--brown);"><?php echo date('M d, Y', strtotime($rec['appointment_date'])); ?></div>
                    <div style="font-size:0.75rem;color:var(--gray);"><?php echo date('h:i A', strtotime($rec['appointment_date'])); ?></div>
                </td>
                <td style="color:var(--brown);"><?php echo htmlspecialchars($rec['customer_name']); ?></td>
                <td style="font-size:0.8rem;"><?php echo $rec['service_type']==='home'?'🏠 Home':'🏢 On-site'; ?></td>
                <td style="color:var(--gray);font-size:0.85rem;"><?php echo $rec['session_time']; ?> min</td>
                <td>
                    <span style="background:<?php echo $sbg; ?>;color:<?php echo $sfg; ?>;
                                 padding:0.2rem 0.65rem;border-radius:20px;font-size:0.75rem;font-weight:600;">
                        <?php echo ucfirst($rec['status']); ?>
                    </span>
                </td>
                <td>
                    <?php if ($rec['commission'] > 0): ?>
                    <span style="color:#2d8a4e;font-weight:700;">₱<?php echo number_format($rec['commission'],2); ?></span>
                    <?php else: ?>
                    <span style="color:var(--gray);">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($rec['rating']): ?>
                    <div><?php for($s=1;$s<=5;$s++) echo '<span style="color:'.($s<=$rec['rating']?'#f59e0b':'#ccc').';">★</span>'; ?></div>
                    <?php if ($rec['feedback_comment']): ?>
                    <div style="font-size:0.7rem;color:var(--gray);max-width:150px;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                         title="<?php echo htmlspecialchars($rec['feedback_comment']); ?>">
                        "<?php echo htmlspecialchars($rec['feedback_comment']); ?>"
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:var(--gray);font-size:0.8rem;">No rating</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; exit(); endif; ?>

<!-- ── STATS ──────────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.5rem;">
    <div class="stat-card green">
        <div class="stat-icon">🟢</div>
        <div class="stat-number"><?php echo $on_duty; ?></div>
        <div class="stat-label">On Duty</div>
    </div>
    <div class="stat-card" style="border-top-color:var(--green);">
        <div class="stat-icon">✅</div>
        <div class="stat-number" style="color:var(--green);"><?php echo $available; ?></div>
        <div class="stat-label">Available</div>
    </div>
    <div class="stat-card rust">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo $serving_now; ?></div>
        <div class="stat-label">Serving Now</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">☕</div>
        <div class="stat-number"><?php echo $on_break; ?></div>
        <div class="stat-label">On Break</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-number">₱<?php echo number_format($total_comm,2); ?></div>
        <div class="stat-label">Today's Commission</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

<!-- ── TODAY'S ROTATION ROSTER ───────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🔄 Today's Rotation — <?php echo date('F d, Y (l)'); ?></span>
        <small style="color:var(--gray);font-size:0.73rem;">Drag to reorder · Resets daily</small>
    </div>
    <div class="panel-body" style="padding:1rem;">

        <?php if (empty($today_roster)): ?>
        <div style="text-align:center;padding:2rem;color:var(--gray);">
            <div style="font-size:2.5rem;margin-bottom:0.5rem;">📋</div>
            <p>No therapists added for today yet.</p>
        </div>

        <?php else: ?>
        <div style="background:rgba(201,106,44,0.08);border:1px solid rgba(201,106,44,0.2);
                    border-radius:8px;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.78rem;color:var(--cream2);">
            🔄 <strong style="color:var(--gold);">Rotation queue</strong> —
            the next available therapist (not serving, not on break) is at the top.
            Drag cards to reorder.
        </div>

        <form method="POST" id="rotationForm">
        <?php echo csrf_field(); ?>
        <div id="rotationList">
        <?php foreach ($today_roster as $i => $r):

            // Determine status using junction table data
            if (!empty($r['time_out'])) {
                $status      = 'done';
                $statusLabel = '🏁 Done';
                $statusColor = 'var(--amber)';
                $statusBg    = 'var(--amber-dim)';
                $cardBorder  = 'var(--border2)';
            } elseif ($r['is_on_break']) {
                $status      = 'break';
                $statusLabel = '☕ On Break';
                $statusColor = '#0070f3';
                $statusBg    = '#cfe2ff';
                $cardBorder  = '#0070f3';
            } elseif ($r['is_assigned']) {
                $status      = 'on_service';
                $statusLabel = '🔴 On Service';
                $statusColor = '#fff';
                $statusBg    = '#dc3545';
                $cardBorder  = '#dc3545';
            } else {
                $status      = 'available';
                $statusLabel = '✅ Available';
                $statusColor = 'var(--green)';
                $statusBg    = 'var(--green-dim)';
                $cardBorder  = 'var(--green)';
            }

            // Highlight the very first available therapist (next in rotation)
            $isNext = ($next && $r['id'] === $next['id']);
        ?>
        <div class="rotation-card" data-id="<?php echo $r['id']; ?>"
             style="border:1px solid <?php echo $cardBorder; ?>;
                    border-radius:10px;padding:0.85rem;margin-bottom:0.6rem;
                    background:<?php echo $isNext ? 'rgba(25,135,84,0.06)' : 'var(--bg3)'; ?>;
                    cursor:grab;
                    <?php echo $status==='done' ? 'opacity:0.55;' : ''; ?>">

            <div style="display:flex;align-items:center;gap:0.75rem;">

                <!-- Queue number -->
                <div data-queue-num
                     style="width:28px;height:28px;border-radius:50%;
                            background:<?php echo $isNext ? 'var(--green)' : 'var(--brown)'; ?>;
                            display:flex;align-items:center;justify-content:center;
                            font-size:0.78rem;font-weight:700;
                            color:<?php echo $isNext ? '#fff' : 'var(--gold)'; ?>;
                            flex-shrink:0;">
                    <?php echo $i + 1; ?>
                </div>

                <!-- Avatar -->
                <div style="width:36px;height:36px;border-radius:50%;
                            background:linear-gradient(135deg,var(--gold),var(--rust));
                            display:flex;align-items:center;justify-content:center;
                            font-size:0.9rem;font-weight:700;color:#fff;flex-shrink:0;">
                    <?php echo strtoupper(substr($r['full_name'], 0, 1)); ?>
                </div>

                <!-- Name + meta -->
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        <span style="font-weight:700;color:var(--brown);font-size:0.9rem;
                                     white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($r['full_name']); ?>
                        </span>
                        <?php if ($isNext): ?>
                        <span style="font-size:0.65rem;background:var(--green);color:#fff;
                                     padding:0.1rem 0.45rem;border-radius:20px;font-weight:700;
                                     white-space:nowrap;flex-shrink:0;">
                            NEXT
                        </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.7rem;color:var(--gray);margin-top:0.1rem;">
                        <?php echo htmlspecialchars($r['specialties'] ?: 'General'); ?>
                        &nbsp;·&nbsp; IN <?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?>
                        <?php if ($r['today_appts'] > 0): ?>
                        &nbsp;·&nbsp; <span style="color:var(--rust);"><?php echo $r['today_appts']; ?> appt<?php echo $r['today_appts']>1?'s':''; ?> today</span>
                        <?php endif; ?>
                    </div>

                    <!-- ── Specialty category tags ─────────────────────── -->
                    <?php $therapist_cats = $specialties_map[$r['therapist_id']] ?? []; ?>
                    <?php if (!empty($therapist_cats)): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:0.3rem;margin-top:0.4rem;">
                        <?php foreach ($therapist_cats as $cat_name): ?>
                        <span style="background:rgba(201,106,44,0.15);color:var(--brown);
                                     border:1px solid rgba(201,106,44,0.35);
                                     padding:0.1rem 0.5rem;border-radius:20px;
                                     font-size:0.67rem;font-weight:600;white-space:nowrap;">
                            <?php echo htmlspecialchars($cat_name); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (!empty($r['specialties'])): ?>
                    <div style="margin-top:0.3rem;">
                        <span style="background:rgba(100,100,100,0.1);color:var(--gray);
                                     padding:0.1rem 0.5rem;border-radius:20px;font-size:0.67rem;">
                            <?php echo htmlspecialchars($r['specialties']); ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Current assignment detail — only when assigned -->
                    <?php if ($status === 'assigned' && $r['current_service']): ?>
                    <div style="margin-top:0.35rem;display:inline-flex;align-items:center;gap:0.4rem;
                                font-size:0.7rem;background:rgba(169,79,29,0.12);
                                border:1px solid rgba(169,79,29,0.25);
                                border-radius:6px;padding:0.2rem 0.55rem;color:var(--rust);">
                        <span>📋 <?php echo htmlspecialchars($r['current_service']); ?></span>
                        <span style="opacity:0.6;">·</span>
                        <span><?php echo date('h:i A', strtotime($r['current_appt_time'])); ?></span>
                        <span style="opacity:0.6;">·</span>
                        <span><?php echo htmlspecialchars($r['current_customer']); ?></span>
                        <?php if ($r['current_people'] > 1): ?>
                        <span style="opacity:0.6;">·</span>
                        <span><?php echo $r['current_people']; ?> pax</span>
                        <?php endif; ?>
                        <?php if ($r['current_appt_id']): ?>
                        <a href="admin_appointments.php?highlight=<?php echo $r['current_appt_id']; ?>"
                           style="color:var(--gold);text-decoration:none;font-weight:700;" title="View appointment">↗</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Status badge -->
                <span style="background:<?php echo $statusBg; ?>;color:<?php echo $statusColor; ?>;
                             font-size:0.72rem;font-weight:700;padding:0.25rem 0.6rem;
                             border-radius:20px;flex-shrink:0;white-space:nowrap;">
                    <?php echo $statusLabel; ?>
                </span>

                <!-- Actions -->
                <div style="display:flex;gap:0.3rem;flex-shrink:0;">
                    <a href="therapists.php?history=<?php echo $r['therapist_id']; ?>"
                       class="btn btn-secondary btn-sm"
                       style="font-size:0.72rem;padding:0.25rem 0.5rem;"
                       title="View history">📋</a>
                    <a href="therapists.php?toggle_break=<?php echo $r['id']; ?>"
                       class="btn btn-sm <?php echo $r['is_on_break'] ? 'btn-primary' : 'btn-secondary'; ?>"
                       style="font-size:0.72rem;padding:0.25rem 0.5rem;"
                       title="<?php echo $r['is_on_break'] ? 'End break' : 'Set on break'; ?>">
                        ☕
                    </a>
                    <a href="therapists.php?remove_today=<?php echo $r['id']; ?>"
                       class="btn btn-danger btn-sm" style="font-size:0.72rem;padding:0.25rem 0.5rem;"
                       onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($r['full_name'])); ?> from today?')">
                        ✕
                    </a>
                </div>
            </div>

            <!-- Checkout row only — commission is now set in Appointments after completion -->
            <div style="display:flex;gap:0.5rem;align-items:center;margin-top:0.65rem;
                        padding-top:0.65rem;border-top:1px solid var(--border2);flex-wrap:wrap;">
                <?php if (empty($r['time_out'])): ?>
                <a href="therapists.php?check_out=<?php echo $r['id']; ?>"
                   class="btn btn-secondary btn-sm" style="font-size:0.75rem;"
                   onclick="return confirm('Check out <?php echo htmlspecialchars(addslashes($r['full_name'])); ?>?')">
                    🏁 Out
                </a>
                <?php else: ?>
                <span style="font-size:0.72rem;color:var(--gray);">
                    🏁 Checked out <?php echo date('g:i A', strtotime($r['time_out'])); ?>
                </span>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
        </div>

        <!-- Hidden rotation inputs -->
        <div id="rotationInputs"></div>
        <button type="submit" name="reorder" id="saveRotationBtn"
                class="btn btn-primary btn-sm" style="display:none;margin-top:0.75rem;width:100%;">
            💾 Save New Rotation Order
        </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ── RIGHT COLUMN: Add form + Next in rotation ─────────────────────────── -->
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    <!-- Next in Rotation highlight card -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">⚡ Next in Rotation</span></div>
        <div class="panel-body" style="padding:1rem;">
            <?php if ($next): ?>
            <div style="display:flex;align-items:center;gap:0.75rem;
                        background:rgba(25,135,84,0.07);border:1px solid rgba(25,135,84,0.2);
                        border-radius:10px;padding:0.9rem;">
                <div style="width:44px;height:44px;border-radius:50%;
                            background:linear-gradient(135deg,var(--gold),var(--rust));
                            display:flex;align-items:center;justify-content:center;
                            font-size:1.1rem;font-weight:700;color:#fff;flex-shrink:0;">
                    <?php echo strtoupper(substr($next['full_name'], 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight:700;color:var(--green);font-size:1rem;">
                        <?php echo htmlspecialchars($next['full_name']); ?>
                    </div>
                    <div style="font-size:0.75rem;color:var(--gray);">
                        <?php echo htmlspecialchars($next['specialties'] ?: 'General'); ?>
                        &nbsp;·&nbsp; In since <?php echo $next['time_in'] ? date('g:i A', strtotime($next['time_in'])) : '—'; ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--green);margin-top:0.2rem;font-weight:600;">
                        Ready for next appointment
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:1.25rem;color:var(--gray);font-size:0.85rem;">
                <div style="font-size:1.8rem;margin-bottom:0.4rem;">😔</div>
                No therapist available right now.<br>
                <small>All are serving, on break, or checked out.</small>
            </div>
            <?php endif; ?>

            <!-- Quick availability summary -->
            <?php if (!empty($today_roster)): ?>
            <div style="margin-top:0.85rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                <?php foreach ($today_roster as $r):
                    if (!empty($r['time_out'])) { $dot='#888'; $tip='Checked out'; }
                    elseif ($r['is_on_break'])  { $dot='#f59e0b'; $tip='On break'; }
                    elseif ($r['is_assigned'])  { $dot='#dc3545'; $tip='On Service'; }
                    else                        { $dot='var(--green)'; $tip='Available'; }
                ?>
                <div title="<?php echo htmlspecialchars($r['full_name']); ?> — <?php echo $tip; ?>"
                     style="display:flex;align-items:center;gap:0.3rem;font-size:0.72rem;color:var(--gray);
                            background:var(--bg3);border-radius:20px;padding:0.2rem 0.55rem;
                            border:1px solid var(--border2);">
                    <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $dot; ?>;flex-shrink:0;"></span>
                    <?php echo htmlspecialchars(explode(' ', $r['full_name'])[0]); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Therapist Form -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">➕ Check In Therapist</span></div>
        <div class="panel-body" style="padding:1rem;">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div style="padding:0.55rem 0.85rem;background:rgba(0,112,243,0.07);
                            border:1px solid rgba(0,112,243,0.2);border-radius:8px;
                            font-size:0.78rem;color:#084298;margin-bottom:0.85rem;line-height:1.5;">
                    💡 To add a new therapist go to
                    <a href="staff.php?tab=therapists" style="color:#0070f3;font-weight:700;">Staff → Therapists</a>.
                </div>

                <div style="margin-bottom:0.85rem;">
                    <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:6px;font-weight:600;">
                        Select Therapist <span style="color:var(--rust);">*</span>
                    </label>
                    <select name="existing_therapist_id" required
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--border2);
                                   border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                        <option value="">— Select a therapist —</option>
                        <?php foreach ($all_therapists as $th):
                            $stars = $th['avg_rating'] ? ' · ' . number_format($th['avg_rating'],1) . '★' : '';
                        ?>
                        <option value="<?php echo $th['id']; ?>"
                                <?php echo $th['on_duty_today'] ? 'style="color:var(--green);font-weight:600;"' : ''; ?>>
                            <?php echo htmlspecialchars($th['full_name']) . $stars . ($th['on_duty_today'] ? ' ✓ On duty' : ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom:0.85rem;">
                    <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:3px;font-weight:600;">Time In</label>
                    <input type="time" name="time_in" value="<?php echo date('H:i'); ?>"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                </div>

                <button type="submit" name="add_today" class="btn btn-primary" style="width:100%;">
                    ➕ Check In to Today's Roster
                </button>
            </form>
        </div><!-- end panel-body -->
    </div><!-- end panel -->

</div><!-- end right column -->
</div><!-- end grid -->

<script>
// ── Drag to reorder rotation ──────────────────────────────────────────────────
const list    = document.getElementById('rotationList');
const saveBtn = document.getElementById('saveRotationBtn');
const inputs  = document.getElementById('rotationInputs');
let dragging  = null;

if (list) {
    list.addEventListener('dragstart', e => {
        dragging = e.target.closest('.rotation-card');
        if (dragging) dragging.style.opacity = '0.45';
    });
    list.addEventListener('dragend', e => {
        if (dragging) dragging.style.opacity = '';
        dragging = null;
        updateRotationInputs();
        if (saveBtn) saveBtn.style.display = 'block';
    });
    list.addEventListener('dragover', e => {
        e.preventDefault();
        const card = e.target.closest('.rotation-card');
        if (card && card !== dragging) {
            const rect = card.getBoundingClientRect();
            const mid  = rect.top + rect.height / 2;
            if (e.clientY < mid) list.insertBefore(dragging, card);
            else list.insertBefore(dragging, card.nextSibling);
        }
    });
    document.querySelectorAll('.rotation-card').forEach(card => {
        card.setAttribute('draggable', true);
    });
}

// Populate hidden inputs on page load so Save always works
updateRotationInputs();

function updateRotationInputs() {
    if (!inputs) return;
    inputs.innerHTML = '';
    document.querySelectorAll('.rotation-card').forEach((card, i) => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'rotation_ids[]';
        inp.value = card.dataset.id;
        inputs.appendChild(inp);
        // Update visible queue number
        const num = card.querySelector('[data-queue-num]');
        if (num) num.textContent = i + 1;
    });
}
</script>


<?php require_once 'admin_footer.php'; ?>