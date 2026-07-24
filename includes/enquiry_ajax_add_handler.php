<?php

require_once __DIR__ . '/enquiry_workflow.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enquiry_json(false, 'POST is required.', [], 405);
}
if (!enquiry_validate_csrf()) {
    enquiry_json(false, 'Your session token expired. Refresh the page and try again.', [], 403);
}

$customer_name = trim((string) ($_POST['customer_name'] ?? ''));
$mobile = trim((string) ($_POST['mobile'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$service_type = trim((string) ($_POST['service_type'] ?? ''));
$from_location = trim((string) ($_POST['from_location'] ?? ''));
$to_location = trim((string) ($_POST['to_location'] ?? ''));
$travel_date = enquiry_parse_date($_POST['travel_date'] ?? '');
$passenger_count = filter_var($_POST['passenger_count'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
$priority = trim((string) ($_POST['priority'] ?? 'Medium'));
$next_follow_up_at = enquiry_parse_date($_POST['next_follow_up_at'] ?? '', true);
$description = trim((string) ($_POST['description'] ?? ''));
$assigned_user = trim((string) ($_POST['assigned_user'] ?? ''));

$errors = [];
if ($customer_name === '') $errors[] = 'Customer Name is required.';
if ($mobile === '') $errors[] = 'Phone Number is required.';
if ($description === '') $errors[] = 'Customer Requirement / Message is required.';
if (!in_array($service_type, enquiry_service_types(), true)) $errors[] = 'Select a valid Service Type.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
if ($travel_date === false) $errors[] = 'Travel / Service Date is invalid.';
if ($next_follow_up_at === false) $errors[] = 'Next Follow-up Date and Time is invalid.';
if ($passenger_count === false) $errors[] = 'Passenger Count must be between 1 and 999.';
if (!in_array($priority, enquiry_priorities(), true)) $errors[] = 'Select a valid priority.';
if (mb_strlen($customer_name) > 255 || mb_strlen($description) > 10000) $errors[] = 'One or more values are too long.';

if (!$enquiry_is_admin_page) {
    $assigned_user = enquiry_current_user_name();
} elseif ($assigned_user !== '') {
    $staff_stmt = mysqli_prepare($db, "SELECT id FROM users WHERE name = ? AND role <> 'admin' LIMIT 1");
    mysqli_stmt_bind_param($staff_stmt, 's', $assigned_user);
    mysqli_stmt_execute($staff_stmt);
    $staff_valid = mysqli_stmt_get_result($staff_stmt)->num_rows > 0;
    mysqli_stmt_close($staff_stmt);
    if (!$staff_valid) $errors[] = 'Assigned Staff is invalid.';
}

if ($errors) {
    enquiry_json(false, implode(' ', $errors), ['errors' => $errors], 422);
}

$subject = $service_type;
$source = 'manual';
$status = 'New';
$created_by = enquiry_current_user_name() ?: 'System';
$travel_date = $travel_date ?: null;
$next_follow_up_at = $next_follow_up_at ?: null;

mysqli_begin_transaction($db);
try {
    $sql = "INSERT INTO enquiries
        (customer_name, mobile, email, subject, description, source, service_type, from_location, to_location,
         travel_date, passenger_count, priority, next_follow_up_at, status, assigned_user, created_by, notified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) throw new RuntimeException('The enquiry could not be prepared.');
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssisssss',
        $customer_name,
        $mobile,
        $email,
        $subject,
        $description,
        $source,
        $service_type,
        $from_location,
        $to_location,
        $travel_date,
        $passenger_count,
        $priority,
        $next_follow_up_at,
        $status,
        $assigned_user,
        $created_by
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('The enquiry could not be saved.');
    }
    $enquiry_id = mysqli_insert_id($db);
    mysqli_stmt_close($stmt);
    if (!enquiry_log($db, "Manual enquiry #$enquiry_id created")) {
        throw new RuntimeException('The activity entry could not be saved.');
    }
    enquiry_sync_customer_master($db, $customer_name, $mobile, $email, $created_by);
    mysqli_commit($db);
    enquiry_json(true, 'Enquiry created successfully.', ['id' => $enquiry_id], 201);
} catch (Throwable $error) {
    mysqli_rollback($db);
    error_log('Manual enquiry creation failed: ' . $error->getMessage());
    enquiry_json(false, 'The enquiry could not be saved. Please try again.', [], 500);
}

