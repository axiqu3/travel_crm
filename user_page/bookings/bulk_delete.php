<?php
require_once(__DIR__ . '/../../includes/db.php');
require_once(__DIR__ . '/../../includes/auth.php');
check_auth();

header('Content-Type: application/json; charset=utf-8');

function user_booking_bulk_delete_response($success, $message, $deleted_count = 0, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
        'deleted_count' => (int) $deleted_count,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    user_booking_bulk_delete_response(false, 'Invalid request method.', 0, 405);
}

$session_token = (string) ($_SESSION['list_bulk_delete_token'] ?? '');
$submitted_token = (string) ($_POST['csrf_token'] ?? '');
if ($session_token === '' || !hash_equals($session_token, $submitted_token)) {
    user_booking_bulk_delete_response(false, 'Your session token expired. Refresh the page and try again.', 0, 419);
}

$submitted_ids = $_POST['ids'] ?? [];
$ids = is_array($submitted_ids)
    ? array_values(array_unique(array_filter(array_map('intval', $submitted_ids), static fn ($id) => $id > 0)))
    : [];
if (!$ids) {
    user_booking_bulk_delete_response(false, 'Select at least one booking to delete.', 0, 422);
}
if (count($ids) > 500) {
    user_booking_bulk_delete_response(false, 'You can delete a maximum of 500 bookings at one time.', 0, 422);
}

$submitted_id_list = implode(',', $ids);
$username = (string) ($_SESSION['user_name'] ?? '');
$escaped_username = mysqli_real_escape_string($db, $username);
$owned_result = mysqli_query(
    $db,
    "SELECT id FROM bookings WHERE id IN ($submitted_id_list) AND created_by = '$escaped_username'"
);
$owned_ids = [];
if ($owned_result) {
    while ($booking = mysqli_fetch_assoc($owned_result)) {
        $owned_ids[] = (int) $booking['id'];
    }
}
if (!$owned_ids) {
    user_booking_bulk_delete_response(false, 'None of the selected bookings belong to your account.', 0, 403);
}

$id_list = implode(',', $owned_ids);
$attachment_paths = [];
$attachment_result = mysqli_query($db, "SELECT file_path FROM booking_attachments WHERE booking_id IN ($id_list)");
if ($attachment_result) {
    while ($attachment = mysqli_fetch_assoc($attachment_result)) {
        if (!empty($attachment['file_path'])) $attachment_paths[] = (string) $attachment['file_path'];
    }
}

mysqli_begin_transaction($db);
try {
    if (!mysqli_query($db, "DELETE FROM booking_attachments WHERE booking_id IN ($id_list)")) {
        throw new RuntimeException('Unable to delete booking attachments.');
    }
    if (!mysqli_query($db, "DELETE FROM bookings WHERE id IN ($id_list) AND created_by = '$escaped_username'")) {
        throw new RuntimeException('Unable to delete bookings.');
    }
    $deleted_count = mysqli_affected_rows($db);

    $log_user = mysqli_real_escape_string($db, $username ?: 'System');
    $action = mysqli_real_escape_string($db, "Bulk deleted $deleted_count own booking(s)");
    mysqli_query(
        $db,
        "INSERT INTO activity_log (username, action, module, activity_date)
         VALUES ('$log_user', '$action', 'Booking', NOW())"
    );
    mysqli_commit($db);
} catch (Throwable $error) {
    mysqli_rollback($db);
    user_booking_bulk_delete_response(false, 'The selected bookings could not be deleted.', 0, 500);
}

$attachment_root = realpath(__DIR__ . '/../../uploads/attachments');
if ($attachment_root !== false) {
    $attachment_prefix = rtrim($attachment_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach ($attachment_paths as $relative_path) {
        $absolute_path = realpath(__DIR__ . '/../../' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_path), DIRECTORY_SEPARATOR));
        if ($absolute_path !== false && str_starts_with($absolute_path, $attachment_prefix) && is_file($absolute_path)) {
            @unlink($absolute_path);
        }
    }
}

user_booking_bulk_delete_response(
    true,
    $deleted_count === 1 ? '1 booking deleted.' : "$deleted_count bookings deleted.",
    $deleted_count
);
