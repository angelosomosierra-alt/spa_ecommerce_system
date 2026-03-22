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

        // ── Handle image upload ────────────────────────────────────────────
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
                    // Delete old image if editing
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

        // ── Save to database ───────────────────────────────────────────────
        if ($message_type !== "danger") {
            if ($id) {
                // UPDATE
                if ($image_name) {
                    $stmt = $conn->prepare("
                        UPDATE services
                        SET name=?, description=?, price=?, session_time=?, slots=?, image=?, category_id=?
                        WHERE id=?
                    ");
                    $stmt->bind_param("ssdiiisi", $name, $description, $price, $session_time, $slots, $image_name, $category_id, $id);
                } else {
                    if ($category_id !== null) {
                        $stmt = $conn->prepare("
                            UPDATE services
                            SET name=?, description=?, price=?, session_time=?, slots=?, category_id=?
                            WHERE id=?
                        ");
                        $stmt->bind_param("ssdiiii", $name, $description, $price, $session_time, $slots, $category_id, $id);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE services
                            SET name=?, description=?, price=?, session_time=?, slots=?, category_id=NULL
                            WHERE id=?
                        ");
                        $stmt->bind_param("ssdiii", $name, $description, $price, $session_time, $slots, $id);
                    }
                }
            } else {
                // INSERT
                $stmt = $conn->prepare("
                    INSERT INTO services (name, description, price, session_time, slots, image, category_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Management - Admin</title>
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
            background: #cfe2ff;
            color: #084298;
        }
        .no-category {
            display: inline-block;
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
            font-size: 0.78rem;
            background: #e2e3e5;
            color: #888;
        }
        .form-row { display:flex; gap:1rem; flex-wrap:wrap; }
        .form-row .form-group { flex:1; min-width:180px; }
    </style>
</head>
<body>

<header>
    <nav>
        <div class="logo">Spa Admin</div>
        <ul class="nav-links">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="services.php" class="active">Services</a></li>
            <li><a href="products.php">Products</a></li>
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
            <li><a href="services.php" class="active">Services</a></li>
            <li><a href="products.php">Products</a></li>
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
            <h2><?php echo $edit_service ? "Edit Service" : "Services Management"; ?></h2>
            <?php if (!$edit_service && !isset($_GET['action'])): ?>
                <a href="services.php?action=add" class="btn btn-primary">+ Add New Service</a>
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
        <?php if ((isset($_GET['action']) && $_GET['action'] === 'add') || $edit_service): ?>
        <div style="background:#FAF3E8; padding:2rem; border-radius:12px; margin-bottom:2rem;">
            <h3 style="color:#3B2A1A; margin-bottom:1.5rem;">
                <?php echo $edit_service ? "✏️ Edit Service" : "➕ Add New Service"; ?>
            </h3>

            <form method="POST" enctype="multipart/form-data">
                <?php if ($edit_service): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_service['id']; ?>">
                <?php endif; ?>

                <!-- Row 1: Name + Category -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Service Name <span style="color:red;">*</span></label>
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
                        <small>
                            <a href="categories.php" target="_blank" style="color:#C96A2C;">
                                + Manage Categories
                            </a>
                        </small>
                    </div>
                </div>

                <!-- Row 2: Price + Session Time + Slots -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Price ($) <span style="color:red;">*</span></label>
                        <input type="number" name="price" step="0.01" min="0.01" required
                               value="<?php echo $edit_service['price'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Session Time (minutes) <span style="color:red;">*</span></label>
                        <input type="number" name="session_time" min="1" required
                               value="<?php echo $edit_service['session_time'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Available Slots/Day <span style="color:red;">*</span></label>
                        <input type="number" name="slots" min="1" required
                               value="<?php echo $edit_service['slots'] ?? 5; ?>">
                    </div>
                </div>

                <!-- Row 3: Image -->
                <div class="form-group">
                    <label>Service Image <?php echo !$edit_service ? '<span style="color:red;">*</span>' : ''; ?></label>
                    <input type="file" name="image" accept="image/*"
                           <?php echo !$edit_service ? 'required' : ''; ?>>
                    <?php if ($edit_service && $edit_service['image']): ?>
                        <div style="margin-top:0.5rem; display:flex; align-items:center; gap:1rem;">
                            <img src="../uploads/services/<?php echo htmlspecialchars($edit_service['image']); ?>"
                                 style="width:60px; height:60px; object-fit:cover; border-radius:8px; border:2px solid #EAD8C0;">
                            <small style="color:#666;">Current image — upload new to replace</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Row 4: Description -->
                <div class="form-group">
                    <label>Description <span style="color:red;">*</span></label>
                    <textarea name="description" rows="4" required><?php echo htmlspecialchars($edit_service['description'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; gap:1rem;">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $edit_service ? "✅ Update Service" : "✅ Add Service"; ?>
                    </button>
                    <a href="services.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- ── SERVICES TABLE ───────────────────────────────────────────────── -->
        <div style="overflow-x:auto; background:#fff; border-radius:12px; padding:1.5rem;">

            <!-- Category filter tabs -->
            <?php $filter_cat = $_GET['category'] ?? 'all'; ?>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem;">
                <a href="services.php"
                   style="padding:0.4rem 1rem; border-radius:20px; font-size:0.88rem; font-weight:bold;
                          text-decoration:none; border:2px solid #EAD8C0;
                          background:<?php echo $filter_cat === 'all' ? '#C96A2C' : '#FAF3E8'; ?>;
                          color:<?php echo $filter_cat === 'all' ? '#fff' : '#3B2A1A'; ?>;">
                    All
                </a>
                <a href="services.php?category=none"
                   style="padding:0.4rem 1rem; border-radius:20px; font-size:0.88rem; font-weight:bold;
                          text-decoration:none; border:2px solid #EAD8C0;
                          background:<?php echo $filter_cat === 'none' ? '#C96A2C' : '#FAF3E8'; ?>;
                          color:<?php echo $filter_cat === 'none' ? '#fff' : '#3B2A1A'; ?>;">
                    Uncategorized
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="services.php?category=<?php echo $cat['id']; ?>"
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
                            <td><strong>#<?php echo $service['id']; ?></strong></td>
                            <td>
                                <?php if ($service['image']): ?>
                                    <img src="../uploads/services/<?php echo htmlspecialchars($service['image']); ?>"
                                         style="width:55px; height:55px; object-fit:cover; border-radius:8px;">
                                <?php else: ?>
                                    <div style="width:55px; height:55px; background:#EAD8C0; border-radius:8px;
                                                display:flex; align-items:center; justify-content:center; color:#888; font-size:0.7rem;">
                                        No img
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($service['name']); ?></strong></td>
                            <td>
                                <?php if ($service['category_name']): ?>
                                    <span class="category-badge">
                                        <?php echo htmlspecialchars($service['category_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-category">Uncategorized</span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color:#C96A2C;">$<?php echo number_format($service['price'], 2); ?></strong></td>
                            <td>⏱ <?php echo $service['session_time']; ?> mins</td>
                            <td>📅 <?php echo $service['slots']; ?> slots</td>
                            <td style="max-width:180px; color:#666;">
                                <?php echo htmlspecialchars(substr($service['description'], 0, 60)) . '...'; ?>
                            </td>
                            <td style="font-size:0.85rem; color:#888;">
                                <?php echo date('M d, Y', strtotime($service['created_at'])); ?>
                            </td>
                            <td>
                                <a href="services.php?edit=<?php echo $service['id']; ?>"
                                   class="btn btn-info"
                                   style="padding:0.4rem 0.8rem; font-size:0.82rem;">Edit</a>
                                <a href="services.php?delete=<?php echo $service['id']; ?>"
                                   class="btn btn-danger"
                                   style="padding:0.4rem 0.8rem; font-size:0.82rem;"
                                   onclick="return confirm('Delete this service?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:2rem; color:#888;">
                                No services found in this category.
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