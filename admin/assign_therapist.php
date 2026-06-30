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
if (!in_array($appt['status'], ['pending','approved','assigned'])) {
    header("Location: appointments.php?msg=cannot_assign"); exit();
}

$appt_date  = $appt['appointment_date'];              // e.g. "2025-04-10 10:00:00"
$people     = max(1, intval($appt['people_count']));  // how many therapists needed

// ── ASSIGN therapists — per-person row submission ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_per_person'])) {
    verify_csrf_token();

    $therapist_ids = array_map('intval', (array)($_POST['therapist_ids'] ?? []));

    if (count($therapist_ids) !== $people || in_array(0, $therapist_ids, true)) {
        $message = "Please select a therapist for every person."; $message_type = "danger";
    } else {
        // Group identical IDs → people_handled count per unique therapist
        $grouped = array_count_values($therapist_ids); // ['tid' => ph]

        $new_session_time = intval($appt['session_time'] ?? 60);
        $is_home          = ($appt['service_type'] ?? '') === 'home';
        $buffer           = $is_home ? 30 : 0;
        $svc_id           = (int)$appt['service_id'];
        $errors           = [];

        foreach ($grouped as $tid_key => $ph) {
            $tid = (int)$tid_key;

            // Specialty check
            $spc = $conn->prepare("
                SELECT (
                    SELECT COUNT(*) FROM therapist_specialty_services WHERE therapist_id=? AND service_id=?
                ) + (
                    SELECT COUNT(*) FROM therapist_specialties ts
                    JOIN services s ON s.category_id = ts.category_id
                    WHERE ts.therapist_id=? AND s.id=?
                ) AS total
            ");
            $spc->bind_param("iiii", $tid, $svc_id, $tid, $svc_id); $spc->execute();
            $spc_count = intval($spc->get_result()->fetch_assoc()['total']); $spc->close();

            if ($spc_count === 0) {
                $tn = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
                $tn->bind_param("i", $tid); $tn->execute();
                $tn_name = htmlspecialchars($tn->get_result()->fetch_assoc()['full_name'] ?? 'Unknown'); $tn->close();
                $errors[] = "<strong>{$tn_name}</strong> is not qualified for <strong>" . htmlspecialchars($appt['service_name']) . "</strong>.";
                continue;
            }

            // Conflict check: new slot = session_time × ph (back-to-back model)
            $end_mins = ($new_session_time * $ph) + $buffer;
            $conf = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM appointment_therapists at2
                JOIN appointments a2 ON at2.appointment_id = a2.id
                JOIN services     s2 ON a2.service_id = s2.id
                WHERE at2.therapist_id = ?
                  AND a2.id            != ?
                  AND a2.status        IN ('approved','assigned')
                  AND (a2.appointment_date - INTERVAL ? MINUTE) < (? + INTERVAL ? MINUTE)
                  AND (a2.appointment_date + INTERVAL (s2.session_time * IFNULL(at2.people_handled,1) + ?) MINUTE)
                        > (? - INTERVAL ? MINUTE)
            ");
            $conf->bind_param("iiisisis", $tid, $appt_id, $buffer, $appt_date, $end_mins, $buffer, $appt_date, $buffer);
            $conf->execute();
            $conf_count = (int)$conf->get_result()->fetch_assoc()['cnt']; $conf->close();

            if ($conf_count > 0) {
                $tn = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
                $tn->bind_param("i", $tid); $tn->execute();
                $tn_name = htmlspecialchars($tn->get_result()->fetch_assoc()['full_name'] ?? 'Unknown'); $tn->close();
                $t_start = date('h:i A', strtotime($appt_date . ' -' . $buffer . ' minutes'));
                $t_end   = date('h:i A', strtotime($appt_date . ' +' . $end_mins . ' minutes'));
                $errors[] = "<strong>{$tn_name}</strong> is busy during {$t_start}–{$t_end}" . ($is_home ? " (incl. travel buffer)" : "") . ".";
            }
        }

        if (!empty($errors)) {
            $message = "⚠️ " . implode(" ", $errors); $message_type = "danger";
        } else {
            // Replace all existing assignments with the new grouped set
            $del = $conn->prepare("DELETE FROM appointment_therapists WHERE appointment_id=?");
            $del->bind_param("i", $appt_id); $del->execute(); $del->close();

            $commission = 0.00; $notes = '';
            $therapist_names = [];
            foreach ($grouped as $tid_key => $ph) {
                $tid = (int)$tid_key;
                $ins = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, notes, commission, people_handled) VALUES (?,?,?,?,?)");
                $ins->bind_param("iisdi", $appt_id, $tid, $notes, $commission, $ph);
                $ins->execute(); $ins->close();

                $tn = $conn->prepare("SELECT full_name FROM therapists WHERE id=?");
                $tn->bind_param("i", $tid); $tn->execute();
                $therapist_names[] = $tn->get_result()->fetch_assoc()['full_name'] ?? 'a therapist'; $tn->close();
            }

            $upd = $conn->prepare("UPDATE appointments SET status='assigned' WHERE id=?");
            $upd->bind_param("i", $appt_id); $upd->execute(); $upd->close();

            require_once __DIR__ . '/../notify.php';
            add_notification($conn, $appt['user_id'], 'appointment',
                '💆 Therapist Assigned!',
                'Your appointment for "' . $appt['service_name'] . '" has been assigned to ' . implode(' & ', $therapist_names) . '.',
                'appointments.php'
            );

            header("Location: assign_therapist.php?appt_id={$appt_id}&msg=assigned"); exit();
        }
    }
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
           IFNULL(at2.people_handled, 1) AS people_handled,
           t.id AS therapist_id, t.full_name, t.specialties
    FROM appointment_therapists at2
    JOIN therapists t ON at2.therapist_id = t.id
    WHERE at2.appointment_id = ?
    ORDER BY at2.assigned_at ASC
