<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

// ─── LOAD CART FROM DB IF SESSION IS EMPTY ────────────────────────────────────
if (empty($_SESSION['cart'])) {
    $_SESSION['cart'] = load_cart_from_db($conn, $user_id);
}

// ─── REMOVE ITEM ──────────────────────────────────────────────────────────────
if (isset($_GET['remove'])) {
    $product_id = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$product_id])) {
        unset($_SESSION['cart'][$product_id]);
        remove_cart_item_from_db($conn, $user_id, $product_id);
    }
    header("Location: cart.php");
    exit();
}

// ─── UPDATE QUANTITIES ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantities'])) {
    if (isset($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $product_id => $quantity) {
            $product_id = intval($product_id);
            $quantity   = max(1, intval($quantity));
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id]['quantity'] = $quantity;
            }
        }
        sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
    }
    header("Location: cart.php");
    exit();
}

// ─── CHECKOUT SELECTED ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_selected'])) {
    $selected_items = isset($_POST['selected']) ? $_POST['selected'] : [];

    if (count($selected_items) === 0) {
        $_SESSION['error'] = "Please select at least one item to checkout.";
        header("Location: cart.php");
        exit();
    }

    $_SESSION['checkout_items']    = [];
    $_SESSION['checkout_item_ids'] = [];
    $error_messages = [];

    foreach ($selected_items as $product_id) {
        $product_id = intval($product_id);
        if (isset($_SESSION['cart'][$product_id])) {
            $cart_item = $_SESSION['cart'][$product_id];

            $stmt = $conn->prepare("SELECT name, price, stock, image FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) continue;

            $available_stock = intval($product['stock']);

            if ($cart_item['quantity'] > $available_stock) {
                $error_messages[] = "Cannot checkout '{$cart_item['name']}'. Only {$available_stock} left in stock.";
            } else {
                $_SESSION['checkout_items'][] = [
                    'type'     => 'product',
                    'id'       => $product_id,
                    'name'     => $product['name'],
                    'image'    => $product['image'],
                    'price'    => $product['price'],
                    'quantity' => $cart_item['quantity']
                ];
                $_SESSION['checkout_item_ids'][] = $product_id;
                $_SESSION['cart'][$product_id]['price'] = $product['price'];
                $_SESSION['cart'][$product_id]['name']  = $product['name'];
                $_SESSION['cart'][$product_id]['image'] = $product['image'];
            }
        }
    }

    if (!empty($error_messages)) {
        $_SESSION['error'] = implode('<br>', $error_messages);
        header("Location: cart.php");
        exit();
    }

    if (empty($_SESSION['checkout_items'])) {
        $_SESSION['error'] = "No valid items to checkout.";
        header("Location: cart.php");
        exit();
    }

    unset($_SESSION['direct_checkout']);
    unset($_SESSION['service_booking']);
    header("Location: checkout.php");
    exit();
}

// ─── BUILD CART DATA ──────────────────────────────────────────────────────────
$cart_items = $_SESSION['cart'];
$cart_total = 0;
$cart_stock = [];

foreach ($cart_items as $product_id => $item) {
    $stmt = $conn->prepare("SELECT name, price, stock, image FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        unset($_SESSION['cart'][$product_id]);
        remove_cart_item_from_db($conn, $user_id, $product_id);
        continue;
    }

    $_SESSION['cart'][$product_id]['price'] = $product['price'];
    $_SESSION['cart'][$product_id]['name']  = $product['name'];
    $_SESSION['cart'][$product_id]['image'] = $product['image'];

    $cart_total += $product['price'] * $item['quantity'];
    $cart_stock[$product_id] = intval($product['stock']);
}

sync_cart_to_db($conn, $user_id, $_SESSION['cart']);
$cart_items = $_SESSION['cart'];

$page_title = 'Shopping Cart';
require_once 'header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');

/* ── Hide header on cart page only ── */
header { display: none !important; }

