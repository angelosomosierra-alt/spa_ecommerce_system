<?php
/**
 * export_daily_report.php — Template-based Excel export.
 *
 * Loads assets/templates/sales_report_template.xlsx (the client's template),
 * drops the "Copy of 13" and "16" day-sheets, renames sheet "28" to the report
 * date, fills in all data zones, and streams the result as a download.
 *
 * Template layout (sheet "28"):
 *   A-C   rows 17-33  : Cash Breakdown (qty | denom | =A*B) + total C33
 *   A-B   rows 35-57  : Summary Report labels + values/formulas
 *   E-W   rows 16-57  : Sales Services data + totals row 58
 *   E-N   rows 65-75  : Influencer / Marketing data + N76 formula
 *   F-H   rows 80-94  : Expenses + H95 total formula
 *   J-N   rows 80-86  : Unpaids Corp + N90 / N87 total formulas
 *   P-U   rows 80-85  : Service GC Sold + T86 total formula
 *   R-V   rows 97-103 : Product Sold + V104/V105 total formulas
 *
 * CRITICAL: Never overwrite row 58 (SUM totals) or any preserved formula cells.
 * Only write to the anchor (top-left) cell of each merged range.
 *
 * Requires: assets/templates/sales_report_template.xlsx must exist.
 */
require_once '../config.php';
redirect_if_not_admin();

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

require_once '../vendor/autoload.php';

$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || !strtotime($report_date)) {
    $report_date = date('Y-m-d');
}
require_once __DIR__ . '/_daily_report_data.php';

// ── Load the client's template ────────────────────────────────────────────────
$tpl_path = dirname(__DIR__) . '/assets/templates/sales_report_template.xlsx';
if (!file_exists($tpl_path)) {
    http_response_code(500);
    echo 'Template not found: assets/templates/sales_report_template.xlsx. '
       . 'Place the client\'s Excel template at that path and retry.';
    exit;
}

$reader = IOFactory::createReader('Xlsx');
$reader->setReadDataOnly(false);   // preserve formulas, styles, images
$wb = $reader->load($tpl_path);

// ── Sheet management: drop day-sheets we don't need ──────────────────────────
foreach (['Copy of 13', '16'] as $del_name) {
    $del_sh = $wb->getSheetByName($del_name);
    if ($del_sh) {
        $wb->removeSheetByIndex($wb->getIndex($del_sh));
    }
}

$ws = $wb->getSheetByName('28');
if (!$ws) {
    http_response_code(500);
    echo 'Sheet "28" not found in the template.';
    exit;
}

// Rename "28" → formatted date (e.g. "Jun 28") and set as active
$ws->setTitle(date('M j', strtotime($report_date)));
$wb->setActiveSheetIndex($wb->getIndex($ws));

// ── Helper: set a single cell value (write only to anchor of merged ranges) ──
$cv = function (string $cell, $value) use ($ws): void {
    $ws->getCell($cell)->setValue($value);
};

// ── Helper: clear a block of cells (values only; preserves format + merges) ──
$clr = function (array $cols, int $r_start, int $r_end) use ($ws): void {
    for ($r = $r_start; $r <= $r_end; $r++) {
        foreach ($cols as $col) {
            $ws->getCell($col . $r)->setValue('');
        }
    }
};

// ════════════════════════════════════════════════════════════════════════════
// 1. HEADER — date, opening/closing cashier
// ════════════════════════════════════════════════════════════════════════════
// H5:K5 is merged — write to anchor H5 as an Excel serial date
$ws->getCell('H5')->setValue(ExcelDate::PHPToExcel(strtotime($report_date)));
$ws->getStyle('H5')->getNumberFormat()->setFormatCode('mmmm d, yyyy');

if ($rpt) {
    // H6 = opening cashier (not part of the J6:K6 merge)
    $cv('H6', $rpt['opening_cashier'] ?? '');
    // J6:K6 merged — write to anchor J6
    $cv('J6', $rpt['closing_cashier'] ?? '');
}

