<?php

require_once __DIR__ . '/enquiry_workflow.php';
require_once __DIR__ . '/enquiry_action_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') enquiry_action_fail('POST is required.', 405);
$enquiry_id = (int) ($_POST['enquiry_id'] ?? $_POST['id'] ?? 0);
if (!enquiry_validate_csrf()) enquiry_action_fail('Your session token expired. Refresh and try again.', 403, $enquiry_id);

$contacted_at = enquiry_parse_date($_POST['contacted_at'] ?? '', true);
$contact_method = trim((string) ($_POST['contact_method'] ?? ''));
$discussion_note = trim((string) ($_POST['discussion_note'] ?? ''));
$quoted_raw = trim((string) ($_POST['quoted_amount'] ?? ''));
$quoted_amount = $quoted_raw === '' ? null : filter_var($quoted_raw, FILTER_VALIDATE_FLOAT);
$next_follow_up_at = enquiry_parse_date($_POST['next_follow_up_at'] ?? '', true);
$result = trim((string) ($_POST['result'] ?? ''));
$closure = trim((string) ($_POST['closure_status'] ?? ''));

$errors = [];
if ($contacted_at === false || $contacted_at === null) $errors[] = 'Contacted Date and Time is required.';
if (!in_array($contact_method, enquiry_contact_methods(), true)) $errors[] = 'Select a valid Contact Method.';
if ($discussion_note === '') $errors[] = 'Discussion Note is required.';
if ($quoted_raw !== '' && ($quoted_amount === false || $quoted_amount < 0)) $errors[] = 'Quoted Amount must be a valid non-negative number.';
if ($next_follow_up_at === false) $errors[] = 'Next Follow-up Date and Time is invalid.';
if (!in_array($result, enquiry_follow_up_results(), true)) $errors[] = 'Select a valid Follow-up Result.';
if ($closure !== '' && (!in_array($closure, ['Cancelled', 'Closed'], true) || $result !== 'Not Interested')) $errors[] = 'Closure is only available for a Not Interested result.';
if (mb_strlen($discussion_note) > 10000) $errors[] = 'Discussion Note is too long.';
if ($errors) enquiry_action_fail(implode(' ', $errors), 422, $enquiry_id);

mysqli_begin_transaction($db);
try {
    $enquiry = enquiry_fetch($db, $enquiry_id, true, true);
    if (!$enquiry) throw new RuntimeException('Enquiry not found or access denied.');
    if (!enquiry_can_follow_up($enquiry['status'])) throw new RuntimeException('Follow-ups cannot be added to this enquiry status.');

    $next_follow_up_at = $next_follow_up_at ?: null;
    $created_by = enquiry_current_user_name() ?: 'System';
    $stmt = mysqli_prepare($db, 'INSERT INTO enquiry_follow_ups (enquiry_id, contacted_at, contact_method, discussion_note, quoted_amount, next_follow_up_at, result, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'isssdsss', $enquiry_id, $contacted_at, $contact_method, $discussion_note, $quoted_amount, $next_follow_up_at, $result, $created_by);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('The follow-up history could not be saved.');
    mysqli_stmt_close($stmt);

    $new_status = $enquiry['status'];
    if ($closure !== '') {
        $new_status = $closure;
        $next_follow_up_at = null;
    } elseif (in_array($new_status, ['New', 'Follow-up'], true)) {
        $new_status = 'Follow-up';
    }
    $update = mysqli_prepare($db, 'UPDATE enquiries SET status = ?, next_follow_up_at = ?, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($update, 'ssi', $new_status, $next_follow_up_at, $enquiry_id);
    if (!mysqli_stmt_execute($update)) throw new RuntimeException('The enquiry reminder could not be updated.');
    mysqli_stmt_close($update);

    $activity = $closure !== '' ? "Follow-up added and enquiry #$enquiry_id marked $closure" : "Follow-up added to enquiry #$enquiry_id";
    if (!enquiry_log($db, $activity)) throw new RuntimeException('The activity entry could not be saved.');
    mysqli_commit($db);
    enquiry_action_success('Follow-up saved.', 'followup', $enquiry_id);
} catch (Throwable $error) {
    mysqli_rollback($db);
    enquiry_action_fail($error->getMessage(), 422, $enquiry_id);
}

