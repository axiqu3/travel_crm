<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enquiry_workflow.php';
check_auth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enquiry_json(false, 'Invalid request method.', [], 405);
}

if (!enquiry_validate_csrf($_POST['csrf_token'] ?? '')) {
    enquiry_json(false, 'Your session token expired. Refresh the page and try again.', [], 419);
}

$submitted_ids = $_POST['ids'] ?? [];
if (!is_array($submitted_ids)) {
    enquiry_json(false, 'No enquiries were selected.', [], 422);
}

$ids = array_values(array_unique(array_filter(
    array_map('intval', $submitted_ids),
    static fn ($id) => $id > 0
)));

if (!$ids) {
    enquiry_json(false, 'Select at least one enquiry to delete.', [], 422);
}
if (count($ids) > 500) {
    enquiry_json(false, 'You can delete a maximum of 500 enquiries at one time.', [], 422);
}

$id_list = implode(',', $ids);
$username = mysqli_real_escape_string($db, enquiry_current_user_name());
$manual_scope = enquiry_manual_source_sql('');
$owner_scope = "(created_by = '$username' OR assigned_user = '$username')";
$owned_result = mysqli_query(
    $db,
    "SELECT id FROM enquiries
     WHERE id IN ($id_list) AND $manual_scope AND $owner_scope"
);

$owned_ids = [];
if ($owned_result) {
    while ($enquiry = mysqli_fetch_assoc($owned_result)) {
        $owned_ids[] = (int) $enquiry['id'];
    }
}

if (!$owned_ids) {
    enquiry_json(false, 'None of the selected enquiries belong to your account.', [], 403);
}

$owned_id_list = implode(',', $owned_ids);
$delete_sql = "DELETE FROM enquiries
    WHERE id IN ($owned_id_list) AND $manual_scope AND $owner_scope";

mysqli_begin_transaction($db);
try {
    if (!mysqli_query($db, $delete_sql)) {
        throw new RuntimeException('The selected enquiries could not be deleted.');
    }

    $deleted_count = mysqli_affected_rows($db);
    if ($deleted_count > 0 && !enquiry_log($db, "Bulk deleted $deleted_count own enquiry(s)")) {
        throw new RuntimeException('The deletion activity could not be saved.');
    }
    mysqli_commit($db);
} catch (Throwable $error) {
    mysqli_rollback($db);
    enquiry_json(false, 'The selected enquiries could not be deleted.', [], 500);
}

enquiry_json(
    true,
    $deleted_count === 1 ? '1 enquiry deleted.' : "$deleted_count enquiries deleted.",
    ['deleted_count' => $deleted_count]
);
