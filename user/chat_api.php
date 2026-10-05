<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in() || is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require_once '../chat.php';

$customer_id = (int)$_SESSION['user_id'];
$action      = $_GET['action'] ?? $_POST['action'] ?? 'badge';

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $message    = trim($_POST['message'] ?? '');
    $attachment = chat_handle_upload($_FILES['attachment'] ?? null);
    if (isset($attachment['error'])) {
        echo json_encode(['ok' => false, 'error' => $attachment['error']]);
        exit;
    }
    if ($message === '' && !$attachment) {
        echo json_encode(['ok' => false, 'error' => 'Message is empty.']);
        exit;
    }
    if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);
    $sender_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Customer');
    chat_send($conn, $customer_id, $customer_id, 'customer', $sender_name, $message, $attachment);
    echo json_encode([
        'ok'            => true,
        'messages_html' => chat_render_messages_html(chat_get_messages($conn, $customer_id), 'customer'),
    ]);
    exit;
}

if ($action === 'thread') {
    chat_mark_read_customer($conn, $customer_id);
    echo json_encode([
        'messages_html' => chat_render_messages_html(chat_get_messages($conn, $customer_id), 'customer'),
        'unread'        => 0,
    ]);
    exit;
}

// default: lightweight badge poll — just the unread count
echo json_encode(['unread' => chat_unread_count_customer($conn, $customer_id)]);
