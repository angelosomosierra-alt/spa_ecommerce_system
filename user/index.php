<?php
/**
 * user/index.php — login/registration landing URL.
 * Renders the exact same homepage as the site root (index.php) in place,
 * so the URL stays at user/index.php after login instead of navigating
 * away to index.php. There is still only one homepage design to maintain —
 * this just requires the root file directly rather than duplicating it
 * (header.php's <base> tag makes its relative asset links still resolve
 * correctly even though this page is one folder deeper than index.php).
 */
require_once __DIR__ . '/../index.php';
