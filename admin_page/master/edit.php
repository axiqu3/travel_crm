<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$message_type = "";

// Fetch existing details
$fetch_query = "SELECT * FROM customer_master WHERE id = $id";
$res = mysqli_query($db, $fetch_query);
$cust = mysqli_fetch_assoc($res);

if (!$cust) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Customer not found.</h2><a href='list.php'>Back to master</a></div>";
    exit;
}

$user_role_val = 'agent';
if (!empty($cust['email'])) {
    $esc_email = mysqli_real_escape_string($db, $cust['email']);
    $user_res = mysqli_query($db, "SELECT role FROM users WHERE email = '$esc_email'");
    if ($user_res && mysqli_num_rows($user_res) > 0) {
        $user_row = mysqli_fetch_assoc($user_res);
        $user_role_val = $user_row['role'];
    }
}

if (isset($_POST["update"])) {
    $customer_type = mysqli_real_escape_string($db, $_POST["customer_type"]);
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
    $updated_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');

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
            $dup_mobile_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE mobile = '$mobile' AND id != $id LIMIT 1");
            if ($dup_mobile_q && mysqli_num_rows($dup_mobile_q) > 0) {
                $dup_row = mysqli_fetch_assoc($dup_mobile_q);
                $message = "Warning: Another customer with this mobile number already exists (Name: " . $dup_row['name'] . ").";
                $message_type = "error";
            }
        }
    }
    if (empty($message)) {
        if (!empty($email)) {
            $dup_email_q = mysqli_query($db, "SELECT id, name FROM customer_master WHERE email = '$email' AND id != $id LIMIT 1");
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
                WHERE id = $id";
            
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
                              VALUES ('$log_user', 'Updated Customer #$id ($name)', 'Customer Master', NOW())";
                mysqli_query($db, $log_query);

                header("Location: view.php?id=$id&success=1");
                exit;
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit <?php echo ($cust['customer_type'] === 'User') ? 'User' : 'Customer'; ?> | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Edit <?php echo ($cust['customer_type'] === 'User') ? 'User' : 'Customer'; ?> #<?php echo $cust['id']; ?></h1>
        <a href="view.php?id=<?php echo $cust['id']; ?>" class="btn btn-secondary">Cancel & Back</a>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width: 600px; padding: 24px;">
        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="customer_type">Customer Type</label>
                <select id="customer_type" name="customer_type" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                    <option value="Walk-in Customer" <?php echo $cust['customer_type'] === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                    <option value="B2B" <?php echo $cust['customer_type'] === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                    <option value="Corporate" <?php echo $cust['customer_type'] === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                    <option value="User" <?php echo $cust['customer_type'] === 'User' ? 'selected' : ''; ?>>User</option>
                </select>
            </div>

            <div class="form-group">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($cust['name']); ?>" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="mobile">Mobile Number</label>
                <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($cust['mobile']); ?>" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($cust['email']); ?>" autocomplete="new-email" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3" placeholder="Enter customer address..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px; font-family: inherit; font-size: inherit;"><?php echo htmlspecialchars($cust['address']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                    <option value="Active" <?php echo ($cust['status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo ($cust['status'] ?? 'Active') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <!-- B2B & Corporate specific fields -->
            <div id="company_fields_container" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
                <div class="form-group">
                    <label for="company_name">Company Name *</label>
                    <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($cust['company_name']); ?>" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="gst_number">GST Number</label>
                    <input type="text" id="gst_number" name="gst_number" value="<?php echo htmlspecialchars($cust['gst_number']); ?>" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>
            </div>

            <!-- User specific login fields -->
            <div id="user_fields_container" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
                <div class="form-group">
                    <label for="password">Login Password (leave blank to keep unchanged)</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="new-password" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="login_role">Login Role *</label>
                    <select id="login_role" name="login_role" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                        <option value="agent" <?php echo $user_role_val === 'agent' ? 'selected' : ''; ?>>Agent (User Console)</option>
                        <option value="admin" <?php echo $user_role_val === 'admin' ? 'selected' : ''; ?>>Admin (Full Control)</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="submit" name="update" class="btn">Update Customer</button>
                <a href="view.php?id=<?php echo $cust['id']; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const typeSelect = document.getElementById('customer_type');
        const companyFields = document.getElementById('company_fields_container');
        const companyNameInput = document.getElementById('company_name');
        const gstInput = document.getElementById('gst_number');

        const userFields = document.getElementById('user_fields_container');
        const emailInput = document.getElementById('email');

        function toggleCompanyFields() {
            const selectedType = typeSelect.value;
            
            // Toggle Company Fields
            if (selectedType === 'B2B' || selectedType === 'Corporate') {
                companyFields.style.display = 'block';
                companyNameInput.setAttribute('required', 'required');
            } else {
                companyFields.style.display = 'none';
                companyNameInput.removeAttribute('required');
            }

            // Toggle User Fields
            if (selectedType === 'User') {
                userFields.style.display = 'block';
                emailInput.setAttribute('required', 'required');
            } else {
                userFields.style.display = 'none';
                emailInput.removeAttribute('required');
            }
        }

        typeSelect.addEventListener('change', toggleCompanyFields);
        toggleCompanyFields(); // Run on load
    });
</script>

</body>
</html>
