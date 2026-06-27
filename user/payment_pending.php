<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: CHECK PAYMENT STATUS
// Called by the "I Already Paid" button via fetch()
// Returns JSON: { status: 'paid' | 'pending' | 'error', message: '...' }
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_payment') {
    ob_clean();
    header('Content-Type: application/json');

    $order_id = intval($_POST['order_id'] ?? 0);

    if (!$order_id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        exit;
    }

    // Fetch order — must belong to this user
    $stmt = $conn->prepare("SELECT id, total_amount, payment_status, paymongo_link_id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        exit;
    }

    // Already paid — just confirm
    if ($order['payment_status'] === 'paid') {
        echo json_encode(['status' => 'paid', 'message' => '✅ Payment already confirmed!']);
        exit;
    }

    // Not paid yet and no PayMongo link
    if (empty($order['paymongo_link_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'No payment link found for this order.']);
        exit;
    }

    // ── Call PayMongo API to check payment status ──────────────────────────
    $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($order['paymongo_link_id']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $pm_status = $result['data']['attributes']['status'] ?? '';

    if ($pm_status === 'paid') {
        // Extract payment method from PayMongo link response
        $payments   = $result['data']['attributes']['payments'] ?? [];
        $raw_method = $payments[0]['attributes']['source']['type']
                   ?? $payments[0]['attributes']['payment_method_used']
                   ?? null;
        $pm_map     = ['gcash'=>'gcash','paymaya'=>'maya','card'=>'card',
                       'dob_ubp'=>'bank','dob'=>'bank','billease'=>'online'];
        $pm_method  = $pm_map[$raw_method] ?? $raw_method ?? 'online';
        $pm_ref     = $payments[0]['attributes']['reference_number']
                   ?? $payments[0]['id']
                   ?? ($order['paymongo_link_id'] ?? '');

        $stmt = $conn->prepare("
            UPDATE orders
            SET payment_status     = 'paid',
                approval_status    = 'pending',
                paymongo_method    = COALESCE(paymongo_method, ?),
                paymongo_reference = COALESCE(paymongo_reference, ?)
            WHERE id = ?
              AND payment_status != 'paid'
        ");
        $stmt->bind_param("ssi", $pm_method, $pm_ref, $order_id);
        $stmt->execute();
        $stmt->close();

        // ── Deduct stock from order_items (no session needed) ─────────────
        $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ? AND product_id IS NOT NULL");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $order_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($order_products as $item) {
            $upd = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $upd->bind_param("iii", $item['quantity'], $item['product_id'], $item['quantity']);
            $upd->execute();
            $upd->close();
        }

        // ── Clear ordered products from cart (session + DB) ───────────────
        foreach ($order_products as $item) {
            $pid = $item['product_id'];
            if (isset($_SESSION['cart'][$pid])) {
                unset($_SESSION['cart'][$pid]);
            }
            remove_cart_item_from_db($conn, $user_id, $pid);
        }

        // Sync remaining cart to DB
        if (!empty($_SESSION['cart'])) {
            sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
        } else {
            $_SESSION['cart'] = [];
            sync_cart_to_db($conn, $user_id, []);
        }

        // ── Notify user and admin ──────────────────────────────────────────
        require_once __DIR__ . '/../notify.php';
        $checkout_type = $_SESSION['paymongo_checkout_type'] ?? 'product';
        $is_service    = ($checkout_type === 'service');

        add_notification($conn, $user_id, $is_service ? 'appointment' : 'order',
            '💳 Payment Confirmed!',
            'Your online payment for order #' . $order_id . ' was successful. Awaiting admin approval.',
            $is_service ? 'appointments.php' : 'appointments.php#orders'
        );
        add_notification($conn, null, $is_service ? 'appointment' : 'order',
            '💳 New Online Payment',
            'Order #' . $order_id . ' (₱' . number_format($order['total_amount'], 2) . ') has been paid online and is awaiting your approval.',
            $is_service ? 'appointments.php' : 'orders.php'
        );

        // ── Clean up checkout session vars ─────────────────────────────────
        unset(
            $_SESSION['direct_checkout'],
            $_SESSION['service_booking'],
            $_SESSION['checkout_items'],
            $_SESSION['checkout_item_ids'],
            $_SESSION['paymongo_order_id'],
            $_SESSION['paymongo_checkout_type'],
            $_SESSION['paymongo_checkout_items'],
            $_SESSION['paymongo_checkout_ids']
        );

        echo json_encode(['status' => 'paid', 'message' => '✅ Payment confirmed! Your order is now pending admin approval.']);
        exit;

    } else {
        // PayMongo says not paid yet
        echo json_encode([
            'status'  => 'pending',
            'message' => 'Payment not confirmed yet. Please complete your payment on the PayMongo page first, then click the button again.'
        ]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PAGE LOAD
// ══════════════════════════════════════════════════════════════════════════════
$order_id = intval($_GET['order_id'] ?? 0);
$pay_url  = $_GET['pay_url'] ?? '';

if (!$order_id) {
    header("Location: index.php"); exit();
}

$stmt = $conn->prepare("SELECT id, total_amount, payment_status, paymongo_link_id FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header("Location: index.php"); exit();
}

// Already paid — skip this page
if ($order['payment_status'] === 'paid') {
    header("Location: payment_success.php?order_id=" . $order_id); exit();
}

// If pay_url missing (user refreshed page), fetch from PayMongo
if (empty($pay_url) && !empty($order['paymongo_link_id'])) {
    $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($order['paymongo_link_id']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $res     = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $pay_url = $res['data']['attributes']['checkout_url'] ?? '';
}

$page_title = 'Complete Your Payment';
require_once 'header.php';
?>

<style>
.pending-wrap {
    max-width: 540px;
    margin: 4rem auto;
    padding: 0 1rem 4rem;
    text-align: center;
}
.pending-card {
    background: #fff;
    border-radius: 20px;
    padding: 2.5rem 2rem;
    box-shadow: 0 8px 40px rgba(59,42,26,0.10);
    border: 1px solid #EAD8C0;
}
.pending-icon { font-size: 3.5rem; margin-bottom: 0.75rem; display: block; }
.pending-card h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.8rem; font-weight: 400;
    color: #3B2A1A; margin-bottom: 0.35rem;
}
.pending-card .order-ref { font-size: 0.85rem; color: #888; margin-bottom: 1.5rem; }
.pending-card .order-ref strong { color: #C96A2C; }

.amount-badge {
    display: inline-block;
    background: #3B2A1A; color: #C8A46B;
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.6rem; font-weight: 400;
    padding: 0.4rem 1.75rem; border-radius: 20px;
    margin-bottom: 1.75rem;
}

.steps-list {
    text-align: left;
    background: #FAF3E8;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.75rem;
    list-style: none;
}
.steps-list li {
    display: flex; align-items: flex-start; gap: 0.75rem;
    font-size: 0.88rem; color: #6B4C30;
    padding: 0.5rem 0;
    border-bottom: 1px solid #EAD8C0;
    line-height: 1.5;
}
.steps-list li:last-child { border-bottom: none; }
.steps-list .sn {
    width: 22px; height: 22px; border-radius: 50%;
    background: #C96A2C; color: #fff;
    font-size: 0.7rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; margin-top: 2px;
}

.btn-pay {
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    width: 100%; padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #C96A2C, #A94F1D);
    color: #fff; border: none; border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: 1rem; font-weight: 700;
    cursor: pointer; text-decoration: none;
    transition: opacity 0.2s, transform 0.15s;
    margin-bottom: 0.75rem;
    box-shadow: 0 4px 16px rgba(201,106,44,0.28);
}
.btn-pay:hover { opacity: 0.9; transform: translateY(-1px); }

.btn-confirm {
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    width: 100%; padding: 0.95rem 1.5rem;
    background: #fff; color: #3B2A1A;
    border: 2px solid #EAD8C0; border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.95rem; font-weight: 600;
    cursor: pointer;
    transition: border-color 0.2s, color 0.2s, box-shadow 0.2s;
    margin-bottom: 0.75rem;
}
.btn-confirm:hover:not(:disabled) { border-color: #198754; color: #198754; }
.btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

.check-msg {
    margin: 0.5rem 0 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    font-size: 0.88rem;
    line-height: 1.5;
    display: none;
    text-align: left;
}
.check-msg.success { background: #d1e7dd; color: #0a3622; border-left: 4px solid #198754; }
.check-msg.error   { background: #f8d7da; color: #842029; border-left: 4px solid #dc3545; }
.check-msg.pending { background: #fff3cd; color: #664d03; border-left: 4px solid #ffc107; }

.skip-link {
    font-size: 0.8rem; color: #aaa;
    text-decoration: underline; cursor: pointer;
    background: none; border: none;
    font-family: inherit;
}
.skip-link:hover { color: #888; }

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 0 rgba(25,135,84,0.3); }
    50%       { box-shadow: 0 0 0 8px rgba(25,135,84,0); }
}
.pulse { animation: pulse-green 1.5s infinite; border-color: #198754 !important; color: #198754 !important; }
</style>

<div class="pending-wrap">
<div class="pending-card">

    <span class="pending-icon">💳</span>
    <h2>Complete Your Payment</h2>
    <p class="order-ref">
        Order <strong>#<?php echo $order_id; ?></strong>
    </p>

    <div class="amount-badge">₱<?php echo number_format($order['total_amount'], 2); ?></div>

    <ul class="steps-list">
        <li>
            <span class="sn">1</span>
            Click <strong>Pay Now</strong> — the PayMongo payment page opens in a new tab.
        </li>
        <li>
            <span class="sn">2</span>
            Pay via <strong>GCash, Maya, or Credit/Debit Card</strong> on that page.
        </li>
        <li>
            <span class="sn">3</span>
            Come back here and click <strong>"I Already Paid"</strong> to confirm your payment.
        </li>
    </ul>

    <?php if ($pay_url): ?>
    <a href="<?php echo htmlspecialchars($pay_url); ?>" target="_blank"
       class="btn-pay" id="payNowBtn">
        💳 Pay Now — Open PayMongo
        <span style="font-size:0.75rem;opacity:0.8;">(opens new tab)</span>
    </a>
    <?php else: ?>
    <div style="background:#f8d7da;color:#842029;padding:0.75rem 1rem;border-radius:10px;
                margin-bottom:0.75rem;font-size:0.88rem;text-align:left;border-left:4px solid #dc3545;">
        ⚠️ Payment link not available. <a href="cart.php" style="color:#842029;font-weight:700;">Go back to cart</a> and try again.
    </div>
    <?php endif; ?>

    <button type="button" class="btn-confirm" id="confirmBtn" onclick="checkPayment()">
        ✅ I Already Paid — Confirm My Payment
    </button>

    <div class="check-msg" id="checkMsg"></div>

    <button class="skip-link" onclick="window.location.href='appointments.php#orders'">
        Not paying now? View my orders
    </button>

</div>
</div>

<script>
const ORDER_ID = <?php echo $order_id; ?>;

// After clicking Pay Now, pulse the confirm button as a reminder
document.getElementById('payNowBtn')?.addEventListener('click', () => {
    setTimeout(() => {
        document.getElementById('confirmBtn').classList.add('pulse');
    }, 2000);
});

function checkPayment() {
    const btn = document.getElementById('confirmBtn');
    const msg = document.getElementById('checkMsg');

    btn.disabled    = true;
    btn.textContent = '⏳ Checking with PayMongo…';
    btn.classList.remove('pulse');
    msg.style.display = 'none';

    fetch('payment_pending.php', {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:        'action=check_payment&order_id=' + ORDER_ID
    })
    .then(r => r.json())
    .then(data => {
        msg.style.display = 'block';

        if (data.status === 'paid') {
            // ✅ Payment confirmed
            msg.className   = 'check-msg success';
            msg.textContent = data.message;
            btn.textContent = '✅ Payment Confirmed!';
            btn.style.background    = '#d1e7dd';
            btn.style.borderColor   = '#198754';
            btn.style.color         = '#0a3622';

            // Redirect to success page after short delay
            setTimeout(() => {
                window.location.href = 'payment_success.php?order_id=' + ORDER_ID;
            }, 1500);

        } else if (data.status === 'pending') {
            // ⏳ Not paid yet
            msg.className   = 'check-msg pending';
            msg.textContent = '⏳ ' + data.message;
            btn.disabled    = false;
            btn.textContent = '✅ I Already Paid — Try Again';
            btn.classList.add('pulse');

        } else {
            // ❌ Error
            msg.className   = 'check-msg error';
            msg.textContent = '❌ ' + (data.message || 'Something went wrong. Please try again.');
            btn.disabled    = false;
            btn.textContent = '✅ I Already Paid — Confirm My Payment';
        }
    })
    .catch(() => {
        msg.className     = 'check-msg error';
        msg.textContent   = '❌ Could not connect. Please check your internet and try again.';
        msg.style.display = 'block';
        btn.disabled      = false;
        btn.textContent   = '✅ I Already Paid — Confirm My Payment';
    });
}
</script>

</body>
</html>