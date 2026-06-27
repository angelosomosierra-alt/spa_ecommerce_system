<?php
/**
 * walkin_payment.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX endpoint called by walkin.php when receptionist clicks GCash / Maya / Card.
 *
 * Flow:
 *   1. Validates the walk-in form data (same rules as walkin.php normal POST)
 *   2. Creates the order with payment_status = 'pending_payment'
 *   3. Calls PayMongo Checkout Sessions API
 *   4. Returns JSON { success: true, checkout_url: "...", order_id: N }
 *      — walkin.php JS opens this URL in a popup window
 *
 * On success the popup redirects to walkin_payment_success.php which
 * signals the parent window to refresh and show the confirmed order.
 */

require_once '../config.php';
redirect_if_not_admin();

header('Content-Type: application/json');

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Read & sanitize inputs ────────────────────────────────────────────────────
verify_csrf_token();
$customer_name  = sanitize_input($_POST['customer_name']  ?? '');
$phone          = sanitize_input($_POST['phone']          ?? '');
$order_type     = $_POST['order_type']     ?? 'service';   // 'service' | 'product'
$item_id        = intval($_POST['item_id'] ?? 0);
$quantity       = max(1, intval($_POST['quantity']      ?? 1));
$people_count   = max(1, intval($_POST['people_count']  ?? 1));
$booking_date   = $_POST['booking_date']  ?? null;
$payment_method = $_POST['payment_method'] ?? 'gcash';     // gcash | paymaya | card
$rate_type      = $_POST['rate_type']      ?? 'regular';
$partner_id     = intval($_POST['partner_id'] ?? 0);
$customer_note  = sanitize_input($_POST['customer_note'] ?? '');
$slip_number    = sanitize_input($_POST['slip_number']   ?? '');
$therapist_id   = intval($_POST['therapist_id'] ?? 0);

$discount_type  = in_array($_POST['discount_type'] ?? '', ['none','voucher','senior','pwd','employee'])
                  ? $_POST['discount_type'] : 'none';
$voucher_type_i = $_POST['voucher_type']   ?? 'cash';
$voucher_value  = floatval($_POST['voucher_amount'] ?? 0);

// ── Basic validation ──────────────────────────────────────────────────────────
if (empty($customer_name) || empty($phone) || !$item_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    exit;
}

$online_methods = ['gcash', 'paymaya', 'maya', 'card', 'qrph'];
if (!in_array($payment_method, $online_methods)) {
    echo json_encode(['success' => false, 'error' => 'Invalid online payment method.']);
    exit;
}

// ── Get or create walk-in user ────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM users WHERE username = 'walkin_customer' LIMIT 1");
$stmt->execute();
$walkin_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($walkin_user) {
    $walkin_user_id = $walkin_user['id'];
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone, address, role)
                            VALUES ('walkin_customer','N/A','walkin@spa.com','Walk-in Customer','N/A','Walk-in Customer','user')");
    $stmt->execute();
    $walkin_user_id = $stmt->insert_id;
    $stmt->close();
}

// ── Fetch item & compute price ────────────────────────────────────────────────
$total_amount  = 0.0;
$item_name     = '';
$charged_price = 0.0;

if ($order_type === 'product') {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND stock > 0");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Product not found or out of stock.']);
        exit;
    }
    $item_name     = $item['name'];
    $total_amount  = $item['price'] * $quantity;
    $charged_price = $item['price'];

} else {
    // service
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Service not found.']);
        exit;
    }
    $item_name     = $item['name'];
    $regular_price = floatval($item['price']);

    // ── Partner rates map (needed for hotel rate) ─────────────────────────────
    $partner_rates_map = [];
    if ($rate_type === 'hotel' && $partner_id > 0) {
        $pr = $conn->prepare("SELECT price FROM partner_rates WHERE partner_id = ? AND service_id = ?");
        $pr->bind_param("ii", $partner_id, $item_id);
        $pr->execute();
        $pr_row = $pr->get_result()->fetch_assoc();
        $pr->close();
        $partner_rates_map[$partner_id][$item_id] = $pr_row['price'] ?? $regular_price;
    }

    switch ($rate_type) {
        case 'home':       $charged_price = ($regular_price * 2) + 300; break;
        case 'hotel':      $charged_price = $partner_rates_map[$partner_id][$item_id] ?? $regular_price; break;
        case 'influencer': $charged_price = 0.00; break;
        default:           $charged_price = $regular_price; break;
    }
    $total_amount = $charged_price;
}

