<?php

session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

require_once 'config.php';

// Get cart count (only for logged-in users)
$cart_count = (isset($_SESSION['user_id']) && isset($_SESSION['cart'])) ? count($_SESSION['cart']) : 0;

// Fetch all services
$services = [];
$result = $conn->query("SELECT * FROM services ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
}

// Fetch all products
$products = [];
$result = $conn->query("SELECT * FROM products ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

// Handle add to cart (only for logged-in users)
if (isset($_POST['add_to_cart']) && isset($_SESSION['user_id'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']) ?? 1;

    // Get product details
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();

        // Initialize cart if not exists
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        // Check if product already in cart
        $found = false;
        foreach ($_SESSION['cart'] as &$item) {
            if ($item['product_id'] == $product_id) {
                $item['quantity'] += $quantity;
                $found = true;
                break;
            }
        }

        // Add new item if not found
        if (!$found) {
            $_SESSION['cart'][] = [
                'product_id' => $product_id,
                'name' => $product['name'],
                'image' => $product['image'],
                'price' => $product['price'],
                'quantity' => $quantity
            ];
        }

        $cart_count = count($_SESSION['cart']);
    }
    $stmt->close();
}

// Handle direct checkout (only for logged-in users)
if (isset($_POST['direct_checkout']) && isset($_SESSION['user_id'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']) ?? 1;

    // Store in session for checkout
    $_SESSION['direct_checkout'] = [
        'product_id' => $product_id,
        'quantity' => $quantity
    ];

    header("Location: " . BASE_URL . "user/checkout.php");
    exit();
}

// Handle book service (only for logged-in users)
if (isset($_POST['book_service']) && isset($_SESSION['user_id'])) {
    $service_id = intval($_POST['service_id']);

    // Store in session for checkout
    $_SESSION['service_booking'] = [
        'service_id' => $service_id
    ];

    header("Location: " . BASE_URL . "user/checkout.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spa Ecommerce - Home</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <!-- Navigation -->
    <header>
        <nav>
            <div class="logo">Spa Ecommerce</div>
            <ul class="nav-links">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#products">Products</a></li>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="user/cart.php">Cart (<?php echo $cart_count; ?>)</a></li>
                    <li><a href="user/profile.php">Profile</a></li>
                    <li><a href="user/appointments.php">Appointments</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <span style="color: #FAF3E8;">Welcome, <?php echo $_SESSION['username']; ?></span>
                    <a href="?logout=1">Logout</a>
                <?php else: ?>
                    <a href="user/auth.php">Login</a>
                    <a href="user/auth.php?register=1">Register</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <h1>Relax. Refresh. Rejuvenate.</h1>
        <p>Experience the ultimate spa and wellness journey</p>
        <div class="hero-buttons">
            <a href="#services" class="btn btn-primary">Book a Service</a>
            <a href="#products" class="btn btn-secondary">Shop Products</a>
        </div>
    </section>

    <div class="container">
        <!-- Services Section -->
        <section class="section" id="services">
            <h2 class="section-title">Our Services</h2>
            <div class="cards-grid">
                <?php foreach ($services as $service): ?>
                    <div class="card">
                        <img src="uploads/services/<?php echo $service['image']; ?>" alt="<?php echo $service['name']; ?>" class="card-image">
                        <div class="card-body">
                            <h3 class="card-title"><?php echo $service['name']; ?></h3>
                            <p class="card-description"><?php echo substr($service['description'], 0, 100) . '...'; ?></p>
                            <div class="card-price">$<?php echo number_format($service['price'], 2); ?></div>
                            <button class="btn btn-primary" onclick="openServiceModal(<?php echo $service['id']; ?>)">View Details</button>
                        </div>
                    </div>

                    <!-- Service Modal -->
                    <div class="modal" id="serviceModal<?php echo $service['id']; ?>">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2><?php echo $service['name']; ?></h2>
                                <button class="modal-close" onclick="closeServiceModal(<?php echo $service['id']; ?>)">×</button>
                            </div>
                            <div class="modal-body">
                                <img src="uploads/services/<?php echo $service['image']; ?>" alt="<?php echo $service['name']; ?>">
                                <h3 style="color: #3B2A1A; margin-bottom: 1rem;"><?php echo $service['name']; ?></h3>
                                <p style="color: #666; margin-bottom: 1rem;"><?php echo $service['description']; ?></p>
                                <p style="color: #C96A2C; font-weight: bold; font-size: 1.3rem; margin-bottom: 0.5rem;">$<?php echo number_format($service['price'], 2); ?></p>
                                <p style="color: #666; margin-bottom: 1rem;">Duration: <?php echo $service['session_time']; ?> minutes</p>
                            </div>
                            <div class="modal-footer">
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                        <button type="submit" name="book_service" class="btn btn-primary" style="width: 100%;">Book Now</button>
                                    </form>
                                <?php else: ?>
                                    <a href="user/auth.php" class="btn btn-primary" style="flex: 1; text-align: center;">Login to Book</a>
                                <?php endif; ?>
                                <button class="btn btn-secondary" onclick="closeServiceModal(<?php echo $service['id']; ?>)" style="flex: 1;">Close</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Products Section -->
        <section class="section" id="products">
            <h2 class="section-title">Our Products</h2>
            <div class="cards-grid">
                <?php foreach ($products as $product): ?>
                    <div class="card <?php echo $product['stock'] <= 0 ? 'out-of-stock' : ''; ?>">
                        <img src="uploads/products/<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" class="card-image">
                        <div class="card-body">
                            <h3 class="card-title"><?php echo $product['name']; ?></h3>
                            <p class="card-description"><?php echo substr($product['description'], 0, 100) . '...'; ?></p>
                            <div class="card-price">$<?php echo number_format($product['price'], 2); ?></div>
                            <?php if ($product['stock'] <= 0): ?>
                                <div style="text-align: center; color: #e74c3c; font-weight: bold;">Out of Stock</div>
                            <?php else: ?>
                                <button class="btn btn-primary" onclick="openProductModal(<?php echo $product['id']; ?>)">View Details</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Product Modal -->
                    <div class="modal" id="productModal<?php echo $product['id']; ?>">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2><?php echo $product['name']; ?></h2>
                                <button class="modal-close" onclick="closeProductModal(<?php echo $product['id']; ?>)">×</button>
                            </div>
                            <div class="modal-body">
                                <img src="uploads/products/<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                <h3 style="color: #3B2A1A; margin-bottom: 1rem;"><?php echo $product['name']; ?></h3>
                                <p style="color: #666; margin-bottom: 1rem;"><?php echo $product['description']; ?></p>
                                <p style="color: #C96A2C; font-weight: bold; font-size: 1.3rem; margin-bottom: 0.5rem;">$<?php echo number_format($product['price'], 2); ?></p>
                                <p style="color: #666; margin-bottom: 1rem;">Stock: <?php echo $product['stock']; ?> available</p>
                                <form id="productForm<?php echo $product['id']; ?>" method="POST">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <div class="form-group">
                                        <label for="quantity<?php echo $product['id']; ?>">Quantity:</label>
                                        <input type="number" id="quantity<?php echo $product['id']; ?>" name="quantity" value="1" min="1" max="<?php echo $product['stock']; ?>">
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <button class="btn btn-primary" onclick="addToCart(<?php echo $product['id']; ?>)">Add to Cart</button>
                                    <button class="btn btn-secondary" onclick="checkoutProduct(<?php echo $product['id']; ?>)">Checkout Now</button>
                                <?php else: ?>
                                    <a href="user/auth.php" class="btn btn-primary" style="flex: 1; text-align: center;">Login to Shop</a>
                                <?php endif; ?>
                                <button class="btn btn-secondary" onclick="closeProductModal(<?php echo $product['id']; ?>)">Close</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <script>
        // Service Modal Functions
        function openServiceModal(id) {
            document.getElementById('serviceModal' + id).classList.add('active');
        }

        function closeServiceModal(id) {
            document.getElementById('serviceModal' + id).classList.remove('active');
        }

        // Product Modal Functions
        function openProductModal(id) {
            document.getElementById('productModal' + id).classList.add('active');
        }

        function closeProductModal(id) {
            document.getElementById('productModal' + id).classList.remove('active');
        }

        // Add to Cart
        function addToCart(productId) {
            const quantityInput = document.getElementById('quantity' + productId);
            const quantity = quantityInput ? quantityInput.value : 1;

            const form = document.getElementById('productForm' + productId);
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'add_to_cart';
            input.value = '1';
            form.appendChild(input);
            form.submit();
        }

        // Direct Checkout
        function checkoutProduct(productId) {
            const quantityInput = document.getElementById('quantity' + productId);
            const quantity = quantityInput ? quantityInput.value : 1;

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="product_id" value="${productId}">
                <input type="hidden" name="quantity" value="${quantity}">
                <input type="hidden" name="direct_checkout" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
