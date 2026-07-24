<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
$is_user_customer_page = stripos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/user_page/customers/') !== false;
check_auth($is_user_customer_page ? null : 'admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$is_admin_user = ($_SESSION['user_role'] ?? '') === 'admin';
$can_manage_customer_type = $is_user_customer_page || $is_admin_user;
$can_manage_login_customer_type = $is_admin_user && !$is_user_customer_page;

// Handle Update Request
if (isset($_POST["update"]) && isset($_POST["customer_id"])) {
    $update_id = intval($_POST["customer_id"]);
    
    // Fetch existing details
    $fetch_query = "SELECT * FROM customer_master WHERE id = $update_id";
    $res = mysqli_query($db, $fetch_query);
    $existing_cust = mysqli_fetch_assoc($res);
    
    if ($existing_cust) {
        // Normal users may edit customer details, but cannot change the
        // customer classification from the user customer page.
        $editable_customer_types = $can_manage_login_customer_type
            ? ['Walk-in Customer', 'B2B', 'Corporate', 'User']
            : ['Walk-in Customer', 'B2B', 'Corporate'];
        $requested_customer_type = trim((string) ($_POST["customer_type"] ?? ''));
        $customer_type = ($can_manage_customer_type && in_array($requested_customer_type, $editable_customer_types, true))
            ? mysqli_real_escape_string($db, $requested_customer_type)
            : mysqli_real_escape_string($db, (string) ($existing_cust['customer_type'] ?: 'Walk-in Customer'));
        $name = mysqli_real_escape_string($db, $_POST["name"]);
        $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
        $email = mysqli_real_escape_string($db, $_POST["email"]);
        $address = mysqli_real_escape_string($db, $_POST["address"]);
        
        // Only save B2B/Corporate fields if type matches
        $company_name = "";
        $gst_number = "";
        if ($customer_type === 'B2B' || $customer_type === 'Corporate') {
            $company_name = mysqli_real_escape_string($db, $_POST["company_name"]);
            $gst_number = mysqli_real_escape_string($db, $_POST["gst_number"]);
        }

        $password = isset($_POST["password"]) ? $_POST["password"] : "";
        $login_role = isset($_POST["login_role"]) ? mysqli_real_escape_string($db, $_POST["login_role"]) : "agent";
        $status = isset($_POST["status"]) ? mysqli_real_escape_string($db, $_POST["status"]) : "Active";

        // Validate User specific fields
        if ($customer_type === 'User') {
            if (empty($email)) {
                $message = "Email address is required for User customer type.";
            } else {
                // Check if email already exists in users table for a DIFFERENT user
                $old_email = mysqli_real_escape_string($db, $existing_cust['email']);
                $check_email = mysqli_real_escape_string($db, $email);
                $check_res = mysqli_query($db, "SELECT id FROM users WHERE email = '$check_email' AND email != '$old_email'");
                if (mysqli_num_rows($check_res) > 0) {
                    $message = "This email is already registered as a login user.";
                }
            }
        }
        if (empty($message)) {
            if (!empty($mobile)) {
                $dup_mobile_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE mobile = '$mobile' AND id != $update_id LIMIT 1");
                if ($dup_mobile_q && mysqli_num_rows($dup_mobile_q) > 0) {
                    $dup_row = mysqli_fetch_assoc($dup_mobile_q);
                    $message = "Warning: Another customer with this mobile number already exists (Name: " . $dup_row['name'] . ").";
                }
            }
        }
        if (empty($message)) {
            if (!empty($email)) {
                $dup_email_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE email = '$email' AND id != $update_id LIMIT 1");
                if ($dup_email_q && mysqli_num_rows($dup_email_q) > 0) {
                    $dup_row = mysqli_fetch_assoc($dup_email_q);
                    $message = "Warning: Another customer with this email address already exists (Name: " . $dup_row['name'] . ").";
                }
            }
        }

        if (empty($message)) {
            if (!empty($name)) {
                $update_query = "UPDATE customer_master SET 
                    customer_type = '$customer_type',
                    name = '$name',
                    mobile = '$mobile',
                    email = '$email',
                    address = '$address',
                    company_name = '$company_name',
                    gst_number = '$gst_number',
                    status = '$status'
                    WHERE id = $update_id";
                
                $result = mysqli_query($db, $update_query);

                if ($result) {
                    // Sync with users table
                    $old_email = mysqli_real_escape_string($db, $existing_cust['email']);
                    
                    // Check if user already existed in users table
                    $user_q = mysqli_query($db, "SELECT id FROM users WHERE email = '$old_email'");
                    $user_exists = (mysqli_num_rows($user_q) > 0);
                    
                    if ($customer_type === 'User') {
                        if ($user_exists) {
                            // Update existing user
                            $user_row = mysqli_fetch_assoc($user_q);
                            $user_id = $user_row['id'];
                            
                            $update_parts = ["name = '$name'", "email = '$email'", "role = '$login_role'"];
                            if (!empty($password)) {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $update_parts[] = "password = '$hashed_password'";
                            }
                            $update_user_sql = "UPDATE users SET " . implode(", ", $update_parts) . " WHERE id = $user_id";
                            mysqli_query($db, $update_user_sql);
                        } else {
                            // Insert new user
                            $hashed_password = password_hash($password ?: '123456', PASSWORD_DEFAULT);
                            $insert_user_sql = "INSERT INTO users (name, email, password, role) 
                                                VALUES ('$name', '$email', '$hashed_password', '$login_role')";
                            mysqli_query($db, $insert_user_sql);
                        }
                    } else {
                        // If type changed from User, delete from users table
                        if ($user_exists) {
                            mysqli_query($db, "DELETE FROM users WHERE email = '$old_email'");
                        }
                    }

                    // Log activity
                    $log_user = $_SESSION['user_name'] ?? 'System';
                    $log_query = "INSERT INTO activity_log (username, action, module, activity_date)
                                  VALUES ('$log_user', 'Updated Customer #$update_id ($name)', 'Customer Master', NOW())";
                    mysqli_query($db, $log_query);

                    $_GET['success'] = 1;
                } else {
                    $message = "Error: " . mysqli_error($db);
                }
            } else {
                $message = "Please fill in all required fields.";
            }
        }
    }
}

// Handle Delete Request
if (isset($_POST['delete'])) {
    $cust_q = mysqli_query($db, "SELECT name, email, customer_type FROM customer_master WHERE id = $id");
    $cust_data = mysqli_fetch_assoc($cust_q);
    $name = $cust_data ? $cust_data['name'] : 'Unknown';
    $cust_email = $cust_data ? $cust_data['email'] : '';
    $cust_type = $cust_data ? $cust_data['customer_type'] : '';

    $delete_query = "DELETE FROM customer_master WHERE id = $id";
    if (mysqli_query($db, $delete_query)) {
        // Delete user in users table if type is User
        if ($cust_type === 'User' && !empty($cust_email)) {
            $escaped_email = mysqli_real_escape_string($db, $cust_email);
            mysqli_query($db, "DELETE FROM users WHERE email = '$escaped_email'");
        }

        // Log activity
        $log_user = $_SESSION['user_name'] ?? 'System';
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date)
                      VALUES ('$log_user', 'Deleted Customer #$id ($name)', 'Customer Master', NOW())";
        mysqli_query($db, $log_query);

        $redirect_url = "list.php";
        if ($cust_type === 'Walk-in Customer') {
            $redirect_url = "list.php?customer_type=Walk-in+Customer";
        } elseif ($cust_type === 'B2B') {
            $redirect_url = "list.php?customer_type=B2B";
        } elseif ($cust_type === 'Corporate') {
            $redirect_url = "list.php?customer_type=Corporate";
        } elseif ($cust_type === 'User') {
            $redirect_url = "list.php?customer_type=User";
        }
        header("Location: " . $redirect_url);
        exit;
    } else {
        $message = "Error deleting customer: " . mysqli_error($db);
    }
}

