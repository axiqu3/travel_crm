<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$user_name = $_SESSION['user_name'] ?? '';

// Fetch metrics dynamically (personalized to the logged-in agent)
$today_bookings_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE DATE(booking_date) = CURDATE() AND assigned_user = '" . mysqli_real_escape_string($db, $user_name) . "'");
$today_bookings = mysqli_fetch_assoc($today_bookings_query)['count'];

$today_profit_query = mysqli_query($db, "SELECT SUM(profit) as profit FROM bookings WHERE DATE(booking_date) = CURDATE() AND assigned_user = '" . mysqli_real_escape_string($db, $user_name) . "'");
$today_profit = mysqli_fetch_assoc($today_profit_query)['profit'] ?: 0;

$month_profit_query = mysqli_query($db, "SELECT SUM(profit) as profit FROM bookings WHERE MONTH(booking_date) = MONTH(CURDATE()) AND YEAR(booking_date) = YEAR(CURDATE()) AND assigned_user = '" . mysqli_real_escape_string($db, $user_name) . "'");
$month_profit = mysqli_fetch_assoc($month_profit_query)['profit'] ?: 0;

$pending_payments_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE status = 'Pending' AND assigned_user = '" . mysqli_real_escape_string($db, $user_name) . "'");
$pending_payments = mysqli_fetch_assoc($pending_payments_query)['count'];

// Fetch recent bookings
$recent_bookings = mysqli_query($db, "SELECT * FROM bookings WHERE assigned_user = '" . mysqli_real_escape_string($db, $user_name) . "' ORDER BY id DESC LIMIT 5");

// Fetch agent's own assigned tasks (limit 5)
$assigned_tasks_query = mysqli_query($db, "SELECT * FROM tasks WHERE assigned_user_id = " . intval($_SESSION['user_id']) . " OR assigned_user_id IS NULL ORDER BY id DESC LIMIT 5");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard | Travel CRM</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="bookings.php">My Bookings</a>
    <a href="bookings_add.php">Add Booking</a>
    <a href="customers.php">Customers</a>
    <a href="enquiry.php"<?= (in_array(basename($_SERVER['PHP_SELF']), ['enquiry.php', 'enquiry_add.php', 'enquiry_edit.php', 'enquiry_view.php'])) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="activity.php">My Tasks</a>
    <a href="../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search bookings...">
        <span class="notify">🔔</span>
        <a href="profile.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Agent Dashboard</h1>
    </div>

    <!-- Stat Cards -->
    <div class="cards-row">
        <div class="card">
            <h2>Today's Bookings</h2>
            <h1><?php echo $today_bookings; ?></h1>
            <span class="change green">My entries created today</span>
        </div>

        <div class="card">
            <h2>Today's Profit</h2>
            <h1 style="color: var(--status-booked-text);">₹<?php echo number_format($today_profit, 2); ?></h1>
            <span class="change green">My cleared revenue today</span>
        </div>

        <div class="card">
            <h2>Monthly Profit</h2>
            <h1 style="color: var(--status-booked-text);">₹<?php echo number_format($month_profit, 2); ?></h1>
            <span class="change green">My monthly cleared profit</span>
        </div>

        <div class="card">
            <h2>Pending Actions</h2>
            <h1 style="color: var(--status-pending-text);"><?php echo $pending_payments; ?></h1>
            <span class="change red">My action items pending</span>
        </div>
    </div>

    <!-- Bottom Layout -->
    <div class="bottom-grid" style="grid-template-columns: 1fr;">
        <div class="table-card">
            <h2 style="margin-bottom: 16px;">My Recent Bookings</h2>
            <table>
                <thead>
                    <tr>
                        <th>Serial No</th>
                        <th>Passenger</th>
                        <th>PNR</th>
                        <th>Service</th>
                        <th>Profit</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($recent_bookings) > 0): ?>
                        <?php while ($booking = mysqli_fetch_assoc($recent_bookings)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($booking["serial_no"]); ?></td>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($booking["passenger_name"]); ?></td>
                            <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($booking["pnr"]); ?></td>
                            <td><?php echo htmlspecialchars($booking["service_type"]); ?></td>
                            <td style="font-weight: 700; color: var(--status-booked-text);">₹<?php echo number_format($booking["profit"], 2); ?></td>
                            <td>
                                <span class="badge <?php echo strtolower($booking["status"]); ?>">
                                    <?php echo htmlspecialchars($booking["status"]); ?>
                                </span>
                            </td>
                            <td>
                                <a href="bookings_view.php?id=<?php echo $booking['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 30px 0;">
                                No bookings recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                 </tbody>
            </table>
        </div>
    </div>

    <!-- Assigned Tasks Card -->
    <div class="table-card" style="margin-top: 24px;">
        <div class="d-flex justify-between align-center" style="margin-bottom: 16px;">
            <h2 style="margin: 0; font-size: 14px; font-weight: 700; color: var(--text-primary);">📋 My Assigned Tasks</h2>
            <a href="activity.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px;">View All Tasks</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Task Title</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Date Assigned</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($assigned_tasks_query) > 0): ?>
                    <?php while ($task = mysqli_fetch_assoc($assigned_tasks_query)): ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($task["title"]); ?></td>
                        <td style="color: var(--text-secondary); font-size: 13px;"><?php echo htmlspecialchars($task["description"]); ?></td>
                        <td>
                            <span class="badge <?php echo strtolower($task['status']) === 'completed' ? 'booked' : 'cancelled'; ?>" style="font-size: 11px;">
                                <?php echo htmlspecialchars($task["status"]); ?>
                            </span>
                        </td>
                        <td style="color: var(--text-secondary);"><?php echo !empty($task["created_at"]) ? date('M d, Y', strtotime($task["created_at"])) : '—'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                            No tasks assigned to you.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>