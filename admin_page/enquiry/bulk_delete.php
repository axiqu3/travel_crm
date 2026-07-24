<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/enquiry_workflow.php';
check_auth('admin');

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
$manual_scope = enquiry_manual_source_sql('');
$delete_sql = "DELETE FROM enquiries WHERE id IN ($id_list) AND $manual_scope";

mysqli_begin_transaction($db);
if (!mysqli_query($db, $delete_sql)) {
    mysqli_rollback($db);
    enquiry_json(false, 'The selected enquiries could not be deleted.', [], 500);
}

$deleted_count = mysqli_affected_rows($db);
if ($deleted_count > 0) {
    enquiry_log($db, "Bulk deleted $deleted_count manual enquiries");
}
mysqli_commit($db);

enquiry_json(
    true,
    $deleted_count === 1 ? '1 enquiry deleted.' : "$deleted_count enquiries deleted.",
    ['deleted_count' => $deleted_count]
);
