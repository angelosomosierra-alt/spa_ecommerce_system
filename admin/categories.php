<?php
require_once '../config.php';
redirect_if_not_admin();

$message      = '';
$message_type = '';

// ─── ADD CATEGORY ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = sanitize_input($_POST['name']);
    $type = $_POST['type'];

    if (empty($name) || empty($type)) {
        $message      = "Category name and type are required.";
        $message_type = "danger";
    } else {
        // Check duplicate
        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND type = ?");
        $stmt->bind_param("ss", $name, $type);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message      = "Category '$name' already exists for this type.";
            $message_type = "danger";
            $stmt->close();
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO categories (name, type) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $type);
            if ($stmt->execute()) {
                $message      = "Category '$name' added successfully!";
                $message_type = "success";
            } else {
                $message      = "Error adding category.";
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
}

// ─── DELETE CATEGORY ──────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Check if category is in use
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM products WHERE category_id = ?) +
            (SELECT COUNT(*) FROM services WHERE category_id = ?) as total
    ");
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $in_use = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    if ($in_use > 0) {
        $message      = "Cannot delete — this category is used by $in_use product(s)/service(s). Reassign them first.";
        $message_type = "danger";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message      = "Category deleted successfully.";
            $message_type = "success";
        }
        $stmt->close();
    }
}

// ─── EDIT CATEGORY ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $id   = intval($_POST['category_id']);
    $name = sanitize_input($_POST['name']);
    $type = $_POST['type'];

    $stmt = $conn->prepare("UPDATE categories SET name = ?, type = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $type, $id);
    if ($stmt->execute()) {
        $message      = "Category updated successfully!";
        $message_type = "success";
    } else {
        $message      = "Error updating category.";
        $message_type = "danger";
    }
    $stmt->close();
}

// ─── FETCH ALL CATEGORIES ─────────────────────────────────────────
$service_categories = [];
$product_categories = [];

