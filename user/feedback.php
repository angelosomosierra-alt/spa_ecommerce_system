<?php
/**
 * feedback.php — Customer Feedback Form
 * Place in: spa_ecommerce_system/user/feedback.php
 *
 * Handles two types:
 *   ?type=appointment&id=X  — after completed appointment
 *   ?type=order&id=X        — after approved product order
 */
require_once '../config.php';
redirect_if_not_user();

$user_id  = $_SESSION['user_id'];
$type     = $_GET['type'] ?? '';   // 'appointment' or 'order'
$ref_id   = intval($_GET['id'] ?? 0);
$message  = '';
$msg_type = '';
$subject  = null;   // The appointment or order data
$existing = null;   // Existing feedback if already rated

if (!$ref_id || !in_array($type, ['appointment','order'])) {
    header("Location: appointments.php"); exit();
}

// ── Fetch subject based on type ───────────────────────────────────────────────
if ($type === 'appointment') {
    $stmt = $conn->prepare("
        SELECT a.*, s.name as subject_name, s.image as subject_image
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.id = ? AND a.user_id = ? AND a.status = 'completed'
    ");
    $stmt->bind_param("ii", $ref_id, $user_id);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$subject) { header("Location: appointments.php"); exit(); }

    // Check existing service feedback
    $stmt = $conn->prepare("SELECT * FROM feedback WHERE appointment_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $ref_id, $user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fetch assigned therapists for this appointment
    $stmt = $conn->prepare("
        SELECT t.id AS therapist_id, t.full_name, t.specialties
        FROM appointment_therapists at2
        JOIN therapists t ON at2.therapist_id = t.id
        WHERE at2.appointment_id = ?
        ORDER BY at2.assigned_at ASC
    ");
    $stmt->bind_param("i", $ref_id); $stmt->execute();
    $assigned_therapists = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    // Check which therapists already have a rating from this user for this appointment
    $existing_therapist_ratings = [];
    if (!empty($assigned_therapists)) {
        foreach ($assigned_therapists as $th) {
            $stmt = $conn->prepare("
                SELECT rating, comment FROM therapist_ratings
                WHERE therapist_id=? AND appointment_id=? AND user_id=?
            ");
            $stmt->bind_param("iii", $th['therapist_id'], $ref_id, $user_id);
            $stmt->execute();
            $tr = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($tr) $existing_therapist_ratings[$th['therapist_id']] = $tr;
        }
    }

} else {
    // 1. Fetch the Order details
    $stmt = $conn->prepare("SELECT id, created_at, total_amount, approval_status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $ref_id, $user_id);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Safety check: If order doesn't exist, go back
    if (!$subject) { 
        header("Location: appointments.php"); 
        exit(); 
    }

    // 2. Fetch the Product Name and Image from order_items
    $stmt = $conn->prepare("
        SELECT p.name AS subject_name, p.image AS subject_image 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $ref_id);
    $stmt->execute();
    $item_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 3. Manually assign names to avoid "Undefined key" errors
    $subject['subject_name']  = $item_info['subject_name'] ?? "Order #" . $subject['id'];
    $subject['subject_image'] = $item_info['subject_image'] ?? null;

    // 4. Check if feedback already exists for this order
    $stmt = $conn->prepare("SELECT * FROM feedback WHERE order_id = ? AND user_id = ? AND appointment_id IS NULL");
    $stmt->bind_param("ii", $ref_id, $user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── Handle submission ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing) {
    verify_csrf_token();
    $rating  = intval($_POST['rating'] ?? 0);
    $comment = sanitize_input($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $message  = "Please select a star rating for the service.";
        $msg_type = "danger";
    } else {
        if ($type === 'appointment') {
            // Get linked order_id
            $stmt = $conn->prepare("SELECT oi.order_id FROM order_items oi WHERE oi.id = ?");
            $stmt->bind_param("i", $subject['order_item_id']);
            $stmt->execute();
            $ord = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $order_id = $ord['order_id'] ?? null;

            // Save service feedback
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, order_id, appointment_id, rating, comment) VALUES (?,?,?,?,?)");
            $stmt->bind_param("iiiis", $user_id, $order_id, $ref_id, $rating, $comment);
            $ok = $stmt->execute(); $stmt->close();

            if ($ok) {
                // Save therapist ratings
                $therapist_ratings_input = $_POST['therapist_ratings'] ?? [];
                foreach ($therapist_ratings_input as $tid => $trating) {
                    $tid     = intval($tid);
                    $trating = intval($trating);
                    if ($tid <= 0 || $trating < 1 || $trating > 5) continue;

                    $tcomment = sanitize_input($_POST['therapist_comments'][$tid] ?? '');
                    $ins = $conn->prepare("
                        INSERT IGNORE INTO therapist_ratings
                            (therapist_id, appointment_id, user_id, rating, comment)
                        VALUES (?,?,?,?,?)
                    ");
                    $ins->bind_param("iiiis", $tid, $ref_id, $user_id, $trating, $tcomment);
                    $ins->execute(); $ins->close();
                }

                $existing = ['rating' => $rating, 'comment' => $comment];
                $message  = "Thank you for your feedback!";
                $msg_type = "success";
            } else {
                $message  = "Error saving feedback. Please try again.";
                $msg_type = "danger";
            }

        } else {
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, order_id, appointment_id, rating, comment) VALUES (?,?,NULL,?,?)");
            $stmt->bind_param("iiis", $user_id, $ref_id, $rating, $comment);

            if ($stmt->execute()) {
                $existing = ['rating' => $rating, 'comment' => $comment];
                $message  = "Thank you for your feedback!";
                $msg_type = "success";
            } else {
                $message  = "Error saving feedback. Please try again.";
                $msg_type = "danger";
            }
            $stmt->close();
        }
    }
}

$page_title = 'Leave Feedback';
require_once 'header.php';
?>

<div class="container" style="max-width:540px;margin:2.5rem auto;padding:0 1rem;">

    <a href="appointments.php"
       style="display:inline-flex;align-items:center;gap:0.4rem;color:#C96A2C;
              font-size:0.88rem;text-decoration:none;margin-bottom:1.25rem;">
        ← Back to My Appointments
    </a>

    <div style="background:#fff;border-radius:16px;padding:2rem;
                box-shadow:0 4px 24px rgba(0,0,0,0.09);">

        <!-- Subject header -->
        <div style="display:flex;align-items:center;gap:1rem;
                    padding-bottom:1.25rem;margin-bottom:1.25rem;
                    border-bottom:1px solid #EAD8C0;">
            <?php
            $img = $subject['subject_image'] ?? null;
            $img_path = $type === 'appointment'
                ? "../uploads/services/{$img}"
                : "../uploads/products/{$img}";
            if ($img):
            ?>
            <img src="<?php echo htmlspecialchars($img_path); ?>"
                 style="width:64px;height:64px;object-fit:cover;border-radius:10px;flex-shrink:0;">
            <?php endif; ?>
            <div>
                <div style="font-weight:700;font-size:1rem;color:#3B2A1A;">
                    <?php echo htmlspecialchars($subject['subject_name']); ?>   
                </div>
                <div style="font-size:0.82rem;color:#888;margin-top:3px;">
                    <?php if ($type === 'appointment'): ?>
                        <?php echo date('F d, Y', strtotime($subject['appointment_date'])); ?>
                        &nbsp;·&nbsp;
                        <span style="background:#cff4fc;color:#055160;padding:2px 8px;
                                     border-radius:20px;font-size:0.75rem;font-weight:600;">
                            Completed
                        </span>
                    <?php else: ?>
                        Order #<?php echo $subject['id']; ?>
                        &nbsp;·&nbsp;
                        <span style="background:#d4edda;color:#155724;padding:2px 8px;
                                     border-radius:20px;font-size:0.75rem;font-weight:600;">
                            Completed
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alert -->
        <?php if ($message): ?>
        <div style="padding:0.75rem 1rem;border-radius:8px;margin-bottom:1.25rem;
                    background:<?php echo $msg_type==='success'?'#d4edda':'#f8d7da'; ?>;
                    color:<?php echo $msg_type==='success'?'#155724':'#721c24'; ?>;
                    border-left:4px solid <?php echo $msg_type==='success'?'#198754':'#dc3545'; ?>;">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <?php if ($existing): ?>
        <!-- ── Already submitted — show service rating ───────────────── -->
        <h3 style="color:#3B2A1A;margin:0 0 0.5rem;">Your Feedback</h3>

        <!-- Service rating -->
        <div style="margin-bottom:1.25rem;padding:1rem;background:#FAF3E8;
                    border-radius:10px;border:1px solid #EAD8C0;">
            <div style="font-size:0.72rem;font-weight:700;color:#888;
                        text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                💆 Service Rating
            </div>
            <div style="display:flex;gap:3px;margin-bottom:0.4rem;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <span style="font-size:2rem;color:<?php echo $i<=$existing['rating']?'#f59e0b':'#e5e7eb'; ?>;">★</span>
                <?php endfor; ?>
                <span style="align-self:center;margin-left:0.5rem;font-size:0.88rem;
                             color:#C96A2C;font-weight:600;">
                    <?php echo ['','Poor','Fair','Good','Very Good','Excellent'][$existing['rating']]; ?>
                </span>
            </div>
            <?php if ($existing['comment']): ?>
            <div style="font-size:0.9rem;color:#3B2A1A;line-height:1.6;
                        border-left:3px solid #C96A2C;padding-left:0.75rem;margin-top:0.5rem;">
                "<?php echo htmlspecialchars($existing['comment']); ?>"
            </div>
            <?php endif; ?>
        </div>

        <!-- Therapist ratings (read-only if already rated) -->
        <?php if ($type === 'appointment' && !empty($assigned_therapists)): ?>
        <div style="margin-bottom:1.25rem;">
            <div style="font-size:0.72rem;font-weight:700;color:#888;
                        text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
                👤 Therapist Ratings
            </div>
            <?php foreach ($assigned_therapists as $th):
                $tr = $existing_therapist_ratings[$th['therapist_id']] ?? null;
            ?>
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;
                        background:#FAF3E8;border-radius:10px;border:1px solid #EAD8C0;
                        margin-bottom:0.5rem;">
                <div style="width:36px;height:36px;border-radius:50%;flex-shrink:0;
                            background:linear-gradient(135deg,#C8A46B,#A94F1D);
                            display:flex;align-items:center;justify-content:center;
                            font-weight:700;color:#fff;font-size:0.9rem;">
                    <?php echo strtoupper(substr($th['full_name'],0,1)); ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:600;color:#3B2A1A;font-size:0.9rem;">
                        <?php echo htmlspecialchars($th['full_name']); ?>
                    </div>
                    <?php if ($tr): ?>
                    <div style="display:flex;gap:2px;margin-top:0.2rem;">
                        <?php for($i=1;$i<=5;$i++): ?>
                        <span style="font-size:1.1rem;color:<?php echo $i<=$tr['rating']?'#f59e0b':'#e5e7eb'; ?>;">★</span>
                        <?php endfor; ?>
                        <span style="align-self:center;margin-left:0.4rem;font-size:0.78rem;color:#C96A2C;font-weight:600;">
                            <?php echo ['','Poor','Fair','Good','Very Good','Excellent'][$tr['rating']]; ?>
                        </span>
                    </div>
                    <?php if ($tr['comment']): ?>
                    <div style="font-size:0.8rem;color:#6B4C30;margin-top:0.2rem;font-style:italic;">
                        "<?php echo htmlspecialchars($tr['comment']); ?>"
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div style="font-size:0.78rem;color:#aaa;margin-top:0.2rem;">Not rated</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="appointments.php"
           style="display:block;text-align:center;margin-top:1rem;padding:0.75rem;
                  background:#3B2A1A;color:#FAF3E8;border-radius:10px;
                  font-weight:600;text-decoration:none;font-size:0.92rem;">
            Back to My Appointments
        </a>

        <?php else: ?>
        <!-- ── Feedback form ──────────────────────────────────────────────── -->
        <h3 style="color:#3B2A1A;margin:0 0 0.25rem;">
            <?php echo $type === 'appointment' ? 'How was your session?' : 'How was your purchase?'; ?>
        </h3>
        <p style="color:#888;font-size:0.85rem;margin-bottom:1.5rem;">
            Your honest feedback helps us improve.
        </p>

        <form method="POST"><?php echo csrf_field(); ?>

            <!-- ── Section 1: Service rating ───────────────────────────── -->
            <div style="margin-bottom:1.5rem;padding:1.1rem;background:#FAF3E8;
                        border-radius:12px;border:1px solid #EAD8C0;">
                <div style="font-size:0.72rem;font-weight:700;color:#888;
                            text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.65rem;">
                    💆 <?php echo $type === 'appointment' ? 'Service Rating' : 'Product Rating'; ?>
                    <span style="color:red;">*</span>
                </div>
                <div id="star-row" style="display:flex;gap:6px;margin-bottom:4px;">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="star" data-val="<?php echo $i; ?>"
                          style="font-size:2.4rem;cursor:pointer;color:#e5e7eb;
                                 transition:color 0.12s,transform 0.12s;line-height:1;">★</span>
                    <?php endfor; ?>
                </div>
                <div id="star-label" style="font-size:0.82rem;color:#C96A2C;font-weight:600;min-height:18px;"></div>
                <input type="hidden" name="rating" id="rating_input" value="0">

                <!-- Comment -->
                <div style="margin-top:0.85rem;">
                    <label style="display:block;font-size:0.82rem;font-weight:600;
                                  color:#3B2A1A;margin-bottom:0.4rem;">
                        Comment <span style="color:#888;font-weight:400;">(optional)</span>
                    </label>
                    <textarea name="comment" rows="3"
                              placeholder="Tell us about the service..."
                              style="width:100%;padding:0.65rem;border:1.5px solid #EAD8C0;
                                     border-radius:8px;font-family:inherit;font-size:0.88rem;
                                     resize:vertical;box-sizing:border-box;color:#3B2A1A;
                                     background:#fff;transition:border-color 0.2s;"
                              onfocus="this.style.borderColor='#C96A2C'"
                              onblur="this.style.borderColor='#EAD8C0'"></textarea>
                </div>
            </div>

            <!-- ── Section 2: Therapist ratings (appointment only) ──────── -->
            <?php if ($type === 'appointment' && !empty($assigned_therapists)): ?>
            <div style="margin-bottom:1.5rem;">
                <div style="font-size:0.72rem;font-weight:700;color:#888;
                            text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
                    👤 Rate Your Therapist<?php echo count($assigned_therapists)>1?'s':''; ?>
                </div>
                <?php foreach ($assigned_therapists as $idx => $th): ?>
                <div style="padding:1rem;background:#FAF3E8;border-radius:12px;
                            border:1px solid #EAD8C0;margin-bottom:0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.75rem;">
                        <div style="width:36px;height:36px;border-radius:50%;flex-shrink:0;
                                    background:linear-gradient(135deg,#C8A46B,#A94F1D);
                                    display:flex;align-items:center;justify-content:center;
                                    font-weight:700;color:#fff;font-size:0.9rem;">
                            <?php echo strtoupper(substr($th['full_name'],0,1)); ?>
                        </div>
                        <div>
                            <div style="font-weight:700;color:#3B2A1A;font-size:0.9rem;">
                                <?php echo htmlspecialchars($th['full_name']); ?>
                            </div>
                            <?php if ($th['specialties']): ?>
                            <div style="font-size:0.72rem;color:#888;">
                                <?php echo htmlspecialchars($th['specialties']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Therapist star rating -->
                    <div style="margin-bottom:0.5rem;">
                        <div class="th-star-row" data-tid="<?php echo $th['therapist_id']; ?>"
                             style="display:flex;gap:5px;margin-bottom:4px;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="th-star"
                                  data-val="<?php echo $i; ?>"
                                  data-tid="<?php echo $th['therapist_id']; ?>"
                                  style="font-size:2rem;cursor:pointer;color:#e5e7eb;
                                         transition:color 0.12s,transform 0.12s;line-height:1;">★</span>
                            <?php endfor; ?>
                        </div>
                        <div id="th-label-<?php echo $th['therapist_id']; ?>"
                             style="font-size:0.78rem;color:#C96A2C;font-weight:600;min-height:16px;"></div>
                        <input type="hidden"
                               name="therapist_ratings[<?php echo $th['therapist_id']; ?>]"
                               id="th-rating-<?php echo $th['therapist_id']; ?>"
                               value="0">
                    </div>

                    <!-- Therapist comment -->
                    <textarea name="therapist_comments[<?php echo $th['therapist_id']; ?>]"
                              rows="2" placeholder="Comment about this therapist (optional)"
                              style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;
                                     border-radius:8px;font-family:inherit;font-size:0.85rem;
                                     resize:vertical;box-sizing:border-box;color:#3B2A1A;
                                     background:#fff;transition:border-color 0.2s;"
                              onfocus="this.style.borderColor='#C96A2C'"
                              onblur="this.style.borderColor='#EAD8C0'"></textarea>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <button type="submit" id="submitBtn"
                    style="width:100%;padding:0.9rem;background:#C96A2C;color:#fff;
                           border:none;border-radius:10px;font-family:inherit;
                           font-size:1rem;font-weight:700;cursor:pointer;
                           transition:background 0.2s;opacity:0.6;"
                    disabled>
                Submit Feedback
            </button>
        </form>
        <?php endif; ?>

    </div>
</div>

<script>
const labels = ['','Poor','Fair','Good','Very Good','Excellent'];

// ── Service stars ──────────────────────────────────────────────────────────
const stars  = document.querySelectorAll('#star-row .star');
const input  = document.getElementById('rating_input');
const lbl    = document.getElementById('star-label');
const btn    = document.getElementById('submitBtn');
let selected = 0;

stars.forEach(s => {
    s.addEventListener('mouseover', () => paintService(+s.dataset.val));
    s.addEventListener('mouseleave', () => paintService(selected));
    s.addEventListener('click', () => {
        selected   = +s.dataset.val;
        input.value = selected;
        paintService(selected);
        lbl.textContent   = labels[selected];
        btn.disabled      = false;
        btn.style.opacity = '1';
        s.style.transform = 'scale(1.25)';
        setTimeout(() => s.style.transform = '', 200);
    });
});

function paintService(val) {
    stars.forEach(s => {
        s.style.color = +s.dataset.val <= val ? '#f59e0b' : '#e5e7eb';
    });
}

// ── Therapist stars (one set per therapist) ────────────────────────────────
const thStars = {};

document.querySelectorAll('.th-star').forEach(s => {
    const tid = s.dataset.tid;
    if (!thStars[tid]) thStars[tid] = { selected: 0 };

    s.addEventListener('mouseover', () => paintTh(tid, +s.dataset.val));
    s.addEventListener('mouseleave', () => paintTh(tid, thStars[tid].selected));
    s.addEventListener('click', () => {
        thStars[tid].selected = +s.dataset.val;
        document.getElementById('th-rating-' + tid).value = thStars[tid].selected;
        paintTh(tid, thStars[tid].selected);
        const lbl = document.getElementById('th-label-' + tid);
        if (lbl) lbl.textContent = labels[thStars[tid].selected];
        s.style.transform = 'scale(1.25)';
        setTimeout(() => s.style.transform = '', 200);
    });
});

function paintTh(tid, val) {
    document.querySelectorAll('.th-star[data-tid="' + tid + '"]').forEach(s => {
        s.style.color = +s.dataset.val <= val ? '#f59e0b' : '#e5e7eb';
    });
}
</script>