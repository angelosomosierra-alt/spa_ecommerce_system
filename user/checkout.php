<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── INITIALIZE CHECKOUT ──────────────────────────────────────────────────────
$checkout_items = [];
$checkout_type  = null;
$total_amount   = 0;
$service_id     = null;

if (isset($_SESSION['service_booking'])) {
    $checkout_type = 'service';
    $service_id    = $_SESSION['service_booking']['service_id'];
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$service) { header("Location: index.php"); exit(); }
    $checkout_items[] = [
        'type'             => 'service',
        'id'               => $service['id'],
        'name'             => $service['name'],
        'image'            => $service['image'],
        'price'            => $service['price'],
        'quantity'         => 1,
        'session_time'     => $service['session_time'],
        'is_home_service'  => $service['is_home_service'],
        'home_service_fee' => floatval($service['home_service_fee'] ?? 0),
    ];
    $total_amount = $service['price'];

    // ── Pre-calculate therapist capacity for this service ─────────────────────
    $booking_date_check = $_SESSION['service_booking']['booking_date']
                       ?? $_POST['booking_date']
                       ?? '';
    $is_today = (!empty($booking_date_check) && date('Y-m-d') === $booking_date_check);

    // Total qualified therapists (have commission set for this service)
    $cap_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT tc.therapist_id) AS total_qualified,
               COUNT(DISTINCT CASE
                   WHEN ta.duty_date = CURDATE() AND ta.time_out IS NULL
                   THEN tc.therapist_id END
               ) AS on_duty_qualified
        FROM therapist_commission tc
        JOIN therapists t ON t.id = tc.therapist_id
        LEFT JOIN therapist_attendance ta
               ON ta.therapist_id = tc.therapist_id
              AND ta.duty_date = CURDATE()
        WHERE tc.service_id = ?
    ");
    $cap_stmt->bind_param("i", $service_id);
    $cap_stmt->execute();
    $cap_row          = $cap_stmt->get_result()->fetch_assoc();
    $cap_stmt->close();
    $total_qualified  = (int)($cap_row['total_qualified']  ?? 0);
    $on_duty_qualified = (int)($cap_row['on_duty_qualified'] ?? 0);

} elseif (isset($_SESSION['direct_checkout'])) {
    $checkout_type = 'product';
    $product_id    = $_SESSION['direct_checkout']['product_id'];
    $quantity      = $_SESSION['direct_checkout']['quantity'];
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$product) { header("Location: index.php"); exit(); }
    $checkout_items[] = [
        'type'     => 'product',
        'id'       => $product['id'],
        'name'     => $product['name'],
        'image'    => $product['image'],
        'price'    => $product['price'],
        'quantity' => $quantity
    ];
    $total_amount = $product['price'] * $quantity;

} elseif (isset($_SESSION['checkout_items']) && !empty($_SESSION['checkout_items'])) {
    $checkout_type  = 'cart';
    $checkout_items = $_SESSION['checkout_items'];
    foreach ($checkout_items as $item) {
        $total_amount += $item['price'] * $item['quantity'];
    }
} else {
    header("Location: cart.php");
    exit();
}

// ─── HANDLE FORM SUBMISSION ───────────────────────────────────────────────────
$message      = '';
$message_type = '';
$capacity_warning = '';

