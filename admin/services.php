<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();
redirect_if_not_admin();

$conn->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL");

$message      = '';
$message_type = '';

// ─── FETCH SERVICE CATEGORIES ─────────────────────────────────────────────────
$categories = [];
$cat_result = $conn->query("SELECT * FROM categories WHERE type = 'service' ORDER BY name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// ─── ARCHIVE (SOFT DELETE) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_service') {
    verify_csrf_token();
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
        } else {
            $message      = "Error archiving service.";
            $message_type = "danger";
        }
        $stmt->close();
    }
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
    $id               = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name             = sanitize_input($_POST['name']);
    $description      = sanitize_input($_POST['description']);
    $price            = floatval($_POST['price']);
    $session_time     = intval($_POST['session_time']);
    $category_id      = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $is_home_service  = isset($_POST['is_home_service']) ? 1 : 0;
    $home_service_fee = $is_home_service ? floatval($_POST['home_service_fee'] ?? 0) : 0.00;

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
            if ($image_name !== '') {
                $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, image=?, category_id=?, is_home_service=?, home_service_fee=? WHERE id=?");
                $stmt->bind_param("ssdiisiidi", $name, $description, $price, $session_time, $image_name, $category_id, $is_home_service, $home_service_fee, $id);
            } else {
                $stmt = $conn->prepare("UPDATE services SET name=?, description=?, price=?, session_time=?, category_id=?, is_home_service=?, home_service_fee=? WHERE id=?");
                $stmt->bind_param("ssdiiidi", $name, $description, $price, $session_time, $category_id, $is_home_service, $home_service_fee, $id);
            }
        } else {
            // NEW SERVICE
            $stmt = $conn->prepare("INSERT INTO services (name, description, price, session_time, image, category_id, is_home_service, home_service_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdisiid", $name, $description, $price, $session_time, $image_name, $category_id, $is_home_service, $home_service_fee);
        }

        if ($stmt->execute()) {
            // Success! Force a redirect to clear POST data and show the new image
            header("Location: services.php?success=1");
            exit();
        } else {
            $message = "Database Error: " . $conn->error;
            $message_type = "danger";
        }
        $stmt->close();
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
    <form method="POST" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
<!-- ── STATS ──────────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.5rem;">
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
            <?php echo csrf_field(); ?>
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

            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;">
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
            </div>

            <!-- Home Service Toggle + Fee -->
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

            <!-- Home Service Fee (shown only when checkbox is checked) -->
            <div class="form-grid form-grid-2" style="margin-bottom:1.25rem;"
                 id="homeFeeSectionRow"
                 <?php if (empty($edit_service['is_home_service'])): ?>style="display:none;"<?php endif; ?>>
                <div class="form-group">
                    <label>🏠 Home Service Fee (₱) <span class="required">*</span></label>
                    <input type="number" name="home_service_fee" id="homeServiceFee"
                           step="0.01" min="0"
                           value="<?php echo $edit_service['home_service_fee'] ?? '0.00'; ?>"
                           placeholder="e.g. 150.00">
                    <small style="color:var(--gray);">
                        Extra charge added to the total when customer selects Home Service. Set to 0 for no extra fee.
                    </small>
                </div>
            </div>
            <script>
            document.getElementById('homeServiceToggle').addEventListener('change', function() {
                const row = document.getElementById('homeFeeSectionRow');
                const fee = document.getElementById('homeServiceFee');
                if (this.checked) {
                    row.style.display = '';
                    fee.required = true;
                } else {
                    row.style.display = 'none';
                    fee.required = false;
                    fee.value = '0.00';
                }
            });
            // Set required state on page load
            (function(){
                const chk = document.getElementById('homeServiceToggle');
                document.getElementById('homeServiceFee').required = chk.checked;
            })();
            </script>

            <div class="form-grid form-grid-1" style="margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Service Image <?php echo !$edit_service ? '<span class="required">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_service ? 'required' : ''; ?>>
                    <?php if ($edit_service && $edit_service['image']): ?>
                        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:1rem;">
                            <img src="../uploads/services/<?php echo htmlspecialchars($service['image']); ?>" class="thumb" alt="">                                 style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--border);">
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
                    <th>Type</th>
                    <th>Price</th>
                    <th>Duration</th>
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
                    <tr<?php if ($service['deleted_at']): ?> style="opacity:0.5;"<?php endif; ?>>
                        <td><strong style="color:var(--gold);">#<?php echo $service['id']; ?></strong></td>
                        <td>
                            <?php if ($service['image']): ?>
                                <img src="../uploads/services/<?php echo htmlspecialchars($service['image']); ?>"
                                     class="thumb" alt="">
                            <?php else: ?>
                                <div class="thumb" style="background:var(--surface);display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:0.7rem;">No img</div>
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
                        <td style="color:var(--gray);">⏱ <?php echo $service['session_time']; ?> mins</td>
                        <td style="max-width:180px;color:var(--gray);font-size:0.82rem;">
                            <?php echo htmlspecialchars(substr($service['description'], 0, 60)) . '...'; ?>
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
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this service?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="id" value="<?php echo intval($service['id']); ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗃️ Archive</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" style="text-align:center;color:var(--gray);padding:2rem;">
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