<?php
/**
 * export_daily_report.php — Single-sheet Excel export.
 * Mirrors Recovery Spa's Google Sheet: one worksheet, blocks arranged
 * left-zone (Cash Breakdown + Summary) beside right-zone (Sales Services),
 * then remaining blocks stacked below, Analysis (Internal) at the very bottom.
 *
 * Uses PhpSpreadsheet >=2.0 (^5.8 installed).
 * API: getCell([$col,$row]) / setCellValue([$col,$row], val) — NO ByColumnAndRow.
 */
require_once '../config.php';
redirect_if_not_admin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

require_once '../vendor/autoload.php';

$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || !strtotime($report_date)) {
    $report_date = date('Y-m-d');
}
require_once __DIR__ . '/_daily_report_data.php';

// ── Palette ───────────────────────────────────────────────────────────────────
$BROWN = '58281C';
$GREEN = '198754';
$RED   = 'DC3545';
$BLUE  = '0070F3';
$MONEY = '[$₱-3409]#,##0.00';

$fn_date   = date('F d, Y', strtotime($report_date));
$fn_export = date('F d, Y h:i A');

// ── Workbook ──────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Recovery Spa & Massage')
    ->setTitle("Daily Report {$report_date}");
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(9);

$sh = $spreadsheet->getActiveSheet()->setTitle('Daily Report');

// ── Column widths ─────────────────────────────────────────────────────────────
// Left zone: A(1) Item/Label, B(2) Qty, C(3) Amount
// Gap:       D(4) spacer
// Sales log: E(5)–U(21) = 17 columns (added Celeb 10% at col 13)
$widths = [
    'A'=>22,'B'=>7,'C'=>12,      // Cash+Summary
    'D'=>1.5,                    // spacer
    'E'=>8,'F'=>8,               // Time In/Out
    'G'=>10,                     // Slip No.
    'H'=>18,                     // Client Name
    'I'=>22,                     // Services
    'J'=>15,                     // Stylist
    'K'=>11,'L'=>11,             // Regular / Promo
    'M'=>10,                     // Celeb 10%
    'N'=>10,                     // Disc 20%
    'O'=>10,'P'=>10,'Q'=>10,     // 30/20/15% CF
    'R'=>10,                     // 50% Staff Disc
    'S'=>11,                     // Net Sales
    'T'=>9,'U'=>14,              // Payment / Remarks
];
foreach ($widths as $col => $w) $sh->getColumnDimension($col)->setWidth($w);

// ── Style arrays ──────────────────────────────────────────────────────────────
$S_TITLE = [
    'font'  => ['bold'=>true,'size'=>13,'color'=>['rgb'=>$BROWN]],
];
$S_SEC = [
    'font'  => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF']],
    'fill'  => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$BROWN]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER],
];
$S_HDR = [
    'font'    => ['bold'=>true,'size'=>8,'color'=>['rgb'=>'FFFFFF']],
    'fill'    => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'7A4F2B']],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'wrapText'=>true,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]],
];
$S_DAT = [
    'font'    => ['size'=>8],
    'borders' => ['bottom'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'DDDDDD']]],
];
$S_TOT = [
    'font'    => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$BROWN]],
    'fill'    => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F5EDE5']],
    'borders' => [
        'top'    => ['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['rgb'=>$BROWN]],
        'bottom' => ['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['rgb'=>$BROWN]],
    ],
];
$S_ANAL_BANNER = [
    'font'  => ['bold'=>true,'size'=>11,'color'=>['rgb'=>'FFFFFF']],
    'fill'  => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1A1A1A']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
];
$S_ANAL_SEC = [
    'font'  => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'444444']],
    'fill'  => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8E8E8']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT],
];

// ── Coordinate helpers ────────────────────────────────────────────────────────
function col2L(int $c): string { return Coordinate::stringFromColumnIndex($c); }
function rng(int $c1,int $r1,int $c2,int $r2): string {
    return col2L($c1).$r1.':'.col2L($c2).$r2;
}
function c(int $col, int $row): string { return col2L($col).$row; }

// Apply style to column range on a row
function rowStyle($sh, int $r, int $c1, int $c2, array $s): void {
    $sh->getStyle(rng($c1,$r,$c2,$r))->applyFromArray($s);
}

// Section-header row: merges c1..c2, applies style, advances &$row
function secRow($sh, int &$row, string $title, int $c1, int $c2, array $style): void {
    $sh->setCellValue([$c1,$row], $title);
    if ($c2 > $c1) $sh->mergeCells(rng($c1,$row,$c2,$row));
    rowStyle($sh,$row,$c1,$c2,$style);
    $sh->getRowDimension($row)->setRowHeight(14);
    $row++;
}

// Table-header row: fills headers starting at c1, advances &$row
function hdrRow($sh, int &$row, array $headers, int $c1, array $style): void {
    $col = $c1;
    foreach ($headers as $h) { $sh->setCellValue([$col,$row], $h); $col++; }
    $c2 = $c1 + count($headers) - 1;
    rowStyle($sh,$row,$c1,$c2,$style);
    $sh->getRowDimension($row)->setRowHeight(22);
    $row++;
}

