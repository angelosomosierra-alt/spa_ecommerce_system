<?php
// _favicon.php — the browser-tab icon (the logo's leaf on brown), for every
// page's <head>: public, customer account and admin. Files in img/icons/ and
// /favicon.ico, made from img/logo.png.
$_fav = defined('BASE_URL') ? BASE_URL : '/';
?>
    <link rel="icon" href="<?php echo $_fav; ?>favicon.ico" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $_fav; ?>img/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $_fav; ?>img/icons/icon-192.png">
    <link rel="apple-touch-icon" href="<?php echo $_fav; ?>img/icons/apple-touch-icon.png">
    <meta name="theme-color" content="#3B2A1A">
