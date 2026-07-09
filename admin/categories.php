<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
require_once __DIR__ . '/../notify.php';

$message = ''; $message_type = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_category'])) {
    verify_csrf_token();
    $name = sanitize_input($_POST['name']);
    $type = $_POST['type'];

    if (empty($name)||empty($type)) { 
        $message="Name and type required."; 
        $message_type="danger"; 
    } else {
        $stmt = $conn->prepare("SELECT id FROM categories WHERE name=? AND type=?");
        $stmt->bind_param("ss",$name,$type); 
        $stmt->execute(); 
        $stmt->store_result();

        if ($stmt->num_rows>0) { 
            $message="Already exists."; 
            $message_type="danger"; 
            $stmt->close(); 
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO categories (name,type) VALUES (?,?)");
            $stmt->bind_param("ss",$name,$type);

            // Execute ONCE and save the boolean result
            $success = $stmt->execute(); 

            if ($success) {
                $message = "Category added!";
                $message_type = "success";
                log_activity($conn, 'category_created', "Created category: {$name} ({$type})", 'category', (int)$conn->insert_id);
            } else {
                $message = "Error adding.";
                $message_type = "danger";
            }

            $stmt->close();
        }
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM products WHERE category_id=?)+(SELECT COUNT(*) FROM services WHERE category_id=?) as t");
    $stmt->bind_param("ii",$id,$id); $stmt->execute();
    $in_use = $stmt->get_result()->fetch_assoc()['t']; $stmt->close();
    if ($in_use>0) { $message="Cannot delete — used by $in_use item(s)."; $message_type="danger"; }
    else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
        $stmt->bind_param("i",$id);
        $ok = $stmt->execute();
        $message      = $ok ? "Deleted." : "Error deleting category.";
        $message_type = $ok ? "success"  : "danger";
        if ($ok) log_activity($conn, 'category_deleted', "Deleted category ID {$id}", 'category', $id);
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_category'])) {
    verify_csrf_token();
    $id = intval($_POST['category_id']); $name = sanitize_input($_POST['name']); $type = $_POST['type'];
    $stmt = $conn->prepare("UPDATE categories SET name=?,type=? WHERE id=?");
    $stmt->bind_param("ssi",$name,$type,$id);
    $ok = $stmt->execute();
    $message      = $ok ? "Category updated!" : "Error updating category.";
    $message_type = $ok ? "success"           : "danger";
    if ($ok) log_activity($conn, 'category_updated', "Updated category: {$name} ({$type})", 'category', $id);
    $stmt->close();
}

$service_cats = []; $product_cats = [];
$result = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM services s WHERE s.category_id=c.id) as service_count, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) as product_count FROM categories c ORDER BY c.type, c.name");
while ($row = $result->fetch_assoc()) {
    if ($row['type']==='service') $service_cats[] = $row;
    else $product_cats[] = $row;
}

$page_title = 'Categories'; $page_icon = '🏷️'; $active_page = 'categories';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom:1.25rem;"><?php echo $message; ?></div>
<?php endif; ?>

<!-- Add Form -->
<div class="form-section" style="margin-bottom:1.5rem;">
    <div class="form-section-header">➕ Add New Category</div>
    <div class="form-section-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="form-group">
                    <label>Category Name <span class="required">*</span></label>
                    <input type="text" name="name" placeholder="e.g. Brows Services" required>
                </div>
                <div class="form-group">
                    <label>Type <span class="required">*</span></label>
                    <select name="type" required>
                        <option value="">-- Select Type --</option>
                        <option value="service">Service Category</option>
                        <option value="product">Product Category</option>
                    </select>
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Category Lists -->
<div class="category-grid">

    <!-- Service Categories -->
    <div class="category-panel">
        <div class="category-panel-header">
            💆 Service Categories
            <span style="margin-left:auto;color:var(--gray);font-size:0.72rem;"><?php echo count($service_cats); ?> total</span>
        </div>
        <div class="category-panel-body">
            <?php if (!empty($service_cats)): foreach ($service_cats as $cat): ?>
            <div class="category-item">
                <span class="category-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                <span class="category-count"><?php echo $cat['service_count']; ?> service(s)</span>
                <div class="category-actions">
                    <button class="btn btn-info btn-sm" onclick="openEdit(<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars($cat['name']); ?>','service')">✏️</button>
                    <?php if ($cat['service_count']==0): ?>
                    <a href="categories.php?delete=<?php echo $cat['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">🗑️</a>
                    <?php else: ?>
                    <span style="font-size:0.72rem;color:var(--gray);padding:0.2rem 0.4rem;">In use</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; else: ?>
            <p style="color:var(--gray);text-align:center;padding:1.5rem;font-size:0.85rem;">No service categories yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Product Categories -->
    <div class="category-panel">
        <div class="category-panel-header">
            🛍️ Product Categories
            <span style="margin-left:auto;color:var(--gray);font-size:0.72rem;"><?php echo count($product_cats); ?> total</span>
        </div>
        <div class="category-panel-body">
            <?php if (!empty($product_cats)): foreach ($product_cats as $cat): ?>
            <div class="category-item">
                <span class="category-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                <span class="category-count"><?php echo $cat['product_count']; ?> product(s)</span>
                <div class="category-actions">
                    <button class="btn btn-info btn-sm" onclick="openEdit(<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars($cat['name']); ?>','product')">✏️</button>
                    <?php if ($cat['product_count']==0): ?>
                    <a href="categories.php?delete=<?php echo $cat['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">🗑️</a>
                    <?php else: ?>
                    <span style="font-size:0.72rem;color:var(--gray);padding:0.2rem 0.4rem;">In use</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; else: ?>
            <p style="color:var(--gray);text-align:center;padding:1.5rem;font-size:0.85rem;">No product categories yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <span class="modal-box-title">✏️ Edit Category</span>
            <button class="modal-box-close" onclick="document.getElementById('editModal').classList.remove('active')">✕</button>
        </div>
        <div class="modal-box-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="category_id" id="edit_id">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Category Name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group" style="margin-bottom:1.5rem;">
                    <label>Type</label>
                    <select name="type" id="edit_type" required>
                        <option value="service">Service Category</option>
                        <option value="product">Product Category</option>
                    </select>
                </div>
                <div class="modal-box-footer" style="padding:0;">
                    <button type="submit" name="edit_category" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('editModal').classList.remove('active')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(id, name, type) {
    document.getElementById('edit_id').value   = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_type').value = type;
    document.getElementById('editModal').classList.add('active');
}
document.getElementById('editModal').addEventListener('click', e => { if (e.target===document.getElementById('editModal')) document.getElementById('editModal').classList.remove('active'); });
</script>

<?php require_once 'admin_footer.php'; ?>