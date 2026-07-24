<?php

if (!isset($enquiry_is_admin_page)) {
    http_response_code(500);
    exit('Enquiry page configuration is missing.');
}

require_once __DIR__ . '/enquiry_workflow.php';
$csrf_token = enquiry_csrf_token();
$enquiry_bulk_delete_enabled = true;
$enquiry_return_to_dashboard = ($_GET['return_to'] ?? '') === 'dashboard';

$staff = [];
if ($enquiry_is_admin_page) {
    $staff_result = mysqli_query($db, "SELECT name FROM users WHERE role <> 'admin' ORDER BY name ASC");
    while ($staff_result && ($staff_row = mysqli_fetch_assoc($staff_result))) $staff[] = $staff_row['name'];
}

$edit_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_enquiry') {
    if (!enquiry_validate_csrf()) {
        $edit_error = 'Your session token expired. Refresh and try again.';
    } else {
        $delete_id = (int) ($_POST['enquiry_id'] ?? 0);
        $enquiry = enquiry_fetch($db, $delete_id, true);
        if ($enquiry) {
            mysqli_begin_transaction($db);
            try {
                $stmt = mysqli_prepare($db, 'DELETE FROM enquiries WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $delete_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                enquiry_log($db, "Enquiry #$delete_id deleted");
                mysqli_commit($db);
                header("Location: list.php?deleted=1");
                exit;
            } catch (Throwable $e) {
                mysqli_rollback($db);
                $edit_error = 'Error deleting enquiry: ' . $e->getMessage();
            }
        }
    }
}
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
$manual_scope = enquiry_manual_source_sql('e');
$owner_scope = enquiry_owner_sql($db, 'e');
$conditions = [$manual_scope, $owner_scope];

$search = trim((string) ($_GET['q'] ?? ''));
$service_filter = trim((string) ($_GET['service_type'] ?? ''));
$staff_filter = trim((string) ($_GET['assigned_staff'] ?? ''));
$priority_filter = trim((string) ($_GET['priority'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));
$follow_up_filter = trim((string) ($_GET['follow_up_date'] ?? ''));
$follow_up_bucket = trim((string) ($_GET['follow_up'] ?? ''));
$valid_follow_up_buckets = ['overdue', 'today', 'upcoming'];
if (!in_array($follow_up_bucket, $valid_follow_up_buckets, true)) {
    $follow_up_bucket = '';
}

if ($search !== '') {
    $term = mysqli_real_escape_string($db, $search);
    $conditions[] = "(e.customer_name LIKE '%$term%' OR e.mobile LIKE '%$term%' OR CAST(e.id AS CHAR) LIKE '%$term%')";
}
if (in_array($service_filter, enquiry_service_types(), true)) {
    $value = mysqli_real_escape_string($db, $service_filter);
    $conditions[] = "COALESCE(NULLIF(e.service_type, ''), e.subject) = '$value'";
}
if ($enquiry_is_admin_page && $staff_filter !== '') {
    $value = mysqli_real_escape_string($db, $staff_filter);
    $conditions[] = "e.assigned_user = '$value'";
}
if (in_array($priority_filter, enquiry_priorities(), true)) {
    $value = mysqli_real_escape_string($db, $priority_filter);
    $conditions[] = "e.priority = '$value'";
}
if (in_array($status_filter, enquiry_statuses(), true)) {
    $value = mysqli_real_escape_string($db, $status_filter);
    $conditions[] = "e.status = '$value'";
}
if (enquiry_parse_date($follow_up_filter) !== false && $follow_up_filter !== '') {
    $value = mysqli_real_escape_string($db, $follow_up_filter);
    $conditions[] = "DATE(e.next_follow_up_at) = '$value'";
}
if ($follow_up_bucket === 'overdue') {
    $conditions[] = "e.status IN ('New','Follow-up','Confirmed') AND (
        (e.next_follow_up_at IS NOT NULL AND DATE(e.next_follow_up_at) < CURDATE())
        OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) < CURDATE())
    )";
} elseif ($follow_up_bucket === 'today') {
    $conditions[] = "e.status IN ('New','Follow-up','Confirmed') AND (
        DATE(e.next_follow_up_at) = CURDATE()
        OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) = CURDATE())
    )";
} elseif ($follow_up_bucket === 'upcoming') {
    $conditions[] = "e.status IN ('New','Follow-up','Confirmed')
        AND e.next_follow_up_at IS NOT NULL
        AND DATE(e.next_follow_up_at) > CURDATE()";
}

$where_sql = implode(' AND ', $conditions);
$data_sql = "SELECT e.*,
    CASE
        WHEN e.status IN ('New','Follow-up','Confirmed') AND (
            (e.next_follow_up_at IS NOT NULL AND DATE(e.next_follow_up_at) < CURDATE())
            OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) < CURDATE())
        ) THEN 0
        WHEN e.status IN ('New','Follow-up','Confirmed') AND (
            DATE(e.next_follow_up_at) = CURDATE()
            OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) = CURDATE())
        ) THEN 1
        WHEN e.status IN ('New','Follow-up','Confirmed') AND e.priority = 'High' THEN 2
        ELSE 3
    END AS workflow_sort
    FROM enquiries e
    WHERE $where_sql
    ORDER BY workflow_sort ASC, e.updated_at DESC, e.id DESC";
$data = mysqli_query($db, $data_sql);

