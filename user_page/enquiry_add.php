<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$message = "";
$message_type = "";

if (isset($_POST["save_enquiry"])) {
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $subject = mysqli_real_escape_string($db, $_POST["subject"]);
    $description = mysqli_real_escape_string($db, $_POST["description"]);
    $source = mysqli_real_escape_string($db, $_POST["source"]);
    $status = mysqli_real_escape_string($db, $_POST["status"]);
    
    $created_by = $_SESSION['user_name'] ?? 'System';
    $assigned_user = $created_by; // Default assignment to creator

    if (empty($customer_name)) {
        $message = "Customer Name is required.";
        $message_type = "error";
    } else {
        $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by) 
                  VALUES ('$customer_name', '$mobile', '$email', '$subject', '$description', '$source', '$status', '$assigned_user', '$created_by')";
        if (mysqli_query($db, $query)) {
            header("Location: enquiry.php?success=1");
            exit;
        } else {
            $message = "Error creating enquiry: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Enquiry | Travel CRM</title>
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
        <h1>Add New Enquiry</h1>
        <a href="enquiry.php" class="btn btn-secondary">Back to List</a>
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

    <div class="card" style="max-width: 600px; margin: 0 auto; padding: 24px;">
        <form method="POST" action="">
            <div class="form-group">
                <label for="customer_name">Customer Name *</label>
                <input type="text" id="customer_name" name="customer_name" required placeholder="e.g. John Doe">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile Number</label>
                    <input type="text" id="mobile" name="mobile" placeholder="e.g. +1234567890">
                </div>
                <div class="form-group" style="flex: 0.7;">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="e.g. john@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="subject">Subject / Product Interested</label>
                <input type="text" id="subject" name="subject" placeholder="e.g. Europe Tour Package 7 Days">
            </div>

            <div class="form-group">
                <label for="description">Enquiry Details</label>
                <textarea id="description" name="description" placeholder="Travel details, budget, preferences..." style="min-height: 240px;"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="source">Source</label>
                    <select id="source" name="source">
                        <option value="Direct">Direct / Walk-in</option>
                        <option value="Website">Website</option>
                        <option value="WhatsApp">WhatsApp</option>
                        <option value="Referral">Referral</option>
                        <option value="Social Media">Social Media</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="New">New</option>
                        <option value="Follow-up">Follow-up</option>
                        <option value="Converted">Converted</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <button type="submit" name="save_enquiry" class="btn" style="width: 100%; margin-top: 10px; background: var(--sidebar-link-active-bg);">
                💾 Save Enquiry
            </button>
        </form>
    </div>
</div>

</body>
</html>
