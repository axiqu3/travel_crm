<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
require_once(__DIR__ . "/../../includes/customer_duplicate_helper.php");
$is_user_customer_page = stripos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/user_page/customers/') !== false;
check_auth($is_user_customer_page ? null : 'admin');

$message = "";
$message_type = "";
$customer_return_to_dashboard = ($_GET['return_to'] ?? $_POST['return_to'] ?? '') === 'dashboard';
$is_admin_user = ($_SESSION['user_role'] ?? '') === 'admin';
$can_manage_customer_type = $is_user_customer_page || $is_admin_user;
$can_manage_login_customer_type = $is_admin_user && !$is_user_customer_page;
$customer_bulk_delete_enabled = $is_user_customer_page || $is_admin_user;
$current_customer_user_name = (string) ($_SESSION['user_name'] ?? '');
if ($customer_bulk_delete_enabled && empty($_SESSION['list_bulk_delete_token'])) {
    $_SESSION['list_bulk_delete_token'] = bin2hex(random_bytes(32));
}
$customer_bulk_delete_token = $customer_bulk_delete_enabled ? $_SESSION['list_bulk_delete_token'] : '';
$filter_type = $is_user_customer_page
    ? ''
    : mysqli_real_escape_string($db, trim((string) ($_GET['customer_type'] ?? '')));

if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $message = "Customer successfully created!";
        $message_type = "success";
    } elseif ($_GET['success'] == 2) {
        $message = "Customer successfully updated!";
        $message_type = "success";
    }
}

// Handle Delete Request
if (isset($_POST['delete']) && isset($_POST['customer_id'])) {
    $delete_id = intval($_POST['customer_id']);
    
    // Log details before deleting
    $cust_q = mysqli_query($db, "SELECT name, email, customer_type FROM customer_master WHERE id = $delete_id");
    $cust_data = mysqli_fetch_assoc($cust_q);
    $cust_name = $cust_data ? $cust_data['name'] : 'Unknown';
    $cust_email = $cust_data ? $cust_data['email'] : '';
    $cust_type = $cust_data ? $cust_data['customer_type'] : '';

    $delete_query = "DELETE FROM customer_master WHERE id = $delete_id";
    if (mysqli_query($db, $delete_query)) {
        $message = "Customer deleted successfully.";
        $message_type = "success";

        // Delete user in users table if type is User
        if ($cust_type === 'User' && !empty($cust_email)) {
            $escaped_email = mysqli_real_escape_string($db, $cust_email);
            mysqli_query($db, "DELETE FROM users WHERE email = '$escaped_email'");
        }

        // Log activity
        $log_user = $_SESSION['user_name'] ?? 'System';
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date)
                      VALUES ('$log_user', 'Deleted Customer #$delete_id ($cust_name)', 'Customer Master', NOW())";
        mysqli_query($db, $log_query);
    } else {
        $message = "Error deleting customer: " . mysqli_error($db);
        $message_type = "error";
    }
}

