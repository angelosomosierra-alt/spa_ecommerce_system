<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require_once '../notify.php';

$uid           = $_SESSION['user_id'];
$unread        = get_unread_count($conn, $uid);
$notifications = get_notifications($conn, $uid, 15);

ob_start();
if (empty($notifications)): ?>
<div style="padding:1.5rem;text-align:center;color:#aaa;font-size:0.82rem;">No notifications yet</div>
<?php else:
$n_icons = ['order'=>'🛍️','appointment'=>'📅','status'=>'🔔','general'=>'💬'];
foreach ($notifications as $n):
    $diff = time() - strtotime($n['created_at']);
    if ($diff < 60)        $n_time = 'Just now';
    elseif ($diff < 3600)  $n_time = floor($diff/60) . 'm ago';
    elseif ($diff < 86400) $n_time = floor($diff/3600) . 'h ago';
    else                   $n_time = date('M d', strtotime($n['created_at']));
?>
<a href="<?php echo htmlspecialchars($n['link']); ?>"
   style="display:flex;align-items:flex-start;gap:0.65rem;
          padding:0.65rem 0.9rem;border-bottom:1px solid #EAD8C0;
          text-decoration:none;color:inherit;
          background:<?php echo $n['is_read'] ? '#fff' : '#fff8f3'; ?>;
          transition:background 0.15s;">
    <span style="font-size:1.2rem;flex-shrink:0;margin-top:1px;">
        <?php echo $n_icons[$n['type']] ?? '🔔'; ?>
    </span>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:0.82rem;color:#3B2A1A;
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?php echo htmlspecialchars($n['title']); ?>
        </div>
        <div style="font-size:0.75rem;color:#666;line-height:1.4;
                    display:-webkit-box;-webkit-line-clamp:2;
                    -webkit-box-orient:vertical;overflow:hidden;">
            <?php echo htmlspecialchars($n['message']); ?>
        </div>
        <div style="font-size:0.7rem;color:#aaa;margin-top:2px;">
            <?php echo $n_time; ?>
        </div>
    </div>
    <?php if (!$n['is_read']): ?>
    <div style="width:7px;height:7px;border-radius:50%;
                background:#C96A2C;flex-shrink:0;margin-top:5px;"></div>
    <?php endif; ?>
</a>
<?php endforeach; ?>
<?php endif;
$list_html = ob_get_clean();

echo json_encode(['unread' => $unread, 'list_html' => $list_html]);
