<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$SUPPLY_CATS = ['Massage','Nails','Lashes','Aesthetics','Assorted','Product','Product Sold'];
$msg = ''; $msg_type = 'success';

// ─── FETCH SUPPLIES (top — needed by POST handlers AND display) ───────────────
$supplies = [];
$_sr = $conn->query("SELECT * FROM supplies WHERE deleted_at IS NULL ORDER BY category, name");
while ($row = $_sr->fetch_assoc()) $supplies[] = $row;
$supply_map     = array_column($supplies, null, 'id');
// Recipe usage map: supply_id => ['cnt' => N, 'names' => 'Svc A, Svc B'] — for archive warning
$supply_recipe_usage = [];
$_sru_q = $conn->query("SELECT ssu.supply_id, COUNT(*) AS cnt, GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') AS svc_names FROM service_supply_usage ssu JOIN services sv ON sv.id = ssu.service_id WHERE sv.deleted_at IS NULL GROUP BY ssu.supply_id");
if ($_sru_q) { while ($row = $_sru_q->fetch_assoc()) { $supply_recipe_usage[(int)$row['supply_id']] = ['cnt' => (int)$row['cnt'], 'names' => $row['svc_names']]; } }
$total_items    = count($supplies);
$low_stock_n    = 0; $out_of_stock_n = 0;
foreach ($supplies as $_s) {
    if ((float)$_s['current_stock'] <= 0) $out_of_stock_n++;
    elseif ($_s['reorder_level'] !== null && (float)$_s['current_stock'] <= (float)$_s['reorder_level']) $low_stock_n++;
}
$grouped = [];
foreach ($supplies as $_s) $grouped[$_s['category']][] = $_s;

// ─── POST: ARCHIVE SUPPLY ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_supply') {
    verify_csrf_token();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE supplies SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
    header("Location: inventory.php?tab=supplies&ok=archived"); exit();
}

// ─── POST: RESTORE SUPPLY ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_supply') {
    verify_csrf_token();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE supplies SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
    header("Location: inventory.php?tab=supplies&ok=restored"); exit();
}

// ─── POST: ADD / EDIT SUPPLY ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_supply','edit_supply'])) {
    verify_csrf_token();
    $is_edit          = ($_POST['action'] === 'edit_supply');
    $id               = (int)($_POST['id'] ?? 0);
    $name             = trim($_POST['name'] ?? '');
    $category         = trim($_POST['category'] ?? '');
    $uom_label        = strtoupper(trim($_POST['uom_label'] ?? ''));
    $base_unit_label  = trim($_POST['base_unit_label'] ?? '');
    $supplier_price   = max(0.0, (float)($_POST['supplier_price'] ?? 0));
    $reorder_raw      = trim($_POST['reorder_level'] ?? '');
    $reorder_level    = ($reorder_raw !== '') ? max(0.0, (float)$reorder_raw) : null;
    $notes            = trim($_POST['notes'] ?? '') ?: null;

    $has_inner_level  = (int)(($_POST['has_inner_level'] ?? '0') === '1');
    $inners_per_case  = null;
    $pieces_per_inner = null;
    $ipc_v = 0.0; $ppi_v = 0.0;
    if ($has_inner_level) {
        $ipc_v = (float)(trim($_POST['inners_per_case']  ?? ''));
        $ppi_v = (float)(trim($_POST['pieces_per_inner'] ?? ''));
        if ($ipc_v > 0 && $ppi_v > 0) {
            $inners_per_case  = $ipc_v;
            $pieces_per_inner = $ppi_v;
            $content_per_unit = $ipc_v * $ppi_v;
        }
    } else {
        $content_per_unit = max(0.0001, (float)($_POST['content_per_unit'] ?? 1));
    }

    if ($has_inner_level && ($ipc_v <= 0 || $ppi_v <= 0)) {
        $msg = 'For inner-packaging items, both Inners per Case and Pieces per Inner must be greater than zero.'; $msg_type = 'danger';
    } elseif ($name === '' || $category === '' || $uom_label === '' || $base_unit_label === '') {
        $msg = 'Name, category, UOM label, and base unit label are required.'; $msg_type = 'danger';
    } elseif (!in_array($category, $SUPPLY_CATS, true)) {
        $msg = 'Invalid category selected.'; $msg_type = 'danger';
    } else {
        if (!$is_edit) {
            $stmt = $conn->prepare("INSERT INTO supplies (name,category,uom_label,content_per_unit,base_unit_label,supplier_price,reorder_level,notes,has_inner_level,inners_per_case,pieces_per_inner) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssdsddsidd", $name,$category,$uom_label,$content_per_unit,$base_unit_label,$supplier_price,$reorder_level,$notes,$has_inner_level,$inners_per_case,$pieces_per_inner);
        } else {
            $stmt = $conn->prepare("UPDATE supplies SET name=?,category=?,uom_label=?,content_per_unit=?,base_unit_label=?,supplier_price=?,reorder_level=?,notes=?,has_inner_level=?,inners_per_case=?,pieces_per_inner=? WHERE id=? AND deleted_at IS NULL");
            $stmt->bind_param("sssdsddsiddi", $name,$category,$uom_label,$content_per_unit,$base_unit_label,$supplier_price,$reorder_level,$notes,$has_inner_level,$inners_per_case,$pieces_per_inner,$id);
        }
        if ($stmt->execute()) {
            header("Location: inventory.php?tab=supplies&ok=" . ($is_edit ? 'updated' : 'added')); exit();
        } else {
            $msg = 'Database error: ' . htmlspecialchars($conn->error); $msg_type = 'danger';
        }
        $stmt->close();
    }
}

// ─── POST: LOG DELIVERY ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_delivery') {
    verify_csrf_token();
    $supply_id      = (int)($_POST['supply_id'] ?? 0);
    $delivery_date  = trim($_POST['delivery_date'] ?? '');
    $qty_units      = (float)($_POST['quantity_units'] ?? 0);
    $delivery_level = trim($_POST['delivery_level'] ?? 'case');
    $notes_del      = trim($_POST['notes'] ?? '') ?: null;
    $added_by       = (int)($_SESSION['user_id'] ?? 0);
    $added_by_name  = trim($_SESSION['username'] ?? 'Admin');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date) || !strtotime($delivery_date))
        $delivery_date = date('Y-m-d');
    if ($qty_units <= 0) {
        $msg = 'Quantity must be greater than zero.'; $msg_type = 'danger';
    } else {
        $sr = $supply_map[$supply_id] ?? null;
        if (!$sr) {
            $msg = 'Supply not found or has been archived.'; $msg_type = 'danger';
        } else {
            $has_inner_d = !empty($sr['has_inner_level']);
            if ($has_inner_d && $delivery_level === 'inner') {
                $ppi_d    = max(0.0001, (float)$sr['pieces_per_inner']);
                $qty_base = $qty_units * $ppi_d;
            } else {
                $cpu_d    = max(0.0001, (float)$sr['content_per_unit']);
                $qty_base = $qty_units * $cpu_d;
            }
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare("INSERT INTO supply_deliveries (supply_id,delivery_date,quantity_units,quantity_base,added_by,added_by_name,notes) VALUES (?,?,?,?,?,?,?)");
                $ins->bind_param("isddiss", $supply_id,$delivery_date,$qty_units,$qty_base,$added_by,$added_by_name,$notes_del);
                $ins->execute(); $ins->close();
                $upd = $conn->prepare("UPDATE supplies SET current_stock = current_stock + ? WHERE id = ?");
                $upd->bind_param("di", $qty_base, $supply_id);
                $upd->execute(); $upd->close();
                $conn->commit();
                header("Location: inventory.php?tab=deliveries&ok=logged"); exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = 'Transaction failed. Please try again.'; $msg_type = 'danger';
            }
        }
    }
}

