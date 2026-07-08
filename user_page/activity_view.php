<?php
require_once(__DIR__ . "/../includes/db.php");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$query = "SELECT * FROM activity_log WHERE id = $id";
$result = mysqli_query($db, $query);
$activity = mysqli_fetch_assoc($result);

if (!$activity) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Activity record not found.</h2><a href='activity.php'>Back to list</a></div>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Activity Details | Travel CRM</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="bookings.php">My Bookings</a>
    <a href="bookings_add.php">Add Booking</a>
    <a href="customers.php">Customers</a>
    <a href="enquiry.php"<?= (in_array(basename($_SERVER['PHP_SELF']), ['enquiry.php', 'enquiry_add.php', 'enquiry_edit.php', 'enquiry_view.php'])) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="activity.php" class="active">My Tasks</a>
    <a href="../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="profile.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Activity Details</h1>
        <a href="activity.php" class="btn btn-secondary">Back to List</a>
    </div>
    
    <hr>

    <div class="card" style="max-width: 600px;">
        <h2 style="font-size: 18px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            Log Entry #<?php echo $activity["id"]; ?>
        </h2>

        <div style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 10px;">
            <div>
                <h4 style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">User Account</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($activity["username"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Action Performed</h4>
                <p style="font-size: 15px; font-weight: 600; color: var(--accent-color);"><?php echo htmlspecialchars($activity["action"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">System Module</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($activity["module"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Timestamp</h4>
                <p style="font-size: 15px; font-weight: 500; color: var(--text-secondary);"><?php echo htmlspecialchars($activity["activity_date"]); ?></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
