<?php
require_once '../config.php';
redirect_if_not_user();

$user_id = $_SESSION['user_id'];

if (empty($_SESSION['cart'])) {
    $_SESSION['cart'] = load_cart_from_db($conn, $user_id);
}

if (isset($_GET['remove'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }
    $product_id = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$product_id])) {
        unset($_SESSION['cart'][$product_id]);
        remove_cart_item_from_db($conn, $user_id, $product_id);
    }
    header("Location: cart.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantities'])) {
    verify_csrf_token();
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
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_selected'])) {
    verify_csrf_token();
    $selected_items = $_POST['selected'] ?? [];
    if (count($selected_items) === 0) {
        $_SESSION['error'] = "Please select at least one item to checkout.";
        header("Location: cart.php"); exit();
    }
    $_SESSION['checkout_items']    = [];
    $_SESSION['checkout_item_ids'] = [];
    $error_messages = [];
    foreach ($selected_items as $product_id) {
        $product_id = intval($product_id);
        if (isset($_SESSION['cart'][$product_id])) {
            $cart_item = $_SESSION['cart'][$product_id];
            $stmt = $conn->prepare("SELECT name, price, stock, image FROM products WHERE id = ? AND deleted_at IS NULL");
            $stmt->bind_param("i", $product_id); $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$product) continue;
            if ($cart_item['quantity'] > intval($product['stock'])) {
                $error_messages[] = "'{$cart_item['name']}' only has {$product['stock']} left in stock.";
            } else {
                $_SESSION['checkout_items'][]    = ['type'=>'product','id'=>$product_id,'name'=>$product['name'],'image'=>$product['image'],'price'=>$product['price'],'quantity'=>$cart_item['quantity']];
                $_SESSION['checkout_item_ids'][] = $product_id;
            }
        }
    }
    if (!empty($error_messages)) { $_SESSION['error'] = implode('<br>', $error_messages); header("Location: cart.php"); exit(); }
    if (empty($_SESSION['checkout_items'])) { $_SESSION['error'] = "No valid items to checkout."; header("Location: cart.php"); exit(); }
    unset($_SESSION['direct_checkout'], $_SESSION['service_booking']);
    header("Location: checkout.php"); exit();
}

