<?php
require_once '../config.php';

header('Content-Type: application/json');

// Any logged-in admin/receptionist/staff account can access the shared inbox —
// not restricted to owners, same as the request ("all receptionist, admins
// can see it and send a reply").
if (!is_logged_in() || !is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require_once '../chat.php';

$action      = $_GET['action'] ?? $_POST['action'] ?? 'badge';
$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$admin_id    = (int)$_SESSION['user_id'];
$admin_name  = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Staff');

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    if ($customer_id <= 0 || $message === '') {
        echo json_encode(['ok' => false, 'error' => 'Message is empty.']);
        exit;
    }
    if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);
    chat_send($conn, $customer_id, $admin_id, 'admin', $admin_name, $message);
    echo json_encode([
        'ok'            => true,
        'messages_html' => chat_render_messages_html(chat_get_messages($conn, $customer_id), 'admin'),
    ]);
    exit;
}

if ($action === 'thread' && $customer_id > 0) {
    chat_mark_read_admin($conn, $customer_id);
    $cstmt = $conn->prepare("SELECT COALESCE(NULLIF(full_name,''), username) AS n FROM users WHERE id=?");
    $cstmt->bind_param("i", $customer_id);
    $cstmt->execute();
    $crow = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();
    echo json_encode([
        'messages_html' => chat_render_messages_html(chat_get_messages($conn, $customer_id), 'admin'),
        'customer_name' => $crow['n'] ?? 'Customer',
    ]);
    exit;
}

if ($action === 'threads') {
    echo json_encode([
        'threads_html' => chat_render_threads_html(chat_get_threads($conn)),
        'unread'       => chat_unread_count_admin_total($conn),
    ]);
    exit;
}

// default: lightweight badge poll — just the total unread count
echo json_encode(['unread' => chat_unread_count_admin_total($conn)]);
