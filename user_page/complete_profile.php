<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");

check_auth();

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

// Fetch current user details
$user_q = mysqli_query($db, "SELECT * FROM users WHERE id = $user_id LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

if (isset($_POST['complete_profile'])) {
    $name = mysqli_real_escape_string($db, trim($_POST['name'] ?? ''));
    $phone = mysqli_real_escape_string($db, trim($_POST['phone'] ?? ''));
    $dob = mysqli_real_escape_string($db, trim($_POST['dob'] ?? ''));
    $address = mysqli_real_escape_string($db, trim($_POST['address'] ?? ''));
    $city = mysqli_real_escape_string($db, trim($_POST['city'] ?? ''));
    $state = mysqli_real_escape_string($db, trim($_POST['state'] ?? ''));
    $country = mysqli_real_escape_string($db, trim($_POST['country'] ?? ''));
    $zip_code = mysqli_real_escape_string($db, trim($_POST['zip_code'] ?? ''));
    $new_password = $_POST['password'] ?? '';

    $errors = [];
    if (empty($name)) $errors[] = "Full Name is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";

    if (empty($errors)) {
        $password_sql = "";
        if (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $password_sql = ", password = '$hashed'";
        }

        $update_sql = "UPDATE users SET 
            name = '$name', 
            phone = '$phone', 
            dob = " . ($dob ? "'$dob'" : "NULL") . ", 
            address = '$address', 
            city = '$city', 
            state = '$state', 
            country = '$country', 
            zip_code = '$zip_code', 
            profile_completed = 1 
            $password_sql 
            WHERE id = $user_id";

        if (mysqli_query($db, $update_sql)) {
            $_SESSION['user_name'] = $name;
            $_SESSION['profile_completed'] = 1;
            
            // Sync with customer_master (where email matches user email)
            $user_email = mysqli_real_escape_string($db, $_SESSION['user_email']);
            mysqli_query($db, "UPDATE customer_master SET 
                name = '$name', 
                mobile = '$phone', 
                address = '$address' 
                WHERE email = '$user_email'");

            header("Location: dashboard.php");
            exit;
        } else {
            $message = "Database Error: " . mysqli_error($db);
            $message_type = "error";
        }
    } else {
        $message = implode(" ", $errors);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Profile | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            background:
                linear-gradient(rgba(7, 27, 43, .58), rgba(7, 27, 43, .58)),
                url('../assets/images/login-travel-bg.png') center / cover no-repeat fixed;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }

        .complete-profile-container {
            width: min(95vw, 1150px);
            margin: 20px auto;
            box-sizing: border-box;
        }

        .complete-profile-card {
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 24px 60px rgba(3, 16, 27, 0.28);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .card-header-icon {
            font-size: 28px;
            width: 46px;
            height: 46px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-header-text h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 3px;
            color: #0d283f;
        }

        .card-header-text p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .card-body {
            padding: 28px;
        }

        .profile-form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .profile-form-grid .span-2 {
            grid-column: span 2;
        }

        .profile-form-grid .span-4 {
            grid-column: span 4;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            color: #475569;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background: rgba(0, 0, 0, .02);
            box-sizing: border-box;
            font-family: inherit;
            transition: border-color 0.2s, background-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #0d283f;
            background: #ffffff;
            outline: none;
        }

        @media (max-width: 992px) {
            .profile-form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .profile-form-grid .span-2,
            .profile-form-grid .span-4 {
                grid-column: span 2;
            }
        }

        @media (max-width: 600px) {
            .profile-form-grid {
                grid-template-columns: 1fr;
            }
            .profile-form-grid .span-2,
            .profile-form-grid .span-4 {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>

<div class="complete-profile-container">
    <div class="complete-profile-card">
        <div class="card-header">
            <div class="card-header-icon">👤</div>
            <div class="card-header-text">
                <h1>Complete Your Profile</h1>
                <p>Please complete your account details before accessing the workspace.</p>
            </div>
        </div>

        <div class="card-body">
            <?php if (!empty($message)): ?>
                <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 13px;
                    background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
                    color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
                    border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" class="profile-form-grid">
                <!-- Row 1 -->
                <div class="form-group">
                    <label for="name">Full Name <span style="color: red;">*</span></label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                </div>

                <div class="form-group">
                    <label for="phone">Phone / Mobile <span style="color: red;">*</span></label>
                    <input type="text" id="phone" name="phone" placeholder="e.g. +919999999999" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="dob">Date of Birth</label>
                    <input type="date" id="dob" name="dob" value="<?php echo htmlspecialchars($user['dob'] ?? ''); ?>">
                </div>

                <!-- Row 2 -->
                <div class="form-group span-2">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="1" placeholder="Enter full address..." style="height: 42px; resize: none;"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                </div>

                <!-- Row 3 -->
                <div class="form-group">
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($user['country'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="zip_code">Zip / Postal Code</label>
                    <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($user['zip_code'] ?? ''); ?>">
                </div>

                <div class="form-group span-2">
                    <label for="password">Change Password</label>
                    <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                </div>

                <!-- Row 4: Actions -->
                <div class="span-4" style="margin-top: 10px; display: flex; gap: 12px; justify-content: flex-end; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <a href="../login.php?logout=1" style="padding: 10px 20px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; color: #475569; font-weight: 600; text-decoration: none;">Logout</a>
                    <button type="submit" name="complete_profile" style="padding: 10px 24px; background: #0d283f; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Save & Continue to Workspace</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
