<?php
/**
 * user/index.php — login/registration landing URL.
 * The actual homepage lives only at the site root (index.php) — this file
 * just redirects there so there is one homepage design to maintain, not two
 * that can drift out of sync (which is what happened before: this file used
 * to be a full standalone page with its own older design).
 */
require_once '../config.php';
header('Location: ' . BASE_URL . 'index.php');
exit();
