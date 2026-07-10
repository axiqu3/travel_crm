<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch enquiry
$query = "SELECT * FROM enquiries WHERE id = $id";
$result = mysqli_query($db, $query);
$enquiry = mysqli_fetch_assoc($result);

if (!$enquiry) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Enquiry not found.</h2><a href='list.php'>Back to list</a></div>";
    exit;
}

$message = "";
$message_type = "";

// Fetch agents list
$agents_res = mysqli_query($db, "SELECT username FROM users ORDER BY username ASC");

if (isset($_POST["update_enquiry"])) {
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $subject = mysqli_real_escape_string($db, $_POST["subject"]);
    $description = mysqli_real_escape_string($db, $_POST["description"]);
    $source = mysqli_real_escape_string($db, $_POST["source"]);
    $status = mysqli_real_escape_string($db, $_POST["status"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"]);

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
            status = '$status',
            assigned_user = '$assigned_user'" . (strtolower($status) === 'seen' ? ", updated_at = updated_at" : ", updated_at = NOW()") . " 
            WHERE id = $id";
        if (mysqli_query($db, $update_query)) {
            header("Location: list.php?success=1");
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
    <title>Edit Enquiry | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Edit Enquiry #<?php echo $enquiry['id']; ?></h1>
        <a href="list.php" class="btn btn-secondary">Back to List</a>
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
                        <option value="Seen" <?php if ($enquiry['status'] == 'Seen') echo 'selected'; ?>>Seen</option>
                        <option value="Replied" <?php if ($enquiry['status'] == 'Replied') echo 'selected'; ?>>Replied</option>
                        <option value="Follow-up" <?php if ($enquiry['status'] == 'Follow-up') echo 'selected'; ?>>Follow-up</option>
                        <option value="Converted" <?php if ($enquiry['status'] == 'Converted') echo 'selected'; ?>>Converted</option>
                        <option value="Cancelled" <?php if ($enquiry['status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                        <option value="Booked" <?php if ($enquiry['status'] == 'Booked') echo 'selected'; ?>>Booked</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="assigned_user">Assign Agent</label>
                <select id="assigned_user" name="assigned_user">
                    <option value="">-- Unassigned --</option>
                    <?php while ($agent = mysqli_fetch_assoc($agents_res)): ?>
                        <option value="<?php echo htmlspecialchars($agent['username']); ?>" <?php if ($enquiry['assigned_user'] === $agent['username']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($agent['username']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" name="update_enquiry" class="btn" style="width: 100%; margin-top: 10px; background: var(--sidebar-link-active-bg);">
                💾 Update Enquiry Details
            </button>
        </form>
    </div>
</div>

</body>
</html>