// Handle Save Request
if (isset($_POST["save"])) {
    $customer_type = $can_manage_customer_type
        ? trim((string) ($_POST["customer_type"] ?? 'Walk-in Customer'))
        : 'Walk-in Customer';
    $name = trim((string) ($_POST["name"] ?? ''));
    $mobile = trim((string) ($_POST["mobile"] ?? ''));
    $email = trim((string) ($_POST["email"] ?? ''));
    $address = trim((string) ($_POST["address"] ?? ''));
    
    // Only save B2B/Corporate fields if type matches
    $company_name = "";
    $gst_number = "";
    if ($customer_type === 'B2B' || $customer_type === 'Corporate') {
        $company_name = trim((string) ($_POST["company_name"] ?? ''));
        $gst_number = trim((string) ($_POST["gst_number"] ?? ''));
    }

    $password = isset($_POST["password"]) ? $_POST["password"] : "";
    $login_role = in_array($_POST["login_role"] ?? 'agent', ['agent', 'admin'], true) ? $_POST["login_role"] : 'agent';
    $status = in_array($_POST["status"] ?? 'Active', ['Active', 'Inactive'], true) ? $_POST["status"] : 'Active';
    $created_by = $_SESSION['user_name'] ?? 'System';
    $allowed_customer_types = $can_manage_login_customer_type
        ? ['Walk-in Customer', 'B2B', 'Corporate', 'User']
        : ['Walk-in Customer', 'B2B', 'Corporate'];

    if (!in_array($customer_type, $allowed_customer_types, true) || $name === '') {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    }

    // Validate User specific fields
    if ($customer_type === 'User') {
        if (empty($email)) {
            $message = "Email address is required for User customer type.";
            $message_type = "error";
        } elseif (empty($password)) {
            $message = "Password is required for User customer type.";
            $message_type = "error";
        } else {
            // Check if email already exists in users table
            $check_stmt = mysqli_prepare($db, 'SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $normalized_user_email = normalize_email($email);
            mysqli_stmt_bind_param($check_stmt, 's', $normalized_user_email);
            mysqli_stmt_execute($check_stmt);
            $check_res = mysqli_stmt_get_result($check_stmt);
            if (mysqli_num_rows($check_res) > 0) {
                $message = "This email is already registered as a login user.";
                $message_type = "error";
            }
            mysqli_stmt_close($check_stmt);
        }
    }
    if (empty($message) && $customer_type === 'Walk-in Customer') {
        $duplicates = search_duplicate_customers($db, 'customer_master', $name, $mobile, $email);
        $exact_duplicate = customer_duplicate_first_exact($duplicates);
        if ($exact_duplicate) {
            $duplicate_fields = [];
            if (in_array('mobile', $exact_duplicate['matched_by'] ?? [], true)) $duplicate_fields[] = 'mobile number';
            if (in_array('email', $exact_duplicate['matched_by'] ?? [], true)) $duplicate_fields[] = 'email address';
            $message = ucfirst(implode(' and ', $duplicate_fields) ?: 'Customer details') . ' already exists. Change the duplicate value before saving.';
            $message_type = 'error';
            customer_duplicate_log(
                $db,
                $created_by,
                "Prevented duplicate customer creation for $name; matched Customer #{$exact_duplicate['id']}",
                'Customer Master'
            );
        }
    }

    if (empty($message)) {
        if ($name !== '') {
            $insert_stmt = mysqli_prepare($db, 'INSERT INTO customer_master (customer_type, name, mobile, email, address, company_name, gst_number, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($insert_stmt, 'sssssssss', $customer_type, $name, $mobile, $email, $address, $company_name, $gst_number, $created_by, $status);
            $result = mysqli_stmt_execute($insert_stmt);

            if ($result) {
                $new_id = mysqli_insert_id($db);
                mysqli_stmt_close($insert_stmt);
                
                // If customer is User, create login record in users table
                if ($customer_type === 'User') {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $user_stmt = mysqli_prepare($db, 'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                    mysqli_stmt_bind_param($user_stmt, 'ssss', $name, $email, $hashed_password, $login_role);
                    mysqli_stmt_execute($user_stmt);
                    mysqli_stmt_close($user_stmt);
                }

                // Update customer_name in enquiries table where mobile matches this customer's mobile
                if (!empty($mobile)) {
                    $mobile_cond = get_mobile_matching_sql($db, 'mobile', $mobile);
                    $update_enq_sql = "UPDATE enquiries SET customer_name = '$name' WHERE $mobile_cond";
                    mysqli_query($db, $update_enq_sql);
                }

                // Log activity
                customer_duplicate_log($db, $created_by, "Created Customer #$new_id ($name)", 'Customer Master');

                $message = "Customer successfully created!";
                $message_type = "success";
                if ($customer_return_to_dashboard) {
                    header('Location: list.php?success=1');
                    exit;
                }
            } else {
                $message = "Error saving customer.";
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields.";
            $message_type = "error";
        }
    }
}

// Handle Update Request
if ($is_user_customer_page && isset($_POST["update"]) && isset($_POST["customer_id"])) {
    $update_id = intval($_POST["customer_id"]);
    
    // Fetch existing details
    $fetch_query = "SELECT * FROM customer_master WHERE id = $update_id";
    $res = mysqli_query($db, $fetch_query);
    $cust = mysqli_fetch_assoc($res);
    
    if ($cust) {
        // The user customer page does not allow changing a customer's type.
        // Preserve the stored value even if a crafted request posts another one.
        $editable_customer_types = $can_manage_login_customer_type
            ? ['Walk-in Customer', 'B2B', 'Corporate', 'User']
            : ['Walk-in Customer', 'B2B', 'Corporate'];
        $requested_customer_type = trim((string) ($_POST["customer_type"] ?? ''));
        $customer_type = ($can_manage_customer_type && in_array($requested_customer_type, $editable_customer_types, true))
            ? mysqli_real_escape_string($db, $requested_customer_type)
            : mysqli_real_escape_string($db, (string) ($cust['customer_type'] ?: 'Walk-in Customer'));
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
                $message_type = "error";
            } else {
                // Check if email already exists in users table for a DIFFERENT user
                $old_email = mysqli_real_escape_string($db, $cust['email']);
                $check_email = mysqli_real_escape_string($db, $email);
                $check_res = mysqli_query($db, "SELECT id FROM users WHERE email = '$check_email' AND email != '$old_email'");
                if (mysqli_num_rows($check_res) > 0) {
                    $message = "This email is already registered as a login user.";
                    $message_type = "error";
                }
            }
        }
        if (empty($message)) {
            if (!empty($mobile)) {
                $dup_mobile_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE mobile = '$mobile' AND id != $update_id LIMIT 1");
                if ($dup_mobile_q && mysqli_num_rows($dup_mobile_q) > 0) {
                    $dup_row = mysqli_fetch_assoc($dup_mobile_q);
                    $message = "Warning: Another customer with this mobile number already exists (Name: " . $dup_row['name'] . ").";
                    $message_type = "error";
                }
            }
        }
        if (empty($message)) {
            if (!empty($email)) {
                $dup_email_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE email = '$email' AND id != $update_id LIMIT 1");
                if ($dup_email_q && mysqli_num_rows($dup_email_q) > 0) {
                    $dup_row = mysqli_fetch_assoc($dup_email_q);
                    $message = "Warning: Another customer with this email address already exists (Name: " . $dup_row['name'] . ").";
                    $message_type = "error";
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
                    $old_email = mysqli_real_escape_string($db, $cust['email']);
                    
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

                    $message = "Customer successfully updated!";
                    $message_type = "success";
                } else {
                    $message = "Error: " . mysqli_error($db);
                    $message_type = "error";
                }
            } else {
                $message = "Please fill in all required fields.";
                $message_type = "error";
            }
        }
    }
}

if ($is_user_customer_page) {
    // User customer management excludes login-user records.
    $query = "SELECT * FROM customer_master
              WHERE customer_type IN ('Walk-in Customer', 'Customer', 'B2B', 'Corporate')
                 OR customer_type IS NULL
                 OR customer_type = ''
              ORDER BY id DESC";
} else {
    // Restore the admin Master list: all customer types, with its original
    // optional customer-type filter.
    $where_sql = $filter_type !== '' ? " WHERE customer_type = '$filter_type'" : '';
    $query = "SELECT * FROM customer_master$where_sql ORDER BY id DESC";
}
$data = mysqli_query($db, $query);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Master | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <?php if ($customer_bulk_delete_enabled): ?>
    <script src="../../assets/js/bulk-list-delete.js" defer></script>
    <?php endif; ?>
    <style>
        .action-btns {
            display: flex;
            gap: 6px;
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
        .badge-status-active {
            background: #dcfce7;
            color: #166534;
        }
        .badge-status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main<?php echo $customer_bulk_delete_enabled ? ' list-bulk-page' : ''; ?>"
     <?php if ($customer_bulk_delete_enabled): ?>
     data-bulk-list
     data-bulk-endpoint="bulk_delete.php"
     data-bulk-token="<?php echo htmlspecialchars($customer_bulk_delete_token); ?>"
     data-bulk-singular="customer"
     data-bulk-plural="customers"
     <?php endif; ?>>
    <?php
    $title_display = 'Customer Master';
    if (!$is_user_customer_page) {
        if ($filter_type === 'B2B') $title_display = 'B2B Customers';
        elseif ($filter_type === 'Corporate') $title_display = 'Corporate Customers';
        elseif ($filter_type === 'Walk-in Customer') $title_display = 'Walk-in Customers';
        elseif ($filter_type === 'User') $title_display = 'Users';
    } else {
        $title_display = 'Customers';
    }
    ?>
    <div class="dashboard-title-row">
        <h1><?php echo htmlspecialchars($title_display); ?></h1>
        <?php if ($is_user_customer_page): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn" id="openAddCustomerBtn">
                + Add Customer
            </button>
            <button type="button" class="btn btn-secondary" data-bulk-toggle>Delete</button>
        </div>
        <?php else: ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn" id="openAddCustomerBtn">
                + <?php echo $filter_type === 'User' ? 'Add User' : 'Add Customer'; ?>
            </button>
            <?php if ($customer_bulk_delete_enabled): ?>
            <button type="button" class="btn btn-secondary" data-bulk-toggle>Delete</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="<?php echo $is_user_customer_page ? 'Search customers by name, mobile, email, address...' : 'Search customers by name, company, mobile, type...'; ?>">
    </div>

    <?php if (!$is_user_customer_page && $filter_type === ''): ?>
    <div class="card" style="margin-bottom: 20px; padding: 20px;">
        <form method="GET" action="" style="display: flex; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; max-width: 250px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Filter by Customer Type</label>
                <select name="customer_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Types</option>
                    <option value="Walk-in Customer">Walk-in Customer</option>
                    <option value="B2B">B2B</option>
                    <option value="Corporate">Corporate</option>
                    <option value="User">User</option>
                </select>
            </div>
            <button type="submit" class="btn" style="padding: 10px 20px; height: 38px; cursor: pointer;">Search</button>
            <a href="list.php" class="btn btn-secondary" style="padding: 10px 20px; height: 38px; display: inline-flex; align-items: center; justify-content: center;">Reset</a>
        </form>
    </div>
    <?php endif; ?>

    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding:12px 20px;border-radius:12px;margin-bottom:20px;font-weight:600;background:<?php echo $message_type == 'success' ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo $message_type == 'success' ? '#065f46' : '#991b1b'; ?>;border:1px solid <?php echo $message_type == 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="booking-alert is-success" role="alert">
            <?php $deleted_count = max(0, (int) $_GET['deleted']); ?>
            <?php echo $deleted_count === 1 ? '1 customer deleted successfully.' : $deleted_count . ' customers deleted successfully.'; ?>
        </div>
    <?php endif; ?>

    <?php if ($customer_bulk_delete_enabled): ?>
    <div class="list-bulk-toolbar" data-bulk-toolbar role="region" aria-label="Bulk customer deletion">
        <strong data-bulk-count>0 customers selected</strong>
        <div class="list-bulk-toolbar-actions">
            <button class="btn btn-secondary" type="button" data-bulk-cancel>Cancel</button>
            <button class="btn btn-danger" type="button" data-bulk-delete-selected disabled>Delete Selected</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <?php if ($customer_bulk_delete_enabled): ?>
                    <th class="list-bulk-select-column">
                        <input type="checkbox" data-bulk-select-all aria-label="Select all visible customers">
                    </th>
                    <?php endif; ?>
                    <th>ID</th>
                    <?php if (!$is_user_customer_page): ?><th>Customer Type</th><?php endif; ?>
                    <th><?php echo $is_user_customer_page ? 'Customer Name' : 'Name / Details'; ?></th>
                    <th>Contact Details</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) == 0): ?>
                <tr>
                    <td colspan="<?php echo $is_user_customer_page ? ($customer_bulk_delete_enabled ? '8' : '7') : ($customer_bulk_delete_enabled ? '9' : '8'); ?>" style="text-align: center; color: var(--text-secondary); padding: 30px;">No customers found.</td>
                </tr>
                <?php endif; ?>
                <?php while ($cust = mysqli_fetch_assoc($data)): 
                    $badge_class = 'badge-walk-in';
                    if ($cust['customer_type'] === 'B2B') $badge_class = 'badge-b2b';
                    elseif ($cust['customer_type'] === 'Corporate') $badge_class = 'badge-corporate';
                    elseif ($cust['customer_type'] === 'User') $badge_class = 'badge-user';

                    // Status styling
                    $status = $cust['status'] ?? 'Active';
                    $status_badge_class = $status === 'Active' ? 'badge-status-active' : 'badge-status-inactive';
                    $user_role_disp = '';
                    if (!$is_user_customer_page && $cust['customer_type'] === 'User' && !empty($cust['email'])) {
                        $esc_email = mysqli_real_escape_string($db, $cust['email']);
                        $user_res = mysqli_query($db, "SELECT role FROM users WHERE email = '$esc_email' LIMIT 1");
                        if ($user_res && ($user_row = mysqli_fetch_assoc($user_res))) {
                            $user_role_disp = $user_row['role'] === 'admin' ? 'Admin' : 'Agent';
                        }
                    }
                ?>
                <tr>
                    <?php if ($customer_bulk_delete_enabled): ?>
                    <td class="list-bulk-select-column">
                        <?php $customer_row_deletable = !$is_user_customer_page || hash_equals($current_customer_user_name, (string) ($cust['created_by'] ?? '')); ?>
                        <?php if ($customer_row_deletable): ?>
                        <input type="checkbox" data-bulk-row value="<?php echo (int) $cust['id']; ?>" aria-label="Select customer #<?php echo (int) $cust['id']; ?>">
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($cust["id"]); ?></td>
                    <?php if (!$is_user_customer_page): ?>
                    <td>
                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($cust['customer_type'] ?: 'Walk-in Customer'); ?></span>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($cust["name"]); ?></div>
                        <?php if ($cust['customer_type'] === 'User' && !empty($user_role_disp)): ?>
                            <div style="font-size: 11px; color: var(--accent-color); font-weight: 600; margin-top: 2px;">Role: <?php echo $user_role_disp; ?></div>
                        <?php elseif (($cust['customer_type'] === 'B2B' || $cust['customer_type'] === 'Corporate') && !empty($cust['company_name'])): ?>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;"><?php echo htmlspecialchars($cust['company_name']); ?><?php echo !empty($cust['gst_number']) ? ' (GST: ' . htmlspecialchars($cust['gst_number']) . ')' : ''; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div>📞 <?php echo htmlspecialchars($cust["mobile"] ?: '—'); ?></div>
                        <?php if (!empty($cust["email"])): ?>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">✉ <?php echo htmlspecialchars($cust["email"]); ?></div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span style="font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($cust["created_by"] ?: 'System'); ?></span>
                    </td>
                    <td style="color: var(--text-secondary); font-size: 13px;"><?php echo !empty($cust["created_at"]) ? htmlspecialchars(date('d M Y', strtotime($cust["created_at"]))) : '—'; ?></td>
                    <td>
                        <span class="badge <?php echo $status_badge_class; ?>">
                            <?php echo htmlspecialchars($status); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <a href="view.php?id=<?php echo $cust['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                            <?php if ($is_user_customer_page): ?>
                            <button type="button" class="btn btn-secondary edit-customer-btn" 
                                    data-id="<?php echo $cust['id']; ?>" 
                                    data-type="<?php echo htmlspecialchars($cust['customer_type']); ?>" 
                                    data-name="<?php echo htmlspecialchars($cust['name']); ?>" 
                                    data-mobile="<?php echo htmlspecialchars($cust['mobile'] ?? ''); ?>" 
                                    data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>" 
                                    data-address="<?php echo htmlspecialchars($cust['address'] ?? ''); ?>" 
                                    data-company="<?php echo htmlspecialchars($cust['company_name'] ?? ''); ?>" 
                                    data-gst="<?php echo htmlspecialchars($cust['gst_number'] ?? ''); ?>" 
                                    data-status="<?php echo htmlspecialchars($status); ?>" 
                                    data-role="<?php echo htmlspecialchars($user_role_disp); ?>"
                                    style="padding: 6px 12px; font-size: 12px; border-radius: 8px; background: rgba(13, 40, 63, 0.05);">Edit</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <?php if ($customer_bulk_delete_enabled): ?>
    <div class="list-bulk-confirm-backdrop" data-bulk-confirm role="dialog" aria-modal="true" aria-labelledby="customerBulkDeleteTitle">
        <div class="list-bulk-confirm-card">
            <div class="list-bulk-confirm-icon" aria-hidden="true">!</div>
            <h2 id="customerBulkDeleteTitle">Delete selected customers?</h2>
            <p data-bulk-confirm-message>The selected customers will be permanently deleted. This action cannot be undone.</p>
            <div class="list-bulk-confirm-error" data-bulk-error role="alert"></div>
            <div class="list-bulk-confirm-actions">
                <button class="btn btn-secondary" type="button" data-bulk-confirm-cancel>Cancel</button>
                <button class="btn btn-danger" type="button" data-bulk-confirm-delete>Delete</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($is_user_customer_page || $is_admin_user): ?>
<style>
/* Edit Customer Modal Styles */
.modal-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255, 255, 255, 0.75);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-backdrop.open { display: flex; }
.modal-card {
    background: #ffffff;
    border-radius: 12px;
    width: 100%;
    max-width: 1150px;
    max-height: 90vh;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    border: 1px solid #e2e8f0;
    animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}
