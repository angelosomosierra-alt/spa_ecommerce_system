<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
require_once __DIR__ . '/../notify.php';

$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS home_service_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER home_service_fee");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS is_two_session TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS session2_price DECIMAL(10,2) NULL DEFAULT NULL");
// ── Classic (single-duration) Promo Price — mirrors service_durations' own
//    promo scheduling columns for services that don't use Duration Options.
//    Independent of service_durations; a service uses one system or the other.
$conn->query("ALTER TABLE services
    ADD COLUMN IF NOT EXISTS promo_price       DECIMAL(10,2) NULL AFTER price,
    ADD COLUMN IF NOT EXISTS price_mode        ENUM('regular','promo') NOT NULL DEFAULT 'regular' AFTER promo_price,
    ADD COLUMN IF NOT EXISTS promo_start_time  TIME NULL AFTER price_mode,
    ADD COLUMN IF NOT EXISTS promo_end_time    TIME NULL AFTER promo_start_time");
$conn->query("CREATE TABLE IF NOT EXISTS service_durations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    duration_minutes INT NOT NULL,
    regular_price DECIMAL(10,2) NOT NULL,
    promo_price DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_service_duration (service_id, duration_minutes),
    CONSTRAINT fk_service_durations_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$conn->query("ALTER TABLE service_durations ADD COLUMN IF NOT EXISTS price_mode ENUM('regular','promo') NOT NULL DEFAULT 'regular' AFTER promo_price");
$conn->query("ALTER TABLE service_durations ADD COLUMN IF NOT EXISTS promo_start_time TIME NULL AFTER price_mode");
$conn->query("ALTER TABLE service_durations ADD COLUMN IF NOT EXISTS promo_end_time TIME NULL AFTER promo_start_time");
// ── Session-Count Packages: N-session package support, layered on top of
//    Duration Options (session_count=1 is an ordinary duration variant,
//    unchanged behavior; >1 is a fixed-price N-session package). Completely
//    independent of the separate is_two_session/session2_price feature above.
$conn->query("ALTER TABLE service_durations ADD COLUMN IF NOT EXISTS session_count INT NOT NULL DEFAULT 1 AFTER duration_minutes");
$_scu_idx  = $conn->query("SHOW INDEX FROM service_durations WHERE Key_name = 'uq_service_duration'");
$_scu_cols = $_scu_idx ? array_column($_scu_idx->fetch_all(MYSQLI_ASSOC), 'Column_name') : [];
if ($_scu_cols && !in_array('session_count', $_scu_cols)) {
    // DROP + ADD must be one ALTER TABLE statement — service_id's FK needs a
    // supporting index at all times, which two separate ALTERs can't guarantee.
    $conn->query("ALTER TABLE service_durations DROP INDEX uq_service_duration, ADD UNIQUE KEY uq_service_duration (service_id, duration_minutes, session_count)");
}
unset($_scu_idx, $_scu_cols);
$_sd_col_chk = $conn->query("SHOW COLUMNS FROM appointments LIKE 'service_duration_id'");
if ($_sd_col_chk && $_sd_col_chk->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN service_duration_id INT NULL DEFAULT NULL AFTER duration_minutes");
    $conn->query("ALTER TABLE appointments ADD CONSTRAINT fk_appt_service_duration FOREIGN KEY (service_duration_id) REFERENCES service_durations(id) ON DELETE SET NULL");
}
unset($_sd_col_chk);

$message      = '';
$message_type = '';

// ─── FETCH SERVICE CATEGORIES ─────────────────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT * FROM categories WHERE type = 'service' ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ─── SUPPLIES FOR RECIPE DROPDOWN ────────────────────────────────────────────
$supply_opts = [];
$_svc_sr = $conn->query("SELECT id, name, category, base_unit_label FROM supplies WHERE deleted_at IS NULL ORDER BY category, name");
while ($row = $_svc_sr->fetch_assoc()) $supply_opts[] = $row;
$supply_map_svc = array_column($supply_opts, null, 'id');

// Build options HTML for recipe select dropdowns (reused in PHP pre-populated rows and JS template)
$recipe_opts_html = '<option value="">-- Select Supply --</option>';
$_rocat = null;
foreach ($supply_opts as $_rsp) {
    if ($_rsp['category'] !== $_rocat) {
        if ($_rocat !== null) $recipe_opts_html .= '</optgroup>';
        $recipe_opts_html .= '<optgroup label="' . htmlspecialchars($_rsp['category']) . '">';
        $_rocat = $_rsp['category'];
    }
    $recipe_opts_html .= '<option value="' . $_rsp['id'] . '" data-unit="' . htmlspecialchars($_rsp['base_unit_label']) . '">'
                       . htmlspecialchars($_rsp['name']) . '</option>';
}
if ($_rocat !== null) $recipe_opts_html .= '</optgroup>';
unset($_rocat, $_rsp, $_svc_sr);

// ─── ARCHIVE (SOFT DELETE) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_service') {
    verify_csrf_token();

    // Receptionist PIN check for archive
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_svc_del = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_svc_del) {
            $message = "⚠️ Incorrect PIN. Archive action cancelled."; $message_type = "danger";
            goto end_svc_delete;
        }
    }

    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT id FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $svc_check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($svc_check) {
        $conn->query("DELETE FROM therapist_specialty_services WHERE service_id = $id");
        $conn->query("DELETE FROM therapist_commission WHERE service_id = $id");
        $conn->query("DELETE FROM partner_rates WHERE service_id = $id");
        $stmt = $conn->prepare("UPDATE services SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message      = "Service archived.";
            $message_type = "success";
            $_actor_svc_del = (is_cashier() && !empty($pr_svc_del['full_name']))
                ? ['id' => null, 'name' => $pr_svc_del['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'service_deleted', "Archived service ID {$id}", 'service', $id, $_actor_svc_del);
        } else {
            $message      = "Error archiving service.";
            $message_type = "danger";
        }
        $stmt->close();
    }
    end_svc_delete:;
}

// ─── RESTORE ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_service') {
    verify_csrf_token();
    $rid = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE services SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $rid);
    $stmt->execute(); $stmt->close();
    $message      = "Service restored.";
    $message_type = "success";
}

