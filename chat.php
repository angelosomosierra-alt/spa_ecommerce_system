<?php
/**
 * chat.php — Shared customer <-> admin messaging helper.
 * One thread per customer (customer_messages.customer_id). Any logged-in
 * admin/receptionist can see and reply to any customer's thread — it's a
 * shared inbox, not a per-admin mailbox.
 *
 * Usage:
 *   require_once '../chat.php';   // from user/ or admin/
 *   require_once 'chat.php';      // from root
 */

if (!isset($conn)) { require_once __DIR__ . '/config.php'; }

$conn->query("CREATE TABLE IF NOT EXISTS customer_messages (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    customer_id          INT NOT NULL,
    sender_id            INT NOT NULL,
    sender_role          ENUM('customer','admin') NOT NULL,
    sender_name          VARCHAR(100) NOT NULL,
    message              TEXT NOT NULL,
    is_read_by_customer  TINYINT(1) NOT NULL DEFAULT 0,
    is_read_by_admin     TINYINT(1) NOT NULL DEFAULT 0,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_time (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("ALTER TABLE customer_messages
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS attachment_type VARCHAR(20) NULL");

define('CHAT_UPLOAD_DIR', __DIR__ . '/uploads/chat/');
define('CHAT_UPLOAD_URL_BASE', 'uploads/chat/'); // relative to site root; callers prefix as needed
define('CHAT_MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8MB
const CHAT_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const CHAT_FILE_EXTS  = ['pdf', 'doc', 'docx', 'xlsx', 'xls', 'txt'];

/**
 * Validate and move an uploaded $_FILES[...] entry into uploads/chat/.
 * Returns ['path'=>relative-path-from-site-root, 'name'=>original filename, 'type'=>'image'|'file']
 * or ['error'=>message] on failure. Returns null if no file was actually submitted.
 */
function chat_handle_upload(?array $file): ?array {
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed.'];
    }
    if ($file['size'] > CHAT_MAX_UPLOAD_BYTES) {
        return ['error' => 'File is too large (max 8MB).'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $isImage = in_array($ext, CHAT_IMAGE_EXTS, true);
    $isFile  = in_array($ext, CHAT_FILE_EXTS, true);
    if (!$isImage && !$isFile) {
        return ['error' => 'That file type is not allowed.'];
    }
    if (!is_dir(CHAT_UPLOAD_DIR)) {
        mkdir(CHAT_UPLOAD_DIR, 0755, true);
    }
    $storedName = uniqid('chat_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], CHAT_UPLOAD_DIR . $storedName)) {
        return ['error' => 'Could not save the uploaded file.'];
    }
    return [
        'path' => CHAT_UPLOAD_URL_BASE . $storedName,
        'name' => basename($file['name']),
        'type' => $isImage ? 'image' : 'file',
    ];
}

function chat_send($conn, $customer_id, $sender_id, $sender_role, $sender_name, $message, ?array $attachment = null) {
    // A sender has trivially already "read" their own message.
    $is_read_customer = $sender_role === 'customer' ? 1 : 0;
    $is_read_admin    = $sender_role === 'admin'    ? 1 : 0;
    $att_path = $attachment['path'] ?? null;
    $att_name = $attachment['name'] ?? null;
    $att_type = $attachment['type'] ?? null;
    $stmt = $conn->prepare("
        INSERT INTO customer_messages
            (customer_id, sender_id, sender_role, sender_name, message, is_read_by_customer, is_read_by_admin,
             attachment_path, attachment_name, attachment_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisssiisss", $customer_id, $sender_id, $sender_role, $sender_name, $message, $is_read_customer, $is_read_admin, $att_path, $att_name, $att_type);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function chat_get_messages($conn, $customer_id, $limit = 300) {
    $stmt = $conn->prepare("
        SELECT id, sender_id, sender_role, sender_name, message, created_at,
               attachment_path, attachment_name, attachment_type
        FROM customer_messages
        WHERE customer_id = ?
        ORDER BY created_at ASC, id ASC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $customer_id, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function chat_unread_count_customer($conn, $customer_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM customer_messages WHERE customer_id=? AND sender_role='admin' AND is_read_by_customer=0");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
}

function chat_mark_read_customer($conn, $customer_id) {
    $stmt = $conn->prepare("UPDATE customer_messages SET is_read_by_customer=1 WHERE customer_id=? AND sender_role='admin' AND is_read_by_customer=0");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $stmt->close();
}

function chat_mark_read_admin($conn, $customer_id) {
    $stmt = $conn->prepare("UPDATE customer_messages SET is_read_by_admin=1 WHERE customer_id=? AND sender_role='customer' AND is_read_by_admin=0");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $stmt->close();
}

// Total unread (customer-authored, unseen by any admin) across every thread —
// drives the shared admin chat-bell badge.
function chat_unread_count_admin_total($conn) {
    $r = $conn->query("SELECT COUNT(*) c FROM customer_messages WHERE sender_role='customer' AND is_read_by_admin=0");
    return (int)($r->fetch_assoc()['c'] ?? 0);
}

// One row per customer who has an existing thread, newest activity first,
// with a preview of the last message and how many customer-sent messages
// are still unread by admin staff.
function chat_get_threads($conn, $limit = 50) {
    $sql = "
        SELECT u.id AS customer_id,
               COALESCE(NULLIF(u.full_name,''), u.username) AS customer_name,
               lm.message         AS last_message,
               lm.attachment_type AS last_attachment_type,
               lm.sender_role     AS last_sender_role,
               lm.created_at      AS last_time,
               (SELECT COUNT(*) FROM customer_messages cm2
                WHERE cm2.customer_id = u.id AND cm2.sender_role='customer' AND cm2.is_read_by_admin=0) AS unread
        FROM (
            SELECT customer_id, MAX(id) AS max_id
            FROM customer_messages
            GROUP BY customer_id
        ) t
        JOIN customer_messages lm ON lm.id = t.max_id
        JOIN users u ON u.id = t.customer_id
        ORDER BY lm.created_at DESC
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ── Shared renderers (used by both user/chat_api.php and admin/chat_api.php) ──

function chat_render_messages_html(array $messages, string $viewer_role): string {
    if (empty($messages)) {
        return '<div style="padding:2.5rem 1rem;text-align:center;color:var(--brown-lt,#9a7c68);font-size:0.85rem;">No messages yet. Say hello!</div>';
    }
    ob_start();
    foreach ($messages as $m):
        $mine = $m['sender_role'] === $viewer_role;
        $time = date('M d, h:i A', strtotime($m['created_at']));
        $hasAttachment = !empty($m['attachment_path']);
        $hasText       = trim($m['message']) !== '';
        // Stored root-relative (e.g. "uploads/chat/xxx.jpg") so it resolves
        // correctly whether rendered from a page under /user/ or /admin/.
        $attUrl = $hasAttachment ? BASE_URL . $m['attachment_path'] : '';
    ?>
    <div style="display:flex;flex-direction:column;align-items:<?php echo $mine ? 'flex-end' : 'flex-start'; ?>;margin-bottom:0.8rem;">
        <?php if (!$mine): ?>
        <div style="font-size:0.68rem;font-weight:700;color:var(--brown-lt,#9a7c68);margin-bottom:3px;padding:0 0.2rem;">
            <?php echo htmlspecialchars($m['sender_name']); ?>
        </div>
        <?php endif; ?>

        <?php if ($hasAttachment && $m['attachment_type'] === 'image'): ?>
        <a href="<?php echo htmlspecialchars($attUrl); ?>" target="_blank" rel="noopener noreferrer"
           style="display:block;max-width:220px;border-radius:14px;overflow:hidden;margin-bottom:<?php echo $hasText ? '4px' : '0'; ?>;
                  box-shadow:0 1px 6px rgba(0,0,0,.15);border-bottom-<?php echo $mine ? 'right' : 'left'; ?>-radius:4px;">
            <img src="<?php echo htmlspecialchars($attUrl); ?>" alt="attachment" style="display:block;width:100%;height:auto;">
        </a>
        <?php elseif ($hasAttachment): ?>
        <a href="<?php echo htmlspecialchars($attUrl); ?>" target="_blank" rel="noopener noreferrer"
           style="display:flex;align-items:center;gap:0.5rem;max-width:220px;padding:0.55rem 0.7rem;border-radius:14px;
                  margin-bottom:<?php echo $hasText ? '4px' : '0'; ?>;text-decoration:none;
                  background:<?php echo $mine ? 'var(--rust,#C96A2C)' : 'var(--bg3,#f1e7da)'; ?>;
                  color:<?php echo $mine ? '#fff' : 'var(--brown,#3B2A1A)'; ?>;
                  border-bottom-<?php echo $mine ? 'right' : 'left'; ?>-radius:4px;">
            <span style="font-size:1.1rem;flex-shrink:0;">📎</span>
            <span style="font-size:0.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php echo htmlspecialchars($m['attachment_name']); ?>
            </span>
        </a>
        <?php endif; ?>

        <?php if ($hasText): ?>
        <div style="max-width:78%;padding:0.55rem 0.8rem;border-radius:14px;
                    font-size:0.85rem;line-height:1.45;word-break:break-word;white-space:pre-wrap;
                    box-shadow:0 1px 3px rgba(0,0,0,.06);
                    background:<?php echo $mine ? 'var(--rust,#C96A2C)' : 'var(--bg3,#f1e7da)'; ?>;
                    color:<?php echo $mine ? '#fff' : 'var(--brown,#3B2A1A)'; ?>;
                    border-bottom-<?php echo $mine ? 'right' : 'left'; ?>-radius:4px;">
            <?php echo htmlspecialchars($m['message']); ?>
        </div>
        <?php endif; ?>

        <div style="font-size:0.65rem;color:var(--brown-lt,#b3a28e);margin-top:3px;padding:0 0.2rem;">
            <?php echo $time; ?>
        </div>
    </div>
    <?php endforeach;
    return ob_get_clean();
}

function chat_render_threads_html(array $threads): string {
    if (empty($threads)) {
        return '<div style="padding:2.5rem 1rem;text-align:center;color:#9a7c68;font-size:0.85rem;">No customer messages yet.</div>';
    }
    ob_start();
    foreach ($threads as $t):
        $diff = time() - strtotime($t['last_time']);
        if ($diff < 60)        $tt = 'Just now';
        elseif ($diff < 3600)  $tt = floor($diff / 60) . 'm ago';
        elseif ($diff < 86400) $tt = floor($diff / 3600) . 'h ago';
        else                   $tt = date('M d', strtotime($t['last_time']));
        if (trim($t['last_message']) === '' && !empty($t['last_attachment_type'])) {
            $preview = $t['last_attachment_type'] === 'image' ? '📷 Photo' : '📎 File';
        } else {
            $preview = mb_strlen($t['last_message']) > 42 ? mb_substr($t['last_message'], 0, 42) . '…' : $t['last_message'];
        }
        $prefix  = $t['last_sender_role'] === 'admin' ? 'You: ' : '';
    ?>
    <div onclick='openChatThread(<?php echo (int)$t['customer_id']; ?>, <?php echo json_encode($t['customer_name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
         style="display:flex;align-items:center;gap:0.65rem;padding:0.7rem 0.9rem;cursor:pointer;
                border-bottom:1px solid #EAD8C0;background:<?php echo $t['unread'] > 0 ? '#fff8f3' : '#fff'; ?>;
                transition:background 0.15s;">
        <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;
                    background:linear-gradient(135deg,#C8A46B,#C96A2C);color:#fff;font-weight:700;
                    display:flex;align-items:center;justify-content:center;">
            <?php echo strtoupper(substr($t['customer_name'], 0, 1)); ?>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:0.85rem;color:#3B2A1A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php echo htmlspecialchars($t['customer_name']); ?>
            </div>
            <div style="font-size:0.75rem;color:#777;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php echo htmlspecialchars($prefix . $preview); ?>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:0.65rem;color:#aaa;"><?php echo $tt; ?></div>
            <?php if ($t['unread'] > 0): ?>
            <div style="margin-top:3px;background:#dc3545;color:#fff;font-size:0.65rem;font-weight:700;
                        min-width:16px;height:16px;border-radius:8px;display:inline-flex;align-items:center;
                        justify-content:center;padding:0 3px;"><?php echo $t['unread']; ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach;
    return ob_get_clean();
}
