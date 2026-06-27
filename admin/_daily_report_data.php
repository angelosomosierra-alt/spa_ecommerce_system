<?php
/**
 * _daily_report_data.php — Shared data layer for all daily-report pages.
 *
 * Prerequisites: $conn (mysqli), $report_date (validated 'YYYY-MM-DD')
 * Optionally uses $rpt if already set (POST handlers in daily_report.php set it first).
 *
 * Sets all data arrays, summary totals, payment-mix, and analysis variables.
 */

// ── Report header ─────────────────────────────────────────────────────────────
if (!isset($rpt)) {
    $_rpt_q = $conn->prepare("SELECT * FROM daily_reports WHERE report_date = ? LIMIT 1");
    $_rpt_q->bind_param("s", $report_date);
    $_rpt_q->execute();
    $rpt = $_rpt_q->get_result()->fetch_assoc();
    $_rpt_q->close();
}

// ── Service transactions (regular, non-influencer) ────────────────────────────
$_s = $conn->prepare("
    SELECT
        o.id              AS order_id,
        o.slip_number,
        o.customer_name,
        o.payment_method,
        o.paymongo_method,
        o.discount_type,
        o.discount_amount,
        o.final_amount,
        o.total_amount,
        o.created_at,
        oi.id             AS oi_id,
        oi.service_id,
        COALESCE(s.name, '[Deleted Service]') AS service_name,
        s.price           AS regular_price,
        a.id              AS appt_id,
        a.appointment_date,
        a.duration_minutes,
        a.rate_type,
        a.charged_price,
        a.status          AS appt_status,
        GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', ') AS therapists,
        IFNULL(SUM(at2.commission),0) AS total_commission
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN services    s  ON s.id = oi.service_id
    JOIN appointments a ON a.order_item_id = oi.id
    LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
    LEFT JOIN therapists t ON t.id = at2.therapist_id
    WHERE DATE(a.appointment_date) = ?
      AND a.status     = 'completed'
      AND a.rate_type != 'influencer'
    GROUP BY oi.id
    ORDER BY o.id ASC, oi.id ASC
");
$_s->bind_param("s", $report_date);
$_s->execute();
$service_rows = $_s->get_result()->fetch_all(MYSQLI_ASSOC);
$_s->close();

// ── Add-on services for completed appointments ────────────────────────────────
$appt_ids       = array_column($service_rows, 'appt_id');
$addon_rows     = [];
$addons_by_appt = [];
if (!empty($appt_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($appt_ids), '?'));
    $in_types        = str_repeat('i', count($appt_ids));
    $_a = $conn->prepare("
        SELECT aes.appointment_id, aes.service_id, aes.therapist_id, aes.person_label,
               aes.charged_price, aes.commission, aes.rate_type,
               aes.payment_method,
               s.name      AS service_name,
               t.full_name AS therapist_name
        FROM appointment_extra_services aes
        JOIN services  s ON s.id  = aes.service_id
        LEFT JOIN therapists t ON t.id = aes.therapist_id
        WHERE aes.appointment_id IN ($in_placeholders)
        ORDER BY aes.appointment_id ASC, aes.created_at ASC
    ");
    $_a->bind_param($in_types, ...$appt_ids);
    $_a->execute();
    $addon_rows = $_a->get_result()->fetch_all(MYSQLI_ASSOC);
    $_a->close();
    foreach ($addon_rows as $ar) $addons_by_appt[$ar['appointment_id']][] = $ar;
}

