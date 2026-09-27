<?php
require_once '../config.php';
require_once __DIR__ . '/_resource_grid.php';

redirect_if_not_admin();

header('Content-Type: text/html; charset=utf-8');
echo render_resource_grid_html($conn);
