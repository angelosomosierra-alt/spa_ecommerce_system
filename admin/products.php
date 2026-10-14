<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();
require_once __DIR__ . '/../notify.php';

$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL");

$message      = '';
$message_type = '';
$field_errors = [];

// ─── FETCH PRODUCT CATEGORIES ─────────────────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT * FROM categories WHERE type = 'product' ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ─── ARCHIVE (SOFT DELETE) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_product') {
    verify_csrf_token();

    // Receptionist PIN check for archive
    $pr_prod_del = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_prod_del = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_prod_del) {
            $message = "Incorrect PIN. Archive action cancelled."; $message_type = "danger";
            goto end_prod_delete;
        }
    }

    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $prod_check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($prod_check) {
        $stmt = $conn->prepare("UPDATE products SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message      = "Product archived.";
            $message_type = "success";
            $_actor_prod_del = (is_cashier() && !empty($pr_prod_del['full_name']))
                ? ['id' => null, 'name' => $pr_prod_del['full_name'], 'role' => 'receptionist']
                : null;
            log_activity($conn, 'product_deleted', "Archived product ID {$id}", 'product', $id, $_actor_prod_del);
        } else {
            $message      = "Error archiving product.";
            $message_type = "danger";
        }
        $stmt->close();
    }
    end_prod_delete:;
}

// ─── RESTORE ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_product') {
    verify_csrf_token();
    $rid = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE products SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $rid);
    $stmt->execute(); $stmt->close();
    $message      = "Product restored.";
    $message_type = "success";
}

// ─── ADD / EDIT ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_product', 'restore_product'])) {
    verify_csrf_token();

    // Receptionist PIN check for add/edit
    $pr_prod_action = null;
    if (is_cashier()) {
        $entered_pin = trim($_POST['pin'] ?? '');
        $ps = $conn->prepare("SELECT full_name FROM receptionist_pins WHERE pin = ? LIMIT 1");
        $ps->bind_param("s", $entered_pin); $ps->execute();
        $pr_prod_action = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$pr_prod_action) {
            $message = "Incorrect PIN. Action cancelled."; $message_type = "danger";
            goto end_prod_action;
        }
    }

    $id          = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name        = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);
    $price       = floatval($_POST['price']);
    $stock       = intval($_POST['stock']);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;

    $field_errors = [];
    if (empty($name))        $field_errors['name']        = "Product name is required.";
    if (empty($description)) $field_errors['description'] = "Description is required.";
    if ($price <= 0)         $field_errors['price']       = "Price must be greater than ₱0.";
    if ($stock < 0)          $field_errors['stock']       = "Stock cannot be negative.";

    if (!empty($field_errors)) {
        $message      = "Please fix the highlighted fields below.";
        $message_type = "danger";
    } else {
        $image_name = '';

        if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
            $file          = $_FILES['image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file['type'], $allowed_types)) {
                $field_errors['image'] = "Only image files (JPEG, PNG, GIF, WebP) are allowed.";
                $message               = $field_errors['image'];
                $message_type          = "danger";
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $field_errors['image'] = "Image file size must not exceed 5MB.";
                $message               = $field_errors['image'];
                $message_type          = "danger";
            } else {
                $image_name  = 'product_' . time() . '_' . basename($file['name']);
                $upload_path = UPLOAD_DIR_PRODUCTS . $image_name;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    mirror_upload_to_persistent(UPLOAD_DIR_PRODUCTS, PERSISTENT_DIR_PRODUCTS, $image_name);
                    if ($id) {
                        $stmt = $conn->prepare("SELECT image FROM products WHERE id = ?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $old = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($old && $old['image'] && file_exists(UPLOAD_DIR_PRODUCTS . $old['image'])) {
                            unlink(UPLOAD_DIR_PRODUCTS . $old['image']);
                            remove_persistent_upload(PERSISTENT_DIR_PRODUCTS, $old['image']);
                        }
                    }
                } else {
                    $field_errors['image'] = "Error uploading image.";
                    $message               = $field_errors['image'];
                    $message_type          = "danger";
                }
            }
        } elseif (!$id) {
            $field_errors['image'] = "An image is required for new products.";
            $message               = $field_errors['image'];
            $message_type          = "danger";
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
                $prod_logged_id = $id ?? (int)$conn->insert_id;
                $_actor_prod = (is_cashier() && !empty($pr_prod_action['full_name']))
                    ? ['id' => null, 'name' => $pr_prod_action['full_name'], 'role' => 'receptionist']
                    : null;
                if ($id) {
                    log_activity($conn, 'product_updated', "Updated product: {$name}", 'product', $prod_logged_id, $_actor_prod);
                } else {
                    log_activity($conn, 'product_created', "Created product: {$name}", 'product', $prod_logged_id, $_actor_prod);
                }
            } else {
                $message      = "Error saving product: " . $conn->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
    end_prod_action:;
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
$low_stock_count = count(array_filter($products, fn($p) => $p['stock'] <= 10));
$out_of_stock    = count(array_filter($products, fn($p) => $p['stock'] == 0));
$in_stock        = $total_products - $out_of_stock;

$page_title  = $edit_product ? 'Edit Product' : 'Products';
$page_icon   = '';
$active_page = 'products';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" id="prod-error-alert"><?php echo $message; ?></div>
<?php if ($message_type === 'danger'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fe = document.querySelector('.field-error-msg');
    if (fe) { fe.scrollIntoView({behavior:'smooth', block:'center'}); }
    else { var al = document.getElementById('prod-error-alert'); if (al) al.scrollIntoView({behavior:'smooth', block:'center'}); }
});
</script>
<?php endif; ?>
<?php endif; ?>



