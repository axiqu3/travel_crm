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

if ($enquiry['status'] === 'New') {
    mysqli_query($db, "UPDATE enquiries SET status = 'Seen', updated_at = updated_at WHERE id = $id");
    $enquiry['status'] = 'Seen';
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Enquiry Details | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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
        <h1>Enquiry Details</h1>
        <div class="time-btns">
            <a href="list.php" class="btn btn-secondary">Back to List</a>
            <a href="edit.php?id=<?php echo $enquiry['id']; ?>" class="btn" style="background: var(--sidebar-link-active-bg);">Edit Enquiry</a>
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
            
            <form method="POST" action="delete.php?id=<?php echo $enquiry['id']; ?>" onsubmit="return confirm('Are you sure you want to delete this enquiry permanently?');" style="margin-left: auto;">
                <button type="submit" class="btn btn-danger" style="background: #ef4444; border: none; color: white;">
                    🗑 Delete Enquiry
                </button>
            </form>
        </div>
    </div>
</div>

</body>
</html>