// ── Compute discount ──────────────────────────────────────────────────────────
$discount_amount_calc = 0.0;
if ($discount_type === 'senior' || $discount_type === 'pwd') {
    $discount_amount_calc = round($total_amount * 0.20, 2);
} elseif ($discount_type === 'voucher' && $voucher_value > 0) {
    $discount_amount_calc = $voucher_type_i === 'percent'
        ? round($total_amount * ($voucher_value / 100), 2)
        : min($voucher_value, $total_amount);
}
$final_amount = max(0.0, $total_amount - $discount_amount_calc);

// PayMongo minimum is ₱20
if ($final_amount < 20) {
    echo json_encode(['success' => false, 'error' => 'Amount too small for online payment (minimum ₱20.00).']);
    exit;
}

// ── Create order (pending_payment) inside a transaction ───────────────────────
$conn->begin_transaction();
try {
    // Lock stock for products
    if ($order_type === 'product') {
        $ls = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        $ls->bind_param("i", $item_id); $ls->execute();
        $live_stock = intval($ls->get_result()->fetch_assoc()['stock'] ?? 0); $ls->close();
        if ($live_stock < $quantity) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => "Insufficient stock (only $live_stock left)."]);
            exit;
        }
    }

    if ($order_type === 'product') {
        $stmt = $conn->prepare("
            INSERT INTO orders
                (user_id, customer_name, phone, total_amount, payment_method,
                 payment_status, approval_status, discount_type, discount_amount,
                 final_amount, slip_number)
            VALUES (?, ?, ?, ?, ?, 'pending_payment', 'pending', ?, ?, ?, ?)
        ");
        $stmt->bind_param("issdssdds",
            $walkin_user_id, $customer_name, $phone,
            $total_amount, $payment_method,
            $discount_type, $discount_amount_calc, $final_amount, $slip_number
        );
    } else {
        $svc_payment_method = $payment_method;
        $stmt = $conn->prepare("
            INSERT INTO orders
                (user_id, customer_name, phone, booking_date,
                 total_amount, payment_method, payment_status, approval_status,
                 discount_type, discount_amount, final_amount, slip_number)
            VALUES (?, ?, ?, ?, ?, ?, 'pending_payment', 'pending', ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssdssdds",
            $walkin_user_id, $customer_name, $phone,
            $booking_date, $total_amount, $svc_payment_method,
            $discount_type, $discount_amount_calc, $final_amount, $slip_number
        );
    }
    $stmt->execute();
    $order_id = $stmt->insert_id;
    $stmt->close();

    // Order item
    if ($order_type === 'product') {
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidd", $order_id, $item_id, $quantity, $charged_price, $total_amount);
    } else {
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal) VALUES (?, ?, 1, ?, ?)");
        $stmt->bind_param("iidd", $order_id, $item_id, $charged_price, $charged_price);
    }
    $stmt->execute();
    $order_item_id = $stmt->insert_id;
    $stmt->close();

    // Appointment (services only)
    if ($order_type === 'service') {
        $svc_type_val    = ($rate_type === 'home') ? 'home' : 'onsite';
        $appt_partner_id = ($rate_type === 'hotel' && $partner_id > 0) ? $partner_id : null;
        $appt_stmt = $conn->prepare("
            INSERT INTO appointments
                (user_id, service_id, order_item_id, appointment_date,
                 status, people_count, service_type, rate_type,
                 partner_id, charged_price, customer_note)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
        ");
        $appt_stmt->bind_param("iiisissids",
            $walkin_user_id, $item_id, $order_item_id,
            $booking_date, $people_count,
            $svc_type_val, $rate_type,
            $appt_partner_id, $charged_price, $customer_note
        );
        $appt_stmt->execute();
        $appointment_id = $appt_stmt->insert_id;  // ← actual appointments.id
        $appt_stmt->close();

        // Therapist assignment
        if ($therapist_id > 0) {
            $cm = $conn->prepare("SELECT commission_percent, influencer_flat_rate FROM therapist_commission WHERE therapist_id = ? AND service_id = ? LIMIT 1");
            $cm->bind_param("ii", $therapist_id, $item_id); $cm->execute();
            $cm_row = $cm->get_result()->fetch_assoc(); $cm->close();
            $commission = 0.0;
            if ($cm_row) {
                $commission = $rate_type === 'influencer'
                    ? floatval($cm_row['influencer_flat_rate'])
                    : round($charged_price * floatval($cm_row['commission_percent']) / 100, 2);
            }
            $at = $conn->prepare("INSERT INTO appointment_therapists (appointment_id, therapist_id, commission, notes) VALUES (?, ?, ?, '')");
            $at->bind_param("iid", $appointment_id, $therapist_id, $commission); // ← fixed
            $at->execute(); $at->close();
        }
    }

    // ── OTP mode: skip checkout session, return order_id + amount ────────────
    if (!empty($_POST['otp_mode'])) {
        $conn->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id, 'final_amount' => $final_amount]);
        exit;
    }

    // ── Build PayMongo Checkout Session ───────────────────────────────────────
    $amount_centavos = intval(round($final_amount * 100));
    if ($amount_centavos < 2000) $amount_centavos = 2000;

    $line_items = [[
        'currency'    => 'PHP',
        'amount'      => $amount_centavos,
        'description' => ($order_type === 'service' ? 'Service: ' : 'Product: ') . $item_name,
        'name'        => $item_name,
        'quantity'    => ($order_type === 'product') ? $quantity : 1,
    ]];

    // ── Phone sanitize for PayMongo ───────────────────────────────────────────
    $_raw_digits = preg_replace('/\D/', '', $phone);
    if (strpos($_raw_digits, '63') === 0)    $_raw_digits = substr($_raw_digits, 2);
    elseif (strpos($_raw_digits, '0') === 0) $_raw_digits = substr($_raw_digits, 1);
    $_pm_phone = '+63' . $_raw_digits;
    if (!preg_match('/^\+639\d{9}$/', $_pm_phone)) $_pm_phone = null;

    // ── PayMongo method types — show only the chosen method ──────────────────
    $method_map = ['gcash' => 'gcash', 'paymaya' => 'paymaya', 'maya' => 'paymaya', 'card' => 'card'];
    $pm_method  = $method_map[$payment_method] ?? 'card';

    // For the popup success/cancel redirect back to walkin
    $success_url = BASE_URL . 'admin/walkin_payment_success.php?order_id=' . $order_id
                   . '&token=' . hash_hmac('sha256', $order_id . '|' . $walkin_user_id, APP_SECRET);
    $cancel_url  = BASE_URL . 'admin/walkin_payment_cancel.php?order_id=' . $order_id;

    $payload = json_encode([
        'data' => [
            'attributes' => [
                'billing' => array_filter([
                    'name'  => $customer_name,
                    'phone' => $_pm_phone,
                ]),
                'collection' => [
                    'customer_info' => [
                        'mobile_phone' => ['state' => 'required'],
                        'name'         => ['state' => 'auto'],
                    ],
                ],
                'billing_information_fields_editable' => 'enabled',
                'line_items'          => $line_items,
                'payment_method_types'=> [$pm_method],
                'success_url'         => $success_url,
                'cancel_url'          => $cancel_url,
                'description'         => 'Recovery Spa Walk-in Order #' . $order_id,
            ]
        ]
    ]);

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => (APP_ENV === 'production'),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curl_err)) {
        $conn->rollback();
        error_log('[WALKIN_PAYMENT] curl failed: ' . $curl_err);
        echo json_encode(['success' => false, 'error' => 'Could not connect to payment gateway. Please try cash payment.']);
        exit;
    }

    $result = json_decode($response, true);

    if (!empty($result['errors'])) {
        $conn->rollback();
        $detail = $result['errors'][0]['detail'] ?? 'Unknown error';
        error_log('[WALKIN_PAYMENT] PayMongo error for order #' . $order_id . ': ' . $detail);
        echo json_encode(['success' => false, 'error' => 'Payment setup failed: ' . $detail]);
        exit;
    }

    $checkout_url     = $result['data']['attributes']['checkout_url'] ?? '';
    $paymongo_link_id = $result['data']['id'] ?? '';

    if (empty($checkout_url)) {
        $conn->rollback();
        error_log('[WALKIN_PAYMENT] No checkout_url in PayMongo response: ' . $response);
        echo json_encode(['success' => false, 'error' => 'Payment setup failed. No checkout URL returned.']);
        exit;
    }

    // Save PayMongo link ID to order
    $upd = $conn->prepare("UPDATE orders SET paymongo_link_id = ? WHERE id = ?");
    $upd->bind_param("si", $paymongo_link_id, $order_id);
    $upd->execute(); $upd->close();

    $conn->commit();

    echo json_encode([
        'success'      => true,
        'checkout_url' => $checkout_url,
        'order_id'     => $order_id,
    ]);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    error_log('[WALKIN_PAYMENT] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()]);
    exit;
}