// ─── POST: SUBMIT COUNT ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_count') {
    verify_csrf_token();
    $count_date      = trim($_POST['count_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $count_date) || !strtotime($count_date))
        $count_date = date('Y-m-d');
    $counted_by      = (int)($_SESSION['user_id'] ?? 0);
    $counted_by_name = trim($_SESSION['username'] ?? 'Admin');
    $whole_units  = (array)($_POST['whole_units']  ?? []);
    $partial_base = (array)($_POST['partial_base'] ?? []);
    $whole_cases  = (array)($_POST['whole_cases']  ?? []);
    $whole_inners = (array)($_POST['whole_inners'] ?? []);
    $loose_pieces = (array)($_POST['loose_pieces'] ?? []);

    // Collect entered supply IDs — different inputs for flat vs inner-level
    $entered_ids = [];
    $all_sids = array_unique(array_merge(
        array_keys($whole_units), array_keys($partial_base),
        array_keys($whole_cases), array_keys($whole_inners), array_keys($loose_pieces)
    ));
    foreach ($all_sids as $raw_sid) {
        $sid = (int)$raw_sid;
        if ($sid <= 0 || !isset($supply_map[$sid])) continue;
        $sd = $supply_map[$sid];
        if (!empty($sd['has_inner_level'])) {
            $wc_s = trim($whole_cases[$sid]  ?? $whole_cases[$raw_sid]  ?? '');
            $wi_s = trim($whole_inners[$sid] ?? $whole_inners[$raw_sid] ?? '');
            $lp_s = trim($loose_pieces[$sid] ?? $loose_pieces[$raw_sid] ?? '');
            if ($wc_s === '' && $wi_s === '' && $lp_s === '') continue;
        } else {
            $wu_s = trim($whole_units[$sid]  ?? $whole_units[$raw_sid]  ?? '');
            $pb_s = trim($partial_base[$sid] ?? $partial_base[$raw_sid] ?? '');
            if ($wu_s === '' && $pb_s === '') continue;
        }
        $entered_ids[] = $sid;
    }

    if (empty($entered_ids)) {
        $msg = 'No items were entered. Fill in at least one count row before submitting.';
        $msg_type = 'danger';
    } else {
        $count_n = 0; $total_net_peso = 0.0;
        $conn->begin_transaction();
        try {
            foreach ($entered_ids as $sid) {
                $sd       = $supply_map[$sid];
                $expected = (float)$sd['current_stock'];
                $cpu_s    = max(0.0001, (float)$sd['content_per_unit']);

                if (!empty($sd['has_inner_level'])) {
                    $ipc_s = max(0.0001, (float)$sd['inners_per_case']);
                    $ppi_s = max(0.0001, (float)$sd['pieces_per_inner']);
                    $wc_s  = trim($whole_cases[$sid]  ?? '');
                    $wi_s  = trim($whole_inners[$sid] ?? '');
                    $lp_s  = trim($loose_pieces[$sid] ?? '');
                    $wc    = $wc_s !== '' ? max(0, (int)$wc_s) : 0;
                    $wi    = $wi_s !== '' ? max(0, (int)$wi_s) : 0;
                    $lp    = $lp_s !== '' ? max(0.0, (float)$lp_s) : 0.0;
                    $pb    = ($wi * $ppi_s) + $lp;
                    $wu    = $wc;  // whole_units_counted = whole cases
                    $total = ($wc * $ipc_s * $ppi_s) + $pb;
                } else {
                    $wu_s  = trim($whole_units[$sid]  ?? '');
                    $pb_s  = trim($partial_base[$sid] ?? '');
                    $wu    = $wu_s !== '' ? max(0, (int)$wu_s) : 0;
                    $pb    = $pb_s !== '' ? max(0.0, (float)$pb_s) : 0.0;
                    $total = ($wu * $cpu_s) + $pb;
                }
                $variance = $total - $expected;

                $ins = $conn->prepare(
                    "INSERT INTO supply_counts (supply_id,count_date,whole_units_counted,partial_base_counted,total_base_counted,expected_stock,variance,counted_by,counted_by_name)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $ins->bind_param("isiddddis",
                    $sid, $count_date, $wu, $pb, $total, $expected, $variance,
                    $counted_by, $counted_by_name);
                $ins->execute(); $ins->close();

                $upd = $conn->prepare("UPDATE supplies SET current_stock = ? WHERE id = ?");
                $upd->bind_param("di", $total, $sid);
                $upd->execute(); $upd->close();

                $unit_cost = $cpu_s > 0 ? (float)$sd['supplier_price'] / $cpu_s : 0.0;
                $total_net_peso += $variance * $unit_cost;
                $count_n++;
            }
            $conn->commit();
            $_SESSION['count_flash'] = ['n' => $count_n, 'net_peso' => $total_net_peso];
            header("Location: inventory.php?tab=count&mode=history&ok=counted"); exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Transaction failed. Please try again.'; $msg_type = 'danger';
        }
    }
}

// ─── TAB / MODE ROUTING ───────────────────────────────────────────────────────
$active_tab = $_GET['tab']  ?? 'supplies';
$count_mode = $_GET['mode'] ?? 'new';

// ─── FETCH DELIVERIES ─────────────────────────────────────────────────────────
$deliveries = []; $del_total = 0; $del_total_pages = 1; $del_page = 1; $df = ''; $dt = '';
if ($active_tab === 'deliveries') {
    $del_page = max(1, (int)($_GET['dp'] ?? 1));
    $del_pp   = 25; $del_off = ($del_page - 1) * $del_pp;
    $df = trim($_GET['df'] ?? ''); $dt = trim($_GET['dt'] ?? '');
    if ($df === '' && $dt === '') { $df = date('Y-m-d', strtotime('-30 days')); $dt = date('Y-m-d'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) $df = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = date('Y-m-d');
    if ($df > $dt) { $tmp = $df; $df = $dt; $dt = $tmp; unset($tmp); }
    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM supply_deliveries WHERE delivery_date BETWEEN ? AND ?");
    $cs->bind_param("ss", $df, $dt); $cs->execute();
    $del_total = (int)$cs->get_result()->fetch_assoc()['c']; $cs->close();
    $del_total_pages = max(1, (int)ceil($del_total / $del_pp));
    $dls = $conn->prepare("SELECT d.*, s.name AS supply_name, s.category, s.uom_label, s.base_unit_label FROM supply_deliveries d JOIN supplies s ON s.id=d.supply_id WHERE d.delivery_date BETWEEN ? AND ? ORDER BY d.delivery_date DESC, d.id DESC LIMIT ? OFFSET ?");
    $dls->bind_param("ssii", $df, $dt, $del_pp, $del_off); $dls->execute();
    $deliveries = $dls->get_result()->fetch_all(MYSQLI_ASSOC); $dls->close();
}

// ─── FETCH COUNT HISTORY ──────────────────────────────────────────────────────
$cnt_history = []; $cnt_dates_total = 0; $cnt_dates_pages = 1; $cnt_page = 1; $cdf = ''; $cdt = '';
if ($active_tab === 'count' && $count_mode === 'history') {
    $cnt_page = max(1, (int)($_GET['cp'] ?? 1));
    $cnt_pp   = 10; $cnt_off = ($cnt_page - 1) * $cnt_pp;
    $cdf = trim($_GET['cdf'] ?? ''); $cdt = trim($_GET['cdt'] ?? '');
    if ($cdf === '' && $cdt === '') { $cdf = date('Y-m-d', strtotime('-30 days')); $cdt = date('Y-m-d'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cdf)) $cdf = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cdt)) $cdt = date('Y-m-d');
    if ($cdf > $cdt) { $tmp = $cdf; $cdf = $cdt; $cdt = $tmp; unset($tmp); }

    $ccs = $conn->prepare("SELECT COUNT(DISTINCT count_date) AS c FROM supply_counts WHERE count_date BETWEEN ? AND ?");
    $ccs->bind_param("ss", $cdf, $cdt); $ccs->execute();
    $cnt_dates_total = (int)$ccs->get_result()->fetch_assoc()['c']; $ccs->close();
    $cnt_dates_pages = max(1, (int)ceil($cnt_dates_total / $cnt_pp));

    $cds = $conn->prepare("SELECT DISTINCT count_date FROM supply_counts WHERE count_date BETWEEN ? AND ? ORDER BY count_date DESC LIMIT ? OFFSET ?");
    $cds->bind_param("ssii", $cdf, $cdt, $cnt_pp, $cnt_off); $cds->execute();
    $cnt_date_rows = $cds->get_result()->fetch_all(MYSQLI_ASSOC); $cds->close();

    foreach ($cnt_date_rows as $dr) {
        $date = $dr['count_date'];
        $crs = $conn->prepare("SELECT sc.*, s.name AS supply_name, s.category, s.uom_label, s.base_unit_label, s.supplier_price, s.content_per_unit FROM supply_counts sc JOIN supplies s ON s.id=sc.supply_id WHERE sc.count_date=? ORDER BY s.category, s.name");
        $crs->bind_param("s", $date); $crs->execute();
        $cnt_rows = $crs->get_result()->fetch_all(MYSQLI_ASSOC); $crs->close();
        $net_peso = 0.0; $bynames = [];
        foreach ($cnt_rows as $cr) {
            $cpu_r = max(0.0001, (float)$cr['content_per_unit']);
            $net_peso += (float)$cr['variance'] * ((float)$cr['supplier_price'] / $cpu_r);
            if ($cr['counted_by_name'] && !in_array($cr['counted_by_name'], $bynames, true))
                $bynames[] = $cr['counted_by_name'];
        }
        $cnt_history[$date] = ['rows' => $cnt_rows, 'n' => count($cnt_rows), 'net_peso' => $net_peso, 'by' => implode(', ', $bynames)];
    }
}

// ─── FLASH MESSAGES ───────────────────────────────────────────────────────────
$ok_flash = $_GET['ok'] ?? '';
if (!$msg) {
    if     ($ok_flash === 'added')    { $msg = '✅ Supply added.';           $msg_type = 'success'; }
    elseif ($ok_flash === 'updated')  { $msg = '✅ Supply updated.';         $msg_type = 'success'; }
    elseif ($ok_flash === 'archived') { $msg = '🗃️ Supply archived.';       $msg_type = 'success'; }
    elseif ($ok_flash === 'restored') { $msg = '♻️ Supply restored.';       $msg_type = 'success'; }
    elseif ($ok_flash === 'logged')   { $msg = '✅ Delivery logged. Stock updated.'; $msg_type = 'success'; }
    elseif ($ok_flash === 'counted' && isset($_SESSION['count_flash'])) {
        $cf  = $_SESSION['count_flash'];
        $nv  = $cf['net_peso'];
        $sign = $nv >= 0 ? '+' : '';
        $word = $nv >= 0 ? 'surplus' : 'shortage';
        $msg = '✅ Count submitted: ' . $cf['n'] . ' item' . ($cf['n'] !== 1 ? 's' : '')
             . ' | Net variance: ' . $sign . '₱' . number_format(abs($nv), 2) . ' ' . $word;
        unset($_SESSION['count_flash']);
        $msg_type = 'success';
    }
}