// ════════════════════════════════════════════════════════════════════════════
// 1b. INSERT comm_25 COLUMN — insert a blank column before R so that:
//     O=comm_30, P=comm_20, Q=comm_15 stay untouched (existing formulas safe)
//     NEW R = comm_25, S = disc_50_staff (was R), T = net_sales (was S), etc.
//     PhpSpreadsheet shifts all column references in formulas automatically.
// ════════════════════════════════════════════════════════════════════════════
$ws->insertNewColumnBefore('R', 1);

// New R column is a blank physical insert — copy header style and width from
// adjacent Q14/Q15 (comm_15 header) so the new comm_25 column looks uniform.
$ws->duplicateStyle($ws->getStyle('Q14'), 'R14');
$ws->duplicateStyle($ws->getStyle('Q15'), 'R15');
$cv('R14', "25%\nCommission fee");
$q_width = $ws->getColumnDimension('Q')->getWidth();
$ws->getColumnDimension('R')->setWidth($q_width);

// ════════════════════════════════════════════════════════════════════════════
// 2. CLEAR DATA ZONES
//    Rule: only clear data-entry cells; never touch row 58 SUM formulas,
//    preserved summary formulas in B36-B39/B41/B47-B52/B56, or other
//    structural formula rows (N76, H95, N87/N90, T86, V104/V105, etc.).
//    After column insertion: old W (remarks) is now at X — clear X too.
// ════════════════════════════════════════════════════════════════════════════

// Sales data rows 16–57 (E through X; row 58 is preserved SUM row)
$sales_cols = ['E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X'];
$clr($sales_cols, 16, 57);

// Cash breakdown: quantity column A only (rows 17-28; B=denominations, C==A*B stay)
$clr(['A'], 17, 28);

// Summary value cells we overwrite (formula cells B36-B39/B41/B47-B52/B56 stay)
foreach ([40, 42, 43, 44, 45, 46, 53] as $br) {
    $cv('B' . $br, '');
}

// Influencer data rows 65–75 (M col has =K+L formula we'll rewrite per row)
$clr(['E','F','G','H','I','J','K','L','M','N'], 65, 75);

// Expenses F–H rows 80–94 (H95 = =SUM(H80:H94) preserved)
$clr(['F','G','H'], 80, 94);

// Unpaids Corp J–N rows 80–86 (N87/N90 formulas preserved)
$clr(['J','K','L','M','N'], 80, 86);

// Service GC Sold P–V rows 80–85 (after col insert: old T86→U86, old U→V)
$clr(['P','Q','R','S','T','U','V'], 80, 85);

// Product Sold S–W rows 97–103 (after col insert: old R→S, old V104/V105→W104/W105)
$clr(['R','S','T','U','V','W'], 97, 103);

