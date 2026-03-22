<?php
require_once 'config.php';

// Clear existing data
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("TRUNCATE TABLE appointments");
$conn->query("TRUNCATE TABLE order_items");
$conn->query("TRUNCATE TABLE orders");
$conn->query("TRUNCATE TABLE products");
$conn->query("TRUNCATE TABLE services");
$conn->query("TRUNCATE TABLE users");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// Default Admin
$admin_username = 'admin';
$admin_password = password_hash('admin123', PASSWORD_DEFAULT);
$admin_email = 'admin@spa.com';
$admin_name = 'System Administrator';
$admin_role = 'admin';

$stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES (?, ?, ?, ?, '0000000000', 'Admin Office', ?)");
$stmt->bind_param("sssss", $admin_username, $admin_password, $admin_email, $admin_name, $admin_role);
$stmt->execute();
$stmt->close();

// Default User
$user_username = 'user';
$user_password = password_hash('user123', PASSWORD_DEFAULT);
$user_email = 'user@example.com';
$user_name = 'John Doe';
$user_role = 'user';

$stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES (?, ?, ?, ?, '1234567890', '123 Spa Lane, Wellness City', ?)");
$stmt->bind_param("sssss", $user_username, $user_password, $user_email, $user_name, $user_role);
$stmt->execute();
$stmt->close();

// Sample Services
$services = [
    ['Swedish Massage', 'Relaxing full body massage using long strokes and kneading.', 60.00, 60, 'service_1.jpg'],
    ['Hot Stone Massage', 'Therapeutic massage using heated stones to melt away tension.', 85.00, 90, 'service_2.jpg'],
    ['Aromatherapy', 'Massage with essential oils for physical and emotional well-being.', 75.00, 60, 'service_3.jpg']
];

foreach ($services as $s) {
    $stmt = $conn->prepare("INSERT INTO services (name, description, price, session_time, image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdis", $s[0], $s[1], $s[2], $s[3], $s[4]);
    $stmt->execute();
    $stmt->close();
}

// Sample Products
$products = [
    ['Lavender Massage Oil', 'Calming lavender scented oil for a relaxing massage at home.', 25.00, 50, 'product_1.jpg'],
    ['Organic Body Scrub', 'Exfoliating body scrub made with natural sea salt and honey.', 18.50, 30, 'product_2.jpg'],
    ['Scented Candle - Zen', 'Create a spa atmosphere with our signature Zen scented candle.', 15.00, 100, 'product_3.jpg']
];

foreach ($products as $p) {
    $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock, image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdis", $p[0], $p[1], $p[2], $p[3], $p[4]);
    $stmt->execute();
    $stmt->close();
}

echo "Database seeded successfully with RBAC roles!\n";
echo "Admin: admin / admin123\n";
echo "User: user / user123\n";
?>
