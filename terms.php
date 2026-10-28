<?php
/**
 * terms.php — Terms and Conditions (public). Linked in the footer; customers
 * agree to it when they register (user/auth.php) and at checkout
 * (user/checkout.php). Update $terms_updated whenever the text changes.
 */
require_once __DIR__ . '/config.php';

$terms_updated = 'October 1, 2026';
$page_title  = 'Terms and Conditions — Recovery Iloilo';
$page_description = 'Booking, down payment, rescheduling, discount, voucher and privacy terms of Recovery Iloilo, Molo, Iloilo City.';
$active_page = '';
$page_extra_css = '';
require_once __DIR__ . '/header.php';

$sections = [
    'bookings' => ['Bookings', [
        'You can book online, by phone or Viber (+63 985 335 9998), or walk in. Walk-ins are served when a therapist and a room are free.',
        'An online booking is confirmed once the down payment is paid and the spa assigns your therapist.',
        'Please arrive <b>15 minutes</b> before your appointment.',
    ]],
    'payment' => ['Down payment and payment', [
        'Online service bookings need a <b>50% down payment</b>, paid through PayMongo (GCash, Maya, card or QR Ph). <b>The down payment is non-refundable</b>, including if you cancel or do not show up.',
        'The down payment is deducted from your bill; the balance is paid at the spa (cash, GCash, Maya, QR Ph or card).',
        'Prices are in Philippine pesos and may change without notice; the price when you book is the price you pay. Promos apply only on their stated days and times (for example, Daytime promos from 10:00 AM to 4:00 PM).',
    ]],
    'resched' => ['Late arrival, rescheduling and cancellation', [
        'If you arrive late, your session may be shortened so it ends at the booked time, to keep the next guest on schedule. The full price applies.',
        'To reschedule, contact us at least <b>2 hours</b> before your appointment. Your down payment moves to the new date.',
        'A cancellation, or a no-show, forfeits the down payment.',
    ]],
    'discounts' => ['Discounts and vouchers', [
        'Senior Citizen and PWD 20% discounts apply only to the cardholder, with a valid ID shown at the spa. Companions pay the regular price.',
        'Senior, PWD and Staff discounts do not apply to Daytime promos, nail services or lash services.',
        'A voucher is for its own services (Nails, Lash, Head spa or Body massage voucher). A Gift Card / GC is for all services. Vouchers are valid for as long as you hold them, are not convertible to cash, and unused value is not refunded.',
        'One discount per service; discounts cannot be combined.',
    ]],
    'packages' => ['Multi-session packages', [
        'Packages (for example, 2 sessions) are paid in full and are for the same person. Each session is booked separately.',
        'Remaining sessions are not refundable or transferable.',
    ]],
    'health' => ['Health and safety', [
        'Tell us before your session if you are pregnant, or have allergies, a skin condition, high blood pressure or any other medical condition. We may adjust or decline a treatment for your safety.',
        'Aesthetic treatments (laser, HIFU, injectables and drips) are done or supervised by our doctor or nurse; please follow their aftercare advice.',
        'Guests under 18 need a parent or guardian with them.',
    ]],
    'home' => ['Home service', [
        'Home service is available within Iloilo City only, at its own rates. Please provide a safe, private space; the therapist may leave if it is unsafe.',
    ]],
    'products' => ['Products', [
        'Products ordered online are picked up at the spa. Opened products cannot be returned. If an item is damaged, contact us with your receipt.',
    ]],
    'conduct' => ['Conduct and belongings', [
        'Our services are strictly therapeutic. Inappropriate behavior ends the session with no refund.',
        'Please keep your valuables with you; the spa is not responsible for lost items.',
    ]],
    'privacy' => ['Privacy', [
        'We collect your name, contact details and booking history to manage your bookings, payments, receipts and notifications, in line with the Data Privacy Act of 2012 (Republic Act No. 10173). We do not sell your data.',
        'Online payments are handled by PayMongo; we do not store card details.',
        'To see, correct or delete your data, email recoveryiloiloph@gmail.com.',
    ]],
    'contact' => ['Changes and contact', [
        'We may update these terms; the date at the top shows the latest version.',
        'Questions: recoveryiloiloph@gmail.com · +63 985 335 9998 · G&amp;R Building, M.H. Del Pilar Street, Molo, Iloilo City.',
    ]],
];
?>
<style>
.tc-wrap { max-width:1120px; margin:0 auto; padding:2.5rem 1rem 3.5rem; display:grid; grid-template-columns:240px minmax(0,1fr); gap:2.25rem; }
@media (max-width:820px) { .tc-wrap { grid-template-columns:1fr; } .tc-toc { position:static !important; } }
.tc-toc { position:sticky; top:1rem; align-self:start; text-align:left; align-items:flex-start; border-left:2px solid #E6D8C3; padding-left:0.9rem; display:flex; flex-direction:column; gap:0.4rem; font-size:0.86rem; }
.tc-toc a { color:#6B5440; text-decoration:none; } .tc-toc a:hover { color:#8A6A33; }
.tc-doc { background:#FFFDF9; border:1px solid #E6D8C3; border-radius:14px; padding:clamp(1.25rem, 4vw, 2.5rem); min-width:0; color:#2B1D12; line-height:1.65; }
.tc-eyebrow { font-size:0.74rem; letter-spacing:0.16em; text-transform:uppercase; color:#8A6A33; font-weight:800; }
.tc-doc h1 { font-family:'Cormorant Garamond', Georgia, serif; font-size:clamp(2rem, 5vw, 2.8rem); line-height:1.1; margin:0.4rem 0 0.25rem; color:#2B1D12; }
.tc-meta { color:#6B5440; font-size:0.85rem; }
.tc-doc h2 { font-family:'Cormorant Garamond', Georgia, serif; font-size:1.45rem; margin:1.9rem 0 0.4rem; color:#2B1D12; scroll-margin-top:1rem; }
.tc-doc h2 span { color:#8A6A33; font-family:inherit; font-size:0.8rem; font-weight:800; margin-right:0.5rem; letter-spacing:0.08em; }
.tc-doc ul { padding-left:1.25rem; margin:0.4rem 0; } .tc-doc li { max-width:68ch; margin:0.25rem 0; }
</style>
<div class="tc-wrap">
    <nav class="tc-toc" aria-label="Sections">
        <?php $i = 0; foreach ($sections as $id => [$title]): $i++; ?>
        <a href="terms.php#<?php echo $id; ?>"><?php echo $i . '. ' . htmlspecialchars($title); ?></a>
        <?php endforeach; ?>
    </nav>
    <article class="tc-doc">
        <div class="tc-eyebrow">Recovery Iloilo · Molo, Iloilo City</div>
        <h1>Terms and Conditions</h1>
        <div class="tc-meta">Last updated <?php echo $terms_updated; ?> · By booking, buying or creating an account you agree to these terms.</div>
        <?php $i = 0; foreach ($sections as $id => [$title, $items]): $i++; ?>
        <h2 id="<?php echo $id; ?>"><span><?php echo sprintf('%02d', $i); ?></span><?php echo htmlspecialchars($title); ?></h2>
        <ul><?php foreach ($items as $li): ?><li><?php echo $li; ?></li><?php endforeach; ?></ul>
        <?php endforeach; ?>
    </article>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
