<?php
require_once '../config.php';
redirect_if_not_admin();

$conn->query("ALTER TABLE therapists ADD COLUMN IF NOT EXISTS is_generalist TINYINT(1) NOT NULL DEFAULT 0");

$page_title  = 'Walk-in Kiosk';
$page_icon   = '🏪';
$active_page = 'walkin';

$walkin_user_id = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username = 'walkin_customer' LIMIT 1");
$stmt->execute();
$walkin_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($walkin_user) {
    $walkin_user_id = $walkin_user['id'];
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role) VALUES ('walkin_customer','N/A','walkin@spa.com','Walk-in Customer','N/A','Walk-in Customer','user')");
    $stmt->execute();
    $walkin_user_id = $stmt->insert_id;
    $stmt->close();
}

$walkin_message = '';
$walkin_type    = '';

// ── Fetch partner rates FIRST (needed in POST handler for hotel pricing) ──────
$all_partners = [];
$result = $conn->query("SELECT id, name, type FROM partners WHERE status='active' ORDER BY name");
while ($row = $result->fetch_assoc()) $all_partners[] = $row;

$partner_rates_map = [];
if (!empty($all_partners)) {
    $pids = implode(',', array_map(fn($p) => intval($p['id']), $all_partners));
    $pr   = $conn->query("SELECT partner_id, service_id, price FROM partner_rates WHERE partner_id IN ($pids)");
    while ($r = $pr->fetch_assoc()) $partner_rates_map[$r['partner_id']][$r['service_id']] = $r['price'];
}

// ── mark_paid_paymongo ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_paid_paymongo') {
    header('Content-Type: application/json');
    if (!verify_csrf_token_ajax()) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF error']); exit;
    }
    $order_id  = intval($_POST['order_id']  ?? 0);
    $pm_ref    = sanitize_input($_POST['pm_ref']    ?? '');
    $pm_method = sanitize_input($_POST['pm_method'] ?? '');
    $allowed_pm = ['gcash', 'maya', 'card', 'qrph', 'online'];
    if (!$order_id || !in_array($pm_method, $allowed_pm)) {
        echo json_encode(['error' => 'Invalid data']); exit;
    }
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE orders
                                SET payment_status='paid', approval_status='approved',
                                    paymongo_method=?, paymongo_reference=?
                                WHERE id=? AND payment_status != 'paid'");
        $upd->bind_param("ssi", $pm_method, $pm_ref, $order_id);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();

        if ($changed > 0) {
            // Approve any linked pending appointments
            $conn->query("UPDATE appointments a
                          INNER JOIN order_items oi ON a.order_item_id = oi.id
                          SET a.status = 'approved'
                          WHERE oi.order_id = $order_id AND a.status = 'pending'");
            // Deduct product stock (for product orders paid via OTP)
            $conn->query("UPDATE products p
                          INNER JOIN order_items oi ON oi.product_id = p.id
                          SET p.stock = p.stock - oi.quantity
                          WHERE oi.order_id = $order_id AND p.stock >= oi.quantity");
        }
        $conn->commit();
        echo json_encode($changed > 0 ? ['success' => true] : ['error' => 'Order already paid or not found']);
    } catch (Throwable $_me) {
        $conn->rollback();
        error_log('[MARK_PAID_PM] ' . $_me->getMessage());
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['walkin_order'])) {
    verify_csrf_token();
    $customer_name  = sanitize_input($_POST['customer_name']);
    $phone          = sanitize_input($_POST['phone']);
    $order_type     = $_POST['order_type'];
    $item_id        = intval($_POST['item_id']);
    $quantity       = max(1, intval($_POST['quantity'] ?? 1));
    $people_count   = max(1, intval($_POST['people_count'] ?? 1));
    $booking_date   = $_POST['booking_date'] ?? null;
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $rate_type      = $_POST['rate_type']      ?? 'regular';
    $partner_id     = intval($_POST['partner_id'] ?? 0);
    $customer_note  = sanitize_input($_POST['customer_note'] ?? '');
    $slip_number    = sanitize_input($_POST['slip_number']    ?? '');
    $therapist_id   = intval($_POST['therapist_id']   ?? 0);

    $discount_type  = in_array($_POST['discount_type'] ?? '', ['none','voucher','senior','pwd','employee'])
                      ? $_POST['discount_type'] : 'none';
    $voucher_type_i = $_POST['voucher_type']   ?? 'cash';
    $voucher_value  = floatval($_POST['voucher_amount'] ?? 0);

    if (empty($customer_name) || empty($phone) || empty($item_id)) {
        $walkin_message = "Please fill in all required fields and select an item.";
        $walkin_type    = "danger";
    } else {
        if ($order_type === 'product') {
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND deleted_at IS NULL");
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$item) {
                $walkin_message = "Insufficient stock.";
                $walkin_type = "danger";
            } else {
                $conn->begin_transaction();
                $ls = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                $ls->bind_param("i", $item_id); $ls->execute();
                $live_stock = intval($ls->get_result()->fetch_assoc()['stock'] ?? 0); $ls->close();

                if ($live_stock < $quantity) {
                    $conn->rollback();
                    $walkin_message = "Insufficient stock (only $live_stock left).";
                    $walkin_type = "danger";
                } else {
                $total_amount = $item['price'] * $quantity;
                $discount_amount_calc = 0.00;
                if ($discount_type === 'senior' || $discount_type === 'pwd') {
                    $discount_amount_calc = round($total_amount * 0.20, 2);
                } elseif ($discount_type === 'employee') {
                    $discount_amount_calc = round($total_amount * 0.50, 2);
                } elseif ($discount_type === 'voucher' && $voucher_value > 0) {
                    $discount_amount_calc = $voucher_type_i === 'percent'
                        ? round($total_amount * ($voucher_value / 100), 2)
                        : min($voucher_value, $total_amount);
                }
                $final_amount = max(0.00, $total_amount - $discount_amount_calc);

                $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number) VALUES (?, ?, ?, ?, ?, 'paid', 'approved', ?, ?, ?, ?)");
                $stmt->bind_param("issdssdds", $walkin_user_id, $customer_name, $phone, $total_amount, $payment_method, $discount_type, $discount_amount_calc, $final_amount, $slip_number);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiidd", $order_id, $item_id, $quantity, $item['price'], $total_amount);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $stmt->bind_param("iii", $quantity, $item_id, $quantity);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $disc_suffix = $discount_type !== 'none' && $discount_amount_calc > 0
                    ? ' · 🎟️ ' . ['voucher'=>'Voucher','senior'=>'Senior','pwd'=>'PWD','employee'=>'Employee'][$discount_type] . ' −₱' . number_format($discount_amount_calc,2) . ' · Final: <strong>₱' . number_format($final_amount,2) . '</strong>'
                    : '';
                $walkin_message = "✅ Product Order #$order_id for <strong>{$customer_name}</strong> · ₱" . number_format($total_amount,2) . $disc_suffix;
                $walkin_type = "success";
                }
            }

        } elseif ($order_type === 'service') {
            $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? AND deleted_at IS NULL");
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($item) {
                $regular_price = floatval($item['price']);
                switch ($rate_type) {
                    case 'home':       $charged_price = ($regular_price * 2) + 300; break;
                    case 'hotel':      $charged_price = $partner_rates_map[$partner_id][$item_id] ?? $regular_price; break;
                    case 'influencer': $charged_price = 0.00; break;
                    default:           $charged_price = $regular_price; break;
                }

                $total_amount    = $charged_price;
                $appt_rate_type  = $rate_type;
                $appt_partner_id = ($rate_type === 'hotel' && $partner_id > 0) ? $partner_id : null;

                $discount_amount_calc = 0.00;
                if ($discount_type === 'senior' || $discount_type === 'pwd') {
                    $discount_amount_calc = round($total_amount * 0.20, 2);
                } elseif ($discount_type === 'employee') {
                    $discount_amount_calc = round($total_amount * 0.50, 2);
                } elseif ($discount_type === 'voucher' && $voucher_value > 0) {
                    $discount_amount_calc = $voucher_type_i === 'percent'
                        ? round($total_amount * ($voucher_value / 100), 2)
                        : min($voucher_value, $total_amount);
                }
                $final_amount = max(0.00, $total_amount - $discount_amount_calc);

                // ── Feature A: today + zero qualified on duty → block ─────────────
                $is_today_booking = (date('Y-m-d') === date('Y-m-d', strtotime($booking_date)));
                if ($is_today_booking) {
                    $qc = $conn->prepare("
                        SELECT COUNT(DISTINCT t.id) AS cnt
                        FROM therapists t
                        JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
                        WHERE (ta.time_out IS NULL OR ta.time_out = '')
                          AND (
                              t.is_generalist = 1
                              OR EXISTS(SELECT 1 FROM therapist_specialty_services WHERE therapist_id = t.id AND service_id = ?)
                              OR EXISTS(SELECT 1 FROM therapist_specialties ts
                                        JOIN services s ON s.category_id = ts.category_id
                                        WHERE ts.therapist_id = t.id AND s.id = ?)
                          )
                    ");
                    $qc->bind_param("ii", $item_id, $item_id); $qc->execute();
                    $qualified_on_duty_count = (int)$qc->get_result()->fetch_assoc()['cnt']; $qc->close();
                    if ($qualified_on_duty_count === 0) {
                        $walkin_message = "⛔ No qualified therapist is currently on duty for <strong>{$item['name']}</strong>. Please book a future date or wait until a qualified therapist checks in.";
                        $walkin_type    = "danger";
                    }
                }

                // ── Feature B-1: today + no free qualified therapist at chosen time ──
                if ($is_today_booking && empty($walkin_message)) {
                    $svc_session_pre = intval($item['session_time'] ?? 60);
                    $svc_buffer_pre  = ($rate_type === 'home') ? 30 : 0;
                    $end_mins_pre    = $svc_session_pre + $svc_buffer_pre;
                    $fq = $conn->prepare("
                        SELECT COUNT(DISTINCT t.id) AS free_cnt
                        FROM therapists t
                        JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE()
                        WHERE (ta.time_out IS NULL OR ta.time_out = '')
                          AND (
                              t.is_generalist = 1
                              OR EXISTS(SELECT 1 FROM therapist_specialty_services
                                        WHERE therapist_id = t.id AND service_id = ?)
                              OR EXISTS(SELECT 1 FROM therapist_specialties ts
                                        JOIN services s ON s.category_id = ts.category_id
                                        WHERE ts.therapist_id = t.id AND s.id = ?)
                          )
                          AND NOT EXISTS(
                              SELECT 1
                              FROM appointment_therapists at2
                              JOIN appointments a2 ON at2.appointment_id = a2.id
                              JOIN services    s2 ON a2.service_id = s2.id
                              WHERE at2.therapist_id = t.id
                                AND a2.status IN ('approved','assigned')
                                AND DATE(a2.appointment_date) = DATE(?)
                                AND (a2.appointment_date - INTERVAL IF(a2.service_type='home',30,0) MINUTE)
                                    < (? + INTERVAL ? MINUTE)
                                AND (a2.appointment_date + INTERVAL (s2.session_time + IF(a2.service_type='home',30,0)) MINUTE)
                                    > (? - INTERVAL ? MINUTE)
                          )
                    ");
                    $fq->bind_param("iissisi", $item_id, $item_id,
                                    $booking_date,
                                    $booking_date, $end_mins_pre, $booking_date, $svc_buffer_pre);
                    $fq->execute();
                    $free_count = (int)$fq->get_result()->fetch_assoc()['free_cnt']; $fq->close();
                    if ($free_count === 0) {
                        $booked_time = date('h:i A', strtotime($booking_date));
                        $walkin_message = "⛔ No qualified therapist is free at <strong>{$booked_time}</strong> "
                                        . "for <strong>{$item['name']}</strong>. Please choose a different time.";
                        $walkin_type    = "danger";
                    }
                }

                if (empty($walkin_message)) {
                $conn->begin_transaction();
                $specialty_error = false;
                try {
                $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, booking_date, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number) VALUES (?, ?, ?, ?, ?, ?, 'paid', 'approved', ?, ?, ?, ?)");
                $stmt->bind_param("isssdssdds", $walkin_user_id, $customer_name, $phone, $booking_date, $total_amount, $payment_method, $discount_type, $discount_amount_calc, $final_amount, $slip_number);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();

                $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
                $item_stmt->bind_param("iidd", $order_id, $item_id, $charged_price, $charged_price);
                $item_stmt->execute();
                $order_item_id = $item_stmt->insert_id;
                $item_stmt->close();

                $appt_stmt = $conn->prepare("INSERT INTO appointments (user_id, service_id, order_item_id, appointment_date, status, people_count, service_type, rate_type, partner_id, charged_price, customer_note) VALUES (?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?)");
                $svc_type_val = ($rate_type === 'home') ? 'home' : 'onsite';
                $appt_stmt->bind_param("iiisissids", $walkin_user_id, $item_id, $order_item_id, $booking_date, $people_count, $svc_type_val, $appt_rate_type, $appt_partner_id, $charged_price, $customer_note);
                $appt_stmt->execute();
                $appointment_id = $appt_stmt->insert_id; // ← actual appointment ID
                $appt_stmt->close();

                if ($therapist_id > 0) {
                    // ── Feature A: server-side specialty enforcement (generalist-aware) ──
                    $spc = $conn->prepare("
                        SELECT t.is_generalist,
                               (SELECT COUNT(*) FROM therapist_specialty_services
                                WHERE therapist_id=? AND service_id=?)
                             + (SELECT COUNT(*) FROM therapist_specialties ts
                                JOIN services s ON s.category_id = ts.category_id
                                WHERE ts.therapist_id=? AND s.id=?) AS specialty_count
                        FROM therapists t WHERE t.id=?
                    ");
                    $spc->bind_param("iiiii", $therapist_id, $item_id, $therapist_id, $item_id, $therapist_id);
                    $spc->execute();
                    $spc_row = $spc->get_result()->fetch_assoc(); $spc->close();
                    if (!($spc_row['is_generalist'] ?? 0) && (int)($spc_row['specialty_count'] ?? 0) === 0) {
                        $specialty_error = true; throw new RuntimeException('specialty_fail');
                    }

                    $svc_session = intval($item['session_time'] ?? 60);
                    $svc_buffer  = ($rate_type === 'home') ? 30 : 0;
                    $cf = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointment_therapists at2 JOIN appointments a2 ON at2.appointment_id = a2.id JOIN services s2 ON a2.service_id = s2.id WHERE at2.therapist_id = ? AND a2.status IN ('approved','assigned') AND (a2.appointment_date - INTERVAL IF(a2.service_type='home',30,0) MINUTE) < (? + INTERVAL ? MINUTE) AND (a2.appointment_date + INTERVAL (s2.session_time + IF(a2.service_type='home',30,0)) MINUTE) > (? - INTERVAL ? MINUTE)");
                    $end_mins = $svc_session + $svc_buffer;
                    $cf->bind_param("isisi", $therapist_id, $booking_date, $end_mins, $booking_date, $svc_buffer);
                    $cf->execute();
                    $cf_count = (int)$cf->get_result()->fetch_assoc()['cnt']; $cf->close();

                    if ($cf_count > 0) {
                        $upd_status = $conn->prepare("UPDATE appointments SET status='pending' WHERE id=?");
                        $upd_status->bind_param("i", $appointment_id); $upd_status->execute(); $upd_status->close();
                        $walkin_message .= " ⚠️ Note: Selected therapist has a conflicting appointment — saved without therapist assignment.";
                    } else {
                        $cm = $conn->prepare("SELECT commission_percent, influencer_flat_rate FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
                        $cm->bind_param("ii", $therapist_id, $item_id); $cm->execute();
                        $cm_row = $cm->get_result()->fetch_assoc(); $cm->close();
                        $commission = 0.00;
                        if ($cm_row) {
                            $commission = $rate_type === 'influencer'
                                ? floatval($cm_row['influencer_flat_rate'])
                                : round($charged_price * floatval($cm_row['commission_percent']) / 100, 2);
                        }
                        $at = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, commission, notes) VALUES (?, ?, ?, '')");
                        $at->bind_param("iid", $appointment_id, $therapist_id, $commission); $at->execute(); $at->close();

                        $is_future = strtotime($booking_date) > (time() + 1800);
                        if (!$is_future) {
                            $max_rot = $conn->query("SELECT IFNULL(MAX(rotation_order), 0) AS m FROM therapist_attendance WHERE duty_date = CURDATE()")->fetch_assoc()['m'];
                            $new_order = $max_rot + 1;
                            $upd_rot = $conn->prepare("UPDATE therapist_attendance SET rotation_order = ? WHERE therapist_id = ? AND duty_date = CURDATE()");
                            $upd_rot->bind_param("ii", $new_order, $therapist_id); $upd_rot->execute(); $upd_rot->close();
                        }
                    }
                }

                $conn->commit();
                $rate_labels = ['regular'=>'Regular','home'=>'Home Service (2× + ₱300)','hotel'=>'Hotel/Partner','influencer'=>'Influencer (Free)'];
                $rate_label = $rate_labels[$rate_type] ?? 'Regular';
                $disc_suffix = $discount_type !== 'none' && $discount_amount_calc > 0
                    ? ' · 🎟️ ' . ['voucher'=>'Voucher','senior'=>'Senior Citizen','pwd'=>'PWD','employee'=>'Employee'][$discount_type] . ' −₱' . number_format($discount_amount_calc,2) . ' · Final: <strong>₱' . number_format($final_amount,2) . '</strong>'
                    : '';
                $walkin_message = "✅ Service Booking #$order_id for <strong>{$customer_name}</strong> — {$item['name']} · {$rate_label} · ₱" . number_format($charged_price, 2) . $disc_suffix;
                $walkin_type    = "success";
                } catch (Throwable $_we) {
                    $conn->rollback();
                    if ($specialty_error) {
                        $walkin_message = "⚠️ The selected therapist is not qualified for <strong>{$item['name']}</strong>. Please select a qualified therapist.";
                    } else {
                        error_log('[WALKIN] Service order failed: ' . $_we->getMessage());
                        $walkin_message = "Order failed. Please try again.";
                    }
                    $walkin_type = "danger";
                }
                } // end if (empty($walkin_message))
            }
        }
    }
}