// Apply currency format to a list of [col,row] pairs
function moneyFmt($sh, array $cells, string $fmt): void {
    foreach ($cells as [$c,$r]) $sh->getStyle([$c,$r])->getNumberFormat()->setFormatCode($fmt);
}

// ════════════════════════════════════════════════════════════════════════════
// ROW 1 — MAIN TITLE (spans all 20 cols)
// ════════════════════════════════════════════════════════════════════════════
$sh->mergeCells('A1:U1');
$sh->setCellValue([1,1], "RECOVERY SPA & MASSAGE — DAILY SALES REPORT — {$fn_date}");
$sh->getStyle('A1:T1')->applyFromArray($S_TITLE);
$sh->getRowDimension(1)->setRowHeight(22);

// ROWS 2–3 — META INFO
$metaL = [[1,'Opening Cashier:'],[2,$rpt['opening_cashier']??'—'],[4,'Closing Cashier:'],[5,$rpt['closing_cashier']??'—']];
$metaR = [[7,'POS Reading:'],[8,(float)($rpt['pos_reading']??0)],[10,'Status:'],[11,!empty($rpt['is_locked'])?'LOCKED':'Open'],[13,'Exported:'],[14,$fn_export]];
foreach (array_merge($metaL,$metaR) as [$mc,$mv]) $sh->setCellValue([$mc,2],$mv);
$sh->getStyle([8,2])->getNumberFormat()->setFormatCode($MONEY);

$sh->setCellValue([1,3], 'Date:');
$sh->setCellValue([2,3], date('l, F d, Y', strtotime($report_date)));
$sh->setCellValue([7,3], 'Cash on Hand:');
$sh->setCellValue([8,3], (float)$cash_on_hand);
$sh->getStyle([8,3])->getNumberFormat()->setFormatCode($MONEY);

foreach ([1,4,7,10,13] as $_bc) $sh->getStyle(col2L($_bc).'2')->getFont()->setBold(true)->getColor()->setRGB($BROWN);
foreach ([1,7] as $_bc) $sh->getStyle(col2L($_bc).'3')->getFont()->setBold(true)->getColor()->setRGB($BROWN);
$sh->getRowDimension(2)->setRowHeight(13);
$sh->getRowDimension(3)->setRowHeight(13);

// ════════════════════════════════════════════════════════════════════════════
// DUAL-ZONE LAYOUT
// Left zone  (cols 1-3): Cash Breakdown → Summary Report
// Right zone (cols 5-20): Sales Services log
// Both start at row 5.
// ════════════════════════════════════════════════════════════════════════════
const LEFT_C1  = 1;  const LEFT_C2  = 3;   // Cash+Summary
const SVC_C1   = 5;  const SVC_C2   = 21;  // Sales Services (E-U, 17 cols)

$L = 5;  // left-zone cursor
$R = 5;  // right-zone cursor

// ── LEFT A: CASH BREAKDOWN ────────────────────────────────────────────────────
secRow($sh, $L, 'CASH BREAKDOWN', LEFT_C1, LEFT_C2, $S_SEC);
hdrRow($sh, $L, ['QTY','Denomination','Total Collection'], LEFT_C1, $S_HDR);

$first_denom_row = $L;
foreach ($denom_list as $d) {
    $qty = intval($denoms_saved[$d]['quantity'] ?? 0);
    $sh->setCellValue([1,$L], $qty > 0 ? $qty : '');
    $sh->setCellValue([2,$L], (float)$d);
    $sh->getStyle([2,$L])->getNumberFormat()->setFormatCode($MONEY);
    $sh->setCellValue([3,$L], "=A{$L}*B{$L}");
    $sh->getStyle([3,$L])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$L,1,3,$S_DAT);
    $L++;
}
$last_denom_row = $L - 1;
$sh->setCellValue([2,$L], 'TOTAL');
$sh->setCellValue([3,$L], "=SUM(C{$first_denom_row}:C{$last_denom_row})");
$sh->getStyle([3,$L])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$L,1,3,$S_TOT);
$cell_denom_tot = 'C' . $L;
$L++;
$L++; // spacer between Cash Breakdown and Summary

// ── LEFT B: SUMMARY REPORT ────────────────────────────────────────────────────
// Labels + styles written now; formula values deferred until sub-ledger cell
// addresses are known (written below before the Analysis section).
secRow($sh, $L, 'SUMMARY REPORT', LEFT_C1, LEFT_C2, $S_SEC);
$sum_data_start = $L;