$summary_sql = "SELECT
    SUM(CASE WHEN e.status IN ('New','Follow-up','Confirmed') AND (
        (e.next_follow_up_at IS NOT NULL AND DATE(e.next_follow_up_at) < CURDATE())
        OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) < CURDATE())
    ) THEN 1 ELSE 0 END) AS overdue_count,
    SUM(CASE WHEN e.status IN ('New','Follow-up','Confirmed') AND (
        DATE(e.next_follow_up_at) = CURDATE()
        OR (e.status = 'Follow-up' AND e.next_follow_up_at IS NULL AND DATE(e.updated_at) = CURDATE())
    ) THEN 1 ELSE 0 END) AS today_count,
    SUM(CASE WHEN e.status IN ('New','Follow-up','Confirmed') AND DATE(e.next_follow_up_at) > CURDATE() THEN 1 ELSE 0 END) AS upcoming_count
    FROM enquiries e WHERE $manual_scope AND $owner_scope";
$summary_result = mysqli_query($db, $summary_sql);
$summary = $summary_result ? (mysqli_fetch_assoc($summary_result) ?: []) : [];

$staff = [];
if ($enquiry_is_admin_page) {
    $staff_result = mysqli_query($db, "SELECT name FROM users WHERE role <> 'admin' ORDER BY name ASC");
    while ($staff_result && ($staff_row = mysqli_fetch_assoc($staff_result))) {
        $staff[] = $staff_row['name'];
    }
}