@keyframes modalFadeIn {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
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
    opacity: 1;
    color: #0f172a;
}
.modal-body {
    padding: 24px;
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
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-main);
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
<div class="modal-backdrop" id="editCustomerModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="modalTitle">✏️ Edit Customer</h2>
            <button class="modal-close" type="button" onclick="closeEditModal()">&times;</button>
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
                    <input type="hidden" id="edit_customer_type" name="customer_type" value="Walk-in Customer">
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

<script>
function closeEditModal() {
    document.getElementById('editCustomerModal').classList.remove('open');
}

document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editCustomerModal');
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
        });
    });

    // Close on click outside modal card
    editModal.addEventListener('click', function(e) {
        if (e.target === editModal) {
            closeEditModal();
        }
    });

    // Add Customer Modal elements
    const addModal = document.getElementById('addCustomerModal');
    const addTypeSelect = document.getElementById('add_customer_type');
    const addCompanyFields = document.getElementById('add_company_fields_container');
    const addCompanyNameInput = document.getElementById('add_company_name');
    const addUserFields = document.getElementById('add_user_fields_container');
    const addEmailInput = document.getElementById('add_email');
    const addPasswordInput = document.getElementById('add_password');
    const defaultAddCustomerType = <?php
        $default_add_customer_type = in_array($filter_type, ['Walk-in Customer', 'B2B', 'Corporate', 'User'], true)
            ? $filter_type
            : 'Walk-in Customer';
        echo json_encode($default_add_customer_type);
    ?>;

    function toggleAddCompanyFields() {
        const selectedType = addTypeSelect.value;
        if (selectedType === 'B2B' || selectedType === 'Corporate') {
            addCompanyFields.style.display = 'grid';
            addCompanyNameInput.setAttribute('required', 'required');
        } else {
            addCompanyFields.style.display = 'none';
            addCompanyNameInput.removeAttribute('required');
        }

        if (selectedType === 'User') {
            addUserFields.style.display = 'grid';
            addEmailInput.setAttribute('required', 'required');
            addPasswordInput.setAttribute('required', 'required');
        } else {
            addUserFields.style.display = 'none';
            addEmailInput.removeAttribute('required');
            addPasswordInput.removeAttribute('required');
        }
    }

    addTypeSelect.addEventListener('change', toggleAddCompanyFields);

    document.getElementById('openAddCustomerBtn').addEventListener('click', function() {
        // Reset form inputs
        addTypeSelect.value = defaultAddCustomerType;
        document.getElementById('add_name').value = '';
        document.getElementById('add_mobile').value = '';
        document.getElementById('add_email').value = '';
        document.getElementById('add_address').value = '';
        document.getElementById('add_status').value = 'Active';
        document.getElementById('add_company_name').value = '';
        document.getElementById('add_gst_number').value = '';
        document.getElementById('add_password').value = '';
        document.getElementById('add_login_role').value = 'agent';

        toggleAddCompanyFields();
        addModal.classList.add('open');
    });

    if (new URLSearchParams(window.location.search).get('action') === 'add') {
        document.getElementById('openAddCustomerBtn').click();
    }

    addModal.addEventListener('click', function(e) {
        if (e.target === addModal) {
            closeAddModal();
        }
    });
});

