<?php
require_once(__DIR__ . '/../../includes/db.php');
require_once(__DIR__ . '/../../includes/auth.php');
check_auth();

header('Content-Type: application/json; charset=utf-8');

function user_customer_bulk_delete_response($success, $message, $deleted_count = 0, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
        'deleted_count' => (int) $deleted_count,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    user_customer_bulk_delete_response(false, 'Invalid request method.', 0, 405);
}

$session_token = (string) ($_SESSION['list_bulk_delete_token'] ?? '');
$submitted_token = (string) ($_POST['csrf_token'] ?? '');
if ($session_token === '' || !hash_equals($session_token, $submitted_token)) {
    user_customer_bulk_delete_response(false, 'Your session token expired. Refresh the page and try again.', 0, 419);
}

$submitted_ids = $_POST['ids'] ?? [];
$ids = is_array($submitted_ids)
    ? array_values(array_unique(array_filter(array_map('intval', $submitted_ids), static fn ($id) => $id > 0)))
    : [];
if (!$ids) {
    user_customer_bulk_delete_response(false, 'Select at least one customer to delete.', 0, 422);
}
if (count($ids) > 500) {
    user_customer_bulk_delete_response(false, 'You can delete a maximum of 500 customers at one time.', 0, 422);
}

$submitted_id_list = implode(',', $ids);
$username = (string) ($_SESSION['user_name'] ?? '');
$escaped_username = mysqli_real_escape_string($db, $username);
$owned_result = mysqli_query(
    $db,
    "SELECT id, email, customer_type FROM customer_master
     WHERE id IN ($submitted_id_list) AND created_by = '$escaped_username'"
);
$owned_ids = [];
$login_emails = [];
if ($owned_result) {
    while ($customer = mysqli_fetch_assoc($owned_result)) {
        $owned_ids[] = (int) $customer['id'];
        if (($customer['customer_type'] ?? '') === 'User' && !empty($customer['email'])) {
            $login_emails[] = (string) $customer['email'];
        }
    }
}
if (!$owned_ids) {
    user_customer_bulk_delete_response(false, 'None of the selected customers belong to your account.', 0, 403);
}

$id_list = implode(',', $owned_ids);
mysqli_begin_transaction($db);
try {
    if (!mysqli_query($db, "DELETE FROM customer_master WHERE id IN ($id_list) AND created_by = '$escaped_username'")) {
        throw new RuntimeException('Unable to delete customers.');
    }
    $deleted_count = mysqli_affected_rows($db);

    if ($login_emails) {
        $escaped_emails = array_map(
            static fn ($email) => "'" . mysqli_real_escape_string($db, $email) . "'",
            array_values(array_unique($login_emails))
        );
        if (!mysqli_query($db, 'DELETE FROM users WHERE email IN (' . implode(',', $escaped_emails) . ')')) {
            throw new RuntimeException('Unable to delete linked login users.');
        }
    }

    $log_user = mysqli_real_escape_string($db, $username ?: 'System');
    $action = mysqli_real_escape_string($db, "Bulk deleted $deleted_count own customer(s)");
    mysqli_query(
        $db,
        "INSERT INTO activity_log (username, action, module, activity_date)
         VALUES ('$log_user', '$action', 'Customer Master', NOW())"
    );
    mysqli_commit($db);
} catch (Throwable $error) {
    mysqli_rollback($db);
    user_customer_bulk_delete_response(false, 'The selected customers could not be deleted.', 0, 500);
}

user_customer_bulk_delete_response(
    true,
    $deleted_count === 1 ? '1 customer deleted.' : "$deleted_count customers deleted.",
    $deleted_count
);
