<?php
require_once(__DIR__ . '/includes/db.php');
require_once(__DIR__ . '/includes/auth.php');
require_once(__DIR__ . '/includes/customer_duplicate_helper.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$name = trim((string) ($_GET['name'] ?? ''));
$mobile = trim((string) ($_GET['mobile'] ?? ''));
$email = trim((string) ($_GET['email'] ?? ''));
$table = trim((string) ($_GET['table'] ?? 'customer_master'));
$exclude_id = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? (int) $_GET['exclude_id'] : null;

if (!customer_duplicate_table_config($table)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid customer source.']);
    exit;
}

if (strlen($name) > 255 || strlen($mobile) > 100 || strlen($email) > 255 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid customer details.']);
    exit;
}

$duplicates = search_duplicate_customers($db, $table, $name, $mobile, $email, $exclude_id);
$safe_duplicates = array_map('customer_duplicate_safe_result', $duplicates);

echo json_encode([
    'status' => 'success',
    'match_type' => customer_duplicate_match_type($duplicates),
    'duplicates' => $safe_duplicates,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
