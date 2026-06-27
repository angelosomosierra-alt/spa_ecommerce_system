<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$message = ''; $message_type = '';
$appt_id = intval($_GET['appt_id'] ?? 0);

if (!$appt_id) { header("Location: appointments.php"); exit(); }

// ── Load appointment ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT a.*, s.name AS service_name, s.session_time,
           u.username, u.email
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    JOIN users    u ON a.user_id    = u.id
    WHERE a.id = ?
");
$stmt->bind_param("i", $appt_id); $stmt->execute();
$appt = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$appt) { header("Location: appointments.php"); exit(); }

// Only allow assignment on approved or assigned appointments
if (!in_array($appt['status'], ['approved','assigned'])) {
    header("Location: appointments.php?msg=cannot_assign"); exit();
}

$appt_date  = $appt['appointment_date'];              // e.g. "2025-04-10 10:00:00"
$people     = max(1, intval($appt['people_count']));  // how many therapists needed

// ── ASSIGN therapist ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_therapist'])) {
    verify_csrf_token();
    $therapist_id = intval($_POST['therapist_id']);
    $notes        = sanitize_input($_POST['notes'] ?? '');
    $commission   = floatval($_POST['commission'] ?? 0);

    if (!$therapist_id) {
        $message = "Please select a therapist."; $message_type = "danger";
    } else {
        // ── Specialty check ───────────────────────────────────────────────────
        $spc = $conn->prepare("
            SELECT (
                SELECT COUNT(*) FROM therapist_specialty_services
                WHERE therapist_id=? AND service_id=?
            ) + (
                SELECT COUNT(*) FROM therapist_specialties ts
                JOIN services s ON s.category_id = ts.category_id
                WHERE ts.therapist_id=? AND s.id=?
            ) AS total
        ");
        $svc_id = (int)$appt['service_id'];
        $spc->bind_param("iiii", $therapist_id, $svc_id, $therapist_id, $svc_id);
        $spc->execute();
        $spc_count = intval($spc->get_result()->fetch_assoc()['total']); $spc->close();

        if ($spc_count === 0) {
            // Get therapist name and service name for a clear message
            $tn = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
            $tn->bind_param("i", $therapist_id); $tn->execute();
            $tn_row = $tn->get_result()->fetch_assoc(); $tn->close();
            $message = "⚠️ <strong>" . htmlspecialchars($tn_row['full_name'] ?? 'This therapist') . "</strong> does not have <strong>" . htmlspecialchars($appt['service_name']) . "</strong> as a specialty. Please assign a qualified therapist.";
            $message_type = "danger";
        } else {
        // ── Proper overlap conflict check using session_time ──────────────────
        // Get session_time for the current appointment's service
        $new_session_time = intval($appt['session_time'] ?? 60);
        $is_home = ($appt['service_type'] ?? '') === 'home';
        $buffer  = $is_home ? 30 : 0;

        // new slot range (with home service buffer)
        $new_start = $appt_date;  // e.g. "2026-05-23 15:00:00"

        $conflict_stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM appointment_therapists at2
            JOIN appointments a2  ON at2.appointment_id = a2.id
            JOIN services     s2  ON a2.service_id = s2.id
            WHERE at2.therapist_id = ?
              AND a2.id            != ?
              AND a2.status        IN ('approved','assigned')
              AND (
                -- existing busy_start < new busy_end
                (a2.appointment_date - INTERVAL ? MINUTE)
                    < (? + INTERVAL ? MINUTE)
                AND
                -- existing busy_end > new busy_start
                (a2.appointment_date + INTERVAL (s2.session_time + ?) MINUTE)
                    > (? - INTERVAL ? MINUTE)
              )
        ");
        // params: therapist_id, appt_id,
        //         existing_buffer, new_start, new_session+buffer,
        //         existing_buffer, new_start, new_buffer
        $end_mins = $new_session_time + $buffer;
        $conflict_stmt->bind_param(
            "iiisisis",
            $therapist_id, $appt_id,
            $buffer, $new_start, $end_mins,
            $buffer, $new_start, $buffer
        );
        $conflict_stmt->execute();
        $conflict_count = $conflict_stmt->get_result()->fetch_assoc()['cnt'];
        $conflict_stmt->close();

        if ($conflict_count > 0) {
            $time_label = date('h:i A', strtotime($appt_date));
            $end_label  = date('h:i A', strtotime($appt_date . ' +' . ($new_session_time + $buffer) . ' minutes'));
            $message = "⚠️ This therapist is already booked during the " . $time_label . "–" . $end_label . " window" . ($is_home ? " (includes 30-min home service travel buffer)" : "") . ". Please choose another therapist.";
            $message_type = "danger";
        } else {
            // ── Check if already assigned to THIS appointment ─────────────────
            $dup = $conn->prepare("SELECT id FROM appointment_therapists WHERE appointment_id=? AND therapist_id=?");
            $dup->bind_param("ii", $appt_id, $therapist_id); $dup->execute();
            $already = $dup->get_result()->fetch_assoc(); $dup->close();

            if ($already) {
                $message = "This therapist is already assigned to this appointment."; $message_type = "danger";
            } else {
                // ── Check we haven't exceeded people_count ────────────────────
                $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointment_therapists WHERE appointment_id=?");
                $cnt_stmt->bind_param("i", $appt_id); $cnt_stmt->execute();
                $current_count = $cnt_stmt->get_result()->fetch_assoc()['c']; $cnt_stmt->close();

                if ($current_count >= $people) {
                    $message = "All {$people} therapist(s) for this appointment are already assigned.";
                    $message_type = "danger";
                } else {
                    // ── Insert assignment with commission ─────────────────────
                    $ins = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, notes, commission) VALUES (?,?,?,?)");
                    $ins->bind_param("iisd", $appt_id, $therapist_id, $notes, $commission);
                    $ok = $ins->execute(); $ins->close();

                    if ($ok) {
                        // Update appointment status to 'assigned'
                        $upd = $conn->prepare("UPDATE appointments SET status='assigned' WHERE id=?");
                        $upd->bind_param("i",$appt_id); $upd->execute(); $upd->close();

                        // Notify user
                        require_once __DIR__ . '/../notify.php';
                        $t_name_stmt = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
                        $t_name_stmt->bind_param("i", $therapist_id); $t_name_stmt->execute();
                        $t_name = $t_name_stmt->get_result()->fetch_assoc()['full_name'] ?? 'a therapist';
                        $t_name_stmt->close();

                        add_notification($conn, $appt['user_id'], 'appointment',
                            '💆 Therapist Assigned!',
                            'Your appointment for "' . $appt['service_name'] . '" has been assigned to ' . $t_name . '.',
                            'appointments.php'
                        );

                        $message = "✅ Therapist assigned successfully."; $message_type = "success";
                    } else {
                        $message = "Error assigning therapist. Please try again."; $message_type = "danger";
                    }
                }
            }
        }
        } // end specialty check else
    }
    // Reload appointment after changes
    $stmt = $conn->prepare("SELECT a.*, s.name AS service_name, s.session_time, u.username, u.email FROM appointments a JOIN services s ON a.service_id=s.id JOIN users u ON a.user_id=u.id WHERE a.id=?");
    $stmt->bind_param("i", $appt_id); $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// ── REMOVE therapist assignment ───────────────────────────────────────────────