<!-- ── ADD / EDIT FORM ────────────────────────────────────────────────────── -->
<?php if ((isset($_GET['action']) && $_GET['action'] === 'add') || $edit_product): ?>

<div style="margin-bottom:1rem;">
    <a href="products.php" class="btn btn-secondary">← Back to Products</a>
</div>

<div class="form-section">
    <div class="form-section-header">
        <?php echo $edit_product ? 'Edit Product' : 'Add New Product'; ?>
    </div>
    <div class="form-section-body">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <?php if ($edit_product): ?>
                <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
            <?php endif; ?>

            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Product Name <span class="required">*</span></label>
                    <input type="text" name="name" required
                           style="<?php echo isset($field_errors['name']) ? 'border-color:#dc3545;' : ''; ?>"
                           value="<?php echo htmlspecialchars($edit_product['name'] ?? ($_POST['name'] ?? '')); ?>">
                    <?php if (isset($field_errors['name'])): ?><div class="field-error-msg" style="color:#dc3545;font-size:0.8rem;margin-top:0.25rem;"><?php echo htmlspecialchars($field_errors['name']); ?></div><?php endif; ?>
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
                           style="<?php echo isset($field_errors['price']) ? 'border-color:#dc3545;' : ''; ?>"
                           value="<?php echo $edit_product['price'] ?? ($_POST['price'] ?? ''); ?>">
                    <?php if (isset($field_errors['price'])): ?><div class="field-error-msg" style="color:#dc3545;font-size:0.8rem;margin-top:0.25rem;"><?php echo htmlspecialchars($field_errors['price']); ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Stock Quantity <span class="required">*</span></label>
                    <input type="number" name="stock" min="0" required
                           style="<?php echo isset($field_errors['stock']) ? 'border-color:#dc3545;' : ''; ?>"
                           value="<?php echo $edit_product['stock'] ?? ($_POST['stock'] ?? ''); ?>">
                    <?php if (isset($field_errors['stock'])): ?><div class="field-error-msg" style="color:#dc3545;font-size:0.8rem;margin-top:0.25rem;"><?php echo htmlspecialchars($field_errors['stock']); ?></div><?php endif; ?>
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Product Image <?php echo !$edit_product ? '<span class="required">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           style="<?php echo isset($field_errors['image']) ? 'outline:2px solid #dc3545;border-radius:4px;' : ''; ?>"
                           <?php echo !$edit_product ? 'required' : ''; ?>>
                    <?php if ($edit_product && $edit_product['image']): ?>
                        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:1rem;">
                            <img src="../uploads/products/<?php echo htmlspecialchars($edit_product['image']); ?>"
                                 style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--border);">
                            <small>Current image — upload new to replace</small>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($field_errors['image'])): ?><div class="field-error-msg" style="color:#dc3545;font-size:0.8rem;margin-top:0.25rem;"><?php echo htmlspecialchars($field_errors['image']); ?></div><?php endif; ?>
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Description <span class="required">*</span></label>
                    <textarea name="description" rows="4" required
                              style="<?php echo isset($field_errors['description']) ? 'border-color:#dc3545;' : ''; ?>"><?php echo htmlspecialchars($edit_product['description'] ?? ($_POST['description'] ?? '')); ?></textarea>
                    <?php if (isset($field_errors['description'])): ?><div class="field-error-msg" style="color:#dc3545;font-size:0.8rem;margin-top:0.25rem;"><?php echo htmlspecialchars($field_errors['description']); ?></div><?php endif; ?>
                </div>
            </div>

            <?php if (is_cashier()): ?>
            <div class="form-group" style="max-width:200px;">
                <label>Your 4-digit PIN <span class="required">*</span></label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required
                       style="letter-spacing:0.3em;text-align:center;font-size:1rem;">
            </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo $edit_product ? 'Update Product' : 'Add Product'; ?>
                </button>
                <a href="products.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- ── PRODUCTS LIST (front end only) ─────────────────────────────────────── -->
