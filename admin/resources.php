<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
require_once __DIR__ . '/../notify.php';

// Self-heal — matches this codebase's convention of every consuming page
// running the same idempotent schema statements, not just the migration file.
$conn->query("CREATE TABLE IF NOT EXISTS service_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    type ENUM('room','chair','head_spa') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS resource_id INT NULL AFTER service_duration_id");
$_fk_chk = $conn->query("SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND CONSTRAINT_NAME='fk_appointments_resource'")->fetch_assoc()['c'];
if ($_fk_chk == 0) {
    $conn->query("ALTER TABLE appointments ADD CONSTRAINT fk_appointments_resource FOREIGN KEY (resource_id) REFERENCES service_resources(id) ON DELETE SET NULL");
}
unset($_fk_chk);

$message = ''; $message_type = '';

$type_labels = ['room' => 'Room', 'chair' => 'Chair', 'head_spa' => 'Head Spa'];

// ─── ADD ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_resource'])) {
    verify_csrf_token();
    $name       = sanitize_input($_POST['name'] ?? '');
    $type       = $_POST['type'] ?? '';
    $sort_order = intval($_POST['sort_order'] ?? 0);

    if (empty($name) || !isset($type_labels[$type])) {
        $message = "Name and type required."; $message_type = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO service_resources (name, type, sort_order) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $type, $sort_order);
        $ok = $stmt->execute();
        if ($ok) {
            $message = "Resource added!"; $message_type = "success";
            log_activity($conn, 'resource_created', "Created resource: {$name} ({$type})", 'service_resource', (int)$conn->insert_id);
        } else {
            $message = "Error adding resource."; $message_type = "danger";
        }
        $stmt->close();
    }
}

// ─── EDIT ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_resource'])) {
    verify_csrf_token();
    $id         = intval($_POST['resource_id'] ?? 0);
    $name       = sanitize_input($_POST['name'] ?? '');
    $type       = $_POST['type'] ?? '';
    $sort_order = intval($_POST['sort_order'] ?? 0);

    if (empty($name) || !isset($type_labels[$type])) {
        $message = "Name and type required."; $message_type = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE service_resources SET name=?, type=?, sort_order=? WHERE id=?");
        $stmt->bind_param("ssii", $name, $type, $sort_order, $id);
        $ok = $stmt->execute();
        $message      = $ok ? "Resource updated!" : "Error updating resource.";
        $message_type = $ok ? "success" : "danger";
        if ($ok) log_activity($conn, 'resource_updated', "Updated resource: {$name} ({$type})", 'service_resource', $id);
        $stmt->close();
    }
}

// ─── DEACTIVATE / REACTIVATE (soft — never hard-delete, historical
//     appointments may reference a resource_id) ───────────────────────────────
if (isset($_GET['deactivate'])) {
    $id = intval($_GET['deactivate']);
    $stmt = $conn->prepare("UPDATE service_resources SET is_active=0 WHERE id=?");
    $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
    $message      = $ok ? "Resource deactivated." : "Error deactivating resource.";
    $message_type = $ok ? "success" : "danger";
    if ($ok) log_activity($conn, 'resource_deactivated', "Deactivated resource ID {$id}", 'service_resource', $id);
}
if (isset($_GET['reactivate'])) {
    $id = intval($_GET['reactivate']);
    $stmt = $conn->prepare("UPDATE service_resources SET is_active=1 WHERE id=?");
    $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
    $message      = $ok ? "Resource reactivated." : "Error reactivating resource.";
    $message_type = $ok ? "success" : "danger";
    if ($ok) log_activity($conn, 'resource_reactivated', "Reactivated resource ID {$id}", 'service_resource', $id);
}

// ─── FETCH, GROUPED BY TYPE ───────────────────────────────────────────────────
$by_type = ['room' => [], 'chair' => [], 'head_spa' => []];
$result = $conn->query("SELECT * FROM service_resources ORDER BY type, sort_order, name");
while ($row = $result->fetch_assoc()) {
    $by_type[$row['type']][] = $row;
}

