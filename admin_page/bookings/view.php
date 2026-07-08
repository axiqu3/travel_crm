<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$message_type = "";

// Handle Delete Booking Request
if (isset($_POST['delete'])) {
    $delete_query = "DELETE FROM bookings WHERE id = $id";
    if (mysqli_query($db, $delete_query)) {
        header("Location: list.php");
        exit;
    } else {
        $message = "Error deleting booking: " . mysqli_error($db);
        $message_type = "error";
    }
}

// Handle Attachment Upload Request
if (isset($_POST['upload_attachment'])) {
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . "/../../uploads/attachments/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_type = mysqli_real_escape_string($db, $_POST['file_type']);
        $custom_name = mysqli_real_escape_string($db, $_POST['custom_name']);
        
        $orig_name = basename($_FILES['attachment']['name']);
        $unique_name = time() . "_" . uniqid() . "_" . $orig_name;
        $target_path = $upload_dir . $unique_name;
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
            $db_path = "uploads/attachments/" . $unique_name;
            $disp_name = !empty($custom_name) ? $custom_name : $orig_name;
            
            $sql = "INSERT INTO booking_attachments (booking_id, file_path, file_name, file_type) VALUES ($id, '$db_path', '$disp_name', '$file_type')";
            if (mysqli_query($db, $sql)) {
                $user_for_log = $_SESSION['user_name'] ?? 'System';
                $log_query = "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', 'Uploaded Attachment ($file_type) for Booking #$id', 'Booking', NOW())";
                mysqli_query($db, $log_query);
                
                $message = "Attachment uploaded successfully.";
                $message_type = "success";
            } else {
                $message = "Error saving attachment to database: " . mysqli_error($db);
                $message_type = "error";
            }
        } else {
            $message = "Failed to save uploaded file.";
            $message_type = "error";
        }
    } else {
        $message = "Failed to upload file. Please select a valid file.";
        $message_type = "error";
    }
}

// Handle Attachment Delete Request
if (isset($_POST['delete_attachment']) && isset($_POST['attachment_id'])) {
    $attachment_id = intval($_POST['attachment_id']);
    $att_query = mysqli_query($db, "SELECT * FROM booking_attachments WHERE id = $attachment_id AND booking_id = $id");
    if ($att_query && mysqli_num_rows($att_query) > 0) {
        $att = mysqli_fetch_assoc($att_query);
        $file_to_delete = __DIR__ . "/../../" . $att['file_path'];
        if (file_exists($file_to_delete)) {
            @unlink($file_to_delete);
        }
        mysqli_query($db, "DELETE FROM booking_attachments WHERE id = $attachment_id");
        
        $user_for_log = $_SESSION['user_name'] ?? 'System';
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', 'Deleted Attachment (" . $att['file_type'] . ") from Booking #$id', 'Booking', NOW())";
        mysqli_query($db, $log_query);
        
        $message = "Attachment deleted successfully.";
        $message_type = "success";
    }
}

// Fetch booking details
$query = "SELECT * FROM bookings WHERE id = $id";
$result = mysqli_query($db, $query);
$booking = mysqli_fetch_assoc($result);

if (!$booking) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Booking not found.</h2><a href='list.php'>Back to list</a></div>";
    exit;
}

// Fetch attachments
$attachments_query = mysqli_query($db, "SELECT * FROM booking_attachments WHERE booking_id = $id ORDER BY id DESC");



