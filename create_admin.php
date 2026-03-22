<?php
require_once 'config.php';

echo "<h2>Session Info</h2>";
echo "Session status: " . session_status() . "<br>";
echo "<pre>"; print_r($_SESSION); echo "</pre>";

echo "<h2>All Users in Database</h2>";
$result = $conn->query("SELECT id, username, password, email, role FROM users");
if ($result->num_rows === 0) {
    echo "❌ NO USERS IN DATABASE AT ALL!";
} else {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']} | 
              Username: '{$row['username']}' | 
              Password: '{$row['password']}' | 
              Role: '{$row['role']}'<br>";
    }
}

echo "<h2>Test Login Manually</h2>";
$username = 'admin';
$password = 'admin123';

$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "❌ User '$username' NOT FOUND in database!";
} else {
    echo "✅ User found!<br>";
    echo "Stored password: '{$user['password']}'<br>";
    echo "Input password:  '$password'<br>";
    echo "Match: " . ($user['password'] === $password ? "✅ YES" : "❌ NO") . "<br>";
    echo "Role: '{$user['role']}'<br>";
}

echo "<h2>Config Check</h2>";
echo "DB_NAME: " . DB_NAME . "<br>";
echo "DB_SERVER: " . DB_SERVER . "<br>";
echo "Connection: " . ($conn->connect_error ? "❌ " . $conn->connect_error : "✅ Connected") . "<br>";

echo "<h2>is_admin() function check</h2>";
$_SESSION['role'] = 'admin';
echo "is_admin() returns: " . (is_admin() ? "✅ true" : "❌ false") . "<br>";
echo "is_logged_in() returns: " . (is_logged_in() ? "true" : "false") . "<br>";
?>