$all_services = [];
$result = $conn->query("SELECT id, name, price, session_time, is_home_service, home_service_fee FROM services WHERE deleted_at IS NULL ORDER BY name");
while ($row = $result->fetch_assoc()) $all_services[] = $row;

// $all_partners and $partner_rates_map already fetched above POST handler

$all_products = [];
$result = $conn->query("SELECT id, name, price, stock FROM products WHERE stock > 0 AND deleted_at IS NULL ORDER BY name");
while ($row = $result->fetch_assoc()) $all_products[] = $row;

$on_duty_therapists = [];
$result = $conn->query("SELECT t.id, t.full_name, t.specialties, ta.is_on_break, ta.time_out, ta.rotation_order FROM therapists t JOIN therapist_attendance ta ON ta.therapist_id = t.id WHERE ta.duty_date = CURDATE() ORDER BY ta.rotation_order ASC");
while ($row = $result->fetch_assoc()) $on_duty_therapists[] = $row;

$all_therapists_list = [];
$result = $conn->query("SELECT t.id, t.full_name, t.specialties, t.is_generalist, ta.rotation_order, ta.is_on_break, ta.time_out, CASE WHEN ta.therapist_id IS NOT NULL THEN 1 ELSE 0 END AS on_duty_today FROM therapists t LEFT JOIN therapist_attendance ta ON ta.therapist_id = t.id AND ta.duty_date = CURDATE() ORDER BY on_duty_today DESC, ta.rotation_order ASC, t.full_name ASC");
while ($row = $result->fetch_assoc()) $all_therapists_list[] = $row;

$services_session_map = [];
foreach ($all_services as $svc) $services_session_map[$svc['id']] = intval($svc['session_time'] ?? 60);

// Therapist specialty map for JS — therapist_id => array of service_ids they can do
$therapist_specialty_svc_map = [];
if (!empty($all_therapists_list)) {
    $tids = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists_list));
    // Direct service specialties
    $sq = $conn->query("SELECT therapist_id, service_id FROM therapist_specialty_services WHERE therapist_id IN ($tids)");
    if ($sq) while ($r = $sq->fetch_assoc()) $therapist_specialty_svc_map[$r['therapist_id']][] = intval($r['service_id']);
    // Category-level specialties
    $cq = $conn->query("SELECT ts.therapist_id, s.id AS service_id FROM therapist_specialties ts JOIN services s ON s.category_id = ts.category_id WHERE ts.therapist_id IN ($tids)");
    if ($cq) while ($r = $cq->fetch_assoc()) {
        if (!in_array(intval($r['service_id']), $therapist_specialty_svc_map[$r['therapist_id']] ?? []))
            $therapist_specialty_svc_map[$r['therapist_id']][] = intval($r['service_id']);
    }
}

$therapist_generalist_map = [];
foreach ($all_therapists_list as $t) {
    $therapist_generalist_map[$t['id']] = (bool)($t['is_generalist'] ?? false);
}

$therapist_busy_slots = [];
if (!empty($all_therapists_list)) {
    $tids = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists_list));
    $busy_q = $conn->query("SELECT at2.therapist_id, a.appointment_date, a.service_type, s.session_time FROM appointment_therapists at2 JOIN appointments a ON at2.appointment_id = a.id JOIN services s ON a.service_id = s.id WHERE at2.therapist_id IN ($tids) AND a.status IN ('approved','assigned') AND DATE(a.appointment_date) >= CURDATE()");
    while ($r = $busy_q->fetch_assoc()) {
        $buffer = ($r['service_type'] === 'home') ? 30 : 0;
        $therapist_busy_slots[$r['therapist_id']][] = [
            'start' => strtotime($r['appointment_date']) - ($buffer * 60),
            'end'   => strtotime($r['appointment_date']) + (($r['session_time'] + $buffer) * 60),
        ];
    }
}