$sum_meta = [
    // [label, color, bold]
    ['Gross Sales',          $GREEN,                     true ],
    ['Staff CF',             $RED,                       false],
    ['Sold GC',              '',                         false],
    ['POS Reading',          '',                         false],
    ['Discounts',            $RED,                       false],
    ['Celeb. Discounts 10%', $RED,                       false],
    ['Redeemed GC',          $RED,                       false],
    ['Swiper',               '',                         false],
    ['GCash',                '',                         false],
    ['Maya',                 '',                         false],
    ['QRPH',                 '',                         false],
    ['Unpaids',              $RED,                       false],
    ['Marketing Expense',    $RED,                       false],
    ['Advance Payment',      $RED,                       false],
    ['Maya (DP)',             $RED,                       false],
    ['Product Sold',         $GREEN,                     false],
    ['Expenses',             $RED,                       false],
    ['Net Cash',             $GREEN,                     true ],
    ['COH (Cash on Hand)',   $BLUE,                      true ],
    ['(Short)/Over',         $short_over>=0?$GREEN:$RED, true ],
];
foreach ($sum_meta as [$label, $color, $bold]) {
    $sh->setCellValue([1,$L], $label);
    $isBoldRow = in_array($label, ['Gross Sales','Net Cash','COH (Cash on Hand)','(Short)/Over']);
    rowStyle($sh,$L,1,3,$isBoldRow ? $S_TOT : $S_DAT);
    if ($color) $sh->getStyle([3,$L])->getFont()->getColor()->setRGB($color);
    if ($bold)  $sh->getStyle(rng(1,$L,3,$L))->getFont()->setBold(true);
    $sh->getStyle([1,$L])->getFont()->setSize(8);
    $sh->getStyle([3,$L])->getNumberFormat()->setFormatCode($MONEY);
    $L++;
}
$left_end = $L;

// ── RIGHT: SALES SERVICES LOG (cols 5-20) ────────────────────────────────────
secRow($sh, $R, 'SALES SERVICES — ' . $fn_date, SVC_C1, SVC_C2, $S_SEC);

hdrRow($sh, $R, [
    'Time In','Time Out','Service Slip No.','Client Name','Services','Stylist',
    'Regular Price','Promo Price','Celeb 10%','Disc 20% (PWD/SNR)',
    '30% Commission Fee','20% Commission Fee','15% Commission Fee',
    '50% Disc. for Staff','Net Sales','Mode of Payment','Remarks',
], SVC_C1, $S_HDR);
$sh->getRowDimension($R-1)->setRowHeight(28);

$first_svc_row = $R;  // first data row of the service log (for SUM ranges)
$last_svc_row  = $R - 1;  // updated after each row; starts before first so SUM(x:x-1)=0 if empty

foreach ($service_rows as $svcRow) {
    $ts_in    = strtotime($svcRow['appointment_date']);
    $time_in  = date('h:i A', $ts_in);
    $time_out = date('h:i A', $ts_in + ((int)($svcRow['duration_minutes']??0))*60);

    $tier  = match($svcRow['rate_type']??'regular'){'home'=>20,'hotel'=>15,default=>30};
    $dpwd  = in_array($svcRow['discount_type'],['senior','pwd'])?(float)$svcRow['discount_amount']:0.0;
    $d50   = ($svcRow['discount_type']==='employee')?(float)$svcRow['discount_amount']:0.0;
    $celeb = floatval($svcRow['celebration_discount']??0);
    $pm    = !empty($svcRow['paymongo_method'])?strtoupper($svcRow['paymongo_method']):strtoupper($svcRow['payment_method']??'cash');

    $sh->setCellValue([5,$R],  $time_in);
    $sh->setCellValue([6,$R],  $time_out);
    $sh->setCellValue([7,$R],  $svcRow['slip_number']??'');
    $sh->setCellValue([8,$R],  $svcRow['customer_name']);
    $sh->setCellValue([9,$R],  $svcRow['service_name']);
    $sh->setCellValue([10,$R], $svcRow['therapists']??'');
    $sh->setCellValue([11,$R], (float)$svcRow['regular_price']);
    $sh->setCellValue([12,$R], (float)$svcRow['charged_price']);
    if ($celeb>0) $sh->setCellValue([13,$R], $celeb);
    if ($dpwd>0)  $sh->setCellValue([14,$R], $dpwd);
    // Commissions: stored value from appointment_therapists (per-therapist/service rate)
    // Column O/P/Q is selected by rate_type; amount is what was actually paid.
    $cf = (float)$svcRow['total_commission'];
    if ($tier===30 && $cf>0) $sh->setCellValue([15,$R], $cf);
    if ($tier===20 && $cf>0) $sh->setCellValue([16,$R], $cf);
    if ($tier===15 && $cf>0) $sh->setCellValue([17,$R], $cf);
    if ($d50>0)              $sh->setCellValue([18,$R], "=K{$R}*0.5");
    // Net Sales = Promo Price − commissions (=L−O−P−Q, works for all tiers)
    $sh->setCellValue([19,$R], "=L{$R}-O{$R}-P{$R}-Q{$R}");
    $sh->setCellValue([20,$R], $pm);
    $sh->setCellValue([21,$R], ucfirst($svcRow['appt_status']).' · '.strtoupper($svcRow['rate_type']));

    $sh->getStyle(rng(11,$R,19,$R))->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$R,5,21,$S_DAT);
    $last_svc_row = $R;
    $R++;

    foreach ($addons_by_appt[$svcRow['appt_id']]??[] as $addon) {
        $at = match($addon['rate_type']??'regular'){'home'=>20,'hotel'=>15,default=>30};

        $sh->setCellValue([8,$R],  '↳ add-on');
        $sh->setCellValue([9,$R],  $addon['service_name'].' ['.($addon['person_label']??'').']');
        $sh->setCellValue([10,$R], $addon['therapist_name']??'');
        $sh->setCellValue([12,$R], (float)$addon['charged_price']);
        // cols 13-14 (celeb, dpwd) intentionally blank for add-ons
        $addon_cf = (float)$addon['commission'];
        if ($at===30 && $addon_cf>0) $sh->setCellValue([15,$R], $addon_cf);
        if ($at===20 && $addon_cf>0) $sh->setCellValue([16,$R], $addon_cf);
        if ($at===15 && $addon_cf>0) $sh->setCellValue([17,$R], $addon_cf);
        $sh->setCellValue([19,$R], "=L{$R}-O{$R}-P{$R}-Q{$R}");
        $sh->setCellValue([20,$R], strtoupper($addon['payment_method']??'cash'));
        $sh->setCellValue([21,$R], 'ADD-ON');
        $sh->getStyle(rng(12,$R,19,$R))->getNumberFormat()->setFormatCode($MONEY);
        rowStyle($sh,$R,5,21,$S_DAT);
        $sh->getStyle(rng(5,$R,21,$R))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FAF6EF');
        $last_svc_row = $R;
        $R++;
    }
}
// Sales Services totals row — SUM formulas for all numeric columns
$sh->setCellValue([5,$R], 'TOTALS');
$sh->setCellValue([11,$R], "=SUM(K{$first_svc_row}:K{$last_svc_row})");
$sh->setCellValue([12,$R], "=SUM(L{$first_svc_row}:L{$last_svc_row})");
$sh->setCellValue([13,$R], "=SUM(M{$first_svc_row}:M{$last_svc_row})");
$sh->setCellValue([14,$R], "=SUM(N{$first_svc_row}:N{$last_svc_row})");
$sh->setCellValue([15,$R], "=SUM(O{$first_svc_row}:O{$last_svc_row})");
$sh->setCellValue([16,$R], "=SUM(P{$first_svc_row}:P{$last_svc_row})");
$sh->setCellValue([17,$R], "=SUM(Q{$first_svc_row}:Q{$last_svc_row})");
$sh->setCellValue([18,$R], "=SUM(R{$first_svc_row}:R{$last_svc_row})");
$sh->setCellValue([19,$R], "=SUM(S{$first_svc_row}:S{$last_svc_row})");
$sh->getStyle(rng(11,$R,19,$R))->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$R,5,21,$S_TOT);
$svc_totals_row = $R;
$R++;