$query = "SELECT * FROM customer_master WHERE id = $id";
$result = mysqli_query($db, $query);
$cust = mysqli_fetch_assoc($result);

if (!$cust) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Customer not found.</h2><a href='list.php'>Back to master</a></div>";
    exit;
}

// Fetch bookings history or managed bookings
$bookings_res = null;
if ($cust['customer_type'] === 'User') {
    // Show bookings managed by this user
    $esc_name = mysqli_real_escape_string($db, $cust['name']);
    $bookings_q = "SELECT * FROM bookings WHERE assigned_user = '$esc_name' OR created_by = '$esc_name' ORDER BY id DESC";
    $bookings_res = mysqli_query($db, $bookings_q);
} else {
    // Show bookings made by this customer
    $cust_id = intval($cust['id']);
    $esc_name = mysqli_real_escape_string($db, $cust['name']);
    $bookings_q = "SELECT * FROM bookings WHERE customer_master_id = $cust_id OR (customer_master_id IS NULL AND customer_name = '$esc_name') ORDER BY id DESC";
    $bookings_res = mysqli_query($db, $bookings_q);
}

$user_role_val = '';
$user_details = null;
if ($cust['customer_type'] === 'User' && !empty($cust['email'])) {
    $esc_email = mysqli_real_escape_string($db, $cust['email']);
    $user_res = mysqli_query(
        $db,
        "SELECT id, role, phone, dob, city, state, country, zip_code, profile_completed
         FROM users
         WHERE email = '$esc_email'"
    );
    if ($user_res && mysqli_num_rows($user_res) > 0) {
        $user_details = mysqli_fetch_assoc($user_res);
        $user_role_val = $user_details['role'] === 'admin' ? 'Admin (Full Control)' : 'Agent (User Console)';
    }
}

