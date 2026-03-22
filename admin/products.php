<?php
require_once '../config.php';
redirect_if_not_admin();

$message      = '';
$message_type = '';

// ─── FETCH PRODUCT CATEGORIES ─────────────────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT * FROM categories WHERE type = 'product' ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("SELECT image FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product) {
        if ($product['image'] && file_exists(UPLOAD_DIR_PRODUCTS . $product['image'])) {
            unlink(UPLOAD_DIR_PRODUCTS . $product['image']);
        }
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message      = "Product deleted successfully!";
            $message_type = "success";
        } else {
            $message      = "Error deleting product.";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// ─── ADD / EDIT ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name        = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);
    $price       = floatval($_POST['price']);
    $stock       = intval($_POST['stock']);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;

    if (empty($name) || empty($description) || $price <= 0 || $stock < 0) {
        $message      = "All fields are required and price must be positive.";
        $message_type = "danger";
    } else {
        $image_name = '';

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
            $file          = $_FILES['image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file['type'], $allowed_types)) {
                $message      = "Only image files (JPEG, PNG, GIF, WebP) are allowed.";
                $message_type = "danger";
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $message      = "File size must not exceed 5MB.";
                $message_type = "danger";
            } else {
                $image_name  = 'product_' . time() . '_' . basename($file['name']);
                $upload_path = UPLOAD_DIR_PRODUCTS . $image_name;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Delete old image if editing
                    if ($id) {
                        $stmt = $conn->prepare("SELECT image FROM products WHERE id = ?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $old = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($old && $old['image'] && file_exists(UPLOAD_DIR_PRODUCTS . $old['image'])) {
                            unlink(UPLOAD_DIR_PRODUCTS . $old['image']);
                        }
                    }
                } else {
                    $message      = "Error uploading image.";
                    $message_type = "danger";
                }
            }
        } elseif (!$id) {
            $message      = "Image is required for new products.";
            $message_type = "danger";
        }

        if ($message_type !== "danger") {
            if ($id) {
                if ($image_name) {
                    $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, image=?, category_id=? WHERE id=?");
                    $stmt->bind_param("ssdisii", $name, $description, $price, $stock, $image_name, $category_id, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category_id=? WHERE id=?");
                    $stmt->bind_param("ssdiII", $name, $description, $price, $stock, $category_id, $id);
                    // Fix bind — use nullable int
                    $stmt->close();
                    if ($category_id !== null) {
                        $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category_id=? WHERE id=?");
                        $stmt->bind_param("ssdiii", $name, $description, $price, $stock, $category_id, $id);
                    } else {
                        $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category_id=NULL WHERE id=?");
                        $stmt->bind_param("ssdii", $name, $description, $price, $stock, $id);
                    }
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock, image, category_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdisi", $name, $description, $price, $stock, $image_name, $category_id);
            }

            if ($stmt->execute()) {
                $message      = $id ? "Product updated successfully!" : "Product added successfully!";
                $message_type = "success";
            } else {
                $message      = "Error saving product: " . $conn->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
}

// ─── FETCH FOR EDITING ────────────────────────────────────────────────────────
$edit_product = null;
if (isset($_GET['edit'])) {
    $id   = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ─── FETCH ALL PRODUCTS ───────────────────────────────────────────────────────
$products = [];
$result   = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Management - Admin</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <style>
        table { width:100%; border-collapse:collapse; }
        table th, table td { padding:0.8rem; text-align:left; border-bottom:1px solid #EAD8C0; }
        table thead tr { background:#f9f1e7; }
        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: bold;
            background: #EAD8C0;
            color: #3B2A1A;
        }
        .no-category {
            display: inline-block;
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
            font-size: 0.78rem;
            background: #e2e3e5;
            color: #888;
        }
        .stock-low  { color: red; font-weight: bold; }
        .stock-ok   { color: #198754; font-weight: bold; }
        .form-row   { display:flex; gap:1rem; flex-wrap:wrap; }
        .form-row .form-group { flex:1; min-width:200px; }
    </style>
</head>
<body>

<header>
    <nav>
        <div class="logo">Spa Admin</div>
        <ul class="nav-links">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php">Services</a></li>
            <li><a href="products.php" class="active">Products</a></li>
            <li><a href="categories.php">Categories</a></li>
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
            <li><a href="products.php" class="active">Products</a></li>
            <li><a href="categories.php">Categories</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="appointments.php">Appointments</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="analytics.php">Analytics</a></li>
            <li><a href="walkin.php">Walk-in</a></li>
        </ul>
    </aside>

    <main class="admin-content">
        <div class="admin-header">
            <h2><?php echo $edit_product ? "Edit Product" : "Products Management"; ?></h2>
            <?php if (!$edit_product && !isset($_GET['action'])): ?>
                <a href="products.php?action=add" class="btn btn-primary">+ Add New Product</a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div style="padding:1rem; border-radius:8px; margin-bottom:1.5rem;
                        background:<?php echo $message_type === 'success' ? '#d1e7dd' : '#f8d7da'; ?>;
                        color:<?php echo $message_type === 'success' ? '#0a3622' : '#842029'; ?>;
                        border-left:5px solid <?php echo $message_type === 'success' ? '#198754' : '#dc3545'; ?>;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- ── ADD / EDIT FORM ──────────────────────────────────────────────── -->
        <?php if (isset($_GET['action']) && $_GET['action'] === 'add' || $edit_product): ?>
        <div style="background:#FAF3E8; padding:2rem; border-radius:12px; margin-bottom:2rem;">
            <h3 style="color:#3B2A1A; margin-bottom:1.5rem;">
                <?php echo $edit_product ? "✏️ Edit Product" : "➕ Add New Product"; ?>
            </h3>

            <form method="POST" enctype="multipart/form-data">
                <?php if ($edit_product): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
                <?php endif; ?>

                <!-- Row 1: Name + Category -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name <span style="color:red;">*</span></label>
                        <input type="text" name="name" required
                               value="<?php echo htmlspecialchars($edit_product['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id">
                            <option value="">-- No Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"
                                    <?php echo (isset($edit_product['category_id']) && $edit_product['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#888;">
                            <a href="categories.php" target="_blank" style="color:#C96A2C;">
                                + Manage Categories
                            </a>
                        </small>
                    </div>
                </div>

                <!-- Row 2: Price + Stock -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Price ($) <span style="color:red;">*</span></label>
                        <input type="number" name="price" step="0.01" min="0.01" required
                               value="<?php echo $edit_product['price'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Stock Quantity <span style="color:red;">*</span></label>
                        <input type="number" name="stock" min="0" required
                               value="<?php echo $edit_product['stock'] ?? ''; ?>">
                    </div>
                </div>

                <!-- Row 3: Image -->
                <div class="form-group">
                    <label>Product Image <?php echo !$edit_product ? '<span style="color:red;">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_product ? 'required' : ''; ?>>
                    <?php if ($edit_product && $edit_product['image']): ?>
                        <div style="margin-top:0.5rem; display:flex; align-items:center; gap:1rem;">
                            <img src="../uploads/products/<?php echo htmlspecialchars($edit_product['image']); ?>"
                                 style="width:60px; height:60px; object-fit:cover; border-radius:8px; border:2px solid #EAD8C0;">
                            <small style="color:#666;">Current image — upload new to replace</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Row 4: Description -->
                <div class="form-group">
                    <label>Description <span style="color:red;">*</span></label>
                    <textarea name="description" rows="4" required><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; gap:1rem;">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $edit_product ? "✅ Update Product" : "✅ Add Product"; ?>
                    </button>
                    <a href="products.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- ── PRODUCTS TABLE ───────────────────────────────────────────────── -->
        <div style="overflow-x:auto; background:#fff; border-radius:12px; padding:1.5rem;">

            <!-- Category filter tabs -->
            <?php
            $filter_cat = $_GET['category'] ?? 'all';
            ?>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem;">
                <a href="products.php"
                   style="padding:0.4rem 1rem; border-radius:20px; font-size:0.88rem; font-weight:bold;
                          text-decoration:none; border:2px solid #EAD8C0;
                          background:<?php echo $filter_cat === 'all' ? '#C96A2C' : '#FAF3E8'; ?>;
                          color:<?php echo $filter_cat === 'all' ? '#fff' : '#3B2A1A'; ?>;">
                    All
                </a>
                <a href="products.php?category=none"
                   style="padding:0.4rem 1rem; border-radius:20px; font-size:0.88rem; font-weight:bold;
                          text-decoration:none; border:2px solid #EAD8C0;
                          background:<?php echo $filter_cat === 'none' ? '#C96A2C' : '#FAF3E8'; ?>;
                          color:<?php echo $filter_cat === 'none' ? '#fff' : '#3B2A1A'; ?>;">
                    Uncategorized
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="products.php?category=<?php echo $cat['id']; ?>"
                       style="padding:0.4rem 1rem; border-radius:20px; font-size:0.88rem; font-weight:bold;
                              text-decoration:none; border:2px solid #EAD8C0;
                              background:<?php echo $filter_cat == $cat['id'] ? '#C96A2C' : '#FAF3E8'; ?>;
                              color:<?php echo $filter_cat == $cat['id'] ? '#fff' : '#3B2A1A'; ?>;">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Description</th>
                        <th>Date Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $filtered = array_filter($products, function($p) use ($filter_cat) {
                        if ($filter_cat === 'all')  return true;
                        if ($filter_cat === 'none') return empty($p['category_id']);
                        return $p['category_id'] == $filter_cat;
                    });
                    ?>
                    <?php if (!empty($filtered)): ?>
                        <?php foreach ($filtered as $product): ?>
                        <tr>
                            <td><strong>#<?php echo $product['id']; ?></strong></td>
                            <td>
                                <?php if ($product['image']): ?>
                                    <img src="../uploads/products/<?php echo htmlspecialchars($product['image']); ?>"
                                         style="width:55px; height:55px; object-fit:cover; border-radius:8px;">
                                <?php else: ?>
                                    <div style="width:55px; height:55px; background:#EAD8C0; border-radius:8px;
                                                display:flex; align-items:center; justify-content:center; color:#888; font-size:0.7rem;">
                                        No img
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                            <td>
                                <?php if ($product['category_name']): ?>
                                    <span class="category-badge">
                                        <?php echo htmlspecialchars($product['category_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-category">Uncategorized</span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color:#C96A2C;">$<?php echo number_format($product['price'], 2); ?></strong></td>
                            <td>
                                <span class="<?php echo $product['stock'] <= 5 ? 'stock-low' : 'stock-ok'; ?>">
                                    <?php echo $product['stock']; ?>
                                    <?php echo $product['stock'] <= 5 ? '⚠️' : ''; ?>
                                </span>
                            </td>
                            <td style="max-width:200px; color:#666;">
                                <?php echo htmlspecialchars(substr($product['description'], 0, 60)) . '...'; ?>
                            </td>
                            <td style="font-size:0.85rem; color:#888;">
                                <?php echo date('M d, Y', strtotime($product['created_at'])); ?>
                            </td>
                            <td>
                                <a href="products.php?edit=<?php echo $product['id']; ?>"
                                   class="btn btn-info"
                                   style="padding:0.4rem 0.8rem; font-size:0.82rem;">Edit</a>
                                <a href="products.php?delete=<?php echo $product['id']; ?>"
                                   class="btn btn-danger"
                                   style="padding:0.4rem 0.8rem; font-size:0.82rem;"
                                   onclick="return confirm('Delete this product?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:2rem; color:#888;">
                                No products found in this category.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>
</div>

</body>
</html>