if (!empty($_SESSION['checkout_error'])) {
    $message      = htmlspecialchars($_SESSION['checkout_error']);
    $message_type = 'danger';
    unset($_SESSION['checkout_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    verify_csrf_token();
    $customer_name   = sanitize_input($_POST['customer_name']);
    $email           = sanitize_input($_POST['email']);
    $phone           = sanitize_input($_POST['phone']);
    $address         = sanitize_input($_POST['address']);
    $booking_date    = $_POST['booking_date'] ?? null;
    $people_count    = intval($_POST['people_count'] ?? 1);
    $payment_method  = $_POST['payment_method'] ?? 'onsite';
    $service_type    = $_POST['service_type']   ?? 'onsite';
    $home_address    = sanitize_input($_POST['home_address'] ?? '');
    $home_notes      = sanitize_input($_POST['home_notes']   ?? '');
    $customer_note   = sanitize_input($_POST['customer_note'] ?? '');

    // ── Discount fields ───────────────────────────────────────────────────────
    // Customers declare their discount type only. The actual amount is confirmed
    // onsite by the receptionist when they show their ID or voucher.
    $discount_type = in_array($_POST['discount_type'] ?? '', ['none','voucher','senior','pwd','employee'])
                     ? $_POST['discount_type'] : 'none';

    // Discounts are not compatible with online payment — block server-side.
    // The JS already hides the online option when a discount is selected,
    // so this only fires if someone bypasses the UI.
    if ($discount_type !== 'none' && $payment_method === 'online') {
        $_SESSION['checkout_error'] = 'Discounts cannot be used with online payment. Please select Pay Onsite.';
        header('Location: ' . BASE_URL . 'user/checkout.php');
        exit;
    }

    $payment_status  = $payment_method === 'online' ? 'pending_payment' : 'unpaid';

    $home_fee_applied = 0.00;
    if ($checkout_type === 'service') {
        if ($service_type === 'home') {
            $home_fee_applied = floatval($checkout_items[0]['home_service_fee'] ?? 0);
            $total_amount     = ($checkout_items[0]['price'] + $home_fee_applied) * $people_count;
        } else {
            $total_amount = $checkout_items[0]['price'] * $people_count;
        }
    }

    if ($checkout_type === 'service' && !empty($booking_date)) {
        date_default_timezone_set('Asia/Manila');
        $chosen_time = strtotime($booking_date);
        $hour = (int)date('H', $chosen_time);
        if ($hour < 10) {
            $message = "❌ We open at 10:00 AM.";
            $message_type = "danger";
        }
        $cutoff_time = strtotime('+2 hours');
        if ($chosen_time < $cutoff_time && empty($message)) {
            $message = "❌ Appointments must be booked at least 2 hours in advance. Earliest: " . date('h:i A', $cutoff_time);
            $message_type = "danger";
        }
    }

    if (empty($customer_name) || empty($email) || empty($phone) || empty($address)) {
        $message = "All fields are required."; $message_type = "danger";
    } elseif (empty($payment_method)) {
        $message = "Please select a payment method."; $message_type = "danger";
    } else {
        if ($checkout_type === 'service') {
            if (empty($booking_date) || $people_count < 1) {
                $message = empty($booking_date)
                    ? "Please select a date and time slot for your appointment."
                    : "Please specify the number of people (minimum 1).";
                $message_type = "danger";
            } else {
                date_default_timezone_set('Asia/Manila');
                $chosen_time = strtotime($booking_date);
                $cutoff_time = strtotime('+2 hours');
                if ($chosen_time < time()) {
                    $message = "You cannot book an appointment in the past."; $message_type = "danger";
                } elseif ($chosen_time < $cutoff_time) {
                    $message = "Appointments must be booked at least 2 hours in advance."; $message_type = "danger";
                } else {
                    // ── Capacity soft warning ─────────────────────────────────
                    $is_today_booking = (date('Y-m-d', $chosen_time) === date('Y-m-d'));
                    $cap_check = $conn->prepare("
                        SELECT COUNT(DISTINCT tc.therapist_id) AS total_qualified,
                               COUNT(DISTINCT CASE
                                   WHEN ta.duty_date = CURDATE() AND ta.time_out IS NULL
                                   THEN tc.therapist_id END
                               ) AS on_duty_qualified
                        FROM therapist_commission tc
                        LEFT JOIN therapist_attendance ta
                               ON ta.therapist_id = tc.therapist_id
                              AND ta.duty_date = CURDATE()
                        WHERE tc.service_id = ?
                    ");
                    $cap_check->bind_param("i", $service_id);
                    $cap_check->execute();
                    $cap_data          = $cap_check->get_result()->fetch_assoc();
                    $cap_check->close();
                    $cap_total         = (int)($cap_data['total_qualified']   ?? 0);
                    $cap_on_duty       = (int)($cap_data['on_duty_qualified']  ?? 0);

                    if ($is_today_booking && $cap_on_duty > 0 && $people_count > $cap_on_duty) {
                        $capacity_warning = "⚠️ You requested {$people_count} people but only {$cap_on_duty} qualified therapist(s) are on duty today. Your booking will be accepted but may need rescheduling.";
                    } elseif (!$is_today_booking && $cap_total > 0 && $people_count > $cap_total) {
                        $capacity_warning = "⚠️ You requested {$people_count} people but we only have {$cap_total} therapist(s) qualified for this service. Your booking will be accepted but may need rescheduling.";
                    }

                    // ── B-2: hard availability check via AvailabilityEngine ────────
                    require_once __DIR__ . '/../admin/availability.php';
                    $engine     = new AvailabilityEngine($conn);
                    $appt_rate  = ($service_type === 'home') ? 'home' : 'regular';
                    $slot_check = $engine->checkSlot(
                        (int)$service_id,
                        date('Y-m-d H:i:s', $chosen_time),
                        (int)$people_count,
                        $appt_rate
                    );
                    if (!$slot_check['available']) {
                        $reason = $slot_check['reason'] ?? 'No therapist is available at this time';
                        $next   = $engine->getNextAvailableSlot(
                            (int)$service_id,
                            date('Y-m-d H:i:s', $chosen_time),
                            (int)$people_count,
                            $appt_rate
                        );
                        $next_txt = $next
                            ? ' Next available: ' . date('M j, g:i A', strtotime($next['datetime'])) . '.'
                            : ' Please choose a different date or time.';
                        $message      = '❌ ' . $reason . '.' . $next_txt;
                        $message_type = 'danger';
                    }
                }
            }
        }

        if (empty($message) && ($checkout_type === 'product' || $checkout_type === 'cart')) {
            $stock_errors = [];
            foreach ($checkout_items as $item) {
                if ($item['type'] === 'product') {
                    $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                    $stmt->bind_param("i", $item['id']); $stmt->execute();
                    $current_stock = intval($stmt->get_result()->fetch_assoc()['stock']); $stmt->close();
                    if ($item['quantity'] > $current_stock) {
                        $stock_errors[] = "'{$item['name']}' only has $current_stock left in stock.";
                    }
                }
            }
            if (!empty($stock_errors)) { $message = implode('<br>', $stock_errors); $message_type = "danger"; }
        }

        if (empty($message)) {
            // ── Compute discount ──────────────────────────────────────────────
            // Senior/PWD: 20% calculated automatically.
            // Voucher: discount_amount stays 0 — the receptionist confirms and
            // applies the real amount onsite when approving the appointment.
            $discount_amount_calc = 0.00;

            if ($discount_type === 'senior' || $discount_type === 'pwd') {
                $discount_amount_calc = round($total_amount * 0.20, 2);
            } elseif ($discount_type === 'employee') {
                $discount_amount_calc = round($total_amount * 0.50, 2);
            }
            // voucher: $discount_amount_calc stays 0.00 — applied onsite

            $final_amount = max(0, $total_amount - $discount_amount_calc);
            $saved_total  = $discount_type !== 'none' ? $final_amount : $total_amount;

            // ── Lock and verify stock before writing (prevents race conditions) ──
            if ($checkout_type === 'product' || $checkout_type === 'cart') {
                $conn->begin_transaction();
                $stock_ok = true;
                foreach ($checkout_items as $item) {
                    if ($item['type'] === 'product') {
                        $ls = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                        $ls->bind_param("i", $item['id']); $ls->execute();
                        $live_stock = intval($ls->get_result()->fetch_assoc()['stock'] ?? 0); $ls->close();
                        if ($item['quantity'] > $live_stock) {
                            $conn->rollback();
                            $message = "'{$item['name']}' only has {$live_stock} left in stock.";
                            $message_type = "danger";
                            $stock_ok = false;
                            break;
                        }
                    }
                }
                if (!$stock_ok) goto end_order;
            } else {
                $conn->begin_transaction();
            }

            try {
            $stmt = $conn->prepare("
                INSERT INTO orders (user_id, customer_name, email, phone, address, booking_date,
                                    total_amount, payment_method, payment_status, approval_status,
                                    discount_type, discount_amount, final_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->bind_param("isssssdsssdd",
                $user_id, $customer_name, $email, $phone, $address, $booking_date,
                $total_amount, $payment_method, $payment_status,
                $discount_type, $discount_amount_calc, $final_amount
            );

            if ($stmt->execute()) {
                $order_id = $stmt->insert_id; $stmt->close();

                foreach ($checkout_items as $item) {
                    if ($item['type'] === 'product') {
                        $subtotal  = $item['price'] * $item['quantity'];
                        $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $item_stmt->bind_param("iiidd", $order_id, $item['id'], $item['quantity'], $item['price'], $subtotal);
                        $item_stmt->execute(); $item_stmt->close();
                    } elseif ($item['type'] === 'service') {
                        $svc_home_fee   = ($service_type === 'home') ? $home_fee_applied : 0.00;
                        $svc_per_person = $item['price'] + $svc_home_fee;
                        $svc_subtotal   = $svc_per_person * $people_count;
                        $item_stmt      = $conn->prepare("INSERT INTO order_items (order_id, service_id, quantity, price, subtotal, home_service_fee) VALUES (?, ?, ?, ?, ?, ?)");
                        $item_stmt->bind_param("iiiddd", $order_id, $item['id'], $people_count, $svc_per_person, $svc_subtotal, $svc_home_fee);
                        $item_stmt->execute(); $order_item_id = $item_stmt->insert_id; $item_stmt->close();

                        $appt_stmt = $conn->prepare("
                            INSERT INTO appointments
                                (user_id, service_id, order_item_id, appointment_date, status,
                                 people_count, service_type, home_address, home_notes, customer_note,
                                 charged_price)
                            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
                        ");
                        // FIXED: Bug 2 — store total for all people, not per-person
                        $appt_charged = floatval($item['price']) * $people_count;
                        $appt_stmt->bind_param("iiisissssd",
                            $user_id, $item['id'], $order_item_id,
                            $booking_date, $people_count,
                            $service_type, $home_address, $home_notes, $customer_note,
                            $appt_charged
                        );
                        $appt_stmt->execute(); $appt_stmt->close();
                    }
                }

                if ($payment_method === 'online') {
                    $success_url = BASE_URL . 'user/payment_success.php?order_id=' . $order_id;
                    $cancel_url  = BASE_URL . 'user/payment_cancel.php?order_id='  . $order_id;
                    $line_items  = [];
                    foreach ($checkout_items as $item) {
                        $item_amount = intval($item['price'] * 100);
                        // PayMongo minimum is ₱20.00 (2000 centavos); skip zero-price items (influencer)
                        if ($item_amount < 2000) $item_amount = 2000;
                        $line_items[] = [
                            'currency'    => 'PHP',
                            'amount'      => $item_amount,
                            'description' => $item['name'],
                            'name'        => $item['name'],
                            'quantity'    => intval($item['quantity']),
                        ];
                    }
                    if ($checkout_type === 'service' && $home_fee_applied > 0) {
                        $line_items[] = [
                            'currency'    => 'PHP',
                            'amount'      => intval($home_fee_applied * 100),
                            'description' => 'Home Service Fee',
                            'name'        => 'Home Service Fee',
                            'quantity'    => 1,
                        ];
                    }

                    // PayMongo billing.phone: strip to 10 digits (09XXXXXXXXX → 9XXXXXXXXX)
                    // PayMongo's checkout page already shows "+63" as a prefix in the UI,
                    // so sending +639XXXXXXXXX causes it to display as "+63 +639XXXXXXXXX" (doubled).
                    // Send the full E.164 format (+639XXXXXXXXX) for the API — PayMongo handles display.
                    // Sanitize phone for PayMongo — handle every input variant:
// +639171234567, 09171234567, 9171234567, 639171234567
$_raw_digits = preg_replace('/\D/', '', $phone); // strip +, spaces, dashes
if (strpos($_raw_digits, '63') === 0)     $_raw_digits = substr($_raw_digits, 2);
elseif (strpos($_raw_digits, '0') === 0)  $_raw_digits = substr($_raw_digits, 1);
$_pm_phone = '+63' . $_raw_digits;
// Validate: must be +63 followed by exactly 10 digits starting with 9
if (!preg_match('/^\+639\d{9}$/', $_pm_phone)) {
    error_log('[PAYMONGO] Invalid PH mobile number: ' . $phone . ' → ' . $_pm_phone);
    $_pm_phone = null; // let PayMongo prompt the customer to enter it themselves
}

$payload = json_encode([
    'data' => [
        'attributes' => [
            'billing'       => array_filter([
                'name'  => $customer_name,
                'email' => $email,
                'phone' => $_pm_phone, // null omitted by array_filter
            ]),
            'collection'    => [
                'customer_info' => [
                    'mobile_phone' => ['state' => 'required'],
                    'name'         => ['state' => 'auto'],
                    'email'        => ['state' => 'auto'],
                ],
            ],
            'billing_information_fields_editable' => 'enabled',
            'line_items'    => $line_items,
            'payment_method_types' => ['card','gcash','paymaya','qrph'],
            'success_url'   => $success_url,
            'cancel_url'    => $cancel_url,
            'description'   => 'Recovery Spa Order #' . $order_id,
        ]
    ]
]);
                    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_SSL_VERIFYPEER => (APP_ENV === 'production'), // allow self-signed on local dev
                        CURLOPT_TIMEOUT        => 15,
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                        ],
                    ]);
                    $response  = curl_exec($ch);
                    $curl_err  = curl_error($ch);
                    $curl_info = curl_getinfo($ch);
                    curl_close($ch);

                    // Log any curl-level failure (network, SSL, timeout)
                    if ($response === false || !empty($curl_err)) {
                        error_log('[PAYMONGO] curl failed for order #' . $order_id . ': ' . $curl_err);
                        $conn->rollback();
                        $message = "Could not connect to payment gateway. Please try onsite payment or check your internet connection.";
                        $message_type = "danger";
                        goto end_order;
                    }

                    $result = json_decode($response, true);

                    // Log PayMongo API errors (wrong key, validation failure, etc.)
                    if (!empty($result['errors'])) {
                        $api_errs = implode('; ', array_map(fn($e) => ($e['code'] ?? '') . ': ' . ($e['detail'] ?? ''), $result['errors']));
                        error_log('[PAYMONGO] API error for order #' . $order_id . ' (HTTP ' . $curl_info['http_code'] . '): ' . $api_errs);
                        $conn->rollback();
                        $message = "Payment setup failed: " . htmlspecialchars($result['errors'][0]['detail'] ?? 'Unknown error') . " Please try onsite payment.";
                        $message_type = "danger";
                        goto end_order;
                    }

                    if (!empty($result['data']['id'])) {
                        $paymongo_link_id = $result['data']['id'];
                        $upd = $conn->prepare("UPDATE orders SET paymongo_link_id = ? WHERE id = ?");
                        $upd->bind_param("si", $paymongo_link_id, $order_id); $upd->execute(); $upd->close();
                        $checkout_url = $result['data']['attributes']['checkout_url'] ?? '';
                        if ($checkout_url) {
                            $conn->commit();
                            $_SESSION['paymongo_order_id'] = $order_id;
                            header("Location: " . $checkout_url); exit();
                        }
                    }
                    error_log('[PAYMONGO] No checkout_url in response for order #' . $order_id . ': ' . $response);
                    $conn->rollback();
                    $message = "Payment setup failed. Please try onsite payment."; $message_type = "danger";

                } else {
                    // Stock deduction for products
                    foreach ($checkout_items as $item) {
                        if ($item['type'] === 'product') {
                            $upd = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                            $upd->bind_param("iii", $item['quantity'], $item['id'], $item['quantity']); $upd->execute(); $upd->close();
                        }
                    }
                    $conn->commit();

                    if ($checkout_type === 'cart' && !empty($_SESSION['checkout_item_ids'])) {
                        foreach ($_SESSION['checkout_item_ids'] as $pid) {
                            unset($_SESSION['cart'][$pid]);
                            remove_cart_item_from_db($conn, $user_id, $pid);
                        }
                    }
                    if ($checkout_type === 'product') {
                        unset($_SESSION['cart'][$_SESSION['direct_checkout']['product_id'] ?? 0]);
                        remove_cart_item_from_db($conn, $user_id, $_SESSION['direct_checkout']['product_id'] ?? 0);
                    }

                    unset($_SESSION['direct_checkout'], $_SESSION['service_booking'], $_SESSION['checkout_items'], $_SESSION['checkout_item_ids']);
                    if (!empty($_SESSION['cart'])) sync_cart_to_db($conn, $user_id, $_SESSION['cart']);

                    $message      = "success";
                    $message_type = "success";
                    $_SESSION['last_order_id'] = $order_id;

                    require_once __DIR__ . '/../notify.php';
                    if ($checkout_type === 'service') {
                        add_notification($conn, $user_id, 'appointment', '📅 Appointment Booked!', 'Your booking #' . $order_id . ' is pending approval.', 'appointments.php');
                        add_notification($conn, null, 'appointment', '📅 New Service Booking', 'New appointment booking #' . $order_id . ' by a customer.', 'appointments.php');
                    } else {
                        add_notification($conn, $user_id, 'order', '🛍️ Order Placed!', 'Your order #' . $order_id . ' (₱' . number_format($total_amount,2) . ') has been placed.', 'appointments.php#orders');
                        add_notification($conn, null, 'order', '🛍️ New Product Order', 'New product order #' . $order_id . ' (₱' . number_format($total_amount,2) . ').', 'orders.php');
                    }
                }
            } else {
                $conn->rollback();
                $message = "Error placing order. Please try again."; $message_type = "danger"; $stmt->close();
            }
            } catch (Throwable $_oe) {
                $conn->rollback();
                error_log('[CHECKOUT] Order creation failed: ' . $_oe->getMessage());
                $message = "Error placing order. Please try again."; $message_type = "danger";
            }
            end_order:
        }
    }
}

$page_title = 'Checkout';
require_once 'header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;600&display=swap');

:root {
    --brown:   #3B2A1A;
    --gold:    #C96A2C;
    --cream:   #FAF3E8;
    --cream2:  #EAD8C0;
    --cream3:  #F5ECD8;
    --gray:    #888;
    --green:   #198754;
    --radius:  14px;
}

.co-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2rem 1.25rem 4rem;
}

/* ── Page title ── */
.co-title {
    font-family: 'Cormorant Garamond', Georgia, serif;
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 600;
    color: var(--brown);
    margin-bottom: 0.25rem;
}
.co-subtitle {
    font-size: 0.88rem;
    color: var(--gray);
    margin-bottom: 2rem;
    font-family: 'DM Sans', sans-serif;
}

/* ── Step indicator ── */
.co-steps {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 2.5rem;
    font-family: 'DM Sans', sans-serif;
}
.co-step {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--gray);
}
.co-step.active { color: var(--gold); }
.co-step.done   { color: var(--green); }
.co-step-num {
    width: 28px; height: 28px;
    border-radius: 50%;
    border: 2px solid currentColor;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
    background: transparent;
    transition: all .2s;
}
.co-step.active .co-step-num { background: var(--gold); color: #fff; border-color: var(--gold); }
.co-step.done   .co-step-num { background: var(--green); color: #fff; border-color: var(--green); }
.co-step-line {
    flex: 1; height: 2px;
    background: var(--cream2);
    margin: 0 0.5rem;
    max-width: 60px;
}

/* ── Layout ── */
.co-grid {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 2rem;
    align-items: start;
}
@media (max-width: 860px) {
    .co-grid { grid-template-columns: 1fr; }
}

/* ── Cards ── */
.co-card {
    background: #fff;
    border-radius: var(--radius);
    border: 1px solid var(--cream2);
    overflow: hidden;
    box-shadow: 0 2px 16px rgba(59,42,26,0.06);
}
.co-card-head {
    padding: 1.1rem 1.5rem;
    border-bottom: 1px solid var(--cream2);
    display: flex;
    align-items: center;
    gap: 0.65rem;
    background: var(--cream);
}
.co-card-head-icon {
    width: 34px; height: 34px;
    border-radius: 10px;
    background: var(--gold);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; flex-shrink: 0;
}
.co-card-head-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--brown);
}
.co-card-body { padding: 1.5rem; }

