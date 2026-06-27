<?php
require_once '../config.php';
redirect_if_not_user();

$user_id  = $_SESSION['user_id'];
$order_id = intval($_GET['order_id'] ?? $_SESSION['paymongo_order_id'] ?? 0);

if ($order_id) {
    // Mark order as cancelled — cart and stock were never touched
    $stmt = $conn->prepare("
        UPDATE orders SET payment_status = 'cancelled'
        WHERE id = ? AND user_id = ? AND payment_status = 'pending_payment'
    ");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $stmt->close();

    // Clean up session — cart is untouched so user can retry
    unset(
        $_SESSION['paymongo_order_id'],
        $_SESSION['paymongo_checkout_type'],
        $_SESSION['paymongo_checkout_items']
    );
}

$page_title = 'Payment Cancelled';
require_once 'header.php';
?>

<div class="container" style="max-width:560px; margin:5rem auto; text-align:center;">
    <div style="background:#fff; border-radius:16px; padding:3rem 2rem; box-shadow:0 8px 32px rgba(0,0,0,0.08);">
        <div style="font-size:4rem; margin-bottom:1rem;">❌</div>
        <h2 style="color:#dc3545; margin-bottom:0.5rem;">Payment Cancelled</h2>
        <p style="color:#555; margin-bottom:0.5rem;">Your payment was not completed.</p>
        <p style="color:#888; font-size:0.9rem; margin-bottom:2rem;">
            No charge was made. Your cart is still intact — you can try again or choose Onsite Payment.
        </p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
            <a href="cart.php" class="btn btn-primary" style="padding:0.75rem 1.5rem;">
                🛒 Back to Cart
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding:0.75rem 1.5rem;">
                🏠 Back to Home
            </a>
        </div>
    </div>
</div>

<footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand"><div class="ft-logo">RECOVERY ILOILO</div><p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p></div>
        <div class="footer-col"><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="index.php#services">Services</a></li><li><a href="index.php#products">Products</a></li><li><a href="index.php#about">About Us</a></li><li><a href="index.php#contact">Contact</a></li></ul></div>
        <div class="footer-col"><h4>Services</h4><ul><li><a href="index.php#services">Massage Therapy</a></li><li><a href="index.php#services">Nail Care</a></li><li><a href="index.php#services">Lash Services</a></li><li><a href="index.php#services">Facial Treatments</a></li><li><a href="index.php#services">Body Scrubs</a></li></ul></div>
        <div class="footer-col"><h4>Contact</h4><ul><li><a href="index.php#contact">Iloilo City, Philippines</a></li><li><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></li><li><a href="tel:+639853359998">+639853359998</a></li><li><a href="index.php#contact">Mon – Sun: 10AM – 10PM</a></li></ul></div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>
</body>
</html>