function enquiry_list_date($value, $with_time = false) {
    if (empty($value)) return '—';
    $time = strtotime($value);
    return $time ? date($with_time ? 'd M Y, h:i A' : 'd M Y', $time) : '—';
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manual Enquiries | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem('sidebar-locked')==='true')document.documentElement.classList.add('sidebar-pref-locked');</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        /* Specific enquiry page styling extensions to complement style.css */
        .enq-page {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .crm-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .crm-metrics {
                grid-template-columns: 1fr;
            }
        }
        
        .enq-phone {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .crm-metric-link {
            display: block;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .crm-metric-link:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--metric-color) 35%, transparent);
            outline-offset: 3px;
        }

        .crm-metric-link.is-active {
            border-color: var(--metric-color);
            box-shadow: 0 10px 24px color-mix(in srgb, var(--metric-color) 16%, transparent);
        }

        .crm-metric-link.is-active::after {
            height: 5px;
        }
        
        .wa-shortcut {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: #25D366;
            color: #fff;
            text-decoration: none;
            flex: 0 0 auto;
            transition: background-color 0.2s, transform 0.1s;
        }
        
        .wa-shortcut:hover {
            background: #20ba5a;
            transform: scale(1.05);
        }
        
        .wa-shortcut svg {
            width: 14px;
            height: 14px;
        }
        
        /* Modal Popup Styles */
        .enq-modal-backdrop {
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
        
        .enq-modal-backdrop.open {
            display: flex;
        }
        
        .enq-modal-card {
            width: min(95vw, 1150px);
            border: 1px solid var(--border-dark);
            border-radius: 14px;
            background: var(--card-bg, #fff);
            box-shadow: 0 24px 60px rgba(15, 39, 71, .28);
            overflow: hidden;
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .enq-modal-header {
            padding: 18px 22px;
            background: #fff;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .enq-modal-header h2 {
            margin: 0;
            color: inherit;
            font-size: 15px;
            font-weight: 600;
        }
        
        .enq-modal-close {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .enq-modal-close:hover {
            opacity: 1;
        }
        
        .enq-modal-body {
            padding: 22px;
        }
        
        .enq-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }
        
        .enq-form label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-main, #1e293b);
            font-size: 12px;
            font-weight: 700;
        }
        
        .enq-form input,
        .enq-form select,
        .enq-form textarea {
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
        
        .enq-form input:focus,
        .enq-form select:focus,
        .enq-form textarea:focus {
            border-color: var(--accent-color);
            background: #fff;
            outline: none;
        }
        
        .enq-form .span-2 {
            grid-column: span 2;
        }
        
        .enq-form .span-3 {
            grid-column: span 3;
        }
        
        .enq-form .span-4 {
            grid-column: span 4;
        }
        
        .form-error {
            display: none;
            grid-column: span 4;
            padding: 10px 12px;
            border-radius: 7px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #fca5a5;
        }
        
        .enq-modal-actions {
            grid-column: span 4;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        
        @media (max-width: 992px) {
            .enq-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .enq-form .span-3,
            .enq-form .span-4,
            .form-error,
            .enq-modal-actions {
                grid-column: span 2;
            }
        }
        
        @media (max-width: 600px) {
            .enq-form {
                grid-template-columns: 1fr;
            }
            .enq-form .span-2,
            .enq-form .span-3,
            .enq-form .span-4,
            .form-error,
            .enq-modal-actions {
                grid-column: span 1;
            }
            .enq-modal-actions {
                flex-direction: column-reverse;
            }
            .enq-modal-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Badges matching original status logic but with cleaner appearance */
        .badge.status-new { background: #e0f2fe; color: #0369a1; }
        .badge.status-follow-up { background: #fef9c3; color: #854d0e; }
        .badge.status-confirmed { background: #e0e7ff; color: #4338ca; }
        .badge.status-converted { background: #dcfce7; color: #166534; }
        .badge.status-cancelled { background: #fee2e2; color: #991b1b; }
        .badge.status-closed { background: #f1f5f9; color: #475569; }
        
        .badge.priority-high { background: #fee2e2; color: #991b1b; font-weight: 700; }
        .badge.priority-medium { background: #fef9c3; color: #854d0e; font-weight: 600; }
        .badge.priority-low { background: #f0fdf4; color: #166534; font-weight: 500; }
        
        .overdue-badge {
            background: #ef4444;
            color: #fff;
            margin-left: 6px;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .bulk-select-column {
            display: none;
            width: 42px;
            text-align: center;
        }

        .enq-page.bulk-delete-mode .bulk-select-column {
            display: table-cell;
        }

        .bulk-select-column input {
            width: 17px;
            height: 17px;
            margin: 0;
            accent-color: #dc2626;
            cursor: pointer;
        }

        .bulk-delete-toolbar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding: 12px 14px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            background: #fff7f7;
        }

        .enq-page.bulk-delete-mode .bulk-delete-toolbar {
            display: flex;
        }

        .bulk-delete-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger {
            border-color: #dc2626;
            background: #dc2626;
            color: #fff;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-danger:disabled {
            cursor: not-allowed;
            opacity: .5;
        }

        tr.bulk-selected td {
            background: #fff7f7;
        }

        .enq-confirm-card {
            width: min(100%, 420px);
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 20px 50px rgba(15, 23, 42, .18);
        }

        .enq-confirm-icon {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            margin-bottom: 16px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            font-size: 20px;
            font-weight: 800;
        }

        .enq-confirm-card h2 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 18px;
        }

        .enq-confirm-card p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.55;
        }

        .enq-confirm-error {
            display: none;
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fff1f2;
            color: #991b1b;
            font-size: 13px;
        }

        .enq-confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }

        @media (max-width: 600px) {
            .bulk-delete-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .bulk-delete-toolbar-actions {
                width: 100%;
            }

            .bulk-delete-toolbar-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }
        
        .enq-empty {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
            font-size: 14px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main">
<div class="enq-page">
    <div class="dashboard-title-row">
        <div>
            <h1>Manual Enquiries</h1>
        </div>
        <div class="d-flex gap-2">
            <button class="btn" type="button" id="openAddEnquiry">+ Add Enquiry</button>
            <?php if ($enquiry_bulk_delete_enabled): ?>
                <button class="btn btn-secondary" type="button" id="toggleBulkDelete">Delete</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($edit_error)): ?>
        <div class="booking-alert is-error" role="alert">
            <?= htmlspecialchars($edit_error) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="booking-alert is-success" role="alert">
            <?= htmlspecialchars($_GET['success'] === 'edited' ? 'Enquiry updated successfully.' : 'Enquiry saved successfully.') ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="booking-alert is-success" role="alert">
            <?= (int) $_GET['deleted'] === 1 ? '1 enquiry deleted successfully.' : (int) $_GET['deleted'] . ' enquiries deleted successfully.' ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['email_import'])): ?>
        <div class="booking-alert is-success" role="alert">
            <?= (int)$_GET['email_import'] ?> unread emails successfully imported as new enquiries.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['whatsapp_error'])): ?>
        <div class="booking-alert is-error" role="alert">
            <?= htmlspecialchars($_GET['whatsapp_error']) ?>
        </div>
    <?php endif; ?>

    <section class="crm-metrics" aria-label="Follow-up reminders">
        <a class="crm-metric-card crm-metric-link <?= $follow_up_bucket === 'overdue' ? 'is-active' : '' ?>"
           href="<?= $follow_up_bucket === 'overdue' ? 'list.php' : 'list.php?follow_up=overdue' ?>"
           <?= $follow_up_bucket === 'overdue' ? 'aria-current="page"' : '' ?>
           aria-label="Show overdue follow-up enquiries"
           style="--metric-color: #dc2626; --metric-tint: #fee2e2;">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap" style="color: #dc2626; background: #fee2e2;">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="fill: none; stroke: currentColor; stroke-width: 2px;"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="crm-metric-kicker" style="font-weight: 600; color: var(--text-secondary);">Overdue Follow-ups</span>
            </div>
            <strong style="font-size: 26px; color: var(--text-primary);"><?= (int) ($summary['overdue_count'] ?? 0) ?></strong>
        </a>
        <a class="crm-metric-card crm-metric-link <?= $follow_up_bucket === 'today' ? 'is-active' : '' ?>"
           href="<?= $follow_up_bucket === 'today' ? 'list.php' : 'list.php?follow_up=today' ?>"
           <?= $follow_up_bucket === 'today' ? 'aria-current="page"' : '' ?>
           aria-label="Show today's follow-up enquiries"
           style="--metric-color: #d97706; --metric-tint: #fef3c7;">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap" style="color: #d97706; background: #fef3c7;">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="fill: none; stroke: currentColor; stroke-width: 2px;"><path d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="crm-metric-kicker" style="font-weight: 600; color: var(--text-secondary);">Today's Follow-ups</span>
            </div>
            <strong style="font-size: 26px; color: var(--text-primary);"><?= (int) ($summary['today_count'] ?? 0) ?></strong>
        </a>
        <a class="crm-metric-card crm-metric-link <?= $follow_up_bucket === 'upcoming' ? 'is-active' : '' ?>"
           href="<?= $follow_up_bucket === 'upcoming' ? 'list.php' : 'list.php?follow_up=upcoming' ?>"
           <?= $follow_up_bucket === 'upcoming' ? 'aria-current="page"' : '' ?>
           aria-label="Show upcoming follow-up enquiries"
           style="--metric-color: #2563eb; --metric-tint: #e0f2fe;">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap" style="color: #2563eb; background: #e0f2fe;">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="fill: none; stroke: currentColor; stroke-width: 2px;"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="crm-metric-kicker" style="font-weight: 600; color: var(--text-secondary);">Upcoming Follow-ups</span>
            </div>
            <strong style="font-size: 26px; color: var(--text-primary);"><?= (int) ($summary['upcoming_count'] ?? 0) ?></strong>
        </a>
    </section>

    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="Search manual enquiries by customer, phone, staff, service, priority...">
    </div>

    <!-- Filters Card -->
    <div class="card" style="margin-bottom: 20px; padding: 20px;">
        <form method="get" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
            <?php if ($follow_up_bucket !== ''): ?>
                <input type="hidden" name="follow_up" value="<?= htmlspecialchars($follow_up_bucket) ?>">
            <?php endif; ?>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Service Type</label>
                <select name="service_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main); font-size: 13px;">
                    <option value="">All services</option>
                    <?php foreach (enquiry_service_types() as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $service_filter === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($enquiry_is_admin_page): ?>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Assigned Staff</label>
                <select name="assigned_staff" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main); font-size: 13px;">
                    <option value="">All staff</option>
                    <?php foreach ($staff as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $staff_filter === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Priority</label>
                <select name="priority" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main); font-size: 13px;">
                    <option value="">All priorities</option>
                    <?php foreach (enquiry_priorities() as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $priority_filter === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Status</label>
                <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main); font-size: 13px;">
                    <option value="">All statuses</option>
                    <?php foreach (enquiry_statuses() as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $status_filter === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Follow-up Date</label>
                <input type="date" name="follow_up_date" value="<?= htmlspecialchars($follow_up_filter) ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main); font-size: 13px;" title="Follow-up date">
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn" type="submit" style="padding: 10px 20px; height: 38px; cursor: pointer;">Search</button>
                <a class="btn btn-secondary" href="list.php" style="padding: 10px 20px; height: 38px; display: inline-flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.1); border: 1px solid var(--border-dark); color: var(--text-main);">Clear</a>
            </div>
        </form>
    </div>

    <?php if ($enquiry_bulk_delete_enabled): ?>
        <div class="bulk-delete-toolbar" id="bulkDeleteToolbar" role="region" aria-label="Bulk enquiry deletion">
            <strong id="bulkSelectedCount">0 enquiries selected</strong>
            <div class="bulk-delete-toolbar-actions">
                <button class="btn btn-secondary" type="button" id="cancelBulkDelete">Cancel</button>
                <button class="btn btn-danger" type="button" id="deleteSelectedEnquiries" disabled>Delete Selected</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table style="width: 100%;">
            <thead>
                <tr>
                    <?php if ($enquiry_bulk_delete_enabled): ?>
                        <th class="bulk-select-column">
                            <input type="checkbox" id="selectAllEnquiries" aria-label="Select all visible enquiries">
                        </th>
                    <?php endif; ?>
                    <th style="width: 70px;">ID</th>
                    <th>Customer</th>
                    <th>Phone / WhatsApp</th>
                    <th>Service</th>
                    <th>Travel / Service Date</th>
                    <th>Next Follow-up</th>
                    <th>Assigned Staff</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th style="width: 100px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($data && mysqli_num_rows($data)): while ($row = mysqli_fetch_assoc($data)):
                $row['status'] = enquiry_normalize_status($row['status'] ?? '', $row['booking_id'] ?? null);
                $row['service_type'] = enquiry_extract_service_type($row);
                $scheduled_overdue = !empty($row['next_follow_up_at']) && date('Y-m-d', strtotime($row['next_follow_up_at'])) < date('Y-m-d');
                $unscheduled_overdue = $row['status'] === 'Follow-up'
                    && empty($row['next_follow_up_at'])
                    && !empty($row['updated_at'])
                    && date('Y-m-d', strtotime($row['updated_at'])) < date('Y-m-d');
                $overdue = in_array($row['status'], enquiry_active_statuses(), true) && ($scheduled_overdue || $unscheduled_overdue);
                $wa_url = enquiry_whatsapp_url($row['mobile'] ?? '', $row['customer_name'] ?? '', $row['service_type']);
            ?>
                <tr style="<?= $overdue ? 'border-left: 4px solid #ef4444;' : '' ?>">
                    <?php if ($enquiry_bulk_delete_enabled): ?>
                        <td class="bulk-select-column">
                            <input type="checkbox" class="enquiry-select" value="<?= (int) $row['id'] ?>" aria-label="Select enquiry #<?= (int) $row['id'] ?>">
                        </td>
                    <?php endif; ?>
                    <td style="font-family: monospace; font-weight: 600;">#<?= (int) $row['id'] ?></td>
                    <td>
                        <a href="view.php?id=<?= (int) $row['id'] ?>" style="font-weight: 600; color: var(--accent-color); text-decoration: none;">
                            <?= htmlspecialchars($row['customer_name']) ?>
                        </a>
                    </td>
                    <td>
                        <div class="enq-phone">
                            <span style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($row['mobile'] ?: '—') ?></span>
                            <?php if ($wa_url): ?>
                                <a class="wa-shortcut" href="<?= htmlspecialchars($wa_url) ?>" target="_blank" rel="noopener noreferrer" title="Open WhatsApp" aria-label="Open WhatsApp for <?= htmlspecialchars($row['customer_name']) ?>" onclick="event.stopPropagation()"><?= enquiry_list_wa_icon() ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($row['service_type']) ?></td>
                    <td><?= enquiry_list_date($row['travel_date']) ?></td>
                    <td>
                        <span style="font-weight: 500;">
                            <?= $row['status'] === 'Follow-up' && empty($row['next_follow_up_at'])
                                ? 'Not scheduled'
                                : enquiry_list_date($row['next_follow_up_at'], true) ?>
                        </span>
                        <?php if ($overdue): ?>
                            <span class="overdue-badge">Overdue</span>
                        <?php endif; ?>
                    </td>
                    <td style="color: var(--text-secondary);"><?= htmlspecialchars($row['assigned_user'] ?: 'Unassigned') ?></td>
                    <td>
                        <span class="badge priority-<?= strtolower($row['priority'] ?: 'medium') ?>">
                            <?= htmlspecialchars($row['priority'] ?: 'Medium') ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= enquiry_status_badge_class($row['status']) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 6px; justify-content: flex-end;">
                            <a class="btn btn-secondary" href="view.php?id=<?= (int) $row['id'] ?>" style="padding: 5px 10px; font-size: 11px; border-radius: 6px;">View</a>
                            <?php if (enquiry_can_edit($row['status'])): ?>
                                <button type="button" class="btn btn-secondary edit-enquiry-btn" 
                                        data-id="<?= (int) $row['id'] ?>" 
                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>" 
                                        data-mobile="<?= htmlspecialchars($row['mobile'] ?? '') ?>" 
                                        data-email="<?= htmlspecialchars($row['email'] ?? '') ?>" 
                                        data-service-type="<?= htmlspecialchars($row['service_type'] ?? '') ?>" 
                                        data-from-location="<?= htmlspecialchars($row['from_location'] ?? '') ?>" 
                                        data-to-location="<?= htmlspecialchars($row['to_location'] ?? '') ?>" 
                                        data-travel-date="<?= htmlspecialchars($row['travel_date'] ?? '') ?>" 
                                        data-passenger-count="<?= (int)($row['passenger_count'] ?? 1) ?>" 
                                        data-priority="<?= htmlspecialchars($row['priority'] ?? 'Medium') ?>" 
                                        data-next-follow-up-at="<?= htmlspecialchars($row['next_follow_up_at'] ? date('Y-m-d', strtotime($row['next_follow_up_at'])) : '') ?>" 
                                        data-description="<?= htmlspecialchars($row['description'] ?? '') ?>" 
                                        data-assigned-user="<?= htmlspecialchars($row['assigned_user'] ?? '') ?>"
                                        style="padding: 5px 10px; font-size: 11px; border-radius: 6px;">Edit</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="<?= $enquiry_bulk_delete_enabled ? 11 : 10 ?>" class="enq-empty">
                        No manual enquiries match these filters.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</main>

<?php if ($enquiry_bulk_delete_enabled): ?>
<div class="enq-modal-backdrop" id="bulkDeleteConfirm" role="dialog" aria-modal="true" aria-labelledby="bulkDeleteConfirmTitle">
    <div class="enq-confirm-card">
        <div class="enq-confirm-icon" aria-hidden="true">!</div>
        <h2 id="bulkDeleteConfirmTitle">Delete selected enquiries?</h2>
        <p id="bulkDeleteConfirmMessage">The selected enquiries will be permanently deleted. This action cannot be undone.</p>
        <div class="enq-confirm-error" id="bulkDeleteConfirmError" role="alert"></div>
        <div class="enq-confirm-actions">
            <button class="btn btn-secondary" type="button" id="cancelBulkDeleteConfirm">Cancel</button>
            <button class="btn btn-danger" type="button" id="confirmBulkDelete">Delete</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="enq-modal-backdrop" id="addEnquiryModal" role="dialog" aria-modal="true" aria-labelledby="addEnquiryTitle">
    <div class="enq-modal-card">
        <div class="enq-modal-header">
            <h2 id="addEnquiryTitle">Add Manual Enquiry</h2>
            <button type="button" class="enq-modal-close" id="closeAddEnquiry" aria-label="Close">&times;</button>
        </div>
        <form class="enq-modal-body" id="addEnquiryForm" method="post" action="ajax_add.php">
            <div class="enq-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div>
                    <label>Customer Name *</label>
                    <input name="customer_name" required maxlength="255" placeholder="e.g. John Doe">
                </div>
                <div>
                    <label>Phone Number *</label>
                    <input name="mobile" required maxlength="50" inputmode="tel" placeholder="e.g. +91 98765 43210">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" maxlength="255" placeholder="e.g. john@example.com">
                </div>
                <div>
                    <label id="lbl_service_type">Service Type *</label>
                    <select name="service_type" id="enq_service_type" required>
                        <option value="" disabled selected>Select Service Type</option>
                        <?php foreach (enquiry_service_types() as $item): ?>
                            <option value="<?= htmlspecialchars($item) ?>"><?= htmlspecialchars($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="container_from_location" style="display: none;">
                    <label id="lbl_from_location">From</label>
                    <input name="from_location" id="enq_from_location" maxlength="150" placeholder="Origin City">
                </div>
                <div id="container_to_location" style="display: none;">
                    <label id="lbl_to_location">To</label>
                    <input name="to_location" id="enq_to_location" maxlength="150" placeholder="Destination City">
                </div>
                <div id="container_travel_date" style="display: none;">
                    <label id="lbl_travel_date">Travel / Service Date</label>
                    <input type="date" name="travel_date" id="enq_travel_date">
                </div>
                <div id="container_checkout_date" style="display: none;">
                    <label id="lbl_checkout_date">Check-out Date</label>
                    <input type="date" name="checkout_date" id="enq_checkout_date">
                </div>
                <div id="container_room_count" style="display: none;">
                    <label id="lbl_room_count">Number of Rooms</label>
                    <input type="number" name="room_count" id="enq_room_count" min="1" max="99" value="1">
                </div>
                <div id="container_passenger_count" style="display: none;">
                    <label id="lbl_passenger_count">Number of Passengers</label>
                    <input type="number" name="passenger_count" id="enq_passenger_count" min="1" max="999" value="1">
                </div>
                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach (enquiry_priorities() as $item): ?>
                            <option value="<?= htmlspecialchars($item) ?>" <?= $item === 'Medium' ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Next Follow-up</label>
                    <input type="datetime-local" name="next_follow_up_at">
                </div>
                <div>
                    <label>Assigned Staff</label>
                    <?php if ($enquiry_is_admin_page): ?>
                        <select name="assigned_user">
                            <option value="">Unassigned</option>
                            <?php foreach ($staff as $item): ?>
                                <option value="<?= htmlspecialchars($item) ?>"><?= htmlspecialchars($item) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input value="<?= htmlspecialchars(enquiry_current_user_name()) ?>" readonly>
                        <input type="hidden" name="assigned_user" value="<?= htmlspecialchars(enquiry_current_user_name()) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label>Status</label>
                    <input value="New" readonly aria-label="Status New">
                </div>
                <div class="span-4">
                    <label>Customer Requirement / Message *</label>
                    <textarea name="description" rows="4" required maxlength="10000" placeholder="Describe travel details, preferred budget, hotel class..."></textarea>
                </div>
                <div class="form-error" id="addEnquiryError" role="alert"></div>
                <div class="enq-modal-actions">
                    <button class="btn btn-secondary" type="button" id="cancelAddEnquiry">Cancel</button>
                    <button class="btn" type="submit" id="saveEnquiry">Save Enquiry</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('addEnquiryModal');
    const form = document.getElementById('addEnquiryForm');
    const save = document.getElementById('saveEnquiry');
    const error = document.getElementById('addEnquiryError');
    const returnToDashboard = <?= $enquiry_return_to_dashboard ? 'true' : 'false' ?>;
    const openedFromDashboard = returnToDashboard && new URLSearchParams(window.location.search).get('action') === 'add';
    const open = () => { modal.classList.add('open'); document.body.style.overflow='hidden'; form.customer_name.focus(); };
    const close = (followReturnDestination = true) => {
        if (followReturnDestination !== false && openedFromDashboard) {
            window.location.href = '../dashboard.php';
            return;
        }
        modal.classList.remove('open');
        document.body.style.overflow='';
        error.style.display='none';
    };
    document.getElementById('openAddEnquiry').addEventListener('click', open);
    document.getElementById('closeAddEnquiry').addEventListener('click', close);
    document.getElementById('cancelAddEnquiry').addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const enquiryPage = document.querySelector('.enq-page');
    const toggleBulkDelete = document.getElementById('toggleBulkDelete');
    const cancelBulkDelete = document.getElementById('cancelBulkDelete');
    const deleteSelected = document.getElementById('deleteSelectedEnquiries');
    const selectAll = document.getElementById('selectAllEnquiries');
    const selectedCount = document.getElementById('bulkSelectedCount');
    const enquiryCheckboxes = Array.from(document.querySelectorAll('.enquiry-select'));
    const bulkDeleteConfirm = document.getElementById('bulkDeleteConfirm');
    const bulkDeleteConfirmMessage = document.getElementById('bulkDeleteConfirmMessage');
    const bulkDeleteConfirmError = document.getElementById('bulkDeleteConfirmError');
    const cancelBulkDeleteConfirm = document.getElementById('cancelBulkDeleteConfirm');
    const confirmBulkDelete = document.getElementById('confirmBulkDelete');
    let pendingDeleteIds = [];

    const visibleEnquiryCheckboxes = () => enquiryCheckboxes.filter(checkbox => checkbox.closest('tr').style.display !== 'none');
    const refreshBulkSelection = () => {
        const visible = visibleEnquiryCheckboxes();
        const selected = enquiryCheckboxes.filter(checkbox => checkbox.checked);
        enquiryCheckboxes.forEach(checkbox => {
            checkbox.closest('tr').classList.toggle('bulk-selected', checkbox.checked);
        });
        if (selectedCount) {
            selectedCount.textContent = selected.length === 1 ? '1 enquiry selected' : selected.length + ' enquiries selected';
        }
        if (deleteSelected) deleteSelected.disabled = selected.length === 0;
        if (selectAll) {
            const visibleSelected = visible.filter(checkbox => checkbox.checked).length;
            selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
            selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
        }
    };
    const closeBulkDeleteConfirm = () => {
        if (!bulkDeleteConfirm) return;
        bulkDeleteConfirm.classList.remove('open');
        document.body.style.overflow = '';
        pendingDeleteIds = [];
        if (bulkDeleteConfirmError) {
            bulkDeleteConfirmError.textContent = '';
            bulkDeleteConfirmError.style.display = 'none';
        }
    };
    const closeBulkDeleteMode = () => {
        if (!enquiryPage) return;
        enquiryPage.classList.remove('bulk-delete-mode');
        enquiryCheckboxes.forEach(checkbox => { checkbox.checked = false; });
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        if (toggleBulkDelete) toggleBulkDelete.textContent = 'Delete';
        refreshBulkSelection();
    };

    if (toggleBulkDelete && enquiryPage) {
        toggleBulkDelete.addEventListener('click', () => {
            if (enquiryPage.classList.contains('bulk-delete-mode')) {
                closeBulkDeleteMode();
                return;
            }
            enquiryPage.classList.add('bulk-delete-mode');
            toggleBulkDelete.textContent = 'Cancel Delete';
            refreshBulkSelection();
        });
    }
    if (cancelBulkDelete) cancelBulkDelete.addEventListener('click', closeBulkDeleteMode);
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            visibleEnquiryCheckboxes().forEach(checkbox => { checkbox.checked = selectAll.checked; });
            refreshBulkSelection();
        });
    }
    enquiryCheckboxes.forEach(checkbox => checkbox.addEventListener('change', refreshBulkSelection));
    if (deleteSelected) {
        deleteSelected.addEventListener('click', () => {
            pendingDeleteIds = enquiryCheckboxes.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
            if (!pendingDeleteIds.length || !bulkDeleteConfirm) return;
            if (bulkDeleteConfirmMessage) {
                bulkDeleteConfirmMessage.textContent = pendingDeleteIds.length === 1
                    ? 'This enquiry will be permanently deleted. This action cannot be undone.'
                    : pendingDeleteIds.length + ' enquiries will be permanently deleted. This action cannot be undone.';
            }
            bulkDeleteConfirm.classList.add('open');
            document.body.style.overflow = 'hidden';
            if (confirmBulkDelete) confirmBulkDelete.focus();
        });
    }
    if (cancelBulkDeleteConfirm) cancelBulkDeleteConfirm.addEventListener('click', closeBulkDeleteConfirm);
    if (bulkDeleteConfirm) {
        bulkDeleteConfirm.addEventListener('click', event => {
            if (event.target === bulkDeleteConfirm) closeBulkDeleteConfirm();
        });
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && bulkDeleteConfirm?.classList.contains('open')) {
            closeBulkDeleteConfirm();
        }
    });
    if (confirmBulkDelete) {
        confirmBulkDelete.addEventListener('click', async () => {
            const ids = pendingDeleteIds.slice();
            if (!ids.length) return;
            const originalText = confirmBulkDelete.textContent;
            confirmBulkDelete.disabled = true;
            confirmBulkDelete.textContent = 'Deleting...';
            if (cancelBulkDeleteConfirm) cancelBulkDeleteConfirm.disabled = true;
            const payload = new FormData();
            payload.append('csrf_token', <?= json_encode($csrf_token) ?>);
            ids.forEach(id => payload.append('ids[]', id));

            try {
                const response = await fetch('bulk_delete.php', {
                    method: 'POST',
                    body: payload,
                    headers: {'Accept': 'application/json'}
                });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result || !result.success) {
                    throw new Error(result?.message || 'The selected enquiries could not be deleted.');
                }
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.set('deleted', String(result.deleted_count || ids.length));
                window.location.href = nextUrl.toString();
            } catch (bulkDeleteError) {
                if (bulkDeleteConfirmError) {
                    bulkDeleteConfirmError.textContent = bulkDeleteError.message;
                    bulkDeleteConfirmError.style.display = 'block';
                }
                confirmBulkDelete.disabled = false;
                confirmBulkDelete.textContent = originalText;
                if (cancelBulkDeleteConfirm) cancelBulkDeleteConfirm.disabled = false;
            }
        });
    }
    
    // Local client-side table filter search bar
    const searchInput = document.querySelector('.search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchVal = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
                const text = row.textContent.toLowerCase();
                if (text.includes(searchVal)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    const checkbox = row.querySelector('.enquiry-select');
                    if (checkbox) checkbox.checked = false;
                }
            });
            refreshBulkSelection();
        });
    }

    // Dynamic fields logic
    const serviceSelect = document.getElementById('enq_service_type');
    const containerFrom = document.getElementById('container_from_location');
    const containerTo = document.getElementById('container_to_location');
    const containerTravel = document.getElementById('container_travel_date');
    const containerPassenger = document.getElementById('container_passenger_count');
    const containerCheckout = document.getElementById('container_checkout_date');
    const containerRooms = document.getElementById('container_room_count');
    
    const labelFrom = document.getElementById('lbl_from_location');
    const labelTo = document.getElementById('lbl_to_location');
    const labelTravel = document.getElementById('lbl_travel_date');
    const labelPassenger = document.getElementById('lbl_passenger_count');
    
    const inputFrom = document.getElementById('enq_from_location');
    const inputTo = document.getElementById('enq_to_location');
    
    const updateFormFields = () => {
        const val = serviceSelect.value;
        if (!val) {
            containerFrom.style.display = 'none';
            containerTo.style.display = 'none';
            containerTravel.style.display = 'none';
            containerPassenger.style.display = 'none';
            containerCheckout.style.display = 'none';
            containerRooms.style.display = 'none';
            return;
        }
        
        // Defaults
        containerFrom.style.display = '';
        containerTo.style.display = '';
        containerTravel.style.display = '';
        containerPassenger.style.display = '';
        containerCheckout.style.display = 'none';
        containerRooms.style.display = 'none';
        
        labelFrom.textContent = 'From';
        labelTo.textContent = 'To';
        labelTravel.textContent = 'Travel / Service Date';
        labelPassenger.textContent = 'Number of Passengers';
        
        inputFrom.placeholder = 'Origin City';
        inputTo.placeholder = 'Destination City';
        
        if (val === 'Flight') {
            labelFrom.textContent = 'From (Origin)';
            labelTo.textContent = 'To (Destination)';
            labelTravel.textContent = 'Departure Date';
            labelPassenger.textContent = 'Number of Passengers';
        } else if (val === 'Hotel') {
            containerFrom.style.display = 'none';
            labelTo.textContent = 'Hotel Destination / City';
            labelTravel.textContent = 'Check-in Date';
            labelPassenger.textContent = 'Number of Guests';
            containerCheckout.style.display = '';
            containerRooms.style.display = '';
            document.getElementById('lbl_checkout_date').textContent = 'Check-out Date';
            document.getElementById('lbl_room_count').textContent = 'Number of Rooms';
            inputTo.placeholder = 'City or Hotel Name';
        } else if (val === 'Visa') {
            containerFrom.style.display = 'none';
            labelTo.textContent = 'Country';
            labelTravel.textContent = 'Expected Travel Date';
            labelPassenger.textContent = 'Number of Applicants';
            inputTo.placeholder = 'Country Name';
        } else if (val === 'Passport') {
            containerFrom.style.display = 'none';
            labelTo.textContent = 'Passport Service / Type';
            labelTravel.textContent = 'Desired Appointment Date';
            labelPassenger.textContent = 'Number of Applicants';
            inputTo.placeholder = 'e.g. Fresh, Re-issue, Tatkaal';
        } else if (val === 'Insurance') {
            containerFrom.style.display = 'none';
            labelTo.textContent = 'Destination Country';
            labelTravel.textContent = 'Policy Start Date';
            labelPassenger.textContent = 'Number of Insured Persons';
            containerCheckout.style.display = '';
            document.getElementById('lbl_checkout_date').textContent = 'Policy End Date';
            inputTo.placeholder = 'e.g. Europe, USA, Thailand';
        } else if (val === 'Tour Package') {
            containerFrom.style.display = 'none';
            labelTo.textContent = 'Destination City/Country';
            labelTravel.textContent = 'Departure Date';
            labelPassenger.textContent = 'Number of Travelers';
            containerRooms.style.display = '';
            document.getElementById('lbl_room_count').textContent = 'Duration (Nights)';
            inputTo.placeholder = 'e.g. Bali 5 Nights, Swiss Alps';
        } else if (val === 'Transport') {
            labelFrom.textContent = 'Pick-up Location';
            labelTo.textContent = 'Drop-off Location';
            labelTravel.textContent = 'Service Date & Time';
            labelPassenger.textContent = 'Number of Passengers';
            inputFrom.placeholder = 'Pick-up Address/City';
            inputTo.placeholder = 'Drop-off Address/City';
        }
    };
    
    if (serviceSelect) {
        serviceSelect.addEventListener('change', updateFormFields);
        updateFormFields();
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (save.disabled) return;
        
        // Append dynamic fields to description for storage
        const serviceType = serviceSelect ? serviceSelect.value : '';
        let finalDescription = form.description.value;
        if (serviceType === 'Hotel') {
            const checkout = document.getElementById('enq_checkout_date') ? document.getElementById('enq_checkout_date').value : '';
            const rooms = document.getElementById('enq_room_count') ? document.getElementById('enq_room_count').value : '';
            if (checkout || rooms) {
                finalDescription += "\n\n[Hotel Info]" + 
                                    (checkout ? "\nCheck-out Date: " + checkout : "") + 
                                    (rooms ? "\nNumber of Rooms: " + rooms : "");
            }
        } else if (serviceType === 'Insurance') {
            const checkout = document.getElementById('enq_checkout_date') ? document.getElementById('enq_checkout_date').value : '';
            if (checkout) {
                finalDescription += "\n\n[Insurance Info]" + 
                                    "\nPolicy End Date: " + checkout;
            }
        } else if (serviceType === 'Tour Package') {
            const rooms = document.getElementById('enq_room_count') ? document.getElementById('enq_room_count').value : '';
            if (rooms) {
                finalDescription += "\n\n[Tour Package Info]" + 
                                    "\nDuration (Nights): " + rooms;
            }
        }
        
        const formData = new FormData(form);
        formData.set('description', finalDescription);
        
        save.disabled = true; save.textContent = 'Saving…'; error.style.display='none';
        try {
            const response = await fetch(form.action, {method:'POST', body:formData, headers:{'Accept':'application/json'}});
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.success) throw new Error(data?.message || 'The enquiry could not be saved.');
            form.reset();
            close(false);
            if (openedFromDashboard) {
                window.location.href = '../dashboard.php?success=enquiry_added';
            } else {
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.set('success', '1');
                window.location.href = nextUrl.toString();
            }
        } catch (err) {
            error.textContent = err.message; error.style.display='block';
        } finally {
            save.disabled = false; save.textContent = 'Save Enquiry';
        }
    });
    if (new URLSearchParams(window.location.search).get('action') === 'add') open();
})();
</script>