$result = $conn->query("
    SELECT c.*, 
        (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) as service_count,
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count
    FROM categories c
    ORDER BY c.type, c.name
");
while ($row = $result->fetch_assoc()) {
    if ($row['type'] === 'service') {
        $service_categories[] = $row;
    } else {
        $product_categories[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management - Admin</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <style>
        .category-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 1.5rem;
        }
        @media (max-width: 768px) { .category-grid { grid-template-columns: 1fr; } }

        .category-panel {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }
        .category-panel h3 {
            color: #3B2A1A;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #EAD8C0;
            font-size: 1.1rem;
        }
        .category-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #FAF3E8;
            border: 1px solid #EAD8C0;
            gap: 0.5rem;
        }
        .category-item:hover { background: #EAD8C0; }
        .category-name { font-weight: bold; color: #3B2A1A; flex: 1; }
        .category-count {
            font-size: 0.8rem;
            background: #C96A2C;
            color: #fff;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
        }
        .category-actions { display: flex; gap: 0.4rem; }
        .btn-edit   { background: #0dcaf0; color: #000; padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.82rem; border: none; cursor: pointer; }
        .btn-delete { background: #dc3545; color: #fff; padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.82rem; text-decoration: none; }

        .add-form {
            background: #FAF3E8;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .add-form h3 { color: #3B2A1A; margin-bottom: 1rem; }
        .form-inline { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
        .form-inline .form-group { flex: 1; min-width: 150px; margin: 0; }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-box h3 { color: #3B2A1A; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

<header>
    <nav>
        <div class="logo">Spa Admin</div>
        <ul class="nav-links">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="categories.php" class="active">Categories</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="analytics.php">Analytics</a></li>
            <li><a href="walkin.php">Walk-in</a></li>
        </ul>
        <div class="auth-links">
            <span style="color:#FAF3E8;">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="index.php?logout=1">Logout</a>
        </div>
    </nav>
</header>

<div class="container">
<div class="admin-container">

    <aside class="admin-sidebar">
        <ul class="admin-menu">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="categories.php" class="active">Categories</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="analytics.php">Analytics</a></li>
            <li><a href="walkin.php">Walk-in</a></li>
        </ul>
    </aside>

    <main class="admin-content">
        <div class="admin-header">
            <h2>🏷️ Category Management</h2>
        </div>

        <?php if ($message): ?>
            <div style="padding:1rem; border-radius:8px; margin-bottom:1.5rem;
                        background:<?php echo $message_type === 'success' ? '#d1e7dd' : '#f8d7da'; ?>;
                        color:<?php echo $message_type === 'success' ? '#0a3622' : '#842029'; ?>;
                        border-left:5px solid <?php echo $message_type === 'success' ? '#198754' : '#dc3545'; ?>;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Add Category Form -->
        <div class="add-form">
            <h3>➕ Add New Category</h3>
            <form method="POST">
                <div class="form-inline">
                    <div class="form-group">
                        <label>Category Name</label>
                        <input type="text" name="name" placeholder="e.g. Brows Services" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" required>
                            <option value="">-- Select Type --</option>
                            <option value="service">Service Category</option>
                            <option value="product">Product Category</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="add_category" class="btn btn-primary"
                                style="padding:0.75rem 1.5rem; margin-top:1.5rem;">
                            Add Category
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Category Lists -->
        <div class="category-grid">

            <!-- Service Categories -->
            <div class="category-panel">
                <h3>💆 Service Categories
                    <span style="font-size:0.85rem; color:#888; font-weight:normal;">
                        (<?php echo count($service_categories); ?> categories)
                    </span>
                </h3>
                <?php if (!empty($service_categories)): ?>
                    <?php foreach ($service_categories as $cat): ?>
                        <div class="category-item">
                            <span class="category-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <span class="category-count"><?php echo $cat['service_count']; ?> service(s)</span>
                            <div class="category-actions">
                                <button class="btn-edit"
                                        onclick="openEdit(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['name']); ?>', 'service')">
                                    ✏️ Edit
                                </button>
                                <?php if ($cat['service_count'] == 0): ?>
                                    <a href="categories.php?delete=<?php echo $cat['id']; ?>"
                                       class="btn-delete"
                                       onclick="return confirm('Delete this category?')">🗑️</a>
                                <?php else: ?>
                                    <span style="font-size:0.75rem; color:#999; padding:0.3rem;">In use</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:#999; text-align:center; padding:1rem;">No service categories yet.</p>
                <?php endif; ?>
            </div>

            <!-- Product Categories -->
            <div class="category-panel">
                <h3>🛍️ Product Categories
                    <span style="font-size:0.85rem; color:#888; font-weight:normal;">
                        (<?php echo count($product_categories); ?> categories)
                    </span>
                </h3>
                <?php if (!empty($product_categories)): ?>
                    <?php foreach ($product_categories as $cat): ?>
                        <div class="category-item">
                            <span class="category-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <span class="category-count"><?php echo $cat['product_count']; ?> product(s)</span>
                            <div class="category-actions">
                                <button class="btn-edit"
                                        onclick="openEdit(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['name']); ?>', 'product')">
                                    ✏️ Edit
                                </button>
                                <?php if ($cat['product_count'] == 0): ?>
                                    <a href="categories.php?delete=<?php echo $cat['id']; ?>"
                                       class="btn-delete"
                                       onclick="return confirm('Delete this category?')">🗑️</a>
                                <?php else: ?>
                                    <span style="font-size:0.75rem; color:#999; padding:0.3rem;">In use</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:#999; text-align:center; padding:1rem;">No product categories yet.</p>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ Edit Category</h3>
        <form method="POST">
            <input type="hidden" name="category_id" id="edit_id">
            <div class="form-group">
                <label>Category Name</label>
                <input type="text" name="name" id="edit_name" required>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type" id="edit_type" required>
                    <option value="service">Service Category</option>
                    <option value="product">Product Category</option>
                </select>
            </div>
            <div style="display:flex; gap:1rem; margin-top:1rem;">
                <button type="submit" name="edit_category" class="btn btn-primary" style="flex:1;">
                    Save Changes
                </button>
                <button type="button" class="btn btn-secondary" style="flex:1;"
                        onclick="document.getElementById('editModal').classList.remove('active')">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(id, name, type) {
    document.getElementById('edit_id').value   = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_type').value = type;
    document.getElementById('editModal').classList.add('active');
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
});
</script>

</body>
</html>