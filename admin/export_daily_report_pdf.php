<?php
/**
 * export_daily_report_pdf.php — Daily report PDF export.
 * Order: operational report first (Cover → Cash/Summary → Services → Influencer →
 *        Products → Expenses → GC → Unpaids), then Analysis (Internal), then Sign-off.
 */
require_once '../config.php';
redirect_if_not_admin();

$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || !strtotime($report_date)) {
    $report_date = date('Y-m-d');
}
require_once '../vendor/autoload.php';
require_once __DIR__ . '/_daily_report_data.php';

use Mpdf\Mpdf;

$fn_date     = date('F d, Y', strtotime($report_date));
$fn_day      = date('l', strtotime($report_date));
$fn_export   = date('F d, Y h:i A');
$fn_filename = 'Recovery_Spa_Daily_Report_' . $report_date . '.pdf';
$locked_lbl  = !empty($rpt['is_locked']) ? 'LOCKED' : 'Open';

function pdf_money(float $v, bool $parens=false): string {
    if ($parens && $v < 0) return '(&#x20B1;'.number_format(abs($v),2).')';
    return ($v < 0 ? '(&#x20B1;' : '&#x20B1;').number_format(abs($v),2).($v<0&&!$parens?')':'');
}
function pdf_pct(float $c, float $l): string {
    if ($l==0) return '—';
    $p=round(($c-$l)/abs($l)*100,1);
    $a=$p>=0?'&#x25B2;':'&#x25BC;';
    $clr=$p>=0?'#198754':'#dc3545';
    return "<span style='color:{$clr};font-weight:700;'>{$a} ".abs($p)."%</span>";
}

