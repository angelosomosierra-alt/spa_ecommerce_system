<?php
/**
 * _daily_report_data.php — Shared data layer for all daily-report pages.
 *
 * Prerequisites: $conn (mysqli), $report_date (validated 'YYYY-MM-DD')
 * Optionally uses $rpt if already set (POST handlers in daily_report.php set it first).
 *
 * Sets all data arrays, summary totals, payment-mix, and analysis variables.
 */

// ── Idempotent DB migrations ──────────────────────────────────────────────────
(function() use ($conn) {
    $appt_cols = [];
    $res = $conn->query("SHOW COLUMNS FROM appointments");
    if ($res) { while ($r = $res->fetch_assoc()) $appt_cols[] = $r['Field']; }
    if (!in_array('celebration_discount', $appt_cols))
        $conn->query("ALTER TABLE appointments ADD COLUMN celebration_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    if (!in_array('advance_payment', $appt_cols))
        $conn->query("ALTER TABLE appointments ADD COLUMN advance_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00");

    $svc_cols = [];
    $res2 = $conn->query("SHOW COLUMNS FROM services");
    if ($res2) { while ($r = $res2->fetch_assoc()) $svc_cols[] = $r['Field']; }
    if (!in_array('at_cost', $svc_cols))
        $conn->query("ALTER TABLE services ADD COLUMN at_cost DECIMAL(10,2) NULL");

    $rpt_cols = [];
    $res3 = $conn->query("SHOW COLUMNS FROM daily_reports");
    if ($res3) { while ($r = $res3->fetch_assoc()) $rpt_cols[] = $r['Field']; }
    if (!in_array('maya_dp', $rpt_cols))
        $conn->query("ALTER TABLE daily_reports ADD COLUMN maya_dp DECIMAL(10,2) NOT NULL DEFAULT 0.00");
})();

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
        -- charged_price is stored as total (per_person × people_count)
        -- multiplication was removed after storage fix was confirmed
        a.charged_price,
        a.celebration_discount,
        a.advance_payment,
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
        -- charged_price is stored as total (per_person × people_count)
        -- multiplication was removed after storage fix was confirmed
        a.charged_price,
        a.status          AS appt_status,
        s.at_cost,
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
$staff_cf              = array_sum(array_column($service_rows, 'total_commission'));
$total_discounts       = array_sum(array_column($service_rows, 'discount_amount'));
$celeb_discount        = array_sum(array_column($service_rows, 'celebration_discount'));
$advance_payment_total = array_sum(array_column($service_rows, 'advance_payment'));
$gc_sold_total         = array_sum(array_column($gc_sold,      'amount'));
$gc_redeem_total       = array_sum(array_column($gc_redeemed,  'amount'));
$unpaids_total         = array_sum(array_column($unpaids,      'amount'));
$expenses_total        = array_sum(array_column($expenses,     'amount'));
$prod_sold_total       = array_sum(array_column($product_sales,        'amount'))
                       + array_sum(array_column($system_product_sales, 'amount'));
$addon_gross           = array_sum(array_column($addon_rows, 'charged_price'));
$addon_cf              = array_sum(array_column($addon_rows, 'commission'));
$staff_cf             += $addon_cf;

// mktg_expense = SUM(at_cost + commission) per influencer row; commission (fixed CF) flows into staff_cf
$mktg_expense              = 0.0;
$influencer_at_cost_total  = 0.0;
foreach ($influencer_rows as $_inf_r) {
    $_inf_at   = floatval($_inf_r['at_cost']    ?? 0);
    $_inf_cf   = floatval($_inf_r['commission'] ?? 0);
    $mktg_expense             += $_inf_at + $_inf_cf;
    $influencer_at_cost_total += $_inf_at;
    $staff_cf                 += $_inf_cf;
}

// POS Reading: manual from DB; computed = sum of all service+addon+influencer revenue + products
$pos_reading          = floatval($rpt['pos_reading'] ?? 0);
$pos_reading_computed = array_sum(array_column($service_rows, 'charged_price'))
                      + $addon_gross + $mktg_expense + $prod_sold_total;
$pos_variance         = $pos_reading - $pos_reading_computed;

// GROSS SALES = POS_READING + SOLD_GC − MARKETING_EXPENSE
$gross_sales   = $pos_reading + $gc_sold_total - $mktg_expense;
$net_sales     = $gross_sales - $staff_cf;
$maya_dp_total = floatval($rpt['maya_dp'] ?? 0);

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

$denom_total  = array_sum(array_map(fn($d) => floatval($d['total']), $denoms_saved));
$cash_on_hand = $denom_total;

// ── NET CASH — mirrors source workbook sheet "28" cell B52 ───────────────────
//
// B52 formula (verbatim from SALES REPORT RECOVERY SPA.xlsx, sheet "28"):
//   =B39-B40-B41-B42-B43-B44-B45-B46-B47-B48-B49-B50-B51
//
// Cell map (verified by reading the workbook directly):
//   $pos_reading           ← B39  POS READING         (manual entry)
//   $total_discounts       ← B40  DISCOUNTS            (374.50 on test date)
//   $celeb_discount        ← B41  CELEB. DISCOUNTS 10%
//   $gc_redeem_total       ← B42  REDEEMED GC
//   $card_total            ← B43  SWIPER
//   $gcash_total           ← B44  GCASH (SALES)
//   $maya_total            ← B45  MAYA (SALES)
//   $maya_dp_total         ← B46  MAYA (DP)
//   $unpaids_total         ← B47  UNPAIDS
//   $advance_payment_total ← B48  ADVANCE PAYMENT
//   $expenses_total        ← B49  EXPENSES
//   $mktg_expense          ← B50  MARKETING EXPENSE
//   $prod_sold_total       ← B51  PRODUCT SOLD         (not yet used below)
//
// STAFF CF (B37 = =O58+P58+Q58+N76) is NOT in B52 and is intentionally absent
// here. B40 is DISCOUNTS, not Staff CF. Proof: with the test-date numbers,
// subtracting Staff CF would yield 753 − 1,992.10 = −1,239.10, which contradicts
// the verified result of ₱753. Commissions are settled outside the cash drawer.
//
// MARKETING EXPENSE appears in BOTH $gross_sales and $net_cash. This is correct
// and is NOT a double-deduction: $net_cash derives from $pos_reading directly
// (not from $gross_sales), so each formula independently deducts mktg_expense
// from its own base. The Excel does the same: B36 = B39+B38−B50 and
// B52 = B39−…−B50, both referencing B39 and B50 independently.
//
// Golden-test arithmetic (sheet "28", date 2026-06-28):
//   12,576.00 (POS)
//  −  374.50  (discounts)   −    0.00 (celeb)
//  −    0.00  (gc_redeem)   − 8,177.50 (card/swiper)
//  −    0.00  (gcash/maya/qrph/maya_dp)
//  − 2,072.50 (unpaids)     −  644.50  (advance)
//  −  554.00  (expenses)    −    0.00  (mktg)
//  ─────────────────────────────────────────────
//  =  753.00  ✓  (matches COH; SHORT/OVER = 0)
$net_cash = $pos_reading
          - $gc_redeem_total
          - $card_total
          - $gcash_total
          - $maya_total
          - $qrph_total
          - $maya_dp_total
          - $unpaids_total
          - $advance_payment_total
          - $total_discounts
          - $celeb_discount
          - $expenses_total
          - $mktg_expense;

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
// revenue = proportional share: (charged_price / people_count) × people_handled
// avoids double-counting when multiple therapists share one appointment row
$_tp = $conn->prepare("
    SELECT t.full_name,
           COUNT(DISTINCT a.id) AS svc_count,
           SUM(at2.commission)  AS commission,
           SUM(
               (a.charged_price / GREATEST(IFNULL(a.people_count, 1), 1))
               * IFNULL(at2.people_handled, 1)
           ) AS revenue
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