/* ── Variables ── */
:root {
    --c-bg:       #f7f3ee;
    --c-white:    #ffffff;
    --c-border:   #e8ddd2;
    --c-brown:    #3B2A1A;
    --c-mid:      #6b5040;
    --c-muted:    #a08878;
    --c-accent:   #C96A2C;
    --c-accent2:  #a8551f;
    --c-green:    #2e7d32;
    --c-red:      #c0392b;
    --c-red-bg:   #fdecea;
    --c-warn-bg:  #fff8e1;
    --c-warn:     #856404;
    --radius-sm:  8px;
    --radius-md:  12px;
    --shadow:     0 2px 16px rgba(59,42,26,.08);
}

/* ── Base ── */
.cart-wrap * { box-sizing: border-box; }
.cart-wrap {
    font-family: 'DM Sans', sans-serif;
    background: var(--c-bg);
    min-height: 60vh;
    padding: 1.75rem 1rem 3rem;
    color: var(--c-mid);
}

/* ── Title row ── */
.cart-title-row {
    display: flex; align-items: baseline;
    gap: .75rem; margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.cart-title {
    font-size: 1.35rem; font-weight: 700; color: var(--c-brown);
}
.cart-title-count {
    font-size: .82rem; color: var(--c-muted);
    background: var(--c-border);
    padding: 2px 10px; border-radius: 20px;
}

/* ── Error ── */
.cart-error {
    background: var(--c-red-bg); color: var(--c-red);
    border: 1px solid #f5c2c7; border-radius: var(--radius-sm);
    padding: .75rem 1rem; font-size: .82rem;
    margin-bottom: 1.25rem;
    display: flex; gap: .4rem; align-items: flex-start;
}

/* ── Qty-changed notice ── */
.cart-qty-notice {
    display: none;
    background: var(--c-warn-bg); color: var(--c-warn);
    border: 1px solid #ffe082; border-radius: var(--radius-sm);
    padding: .55rem 1rem; font-size: .78rem;
    margin-bottom: 1rem;
    text-align: center;
}

/* ── Select-all bar ── */
.cart-selbar {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--c-white); border: 1px solid var(--c-border);
    border-radius: var(--radius-sm); padding: .55rem .9rem;
    margin-bottom: .6rem;
}
.cart-selbar label {
    display: flex; align-items: center; gap: .45rem;
    font-size: .82rem; font-weight: 600; color: var(--c-brown);
    cursor: pointer;
}
.cart-selbar input[type="checkbox"] {
    width: 15px; height: 15px; accent-color: var(--c-accent); cursor: pointer;
}
.cart-selbar-count { font-size: .75rem; color: var(--c-muted); }

/* ── Item list ── */
.cart-items-list {
    display: flex; flex-direction: column; gap: .55rem;
    margin-bottom: .85rem;
}

/* ── Single item card ── */
.cart-item-card {
    background: var(--c-white);
    border: 1px solid var(--c-border);
    border-radius: var(--radius-md);
    padding: .85rem;
    transition: opacity .2s, box-shadow .2s;
}
.cart-item-card:hover { box-shadow: var(--shadow); }
.cart-item-card.dimmed { opacity: .42; }

/* Row 1: checkbox + image + name + delete */
.cart-item-row1 {
    display: flex; align-items: center; gap: .75rem;
    margin-bottom: .65rem;
}
.cart-item-cb {
    width: 15px; height: 15px; flex-shrink: 0;
    accent-color: var(--c-accent); cursor: pointer;
}
.cart-item-img {
    width: 56px; height: 56px; border-radius: 8px;
    object-fit: cover; border: 1px solid var(--c-border); flex-shrink: 0;
}
.cart-item-name {
    flex: 1; min-width: 0;
    font-size: .88rem; font-weight: 600; color: var(--c-brown);
    /* Allow wrapping so long names show fully */
    word-break: break-word;
    line-height: 1.35;
}
.cart-item-del {
    flex-shrink: 0; background: transparent; border: none;
    color: #c8b8a8; font-size: .95rem; cursor: pointer;
    padding: 5px; border-radius: 6px;
    transition: color .2s, background .2s;
    text-decoration: none; display: flex; align-items: center;
}
.cart-item-del:hover { color: var(--c-red); background: var(--c-red-bg); }

