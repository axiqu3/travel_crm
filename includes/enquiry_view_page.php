<?php

if (!isset($enquiry_is_admin_page)) {
    http_response_code(500);
    exit('Enquiry page configuration is missing.');
}
require_once __DIR__ . '/enquiry_workflow.php';
$csrf_token = enquiry_csrf_token();
$enquiry_view_page_active = true;

$staff = [];
if ($enquiry_is_admin_page) {
    $staff_result = mysqli_query($db, "SELECT name FROM users WHERE role <> 'admin' ORDER BY name ASC");
    while ($staff_result && ($staff_row = mysqli_fetch_assoc($staff_result))) $staff[] = $staff_row['name'];
}

$edit_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_enquiry') {
    if (!enquiry_validate_csrf()) {
        $edit_error = 'Your session token expired. Refresh and try again.';
    } else {
        $enquiry_id = (int) ($_POST['enquiry_id'] ?? 0);
        $enquiry = enquiry_fetch($db, $enquiry_id, true);
        if (!$enquiry) {
            $edit_error = 'Enquiry not found or access denied.';
        } elseif (!enquiry_can_edit($enquiry['status'])) {
            $edit_error = 'This enquiry can no longer be edited.';
        } else {
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
            $assigned_user = $enquiry_is_admin_page ? trim((string) ($_POST['assigned_user'] ?? '')) : $enquiry['assigned_user'];
            
            $errors = [];
            if ($customer_name === '') $errors[] = 'Customer Name is required.';
            if ($mobile === '') $errors[] = 'Phone Number is required.';
            if ($description === '') $errors[] = 'Customer Requirement / Message is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is invalid.';
            if (!in_array($service_type, enquiry_service_types(), true)) $errors[] = 'Service Type is invalid.';
            if ($travel_date === false) $errors[] = 'Travel Date is invalid.';
            if ($passenger_count === false) $errors[] = 'Passenger Count must be between 1 and 999.';
            if (!in_array($priority, enquiry_priorities(), true)) $errors[] = 'Priority is invalid.';
            if ($next_follow_up_at === false) $errors[] = 'Next Follow-up is invalid.';
            if ($enquiry_is_admin_page && $assigned_user !== '' && !in_array($assigned_user, $staff, true)) $errors[] = 'Assigned Staff is invalid.';
            
            if ($errors) {
                $edit_error = implode(' ', $errors);
            } else {
                $subject = $service_type;
                $travel_date = $travel_date ?: null;
                $next_follow_up_at = $next_follow_up_at ?: null;
                mysqli_begin_transaction($db);
                try {
                    $stmt = mysqli_prepare($db, 'UPDATE enquiries SET customer_name=?, mobile=?, email=?, subject=?, description=?, service_type=?, from_location=?, to_location=?, travel_date=?, passenger_count=?, priority=?, next_follow_up_at=?, assigned_user=?, updated_at=NOW() WHERE id=?');
                    mysqli_stmt_bind_param($stmt, 'sssssssssisssi', $customer_name, $mobile, $email, $subject, $description, $service_type, $from_location, $to_location, $travel_date, $passenger_count, $priority, $next_follow_up_at, $assigned_user, $enquiry_id);
                    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('The enquiry could not be updated.');
                    mysqli_stmt_close($stmt);
                    if (!enquiry_log($db, "Enquiry #$enquiry_id edited")) throw new RuntimeException('The activity entry could not be saved.');
                    enquiry_sync_customer_master($db, $customer_name, $mobile, $email, $assigned_user);
                    mysqli_commit($db);
                    
                    $redirect_url = isset($enquiry_view_page_active) ? 'view.php?id=' . $enquiry_id . '&success=edited' : 'list.php?success=edited';
                    header("Location: " . $redirect_url);
                    exit;
                } catch (Throwable $exception) {
                    mysqli_rollback($db);
                    $edit_error = $exception->getMessage();
                }
            }
        }
    }
}
$enquiry_id = (int) ($_GET['id'] ?? 0);
$enquiry = enquiry_fetch($db, $enquiry_id, true);
if (!$enquiry) {
    http_response_code(404);
    echo '<div style="padding:40px;font-family:sans-serif"><h2>Enquiry not found or access denied.</h2><a href="list.php">Back to All Enquiries</a></div>';
    exit;
}
$description = enquiry_clean_description($enquiry['description'] ?? '');
$wa_url = enquiry_whatsapp_url($enquiry['mobile'] ?? '', $enquiry['customer_name'] ?? '', $enquiry['service_type']);