// ════════════════════════════════════════════════════════════════════════════
// 3. SALES DATA ROWS (rows 16–57, columns E–W)
//    Template SUM row 58 covers K15:K57 (and likewise for L–S, U).
//    Max 42 rows (16 to 57). Overflow rows are silently truncated.
// ════════════════════════════════════════════════════════════════════════════
$data_row = 16;
foreach ($spreadsheet_rows as $sr) {
    if ($data_row > 57) break;

    $cv('E' . $data_row, $sr['time_in']      ?? '');
    $cv('F' . $data_row, $sr['time_out']     ?? '');
    $cv('G' . $data_row, $sr['slip_no']      ?? '');
    $cv('H' . $data_row, $sr['client_name']  ?? '');
    $cv('I' . $data_row, $sr['service_name'] ?? '');
    $cv('J' . $data_row, $sr['stylist']      ?? '');

    // Prices as numbers (replace template VLOOKUP formulas)
    $cv('K' . $data_row, (float)($sr['regular_price'] ?? 0));
    $cv('L' . $data_row, (float)($sr['promo_price']   ?? 0));

    // Discount/commission columns — write non-zero values only (cleaner output)
    $m   = (float)($sr['celeb_10']       ?? 0);
    $n   = (float)($sr['disc_20_pwd']    ?? 0);
    $o   = (float)($sr['comm_30']        ?? 0);
    $p   = (float)($sr['comm_20']        ?? 0);
    $q   = (float)($sr['comm_15']        ?? 0);
    $r   = (float)($sr['comm_25']        ?? 0); // NEW: comm_25 now at column R
    $s   = (float)($sr['disc_50_staff']  ?? 0); // disc_50_staff shifted to S
    $adv = (float)($sr['advance_payment']?? 0);

    if ($m  > 0) $cv('M' . $data_row, $m);
    if ($n  > 0) $cv('N' . $data_row, $n);
    if ($o  > 0) $cv('O' . $data_row, $o);
    if ($p  > 0) $cv('P' . $data_row, $p);
    if ($q  > 0) $cv('Q' . $data_row, $q);
    if ($r  > 0) $cv('R' . $data_row, $r);
    if ($s  > 0) $cv('S' . $data_row, $s);

    // T = net sales formula: =L-SUM(O:R)  (all four commission columns deducted)
    $cv('T' . $data_row, "=L{$data_row}-SUM(O{$data_row}:R{$data_row})");
    // U = mode of payment, V = advance payment, W = secondary MOP (blank), X = remarks
    $cv('U' . $data_row, $sr['mode_of_payment'] ?? '');
    if ($adv > 0) $cv('V' . $data_row, $adv);
    $cv('X' . $data_row, $sr['remarks'] ?? '');

    $data_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 4. CASH BREAKDOWN — quantities to column A (rows 17-28)
//    B17:B28 = denomination values (already in template, preserved)
//    C17:C28 = =A*B formulas (already in template, preserved)
//    C33     = =SUM(C17:C31) total (already in template, preserved)
// ════════════════════════════════════════════════════════════════════════════
$denom_row_map = [
    1000 => 17, 500 => 18, 200 => 19, 100 => 20,
      50 => 21,  20 => 22,  10 => 23,   5 => 24,
       1 => 25, 0.5 => 26, 0.1 => 27, 0.05 => 28,
];
foreach ($denom_row_map as $denom => $drow) {
    $qty = (int)($denoms_saved[(float)$denom]['quantity'] ?? 0);
    if ($qty > 0) {
        $cv('A' . $drow, $qty);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// 5. SUMMARY REPORT — column B (rows 36-57)
//
//    PRESERVED formula cells (do NOT overwrite, EXCEPT B37 which is updated):
//      B36 =B39+B38-B50  (Gross Sales)
//      B37 — written explicitly below as =O58+P58+Q58+R58+N76 (Staff CF, now includes comm_25)
//      B38 =T86  (Sold GC)
//      B39 =K58+O76+V104  (POS Reading — derived from sheet data)
//      B41 =M58  (Celeb. Discounts)
//      B47 =N90  (Unpaids)
//      B48 =U58  (Advance Payment)
//      B49 =H95  (Expenses)
//      B50 =O76  (Marketing Expense)
//      B51 =V105  (Product Sold)
//      B52 =B39-B40-…-B51  (Net Cash)
//      B56 =B53-B52  ((Short)/Over)
//
//    WRITTEN as values:
//      B40 = total discounts
//      B42 = GC redeemed total
//      B43 = card/swiper total
//      B44 = GCash total
//      B45 = Maya total
//      B46 = Maya DP
//      B53 = Cash on Hand (from denominations)
// ════════════════════════════════════════════════════════════════════════════
// Newly inserted column R needs its own SUM formula (old R58 shifted to S58)
$cv('R58', '=SUM(R16:R57)');
// Update Staff CF formula to include comm_25 column
$cv('B37', '=O58+P58+Q58+R58+N76');
$cv('B40', (float)$total_discounts);
$cv('B42', (float)$gc_redeem_total);
$cv('B43', (float)$card_total);
$cv('B44', (float)$gcash_total);
$cv('B45', (float)$maya_total);
$cv('B46', (float)$maya_dp_total);
$cv('B53', (float)$cash_on_hand);

// ════════════════════════════════════════════════════════════════════════════
// 6. INFLUENCER / MARKETING (rows 65-75, columns E-N)
//    N76 = =SUM(L65:L75) formula preserved (commission fee total → Staff CF)
//    O76 = template formula preserved (total MKTG expense → POS Reading)
//    Max 11 influencer rows.
// ════════════════════════════════════════════════════════════════════════════
$inf_row = 65;
foreach ($influencer_rows as $inf) {
    if ($inf_row > 75) break;

    $ts  = strtotime($inf['appointment_date']);
    $dur = max(0, (int)($inf['duration_minutes'] ?? 0));

    $cv('E' . $inf_row, date('h:i A', $ts));
    $cv('F' . $inf_row, $dur > 0 ? date('h:i A', $ts + $dur * 60) : '');
    $cv('G' . $inf_row, $inf['slip_number']  ?? '');
    $cv('H' . $inf_row, $inf['customer_name']?? '');
    $cv('I' . $inf_row, $inf['service_name'] ?? '');
    $cv('J' . $inf_row, $inf['therapists']   ?? '');
    $cv('K' . $inf_row, (float)($inf['at_cost']    ?? 0));  // AT COST
    $cv('L' . $inf_row, (float)($inf['commission'] ?? 0));  // Commission fee (fix)
    $cv('M' . $inf_row, "=K{$inf_row}+L{$inf_row}");       // Total MKTG Exp formula

    $inf_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 7. EXPENSES (rows 80-94, columns F-H)
//    H95 = =SUM(H80:H94) preserved
// ════════════════════════════════════════════════════════════════════════════
$exp_row = 80;
foreach ($expenses as $e) {
    if ($exp_row > 94) break;
    $cv('F' . $exp_row, '•');
    $cv('G' . $exp_row, $e['label']     ?? ($e['particular'] ?? ''));
    $cv('H' . $exp_row, (float)($e['amount'] ?? 0));
    $exp_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 8. UNPAIDS CORP (rows 80-86, columns J/K/N)
//    N87 = =SUM(N79:N86), N90 = =SUM(N80:N86) — both preserved
//    L and M columns are blank/merged — write only J, K, N
// ════════════════════════════════════════════════════════════════════════════
$unp_row = 80;
foreach ($unpaids as $u) {
    if ($unp_row > 86) break;
    $cv('J' . $unp_row, '•');
    $cv('K' . $unp_row, $u['client_name']   ?? ($u['customer_name'] ?? ''));
    $cv('N' . $unp_row, (float)($u['amount'] ?? 0));
    $unp_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 9. SERVICE GC SOLD (rows 80-85, columns P-U)
//    T86 = =SUM(T80:T85) preserved (referenced by summary B38 via =T86)
// ════════════════════════════════════════════════════════════════════════════
$gc_row = 80;
foreach ($gc_sold as $gc) {
    if ($gc_row > 85) break;
    $cv('P' . $gc_row, $gc['series']       ?? '');
    $cv('Q' . $gc_row, $gc['client_name']  ?? '');
    // After insertNewColumnBefore('R'): voucher→S, qty→T, amount→U, remarks→V
    $cv('S' . $gc_row, $gc['voucher_code'] ?? '');
    $cv('T' . $gc_row, (int)($gc['qty']    ?? 1));
    $cv('U' . $gc_row, (float)($gc['amount']    ?? 0));
    $cv('V' . $gc_row, $gc['remarks']      ?? '');
    $gc_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 10. PRODUCT SOLD (rows 97-103, columns R-V)
//     V104 = =SUM(V97:V103), V105 = =SUM(V97:V104) — both preserved
//     V per-row formula =T*U matches the client's original =T97*U97 pattern.
//     Combines manual product_sales and system_product_sales (max 7 rows).
// ════════════════════════════════════════════════════════════════════════════
$all_products = array_merge($product_sales, $system_product_sales);
$prod_row = 97;
foreach ($all_products as $p) {
    if ($prod_row > 103) break;
    // After insertNewColumnBefore('R'): bullet→S, particular→T, qty→U, price→V, total→W
    $cv('S' . $prod_row, '•');
    $cv('T' . $prod_row, $p['particular'] ?? '');
    $cv('U' . $prod_row, (int)($p['qty']   ?? 1));
    $cv('V' . $prod_row, (float)($p['price'] ?? 0));
    $cv('W' . $prod_row, "=U{$prod_row}*V{$prod_row}");
    $prod_row++;
}

// ════════════════════════════════════════════════════════════════════════════
// 11. STREAM TO BROWSER
// ════════════════════════════════════════════════════════════════════════════
$filename = 'Recovery_Spa_Report_' . date('M_j_Y', strtotime($report_date)) . '.xlsx';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
(new Xlsx($wb))->save('php://output');
exit;