");
$assigned_stmt->bind_param("i", $appt_id); $assigned_stmt->execute();
$assigned_therapists = $assigned_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$assigned_stmt->close();

// People budget: sum of people_handled across assigned therapists
$slots_filled = array_sum(array_column($assigned_therapists, 'people_handled'));
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

// All therapists for per-person dropdowns — includes already-assigned (same therapist
// can handle multiple person-rows), no attendance filter so future appts work too.
$form_t_stmt = $conn->query("
    SELECT t.id, t.full_name, t.specialties,
           COALESCE(ta.is_on_break, 0) AS is_on_break,
           ta.time_out,
           (SELECT COUNT(*) FROM therapist_specialty_services tss
            WHERE tss.therapist_id=t.id AND tss.service_id={$appt['service_id']}
           ) + (SELECT COUNT(*) FROM therapist_specialties ts2
            JOIN services s2 ON s2.category_id=ts2.category_id
            WHERE ts2.therapist_id=t.id AND s2.id={$appt['service_id']}
           ) AS has_specialty,
           (SELECT COUNT(*) FROM appointment_therapists at3
            JOIN appointments a3 ON at3.appointment_id=a3.id
            JOIN services     s3 ON a3.service_id=s3.id
            WHERE at3.therapist_id=t.id
              AND a3.id     != {$appt_id}
              AND a3.status IN ('approved','assigned')
              AND (a3.appointment_date - INTERVAL IF(a3.service_type='home',30,0) MINUTE) < {$new_end_expr}
              AND (a3.appointment_date + INTERVAL (s3.session_time + IF(a3.service_type='home',30,0)) MINUTE) > {$new_start_expr}
           ) AS slot_conflict
    FROM therapists t
    LEFT JOIN therapist_attendance ta ON ta.therapist_id=t.id AND ta.duty_date=CURDATE()
    ORDER BY has_specialty DESC, slot_conflict ASC, t.full_name ASC
");
$form_therapists = $form_t_stmt->fetch_all(MYSQLI_ASSOC);

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
<?php if (isset($_GET['msg']) && $_GET['msg']==='assigned'): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem;">✅ Therapist assignments saved successfully.</div>
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
                    <span style="font-size:0.76rem;color:var(--gray);">People covered</span>
                    <span style="font-size:0.76rem;font-weight:700;
                        color:<?php echo $slots_filled>=$people?'var(--green)':'var(--gold)'; ?>">
                        <?php echo $slots_filled; ?> / <?php echo $people; ?> covered
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
            <span class="panel-title">💆 Assigned Therapists (<?php echo $slots_filled; ?>/<?php echo $people; ?> people covered)</span>
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
                        <div style="font-size:0.72rem;color:var(--gold);margin-top:0.15rem;">
                            👥 Handles: <?php echo $at['people_handled']; ?> person<?php echo $at['people_handled']>1?'s':''; ?>
                        </div>
                        <div style="font-size:0.72rem;color:#a3e6a3;margin-top:0.1rem;">
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

    <!-- Assign Form — per-person rows -->
    <div class="panel" style="margin-bottom:1.25rem;">
        <div class="panel-header">
            <span class="panel-title">💆 Assign Therapists</span>
            <?php if ($slots_left <= 0): ?>
            <span style="font-size:0.75rem;background:rgba(25,135,84,0.15);color:var(--green);
                         padding:0.2rem 0.65rem;border-radius:20px;font-weight:600;">All <?php echo $people; ?> people covered ✓</span>
            <?php else: ?>
            <span style="font-size:0.75rem;background:rgba(201,106,44,0.15);color:var(--gold);
                         padding:0.2rem 0.65rem;border-radius:20px;font-weight:600;"><?php echo $slots_left; ?> person<?php echo $slots_left>1?'s':''; ?> unassigned</span>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="padding:1.1rem;">

        <?php if ($slots_left <= 0): ?>
        <div style="margin-bottom:1rem;padding:0.7rem 0.9rem;background:rgba(25,135,84,0.08);
                    border:1px solid rgba(25,135,84,0.2);border-radius:8px;font-size:0.85rem;color:var(--green);">
            ✅ All <?php echo $people; ?> people are covered. You may reassign below if needed.
        </div>
        <?php endif; ?>

        <form method="POST">
        <?php echo csrf_field(); ?>
        <div style="display:grid;gap:0.65rem;margin-bottom:1rem;">
        <?php
        for ($p = 1; $p <= $people; $p++):
            // Pre-fill by walking through assigned_therapists and accumulating people_handled
            $prefill_id = 0; $counter = 0;
            foreach ($assigned_therapists as $at) {
                $counter += intval($at['people_handled']);
                if ($p <= $counter) { $prefill_id = $at['therapist_id']; break; }
            }
        ?>
        <div style="display:grid;grid-template-columns:90px 1fr;align-items:center;gap:0.75rem;
                    padding:0.65rem 0.85rem;background:var(--bg3);border:1px solid var(--border2);
                    border-radius:8px;">
            <div style="font-size:0.82rem;font-weight:600;color:var(--gold);">👤 Person <?php echo $p; ?></div>
            <select name="therapist_ids[]" required
                    style="padding:0.45rem 0.65rem;border:1px solid var(--border2);border-radius:7px;
                           background:var(--bg2);color:var(--cream);font-size:0.85rem;width:100%;">
                <option value="">— Select therapist —</option>
                <?php foreach ($form_therapists as $t):
                    $selected  = ($t['id'] == $prefill_id) ? 'selected' : '';
                    $qualified = $t['has_specialty'] > 0;
                    $busy      = $t['slot_conflict'] > 0;
                    $onbreak   = $t['is_on_break'];
                    $out       = !empty($t['time_out']);
                    $label     = htmlspecialchars($t['full_name']);
                    if (!$qualified)  $label .= ' — ❌ Not qualified';
                    elseif ($busy)    $label .= ' — ⚠️ Busy';
                    elseif ($onbreak) $label .= ' — ☕ On break';
                    elseif ($out)     $label .= ' — 🏁 Checked out';
                    else              $label .= ' — ✅ Available';
                ?>
                <option value="<?php echo $t['id']; ?>" <?php echo $selected; ?> <?php echo !$qualified ? 'disabled' : ''; ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endfor; ?>
        </div>

        <?php if (empty($form_therapists)): ?>
        <div style="padding:0.65rem;background:rgba(220,53,69,0.1);border-radius:8px;font-size:0.82rem;
                    color:#ff6b7a;border:1px solid rgba(220,53,69,0.25);margin-bottom:1rem;">
            ⚠️ No therapists found. <a href="therapists.php" style="color:var(--gold);">Check therapist roster.</a>
        </div>
        <?php endif; ?>

        <button type="submit" name="save_per_person" class="btn btn-primary" style="width:100%;">
            💾 Save All Assignments
        </button>
        </form>

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