<?php
/**
 * notify.php — Shared notification helper
 * Place in: spa_ecommerce_system/notify.php  (project root)
 *
 * Usage:
 *   require_once '../notify.php';      // from user/ or admin/
 *   require_once 'notify.php';         // from root
 *
 *   // Notify a specific user
 *   add_notification($conn, $user_id, 'order', 'Order Placed!', 'Your order #5 has been placed.', 'appointments.php');
 *
 *   // Notify admin (user_id = NULL)
 *   add_notification($conn, null, 'order', 'New Order', 'User Angelo placed an order.', 'orders.php');
 */

function add_notification($conn, $user_id, $type, $title, $message, $link = '#') {
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $user_id, $type, $title, $message, $link);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get unread count.
 * Pass null for $user_id to get admin unread count.
 */
function get_unread_count($conn, $user_id) {
    if ($user_id === null) {
        $r = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id IS NULL AND is_read = 0");
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
    }
    return intval($r->fetch_assoc()['c']);
}

/**
 * Get recent notifications (last 15).
 * Pass null for $user_id to get admin notifications.
 */
function get_notifications($conn, $user_id, $limit = 15) {
    if ($user_id === null) {
        $r = $conn->query("SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT $limit");
        return $r->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

/**
 * Mark all as read for a user (or admin).
 */
function mark_all_read($conn, $user_id) {
    if ($user_id === null) {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL");
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
}