// Advance main cursor past both zones
$row = max($left_end,$R) + 2;

// ════════════════════════════════════════════════════════════════════════════
// INFLUENCER / MARKETING  (cols 1–10)
// ════════════════════════════════════════════════════════════════════════════
secRow($sh, $row, 'SALES SERVICES — INFLUENCER / MARKETING', 1, 10, $S_SEC);
hdrRow($sh, $row, [
    'Time Start','Time End','Service Slip No.','Client Name','Services','Stylist',
    'At Cost','Commission Fee','Total MKTG Exp.','Remarks',
], 1, $S_HDR);

$first_inf_row = !empty($influencer_rows) ? $row : null;
$last_inf_row  = null;
foreach ($influencer_rows as $inf) {
    $ts  = strtotime($inf['appointment_date']);
    $ti  = date('h:i A', $ts);
    $to  = date('h:i A', $ts + ((int)($inf['duration_minutes']??0))*60);
    $sh->setCellValue([1,$row], $ti);
    $sh->setCellValue([2,$row], $to);
    $sh->setCellValue([3,$row], $inf['slip_number']??'');
    $sh->setCellValue([4,$row], $inf['customer_name']);
    $sh->setCellValue([5,$row], $inf['service_name']);
    $sh->setCellValue([6,$row], $inf['therapists']??'');
    $sh->setCellValue([7,$row], floatval($inf['at_cost'] ?? 0));
    $sh->setCellValue([8,$row], (float)$inf['commission']);
    // Total MKTG Exp per row = at_cost + CF (live formula)
    $sh->setCellValue([9,$row], "=G{$row}+H{$row}");
    $sh->setCellValue([10,$row], 'INFLUENCER');
    $sh->getStyle(rng(7,$row,9,$row))->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,10,$S_DAT);
    $last_inf_row = $row;
    $row++;
}
$sh->setCellValue([1,$row], 'TOTAL MARKETING EXPENSE');
if ($first_inf_row !== null) {
    $sh->setCellValue([9,$row], "=SUM(I{$first_inf_row}:I{$last_inf_row})");
} else {
    $sh->setCellValue([9,$row], 0.0);
}
$sh->getStyle([9,$row])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$row,1,10,$S_TOT);
$cell_inf_mktg = 'I' . $row;  // total MKTG expense cell (at_cost + CF)
$row++;

