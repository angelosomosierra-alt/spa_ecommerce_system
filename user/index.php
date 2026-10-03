<?php
/**
 * user/index.php — the homepage, reached while logged in.
 * Renders the exact same homepage content as the site root (index.php),
 * via the shared homepage_data.php/homepage_content.php partials, but
 * wrapped in the account-area header (user/header.php: avatar, cart,
 * notifications, appointments) instead of the public header. header.php's
 * <base> tag makes the shared content's relative asset links still resolve
 * correctly even though this page is one folder deeper than index.php.
 */
require_once '../config.php';
require_once '../homepage_data.php';

$page_title = 'Recovery Iloilo — Home';
require_once 'header.php';
require_once '../homepage_content.php';
require_once '../footer.php';