// ── Influencer / Marketing transactions ───────────────────────────────────────
$_i = $conn->prepare("
    SELECT
        o.id              AS order_id,
        o.slip_number,
        o.customer_name,
        o.created_at,
        oi.id             AS oi_id,
        s.name            AS service_name,
        s.price           AS regular_price,
        a.appointment_date,
        a.duration_minutes,
        a.charged_price,
        a.status          AS appt_status,
        GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', ') AS therapists,
        IFNULL(SUM(at2.commission),0) AS commission
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN services    s  ON s.id = oi.service_id
    JOIN appointments a ON a.order_item_id = oi.id
    LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
    LEFT JOIN therapists t ON t.id = at2.therapist_id
    WHERE DATE(a.appointment_date) = ?
      AND a.status    = 'completed'
      AND a.rate_type = 'influencer'
    GROUP BY oi.id
    ORDER BY o.id ASC
");
$_i->bind_param("s", $report_date);
$_i->execute();
$influencer_rows = $_i->get_result()->fetch_all(MYSQLI_ASSOC);
$_i->close();

// ── Upcoming Paid Appointments (paid today, service on a future date) ─────────
$_up_paid = $conn->prepare("
    SELECT
        o.customer_name,
        o.payment_method,
        o.final_amount,
        a.appointment_date,
        s.name  AS service_name
    FROM   orders      o
    JOIN   order_items oi ON oi.order_id      = o.id
    JOIN   services    s  ON s.id             = oi.service_id
    JOIN   appointments a ON a.order_item_id  = oi.id
    WHERE  DATE(o.created_at)       = ?
      AND  DATE(a.appointment_date) > ?
      AND  o.payment_status         = 'paid'
    ORDER BY a.appointment_date ASC
");
$_up_paid->bind_param("ss", $report_date, $report_date);
$_up_paid->execute();
$upcoming_paid = $_up_paid->get_result()->fetch_all(MYSQLI_ASSOC);
$_up_paid->close();

// ── Denominations ─────────────────────────────────────────────────────────────
$denoms_saved = [];
$denom_list   = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.5, 0.1, 0.05];
if ($rpt) {
    $_d = $conn->prepare("
        SELECT denomination, quantity, total
        FROM daily_report_denominations
        WHERE report_id = ?
        ORDER BY denomination DESC
    ");
    $_d->bind_param("i", $rpt['id']);
    $_d->execute();
    $_dr = $_d->get_result();
    while ($row = $_dr->fetch_assoc()) $denoms_saved[floatval($row['denomination'])] = $row;
    $_d->close();
}

// ── Gift Certificates ─────────────────────────────────────────────────────────
$_gc1 = $conn->prepare("SELECT * FROM gift_certificates WHERE report_date = ? AND type = 'sold' ORDER BY id");
$_gc1->bind_param("s", $report_date); $_gc1->execute();
$gc_sold = $_gc1->get_result()->fetch_all(MYSQLI_ASSOC); $_gc1->close();

$_gc2 = $conn->prepare("SELECT * FROM gift_certificates WHERE report_date = ? AND type = 'redeemed' ORDER BY id");
$_gc2->bind_param("s", $report_date); $_gc2->execute();
$gc_redeemed = $_gc2->get_result()->fetch_all(MYSQLI_ASSOC); $_gc2->close();

// ── Unpaids Corp ──────────────────────────────────────────────────────────────
$_up = $conn->prepare("SELECT * FROM unpaids_corp WHERE report_date = ? ORDER BY id");
$_up->bind_param("s", $report_date); $_up->execute();
$unpaids = $_up->get_result()->fetch_all(MYSQLI_ASSOC); $_up->close();

// ── Product Sales (manual) — (qty*price) computed as 'amount' ────────────────
$_ps = $conn->prepare("SELECT *, (qty * price) AS amount FROM daily_product_sales WHERE report_date = ? ORDER BY id");
$_ps->bind_param("s", $report_date); $_ps->execute();
$product_sales = $_ps->get_result()->fetch_all(MYSQLI_ASSOC); $_ps->close();

// ── System product orders ─────────────────────────────────────────────────────
$_spo = $conn->prepare("
    SELECT
        COALESCE(p.name, '[Deleted Product]') AS particular,
        oi.quantity                  AS qty,
        oi.price                     AS price,
        (oi.quantity * oi.price)     AS amount,
        o.payment_method,
        o.paymongo_method,
        o.final_amount,
        o.customer_name,
        o.id                         AS order_id
    FROM   order_items oi
    JOIN   orders    o  ON o.id   = oi.order_id
    LEFT JOIN products  p  ON p.id   = oi.product_id
    WHERE  oi.product_id IS NOT NULL
      AND  DATE(o.created_at) = ?
      AND  o.payment_status   = 'paid'
    ORDER BY o.id ASC, oi.id ASC
");
$_spo->bind_param("s", $report_date);
$_spo->execute();
$system_product_sales = $_spo->get_result()->fetch_all(MYSQLI_ASSOC);
$_spo->close();

// ── Business Expenses ─────────────────────────────────────────────────────────
$_ex = $conn->prepare("SELECT * FROM business_expenses WHERE expense_date = ? ORDER BY created_at");
$_ex->bind_param("s", $report_date); $_ex->execute();
$expenses = $_ex->get_result()->fetch_all(MYSQLI_ASSOC); $_ex->close();

// ── Compute Summary ───────────────────────────────────────────────────────────
$gross_sales     = array_sum(array_column($service_rows, 'charged_price'));
$staff_cf        = array_sum(array_column($service_rows, 'total_commission'));
$total_discounts = array_sum(array_column($service_rows, 'discount_amount'));
$gc_sold_total   = array_sum(array_column($gc_sold,     'amount'));
$gc_redeem_total = array_sum(array_column($gc_redeemed, 'amount'));
$unpaids_total   = array_sum(array_column($unpaids,     'amount'));
$expenses_total  = array_sum(array_column($expenses,    'amount'));
$mktg_expense    = array_sum(array_column($influencer_rows, 'commission'));
$prod_sold_total = array_sum(array_column($product_sales,        'amount'))
                 + array_sum(array_column($system_product_sales, 'amount'));
$net_sales       = array_sum(array_column($service_rows, 'final_amount')) ?: ($gross_sales - $staff_cf);
$addon_gross     = array_sum(array_column($addon_rows, 'charged_price'));
$addon_cf        = array_sum(array_column($addon_rows, 'commission'));
$gross_sales    += $addon_gross;
$staff_cf       += $addon_cf;
$net_sales      += $addon_gross - $addon_cf;

// Payment method totals
$pm_totals = [];
foreach ($service_rows as $row) {
    $pm = (!empty($row['paymongo_method']))
        ? $row['paymongo_method']
        : ($row['payment_method'] ?? 'cash');
    $pm_totals[$pm] = ($pm_totals[$pm] ?? 0) + $row['charged_price'];
}
foreach ($addon_rows as $_ar) {
    $_pm = $_ar['payment_method'] ?? 'cash';
    $pm_totals[$_pm] = ($pm_totals[$_pm] ?? 0) + (float)$_ar['charged_price'];
}
foreach ($system_product_sales as $_sp) {
    $_pm = (!empty($_sp['paymongo_method']))
         ? $_sp['paymongo_method']
         : ($_sp['payment_method'] ?? 'cash');
    $pm_totals[$_pm] = ($pm_totals[$_pm] ?? 0) + (float)$_sp['amount'];
}
$gcash_total  = ($pm_totals['gcash']  ?? 0);
$maya_total   = ($pm_totals['maya']   ?? 0);
$qrph_total   = ($pm_totals['qrph']   ?? 0);
$card_total   = ($pm_totals['card']   ?? 0) + ($pm_totals['bank'] ?? 0);
$online_total = ($pm_totals['online'] ?? 0);

$pos_reading  = floatval($rpt['pos_reading'] ?? 0);
$denom_total  = array_sum(array_map(fn($d) => floatval($d['total']), $denoms_saved));
$cash_on_hand = $denom_total;

// Net Cash matches Recovery Spa's Google Sheet formula:
// Net Cash = Gross Sales − Discounts − Expenses
// (Staff CF and Marketing Expense are paid separately, not deducted from the daily cash)
// Verified: 9005 − 67.35 − 510 = 8427.65 ✓
$net_cash = $gross_sales - $total_discounts - $expenses_total;

// ── Cash received today (drawer / paid-date basis) ────────────────────────────
$_cash_q = $conn->prepare("
    SELECT COALESCE(SUM(o.final_amount), 0) AS total
    FROM   orders o
    WHERE  o.payment_method = 'cash'
      AND  DATE(o.created_at) = ?
      AND  o.payment_status   = 'paid'
");
$_cash_q->bind_param("s", $report_date);
$_cash_q->execute();
$cash_from_orders = (float)$_cash_q->get_result()->fetch_assoc()['total'];
$_cash_q->close();

$cash_from_addons = 0.0;
if (!empty($appt_ids)) {
    $in_ph  = implode(',', array_fill(0, count($appt_ids), '?'));
    $in_t   = str_repeat('i', count($appt_ids));
    $_caq   = $conn->prepare("
        SELECT COALESCE(SUM(aes.charged_price), 0) AS total
        FROM   appointment_extra_services aes
        WHERE  aes.appointment_id IN ($in_ph)
          AND  aes.payment_method  = 'cash'
          AND  aes.payment_status  = 'paid'
    ");
    $_caq->bind_param($in_t, ...$appt_ids);
    $_caq->execute();
    $cash_from_addons = (float)$_caq->get_result()->fetch_assoc()['total'];
    $_caq->close();
}

$manual_prod_total   = array_sum(array_column($product_sales, 'amount'));
$cash_received_today = $cash_from_orders + $cash_from_addons + $manual_prod_total;
$expected_drawer     = $cash_received_today - $expenses_total;
// (Short) / Over = COH − Net Cash  (matches the sheet: with COH=0, gives −8427.65)
$short_over          = $cash_on_hand - $net_cash;

// ════════════════════════════════════════════════════════════════════════════
// ANALYSIS LAYER — all vars prefixed $wow_ or named clearly
// ════════════════════════════════════════════════════════════════════════════

// KPI base
$transaction_count = count($service_rows);
$guests_served     = count(array_unique(array_column($service_rows, 'customer_name')));
$avg_check         = $transaction_count > 0 ? round($gross_sales / $transaction_count, 2) : 0.0;

// Week-over-week: same queries on report_date − 7 days
$wow_date = date('Y-m-d', strtotime($report_date . ' -7 days'));
$_wq = $conn->prepare("
    SELECT
        COALESCE(SUM(a.charged_price), 0)   AS gross_sales,
        COALESCE(SUM(at2.commission), 0)    AS staff_cf,
        COALESCE(SUM(o.discount_amount), 0) AS total_discounts,
        COUNT(DISTINCT a.id)                AS transaction_count,
        COUNT(DISTINCT o.customer_name)     AS guests_served
    FROM orders o
    JOIN order_items oi ON oi.order_id     = o.id
    JOIN appointments a ON a.order_item_id = oi.id
    LEFT JOIN appointment_therapists at2 ON at2.appointment_id = a.id
    WHERE DATE(a.appointment_date) = ?
      AND a.status     = 'completed'
      AND a.rate_type != 'influencer'
");
$_wq->bind_param("s", $wow_date);
$_wq->execute();
$wow = $_wq->get_result()->fetch_assoc() ?? [];
$_wq->close();
$wow['gross_sales']       = floatval($wow['gross_sales']       ?? 0);
$wow['staff_cf']          = floatval($wow['staff_cf']          ?? 0);
$wow['total_discounts']   = floatval($wow['total_discounts']   ?? 0);
$wow['net_sales']         = $wow['gross_sales'] - $wow['staff_cf'];
$wow['transaction_count'] = intval($wow['transaction_count']   ?? 0);
$wow['guests_served']     = intval($wow['guests_served']       ?? 0);
$wow['avg_check']         = $wow['transaction_count'] > 0
    ? round($wow['gross_sales'] / $wow['transaction_count'], 2)
    : 0.0;

// Daypart Performance (appointment time buckets)
$_dp = $conn->prepare("
    SELECT
        CASE
            WHEN HOUR(a.appointment_date) BETWEEN 6  AND 11 THEN 'Morning'
            WHEN HOUR(a.appointment_date) BETWEEN 12 AND 13 THEN 'Midday'
            WHEN HOUR(a.appointment_date) BETWEEN 14 AND 17 THEN 'Afternoon'
            ELSE 'Evening'
        END                  AS daypart,
        COUNT(*)             AS txn_count,
        SUM(a.charged_price) AS revenue
    FROM appointments a
    WHERE DATE(a.appointment_date) = ?
      AND a.status     = 'completed'
      AND a.rate_type != 'influencer'
    GROUP BY daypart
");
$_dp->bind_param("s", $report_date);
$_dp->execute();
$_dp_raw = $_dp->get_result()->fetch_all(MYSQLI_ASSOC);
$_dp->close();
$daypart_data = [
    'Morning'   => ['label' => '6 AM – 11 AM',  'txn_count' => 0, 'revenue' => 0.0],
    'Midday'    => ['label' => '12 PM – 1 PM',  'txn_count' => 0, 'revenue' => 0.0],
    'Afternoon' => ['label' => '2 PM – 5 PM',   'txn_count' => 0, 'revenue' => 0.0],
    'Evening'   => ['label' => '6 PM onwards',  'txn_count' => 0, 'revenue' => 0.0],
];
foreach ($_dp_raw as $_dp_r) {
    if (isset($daypart_data[$_dp_r['daypart']])) {
        $daypart_data[$_dp_r['daypart']]['txn_count'] = intval($_dp_r['txn_count']);
        $daypart_data[$_dp_r['daypart']]['revenue']   = floatval($_dp_r['revenue']);
    }
}

// Top 5 Services by revenue
$_ts = $conn->prepare("
    SELECT COALESCE(s.name, '[Deleted Service]') AS service_name,
           SUM(a.charged_price) AS revenue,
           COUNT(a.id)          AS txn_count
    FROM appointments a
    JOIN order_items oi ON oi.id       = a.order_item_id
    LEFT JOIN services s ON s.id       = oi.service_id
    WHERE DATE(a.appointment_date) = ?
      AND a.status     = 'completed'
      AND a.rate_type != 'influencer'
    GROUP BY oi.service_id
    ORDER BY revenue DESC
    LIMIT 5
");
$_ts->bind_param("s", $report_date);
$_ts->execute();
$top_services = $_ts->get_result()->fetch_all(MYSQLI_ASSOC);
$_ts->close();

// Therapist Productivity
$_tp = $conn->prepare("
    SELECT t.full_name,
           COUNT(DISTINCT a.id)  AS svc_count,
           SUM(at2.commission)   AS commission,
           SUM(a.charged_price)  AS revenue
    FROM appointment_therapists at2
    JOIN therapists   t ON t.id  = at2.therapist_id
    JOIN appointments a ON a.id  = at2.appointment_id
    WHERE DATE(a.appointment_date) = ?
      AND a.status = 'completed'
    GROUP BY at2.therapist_id
    ORDER BY revenue DESC
");
$_tp->bind_param("s", $report_date);
$_tp->execute();
$therapist_stats = $_tp->get_result()->fetch_all(MYSQLI_ASSOC);
$_tp->close();

// Discount Impact
$discount_pct_of_gross = $gross_sales > 0
    ? round($total_discounts / $gross_sales * 100, 1)
    : 0.0;
$influencer_comp_total = $mktg_expense;