// ════════════════════════════════════════════════════════════════════════════
// EXPENSES (cols 1–3)  |  UNPAIDS CORP. (cols 5–8)  — side-by-side
// ════════════════════════════════════════════════════════════════════════════
$row++;
$zone2_start = $row;
$Lx = $row;  // expense row cursor
$Rx = $row;  // unpaids row cursor

// -- Expenses --
secRow($sh, $Lx, 'EXPENSES', 1, 3, $S_SEC);
hdrRow($sh, $Lx, ['Particular','Amount',''], 1, $S_HDR);
$first_exp_row = !empty($expenses) ? $Lx : null;
$last_exp_row  = null;
foreach ($expenses as $e) {
    $sh->setCellValue([1,$Lx], $e['label']);
    $sh->setCellValue([2,$Lx], (float)$e['amount']);
    $sh->getStyle([2,$Lx])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$Lx,1,3,$S_DAT);
    $last_exp_row = $Lx;
    $Lx++;
}
$sh->setCellValue([1,$Lx], 'TOTAL');
if ($first_exp_row !== null) {
    $sh->setCellValue([2,$Lx], "=SUM(B{$first_exp_row}:B{$last_exp_row})");
} else {
    $sh->setCellValue([2,$Lx], 0.0);
}
$sh->getStyle([2,$Lx])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$Lx,1,3,$S_TOT);
$cell_exp_tot = 'B' . $Lx;
$Lx++;

// -- Unpaids Corp --
secRow($sh, $Rx, 'UNPAIDS CORP.', 5, 8, $S_SEC);
hdrRow($sh, $Rx, ['Name','Amount','Total',''], 5, $S_HDR);
$first_unp_row = !empty($unpaids) ? $Rx : null;
$last_unp_row  = null;
foreach ($unpaids as $up) {
    $sh->setCellValue([5,$Rx], $up['client_name']);
    $sh->setCellValue([6,$Rx], (float)$up['amount']);
    $sh->getStyle([6,$Rx])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$Rx,5,8,$S_DAT);
    $last_unp_row = $Rx;
    $Rx++;
}
$sh->setCellValue([5,$Rx], 'TOTAL');
if ($first_unp_row !== null) {
    $sh->setCellValue([7,$Rx], "=SUM(F{$first_unp_row}:F{$last_unp_row})");
} else {
    $sh->setCellValue([7,$Rx], 0.0);
}
$sh->getStyle([7,$Rx])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$Rx,5,8,$S_TOT);
$cell_unp_tot = 'G' . $Rx;
$Rx++;

$row = max($Lx,$Rx) + 1;

// ════════════════════════════════════════════════════════════════════════════
// SERVICE GC SOLD (cols 1–7)  |  PAID GC (cols 9–15)  — side-by-side
// ════════════════════════════════════════════════════════════════════════════
$row++;
$Lg = $row;   // GC-sold row cursor
$Rg = $row;   // GC-paid row cursor

// GC column headers per spec: Series | Name | Voucher | Qty | Amount | Remarks | Total
$gc_hdr = ['Series','Name','Voucher','Qty','Amount','Remarks','Total'];

// -- Service GC (Sold) --
secRow($sh, $Lg, 'SERVICE GC (SOLD)', 1, 7, $S_SEC);
hdrRow($sh, $Lg, $gc_hdr, 1, $S_HDR);
$first_gc_sold_row = !empty($gc_sold) ? $Lg : null;
$last_gc_sold_row  = null;
foreach ($gc_sold as $gc) {
    $sh->setCellValue([1,$Lg], $gc['series']??'');
    $sh->setCellValue([2,$Lg], $gc['client_name']);
    $sh->setCellValue([3,$Lg], $gc['voucher_code']??'');
    $sh->setCellValue([4,$Lg], (int)$gc['qty']);
    $sh->setCellValue([5,$Lg], (float)$gc['amount']);
    $sh->setCellValue([6,$Lg], $gc['remarks']??'');
    $sh->getStyle([5,$Lg])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$Lg,1,7,$S_DAT);
    $last_gc_sold_row = $Lg;
    $Lg++;
}
$sh->setCellValue([1,$Lg], 'TOTAL');
if ($first_gc_sold_row !== null) {
    $sh->setCellValue([7,$Lg], "=SUM(E{$first_gc_sold_row}:E{$last_gc_sold_row})");
} else {
    $sh->setCellValue([7,$Lg], 0.0);
}
$sh->getStyle([7,$Lg])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$Lg,1,7,$S_TOT);
$cell_gc_sold = 'G' . $Lg;
$Lg++;