if (isset($_GET['remove_assign'])) {
    $at_id = intval($_GET['remove_assign']);
    $del   = $conn->prepare("DELETE FROM appointment_therapists WHERE id=? AND appointment_id=?");
    $del->bind_param("ii", $at_id, $appt_id); $del->execute(); $del->close();

    // If no therapists left, revert status to 'approved'
    $remaining = $conn->prepare("SELECT COUNT(*) AS c FROM appointment_therapists WHERE appointment_id=?");
    $remaining->bind_param("i", $appt_id); $remaining->execute();
    $rem_count = $remaining->get_result()->fetch_assoc()['c']; $remaining->close();

    if ($rem_count === 0) {
        $rev = $conn->prepare("UPDATE appointments SET status='approved' WHERE id=?");
        $rev->bind_param("i", $appt_id); $rev->execute(); $rev->close();
    }

    header("Location: assign_therapist.php?appt_id={$appt_id}&msg=removed"); exit();
}

// ── Currently assigned therapists for this appointment ───────────────────────
$assigned_stmt = $conn->prepare("
    SELECT at2.id AS at_id, at2.assigned_at, at2.notes, at2.commission,
           t.id AS therapist_id, t.full_name, t.specialties
    FROM appointment_therapists at2
    JOIN therapists t ON at2.therapist_id = t.id
    WHERE at2.appointment_id = ?
    ORDER BY at2.assigned_at ASC
");
$assigned_stmt->bind_param("i", $appt_id); $assigned_stmt->execute();
$assigned_therapists = $assigned_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$assigned_stmt->close();

$slots_filled = count($assigned_therapists);
$slots_left   = $people - $slots_filled;

// ── Available therapists on duty TODAY ───────────────────────────────────────
// Exclude those who are already assigned at the same date+time (conflict check)
// and those already assigned to this appointment
$already_assigned_ids = array_column($assigned_therapists, 'therapist_id');
$already_in           = empty($already_assigned_ids) ? '0' : implode(',', array_map('intval', $already_assigned_ids));

$appt_session_time = intval($appt['session_time'] ?? 60);
$appt_is_home      = ($appt['service_type'] ?? '') === 'home';
$appt_buffer       = $appt_is_home ? 30 : 0;
$appt_date_esc     = $conn->real_escape_string($appt_date);
$new_end_expr      = "'{$appt_date_esc}' + INTERVAL " . ($appt_session_time + $appt_buffer) . " MINUTE";
$new_start_expr    = "'{$appt_date_esc}' - INTERVAL {$appt_buffer} MINUTE";

$appt_is_today = (date('Y-m-d', strtotime($appt_date)) === date('Y-m-d'));

if ($appt_is_today) {
    // Same-day: restrict to therapists on duty TODAY (query unchanged)
    $available_stmt = $conn->query("
        SELECT t.id, t.full_name, t.specialties,
               ta.is_on_break, ta.time_out, ta.rotation_order,
               -- Specialty category names as comma-separated string
               (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                FROM therapist_specialties ts2
                JOIN categories c ON ts2.category_id = c.id
                WHERE ts2.therapist_id = t.id
               ) AS specialty_categories,
               -- Check if therapist has this appointment's service as a specialty
               (SELECT COUNT(*)
                FROM therapist_specialty_services tss
                WHERE tss.therapist_id = t.id
                  AND tss.service_id = {$appt['service_id']}
               ) + (SELECT COUNT(*)
                FROM therapist_specialties ts2
                JOIN services s2 ON s2.category_id = ts2.category_id
                WHERE ts2.therapist_id = t.id
                  AND s2.id = {$appt['service_id']}
               ) AS has_specialty,
               -- Proper overlap conflict check using session_time + home service buffer
               (SELECT COUNT(*)
                FROM appointment_therapists at3
                JOIN appointments a3 ON at3.appointment_id = a3.id
                JOIN services     s3 ON a3.service_id = s3.id
                WHERE at3.therapist_id = t.id
                  AND a3.id     != {$appt_id}
                  AND a3.status IN ('approved','assigned')
                  AND (a3.appointment_date - INTERVAL IF(a3.service_type='home',30,0) MINUTE)
                        < {$new_end_expr}
                  AND (a3.appointment_date + INTERVAL (s3.session_time + IF(a3.service_type='home',30,0)) MINUTE)
                        > {$new_start_expr}
               ) AS slot_conflict
        FROM therapists t
        JOIN therapist_attendance ta ON ta.therapist_id = t.id
        WHERE ta.duty_date = CURDATE()
          AND t.id NOT IN ({$already_in})
        ORDER BY has_specialty DESC, slot_conflict ASC, ta.rotation_order ASC
    ");
} else {
    // Future: show all qualified therapists regardless of today's roster
    $available_stmt = $conn->query("
        SELECT t.id, t.full_name, t.specialties,
               ta.is_on_break, ta.time_out, ta.rotation_order,
               -- Specialty category names as comma-separated string
               (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                FROM therapist_specialties ts2
                JOIN categories c ON ts2.category_id = c.id
                WHERE ts2.therapist_id = t.id
               ) AS specialty_categories,
               -- Check if therapist has this appointment's service as a specialty
               (SELECT COUNT(*)
                FROM therapist_specialty_services tss
                WHERE tss.therapist_id = t.id
                  AND tss.service_id = {$appt['service_id']}
               ) + (SELECT COUNT(*)
                FROM therapist_specialties ts2
                JOIN services s2 ON s2.category_id = ts2.category_id
                WHERE ts2.therapist_id = t.id
                  AND s2.id = {$appt['service_id']}
               ) AS has_specialty,
               -- Proper overlap conflict check using session_time + home service buffer
               (SELECT COUNT(*)
                FROM appointment_therapists at3
                JOIN appointments a3 ON at3.appointment_id = a3.id
                JOIN services     s3 ON a3.service_id = s3.id
                WHERE at3.therapist_id = t.id
                  AND a3.id     != {$appt_id}
                  AND a3.status IN ('approved','assigned')
                  AND (a3.appointment_date - INTERVAL IF(a3.service_type='home',30,0) MINUTE)
                        < {$new_end_expr}
                  AND (a3.appointment_date + INTERVAL (s3.session_time + IF(a3.service_type='home',30,0)) MINUTE)
                        > {$new_start_expr}
               ) AS slot_conflict
        FROM therapists t
        LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
        WHERE t.id NOT IN ({$already_in})
        ORDER BY has_specialty DESC, slot_conflict ASC, t.full_name ASC
    ");
}
$available_therapists = $available_stmt->fetch_all(MYSQLI_ASSOC);

$page_title  = 'Assign Therapist';
$page_icon   = '💆';
$active_page = 'appointments';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg']==='removed'): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem;">✅ Therapist removed from this appointment.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.1fr;gap:1.5rem;align-items:start;">

<!-- ── LEFT: Appointment Info + Assigned Therapists ──────────────────────── -->
<div>

    <!-- Appointment Details card -->
    <div class="panel" style="margin-bottom:1.25rem;">
        <div class="panel-header">
            <span class="panel-title">📅 Appointment #<?php echo $appt_id; ?></span>
            <a href="appointments.php" style="font-size:0.78rem;color:var(--gold);">← Back to list</a>
        </div>
        <div class="panel-body" style="padding:1.1rem;display:grid;gap:0.65rem;">

            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:0.78rem;color:var(--gray);">Status</span>
                <?php
                $s = $appt['status'];
                $badge_map = [
                    'pending'  => ['#fff3cd','#664d03','⏳ Pending'],
                    'approved' => ['#d1e7dd','#0a3622','✅ Approved'],
                    'assigned' => ['#cfe2ff','#084298','💆 Assigned'],
                    'completed'=> ['#cff4fc','#055160','🎉 Completed'],
                    'declined' => ['#f8d7da','#842029','❌ Declined'],
                ];
                [$bg,$fg,$label] = $badge_map[$s] ?? ['#e2e3e5','#41464b',$s];
                ?>
                <span style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;padding:0.3rem 0.85rem;border-radius:20px;font-size:0.8rem;font-weight:700;">
                    <?php echo $label; ?>
                </span>
            </div>

            <div style="display:flex;justify-content:space-between;">
                <span style="font-size:0.78rem;color:var(--gray);">Customer</span>
                <span style="font-size:0.85rem;font-weight:600;color:var(--cream);"><?php echo htmlspecialchars($appt['username']); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span style="font-size:0.78rem;color:var(--gray);">Service</span>
                <span style="font-size:0.85rem;font-weight:600;color:var(--gold);"><?php echo htmlspecialchars($appt['service_name']); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span style="font-size:0.78rem;color:var(--gray);">Date & Time</span>
                <span style="font-size:0.85rem;color:var(--cream);"><?php echo date('M d, Y — h:i A', strtotime($appt_date)); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span style="font-size:0.78rem;color:var(--gray);">People</span>
                <span style="font-size:0.85rem;color:var(--cream);"><?php echo $people; ?> person<?php echo $people>1?'s':''; ?></span>
            </div>
            <?php if ($appt['service_type'] === 'home'): ?>
            <div style="display:flex;justify-content:space-between;">
                <span style="font-size:0.78rem;color:var(--gray);">Type</span>
                <span style="font-size:0.85rem;color:var(--cream);">🏠 Home Service — <?php echo htmlspecialchars($appt['home_address'] ?? ''); ?></span>
            </div>
            <?php endif; ?>

            <!-- Therapist assignment progress -->
            <div style="margin-top:0.5rem;">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;">
                    <span style="font-size:0.76rem;color:var(--gray);">Therapists assigned</span>
                    <span style="font-size:0.76rem;font-weight:700;
                        color:<?php echo $slots_filled>=$people?'var(--green)':'var(--gold)'; ?>">
                        <?php echo $slots_filled; ?> / <?php echo $people; ?> assigned
                    </span>
                </div>
                <div style="height:6px;background:var(--border2);border-radius:99px;overflow:hidden;">
                    <div style="height:100%;border-radius:99px;background:<?php echo $slots_filled>=$people?'var(--green)':'var(--gold)'; ?>;
                                width:<?php echo $people>0?round($slots_filled/$people*100):0; ?>%;transition:width 0.4s;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assigned Therapists -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">💆 Assigned Therapists (<?php echo $slots_filled; ?>/<?php echo $people; ?>)</span>
        </div>
        <div class="panel-body" style="padding:1rem;">
            <?php if (empty($assigned_therapists)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--gray);font-size:0.85rem;">
                No therapists assigned yet.
            </div>
            <?php else: ?>
            <?php foreach ($assigned_therapists as $at): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;
                        padding:0.75rem;background:var(--bg3);border-radius:10px;margin-bottom:0.6rem;
                        border:1px solid var(--border2);">
                <div style="display:flex;align-items:center;gap:0.65rem;">
                    <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--rust));
                                display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:0.9rem;flex-shrink:0;">
                        <?php echo strtoupper(substr($at['full_name'],0,1)); ?>
                    </div>
                    <div>
                        <div style="font-weight:700;color:var(--cream);font-size:0.88rem;"><?php echo htmlspecialchars($at['full_name']); ?></div>
                        <div style="font-size:0.72rem;color:var(--gray);"><?php echo htmlspecialchars($at['specialties'] ?: 'General'); ?></div>
                        <?php if ($at['notes']): ?>
                        <div style="font-size:0.72rem;color:var(--gold);margin-top:0.15rem;">📝 <?php echo htmlspecialchars($at['notes']); ?></div>
                        <?php endif; ?>
                        <div style="font-size:0.72rem;color:#a3e6a3;margin-top:0.15rem;">
                            💵 Commission: ₱<?php echo number_format($at['commission'] ?? 0, 2); ?>
                        </div>
                    </div>
                </div>
                <a href="assign_therapist.php?appt_id=<?php echo $appt_id; ?>&remove_assign=<?php echo $at['at_id']; ?>"
                   onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($at['full_name'])); ?> from this appointment?')"
                   style="font-size:0.75rem;padding:0.3rem 0.65rem;background:rgba(220,53,69,0.15);color:#ff6b7a;
                          border-radius:6px;text-decoration:none;white-space:nowrap;border:1px solid rgba(220,53,69,0.25);">
                    ✕ Remove
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div><!-- end left col -->

