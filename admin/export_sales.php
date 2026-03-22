<?php
require_once '../config.php';
redirect_if_not_admin();

// ─── EXPORT TYPE ──────────────────────────────────────────────────────────────
$type = $_GET['type'] ?? 'all';

// ─── HELPER: send headers ─────────────────────────────────────────────────────
function xlsHeader($filename) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
}

// ─── HELPER: peso format ──────────────────────────────────────────────────────
function peso($amount) {
    return '₱' . number_format($amount, 2);
}

// ─── QUERY HELPERS ────────────────────────────────────────────────────────────
function fetchAll($conn, $sql) {
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT: ALL SALES (combined workbook-style — multiple sections in one sheet)
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'all' || $type === 'orders') {

    $orders = fetchAll($conn, "
        SELECT 
            o.id,
            o.customer_name,
            o.total_amount,
            o.payment_status,
            o.payment_method,
            COUNT(oi.id) as item_count,
            o.created_at
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");

    $order_items = fetchAll($conn, "
        SELECT 
            oi.order_id,
            p.name as product_name,
            oi.quantity,
            oi.price,
            oi.subtotal,
            o.customer_name,
            o.created_at
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        ORDER BY o.created_at DESC
    ");

    $services = fetchAll($conn, "
        SELECT 
            s.name as service_name,
            COUNT(a.id) as total_bookings,
            SUM(oi.subtotal) as total_revenue,
            AVG(oi.subtotal) as avg_revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        JOIN order_items oi ON a.order_item_id = oi.id
        JOIN orders o ON oi.order_id = o.id
        WHERE a.status IN ('approved','completed')
        GROUP BY s.id, s.name
        ORDER BY total_bookings DESC
    ");

    $products = fetchAll($conn, "
        SELECT 
            p.name as product_name,
            SUM(oi.quantity) as total_qty,
            SUM(oi.subtotal) as total_revenue,
            AVG(oi.price) as avg_price
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE o.payment_status = 'paid'
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC
    ");

    $monthly = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            DATE_FORMAT(created_at, '%M %Y') as month_label,
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value
        FROM orders
        WHERE payment_status = 'paid'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");

    xlsHeader('Spa_Sales_Report_' . date('Y-m-d') . '.xls');

    // ── UTF-8 BOM for Excel ───────────────────────────────────────────────────
    echo "\xEF\xBB\xBF";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 1 — SUMMARY
    // ══════════════════════════════════════════════════════════════════════════
    $total_paid   = array_sum(array_column(array_filter($orders, fn($o) => $o['payment_status'] === 'paid'), 'total_amount'));
    $count_paid   = count(array_filter($orders, fn($o) => $o['payment_status'] === 'paid'));
    $count_unpaid = count(array_filter($orders, fn($o) => $o['payment_status'] === 'unpaid'));
    $avg_order    = $count_paid > 0 ? $total_paid / $count_paid : 0;

    echo "SPA SALES REPORT\t\n";
    echo "Generated:\t" . date('F d, Y h:i A') . "\t\n";
    echo "\t\n";

    echo "── SUMMARY ──\t\n";
    echo "Total Revenue (Paid)\t" . peso($total_paid) . "\t\n";
    echo "Total Paid Orders\t" . $count_paid . "\t\n";
    echo "Pending (Unpaid) Orders\t" . $count_unpaid . "\t\n";
    echo "Average Order Value\t" . peso($avg_order) . "\t\n";
    echo "\t\n";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 2 — MONTHLY REVENUE
    // ══════════════════════════════════════════════════════════════════════════
    echo "── MONTHLY REVENUE ──\t\n";
    echo "Month\tTotal Orders\tTotal Revenue\tAvg Order Value\t\n";
    foreach ($monthly as $m) {
        echo $m['month_label'] . "\t";
        echo $m['total_orders'] . "\t";
        echo peso($m['total_revenue']) . "\t";
        echo peso($m['avg_order_value']) . "\t\n";
    }
    echo "\t\n";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 3 — ALL ORDERS
    // ══════════════════════════════════════════════════════════════════════════
    echo "── ALL ORDERS ──\t\n";
    echo "Order ID\tCustomer Name\tItems\tTotal Amount\tPayment Status\tPayment Method\tDate\t\n";
    foreach ($orders as $o) {
        echo '#' . $o['id'] . "\t";
        echo $o['customer_name'] . "\t";
        echo $o['item_count'] . " item(s)\t";
        echo peso($o['total_amount']) . "\t";
        echo ucfirst($o['payment_status']) . "\t";
        echo ucfirst($o['payment_method'] ?? 'onsite') . "\t";
        echo date('M d, Y', strtotime($o['created_at'])) . "\t\n";
    }
    echo "\t\n";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 4 — ORDER ITEMS BREAKDOWN
    // ══════════════════════════════════════════════════════════════════════════
    echo "── ORDER ITEMS BREAKDOWN ──\t\n";
    echo "Order ID\tCustomer\tProduct\tQty\tUnit Price\tSubtotal\tDate\t\n";
    foreach ($order_items as $item) {
        echo '#' . $item['order_id'] . "\t";
        echo $item['customer_name'] . "\t";
        echo $item['product_name'] . "\t";
        echo $item['quantity'] . "\t";
        echo peso($item['price']) . "\t";
        echo peso($item['subtotal']) . "\t";
        echo date('M d, Y', strtotime($item['created_at'])) . "\t\n";
    }
    echo "\t\n";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 5 — TOP PRODUCTS
    // ══════════════════════════════════════════════════════════════════════════
    echo "── TOP PRODUCTS BY SALES ──\t\n";
    echo "Rank\tProduct Name\tTotal Qty Sold\tTotal Revenue\tAvg Price\t\n";
    foreach ($products as $i => $p) {
        echo ($i + 1) . "\t";
        echo $p['product_name'] . "\t";
        echo $p['total_qty'] . "\t";
        echo peso($p['total_revenue']) . "\t";
        echo peso($p['avg_price']) . "\t\n";
    }
    echo "\t\n";

    // ══════════════════════════════════════════════════════════════════════════
    // SECTION 6 — TOP SERVICES
    // ══════════════════════════════════════════════════════════════════════════
    echo "── TOP SERVICES BY BOOKINGS ──\t\n";
    echo "Rank\tService Name\tTotal Bookings\tTotal Revenue\tAvg Revenue per Booking\t\n";
    foreach ($services as $i => $s) {
        echo ($i + 1) . "\t";
        echo $s['service_name'] . "\t";
        echo $s['total_bookings'] . "\t";
        echo peso($s['total_revenue']) . "\t";
        echo peso($s['avg_revenue']) . "\t\n";
    }

    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT: ORDERS ONLY
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'orders_only') {
    $orders = fetchAll($conn, "
        SELECT 
            o.id,
            o.customer_name,
            o.total_amount,
            o.payment_status,
            o.payment_method,
            COUNT(oi.id) as item_count,
            o.created_at
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");

    xlsHeader('Spa_Orders_' . date('Y-m-d') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "Order ID\tCustomer Name\tItems\tTotal Amount\tPayment Status\tPayment Method\tDate\t\n";
    foreach ($orders as $o) {
        echo '#' . $o['id'] . "\t";
        echo $o['customer_name'] . "\t";
        echo $o['item_count'] . " item(s)\t";
        echo peso($o['total_amount']) . "\t";
        echo ucfirst($o['payment_status']) . "\t";
        echo ucfirst($o['payment_method'] ?? 'onsite') . "\t";
        echo date('M d, Y', strtotime($o['created_at'])) . "\t\n";
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT: MONTHLY SUMMARY ONLY
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'monthly') {
    $monthly = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(created_at, '%M %Y') as month_label,
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value,
            SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END) as paid_revenue,
            SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid_count
        FROM orders
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m') ASC
    ");

    xlsHeader('Spa_Monthly_Sales_' . date('Y-m-d') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "Month\tTotal Orders\tPaid Revenue\tTotal Revenue\tAvg Order Value\tUnpaid Orders\t\n";
    foreach ($monthly as $m) {
        echo $m['month_label'] . "\t";
        echo $m['total_orders'] . "\t";
        echo peso($m['paid_revenue']) . "\t";
        echo peso($m['total_revenue']) . "\t";
        echo peso($m['avg_order_value']) . "\t";
        echo $m['unpaid_count'] . "\t\n";
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT: PRODUCTS ONLY
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'products') {
    $products = fetchAll($conn, "
        SELECT 
            p.name as product_name,
            SUM(oi.quantity) as total_qty,
            SUM(oi.subtotal) as total_revenue,
            AVG(oi.price) as avg_price
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE o.payment_status = 'paid'
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC
    ");

    xlsHeader('Spa_Products_Sales_' . date('Y-m-d') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "Rank\tProduct Name\tTotal Qty Sold\tTotal Revenue\tAvg Price\t\n";
    foreach ($products as $i => $p) {
        echo ($i + 1) . "\t";
        echo $p['product_name'] . "\t";
        echo $p['total_qty'] . "\t";
        echo peso($p['total_revenue']) . "\t";
        echo peso($p['avg_price']) . "\t\n";
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT: SERVICES ONLY
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'services') {
    $services = fetchAll($conn, "
        SELECT 
            s.name as service_name,
            COUNT(a.id) as total_bookings,
            SUM(oi.subtotal) as total_revenue,
            AVG(oi.subtotal) as avg_revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        JOIN order_items oi ON a.order_item_id = oi.id
        JOIN orders o ON oi.order_id = o.id
        WHERE a.status IN ('approved','completed')
        GROUP BY s.id, s.name
        ORDER BY total_bookings DESC
    ");

    xlsHeader('Spa_Services_Sales_' . date('Y-m-d') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "Rank\tService Name\tTotal Bookings\tTotal Revenue\tAvg Revenue per Booking\t\n";
    foreach ($services as $i => $s) {
        echo ($i + 1) . "\t";
        echo $s['service_name'] . "\t";
        echo $s['total_bookings'] . "\t";
        echo peso($s['total_revenue']) . "\t";
        echo peso($s['avg_revenue']) . "\t\n";
    }
    exit;
}
