<?php
require_once(__DIR__ . '/../../includes/db.php');
require_once(__DIR__ . '/../../includes/auth.php');
check_auth('admin');

header('Content-Type: application/json; charset=utf-8');

function customer_bulk_delete_response($success, $message, $deleted_count = 0, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
        'deleted_count' => (int) $deleted_count,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    customer_bulk_delete_response(false, 'Invalid request method.', 0, 405);
}

$session_token = (string) ($_SESSION['list_bulk_delete_token'] ?? '');
$submitted_token = (string) ($_POST['csrf_token'] ?? '');
if ($session_token === '' || !hash_equals($session_token, $submitted_token)) {
    customer_bulk_delete_response(false, 'Your session token expired. Refresh the page and try again.', 0, 419);
}

$submitted_ids = $_POST['ids'] ?? [];
if (!is_array($submitted_ids)) {
    customer_bulk_delete_response(false, 'No customers were selected.', 0, 422);
}

$ids = array_values(array_unique(array_filter(
    array_map('intval', $submitted_ids),
    static fn ($id) => $id > 0
)));
if (!$ids) {
    customer_bulk_delete_response(false, 'Select at least one customer to delete.', 0, 422);
}
if (count($ids) > 500) {
    customer_bulk_delete_response(false, 'You can delete a maximum of 500 customers at one time.', 0, 422);
}

$id_list = implode(',', $ids);
$login_emails = [];
$customer_result = mysqli_query(
    $db,
    "SELECT email FROM customer_master
     WHERE id IN ($id_list) AND customer_type = 'User' AND email IS NOT NULL AND email != ''"
);
if ($customer_result) {
    while ($customer = mysqli_fetch_assoc($customer_result)) {
        $login_emails[] = (string) $customer['email'];
    }
}

mysqli_begin_transaction($db);
try {
    if (!mysqli_query($db, "DELETE FROM customer_master WHERE id IN ($id_list)")) {
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

    $username = mysqli_real_escape_string($db, (string) ($_SESSION['user_name'] ?? 'System'));
    $action = mysqli_real_escape_string($db, "Bulk deleted $deleted_count customer(s)");
    mysqli_query(
        $db,
        "INSERT INTO activity_log (username, action, module, activity_date)
         VALUES ('$username', '$action', 'Customer Master', NOW())"
    );
    mysqli_commit($db);
} catch (Throwable $error) {
    mysqli_rollback($db);
    customer_bulk_delete_response(false, 'The selected customers could not be deleted.', 0, 500);
}

customer_bulk_delete_response(
    true,
    $deleted_count === 1 ? '1 customer deleted.' : "$deleted_count customers deleted.",
    $deleted_count
);