<!-- ── RIGHT: Assign Therapist Form + Available Roster ───────────────────── -->
<div>

    <!-- Assign Form -->
    <div class="panel" style="margin-bottom:1.25rem;">
        <div class="panel-header">
            <span class="panel-title">➕ Assign a Therapist</span>
            <?php if ($slots_left <= 0): ?>
            <span style="font-size:0.75rem;background:rgba(25,135,84,0.15);color:var(--green);
                         padding:0.2rem 0.65rem;border-radius:20px;font-weight:600;">All therapists assigned ✓</span>
            <?php else: ?>
            <span style="font-size:0.75rem;background:rgba(201,106,44,0.15);color:var(--gold);
                         padding:0.2rem 0.65rem;border-radius:20px;font-weight:600;"><?php echo $slots_left; ?> more therapist<?php echo $slots_left>1?'s':''; ?> needed</span>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="padding:1.1rem;">

        <?php if ($slots_left <= 0): ?>
        <div style="text-align:center;padding:1.25rem;background:rgba(25,135,84,0.08);border-radius:10px;
                    border:1px solid rgba(25,135,84,0.2);color:var(--green);font-size:0.88rem;">
            ✅ All <?php echo $people; ?> therapist<?php echo $people>1?'s have':'has'; ?> been assigned for this appointment.
        </div>
        <?php else: ?>
        <form method="POST">
        <?php echo csrf_field(); ?>
            <div style="display:grid;gap:0.85rem;">
                <div>
                    <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:4px;">
                        Select Therapist <span style="color:#ff6b7a;">*</span>
                    </label>
                    <select name="therapist_id" required
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--border2);
                                   border-radius:8px;background:var(--bg3);color:var(--cream);font-size:0.85rem;">
                        <option value="">— Choose therapist —</option>
                        <?php
                        // Split into qualified and non-qualified
                        $qualified     = array_filter($available_therapists, fn($t) => $t['has_specialty'] > 0);
                        $not_qualified = array_filter($available_therapists, fn($t) => $t['has_specialty'] == 0);
                        ?>
                        <?php if (!empty($qualified)): ?>
                        <optgroup label="✅ Qualified for <?php echo htmlspecialchars($appt['service_name']); ?>">
                        <?php foreach ($qualified as $t):
                            $busy    = $t['slot_conflict'] > 0;
                            $onbreak = $t['is_on_break'];
                            $out     = !empty($t['time_out']);
                            $label   = htmlspecialchars($t['full_name']);
                            if ($busy)        $label .= ' — ⚠️ Busy at this time';
                            elseif ($onbreak) $label .= ' — ☕ On break';
                            elseif ($out)     $label .= ' — 🏁 Checked out';
                            else              $label .= ' — ✅ Available';
                        ?>
                        <option value="<?php echo $t['id']; ?>"
                                <?php echo ($busy || $out) ? 'disabled' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($not_qualified)): ?>
                        <optgroup label="❌ No specialty for <?php echo htmlspecialchars($appt['service_name']); ?>">
                        <?php foreach ($not_qualified as $t): ?>
                        <option value="<?php echo $t['id']; ?>" disabled
                                style="color:#888;">
                            <?php echo htmlspecialchars($t['full_name']); ?> — ❌ Not qualified
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (empty($available_therapists)): ?>
                        <option value="" disabled>No therapists on duty today</option>
                        <?php endif; ?>
                    </select>
                    <small style="color:var(--gray);font-size:0.71rem;">
                        Only therapists with <strong><?php echo htmlspecialchars($appt['service_name']); ?></strong> specialty are selectable. Others are shown but disabled.
                    </small>
                </div>
                <div>
                    <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:4px;">Notes (optional)</label>
                    <input type="text" name="notes" placeholder="e.g. Guest prefers light pressure"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--cream);font-size:0.85rem;">
                </div>
                <div>
                    <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:4px;">
                        💵 Commission (₱) <span style="color:#ff6b7a;">*</span>
                    </label>
                    <input type="number" name="commission" step="0.01" min="0" value="0.00" required
                           placeholder="e.g. 150.00"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid var(--border2);
                                  border-radius:8px;background:var(--bg3);color:var(--cream);font-size:0.85rem;">
                    <small style="color:var(--gray);font-size:0.71rem;">
                        Fixed commission amount for this therapist on this service. Saved to their daily record.
                    </small>
                </div>
                <button type="submit" name="assign_therapist" class="btn btn-primary">
                    💆 Assign Therapist
                </button>
            </div>
        </form>
        <?php endif; ?>
        </div>
    </div>

    <!-- Full roster with availability status -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title"><?php echo $appt_is_today ? '📋 Today\'s Roster — Availability' : '📋 Qualified Therapists — Availability'; ?></span></div>
        <div class="panel-body" style="padding:1rem;">
        <?php if (empty($available_therapists) && empty($assigned_therapists)): ?>
            <p style="color:var(--gray);font-size:0.85rem;text-align:center;padding:1rem 0;"><?php echo $appt_is_today ? 'No therapists are on duty today.' : 'No qualified therapists found for this service.'; ?></p>
        <?php else: ?>

            <!-- Legend -->
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.85rem;font-size:0.72rem;">
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--green);margin-right:3px;"></span>Available</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f59e0b;margin-right:3px;"></span>On break</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ff6b7a;margin-right:3px;"></span>Busy at this time</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#888;margin-right:3px;"></span>Checked out / Already assigned</span>
            </div>

            <?php
            // Combine assigned + available into one full list for display
            $assigned_ids = array_column($assigned_therapists, 'therapist_id');
            foreach ($available_therapists as $t):
                $busy    = $t['slot_conflict'] > 0;
                $onbreak = $t['is_on_break'];
                $out     = !empty($t['time_out']);
                if ($busy)      { $dot='#ff6b7a'; $tag='Busy at this time'; }
                elseif ($onbreak) { $dot='#f59e0b'; $tag='On break'; }
                elseif ($out)   { $dot='#888'; $tag='Checked out'; }
                else            { $dot='var(--green)'; $tag='Available'; }
            ?>
            <div style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0.5rem;
                        border-bottom:1px solid var(--border2);opacity:<?php echo ($busy||$out)?'0.55':'1'; ?>">
                <div style="width:9px;height:9px;border-radius:50%;background:<?php echo $dot; ?>;flex-shrink:0;"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.83rem;font-weight:600;color:var(--cream);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($t['full_name']); ?>
                    </div>
                    <?php if (!empty($t['specialty_categories'])): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:0.2rem;margin-top:0.2rem;">
                        <?php foreach (explode(', ', $t['specialty_categories']) as $scat): ?>
                        <span style="background:rgba(201,106,44,0.15);color:var(--gold);border:1px solid rgba(201,106,44,0.3);padding:0.05rem 0.4rem;border-radius:20px;font-size:0.63rem;font-weight:600;">
                            <?php echo htmlspecialchars(trim($scat)); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (!empty($t['specialties'])): ?>
                    <div style="font-size:0.7rem;color:var(--gray);"><?php echo htmlspecialchars($t['specialties']); ?></div>
                    <?php endif; ?>
                </div>
                <span style="font-size:0.7rem;color:var(--gray);white-space:nowrap;"><?php echo $tag; ?></span>
            </div>
            <?php endforeach; ?>

            <?php foreach ($assigned_therapists as $at): ?>
            <div style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0.5rem;
                        border-bottom:1px solid var(--border2);">
                <div style="width:9px;height:9px;border-radius:50%;background:#888;flex-shrink:0;"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.83rem;font-weight:600;color:var(--cream);">
                        <?php echo htmlspecialchars($at['full_name']); ?>
                    </div>
                    <div style="font-size:0.7rem;color:var(--gray);"><?php echo htmlspecialchars($at['specialties'] ?: 'General'); ?></div>
                </div>
                <span style="font-size:0.7rem;background:rgba(8,66,152,0.18);color:#84aef0;
                             padding:0.15rem 0.5rem;border-radius:20px;white-space:nowrap;">Already assigned here</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

</div><!-- end right col -->
</div>

<?php require_once 'admin_footer.php'; ?>