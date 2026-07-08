<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$user_id = intval($_SESSION['user_id'] ?? 0);
$message = "";
$message_type = "";

// Handle Update Request
if (isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($db, $_POST['name']);
    $email = mysqli_real_escape_string($db, $_POST['email']);
    
    // Check password
    $password_sql = "";
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm = $_POST['confirm'] ?? '';
        if ($password === $confirm) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $hashed = mysqli_real_escape_string($db, $hashed);
            $password_sql = ", password = '$hashed'";
        } else {
            $message = "Passwords do not match!";
            $message_type = "error";
        }
    }
    
    if (empty($message)) {
        $update_query = "UPDATE users SET name = '$name', email = '$email' $password_sql WHERE id = $user_id";
        if (mysqli_query($db, $update_query)) {
            $_SESSION['user_name'] = $name;
            $message = "Profile details updated successfully!";
            $message_type = "success";
        } else {
            $message = "Database Error: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}

// Fetch current logged-in user details
$admin_query = mysqli_query($db, "SELECT * FROM users WHERE id = $user_id");
$admin = mysqli_fetch_assoc($admin_query);

if (!$admin) {
    $admin = [
        'name' => $_SESSION['user_name'] ?? 'Admin User',
        'email' => 'admin@gmail.com',
        'role' => 'admin'
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Profile | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="../dashboard.php">Dashboard</a>
    <a href="../master/list.php">Master</a>
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
        <a href="index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Administrator Profile</h1>
        <a href="../dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;
            background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>;
            color: <?php echo $message_type === 'success' ? '#065f46' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type === 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bottom-grid" style="grid-template-columns: 2fr 1fr; gap: 30px;">
        <!-- Left: Form Mock -->
        <div class="card">
            <h2 style="font-size: 18px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Security & Account Settings</h2>
            
            <form method="POST">
                <div class="form-group">
                    <label for="name">Admin Name</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Change Password</label>
                        <input type="password" id="password" name="password" placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label for="confirm">Confirm Password</label>
                        <input type="password" id="confirm" name="confirm" placeholder="••••••••">
                    </div>
                </div>

                <button type="submit" name="update_profile">Update Administrator Profile</button>
            </form>
        </div>

        <!-- Right Card -->
        <div class="card" style="text-align: center; padding: 40px 24px;">
            <div style="width: 90px; height: 90px; border-radius: 50%; background: #fee2e2; color: #b91c1c; font-size: 40px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; font-weight: 700; border: 4px solid #fff; box-shadow: var(--shadow-md);">
                <?php echo strtoupper(substr($admin['name'] ?: 'A', 0, 1)); ?>
            </div>
            
            <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 4px;"><?php echo htmlspecialchars($admin['name']); ?></h2>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 16px;"><?php echo htmlspecialchars($admin['email']); ?></p>
            
            <span class="badge completed" style="text-transform: uppercase; font-size: 11px; padding: 6px 12px; letter-spacing: 0.5px;">
                Role: <?php echo htmlspecialchars(ucfirst($admin['role'])); ?>
            </span>

            <div style="margin-top: 32px; border-top: 1px solid var(--border-color); padding-top: 24px; text-align: left;">
                <h4 style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">System Access</h4>
                <div class="d-flex align-center gap-2" style="font-size: 13px; color: var(--status-booked-text);">
                    <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                    Root Administrator Access
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
