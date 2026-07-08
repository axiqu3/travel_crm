<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$message = "";
$message_type = "";

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

// Fetch Filter Value
$filter_type = isset($_GET['customer_type']) ? mysqli_real_escape_string($db, $_GET['customer_type']) : '';

$where_sql = "";
if ($filter_type !== '') {
    $where_sql = " WHERE customer_type = '$filter_type'";
}

$query = "SELECT * FROM customer_master $where_sql ORDER BY id DESC";
$data = mysqli_query($db, $query);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Master | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
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
        <input class="search" placeholder="Search customer...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Customer Master</h1>
        <a href="add.php" class="btn">+ Add Customer</a>
    </div>
    
    <div class="card" style="margin-bottom: 20px; padding: 20px;">
        <form method="GET" action="" style="display: flex; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; max-width: 250px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Filter by Customer Type</label>
                <select name="customer_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Types</option>
                    <option value="Walk-in Customer" <?php echo $filter_type === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                    <option value="B2B" <?php echo $filter_type === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                    <option value="Corporate" <?php echo $filter_type === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                    <option value="User" <?php echo $filter_type === 'User' ? 'selected' : ''; ?>>User</option>
                </select>
            </div>
            <button type="submit" class="btn" style="padding: 10px 20px; height: 38px; cursor: pointer;">🔍 Filter</button>
            <a href="list.php" class="btn btn-secondary" style="padding: 10px 20px; height: 38px; display: inline-flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.1); border: 1px solid var(--border-dark); color: var(--text-main);">Reset</a>
        </form>
    </div>

    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding:12px 20px;border-radius:12px;margin-bottom:20px;font-weight:600;background:<?php echo $message_type == 'success' ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo $message_type == 'success' ? '#065f46' : '#991b1b'; ?>;border:1px solid <?php echo $message_type == 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer Type</th>
                    <th>Name / Details</th>
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
                    <td colspan="8" style="text-align: center; color: var(--text-secondary); padding: 30px;">No customers found.</td>
                </tr>
                <?php endif; ?>
                <?php while ($cust = mysqli_fetch_assoc($data)): 
                    $badge_class = 'badge-walk-in';
                    if ($cust['customer_type'] === 'B2B') $badge_class = 'badge-b2b';
                    elseif ($cust['customer_type'] === 'Corporate') $badge_class = 'badge-corporate';
                    elseif ($cust['customer_type'] === 'User') $badge_class = 'badge-user';
                    
                    // Fetch role for User type
                    $user_role_disp = '';
                    if ($cust['customer_type'] === 'User' && !empty($cust['email'])) {
                        $esc_email = mysqli_real_escape_string($db, $cust['email']);
                        $user_res = mysqli_query($db, "SELECT role FROM users WHERE email = '$esc_email'");
                        if ($user_res && mysqli_num_rows($user_res) > 0) {
                            $user_row = mysqli_fetch_assoc($user_res);
                            $user_role_disp = $user_row['role'] === 'admin' ? 'Admin' : 'Agent';
                        }
                    }

                    // Status styling
                    $status = $cust['status'] ?? 'Active';
                    $status_badge_class = $status === 'Active' ? 'badge-status-active' : 'badge-status-inactive';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($cust["id"]); ?></td>
                    <td>
                        <span class="badge <?php echo $badge_class; ?>">
                            <?php echo htmlspecialchars($cust["customer_type"]); ?>
                        </span>
                    </td>
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
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