<!-- Edit Enquiry Modal -->
<div class="enq-modal-backdrop" id="editEnquiryModal">
    <div class="enq-modal-card" style="width: min(95vw, 1150px); overflow: hidden;">
        <div class="enq-modal-header">
            <h2>✏️ Edit Enquiry</h2>
            <button class="enq-modal-close" type="button" onclick="closeEditEnquiryModal()">&times;</button>
        </div>
        <div class="enq-modal-body" style="padding: 22px;">
            <form method="POST" id="editEnquiryForm" autocomplete="off" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
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
            
            if (editModal) editModal.classList.add('open');
        });
    });

    if (editModal) {
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) {
                closeEditEnquiryModal();
            }
        });
    }
})();
</script>

<!-- Single Delete Enquiry Modal -->
<div class="enq-modal-backdrop" id="singleDeleteModal">
    <div class="enq-modal-card" style="width: min(95vw, 520px); overflow: hidden;">
        <div class="enq-modal-header" style="background: #dc2626; color: #fff;">
            <h2 style="color: #fff; margin: 0; font-size: 18px;">🗑️ Delete Enquiry</h2>
            <button class="enq-modal-close" type="button" onclick="closeSingleDeleteModal()" style="color: #fff;">&times;</button>
        </div>
        <div class="enq-modal-body" style="padding: 24px;">
            <form method="POST" action="">
                <input type="hidden" name="enquiry_id" id="delete_enquiry_id">
                <input type="hidden" name="action" value="delete_enquiry">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <p style="font-size: 15px; color: var(--text-main); margin-bottom: 20px; line-height: 1.5;">
                    Are you sure you want to delete enquiry <strong id="delete_enquiry_label">#0</strong>? This action cannot be undone.
                </p>

                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-secondary" onclick="closeSingleDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background: #dc2626; border-color: #dc2626; color: #fff;">Yes, Delete Enquiry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    const singleDeleteModal = document.getElementById('singleDeleteModal');
    
    window.closeSingleDeleteModal = function() {
        if (singleDeleteModal) singleDeleteModal.classList.remove('open');
    };

    document.querySelectorAll('.delete-enquiry-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('delete_enquiry_id').value = this.dataset.id;
            document.getElementById('delete_enquiry_label').textContent = '#' + this.dataset.id + (this.dataset.name ? ' (' + this.dataset.name + ')' : '');
            if (singleDeleteModal) singleDeleteModal.classList.add('open');
        });
    });

    if (singleDeleteModal) {
        singleDeleteModal.addEventListener('click', function(e) {
            if (e.target === singleDeleteModal) {
                closeSingleDeleteModal();
            }
        });
    }
})();
</script>
</body>
</html>
