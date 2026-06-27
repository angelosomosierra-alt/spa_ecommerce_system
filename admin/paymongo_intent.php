<?php
/**
 * paymongo_intent.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Server-side AJAX endpoint for the PayMongo payment intent / OTP flow.
 * The secret key NEVER leaves the server — all API calls happen here.
 *
 * Actions (POST):
 *   create_intent  → creates a payment_intent, returns intent_id + client_key
 *   attach_method  → creates a payment_method and attaches it to the intent
 *   confirm_otp    → re-checks intent status (OTP confirmation is redirect-based)
 *   check_intent   → polls intent status, returns status + payment reference
 */

require_once '../config.php';
redirect_if_not_admin();
header('Content-Type: application/json');

if (!verify_csrf_token_ajax()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';
$secret = PAYMONGO_SECRET_KEY;

function pm_call(string $method, string $endpoint, array $body = [], string $secret = ''): array {
    $ch = curl_init('https://api.paymongo.com/v1' . $endpoint);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secret . ':'),
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($method !== 'GET' && !empty($body)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(['data' => ['attributes' => $body]]);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['_http_code' => 0, '_curl_error' => $err];
    }
    $res = json_decode($raw, true) ?? [];
    $res['_http_code'] = $code;
    return $res;
}

function pm_call_raw(string $method, string $endpoint, ?string $json_body, string $secret): array {
    $ch = curl_init('https://api.paymongo.com/v1' . $endpoint);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($secret . ':'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($json_body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
    }
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['ok' => false, 'data' => null, 'code' => 0, '_curl_error' => $curl_err];
    }
    return [
        'ok'   => $http_code >= 200 && $http_code < 300,
        'data' => json_decode($response, true),
        'code' => $http_code,
    ];
}

// ── CREATE INTENT ─────────────────────────────────────────────────────────────
if ($action === 'create_intent') {
    $amount_centavos = intval($_POST['amount_centavos'] ?? 0);
    $pm_method       = $_POST['pm_method'] ?? '';
    $description     = sanitize_input($_POST['description'] ?? 'Walk-in payment');

    $allowed_api_methods = ['gcash', 'paymaya', 'card'];
    // Normalize: frontend sends 'maya', API expects 'paymaya'
    $api_method = ['gcash' => 'gcash', 'maya' => 'paymaya', 'card' => 'card'][$pm_method] ?? $pm_method;

    if ($amount_centavos < 2000 || !in_array($api_method, $allowed_api_methods)) {
        echo json_encode(['error' => 'Invalid amount or payment method']);
        exit;
    }

    $res = pm_call('POST', '/payment_intents', [
        'amount'                 => $amount_centavos,
        'currency'               => 'PHP',
        'capture_type'           => 'automatic',
        'description'            => $description,
        'payment_method_allowed' => [$api_method],
    ], $secret);

    if (!empty($res['_curl_error'])) {
        echo json_encode(['error' => 'Connection error: ' . $res['_curl_error']]);
        exit;
    }
    if (!empty($res['errors'])) {
        echo json_encode(['error' => $res['errors'][0]['detail'] ?? 'PayMongo error']);
        exit;
    }

    $intent_id  = $res['data']['id'] ?? '';
    $client_key = $res['data']['attributes']['client_key'] ?? '';
    if (!$intent_id) {
        echo json_encode(['error' => 'No intent ID returned from PayMongo']);
        exit;
    }

    echo json_encode(['intent_id' => $intent_id, 'client_key' => $client_key]);
    exit;
}

