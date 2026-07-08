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

$message = "";
$message_type = "";

if (isset($_POST["update_enquiry"])) {
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $subject = mysqli_real_escape_string($db, $_POST["subject"]);
    $description = mysqli_real_escape_string($db, $_POST["description"]);
    $source = mysqli_real_escape_string($db, $_POST["source"]);
    $status = mysqli_real_escape_string($db, $_POST["status"]);

    if (empty($customer_name)) {
        $message = "Customer Name is required.";
        $message_type = "error";
    } else {
        $update_query = "UPDATE enquiries SET 
            customer_name = '$customer_name', 
            mobile = '$mobile', 
            email = '$email', 
            subject = '$subject', 
            description = '$description', 
            source = '$source', 
            status = '$status' 
            WHERE id = $id";
        if (mysqli_query($db, $update_query)) {
            header("Location: enquiry.php?success=1");
            exit;
        } else {
            $message = "Error updating enquiry: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Enquiry | Travel CRM</title>
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
        <h1>Edit Enquiry #<?php echo $enquiry['id']; ?></h1>
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
                <input type="text" id="customer_name" name="customer_name" required value="<?php echo htmlspecialchars($enquiry['customer_name']); ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile Number</label>
                    <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($enquiry['mobile']); ?>">
                </div>
                <div class="form-group" style="flex: 0.7;">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($enquiry['email']); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="subject">Subject / Product Interested</label>
                <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($enquiry['subject']); ?>">
            </div>

            <div class="form-group">
                <label for="description">Enquiry Details</label>
                <textarea id="description" name="description" style="min-height: 240px;"><?php echo htmlspecialchars($enquiry['description']); ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="source">Source</label>
                    <select id="source" name="source">
                        <option value="Direct" <?php if ($enquiry['source'] == 'Direct') echo 'selected'; ?>>Direct / Walk-in</option>
                        <option value="Website" <?php if ($enquiry['source'] == 'Website') echo 'selected'; ?>>Website</option>
                        <option value="WhatsApp" <?php if ($enquiry['source'] == 'WhatsApp') echo 'selected'; ?>>WhatsApp</option>
                        <option value="Referral" <?php if ($enquiry['source'] == 'Referral') echo 'selected'; ?>>Referral</option>
                        <option value="Social Media" <?php if ($enquiry['source'] == 'Social Media') echo 'selected'; ?>>Social Media</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="New" <?php if ($enquiry['status'] == 'New') echo 'selected'; ?>>New</option>
                        <option value="Follow-up" <?php if ($enquiry['status'] == 'Follow-up') echo 'selected'; ?>>Follow-up</option>
                        <option value="Converted" <?php if ($enquiry['status'] == 'Converted') echo 'selected'; ?>>Converted</option>
                        <option value="Cancelled" <?php if ($enquiry['status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <button type="submit" name="update_enquiry" class="btn" style="width: 100%; margin-top: 10px; background: var(--sidebar-link-active-bg);">
                💾 Update Enquiry Details
            </button>
        </form>
    </div>
</div>

</body>
</html>
