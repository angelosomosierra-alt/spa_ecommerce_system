<?php
require_once '../config.php';
redirect_if_not_admin();

$message      = '';
$message_type = '';

// ─── FETCH SERVICE CATEGORIES ─────────────────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT * FROM categories WHERE type = 'service' ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("SELECT image FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($service) {
        if ($service['image'] && file_exists(UPLOAD_DIR_SERVICES . $service['image'])) {
            unlink(UPLOAD_DIR_SERVICES . $service['image']);
        }
        $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message      = "Service deleted successfully!";
            $message_type = "success";
        } else {
            $message      = "Error deleting service.";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// ─── ADD / EDIT ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id           = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name         = sanitize_input($_POST['name']);
    $description  = sanitize_input($_POST['description']);
    $price        = floatval($_POST['price']);
    $session_time = intval($_POST['session_time']);
    $slots        = intval($_POST['slots']);
    $category_id  = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;

    if (empty($name) || empty($description) || $price <= 0 || $session_time <= 0 || $slots <= 0) {
        $message      = "All fields are required and price/session time/slots must be positive.";
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
                $image_name  = 'service_' . time() . '_' . basename($file['name']);
                $upload_path = UPLOAD_DIR_SERVICES . $image_name;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    if ($id) {
                        $stmt = $conn->prepare("SELECT image FROM services WHERE id = ?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $old = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($old && $old['image'] && file_exists(UPLOAD_DIR_SERVICES . $old['image'])) {
                            unlink(UPLOAD_DIR_SERVICES . $old['image']);
                        }
                    }
                } else {
                    $message      = "Error uploading image.";
                    $message_type = "danger";
                }
            }
        } elseif (!$id) {
            $message      = "Image is required for new services.";
            $message_type = "danger";
        }

        if ($message_type !== "danger") {
            if ($id) {
                if ($image_name) {
                    $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, slots=?, image=?, category_id=? WHERE id=?");
                    $stmt->bind_param("ssdiiisi", $name, $description, $price, $session_time, $slots, $image_name, $category_id, $id);
                } else {
                    if ($category_id !== null) {
                        $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, slots=?, category_id=? WHERE id=?");
                        $stmt->bind_param("ssdiiii", $name, $description, $price, $session_time, $slots, $category_id, $id);
                    } else {
                        $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, slots=?, category_id=NULL WHERE id=?");
                        $stmt->bind_param("ssdiii", $name, $description, $price, $session_time, $slots, $id);
                    }
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO services (name, description, price, session_time, slots, image, category_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdiisi", $name, $description, $price, $session_time, $slots, $image_name, $category_id);
            }

            if ($stmt->execute()) {
                $message      = $id ? "Service updated successfully!" : "Service added successfully!";
                $message_type = "success";
            } else {
                $message      = "Error saving service: " . $conn->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
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

// ─── STATS ────────────────────────────────────────────────────────────────────
$total_services    = count($services);
$total_slots       = array_sum(array_column($services, 'slots'));
$avg_price         = $total_services ? array_sum(array_column($services, 'price')) / $total_services : 0;
$categorized_count = count(array_filter($services, fn($s) => !empty($s['category_id'])));

$page_title  = $edit_service ? 'Edit Service' : 'Services';
$page_icon   = '💆';
$active_page = 'services';
require_once 'admin_header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<?php if (!$edit_service && !isset($_GET['action'])): ?>
<!-- ── STATS ──────────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon">💆</div>
        <div class="stat-number"><?php echo $total_services; ?></div>
        <div class="stat-label">Total Services</div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon">📅</div>
        <div class="stat-number"><?php echo $total_slots; ?></div>
        <div class="stat-label">Total Slots/Day</div>
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
</div>
<?php endif; ?>

<!-- ── ADD / EDIT FORM ────────────────────────────────────────────────────── -->
<?php if ((isset($_GET['action']) && $_GET['action'] === 'add') || $edit_service): ?>

<div style="margin-bottom:1rem;">
    <a href="services.php" class="btn btn-secondary">← Back to Services</a>
</div>

<div class="form-section">
    <div class="form-section-header">
        <?php echo $edit_service ? '✏️ Edit Service' : '➕ Add New Service'; ?>
    </div>
    <div class="form-section-body">
        <form method="POST" enctype="multipart/form-data">
            <?php if ($edit_service): ?>
                <input type="hidden" name="id" value="<?php echo $edit_service['id']; ?>">
            <?php endif; ?>

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

            <div class="form-grid form-grid-3" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price" step="0.01" min="0.01" required
                           value="<?php echo $edit_service['price'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Session Time (minutes) <span class="required">*</span></label>
                    <input type="number" name="session_time" min="1" required
                           value="<?php echo $edit_service['session_time'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Available Slots/Day <span class="required">*</span></label>
                    <input type="number" name="slots" min="1" required
                           value="<?php echo $edit_service['slots'] ?? 5; ?>">
                </div>
            </div>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Service Image <?php echo !$edit_service ? '<span class="required">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_service ? 'required' : ''; ?>>
                    <?php if ($edit_service && $edit_service['image']): ?>
                        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:1rem;">
                            <img src="../uploads/services/<?php echo htmlspecialchars($edit_service['image']); ?>"
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

<!-- ── SERVICES TABLE ─────────────────────────────────────────────────────── -->
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

    <div class="table-wrap" style="border:none;border-radius:0;">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Duration</th>
                    <th>Slots/Day</th>
                    <th>Description</th>
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
                    <tr>
                        <td><strong style="color:var(--gold);">#<?php echo $service['id']; ?></strong></td>
                        <td>
                            <?php if ($service['image']): ?>
                                <img src="../uploads/services/<?php echo htmlspecialchars($service['image']); ?>"
                                     class="thumb" alt="">
                            <?php else: ?>
                                <div class="thumb" style="background:var(--surface);display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:0.7rem;">No img</div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($service['name']); ?></strong></td>
                        <td>
                            <?php if ($service['category_name']): ?>
                                <span class="badge badge-approved"><?php echo htmlspecialchars($service['category_name']); ?></span>
                            <?php else: ?>
                                <span class="badge" style="background:var(--surface);color:var(--gray);">Uncategorized</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color:var(--rust);">₱<?php echo number_format($service['price'], 2); ?></strong></td>
                        <td style="color:var(--gray);">⏱ <?php echo $service['session_time']; ?> mins</td>
                        <td><span class="badge badge-info">📅 <?php echo $service['slots']; ?> slots</span></td>
                        <td style="max-width:180px;color:var(--gray);font-size:0.82rem;">
                            <?php echo htmlspecialchars(substr($service['description'], 0, 60)) . '...'; ?>
                        </td>
                        <td style="font-size:0.78rem;color:var(--gray);">
                            <?php echo date('M d, Y', strtotime($service['created_at'])); ?>
                        </td>
                        <td>
                            <a href="services.php?edit=<?php echo $service['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                            <a href="services.php?delete=<?php echo $service['id']; ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete this service?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align:center;color:var(--gray);padding:2rem;">
                            No services found in this category.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>