/* ── Form inputs ── */
.co-field { margin-bottom: 1.1rem; }
.co-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--brown);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.4rem;
    font-family: 'DM Sans', sans-serif;
}
.co-label span { color: var(--gold); }
.co-input {
    width: 100%;
    padding: 0.65rem 0.9rem;
    border: 1.5px solid var(--cream2);
    border-radius: 10px;
    background: var(--cream);
    color: var(--brown);
    font-size: 0.9rem;
    font-family: 'DM Sans', sans-serif;
    transition: border-color .18s, box-shadow .18s;
    box-sizing: border-box;
}
.co-input:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(201,106,44,0.12);
    background: #fff;
}
.co-input-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 520px) { .co-input-row { grid-template-columns: 1fr; } }

/* ── Service type toggle ── */
.svc-type-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1.1rem;
}
.svc-type-btn {
    padding: 0.9rem;
    border: 2px solid var(--cream2);
    border-radius: 12px;
    background: var(--cream);
    cursor: pointer;
    text-align: center;
    transition: all .18s;
    font-family: 'DM Sans', sans-serif;
}
.svc-type-btn:hover { border-color: var(--gold); }
.svc-type-btn.active {
    border-color: var(--gold);
    background: linear-gradient(135deg, #C96A2C, #a94f1d);
    color: #fff;
}
.svc-type-btn .svc-type-icon { font-size: 1.4rem; display: block; margin-bottom: 0.3rem; }
.svc-type-btn .svc-type-label { font-size: 0.82rem; font-weight: 700; display: block; }
.svc-type-btn .svc-type-sub { font-size: 0.7rem; opacity: 0.7; display: block; margin-top: 0.1rem; }
.svc-type-btn.active .svc-type-sub { opacity: 0.85; }

/* ── Payment method ── */
.pay-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.pay-btn {
    padding: 1rem;
    border: 2px solid var(--cream2);
    border-radius: 12px;
    background: var(--cream);
    cursor: pointer;
    text-align: center;
    transition: all .18s;
    font-family: 'DM Sans', sans-serif;
    position: relative;
    overflow: hidden;
}
.pay-btn:hover { border-color: var(--gold); transform: translateY(-1px); }
.pay-btn.active {
    border-color: var(--gold);
    background: linear-gradient(135deg, #C96A2C, #a94f1d);
}
.pay-btn.active * { color: #fff !important; }
.pay-btn-icon { font-size: 1.6rem; display: block; margin-bottom: 0.35rem; }
.pay-btn-label { font-size: 0.85rem; font-weight: 700; color: var(--brown); display: block; }
.pay-btn-sub { font-size: 0.72rem; color: var(--gray); display: block; margin-top: 0.15rem; }
.pay-badge {
    display: inline-block;
    background: #d1e7dd;
    color: #0a3622;
    font-size: 0.65rem;
    padding: 0.1rem 0.45rem;
    border-radius: 20px;
    margin-top: 0.3rem;
    font-weight: 600;
}
.pay-note {
    padding: 0.7rem 0.9rem;
    border-radius: 9px;
    font-size: 0.82rem;
    margin-bottom: 1rem;
    border-left: 3px solid var(--gold);
    background: #fff8f2;
    color: var(--brown);
    font-family: 'DM Sans', sans-serif;
    transition: all .2s;
}

/* ── Order summary ── */
.co-summary { position: sticky; top: 1.5rem; }
.co-item {
    display: flex;
    gap: 1rem;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid var(--cream2);
}
.co-item:last-child { border-bottom: none; }
.co-item-img {
    width: 68px; height: 68px;
    object-fit: cover;
    border-radius: 10px;
    flex-shrink: 0;
    border: 1px solid var(--cream2);
}
.co-item-name { font-weight: 600; color: var(--brown); font-size: 0.9rem; margin-bottom: 0.2rem; }
.co-item-meta { font-size: 0.75rem; color: var(--gray); }
.co-item-price { font-weight: 700; color: var(--gold); font-size: 0.95rem; margin-left: auto; white-space: nowrap; }

.co-totals { margin-top: 1rem; padding-top: 1rem; border-top: 2px solid var(--cream2); }
.co-total-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.88rem;
    color: var(--gray);
    margin-bottom: 0.5rem;
    font-family: 'DM Sans', sans-serif;
}
.co-total-row.grand {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--brown);
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--cream2);
}
.co-total-row.grand span:last-child { color: var(--gold); }