// ─── ADD / EDIT ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_service', 'restore_service'])) {
    verify_csrf_token();

    // Receptionist PIN check for add/edit
    $pr_svc_action = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_svc_action = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_svc_action) {
            $message = "⚠️ Incorrect PIN. Action cancelled."; $message_type = "danger";
            goto end_svc_action;
        }
    }

    $id               = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name             = sanitize_input($_POST['name']);
    $description      = sanitize_input($_POST['description']);
    $category_id      = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $is_home_service    = isset($_POST['is_home_service']) ? 1 : 0;
    $home_service_price = $is_home_service ? floatval($_POST['home_service_price'] ?? 0) : 0.00;
    $at_cost            = max(0.0, floatval($_POST['at_cost'] ?? 0));

    // ── Duration Options rows — the ONLY pricing UI now. Every service has
    //    at least one row; services.price/session_time/promo_price/price_mode/
    //    promo_start_time/promo_end_time are set further below purely as a
    //    read-only sync mirror of row 1, for other code that hasn't been
    //    migrated to read service_durations directly (out of scope here).
    $duration_variants = [];
    $_vd_list = (array)($_POST['variant_duration']      ?? []);
    $_vr_list = (array)($_POST['variant_regular_price'] ?? []);
    $_vp_list = (array)($_POST['variant_promo_price']   ?? []);
    $_pm_list = (array)($_POST['variant_price_mode']    ?? []);
    $_ps_list = (array)($_POST['variant_promo_start']   ?? []);
    $_pe_list = (array)($_POST['variant_promo_end']     ?? []);
    $_sc_list = (array)($_POST['variant_session_count'] ?? []);
    foreach ($_vd_list as $_vi => $_vd) {
        $_vd = intval($_vd);
        $_vr = floatval($_vr_list[$_vi] ?? 0);
        $_vp = floatval($_vp_list[$_vi] ?? 0);
        $_sc = max(1, intval($_sc_list[$_vi] ?? 1));
        if ($_vd <= 0 || $_vr <= 0) continue; // skip incomplete rows

        $_pm = (($_pm_list[$_vi] ?? 'regular') === 'promo') ? 'promo' : 'regular';
        $_ps = trim($_ps_list[$_vi] ?? '');
        $_pe = trim($_pe_list[$_vi] ?? '');
        if ($_pm === 'promo') {
            if ($_ps === '' || $_pe === '') {
                $message = "Please set both a start and end time for every duration option using Promo Price (or switch it back to Regular Price).";
                $message_type = "danger";
                break;
            }
        } else {
            // price_mode='regular' — times are ignored/cleared regardless
            // of whatever the (hidden) fields happen to contain.
            $_ps = null; $_pe = null;
        }

        $duration_variants[] = ['duration' => $_vd, 'regular' => $_vr, 'promo' => $_vp, 'price_mode' => $_pm, 'promo_start' => $_ps, 'promo_end' => $_pe, 'session_count' => $_sc];
    }

    if ($message_type !== 'danger' && empty($duration_variants)) {
        $message = "Add at least one Duration Option (Duration + Regular Price are required).";
        $message_type = "danger";
    }

    $price = $session_time = $promo_price = $price_mode = $promo_start_time = $promo_end_time = null;
    if ($message_type !== 'danger') {
        // Lowest duration first, lowest session_count as tiebreak — row 1 is
        // what gets mirrored into services' own columns.
        usort($duration_variants, fn($a, $b) => ($a['duration'] <=> $b['duration']) ?: ($a['session_count'] <=> $b['session_count']));
        $price            = $duration_variants[0]['regular'];
        $session_time     = $duration_variants[0]['duration'];
        $promo_price      = $duration_variants[0]['promo'];
        $price_mode       = $duration_variants[0]['price_mode'];
        $promo_start_time = $duration_variants[0]['promo_start'];
        $promo_end_time   = $duration_variants[0]['promo_end'];
    }

    $image_name = '';

    // 1. HANDLE IMAGE UPLOAD FIRST
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['image']['tmp_name'];
        $file_name = $_FILES['image']['name'];
        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
        
        $image_name = 'service_' . time() . '.' . $ext;
        $target_path = UPLOAD_DIR_SERVICES . $image_name;

        if (move_uploaded_file($file_tmp, $target_path)) {
            if ($id) {
                $check = $conn->query("SELECT image FROM services WHERE id = $id");
                $old = $check->fetch_assoc();
                if ($old && $old['image'] && file_exists(UPLOAD_DIR_SERVICES . $old['image'])) {
                    unlink(UPLOAD_DIR_SERVICES . $old['image']);
                }
            }
        } else {
            $message = "Folder permission error: Cannot move file to " . UPLOAD_DIR_SERVICES;
            $message_type = "danger";
        }
    }

    // 2. CONSTRUCT THE SQL
    if ($message_type !== 'danger') {
        if ($id) {
            // EDITING
            // is_two_session / session2_price are intentionally omitted here — that
            // checkbox has been retired from this form (Phase 0), but existing
            // services that already have it set keep their value untouched.
            if ($image_name !== '') {
                $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, image=?, category_id=?, is_home_service=?, home_service_price=?, at_cost=?, promo_price=?, price_mode=?, promo_start_time=?, promo_end_time=? WHERE id=?");
                $stmt->bind_param("ssdisiidddsssi", $name, $description, $price, $session_time, $image_name, $category_id, $is_home_service, $home_service_price, $at_cost, $promo_price, $price_mode, $promo_start_time, $promo_end_time, $id);
            } else {
                $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, category_id=?, is_home_service=?, home_service_price=?, at_cost=?, promo_price=?, price_mode=?, promo_start_time=?, promo_end_time=? WHERE id=?");
                $stmt->bind_param("ssdiiidddsssi", $name, $description, $price, $session_time, $category_id, $is_home_service, $home_service_price, $at_cost, $promo_price, $price_mode, $promo_start_time, $promo_end_time, $id);
            }
        } else {
            // NEW SERVICE — is_two_session/session2_price keep their column defaults (0/NULL)
            $stmt = $conn->prepare("INSERT INTO services (name, description, price, session_time, image, category_id, is_home_service, home_service_price, at_cost, promo_price, price_mode, promo_start_time, promo_end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdisiidddsss", $name, $description, $price, $session_time, $image_name, $category_id, $is_home_service, $home_service_price, $at_cost, $promo_price, $price_mode, $promo_start_time, $promo_end_time);
        }

        if ($stmt->execute()) {
            $svc_logged_id = $id ?? (int)$conn->insert_id;
            $_actor_svc = (is_cashier() && !empty($pr_svc_action['full_name']))
                ? ['id' => null, 'name' => $pr_svc_action['full_name'], 'role' => 'receptionist']
                : null;
            if ($id) {
                log_activity($conn, 'service_updated', "Updated service: {$name}", 'service', $svc_logged_id, $_actor_svc);
            } else {
                log_activity($conn, 'service_created', "Created service: {$name}", 'service', $svc_logged_id, $_actor_svc);
            }
            // ── Save recipe: delete-then-reinsert ─────────────────────────
            $r_sids      = array_map('intval', (array)($_POST['recipe_supply_id']          ?? []));
            $r_qtys      = (array)($_POST['recipe_qty_per_person']                          ?? []);
            $r_arch_sids = array_map('intval', (array)($_POST['recipe_supply_id_archived']  ?? []));
            $r_arch_qtys = (array)($_POST['recipe_qty_per_person_archived']                 ?? []);
            $del_rr = $conn->prepare("DELETE FROM service_supply_usage WHERE service_id = ?");
            $del_rr->bind_param("i", $svc_logged_id);
            $del_rr->execute(); $del_rr->close();
            $seen_r = [];
            foreach ($r_sids as $_ri => $_rsid) {
                if (!$_rsid || !isset($supply_map_svc[$_rsid])) continue;
                if (in_array($_rsid, $seen_r, true)) continue;
                $_rqty = max(0.0001, (float)($r_qtys[$_ri] ?? 0));
                $ins_rr = $conn->prepare("INSERT INTO service_supply_usage (service_id, supply_id, quantity_per_person) VALUES (?, ?, ?)");
                $ins_rr->bind_param("iid", $svc_logged_id, $_rsid, $_rqty);
                $ins_rr->execute(); $ins_rr->close();
                $seen_r[] = $_rsid;
            }
            foreach ($r_arch_sids as $_ri => $_rsid) {
                if (!$_rsid) continue;
                if (in_array($_rsid, $seen_r, true)) continue;
                $_rqty = max(0.0001, (float)($r_arch_qtys[$_ri] ?? 0));
                $ins_rr = $conn->prepare("INSERT INTO service_supply_usage (service_id, supply_id, quantity_per_person) VALUES (?, ?, ?)");
                $ins_rr->bind_param("iid", $svc_logged_id, $_rsid, $_rqty);
                $ins_rr->execute(); $ins_rr->close();
                $seen_r[] = $_rsid;
            }
            // ──────────────────────────────────────────────────────────────

            // ── Save duration variants: delete-then-reinsert (same pattern as
            //    the recipe save above) ─────────────────────────────────────
            $del_sd = $conn->prepare("DELETE FROM service_durations WHERE service_id = ?");
            $del_sd->bind_param("i", $svc_logged_id);
            $del_sd->execute(); $del_sd->close();
            foreach ($duration_variants as $_dv) {
                $ins_sd = $conn->prepare("INSERT INTO service_durations (service_id, duration_minutes, session_count, regular_price, promo_price, price_mode, promo_start_time, promo_end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_sd->bind_param("iiiddsss", $svc_logged_id, $_dv['duration'], $_dv['session_count'], $_dv['regular'], $_dv['promo'], $_dv['price_mode'], $_dv['promo_start'], $_dv['promo_end']);
                $ins_sd->execute(); $ins_sd->close();
            }
            // ──────────────────────────────────────────────────────────────
            $redirect_to = $id ? "services.php?edit={$svc_logged_id}&saved=1" : "services.php?success=1";
            header("Location: $redirect_to");
            exit();
        } else {
            $message = "Database Error: " . $conn->error;
            $message_type = "danger";
        }
        $stmt->close();
    }
    end_svc_action:;
}
// ─── FETCH FOR EDITING ────────────────────────────────────────────────────────
$edit_service = null;
if (isset($_GET['edit'])) {
    $id   = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_service = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ─── FETCH RECIPE FOR EDITING ─────────────────────────────────────────────────
$svc_recipe          = [];
$_archived_in_recipe = [];
if ($edit_service) {
    $rr_stmt = $conn->prepare("SELECT supply_id, quantity_per_person FROM service_supply_usage WHERE service_id = ? ORDER BY id");
    $rr_stmt->bind_param("i", $edit_service['id']);
    $rr_stmt->execute();
    $svc_recipe = $rr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rr_stmt->close();
    // Detect archived supplies still referenced in this service's recipe
    if (!empty($svc_recipe)) {
        $_all_rsids    = array_map('intval', array_column($svc_recipe, 'supply_id'));
        $_archived_ids = array_values(array_diff($_all_rsids, array_keys($supply_map_svc)));
        if (!empty($_archived_ids)) {
            $_in_clause = implode(',', $_archived_ids); // safe: all intval'd
            $_asr = $conn->query("SELECT id, name, base_unit_label FROM supplies WHERE id IN ({$_in_clause})");
            foreach ($_asr->fetch_all(MYSQLI_ASSOC) as $_ar) {
                $_archived_in_recipe[(int)$_ar['id']] = $_ar;
            }
        }
    }
}

// ─── FETCH DURATION VARIANTS FOR EDITING ─────────────────────────────────────
$svc_durations_list = [];
if ($edit_service) {
    $sd_stmt = $conn->prepare("SELECT duration_minutes, session_count, regular_price, promo_price, price_mode, promo_start_time, promo_end_time FROM service_durations WHERE service_id = ? ORDER BY duration_minutes, session_count");
    $sd_stmt->bind_param("i", $edit_service['id']);
    $sd_stmt->execute();
    $svc_durations_list = $sd_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sd_stmt->close();
    // Trim TIME columns to HH:MM for the <input type="time"> value attribute
    foreach ($svc_durations_list as &$_sdl_row) {
        if (!empty($_sdl_row['promo_start_time'])) $_sdl_row['promo_start_time'] = substr($_sdl_row['promo_start_time'], 0, 5);
        if (!empty($_sdl_row['promo_end_time']))   $_sdl_row['promo_end_time']   = substr($_sdl_row['promo_end_time'], 0, 5);
    }
    unset($_sdl_row);
}

// ─── FETCH ALL SERVICES ───────────────────────────────────────────────────────
$services = [];
$result   = $conn->query("
    SELECT s.*, c.name as category_name
    FROM services s
    LEFT JOIN categories c ON s.category_id = c.id
    ORDER BY s.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}

// ─── RECIPE COUNTS (for list indicator) ──────────────────────────────────────
$svc_recipe_counts = [];
$_rcq = $conn->query("SELECT service_id, COUNT(*) AS cnt FROM service_supply_usage GROUP BY service_id");
while ($row = $_rcq->fetch_assoc()) $svc_recipe_counts[(int)$row['service_id']] = (int)$row['cnt'];

// ─── DURATION VARIANTS (for list badge) ──────────────────────────────────────
$svc_durations_by_service = []; // service_id => [['duration_minutes'=>.., 'session_count'=>..], ...]
$_sdq = $conn->query("SELECT service_id, duration_minutes, session_count FROM service_durations ORDER BY service_id, duration_minutes, session_count");
while ($row = $_sdq->fetch_assoc()) $svc_durations_by_service[(int)$row['service_id']][] = ['duration_minutes' => (int)$row['duration_minutes'], 'session_count' => (int)$row['session_count']];

// ─── STATS ────────────────────────────────────────────────────────────────────
$total_services    = count($services);
$avg_price         = $total_services ? array_sum(array_column($services, 'price')) / $total_services : 0;
$categorized_count = count(array_filter($services, fn($s) => !empty($s['category_id'])));
$with_recipe_count = count(array_filter($services, fn($s) => !$s['deleted_at'] && ($svc_recipe_counts[$s['id']] ?? 0) > 0));

if (!$message && isset($_GET['saved']))   { $message = "Service updated.";           $message_type = "success"; }
if (!$message && isset($_GET['success'])) { $message = "Service added successfully."; $message_type = "success"; }

$page_title  = $edit_service ? 'Edit Service' : 'Services';
$page_icon   = '💆';
$active_page = 'services';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<?php if (!$edit_service && !isset($_GET['action'])): ?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo $total_services; ?></div>
        <div class="stat-label">Total Services</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">💰</div>
        <div class="stat-number">₱<?php echo number_format($avg_price, 0); ?></div>
        <div class="stat-label">Avg. Price</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🏷️</div>
        <div class="stat-number"><?php echo $categorized_count; ?></div>
        <div class="stat-label">Categorized</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">🧴</div>
        <div class="stat-number"><?php echo $with_recipe_count; ?></div>
        <div class="stat-label">With Recipe</div>
    </div>
</div>
<?php endif; ?>

<?php if ((isset($_GET['action']) && $_GET['action'] === 'add') || $edit_service): ?>

<div style="margin-bottom:1rem;">
    <a href="services.php" class="btn btn-secondary">← Back to Services</a>
</div>

<div class="form-section">
    <div class="form-section-header">
        <?php echo $edit_service ? '✏️ Edit Service' : '➕ Add New Service'; ?>
    </div>
    <div class="form-section-body">
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateDurationVariantsOnSubmit();">
            <?php echo csrf_field(); ?>
            <?php if ($edit_service): ?>
                <input type="hidden" name="id" value="<?php echo $edit_service['id']; ?>">
            <?php endif; ?>

            <span class="section-label-sm">🪪 Service Info</span>
            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Service Name <span class="required">*</span></label>
                    <input type="text" name="name" required
                           value="<?php echo htmlspecialchars($edit_service['name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">-- No Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"
                                <?php echo (isset($edit_service['category_id']) && $edit_service['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small><a href="categories.php" target="_blank">+ Manage Categories</a></small>
                </div>
            </div>

            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">
                <span class="section-label-sm">💰 Pricing &amp; Duration</span>
            </div>

            <div id="durationVariantsContainer" style="margin-bottom:1.25rem;">
                <small style="color:var(--gray);display:block;margin-bottom:0.5rem;">
                    Every service has at least one Duration Option. Add more rows for extra durations or multi-session packages — each has its own Regular/Promo price. Receptionists pick one at booking time.
                </small>
                <div id="durationVariantsRows"></div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addDurationVariantRow()" style="margin-top:0.5rem;">+ Add Duration Option</button>
            </div>

            <div class="form-grid form-grid-3" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>At Cost (₱) <span style="font-size:0.72rem;color:var(--gray);font-weight:400;">— Influencer / Marketing</span></label>
                    <input type="number" name="at_cost" step="0.01" min="0"
                           value="<?php echo floatval($edit_service['at_cost'] ?? 0); ?>"
                           placeholder="0.00">
                    <small>Used in Marketing Expense = At Cost + Therapist CF. Leave 0 for regular.</small>
                </div>
            </div>

            <script>
            var svcDurationVariants = <?php echo json_encode(array_values($svc_durations_list)); ?>;

            var _dvRowSeq = 0;

            function addDurationVariantRow(duration, regular, promo, priceMode, promoStart, promoEnd, sessionCount) {
                var rows   = document.getElementById('durationVariantsRows');
                var rowId  = 'dvrow' + (_dvRowSeq++);
                var isPromo = (priceMode === 'promo');

                var wrap = document.createElement('div');
                wrap.className = 'duration-variant-option';
                wrap.style.marginBottom = '0.75rem';
                wrap.style.paddingBottom = '0.75rem';
                wrap.style.borderBottom = '1px dashed var(--border2)';

                var grid = document.createElement('div');
                grid.className = 'form-grid form-grid-4';
                grid.style.alignItems = 'end';
                grid.innerHTML =
                    '<div class="form-group"><label>Duration (minutes) <span class="required">*</span></label>' +
                    '<input type="number" name="variant_duration[]" min="1" required value="' + (duration || '') + '"></div>' +
                    '<div class="form-group"><label>Sessions <span style="font-size:0.68rem;color:var(--gray);font-weight:400;">(1 = regular)</span></label>' +
                    '<input type="number" name="variant_session_count[]" min="1" value="' + (sessionCount || '1') + '"></div>' +
                    '<div class="form-group"><label>Regular Price (₱) <span style="font-size:0.68rem;color:var(--gray);font-weight:400;">(full package total if Sessions &gt; 1)</span> <span class="required">*</span></label>' +
                    '<input type="number" name="variant_regular_price[]" step="0.01" min="0.01" required value="' + (regular || '') + '"></div>' +
                    '<div class="form-group"><label>Promo Price (₱)</label>' +
                    '<input type="number" name="variant_promo_price[]" step="0.01" min="0" value="' + (promo || '') + '"></div>' +
                    '<div class="form-group"><button type="button" class="btn btn-danger btn-sm dv-remove-btn" onclick="removeDurationVariantRow(this)">✕ Remove</button></div>';
                wrap.appendChild(grid);

                var sched = document.createElement('div');
                sched.style.marginTop = '0.5rem';
                sched.innerHTML =
                    '<label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;font-weight:400;margin-right:1.25rem;">' +
                        '<input type="radio" name="' + rowId + '_mode" value="regular" ' + (isPromo ? '' : 'checked') + '> Use Regular Price</label>' +
                    '<label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;font-weight:400;">' +
                        '<input type="radio" name="' + rowId + '_mode" value="promo" ' + (isPromo ? 'checked' : '') + '> Use Promo Price</label>' +
                    '<input type="hidden" name="variant_price_mode[]" class="dv-price-mode-input" value="' + (isPromo ? 'promo' : 'regular') + '">' +
                    '<div class="dv-promo-time-fields" style="display:' + (isPromo ? 'flex' : 'none') + ';gap:0.75rem;margin-top:0.5rem;align-items:end;">' +
                        '<div><label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:3px;">Active from <span class="required">*</span></label>' +
                        '<input type="time" name="variant_promo_start[]" class="dv-promo-start" value="' + (promoStart || '') + '" ' + (isPromo ? 'required' : '') + '></div>' +
                        '<div><label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:3px;">to <span class="required">*</span></label>' +
                        '<input type="time" name="variant_promo_end[]"   class="dv-promo-end"   value="' + (promoEnd   || '') + '" ' + (isPromo ? 'required' : '') + '></div>' +
                    '</div>';
                wrap.appendChild(sched);
                rows.appendChild(wrap);

                var timeFields  = sched.querySelector('.dv-promo-time-fields');
                var startInput  = sched.querySelector('.dv-promo-start');
                var endInput    = sched.querySelector('.dv-promo-end');
                var modeInput   = sched.querySelector('.dv-price-mode-input');
                sched.querySelectorAll('input[type="radio"][name="' + rowId + '_mode"]').forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        var promoSelected = (this.value === 'promo');
                        modeInput.value = promoSelected ? 'promo' : 'regular';
                        timeFields.style.display = promoSelected ? 'flex' : 'none';
                        startInput.required = promoSelected;
                        endInput.required   = promoSelected;
                        if (!promoSelected) { startInput.value = ''; endInput.value = ''; }
                    });
                });

                refreshDurationRemoveButtons();
            }

            // A service can never have zero rows — hide Remove while only one is left.
            function refreshDurationRemoveButtons() {
                var rows = document.querySelectorAll('#durationVariantsRows > .duration-variant-option');
                rows.forEach(function(r) {
                    var b = r.querySelector('.dv-remove-btn');
                    if (b) b.style.display = rows.length <= 1 ? 'none' : '';
                });
            }

            function removeDurationVariantRow(btn) {
                var row = btn.closest('.duration-variant-option');
                if (row && document.querySelectorAll('#durationVariantsRows > .duration-variant-option').length > 1) {
                    row.remove();
                    refreshDurationRemoveButtons();
                }
            }

            function validateDurationVariantsOnSubmit() {
                if (document.querySelectorAll('#durationVariantsRows > .duration-variant-option').length < 1) {
                    alert('Add at least one Duration Option.');
                    return false;
                }
                return true;
            }

            (function() {
                if (svcDurationVariants.length > 0) {
                    svcDurationVariants.forEach(function(v) {
                        addDurationVariantRow(v.duration_minutes, v.regular_price, v.promo_price, v.price_mode, v.promo_start_time, v.promo_end_time, v.session_count);
                    });
                } else {
                    addDurationVariantRow();
                }
            })();
            </script>

            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">
                <span class="section-label-sm">🏠 Availability</span>
            </div>
            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:0.75rem;cursor:pointer;user-select:none;">
                        <input type="checkbox" name="is_home_service" value="1"
                               id="homeServiceToggle"
                               style="width:18px;height:18px;cursor:pointer;"
                               <?php echo !empty($edit_service['is_home_service']) ? 'checked' : ''; ?>>
                        <span>🏠 This service is available as <strong>Home Service</strong></span>
                    </label>
                    <small style="color:var(--gray);margin-top:0.3rem;display:block;">
                        If enabled, customers can choose between visiting the spa or booking a home visit.
                    </small>
                </div>
            </div>

            <div class="form-grid form-grid-2"
                 id="homeFeeSectionRow"
                 style="margin-bottom:1.25rem;<?php echo empty($edit_service['is_home_service']) ? 'display:none;' : ''; ?>">
                <div class="form-group">
                    <label>🏠 Home Service Price (₱) — Fixed Total <span class="required">*</span></label>
                    <input type="number" name="home_service_price" id="homeServicePrice"
                           step="0.01" min="0"
                           value="<?php echo floatval($edit_service['home_service_price'] ?? 0); ?>"
                           placeholder="e.g. 1500.00">
                    <small style="color:var(--gray);">
                        Kumpletong halaga para sa home service booking — direktang total, hindi add-on.
                    </small>
                </div>
            </div>

            <script>
            document.getElementById('homeServiceToggle').addEventListener('change', function() {
                const row   = document.getElementById('homeFeeSectionRow');
                const price = document.getElementById('homeServicePrice');
                if (this.checked) {
                    row.style.display = '';
                    if (price) price.required = true;
                } else {
                    row.style.display = 'none';
                    if (price) { price.required = false; price.value = '0.00'; }
                }
            });
            // Sync display + required state on page load
            (function(){
                const chk   = document.getElementById('homeServiceToggle');
                const row   = document.getElementById('homeFeeSectionRow');
                const price = document.getElementById('homeServicePrice');
                if (row)   row.style.display = chk.checked ? '' : 'none';
                if (price) price.required = chk.checked;
            })();
            </script>

            <?php if (!empty($edit_service['is_two_session'])): ?>
            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <div style="padding:0.75rem 1rem;background:var(--bg3);border:1px solid var(--border2);border-radius:8px;font-size:0.85rem;color:var(--brown);">
                        🔁 This service still has the legacy <strong>2-Session Package</strong> enabled (Price for 2 Sessions: ₱<?php echo number_format(floatval($edit_service['session2_price'] ?? 0), 2); ?>). New services can no longer add this — it's retired in favor of Duration Options' Sessions field above. Existing bookings and this service's setting are unaffected.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">
                <span class="section-label-sm">🖼️ Media</span>
            </div>
            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Service Image <?php echo !$edit_service ? '<span class="required">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_service ? 'required' : ''; ?>>
                    <?php if ($edit_service && $edit_service['image']): ?>
                        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:1rem;">
                            <img src="../uploads/services/<?php echo htmlspecialchars($edit_service['image']); ?>" 
                                 class="thumb" 
                                 alt="Current Service Image" 
                                 style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--border);">
                            <small>Current image — upload new to replace</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Description <span class="required">*</span></label>
                    <textarea name="description" rows="4" required><?php echo htmlspecialchars($edit_service['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- ─── SUPPLIES USED PER SESSION ────────────────────────────────── -->
            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">
                <span class="section-label-sm" style="color:var(--brown-md);">🧴 Supplies Used per Session</span>
                <p style="font-size:0.83rem;color:var(--gray);margin:0.4rem 0 1rem;">
                    Define which supplies are consumed per person when this service is completed.
                    Quantities are in base units (ml, g, pcs…). Leave empty if no supplies are deducted.
                </p>
                <style>
                .recipe-supply-sel, .recipe-qty-inp {
                    background: #fff;
                    border: 2px solid var(--border);
                    border-radius: 6px;
                    padding: 0.55rem 0.75rem;
                    font-family: var(--font-body);
                    font-size: 0.875rem;
                    color: var(--brown);
                    transition: all 0.18s;
                    box-sizing: border-box;
                }
                .recipe-supply-sel:focus, .recipe-qty-inp:focus {
                    outline: none;
                    border-color: var(--rust);
                    box-shadow: 0 0 0 3px rgba(201,106,44,0.12);
                }
                </style>
                <div style="overflow-x:auto;">
                    <div id="recipeColHeaders"
                         style="display:flex;gap:0.75rem;margin-bottom:0.4rem;<?php echo count($svc_recipe) > 0 ? '' : 'display:none;'; ?>">
                        <div style="flex:1;min-width:200px;font-size:0.7rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gray);">Supply</div>
                        <div style="width:85px;flex-shrink:0;font-size:0.7rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gray);">Qty / person</div>
                        <div style="width:40px;flex-shrink:0;font-size:0.7rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gray);">Unit</div>
                        <div style="width:32px;flex-shrink:0;"></div>
                    </div>
                    <div id="recipeRows">
                        <p id="recipeEmpty"
                           style="color:var(--gray);font-size:0.83rem;margin:0.5rem 0;<?php echo count($svc_recipe) > 0 ? 'display:none;' : ''; ?>">
                            No supplies added yet — click <strong>+ Add Supply</strong> to define which items are consumed per session.
                        </p>
                        <?php foreach ($svc_recipe as $rrow):
                            $rsid        = (int)$rrow['supply_id'];
                            $is_archived = !isset($supply_map_svc[$rsid]);
                            if ($is_archived):
                                $arch_info = $_archived_in_recipe[$rsid] ?? null;
                                $arch_name = $arch_info ? htmlspecialchars($arch_info['name']) : '(Unknown supply #' . $rsid . ')';
                                $arch_bul  = htmlspecialchars($arch_info['base_unit_label'] ?? '');
                        ?>
                        <div class="recipe-row" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.6rem;background:#fff8f8;border:1.5px dashed #fca5a5;border-radius:8px;padding:0.5rem 0.65rem;">
                            <div style="flex:1;min-width:200px;">
                                <span style="font-weight:600;color:var(--brown);"><?php echo $arch_name; ?></span>
                                <span style="display:inline-block;margin-left:0.35rem;padding:0.1rem 0.45rem;font-size:0.68rem;font-weight:700;background:#fee2e2;color:#991b1b;border-radius:4px;text-transform:uppercase;letter-spacing:0.05em;">archived</span>
                                <input type="hidden" name="recipe_supply_id_archived[]" value="<?php echo $rsid; ?>">
                                <div style="font-size:0.72rem;color:#b91c1c;margin-top:0.2rem;">This ingredient has been archived. Remove this row or replace it with an active supply.</div>
                            </div>
                            <input type="number" name="recipe_qty_per_person_archived[]"
                                   class="recipe-qty-inp"
                                   step="0.0001" min="0.0001"
                                   value="<?php echo htmlspecialchars($rrow['quantity_per_person']); ?>"
                                   style="width:85px;flex-shrink:0;">
                            <span class="recipe-unit-label"
                                  style="width:40px;flex-shrink:0;color:#b91c1c;font-size:0.82rem;">
                                <?php echo $arch_bul; ?>
                            </span>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="removeRecipeRow(this)"
                                    style="flex-shrink:0;">×</button>
                        </div>
                        <?php else:
                            $row_opts = str_replace(
                                '<option value="' . $rsid . '" data-unit=',
                                '<option value="' . $rsid . '" selected data-unit=',
                                $recipe_opts_html
                            );
                            $bul = htmlspecialchars($supply_map_svc[$rsid]['base_unit_label'] ?? '');
                        ?>
                        <div class="recipe-row" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.6rem;">
                            <select name="recipe_supply_id[]" class="recipe-supply-sel"
                                    onchange="updateRecipeUnit(this)"
                                    style="flex:1;min-width:200px;">
                                <?php echo $row_opts; ?>
                            </select>
                            <input type="number" name="recipe_qty_per_person[]"
                                   class="recipe-qty-inp"
                                   step="0.0001" min="0.0001"
                                   value="<?php echo htmlspecialchars($rrow['quantity_per_person']); ?>"
                                   style="width:85px;flex-shrink:0;">
                            <span class="recipe-unit-label"
                                  style="width:40px;flex-shrink:0;color:var(--gray);font-size:0.82rem;">
                                <?php echo $bul; ?>
                            </span>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="removeRecipeRow(this)"
                                    style="flex-shrink:0;">×</button>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:0.75rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addRecipeRow()">
                        + Add Supply
                    </button>
                    <span id="recipeDupeWarning"
                          style="color:var(--rust);font-size:0.82rem;display:none;">
                        ⚠️ Duplicate supply — each supply can only appear once per service.
                    </span>
                </div>
            </div>
            <script>
            var _recipeOptsTpl = <?php echo json_encode($recipe_opts_html); ?>;

            function addRecipeRow(selVal, qty) {
                selVal = selVal || '';
                qty    = qty    || '';
                var container = document.getElementById('recipeRows');
                var div = document.createElement('div');
                div.className = 'recipe-row';
                div.style.cssText = 'display:flex;align-items:center;gap:0.75rem;margin-bottom:0.6rem;';
                div.innerHTML =
                    '<select name="recipe_supply_id[]" class="recipe-supply-sel" onchange="updateRecipeUnit(this)" style="flex:1;min-width:200px;">' +
                        _recipeOptsTpl +
                    '</select>' +
                    '<input type="number" name="recipe_qty_per_person[]" class="recipe-qty-inp" step="0.0001" min="0.0001" value="' + qty + '" style="width:85px;flex-shrink:0;">' +
                    '<span class="recipe-unit-label" style="width:40px;flex-shrink:0;color:var(--gray);font-size:0.82rem;"></span>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="removeRecipeRow(this)" style="flex-shrink:0;">×</button>';
                container.appendChild(div);
                if (selVal) {
                    var sel = div.querySelector('select');
                    sel.value = selVal;
                    updateRecipeUnit(sel);
                }
                checkRecipeDupes();
                checkRecipeEmpty();
                var inp = div.querySelector('input[type=number]');
                if (inp && !qty) inp.focus();
            }

            function removeRecipeRow(btn) {
                var row = btn.closest('.recipe-row');
                if (row) { row.remove(); checkRecipeDupes(); checkRecipeEmpty(); }
            }

            function updateRecipeUnit(sel) {
                var opt  = sel.options[sel.selectedIndex];
                var span = sel.closest('.recipe-row').querySelector('.recipe-unit-label');
                if (span) span.textContent = (opt && opt.dataset.unit) ? opt.dataset.unit : '';
                checkRecipeDupes();
            }

            function checkRecipeDupes() {
                var sels = document.querySelectorAll('#recipeRows .recipe-supply-sel');
                var seen = {}, hasDupe = false;
                sels.forEach(function(s) {
                    var v = s.value; if (!v) return;
                    if (seen[v]) hasDupe = true;
                    seen[v] = true;
                });
                var w = document.getElementById('recipeDupeWarning');
                if (w) w.style.display = hasDupe ? '' : 'none';
            }

            function checkRecipeEmpty() {
                var hasRows = document.querySelectorAll('#recipeRows .recipe-row').length > 0;
                var empty = document.getElementById('recipeEmpty');
                var hdr   = document.getElementById('recipeColHeaders');
                if (empty) empty.style.display = hasRows ? 'none' : '';
                if (hdr)   hdr.style.display   = hasRows ? ''     : 'none';
            }

            // Init unit labels and empty state on pre-populated rows
            document.querySelectorAll('#recipeRows .recipe-supply-sel').forEach(function(s) {
                updateRecipeUnit(s);
            });
            checkRecipeEmpty();
            </script>
            <!-- ──────────────────────────────────────────────────────────── -->

            <?php if (is_cashier()): ?>
            <div class="form-group" style="max-width:200px;">
                <label>Your 4-digit PIN <span class="required">*</span></label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required
                       style="letter-spacing:0.3em;text-align:center;font-size:1rem;">
            </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo $edit_service ? '✅ Update Service' : '✅ Add Service'; ?>
                </button>
                <a href="services.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php if (!$edit_service): ?>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">💆 All Services</span>
        <?php if (!isset($_GET['action'])): ?>
            <a href="services.php?action=add" class="btn btn-primary btn-sm">+ Add New Service</a>
        <?php endif; ?>
    </div>

    <?php $filter_cat = $_GET['category'] ?? 'all'; ?>
    <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--border);">
        <div class="filter-tabs">
            <a href="services.php" class="filter-tab <?php echo $filter_cat === 'all' ? 'active' : ''; ?>">All</a>
            <a href="services.php?category=none" class="filter-tab <?php echo $filter_cat === 'none' ? 'active' : ''; ?>">Uncategorized</a>
            <?php foreach ($categories as $cat): ?>
                <a href="services.php?category=<?php echo $cat['id']; ?>"
                   class="filter-tab <?php echo $filter_cat == $cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <style>
    .svc-search-wrap { position:relative; margin:1rem 1.5rem 0.75rem; display:flex; align-items:center; }
    .svc-search-icon { position:absolute; left:0.85rem; font-size:0.9rem; pointer-events:none; opacity:0.55; }
    .svc-search-input {
        width:100%; padding:0.55rem 2.5rem 0.55rem 2.4rem;
        border:1.5px solid var(--border2,#e5e0d8); border-radius:10px;
        background:var(--bg3,#f7f5f2); color:var(--brown,#3B2A1A);
        font-size:0.9rem; outline:none; box-sizing:border-box;
        transition:border-color .15s, box-shadow .15s;
    }
    .svc-search-input:focus {
        border-color:var(--rust,#A94F1D);
        box-shadow:0 0 0 3px rgba(169,79,29,0.12);
        background:#fff;
    }
    .svc-search-clear {
        position:absolute; right:0.75rem; background:none; border:none;
        color:var(--gray,#6b7280); cursor:pointer; font-size:0.85rem;
        padding:0.2rem 0.3rem; border-radius:4px; line-height:1;
    }
    .svc-search-clear:hover { color:var(--rust,#A94F1D); }
    </style>

    <div class="svc-search-wrap">
        <span class="svc-search-icon">🔍</span>
        <input type="search" id="serviceSearchInput" class="svc-search-input"
               placeholder="Search by name or category…"
               oninput="filterServices(this.value)"
               autocomplete="off">
        <button type="button" id="svcSearchClear" class="svc-search-clear"
                onclick="clearServiceSearch()" style="display:none;">✕</button>
    </div>

    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Price</th>
                    <th>Duration</th>
                    <th>Recipe</th>
                    <th>Date Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $filtered = array_filter($services, function($s) use ($filter_cat) {
                    if ($filter_cat === 'all')  return true;
                    if ($filter_cat === 'none') return empty($s['category_id']);
                    return $s['category_id'] == $filter_cat;
                });
                ?>
                <?php if (!empty($filtered)): ?>
                    <?php foreach ($filtered as $service): ?>
                    <tr class="service-row"
                        data-search="<?php echo htmlspecialchars(strtolower($service['name'].' '.($service['category_name'] ?? ''))); ?>"
                        <?php if ($service['deleted_at']): ?> style="opacity:0.5;"<?php endif; ?>>
                        <td>
                            <?php if (!empty($service['image'])): ?>
                                <img src="../uploads/services/<?php echo htmlspecialchars($service['image']); ?>"
                                     class="thumb" 
                                     alt="Service Thumbnail"
                                     style="width:50px;height:50px;object-fit:cover;border-radius:6px;">
                            <?php else: ?>
                                <div class="no-thumb">No img</div>
                            <?php endif; ?>
                        </td>
                        
                        <td><strong><?php echo htmlspecialchars($service['name']); ?></strong><?php if ($service['deleted_at']): ?> <span class="badge" style="background:#6c757d;color:#fff;font-size:0.68rem;">ARCHIVED</span><?php endif; ?></td>
                        <td>
                            <?php if ($service['category_name']): ?>
                                <span class="badge badge-approved"><?php echo htmlspecialchars($service['category_name']); ?></span>
                            <?php else: ?>
                                <span class="badge" style="background:var(--surface);color:var(--gray);">Uncategorized</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($service['is_home_service'])): ?>
                                <span class="badge badge-online">🏠 Home</span>
                            <?php else: ?>
                                <span class="badge badge-onsite">🏪 Onsite</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color:var(--rust);">₱<?php echo number_format($service['price'], 2); ?></strong></td>
                        <td style="color:var(--gray);">
                            <?php $_svc_durs = $svc_durations_by_service[$service['id']] ?? []; ?>
                            <?php if (!empty($_svc_durs)): ?>
                                <?php $_svc_durs_lbl = implode(' / ', array_map(fn($d) => $d['duration_minutes'] . ($d['session_count'] > 1 ? '×' . $d['session_count'] : ''), $_svc_durs)); ?>
                                <span style="background:rgba(13,110,253,0.1);color:#0d6efd;padding:0.1rem 0.45rem;border-radius:20px;font-size:0.68rem;font-weight:700;border:1px solid #9ec5fe;display:inline-block;">⏱ <?php echo $_svc_durs_lbl; ?> mins</span>
                            <?php else: ?>
                                ⏱ <?php echo $service['session_time']; ?> mins
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php $rcnt = $svc_recipe_counts[$service['id']] ?? 0; ?>
                            <?php if ($rcnt > 0): ?>
                                <span class="badge badge-completed" style="font-size:0.7rem;">🧴 <?php echo $rcnt; ?></span>
                            <?php else: ?>
                                <span style="color:var(--gray);font-size:0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.78rem;color:var(--gray);">
                            <?php echo date('M d, Y', strtotime($service['created_at'])); ?>
                        </td>
                        <td>
                            <?php if ($service['deleted_at']): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="restore_service">
                                <input type="hidden" name="id" value="<?php echo intval($service['id']); ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">♻️ Restore</button>
                            </form>
                            <?php else: ?>
                            <a href="services.php?edit=<?php echo $service['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                            <form method="POST" style="display:inline;" onsubmit="event.preventDefault();uiConfirm('Archive this service?').then(ok=>{if(ok)this.submit()})">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="id" value="<?php echo intval($service['id']); ?>">
                                <?php if (is_cashier()): ?>
                                <input type="password" name="pin" maxlength="4" placeholder="PIN" required
                                       style="width:58px;padding:0.25rem 0.4rem;border:1px solid var(--border2);border-radius:6px;font-size:0.8rem;letter-spacing:0.2em;text-align:center;vertical-align:middle;">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-danger btn-sm">🗃️ Archive</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--gray);padding:2rem;">
                            No services found in this category.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<div id="svcNoResults" style="display:none;text-align:center;padding:2rem 1rem;color:var(--gray);font-size:0.9rem;background:var(--bg3);border-radius:12px;margin:0 1.5rem 1rem;">
    No services match your search.
</div>

<script>
function filterServices(query) {
    var q   = query.trim().toLowerCase();
    var rows = document.querySelectorAll('tr.service-row');
    var visible = 0;
    rows.forEach(function(row) {
        var match = !q || (row.dataset.search || '').indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    var noResults = document.getElementById('svcNoResults');
    if (noResults) noResults.style.display = (q && visible === 0) ? '' : 'none';
    var clearBtn = document.getElementById('svcSearchClear');
    if (clearBtn) clearBtn.style.display = q ? '' : 'none';
}

function clearServiceSearch() {
    var input = document.getElementById('serviceSearchInput');
    if (input) { input.value = ''; input.focus(); }
    filterServices('');
}
</script>

<?php require_once 'admin_footer.php'; ?>