$selling_cost = floatval($booking['selling_cost']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Booking Info | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .view-header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border-dark);
            border-radius: 8px;
            margin-bottom: 10px;
            background: var(--bg-card);
        }
        .attachment-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .attachment-icon {
            font-size: 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .info-card-item {
            padding: 12px;
            border-bottom: 1px solid var(--border-dark);
        }
        .info-card-item h4 {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .info-card-item p {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
            margin: 0;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="../dashboard.php">Dashboard</a>
    <a href="../master/list.php">Master</a>
    <a href="list.php" class="active">Bookings</a>
    <a href="add.php">Add Booking</a>
    <a href="reports.php">Reports</a>
    <a href="../enquiry/list.php"<?= (strpos($_SERVER['PHP_SELF'], '/enquiry/') !== false) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="../tasks/index.php">Tasks</a>
    <a href="../admin/activity.php">Activity</a>
    <a href="../../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Booking Overview</h1>
        <div class="time-btns">
            <a href="list.php" class="btn btn-secondary">Back to List</a>
            <a href="edit.php?id=<?php echo $booking['id']; ?>" class="btn">Edit Booking</a>
        </div>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Top Card: Header Details -->
    <div class="view-header-card d-flex justify-between align-center" style="flex-wrap: wrap; gap: 20px;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <span style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--accent-color); padding: 4px 8px; background: rgba(26, 115, 232, 0.1); border-radius: 6px;">
                    No. <?php echo htmlspecialchars($booking['serial_no']); ?>
                </span>
                <span class="badge <?php echo strtolower($booking["status"] ?: 'booked'); ?>">
                    <?php echo htmlspecialchars($booking["status"] ?: 'Booked'); ?>
                </span>
            </div>
            <h2 style="font-size: 24px; color: var(--text-main); font-weight: 700; margin: 0;">
                <?php echo htmlspecialchars($booking["passenger_name"]); ?>
            </h2>
        </div>
        <div style="font-size: 14px; color: var(--text-secondary); text-align: right;">
            Booking Date: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($booking["booking_date"]); ?></strong>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="bottom-grid" style="grid-template-columns: 2fr 1fr; gap: 24px;">
        
        <!-- Left: Flight & Ticket & Attachments -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Flight Details Card -->
            <div class="card">
                <h2>Flight & Ticket Details</h2>
                <hr style="margin: 16px 0;">

                <div class="info-grid">
                    <div class="info-card-item">
                        <h4>Passenger Name(s)</h4>
                        <p><?php echo htmlspecialchars($booking["passenger_name"]); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>PNR Code</h4>
                        <p style="font-family: monospace; color: var(--accent-color); font-size: 16px;"><?php echo htmlspecialchars($booking["pnr"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Ticket Number(s)</h4>
                        <p style="font-family: monospace;"><?php echo htmlspecialchars($booking["ticket_number"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Flight Number</h4>
                        <p style="font-family: monospace;"><?php echo htmlspecialchars($booking["flight_number"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Airline Name</h4>
                        <p><?php echo htmlspecialchars($booking["airline_name"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>From (Origin)</h4>
                        <p><?php echo htmlspecialchars($booking["from_city"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>To (Destination)</h4>
                        <p><?php echo htmlspecialchars($booking["to_city"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item">
                        <h4>Departure Details</h4>
                        <p>
                            <?php echo htmlspecialchars($booking["departure_date"] ?: '-'); ?>
                            <?php if ($booking["departure_time"]): ?> @ <?php echo htmlspecialchars($booking["departure_time"]); ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="info-card-item">
                        <h4>Arrival Details</h4>
                        <p>
                            <?php echo htmlspecialchars($booking["arrival_date"] ?: '-'); ?>
                            <?php if ($booking["arrival_time"]): ?> @ <?php echo htmlspecialchars($booking["arrival_time"]); ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="info-card-item">
                        <h4>Customer / Agency</h4>
                        <p><?php echo htmlspecialchars($booking["customer_name"] ?: '-'); ?></p>
                    </div>

                    <div class="info-card-item">
                        <h4>Supplier</h4>
                        <p><?php echo htmlspecialchars($booking["supplier_name"] ?: '-'); ?></p>
                    </div>
                    <div class="info-card-item" style="border-bottom: none;">
                        <h4>Service Type</h4>
                        <p><?php echo htmlspecialchars($booking["service_type"] ?: 'Flight'); ?></p>
                    </div>
                </div>

                <?php if (!empty($booking["remarks"])): ?>
                    <div style="margin-top: 24px; padding: 16px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-dark); border-radius: 8px;">
                        <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px; font-weight: 600;">Remarks / Special Notes</h4>
                        <p style="font-size: 14px; margin: 0; color: var(--text-main); white-space: pre-wrap;"><?php echo htmlspecialchars($booking["remarks"]); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Attachments Card -->
            <div class="card">
                <h2>📁 Booking Attachments (Visa, Voucher, Ticket etc.)</h2>
                <hr style="margin: 16px 0;">

                <div style="display: grid; grid-template-columns: 1fr; gap: 24px;">
                    <div>
                        <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 12px; font-weight: 600;">Active Attachments</h4>
                        
                        <?php if (mysqli_num_rows($attachments_query) > 0): ?>
                            <?php while ($att = mysqli_fetch_assoc($attachments_query)): ?>
                                <div class="attachment-item">
                                    <div class="attachment-info">
                                        <span class="attachment-icon">
                                            <?php 
                                                switch (strtolower($att['file_type'])) {
                                                    case 'visa': echo '🛂'; break;
                                                    case 'voucher': echo '🎫'; break;
                                                    case 'ticket': echo '✈️'; break;
                                                    case 'passport': echo '📕'; break;
                                                    default: echo '📄';
                                                }
                                            ?>
                                        </span>
                                        <div>
                                            <strong style="font-size: 14px; color: var(--text-primary);"><?php echo htmlspecialchars($att['file_name']); ?></strong>
                                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                                Type: <span style="text-transform: uppercase; font-weight:600;"><?php echo htmlspecialchars($att['file_type']); ?></span> 
                                                &nbsp;•&nbsp; Uploaded: <?php echo date('M d, Y', strtotime($att['uploaded_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="../../<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">Download</a>
                                        
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this attachment?');">
                                            <input type="hidden" name="attachment_id" value="<?php echo $att['id']; ?>">
                                            <button type="submit" name="delete_attachment" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2);">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px 0; color: var(--text-secondary); border: 1px dashed var(--border-dark); border-radius: 8px;">
                                <p style="font-size: 13px; margin: 0;">No attachments uploaded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="border-top: 1px solid var(--border-dark); padding-top: 20px;">
                        <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 12px; font-weight: 600;">Upload New Document</h4>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="attachment">Select File</label>
                                    <input type="file" id="attachment" name="attachment" required style="width: 100%;">
                                </div>
                                <div class="form-group">
                                    <label for="file_type">Attachment Type</label>
                                    <select id="file_type" name="file_type">
                                        <option value="Visa">Visa</option>
                                        <option value="Voucher">Voucher</option>
                                        <option value="Ticket">Ticket</option>
                                        <option value="Passport">Passport</option>
                                        <option value="Other">Other Document</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="custom_name">Custom Label / File Name (Optional)</label>
                                <input type="text" id="custom_name" name="custom_name" placeholder="e.g. Passenger Visa PDF">
                            </div>
                            <button type="submit" name="upload_attachment" style="background: var(--accent-color); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                📤 Upload Attachment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Financial Card & Log Details -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Financial Card -->
            <div class="card" style="background: linear-gradient(135deg, #163552 0%, #0d283f 100%); color: white; border: none;">
                <h2 style="color: white; font-size: 18px; margin-bottom: 20px;">Financial Details</h2>
                
                <div style="margin-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px;">
                    <p style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; margin: 0 0 4px 0;">Selling Cost</p>
                    <h3 style="font-size: 20px; font-weight: 700; color: #f1f5f9; margin: 0;">₹<?php echo number_format($selling_cost, 2); ?></h3>
                </div>

                <div style="margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px;">
                    <p style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; margin: 0 0 4px 0;">Net Profit</p>
                    <h2 style="font-size: 28px; font-weight: 800; color: #34d399; margin: 0;">₹<?php echo number_format($booking["profit"], 2); ?></h2>
                </div>

                <div style="margin-bottom: 12px; font-size: 13px;">
                    <span style="color: #cbd5e1;">Payment Mode:</span>
                    <strong style="float: right;"><?php echo htmlspecialchars($booking["payment_method"] ?: '-'); ?></strong>
                </div>

                <div style="font-size: 13px;">
                    <span style="color: #cbd5e1;">Assigned Agent:</span>
                    <strong style="float: right;"><?php echo htmlspecialchars($booking["assigned_user"] ?: '-'); ?></strong>
                </div>
            </div>

            <!-- Activity Log Card -->
            <div class="card">
                <h2>System Logs</h2>
                <hr style="margin: 16px 0;">

                <div style="font-size: 13px; display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <span style="color: var(--text-secondary); display: block; font-size: 11px; text-transform: uppercase;">Created By</span>
                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars($booking["created_by"] ?: 'System / Bulk Import'); ?></strong>
                        <span style="color: var(--text-secondary); display: block; font-size: 11px; margin-top: 2px;"><?php echo htmlspecialchars($booking["created_at"]); ?></span>
                    </div>

                    <?php if (!empty($booking["updated_by"])): ?>
                    <div style="border-top: 1px solid var(--border-dark); padding-top: 12px;">
                        <span style="color: var(--text-secondary); display: block; font-size: 11px; text-transform: uppercase;">Last Updated By</span>
                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars($booking["updated_by"]); ?></strong>
                        <span style="color: var(--text-secondary); display: block; font-size: 11px; margin-top: 2px;"><?php echo htmlspecialchars($booking["updated_at"]); ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this booking record permanently?');" style="margin-top: 8px;">
                        <button type="submit" name="delete" class="btn btn-danger" style="width: 100%; background: #ef4444; border: none; color: white;">
                            🗑 Delete Booking Record
                        </button>
                    </form>
                </div>
            </div>
            
        </div>

    </div>

</div>

</body>
</html>