/* ── Home fee callout ── */
.home-fee-badge {
    display: none;
    background: #fff8f2;
    border: 1px solid #f0c99a;
    border-radius: 9px;
    padding: 0.6rem 0.9rem;
    font-size: 0.8rem;
    color: #7a3d0e;
    margin-top: 0.5rem;
    font-family: 'DM Sans', sans-serif;
}

/* ── Note textarea ── */
.co-note-wrap {
    background: linear-gradient(135deg, #fffbf5, #fef6ea);
    border: 1.5px dashed var(--cream2);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    margin-bottom: 1.1rem;
    transition: border-color .18s;
}
.co-note-wrap:focus-within { border-color: var(--gold); border-style: solid; }
.co-note-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--brown);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-family: 'DM Sans', sans-serif;
}
.co-note-textarea {
    width: 100%;
    border: none;
    background: transparent;
    resize: vertical;
    min-height: 70px;
    font-size: 0.88rem;
    color: var(--brown);
    font-family: 'DM Sans', sans-serif;
    box-sizing: border-box;
}
.co-note-textarea:focus { outline: none; }
.co-note-textarea::placeholder { color: #bbb; }

/* ── Submit button ── */
.co-submit {
    width: 100%;
    padding: 1rem;
    background: linear-gradient(135deg, #C96A2C, #a94f1d);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    letter-spacing: 0.02em;
    transition: all .2s;
    position: relative;
    overflow: hidden;
}
.co-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(201,106,44,0.35); }
.co-submit:active { transform: translateY(0); }
.co-back {
    display: block;
    text-align: center;
    padding: 0.7rem;
    border-radius: 12px;
    border: 1.5px solid var(--cream2);
    color: var(--gray);
    text-decoration: none;
    font-size: 0.85rem;
    margin-top: 0.6rem;
    font-family: 'DM Sans', sans-serif;
    transition: all .18s;
}
.co-back:hover { border-color: var(--brown); color: var(--brown); }

/* ── Alert ── */
.co-alert {
    padding: 1rem 1.25rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    font-family: 'DM Sans', sans-serif;
    border-left: 4px solid;
    animation: slideIn .3s ease;
}
.co-alert.danger  { background: #fef2f2; color: #991b1b; border-color: #dc2626; }
.co-alert.success { background: #f0fdf4; color: #166534; border-color: #16a34a; }

/* ── Security badge ── */
.co-secure {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    color: var(--gray);
    margin-top: 0.75rem;
    font-family: 'DM Sans', sans-serif;
}

@keyframes slideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

/* ── Success overlay ── */
.co-success-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(59,42,26,0.55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
    animation: fadeIn .3s ease;
}
.co-success-overlay.show { display: flex; }
.co-success-box {
    background: #fff;
    border-radius: 20px;
    padding: 2.5rem 2rem;
    text-align: center;
    max-width: 400px;
    width: 90vw;
    box-shadow: 0 24px 60px rgba(0,0,0,0.2);
    animation: popIn .35s cubic-bezier(.34,1.56,.64,1);
}
@keyframes popIn { from { transform:scale(.85); opacity:0; } to { transform:scale(1); opacity:1; } }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.co-success-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1.25rem;
}
</style>

<div class="co-wrap">

    <?php if ($message === 'success'): ?>
    <!-- Success overlay -->
    <div class="co-success-overlay show" id="successOverlay">
        <div class="co-success-box">
            <div class="co-success-icon">📅</div>
            <h2 style="font-family:'Cormorant Garamond',serif;color:#3B2A1A;margin:0 0 0.5rem;font-size:1.6rem;">
                Booking Submitted!
            </h2>
            <?php if ($checkout_type === 'service'): ?>

            <!-- Pending warning box -->
            <div style="background:#fff8f2;border:2px solid #C96A2C;border-radius:12px;
                        padding:1rem;margin-bottom:1.25rem;text-align:left;">
                <div style="font-weight:700;color:#C96A2C;font-size:0.92rem;margin-bottom:0.5rem;">
                    ⏳ Your booking is PENDING approval
                </div>
                <ul style="margin:0;padding-left:1.2rem;color:#3B2A1A;font-size:0.85rem;
                           line-height:1.8;font-family:'DM Sans',sans-serif;">
                    <li><strong>Do NOT go to the spa yet</strong> until you receive a confirmation</li>
                    <li>We will review your booking and assign a therapist</li>
                    <li>You will receive an <strong>email + app notification</strong> once approved</li>
                    <li>Only proceed to the spa after receiving the ✅ approval</li>
                </ul>
            </div>

            <p style="color:#666;font-size:0.85rem;margin-bottom:1.5rem;
                      font-family:'DM Sans',sans-serif;line-height:1.6;">
                Booking reference: <strong>#<?php echo $_SESSION['last_order_id'] ?? ''; ?></strong><br>
                Check your appointments page for real-time status updates.
            </p>

            <?php else: ?>
            <p style="color:#666;font-size:0.9rem;margin-bottom:1.5rem;font-family:'DM Sans',sans-serif;line-height:1.6;">
                Your order #<?php echo $_SESSION['last_order_id'] ?? ''; ?> has been placed successfully!
            </p>
            <?php endif; ?>

            <a href="appointments.php"
               style="display:block;padding:0.85rem;background:linear-gradient(135deg,#C96A2C,#a94f1d);
                      color:#fff;border-radius:12px;text-decoration:none;font-weight:700;
                      font-family:'DM Sans',sans-serif;font-size:0.9rem;margin-bottom:0.6rem;">
                📅 Track My Appointment
            </a>
            <a href="index.php"
               style="display:block;padding:0.7rem;border:1.5px solid #EAD8C0;color:#888;
                      border-radius:12px;text-decoration:none;font-size:0.85rem;
                      font-family:'DM Sans',sans-serif;">
                Back to Home
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($message && $message !== 'success'): ?>
    <div class="co-alert <?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($capacity_warning)): ?>
    <div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:12px;
                padding:0.85rem 1.1rem;margin-bottom:1.5rem;
                font-size:0.88rem;color:#92400e;font-family:'DM Sans',sans-serif;">
        <?php echo htmlspecialchars($capacity_warning); ?>
        <div style="font-size:0.78rem;margin-top:0.3rem;opacity:0.8;">
            We will contact you if rescheduling is needed. You can also check your appointment status anytime.
        </div>
    </div>
    <?php endif; ?>

    <div class="co-title">
        <?php echo $checkout_type === 'service' ? 'Book Your Session' : 'Complete Your Order'; ?>
    </div>
    <div class="co-subtitle">
        <?php echo $checkout_type === 'service'
            ? 'Fill in your details and preferred schedule below'
            : 'Review your items and complete payment'; ?>
    </div>

    <!-- Steps -->
    <div class="co-steps">
        <div class="co-step done">
            <div class="co-step-num">✓</div>
            <span>Cart</span>
        </div>
        <div class="co-step-line"></div>
        <div class="co-step active">
            <div class="co-step-num">2</div>
            <span>Details</span>
        </div>
        <div class="co-step-line"></div>
        <div class="co-step">
            <div class="co-step-num">3</div>
            <span>Payment</span>
        </div>
        <div class="co-step-line"></div>
        <div class="co-step">
            <div class="co-step-num">4</div>
            <span>Confirm</span>
        </div>
    </div>

    <div class="co-grid">

        <!-- ── LEFT: FORM ──────────────────────────────────────────────── -->
        <div>

        <!-- Contact Info -->
        <div class="co-card" style="margin-bottom:1.25rem;">
            <div class="co-card-head">
                <div class="co-card-head-icon">👤</div>
                <div class="co-card-head-title">Contact Information</div>
            </div>
            <form method="POST" id="checkoutForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="payment_method" id="payment_method" value="onsite">
            <input type="hidden" name="service_type"   id="service_type_hidden" value="onsite">
            <div class="co-card-body">
                <div class="co-input-row">
                    <div class="co-field">
                        <label class="co-label">Full Name <span>*</span></label>
                        <input type="text" name="customer_name" class="co-input"
                               value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    <div class="co-field">
                        <label class="co-label">Phone <span>*</span></label>
                        <input type="tel" name="phone" class="co-input"
                               value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                    </div>
                </div>
                <div class="co-field">
                    <label class="co-label">Email Address <span>*</span></label>
                    <input type="email" name="email" class="co-input"
                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>
                <div class="co-field" style="margin-bottom:0;">
                    <label class="co-label">Address <span>*</span></label>
                    <textarea name="address" class="co-input" rows="2"
                              required><?php echo htmlspecialchars($user['address']); ?></textarea>
                </div>
            </div>
        </div>

        <?php if ($checkout_type === 'service'): ?>

        <!-- Booking Details -->
        <div class="co-card" style="margin-bottom:1.25rem;">
            <div class="co-card-head">
                <div class="co-card-head-icon">📅</div>
                <div class="co-card-head-title">Booking Details</div>
            </div>
            <div class="co-card-body">

                <!-- Service type -->
                <div class="co-field">
                    <label class="co-label">Service Type <span>*</span></label>
                    <div class="svc-type-group">
                        <div class="svc-type-btn active" id="svcbtn-onsite"
                             onclick="selectServiceType('onsite')">
                            <span class="svc-type-icon">🏢</span>
                            <span class="svc-type-label">At the Spa</span>
                            <span class="svc-type-sub">Visit our branch</span>
                        </div>
                        <?php if (!empty($checkout_items[0]['is_home_service'])): ?>
                        <div class="svc-type-btn" id="svcbtn-home"
                             onclick="selectServiceType('home')">
                            <span class="svc-type-icon">🏠</span>
                            <span class="svc-type-label">Home Service</span>
                            <span class="svc-type-sub">
                                +₱<?php echo number_format($checkout_items[0]['home_service_fee'],2); ?> fee
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="home-fee-badge" id="homeFeeNotice">
                        🏠 A home service fee of
                        <strong>₱<?php echo number_format($checkout_items[0]['home_service_fee'] ?? 0, 2); ?></strong>
                        will be added to your total.
                    </div>
                </div>

                <!-- Home address (hidden by default) -->
                <div id="homeAddressBlock" style="display:none;">
                    <div class="co-field">
                        <label class="co-label">Home Address <span>*</span></label>
                        <textarea name="home_address" id="home_address" class="co-input" rows="2"
                                  placeholder="Full address for home service..."></textarea>
                    </div>
                    <div class="co-field">
                        <label class="co-label">Home Service Notes</label>
                        <input type="text" name="home_notes" class="co-input"
                               placeholder="e.g. Gate code, building floor, landmark...">
                    </div>
                </div>

                <!-- Date & people -->
                <div class="co-input-row">
                    <div class="co-field">
                        <label class="co-label">Date <span>*</span></label>
                        <input type="date" id="booking_date_picker"
                               class="co-input"
                               min="<?php echo date('Y-m-d'); ?>"
                               max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                               onchange="loadSlots()"
                               required>
                        <div style="font-size:0.72rem;color:var(--gray);margin-top:4px;">
                            Open 10:00 AM – 10:00 PM · Book up to 30 days ahead
                        </div>
                    </div>
                    <div class="co-field">
                        <label class="co-label">Number of People <span>*</span></label>
                        <div style="display:flex;align-items:center;gap:0.6rem;">
                            <button type="button" onclick="changePeople(-1)"
                                    style="width:38px;height:38px;border-radius:10px;border:1.5px solid var(--cream2);
                                           background:var(--cream);font-size:1.1rem;cursor:pointer;
                                           display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                           transition:all .15s;" onmouseover="this.style.borderColor='var(--gold)'"
                                    onmouseout="this.style.borderColor='var(--cream2)'">−</button>
                            <input type="number" name="people_count" id="people_count"
                                   value="1" min="1" max="<?php echo max($total_qualified, 10); ?>" readonly
                                   class="co-input"
                                   style="text-align:center;font-weight:700;font-size:1.05rem;
                                          flex:1;cursor:default;">
                            <button type="button" onclick="changePeople(1)"
                                    style="width:38px;height:38px;border-radius:10px;border:1.5px solid var(--cream2);
                                           background:var(--cream);font-size:1.1rem;cursor:pointer;
                                           display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                           transition:all .15s;" onmouseover="this.style.borderColor='var(--gold)'"
                                    onmouseout="this.style.borderColor='var(--cream2)'">+</button>
                        </div>
                        <div id="capacity-warning" style="display:none;margin-top:0.5rem;
                             padding:0.5rem 0.75rem;background:#fffbeb;border-radius:8px;
                             border:1px solid #f59e0b;font-size:0.76rem;color:#92400e;
                             font-family:'DM Sans',sans-serif;"></div>
                    </div>
                </div>

                <!-- Hidden actual booking_date submitted with form -->
                <input type="hidden" name="booking_date" id="booking_date_input" required>

                <!-- Slot picker -->
                <div id="slot-section" style="display:none;margin-bottom:1.1rem;">
                    <label class="co-label">Available Times <span>*</span></label>
                    <div id="slot-loading" style="display:none;text-align:center;padding:1.5rem;color:var(--gray);font-size:0.85rem;">
                        ⏳ Checking availability...
                    </div>
                    <div id="slot-unavailable" style="display:none;padding:1rem;background:#fff5f5;
                         border:1px solid #fecaca;border-radius:10px;font-size:0.85rem;color:#991b1b;text-align:center;">
                    </div>
                    <div id="slot-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;"></div>
                    <div id="slot-warning" style="display:none;margin-top:0.65rem;padding:0.55rem 0.85rem;
                         background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;
                         font-size:0.76rem;color:#92400e;font-family:'DM Sans',sans-serif;"></div>
                </div>

            </div>
        </div>

        <!-- Special Requests -->
        <div class="co-card" style="margin-bottom:1.25rem;">
            <div class="co-card-head">
                <div class="co-card-head-icon">💬</div>
                <div class="co-card-head-title">Special Requests & Notes</div>
            </div>
            <div class="co-card-body">
                <div class="co-note-wrap">
                    <div class="co-note-label">
                        ✨ Anything we should know?
                        <span style="font-size:0.68rem;color:var(--gray);font-weight:400;text-transform:none;letter-spacing:0;">
                            optional
                        </span>
                    </div>
                    <textarea name="customer_note" class="co-note-textarea"
                              placeholder="e.g. I prefer a female therapist · I have a sore left shoulder · Please avoid strong pressure · Allergic to certain oils · First time visiting..."
                              maxlength="500"></textarea>
                    <div style="text-align:right;font-size:0.68rem;color:var(--gray);margin-top:4px;">
                        <span id="noteCount">0</span>/500
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                    <span style="font-size:0.72rem;color:var(--gray);margin-right:0.2rem;align-self:center;">Quick add:</span>
                    <?php
                    $quick_tags = ['Female therapist','Male therapist','Light pressure','Firm pressure','First time visit','Sore neck/shoulders','Lower back pain','Sensitive skin'];
                    foreach ($quick_tags as $tag): ?>
                    <button type="button" onclick="addQuickNote('<?php echo $tag; ?>')"
                            style="padding:0.25rem 0.65rem;border-radius:20px;border:1px solid var(--cream2);
                                   background:var(--cream);font-size:0.72rem;color:var(--brown);
                                   cursor:pointer;transition:all .15s;font-family:'DM Sans',sans-serif;"
                            onmouseover="this.style.borderColor='var(--gold)';this.style.background='#fff8f2'"
                            onmouseout="this.style.borderColor='var(--cream2)';this.style.background='var(--cream)'">
                        <?php echo $tag; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Discount / Voucher -->
        <div class="co-card" style="margin-bottom:1.25rem;" id="discountCard">
            <div class="co-card-head">
                <div class="co-card-head-icon">🎟️</div>
                <div class="co-card-head-title">Discount / Voucher</div>
            </div>
            <div class="co-card-body">
                <p style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;">
                    Do you have a voucher, Senior Citizen, or PWD discount?
                </p>

                <!-- Discount type buttons -->
                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.5rem;margin-bottom:1rem;">
                    <div class="disc-btn active" id="discbtn-none" onclick="selectDiscount('none')"
                         style="padding:0.65rem 0.5rem;border:2px solid var(--border);border-radius:10px;
                                text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1.1rem;">🚫</div>
                        <div style="font-size:0.75rem;font-weight:600;margin-top:0.2rem;">None</div>
                    </div>
                    <div class="disc-btn" id="discbtn-voucher" onclick="selectDiscount('voucher')"
                         style="padding:0.65rem 0.5rem;border:2px solid var(--border);border-radius:10px;
                                text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1.1rem;">🎟️</div>
                        <div style="font-size:0.75rem;font-weight:600;margin-top:0.2rem;">Voucher</div>
                    </div>
                    <div class="disc-btn" id="discbtn-senior" onclick="selectDiscount('senior')"
                         style="padding:0.65rem 0.5rem;border:2px solid var(--border);border-radius:10px;
                                text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1.1rem;">👴</div>
                        <div style="font-size:0.75rem;font-weight:600;margin-top:0.2rem;">Senior</div>
                        <div style="font-size:0.68rem;color:#6b7280;">20% off</div>
                    </div>
                    <div class="disc-btn" id="discbtn-pwd" onclick="selectDiscount('pwd')"
                         style="padding:0.65rem 0.5rem;border:2px solid var(--border);border-radius:10px;
                                text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1.1rem;">♿</div>
                        <div style="font-size:0.75rem;font-weight:600;margin-top:0.2rem;">PWD</div>
                        <div style="font-size:0.68rem;color:#6b7280;">20% off</div>
                    </div>
                    <div class="disc-btn" id="discbtn-employee" onclick="selectDiscount('employee')"
                         style="padding:0.65rem 0.5rem;border:2px solid var(--border);border-radius:10px;
                                text-align:center;cursor:pointer;transition:all .15s;">
                        <div style="font-size:1.1rem;">🪪</div>
                        <div style="font-size:0.75rem;font-weight:600;margin-top:0.2rem;">Staff</div>
                        <div style="font-size:0.68rem;color:#6b7280;">50% off</div>
                    </div>
                </div>

                <!-- Onsite confirmation notice (shown when any discount selected) -->
                <div id="discountOnsiteNotice" style="display:none;
                     background:#fff3cd;border:1px solid #f59e0b;border-radius:8px;
                     padding:0.75rem 1rem;font-size:0.82rem;color:#92400e;margin-bottom:0.75rem;">
                    ⚠️ <strong>Onsite payment required.</strong>
                    Bookings with discounts/vouchers must be paid at the spa.
                    Please bring your <span id="discountProofLabel">voucher / ID</span> when you arrive —
                    the receptionist will verify it and apply the discount before approving your appointment.
                </div>

                <input type="hidden" name="discount_type" id="discount_type_input" value="none">
            </div>
        </div>

        <!-- Payment Method -->
        <div class="co-card" style="margin-bottom:1.25rem;">
            <div class="co-card-head">
                <div class="co-card-head-icon">💳</div>
                <div class="co-card-head-title">Payment Method</div>
            </div>
            <div class="co-card-body">
                <div class="pay-group" id="paymentBtnsGroup">
                    <div class="pay-btn active" id="paybtn-onsite" onclick="selectPayment('onsite')">
                        <span class="pay-btn-icon">🏪</span>
                        <span class="pay-btn-label">Pay at Spa</span>
                        <span class="pay-btn-sub">Cash when you arrive</span>
                    </div>
                    <div class="pay-btn" id="paybtn-online" onclick="selectPayment('online')">
                        <span class="pay-btn-icon">💳</span>
                        <span class="pay-btn-label">Pay Online</span>
                        <span class="pay-btn-sub">GCash · Maya · Cards</span>
                        <span class="pay-badge">Secure via PayMongo</span>
                    </div>
                </div>
                <div class="pay-note" id="pay-note">
                    🏪 <strong>Pay at Spa:</strong> Your booking will be set to
                    <strong>Pending</strong> — pay when you arrive and staff will confirm.
                </div>

                <button type="submit" name="place_order" id="placeOrderBtn" class="co-submit">
                    ✅ Confirm Booking
                </button>
                <a href="<?php echo $checkout_type === 'service' ? 'index.php' : 'cart.php'; ?>"
                   class="co-back">← Go Back</a>
                <div class="co-secure">
                    🔒 Secured by 256-bit SSL encryption
                </div>
            </div>
        </div>

            </form>
        </div>

        <!-- ── RIGHT: ORDER SUMMARY ────────────────────────────────────── -->
        <div class="co-summary">
            <div class="co-card">
                <div class="co-card-head">
                    <div class="co-card-head-icon">🛍️</div>
                    <div class="co-card-head-title">Order Summary</div>
                </div>
                <div class="co-card-body">
                    <?php foreach ($checkout_items as $item):
                        $img = $item['type'] === 'service'
                            ? '/spa_ecommerce_system/uploads/services/' . $item['image']
                            : '/spa_ecommerce_system/uploads/products/' . $item['image'];
                    ?>
                    <div class="co-item">
                        <img src="<?php echo $img; ?>"
                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                             class="co-item-img">
                        <div style="flex:1;min-width:0;">
                            <div class="co-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                            <?php if (isset($item['session_time'])): ?>
                            <div class="co-item-meta">⏱ <?php echo $item['session_time']; ?> mins</div>
                            <?php endif; ?>
                            <div class="co-item-meta">Qty: <?php echo $item['quantity']; ?></div>
                        </div>
                        <div class="co-item-price">
                            ₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="co-totals">
                        <div class="co-total-row">
                            <span>Subtotal</span>
                            <span id="subtotalDisplay">₱<?php echo number_format($total_amount, 2); ?></span>
                        </div>
                        <div class="co-total-row" id="homeFeeRow" style="display:none;">
                            <span>🏠 Home Service Fee</span>
                            <span id="homeFeeAmt">+₱<?php echo number_format($checkout_items[0]['home_service_fee'] ?? 0, 2); ?></span>
                        </div>
                        <div class="co-total-row" id="discountRow" style="display:none;color:#16a34a;">
                            <span id="discountLabel">🎟️ Discount</span>
                            <span id="discountAmt">−₱0.00</span>
                        </div>
                        <div class="co-total-row">
                            <span>Service Charge</span>
                            <span>Free</span>
                        </div>
                        <div class="co-total-row grand">
                            <span>Total</span>
                            <span id="grandTotal">₱<?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    </div>

                    <!-- Service info box -->
                    <?php if ($checkout_type === 'service'): ?>
                    <div style="margin-top:1.25rem;padding:1rem;background:var(--cream);
                                border-radius:10px;border:1px solid var(--cream2);">
                        <div style="font-size:0.72rem;font-weight:700;color:var(--gray);
                                    text-transform:uppercase;letter-spacing:.06em;margin-bottom:0.6rem;">
                            What to expect
                        </div>
                        <div style="font-size:0.8rem;color:var(--brown);line-height:1.7;">
                            📍 Please arrive <strong>15–20 minutes early</strong><br>
                            🧴 Wear comfortable clothing<br>
                            📵 Keep your phone on silent<br>
                            💧 Stay hydrated before your session
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- end co-grid -->
</div><!-- end co-wrap -->

<?php
$total_qualified   = $total_qualified   ?? 0;
$on_duty_qualified = $on_duty_qualified ?? 0;
?>
<script>
// ── Capacity data from PHP ────────────────────────────────────────────────────
const TOTAL_QUALIFIED   = <?php echo $total_qualified; ?>;
const ON_DUTY_QUALIFIED = <?php echo $on_duty_qualified; ?>;

// ── People counter ────────────────────────────────────────────────────────────
function changePeople(delta) {
    const inp = document.getElementById('people_count');
    let val = parseInt(inp.value) + delta;
    if (val < 1) val = 1;
    if (val > Math.max(TOTAL_QUALIFIED, 10)) val = Math.max(TOTAL_QUALIFIED, 10);
    inp.value = val;
    checkCapacityWarning(val);
}

function checkCapacityWarning(count) {
    const warn    = document.getElementById('capacity-warning');
    const dateInp = document.getElementById('booking_date_input');
    if (!warn) return;

    const isToday = dateInp?.value
        ? new Date(dateInp.value).toDateString() === new Date().toDateString()
        : false;

    const limit = isToday && ON_DUTY_QUALIFIED > 0 ? ON_DUTY_QUALIFIED : TOTAL_QUALIFIED;

    if (limit > 0 && count > limit) {
        const context = isToday && ON_DUTY_QUALIFIED > 0
            ? `only <strong>${ON_DUTY_QUALIFIED}</strong> qualified therapist(s) on duty today`
            : `only <strong>${TOTAL_QUALIFIED}</strong> qualified therapist(s) available for this service`;
        warn.innerHTML = `⚠️ You selected <strong>${count} people</strong> but we have ${context}. Your booking will be accepted but may need rescheduling.`;
        warn.style.display = '';
    } else {
        warn.style.display = 'none';
    }
}

// ── Service type ──────────────────────────────────────────────────────────────
const BASE_PRICE    = <?php echo floatval($checkout_items[0]['price'] ?? 0); ?>;
const HOME_FEE      = <?php echo floatval($checkout_items[0]['home_service_fee'] ?? 0); ?>;

function selectServiceType(type) {
    ['onsite','home'].forEach(t => {
        const btn = document.getElementById('svcbtn-' + t);
        if (btn) btn.classList.toggle('active', t === type);
    });
    document.getElementById('service_type_hidden').value = type;

    const homeBlock  = document.getElementById('homeAddressBlock');
    const homeFeeRow = document.getElementById('homeFeeRow');
    const homeNotice = document.getElementById('homeFeeNotice');
    const homeAddr   = document.getElementById('home_address');

    if (type === 'home') {
        if (homeBlock)  homeBlock.style.display  = '';
        if (homeFeeRow) homeFeeRow.style.display  = '';
        if (homeNotice) homeNotice.style.display  = '';
        if (homeAddr)   homeAddr.required = true;
    } else {
        if (homeBlock)  homeBlock.style.display  = 'none';
        if (homeFeeRow) homeFeeRow.style.display  = 'none';
        if (homeNotice) homeNotice.style.display  = 'none';
        if (homeAddr)   homeAddr.required = false;
    }
    updateDiscountPreview();
}

// ── Payment method ────────────────────────────────────────────────────────────
function selectPayment(method) {
    ['onsite','online'].forEach(m => {
        document.getElementById('paybtn-' + m)?.classList.toggle('active', m === method);
    });
    document.getElementById('payment_method').value = method;

    const note = document.getElementById('pay-note');
    const btn  = document.getElementById('placeOrderBtn');
    if (method === 'online') {
        note.innerHTML    = '💳 <strong>Online Payment:</strong> You\'ll be redirected to PayMongo to pay securely via <strong>GCash, Maya, or Card</strong>.';
        note.style.borderLeftColor = '#198754';
        note.style.background      = '#f0fdf4';
        btn.textContent   = '💳 Proceed to Payment';
    } else {
        note.innerHTML    = '🏪 <strong>Pay at Spa:</strong> Your booking will be set to <strong>Pending</strong> — pay when you arrive and staff will confirm.';
        note.style.borderLeftColor = '#C96A2C';
        note.style.background      = '#fff8f2';
        btn.textContent   = '✅ Confirm Booking';
    }
}

// ── Note character counter ────────────────────────────────────────────────────
const noteTA = document.querySelector('textarea[name="customer_note"]');
const noteCount = document.getElementById('noteCount');
if (noteTA && noteCount) {
    noteTA.addEventListener('input', () => {
        noteCount.textContent = noteTA.value.length;
        noteCount.style.color = noteTA.value.length > 450 ? '#dc2626' : '';
    });
}

// ── Quick note tags ───────────────────────────────────────────────────────────
function addQuickNote(tag) {
    if (!noteTA) return;
    const cur = noteTA.value.trim();
    noteTA.value = cur ? cur + ' · ' + tag : tag;
    noteTA.dispatchEvent(new Event('input'));
    noteTA.focus();
}

// ── SLOT PICKER ───────────────────────────────────────────────────────────────
const SERVICE_ID = <?php echo intval($service_id ?? 0); ?>;
let selectedSlot = null;

function loadSlots() {
    const datePicker = document.getElementById('booking_date_picker');
    const people     = parseInt(document.getElementById('people_count').value) || 1;
    const rateType   = document.querySelector('[name="rate_type"]')?.value || 'regular';
    if (!datePicker.value) return;

    const slotSection    = document.getElementById('slot-section');
    const slotLoading    = document.getElementById('slot-loading');
    const slotGrid       = document.getElementById('slot-grid');
    const slotUnavail    = document.getElementById('slot-unavailable');
    const slotWarning    = document.getElementById('slot-warning');
    const bookingHidden  = document.getElementById('booking_date_input');

    slotSection.style.display = 'block';
    slotLoading.style.display = 'block';
    slotGrid.style.display    = 'none';
    slotGrid.innerHTML        = '';
    slotUnavail.style.display = 'none';
    slotWarning.style.display = 'none';
    bookingHidden.value       = '';
    selectedSlot              = null;

    fetch(`../admin/slots.php?service_id=${SERVICE_ID}&date=${datePicker.value}&people=${people}&rate_type=${rateType}`)
        .then(r => r.json())
        .then(data => {
            slotLoading.style.display = 'none';

            if (data.error || data.service_status === 'unavailable') {
                slotUnavail.style.display = 'block';
                slotUnavail.textContent   = '⚠️ ' + (data.message || 'This service is currently unavailable.');
                return;
            }

            if (!data.slots || data.slots.length === 0) {
                slotUnavail.style.display = 'block';
                slotUnavail.textContent   = '⚠️ No time slots available for this date.';
                return;
            }

            slotGrid.style.display = 'grid';
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type  = 'button';
                btn.textContent = slot.time_label;

                if (slot.status === 'unavailable' || slot.is_past) {
                    btn.disabled = true;
                    btn.style.cssText = `
                        padding:0.55rem 0.4rem;border-radius:8px;font-size:0.8rem;font-weight:600;
                        border:1.5px solid #e5e7eb;background:#f9fafb;color:#9ca3af;cursor:not-allowed;
                        opacity:0.6;text-decoration:${slot.is_past ? 'line-through' : 'none'};`;
                    if (slot.reason && !slot.is_past) {
                        btn.title = slot.reason;
                    }
                } else if (slot.status === 'warning') {
                    btn.style.cssText = `
                        padding:0.55rem 0.4rem;border-radius:8px;font-size:0.8rem;font-weight:600;
                        border:1.5px solid #f59e0b;background:#fffbeb;color:#92400e;cursor:pointer;
                        transition:all .15s;`;
                    btn.title = slot.reason;
                    btn.onclick = () => selectSlot(btn, slot, data.slots);
                } else {
                    btn.style.cssText = `
                        padding:0.55rem 0.4rem;border-radius:8px;font-size:0.8rem;font-weight:600;
                        border:1.5px solid var(--cream2);background:var(--cream);color:var(--brown);
                        cursor:pointer;transition:all .15s;`;
                    btn.onclick = () => selectSlot(btn, slot, data.slots);
                }
                slotGrid.appendChild(btn);
            });

            if (data.available_count === 0) {
                slotUnavail.style.display = 'block';
                slotUnavail.innerHTML = '⚠️ No available slots for this date. <br><small>Please try a different date.</small>';
            }
        })
        .catch(() => {
            slotLoading.style.display = 'none';
            slotUnavail.style.display = 'block';
            slotUnavail.textContent   = '⚠️ Could not load availability. Please try again.';
        });
}