// Therapists with an approved/assigned appointment today (treated as effectively present)
$has_appt_today_ids = [];
if (!empty($all_therapists_list)) {
    $tids_str = implode(',', array_map(fn($t) => intval($t['id']), $all_therapists_list));
    $haq = $conn->query("
        SELECT DISTINCT at2.therapist_id
        FROM appointment_therapists at2
        JOIN appointments a ON at2.appointment_id = a.id
        WHERE at2.therapist_id IN ($tids_str)
          AND a.status IN ('approved','assigned')
          AND DATE(a.appointment_date) = CURDATE()
    ");
    while ($r = $haq->fetch_assoc()) {
        $has_appt_today_ids[] = intval($r['therapist_id']);
    }
}

require_once 'admin_header.php';
?>

<?php if ($walkin_message): ?>
<div class="walkin-alert <?php echo $walkin_type; ?>">
    <?php echo $walkin_message; ?>
</div>
<?php endif; ?>

<div class="kiosk-tabs" style="max-width:320px;">
    <button type="button" class="kiosk-tab active" onclick="switchTab('service', this)">💆 Book Service</button>
    <button type="button" class="kiosk-tab" onclick="switchTab('product', this)">🛍️ Buy Product</button>
</div>

<div class="kiosk-panel active" id="tab-service">
    <form method="POST" id="serviceForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="walkin_order"   value="1">
        <input type="hidden" name="order_type"     value="service">
        <input type="hidden" name="item_id"        id="service_item_id" value="">
        <input type="hidden" name="payment_method" id="service_payment_method" value="cash">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
            <div>
                <div class="form-section">
                    <div class="form-section-header">👤 Customer Information</div>
                    <div class="form-section-body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="customer_name" placeholder="Enter full name" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number <span class="required">*</span></label>
                                <input type="tel" name="phone" placeholder="09XXXXXXXXX" required>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:0.5rem;">
                            <label>Service Slip No.</label>
                            <input type="text" name="slip_number" id="service_slip_number" placeholder="e.g. 1108-2026" style="width:180px;letter-spacing:0.05em;" oninput="syncSlipNumber('service',this.value)">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">💆 Select Service <span class="required">*</span></div>
                    <div class="form-section-body" style="padding:1rem;">
                        <div class="item-select-grid" id="service-grid">
                            <?php foreach ($all_services as $svc): ?>
                            <div class="item-card" onclick="selectItem('service', <?php echo $svc['id']; ?>, this)" data-id="<?php echo $svc['id']; ?>">
                                <div class="item-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                                <div class="item-price">₱<?php echo number_format($svc['price'], 2); ?></div>
                                <div class="item-meta">⏱ <?php echo $svc['session_time']; ?> min</div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_services)): ?><p style="color:var(--gray);font-size:0.82rem;padding:1rem;grid-column:1/-1;">No services available.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="form-section">
                    <div class="form-section-header">📅 Booking Details</div>
                    <div class="form-section-body">
                        <div class="form-group" style="margin-bottom:1rem;">
                            <label>Booking Date & Time <span class="required">*</span></label>
                            <input type="datetime-local" name="booking_date" id="service_booking_date" required>
                        </div>
                        <div class="form-group">
                            <label>Number of People <span class="required">*</span></label>
                            <input type="number" name="people_count" value="1" min="1" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header" id="therapist-section-header">
                        💆 Assign Therapist
                        <span id="therapist-mode-badge" style="font-size:0.72rem;font-weight:400;color:var(--gray);margin-left:0.4rem;"></span>
                    </div>
                    <div class="form-section-body">
                        <input type="hidden" name="therapist_id" id="svc_therapist_id" value="0">
                        <div id="therapist-no-svc-notice" style="margin-bottom:0.75rem;padding:0.6rem 0.85rem;background:rgba(107,114,128,0.08);border:1px solid rgba(107,114,128,0.25);border-radius:8px;font-size:0.8rem;color:var(--gray);">
                            💡 Select a service above to see available therapists.
                        </div>
                        <div id="therapist-today-block" style="display:none;margin-bottom:0.75rem;padding:0.6rem 0.85rem;background:rgba(220,53,69,0.08);border:1px solid rgba(220,53,69,0.35);border-radius:8px;font-size:0.8rem;color:#b91c1c;"></div>
                        <div id="people-count-warning" style="display:none;margin-bottom:0.75rem;padding:0.6rem 0.85rem;background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.5);border-radius:8px;font-size:0.8rem;color:#92400e;"></div>
                        <?php if (empty($all_therapists_list)): ?>
                        <div style="padding:0.65rem;background:rgba(220,53,69,0.08);border:1px solid rgba(220,53,69,0.2);border-radius:8px;font-size:0.82rem;color:#ff6b7a;">
                            ⚠️ No therapists in the system. Go to <a href="staff.php?tab=therapists" style="color:var(--gold);font-weight:600;">Staff → Therapists</a> to add therapists.
                        </div>
                        <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:0.4rem;" id="therapist-btn-list">
                            <div class="therapist-pick-btn" id="tbtn-0" onclick="selectWalkinTherapist(0)"
                                 style="display:flex;align-items:center;gap:0.65rem;padding:0.55rem 0.75rem;border:2px solid var(--gold);background:#fff8f2;border-radius:9px;cursor:pointer;transition:all .15s;">
                                <div style="width:28px;height:28px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:0.85rem;flex-shrink:0;">🚫</div>
                                <div>
                                    <div style="font-size:0.82rem;font-weight:700;color:var(--brown);">No assignment yet</div>
                                    <div style="font-size:0.7rem;color:var(--gray);">Assign later from Appointments panel</div>
                                </div>
                            </div>
                            <?php foreach ($all_therapists_list as $th):
                                $is_checked_out = !empty($th['time_out']);
                                $is_on_break    = !empty($th['is_on_break']);
                                $is_on_duty     = !empty($th['on_duty_today']);
                            ?>
                            <div class="therapist-pick-btn"
                                 id="tbtn-<?php echo $th['id']; ?>"
                                 data-therapist-id="<?php echo $th['id']; ?>"
                                 data-on-duty="<?php echo $is_on_duty ? '1' : '0'; ?>"
                                 data-checked-out="<?php echo $is_checked_out ? '1' : '0'; ?>"
                                 data-on-break="<?php echo $is_on_break ? '1' : '0'; ?>"
                                 data-has-appt-today="<?php echo in_array($th['id'], $has_appt_today_ids) ? '1' : '0'; ?>"
                                 onclick="selectWalkinTherapist(<?php echo $th['id']; ?>)"
                                 style="display:flex;align-items:center;gap:0.65rem;padding:0.55rem 0.75rem;border:2px solid var(--border2);background:var(--bg3);border-radius:9px;cursor:pointer;transition:all .15s;">
                                <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--rust));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:0.82rem;">
                                    <?php echo strtoupper(substr($th['full_name'],0,1)); ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:0.82rem;font-weight:700;color:var(--brown);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo htmlspecialchars($th['full_name']); ?>
                                        <?php if (!$is_on_duty): ?><span style="font-size:0.65rem;background:rgba(107,114,128,0.12);color:#6b7280;padding:0.1rem 0.4rem;border-radius:20px;margin-left:0.3rem;">Not checked in</span><?php endif; ?>
                                    </div>
                                    <div class="th-status-<?php echo $th['id']; ?>" style="font-size:0.7rem;color:var(--gray);">
                                        <?php echo htmlspecialchars($th['specialties'] ?: 'General'); ?>
                                        &nbsp;·&nbsp;
                                        <span style="color:<?php echo $is_checked_out ? '#9ca3af' : ($is_on_break ? '#f59e0b' : '#22c55e'); ?>;font-weight:600;">
                                            <?php echo $is_checked_out ? 'Checked out' : ($is_on_break ? '☕ On break' : ($is_on_duty ? '✅ On duty' : '⚪ Not yet in')); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">🏷️ Rate Type <span class="required">*</span></div>
                    <div class="form-section-body">
                        <input type="hidden" name="rate_type"  id="rate_type_val"  value="regular">
                        <input type="hidden" name="partner_id" id="partner_id_val" value="0">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.85rem;">
                            <div class="rate-type-btn active" id="rtbtn-regular" onclick="selectRateType('regular')"><span style="font-size:1.1rem;">🟢</span><span style="font-weight:700;font-size:0.82rem;">Regular</span><span style="font-size:0.7rem;opacity:0.75;">Standard price</span></div>
                            <div class="rate-type-btn" id="rtbtn-home" onclick="selectRateType('home')"><span style="font-size:1.1rem;">🏠</span><span style="font-weight:700;font-size:0.82rem;">Home Service</span><span style="font-size:0.7rem;opacity:0.75;">2× + ₱300</span></div>
                            <div class="rate-type-btn" id="rtbtn-hotel" onclick="selectRateType('hotel')"><span style="font-size:1.1rem;">🏨</span><span style="font-weight:700;font-size:0.82rem;">Hotel / Partner</span><span style="font-size:0.7rem;opacity:0.75;">Partner rate</span></div>
                            <div class="rate-type-btn" id="rtbtn-influencer" onclick="selectRateType('influencer')"><span style="font-size:1.1rem;">🌟</span><span style="font-weight:700;font-size:0.82rem;">Influencer / PR</span><span style="font-size:0.7rem;opacity:0.75;">Free — ₱0</span></div>
                        </div>
                        <div id="partner-select-block" style="display:none;margin-bottom:0.85rem;">
                            <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:4px;font-weight:600;">Select Partner <span class="required">*</span></label>
                            <select id="partner_select_ui" onchange="onPartnerChange(this.value)" style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;">
                                <option value="">— Select partner —</option>
                                <?php foreach ($all_partners as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo ['hotel'=>'🏨','corporate'=>'🏢','other'=>'🤝'][$p['type']] ?? '🤝'; ?> <?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($all_partners)): ?><option value="" disabled>No active partners</option><?php endif; ?>
                            </select>
                        </div>
                        <div style="margin-bottom:0.85rem;">
                            <label style="font-size:0.78rem;color:var(--gray);display:block;margin-bottom:4px;font-weight:600;">Customer Notes / Requests</label>
                            <textarea name="customer_note" style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border2);border-radius:8px;background:var(--bg3);color:var(--brown);font-size:0.85rem;resize:vertical;min-height:55px;box-sizing:border-box;" placeholder="e.g. Female therapist, light pressure, VIP guest…"></textarea>
                        </div>
                        <div id="price-preview" style="background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:0.75rem 1rem;display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-size:0.72rem;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Charged to Customer</div>
                                <div style="font-size:0.75rem;color:var(--gray);" id="price-formula">Regular price</div>
                            </div>
                            <div style="font-size:1.4rem;font-weight:800;color:var(--gold);" id="price-display">₱0.00</div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">🎟️ Discount / Voucher</div>
                    <div class="form-section-body">
                        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.4rem;margin-bottom:0.75rem;">
                            <button type="button" id="svc-discbtn-none" onclick="setWalkinDiscount('service','none')" style="padding:0.5rem 0.3rem;border:2px solid var(--gold);border-radius:8px;background:#fff8f2;cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🚫 None</button>
                            <button type="button" id="svc-discbtn-voucher" onclick="setWalkinDiscount('service','voucher')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🎟️ Voucher</button>
                            <button type="button" id="svc-discbtn-senior" onclick="setWalkinDiscount('service','senior')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">👴 Senior<br><small style="font-weight:400;">20% off</small></button>
                            <button type="button" id="svc-discbtn-pwd" onclick="setWalkinDiscount('service','pwd')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">♿ PWD<br><small style="font-weight:400;">20% off</small></button>
                            <button type="button" id="svc-discbtn-employee" onclick="setWalkinDiscount('service','employee')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🪪 Staff<br><small style="font-weight:400;">50% off</small></button>
                        </div>
                        <div id="svc-voucher-inputs" style="display:none;background:#fef9f0;border:1px solid #f59e0b;border-radius:8px;padding:0.75rem;margin-bottom:0.5rem;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                                <div>
                                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;font-weight:600;">Voucher Type</label>
                                    <select id="svc-voucher-type" name="voucher_type" onchange="updateWalkinPreview('service')" style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:7px;font-size:0.82rem;background:var(--bg3);">
                                        <option value="cash">💵 Cash Off (₱)</option>
                                        <option value="percent">% Percentage Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;font-weight:600;">Amount</label>
                                    <input type="number" id="svc-voucher-amount" name="voucher_amount" min="0" step="0.01" placeholder="0.00" value="0" oninput="updateWalkinPreview('service')" style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:7px;font-size:0.82rem;box-sizing:border-box;background:var(--bg3);">
                                </div>
                            </div>
                        </div>
                        <div id="svc-discount-preview" style="display:none;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.3);border-radius:8px;padding:0.5rem 0.75rem;font-size:0.8rem;color:#15803d;margin-bottom:0.5rem;"></div>
                        <input type="hidden" name="discount_type" id="svc-discount-type" value="none">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">💳 Payment Method <span class="required">*</span></div>
                    <div class="form-section-body">
                        <div id="svc-pm-container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(70px, 1fr));gap:0.4rem;margin-bottom:0.75rem;">
                            <?php
                            $pm_wk = [
                                'cash'  => ['💵', 'Cash'],
                                'gcash' => ['📱', 'GCash'],
                                'maya'  => ['💜', 'Maya'],
                                'qrph'  => ['📷', 'QR Ph'],
                                'card'  => ['💳', 'Card'],
                                'bank'  => ['🏦', 'Bank'],
                            ];
                            foreach ($pm_wk as $pmv => $pmi):
                                if (in_array($pmv, ['gcash','maya']) && !SHOW_GCASH_MAYA) continue;
                            ?>
                            <label style="display:flex;align-items:center;gap:0.4rem;
                                          padding:0.45rem 0.6rem;border:1.5px solid var(--border2);
                                          border-radius:8px;cursor:pointer;font-size:0.82rem;
                                          transition:border-color 0.15s,background 0.15s;"
                                   id="pm-label-svc-<?php echo $pmv; ?>">
                                <input type="radio" name="svc_pm_choice"
                                       value="<?php echo $pmv; ?>"
                                       onchange="document.getElementById('service_payment_method').value=this.value; highlightPM(document.getElementById('svc-pm-container'))"
                                       <?php echo $pmv === 'cash' ? 'checked' : ''; ?>
                                       style="accent-color:var(--brown);">
                                <?php echo $pmi[0]; ?> <?php echo $pmi[1]; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div id="svc-online-status" style="display:none;margin-bottom:0.5rem;padding:0.55rem 0.75rem;border-radius:8px;font-size:0.8rem;font-weight:600;"></div>
                        <button type="button" id="svc-book-now" onclick="proceedBooking('service')" style="width:100%;margin-top:0.25rem;padding:0.8rem 1rem;background:var(--gold);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;letter-spacing:0.03em;transition:opacity 0.15s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">✅ Book Now</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="kiosk-panel" id="tab-product">
    <form method="POST" id="productForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="walkin_order"   value="1">
        <input type="hidden" name="order_type"     value="product">
        <input type="hidden" name="item_id"        id="product_item_id" value="">
        <input type="hidden" name="payment_method" id="product_payment_method" value="cash">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
            <div>
                <div class="form-section">
                    <div class="form-section-header">👤 Customer Information</div>
                    <div class="form-section-body">
                        <div class="form-grid-2">
                            <div class="form-group"><label>Full Name <span class="required">*</span></label><input type="text" name="customer_name" placeholder="Enter full name" required></div>
                            <div class="form-group"><label>Phone Number <span class="required">*</span></label><input type="tel" name="phone" placeholder="09XXXXXXXXX" required></div>
                        </div>
                        <div class="form-group" style="margin-top:0.5rem;">
                            <label>Service Slip No.</label>
                            <input type="text" name="slip_number" id="product_slip_number" placeholder="e.g. 1108-2026" style="width:180px;letter-spacing:0.05em;" oninput="syncSlipNumber('product',this.value)">
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <div class="form-section-header">🛍️ Select Product <span class="required">*</span></div>
                    <div class="form-section-body" style="padding:1rem;">
                        <div class="item-select-grid" id="product-grid">
                            <?php foreach ($all_products as $prd): ?>
                            <div class="item-card" onclick="selectItem('product', <?php echo $prd['id']; ?>, this)" data-id="<?php echo $prd['id']; ?>">
                                <div class="item-name"><?php echo htmlspecialchars($prd['name']); ?></div>
                                <div class="item-price">₱<?php echo number_format($prd['price'], 2); ?></div>
                                <span class="slots-info">📦 <?php echo $prd['stock']; ?> left</span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_products)): ?><p style="color:var(--gray);font-size:0.82rem;padding:1rem;grid-column:1/-1;">No products available.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <div class="form-section">
                    <div class="form-section-header">📦 Order Details</div>
                    <div class="form-section-body">
                        <div class="form-group"><label>Quantity <span class="required">*</span></label><input type="number" name="quantity" id="product_quantity" value="1" min="1" required style="max-width:140px;"></div>
                    </div>
                </div>
                <div class="form-section">
                    <div class="form-section-header">🎟️ Discount / Voucher</div>
                    <div class="form-section-body">
                        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.4rem;margin-bottom:0.75rem;">
                            <button type="button" id="prod-discbtn-none" onclick="setWalkinDiscount('product','none')" style="padding:0.5rem 0.3rem;border:2px solid var(--gold);border-radius:8px;background:#fff8f2;cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🚫 None</button>
                            <button type="button" id="prod-discbtn-voucher" onclick="setWalkinDiscount('product','voucher')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🎟️ Voucher</button>
                            <button type="button" id="prod-discbtn-senior" onclick="setWalkinDiscount('product','senior')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">👴 Senior<br><small style="font-weight:400;">20% off</small></button>
                            <button type="button" id="prod-discbtn-pwd" onclick="setWalkinDiscount('product','pwd')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">♿ PWD<br><small style="font-weight:400;">20% off</small></button>
                            <button type="button" id="prod-discbtn-employee" onclick="setWalkinDiscount('product','employee')" style="padding:0.5rem 0.3rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.75rem;font-weight:600;">🪪 Staff<br><small style="font-weight:400;">50% off</small></button>
                        </div>
                        <div id="prod-voucher-inputs" style="display:none;background:#fef9f0;border:1px solid #f59e0b;border-radius:8px;padding:0.75rem;margin-bottom:0.5rem;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                                <div>
                                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;font-weight:600;">Voucher Type</label>
                                    <select id="prod-voucher-type" name="voucher_type" onchange="updateWalkinPreview('product')" style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:7px;font-size:0.82rem;background:var(--bg3);">
                                        <option value="cash">💵 Cash Off (₱)</option>
                                        <option value="percent">% Percentage Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;font-weight:600;">Amount</label>
                                    <input type="number" id="prod-voucher-amount" name="voucher_amount" min="0" step="0.01" placeholder="0.00" value="0" oninput="updateWalkinPreview('product')" style="width:100%;padding:0.45rem 0.6rem;border:1px solid var(--border2);border-radius:7px;font-size:0.82rem;box-sizing:border-box;background:var(--bg3);">
                                </div>
                            </div>
                        </div>
                        <div id="prod-discount-preview" style="display:none;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.3);border-radius:8px;padding:0.5rem 0.75rem;font-size:0.8rem;color:#15803d;margin-bottom:0.5rem;"></div>
                        <input type="hidden" name="discount_type" id="prod-discount-type" value="none">
                    </div>
                </div>
                <div class="form-section">
                    <div class="form-section-header">💳 Payment Method <span class="required">*</span></div>
                    <div class="form-section-body">
                        <div id="prod-pm-container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(70px, 1fr));gap:0.4rem;margin-bottom:0.75rem;">
                            <?php foreach ($pm_wk as $pmv => $pmi):
                                if (in_array($pmv, ['gcash','maya']) && !SHOW_GCASH_MAYA) continue; ?>
                            <label style="display:flex;align-items:center;gap:0.4rem;
                                          padding:0.45rem 0.6rem;border:1.5px solid var(--border2);
                                          border-radius:8px;cursor:pointer;font-size:0.82rem;
                                          transition:border-color 0.15s,background 0.15s;"
                                   id="pm-label-prod-<?php echo $pmv; ?>">
                                <input type="radio" name="prod_pm_choice"
                                       value="<?php echo $pmv; ?>"
                                       onchange="document.getElementById('product_payment_method').value=this.value; highlightPM(document.getElementById('prod-pm-container'))"
                                       <?php echo $pmv === 'cash' ? 'checked' : ''; ?>
                                       style="accent-color:var(--brown);">
                                <?php echo $pmi[0]; ?> <?php echo $pmi[1]; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div id="prod-online-status" style="display:none;margin-bottom:0.5rem;padding:0.55rem 0.75rem;border-radius:8px;font-size:0.8rem;font-weight:600;"></div>
                        <button type="button" id="prod-book-now" onclick="proceedBooking('product')" style="width:100%;margin-top:0.25rem;padding:0.8rem 1rem;background:var(--gold);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;letter-spacing:0.03em;transition:opacity 0.15s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">✅ Confirm Order</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
