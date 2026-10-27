<?php
// sitemap.php — the public pages of recoveryiloilo.com for search engines
// (listed in robots.txt; submit it in Google Search Console).
require_once __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');

$urls = [[SITE_URL . '/', '1.0'], [SITE_URL . '/services.php', '0.9'], [SITE_URL . '/products.php', '0.7']];
// Each service / product category that has something to show.
foreach ([['services.php', 'services', 's.deleted_at IS NULL'], ['products.php', 'products', 's.deleted_at IS NULL']] as [$page, $table, $live]) {
    $q = $conn->query("SELECT DISTINCT s.category_id FROM $table s WHERE $live AND s.category_id IS NOT NULL ORDER BY s.category_id");
    if ($q) foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) $urls[] = [SITE_URL . '/' . $page . '?category=' . (int)$r['category_id'], '0.6'];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $prio]) {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><changefreq>weekly</changefreq><priority>' . $prio . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