/* Row 2: unit price + qty stepper + subtotal — ALL visible */
.cart-item-row2 {
    display: flex; align-items: center;
    gap: .5rem; flex-wrap: wrap;
    padding-left: calc(15px + .75rem + 56px + .75rem); /* align under name */
}

/* Unit price badge */
.cart-item-unit {
    font-size: .75rem; color: var(--c-muted);
    background: var(--c-bg); border: 1px solid var(--c-border);
    padding: 3px 8px; border-radius: 6px;
    white-space: nowrap;
}

/* Qty stepper */
.cart-stepper {
    display: inline-flex; align-items: center;
    border: 1px solid var(--c-border); border-radius: 7px; overflow: hidden;
    flex-shrink: 0;
}
.cart-stepper button {
    width: 28px; height: 28px; border: none; background: var(--c-bg);
    color: var(--c-mid); font-size: .95rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
}
.cart-stepper button:hover { background: var(--c-border); color: var(--c-accent); }
.cart-stepper input {
    width: 36px; height: 28px; border: none;
    border-left: 1px solid var(--c-border); border-right: 1px solid var(--c-border);
    text-align: center; font-family: 'DM Sans', sans-serif;
    font-size: .82rem; color: var(--c-brown); background: var(--c-white);
    outline: none; -moz-appearance: textfield;
}
.cart-stepper input::-webkit-outer-spin-button,
.cart-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; }

/* Subtotal */
.cart-item-subtotal {
    margin-left: auto;
    font-size: .88rem; font-weight: 700; color: var(--c-brown);
    white-space: nowrap;
}

/* Stock warning */
.cart-stock-warn {
    display: none; width: 100%;
    font-size: .7rem; color: var(--c-red);
    padding-left: calc(15px + .75rem + 56px + .75rem);
    margin-top: .25rem;
}