function selectSlot(btn, slot, allSlots) {
    // Deselect all
    document.querySelectorAll('#slot-grid button').forEach(b => {
        if (!b.disabled) {
            const isWarning = b.style.borderColor.includes('f59e0b');
            b.style.background = isWarning ? '#fffbeb' : 'var(--cream)';
            b.style.borderColor = isWarning ? '#f59e0b' : 'var(--cream2)';
            b.style.color = isWarning ? '#92400e' : 'var(--brown)';
        }
    });

    // Select this slot
    btn.style.background  = '#C96A2C';
    btn.style.borderColor = '#C96A2C';
    btn.style.color       = '#fff';

    selectedSlot = slot;
    document.getElementById('booking_date_input').value = slot.datetime;

    // Show warning if applicable
    const warnEl = document.getElementById('slot-warning');
    if (slot.status === 'warning') {
        warnEl.style.display = 'block';
        warnEl.innerHTML = `⚠️ ${slot.reason} — <strong>Receptionist will contact you to confirm arrangement.</strong>`;
    } else {
        warnEl.style.display = 'none';
    }

    // Update booking confirmation area
    updateBookingConfirmation();
}

// Reload slots and totals when people count changes
const origChangePeople = window.changePeople;
function changePeople(delta) {
    const inp = document.getElementById('people_count');
    let val = parseInt(inp.value) + delta;
    if (val < 1) val = 1;
    if (val > Math.max(TOTAL_QUALIFIED, 10)) val = Math.max(TOTAL_QUALIFIED, 10);
    inp.value = val;
    checkCapacityWarning(val);
    updateDiscountPreview();
    // Reload slots with new people count
    if (document.getElementById('booking_date_picker')?.value) loadSlots();
}