$page_title  = 'Inventory';
$page_icon   = '🧴';
$active_page = 'inventory';
require_once 'admin_header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- ── TABS ──────────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:0.5rem;margin-bottom:1.5rem;
            border-bottom:2px solid var(--border2);padding-bottom:0;flex-wrap:wrap;">
<?php foreach (['supplies'=>'🧴 Supplies','deliveries'=>'🚚 Deliveries','count'=>'📋 Physical Count'] as $tk=>$tl):
    $ia = ($active_tab === $tk); ?>
    <a href="inventory.php?tab=<?php echo $tk; ?>"
       style="padding:0.6rem 1.25rem;font-size:0.85rem;font-weight:700;text-decoration:none;
              border-radius:8px 8px 0 0;border:2px solid var(--border2);border-bottom:none;
              background:<?php echo $ia?'var(--brown)':'var(--bg3)';?>;
              color:<?php echo $ia?'#fff':'var(--brown)';?>;
              margin-bottom:-2px;transition:background .15s;"><?php echo $tl;?></a>
<?php endforeach; ?>
</div>


<!-- ════════════════ TAB: SUPPLIES ════════════════════════════════════════════ -->
<?php if ($active_tab === 'supplies'): ?>
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.5rem;">
    <div class="stat-card"><div class="stat-icon">🧴</div><div class="stat-number"><?php echo $total_items;?></div><div class="stat-label">Total Items</div></div>
    <div class="stat-card amber"><div class="stat-icon">⚠️</div><div class="stat-number"><?php echo $low_stock_n;?></div><div class="stat-label">Low Stock</div></div>
    <div class="stat-card"><div class="stat-icon">🚫</div><div class="stat-number" style="<?php echo $out_of_stock_n>0?'color:#dc2626;':'';?>"><?php echo $out_of_stock_n;?></div><div class="stat-label">Out of Stock</div></div>
