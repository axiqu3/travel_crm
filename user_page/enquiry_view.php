<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_name = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? '');

// Fetch enquiry and check ownership
$query = "SELECT * FROM enquiries WHERE id = $id";
$result = mysqli_query($db, $query);
$enquiry = mysqli_fetch_assoc($result);

if (!$enquiry) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Enquiry not found or access denied.</h2><a href='enquiry.php'>Back to list</a></div>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enquiry Details | Travel CRM</title>
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
    <a href="enquiry.php" class="active">Enquiry</a>
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
        <h1>Enquiry Details</h1>
        <div class="time-btns">
            <a href="enquiry.php" class="btn btn-secondary">Back to List</a>
            <a href="enquiry_edit.php?id=<?php echo $enquiry['id']; ?>" class="btn" style="background: var(--sidebar-link-active-bg);">Edit Enquiry</a>
        </div>
    </div>
    
    <hr>

    <div class="card" style="max-width: 700px; margin: 0 auto; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px;">
            <h2 style="font-size: 22px; color: var(--accent-color); margin: 0;">
                💬 <?php echo htmlspecialchars($enquiry["customer_name"]); ?>
            </h2>
            <span class="badge <?php echo strtolower($enquiry["status"] ?: 'new'); ?>">
                <?php echo htmlspecialchars($enquiry["status"] ?: 'New'); ?>
            </span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Enquiry ID</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($enquiry["id"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Created Date</h4>
                <p style="font-size: 15px; font-weight: 600; color: var(--text-secondary);"><?php echo htmlspecialchars($enquiry["created_at"]); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Mobile Number</h4>
                <div style="font-size: 15px; font-weight: 600; display: flex; flex-direction: column; gap: 4px; align-items: start;">
                    <span><?php echo htmlspecialchars($enquiry["mobile"] ?: 'Not Provided'); ?></span>
                    <?php 
                    $wa_vars = [
                        'customer' => $enquiry['customer_name'],
                        'service' => $enquiry['subject']
                    ];
                    echo get_whatsapp_dropdown($enquiry['mobile'], $wa_vars);
                    ?>
                </div>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Email Address</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($enquiry["email"] ?: 'Not Provided'); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Source</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($enquiry["source"] ?: 'Direct'); ?></p>
            </div>
            <div>
                <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Assigned Agent</h4>
                <p style="font-size: 15px; font-weight: 600;"><?php echo htmlspecialchars($enquiry["assigned_user"] ?: '-'); ?></p>
            </div>
        </div>

        <div style="margin-bottom: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
            <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Subject / Product of Interest</h4>
            <p style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;"><?php echo htmlspecialchars($enquiry["subject"] ?: '-'); ?></p>
        </div>

        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px;">Details / Description</h4>
            <p style="font-size: 14px; font-weight: 500; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #eaedf2; min-height: 80px; white-space: pre-wrap; margin: 0;"><?php echo htmlspecialchars($enquiry["description"] ?: 'No details specified'); ?></p>
        </div>

        <div style="border-top: 1px solid var(--border-color); padding-top: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <?php 
            $wa_vars = [
                'customer' => $enquiry['customer_name'],
                'service' => $enquiry['subject']
            ];
            echo get_whatsapp_dropdown($enquiry['mobile'], $wa_vars);
            ?>
        </div>
    </div>
</div>

</body>
</html>
