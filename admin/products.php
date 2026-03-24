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

// ─── STATS ────────────────────────────────────────────────────────────────────
$total_products  = count($products);
$low_stock_count = count(array_filter($products, fn($p) => $p['stock'] <= 5));
$out_of_stock    = count(array_filter($products, fn($p) => $p['stock'] == 0));
$in_stock        = $total_products - $out_of_stock;

$page_title  = $edit_product ? 'Edit Product' : 'Products';
$page_icon   = '🛍️';
$active_page = 'products';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<?php if (!$edit_product && !isset($_GET['action'])): ?>
<!-- ── STATS ──────────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon">🛍️</div>
        <div class="stat-number"><?php echo $total_products; ?></div>
        <div class="stat-label">Total Products</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">✅</div>
        <div class="stat-number"><?php echo $in_stock; ?></div>
        <div class="stat-label">In Stock</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">⚠️</div>
        <div class="stat-number"><?php echo $low_stock_count; ?></div>
        <div class="stat-label">Low Stock (≤5)</div>
    </div>
    <div class="stat-card red">
        <div class="stat-icon">❌</div>
        <div class="stat-number"><?php echo $out_of_stock; ?></div>
        <div class="stat-label">Out of Stock</div>
    </div>
</div>
<?php endif; ?>

<!-- ── ADD / EDIT FORM ────────────────────────────────────────────────────── -->
<?php if ((isset($_GET['action']) && $_GET['action'] === 'add') || $edit_product): ?>

<div style="margin-bottom:1rem;">
    <a href="products.php" class="btn btn-secondary">← Back to Products</a>
</div>

<div class="form-section">
    <div class="form-section-header">
        <?php echo $edit_product ? '✏️ Edit Product' : '➕ Add New Product'; ?>
    </div>
    <div class="form-section-body">
        <form method="POST" enctype="multipart/form-data">
            <?php if ($edit_product): ?>
                <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
            <?php endif; ?>

            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Product Name <span class="required">*</span></label>
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
                    <small><a href="categories.php" target="_blank">+ Manage Categories</a></small>
                </div>
            </div>

            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price" step="0.01" min="0.01" required
                           value="<?php echo $edit_product['price'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Stock Quantity <span class="required">*</span></label>
                    <input type="number" name="stock" min="0" required
                           value="<?php echo $edit_product['stock'] ?? ''; ?>">
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Product Image <?php echo !$edit_product ? '<span class="required">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_product ? 'required' : ''; ?>>
                    <?php if ($edit_product && $edit_product['image']): ?>
                        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:1rem;">
                            <img src="../uploads/products/<?php echo htmlspecialchars($edit_product['image']); ?>"
                                 style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--border);">
                            <small>Current image — upload new to replace</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Description <span class="required">*</span></label>
                    <textarea name="description" rows="4" required><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo $edit_product ? '✅ Update Product' : '✅ Add Product'; ?>
                </button>
                <a href="products.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- ── PRODUCTS TABLE ─────────────────────────────────────────────────────── -->
<?php if (!$edit_product): ?>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🛍️ All Products</span>
        <?php if (!isset($_GET['action'])): ?>
            <a href="products.php?action=add" class="btn btn-primary btn-sm">+ Add New Product</a>
        <?php endif; ?>
    </div>

    <?php
    $filter_cat = $_GET['category'] ?? 'all';
    ?>
    <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--border);">
        <div class="filter-tabs">
            <a href="products.php" class="filter-tab <?php echo $filter_cat === 'all' ? 'active' : ''; ?>">All</a>
            <a href="products.php?category=none" class="filter-tab <?php echo $filter_cat === 'none' ? 'active' : ''; ?>">Uncategorized</a>
            <?php foreach ($categories as $cat): ?>
                <a href="products.php?category=<?php echo $cat['id']; ?>"
                   class="filter-tab <?php echo $filter_cat == $cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="table-wrap" style="border:none;border-radius:0;">
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
                        <td><strong style="color:var(--gold);">#<?php echo $product['id']; ?></strong></td>
                        <td>
                            <?php if ($product['image']): ?>
                                <img src="../uploads/products/<?php echo htmlspecialchars($product['image']); ?>"
                                     class="thumb" alt="">
                            <?php else: ?>
                                <div class="thumb" style="background:var(--surface);display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:0.7rem;">No img</div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                        <td>
                            <?php if ($product['category_name']): ?>
                                <span class="badge badge-approved"><?php echo htmlspecialchars($product['category_name']); ?></span>
                            <?php else: ?>
                                <span class="badge" style="background:var(--surface);color:var(--gray);">Uncategorized</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color:var(--rust);">₱<?php echo number_format($product['price'], 2); ?></strong></td>
                        <td>
                            <?php if ($product['stock'] == 0): ?>
                                <span class="badge badge-rejected">Out of Stock</span>
                            <?php elseif ($product['stock'] <= 5): ?>
                                <span class="badge badge-pending"><?php echo $product['stock']; ?> ⚠️</span>
                            <?php else: ?>
                                <span class="badge badge-approved"><?php echo $product['stock']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:200px;color:var(--gray);font-size:0.82rem;">
                            <?php echo htmlspecialchars(substr($product['description'], 0, 60)) . '...'; ?>
                        </td>
                        <td style="font-size:0.78rem;color:var(--gray);">
                            <?php echo date('M d, Y', strtotime($product['created_at'])); ?>
                        </td>
                        <td>
                            <a href="products.php?edit=<?php echo $product['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                            <a href="products.php?delete=<?php echo $product['id']; ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete this product?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--gray);padding:2rem;">
                            No products found in this category.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>