$follow_ups = [];
$stmt = mysqli_prepare($db, 'SELECT * FROM enquiry_follow_ups WHERE enquiry_id = ? ORDER BY contacted_at DESC, id DESC');
mysqli_stmt_bind_param($stmt, 'i', $enquiry_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $follow_ups[] = $row;
mysqli_stmt_close($stmt);

$booking = null;
$booking_id = (int) ($enquiry['booking_id'] ?? 0);
if ($booking_id > 0) {
    $stmt = mysqli_prepare($db, 'SELECT id, status, passenger_name, service_type, created_at FROM bookings WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $booking_id);
} else {
    $stmt = mysqli_prepare($db, 'SELECT id, status, passenger_name, service_type, created_at FROM bookings WHERE enquiry_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $enquiry_id);
}
mysqli_stmt_execute($stmt);
$booking = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
mysqli_stmt_close($stmt);

$activities = [];
$activity_like = '%Enquiry #' . $enquiry_id . '%';
$stmt = mysqli_prepare($db, "SELECT username, action, activity_date FROM activity_log WHERE module = 'Enquiry' AND action LIKE ? ORDER BY id DESC LIMIT 50");
mysqli_stmt_bind_param($stmt, 's', $activity_like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $activities[] = $row;
mysqli_stmt_close($stmt);

function enquiry_view_date($value, $with_time = false) {
    if (empty($value)) return '—';
    $time = strtotime($value);
    return $time ? date($with_time ? 'd M Y, h:i A' : 'd M Y', $time) : '—';
}
function enquiry_view_money($value) {
    return ($value === null || $value === '') ? '—' : '₹' . number_format((float) $value, 2);
}
$open_action = in_array($_GET['action'] ?? '', ['followup', 'confirm', 'close'], true) ? $_GET['action'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enquiry #<?= $enquiry_id ?> | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem('sidebar-locked')==='true')document.documentElement.classList.add('sidebar-pref-locked');</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .booking-view-page {
            max-width: 1320px;
            margin: 0 auto;
            padding-bottom: 44px;
        }

        .ev-page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 18px;
            padding-bottom: 17px;
            border-bottom: 1px solid #dfe6ed;
        }

        .ev-kicker {
            margin: 0 0 5px;
            color: var(--text-secondary, #64748b);
            font-size: 12px;
            font-weight: 700;
        }

        .ev-title-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .ev-title-row h1 {
            margin: 0;
            color: var(--text-primary, #0f172a);
            font-size: clamp(24px, 3vw, 32px);
            line-height: 1.2;
        }

        .ev-subtitle {
            margin: 7px 0 0;
            color: var(--text-secondary, #64748b);
            font-size: 13px;
        }

        .ev-actions {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ev-actions .btn {
            min-height: 38px;
            white-space: nowrap;
        }

        .ev-alert {
            margin-bottom: 16px;
        }

        .ev-summary,
        .ev-section {
            background: #fff;
            border: 1px solid #dbe3ea;
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(13, 40, 63, .055);
        }

        .ev-summary {
            padding: 28px;
            margin-bottom: 28px;
        }

        .ev-summary h2 {
            margin: 0 0 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid #dbe3ea;
            color: #0d283f;
            font-size: 19px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .ev-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            overflow: hidden;
            border: 1px solid #d7e1e8;
            border-radius: 14px;
            background: #f9fbfc;
        }

        .ev-summary-item {
            min-width: 0;
            min-height: 105px;
            padding: 19px 20px;
            border-right: 1px solid #dbe3ea;
            border-bottom: 1px solid #dbe3ea;
            background: #f9fbfc;
        }

        .ev-summary-item:nth-child(4n) {
            border-right: 0;
        }

        .ev-summary-item:nth-last-child(-n + 4) {
            border-bottom: 0;
        }

        .ev-summary-item span,
        .ev-detail dt,
        .ev-side-label {
            display: block;
            margin-bottom: 8px;
            color: #73869a;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .ev-summary-item strong {
            display: block;
            overflow-wrap: anywhere;
            color: #102a43;
            font-size: 15px;
            line-height: 1.45;
        }

        .ev-summary-item .is-due {
            color: #b91c1c;
        }

        .ev-content-grid {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        .ev-stack {
            display: contents;
        }

        .ev-section {
            padding: 28px;
        }

        .ev-section-title {
            margin: 0 0 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid #dbe3ea;
            color: #0d283f;
            font-size: 19px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .ev-contact-section { order: 1; }
        .ev-requirement-section { order: 2; }
        .ev-status-section { order: 3; }
        .ev-booking-section { order: 4; }
        .ev-confirmation-section { order: 5; }
        .ev-followup-section { order: 6; }
        .ev-activity-section { order: 7; }

        .ev-model-grid {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0 !important;
            overflow: hidden;
            border: 1px solid #d7e1e8;
            border-radius: 14px;
            background: #f9fbfc;
        }

        .ev-model-grid > div {
            min-width: 0;
            min-height: 102px;
            padding: 18px 20px;
            border-right: 1px solid #dbe3ea;
            background: #f9fbfc;
        }

        .ev-model-grid > div:nth-child(4n) {
            border-right: 0;
        }

        .ev-model-grid > div > span:first-child {
            display: block !important;
            margin: 0 0 8px !important;
            color: #73869a !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            letter-spacing: .06em;
            text-transform: uppercase !important;
        }

        .ev-model-grid > div > strong,
        .ev-model-grid > div > p,
        .ev-model-grid > div > a:not(.wa-shortcut) {
            color: #102a43 !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            line-height: 1.5;
        }

        .ev-model-grid-action > div {
            border-bottom: 1px solid #dbe3ea;
        }

        .ev-model-grid-action > .btn {
            grid-column: 1 / -1;
            width: auto !important;
            margin: 14px;
            justify-content: center;
        }

        .booking-view-page .info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            overflow: hidden;
            border: 1px solid #d7e1e8;
            border-radius: 14px;
            background: #f9fbfc;
        }

        .booking-view-page .info-card-item,
        .booking-view-page .info-card-item[style] {
            min-height: 105px;
            padding: 18px 20px !important;
            border: 0 !important;
            border-right: 1px solid #dbe3ea !important;
            border-bottom: 1px solid #dbe3ea !important;
            border-radius: 0;
            background: #f9fbfc;
        }

        .booking-view-page .info-card-item:nth-child(4n) {
            border-right: 0 !important;
        }

        .booking-view-page .info-card-item:last-child {
            border-right: 0 !important;
            border-bottom: 0 !important;
        }

        .booking-view-page .info-card-item h4,
        .booking-view-page .info-card-item h4[style] {
            margin: 0 0 8px !important;
            color: #73869a !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .booking-view-page .info-card-item p,
        .booking-view-page .info-card-item p[style] {
            margin: 0 !important;
            color: #102a43 !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            line-height: 1.5;
        }

        .booking-view-page .info-card-item:last-child p {
            font-weight: 400 !important;
        }

        .ev-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px 22px;
            margin: 0;
        }

        .ev-detail {
            min-width: 0;
        }

        .ev-detail.is-wide {
            grid-column: 1 / -1;
        }

        .ev-detail dd {
            margin: 0;
            overflow-wrap: anywhere;
            color: var(--text-primary, #0f172a);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.5;
        }

        .ev-message {
            padding: 13px 14px;
            border-radius: 9px;
            background: #f8fafc;
            white-space: pre-wrap;
            font-weight: 400 !important;
        }

        .ev-contact-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ev-contact-link {
            color: var(--text-primary, #0f172a);
            text-decoration: none;
        }

        .ev-contact-link:hover {
            color: var(--accent-color, #0d283f);
            text-decoration: underline;
        }

        .wa-shortcut {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border-radius: 7px;
            background: #25d366;
            color: #fff;
            text-decoration: none;
        }

        .wa-shortcut:hover {
            background: #20ba5a;
        }

        .wa-shortcut svg {
            width: 16px;
            height: 16px;
        }

        .badge.status-new { background: #e0f2fe; color: #0369a1; }
        .badge.status-follow-up { background: #fef9c3; color: #854d0e; }
        .badge.status-confirmed { background: #e0e7ff; color: #4338ca; }
        .badge.status-converted { background: #dcfce7; color: #166534; }
        .badge.status-cancelled { background: #fee2e2; color: #991b1b; }
        .badge.status-closed { background: #f1f5f9; color: #475569; }

        .ev-side-list {
            display: flex;
            flex-direction: column;
        }

        .ev-side-row {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
        }

        .ev-side-row:first-child {
            padding-top: 0;
        }

        .ev-side-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .ev-side-value {
            margin: 0;
            overflow-wrap: anywhere;
            color: var(--text-primary, #0f172a);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.5;
        }

        .ev-booking-link {
            width: 100%;
            margin-top: 13px;
            justify-content: center;
        }

        .history {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .history-item {
            padding: 13px 14px;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 9px;
            background: #f8fafc;
        }

        .history-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 7px;
            color: var(--text-secondary, #64748b);
            font-size: 11px;
        }

        .history-note {
            color: var(--text-primary, #0f172a);
            font-size: 13px;
            line-height: 1.55;
            white-space: pre-wrap;
        }

        .empty {
            margin: 0;
            color: var(--text-secondary, #64748b);
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 950px) {
            .ev-page-header {
                display: flex;
                flex-direction: column;
            }

            .ev-actions {
                justify-content: flex-start;
            }

            .ev-summary-grid,
            .ev-model-grid,
            .booking-view-page .info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ev-summary-item,
            .ev-summary-item:nth-child(4n) {
                border-right: 1px solid #dbe3ea;
                border-bottom: 1px solid #dbe3ea;
            }

            .ev-summary-item:nth-child(2n) {
                border-right: 0;
            }

            .ev-summary-item:nth-last-child(-n + 2) {
                border-bottom: 0;
            }

            .ev-model-grid > div {
                border-right: 1px solid #dbe3ea;
                border-bottom: 1px solid #dbe3ea;
            }

            .ev-model-grid > div:nth-child(2n) {
                border-right: 0;
            }

            .ev-model-grid:not(.ev-model-grid-action) > div:nth-last-child(-n + 2) {
                border-bottom: 0;
            }

            .booking-view-page .info-card-item,
            .booking-view-page .info-card-item:nth-child(4n) {
                border-right: 1px solid #dbe3ea !important;
            }

            .booking-view-page .info-card-item:nth-child(2n) {
                border-right: 0 !important;
            }
        }

        @media (max-width: 680px) {
            .ev-summary-grid,
            .ev-model-grid,
            .ev-details,
            .booking-view-page .info-grid {
                grid-template-columns: 1fr;
            }

            .ev-summary,
            .ev-section {
                padding: 20px;
                border-radius: 14px;
            }

            .ev-summary h2,
            .ev-section-title {
                font-size: 16px;
            }

            .ev-summary-item,
            .ev-summary-item:nth-child(2n),
            .ev-summary-item:nth-child(4n) {
                min-height: 0;
                border-right: 0;
                border-bottom: 1px solid #dbe3ea;
            }

            .ev-summary-item:last-child {
                border-bottom: 0;
            }

            .ev-model-grid > div,
            .ev-model-grid > div:nth-child(2n),
            .ev-model-grid > div:nth-child(4n) {
                min-height: 0;
                border-right: 0;
                border-bottom: 1px solid #dbe3ea;
            }

            .ev-model-grid:not(.ev-model-grid-action) > div:last-child {
                border-bottom: 0;
            }

            .booking-view-page .info-card-item,
            .booking-view-page .info-card-item:nth-child(2n),
            .booking-view-page .info-card-item:nth-child(4n) {
                min-height: 0;
                border-right: 0 !important;
            }

            .ev-detail.is-wide {
                grid-column: auto;
            }

            .ev-actions {
                display: grid;
                width: 100%;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ev-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Compact view: only the main enquiry box uses the voucher-style grid. */
        .booking-view-page {
            max-width: 1180px;
            padding-bottom: 28px;
        }

        .ev-page-header {
            margin-bottom: 12px;
            padding-bottom: 12px;
        }

        .ev-title-row h1 {
            font-size: clamp(21px, 2.4vw, 27px);
        }

        .ev-subtitle {
            margin-top: 4px;
            font-size: 12px;
        }

        .ev-actions .btn {
            min-height: 34px;
            padding-top: 7px;
            padding-bottom: 7px;
        }

        .ev-summary {
            margin-bottom: 14px;
            padding: 17px;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(13, 40, 63, .04);
        }

        .ev-summary h2 {
            margin-bottom: 12px;
            padding-bottom: 10px;
            font-size: 15px;
        }

        .ev-summary-item {
            min-height: 70px;
            padding: 11px 13px;
        }

        .ev-summary-item span {
            margin-bottom: 4px;
            font-size: 9px;
        }

        .ev-summary-item strong {
            font-size: 13px;
            line-height: 1.35;
        }

        .ev-content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr);
            align-items: start;
            gap: 14px;
        }

        .ev-stack {
            display: flex;
            min-width: 0;
            flex-direction: column;
            gap: 14px;
        }

        .ev-section {
            padding: 16px;
            border-radius: 11px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .035);
        }

        .ev-section-title {
            margin-bottom: 12px;
            padding-bottom: 9px;
            font-size: 14px;
            text-transform: none;
        }

        .booking-view-page .info-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            overflow: visible;
            border: 0;
            border-radius: 0;
            background: transparent;
        }

        .booking-view-page .info-card-item,
        .booking-view-page .info-card-item[style],
        .booking-view-page .info-card-item:nth-child(2n),
        .booking-view-page .info-card-item:nth-child(4n),
        .booking-view-page .info-card-item:last-child {
            min-height: 0;
            padding: 10px 12px !important;
            border: 0 !important;
            border-radius: 8px;
            background: #f8fafc;
        }

        .booking-view-page .info-card-item h4,
        .booking-view-page .info-card-item h4[style] {
            margin-bottom: 4px !important;
            font-size: 9px !important;
        }

        .booking-view-page .info-card-item p,
        .booking-view-page .info-card-item p[style] {
            font-size: 12px !important;
            line-height: 1.4;
        }

        .ev-model-grid,
        .ev-model-grid-action {
            display: flex !important;
            flex-direction: column;
            gap: 0 !important;
            overflow: visible;
            border: 0;
            border-radius: 0;
            background: transparent;
        }

        .ev-model-grid > div,
        .ev-model-grid > div:nth-child(2n),
        .ev-model-grid > div:nth-child(4n),
        .ev-model-grid-action > div {
            min-height: 0;
            padding: 9px 0;
            border: 0;
            border-bottom: 1px solid #edf1f5;
            background: transparent;
        }

        .ev-model-grid > div:first-child {
            padding-top: 0;
        }

        .ev-model-grid > div:last-of-type {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .ev-model-grid > div > span:first-child {
            margin-bottom: 3px !important;
            font-size: 9px !important;
        }

        .ev-model-grid > div > strong,
        .ev-model-grid > div > p,
        .ev-model-grid > div > a:not(.wa-shortcut) {
            font-size: 12px !important;
            line-height: 1.4;
        }

        .ev-model-grid-action > .btn {
            width: 100% !important;
            margin: 11px 0 0;
        }

        .history {
            gap: 8px;
        }

        .history-item {
            padding: 10px 12px;
        }

        .history-meta {
            margin-bottom: 5px;
        }

        .history-note {
            font-size: 12px;
            line-height: 1.45;
        }

        @media (max-width: 950px) {
            .ev-content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 680px) {
            .ev-summary,
            .ev-section {
                padding: 15px;
            }

            .booking-view-page .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Modals */
        .enq-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2100;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15, 23, 42, .48);
            backdrop-filter: blur(3px);
        }

        .enq-modal-backdrop.open {
            display: flex;
        }

        .enq-modal-card {
            width: min(calc(100vw - 36px), 1040px) !important;
            max-height: calc(100vh - 36px);
            display: flex;
            flex-direction: column;
            overflow: hidden !important;
            border: 1px solid #dbe3ea;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
            animation: modalFadeIn .2s ease-out;
        }

        .enq-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex: 0 0 auto;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #fff;
        }

        .enq-modal-header h2 {
            margin: 0;
            color: #0f172a;
            font-size: 16px;
            font-weight: 700;
        }

        .enq-modal-close {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            font-size: 23px;
            line-height: 1;
            cursor: pointer;
        }

        .enq-modal-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .enq-modal-body {
            min-height: 0;
            padding: 20px !important;
            overflow-y: auto;
        }

        #editEnquiryForm {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }

        #editEnquiryForm .form-group {
            min-width: 0;
            margin: 0;
        }

        #editEnquiryForm input,
        #editEnquiryForm select,
        #editEnquiryForm textarea {
            min-height: 42px;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(8px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1200;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-backdrop.open {
            display: flex;
        }
        
        .modal-card {
            width: min(100%, 650px);
            border: 1px solid var(--border-dark);
            border-radius: 14px;
            background: var(--card-bg, #fff);
            box-shadow: 0 24px 60px rgba(15, 39, 71, .28);
            overflow: hidden;
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .modal-head {
            padding: 18px 22px;
            background: #fff;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .modal-head h2 {
            margin: 0;
            color: inherit;
            font-size: 15px;
            font-weight: 600;
        }
        
        .modal-close {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .modal-close:hover {
            color: #0f172a;
            opacity: 1;
        }
        
        .ev-form {
            padding: 22px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        
        .ev-form label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-main);
            font-size: 12px;
            font-weight: 700;
        }
        
        .ev-form input,
        .ev-form select,
        .ev-form textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border-dark);
            border-radius: 7px;
            background: rgba(0, 0, 0, .02);
            color: var(--text-main);
            font: inherit;
            box-sizing: border-box;
            transition: border-color 0.2s, background-color 0.2s;
        }
        
        .ev-form input:focus,
        .ev-form select:focus,
        .ev-form textarea:focus {
            border-color: var(--accent-color);
            background: #fff;
            outline: none;
        }
        
        .ev-form .span-2 {
            grid-column: span 2;
        }
        
        .modal-actions {
            grid-column: span 2;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        
        .closure-option {
            display: none;
        }
        
        @media (max-width: 600px) {
            .enq-modal-backdrop {
                align-items: flex-start;
                padding: 10px;
            }

            .enq-modal-card {
                width: 100% !important;
                max-height: calc(100vh - 20px);
            }

            .enq-modal-body {
                padding: 16px !important;
            }

            #editEnquiryForm {
                grid-template-columns: 1fr !important;
            }

            #editEnquiryForm > * {
                grid-column: 1 / -1 !important;
            }

            .ev-form {
                grid-template-columns: 1fr;
            }
            .ev-form .span-2,
            .modal-actions {
                grid-column: span 1;
            }
            .modal-actions {
                flex-direction: column-reverse;
            }
            .modal-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (min-width: 601px) and (max-width: 900px) {
            #editEnquiryForm {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            #editEnquiryForm > [style*="grid-column: span 4"] {
                grid-column: 1 / -1 !important;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main booking-view-page">
    <header class="ev-page-header">
        <div>
            <p class="ev-kicker">Enquiry #<?= $enquiry_id ?></p>
            <div class="ev-title-row">
                <h1><?= htmlspecialchars($enquiry['customer_name']) ?></h1>
                <span class="badge <?= enquiry_status_badge_class($enquiry['status']) ?>"><?= htmlspecialchars($enquiry['status']) ?></span>
            </div>
            <p class="ev-subtitle">Customer request, follow-ups, status, and booking information.</p>
        </div>
        <div class="ev-actions">
            <a class="btn btn-secondary" href="list.php">Back to List</a>
            <?php if (enquiry_can_edit($enquiry['status'])): ?>
                <button type="button" class="btn btn-secondary edit-enquiry-btn"
                        data-id="<?= $enquiry_id ?>" 
                        data-customer-name="<?= htmlspecialchars($enquiry['customer_name'] ?? '') ?>" 
                        data-mobile="<?= htmlspecialchars($enquiry['mobile'] ?? '') ?>" 
                        data-email="<?= htmlspecialchars($enquiry['email'] ?? '') ?>" 
                        data-service-type="<?= htmlspecialchars($enquiry['service_type'] ?? '') ?>" 
                        data-from-location="<?= htmlspecialchars($enquiry['from_location'] ?? '') ?>" 
                        data-to-location="<?= htmlspecialchars($enquiry['to_location'] ?? '') ?>" 
                        data-travel-date="<?= htmlspecialchars($enquiry['travel_date'] ?? '') ?>" 
                        data-passenger-count="<?= (int)($enquiry['passenger_count'] ?? 1) ?>" 
                        data-priority="<?= htmlspecialchars($enquiry['priority'] ?? 'Medium') ?>" 
                        data-next-follow-up-at="<?= htmlspecialchars($enquiry['next_follow_up_at'] ? date('Y-m-d', strtotime($enquiry['next_follow_up_at'])) : '') ?>" 
                        data-description="<?= htmlspecialchars($enquiry['description'] ?? '') ?>" 
                        data-assigned-user="<?= htmlspecialchars($enquiry['assigned_user'] ?? '') ?>">Edit</button>
            <?php endif; ?>
            <?php if (in_array($enquiry['status'], ['New','Follow-up'], true)): ?>
                <button class="btn" type="button" data-open="followupModal">Add Follow-up</button>
            <?php endif; ?>
            <?php if (enquiry_can_confirm($enquiry['status'])): ?>
                <button class="btn" type="button" data-open="confirmModal">Confirm</button>
            <?php endif; ?>
            <?php if ($booking): ?>
                <a class="btn" href="../bookings/view.php?id=<?= (int)$booking['id'] ?>" style="background: #15803d; border-color: #15803d; color: #fff;">View Booking</a>
            <?php elseif (enquiry_can_convert($enquiry['status'], $enquiry['booking_id'])): ?>
                <a class="btn" href="../bookings/add.php?enquiry_id=<?= $enquiry_id ?>" style="background: #15803d; border-color: #15803d; color: #fff;">Convert to Booking</a>
            <?php endif; ?>
            <?php if (enquiry_can_close($enquiry['status'])): ?>
                <button class="btn" type="button" data-open="closeModal" style="background:#fff;border-color:#fecaca;color:#b91c1c;">Close</button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (isset($_GET['success'])): ?>
        <div class="booking-alert is-success ev-alert" role="alert">
            Enquiry updated successfully.
        </div>
    <?php endif; ?>
    
    <?php if (!empty($_GET['error'])): ?>
        <div class="booking-alert is-error ev-alert" role="alert">
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <section class="ev-summary" aria-labelledby="enquiry-summary-title">
        <h2 id="enquiry-summary-title">Enquiry Details</h2>
        <div class="ev-summary-grid">
            <div class="ev-summary-item">
                <span>Service</span>
                <strong><?= htmlspecialchars($enquiry['service_type'] ?: '—') ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Route</span>
                <strong><?= htmlspecialchars(($enquiry['from_location'] ?: '—') . ' → ' . ($enquiry['to_location'] ?: '—')) ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Travel / Service Date</span>
                <strong><?= enquiry_view_date($enquiry['travel_date']) ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Passengers</span>
                <strong><?= (int) ($enquiry['passenger_count'] ?: 1) ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Priority</span>
                <strong><span class="badge priority-<?= strtolower($enquiry['priority'] ?: 'medium') ?>"><?= htmlspecialchars($enquiry['priority'] ?: 'Medium') ?></span></strong>
            </div>
            <div class="ev-summary-item">
                <span>Next Follow-up</span>
                <strong class="<?= !empty($enquiry['next_follow_up_at']) ? 'is-due' : '' ?>"><?= enquiry_view_date($enquiry['next_follow_up_at'], true) ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Source</span>
                <strong><?= htmlspecialchars($enquiry['source'] ?: 'Manual') ?></strong>
            </div>
            <div class="ev-summary-item">
                <span>Assigned Agent</span>
                <strong><?= htmlspecialchars($enquiry['assigned_user'] ?: 'Unassigned') ?></strong>
            </div>
        </div>
    </section>

    <div class="ev-content-grid">
        <!-- Left Side: Detail Cards -->
        <div class="ev-stack">
            <!-- Travel Requirement Details -->
            <section class="ev-section ev-requirement-section">
                <h2 class="ev-section-title">Travel / Service Requirement</h2>
                <div class="info-grid">
                    <div class="info-card-item">
                        <h4>Service Type</h4>
                        <p><?= htmlspecialchars($enquiry['service_type']) ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>From / To Location</h4>
                        <p><?= htmlspecialchars(($enquiry['from_location'] ?: '—') . ' → ' . ($enquiry['to_location'] ?: '—')) ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Travel / Service Date</h4>
                        <p><?= enquiry_view_date($enquiry['travel_date']) ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Passenger Count</h4>
                        <p><?= (int) ($enquiry['passenger_count'] ?: 1) ?> passenger(s)</p>
                    </div>
                    <div class="info-card-item" style="grid-column: 1 / -1;">
                        <h4>Original Requirement / Message</h4>
                        <p style="font-weight: normal; color: var(--text-primary); white-space: pre-wrap; line-height: 1.5; margin-top: 6px;"><?= htmlspecialchars($description ?: '—') ?></p>
                    </div>
                </div>
            </section>

            <!-- Follow-up History -->
            <section class="ev-section ev-followup-section">
                <h2 class="ev-section-title">Follow-up History</h2>
                <div class="history">
                    <?php if ($follow_ups): foreach ($follow_ups as $follow): ?>
                        <article class="history-item">
                            <div class="history-meta">
                                <strong style="color: var(--text-primary);"><?= enquiry_view_date($follow['contacted_at'], true) ?></strong>
                                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--text-main); font-weight: 600;"><?= htmlspecialchars($follow['contact_method']) ?></span>
                                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--text-secondary);"><?= htmlspecialchars($follow['result']) ?></span>
                                <span>by <strong><?= htmlspecialchars($follow['created_by'] ?: 'System') ?></strong></span>
                            </div>
                            <div class="history-note"><?= htmlspecialchars($follow['discussion_note']) ?></div>
                            <div class="history-meta" style="margin-top: 10px; margin-bottom: 0;">
                                <span>Quoted Amount: <strong><?= enquiry_view_money($follow['quoted_amount']) ?></strong></span>
                                <?php if (!empty($follow['next_follow_up_at'])): ?>
                                    <span style="margin-left: auto;">Next: <strong><?= enquiry_view_date($follow['next_follow_up_at'], true) ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; else: ?>
                        <p class="empty">No follow-ups recorded yet. Click "Add Follow-up" above to log one.</p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Activity Logs -->
            <section class="ev-section ev-activity-section">
                <h2 class="ev-section-title">Activity</h2>
                <div class="history">
                    <?php if ($activities): foreach ($activities as $activity): ?>
                        <article class="history-item" style="border-left-color: var(--border-dark);">
                            <div class="history-meta" style="margin-bottom: 4px;">
                                <strong><?= htmlspecialchars($activity['username']) ?></strong>
                                <span style="margin-left: auto;"><?= enquiry_view_date($activity['activity_date'], true) ?></span>
                            </div>
                            <div class="history-note" style="font-size: 12px; color: var(--text-secondary);"><?= htmlspecialchars($activity['action']) ?></div>
                        </article>
                    <?php endforeach; else: ?>
                        <p class="empty">No workflow activity recorded.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- Right Side: Contact, Status and Booking -->
        <div class="ev-stack">
            <!-- Customer Details & Quick Contact -->
            <section class="ev-section ev-contact-section">
                <h2 class="ev-section-title">Customer Contact</h2>
                <div class="ev-model-grid">
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Name</span>
                        <strong style="font-size: 14px; color: var(--text-primary);"><?= htmlspecialchars($enquiry['customer_name']) ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Source</span>
                        <strong style="font-size: 14px; color: var(--text-primary);"><?= htmlspecialchars($enquiry['source'] ?: 'Manual') ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Phone Number</span>
                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
                            <?php if (!empty($enquiry['mobile'])): ?>
                                <a class="ev-contact-link" href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $enquiry['mobile'])) ?>"><?= htmlspecialchars($enquiry['mobile']) ?></a>
                            <?php else: ?>
                                <strong>—</strong>
                            <?php endif; ?>
                            <?php if ($wa_url): ?>
                                <a class="wa-shortcut" href="<?= htmlspecialchars($wa_url) ?>" target="_blank" rel="noopener noreferrer" title="Open WhatsApp" aria-label="Open WhatsApp"><?= enquiry_list_wa_icon() ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Email Address</span>
                        <?php if (!empty($enquiry['email'])): ?>
                            <a class="ev-contact-link" href="mailto:<?= htmlspecialchars($enquiry['email']) ?>"><?= htmlspecialchars($enquiry['email']) ?></a>
                        <?php else: ?>
                            <strong>—</strong>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Current Status -->
            <section class="ev-section ev-status-section">
                <h2 class="ev-section-title">Status & Owner</h2>
                <div class="ev-model-grid">
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 4px;">Status</span>
                        <span class="badge <?= enquiry_status_badge_class($enquiry['status']) ?>"><?= htmlspecialchars($enquiry['status']) ?></span>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Assigned Agent</span>
                        <strong style="font-size: 14px; color: var(--text-primary);"><?= htmlspecialchars($enquiry['assigned_user'] ?: 'Unassigned') ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Created On</span>
                        <strong style="font-size: 13px; color: var(--text-primary);"><?= enquiry_view_date($enquiry['created_at'], true) ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Last Updated</span>
                        <strong style="font-size: 13px; color: var(--text-primary);"><?= enquiry_view_date($enquiry['updated_at'], true) ?></strong>
                    </div>
                </div>
            </section>

            <!-- Linked Booking -->
            <section class="ev-section ev-booking-section">
                <h2 class="ev-section-title">Booking</h2>
                <?php if ($booking): ?>
                    <div class="ev-model-grid ev-model-grid-action">
                        <div>
                            <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Booking ID</span>
                            <strong style="font-size: 14px; color: var(--text-primary);">#<?= (int) $booking['id'] ?></strong>
                        </div>
                        <div>
                            <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 4px;">Status</span>
                            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 600;"><?= htmlspecialchars($booking['status']) ?></span>
                        </div>
                        <div>
                            <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Passenger / Service</span>
                            <strong style="font-size: 13px; color: var(--text-primary);"><?= htmlspecialchars(($booking['passenger_name'] ?: '—') . ' · ' . ($booking['service_type'] ?: '—')) ?></strong>
                        </div>
                        <a class="btn btn-secondary" href="../bookings/view.php?id=<?= (int) $booking['id'] ?>" style="width: 100%; text-align: center; justify-content: center;">View Booking File</a>
                    </div>
                <?php else: ?>
                    <p class="empty">No booking is linked to this enquiry yet.</p>
                <?php endif; ?>
            </section>

            <!-- Confirmation Details -->
            <?php if (!empty($enquiry['confirmed_at']) || ($enquiry['final_selling_amount'] ?? '') !== '' || !empty($enquiry['confirmation_note'])): ?>
            <section class="ev-section ev-confirmation-section">
                <h2 class="ev-section-title">Confirmation</h2>
                <div class="ev-model-grid">
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Confirmed Date</span>
                        <strong style="font-size: 13px; color: var(--text-primary);"><?= enquiry_view_date($enquiry['confirmed_at'], true) ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Final Service Date</span>
                        <strong style="font-size: 13px; color: var(--text-primary);"><?= enquiry_view_date($enquiry['final_service_date']) ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Final Selling Amount</span>
                        <strong style="font-size: 14px; color: #166534;"><?= enquiry_view_money($enquiry['final_selling_amount']) ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; margin-bottom: 2px;">Confirmation Note</span>
                        <p style="font-size: 12px; color: var(--text-main); line-height: 1.4; margin-top: 4px;"><?= htmlspecialchars($enquiry['confirmation_note'] ?: '—') ?></p>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Follow-up Modal -->
<div class="modal-backdrop" id="followupModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2>Add Follow-up Log</h2>
            <button class="modal-close" type="button" data-close>&times;</button>
        </div>
        <form class="ev-form" method="post" action="add_follow_up.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="enquiry_id" value="<?= $enquiry_id ?>">
            <div>
                <label>Contact Date & Time *</label>
                <input type="datetime-local" name="contacted_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div>
                <label>Contact Method *</label>
                <select name="contact_method" required>
                    <?php foreach (enquiry_contact_methods() as $item): ?>
                        <option><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="span-2">
                <label>Discussion Note *</label>
                <textarea name="discussion_note" rows="4" placeholder="Summarize call details, travel requirements discussed..." required></textarea>
            </div>
            <div>
                <label>Quoted Amount</label>
                <input type="number" name="quoted_amount" min="0" step="0.01" placeholder="₹0.00">
            </div>
            <div>
                <label>Next Follow-up Date</label>
                <input type="datetime-local" name="next_follow_up_at">
            </div>
            <div>
                <label>Follow-up Result *</label>
                <select name="result" id="followupResult" required>
                    <?php foreach (enquiry_follow_up_results() as $item): ?>
                        <option><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="closure-option" id="closureOption">
                <label>Explicitly End Enquiry</label>
                <select name="closure_status">
                    <option value="">Keep Active</option>
                    <option value="Closed">Closed</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" data-close>Cancel</button>
                <button class="btn" type="submit" style="background: var(--accent-color); color: #fff; border-color: var(--accent-color);">Save Follow-up</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Enquiry Modal -->
<div class="modal-backdrop" id="confirmModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2>Mark Enquiry as Confirmed</h2>
            <button class="modal-close" type="button" data-close>&times;</button>
        </div>
        <form class="ev-form" method="post" action="confirm.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="enquiry_id" value="<?= $enquiry_id ?>">
            <div>
                <label>Final Travel / Service Date *</label>
                <input type="date" name="final_service_date" value="<?= htmlspecialchars($enquiry['travel_date'] ?: '') ?>" required>
            </div>
            <div>
                <label>Final Selling Amount *</label>
                <input type="number" min="0" step="0.01" name="final_selling_amount" placeholder="₹0.00" required>
            </div>
            <div>
                <label>Passenger Count *</label>
                <input type="number" min="1" max="999" name="passenger_count" value="<?= (int) ($enquiry['passenger_count'] ?: 1) ?>" required>
            </div>
            <div>
                <label>Confirmation Date & Time *</label>
                <input type="datetime-local" name="confirmed_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="span-2">
                <label>Confirmation / Booking Note *</label>
                <textarea name="confirmation_note" rows="4" placeholder="Enter confirmation reference details, hotel or sector confirmations..." required></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" data-close>Cancel</button>
                <button class="btn" type="submit" style="background: #15803d; border-color: #15803d; color: #fff;">Mark Confirmed</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel or Close Modal -->
<div class="modal-backdrop" id="closeModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2>Cancel or Close Enquiry</h2>
            <button class="modal-close" type="button" data-close>&times;</button>
        </div>
        <form class="ev-form" method="post" action="change_status.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="enquiry_id" value="<?= $enquiry_id ?>">
            <div class="span-2">
                <label>Select Final Status *</label>
                <select name="status" required>
                    <option value="Closed">Closed</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
                <p style="margin-top: 8px; font-size: 12px; color: var(--text-secondary);">Closing or cancelling this enquiry clears any scheduled follow-up reminders. Converted enquiries cannot be modified here.</p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" data-close>Keep Enquiry Active</button>
                <button class="btn" type="submit" style="background: #dc2626; border-color: #dc2626; color: #fff;">Confirm Status Change</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const closeAll = () => { document.querySelectorAll('.modal-backdrop.open').forEach(el=>el.classList.remove('open')); document.body.style.overflow=''; };
    const open = id => { const el=document.getElementById(id); if(el){el.classList.add('open');document.body.style.overflow='hidden';} };
    document.querySelectorAll('[data-open]').forEach(btn=>btn.addEventListener('click',()=>open(btn.dataset.open)));
    document.querySelectorAll('[data-close]').forEach(btn=>btn.addEventListener('click',closeAll));
    document.querySelectorAll('.modal-backdrop').forEach(el=>el.addEventListener('click',e=>{if(e.target===el)closeAll();}));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();});
    const result=document.getElementById('followupResult'), closure=document.getElementById('closureOption');
    if(result) result.addEventListener('change',()=>{closure.style.display=result.value==='Not Interested'?'block':'none'; if(result.value!=='Not Interested')closure.querySelector('select').value='';});
    const initial=<?= json_encode($open_action) ?>; if(initial==='followup')open('followupModal'); if(initial==='confirm')open('confirmModal'); if(initial==='close')open('closeModal');
})();
</script>

<!-- Edit Enquiry Modal -->
<div class="enq-modal-backdrop" id="editEnquiryModal" role="dialog" aria-modal="true" aria-labelledby="editEnquiryTitle">
    <div class="enq-modal-card">
        <div class="enq-modal-header">
            <h2 id="editEnquiryTitle">Edit Enquiry</h2>
            <button class="enq-modal-close" type="button" onclick="closeEditEnquiryModal()" aria-label="Close">&times;</button>
        </div>
        <div class="enq-modal-body">
            <form method="POST" id="editEnquiryForm" autocomplete="off">
                <input type="hidden" name="enquiry_id" id="edit_enquiry_id">
                <input type="hidden" name="action" value="edit_enquiry">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="form-group">
                    <label for="edit_customer_name" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Customer Name *</label>
                    <input type="text" id="edit_customer_name" name="customer_name" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_mobile" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Phone Number *</label>
                    <input type="text" id="edit_mobile" name="mobile" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_email" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Email Address</label>
                    <input type="email" id="edit_email" name="email" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_service_type" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Service Type</label>
                    <select id="edit_service_type" name="service_type" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                        <?php foreach (enquiry_service_types() as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_from_location" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">From Location</label>
                    <input type="text" id="edit_from_location" name="from_location" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_to_location" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">To Location</label>
                    <input type="text" id="edit_to_location" name="to_location" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_travel_date" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Travel Date</label>
                    <input type="date" id="edit_travel_date" name="travel_date" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_passenger_count" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Passenger Count</label>
                    <input type="number" id="edit_passenger_count" name="passenger_count" min="1" max="999" value="1" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <div class="form-group">
                    <label for="edit_priority" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Priority</label>
                    <select id="edit_priority" name="priority" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                        <?php foreach (enquiry_priorities() as $pr): ?>
                            <option value="<?= htmlspecialchars($pr) ?>"><?= htmlspecialchars($pr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_next_follow_up_at" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Next Follow-up Date</label>
                    <input type="date" id="edit_next_follow_up_at" name="next_follow_up_at" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                </div>
                
                <?php if ($enquiry_is_admin_page): ?>
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="edit_assigned_user" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Assigned Staff</label>
                        <select id="edit_assigned_user" name="assigned_user" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box;">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="form-group" style="grid-column: span 2;">
                        <!-- Spacer -->
                    </div>
                <?php endif; ?>
                
                <div class="form-group" style="grid-column: span 4;">
                    <label for="edit_description" style="display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase;">Customer Requirement / Message *</label>
                    <textarea id="edit_description" name="description" rows="3" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: rgba(0, 0, 0, .02); font: inherit; box-sizing: border-box; height: 43px; resize: none;"></textarea>
                </div>
                
                <div style="grid-column: span 4; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="submit" class="btn">Update Enquiry</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditEnquiryModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    const editModal = document.getElementById('editEnquiryModal');
    const editForm = document.getElementById('editEnquiryForm');
    
    window.closeEditEnquiryModal = function() {
        if (editModal) editModal.classList.remove('open');
        document.body.style.overflow = '';
    };

    document.querySelectorAll('.edit-enquiry-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('edit_enquiry_id').value = this.dataset.id;
            document.getElementById('edit_customer_name').value = this.dataset.customerName;
            document.getElementById('edit_mobile').value = this.dataset.mobile;
            document.getElementById('edit_email').value = this.dataset.email;
            document.getElementById('edit_service_type').value = this.dataset.serviceType;
            document.getElementById('edit_from_location').value = this.dataset.fromLocation;
            document.getElementById('edit_to_location').value = this.dataset.toLocation;
            document.getElementById('edit_travel_date').value = this.dataset.travelDate;
            document.getElementById('edit_passenger_count').value = this.dataset.passengerCount;
            document.getElementById('edit_priority').value = this.dataset.priority;
            document.getElementById('edit_next_follow_up_at').value = this.dataset.nextFollowUpAt;
            document.getElementById('edit_description').value = this.dataset.description;
            
            const assignedSelect = document.getElementById('edit_assigned_user');
            if (assignedSelect) {
                assignedSelect.value = this.dataset.assignedUser;
            }
            
            if (editModal) {
                editModal.classList.add('open');
                document.body.style.overflow = 'hidden';
                requestAnimationFrame(() => document.getElementById('edit_customer_name')?.focus());
            }
        });
    });

    if (editModal) {
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) {
                closeEditEnquiryModal();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && editModal?.classList.contains('open')) {
            closeEditEnquiryModal();
        }
    });
})();
</script>
</body>
</html>