.pay-method-btn { padding:0.55rem 0.4rem;border:2px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;text-align:center;font-size:0.78rem;font-weight:600;color:var(--brown);font-family:var(--font-body);transition:all .15s; }
.pay-method-btn:hover { border-color:var(--gold);background:#fff8f2; }
.pay-method-btn.active-pay { border-color:var(--gold);background:#fff8f2;color:var(--rust); }
.rate-type-btn { display:flex;flex-direction:column;align-items:center;gap:0.2rem;padding:0.7rem 0.5rem;border:2px solid var(--border2);border-radius:10px;background:var(--bg3);cursor:pointer;text-align:center;transition:all .15s;color:var(--brown); }
.rate-type-btn:hover { border-color:var(--gold); }
.rate-type-btn.active { border-color:var(--gold);background:linear-gradient(135deg,var(--gold),var(--rust));color:#fff; }
</style>

<script>
const serviceData    = <?php echo json_encode(array_column($all_services, null, 'id')); ?>;
const partnerRates   = <?php echo json_encode($partner_rates_map); ?>;
const therapistBusySlots   = <?php echo json_encode($therapist_busy_slots); ?>;
const servicesSessionMap   = <?php echo json_encode($services_session_map); ?>;
const therapistSpecialtySvcMap = <?php echo json_encode($therapist_specialty_svc_map); ?>;
const therapistIsGeneralist    = <?php echo json_encode((object)$therapist_generalist_map); ?>;

let currentServiceId   = null;
let currentRateType    = 'regular';
let currentPartnerId   = 0;
let walkinAvailBlocked = false;

const CLOSING_HOUR = 22; // 10 PM — last slot must end by this hour

function findNextAvailableTime(tids, fromTs, sessionSecs, bufferSecs) {
    const d = new Date(fromTs * 1000);
    const closingTs = new Date(d.getFullYear(), d.getMonth(), d.getDate(),
                               CLOSING_HOUR, 0, 0).getTime() / 1000;
    let earliest = null;
    for (const tid of tids) {
        const slots = (therapistBusySlots[tid] || []).slice().sort((a, b) => a.start - b.start);
        let candidate = fromTs;
        for (;;) {
            const wStart = candidate - bufferSecs;
            const wEnd   = candidate + sessionSecs + bufferSecs;
            if (wEnd > closingTs) break;
            const hit = slots.find(s => s.start < wEnd && s.end > wStart);
            if (!hit) { if (earliest === null || candidate < earliest) earliest = candidate; break; }
            candidate = hit.end + bufferSecs;
        }
    }
    return earliest; // null = nothing fits before closing
}

function switchTab(type, el) {
    document.querySelectorAll('.kiosk-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.kiosk-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + type).classList.add('active');
    el.classList.add('active');
}

function selectItem(type, id, el) {
    el.closest('.item-select-grid').querySelectorAll('.item-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById(type + '_item_id').value = id;
    if (type === 'service') {
        currentServiceId = id;
        selectWalkinTherapist(0); // clear stale therapist when service changes
        updatePricePreview();
        updateTherapistMode();
    }
    if (type === 'product') {
        const stock = parseInt(el.querySelector('.slots-info').textContent.replace(/\D/g,''));
        document.getElementById('product_quantity').max = stock;
    }
}

function selectRateType(type) {
    currentRateType = type;
    document.getElementById('rate_type_val').value = type;
    ['regular','home','hotel','influencer'].forEach(t => document.getElementById('rtbtn-' + t)?.classList.toggle('active', t === type));
    const partnerBlock = document.getElementById('partner-select-block');
    if (type === 'hotel') { partnerBlock.style.display = ''; }
    else { partnerBlock.style.display = 'none'; currentPartnerId = 0; document.getElementById('partner_id_val').value = '0'; const sel = document.getElementById('partner_select_ui'); if (sel) sel.value = ''; }
    updatePricePreview();
    updateTherapistMode();
}

function selectWalkinTherapist(id) {
    document.getElementById('svc_therapist_id').value = id;
    document.querySelectorAll('.therapist-pick-btn').forEach(btn => {
        const isSelected = btn.id === 'tbtn-' + id;
        btn.style.borderColor = isSelected ? 'var(--gold)' : 'var(--border2)';
        btn.style.background  = isSelected ? '#fff8f2'     : 'var(--bg3)';
    });
}

function updateTherapistMode() {
    const bookingInput = document.getElementById('service_booking_date');
    const peopleInput  = document.querySelector('[name="people_count"]');
    const btnList      = document.getElementById('therapist-btn-list');
    const noSvcNotice  = document.getElementById('therapist-no-svc-notice');
    const todayBlock   = document.getElementById('therapist-today-block');
    const warnEl       = document.getElementById('people-count-warning');
    const badge        = document.getElementById('therapist-mode-badge');
    const svcId        = currentServiceId ? parseInt(currentServiceId) : 0;

    // ── No service selected → dim picker, show hint ──────────────────────
    if (!svcId) {
        walkinAvailBlocked = false;
        if (noSvcNotice) noSvcNotice.style.display = '';
        if (todayBlock)  todayBlock.style.display   = 'none';
        if (warnEl)      warnEl.style.display        = 'none';
        if (badge)       badge.textContent            = '';
        if (btnList) { btnList.style.opacity = '0.35'; btnList.style.pointerEvents = 'none'; }
        return;
    }
    if (noSvcNotice) noSvcNotice.style.display = 'none';
    if (btnList) { btnList.style.opacity = ''; btnList.style.pointerEvents = ''; }

    // ── Apply specialty filter even without a date selected ──────────────
    if (!bookingInput || !bookingInput.value) {
        walkinAvailBlocked = false;
        if (todayBlock) todayBlock.style.display = 'none';
        if (warnEl)     warnEl.style.display     = 'none';
        if (badge)      badge.textContent          = '';
        document.querySelectorAll('.therapist-pick-btn[data-therapist-id]').forEach(btn => {
            const tid  = parseInt(btn.dataset.therapistId);
            const mySpec = therapistSpecialtySvcMap[tid];
            const ok   = !svcId ? true
                       : (therapistIsGeneralist[tid] === true)
                         || (Array.isArray(mySpec) && mySpec.includes(svcId));
            btn.style.display       = 'flex';
            btn.style.opacity       = ok ? '1'       : '0.25';
            btn.style.cursor        = ok ? 'pointer' : 'not-allowed';
            btn.style.pointerEvents = ok ? 'auto'    : 'none';
            btn.title               = ok ? ''        : '❌ Not qualified for this service';
        });
        return;
    }

    const bookingTs   = new Date(bookingInput.value).getTime() / 1000;
    const nowTs       = Date.now() / 1000;
    const isFuture    = (bookingTs - nowTs) > 1800;
    const isHome      = currentRateType === 'home';
    const buffer      = isHome ? 30 * 60 : 0;
    const sessionTime = (servicesSessionMap[currentServiceId] || 60) * 60;
    const newStart    = bookingTs - buffer;
    const newEnd      = bookingTs + sessionTime + buffer;
    const peopleCount = parseInt(peopleInput?.value || 1);

    if (badge) {
        badge.textContent = isFuture ? '📅 Future booking — therapist optional' : '⚡ Immediate — assign therapist now';
        badge.style.color = isFuture ? '#0891b2' : '#15803d';
    }

    let qualifiedFree = 0, qualifiedOnDutyCount = 0, qualifiedOnDutyTids = [];

    document.querySelectorAll('.therapist-pick-btn[data-therapist-id]').forEach(btn => {
        const tid               = parseInt(btn.dataset.therapistId);
        const isCheckedOut      = btn.dataset.checkedOut === '1';
        const onDuty            = btn.dataset.onDuty === '1';
        const hasApptToday      = btn.dataset.hasApptToday === '1';
        // Treat as present if clocked in OR has a confirmed appointment today
        const effectivelyPresent = onDuty || hasApptToday;
        const busy              = therapistBusySlots[tid] || [];
        const hasConflict       = busy.some(s => s.start < newEnd && s.end > newStart);
        const shouldHide        = !isFuture && !effectivelyPresent;
        const mySpec            = therapistSpecialtySvcMap[tid];
        const hasSpecialty      = !svcId ? true
                                : (therapistIsGeneralist[tid] === true)
                                  || (Array.isArray(mySpec) && mySpec.includes(svcId));

        btn.style.display = shouldHide ? 'none' : 'flex';

        if (!hasSpecialty || hasConflict || isCheckedOut) {
            btn.style.opacity       = hasSpecialty ? '0.4' : '0.25';
            btn.style.cursor        = 'not-allowed';
            btn.style.pointerEvents = 'none';
            btn.title = !hasSpecialty ? '❌ Not qualified for this service'
                      : hasConflict   ? '⚠️ Busy at this time'
                                      : '🏁 Checked out';
        } else {
            btn.style.opacity       = '1';
            btn.style.cursor        = 'pointer';
            btn.style.pointerEvents = 'auto';
            btn.title               = '';
            if (!isFuture || effectivelyPresent) qualifiedFree++;
        }
        if (hasSpecialty && effectivelyPresent && !isCheckedOut) {
            qualifiedOnDutyTids.push(tid);
            qualifiedOnDutyCount++;
        }
    });

    // ── Today availability (Feature A + B-1) ─────────────────────────────
    if (!isFuture) {
        if (qualifiedOnDutyCount === 0) {
            // Feature A: no qualified therapist on duty at all
            walkinAvailBlocked = true;
            if (todayBlock) {
                todayBlock.style.display = '';
                todayBlock.innerHTML = '⛔ No qualified therapist is on duty for this service. '
                    + '<strong>Please change the date to a future booking</strong>, '
                    + 'or wait until a qualified therapist checks in.';
            }
            if (warnEl) warnEl.style.display = 'none';
        } else if (qualifiedFree === 0) {
            // Feature B-1: qualified on duty but none free at the picked time
            walkinAvailBlocked = true;
            const nextTs = findNextAvailableTime(qualifiedOnDutyTids, bookingTs, sessionTime, buffer);
            const pickedLabel = new Date(bookingInput.value)
                .toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit'});
            let msg;
            if (nextTs === null) {
                msg = '⛔ No availability left today for this service — please choose a future date.';
            } else {
                const nextLabel = new Date(nextTs * 1000)
                    .toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit'});
                msg = `⛔ No qualified therapist free at <strong>${pickedLabel}</strong>. `
                    + `Next available: <strong>${nextLabel}</strong>.`;
            }
            if (todayBlock) { todayBlock.style.display = ''; todayBlock.innerHTML = msg; }
            if (warnEl) warnEl.style.display = 'none';
        } else {
            // At least one free — allow booking
            walkinAvailBlocked = false;
            if (todayBlock) todayBlock.style.display = 'none';
            if (warnEl && peopleCount > qualifiedFree) {
                const t = new Date(bookingInput.value)
                    .toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit'});
                warnEl.style.display = 'block';
                warnEl.innerHTML = `⚠️ <strong>${peopleCount} people</strong> requested but only `
                    + `<strong>${qualifiedFree} qualified therapist(s)</strong> available at ${t}.`;
            } else if (warnEl) { warnEl.style.display = 'none'; }
        }
    } else {
        // Future booking — no time-availability block
        walkinAvailBlocked = false;
        if (todayBlock) todayBlock.style.display = 'none';
        if (warnEl && peopleCount > qualifiedFree && qualifiedFree > 0) {
            const t = new Date(bookingInput.value)
                .toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit'});
            warnEl.style.display = 'block';
            warnEl.innerHTML = `⚠️ <strong>${peopleCount} people</strong> requested but only `
                + `<strong>${qualifiedFree} qualified therapist(s)</strong> available at ${t}.`;
        } else if (warnEl) { warnEl.style.display = 'none'; }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const bi = document.getElementById('service_booking_date');
    if (bi) { bi.addEventListener('change', updateTherapistMode); bi.addEventListener('input', updateTherapistMode); }
    const pi = document.querySelector('[name="people_count"]');
    if (pi) pi.addEventListener('change', updateTherapistMode);
});

function onPartnerChange(partnerId) { currentPartnerId = parseInt(partnerId) || 0; document.getElementById('partner_id_val').value = currentPartnerId; updatePricePreview(); }

function updatePricePreview() {
    const svc = serviceData[currentServiceId]; const display = document.getElementById('price-display'); const formula = document.getElementById('price-formula');
    if (!display || !formula) return;
    if (!svc) { display.textContent = '₱0.00'; formula.textContent = 'Select a service first'; return; }
    const regular = parseFloat(svc.price); let charged = regular; let formulaTxt = '';
    switch (currentRateType) {
        case 'regular':    charged = regular; formulaTxt = 'Regular price'; break;
        case 'home':       charged = (regular * 2) + 300; formulaTxt = `(₱${regular.toFixed(2)} × 2) + ₱300`; break;
        case 'hotel':      if (currentPartnerId > 0 && partnerRates[currentPartnerId]?.[currentServiceId]) { charged = parseFloat(partnerRates[currentPartnerId][currentServiceId]); formulaTxt = 'Partner rate'; } else { charged = regular; formulaTxt = currentPartnerId > 0 ? '⚠️ No rate set — using regular price' : 'Select a partner'; } break;
        case 'influencer': charged = 0; formulaTxt = 'Complimentary — ₱0'; break;
    }
    display.textContent = '₱' + charged.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    formula.textContent = formulaTxt;
    display.style.color = currentRateType === 'influencer' ? 'var(--green)' : 'var(--gold)';
}

function highlightPM(container) {
    container.querySelectorAll('[id^="pm-label-"]').forEach(function(lbl) {
        var radio = lbl.querySelector('input[type="radio"]');
        lbl.style.borderColor = radio.checked ? 'var(--brown)' : 'var(--border2)';
        lbl.style.background  = radio.checked ? 'var(--bg3)'   : '';
        lbl.style.fontWeight  = radio.checked ? '700'          : '400';
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var svcC = document.getElementById('svc-pm-container');
    var prdC = document.getElementById('prod-pm-container');
    if (svcC) highlightPM(svcC);
    if (prdC) highlightPM(prdC);
});

function selectPayment(formType, method, btn) {
    document.getElementById(formType + '_payment_method').value = method;
    const prefix = formType === 'service' ? 'svc' : 'prod';
    document.querySelectorAll('[id^="' + prefix + '-pay-"]').forEach(b => b.classList.remove('active-pay'));
    btn.classList.add('active-pay');
    const labels = {cash:'💵 Cash',gcash:'📱 GCash',paymaya:'💜 Maya',qrph:'📷 QRPH',bank:'🏦 Bank Transfer',card:'💳 Card'};
    const el = document.getElementById(prefix + '-pay-selected');
    if (el) el.textContent = 'Selected: ' + (labels[method] || method);
    const statusEl = document.getElementById(prefix + '-online-status');
    if (statusEl) statusEl.style.display = 'none';
}

function proceedBooking(formType) {
    const method = document.getElementById(formType + '_payment_method').value;
    const onlineMethods = ['gcash', 'maya', 'card'];
    if (onlineMethods.includes(method)) { openPaymongoPopup(formType, method); return; }
    if (method === 'qrph') { openQrphFlow(formType); return; }
    const form = document.getElementById(formType === 'service' ? 'serviceForm' : 'productForm');
    if (!form) return;
    const itemId = form.querySelector('[name="item_id"]')?.value;
    if (!itemId) { alert('Please select a ' + (formType === 'service' ? 'service' : 'product') + ' first.'); return; }
    if (formType === 'service') {
        const bookingDate = form.querySelector('[name="booking_date"]')?.value;
        if (!bookingDate) { alert('Please select a booking date first.'); return; }
        if (document.getElementById('rate_type_val')?.value === 'hotel' && parseInt(document.getElementById('partner_id_val')?.value || 0) === 0) { alert('Please select a hotel/partner for the Hotel rate.'); return; }
        if (walkinAvailBlocked) {
            alert('⛔ Cannot book: no qualified therapist is available at the selected time. Please adjust the booking time or choose a future date.');
            return;
        }
    }
    form.submit();
}

function syncSlipNumber(form, val) { const other = form === 'service' ? 'product_slip_number' : 'service_slip_number'; const el = document.getElementById(other); if (el) el.value = val; }

function openPaymongoPopup(formType, method) {
    const form = document.getElementById(formType === 'service' ? 'serviceForm' : 'productForm');
    if (!form) return;
    const itemId = form.querySelector('[name="item_id"]')?.value;
    if (!itemId) { alert('Please select a ' + (formType === 'service' ? 'service' : 'product') + ' first.'); return; }
    const name  = form.querySelector('[name="customer_name"]')?.value?.trim();
    const phone = form.querySelector('[name="phone"]')?.value?.trim();
    if (!name || !phone) { alert('Please fill in customer name and phone number first.'); return; }
    if (formType === 'service') {
        if (!form.querySelector('[name="booking_date"]')?.value) { alert('Please select a booking date first.'); return; }
        if (document.getElementById('rate_type_val')?.value === 'hotel' && parseInt(document.getElementById('partner_id_val')?.value || 0) === 0) { alert('Please select a hotel/partner for the Hotel rate.'); return; }
    }
    const prefix = formType === 'service' ? 'svc' : 'prod';
    const statusEl = document.getElementById(prefix + '-online-status');
    if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(234,179,8,0.1)'; statusEl.style.border='1px solid rgba(234,179,8,0.4)'; statusEl.style.color='#92400e'; statusEl.textContent='⏳ Creating order…'; }
    const fd = new FormData(form);
    fd.set('payment_method', method);
    fd.set('otp_mode', '1');
    const payUrl = '<?php echo rtrim(BASE_URL, '/') . '/admin/walkin_payment.php'; ?>';
    fetch(payUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.text().then(t => ({ status: r.status, text: t })))
        .then(({ status, text }) => {
            let data; try { data = JSON.parse(text); } catch(e) { if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(220,53,69,0.08)'; statusEl.style.border='1px solid rgba(220,53,69,0.3)'; statusEl.style.color='#b91c1c'; statusEl.innerHTML='❌ Server error (HTTP '+status+'):<br><small style="font-family:monospace;word-break:break-all;">'+text.replace(/</g,'&lt;').substring(0,400)+'</small>'; } return; }
            if (!data.success) { if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(220,53,69,0.08)'; statusEl.style.border='1px solid rgba(220,53,69,0.3)'; statusEl.style.color='#b91c1c'; statusEl.textContent='❌ '+(data.error||'Payment setup failed.'); } return; }
            if (statusEl) statusEl.style.display = 'none';
            // Open OTP/intent modal instead of a popup window
            openPaymentModal(data.order_id, method, data.final_amount || 0, phone, name);
        })
        .catch(err => { if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(220,53,69,0.08)'; statusEl.style.border='1px solid rgba(220,53,69,0.3)'; statusEl.style.color='#b91c1c'; statusEl.textContent='❌ '+err.message; } });
}

window.addEventListener('message', function(event) {
    if (!event.data || !event.data.type) return;
    if (event.data.type === 'walkin_payment_success') {
        const banner = document.createElement('div');
        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;background:#15803d;color:#fff;text-align:center;padding:1rem 1.5rem;font-size:1rem;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,0.2);';
        banner.textContent = '✅ Payment confirmed! Order #'+event.data.order_id+' — '+event.data.customer+' — '+event.data.amount+' via '+event.data.method+' · Reloading…';
        document.body.prepend(banner);
        setTimeout(() => location.reload(), 2000);
    }
    if (event.data.type === 'walkin_payment_cancelled') {
        ['svc-online-status','prod-online-status'].forEach(id => { const el = document.getElementById(id); if (el) { el.style.display='block'; el.style.background='rgba(220,53,69,0.08)'; el.style.border='1px solid rgba(220,53,69,0.3)'; el.style.color='#b91c1c'; el.textContent='⚠️ Payment was cancelled. Order removed.'; } });
    }
});

document.getElementById('serviceForm').addEventListener('submit', function(e) {
    if (!document.getElementById('service_item_id').value) { e.preventDefault(); alert('Please select a service first.'); return; }
    if (currentRateType === 'hotel' && currentPartnerId === 0) { e.preventDefault(); alert('Please select a hotel/partner for the Hotel rate.'); return; }
});
document.getElementById('productForm').addEventListener('submit', function(e) {
    if (!document.getElementById('product_item_id').value) { e.preventDefault(); alert('Please select a product first.'); }
});

const now = new Date(); const pad = n => String(n).padStart(2,'0');
const minDate = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
document.getElementById('service_booking_date').min = minDate;

(function() {
    const year = now.getFullYear();
    const suggested = <?php $last = $conn->query("SELECT slip_number FROM orders WHERE slip_number IS NOT NULL AND slip_number != '' ORDER BY id DESC LIMIT 1")->fetch_assoc(); $last_num = 1000; if ($last && preg_match('/^(\d+)-\d{4}$/', $last['slip_number'], $m)) { $last_num = intval($m[1]); } echo ($last_num + 1); ?>;
    const val = suggested + '-' + year;
    const sf = document.getElementById('service_slip_number'); const pf = document.getElementById('product_slip_number');
    if (sf && !sf.value) sf.value = val; if (pf && !pf.value) pf.value = val;
})();

function setWalkinDiscount(form, type) {
    const p = form === 'service' ? 'svc' : 'prod';
    document.getElementById(p + '-discount-type').value = type;
    ['none','voucher','senior','pwd','employee'].forEach(t => { const btn = document.getElementById(p+'-discbtn-'+t); if (!btn) return; btn.style.borderColor = t===type ? 'var(--gold)' : 'var(--border2)'; btn.style.background = t===type ? '#fff8f2' : 'var(--bg3)'; });
    const vInputs = document.getElementById(p + '-voucher-inputs');
    if (vInputs) vInputs.style.display = type === 'voucher' ? 'block' : 'none';
    if (type !== 'voucher') { const va = document.getElementById(p+'-voucher-amount'); if (va) va.value = '0'; }
    updateWalkinPreview(form);
}

function updateWalkinPreview(form) {
    const p = form === 'service' ? 'svc' : 'prod';
    const type = document.getElementById(p+'-discount-type')?.value || 'none';
    const preview = document.getElementById(p+'-discount-preview');
    if (!preview) return;
    let basePrice = 0;
    if (form === 'service') { const priceText = document.getElementById('price-display')?.textContent || '0'; basePrice = parseFloat(priceText.replace(/[^0-9.]/g,'')) || 0; }
    else { const selCard = document.querySelector('#product-grid .item-card.selected'); if (selCard) { const priceEl = selCard.querySelector('.item-price'); basePrice = parseFloat((priceEl?.textContent||'0').replace(/[^0-9.]/g,''))||0; } const qty = parseInt(document.getElementById('product_quantity')?.value||1); basePrice *= qty; }
    if (type === 'none' || basePrice <= 0) { preview.style.display = 'none'; return; }
    let discountAmt = 0, label = '';
    if (type === 'senior') { discountAmt = basePrice * 0.20; label = '👴 Senior Citizen (20%)'; }
    else if (type === 'pwd') { discountAmt = basePrice * 0.20; label = '♿ PWD (20%)'; }
    else if (type === 'employee') { discountAmt = basePrice * 0.50; label = '🪪 Employee (50% off)'; }
    else if (type === 'voucher') { const vType = document.getElementById(p+'-voucher-type')?.value||'cash'; const vVal = parseFloat(document.getElementById(p+'-voucher-amount')?.value||0); if (vType === 'percent') { discountAmt = basePrice*(vVal/100); label='🎟️ Voucher ('+vVal+'% off)'; } else { discountAmt = Math.min(vVal,basePrice); label='🎟️ Voucher'; } }
    const finalPrice = Math.max(0, basePrice - discountAmt);
    if (discountAmt > 0) { preview.style.display='block'; const fmt = n => n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); preview.innerHTML = label+'<br>Original: <strong>₱'+fmt(basePrice)+'</strong> &nbsp;−&nbsp; <strong style="color:var(--rust);">₱'+fmt(discountAmt)+'</strong> &nbsp;=&nbsp; <strong style="color:#15803d;">₱'+fmt(finalPrice)+'</strong>'; }
    else { preview.style.display='none'; }
}

document.addEventListener('click', function(e) {
    if (e.target.closest('.rate-type-btn')) setTimeout(() => updateWalkinPreview('service'), 80);
    if (e.target.closest('#service-grid .item-card')) setTimeout(() => updateWalkinPreview('service'), 80);
    if (e.target.closest('#product-grid .item-card')) setTimeout(() => updateWalkinPreview('product'), 80);
});
document.getElementById('product_quantity')?.addEventListener('input', () => updateWalkinPreview('product'));
</script>

<!-- ── PayMongo OTP / Intent Payment Modal ─────────────────────────────────── -->
<div id="pmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:420px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.28);max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <span id="pmModalTitle" style="font-weight:800;font-size:1rem;color:#3B2A1A;">💳 Online Payment</span>
      <button onclick="closePmModal()" id="pmModalCloseBtn" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
    </div>
    <!-- Step 1: Input details -->
    <div id="pm-step-1">
      <div style="text-align:center;margin-bottom:1.1rem;">
        <div id="pm-method-icon" style="font-size:2.5rem;margin-bottom:0.4rem;"></div>
        <div style="font-weight:700;color:#3B2A1A;font-size:1.15rem;" id="pm-amount-display"></div>
        <div style="color:#6b7280;font-size:0.82rem;margin-top:3px;" id="pm-method-label"></div>
      </div>
      <div id="pm-step1-gcash-maya">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Mobile Number</label>
        <input type="tel" id="pm-phone-input" placeholder="09XXXXXXXXX"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.65rem;">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Customer Email <span style="color:red;">*</span></label>
        <input type="email" id="pm-email-input" placeholder="customer@email.com"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.35rem;">
        <p style="font-size:0.72rem;color:#6b7280;margin:0 0 0.85rem;">Required by PayMongo for digital payments.</p>
      </div>
      <div id="pm-step1-card" style="display:none;">
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Card Number</label>
        <input type="text" id="pm-card-number" placeholder="0000 0000 0000 0000" maxlength="19"
               oninput="formatCardNumber(this)"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.65rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;margin-bottom:0.65rem;">
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">Exp Month</label>
            <input type="number" id="pm-card-exp-month" min="1" max="12" placeholder="MM"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">Exp Year</label>
            <input type="number" id="pm-card-exp-year" min="2024" max="2040" placeholder="YYYY"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:0.72rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:3px;">CVV</label>
            <input type="password" id="pm-card-cvv" maxlength="4" placeholder="•••"
                   style="width:100%;padding:0.55rem;border:1.5px solid #EAD8C0;border-radius:7px;font-size:0.85rem;color:#3B2A1A;box-sizing:border-box;">
          </div>
        </div>
        <label style="font-size:0.78rem;font-weight:700;color:#3B2A1A;display:block;margin-bottom:4px;">Customer Email <span style="color:red;">*</span></label>
        <input type="email" id="pm-email-card" placeholder="customer@email.com"
               style="width:100%;padding:0.6rem;border:1.5px solid #EAD8C0;border-radius:8px;font-size:0.92rem;color:#3B2A1A;box-sizing:border-box;margin-bottom:0.85rem;">
      </div>
      <button onclick="pmSendOtp()" id="pmSendBtn"
              style="width:100%;padding:0.75rem;background:#C96A2C;color:#fff;border:none;border-radius:10px;font-size:0.95rem;font-weight:700;cursor:pointer;transition:opacity .15s;">
        ▶ Proceed to Payment
      </button>
      <div style="text-align:center;margin-top:0.6rem;">
        <button onclick="closePmModal()" style="background:none;border:none;color:#6b7280;font-size:0.82rem;cursor:pointer;text-decoration:underline;">Cancel</button>
      </div>
    </div>
    <!-- Step 2: Awaiting authorization (redirect) -->
    <div id="pm-step-2" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:2.5rem;margin-bottom:0.65rem;">⏳</div>
      <div style="font-weight:700;color:#3B2A1A;margin-bottom:0.5rem;">Awaiting Authorization</div>
      <div id="pm-step2-msg" style="color:#6b7280;font-size:0.85rem;line-height:1.5;margin-bottom:1rem;"></div>
      <a id="pm-redirect-link" href="#" target="_blank"
         style="display:inline-block;padding:0.65rem 1.25rem;background:#C96A2C;color:#fff;border-radius:9px;font-size:0.88rem;font-weight:700;text-decoration:none;margin-bottom:1rem;">
        Open Payment
      </a>
      <div style="font-size:0.75rem;color:#6b7280;margin-bottom:1rem;">
        Waiting for payment confirmation…<br>
        <span id="pm-poll-counter" style="font-weight:600;"></span>
      </div>
      <button onclick="pmBackToStep1()" style="width:100%;padding:0.6rem;background:transparent;border:1px solid #d1d5db;color:#6b7280;border-radius:9px;font-size:0.82rem;cursor:pointer;">
        ← Change Payment Details
      </button>
    </div>
    <!-- Step 3: Error -->
    <div id="pm-step-3" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:2.5rem;margin-bottom:0.65rem;">❌</div>
      <div style="font-weight:700;color:#b91c1c;margin-bottom:0.5rem;" id="pm-err-title">Payment Failed</div>
      <div style="color:#6b7280;font-size:0.85rem;" id="pm-err-msg"></div>
      <button onclick="pmBackToStep1()" style="width:100%;padding:0.6rem;background:transparent;border:1px solid #d1d5db;color:#6b7280;border-radius:9px;font-size:0.82rem;cursor:pointer;margin-top:1rem;">
        ← Try Again
      </button>
    </div>
    <!-- Step 4: Success -->
    <div id="pm-step-4" style="display:none;text-align:center;padding:0.5rem 0;">
      <div style="font-size:3rem;margin-bottom:0.65rem;">✅</div>
      <div style="font-weight:800;color:#15803d;font-size:1.1rem;margin-bottom:0.5rem;">Payment Confirmed!</div>
      <div id="pm-done-msg" style="color:#6b7280;font-size:0.85rem;"></div>
    </div>
    <!-- Error banner (shown inline within any step) -->
    <div id="pm-error-banner" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.65rem 0.85rem;margin-top:0.75rem;font-size:0.82rem;color:#b91c1c;"></div>
  </div>
</div>

<!-- ── QR Ph Payment Modal ──────────────────────────────────────────────────── -->
<div id="qrphModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:320px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.28);text-align:center;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <span style="font-weight:800;font-size:1rem;color:#3B2A1A;">📷 QR Ph Payment</span>
      <button onclick="closeQrphModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
    </div>
    <p style="font-size:0.82rem;color:#6b7280;margin-bottom:0.85rem;line-height:1.5;">
      Customer scans with any Philippine banking app<br>(GCash, Maya, BPI, BDO, UnionBank, etc.)
    </p>
    <div id="qrphImageContainer" style="margin:0 auto 0.85rem;width:280px;height:auto;min-height:180px;display:flex;align-items:center;justify-content:center;background:#f5f5f5;border-radius:10px;border:1px solid #EAD8C0;">
      <span style="color:#6b7280;font-size:0.82rem;">⏳ Generating…</span>
    </div>
    <div id="qrphAmount" style="font-size:1.3rem;font-weight:800;color:#3B2A1A;margin-bottom:0.4rem;"></div>
    <div id="qrphStatus" style="font-size:0.85rem;color:#C96A2C;margin-bottom:1rem;">⏳ Waiting for payment…</div>
    <button onclick="closeQrphModal()" style="padding:0.55rem 1.5rem;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#6b7280;font-size:0.85rem;cursor:pointer;font-family:inherit;">Cancel</button>
  </div>
</div>

<script>
var pmState = {
    intentId: '', pmId: '', clientKey: '', method: '', orderId: 0,
    amount: 0, pollTimer: null, pollCount: 0,
    addonForm: null, prefillPhone: '', prefillName: ''
};

function openPaymentModal(orderId, method, amount, phone, name) {
    pmState.orderId     = orderId;
    pmState.method      = method;
    pmState.amount      = parseFloat(amount) || 0;
    pmState.addonForm   = null;
    pmState.prefillPhone = phone || '';
    pmState.prefillName  = name  || '';
    pmInitModal();
}

function openAddonPaymentModal(form, method, amount) {
    pmState.orderId     = 0;
    pmState.method      = method;
    pmState.amount      = parseFloat(amount) || 0;
    pmState.addonForm   = form;
    pmState.prefillPhone = '';
    pmState.prefillName  = '';
    pmInitModal();
}

function pmInitModal() {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    pmState.intentId = ''; pmState.pmId = ''; pmState.clientKey = ''; pmState.pollCount = 0;
    var icons  = {gcash:'📱', maya:'💜', card:'💳'};
    var labels = {gcash:'GCash', maya:'Maya', card:'Credit/Debit Card'};
    document.getElementById('pm-method-icon').textContent    = icons[pmState.method]  || '💳';
    document.getElementById('pm-amount-display').textContent = '₱' + pmState.amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('pm-method-label').textContent   = labels[pmState.method] || pmState.method;
    var isCard = pmState.method === 'card';
    document.getElementById('pm-step1-gcash-maya').style.display = isCard ? 'none' : '';
    document.getElementById('pm-step1-card').style.display        = isCard ? ''     : 'none';
    var phoneEl = document.getElementById('pm-phone-input');
    if (phoneEl) phoneEl.value = pmState.prefillPhone || '';
    document.getElementById('pmModalCloseBtn').style.display = '';
    pmShowStep(1);
    document.getElementById('pmModal').style.display = 'flex';
}

function closePmModal() {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    document.getElementById('pmModal').style.display = 'none';
}

function pmShowStep(n) {
    [1,2,3,4].forEach(function(i) {
        var el = document.getElementById('pm-step-' + i);
        if (el) el.style.display = (i === n) ? '' : 'none';
    });
    document.getElementById('pm-error-banner').style.display = 'none';
}

function pmError(msg) {
    var el = document.getElementById('pm-error-banner');
    el.textContent = '⚠️ ' + msg;
    el.style.display = '';
    var btn = document.getElementById('pmSendBtn');
    if (btn) { btn.disabled = false; btn.textContent = '▶ Proceed to Payment'; }
}

function pmGetCsrf() {
    var el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
}

function pmCreateIntent(callback) {
    var amountCentavos = Math.round(pmState.amount * 100);
    var apiMethod = {gcash:'gcash', maya:'paymaya', card:'card'}[pmState.method] || pmState.method;
    var desc = pmState.orderId ? 'Walk-in Order #' + pmState.orderId : 'Add-on service payment';
    var fd = new FormData();
    fd.append('action', 'create_intent');
    fd.append('csrf_token', pmGetCsrf());
    fd.append('amount_centavos', amountCentavos);
    fd.append('pm_method', pmState.method);
    fd.append('description', desc);
    fetch('paymongo_intent.php', {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { pmError(data.error); return; }
            pmState.intentId  = data.intent_id;
            pmState.clientKey = data.client_key || '';
            callback();
        })
        .catch(function(e) { pmError(e.message); });
}

function pmSendOtp() {
    var btn = document.getElementById('pmSendBtn');
    btn.disabled = true; btn.textContent = '⏳ Processing…';
    document.getElementById('pm-error-banner').style.display = 'none';
    var phone = (document.getElementById('pm-phone-input')?.value || '').trim();
    var email = '';
    if (pmState.method === 'gcash' || pmState.method === 'maya') {
        email = (document.getElementById('pm-email-input')?.value || '').trim();
        if (!phone) { pmError('Please enter a mobile number.'); return; }
        if (!email) { pmError('Customer email is required for GCash/Maya payment.'); return; }
    }
    if (pmState.method === 'card') {
        var num  = (document.getElementById('pm-card-number')?.value || '').replace(/\s/g,'');
        var expM = document.getElementById('pm-card-exp-month')?.value || '';
        var expY = document.getElementById('pm-card-exp-year')?.value  || '';
        var cvv  = document.getElementById('pm-card-cvv')?.value || '';
        email    = (document.getElementById('pm-email-card')?.value || '').trim();
        if (!num || !expM || !expY || !cvv) { pmError('Please fill in all card details.'); return; }
    }
    pmCreateIntent(function() {
        var fd = new FormData();
        fd.append('action', 'attach_method');
        fd.append('csrf_token', pmGetCsrf());
        fd.append('intent_id', pmState.intentId);
        fd.append('pm_method', pmState.method);
        fd.append('phone', phone || pmState.prefillPhone || '');
        fd.append('name',  pmState.prefillName || '');
        fd.append('email', email);
        if (pmState.method === 'card') {
            fd.append('card_number',    document.getElementById('pm-card-number')?.value || '');
            fd.append('card_exp_month', document.getElementById('pm-card-exp-month')?.value || '');
            fd.append('card_exp_year',  document.getElementById('pm-card-exp-year')?.value  || '');
            fd.append('card_cvv',       document.getElementById('pm-card-cvv')?.value || '');
        }
        fetch('paymongo_intent.php', {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { pmError(data.error); return; }
                pmState.pmId = data.pm_id || '';
                if (data.status === 'succeeded') {
                    pmMarkPaid(data.reference || pmState.intentId);
                    return;
                }
                // Awaiting redirect authorization (GCash/Maya/3DS)
                var na = data.next_action;
                var redirectUrl = (na && na.redirect && na.redirect.url) ? na.redirect.url : '';
                pmShowStep(2);
                var methodNames = {gcash:'GCash', maya:'Maya', card:'3D Secure'};
                document.getElementById('pm-step2-msg').textContent =
                    'Please authorize the payment in your ' + (methodNames[pmState.method] || '') + ' app, then return to this page.';
                var linkEl = document.getElementById('pm-redirect-link');
                if (redirectUrl) {
                    linkEl.href = redirectUrl;
                    linkEl.textContent = 'Open ' + (methodNames[pmState.method] || 'Payment');
                    linkEl.style.display = 'inline-block';
                    window.open(redirectUrl, '_blank');
                } else {
                    linkEl.style.display = 'none';
                }
                pmState.pollCount = 0;
                pmState.pollTimer = setInterval(pmPollStatus, 3000);
            })
            .catch(function(e) { pmError(e.message); });
    });
}

function pmBackToStep1() {
    if (pmState.pollTimer) clearInterval(pmState.pollTimer);
    pmShowStep(1);
    var btn = document.getElementById('pmSendBtn');
    if (btn) { btn.disabled = false; btn.textContent = '▶ Proceed to Payment'; }
}

function pmPollStatus() {
    pmState.pollCount++;
    var counter = document.getElementById('pm-poll-counter');
    if (counter) counter.textContent = '(check ' + pmState.pollCount + ' of 40)';
    if (pmState.pollCount > 40) {
        clearInterval(pmState.pollTimer);
        pmShowStep(3);
        document.getElementById('pm-err-title').textContent = 'Payment Timed Out';
        document.getElementById('pm-err-msg').textContent   = 'We couldn\'t confirm your payment. Please try again or use cash.';
        return;
    }
    var fd = new FormData();
    fd.append('action', 'check_intent');
    fd.append('csrf_token', pmGetCsrf());
    fd.append('intent_id', pmState.intentId);
    fetch('paymongo_intent.php', {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) return;
            if (data.status === 'succeeded') {
                clearInterval(pmState.pollTimer);
                pmMarkPaid(data.reference || pmState.intentId);
            } else if (data.status === 'payment_error' || data.status === 'failed') {
                clearInterval(pmState.pollTimer);
                pmShowStep(3);
                document.getElementById('pm-err-title').textContent = 'Payment Failed';
                document.getElementById('pm-err-msg').textContent   = 'Payment was not completed. Please try again.';
            }
        })
        .catch(function() {});
}

function pmMarkPaid(reference) {
    if (pmState.addonForm) {
        pmDoneAddon(reference);
        return;
    }
    var fd = new FormData();
    fd.append('action',    'mark_paid_paymongo');
    fd.append('csrf_token', pmGetCsrf());
    fd.append('order_id',  pmState.orderId);
    fd.append('pm_ref',    reference || pmState.intentId);
    fd.append('pm_method', pmState.method);
    fd.append('intent_id', pmState.intentId);
    fetch(window.location.pathname + window.location.search, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                pmDone(reference);
            } else {
                pmError(data.error || 'Could not record payment. Please check with the admin.');
            }
        })
        .catch(function(e) { pmError(e.message); });
}

function pmDoneAddon(reference) {
    var form = pmState.addonForm;
    function injectHidden(name, val) {
        var inp = form.querySelector('[name="' + name + '"]');
        if (!inp) { inp = document.createElement('input'); inp.type='hidden'; inp.name=name; form.appendChild(inp); }
        inp.value = val;
    }
    injectHidden('paymongo_reference', reference || pmState.intentId);
    injectHidden('paymongo_method',    pmState.method);
    document.getElementById('pmModalCloseBtn').style.display = 'none';
    pmShowStep(4);
    document.getElementById('pm-done-msg').textContent =
        'Payment confirmed via ' + ({gcash:'GCash',maya:'Maya',card:'Card'}[pmState.method]||pmState.method) + '. Submitting…';
    setTimeout(function() { form.submit(); }, 1200);
}

function pmDone(reference) {
    document.getElementById('pmModalCloseBtn').style.display = 'none';
    pmShowStep(4);
    var mnames = {gcash:'GCash', maya:'Maya', card:'Card'};
    document.getElementById('pm-done-msg').textContent =
        'Order #' + pmState.orderId + ' confirmed via ' + (mnames[pmState.method]||pmState.method) + '. Ref: ' + (reference||'N/A') + ' — reloading…';
    setTimeout(function() { location.reload(); }, 2500);
}

function formatCardNumber(input) {
    var v = input.value.replace(/\s+/g,'').replace(/\D/g,'');
    var m = v.match(/\d{1,4}/g);
    input.value = m ? m.join(' ') : '';
}

// ── QR Ph flow ────────────────────────────────────────────────────────────────
var qrphState = { orderId: 0, sessionId: '', checkoutUrl: '', pollTimer: null, formType: '' };

function openQrphFlow(formType) {
    var form = document.getElementById(formType === 'service' ? 'serviceForm' : 'productForm');
    if (!form) return;
    var itemId = form.querySelector('[name="item_id"]')?.value;
    if (!itemId) { alert('Please select a ' + (formType === 'service' ? 'service' : 'product') + ' first.'); return; }
    var name  = (form.querySelector('[name="customer_name"]')?.value || '').trim();
    var phone = (form.querySelector('[name="phone"]')?.value || '').trim();
    if (!name || !phone) { alert('Please fill in customer name and phone number first.'); return; }
    if (formType === 'service') {
        if (!form.querySelector('[name="booking_date"]')?.value) { alert('Please select a booking date first.'); return; }
        if (document.getElementById('rate_type_val')?.value === 'hotel' && parseInt(document.getElementById('partner_id_val')?.value || 0) === 0) { alert('Please select a hotel/partner for the Hotel rate.'); return; }
        if (walkinAvailBlocked) { alert('⛔ Cannot book: no qualified therapist is available at the selected time.'); return; }
    }
    qrphState.formType = formType;
    var prefix   = formType === 'service' ? 'svc' : 'prod';
    var statusEl = document.getElementById(prefix + '-online-status');
    if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(234,179,8,0.1)'; statusEl.style.border='1px solid rgba(234,179,8,0.4)'; statusEl.style.color='#92400e'; statusEl.textContent='⏳ Creating order…'; }
    var fd = new FormData(form);
    fd.set('payment_method', 'qrph');
    fd.set('otp_mode', '1');
    var payUrl = '<?php echo rtrim(BASE_URL, "/") . "/admin/walkin_payment.php"; ?>';
    fetch(payUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                if (statusEl) { statusEl.style.background='rgba(220,53,69,0.08)'; statusEl.style.border='1px solid rgba(220,53,69,0.3)'; statusEl.style.color='#b91c1c'; statusEl.textContent='❌ ' + (data.error || 'Order creation failed.'); }
                return;
            }
            if (statusEl) statusEl.style.display = 'none';
            openQrphModal(data.order_id || 0, data.final_amount || 0);
        })
        .catch(function(err) {
            if (statusEl) { statusEl.style.display='block'; statusEl.style.background='rgba(220,53,69,0.08)'; statusEl.style.border='1px solid rgba(220,53,69,0.3)'; statusEl.style.color='#b91c1c'; statusEl.textContent='❌ ' + err.message; }
        });
}

function openQrphModal(orderId, amount) {
    qrphState.orderId = orderId;
    if (qrphState.pollTimer) clearInterval(qrphState.pollTimer);
    var modal = document.getElementById('qrphModal');
    modal.style.display = 'flex';
    document.getElementById('qrphAmount').textContent = '₱' + parseFloat(amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('qrphImageContainer').innerHTML = '<span style="color:#6b7280;font-size:0.82rem;">⏳ Generating QR code…</span>';
    document.getElementById('qrphStatus').textContent = '⏳ Waiting for payment…';
    document.getElementById('qrphStatus').style.color = '#C96A2C';
    var fd = new FormData();
    fd.append('action',      'create_qrph');
    fd.append('csrf_token',  pmGetCsrf());
    fd.append('order_id',    orderId);
    fd.append('amount',      parseFloat(amount).toFixed(2));
    fd.append('description', 'Recovery Spa Walk-in Order #' + orderId);
    fetch('paymongo_intent.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('qrphImageContainer').innerHTML = '<span style="color:#b91c1c;font-size:0.82rem;">❌ ' + (data.error || 'Failed to generate QR code') + '</span>';
                return;
            }
            qrphState.sessionId   = data.session_id;
            qrphState.checkoutUrl = data.checkout_url;
            var cont = document.getElementById('qrphImageContainer');
            cont.style.width     = '280px';
            cont.style.height    = 'auto';
            cont.style.minHeight = '180px';
            cont.innerHTML =
                '<div style="text-align:center;padding:1.5rem;">'
              + '<div style="font-size:2.5rem;margin-bottom:0.75rem;">📷</div>'
              + '<p style="font-size:0.85rem;color:#3B2A1A;margin-bottom:1rem;line-height:1.5;">'
              + 'Click below to open the QR Ph payment page in a new window.</p>'
              + '<button type="button" onclick="window.open(qrphState.checkoutUrl,\'paymongo_qrph\',\'width=420,height=700\')" '
              + 'style="padding:0.7rem 1.5rem;background:#C96A2C;color:#fff;'
              + 'border:none;border-radius:8px;font-weight:700;font-size:0.9rem;cursor:pointer;">Open QR Code</button>'
              + '</div>';
            var popup = window.open(data.checkout_url, 'paymongo_qrph', 'width=420,height=700');
            if (!popup || popup.closed || typeof popup.closed === 'undefined') {
                cont.innerHTML =
                    '<div style="text-align:center;padding:1.5rem;">'
                  + '<p style="font-size:0.85rem;color:#b91c1c;margin-bottom:1rem;">'
                  + '⚠️ Popup blocked by browser. Click below to open manually:</p>'
                  + '<button type="button" onclick="window.open(qrphState.checkoutUrl,\'_blank\')" '
                  + 'style="padding:0.7rem 1.5rem;background:#C96A2C;color:#fff;'
                  + 'border:none;border-radius:8px;font-weight:700;cursor:pointer;">Open Payment Page</button>'
                  + '</div>';
            }
            startQrphPolling();
        })
        .catch(function(err) {
            document.getElementById('qrphImageContainer').innerHTML = '<span style="color:#b91c1c;font-size:0.82rem;">❌ ' + err.message + '</span>';
        });
}

function startQrphPolling() {
    if (qrphState.pollTimer) clearInterval(qrphState.pollTimer);
    var pollCount = 0;
    qrphState.pollTimer = setInterval(function() {
        pollCount++;
        if (pollCount > 60) {
            clearInterval(qrphState.pollTimer);
            document.getElementById('qrphStatus').textContent = '⏰ Timed out. Please cancel and try another payment method.';
            document.getElementById('qrphStatus').style.color = '#b91c1c';
            return;
        }
        var fd = new FormData();
        fd.append('action',     'check_qrph');
        fd.append('csrf_token', pmGetCsrf());
        fd.append('session_id', qrphState.sessionId);
        fd.append('order_id',   qrphState.orderId);
        fetch('paymongo_intent.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.paid) {
                    clearInterval(qrphState.pollTimer);
                    document.getElementById('qrphStatus').textContent = '✅ Payment received!';
                    document.getElementById('qrphStatus').style.color = '#15803d';
                    qrphMarkPaid(data.reference || qrphState.sessionId);
                }
            })
            .catch(function() {});
    }, 3000);
}

function qrphMarkPaid(reference) {
    var fd = new FormData();
    fd.append('action',     'mark_paid_paymongo');
    fd.append('csrf_token', pmGetCsrf());
    fd.append('order_id',   qrphState.orderId);
    fd.append('pm_ref',     reference);
    fd.append('pm_method',  'qrph');
    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('qrphStatus').textContent = '✅ Order confirmed! Reloading…';
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                document.getElementById('qrphStatus').textContent = '⚠️ ' + (data.error || 'Could not record payment.');
                document.getElementById('qrphStatus').style.color = '#b91c1c';
            }
        })
        .catch(function(err) {
            document.getElementById('qrphStatus').textContent = '⚠️ ' + err.message;
            document.getElementById('qrphStatus').style.color = '#b91c1c';
        });
}

function closeQrphModal() {
    if (qrphState.pollTimer) clearInterval(qrphState.pollTimer);
    document.getElementById('qrphModal').style.display = 'none';
}
</script>

<?php require_once 'admin_footer.php'; ?>