<?php if (!$edit_product): ?>
<?php
$_pl_rows = [];
foreach ($products as $p) {
    $arch  = !empty($p['deleted_at']);
    $stock = (int)$p['stock'];
    $hasImg = !empty($p['image']) && is_file(UPLOAD_DIR_PRODUCTS . $p['image']);
    $flags = [];
    if ($arch) $flags[] = 'arch';
    else {
        $flags[] = 'all';
        if ($stock <= 0) $flags[] = 'out';
        elseif ($stock <= 10) $flags[] = 'low';
        else $flags[] = 'instock';
        if (empty($p['category_id'])) $flags[] = 'nocat';
        if (!$hasImg) $flags[] = 'nophoto';
    }
    $_pl_rows[] = ['p' => $p, 'arch' => $arch, 'stock' => $stock, 'img' => $hasImg, 'flags' => $flags];
}
$_pl_groups = [];
foreach ($_pl_rows as $r) $_pl_groups[$r['p']['category_name'] ?: ''][] = $r;
uksort($_pl_groups, function ($a, $b) { if ($a === '') return 1; if ($b === '') return -1; return strcasecmp($a, $b); });
foreach ($_pl_groups as &$_g) usort($_g, function ($a, $b) { return strcasecmp($a['p']['name'], $b['p']['name']); });
unset($_g);
$_pl_count = function ($f) use ($_pl_rows) { return count(array_filter($_pl_rows, function ($r) use ($f) { return in_array($f, $r['flags'], true); })); };
$_pl_filters = ['all' => 'All', 'instock' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock', 'nophoto' => 'No photo', 'nocat' => 'Uncategorized', 'arch' => 'Archived'];
$_pl_tiles   = ['all' => 'Active products', 'instock' => 'In stock', 'low' => 'Low stock (10 or less)', 'out' => 'Out of stock', 'arch' => 'Archived'];
$_pl_cat_req = $_GET['category'] ?? '';
$_pl_cat_init = $_pl_cat_req === 'none' ? '__none' : '';
foreach ($categories as $c) if ((string)$c['id'] === (string)$_pl_cat_req) $_pl_cat_init = $c['name'];
if (isset($_GET['cat_name'])) $_pl_cat_init = (string)$_GET['cat_name'];
$_pl_cat_names = array_values(array_filter(array_keys($_pl_groups), function ($k) { return $k !== ''; }));
?>
<link rel="stylesheet" href="../assets/admin-list.css?v=<?php echo filemtime(__DIR__ . '/../assets/admin-list.css'); ?>">
<style>
/* Products: price, stock and added columns instead of price chips + recipe */
.al-prod.al-row, .al-prod.al-colhead { grid-template-columns:56px minmax(0,2.6fr) 110px 130px 120px auto; }
.al-stock { font-size:0.8rem; white-space:nowrap; }
.al-stock b { display:block; font-size:0.95rem; color:var(--text, #2B1D12); font-variant-numeric:tabular-nums; }
.al-stock .bar { display:block; height:6px; border-radius:3px; background:#F3EBDF; margin-top:4px; overflow:hidden; width:90px; }
.al-stock .bar i { display:block; height:100%; border-radius:3px; }
.al-stock.out b { color:#A3261B; } .al-stock.low b { color:#A94F1D; }
.al-desc { color:var(--gray, #8A7560); font-size:0.78rem; margin-top:3px; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
.al-tag.out { background:#FBE3E0; color:#A3261B; } .al-tag.low { background:#F7E3D6; color:#A94F1D; } .al-tag.nophoto { background:#EEEAE4; color:#5E4733; }
.al-price { font-weight:800; font-size:1rem; color:var(--text, #2B1D12); font-variant-numeric:tabular-nums; }
@media (max-width:900px) {
  .al-prod.al-row { grid-template-columns:56px minmax(0,1fr) auto; grid-template-areas:"ph nm acts" "ph pr rc" "ph dt dt"; }
  .al-prod .al-dt { text-align:left; }
}
@media (max-width:520px) {
  .al-prod.al-row { grid-template-columns:48px minmax(0,1fr) minmax(0,1fr); grid-template-areas:"ph nm nm" "pr pr rc" "dt dt dt" "acts acts acts"; }
}
</style>

<div class="al-page">
    <div class="al-top">
        <div><h2>All products</h2><p id="alSub"><?php echo $_pl_count('all'); ?> active · <?php echo count($_pl_cat_names); ?> categories</p></div>
        <?php if (!isset($_GET['action'])): ?><a href="products.php?action=add" class="btn btn-primary btn-sm">+ Add New Product</a><?php endif; ?>
    </div>

    <div class="al-tiles" role="group" aria-label="Quick view">
        <?php foreach ($_pl_tiles as $k => $lbl): $n = $_pl_count($k); ?>
        <button type="button" class="al-tile<?php echo (in_array($k, ['low', 'out'], true) && $n) ? ' warn' : ''; ?>" data-f="<?php echo $k; ?>" aria-pressed="<?php echo $k === 'all' ? 'true' : 'false'; ?>"><b><?php echo $n; ?></b><span><?php echo $lbl; ?></span></button>
        <?php endforeach; ?>
    </div>

    <div class="al-bar">
        <label class="al-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="serviceSearchInput" placeholder="Search name, category or description…" aria-label="Search products" autocomplete="off"></label>
        <select id="alCat" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($_pl_cat_names as $cn): ?><option value="<?php echo htmlspecialchars($cn); ?>"<?php echo $_pl_cat_init === $cn ? ' selected' : ''; ?>><?php echo htmlspecialchars($cn); ?></option><?php endforeach; ?>
            <option value="__none"<?php echo $_pl_cat_init === '__none' ? ' selected' : ''; ?>>Uncategorized</option>
        </select>
        <div class="al-chips" role="group" aria-label="Filters">
            <?php foreach ($_pl_filters as $k => $lbl): ?>
            <button type="button" class="al-chip" data-f="<?php echo $k; ?>" aria-pressed="<?php echo $k === 'all' ? 'true' : 'false'; ?>"><?php echo $lbl; ?><small><?php echo $_pl_count($k); ?></small></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="al-colhead al-prod" aria-hidden="true"><span></span><span>Product</span><span>Price</span><span>Stock</span><span>Added</span><span style="text-align:right">Actions</span></div>

    <?php foreach ($_pl_groups as $gname => $rows): ?>
    <section class="al-group" data-cat="<?php echo htmlspecialchars($gname === '' ? '__none' : $gname); ?>">
        <div class="al-ghead"><h3><?php echo htmlspecialchars($gname === '' ? 'Uncategorized' : $gname); ?></h3><span class="al-gcount"><?php echo count($rows); ?></span></div>
        <div class="al-list">
        <?php foreach ($rows as $r): $product = $r['p']; $stk = $r['stock'];
            $scls = $stk <= 0 ? 'out' : ($stk <= 10 ? 'low' : '');
            $bar  = $stk <= 0 ? 0 : min(100, round($stk / 50 * 100));
            $bcol = $stk <= 0 ? '#A3261B' : ($stk <= 10 ? '#C9963A' : '#198754'); ?>
            <div class="al-row al-prod<?php echo $r['arch'] ? ' arch' : ''; ?>"
                 data-flags="<?php echo implode(' ', $r['flags']); ?>"
                 data-search="<?php echo htmlspecialchars(strtolower($product['name'] . ' ' . ($product['category_name'] ?? '') . ' ' . ($product['description'] ?? ''))); ?>">
                <div class="al-ph">
                    <?php if ($r['img']): ?><img src="../uploads/products/<?php echo htmlspecialchars($product['image']); ?>" alt="" loading="lazy" onerror="this.remove()"><?php else: ?>No img<?php endif; ?>
                </div>
                <div class="al-nm">
                    <b><?php echo htmlspecialchars($product['name']); ?></b>
                    <div class="al-sub"><?php echo htmlspecialchars($product['category_name'] ?: 'No category'); ?></div>
                    <?php if (!empty($product['description'])): ?><div class="al-desc"><?php echo htmlspecialchars($product['description']); ?></div><?php endif; ?>
                    <div class="al-tags">
                        <?php if (!$r['arch'] && $stk <= 0): ?><span class="al-tag out">Out of stock</span><?php elseif (!$r['arch'] && $stk <= 10): ?><span class="al-tag low">Low stock</span><?php endif; ?>
                        <?php if (!$r['img']): ?><span class="al-tag nophoto">No photo</span><?php endif; ?>
                        <?php if (empty($product['category_id'])): ?><span class="al-tag nocat">Uncategorized</span><?php endif; ?>
                        <?php if ($r['arch']): ?><span class="al-tag arch">Archived</span><?php endif; ?>
                    </div>
                </div>
                <div class="al-prices"><span class="al-price">₱<?php echo number_format($product['price'], 2); ?></span></div>
                <div class="al-rc al-stock <?php echo $scls; ?>"><b><?php echo $stk <= 0 ? 'Out of stock' : $stk . ' in stock'; ?></b><span class="bar"><i style="width:<?php echo $bar; ?>%;background:<?php echo $bcol; ?>"></i></span></div>
                <div class="al-dt"><?php echo !empty($product['created_at']) ? 'Added ' . date('M d, Y', strtotime($product['created_at'])) : ''; ?></div>
                <div class="al-acts">
                    <?php if ($product['deleted_at']): ?>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="restore_product">
                        <input type="hidden" name="id" value="<?php echo intval($product['id']); ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">Restore</button>
                    </form>
                    <?php else: ?>
                    <a href="products.php?edit=<?php echo $product['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                    <form method="POST" onsubmit="event.preventDefault();uiConfirm('Archive this product?').then(ok=>{if(ok)this.submit()})">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="id" value="<?php echo intval($product['id']); ?>">
                        <?php if (is_cashier()): ?>
                        <input type="password" name="pin" maxlength="4" placeholder="PIN" required aria-label="PIN">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-danger btn-sm">Archive</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="al-empty" id="svcNoResults"<?php echo $_pl_rows ? ' hidden' : ''; ?>><b><?php echo $_pl_rows ? 'No products match' : 'No products yet'; ?></b><?php echo $_pl_rows ? 'Try another word, category or filter.' : 'Add your first product with “+ Add New Product”.'; ?></div>
</div>

<script>
(function () {
    var state = { f: 'all', q: '', cat: document.getElementById('alCat').value };
    var groups = [].slice.call(document.querySelectorAll('.al-group'));
    function apply() {
        var q = state.q.trim().toLowerCase(), shown = 0;
        groups.forEach(function (g) {
            var inCat = !state.cat || g.dataset.cat === state.cat, n = 0;
            g.querySelectorAll('.al-row').forEach(function (r) {
                var ok = inCat && (' ' + r.dataset.flags + ' ').indexOf(' ' + state.f + ' ') >= 0 && (!q || r.dataset.search.indexOf(q) >= 0);
                r.hidden = !ok; if (ok) n++;
            });
            g.hidden = n === 0;
            g.querySelector('.al-gcount').textContent = n;
            shown += n;
        });
        if (groups.length) document.getElementById('svcNoResults').hidden = shown > 0;
        document.querySelectorAll('.al-tile, .al-chip').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.f === state.f); });
        var sub = document.getElementById('alSub');
        sub.textContent = sub.textContent.split(' · showing')[0] + ' · showing ' + shown;
    }
    document.querySelector('.al-page').addEventListener('click', function (e) { var b = e.target.closest('[data-f]'); if (!b) return; state.f = b.dataset.f; apply(); });
    document.getElementById('serviceSearchInput').addEventListener('input', function () { state.q = this.value; apply(); });
    document.getElementById('alCat').addEventListener('change', function () {
        state.cat = this.value; apply();
        var url = new URL(location.href);
        if (this.value) url.searchParams.set('cat_name', this.value); else url.searchParams.delete('cat_name');
        url.searchParams.delete('category');
        history.replaceState(null, '', url);
    });
    apply();
})();
</script>

<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>