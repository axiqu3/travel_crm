<?php

require_once __DIR__ . '/enquiry_workflow.php';
require_once __DIR__ . '/enquiry_action_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') enquiry_action_fail('POST is required.', 405);
$enquiry_id = (int) ($_POST['enquiry_id'] ?? $_POST['id'] ?? 0);
$new_status = trim((string) ($_POST['status'] ?? ''));
if (!enquiry_validate_csrf()) enquiry_action_fail('Your session token expired. Refresh and try again.', 403, $enquiry_id);
if (!in_array($new_status, ['Cancelled', 'Closed'], true)) enquiry_action_fail('Only Cancelled or Closed may be selected here.', 422, $enquiry_id);

mysqli_begin_transaction($db);
try {
    $enquiry = enquiry_fetch($db, $enquiry_id, true, true);
    if (!$enquiry) throw new RuntimeException('Enquiry not found or access denied.');
    if (!enquiry_can_close($enquiry['status'])) throw new RuntimeException('This enquiry can no longer be cancelled or closed.');
    $stmt = mysqli_prepare($db, 'UPDATE enquiries SET status = ?, next_follow_up_at = NULL, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'si', $new_status, $enquiry_id);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('The status could not be updated.');
    mysqli_stmt_close($stmt);
    if (!enquiry_log($db, "Enquiry #$enquiry_id status changed to $new_status")) throw new RuntimeException('The activity entry could not be saved.');
    mysqli_commit($db);
    enquiry_action_success("Enquiry marked $new_status.", 'status', $enquiry_id, ['status' => $new_status]);
} catch (Throwable $error) {
    mysqli_rollback($db);
    enquiry_action_fail($error->getMessage(), 422, $enquiry_id);
}