$cart_items = $_SESSION['cart'];
$cart_total = 0;
$cart_stock = [];
foreach ($cart_items as $product_id => $item) {
    $stmt = $conn->prepare("SELECT name, price, stock, image FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id); $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$product) { unset($_SESSION['cart'][$product_id]); remove_cart_item_from_db($conn, $user_id, $product_id); continue; }
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cart</title>

<style>
/* ═══════════════════════════════════════════════
   CART PAGE — Recovery Spa
   ═══════════════════════════════════════════════ */
   
.cp {
    min-height: 100vh;
    background: #F7F3EE;
    padding: 3rem 1.5rem 6rem;
    font-family: 'DM Sans', sans-serif;
    background: linear-gradient(rgba(59, 42, 26, 0.6), rgba(59, 42, 26, 0.6)), 
                url('../img/cartbg.jpg') no-repeat center center fixed; 
}
.cp-inner { max-width: 1080px; margin: 0 auto; }

/* ── Heading ── */
.cp-head {
    display: flex; align-items: flex-end; justify-content: space-between;
    flex-wrap: wrap; gap: 0.5rem;
    margin-bottom: 2.5rem; padding-bottom: 1.5rem;
    border-bottom: 1px solid #E3D5C5;
}
.cp-head-title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2rem,4vw,2.8rem); font-weight: 300; color: #3B2A1A; line-height: 1; }
.cp-head-title em { font-style: italic; color: #C96A2C; }
.cp-head-sub { font-size: 0.75rem; color: #A07850; margin-top: 0.3rem; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 600; }
.cp-back { font-size: 0.8rem; color: #A07850; text-decoration: none; display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border: 1px solid #E3D5C5; border-radius: 20px; background: #fff; transition: all 0.2s; white-space: nowrap; }
.cp-back:hover { border-color: #C96A2C; color: #C96A2C; }

/* ── Alert ── */
.cp-alert { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.85rem 1.1rem; border-radius: 10px; font-size: 0.83rem; margin-bottom: 1.5rem; border-left: 4px solid; }
.cp-alert.err  { background: #fdecea; color: #c0392b; border-color: #dc3545; }
.cp-alert.save { background: #edf7ed; color: #2e7d32; border-color: #4caf50; display: none; }

/* ── Grid ── */
.cp-grid { display: grid; grid-template-columns: 1fr 320px; gap: 1.75rem; align-items: start; }
@media(max-width:860px) { .cp-grid { grid-template-columns: 1fr; } .cp-summary { position: static !important; } }

/* ── Select bar ── */
.cp-selbar { display: flex; align-items: center; justify-content: space-between; background: #fff; border: 1px solid #E3D5C5; border-radius: 10px; padding: 0.6rem 1rem; margin-bottom: 0.75rem; font-size: 0.8rem; }
.cp-selbar-lbl { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #3B2A1A; cursor: pointer; }
.cp-selbar-lbl input { width: 14px; height: 14px; accent-color: #C96A2C; cursor: pointer; }
.cp-selbar-ct { color: #A07850; font-size: 0.75rem; }

/* ── Items list ── */
.cp-list { display: flex; flex-direction: column; gap: 0.65rem; }

/* ── Item card ── */
.cp-card {
    background: #fff; border: 1px solid #E3D5C5; border-radius: 14px;
    padding: 1.1rem 1.2rem;
    display: grid;
    grid-template-columns: auto 72px 1fr auto;
    grid-template-rows: auto auto;
    column-gap: 0.9rem; row-gap: 0.5rem; align-items: center;
    transition: box-shadow 0.2s, border-color 0.2s, opacity 0.2s;
}
.cp-card:hover { box-shadow: 0 4px 20px rgba(59,42,26,0.07); border-color: #CDB99A; }
.cp-card.dim { opacity: 0.4; }
.cp-card.on  { border-left: 3px solid #C96A2C; }

.cc-cb   { grid-column: 1; grid-row: 1/3; width: 15px; height: 15px; accent-color: #C96A2C; cursor: pointer; align-self: center; }
.cc-img  { grid-column: 2; grid-row: 1/3; width: 72px; height: 72px; border-radius: 10px; object-fit: cover; border: 1px solid #E3D5C5; align-self: center; }
.cc-info { grid-column: 3; grid-row: 1; }
.cc-name { font-size: 0.9rem; font-weight: 600; color: #3B2A1A; margin-bottom: 0.15rem; line-height: 1.35; }
.cc-unit { font-size: 0.72rem; color: #A07850; background: #F7F3EE; border: 1px solid #E3D5C5; padding: 1px 7px; border-radius: 5px; display: inline-block; }
.cc-ctrl { grid-column: 3; grid-row: 2; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.cc-right { grid-column: 4; grid-row: 1/3; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; gap: 0.5rem; align-self: center; }

/* Stepper */
.cc-qty { display: inline-flex; align-items: center; border: 1px solid #E3D5C5; border-radius: 7px; overflow: hidden; background: #fff; }
.cc-qty button { width: 28px; height: 28px; border: none; background: #F7F3EE; color: #6B4C30; font-size: 0.95rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s, color 0.15s; }
.cc-qty button:hover { background: #EAD8C0; color: #C96A2C; }
.cc-qty input { width: 36px; height: 28px; border: none; border-left: 1px solid #E3D5C5; border-right: 1px solid #E3D5C5; text-align: center; font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; color: #3B2A1A; background: #fff; outline: none; -moz-appearance: textfield; }
.cc-qty input::-webkit-outer-spin-button, .cc-qty input::-webkit-inner-spin-button { -webkit-appearance: none; }
.cc-warn  { font-size: 0.7rem; color: #c0392b; display: none; }
.cc-sub   { font-size: 0.98rem; font-weight: 700; color: #C96A2C; white-space: nowrap; }
.cc-del   { background: none; border: none; cursor: pointer; color: #C8B8A8; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; text-decoration: none; display: flex; align-items: center; gap: 0.25rem; transition: color 0.2s, background 0.2s; white-space: nowrap; }
.cc-del:hover { color: #c0392b; background: #fdecea; }

/* ── Summary panel ── */
.cp-summary { position: sticky; top: 90px; background: #fff; border: 1px solid #E3D5C5; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(59,42,26,0.07); }
.cs-head { background: #3B2A1A; padding: 1rem 1.25rem; }
.cs-head h3 { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 400; color: #F4E7D3; letter-spacing: 0.04em; }
.cs-body { padding: 1.25rem; }
.cs-pill { display: flex; justify-content: space-between; align-items: center; background: #FBF5ED; border: 1px solid #EAD8C0; border-radius: 8px; padding: 0.5rem 0.85rem; margin-bottom: 1rem; font-size: 0.78rem; color: #6B4C30; font-weight: 500; }
.cs-pill strong { color: #C96A2C; }
.cs-row  { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; color: #999; padding: 0.3rem 0; }
.cs-free { color: #2e7d32 !important; font-weight: 600 !important; }
.cs-div  { height: 1px; background: #EAD8C0; margin: 0.75rem 0; }
.cs-total { display: flex; justify-content: space-between; align-items: baseline; }
.cs-total-lbl { font-size: 0.95rem; font-weight: 700; color: #3B2A1A; }
.cs-total-val { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; font-weight: 600; color: #C96A2C; letter-spacing: -0.01em; }
.cs-btn { display: block; width: 100%; margin-top: 1.1rem; padding: 0.9rem; background: linear-gradient(135deg, #C96A2C 0%, #A94F1D 100%); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 0.88rem; font-weight: 700; letter-spacing: 0.03em; cursor: pointer; text-align: center; box-shadow: 0 4px 16px rgba(201,106,44,0.3); transition: opacity 0.2s, transform 0.15s, box-shadow 0.15s; }
.cs-btn:hover:not(:disabled) { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 6px 24px rgba(201,106,44,0.4); }
.cs-btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none; box-shadow: none; }
.cs-note { text-align: center; font-size: 0.68rem; color: #C8B8A8; margin-top: 0.5rem; }
.cs-trust { display: flex; justify-content: space-around; margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid #EAD8C0; }
.cs-badge { display: flex; flex-direction: column; align-items: center; gap: 0.2rem; font-size: 0.62rem; color: #A07850; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.cs-badge span:first-child { font-size: 1.15rem; }

/* ── Empty state ── */
.cp-empty { grid-column: 1/-1; text-align: center; padding: 5rem 2rem; background: #fff; border: 1px solid #E3D5C5; border-radius: 20px; }
.cp-empty-ico { font-size: 3.5rem; opacity: 0.12; display: block; margin-bottom: 1.25rem; }
.cp-empty h2 { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 400; color: #3B2A1A; margin-bottom: 0.5rem; }
.cp-empty p  { font-size: 0.85rem; color: #A07850; margin-bottom: 2rem; }
.cp-empty-btn { display: inline-block; padding: 0.8rem 2.25rem; background: linear-gradient(135deg,#C96A2C,#A94F1D); color: #fff; border-radius: 10px; font-weight: 700; font-size: 0.88rem; text-decoration: none; box-shadow: 0 4px 16px rgba(201,106,44,0.3); transition: opacity 0.2s, transform 0.15s; }
.cp-empty-btn:hover { opacity: 0.9; transform: translateY(-1px); }
</style>


<div class="cp"><div class="cp-inner">

<!-- Heading -->
<div class="cp-head">
    <div>
        <h1 class="cp-head-title">Your <em>Cart</em></h1>
        <p class="cp-head-sub"><?php echo count($cart_items); ?> item<?php echo count($cart_items)!==1?'s':''; ?> waiting for you</p>
    </div>
    <a href="index.php" class="cp-back">&larr; Continue Shopping</a>
</div>

<!-- Alerts -->
<?php if(!empty($_SESSION['error'])): ?>
<div class="cp-alert err">&#9888; <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>
<div class="cp-alert save" id="savedMsg">&#10003; Cart saved.</div>

<div class="cp-grid">
<?php if(!empty($cart_items)): ?>

<!-- LEFT: Items -->
<div>
<form method="POST" id="cartForm">
<?php echo csrf_field(); ?>

<div class="cp-selbar">
    <label class="cp-selbar-lbl">
        <input type="checkbox" id="selectAll" onchange="toggleAll(this)" checked>
        Select all items
    </label>
    <span class="cp-selbar-ct" id="selBarCt"><?php echo count($cart_items); ?> / <?php echo count($cart_items); ?> selected</span>
</div>

<div class="cp-list">
<?php foreach($cart_items as $product_id => $item):
    $subtotal = $item['price'] * $item['quantity'];
    $stock    = $cart_stock[$product_id] ?? 99;
?>
<div class="cp-card on" id="card_<?php echo $product_id; ?>">
    <input type="checkbox" class="cc-cb item-cb" name="selected[]" value="<?php echo $product_id; ?>" data-pid="<?php echo $product_id; ?>" checked onchange="onCb(this)">
    <img class="cc-img" src="../uploads/products/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" onerror="this.src='../uploads/products/default.jpg'">
    <div class="cc-info">
        <div class="cc-name"><?php echo htmlspecialchars($item['name']); ?></div>
        <span class="cc-unit">&#8369;<?php echo number_format($item['price'],2); ?> / unit</span>
    </div>
    <div class="cc-right">
        <span class="cc-sub" id="sub_<?php echo $product_id; ?>">&#8369;<?php echo number_format($subtotal,2); ?></span>
        <a href="cart.php?remove=<?php echo $product_id; ?>&csrf_token=<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" class="cc-del" onclick="return confirm('Remove this item?')">&#128465; Remove</a>
    </div>
    <div class="cc-ctrl">
        <div class="cc-qty">
            <button type="button" onclick="step(<?php echo $product_id; ?>,-1)">&#8722;</button>
            <input type="number" name="quantities[<?php echo $product_id; ?>]" id="qty_<?php echo $product_id; ?>" value="<?php echo $item['quantity']; ?>" min="1" max="<?php echo $stock; ?>" data-pid="<?php echo $product_id; ?>" data-price="<?php echo $item['price']; ?>" data-stock="<?php echo $stock; ?>" oninput="onQty(this)">
            <button type="button" onclick="step(<?php echo $product_id; ?>,1)">+</button>
        </div>
        <span class="cc-warn" id="warn_<?php echo $product_id; ?>">&#9888; Max <?php echo $stock; ?> available</span>
    </div>
</div>
<?php endforeach; ?>
</div>

<button type="submit" name="checkout_selected" id="checkoutHiddenBtn" style="display:none;"></button>
</form>
</div>

<!-- RIGHT: Summary -->
<div>
<div class="cp-summary">
    <div class="cs-head"><h3>&#128717; Order Summary</h3></div>
    <div class="cs-body">
        <div class="cs-pill">
            <span>Selected items</span>
            <strong id="selCount"><?php echo count($cart_items); ?> item<?php echo count($cart_items)!==1?'s':''; ?></strong>
        </div>
        <div class="cs-row"><span>Subtotal</span><span id="cs-subtotal">&#8369;<?php echo number_format($cart_total,2); ?></span></div>
        <div class="cs-row cs-free"><span>Shipping</span><span>Free</span></div>
        <div class="cs-div"></div>
        <div class="cs-total">
            <span class="cs-total-lbl">Total</span>
            <span class="cs-total-val" id="cs-total">₱<?php echo number_format($cart_total, 2); ?></span>        </div>
        <button type="button" class="cs-btn" id="checkoutBtn" onclick="doCheckout()">
            Proceed to Checkout &rarr;
        </button>
        <p class="cs-note">Only selected items will be checked out</p>
        <div class="cs-trust">
            <div class="cs-badge"><span>&#128274;</span><span>Secure</span></div>
            <div class="cs-badge"><span>&#128179;</span><span>GCash/Maya</span></div>
        </div>
    </div>
</div>
</div>

<?php else: ?>
<div class="cp-empty">
    <span class="cp-empty-ico">&#128722;</span>
    <h2>Your cart is empty</h2>
    <p>Discover our spa products and treatments.<br>Add something you love.</p>
    <a href="index.php" class="cp-empty-btn">Browse Products</a>
</div>
<?php endif; ?>

</div>
</div></div>
</head>
<body>
    
</body>
</html>

<script>
function toggleAll(m){document.querySelectorAll('.item-cb').forEach(cb=>{cb.checked=m.checked;styleCard(cb);});recalc();}
function onCb(cb){styleCard(cb);const a=[...document.querySelectorAll('.item-cb')];const m=document.getElementById('selectAll');m.checked=a.every(c=>c.checked);m.indeterminate=!a.every(c=>c.checked)&&!a.every(c=>!c.checked);recalc();}
function styleCard(cb){const c=document.getElementById('card_'+cb.dataset.pid);if(!c)return;c.classList.toggle('dim',!cb.checked);c.classList.toggle('on',cb.checked);}
function step(pid,d){const i=document.getElementById('qty_'+pid);if(!i)return;i.value=Math.max(1,Math.min(parseInt(i.dataset.stock),parseInt(i.value||1)+d));onQty(i);}

function onQty(i){
    const pid=i.dataset.pid, s=parseInt(i.dataset.stock), w=document.getElementById('warn_'+pid);
    if(parseInt(i.value)>s){
        i.value=s;
        if(w)w.style.display='inline';
    }else{
        if(w)w.style.display='none';
    }
    const sub=document.getElementById('sub_'+pid);
    if(sub){
        // FIXED: Using real symbol instead of &#8369;
        const val = (parseFloat(i.dataset.price)*parseInt(i.value)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
        sub.textContent = '₱' + val; 
    }
    recalc();
    autoSave();
}

function recalc(){
    let t=0, s=0;
    const n=document.querySelectorAll('.item-cb').length;
    document.querySelectorAll('.item-cb').forEach(cb=>{
        if(!cb.checked)return;
        const i=document.getElementById('qty_'+cb.dataset.pid);
        t += parseFloat(i?.dataset?.price||0) * parseInt(i?.value||1);
        s++;
    });
    
    // FIXED: Using real symbol here too
    const fmt = v => '₱' + v.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    
    document.getElementById('cs-subtotal').textContent = fmt(t);
    document.getElementById('cs-total').textContent = fmt(t);
    document.getElementById('selCount').textContent = s + ' item' + (s!==1?'s':'');
    document.getElementById('selBarCt').textContent = s + ' / ' + n + ' selected';
    
    const btn=document.getElementById('checkoutBtn');
    btn.disabled = s===0;
    btn.textContent = s===0 ? 'Select items to checkout' : 'Proceed to Checkout ('+s+') \u2192';
}

let st;
function autoSave(){
    clearTimeout(st);
    st=setTimeout(()=>{
        const fd=new FormData(document.getElementById('cartForm'));
        fd.append('update_quantities','1');
        fetch('cart.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){
                const n=document.getElementById('savedMsg');
                n.style.display='flex';
                setTimeout(()=>n.style.display='none',2000);
            }
        }).catch(()=>{});
    },800);
}

function doCheckout(){
    if(![...document.querySelectorAll('.item-cb')].some(c=>c.checked)){
        alert('Please select at least one item.');
        return;
    }
    document.getElementById('checkoutHiddenBtn').click();
}

// Initialize on load
recalc();
</script>