$password_change_error = '';
$password_modal_open = false;
$can_change_user_password = $is_admin_user
    && !$is_user_customer_page
    && $cust['customer_type'] === 'User'
    && $user_details;

if ($can_change_user_password && empty($_SESSION['change_password_token'])) {
    $_SESSION['change_password_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['change_password'])) {
    if (!$can_change_user_password) {
        http_response_code(403);
        $message = 'You are not authorized to change this password.';
    } else {
        $password_modal_open = true;
        $token = (string) ($_POST['csrf_token'] ?? '');
        $new_password = (string) ($_POST['new_password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if (!hash_equals($_SESSION['change_password_token'], $token)) {
            $password_change_error = 'Your session token expired. Please reload the page and try again.';
        } elseif (strlen($new_password) < 6) {
            $password_change_error = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $password_change_error = 'The passwords do not match.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $login_user_id = (int) $user_details['id'];
            $update_password_stmt = mysqli_prepare($db, 'UPDATE users SET password = ? WHERE id = ?');
            mysqli_stmt_bind_param($update_password_stmt, 'si', $password_hash, $login_user_id);
            $password_updated = mysqli_stmt_execute($update_password_stmt);
            mysqli_stmt_close($update_password_stmt);

            if ($password_updated) {
                $admin_name = $_SESSION['user_name'] ?? 'System';
                $password_action = 'Changed password for user #' . $cust['id'] . ' (' . $cust['name'] . ')';
                $password_log_stmt = mysqli_prepare(
                    $db,
                    "INSERT INTO activity_log (username, action, module, activity_date)
                     VALUES (?, ?, 'Customer Master', NOW())"
                );
                if ($password_log_stmt) {
                    mysqli_stmt_bind_param($password_log_stmt, 'ss', $admin_name, $password_action);
                    mysqli_stmt_execute($password_log_stmt);
                    mysqli_stmt_close($password_log_stmt);
                }

                unset($_SESSION['change_password_token']);
                header('Location: view.php?id=' . $cust['id'] . '&password_changed=1');
                exit;
            }

            $password_change_error = 'Unable to change the password. Please try again.';
        }
    }
}

$success = isset($_GET['success']);
$password_changed = isset($_GET['password_changed']);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo ($cust['customer_type'] === 'User') ? 'User' : 'Customer'; ?> Details | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            height: 100vh;
            overflow: hidden;
            margin: 0;
            background: #f4f7fb;
            font-family: 'Outfit', sans-serif;
        }
        .main {
            height: calc(100vh - 20px);
            margin-top: 10px;
            margin-bottom: 10px;
            margin-right: 20px;
            padding: 14px 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-sizing: border-box;
            background: #f4f7fb;
            border: none;
            box-shadow: none;
        }
        .dashboard-title-row {
            margin-bottom: 9px;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #dfe6ed;
        }
        .dashboard-title-row h1 {
            font-size: 19px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .view-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 6px;
            margin-bottom: 4px;
            min-height: 0;
        }
        .view-scroll-area::-webkit-scrollbar {
            width: 6px;
        }
        .view-scroll-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 3px;
        }
        .view-scroll-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .view-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .detail-card {
            background: #fff;
            border: 1px solid #dfe6ed;
            border-radius: 11px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .035);
            padding: 16px;
            margin-bottom: 14px;
            box-sizing: border-box;
        }
        .detail-section-title {
            margin: 0 0 12px;
            padding-bottom: 9px;
            border-bottom: 1px solid #e2e8f0;
            color: #0d283f;
            font-size: 14px;
            font-weight: 700;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }
        @media (max-width: 1100px) {
            .detail-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 800px) {
            .detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        .detail-item {
            min-width: 0;
            min-height: 62px;
            padding: 10px 12px;
            border: 0;
            border-radius: 8px;
            background: #f8fafc;
            box-sizing: border-box;
        }
        .detail-item-wide {
            grid-column: span 2;
        }
        .detail-label {
            font-size: 9px;
            text-transform: uppercase;
            color: #73869a;
            font-weight: 800;
            margin-bottom: 4px;
            letter-spacing: .06em;
        }
        .detail-value {
            overflow-wrap: anywhere;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
            color: #102a43;
        }
        .badge {
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            display: inline-block;
        }
        .badge-walk-in {
            background: #e0f2fe;
            color: #0369a1;
        }
        .badge-b2b {
            background: #fef9c3;
            color: #854d0e;
        }
        .badge-corporate {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-user {
            background: #dcfce7;
            color: #166534;
        }
        .btn {
            background: #0d283f;
            border: none;
            border-radius: 8px;
            padding: 7px 13px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(13, 40, 63, .14);
        }
        .btn-secondary {
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #cbd5e1;
            box-shadow: none;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            box-shadow: none;
        }
        .card {
            background: #fff;
            border: 1px solid #dfe6ed;
            border-radius: 11px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .035);
            padding: 16px;
            box-sizing: border-box;
        }
        .card h2 {
            margin: 0 0 10px !important;
            padding-bottom: 9px !important;
            font-size: 14px !important;
        }
        .card table th,
        .card table td {
            padding: 8px 9px !important;
            font-size: 11px;
        }
        .card table thead tr {
            font-size: 10px !important;
        }
        .booking-history-card {
            margin-top: 14px !important;
            padding: 16px !important;
        }
        .detail-delete-row {
            padding-top: 11px !important;
        }
        hr {
            display: none;
        }
        @media (max-width: 600px) {
            .main {
                margin-right: 0;
                padding: 12px;
            }
            .dashboard-title-row {
                align-items: flex-start;
                flex-direction: column;
            }
            .time-btns {
                width: 100%;
                flex-wrap: wrap;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .detail-item-wide {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1><?php echo htmlspecialchars($cust['name']); ?></h1>
        <div class="time-btns" style="display: flex; gap: 8px;">
            <button type="button" class="btn edit-customer-btn" 
                    data-id="<?php echo $cust['id']; ?>" 
                    data-type="<?php echo htmlspecialchars($cust['customer_type']); ?>" 
                    data-name="<?php echo htmlspecialchars($cust['name']); ?>" 
                    data-mobile="<?php echo htmlspecialchars($cust['mobile'] ?? ''); ?>" 
                    data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>" 
                    data-address="<?php echo htmlspecialchars($cust['address'] ?? ''); ?>" 
                    data-company="<?php echo htmlspecialchars($cust['company_name'] ?? ''); ?>" 
                    data-gst="<?php echo htmlspecialchars($cust['gst_number'] ?? ''); ?>" 
                    data-status="<?php echo htmlspecialchars($cust['status'] ?? 'Active'); ?>" 
                    data-role="<?php echo htmlspecialchars($user_details ? $user_details['role'] : ''); ?>">Edit <?php echo ($cust['customer_type'] === 'User') ? 'User' : 'Customer'; ?></button>
            <?php if ($cust['customer_type'] === 'User' && $user_details): ?>
                <?php if ($can_change_user_password): ?>
                    <button type="button" class="btn btn-secondary password-change-btn">Change Password</button>
                <?php endif; ?>
            <?php endif; ?>
            <?php
            $selected_type = $cust['customer_type'] ?? '';
            $back_url = "list.php";
            $back_text = "Back to All Customers";
            if ($selected_type === 'Walk-in Customer') {
                $back_url = "list.php?customer_type=Walk-in+Customer";
                $back_text = "Back to Walk-in Customers";
            } elseif ($selected_type === 'B2B') {
                $back_url = "list.php?customer_type=B2B";
                $back_text = "Back to B2B Customers";
            } elseif ($selected_type === 'Corporate') {
                $back_url = "list.php?customer_type=Corporate";
                $back_text = "Back to Corporate Customers";
            } elseif ($selected_type === 'User') {
                $back_url = "list.php?customer_type=User";
                $back_text = "Back to Users";
            }
            ?>
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary">← <?php echo htmlspecialchars($back_text); ?></a>
        </div>
    </div>

    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding:12px 20px;border-radius:12px;margin-bottom:20px;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="padding:12px 20px;border-radius:12px;margin-bottom:20px;font-weight:600;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;">
            ✅ <?php echo ($cust['customer_type'] === 'User') ? 'User' : 'Customer'; ?> details updated successfully.
        </div>
    <?php endif; ?>
    <?php if ($password_changed): ?>
        <div style="padding:12px 20px;border-radius:12px;margin-bottom:20px;font-weight:600;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;">
            User password changed successfully.
        </div>
    <?php endif; ?>

    <div class="view-scroll-area">
        <div class="detail-card">
        <h2 class="detail-section-title"><?php echo ($cust['customer_type'] === 'User') ? 'User Information' : 'Customer Information'; ?></h2>
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Customer ID</div>
                <div class="detail-value">#<?php echo htmlspecialchars($cust['id']); ?></div>
            </div>
            
            <div class="detail-item">
                <div class="detail-label">Customer Type</div>
                <div class="detail-value">
                    <?php
                        $badge_class = 'badge-walk-in';
                        if ($cust['customer_type'] === 'B2B') $badge_class = 'badge-b2b';
                        elseif ($cust['customer_type'] === 'Corporate') $badge_class = 'badge-corporate';
                        elseif ($cust['customer_type'] === 'User') $badge_class = 'badge-user';
                    ?>
                    <span class="badge <?php echo $badge_class; ?>">
                        <?php echo htmlspecialchars($cust['customer_type']); ?>
                    </span>
                </div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Full Name</div>
                <div class="detail-value" style="font-weight: 600;"><?php echo htmlspecialchars($cust['name']); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Mobile Number</div>
                <div class="detail-value"><?php echo htmlspecialchars($cust['mobile'] ?: '—'); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Email Address</div>
                <div class="detail-value"><?php echo htmlspecialchars($cust['email'] ?: '—'); ?></div>
            </div>

            <div class="detail-item detail-item-wide">
                <div class="detail-label">Address</div>
                <div class="detail-value" style="white-space: pre-wrap;"><?php echo htmlspecialchars($cust['address'] ?: '—'); ?></div>
            </div>

            <?php if ($cust['customer_type'] === 'B2B' || $cust['customer_type'] === 'Corporate'): ?>
                <div class="detail-item">
                    <div class="detail-label">Company Name</div>
                    <div class="detail-value" style="font-weight: 600; color: var(--status-booked-text);"><?php echo htmlspecialchars($cust['company_name'] ?: '—'); ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">GST Number</div>
                    <div class="detail-value"><?php echo htmlspecialchars($cust['gst_number'] ?: '—'); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($cust['customer_type'] === 'User'): ?>
                <div class="detail-item">
                    <div class="detail-label">Login Role</div>
                    <div class="detail-value" style="font-weight: 600; color: var(--accent-color);"><?php echo htmlspecialchars($user_role_val ?: 'Agent (User Console)'); ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Password</div>
                    <div class="detail-value">
                        Protected and not viewable
                        <?php if ($can_change_user_password): ?>
                            · <button type="button" class="password-inline-button password-change-btn">Change password</button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($user_details): ?>
                    <div class="detail-item">
                        <div class="detail-label">Phone / Mobile</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['phone'] ?: '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Date of Birth</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['dob'] ? date('d F Y', strtotime($user_details['dob'])) : '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">City</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['city'] ?: '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">State</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['state'] ?: '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Country</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['country'] ?: '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Zip / Postal Code</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_details['zip_code'] ?: '—'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Profile Completed</div>
                        <div class="detail-value">
                            <span class="badge" style="background: <?php echo $user_details['profile_completed'] ? '#e2f0d9' : '#fce4d6'; ?>; color: <?php echo $user_details['profile_completed'] ? '#385723' : '#c65911'; ?>; border: 1px solid <?php echo $user_details['profile_completed'] ? '#c4dfb4' : '#f8caac'; ?>; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; display: inline-block;">
                                <?php echo $user_details['profile_completed'] ? 'Yes' : 'No (Pending First Login)'; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">Created By</div>
                <div class="detail-value"><?php echo htmlspecialchars($cust['created_by'] ?: 'System'); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?php echo htmlspecialchars(date('d F Y, H:i', strtotime($cust['created_at']))); ?></div>
            </div>
        </div>

        <div class="detail-delete-row" style="border-top: 1px solid #f1f5f9; padding-top: 20px; display: flex; justify-content: flex-end;">
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this customer permanently?');">
                <button type="submit" name="delete" class="btn" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer;">Delete Customer</button>
            </form>
        </div>
    </div>

    <!-- Bookings Table card -->
    <div class="card booking-history-card">
        <h2 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; color: var(--accent-color);">
            <?php echo $cust['customer_type'] === 'User' ? 'Managed Bookings' : 'Booking History'; ?>
        </h2>
        
        <div style="margin-top: 10px; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid var(--border-color); font-size: 13px; color: var(--text-secondary);">
                        <th style="padding: 12px 10px;">ID</th>
                        <th style="padding: 12px 10px;">Passenger</th>
                        <?php if ($cust['customer_type'] === 'User'): ?>
                            <th style="padding: 12px 10px;">Customer</th>
                        <?php else: ?>
                            <th style="padding: 12px 10px;">Agent / User</th>
                        <?php endif; ?>
                        <th style="padding: 12px 10px;">Service</th>
                        <th style="padding: 12px 10px;">PNR / Ticket</th>
                        <th style="padding: 12px 10px;">Route</th>
                        <th style="padding: 12px 10px;">Departure</th>
                        <th style="padding: 12px 10px;">Status</th>
                        <th style="padding: 12px 10px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody style="font-size: 13px;">
                    <?php if (!$bookings_res || mysqli_num_rows($bookings_res) === 0): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-secondary);">
                                No bookings found for this <?php echo $cust['customer_type'] === 'User' ? 'user' : 'customer'; ?>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($b = mysqli_fetch_assoc($bookings_res)): 
                            $status_color = '#0284c7';
                            if ($b['status'] === 'Pending') $status_color = '#eab308';
                            elseif ($b['status'] === 'Cancelled') $status_color = '#dc2626';
                        ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px 10px; font-weight: 600;">#<?php echo htmlspecialchars($b['id']); ?></td>
                                <td style="padding: 12px 10px; font-weight: 500;"><?php echo htmlspecialchars($b['passenger_name']); ?></td>
                                <?php if ($cust['customer_type'] === 'User'): ?>
                                    <td style="padding: 12px 10px;"><?php echo htmlspecialchars($b['customer_name']); ?> <span style="font-size:11px; color:var(--text-secondary);">(<?php echo htmlspecialchars($b['customer_type']); ?>)</span></td>
                                <?php else: ?>
                                    <td style="padding: 12px 10px;"><?php echo htmlspecialchars($b['assigned_user'] ?: $b['created_by'] ?: 'System'); ?></td>
                                <?php endif; ?>
                                <td style="padding: 12px 10px;"><span style="font-size:11px; font-weight:600; padding:2px 6px; border-radius:4px; background:#f1f5f9; color:#475569;"><?php echo htmlspecialchars($b['service_type'] ?: 'Flight'); ?></span></td>
                                <td style="padding: 12px 10px;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($b['pnr'] ?: '—'); ?></div>
                                    <?php if (!empty($b['ticket_number'])): ?>
                                        <div style="font-size: 11px; color: var(--text-secondary);"><?php echo htmlspecialchars($b['ticket_number']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 10px;"><?php echo htmlspecialchars($b['from_city'] ?: '—'); ?> ➔ <?php echo htmlspecialchars($b['to_city'] ?: '—'); ?></td>
                                <td style="padding: 12px 10px;"><?php echo $b['departure_date'] ? htmlspecialchars(date('d M Y', strtotime($b['departure_date']))) : '—'; ?></td>
                                <td style="padding: 12px 10px;">
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $status_color; ?>;">
                                        ● <?php echo htmlspecialchars($b['status']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 10px; text-align: center;">
                                    <a href="../bookings/view.php?id=<?php echo $b['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px; border-radius: 8px;">Details</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>
</div>


<style>
/* Edit Customer Modal Styles */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .48);
    backdrop-filter: blur(3px);
    z-index: 2100;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}
.modal-backdrop.open { display: flex; }
.modal-card {
    background: #ffffff;
    border-radius: 14px;
    width: min(calc(100vw - 36px), 1040px);
    max-height: calc(100vh - 36px);
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
    border: 1px solid #dbe3ea;
    animation: modalFadeIn .2s ease-out;
    overflow: hidden;
}
@keyframes modalFadeIn {
    from { transform: translateY(8px) scale(.98); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}
.modal-head {
    padding: 16px 20px;
    background: #fff;
    color: #0f172a;
    display: flex;
    flex: 0 0 auto;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
}
.modal-head h2 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
    font-weight: 700;
}
.modal-close {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: #f1f5f9;
    color: #64748b;
    font-size: 23px;
    cursor: pointer;
    line-height: 1;
}
.modal-close:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.modal-body {
    min-height: 0;
    padding: 20px;
    overflow-y: auto;
}
.modal-body form {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}
.form-group {
    margin-bottom: 0px;
}
.form-group label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 6px;
    color: #475569;
}
.modal-body input,
.modal-body select,
.modal-body textarea {
    width: 100%;
    min-height: 42px;
    box-sizing: border-box;
    border-color: #cbd5e1 !important;
    background: rgba(0, 0, 0, .02);
    font: inherit;
}
.modal-body input:focus,
.modal-body select:focus,
.modal-body textarea:focus {
    border-color: #0d283f !important;
    background: #fff;
    outline: none;
}
.modal-body .span-2 {
    grid-column: span 2;
}
.modal-body .span-3 {
    grid-column: span 3;
}
.modal-body .span-4 {
    grid-column: span 4;
}
.password-modal-card {
    width: min(calc(100vw - 36px), 620px);
}
.modal-body .password-form {
    grid-template-columns: 1fr;
}
.password-note {
    margin: 0;
    padding: 14px 16px;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    background: #f0f9ff;
    color: #075985;
    line-height: 1.5;
}
.password-error {
    padding: 12px 16px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fee2e2;
    color: #991b1b;
    font-weight: 600;
}
.password-wrap {
    position: relative;
}
.password-wrap input {
    padding-right: 76px;
}
.password-toggle {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    border: 0;
    border-radius: 6px;
    padding: 7px 9px;
    background: #e2e8f0;
    color: #334155;
    cursor: pointer;
    font-weight: 600;
}
.password-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 8px;
}
.password-inline-button {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--accent-color);
    cursor: pointer;
    font: inherit;
    text-decoration: underline;
}