// ── Discount / Voucher ────────────────────────────────────────────────────────
const BASE_TOTAL_AMOUNT = <?php echo $total_amount; ?>;
let currentDiscount = 'none';

function updatePaymentOptionsForDiscount(type) {
    const onlineBtn = document.getElementById('paybtn-online');
    if (type !== 'none') {
        selectPayment('onsite');
        if (onlineBtn) onlineBtn.style.display = 'none';
    } else {
        if (onlineBtn) onlineBtn.style.display = '';
    }
}

function selectDiscount(type) {
    currentDiscount = type;
    document.getElementById('discount_type_input').value = type;

    // Update button styles
    ['none','voucher','senior','pwd','employee'].forEach(t => {
        const btn = document.getElementById('discbtn-' + t);
        if (!btn) return;
        btn.style.borderColor = t === type ? '#C96A2C' : 'var(--border)';
        btn.style.background  = t === type ? '#fff8f2' : '';
    });

    // Show/hide onsite notice + set proof label
    const notice     = document.getElementById('discountOnsiteNotice');
    const proofLabel = document.getElementById('discountProofLabel');
    if (type !== 'none') {
        notice.style.display = 'block';
        if (type === 'senior')         proofLabel.textContent = 'Senior Citizen ID';
        else if (type === 'pwd')       proofLabel.textContent = 'PWD ID';
        else if (type === 'employee')  proofLabel.textContent = 'Employee ID';
        else                           proofLabel.textContent = 'voucher';
    } else {
        notice.style.display = 'none';
    }

    updatePaymentOptionsForDiscount(type);
    updateDiscountPreview();
}

