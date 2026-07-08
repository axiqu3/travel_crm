<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch customer details
$query = "SELECT * FROM customers WHERE id = $id";
$result = mysqli_query($db, $query);
$customer = mysqli_fetch_assoc($result);

if (!$customer) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Customer not found.</h2><a href='customers.php'>Back to list</a></div>";
    exit;
}

$wa_url = get_whatsapp_url($customer['mobile'], "Hello " . $customer['name'] . ",");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Profile | Travel CRM</title>
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
    <a href="customers.php" class="active">Customers</a>
    <a href="enquiry.php"<?= (in_array(basename($_SERVER['PHP_SELF']), ['enquiry.php', 'enquiry_add.php', 'enquiry_edit.php', 'enquiry_view.php'])) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="activity.php">My Tasks</a>
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
        <h1>Customer Profile</h1>
        <div class="time-btns d-flex gap-2 align-center">
            <a href="customers.php" class="btn btn-secondary">Back to List</a>
            <a href="customers_edit.php?id=<?php echo $customer['id']; ?>" class="btn" style="background: var(--sidebar-link-active-bg);">Edit Details</a>
        </div>
    </div>
    
    <hr>

    <div class="card" style="max-width: 700px; margin: 0 auto; padding: 24px;">
        <h2 style="font-size: 22px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; color: var(--accent-color);">
            👤 <?php echo htmlspecialchars($customer["name"]); ?>
        </h2>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Customer ID</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($customer["id"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Created Date</h4>
                <p style="font-size: 15px; font-weight: 600; color: var(--text-secondary);"><?php echo htmlspecialchars($customer["created_at"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Mobile Number</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($customer["mobile"] ?: 'Not Provided'); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Email Address</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($customer["email"] ?: 'Not Provided'); ?></p>
            </div>
        </div>

        <div style="margin-bottom: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
            <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px;">Address</h4>
            <p style="font-size: 14px; font-weight: 500; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #eaedf2; min-height: 50px; white-space: pre-wrap;"><?php echo htmlspecialchars($customer["address"] ?: 'No address specified'); ?></p>
        </div>

        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px;">Notes / Remarks</h4>
            <p style="font-size: 14px; font-weight: 500; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #eaedf2; min-height: 80px; white-space: pre-wrap;"><?php echo htmlspecialchars($customer["notes"] ?: 'No notes available'); ?></p>
        </div>
    </div>
</div>

</body>
</html>