// ── ATTACH METHOD ─────────────────────────────────────────────────────────────
if ($action === 'attach_method') {
    $intent_id = sanitize_input($_POST['intent_id'] ?? '');
    $pm_method = $_POST['pm_method'] ?? '';
    $phone     = sanitize_input($_POST['phone']     ?? '');
    $name      = sanitize_input($_POST['name']      ?? '');
    $email     = sanitize_input($_POST['email']     ?? '');

    // Card details
    $card_number = preg_replace('/\s+/', '', $_POST['card_number']  ?? '');
    $card_exp_m  = intval($_POST['card_exp_month']  ?? 0);
    $card_exp_y  = intval($_POST['card_exp_year']   ?? 0);
    $card_cvv    = $_POST['card_cvv'] ?? '';

    if (!$intent_id || !in_array($pm_method, ['gcash', 'maya', 'card'])) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    // Normalize phone number to +639XXXXXXXXX
    $_raw = preg_replace('/\D/', '', $phone);
    if (str_starts_with($_raw, '63'))   $_raw = substr($_raw, 2);
    elseif (str_starts_with($_raw, '0')) $_raw = substr($_raw, 1);
    $pm_phone = '+63' . $_raw;
    if (!preg_match('/^\+639\d{9}$/', $pm_phone)) $pm_phone = null;

    $api_type = ['gcash' => 'gcash', 'maya' => 'paymaya', 'card' => 'card'][$pm_method];

    // Build payment method attributes
    $method_attrs = ['type' => $api_type];
    if ($pm_method === 'gcash' || $pm_method === 'maya') {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Customer email is required for GCash/Maya payment']);
            exit;
        }
        $billing = array_filter([
            'name'  => $name  ?: null,
            'phone' => $pm_phone ?: null,
            'email' => $email,
        ]);
        $method_attrs['billing'] = $billing;
    } elseif ($pm_method === 'card') {
        if (!$card_number || !$card_exp_m || !$card_exp_y || !$card_cvv) {
            echo json_encode(['error' => 'Please fill in all card details']);
            exit;
        }
        $method_attrs['details'] = [
            'card_number' => $card_number,
            'exp_month'   => $card_exp_m,
            'exp_year'    => $card_exp_y,
            'cvc'         => $card_cvv,
        ];
        $card_billing = array_filter([
            'name'  => $name  ?: null,
            'email' => $email ?: null,
        ]);
        if (!empty($card_billing)) $method_attrs['billing'] = $card_billing;
    }

    // Create payment method
    $pm_res = pm_call('POST', '/payment_methods', $method_attrs, $secret);
    if (!empty($pm_res['_curl_error'])) {
        echo json_encode(['error' => 'Connection error: ' . $pm_res['_curl_error']]); exit;
    }
    if (!empty($pm_res['errors'])) {
        echo json_encode(['error' => $pm_res['errors'][0]['detail'] ?? 'Could not create payment method']); exit;
    }
    $pm_id = $pm_res['data']['id'] ?? '';
    if (!$pm_id) {
        echo json_encode(['error' => 'No payment method ID returned']); exit;
    }

    // Attach to intent (triggers GCash/Maya redirect or 3DS for cards)
    $attach_res = pm_call('POST', '/payment_intents/' . $intent_id . '/attach', [
        'payment_method' => $pm_id,
        'return_url'     => BASE_URL . 'admin/walkin.php',
    ], $secret);

    if (!empty($attach_res['_curl_error'])) {
        echo json_encode(['error' => 'Connection error: ' . $attach_res['_curl_error']]); exit;
    }
    if (!empty($attach_res['errors'])) {
        echo json_encode(['error' => $attach_res['errors'][0]['detail'] ?? 'Attach failed']); exit;
    }

    $status      = $attach_res['data']['attributes']['status'] ?? '';
    $next_action = $attach_res['data']['attributes']['next_action'] ?? null;
    $reference   = '';
    if ($status === 'succeeded') {
        $payments  = $attach_res['data']['attributes']['payments'] ?? [];
        $reference = $payments[0]['id'] ?? $intent_id;
    }

    echo json_encode([
        'status'      => $status,
        'pm_id'       => $pm_id,
        'next_action' => $next_action,
        'reference'   => $reference,
    ]);
    exit;
}

// ── CONFIRM OTP ───────────────────────────────────────────────────────────────
// For PayMongo, OTP authorization is redirect-based. This action checks
// whether the intent has been authorized since the redirect.
if ($action === 'confirm_otp') {
    $intent_id = sanitize_input($_POST['intent_id'] ?? '');
    if (!$intent_id) {
        echo json_encode(['error' => 'Missing intent ID']); exit;
    }

    $res    = pm_call('GET', '/payment_intents/' . $intent_id, [], $secret);
    $status = $res['data']['attributes']['status'] ?? 'unknown';

    $reference = '';
    if ($status === 'succeeded') {
        $payments  = $res['data']['attributes']['payments'] ?? [];
        $reference = $payments[0]['id'] ?? $intent_id;
    }

    echo json_encode(['status' => $status, 'reference' => $reference]);
    exit;
}