// -- Paid GC (Redeemed) --
secRow($sh, $Rg, 'PAID GC', 9, 15, $S_SEC);
$col = 9;
foreach ($gc_hdr as $h) { $sh->setCellValue([$col,$Rg],$h); $col++; }
rowStyle($sh,$Rg,9,15,$S_HDR);
$sh->getRowDimension($Rg)->setRowHeight(22);
$Rg++;
$first_gc_red_row = !empty($gc_redeemed) ? $Rg : null;
$last_gc_red_row  = null;
foreach ($gc_redeemed as $gc) {
    $sh->setCellValue([9,$Rg],  $gc['series']??'');
    $sh->setCellValue([10,$Rg], $gc['client_name']);
    $sh->setCellValue([11,$Rg], $gc['voucher_code']??'');
    $sh->setCellValue([12,$Rg], (int)$gc['qty']);
    $sh->setCellValue([13,$Rg], (float)$gc['amount']);
    $sh->setCellValue([14,$Rg], $gc['remarks']??'');
    $sh->getStyle([13,$Rg])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$Rg,9,15,$S_DAT);
    $last_gc_red_row = $Rg;
    $Rg++;
}
$sh->setCellValue([9,$Rg],  'TOTAL');
if ($first_gc_red_row !== null) {
    $sh->setCellValue([15,$Rg], "=SUM(M{$first_gc_red_row}:M{$last_gc_red_row})");
} else {
    $sh->setCellValue([15,$Rg], 0.0);
}
$sh->getStyle([15,$Rg])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$Rg,9,15,$S_TOT);
$cell_gc_red = 'O' . $Rg;
$Rg++;

$row = max($Lg,$Rg) + 1;

// ════════════════════════════════════════════════════════════════════════════
// PRODUCT SOLD  (cols 1–5)
// ════════════════════════════════════════════════════════════════════════════
$row++;
secRow($sh, $row, 'PRODUCT SOLD', 1, 5, $S_SEC);
hdrRow($sh, $row, ['Particular','Qty','Price','Amount','Total'], 1, $S_HDR);

$all_products = array_merge($product_sales, $system_product_sales);
$first_prod_row = !empty($all_products) ? $row : null;
$last_prod_row  = null;
foreach ($all_products as $ps) {
    $sh->setCellValue([1,$row], $ps['particular']);
    $sh->setCellValue([2,$row], (int)$ps['qty']);
    $sh->setCellValue([3,$row], (float)$ps['price']);
    // Amount = Qty × Price (live formula matching workbook V97=T97*U97)
    $sh->setCellValue([4,$row], "=B{$row}*C{$row}");
    $sh->getStyle(rng(3,$row,4,$row))->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,5,$S_DAT);
    $last_prod_row = $row;
    $row++;
}
$sh->setCellValue([1,$row], 'TOTAL');
if ($first_prod_row !== null) {
    $sh->setCellValue([5,$row], "=SUM(D{$first_prod_row}:D{$last_prod_row})");
} else {
    $sh->setCellValue([5,$row], 0.0);
}
$sh->getStyle([5,$row])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$row,1,5,$S_TOT);
$cell_prod = 'E' . $row;
$row++;

// ════════════════════════════════════════════════════════════════════════════
// DEFERRED SUMMARY FORMULAS
// Now that all sub-ledger sections have been written, we know every cell
// address needed for the Summary formula chain.
// ════════════════════════════════════════════════════════════════════════════

// Update H3 (Cash on Hand in meta header) to reference the denom total live
$sh->setCellValue([8,3], "={$cell_denom_tot}");

// Service log totals (column letters for the totals row)
$cell_svc_K = 'K' . $svc_totals_row;  // Regular Price total → POS Reading
$cell_svc_O = 'O' . $svc_totals_row;  // 30% CF total → Staff CF
$cell_svc_P = 'P' . $svc_totals_row;  // 20% CF total
$cell_svc_Q = 'Q' . $svc_totals_row;  // 15% CF total

// Staff CF = stored sum from appointment_therapists (per-therapist/service rates,
// not formula-derived). Static so it matches what was actually paid.

// Row offsets within Summary (0-based from $sum_data_start)
$sr_gross    = $sum_data_start;      // Gross Sales
$sr_staffcf  = $sum_data_start + 1;  // Staff CF
$sr_soldgc   = $sum_data_start + 2;  // Sold GC
$sr_pos      = $sum_data_start + 3;  // POS Reading
$sr_disc     = $sum_data_start + 4;  // Discounts
$sr_celeb    = $sum_data_start + 5;  // Celeb. Discounts 10%
$sr_gcred    = $sum_data_start + 6;  // Redeemed GC
$sr_swiper   = $sum_data_start + 7;  // Swiper
$sr_gcash    = $sum_data_start + 8;  // GCash
$sr_maya     = $sum_data_start + 9;  // Maya
$sr_qrph     = $sum_data_start + 10; // QRPH
$sr_unp      = $sum_data_start + 11; // Unpaids
$sr_mktg     = $sum_data_start + 12; // Marketing Expense
$sr_adv      = $sum_data_start + 13; // Advance Payment
$sr_mayadp   = $sum_data_start + 14; // Maya (DP)
$sr_prod     = $sum_data_start + 15; // Product Sold
$sr_exp      = $sum_data_start + 16; // Expenses
$sr_net      = $sum_data_start + 17; // Net Cash
$sr_coh      = $sum_data_start + 18; // COH
$sr_short    = $sum_data_start + 19; // (Short)/Over

