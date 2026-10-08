<?php
/**
 * _sell_product.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Data + save for the Dashboard's Sell Product window (counter product sales;
 * markup and JS in _sell_product_modal.php). Replaces walkin.php's "Buy
 * Product" tab and saves the same shape it does — one paid order, one
 * order_items row per product, stock lowered — so the Daily Report's product
 * sales (order_items with product_id, paid orders) count it unchanged. The
 * difference: several products per sale instead of one.
 *
 * Requires: config.php loaded (get_walkin_customer_id, sanitize_input).
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!defined('SELL_PRODUCT_LOW_STOCK')) define('SELL_PRODUCT_LOW_STOCK', 5); // same line the Dashboard's Low Stock card uses

if (!function_exists('sell_product_data')) {
function sell_product_data(mysqli $conn): array {
    $rows = $conn->query("
        SELECT p.id, p.name, p.price, p.stock, p.image, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.deleted_at IS NULL
        ORDER BY c.name, p.name
    ")->fetch_all(MYSQLI_ASSOC);
    return [
        'low'      => SELL_PRODUCT_LOW_STOCK,
        'products' => array_map(fn($p) => [
            'id'    => (int)$p['id'],
            'name'  => $p['name'],
            'price' => (float)$p['price'],
            'stock' => max(0, (int)$p['stock']),
            'cat'   => $p['category_name'] ?: 'Other',
            'image' => $p['image'] ? '../uploads/products/' . rawurlencode($p['image']) : null,
        ], $rows),
    ];
}
}

if (!function_exists('sell_product_save')) {
/**
 * $items: [ ['id' => product id, 'qty' => n], ... ]. Prices and stock come
 * from the database, never the browser; stock is locked (FOR UPDATE) so two
 * receptionists can't sell the last bottle twice.
 */
function sell_product_save(mysqli $conn, array $items, string $customer, string $slip, string $discount_type, float $voucher_amount, string $payment_method): array {
    $qty_by_id = [];
    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0); $qty = (int)($it['qty'] ?? 0);
        if ($id > 0 && $qty > 0) $qty_by_id[$id] = ($qty_by_id[$id] ?? 0) + $qty;
    }
    if (!$qty_by_id) return ['ok' => false, 'message' => 'Add at least one product.'];
    if (!in_array($payment_method, ['cash', 'gcash', 'maya', 'qrph', 'card', 'swiper'], true)) return ['ok' => false, 'message' => 'Choose a payment method.'];
    if (!in_array($discount_type, ['none', 'senior', 'pwd', 'employee', 'voucher'], true)) $discount_type = 'none';
    if ($discount_type === 'voucher' && $voucher_amount <= 0) return ['ok' => false, 'message' => 'Enter the voucher amount, or choose None.'];

    $customer = trim(sanitize_input($customer)) ?: 'Walk-in';
    $slip     = trim(sanitize_input($slip));
    $slip     = $slip === '' ? null : $slip;

    $conn->begin_transaction();
    try {
        $lines = []; $total = 0.0;
        $lock = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        foreach ($qty_by_id as $id => $qty) {
            $lock->bind_param("i", $id); $lock->execute();
            $p = $lock->get_result()->fetch_assoc();
            if (!$p) { $lock->close(); $conn->rollback(); return ['ok' => false, 'message' => 'A product in this sale is no longer available.']; }
            if ((int)$p['stock'] < $qty) {
                $lock->close(); $conn->rollback();
                return ['ok' => false, 'message' => "Only {$p['stock']} {$p['name']} left. Lower the quantity and try again."];
            }
            $sub = round((float)$p['price'] * $qty, 2);
            $lines[] = ['id' => $id, 'qty' => $qty, 'price' => (float)$p['price'], 'subtotal' => $sub];
            $total += $sub;
        }
        $lock->close();

        // Same discount rules as walkin.php's product sale.
        $discount = 0.0;
        if ($discount_type === 'senior' || $discount_type === 'pwd') $discount = round($total * 0.20, 2);
        elseif ($discount_type === 'employee')                        $discount = round($total * 0.50, 2);
        elseif ($discount_type === 'voucher')                         $discount = min($voucher_amount, $total);
        $final = max(0.0, $total - $discount);

        $uid = get_walkin_customer_id();
        $stmt = $conn->prepare("INSERT INTO orders (user_id, customer_name, phone, total_amount, payment_method, payment_status, approval_status, discount_type, discount_amount, final_amount, slip_number) VALUES (?, ?, '', ?, ?, 'paid', 'approved', ?, ?, ?, ?)");
        $stmt->bind_param("isdssdds", $uid, $customer, $total, $payment_method, $discount_type, $discount, $final, $slip);
        $stmt->execute(); $order_id = $stmt->insert_id; $stmt->close();

        $ins = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $dec = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        foreach ($lines as $L) {
            $ins->bind_param("iiidd", $order_id, $L['id'], $L['qty'], $L['price'], $L['subtotal']); $ins->execute();
            $dec->bind_param("iii", $L['qty'], $L['id'], $L['qty']); $dec->execute();
        }
        $ins->close(); $dec->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Could not record the sale. Please try again.'];
    }

    require_once __DIR__ . '/../notify.php';
    if (function_exists('log_activity')) {
        log_activity($conn, 'product_sale', "Counter sale order #$order_id · $customer · ₱" . number_format($final, 2), 'order', $order_id, null);
    }
    $n = array_sum(array_column($lines, 'qty'));
    return ['ok' => true, 'order_id' => $order_id, 'total' => $final,
            'message' => "Sale recorded · Order #$order_id · $customer · $n item" . ($n > 1 ? 's' : '') . ' · ₱' . number_format($final, 2) . '. Stock updated.'];
}
}
