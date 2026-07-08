<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

// Fetch all bookings for the logged-in user with dynamic filters
$user_name = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? '');

$start_date = mysqli_real_escape_string($db, $_GET['start_date'] ?? '');
$end_date = mysqli_real_escape_string($db, $_GET['end_date'] ?? '');
$customer_type = mysqli_real_escape_string($db, $_GET['customer_type'] ?? '');
$status = mysqli_real_escape_string($db, $_GET['status'] ?? '');

$where_clauses = ["(assigned_user = '$user_name' OR created_by = '$user_name')"];

if (!empty($start_date)) {
    $where_clauses[] = "booking_date >= '$start_date'";
}
if (!empty($end_date)) {
    $where_clauses[] = "booking_date <= '$end_date'";
}
if (!empty($customer_type)) {
    $where_clauses[] = "customer_type = '$customer_type'";
}
if (!empty($status)) {
    $where_clauses[] = "status = '$status'";
}

$where_sql = implode(" AND ", $where_clauses);
$query = "SELECT bookings.*, customers.mobile AS cust_phone, customers.email AS cust_email FROM bookings LEFT JOIN customers ON bookings.customer_name = customers.name WHERE $where_sql ORDER BY CAST(bookings.serial_no AS UNSIGNED) DESC, bookings.id DESC";
$data = mysqli_query($db, $query);
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Bookings | Travel CRM</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="bookings.php" class="active">My Bookings</a>
    <a href="bookings_add.php">Add Booking</a>
    <a href="customers.php">Customers</a>
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
        <h1>My Bookings</h1>
        <a href="bookings_add.php" class="btn">+ Add Booking</a>
    </div>
    
    <div class="card" style="margin-bottom: 20px; padding: 12px 20px;">
        <div class="d-flex justify-between align-center" style="flex-wrap: wrap; gap: 12px;">
            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Actions:</div>
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                <a href="export_excel.php" class="btn btn-secondary">📤 Export Excel</a>
            </div>
        </div>
    </div>
    
    <!-- Filters Card -->
    <div class="card" style="margin-bottom: 20px; padding: 20px;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; min-width: 150px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Customer Type</label>
                <select name="customer_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Types</option>
                    <option value="Walk-in Customer" <?php echo $customer_type === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                    <option value="B2B" <?php echo $customer_type === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                    <option value="Corporate" <?php echo $customer_type === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                    <option value="Staff" <?php echo $customer_type === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="Other" <?php echo $customer_type === 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Status</label>
                <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Statuses</option>
                    <option value="Booked" <?php echo $status === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                    <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Cancelled" <?php echo $status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 10px 20px; height: 38px; cursor: pointer;">🔍 Filter</button>
                <a href="bookings.php" class="btn btn-secondary" style="padding: 10px 20px; height: 38px; display: inline-flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.1); border: 1px solid var(--border-dark); color: var(--text-main);">Reset</a>
            </div>
        </form>
    </div>
    
    <hr>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Booking saved successfully!
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th style="width: 60px;">No.</th>
                    <th>Date</th>
                    <th>Customer Type</th>
                    <th>Passenger Name</th>
                    <th>Ticket Service</th>
                    <th>Sector / PNR / Tkt</th>
                    <th>Phone / Email</th>
                    <th>Supplier / Customer</th>
                    <th>Payment Mode</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($row["serial_no"]); ?></td>
                        <td><?php echo htmlspecialchars($row["booking_date"]); ?></td>
                        <td>
                            <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--text-main); border: 1px solid var(--border-dark); font-weight: 600;">
                                <?php echo htmlspecialchars($row["customer_type"] ?: 'Walk-in Customer'); ?>
                            </span>
                        </td>
                        <td style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row["passenger_name"]); ?></td>
                        <td>
                            <span style="font-weight: 500;"><?php echo htmlspecialchars($row["service_type"] ?: 'Flight'); ?></span>
                        </td>
                        <td>
                            <?php 
                            $sector = [];
                            if (!empty($row["from_city"])) $sector[] = $row["from_city"];
                            if (!empty($row["to_city"])) $sector[] = $row["to_city"];
                            if (!empty($sector)) {
                                echo '<strong style="color: var(--text-main);">' . htmlspecialchars(implode(" - ", $sector)) . '</strong>';
                            } else {
                                echo '<span style="color: var(--text-secondary);">-</span>';
                            }
                            if (!empty($row["pnr"])) {
                                echo '<br><span style="font-size: 11px; color: var(--accent-color); font-weight: 600; font-family: monospace;">PNR: ' . htmlspecialchars($row["pnr"]) . '</span>';
                            }
                            if (!empty($row["ticket_number"])) {
                                echo '<br><span style="font-size: 11px; color: var(--text-secondary); font-family: monospace;">Tkt: ' . htmlspecialchars($row["ticket_number"]) . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            $phone = !empty($row["cust_phone"]) ? htmlspecialchars($row["cust_phone"]) : '-';
                            $email = !empty($row["cust_email"]) ? htmlspecialchars($row["cust_email"]) : '';
                            echo '<strong style="color: var(--text-main);">' . $phone . '</strong>';
                            if (!empty($email)) {
                                echo '<br><span style="font-size: 11px; color: var(--text-secondary);">' . $email . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row["customer_name"]); ?></span>
                            <?php if (!empty($row["supplier_name"])): ?>
                                <br><span style="font-size: 11px; color: var(--text-secondary);">Sup: <?php echo htmlspecialchars($row["supplier_name"]); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size: 13px; font-weight: 500; color: var(--text-secondary);">
                                <?php echo htmlspecialchars($row["payment_method"]); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo strtolower($row["status"] ?: 'booked'); ?>">
                                <?php echo htmlspecialchars($row["status"] ?: 'Booked'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="bookings_view.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                                <a href="bookings_edit.php?id=<?php echo $row['id']; ?>" class="btn" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">Edit</a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No bookings found. Click "Add Booking" to create one.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<script>
document.querySelector('.search').addEventListener('input', function() {
    var searchVal = this.value.toLowerCase();
    var rows = document.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
        // Skip the "No bookings found" row if present
        if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
        
        var text = row.textContent.toLowerCase();
        if (text.includes(searchVal)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});
</script>
</body>
</html>