@media (max-width: 992px) {
    .modal-body form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .modal-body .span-3,
    .modal-body .span-4 {
        grid-column: span 2;
    }
}

@media (max-width: 600px) {
    .modal-backdrop {
        align-items: flex-start;
        padding: 10px;
    }
    .modal-card {
        width: 100%;
        max-height: calc(100vh - 20px);
    }
    .modal-body {
        padding: 16px;
    }
    .modal-body form {
        grid-template-columns: 1fr;
    }
    .modal-body .span-2,
    .modal-body .span-3,
    .modal-body .span-4 {
        grid-column: span 1;
    }
}
</style>

<!-- Edit Customer Modal -->
<div class="modal-backdrop" id="editCustomerModal" role="dialog" aria-modal="true" aria-labelledby="editCustomerTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="editCustomerTitle">Edit Customer</h2>
            <button class="modal-close" type="button" onclick="closeEditModal()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="customer_id" id="edit_id">
                
                <?php if ($can_manage_customer_type): ?>
                <div class="form-group">
                    <label for="edit_customer_type">Customer Type *</label>
                    <select id="edit_customer_type" name="customer_type" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                        <option value="Walk-in Customer">Walk-in Customer</option>
                        <option value="B2B">B2B</option>
                        <option value="Corporate">Corporate</option>
                        <?php if ($can_manage_login_customer_type): ?>
                        <option value="User">User</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" id="edit_customer_type" name="customer_type" value="<?php echo htmlspecialchars($cust['customer_type'] ?: 'Walk-in Customer'); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="edit_name">Full Name *</label>
                    <input type="text" id="edit_name" name="name" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="edit_mobile">Mobile Number</label>
                    <input type="text" id="edit_mobile" name="mobile" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="edit_email">Email Address</label>
                    <input type="email" id="edit_email" name="email" autocomplete="new-email" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group span-3">
                    <label for="edit_address">Address</label>
                    <textarea id="edit_address" name="address" rows="3" placeholder="Enter customer address..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px; font-family: inherit; font-size: inherit; box-sizing: border-box; height: 43px; resize: none;"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

                <!-- B2B & Corporate specific fields -->
                <div id="edit_company_fields_container" class="span-4" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
                    <div class="form-group span-2">
                        <label for="edit_company_name">Company Name *</label>
                        <input type="text" id="edit_company_name" name="company_name" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>

                    <div class="form-group span-2">
                        <label for="edit_gst_number">GST Number</label>
                        <input type="text" id="edit_gst_number" name="gst_number" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>
                </div>

                <!-- User specific login fields -->
                <div id="edit_user_fields_container" class="span-4" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
                    <div class="form-group span-2">
                        <label for="edit_password">Login Password (leave blank to keep unchanged)</label>
                        <input type="password" id="edit_password" name="password" placeholder="••••••••" autocomplete="new-password" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>

                    <div class="form-group span-2">
                        <label for="edit_login_role">Login Role *</label>
                        <select id="edit_login_role" name="login_role" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                            <option value="agent">Agent (User Console)</option>
                            <option value="admin">Admin (Full Control)</option>
                        </select>
                    </div>
                </div>

                <div class="span-4" style="margin-top: 24px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="submit" name="update" class="btn">Update Customer</button>
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($can_change_user_password): ?>
<!-- Change Password Modal -->
<div class="modal-backdrop<?php echo $password_modal_open ? ' open' : ''; ?>" id="passwordChangeModal" role="dialog" aria-modal="true" aria-labelledby="passwordChangeTitle">
    <div class="modal-card password-modal-card">
        <div class="modal-head">
            <h2 id="passwordChangeTitle">Change User Password</h2>
            <button class="modal-close" type="button" onclick="closePasswordModal()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" class="password-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['change_password_token']); ?>">

                <p class="password-note">
                    Existing passwords are securely hashed and cannot be viewed. Enter and confirm a new password for
                    <strong><?php echo htmlspecialchars($cust['name']); ?></strong>.
                </p>

                <?php if ($password_change_error !== ''): ?>
                    <div class="password-error" role="alert"><?php echo htmlspecialchars($password_change_error); ?></div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <div class="password-wrap">
                        <input type="password" id="new_password" name="new_password" minlength="6" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Show new password">Show</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password *</label>
                    <div class="password-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" minlength="6" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show confirmed password">Show</button>
                    </div>
                </div>

                <div class="password-actions">
                    <button type="submit" name="change_password" value="1" class="btn">Change Password</button>
                    <button type="button" onclick="closePasswordModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function closeEditModal() {
    document.getElementById('editCustomerModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closePasswordModal() {
    const modal = document.getElementById('passwordChangeModal');
    if (modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editCustomerModal');
    const passwordModal = document.getElementById('passwordChangeModal');
    const typeSelect = document.getElementById('edit_customer_type');
    const companyFields = document.getElementById('edit_company_fields_container');
    const companyNameInput = document.getElementById('edit_company_name');
    const userFields = document.getElementById('edit_user_fields_container');
    const emailInput = document.getElementById('edit_email');

    function toggleCompanyFields() {
        const selectedType = typeSelect.value;
        if (selectedType === 'B2B' || selectedType === 'Corporate') {
            companyFields.style.display = 'grid';
            companyNameInput.setAttribute('required', 'required');
        } else {
            companyFields.style.display = 'none';
            companyNameInput.removeAttribute('required');
        }

        if (selectedType === 'User') {
            userFields.style.display = 'grid';
            emailInput.setAttribute('required', 'required');
        } else {
            userFields.style.display = 'none';
            emailInput.removeAttribute('required');
        }
    }

    typeSelect.addEventListener('change', toggleCompanyFields);

    // Open modal on Edit button click
    document.querySelectorAll('.edit-customer-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value = this.dataset.id;
            document.getElementById('edit_customer_type').value = this.dataset.type;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_mobile').value = this.dataset.mobile;
            document.getElementById('edit_email').value = this.dataset.email;
            document.getElementById('edit_address').value = this.dataset.address;
            document.getElementById('edit_status').value = this.dataset.status;
            document.getElementById('edit_company_name').value = this.dataset.company;
            document.getElementById('edit_gst_number').value = this.dataset.gst;
            document.getElementById('edit_login_role').value = this.dataset.role || 'agent';
            document.getElementById('edit_password').value = ''; // clear password input

            toggleCompanyFields();
            editModal.classList.add('open');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => document.getElementById('edit_name')?.focus());
        });
    });

    // Close on click outside modal card
    editModal.addEventListener('click', function(e) {
        if (e.target === editModal) {
            closeEditModal();
        }
    });

    document.querySelectorAll('.password-change-btn').forEach((button) => {
        button.addEventListener('click', function() {
            if (!passwordModal) return;
            passwordModal.classList.add('open');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => document.getElementById('new_password')?.focus());
        });
    });

    document.querySelectorAll('.password-toggle').forEach((button) => {
        button.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            const willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            this.textContent = willShow ? 'Hide' : 'Show';
            this.setAttribute('aria-label', (willShow ? 'Hide ' : 'Show ') + input.id.replaceAll('_', ' '));
        });
    });

    if (passwordModal) {
        passwordModal.addEventListener('click', function(e) {
            if (e.target === passwordModal) {
                closePasswordModal();
            }
        });

        if (passwordModal.classList.contains('open')) {
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => document.getElementById('new_password')?.focus());
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && editModal.classList.contains('open')) {
            closeEditModal();
        }
        if (e.key === 'Escape' && passwordModal?.classList.contains('open')) {
            closePasswordModal();
        }
    });
});
</script>
</body>
</html>
