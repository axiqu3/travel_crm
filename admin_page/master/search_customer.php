<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
require_once(__DIR__ . "/../../includes/customer_search.php");
check_auth('admin');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(search_active_customer_master(
    $db,
    $_GET['q'] ?? '',
    10,
    $_GET['customer_type'] ?? ''
));
exit;
