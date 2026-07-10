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

                // Update customer_name in enquiries table where mobile matches this customer's mobile
                if (!empty($mobile)) {
                    $mobile_cond = get_mobile_matching_sql($db, 'mobile', $mobile);
                    $update_enq_sql = "UPDATE enquiries SET customer_name = '$name' WHERE $mobile_cond";
                    mysqli_query($db, $update_enq_sql);
                }

                // Log activity
                $log_user = $_SESSION['user_name'] ?? 'System';
                $log_query = "INSERT INTO activity_log (username, action, module, activity_date)
                              VALUES ('$log_user', 'Created Customer #$new_id ($name)', 'Customer Master', NOW())";
                mysqli_query($db, $log_query);

                if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'chat' && isset($_GET['enquiry_id'])) {
                    $enquiry_id = intval($_GET['enquiry_id']);
                    header("Location: ../enquiry/chat.php?id=$enquiry_id&customer_added=1");
                } else {
                    header("Location: list.php?success=1");
                }
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
    <title>Add Customer | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        body {
            height: 100vh;
            overflow: hidden;
            margin: 0;
            background: #f8fafc;
            font-family: 'Outfit', sans-serif;
        }
        .main {
            height: calc(100vh - 20px);
            margin-top: 10px;
            margin-bottom: 10px;
            margin-right: 20px;
            padding: 16px 24px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-sizing: border-box;
            background: #f8fafc;
            border: none;
            box-shadow: none;
        }
        .dashboard-title-row {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dashboard-title-row h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .premium-form-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 24px;
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            box-sizing: border-box;
        }
        .premium-form-card form {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }
        .form-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 12px;
            margin-bottom: 16px;
        }
        .form-scroll-area::-webkit-scrollbar {
            width: 6px;
        }
        .form-scroll-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 3px;
        }
        .form-scroll-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .form-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .form-group-full {
            grid-column: span 2;
        }
        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            background: #ffffff;
            color: #1e293b;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #0d283f;
            box-shadow: 0 0 0 3px rgba(13, 40, 63, 0.15);
            background: #ffffff;
        }
        .form-actions-bar {
            display: flex;
            gap: 12px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            margin-top: auto;
        }
        .btn {
            background: linear-gradient(135deg, #0d283f 0%, #1a4970 100%);
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(13, 40, 63, 0.2);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(13, 40, 63, 0.3);
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
        hr {
            margin: 0 0 12px 0;
            border: 0;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1>Add New Customer</h1>
        <?php if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'chat' && isset($_GET['enquiry_id'])): ?>
            <a href="../enquiry/chat.php?id=<?php echo intval($_GET['enquiry_id']); ?>" class="btn btn-secondary">Back to Chat</a>
        <?php else: ?>
            <a href="list.php" class="btn btn-secondary">Back to Master</a>
        <?php endif; ?>
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

    <div class="premium-form-card">
        <form method="POST" autocomplete="off">
            <div class="form-scroll-area">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="customer_type">Customer Type</label>
                        <select id="customer_type" name="customer_type" required>
                            <option value="Walk-in Customer">Walk-in Customer</option>
                            <option value="B2B">B2B</option>
                            <option value="Corporate">Corporate</option>
                            <option value="User">User</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_GET['name'] ?? ''); ?>" placeholder="John Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="mobile">Mobile Number</label>
                        <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>" placeholder="9876543210">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>" placeholder="john@example.com" autocomplete="new-email">
                    </div>

                    <div class="form-group form-group-full">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="3" placeholder="Enter customer address..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- B2B & Corporate specific fields -->
                    <div id="company_fields_container" class="form-group-full" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 8px;">
                        <div class="form-grid" style="gap: 16px;">
                            <div class="form-group">
                                <label for="company_name">Company Name *</label>
                                <input type="text" id="company_name" name="company_name" placeholder="Acme Corp">
                            </div>

                            <div class="form-group">
                                <label for="gst_number">GST Number</label>
                                <input type="text" id="gst_number" name="gst_number" placeholder="22AAAAA0000A1Z5">
                            </div>
                        </div>
                    </div>

                    <!-- User specific login fields -->
                    <div id="user_fields_container" class="form-group-full" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 8px;">
                        <div class="form-grid" style="gap: 16px;">
                            <div class="form-group">
                                <label for="password">Login Password *</label>
                                <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label for="login_role">Login Role *</label>
                                <select id="login_role" name="login_role">
                                    <option value="agent">Agent (User Console)</option>
                                    <option value="admin">Admin (Full Control)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions-bar">
                <button type="submit" name="save" class="btn">Save Customer</button>
                <?php if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'chat' && isset($_GET['enquiry_id'])): ?>
                    <a href="../enquiry/chat.php?id=<?php echo intval($_GET['enquiry_id']); ?>" class="btn btn-secondary">Cancel</a>
                <?php else: ?>
                    <a href="list.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
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
