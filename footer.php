<?php
/**
 * footer.php — shared footer for the whole site: index.php/services.php/
 * products.php AND the logged-in user/*.php account pages.
 * Category quick-links are pulled from real data (get_customer_categories_with_counts)
 * instead of the old hardcoded labels that didn't match actual category names.
 * All internal links use BASE_URL (absolute) rather than relative paths — this
 * file used to assume it was only ever included from the site root, so a plain
 * href="index.php" resolved to the wrong page (e.g. user/index.php) when the
 * several user/*.php pages that duplicated this markup inline are switched to
 * require_once this shared file instead.
 */
if (!isset($conn)) { require_once __DIR__ . '/config.php'; }
$_footer_svc_cats = get_customer_categories_with_counts($conn, 'service');
?>
<footer class="spa-footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <div class="ft-logo">RECOVERY</div>
            <p>Your sanctuary for wellness and restoration in the heart of Iloilo City.</p>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="<?php echo BASE_URL; ?>index.php">Home</a></li>
                <li><a href="<?php echo BASE_URL; ?>services.php">Services</a></li>
                <li><a href="<?php echo BASE_URL; ?>products.php">Products</a></li>
                <li><a href="<?php echo BASE_URL; ?>index.php#about">About Us</a></li>
                <li><a href="<?php echo BASE_URL; ?>index.php#contact">Contact</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Popular Categories</h4>
            <ul>
                <?php foreach (array_slice($_footer_svc_cats, 0, 5) as $fc): ?>
                <li><a href="<?php echo BASE_URL; ?>services.php?category=<?php echo (int)$fc['id']; ?>"><?php echo htmlspecialchars($fc['name']); ?></a></li>
                <?php endforeach; ?>
                <?php if (empty($_footer_svc_cats)): ?><li><a href="<?php echo BASE_URL; ?>services.php">View All Services</a></li><?php endif; ?>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Contact</h4>
            <ul>
                <li><a href="<?php echo BASE_URL; ?>index.php#contact">G&amp;R Bldg., M.H. Del Pilar, Molo, Iloilo City</a></li>
                <li><a href="mailto:recoveryiloiloph@gmail.com">recoveryiloiloph@gmail.com</a></li>
                <li><a href="tel:+639853359998">+639853359998</a></li>
                <li><a href="<?php echo BASE_URL; ?>index.php#contact">Mon – Sun: 10AM – 10PM</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">&copy; <?php echo date('Y'); ?> Recovery Spa Iloilo. All rights reserved.</div>
</footer>
