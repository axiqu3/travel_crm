<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$message_type = "";

// Fetch customer details
$query = "SELECT * FROM customers WHERE id = $id";
$result = mysqli_query($db, $query);
$customer = mysqli_fetch_assoc($result);

if (!$customer) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Customer not found.</h2><a href='customers.php'>Back to list</a></div>";
    exit;
}

if (isset($_POST["update_customer"])) {
    $name = mysqli_real_escape_string($db, $_POST["name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $address = mysqli_real_escape_string($db, $_POST["address"]);
    $notes = mysqli_real_escape_string($db, $_POST["notes"]);

    if (empty($name)) {
        $message = "Customer Name is required.";
        $message_type = "error";
    } else {
        $update_query = "UPDATE customers SET 
                            name = '$name', 
                            mobile = '$mobile', 
                            email = '$email', 
                            address = '$address', 
                            notes = '$notes' 
                         WHERE id = $id";
        if (mysqli_query($db, $update_query)) {
            header("Location: customers.php?success=1");
            exit;
        } else {
            $message = "Error updating customer: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Customer | Travel CRM</title>
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
        <h1>Edit Customer</h1>
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
                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($customer['name']); ?>" placeholder="e.g. John Doe">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile Number</label>
                    <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($customer['mobile']); ?>" placeholder="e.g. +1234567890">
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($customer['email']); ?>" placeholder="e.g. john@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" placeholder="Customer's physical address..."><?php echo htmlspecialchars($customer['address']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="notes">Notes / Remarks</label>
                <textarea id="notes" name="notes" placeholder="Additional notes or references..."><?php echo htmlspecialchars($customer['notes']); ?></textarea>
            </div>

            <div style="margin-top: 24px; text-align: right;">
                <button type="submit" name="update_customer" class="btn">Update Customer</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