// ── CHECK INTENT ──────────────────────────────────────────────────────────────
if ($action === 'check_intent') {
    $intent_id = sanitize_input($_POST['intent_id'] ?? '');
    if (!$intent_id) {
        echo json_encode(['error' => 'No intent ID']); exit;
    }

    $res    = pm_call('GET', '/payment_intents/' . $intent_id, [], $secret);
    if (!empty($res['_curl_error'])) {
        echo json_encode(['error' => 'Connection error']); exit;
    }

    $status    = $res['data']['attributes']['status'] ?? 'unknown';
    $reference = '';
    if ($status === 'succeeded') {
        $payments  = $res['data']['attributes']['payments'] ?? [];
        $reference = $payments[0]['id'] ?? $intent_id;
    }

    echo json_encode(['status' => $status, 'reference' => $reference]);
    exit;
}

// ── QR Ph: create PayMongo Checkout Session, return hosted checkout URL ────────
if ($action === 'create_qrph') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $amount   = floatval($_POST['amount'] ?? 0);
    $desc     = sanitize_input($_POST['description'] ?? 'Recovery Spa Order');

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid amount']); exit;
    }

    $amount_centavos = (int) round($amount * 100);

    $session_payload = json_encode([
        'data' => [
            'attributes' => [
                'line_items'           => [[
                    'currency' => 'PHP',
                    'amount'   => $amount_centavos,
                    'name'     => $desc,
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['qrph'],
                'description'          => $desc,
                'send_email_receipt'   => false,
                'show_description'     => true,
                'show_line_items'      => true,
                'success_url' => BASE_URL . 'admin/walkin.php?qrph_done=1&order_id=' . $order_id,
                'cancel_url'  => BASE_URL . 'admin/walkin.php?qrph_cancel=1&order_id=' . $order_id,
            ]
        ]
    ]);

    $cs_res = pm_call_raw('POST', '/checkout_sessions', $session_payload, $secret);

    if (!$cs_res['ok']) {
        error_log('[QRPH] Checkout session creation failed: ' . json_encode($cs_res['data']));
        echo json_encode(['success' => false, 'error' => 'Failed to create QR Ph session']); exit;
    }

    $session_id   = $cs_res['data']['data']['id']                            ?? null;
    $checkout_url = $cs_res['data']['data']['attributes']['checkout_url']    ?? null;

    if (!$session_id || !$checkout_url) {
        error_log('[QRPH] No session ID or checkout URL: ' . json_encode($cs_res['data']));
        echo json_encode(['success' => false, 'error' => 'No checkout session returned']); exit;
    }

    $_SESSION['qrph_session_' . $order_id] = $session_id;

    echo json_encode([
        'success'      => true,
        'session_id'   => $session_id,
        'checkout_url' => $checkout_url,
    ]);
    exit;
}

// ── QR Ph: poll checkout session status ──────────────────────────────────────
if ($action === 'check_qrph') {
    $order_id   = intval($_POST['order_id']   ?? 0);
    $session_id = sanitize_input($_POST['session_id'] ?? '');
    if (!$session_id && $order_id) {
        $session_id = $_SESSION['qrph_session_' . $order_id] ?? '';
    }
    if (!$session_id) { echo json_encode(['success' => false, 'error' => 'No session found']); exit; }

    $check_res = pm_call_raw('GET', '/checkout_sessions/' . $session_id, null, $secret);
    if (!$check_res['ok']) {
        echo json_encode(['success' => false, 'error' => 'Failed to check status']); exit;
    }

    $payment_intent = $check_res['data']['data']['attributes']['payment_intent'] ?? null;
    $status         = $payment_intent['attributes']['status'] ?? 'awaiting_payment_method';
    $payments       = $payment_intent['attributes']['payments'] ?? [];
    $reference      = $payments[0]['attributes']['reference_number']
                   ?? $payments[0]['id']
                   ?? null;

    echo json_encode([
        'success'   => true,
        'status'    => $status,
        'reference' => $reference,
        'paid'      => $status === 'succeeded' || count($payments) > 0,
    ]);
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
