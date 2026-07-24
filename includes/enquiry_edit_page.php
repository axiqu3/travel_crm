<?php

if (!isset($enquiry_is_admin_page)) {
    http_response_code(500);
    exit('Enquiry page configuration is missing.');
}
require_once __DIR__ . '/enquiry_workflow.php';
$csrf_token = enquiry_csrf_token();
$enquiry_id = (int) ($_GET['id'] ?? $_POST['enquiry_id'] ?? 0);
$enquiry = enquiry_fetch($db, $enquiry_id, true);
if (!$enquiry) {
    http_response_code(404);
    exit('Enquiry not found or access denied.');
}
if (!enquiry_can_edit($enquiry['status'])) {
    enquiry_redirect_path('view.php?id=' . $enquiry_id . '&error=' . rawurlencode('This enquiry can no longer be edited.'));
}

$staff = [];
if ($enquiry_is_admin_page) {
    $staff_result = mysqli_query($db, "SELECT name FROM users WHERE role <> 'admin' ORDER BY name ASC");
    while ($staff_result && ($staff_row = mysqli_fetch_assoc($staff_result))) $staff[] = $staff_row['name'];
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!enquiry_validate_csrf()) {
        $error = 'Your session token expired. Refresh and try again.';
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
            $error = implode(' ', $errors);
        } else {
            $subject = $service_type;
            $travel_date = $travel_date ?: null;
            $next_follow_up_at = $next_follow_up_at ?: null;
            mysqli_begin_transaction($db);
            try {
                $locked = enquiry_fetch($db, $enquiry_id, true, true);
                if (!$locked || !enquiry_can_edit($locked['status'])) throw new RuntimeException('This enquiry can no longer be edited.');
                $stmt = mysqli_prepare($db, 'UPDATE enquiries SET customer_name=?, mobile=?, email=?, subject=?, description=?, service_type=?, from_location=?, to_location=?, travel_date=?, passenger_count=?, priority=?, next_follow_up_at=?, assigned_user=?, updated_at=NOW() WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'sssssssssisssi', $customer_name, $mobile, $email, $subject, $description, $service_type, $from_location, $to_location, $travel_date, $passenger_count, $priority, $next_follow_up_at, $assigned_user, $enquiry_id);
                if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('The enquiry could not be updated.');
                mysqli_stmt_close($stmt);
                if (!enquiry_log($db, "Enquiry #$enquiry_id edited")) throw new RuntimeException('The activity entry could not be saved.');
                mysqli_commit($db);
                enquiry_redirect_path('view.php?id=' . $enquiry_id . '&success=edited');
            } catch (Throwable $exception) {
                mysqli_rollback($db);
                $error = $exception->getMessage();
            }
        }
        $enquiry = array_merge($enquiry, compact('customer_name','mobile','email','service_type','from_location','to_location','travel_date','passenger_count','priority','next_follow_up_at','description','assigned_user'));
    }
}
$description_value = isset($enquiry['description']) ? enquiry_clean_description($enquiry['description']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Enquiry #<?= $enquiry_id ?> | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem('sidebar-locked')==='true')document.documentElement.classList.add('sidebar-pref-locked');</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .ee-page {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .ee-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }
        
        .ee-form label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-main, #1e293b);
            font-size: 12px;
            font-weight: 700;
        }
        
        .ee-form input,
        .ee-form select,
        .ee-form textarea {
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
        
        .ee-form input:focus,
        .ee-form select:focus,
        .ee-form textarea:focus {
            border-color: var(--accent-color);
            background: #fff;
            outline: none;
        }
        
        .ee-form .span-3 {
            grid-column: span 3;
        }
        
        .ee-actions {
            grid-column: span 3;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        
        .ee-error {
            grid-column: span 3;
            padding: 10px 12px;
            border-radius: 7px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #fca5a5;
        }
        
        @media (max-width: 700px) {
            .ee-form {
                grid-template-columns: 1fr;
            }
            .ee-form .span-3,
            .ee-actions,
            .ee-error {
                grid-column: span 1;
            }
            .ee-actions {
                flex-direction: column-reverse;
            }
            .ee-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main">
    <div class="ee-page">
        <header class="booking-page-topbar" style="margin-bottom: 20px;">
            <p class="booking-page-eyebrow" style="font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 4px; font-weight: 600;">Update Records</p>
            <h1 style="font-size: 24px; font-weight: 700; color: #0f172a; margin: 0;">Edit Enquiry #<?= $enquiry_id ?></h1>
            <p class="booking-page-subtitle" style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;">Update details of the requirement file. Status is currently: <strong><?= htmlspecialchars($enquiry['status']) ?></strong></p>
        </header>

        <div class="card" style="padding: 24px;">
            <form class="ee-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="enquiry_id" value="<?= $enquiry_id ?>">
                
                <?php if ($error): ?>
                    <div class="ee-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <div>
                    <label>Customer Name *</label>
                    <input name="customer_name" required value="<?= htmlspecialchars($enquiry['customer_name']) ?>">
                </div>
                
                <div>
                    <label>Phone Number *</label>
                    <input name="mobile" required value="<?= htmlspecialchars($enquiry['mobile']) ?>">
                </div>
                
                <div>
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($enquiry['email']) ?>">
                </div>
                
                <div>
                    <label>Service Type *</label>
                    <select name="service_type">
                        <?php foreach(enquiry_service_types() as $item): ?>
                            <option <?= $enquiry['service_type']===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label>From</label>
                    <input name="from_location" value="<?= htmlspecialchars($enquiry['from_location'] ?? '') ?>">
                </div>
                
                <div>
                    <label>To</label>
                    <input name="to_location" value="<?= htmlspecialchars($enquiry['to_location'] ?? '') ?>">
                </div>
                
                <div>
                    <label>Travel / Service Date</label>
                    <input type="date" name="travel_date" value="<?= htmlspecialchars($enquiry['travel_date'] ?? '') ?>">
                </div>
                
                <div>
                    <label>Passenger Count</label>
                    <input type="number" min="1" max="999" name="passenger_count" value="<?= (int) ($enquiry['passenger_count'] ?: 1) ?>">
                </div>
                
                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach(enquiry_priorities() as $item): ?>
                            <option <?= ($enquiry['priority'] ?? 'Medium')===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label>Next Follow-up</label>
                    <input type="datetime-local" name="next_follow_up_at" value="<?= !empty($enquiry['next_follow_up_at']) ? date('Y-m-d\TH:i', strtotime($enquiry['next_follow_up_at'])) : '' ?>">
                </div>
                
                <div>
                    <label>Assigned Staff</label>
                    <?php if($enquiry_is_admin_page): ?>
                        <select name="assigned_user">
                            <option value="">Unassigned</option>
                            <?php foreach($staff as $item): ?>
                                <option value="<?= htmlspecialchars($item) ?>" <?= ($enquiry['assigned_user'] ?? '')===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input value="<?= htmlspecialchars($enquiry['assigned_user'] ?: enquiry_current_user_name()) ?>" readonly>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label>Source</label>
                    <input value="<?= htmlspecialchars($enquiry['source'] ?: 'manual') ?>" readonly>
                </div>
                
                <div class="span-3">
                    <label>Customer Requirement / Message *</label>
                    <textarea name="description" rows="6" required><?= htmlspecialchars($description_value) ?></textarea>
                </div>
                
                <div class="ee-actions">
                    <a class="btn btn-secondary" href="view.php?id=<?= $enquiry_id ?>">Cancel</a>
                    <button class="btn" type="submit" style="background: var(--accent-color); color: #fff; border-color: var(--accent-color);">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