function updateDiscountPreview() {
    const type    = currentDiscount;
    const discRow = document.getElementById('discountRow');
    const discLbl = document.getElementById('discountLabel');
    const discAmt = document.getElementById('discountAmt');
    const grandEl = document.getElementById('grandTotal');
    if (!discRow || !grandEl) return;

    const people = parseInt(document.getElementById('people_count')?.value || 1) || 1;
    const homeFeeRow = document.getElementById('homeFeeRow');
    const homeFeePerPerson = (homeFeeRow && homeFeeRow.style.display !== 'none')
        ? parseFloat(document.getElementById('homeFeeAmt')?.textContent?.replace(/[^0-9.]/g,'') || 0) : 0;
    let subtotal = (BASE_TOTAL_AMOUNT + homeFeePerPerson) * people;
    const subtotalEl = document.getElementById('subtotalDisplay');
    if (subtotalEl) subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    let discountAmt = 0;

    if (type === 'senior') {
        discountAmt = subtotal * 0.20;
        discLbl.textContent = '👴 Senior Citizen (20%)';
    } else if (type === 'pwd') {
        discountAmt = subtotal * 0.20;
        discLbl.textContent = '♿ PWD (20%)';
    } else if (type === 'employee') {
        discountAmt = subtotal * 0.50;
        discLbl.textContent = '🪪 Employee (50%)';
    } else if (type === 'voucher') {
        // Voucher amount is confirmed onsite — show placeholder row only
        discLbl.textContent = '🎟️ Voucher (confirmed onsite)';
        discAmt.textContent = '−₱TBD';
        discRow.style.display = '';
        // Don't recalculate grand total for voucher — stays at full price until onsite confirmation
        grandEl.textContent = '₱' + subtotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        return;
    }

    if (discountAmt > 0) {
        discRow.style.display = '';
        discAmt.textContent   = '−₱' + discountAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        discRow.style.display = type !== 'none' ? '' : 'none';
        discAmt.textContent   = '−₱0.00';
    }

    const finalTotal = Math.max(0, subtotal - discountAmt);
    grandEl.textContent = '₱' + finalTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Init
selectPayment('onsite');
selectDiscount('none');

<?php if ($checkout_type === 'service'): ?>
// Prevent form submission when no time slot has been selected.
// `required` on type="hidden" is ignored by all browsers, so we enforce it here.
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    const bookingDate = document.getElementById('booking_date_input').value;
    if (bookingDate) return; // slot selected — proceed

    e.preventDefault();

    const datePicker  = document.getElementById('booking_date_picker');
    const slotSection = document.getElementById('slot-section');
    const errBox      = document.getElementById('slot-unavailable');

    if (!datePicker.value) {
        // User hasn't even chosen a date yet — highlight the date picker
        datePicker.style.outline = '2px solid #ef4444';
        datePicker.focus();
        datePicker.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => { datePicker.style.outline = ''; }, 3000);
    } else {
        // Date chosen but no slot clicked — reveal the slot grid with an inline error
        slotSection.style.display = 'block';
        errBox.style.display      = 'block';
        errBox.textContent        = '⚠️ Please select a time slot before confirming your booking.';
        slotSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});
<?php endif; ?>
</script>

<footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand"><div class="ft-logo">RECOVERY ILOILO</div><p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p></div>
        <div class="footer-col"><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="index.php#services">Services</a></li><li><a href="index.php#products">Products</a></li><li><a href="index.php#about">About Us</a></li><li><a href="index.php#contact">Contact</a></li></ul></div>
        <div class="footer-col"><h4>Services</h4><ul><li><a href="index.php#services">Massage Therapy</a></li><li><a href="index.php#services">Nail Care</a></li><li><a href="index.php#services">Lash Services</a></li><li><a href="index.php#services">Facial Treatments</a></li><li><a href="index.php#services">Body Scrubs</a></li></ul></div>
        <div class="footer-col"><h4>Contact</h4><ul><li><a href="index.php#contact">Iloilo City, Philippines</a></li><li><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></li><li><a href="tel:+639853359998">+639853359998</a></li><li><a href="index.php#contact">Mon – Sun: 10AM – 10PM</a></li></ul></div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>
</body>
</html>