$page_title = 'Resources'; $page_icon = ''; $active_page = 'resources';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>

<!-- Add Form -->
<div class="form-section" style="margin-bottom:1.5rem;">
    <div class="form-section-header">Add New Resource</div>
    <div class="form-section-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-grid form-grid-3">
                <div class="form-group">
                    <label>Resource Name <span class="required">*</span></label>
                    <input type="text" name="name" placeholder="e.g. Room 5" required>
                </div>
                <div class="form-group">
                    <label>Type <span class="required">*</span></label>
                    <select name="type" required>
                        <option value="">-- Select Type --</option>
                        <?php foreach ($type_labels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="0" min="0">
                </div>
            </div>
            <div class="form-actions" style="margin-top:1rem;">
                <button type="submit" name="add_resource" class="btn btn-primary">Add Resource</button>
            </div>
        </form>
    </div>
</div>

<!-- Resource Lists, grouped by type -->
<div class="category-grid" style="grid-template-columns:repeat(3,1fr);">
    <?php foreach ($type_labels as $type => $label):
        $items = $by_type[$type];
    ?>
    <div class="category-panel">
        <div class="category-panel-header">
            <?php echo $label; ?>
            <span style="margin-left:auto;color:var(--gray);font-size:0.72rem;"><?php echo count($items); ?> total</span>
        </div>
        <div class="category-panel-body">
            <?php if (!empty($items)): foreach ($items as $res): ?>
            <div class="category-item" style="<?php echo $res['is_active'] ? '' : 'opacity:0.5;'; ?>">
                <span class="category-name">
                    <?php echo htmlspecialchars($res['name']); ?>
                    <?php if (!$res['is_active']): ?><span class="badge" style="background:#6c757d;color:#fff;font-size:0.62rem;margin-left:0.3rem;">INACTIVE</span><?php endif; ?>
                </span>
                <span class="category-count">order: <?php echo (int)$res['sort_order']; ?></span>
                <div class="category-actions">
                    <button class="btn btn-info btn-sm"
                            onclick="openEdit(<?php echo $res['id']; ?>,'<?php echo htmlspecialchars(addslashes($res['name'])); ?>','<?php echo $res['type']; ?>',<?php echo (int)$res['sort_order']; ?>)">Edit</button>
                    <?php if ($res['is_active']): ?>
                    <a href="resources.php?deactivate=<?php echo $res['id']; ?>" class="btn btn-danger btn-sm"
                       onclick="var _h=this.href;event.preventDefault();uiConfirm('Deactivate this resource? It will no longer be assignable, but past appointments referencing it are unaffected.').then(ok=>{if(ok)window.location.href=_h;})">Deactivate</a>
                    <?php else: ?>
                    <a href="resources.php?reactivate=<?php echo $res['id']; ?>" class="btn btn-secondary btn-sm">Reactivate</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; else: ?>
            <p style="color:var(--gray);text-align:center;padding:1.5rem;font-size:0.85rem;">No <?php echo strtolower($label); ?> resources yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <span class="modal-box-title">Edit Resource</span>
            <button class="modal-box-close" onclick="document.getElementById('editModal').classList.remove('active')">✕</button>
        </div>
        <div class="modal-box-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="resource_id" id="edit_id">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Resource Name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Type</label>
                    <select name="type" id="edit_type" required>
                        <?php foreach ($type_labels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:1.5rem;">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" id="edit_sort_order" min="0">
                </div>
                <div class="modal-box-footer" style="padding:0;">
                    <button type="submit" name="edit_resource" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('editModal').classList.remove('active')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(id, name, type, sortOrder) {
    document.getElementById('edit_id').value         = id;
    document.getElementById('edit_name').value        = name;
    document.getElementById('edit_type').value        = type;
    document.getElementById('edit_sort_order').value  = sortOrder;
    document.getElementById('editModal').classList.add('active');
}
document.getElementById('editModal').addEventListener('click', e => { if (e.target===document.getElementById('editModal')) document.getElementById('editModal').classList.remove('active'); });
</script>

<?php require_once 'admin_footer.php'; ?>