/* ── Actions row ── */
.cart-actions {
    display: flex; gap: .6rem; flex-wrap: wrap;
    margin-bottom: 1.25rem;
}
.btn-update {
    flex: 1; min-width: 120px;
    display: flex; align-items: center; justify-content: center; gap: .35rem;
    padding: .6rem .9rem; border-radius: var(--radius-sm);
    border: 1.5px solid var(--c-border); background: var(--c-white);
    color: var(--c-mid); font-family: 'DM Sans', sans-serif;
    font-size: .82rem; font-weight: 600; cursor: pointer;
    transition: border-color .2s, color .2s, background .2s;
    white-space: nowrap;
}
.btn-update:hover:not(:disabled) { border-color: var(--c-accent); color: var(--c-accent); background: #fdf3eb; }
.btn-update:disabled { opacity: .35; cursor: not-allowed; }
.btn-continue {
    flex: 1; min-width: 120px;
    display: flex; align-items: center; justify-content: center; gap: .3rem;
    padding: .6rem .9rem; border-radius: var(--radius-sm);
    border: 1.5px solid var(--c-border); background: var(--c-white);
    color: var(--c-muted); font-family: 'DM Sans', sans-serif;
    font-size: .82rem; font-weight: 500; cursor: pointer;
    text-decoration: none; transition: border-color .2s, color .2s;
    white-space: nowrap;
}
.btn-continue:hover { border-color: var(--c-mid); color: var(--c-brown); }

/* ── Order Summary card ── */
.cart-summary-card {
    background: var(--c-white);
    border: 1px solid var(--c-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.cart-summary-head {
    padding: .75rem 1rem;
    background: var(--c-bg);
    border-bottom: 1px solid var(--c-border);
    font-size: .88rem; font-weight: 700; color: var(--c-brown);
}
.cart-summary-body { padding: 1rem; }
.summary-line {
    display: flex; justify-content: space-between; align-items: center;
    font-size: .82rem; color: #777; padding: .28rem 0;
}
.summary-sel-chip {
    display: flex; justify-content: space-between; align-items: center;
    background: #fdf3eb; border-radius: 7px;
    padding: .4rem .75rem; margin-bottom: .6rem;
    font-size: .78rem; font-weight: 500; color: var(--c-brown);
}
.summary-divider { height: 1px; background: var(--c-border); margin: .55rem 0; }
.summary-total-row {
    display: flex; justify-content: space-between; align-items: baseline;
    padding: .3rem 0 .15rem;
}
.summary-total-label { font-size: .9rem; font-weight: 700; color: var(--c-brown); }
.summary-total-val   { font-size: 1.2rem; font-weight: 700; color: var(--c-accent); }

/* Checkout button */
.btn-checkout {
    display: block; width: 100%; margin-top: .9rem;
    padding: .85rem; border: none; border-radius: var(--radius-sm);
    background: var(--c-accent); color: #fff;
    font-family: 'DM Sans', sans-serif; font-size: .88rem; font-weight: 700;
    text-align: center; cursor: pointer; letter-spacing: .02em;
    box-shadow: 0 4px 14px rgba(201,106,44,.28);
    transition: background .2s, transform .15s, box-shadow .15s;
}
.btn-checkout:hover:not(:disabled) {
    background: var(--c-accent2); transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(201,106,44,.38);
}
.btn-checkout:disabled { opacity: .38; cursor: not-allowed; transform: none; box-shadow: none; }
.cart-note { font-size: .7rem; color: #bbb; text-align: center; margin-top: .4rem; }

/* ── Empty state ── */
.cart-empty {
    text-align: center; padding: 4rem 1.5rem;
    background: var(--c-white); border: 1px solid var(--c-border);
    border-radius: var(--radius-md); box-shadow: var(--shadow);
}
.cart-empty-icon { font-size: 3.5rem; opacity: .18; margin-bottom: .85rem; }
.cart-empty h2 { font-size: 1.2rem; color: var(--c-brown); margin-bottom: .4rem; font-weight: 700; }
.cart-empty p  { font-size: .85rem; color: var(--c-muted); margin-bottom: 1.5rem; }
.btn-shop {
    display: inline-block; padding: .7rem 1.75rem;
    background: var(--c-accent); color: #fff; border-radius: var(--radius-sm);
    font-weight: 700; font-size: .85rem; text-decoration: none;
    box-shadow: 0 4px 12px rgba(201,106,44,.25);
    transition: background .2s, transform .15s;
}
.btn-shop:hover { background: var(--c-accent2); transform: translateY(-1px); }
</style>

<div class="cart-wrap">

    <!-- Title -->
    <div class="cart-title-row">
        <span class="cart-title">🛒 Shopping Cart</span>
        <span class="cart-title-count">
            <?php echo count($cart_items); ?> item<?php echo count($cart_items) !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <!-- Error -->
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="cart-error">
            ⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cart_items)): ?>

    <!-- Qty-changed notice -->
    <div class="cart-qty-notice" id="qtyNotice">
        ⚠️ Quantities changed — click <strong>Update Cart</strong> before checking out.
    </div>

    <form method="POST" id="cartForm">

        <!-- Select-all bar -->
        <div class="cart-selbar">
            <label>
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" checked>
                Select all
            </label>
            <span class="cart-selbar-count" id="selBarCount">
                <?php echo count($cart_items); ?> / <?php echo count($cart_items); ?> selected
            </span>
        </div>

        <!-- Item cards -->
        <div class="cart-items-list">
            <?php foreach ($cart_items as $product_id => $item):
                $subtotal = $item['price'] * $item['quantity'];
                $stock    = $cart_stock[$product_id] ?? 99;
            ?>
            <div class="cart-item-card" id="card_<?php echo $product_id; ?>">

                <!-- Row 1: checkbox · image · name · delete -->
                <div class="cart-item-row1">
                    <input type="checkbox"
                           class="cart-item-cb item-checkbox"
                           name="selected[]"
                           value="<?php echo $product_id; ?>"
                           data-pid="<?php echo $product_id; ?>"
                           checked
                           onchange="onCbChange(this)">

                    <img class="cart-item-img"
                         src="../uploads/products/<?php echo htmlspecialchars($item['image']); ?>"
                         alt="<?php echo htmlspecialchars($item['name']); ?>"
                         onerror="this.style.opacity='.25'">

                    <span class="cart-item-name">
                        <?php echo htmlspecialchars($item['name']); ?>
                    </span>

                    <a href="cart.php?remove=<?php echo $product_id; ?>"
                       class="cart-item-del" title="Remove"
                       onclick="return confirm('Remove this item?')">🗑</a>
                </div>

                <!-- Row 2: unit price · stepper · subtotal -->
                <div class="cart-item-row2">
                    <span class="cart-item-unit">
                        ₱<?php echo number_format($item['price'], 2); ?> each
                    </span>

                    <div class="cart-stepper">
                        <button type="button" onclick="stepQty(<?php echo $product_id; ?>, -1)">−</button>
                        <input type="number"
                               name="quantities[<?php echo $product_id; ?>]"
                               id="qty_<?php echo $product_id; ?>"
                               value="<?php echo $item['quantity']; ?>"
                               min="1" max="<?php echo $stock; ?>"
                               data-pid="<?php echo $product_id; ?>"
                               data-price="<?php echo $item['price']; ?>"
                               data-stock="<?php echo $stock; ?>"
                               oninput="onQtyInput(this)">
                        <button type="button" onclick="stepQty(<?php echo $product_id; ?>, 1)">+</button>
                    </div>

                    <span class="cart-item-subtotal" id="sub_<?php echo $product_id; ?>">
                        ₱<?php echo number_format($subtotal, 2); ?>
                    </span>
                </div>

                <!-- Stock warning (full width) -->
                <div class="cart-stock-warn" id="warn_<?php echo $product_id; ?>">
                    ⚠️ Only <?php echo $stock; ?> left in stock!
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions: Update + Continue Shopping -->
        <div class="cart-actions">
            <button type="submit" name="update_quantities"
                    class="btn-update" id="btnUpdate" disabled>
                🔄 Update Cart
            </button>
            <a href="index.php" class="btn-continue">← Continue Shopping</a>
        </div>

        <!-- Order Summary -->
        <div class="cart-summary-card">
            <div class="cart-summary-head">Order Summary</div>
            <div class="cart-summary-body">

                <div class="summary-sel-chip">
                    <span>Selected items</span>
                    <span id="selCount"><?php echo count($cart_items); ?></span>
                </div>

                <div class="summary-line">
                    <span>Subtotal</span>
                    <span id="summary-subtotal">₱<?php echo number_format($cart_total, 2); ?></span>
                </div>
                <div class="summary-line">
                    <span>Shipping</span>
                    <span style="color:var(--c-green);font-weight:600;">Free</span>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-total-row">
                    <span class="summary-total-label">Total</span>
                    <span class="summary-total-val" id="summary-total">
                        ₱<?php echo number_format($cart_total, 2); ?>
                    </span>
                </div>

                <button type="submit"
                        name="checkout_selected"
                        id="checkoutBtn"
                        class="btn-checkout">
                    Proceed to Checkout →
                </button>

                <p class="cart-note">✅ Only checked items will be checked out</p>
            </div>
        </div>

    </form>

    <?php else: ?>

    <!-- Empty state -->
    <div class="cart-empty">
        <div class="cart-empty-icon">🛒</div>
        <h2>Your cart is empty</h2>
        <p>Start shopping to add items to your cart.</p>
        <a href="index.php" class="btn-shop">Browse Products</a>
    </div>

    <?php endif; ?>
</div>

<script>
// ── Select all ────────────────────────────────────────────────────────────────
function toggleSelectAll(master) {
    document.querySelectorAll('.item-checkbox').forEach(cb => {
        cb.checked = master.checked;
        dimCard(cb);
    });
    updateSummary();
}

function onCbChange(cb) {
    dimCard(cb);
    const all    = [...document.querySelectorAll('.item-checkbox')];
    const master = document.getElementById('selectAll');
    master.checked       = all.every(c => c.checked);
    master.indeterminate = !all.every(c => c.checked) && !all.every(c => !c.checked);
    updateSummary();
}

function dimCard(cb) {
    const card = document.getElementById('card_' + cb.dataset.pid);
    if (card) card.classList.toggle('dimmed', !cb.checked);
}

// ── Qty stepper ───────────────────────────────────────────────────────────────
function stepQty(pid, delta) {
    const input = document.getElementById('qty_' + pid);
    if (!input) return;
    input.value = Math.max(1, Math.min(parseInt(input.dataset.stock), parseInt(input.value || 1) + delta));
    onQtyInput(input);
}

function onQtyInput(input) {
    const pid   = input.dataset.pid;
    const stock = parseInt(input.dataset.stock);
    const warn  = document.getElementById('warn_' + pid);

    if (parseInt(input.value) > stock) {
        input.value = stock;
        if (warn) warn.style.display = 'block';
    } else {
        if (warn) warn.style.display = 'none';
    }

    // Update subtotal
    const price = parseFloat(input.dataset.price);
    const sub   = document.getElementById('sub_' + pid);
    if (sub) sub.textContent = '₱' + (price * parseInt(input.value)).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    updateSummary();
    flagQtyChanged();
}

// ── Live totals ───────────────────────────────────────────────────────────────
function updateSummary() {
    let total = 0, sel = 0;
    const totalItems = document.querySelectorAll('.item-checkbox').length;

    document.querySelectorAll('.item-checkbox').forEach(cb => {
        const pid   = cb.dataset.pid;
        const input = document.getElementById('qty_' + pid);
        const price = parseFloat(input?.dataset?.price || 0);
        const qty   = parseInt(input?.value || 1);
        if (cb.checked) { total += price * qty; sel++; }
    });

    const fmt = n => '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    document.getElementById('summary-subtotal').textContent = fmt(total);
    document.getElementById('summary-total').textContent    = fmt(total);
    document.getElementById('selCount').textContent         = sel;
    document.getElementById('selBarCount').textContent      = sel + ' / ' + totalItems + ' selected';

    const btn = document.getElementById('checkoutBtn');
    btn.disabled    = sel === 0;
    btn.textContent = sel === 0
        ? 'Select items to checkout'
        : 'Proceed to Checkout (' + sel + ') →';
}

// ── Qty-changed guard ─────────────────────────────────────────────────────────
let qtyDirty = false;

function flagQtyChanged() {
    qtyDirty = true;
    document.getElementById('qtyNotice').style.display = 'block';
    document.getElementById('btnUpdate').disabled = false;
}

document.getElementById('cartForm').addEventListener('submit', function(e) {
    if (e.submitter?.name === 'checkout_selected') {
        const anyChecked = [...document.querySelectorAll('.item-checkbox')].some(c => c.checked);
        if (!anyChecked) { e.preventDefault(); alert('Please select at least one item.'); return; }
        if (qtyDirty)    { e.preventDefault(); alert("Click 'Update Cart' first before checking out."); return; }
    }
    if (e.submitter?.name === 'update_quantities') qtyDirty = false;
});

// ── Init ──────────────────────────────────────────────────────────────────────
updateSummary();
</script>