function closeAddModal() {
    if (<?php echo $customer_return_to_dashboard ? 'true' : 'false'; ?>) {
        window.location.href = '../dashboard.php';
        return;
    }
    document.getElementById('addCustomerModal').classList.remove('open');
}
</script>

<!-- Add Customer Modal -->
<div class="modal-backdrop" id="addCustomerModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="addModalTitle">➕ Add New <?php echo $filter_type === 'User' ? 'User' : 'Customer'; ?></h2>
            <button class="modal-close" type="button" onclick="closeAddModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <?php if ($can_manage_customer_type): ?>
                <div class="form-group">
                    <label for="add_customer_type">Customer Type *</label>
                    <select id="add_customer_type" name="customer_type" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                        <option value="Walk-in Customer" <?php echo $filter_type === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                        <option value="B2B" <?php echo $filter_type === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                        <option value="Corporate" <?php echo $filter_type === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                        <?php if ($can_manage_login_customer_type): ?>
                        <option value="User" <?php echo $filter_type === 'User' ? 'selected' : ''; ?>>User</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" id="add_customer_type" name="customer_type" value="Walk-in Customer">
                <?php endif; ?>

                <div class="form-group">
                    <label for="add_name">Full Name *</label>
                    <input type="text" id="add_name" name="name" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="add_mobile">Mobile Number</label>
                    <input type="text" id="add_mobile" name="mobile" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="add_email">Email Address</label>
                    <input type="email" id="add_email" name="email" autocomplete="new-email" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group span-3">
                    <label for="add_address">Address</label>
                    <textarea id="add_address" name="address" rows="3" placeholder="Enter customer address..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px; font-family: inherit; font-size: inherit; box-sizing: border-box; height: 43px; resize: none;"></textarea>
                </div>

                <div class="form-group">
                    <label for="add_status">Status</label>
                    <select id="add_status" name="status" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

                <!-- B2B & Corporate specific fields -->
                <div id="add_company_fields_container" class="span-4" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
                    <div class="form-group span-2">
                        <label for="add_company_name">Company Name *</label>
                        <input type="text" id="add_company_name" name="company_name" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>

                    <div class="form-group span-2">
                        <label for="add_gst_number">GST Number</label>
                        <input type="text" id="add_gst_number" name="gst_number" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>
                </div>

                <!-- User specific login fields -->
                <div id="add_user_fields_container" class="span-4" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
                    <div class="form-group span-2">
                        <label for="add_password">Login Password *</label>
                        <input type="password" id="add_password" name="password" placeholder="••••••••" autocomplete="new-password" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                    </div>

                    <div class="form-group span-2">
                        <label for="add_login_role">Login Role *</label>
                        <select id="add_login_role" name="login_role" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                            <option value="agent">Agent (User Console)</option>
                            <option value="admin">Admin (Full Control)</option>
                        </select>
                    </div>
                </div>

                <div class="span-4" style="margin-top: 24px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="submit" name="save" class="btn">Save Customer</button>
                    <button type="button" onclick="closeAddModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