// Build summary formula values — references chain through the Summary col C
$sum_formulas = [
    $sr_gross   => "=C{$sr_pos}+C{$sr_soldgc}-C{$sr_mktg}",
    $sr_staffcf => (float)$staff_cf,
    $sr_soldgc  => "={$cell_gc_sold}",
    // POS Reading references the manually-entered value in the header (H2).
    // Using H2 keeps Excel consistent with PHP's $pos_reading (from daily_reports),
    // so Net Cash and Gross Sales chain from the same anchor as the PHP formula.
    $sr_pos     => "=H2",
    $sr_disc    => "=SUM(N{$first_svc_row}:N{$last_svc_row})",
    $sr_celeb   => "=SUM(M{$first_svc_row}:M{$last_svc_row})",
    $sr_gcred   => "={$cell_gc_red}",
    $sr_swiper  => (float)$card_total,
    $sr_gcash   => (float)$gcash_total,
    $sr_maya    => (float)$maya_total,
    $sr_qrph    => (float)$qrph_total,
    $sr_unp     => "={$cell_unp_tot}",
    $sr_mktg    => "={$cell_inf_mktg}",
    $sr_adv     => (float)$advance_payment_total,
    $sr_mayadp  => (float)$maya_dp_total,
    $sr_prod    => "={$cell_prod}",
    $sr_exp     => "={$cell_exp_tot}",
    // NET CASH mirrors workbook B52: POS − all 13 deductions (Staff CF excluded)
    $sr_net     => "=C{$sr_pos}-C{$sr_gcred}-C{$sr_swiper}-C{$sr_gcash}-C{$sr_maya}-C{$sr_qrph}-C{$sr_mayadp}-C{$sr_unp}-C{$sr_adv}-C{$sr_disc}-C{$sr_celeb}-C{$sr_exp}-C{$sr_mktg}",
    $sr_coh     => "={$cell_denom_tot}",
    $sr_short   => "=C{$sr_coh}-C{$sr_net}",
];
foreach ($sum_formulas as $sr => $val) {
    $sh->setCellValue([3,$sr], $val);
}

// ════════════════════════════════════════════════════════════════════════════
// ── ANALYSIS (INTERNAL) ── clearly separated, at the very bottom ──────────
// ════════════════════════════════════════════════════════════════════════════
$row += 3;
$sh->mergeCells(rng(1,$row,20,$row));
$sh->setCellValue([1,$row], '▬▬▬  ANALYSIS (INTERNAL) — NOT PART OF THE OPERATIONAL REPORT  ▬▬▬');
$sh->getStyle(rng(1,$row,20,$row))->applyFromArray($S_ANAL_BANNER);
$sh->getRowDimension($row)->setRowHeight(20);
$row++;

$sh->mergeCells(rng(1,$row,20,$row));
$sh->setCellValue([1,$row], 'Performance tracking only. Generated: ' . $fn_export);
$sh->getStyle(rng(1,$row,20,$row))->applyFromArray(['font'=>['italic'=>true,'size'=>8,'color'=>['rgb'=>'888888']]]);
$row += 2;

$_wpct = fn($c,$l) => $l!=0 ? round(($c-$l)/abs($l)*100,1).'%' : '—';
$_gross_nz = max(1,$gross_sales);

// KPI Snapshot
$sh->getStyle(rng(1,$row,6,$row))->applyFromArray($S_ANAL_SEC);
secRow($sh, $row, '— KPI SNAPSHOT  (vs last week '.date('M d',strtotime($wow_date)).')', 1, 6, $S_ANAL_SEC);
hdrRow($sh, $row, ['Metric','Today','Last Week','WoW %','',''], 1, $S_HDR);
$kpis=[
    ['Gross Sales',$gross_sales,$wow['gross_sales'],true],
    ['Net Cash',$net_cash,null,true],
    ['Transactions',$transaction_count,$wow['transaction_count'],false],
    ['Avg. Check',$avg_check,$wow['avg_check'],true],
    ['Guests Served',$guests_served,$wow['guests_served'],false],
    ['Cash Over/Short',$short_over,null,true],
];
foreach ($kpis as [$kl,$kv,$kw,$km]) {
    $sh->setCellValue([1,$row],$kl);
    $sh->setCellValue([2,$row],$km?(float)$kv:(int)$kv);
    $sh->setCellValue([3,$row],$kw!==null?($km?(float)$kw:(int)$kw):'—');
    $sh->setCellValue([4,$row],$kw!==null?$_wpct($kv,$kw):'—');
    if ($km) { $sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY); if ($kw!==null) $sh->getStyle([3,$row])->getNumberFormat()->setFormatCode($MONEY); }
    rowStyle($sh,$row,1,6,$S_DAT); $row++;
}
$row++;

// Payment Mix
secRow($sh,$row,'— PAYMENT MIX —',1,4,$S_ANAL_SEC);
hdrRow($sh,$row,['Method','Amount','% of Total',''],1,$S_HDR);
$_pmt=max(1,array_sum($pm_totals));
foreach (['Cash'=>$pm_totals['cash']??0,'GCash'=>$gcash_total,'Maya'=>$maya_total,'QRPH'=>$qrph_total,'Swiper'=>$card_total,'Online'=>$online_total] as $pml=>$pmv) {
    if ($pmv<=0) continue;
    $sh->setCellValue([1,$row],$pml); $sh->setCellValue([2,$row],(float)$pmv);
    $sh->setCellValue([3,$row],round($pmv/$_pmt*100,1).'%');
    $sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,4,$S_DAT); $row++;
}
$row++;