</div>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🧴 Supplies</span>
        <div style="display:flex;gap:0.5rem;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="openDeliveryModal(null)">🚚 Log Delivery</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddModal()">+ Add Supply</button>
        </div>
    </div>
    <style>
    .inv-search-wrap{position:relative;margin:1rem 1.5rem .75rem;display:flex;align-items:center;}
    .inv-search-icon{position:absolute;left:.85rem;font-size:.9rem;pointer-events:none;opacity:.55;}
    .inv-search-input{width:100%;padding:.55rem 2.5rem .55rem 2.4rem;border:1.5px solid var(--border2,#e5e0d8);border-radius:10px;background:var(--bg3,#f7f5f2);color:var(--brown,#3B2A1A);font-size:.9rem;outline:none;box-sizing:border-box;transition:border-color .15s,box-shadow .15s;}
    .inv-search-input:focus{border-color:var(--rust,#A94F1D);box-shadow:0 0 0 3px rgba(169,79,29,.12);background:#fff;}
    .inv-search-clear{position:absolute;right:.75rem;background:none;border:none;color:var(--gray,#6b7280);cursor:pointer;font-size:.85rem;padding:.2rem .3rem;border-radius:4px;line-height:1;}
    .inv-search-clear:hover{color:var(--rust,#A94F1D);}
    .inv-cat-hdr{background:var(--bg3,#f7f5f2)!important;}
    .inv-cat-hdr td{padding:.45rem 1rem!important;font-size:.76rem;font-weight:700;color:var(--gray,#6b7280);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border2,#e5e0d8);}
    </style>
    <div class="inv-search-wrap">
        <span class="inv-search-icon">🔍</span>
        <input type="search" id="invSearchInput" class="inv-search-input"
               placeholder="Search by name or category…" oninput="filterInvSupplies(this.value)" autocomplete="off">
        <button type="button" id="invSearchClear" class="inv-search-clear" onclick="clearInvSearch()" style="display:none;">✕</button>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>Name</th><th>UOM</th><th style="text-align:right;">Stock (base)</th><th style="text-align:right;">Stock (units)</th><th style="text-align:right;">Unit Cost</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($supplies)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--gray);padding:2.5rem;">No supplies yet. Click <strong>+ Add Supply</strong> to get started.</td></tr>
            <?php else: ?>
                <?php foreach ($grouped as $cat => $rows): ?>
                <tr class="inv-cat-hdr"><td colspan="7">📦 <?php echo htmlspecialchars($cat);?> <span style="font-weight:400;opacity:.7;">(<?php echo count($rows);?>)</span></td></tr>
                <?php foreach ($rows as $s):
                    $stock=$s['current_stock']; $cpu_s=max(0.0001,(float)$s['content_per_unit']);
                    $uc_html=(float)$s['supplier_price']>0?'₱'.number_format($s['supplier_price']/$cpu_s,4).' <small style="color:var(--gray);">/ '.htmlspecialchars($s['base_unit_label']).'</small>':'<span style="color:var(--gray);">—</span>';
                    if($stock<=0) $badge='<span class="badge" style="background:#dc2626;color:#fff;">🚫 Out of Stock</span>';
                    elseif($s['reorder_level']!==null&&$stock<=$s['reorder_level']) $badge='<span class="badge" style="background:#d97706;color:#fff;">⚠️ Low Stock</span>';
                    else $badge='<span class="badge badge-approved">✅ OK</span>';
                    $has_inner_s = !empty($s['has_inner_level']);
                    $sj=htmlspecialchars(json_encode([
                        'id'              => (int)$s['id'],
                        'name'            => $s['name'],
                        'category'        => $s['category'],
                        'uom_label'       => $s['uom_label'],
                        'content_per_unit'=> $s['content_per_unit'],
                        'base_unit_label' => $s['base_unit_label'],
                        'supplier_price'  => $s['supplier_price'],
                        'reorder_level'   => $s['reorder_level'],
                        'notes'           => $s['notes'] ?? '',
                        'has_inner_level' => (int)$has_inner_s,
                        'inners_per_case' => $s['inners_per_case'],
                        'pieces_per_inner'=> $s['pieces_per_inner'],
                    ]),ENT_QUOTES);
                    $_sru     = $supply_recipe_usage[(int)$s['id']] ?? null;
                    $_arc_msg = $_sru
                        ? 'This supply is used in ' . $_sru['cnt'] . ' service recipe(s): ' . $_sru['names'] . '. After archiving, future appointment completions will skip deducting this ingredient. Continue?'
                        : 'Archive this supply?';
                ?>
                <tr class="inv-supply-row" data-search="<?php echo htmlspecialchars(strtolower($s['name'].' '.$s['category']));?>">
                    <td>
                        <strong><?php echo htmlspecialchars($s['name']);?></strong>
                        <?php if($has_inner_s): ?><br><small style="color:#9333ea;font-size:.72rem;">📦 <?php echo (float)$s['inners_per_case'];?> inners/case · <?php echo (float)$s['pieces_per_inner'];?> <?php echo htmlspecialchars($s['base_unit_label']);?>/inner</small><?php endif;?>
                    </td>
                    <td style="color:var(--gray);"><?php echo htmlspecialchars($s['uom_label']);?></td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo number_format((float)$stock,2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($s['base_unit_label']);?></small></td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo number_format((float)$stock/$cpu_s,2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($s['uom_label']);?></small></td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo $uc_html;?></td>
                    <td><?php echo $badge;?></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-secondary btn-sm" title="Log delivery" onclick="openDeliveryModal(<?php echo (int)$s['id'];?>)">🚚</button>
                        <button type="button" class="btn btn-info btn-sm" data-supply="<?php echo $sj;?>" onclick="openEditModal(this)">✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="event.preventDefault();uiConfirm(<?php echo json_encode($_arc_msg); ?>).then(function(ok){if(ok)this.submit();}.bind(this));">
                            <?php echo csrf_field();?>
                            <input type="hidden" name="action" value="archive_supply">
                            <input type="hidden" name="id" value="<?php echo (int)$s['id'];?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Archive">🗃️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="invNoResults" style="display:none;text-align:center;padding:2rem 1rem;color:var(--gray);font-size:.9rem;background:var(--bg3);border-radius:12px;margin:0 1.5rem 1rem;">No supplies match your search.</div>
<?php endif; ?>


<!-- ════════════════ TAB: DELIVERIES ══════════════════════════════════════════ -->
<?php if ($active_tab === 'deliveries'): ?>
<div class="panel" style="margin-bottom:1.25rem;">
    <div class="panel-header">
        <span class="panel-title">🚚 Delivery Log</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openDeliveryModal(null)">+ Log Delivery</button>
    </div>
    <div style="padding:.75rem 1.5rem;border-bottom:1px solid var(--border2);display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
        <form method="GET" action="inventory.php" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="deliveries">
            <div><div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">From</div><input type="date" name="df" value="<?php echo htmlspecialchars($df);?>" style="padding:.4rem .6rem;border:1.5px solid var(--border2);border-radius:8px;font-size:.85rem;background:var(--bg3);"></div>
            <div style="color:var(--gray);font-size:.85rem;align-self:center;padding-bottom:.15rem;">→</div>
            <div><div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">To</div><input type="date" name="dt" value="<?php echo htmlspecialchars($dt);?>" style="padding:.4rem .6rem;border:1.5px solid var(--border2);border-radius:8px;font-size:.85rem;background:var(--bg3);"></div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="inventory.php?tab=deliveries" class="btn btn-secondary btn-sm">Reset</a>
        </form>
        <div style="font-size:.78rem;color:var(--gray);margin-left:auto;align-self:center;"><?php echo number_format($del_total);?> record<?php echo $del_total!==1?'s':'';?></div>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead><tr><th>Date</th><th>Supply</th><th>Category</th><th style="text-align:right;">Qty (units)</th><th style="text-align:right;">Qty (base)</th><th>Added By</th><th>Notes</th></tr></thead>
            <tbody>
            <?php if (empty($deliveries)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--gray);padding:2.5rem;">No deliveries found for this period.</td></tr>
            <?php else: foreach ($deliveries as $d): ?>
                <tr>
                    <td style="white-space:nowrap;"><strong><?php echo date('M d, Y',strtotime($d['delivery_date']));?></strong></td>
                    <td><strong><?php echo htmlspecialchars($d['supply_name']);?></strong></td>
                    <td><span class="badge" style="background:var(--surface);color:var(--brown);font-size:.73rem;"><?php echo htmlspecialchars($d['category']);?></span></td>
                    <td style="text-align:right;">+<?php echo number_format((float)$d['quantity_units'],2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($d['uom_label']);?></small></td>
                    <td style="text-align:right;">+<?php echo number_format((float)$d['quantity_base'],2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($d['base_unit_label']);?></small></td>
                    <td style="font-size:.83rem;color:var(--gray);"><?php echo htmlspecialchars($d['added_by_name']);?></td>
                    <td style="font-size:.82rem;color:var(--gray);max-width:180px;"><?php echo $d['notes']?htmlspecialchars($d['notes']):'<span style="opacity:.4;">—</span>';?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($del_total_pages > 1): $del_qs='tab=deliveries&df='.urlencode($df).'&dt='.urlencode($dt); $del_base='inventory.php?'.$del_qs.'&dp='; ?>
    <div style="display:flex;justify-content:center;gap:.4rem;padding:1rem 1.5rem;flex-wrap:wrap;">
    <?php for ($i=1;$i<=$del_total_pages;$i++):
        if ($i===$del_page) echo "<span style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:.82rem;font-weight:700;background:var(--rust);color:#fff;border:1.5px solid var(--rust);\">{$i}</span>";
        elseif ($i===1||$i===$del_total_pages||abs($i-$del_page)<=2) echo "<a href=\"{$del_base}{$i}\" style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;border:1.5px solid #e5e7eb;color:#374151;\">{$i}</a>";
        elseif (abs($i-$del_page)===3) echo "<span style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;font-size:.82rem;color:#9ca3af;\">…</span>";
    endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ════════════════ TAB: PHYSICAL COUNT ══════════════════════════════════════ -->
<?php if ($active_tab === 'count'): ?>

<!-- Mode toggle -->
<div style="display:flex;gap:0.5rem;margin-bottom:1.25rem;align-items:center;">
    <a href="inventory.php?tab=count&mode=new"
       class="btn btn-sm" style="background:<?php echo $count_mode==='new'?'var(--brown)':'var(--bg3)';?>;color:<?php echo $count_mode==='new'?'#fff':'var(--brown)';?>;border:1.5px solid var(--border2);font-weight:700;">
        📝 New Count
    </a>
    <a href="inventory.php?tab=count&mode=history"
       class="btn btn-sm" style="background:<?php echo $count_mode==='history'?'var(--brown)':'var(--bg3)';?>;color:<?php echo $count_mode==='history'?'#fff':'var(--brown)';?>;border:1.5px solid var(--border2);font-weight:700;">
        📋 Count History
    </a>
</div>


<!-- ─── MODE A: NEW COUNT ─────────────────────────────────────────────────── -->
<?php if ($count_mode === 'new'): ?>
<style>
.cnt-inp{width:70px;padding:.3rem .45rem;border:1.5px solid var(--border2);border-radius:7px;font-size:.85rem;text-align:right;background:var(--bg3);}
.cnt-inp:focus{border-color:var(--rust);outline:none;background:#fff;}
.cnt-inp-sm{width:60px;}
.cnt-cat-hdr{background:var(--bg3,#f7f5f2)!important;}
.cnt-cat-hdr td{padding:.45rem 1rem!important;font-size:.76rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border2);}
.cnt-var-pos{color:#16a34a;font-weight:700;}
.cnt-var-neg{color:#dc2626;font-weight:700;}
.cnt-var-zer{color:#6b7280;}
.cnt-norm-hint{font-size:.68rem;color:#9333ea;margin-top:.15rem;}
</style>

<form id="cntForm" method="POST" action="inventory.php?tab=count">
<?php echo csrf_field();?>
<input type="hidden" name="action" value="submit_count">

<div class="panel" style="margin-bottom:0;">
    <div class="panel-header">
        <span class="panel-title">📋 New Physical Count</span>
        <div style="display:flex;align-items:center;gap:.75rem;">
            <label style="font-size:.82rem;font-weight:600;color:var(--brown);">Count Date:</label>
            <input type="date" name="count_date" id="cnt_date" required
                   value="<?php echo date('Y-m-d');?>"
                   style="padding:.35rem .6rem;border:1.5px solid var(--border2);border-radius:8px;font-size:.85rem;background:var(--bg3);">
        </div>
    </div>

    <!-- Running summary -->
    <div id="cnt-summary" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center;
         padding:.7rem 1.5rem;background:var(--bg3);border-bottom:1px solid var(--border2);font-size:.84rem;">
        <span style="color:var(--gray);">Items to submit: <strong id="cnt-s-n" style="color:var(--brown);">0</strong></span>
        <span style="color:#16a34a;">Surplus: <strong id="cnt-s-pos">₱0.00</strong></span>
        <span style="color:#dc2626;">Shortage: <strong id="cnt-s-neg">₱0.00</strong></span>
        <span>Net: <strong id="cnt-s-net" style="color:#6b7280;">₱0.00</strong></span>
        <span style="font-size:.75rem;color:var(--gray);margin-left:auto;">Blank rows are skipped.</span>
    </div>

    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>Supply</th>
                    <th style="text-align:right;">Expected Stock</th>
                    <th style="text-align:right;">Whole / Cases+Inners</th>
                    <th style="text-align:right;">Partial / Loose</th>
                    <th style="text-align:right;">Total Counted</th>
                    <th style="text-align:right;">Variance</th>
                    <th style="text-align:right;">₱ Value</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($supplies)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--gray);padding:2.5rem;">No active supplies found.</td></tr>
            <?php else: foreach ($grouped as $cat => $rows): ?>
                <tr class="cnt-cat-hdr"><td colspan="7">📦 <?php echo htmlspecialchars($cat);?> (<?php echo count($rows);?>)</td></tr>
                <?php foreach ($rows as $s):
                    $cpu_c      = max(0.0001,(float)$s['content_per_unit']);
                    $exp_units  = (float)$s['current_stock'] / $cpu_c;
                    $has_inner_c = !empty($s['has_inner_level']);
                    $ipc_c      = $has_inner_c ? max(0.0001,(float)$s['inners_per_case'])  : 0;
                    $ppi_c      = $has_inner_c ? max(0.0001,(float)$s['pieces_per_inner']) : 0;
                    $sid_c      = (int)$s['id'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($s['name']);?></strong>
                        <br><small style="color:var(--gray);">
                        <?php if($has_inner_c): ?>
                            <span style="color:#9333ea;">📦 case=<?php echo $ipc_c;?> inners · inner=<?php echo $ppi_c;?> <?php echo htmlspecialchars($s['base_unit_label']);?></span>
                        <?php else: ?>
                            <?php echo htmlspecialchars($s['uom_label']);?> · <?php echo htmlspecialchars($s['base_unit_label']);?>
                        <?php endif; ?>
                        </small>
                    </td>
                    <td style="text-align:right;font-size:.84rem;">
                        <strong><?php echo number_format((float)$s['current_stock'],2);?></strong> <small style="color:var(--gray);"><?php echo htmlspecialchars($s['base_unit_label']);?></small>
                        <br><small style="color:var(--gray);"><?php echo number_format($exp_units,2);?> <?php echo htmlspecialchars($s['uom_label']);?></small>
                    </td>
                    <td style="text-align:right;">
                    <?php if($has_inner_c): ?>
                        <input type="number" id="wc_<?php echo $sid_c;?>"
                               name="whole_cases[<?php echo $sid_c;?>]"
                               class="cnt-inp cnt-inp-sm" min="0" step="1" placeholder=""
                               oninput="recalcRow(<?php echo $sid_c;?>)">
                        <br><small style="color:var(--gray);font-size:.72rem;">cases</small>
                        <br>
                        <input type="number" id="wi_<?php echo $sid_c;?>"
                               name="whole_inners[<?php echo $sid_c;?>]"
                               class="cnt-inp cnt-inp-sm" min="0" step="1" placeholder=""
                               oninput="recalcRow(<?php echo $sid_c;?>)" style="margin-top:.2rem;">
                        <br><small style="color:var(--gray);font-size:.72rem;">inners</small>
                        <div id="norm_hint_<?php echo $sid_c;?>" class="cnt-norm-hint" style="display:none;"></div>
                    <?php else: ?>
                        <input type="number" id="wu_<?php echo $sid_c;?>"
                               name="whole_units[<?php echo $sid_c;?>]"
                               class="cnt-inp" min="0" step="1" placeholder=""
                               oninput="recalcRow(<?php echo $sid_c;?>)">
                        <br><small style="color:var(--gray);font-size:.72rem;"><?php echo htmlspecialchars($s['uom_label']);?></small>
                    <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                    <?php if($has_inner_c): ?>
                        <input type="number" id="lp_<?php echo $sid_c;?>"
                               name="loose_pieces[<?php echo $sid_c;?>]"
                               class="cnt-inp" min="0" step="any" placeholder=""
                               oninput="recalcRow(<?php echo $sid_c;?>)">
                        <br><small style="color:var(--gray);font-size:.72rem;"><?php echo htmlspecialchars($s['base_unit_label']);?></small>
                    <?php else: ?>
                        <input type="number" id="pb_<?php echo $sid_c;?>"
                               name="partial_base[<?php echo $sid_c;?>]"
                               class="cnt-inp" min="0" step="any" placeholder=""
                               oninput="recalcRow(<?php echo $sid_c;?>)">
                        <br><small style="color:var(--gray);font-size:.72rem;"><?php echo htmlspecialchars($s['base_unit_label']);?></small>
                    <?php endif; ?>
                    </td>
                    <td id="tot_<?php echo $sid_c;?>" style="text-align:right;font-size:.84rem;color:var(--gray);">—</td>
                    <td id="var_<?php echo $sid_c;?>" style="text-align:right;font-size:.84rem;" class="cnt-var-zer">—</td>
                    <td id="peso_<?php echo $sid_c;?>" style="text-align:right;font-size:.84rem;color:var(--gray);">—</td>
                </tr>
                <?php endforeach; endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Submit bar -->
    <div style="padding:1rem 1.5rem;border-top:1px solid var(--border2);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary" onclick="return confirmCountSubmit()">
            ✅ Submit Count
        </button>
        <button type="button" class="btn btn-secondary" onclick="clearCountForm()">Clear All</button>
        <span style="font-size:.78rem;color:var(--gray);">Only rows with values entered will be saved.</span>
    </div>
</div>
</form>
<?php endif; /* mode=new */ ?>


<!-- ─── MODE B: COUNT HISTORY ─────────────────────────────────────────────── -->
<?php if ($count_mode === 'history'): ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">📋 Count History</span>
        <a href="inventory.php?tab=count&mode=new" class="btn btn-primary btn-sm">+ New Count</a>
    </div>

    <!-- Date filter -->
    <div style="padding:.75rem 1.5rem;border-bottom:1px solid var(--border2);display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
        <form method="GET" action="inventory.php" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="count">
            <input type="hidden" name="mode" value="history">
            <div><div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">From</div><input type="date" name="cdf" value="<?php echo htmlspecialchars($cdf);?>" style="padding:.4rem .6rem;border:1.5px solid var(--border2);border-radius:8px;font-size:.85rem;background:var(--bg3);"></div>
            <div style="color:var(--gray);font-size:.85rem;align-self:center;padding-bottom:.15rem;">→</div>
            <div><div style="font-size:.72rem;color:var(--gray);margin-bottom:2px;">To</div><input type="date" name="cdt" value="<?php echo htmlspecialchars($cdt);?>" style="padding:.4rem .6rem;border:1.5px solid var(--border2);border-radius:8px;font-size:.85rem;background:var(--bg3);"></div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="inventory.php?tab=count&mode=history" class="btn btn-secondary btn-sm">Reset</a>
        </form>
        <div style="font-size:.78rem;color:var(--gray);margin-left:auto;align-self:center;"><?php echo number_format($cnt_dates_total);?> count date<?php echo $cnt_dates_total!==1?'s':'';?></div>
    </div>

    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Date</th>
                    <th style="text-align:right;">Items</th>
                    <th>Counted By</th>
                    <th style="text-align:right;">Net Variance (₱)*</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($cnt_history)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--gray);padding:2.5rem;">No count records found for this period.</td></tr>
            <?php else: foreach ($cnt_history as $date => $ch):
                $nv = $ch['net_peso'];
                $nv_html = ($nv >= 0 ? '+₱' : '-₱') . number_format(abs($nv), 2);
                $nv_color = $nv > 0 ? '#16a34a' : ($nv < 0 ? '#dc2626' : '#6b7280');
                $safe_date = htmlspecialchars($date, ENT_QUOTES);
            ?>
            <tr class="cnt-hist-hdr" data-cdate="<?php echo $safe_date;?>"
                onclick="toggleCntDetail('<?php echo $safe_date;?>')"
                style="cursor:pointer;background:var(--surface,#fdf8f3);">
                <td style="width:28px;text-align:center;font-size:.8rem;" class="cnt-chev">▶</td>
                <td><strong><?php echo date('M d, Y', strtotime($date));?></strong></td>
                <td style="text-align:right;"><span class="badge" style="background:var(--border2);color:var(--brown);"><?php echo $ch['n'];?></span></td>
                <td style="font-size:.83rem;color:var(--gray);"><?php echo htmlspecialchars($ch['by'] ?: '—');?></td>
                <td style="text-align:right;font-weight:700;color:<?php echo $nv_color;?>;"><?php echo $nv_html;?></td>
            </tr>
            <tr class="cnt-hist-detail" data-cdate="<?php echo $safe_date;?>" style="display:none;">
                <td colspan="5" style="padding:0;">
                    <div style="background:var(--bg3);border-bottom:2px solid var(--border2);">
                        <table style="width:100%;font-size:.82rem;">
                            <thead><tr style="background:var(--surface);">
                                <th style="padding:.4rem .75rem;">Supply</th>
                                <th style="padding:.4rem .75rem;">Category</th>
                                <th style="text-align:right;padding:.4rem .75rem;">Expected</th>
                                <th style="text-align:right;padding:.4rem .75rem;">Counted</th>
                                <th style="text-align:right;padding:.4rem .75rem;">Variance (base)</th>
                                <th style="text-align:right;padding:.4rem .75rem;">Variance (₱)*</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($ch['rows'] as $cr):
                                $cpu_r = max(0.0001,(float)$cr['content_per_unit']);
                                $var_r = (float)$cr['variance'];
                                $vp_r  = $var_r * ((float)$cr['supplier_price'] / $cpu_r);
                                $vc    = $var_r > 0 ? '#16a34a' : ($var_r < 0 ? '#dc2626' : '#6b7280');
                                $vpc   = $vp_r > 0 ? '#16a34a' : ($vp_r < 0 ? '#dc2626' : '#6b7280');
                            ?>
                            <tr style="border-top:1px solid var(--border2);">
                                <td style="padding:.4rem .75rem;"><strong><?php echo htmlspecialchars($cr['supply_name']);?></strong></td>
                                <td style="padding:.4rem .75rem;color:var(--gray);"><?php echo htmlspecialchars($cr['category']);?></td>
                                <td style="text-align:right;padding:.4rem .75rem;"><?php echo number_format((float)$cr['expected_stock'],2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($cr['base_unit_label']);?></small></td>
                                <td style="text-align:right;padding:.4rem .75rem;"><?php echo number_format((float)$cr['total_base_counted'],2);?> <small style="color:var(--gray);"><?php echo htmlspecialchars($cr['base_unit_label']);?></small></td>
                                <td style="text-align:right;padding:.4rem .75rem;font-weight:700;color:<?php echo $vc;?>;"><?php echo ($var_r>=0?'+':'').number_format($var_r,2);?> <small><?php echo htmlspecialchars($cr['base_unit_label']);?></small></td>
                                <td style="text-align:right;padding:.4rem .75rem;font-weight:700;color:<?php echo $vpc;?>;"><?php echo ($vp_r>=0?'+₱':'-₱').number_format(abs($vp_r),2);?></td>
                            </tr>
                            <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif;?>
            </tbody>
        </table>
    </div>
    <div style="padding:.5rem 1.5rem .75rem;font-size:.72rem;color:var(--gray);">* At current supplier prices; may differ from prices at time of count.</div>

    <?php if ($cnt_dates_pages > 1): $cnt_qs='tab=count&mode=history&cdf='.urlencode($cdf).'&cdt='.urlencode($cdt); $cnt_base='inventory.php?'.$cnt_qs.'&cp='; ?>
    <div style="display:flex;justify-content:center;gap:.4rem;padding:.75rem 1.5rem 1rem;flex-wrap:wrap;">
    <?php for ($i=1;$i<=$cnt_dates_pages;$i++):
        if ($i===$cnt_page) echo "<span style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:.82rem;font-weight:700;background:var(--rust);color:#fff;border:1.5px solid var(--rust);\">{$i}</span>";
        elseif ($i===1||$i===$cnt_dates_pages||abs($i-$cnt_page)<=2) echo "<a href=\"{$cnt_base}{$i}\" style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;border:1.5px solid #e5e7eb;color:#374151;\">{$i}</a>";
        elseif (abs($i-$cnt_page)===3) echo "<span style=\"display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;font-size:.82rem;color:#9ca3af;\">…</span>";
    endfor;?>
    </div>
    <?php endif;?>
</div>
<?php endif; /* mode=history */ ?>
<?php endif; /* count tab */ ?>


<!-- ════════════════ SUPPLY ADD / EDIT MODAL ══════════════════════════════════ -->
<div id="invModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(30,20,10,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:580px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);animation:popIn .28s cubic-bezier(.34,1.56,.64,1);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1.1rem 1.5rem;border-bottom:1px solid var(--border2);flex-shrink:0;">
            <span id="invModalTitle" style="font-weight:700;font-size:1rem;color:var(--brown);"></span>
            <button type="button" onclick="closeInvModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--gray);line-height:1;padding:.2rem .4rem;border-radius:6px;">✕</button>
        </div>
        <div style="overflow-y:auto;padding:1.25rem 1.5rem 1.5rem;flex:1;">
            <form id="invModalForm" method="POST" action="inventory.php?tab=supplies">
                <?php echo csrf_field();?>
                <input type="hidden" name="action" id="invAction" value="add_supply">
                <input type="hidden" name="id"     id="invId"     value="">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1;margin-bottom:1rem;"><label>Supply Name <span class="required">*</span></label><input type="text" id="inv_name" name="name" required maxlength="255" placeholder="e.g. Lavender Massage Oil"></div>
                    <div class="form-group" style="margin-bottom:1rem;"><label>Category <span class="required">*</span></label><select id="inv_category" name="category" required><option value="">— Select —</option><?php foreach ($SUPPLY_CATS as $cat): ?><option value="<?php echo htmlspecialchars($cat);?>"><?php echo htmlspecialchars($cat);?></option><?php endforeach;?></select></div>
                    <div class="form-group" style="margin-bottom:1rem;"><label>Purchase UOM <span class="required">*</span></label><input type="text" id="inv_uom" name="uom_label" required maxlength="20" placeholder="BOT, PCS, GAL…" style="text-transform:uppercase;"><small style="color:var(--gray);">Purchase unit abbreviation</small></div>
                    <div class="form-group" style="margin-bottom:1rem;"><label>Base Unit Label <span class="required">*</span></label><input type="text" id="inv_bul" name="base_unit_label" required maxlength="20" placeholder="ml, g, pcs…"><small style="color:var(--gray);">Smallest tracked unit</small></div>

                    <!-- Inner packaging toggle -->
                    <div class="form-group" style="grid-column:1/-1;margin-bottom:.75rem;">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:600;">
                            <input type="checkbox" id="inv_has_inner" name="has_inner_level" value="1"
                                   onchange="toggleInnerLevel()"
                                   style="width:16px;height:16px;accent-color:var(--rust);">
                            This item has inner packaging (Case → Inner → Piece)
                        </label>
                        <small style="color:var(--gray);padding-left:1.5rem;">e.g. 1 case = 2 inners, 1 inner = 150 pcs</small>
                    </div>

                    <!-- Inner level fields (shown when checkbox checked) -->
                    <div id="inv_inner_fields" style="display:none;grid-column:1/-1;margin-bottom:1rem;">
                        <div style="background:#faf5ff;border:1.5px solid #e9d5ff;border-radius:10px;padding:1rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:.8rem;">Inners per Case</label>
                                <input type="number" id="inv_ipc" name="inners_per_case" min="0.0001" step="any" placeholder="e.g. 2" oninput="computeInnerCPU()">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:.8rem;">Pieces per Inner</label>
                                <input type="number" id="inv_ppi" name="pieces_per_inner" min="0.0001" step="any" placeholder="e.g. 150" oninput="computeInnerCPU()">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:.8rem;">Pcs per Case <small style="color:#9333ea;">(auto)</small></label>
                                <input type="number" id="inv_cpu" name="content_per_unit" required min="0.0001" step="any" value="1" placeholder="auto" readonly style="background:#f3e8ff;color:#7c3aed;font-weight:700;cursor:not-allowed;">
                            </div>
                        </div>
                        <small style="color:#9333ea;display:block;margin-top:.35rem;">Pieces per Case = Inners × Pieces/Inner. Updated automatically.</small>
                    </div>

                    <!-- Flat content_per_unit (shown when inner packaging is OFF) -->
                    <div id="inv_flat_cpu" class="form-group" style="margin-bottom:1rem;">
                        <label>Content per Unit <span class="required">*</span></label>
                        <input type="number" id="inv_cpu_flat" name="content_per_unit" required min="0.0001" step="any" value="1" placeholder="e.g. 500">
                        <small style="color:var(--gray);">Base units in one purchase unit</small>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;"><label>Supplier Price (₱)</label><input type="number" id="inv_price" name="supplier_price" min="0" step="0.01" value="0.00" placeholder="Cost per purchase unit"></div>
                    <div class="form-group" style="margin-bottom:1rem;"><label>Reorder Level <small style="color:var(--gray);">(base units, optional)</small></label><input type="number" id="inv_reorder" name="reorder_level" min="0" step="any" placeholder="Leave blank for no alert"></div>
                    <div class="form-group" style="grid-column:1/-1;margin-bottom:.5rem;"><label>Notes <small style="color:var(--gray);">(optional)</small></label><textarea id="inv_notes" name="notes" rows="2" placeholder="Brand, supplier, storage notes…"></textarea></div>
                </div>
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" id="invModalSubmit" class="btn btn-primary">✅ Add Supply</button>
                    <button type="button" class="btn btn-secondary" onclick="closeInvModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ════════════════ DELIVERY LOG MODAL ═══════════════════════════════════════ -->
<div id="invDelivModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(30,20,10,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:480px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);animation:popIn .28s cubic-bezier(.34,1.56,.64,1);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1.1rem 1.5rem;border-bottom:1px solid var(--border2);flex-shrink:0;">
            <span style="font-weight:700;font-size:1rem;color:var(--brown);">🚚 Log Delivery</span>
            <button type="button" onclick="closeDelivModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--gray);line-height:1;padding:.2rem .4rem;border-radius:6px;">✕</button>
        </div>
        <div style="overflow-y:auto;padding:1.25rem 1.5rem 1.5rem;flex:1;">
            <form id="invDelivForm" method="POST" action="inventory.php?tab=deliveries">
                <?php echo csrf_field();?>
                <input type="hidden" name="action" value="log_delivery">
                <input type="hidden" name="delivery_level" id="del_level_hidden" value="case">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Supply <span class="required">*</span></label>
                    <select id="del_supply_id" name="supply_id" required onchange="resetDelivLevel();updateDelivPreview();">
                        <option value="">— Select supply —</option>
                        <?php foreach ($grouped as $cat => $rows): ?>
                        <optgroup label="<?php echo htmlspecialchars($cat);?>">
                            <?php foreach ($rows as $s):
                                $hi = !empty($s['has_inner_level']);
                            ?>
                            <option value="<?php echo (int)$s['id'];?>"
                                    data-cpu="<?php echo (float)$s['content_per_unit'];?>"
                                    data-bul="<?php echo htmlspecialchars($s['base_unit_label'],ENT_QUOTES);?>"
                                    data-uom="<?php echo htmlspecialchars($s['uom_label'],ENT_QUOTES);?>"
                                    data-has-inner="<?php echo $hi?'1':'0';?>"
                                    data-ipc="<?php echo $hi?(float)$s['inners_per_case']:0;?>"
                                    data-ppi="<?php echo $hi?(float)$s['pieces_per_inner']:0;?>">
                                <?php echo htmlspecialchars($s['name']);?>
                            </option>
                            <?php endforeach;?>
                        </optgroup>
                        <?php endforeach;?>
                    </select>
                </div>

                <!-- Inner-level unit selector (shown only for has_inner_level=1 supplies) -->
                <div id="del_level_row" style="display:none;margin-bottom:1rem;background:#faf5ff;border:1.5px solid #e9d5ff;border-radius:10px;padding:.65rem 1rem;">
                    <div style="font-size:.8rem;font-weight:600;color:#7c3aed;margin-bottom:.4rem;">Receive by:</div>
                    <div style="display:flex;gap:1.25rem;">
                        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.88rem;">
                            <input type="radio" name="del_level_sel" value="case" checked onchange="updateDelivLevel()" style="accent-color:#7c3aed;"> Case
                        </label>
                        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.88rem;">
                            <input type="radio" name="del_level_sel" value="inner" onchange="updateDelivLevel()" style="accent-color:#7c3aed;"> Inner
                        </label>
                    </div>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="form-group" style="margin-bottom:1rem;"><label>Delivery Date <span class="required">*</span></label><input type="date" id="del_date" name="delivery_date" required value="<?php echo date('Y-m-d');?>"></div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label>Quantity Received <span class="required">*</span></label>
                        <input type="number" id="del_qty_units" name="quantity_units" required min="0.0001" step="any" placeholder="e.g. 3" oninput="updateDelivPreview()">
                        <small id="del_qty_label" style="color:var(--gray);">In purchase units</small>
                    </div>
                </div>
                <div id="del_base_preview" style="display:none;background:var(--bg3);border:1.5px solid var(--border2);border-radius:10px;padding:.6rem 1rem;margin-bottom:1rem;font-size:.88rem;color:var(--brown);font-weight:600;text-align:center;"></div>
                <div class="form-group" style="margin-bottom:.5rem;"><label>Notes <small style="color:var(--gray);">(optional)</small></label><textarea id="del_notes" name="notes" rows="2" placeholder="Supplier, invoice #…"></textarea></div>
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary">✅ Log Delivery</button>
                    <button type="button" class="btn btn-secondary" onclick="closeDelivModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
// ── invCountData — per-supply data for count calculations ─────────────────────
var invCountData = {};
<?php foreach ($supplies as $s):
    $cpu_j   = max(0.0001,(float)$s['content_per_unit']);
    $uc_j    = $s['supplier_price'] > 0 ? (float)$s['supplier_price'] / $cpu_j : 0;
    $hi_j    = !empty($s['has_inner_level']);
    $ipc_j   = $hi_j ? max(0.0001,(float)$s['inners_per_case'])  : 0;
    $ppi_j   = $hi_j ? max(0.0001,(float)$s['pieces_per_inner']) : 0;
?>
invCountData[<?php echo $s['id'];?>] = {
    cpu:      <?php echo $cpu_j;?>,
    expected: <?php echo (float)$s['current_stock'];?>,
    unit_cost:<?php echo $uc_j;?>,
    bul:      <?php echo json_encode($s['base_unit_label']);?>,
    uom:      <?php echo json_encode($s['uom_label']);?>,
    has_inner:<?php echo $hi_j?'true':'false';?>,
    ipc:      <?php echo $ipc_j;?>,
    ppi:      <?php echo $ppi_j;?>
};
<?php endforeach;?>

// ── Supply search (Tab 1) ─────────────────────────────────────────────────────
function filterInvSupplies(query) {
    var q = query.trim().toLowerCase();
    var rows = document.querySelectorAll('tr.inv-supply-row');
    var hdrs = document.querySelectorAll('tr.inv-cat-hdr');
    var visible = 0;
    rows.forEach(function(r){ var m=!q||(r.dataset.search||'').indexOf(q)!==-1; r.style.display=m?'':'none'; if(m)visible++; });
    hdrs.forEach(function(h){
        if(!q){h.style.display='';return;}
        var sib=h.nextElementSibling, has=false;
        while(sib&&sib.classList.contains('inv-supply-row')){if(sib.style.display!=='none'){has=true;break;}sib=sib.nextElementSibling;}
        h.style.display=has?'':'none';
    });
    var noRes=document.getElementById('invNoResults'), clr=document.getElementById('invSearchClear');
    if(noRes) noRes.style.display=(q&&visible===0)?'':'none';
    if(clr)   clr.style.display=q?'':'none';
}
function clearInvSearch(){ var i=document.getElementById('invSearchInput'); if(i){i.value='';i.focus();} filterInvSupplies(''); }

// ── Supply add/edit modal ─────────────────────────────────────────────────────
function toggleInnerLevel() {
    var checked = document.getElementById('inv_has_inner').checked;
    document.getElementById('inv_inner_fields').style.display = checked ? '' : 'none';
    document.getElementById('inv_flat_cpu').style.display     = checked ? 'none' : '';
    document.getElementById('inv_cpu_flat').required          = !checked;
    if (!checked) {
        document.getElementById('inv_ipc').value = '';
        document.getElementById('inv_ppi').value = '';
        document.getElementById('inv_cpu').value = '1';
    }
    computeInnerCPU();
}
function computeInnerCPU() {
    if (!document.getElementById('inv_has_inner').checked) return;
    var ipc = parseFloat(document.getElementById('inv_ipc').value || '0');
    var ppi = parseFloat(document.getElementById('inv_ppi').value || '0');
    document.getElementById('inv_cpu').value = (ipc > 0 && ppi > 0) ? (ipc * ppi).toFixed(4) : '';
}

function openAddModal(){
    document.getElementById('invModalTitle').textContent = '➕ Add Supply';
    document.getElementById('invAction').value = 'add_supply';
    document.getElementById('invId').value = '';
    document.getElementById('invModalSubmit').textContent = '✅ Add Supply';
    document.getElementById('invModalForm').reset();
    document.getElementById('inv_has_inner').checked = false;
    document.getElementById('inv_inner_fields').style.display = 'none';
    document.getElementById('inv_flat_cpu').style.display = '';
    document.getElementById('inv_cpu_flat').required = true;
    document.getElementById('inv_cpu_flat').value = '1';
    document.getElementById('inv_cpu').value = '1';
    document.getElementById('inv_price').value = '0.00';
    document.getElementById('invModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('inv_name').focus(); }, 80);
}
function openEditModal(btn){
    var d = JSON.parse(btn.getAttribute('data-supply'));
    document.getElementById('invModalTitle').textContent = '✏️ Edit Supply';
    document.getElementById('invAction').value = 'edit_supply';
    document.getElementById('invId').value = d.id;
    document.getElementById('invModalSubmit').textContent = '✅ Update Supply';
    document.getElementById('inv_name').value     = d.name     || '';
    document.getElementById('inv_uom').value      = d.uom_label|| '';
    document.getElementById('inv_bul').value      = d.base_unit_label || '';
    document.getElementById('inv_price').value    = d.supplier_price  || '0';
    document.getElementById('inv_reorder').value  = d.reorder_level != null ? d.reorder_level : '';
    document.getElementById('inv_notes').value    = d.notes    || '';
    var sel = document.getElementById('inv_category');
    for (var i=0;i<sel.options.length;i++){ if(sel.options[i].value===d.category){sel.selectedIndex=i;break;} }

    var hasInner = d.has_inner_level == 1;
    document.getElementById('inv_has_inner').checked              = hasInner;
    document.getElementById('inv_inner_fields').style.display     = hasInner ? '' : 'none';
    document.getElementById('inv_flat_cpu').style.display         = hasInner ? 'none' : '';
    document.getElementById('inv_cpu_flat').required              = !hasInner;
    if (hasInner) {
        document.getElementById('inv_ipc').value = d.inners_per_case  || '';
        document.getElementById('inv_ppi').value = d.pieces_per_inner || '';
        var ipc = parseFloat(d.inners_per_case  || 0);
        var ppi = parseFloat(d.pieces_per_inner || 0);
        document.getElementById('inv_cpu').value = (ipc>0&&ppi>0) ? (ipc*ppi).toFixed(4) : '';
    } else {
        document.getElementById('inv_cpu_flat').value = d.content_per_unit || '1';
        document.getElementById('inv_ipc').value = '';
        document.getElementById('inv_ppi').value = '';
    }
    document.getElementById('invModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('inv_name').focus(); }, 80);
}
function closeInvModal(){ document.getElementById('invModal').style.display='none'; }
document.getElementById('invModal').addEventListener('click',function(e){if(e.target===this)closeInvModal();});

// ── Delivery modal ────────────────────────────────────────────────────────────
function resetDelivLevel(){
    document.getElementById('del_level_hidden').value = 'case';
    var radios = document.querySelectorAll('input[name="del_level_sel"]');
    radios.forEach(function(r){ r.checked = (r.value === 'case'); });
}
function updateDelivLevel(){
    var level = 'case';
    document.querySelectorAll('input[name="del_level_sel"]').forEach(function(r){ if(r.checked) level=r.value; });
    document.getElementById('del_level_hidden').value = level;
    updateDelivPreview();
}
function updateDelivPreview(){
    var sel  = document.getElementById('del_supply_id');
    var qEl  = document.getElementById('del_qty_units');
    var prev = document.getElementById('del_base_preview');
    var lrow = document.getElementById('del_level_row');
    var qlbl = document.getElementById('del_qty_label');
    if (!sel||!qEl||!prev) return;

    var opt       = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    var has_inner = opt && opt.dataset.hasInner === '1';
    var bul       = opt && opt.dataset.bul ? opt.dataset.bul : '';
    var uom       = opt && opt.dataset.uom ? opt.dataset.uom : '';
    if (lrow) lrow.style.display = (has_inner && sel.value) ? '' : 'none';

    var level = document.getElementById('del_level_hidden').value || 'case';
    if (!has_inner) level = 'case';

    var qty  = parseFloat(qEl.value || '0');
    var base = 0, unit_lbl = uom;
    if (has_inner) {
        var ipc = opt.dataset.ipc ? parseFloat(opt.dataset.ipc) : 0;
        var ppi = opt.dataset.ppi ? parseFloat(opt.dataset.ppi) : 0;
        if (level === 'inner') { base = qty * ppi; unit_lbl = 'inner(s)'; }
        else                   { base = qty * ipc * ppi; unit_lbl = uom || 'case(s)'; }
    } else {
        var cpu = opt && opt.dataset.cpu ? parseFloat(opt.dataset.cpu) : 0;
        base = qty * cpu;
        unit_lbl = uom;
    }

    if (qlbl) {
        if (has_inner && level === 'inner') qlbl.textContent = 'In inners';
        else if (has_inner)                 qlbl.textContent = 'In cases (' + uom + ')';
        else                                qlbl.textContent = 'In purchase units';
    }

    if (sel.value && qty > 0 && base > 0) {
        prev.textContent = qty.toFixed(2) + ' ' + unit_lbl + ' = ' + base.toFixed(2) + ' ' + bul;
        prev.style.display = '';
    } else {
        prev.style.display = 'none';
    }
}
function openDeliveryModal(preselectId){
    document.getElementById('invDelivForm').reset();
    document.getElementById('del_date').value = '<?php echo date('Y-m-d');?>';
    document.getElementById('del_base_preview').style.display = 'none';
    document.getElementById('del_level_row').style.display = 'none';
    resetDelivLevel();
    var sel = document.getElementById('del_supply_id'); sel.selectedIndex = 0;
    if (preselectId) {
        for (var i=0;i<sel.options.length;i++){
            if (parseInt(sel.options[i].value) === parseInt(preselectId)) { sel.selectedIndex=i; break; }
        }
    }
    updateDelivPreview();
    document.getElementById('invDelivModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('del_qty_units').focus(); }, 80);
}
function closeDelivModal(){ document.getElementById('invDelivModal').style.display='none'; }
document.getElementById('invDelivModal').addEventListener('click',function(e){if(e.target===this)closeDelivModal();});

// ── Physical Count calculations ───────────────────────────────────────────────
function recalcRow(sid) {
    var data  = invCountData[sid]; if (!data) return;
    var totEl = document.getElementById('tot_'+sid);
    var varEl = document.getElementById('var_'+sid);
    var psoEl = document.getElementById('peso_'+sid);
    if (!totEl||!varEl||!psoEl) return;

    var total, entered;

    if (data.has_inner) {
        var wcEl = document.getElementById('wc_'+sid);
        var wiEl = document.getElementById('wi_'+sid);
        var lpEl = document.getElementById('lp_'+sid);
        if (!wcEl||!wiEl||!lpEl) return;
        var wc_s = wcEl.value.trim(), wi_s = wiEl.value.trim(), lp_s = lpEl.value.trim();
        entered = (wc_s !== '' || wi_s !== '' || lp_s !== '');
        if (!entered) {
            _clearCountCells(totEl, varEl, psoEl);
            // clear norm hint
            var hEl = document.getElementById('norm_hint_'+sid);
            if (hEl) { hEl.textContent=''; hEl.style.display='none'; }
            recalcSummary(); return;
        }
        var wc = wc_s !== '' ? (parseInt(wc_s)||0) : 0;
        var wi = wi_s !== '' ? (parseInt(wi_s)||0) : 0;
        var lp = lp_s !== '' ? (parseFloat(lp_s)||0) : 0;
        total  = (wc * data.ipc * data.ppi) + (wi * data.ppi) + lp;
        // Normalized hint: if wi >= ipc, show "≈ Nc case(s) + Ni inner(s)"
        var hEl = document.getElementById('norm_hint_'+sid);
        if (hEl) {
            if (wi > 0 && data.ipc > 0) {
                var nc = Math.floor(wi / data.ipc);
                var ni = Math.round(wi - nc * data.ipc);
                if (nc > 0) {
                    hEl.textContent = '≈ '+nc+' case'+(nc!==1?'s':'')+' + '+ni+' inner'+(ni!==1?'s':'');
                    hEl.style.display = '';
                } else { hEl.textContent=''; hEl.style.display='none'; }
            } else { hEl.textContent=''; hEl.style.display='none'; }
        }
    } else {
        var wuEl = document.getElementById('wu_'+sid);
        var pbEl = document.getElementById('pb_'+sid);
        if (!wuEl) return;
        var wu_s = wuEl.value.trim();
        var pb_s = pbEl ? pbEl.value.trim() : '';
        entered = (wu_s !== '' || pb_s !== '');
        if (!entered) { _clearCountCells(totEl, varEl, psoEl); recalcSummary(); return; }
        var wu = wu_s !== '' ? (parseInt(wu_s)||0) : 0;
        var pb = pb_s !== '' ? (parseFloat(pb_s)||0) : 0;
        total  = (wu * data.cpu) + pb;
    }

    var vari = total - data.expected;
    var vp   = vari * data.unit_cost;

    totEl.textContent = total.toFixed(2)+' '+data.bul;
    totEl.style.color = 'var(--brown)';
    varEl.textContent = (vari>=0?'+':'')+vari.toFixed(2)+' '+data.bul;
    varEl.className   = vari>0?'cnt-var-pos':(vari<0?'cnt-var-neg':'cnt-var-zer');
    psoEl.textContent = (vp>=0?'+₱':'-₱')+Math.abs(vp).toFixed(2);
    psoEl.style.color = vp>0?'#16a34a':(vp<0?'#dc2626':'#6b7280');

    recalcSummary();
}
function _clearCountCells(totEl, varEl, psoEl) {
    totEl.textContent='—'; totEl.style.color='var(--gray)';
    varEl.textContent='—'; varEl.className='cnt-var-zer';
    psoEl.textContent='—'; psoEl.style.color='var(--gray)';
}

function recalcSummary(){
    var n=0, pos=0, neg=0;
    Object.keys(invCountData).forEach(function(sid){
        var data = invCountData[sid];
        var total = 0, entered = false;
        if (data.has_inner) {
            var wcEl = document.getElementById('wc_'+sid);
            var wiEl = document.getElementById('wi_'+sid);
            var lpEl = document.getElementById('lp_'+sid);
            if (!wcEl||!wiEl||!lpEl) return;
            var wc_s=wcEl.value.trim(), wi_s=wiEl.value.trim(), lp_s=lpEl.value.trim();
            if (wc_s===''&&wi_s===''&&lp_s==='') return;
            entered = true;
            var wc=parseInt(wc_s)||0, wi=parseInt(wi_s)||0, lp=parseFloat(lp_s)||0;
            total = (wc*data.ipc*data.ppi)+(wi*data.ppi)+lp;
        } else {
            var wuEl = document.getElementById('wu_'+sid);
            var pbEl = document.getElementById('pb_'+sid);
            if (!wuEl) return;
            var wu_s=wuEl.value.trim(), pb_s=pbEl?pbEl.value.trim():'';
            if (wu_s===''&&pb_s==='') return;
            entered = true;
            var wu=parseInt(wu_s)||0, pb=parseFloat(pb_s)||0;
            total = (wu*data.cpu)+pb;
        }
        if (!entered) return;
        n++;
        var vp = (total - data.expected) * data.unit_cost;
        if (vp>0) pos+=vp; else neg+=vp;
    });
    var nEl=document.getElementById('cnt-s-n');
    var pEl=document.getElementById('cnt-s-pos');
    var ngEl=document.getElementById('cnt-s-neg');
    var ntEl=document.getElementById('cnt-s-net');
    if (nEl)  nEl.textContent  = n;
    if (pEl)  pEl.textContent  = '+₱'+pos.toFixed(2);
    if (ngEl) ngEl.textContent = '-₱'+Math.abs(neg).toFixed(2);
    if (ntEl) {
        var net = pos+neg;
        ntEl.textContent = (net>=0?'+':'')+' ₱'+Math.abs(net).toFixed(2);
        ntEl.style.color = net>0?'#16a34a':(net<0?'#dc2626':'#6b7280');
    }
}

function clearCountForm(){
    var form = document.getElementById('cntForm');
    if (!form) return;
    form.querySelectorAll('input[type="number"]').forEach(function(i){ if(!i.disabled) i.value=''; });
    Object.keys(invCountData).forEach(function(sid){ recalcRow(parseInt(sid)); });
}

function confirmCountSubmit(){
    var n = parseInt((document.getElementById('cnt-s-n')||{}).textContent||'0');
    if (n===0) { alert('No items entered yet. Fill in at least one count row.'); return false; }
    return true;
}

// ── Count history expand/collapse ─────────────────────────────────────────────
function toggleCntDetail(dateStr) {
    var detail = document.querySelector('tr.cnt-hist-detail[data-cdate="'+dateStr+'"]');
    var hdr    = document.querySelector('tr.cnt-hist-hdr[data-cdate="'+dateStr+'"]');
    if (!detail) return;
    var open = detail.style.display !== 'none';
    detail.style.display = open ? 'none' : '';
    if (hdr) { var ch=hdr.querySelector('.cnt-chev'); if(ch) ch.textContent=open?'▶':'▼'; }
}

// ── Close modals on Escape ────────────────────────────────────────────────────
document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    if (document.getElementById('invModal').style.display==='flex')      closeInvModal();
    if (document.getElementById('invDelivModal').style.display==='flex') closeDelivModal();
});
</script>

<?php require_once 'admin_footer.php'; ?>