ob_start();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:sans-serif;font-size:8pt;color:#2d1a10;margin:0;padding:0;}
h2{font-size:11pt;color:#58281C;margin:0 0 3px 0;}
h3{font-size:9pt;color:#58281C;margin:10px 0 3px 0;border-bottom:1.5px solid #c8a46b;padding-bottom:2px;}
.cover{border:2px solid #58281C;border-radius:4px;padding:10px 14px;margin-bottom:12px;background:#fdf8f3;}
.cover-title{font-size:14pt;font-weight:700;color:#58281C;margin-bottom:2px;}
.cover-sub{font-size:8.5pt;color:#7a4f2b;}
.sec-banner{background:#58281C;color:#fff;font-weight:700;font-size:9pt;padding:4px 8px;margin:10px 0 3px 0;}
.analysis-banner{background:#1a1a1a;color:#fff;font-weight:700;font-size:11pt;text-align:center;padding:7px;margin:16px 0 4px 0;}
.analysis-sub{font-size:7.5pt;color:#888;font-style:italic;margin:0 0 10px 0;text-align:center;}
table{width:100%;border-collapse:collapse;font-size:7.8pt;margin-bottom:8px;}
th{background:#58281C;color:#fff;font-weight:700;padding:4px 5px;text-align:left;font-size:7.5pt;}
th.r,td.r{text-align:right;}
th.c,td.c{text-align:center;}
td{padding:3px 5px;border-bottom:0.5pt solid #e8ddd4;}
tr.zebra td{background:#fdf8f3;}
tr.total-row td{background:#f5ede5;font-weight:700;border-top:1pt solid #58281C;border-bottom:1pt solid #58281C;color:#58281C;}
tr.addon-row td{background:#faf6ef;font-size:7pt;}
.page-break{page-break-before:always;}
.signoff{margin-top:24px;padding:10px 0;border-top:1.5pt solid #58281C;}
</style>
</head><body>

<!-- ══ COVER ════════════════════════════════════════════════════════════════ -->
<div class="cover">
<div class="cover-title">Recovery Spa &amp; Massage</div>
<div class="cover-sub">Daily Sales Report &mdash; <?php echo htmlspecialchars($fn_day.', '.$fn_date); ?></div>
<table style="margin-top:8px;border:none;">
<tr>
    <td style="width:25%;font-weight:700;color:#58281C;">Opening Cashier:</td>
    <td style="width:25%;"><?php echo htmlspecialchars($rpt['opening_cashier']??'Not set'); ?></td>
    <td style="width:25%;font-weight:700;color:#58281C;">Closing Cashier:</td>
    <td style="width:25%;"><?php echo htmlspecialchars($rpt['closing_cashier']??'Not set'); ?></td>
</tr><tr>
    <td style="font-weight:700;color:#58281C;">Status:</td>
    <td><?php echo $locked_lbl; ?></td>
    <td style="font-weight:700;color:#58281C;">Exported:</td>
    <td><?php echo $fn_export; ?></td>
</tr><tr>
    <td style="font-weight:700;color:#58281C;">POS Reading:</td>
    <td style="font-family:monospace;">&#x20B1;<?php echo number_format(floatval($rpt['pos_reading']??0),2); ?></td>
    <td style="font-weight:700;color:#58281C;">Cash on Hand (COH):</td>
    <td style="font-family:monospace;">&#x20B1;<?php echo number_format($cash_on_hand,2); ?></td>
</tr>
<?php if (!empty($rpt['notes'])): ?>
<tr>
    <td style="font-weight:700;color:#58281C;">Notes:</td>
    <td colspan="3"><?php echo htmlspecialchars($rpt['notes']); ?></td>
</tr>
<?php endif; ?>
</table>
</div>

<!-- ══ CASH BREAKDOWN + SUMMARY REPORT (side-by-side) ══════════════════════ -->
<table style="width:100%;border:none;margin-bottom:6px;">
<tr>
<td style="width:38%;vertical-align:top;padding-right:8px;border:none;">

<div class="sec-banner">CASH BREAKDOWN</div>
<table>
    <tr><th style="width:18%;">QTY</th><th>Denomination</th><th class="r">Total Collection</th></tr>
    <?php $i=0; foreach ($denom_list as $d):
        $qty=intval($denoms_saved[$d]['quantity']??0);
        $tot=floatval($denoms_saved[$d]['total']??0);
        $rc=($i++%2===0)?'':'class="zebra"';
    ?>
    <tr <?php echo $rc; ?>>
        <td class="c"><?php echo $qty>0?$qty:''; ?></td>
        <td>&#x20B1;<?php echo number_format($d,$d<1?2:0); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo $tot>0?pdf_money($tot):'—'; ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="2">TOTAL</td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($denom_total); ?></td>
    </tr>
</table>

</td>
<td style="width:60%;vertical-align:top;padding-left:8px;border:none;">

<div class="sec-banner">SUMMARY REPORT</div>
<table>
    <tr><th style="width:65%;">Item</th><th class="r">Amount</th></tr>
    <?php
    $sum_rows=[
        ['Gross Sales',       $gross_sales,     '#198754',true],
        ['Staff CF',          $staff_cf,        '#c9280c',false],
        ['Sold GC',           $gc_sold_total,   '',false],
        ['POS Reading',       $pos_reading,     '',false],
        ['Discounts',         $total_discounts, '#c9280c',false],
        ['Redeemed GC',       $gc_redeem_total, '#c9280c',false],
        ['Swiper',            $card_total,      '',false],
        ['GCash',             $gcash_total,     '',false],
        ['Maya',              $maya_total,      '',false],
        ['QRPH',              $qrph_total,      '',false],
        ['Unpaids',           $unpaids_total,   '#c9280c',false],
        ['Expenses',          $expenses_total,  '#c9280c',false],
        ['Marketing Expense', $mktg_expense,    '#c9280c',false],
        ['Product Sold',      $prod_sold_total, '#198754',false],
        ['Net Cash',          $net_cash,        '#198754',true],
        ['COH (Cash on Hand)',$cash_on_hand,    '#0070f3',true],
        ['(Short)/Over',      $short_over,      $short_over>=0?'#198754':'#c9280c',true],
    ];
    foreach ($sum_rows as $i=>[$sl,$sv,$sc,$bold]):
        $bs=$bold?'font-weight:700;':'';
        $rc=in_array($sl,['Net Cash','(Short)/Over'])?'class="total-row"':($i%2===0?'':'class="zebra"');
    ?>
    <tr <?php echo $rc; ?>>
        <td style="<?php echo $bs; ?>"><?php echo $sl; ?></td>
        <td class="r" style="font-family:monospace;<?php echo $bs; ?>color:<?php echo $sc?:'inherit'; ?>;"><?php echo pdf_money((float)$sv,true); ?></td>
    </tr>
    <?php endforeach; ?>
</table>

</td>
</tr>
</table>

<!-- ══ SALES SERVICES ═══════════════════════════════════════════════════════ -->
<div class="sec-banner">SALES SERVICES &mdash; <?php echo $fn_date; ?></div>
<p style="font-size:6pt;color:#888;margin:0 0 2px 0;">Time In | Time Out | Slip No. | Client | Services | Stylist | Regular | Promo | Disc 20% (PWD/SNR) | 30% CF | 20% CF | 15% CF | 50% Staff Disc | Net Sales | Payment | Remarks</p>
<table style="font-size:6pt;">
    <tr>
        <th style="width:5%;">In</th>
        <th style="width:5%;">Out</th>
        <th style="width:6%;">Slip</th>
        <th style="width:9%;">Client</th>
        <th style="width:13%;">Services</th>
        <th style="width:8%;">Stylist</th>
        <th class="r" style="width:6%;">Regular</th>
        <th class="r" style="width:6%;">Promo</th>
        <th class="r" style="width:6%;">Disc 20%</th>
        <th class="r" style="width:5%;">30% CF</th>
        <th class="r" style="width:5%;">20% CF</th>
        <th class="r" style="width:5%;">15% CF</th>
        <th class="r" style="width:5%;">50% Disc</th>
        <th class="r" style="width:7%;">Net Sales</th>
        <th style="width:5%;">Pymt</th>
        <th style="width:5%;">Rmks</th>
    </tr>
<?php if (empty($service_rows)): ?>
    <tr><td colspan="16" class="c" style="color:#888;padding:6px;">No service transactions for this date.</td></tr>
<?php else:
    $prev_order=null;
    $_pt=array_fill_keys(['reg','promo','dpwd','c30','c20','c15','d50','net'],0.0);
    foreach ($service_rows as $i=>$row):
        $is_same=($prev_order===$row['order_id']);
        $ts=strtotime($row['appointment_date']);
        $ti=date('h:i A',$ts); $to=date('h:i A',$ts+((int)($row['duration_minutes']??0))*60);
        $tier=match($row['rate_type']??'regular'){'home'=>20,'hotel'=>15,default=>30};
        $c30=($tier===30)?(float)$row['total_commission']:0;
        $c20=($tier===20)?(float)$row['total_commission']:0;
        $c15=($tier===15)?(float)$row['total_commission']:0;
        $dpwd=in_array($row['discount_type'],['senior','pwd'])?(float)$row['discount_amount']:0;
        $d50=($row['discount_type']==='employee')?(float)$row['discount_amount']:0;
        $net=(float)$row['charged_price']-(float)$row['total_commission'];
        $pm=!empty($row['paymongo_method'])?$row['paymongo_method']:($row['payment_method']??'cash');
        $rc=($i%2===0)?'':'class="zebra"';
        $prev_order=$row['order_id'];
        $_pt['reg']+=(float)$row['regular_price'];$_pt['promo']+=(float)$row['charged_price'];
        $_pt['dpwd']+=$dpwd;$_pt['c30']+=$c30;$_pt['c20']+=$c20;$_pt['c15']+=$c15;$_pt['d50']+=$d50;$_pt['net']+=$net;
?>
    <tr <?php echo $rc; ?>>
        <td><?php echo $ti; ?></td>
        <td><?php echo $to; ?></td>
        <td style="font-family:monospace;color:#b07d2b;"><?php echo $is_same?'':htmlspecialchars($row['slip_number']??'—'); ?></td>
        <td style="font-weight:<?php echo $is_same?400:600; ?>;"><?php echo $is_same?'↳':htmlspecialchars($row['customer_name']); ?></td>
        <td><?php echo htmlspecialchars($row['service_name']); ?></td>
        <td style="color:#888;"><?php echo htmlspecialchars($row['therapists']??'—'); ?></td>
        <td class="r" style="font-family:monospace;color:#888;"><?php echo pdf_money($row['regular_price']); ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;"><?php echo pdf_money($row['charged_price']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $dpwd>0?pdf_money($dpwd):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $c30>0?pdf_money($c30):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $c20>0?pdf_money($c20):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $c15>0?pdf_money($c15):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $d50>0?pdf_money($d50):'—'; ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;color:#198754;"><?php echo pdf_money($net); ?></td>
        <td style="font-size:6pt;"><?php echo strtoupper($pm); ?></td>
        <td style="font-size:6pt;color:#888;"><?php echo strtoupper($row['rate_type']); ?></td>
    </tr>
<?php if (!empty($addons_by_appt[$row['appt_id']])): foreach ($addons_by_appt[$row['appt_id']] as $addon):
    $at=match($addon['rate_type']??'regular'){'home'=>20,'hotel'=>15,default=>30};
    $ac30=($at===30)?(float)$addon['commission']:0;$ac20=($at===20)?(float)$addon['commission']:0;$ac15=($at===15)?(float)$addon['commission']:0;
    $an=(float)$addon['charged_price']-(float)$addon['commission'];
    $_pt['promo']+=(float)$addon['charged_price'];$_pt['c30']+=$ac30;$_pt['c20']+=$ac20;$_pt['c15']+=$ac15;$_pt['net']+=$an;
?>
    <tr class="addon-row">
        <td colspan="2" style="text-align:center;color:#c8a46b;">+ add-on</td>
        <td></td><td colspan="2"><?php echo htmlspecialchars($addon['service_name']); ?> [<?php echo htmlspecialchars($addon['person_label']??''); ?>]</td>
        <td style="color:#888;"><?php echo htmlspecialchars($addon['therapist_name']??'—'); ?></td>
        <td></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($addon['charged_price']); ?></td>
        <td></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $ac30>0?pdf_money($ac30):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $ac20>0?pdf_money($ac20):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $ac15>0?pdf_money($ac15):'—'; ?></td>
        <td></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($an); ?></td>
        <td style="font-size:6pt;"><?php echo strtoupper($addon['payment_method']??'cash'); ?></td>
        <td style="font-size:6pt;color:#888;">ADD-ON</td>
    </tr>
<?php endforeach; endif; ?>
<?php endforeach; endif; ?>
    <tr class="total-row">
        <td colspan="6">TOTALS</td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($_pt['reg']); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($_pt['promo']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $_pt['dpwd']>0?pdf_money($_pt['dpwd']):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $_pt['c30']>0?pdf_money($_pt['c30']):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $_pt['c20']>0?pdf_money($_pt['c20']):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $_pt['c15']>0?pdf_money($_pt['c15']):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo $_pt['d50']>0?pdf_money($_pt['d50']):'—'; ?></td>
        <td class="r" style="font-family:monospace;color:#198754;"><?php echo pdf_money($_pt['net']); ?></td>
        <td colspan="2"></td>
    </tr>
</table>

<!-- ══ INFLUENCER / MARKETING ═══════════════════════════════════════════════ -->
<div class="sec-banner">SALES SERVICES — INFLUENCER / MARKETING</div>
<?php if (empty($influencer_rows)): ?>
<p style="color:#888;">No influencer/marketing transactions.</p>
<?php else: ?>
<table>
    <tr>
        <th style="width:7%;">Time Start</th><th style="width:7%;">Time End</th>
        <th style="width:8%;">Slip No.</th><th style="width:12%;">Client</th>
        <th style="width:15%;">Services</th><th style="width:11%;">Stylist</th>
        <th class="r" style="width:10%;">At Cost</th>
        <th class="r" style="width:10%;">Comm. Fee</th>
        <th class="r" style="width:10%;">Total MKTG Exp.</th>
        <th style="width:10%;">Remarks</th>
    </tr>
    <?php foreach ($influencer_rows as $i=>$row):
        $ts=strtotime($row['appointment_date']);
        $ti=date('h:i A',$ts);$to=date('h:i A',$ts+((int)($row['duration_minutes']??0))*60);
        $rc=($i%2===0)?'':'class="zebra"';
    ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo $ti; ?></td><td><?php echo $to; ?></td>
        <td style="font-family:monospace;font-size:7pt;"><?php echo htmlspecialchars($row['slip_number']??'—'); ?></td>
        <td style="font-weight:600;"><?php echo htmlspecialchars($row['customer_name']); ?></td>
        <td><?php echo htmlspecialchars($row['service_name']); ?></td>
        <td style="color:#888;"><?php echo htmlspecialchars($row['therapists']??'—'); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($row['charged_price']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($row['commission']); ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;color:#c9280c;"><?php echo pdf_money($row['commission']); ?></td>
        <td style="font-size:7pt;">INFLUENCER</td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="8">Total Marketing Expense</td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($mktg_expense); ?></td>
        <td></td>
    </tr>
</table>
<?php endif; ?>

<!-- ══ PRODUCTS SOLD ════════════════════════════════════════════════════════ -->
<div class="sec-banner">PRODUCT SOLD</div>
<?php if (empty($product_sales) && empty($system_product_sales)): ?>
<p style="color:#888;">No product sales for this date.</p>
<?php else: ?>
<table>
    <tr><th>Particular</th><th class="c" style="width:8%;">Qty</th><th class="r" style="width:13%;">Price</th><th class="r" style="width:13%;">Amount</th></tr>
    <?php
    $all_prod = array_merge($product_sales, $system_product_sales);
    foreach ($all_prod as $i=>$ps):
        $rc=($i%2===0)?'':'class="zebra"';
    ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo htmlspecialchars($ps['particular']); ?></td>
        <td class="c"><?php echo intval($ps['qty']); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($ps['price']); ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;"><?php echo pdf_money($ps['amount']); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="3">TOTAL</td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($prod_sold_total); ?></td>
    </tr>
</table>
<?php endif; ?>

<!-- ══ EXPENSES + UNPAIDS CORP. (side-by-side) ══════════════════════════════ -->
<table style="width:100%;border:none;margin-bottom:6px;">
<tr>
<td style="width:48%;vertical-align:top;padding-right:8px;border:none;">
<div class="sec-banner">EXPENSES</div>
<?php if (empty($expenses)): ?>
<p style="color:#888;">No expenses logged.</p>
<?php else: ?>
<table>
    <tr><th>Particular</th><th class="r">Amount</th></tr>
    <?php foreach ($expenses as $i=>$e): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo htmlspecialchars($e['label']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($e['amount']); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td>TOTAL</td><td class="r" style="font-family:monospace;"><?php echo pdf_money($expenses_total); ?></td></tr>
</table>
<?php endif; ?>
</td>
<td style="width:48%;vertical-align:top;padding-left:8px;border:none;">
<div class="sec-banner">UNPAIDS CORP.</div>
<?php if (empty($unpaids)): ?>
<p style="color:#888;">No unpaids logged.</p>
<?php else: ?>
<table>
    <tr><th>Name</th><th class="r">Amount</th></tr>
    <?php foreach ($unpaids as $i=>$up): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo htmlspecialchars($up['client_name']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($up['amount']); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td>TOTAL</td><td class="r" style="font-family:monospace;"><?php echo pdf_money($unpaids_total); ?></td></tr>
</table>
<?php endif; ?>
</td>
</tr>
</table>

<!-- ══ SERVICE GC (SOLD) + PAID GC (side-by-side) ══════════════════════════ -->
<table style="width:100%;border:none;margin-bottom:6px;">
<tr>
<td style="width:48%;vertical-align:top;padding-right:8px;border:none;">
<div class="sec-banner">SERVICE GC (SOLD)</div>
<?php if (empty($gc_sold)): ?>
<p style="color:#888;">No GC sold.</p>
<?php else: ?>
<table>
    <tr><th>Series</th><th>Name</th><th>Voucher</th><th class="c">Qty</th><th class="r">Amount</th><th>Remarks</th></tr>
    <?php foreach ($gc_sold as $i=>$gc): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td style="font-family:monospace;"><?php echo htmlspecialchars($gc['series']??''); ?></td>
        <td><?php echo htmlspecialchars($gc['client_name']); ?></td>
        <td style="font-size:7pt;"><?php echo htmlspecialchars($gc['voucher_code']??''); ?></td>
        <td class="c"><?php echo intval($gc['qty']); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($gc['amount']); ?></td>
        <td style="font-size:7pt;"><?php echo htmlspecialchars($gc['remarks']??''); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td colspan="5">TOTAL</td><td class="r" style="font-family:monospace;"><?php echo pdf_money($gc_sold_total); ?></td></tr>
</table>
<?php endif; ?>
</td>
<td style="width:48%;vertical-align:top;padding-left:8px;border:none;">
<div class="sec-banner">PAID GC</div>
<?php if (empty($gc_redeemed)): ?>
<p style="color:#888;">No GC redeemed.</p>
<?php else: ?>
<table>
    <tr><th>Series</th><th>Name</th><th>Voucher</th><th class="c">Qty</th><th class="r">Amount</th><th>Remarks</th></tr>
    <?php foreach ($gc_redeemed as $i=>$gc): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td style="font-family:monospace;"><?php echo htmlspecialchars($gc['series']??''); ?></td>
        <td><?php echo htmlspecialchars($gc['client_name']); ?></td>
        <td style="font-size:7pt;"><?php echo htmlspecialchars($gc['voucher_code']??''); ?></td>
        <td class="c"><?php echo intval($gc['qty']); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($gc['amount']); ?></td>
        <td style="font-size:7pt;"><?php echo htmlspecialchars($gc['remarks']??''); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td colspan="5">TOTAL</td><td class="r" style="font-family:monospace;"><?php echo pdf_money($gc_redeem_total); ?></td></tr>
</table>
<?php endif; ?>
</td>
</tr>
</table>

<!-- ══════════════════════════ PAGE BREAK ══════════════════════════════════ -->
<div class="page-break"></div>

<!-- ══ ANALYSIS (INTERNAL) ════════════════════════════════════════════════ -->
<div class="analysis-banner">ANALYSIS (INTERNAL) — NOT PART OF THE OPERATIONAL REPORT</div>
<p class="analysis-sub">Performance tracking only. Generated: <?php echo $fn_export; ?></p>

<!-- KPI Snapshot -->
<h3>KPI Snapshot &mdash; vs. <?php echo date('M d, Y',strtotime($wow_date)); ?> (last week)</h3>
<table style="width:70%;margin-bottom:10px;">
    <tr><th style="width:35%;">Metric</th><th class="r">Today</th><th class="r">Last Week</th><th class="r">WoW %</th></tr>
    <?php
    $kpis=[
        ['Gross Sales',$gross_sales,$wow['gross_sales'],true],
        ['Net Cash',$net_cash,null,true],
        ['Transactions',$transaction_count,$wow['transaction_count'],false],
        ['Avg. Check',$avg_check,$wow['avg_check'],true],
        ['Guests Served',$guests_served,$wow['guests_served'],false],
        ['Cash Over/Short',$short_over,null,true],
    ];
    foreach ($kpis as $i=>[$kl,$kv,$kw,$km]):
        $kv_str=$km?pdf_money((float)$kv):number_format((int)$kv);
        $kw_str=$kw!==null?($km?pdf_money((float)$kw):number_format((int)$kw)):'—';
        $wow_str=$kw!==null?pdf_pct((float)$kv,(float)$kw):'—';
        $rc=($i%2===0)?'':'class="zebra"';
    ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo $kl; ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;"><?php echo $kv_str; ?></td>
        <td class="r" style="font-family:monospace;color:#888;"><?php echo $kw_str; ?></td>
        <td class="r"><?php echo $wow_str; ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<!-- Sales Mix + Payment Mix -->
<table style="width:100%;border:none;margin-bottom:4px;">
<tr>
<td style="width:49%;vertical-align:top;padding-right:6px;border:none;">
<h3 style="margin-top:0;">Sales Mix</h3>
<table>
    <tr><th>Category</th><th class="r">Amount</th><th class="r" style="width:16%;">%</th></tr>
    <?php $gnz=max(1,$gross_sales); $i=0;
    foreach (['Services'=>$gross_sales-$addon_gross,'Add-ons'=>$addon_gross,'Products'=>$prod_sold_total] as $ml=>$mv):
        $rc=$i++%2===0?'':'class="zebra"';
    ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo $ml; ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($mv); ?></td>
        <td class="r"><?php echo round($mv/$gnz*100,1); ?>%</td>
    </tr>
    <?php endforeach; ?>
</table>
</td>
<td style="width:49%;vertical-align:top;padding-left:6px;border:none;">
<h3 style="margin-top:0;">Payment Mix</h3>
<table>
    <tr><th>Method</th><th class="r">Amount</th><th class="r" style="width:16%;">%</th></tr>
    <?php
    $pm_disp=['Cash'=>$pm_totals['cash']??0,'GCash'=>$gcash_total,'Maya'=>$maya_total,'QRPH'=>$qrph_total,'Swiper'=>$card_total,'Online'=>$online_total];
    $pm_all=max(1,array_sum($pm_disp));
    $j=0;
    foreach ($pm_disp as $pml=>$pmv): if ($pmv<=0) continue; ?>
    <tr <?php echo $j++%2===0?'':'class="zebra"'; ?>>
        <td><?php echo $pml; ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($pmv); ?></td>
        <td class="r"><?php echo round($pmv/$pm_all*100,1); ?>%</td>
    </tr>
    <?php endforeach; ?>
</table>
</td>
</tr>
</table>

<!-- Daypart + Top Services -->
<table style="width:100%;border:none;margin-bottom:4px;">
<tr>
<td style="width:49%;vertical-align:top;padding-right:6px;border:none;">
<h3 style="margin-top:0;">Daypart Performance</h3>
<table>
    <tr><th>Period</th><th>Time</th><th class="c">Txns</th><th class="r">Revenue</th></tr>
    <?php $i=0; foreach ($daypart_data as $dpk=>$dpv): $rc=($i++%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td style="font-weight:600;"><?php echo $dpk; ?></td>
        <td style="color:#888;font-size:7pt;"><?php echo $dpv['label']; ?></td>
        <td class="c"><?php echo $dpv['txn_count']; ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;"><?php echo pdf_money($dpv['revenue']); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</td>
<td style="width:49%;vertical-align:top;padding-left:6px;border:none;">
<h3 style="margin-top:0;">Top Services (by Revenue)</h3>
<?php if (empty($top_services)): ?>
<p style="color:#888;">No data.</p>
<?php else: ?>
<table>
    <tr><th>Service</th><th class="r">Revenue</th><th class="r">Txns</th></tr>
    <?php foreach ($top_services as $i=>$ts): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td><?php echo htmlspecialchars($ts['service_name']); ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;"><?php echo pdf_money($ts['revenue']); ?></td>
        <td class="r"><?php echo $ts['txn_count']; ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
</td>
</tr>
</table>

<!-- Therapist Productivity -->
<h3>Therapist Productivity</h3>
<?php if (empty($therapist_stats)): ?>
<p style="color:#888;">No therapist data.</p>
<?php else: ?>
<table>
    <tr><th>Therapist</th><th class="c">Services</th><th class="r">Revenue</th><th class="r">Commission</th><th class="r">Net to Salon</th></tr>
    <?php foreach ($therapist_stats as $i=>$thr): $rc=($i%2===0)?'':'class="zebra"'; ?>
    <tr <?php echo $rc; ?>>
        <td style="font-weight:600;"><?php echo htmlspecialchars($thr['full_name']); ?></td>
        <td class="c"><?php echo intval($thr['svc_count']); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money($thr['revenue']); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($thr['commission']); ?></td>
        <td class="r" style="font-family:monospace;font-weight:700;color:#198754;"><?php echo pdf_money($thr['revenue']-$thr['commission']); ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td>Totals</td>
        <td class="c"><?php echo array_sum(array_column($therapist_stats,'svc_count')); ?></td>
        <td class="r" style="font-family:monospace;"><?php echo pdf_money(array_sum(array_column($therapist_stats,'revenue'))); ?></td>
        <td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money(array_sum(array_column($therapist_stats,'commission'))); ?></td>
        <td class="r" style="font-family:monospace;"><?php
            $tr=array_sum(array_column($therapist_stats,'revenue'));
            $tc=array_sum(array_column($therapist_stats,'commission'));
            echo pdf_money($tr-$tc); ?></td>
    </tr>
</table>
<?php endif; ?>

<!-- Discount Impact -->
<h3>Discount &amp; Voucher Impact</h3>
<table style="width:50%;">
    <tr><th style="width:70%;">Item</th><th class="r">Value</th></tr>
    <tr><td>Total Discounts Given</td><td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($total_discounts); ?></td></tr>
    <tr class="zebra"><td>% of Gross Sales</td><td class="r" style="font-weight:700;"><?php echo $discount_pct_of_gross; ?>%</td></tr>
    <tr><td>Influencer Comp Cost</td><td class="r" style="font-family:monospace;color:#c9280c;"><?php echo pdf_money($influencer_comp_total); ?></td></tr>
</table>

<!-- ══ SIGN-OFF ══════════════════════════════════════════════════════════════ -->
<div class="signoff">
<p style="font-size:8pt;font-weight:700;color:#58281C;margin-bottom:12px;">Report Sign-Off &mdash; <?php echo $fn_date; ?></p>
<table style="width:100%;border:none;">
<tr>
    <td style="width:33%;text-align:center;border:none;padding:0 8px;">
        <div style="border-top:1px solid #333;margin:36px auto 4px;width:85%;"></div>
        <div style="font-size:7.5pt;font-weight:700;color:#333;">Prepared by</div>
        <div style="font-size:7pt;color:#888;">Name &amp; Signature</div>
    </td>
    <td style="width:33%;text-align:center;border:none;padding:0 8px;">
        <div style="border-top:1px solid #333;margin:36px auto 4px;width:85%;"></div>
        <div style="font-size:7.5pt;font-weight:700;color:#333;">Verified by</div>
        <div style="font-size:7pt;color:#888;">Name &amp; Signature</div>
    </td>
    <td style="width:33%;text-align:center;border:none;padding:0 8px;">
        <div style="border-top:1px solid #333;margin:36px auto 4px;width:85%;"></div>
        <div style="font-size:7.5pt;font-weight:700;color:#333;">Approved by</div>
        <div style="font-size:7pt;color:#888;">Name &amp; Signature</div>
    </td>
</tr>
<tr>
    <td colspan="3" style="border:none;text-align:center;padding-top:8px;">
        <div style="border-top:1px solid #ccc;padding-top:4px;font-size:7pt;color:#aaa;">
            Date: __________________________
        </div>
    </td>
</tr>
</table>
</div>

</body></html>
<?php
$html = ob_get_clean();

// ── Render with mPDF ──────────────────────────────────────────────────────────
$mpdf = new Mpdf([
    'mode'              => 'utf-8',
    'format'            => 'A4',
    'margin_top'        => 14,
    'margin_bottom'     => 14,
    'margin_left'       => 12,
    'margin_right'      => 12,
    'default_font'      => 'dejavusans',
    'default_font_size' => 8,
]);
$mpdf->SetTitle("Recovery Spa Daily Report — {$fn_date}");
$mpdf->SetAuthor('Recovery Spa & Massage');
$mpdf->SetCreator('SPA System');
$mpdf->SetHTMLHeader("
    <table width='100%' style='font-size:7pt;color:#888;border-bottom:0.5pt solid #ccc;padding-bottom:3pt;'>
        <tr>
            <td style='text-align:left;'>Recovery Spa &amp; Massage — Daily Report</td>
            <td style='text-align:right;'>{$fn_date}</td>
        </tr>
    </table>
");
$mpdf->SetHTMLFooter("
    <table width='100%' style='font-size:6.5pt;color:#aaa;border-top:0.5pt solid #e0d0c0;padding-top:2pt;'>
        <tr>
            <td style='text-align:left;'>Generated: {$fn_export}</td>
            <td style='text-align:center;'>CONFIDENTIAL — Internal Use Only</td>
            <td style='text-align:right;'>Page {PAGENO} of {nbpg}</td>
        </tr>
    </table>
");
$mpdf->WriteHTML($html);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$fn_filename.'"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
$mpdf->Output($fn_filename, 'D');
exit();
