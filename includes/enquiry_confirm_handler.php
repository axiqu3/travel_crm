<?php

require_once __DIR__ . '/enquiry_workflow.php';
require_once __DIR__ . '/enquiry_action_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') enquiry_action_fail('POST is required.', 405);
$enquiry_id = (int) ($_POST['enquiry_id'] ?? 0);
if (!enquiry_validate_csrf()) enquiry_action_fail('Your session token expired. Refresh and try again.', 403, $enquiry_id);

$final_service_date = enquiry_parse_date($_POST['final_service_date'] ?? '');
$amount_raw = trim((string) ($_POST['final_selling_amount'] ?? ''));
$final_selling_amount = $amount_raw === '' ? null : filter_var($amount_raw, FILTER_VALIDATE_FLOAT);
$confirmation_note = trim((string) ($_POST['confirmation_note'] ?? ''));
$passenger_count = filter_var($_POST['passenger_count'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
$confirmed_at = enquiry_parse_date($_POST['confirmed_at'] ?? '', true);

$errors = [];
if ($final_service_date === false || $final_service_date === null) $errors[] = 'Final Travel / Service Date is required.';
if ($amount_raw !== '' && ($final_selling_amount === false || $final_selling_amount < 0)) $errors[] = 'Final Selling Amount must be a valid non-negative number.';
if ($confirmation_note === '') $errors[] = 'Confirmation Note is required.';
if ($passenger_count === false) $errors[] = 'Passenger Count must be between 1 and 999.';
if ($confirmed_at === false || $confirmed_at === null) $errors[] = 'Customer Confirmation Date and Time is required.';
if (mb_strlen($confirmation_note) > 10000) $errors[] = 'Confirmation Note is too long.';
if ($errors) enquiry_action_fail(implode(' ', $errors), 422, $enquiry_id);

mysqli_begin_transaction($db);
try {
    $enquiry = enquiry_fetch($db, $enquiry_id, true, true);
    if (!$enquiry) throw new RuntimeException('Enquiry not found or access denied.');
    if (!enquiry_can_confirm($enquiry['status'])) throw new RuntimeException('Only New or Follow-up enquiries can be confirmed.');
    $status = 'Confirmed';
    $stmt = mysqli_prepare($db, 'UPDATE enquiries SET status = ?, confirmed_at = ?, final_service_date = ?, final_selling_amount = ?, confirmation_note = ?, passenger_count = ?, next_follow_up_at = NULL, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'sssdsii', $status, $confirmed_at, $final_service_date, $final_selling_amount, $confirmation_note, $passenger_count, $enquiry_id);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('The confirmation could not be saved.');
    mysqli_stmt_close($stmt);
    if (!enquiry_log($db, "Enquiry #$enquiry_id marked Confirmed")) throw new RuntimeException('The activity entry could not be saved.');
    mysqli_commit($db);
    enquiry_action_success('Enquiry confirmed.', 'confirmed', $enquiry_id);
} catch (Throwable $error) {
    mysqli_rollback($db);
    enquiry_action_fail($error->getMessage(), 422, $enquiry_id);
}

