<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";

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

        header("Location: list.php");
        exit;
    } else {
        $message = "Error deleting customer: " . mysqli_error($db);
    }
}

$query = "SELECT * FROM customer_master WHERE id = $id";
$result = mysqli_query($db, $query);
$cust = mysqli_fetch_assoc($result);

if (!$cust) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Customer not found.</h2><a href='list.php'>Back to list</a></div>";
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
if ($cust['customer_type'] === 'User' && !empty($cust['email'])) {
    $esc_email = mysqli_real_escape_string($db, $cust['email']);
    $user_res = mysqli_query($db, "SELECT role FROM users WHERE email = '$esc_email'");
    if ($user_res && mysqli_num_rows($user_res) > 0) {
        $user_row = mysqli_fetch_assoc($user_res);
        $user_role_val = $user_row['role'] === 'admin' ? 'Admin (Full Control)' : 'Agent (User Console)';
    }
}

$success = isset($_GET['success']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Details | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .detail-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow-sm);
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 600px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
        .detail-item {
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 4px;
        }
        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
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
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Customer Details #<?php echo $cust['id']; ?></h1>
        <div class="time-btns" style="display: flex; gap: 8px;">
            <a href="edit.php?id=<?php echo $cust['id']; ?>" class="btn">✏ Edit Customer</a>
            <a href="list.php" class="btn btn-secondary">← Back to List</a>
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
            ✅ Customer details updated successfully.
        </div>
    <?php endif; ?>

    <div class="detail-card">
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

            <div class="detail-item">
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

        <div style="border-top: 1px solid #f1f5f9; padding-top: 20px; display: flex; justify-content: flex-end;">
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this customer permanently?');">
                <button type="submit" name="delete" class="btn" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer;">🗑 Delete Customer</button>
            </form>
        </div>
    </div>

    <!-- Bookings Table card -->
    <div class="card" style="margin-top: 24px; padding: 24px;">
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

</body>
</html>
