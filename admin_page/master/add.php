<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$message = "";
$message_type = "";

if (isset($_POST["save"])) {
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

    $created_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');

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
            $check_email = mysqli_real_escape_string($db, $email);
            $check_res = mysqli_query($db, "SELECT id FROM users WHERE email = '$check_email'");
            if (mysqli_num_rows($check_res) > 0) {
                $message = "This email is already registered as a login user.";
                $message_type = "error";
            }
        }
    }

    if (empty($message)) {
        if (!empty($name)) {
            $sql = "INSERT INTO customer_master (customer_type, name, mobile, email, address, company_name, gst_number, created_by, status) 
                    VALUES ('$customer_type', '$name', '$mobile', '$email', '$address', '$company_name', '$gst_number', '$created_by', '$status')";
            $result = mysqli_query($db, $sql);

            if ($result) {
                $new_id = mysqli_insert_id($db);
                
                // If customer is User, create login record in users table
                if ($customer_type === 'User') {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $insert_user_sql = "INSERT INTO users (name, email, password, role) 
                                        VALUES ('$name', '$email', '$hashed_password', '$login_role')";
                    mysqli_query($db, $insert_user_sql);
                }

                // Log activity
                $log_user = $_SESSION['user_name'] ?? 'System';
                $log_query = "INSERT INTO activity_log (username, action, module, activity_date)
                              VALUES ('$log_user', 'Created Customer #$new_id ($name)', 'Customer Master', NOW())";
                mysqli_query($db, $log_query);

                header("Location: list.php?success=1");
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
    <title>Add Customer | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="../dashboard.php">Dashboard</a>
    <a href="list.php" class="active">Master</a>
    <a href="../bookings/list.php">Bookings</a>
    <a href="../bookings/add.php">Add Booking</a>
    <a href="../bookings/reports.php">Reports</a>
    <a href="../enquiry/list.php"<?= (strpos($_SERVER['PHP_SELF'], '/enquiry/') !== false) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="../tasks/index.php">Tasks</a>
    <a href="../admin/activity.php">Activity</a>
    <a href="../../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Add New Customer</h1>
        <a href="list.php" class="btn btn-secondary">Back to List</a>
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
                    <option value="Walk-in Customer">Walk-in Customer</option>
                    <option value="B2B">B2B</option>
                    <option value="Corporate">Corporate</option>
                    <option value="User">User</option>
                </select>
            </div>

            <div class="form-group">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_GET['name'] ?? ''); ?>" placeholder="John Doe" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="mobile">Mobile Number</label>
                <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>" placeholder="9876543210" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>" placeholder="john@example.com" autocomplete="new-email" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3" placeholder="Enter customer address..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px; font-family: inherit; font-size: inherit;"></textarea>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <!-- B2B & Corporate specific fields -->
            <div id="company_fields_container" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
                <div class="form-group">
                    <label for="company_name">Company Name *</label>
                    <input type="text" id="company_name" name="company_name" placeholder="Acme Corp" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="gst_number">GST Number</label>
                    <input type="text" id="gst_number" name="gst_number" placeholder="22AAAAA0000A1Z5" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>
            </div>

            <!-- User specific login fields -->
            <div id="user_fields_container" style="display: none; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
                <div class="form-group">
                    <label for="password">Login Password *</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="new-password" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                </div>

                <div class="form-group">
                    <label for="login_role">Login Role *</label>
                    <select id="login_role" name="login_role" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-dark); border-radius: 8px;">
                        <option value="agent">Agent (User Console)</option>
                        <option value="admin">Admin (Full Control)</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="submit" name="save" class="btn">Save Customer</button>
                <a href="list.php" class="btn btn-secondary">Cancel</a>
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
        const passwordInput = document.getElementById('password');
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
                companyNameInput.value = '';
                gstInput.value = '';
            }

            // Toggle User Fields
            if (selectedType === 'User') {
                userFields.style.display = 'block';
                passwordInput.setAttribute('required', 'required');
                emailInput.setAttribute('required', 'required');
            } else {
                userFields.style.display = 'none';
                passwordInput.removeAttribute('required');
                emailInput.removeAttribute('required');
                passwordInput.value = '';
            }
        }

        typeSelect.addEventListener('change', toggleCompanyFields);
        toggleCompanyFields(); // Run on load
    });
</script>

</body>
</html>