// Sales Mix
secRow($sh,$row,'— SALES MIX —',1,4,$S_ANAL_SEC);
hdrRow($sh,$row,['Category','Amount','% of Gross',''],1,$S_HDR);
foreach (['Services'=>$gross_sales-$addon_gross,'Add-ons'=>$addon_gross,'Products'=>$prod_sold_total] as $ml=>$mv) {
    $sh->setCellValue([1,$row],$ml); $sh->setCellValue([2,$row],(float)$mv);
    $sh->setCellValue([3,$row],round($mv/$_gross_nz*100,1).'%');
    $sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,4,$S_DAT); $row++;
}
$row++;

// Daypart Performance
secRow($sh,$row,'— DAYPART PERFORMANCE —',1,4,$S_ANAL_SEC);
hdrRow($sh,$row,['Period','Time','Transactions','Revenue'],1,$S_HDR);
foreach ($daypart_data as $dpk=>$dpv) {
    $sh->setCellValue([1,$row],$dpk); $sh->setCellValue([2,$row],$dpv['label']);
    $sh->setCellValue([3,$row],$dpv['txn_count']); $sh->setCellValue([4,$row],(float)$dpv['revenue']);
    $sh->getStyle([4,$row])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,4,$S_DAT); $row++;
}
$row++;

// Top 5 Services
secRow($sh,$row,'— TOP 5 SERVICES —',1,3,$S_ANAL_SEC);
hdrRow($sh,$row,['Service','Revenue','Transactions'],1,$S_HDR);
foreach ($top_services as $ts) {
    $sh->setCellValue([1,$row],$ts['service_name']); $sh->setCellValue([2,$row],(float)$ts['revenue']);
    $sh->setCellValue([3,$row],(int)$ts['txn_count']);
    $sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,3,$S_DAT); $row++;
}
$row++;

// Therapist Productivity
secRow($sh,$row,'— THERAPIST PRODUCTIVITY —',1,5,$S_ANAL_SEC);
hdrRow($sh,$row,['Therapist','Services','Revenue','Commission','Net to Salon'],1,$S_HDR);
foreach ($therapist_stats as $thr) {
    $net_sal=(float)$thr['revenue']-(float)$thr['commission'];
    $sh->setCellValue([1,$row],$thr['full_name']); $sh->setCellValue([2,$row],(int)$thr['svc_count']);
    $sh->setCellValue([3,$row],(float)$thr['revenue']); $sh->setCellValue([4,$row],(float)$thr['commission']);
    $sh->setCellValue([5,$row],$net_sal);
    $sh->getStyle(rng(3,$row,5,$row))->getNumberFormat()->setFormatCode($MONEY);
    rowStyle($sh,$row,1,5,$S_DAT); $row++;
}
$sh->setCellValue([1,$row],'Totals');
$sh->setCellValue([2,$row],array_sum(array_column($therapist_stats,'svc_count')));
$sh->setCellValue([3,$row],array_sum(array_column($therapist_stats,'revenue')));
$sh->setCellValue([4,$row],array_sum(array_column($therapist_stats,'commission')));
$sh->setCellValue([5,$row],array_sum(array_column($therapist_stats,'revenue'))-array_sum(array_column($therapist_stats,'commission')));
$sh->getStyle(rng(3,$row,5,$row))->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$row,1,5,$S_TOT);
$row++;
$row++;

// Discount Impact
secRow($sh,$row,'— DISCOUNT IMPACT —',1,3,$S_ANAL_SEC);
rowStyle($sh,$row,1,3,$S_DAT);
$sh->setCellValue([1,$row],'Total Discounts Given');   $sh->setCellValue([2,$row],$total_discounts);
$sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY); $row++;
$sh->setCellValue([1,$row],'% of Gross Sales');        $sh->setCellValue([2,$row],$discount_pct_of_gross.'%');
rowStyle($sh,$row,1,3,$S_DAT); $row++;
$sh->setCellValue([1,$row],'Influencer Comp Cost');    $sh->setCellValue([2,$row],$influencer_comp_total);
$sh->getStyle([2,$row])->getNumberFormat()->setFormatCode($MONEY);
rowStyle($sh,$row,1,3,$S_DAT);

// ── Auto-size columns ─────────────────────────────────────────────────────────
foreach (range('A','U') as $col) $sh->getColumnDimension($col)->setAutoSize(false); // keep manual widths

// ── Freeze header rows ────────────────────────────────────────────────────────
$sh->freezePane('E5');

// ── Print setup ───────────────────────────────────────────────────────────────
$sh->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sh->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);

// ── Stream to browser ─────────────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Recovery_Spa_Daily_Report_'.$report_date.'.xlsx"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
(new Xlsx($spreadsheet))->save('php://output');
exit();
