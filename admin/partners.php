<?php
/**
 * partners.php — Hotel & Corporate Partner Management (Owner only)
 * Owner adds partners and sets their rate per service.
 */
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
redirect_if_not_owner();

$message = ''; $message_type = '';

// ── DELETE PARTNER ────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_owner()) {
    $pid  = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM partners WHERE id = ?");
    $stmt->bind_param("i", $pid); $stmt->execute(); $stmt->close();
    header("Location: partners.php?deleted=1"); exit();
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $pid  = intval($_GET['toggle']);
    $stmt = $conn->prepare("UPDATE partners SET status = IF(status='active','inactive','active') WHERE id = ?");
    $stmt->bind_param("i", $pid); $stmt->execute(); $stmt->close();
    header("Location: partners.php"); exit();
}

// ── ADD / EDIT PARTNER ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_partner'])) {
    verify_csrf_token();
    $pid     = intval($_POST['partner_id'] ?? 0);
    $name    = sanitize_input($_POST['name']    ?? '');
    $type    = in_array($_POST['type'] ?? '', ['hotel','corporate','other'])
               ? $_POST['type'] : 'hotel';
    $contact = sanitize_input($_POST['contact'] ?? '');
    $notes   = sanitize_input($_POST['notes']   ?? '');

    if (empty($name)) {
        $message = "Partner name is required."; $message_type = "danger";
    } else {
        if ($pid > 0) {
            $stmt = $conn->prepare("UPDATE partners SET name=?, type=?, contact=?, notes=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $type, $contact, $notes, $pid);
            $stmt->execute(); $stmt->close();
            $message = "✅ Partner updated."; $message_type = "success";
        } else {
            $stmt = $conn->prepare("INSERT INTO partners (name, type, contact, notes) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $name, $type, $contact, $notes);
            $stmt->execute();
            $pid = $stmt->insert_id;
            $stmt->close();
            $message = "✅ Partner <strong>{$name}</strong> added."; $message_type = "success";
        }
    }
}

// ── SAVE RATE MATRIX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rates'])) {
    verify_csrf_token();
    $pid      = intval($_POST['rate_partner_id'] ?? 0);
    $svc_ids  = $_POST['rate_svc_id']  ?? [];
    $prices   = $_POST['rate_price']   ?? [];

    if ($pid > 0) {
        foreach ($svc_ids as $i => $sid) {
            $sid   = intval($sid);
            $price = floatval($prices[$i] ?? 0);
            if ($sid <= 0) continue;

            if ($price > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO partner_rates (partner_id, service_id, price)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE price = VALUES(price)
                ");
                $stmt->bind_param("iid", $pid, $sid, $price);
                $stmt->execute(); $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM partner_rates WHERE partner_id=? AND service_id=?");
                $stmt->bind_param("ii", $pid, $sid);
                $stmt->execute(); $stmt->close();
            }
        }
        $message = "✅ Rates saved."; $message_type = "success";
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$partners = $conn->query("
    SELECT p.*,
           COUNT(pr.id) AS rates_set
    FROM partners p
    LEFT JOIN partner_rates pr ON pr.partner_id = p.id
    GROUP BY p.id
    ORDER BY p.name ASC
")->fetch_all(MYSQLI_ASSOC);

$services = $conn->query("
    SELECT s.id, s.name, s.price,
           IFNULL(c.name,'Uncategorized') AS category_name
    FROM services s
    LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY c.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$svc_by_cat = [];
foreach ($services as $svc) {
    $svc_by_cat[$svc['category_name']][] = $svc;
}

// Pre-load all saved rates for all partners
$saved_rates = [];
if (!empty($partners)) {
    $pids = implode(',', array_map(fn($p) => intval($p['id']), $partners));
    $rows = $conn->query("SELECT partner_id, service_id, price FROM partner_rates WHERE partner_id IN ($pids)");
    while ($r = $rows->fetch_assoc()) {
        $saved_rates[$r['partner_id']][$r['service_id']] = $r['price'];
    }
}

// Edit mode
$edit_partner = null;
if (isset($_GET['edit'])) {
    $eid  = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM partners WHERE id = ?");
    $stmt->bind_param("i", $eid); $stmt->execute();
    $edit_partner = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

$type_icons  = ['hotel' => '🏨', 'corporate' => '🏢', 'other' => '🤝'];
$page_title  = 'Partners & Rates';
$page_icon   = '🤝';
$active_page = 'partners';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.5rem;">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" style="margin-bottom:1.5rem;">🗑️ Partner removed.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:2rem;align-items:start;">

<!-- ══════════════════════════════════════════════════════════
     LEFT — ADD / EDIT FORM + PARTNER LIST
══════════════════════════════════════════════════════════ -->
<div>

    <!-- Add / Edit form -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">
                <?php echo $edit_partner ? '✏️ Edit Partner' : '➕ Add Partner'; ?>
            </span>
            <?php if ($edit_partner): ?>
            <a href="partners.php" class="btn btn-secondary btn-sm">✕ Cancel</a>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="padding:1.25rem;">
            <form method="POST" style="display:flex;flex-direction:column;gap:1rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_partner" value="1">
                <input type="hidden" name="partner_id"
                       value="<?php echo $edit_partner['id'] ?? 0; ?>">

                <div class="form-group">
                    <label>Partner Name <span style="color:var(--rust);">*</span></label>
                    <input type="text" name="name" required maxlength="120"
                           placeholder="e.g. Seda Hotel, Hotel del Rio"
                           value="<?php echo htmlspecialchars($edit_partner['name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <?php foreach (['hotel' => '🏨 Hotel', 'corporate' => '🏢 Corporate', 'other' => '🤝 Other'] as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"
                            <?php echo ($edit_partner['type'] ?? 'hotel') === $val ? 'selected' : ''; ?>>
                            <?php echo $lbl; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Contact Person / Number</label>
                    <input type="text" name="contact" maxlength="120"
                           placeholder="e.g. Juan dela Cruz · 09XX"
                           value="<?php echo htmlspecialchars($edit_partner['contact'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2"
                              placeholder="Any special arrangements..."><?php echo htmlspecialchars($edit_partner['notes'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-full">
                    <?php echo $edit_partner ? '💾 Save Changes' : '➕ Add Partner'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Partner list -->
    <div class="panel" style="margin-top:1.5rem;">
        <div class="panel-header">
            <span class="panel-title">🤝 All Partners (<?php echo count($partners); ?>)</span>
        </div>

        <?php if (empty($partners)): ?>
        <div class="panel-body" style="text-align:center;padding:2rem;color:var(--gray);">
            No partners yet. Add one above.
        </div>
        <?php else: ?>
        <div style="padding:0.5rem;">
            <?php foreach ($partners as $p):
                $is_editing = ($edit_partner['id'] ?? 0) == $p['id'];
            ?>
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;
                        border-radius:10px;margin-bottom:0.4rem;
                        background:<?php echo $is_editing ? 'rgba(201,106,44,0.08)' : 'var(--bg3)'; ?>;
                        border:1px solid <?php echo $is_editing ? 'var(--gold)' : 'var(--border2)'; ?>;">

                <div style="font-size:1.2rem;flex-shrink:0;">
                    <?php echo $type_icons[$p['type']] ?? '🤝'; ?>
                </div>

                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:var(--brown);font-size:0.88rem;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($p['name']); ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray);">
                        <?php echo $p['rates_set']; ?> rate<?php echo $p['rates_set'] != 1 ? 's' : ''; ?> set
                        <?php if ($p['contact']): ?>
                         · <?php echo htmlspecialchars($p['contact']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;gap:0.3rem;flex-shrink:0;align-items:center;">
                    <a href="partners.php?toggle=<?php echo $p['id']; ?>"
                       title="Click to toggle status"
                       style="text-decoration:none;">
                        <span style="font-size:0.68rem;padding:0.18rem 0.55rem;border-radius:20px;
                                     font-weight:700;cursor:pointer;white-space:nowrap;
                                     background:<?php echo $p['status']==='active' ? '#d1e7dd' : '#f8d7da'; ?>;
                                     color:<?php echo $p['status']==='active' ? '#0a3622' : '#842029'; ?>;">
                            <?php echo $p['status'] === 'active' ? '✅ Active' : '❌ Inactive'; ?>
                        </span>
                    </a>
                    <a href="partners.php?edit=<?php echo $p['id']; ?>"
                       class="btn btn-secondary btn-sm"
                       style="font-size:0.72rem;padding:0.2rem 0.55rem;">
                        ✏️
                    </a>
                    <a href="partners.php?delete=<?php echo $p['id']; ?>"
                       class="btn btn-danger btn-sm"
                       style="font-size:0.72rem;padding:0.2rem 0.55rem;"
                       onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($p['name'])); ?>?\nThis will also delete all their custom rates.')">
                        🗑️
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /left col -->

<!-- ══════════════════════════════════════════════════════════
     RIGHT — RATE MATRIX
══════════════════════════════════════════════════════════ -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">💰 Partner Rate Matrix</span>
        <small style="color:var(--gray);font-size:0.73rem;">
            Set the price each partner pays per service. Leave blank = use regular price.
        </small>
    </div>
    <div class="panel-body" style="padding:1.25rem;">

        <?php if (empty($partners)): ?>
        <div style="text-align:center;padding:2.5rem;color:var(--gray);font-size:0.88rem;">
            Add a partner first to configure their rates.
        </div>
        <?php else: ?>

        <!-- Partner selector tabs -->
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
            <?php foreach ($partners as $p): ?>
            <button type="button"
                    id="rtab-<?php echo $p['id']; ?>"
                    onclick="showRates(<?php echo $p['id']; ?>)"
                    style="padding:0.4rem 1.1rem;border-radius:20px;
                           border:2px solid var(--border2);background:var(--bg3);
                           color:var(--brown);font-size:0.82rem;font-weight:600;
                           cursor:pointer;transition:all .15s;
                           <?php echo $p['status'] !== 'active' ? 'opacity:0.5;' : ''; ?>">
                <?php echo $type_icons[$p['type']] ?? '🤝'; ?>
                <?php echo htmlspecialchars($p['name']); ?>
                <?php if ($p['rates_set'] > 0): ?>
                <span style="background:var(--gold);color:#fff;border-radius:20px;
                             padding:0.05rem 0.45rem;font-size:0.65rem;margin-left:0.2rem;">
                    <?php echo $p['rates_set']; ?>
                </span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Placeholder shown before a partner is selected -->
        <div id="rate-placeholder"
             style="text-align:center;padding:2.5rem;color:var(--gray);
                    background:var(--bg3);border-radius:10px;
                    border:1px solid var(--border2);font-size:0.88rem;">
            👆 Select a partner above to view and edit their service rates.
        </div>

        <!-- Rate form (hidden until partner tab clicked) -->
        <form method="POST" id="rateForm" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="save_rates" value="1">
            <input type="hidden" name="rate_partner_id" id="rate_partner_id" value="">

            <!-- Selected partner name heading -->
            <div id="rate-partner-name"
                 style="font-size:1rem;font-weight:700;color:var(--brown);
                        margin-bottom:1rem;padding-bottom:0.75rem;
                        border-bottom:2px solid var(--border2);">
            </div>

            <!-- Table column headers -->
            <div style="display:grid;grid-template-columns:2.5fr 1fr 1fr;gap:0.5rem;
                        padding:0.5rem 0.75rem;background:var(--bg3);border-radius:8px;
                        margin-bottom:0.5rem;font-size:0.73rem;font-weight:700;
                        color:var(--gray);border:1px solid var(--border2);
                        text-transform:uppercase;letter-spacing:0.05em;">
                <div>Service</div>
                <div style="text-align:center;">Regular Price</div>
                <div style="text-align:center;">Partner Rate (₱)</div>
            </div>

            <!-- Rows injected by JS -->
            <div id="rate-rows"></div>

            <div style="margin-top:1.25rem;display:flex;align-items:center;
                        gap:1rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">
                    💾 Save Rates
                </button>
                <span style="font-size:0.78rem;color:var(--gray);">
                    Set 0 or leave blank to remove a custom rate and fall back to regular price.
                </span>
            </div>
        </form>

        <?php endif; ?>
    </div>
</div><!-- /right panel -->

</div><!-- /grid -->

<script>
// Data passed from PHP → JS
const svcByCategory    = <?php echo json_encode($svc_by_cat, JSON_HEX_TAG); ?>;
const savedRates       = <?php echo json_encode($saved_rates, JSON_HEX_TAG); ?>;
const partnerNames     = <?php
    $pnames = [];
    foreach ($partners as $p) $pnames[$p['id']] = $p['name'];
    echo json_encode($pnames, JSON_HEX_TAG);
?>;
const partnerTypeIcons = <?php
    $picons = [];
    foreach ($partners as $p) {
        $picons[$p['id']] = ['hotel'=>'🏨','corporate'=>'🏢','other'=>'🤝'][$p['type']] ?? '🤝';
    }
    echo json_encode($picons, JSON_HEX_TAG);
?>;

function showRates(partnerId) {
    // Reset all tabs
    document.querySelectorAll('[id^="rtab-"]').forEach(btn => {
        btn.style.background  = 'var(--bg3)';
        btn.style.borderColor = 'var(--border2)';
        btn.style.color       = 'var(--brown)';
    });
    // Highlight active tab
    const activeTab = document.getElementById('rtab-' + partnerId);
    if (activeTab) {
        activeTab.style.background  = 'var(--gold)';
        activeTab.style.borderColor = 'var(--gold)';
        activeTab.style.color       = '#fff';
    }

    document.getElementById('rate-placeholder').style.display = 'none';
    document.getElementById('rateForm').style.display         = 'block';
    document.getElementById('rate_partner_id').value          = partnerId;
    document.getElementById('rate-partner-name').textContent  =
        (partnerTypeIcons[partnerId] || '🤝') + ' ' +
        (partnerNames[partnerId]     || '')   + ' — Service Rates';

    const saved  = savedRates[partnerId] || {};
    const rowsEl = document.getElementById('rate-rows');
    rowsEl.innerHTML = '';

    Object.entries(svcByCategory).forEach(([cat, svcs]) => {
        // Category divider
        const catEl = document.createElement('div');
        catEl.style.cssText = `
            font-size:0.72rem;font-weight:700;color:var(--gold);
            text-transform:uppercase;letter-spacing:0.06em;
            padding:0.65rem 0 0.3rem;margin-top:0.4rem;
            border-top:1px solid var(--border2);
        `;
        catEl.textContent = cat;
        rowsEl.appendChild(catEl);

        svcs.forEach(svc => {
            const rawSaved   = saved[svc.id];
            const hasRate    = rawSaved !== undefined && parseFloat(rawSaved) > 0;
            const savedPrice = hasRate ? parseFloat(rawSaved).toFixed(2) : '';

            const regPrice = parseFloat(svc.price).toLocaleString('en-PH', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });

            const row = document.createElement('div');
            row.style.cssText = `
                display:grid;grid-template-columns:2.5fr 1fr 1fr;gap:0.5rem;
                align-items:center;padding:0.4rem 0.75rem;border-radius:8px;
                margin-bottom:0.3rem;
                background:${hasRate ? '#fff8f0' : 'var(--bg3)'};
                border:1px solid ${hasRate ? 'var(--gold)' : 'var(--border2)'};
                transition:border-color .15s, background .15s;
            `;

            row.innerHTML = `
                <div>
                    <input type="hidden" name="rate_svc_id[]" value="${svc.id}">
                    <div style="font-size:0.83rem;font-weight:600;color:var(--brown);">
                        ${svc.name}
                    </div>
                </div>
                <div style="text-align:center;font-size:0.82rem;
                            color:var(--gray);font-weight:600;">
                    ₱${regPrice}
                </div>
                <div>
                    <input type="number"
                           name="rate_price[]"
                           value="${savedPrice}"
                           min="0" step="0.01"
                           placeholder="e.g. 1380.00"
                           oninput="highlightRow(this)"
                           style="width:100%;padding:0.35rem 0.5rem;
                                  border:1px solid ${hasRate ? 'var(--gold)' : 'var(--border2)'};
                                  border-radius:6px;
                                  background:${hasRate ? '#fff' : 'var(--bg2)'};
                                  color:var(--brown);font-size:0.85rem;
                                  text-align:center;box-sizing:border-box;
                                  transition:border-color .15s,background .15s;">
                </div>
            `;
            rowsEl.appendChild(row);
        });
    });
}

// Highlight row gold when a rate is typed, clear when empty
function highlightRow(input) {
    const row    = input.closest('div[style*="grid-template-columns"]');
    const hasVal = input.value !== '' && parseFloat(input.value) > 0;
    if (row) {
        row.style.background    = hasVal ? '#fff8f0'        : 'var(--bg3)';
        row.style.borderColor   = hasVal ? 'var(--gold)'    : 'var(--border2)';
        input.style.borderColor = hasVal ? 'var(--gold)'    : 'var(--border2)';
        input.style.background  = hasVal ? '#fff'           : 'var(--bg2)';
    }
}
</script>

<?php require_once 'admin_footer.php'; ?>