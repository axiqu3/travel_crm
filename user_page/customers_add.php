<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$message = "";
$message_type = "";

if (isset($_POST["save_customer"])) {
    ensure_column_exists($db, 'customers', 'notes', 'TEXT NULL');
    $name = mysqli_real_escape_string($db, $_POST["name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $address = mysqli_real_escape_string($db, $_POST["address"]);
    $notes = mysqli_real_escape_string($db, $_POST["notes"]);

    if (empty($name)) {
        $message = "Customer Name is required.";
        $message_type = "error";
    } else {
        $query = "INSERT INTO customers (name, mobile, email, address, notes) 
                  VALUES ('$name', '$mobile', '$email', '$address', '$notes')";
        if (mysqli_query($db, $query)) {
            header("Location: customers.php?success=1");
            exit;
        } else {
            $message = "Error creating customer: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Customer | Travel CRM</title>
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
        <h1>Add New Customer</h1>
        <a href="customers.php" class="btn btn-secondary">Back to List</a>
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

    <div class="card" style="max-width: 600px; margin: 0 auto;">
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Customer Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. John Doe">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile Number</label>
                    <input type="text" id="mobile" name="mobile" placeholder="e.g. +1234567890">
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="e.g. john@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" placeholder="Customer's physical address..."></textarea>
            </div>

            <div class="form-group">
                <label for="notes">Notes / Remarks</label>
                <textarea id="notes" name="notes" placeholder="Additional notes or references..."></textarea>
            </div>

            <div style="margin-top: 24px; text-align: right;">
                <button type="submit" name="save_customer" class="btn">Confirm & Save Customer</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
