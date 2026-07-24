<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();

// Fetch dynamic filters for admin
$start_date = mysqli_real_escape_string($db, $_GET['start_date'] ?? '');
$end_date = mysqli_real_escape_string($db, $_GET['end_date'] ?? '');
$agent = mysqli_real_escape_string($db, $_GET['agent'] ?? '');
$customer_type = mysqli_real_escape_string($db, $_GET['customer_type'] ?? '');
$service_type = mysqli_real_escape_string($db, $_GET['service_type'] ?? '');

$booking_is_user_page = stripos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/user_page/bookings/') !== false;
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin' && !$booking_is_user_page;
$user_name = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? '');
$booking_bulk_delete_enabled = $booking_is_user_page || $is_admin;
if ($booking_bulk_delete_enabled && empty($_SESSION['list_bulk_delete_token'])) {
    $_SESSION['list_bulk_delete_token'] = bin2hex(random_bytes(32));
}
$booking_bulk_delete_token = $booking_bulk_delete_enabled ? $_SESSION['list_bulk_delete_token'] : '';
if (!$is_admin) {
    // A normal user cannot use the shared admin filter to inspect another
    // user's booking assignment.
    $agent = '';
}

$where_clauses = [];
if (!$is_admin) {
    $where_clauses[] = "bookings.created_by = '$user_name'";
}

if (!empty($start_date)) {
    $where_clauses[] = "bookings.booking_date >= '$start_date'";
}
if (!empty($end_date)) {
    $where_clauses[] = "bookings.booking_date <= '$end_date'";
}
if ($is_admin && !empty($agent)) {
    $where_clauses[] = "(bookings.assigned_user = '$agent' OR bookings.created_by = '$agent')";
}
if (!empty($customer_type)) {
    $where_clauses[] = "bookings.customer_type = '$customer_type'";
}
if (!empty($service_type)) {
    $where_clauses[] = "bookings.service_type = '$service_type'";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

$query = "SELECT bookings.*, customer_master.mobile AS cust_phone, customer_master.email AS cust_email FROM bookings LEFT JOIN customer_master ON (bookings.customer_master_id = customer_master.id OR (bookings.customer_master_id IS NULL AND bookings.customer_name = customer_master.name)) $where_sql ORDER BY CAST(bookings.serial_no AS UNSIGNED) DESC, bookings.id DESC";
$data = mysqli_query($db, $query);

$users_res = $is_admin ? mysqli_query($db, "SELECT name FROM users ORDER BY name ASC") : false;
$service_type_owner_sql = $is_admin
    ? ''
    : " AND created_by = '$user_name'";
$service_types_res = mysqli_query($db, "SELECT DISTINCT service_type FROM bookings WHERE service_type IS NOT NULL AND service_type != ''$service_type_owner_sql ORDER BY service_type ASC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bookings List | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .booking-export-modal { position: fixed; inset: 0; z-index: 1200; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(255, 255, 255, 0.75); backdrop-filter: blur(4px); }
        .booking-export-modal.is-open { display: flex; }
        .booking-export-dialog { width: min(100%, 430px); border: 1px solid var(--border-dark); border-radius: 14px; background: var(--card-bg, #fff); box-shadow: 0 24px 60px rgba(15, 39, 71, .28); overflow: hidden; }
        .booking-export-header { padding: 18px 22px; background: var(--accent-color, #0f2747); color: #fff; }
        .booking-export-header h2 { margin: 0; color: inherit; font-size: 20px; }
        .booking-export-body { padding: 22px; }
        .booking-export-body label { display: block; margin-bottom: 7px; color: var(--text-main); font-size: 13px; font-weight: 700; }
        .booking-export-body select { width: 100%; padding: 10px 12px; border: 1px solid var(--border-dark); border-radius: 7px; background: rgba(0,0,0,.03); color: var(--text-main); font: inherit; }
        .booking-export-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
        .booking-export-message { display: none; margin-top: 14px; padding: 10px 12px; border-radius: 7px; background: #fee2e2; color: #991b1b; font-size: 13px; font-weight: 600; }
        .booking-export-message.is-visible { display: block; }
        .booking-export-actions .btn:disabled { cursor: wait; opacity: .65; }
        .booking-customer-type {
            border: 1px solid transparent;
            font-weight: 700;
        }
        .booking-customer-type.badge-walk-in {
            border-color: #bae6fd;
            background: #e0f2fe;
            color: #0369a1;
        }
        .booking-customer-type.badge-b2b {
            border-color: #fde68a;
            background: #fef9c3;
            color: #854d0e;
        }
        .booking-customer-type.badge-corporate {
            border-color: #fecaca;
            background: #fee2e2;
            color: #991b1b;
        }
        .booking-customer-type.badge-user {
            border-color: #bbf7d0;
            background: #dcfce7;
            color: #166534;
        }
        .booking-customer-type.badge-other {
            border-color: #ddd6fe;
            background: #f3e8ff;
            color: #6b21a8;
        }
        @media (max-width: 520px) { .booking-export-actions { flex-direction: column-reverse; } .booking-export-actions .btn { width: 100%; justify-content: center; } }
    </style>
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <?php if ($booking_bulk_delete_enabled): ?>
    <script src="../../assets/js/bulk-list-delete.js" defer></script>
    <?php endif; ?>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main<?php echo $booking_bulk_delete_enabled ? ' list-bulk-page' : ''; ?>"
     <?php if ($booking_bulk_delete_enabled): ?>
     data-bulk-list
     data-bulk-endpoint="bulk_delete.php"
     data-bulk-token="<?php echo htmlspecialchars($booking_bulk_delete_token); ?>"
     data-bulk-singular="booking"
     data-bulk-plural="bookings"
     <?php endif; ?>>
    <div class="dashboard-title-row">
        <h1>Bookings</h1>
        <div class="d-flex gap-2">
            <a href="add.php" class="btn">+ Add Booking</a>
            <?php if ($booking_bulk_delete_enabled): ?>
            <button type="button" class="btn btn-secondary" data-bulk-toggle>Delete</button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="Search by passenger, customer, supplier, ticket or PNR...">
    </div>
    
    <div class="card" style="margin-bottom: 20px; padding: 12px 20px;">
        <div class="d-flex justify-between align-center" style="flex-wrap: wrap; gap: 12px;">
            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Actions & Reports:</div>
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                <a href="import.php" class="btn btn-secondary">📥 Import Excel</a>
                <button type="button" id="openExportModal" class="btn btn-secondary">📤 Export Excel</button>
            </div>
        </div>
    </div>
    <!-- Filters Card -->
    <div class="card" style="margin-bottom: 20px; padding: 20px;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
            </div>
            <?php if ($is_admin): ?>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Agent / User</label>
                <select name="agent" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Agents</option>
                    <?php 
                    mysqli_data_seek($users_res, 0);
                    while ($u = mysqli_fetch_assoc($users_res)): 
                    ?>
                        <option value="<?php echo htmlspecialchars($u['name']); ?>" <?php echo $agent === $u['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Customer Type</label>
                <select name="customer_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Types</option>
                    <option value="Walk-in Customer" <?php echo $customer_type === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                    <option value="B2B" <?php echo $customer_type === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                    <option value="Corporate" <?php echo $customer_type === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                    <option value="User" <?php echo $customer_type === 'User' ? 'selected' : ''; ?>>User</option>
                    <option value="Other" <?php echo $customer_type === 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary);">Service Type</label>
                <select name="service_type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-dark); border-radius: 6px; background: rgba(0,0,0,0.05); color: var(--text-main);">
                    <option value="">All Service Types</option>
                    <?php 
                    mysqli_data_seek($service_types_res, 0);
                    while ($st = mysqli_fetch_assoc($service_types_res)): 
                    ?>
                        <option value="<?php echo htmlspecialchars($st['service_type']); ?>" <?php echo $service_type === $st['service_type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($st['service_type']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 10px 20px; height: 38px; cursor: pointer;">Search</button>
                <a href="list.php" class="btn btn-secondary" style="padding: 10px 20px; height: 38px; display: inline-flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.1); border: 1px solid var(--border-dark); color: var(--text-main);">Reset</a>
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

    <?php if (isset($_GET['deleted'])): ?>
        <div class="booking-alert is-success" role="alert">
            <?php $deleted_count = max(0, (int) $_GET['deleted']); ?>
            <?php echo $deleted_count === 1 ? '1 booking deleted successfully.' : $deleted_count . ' bookings deleted successfully.'; ?>
        </div>
    <?php endif; ?>

    <?php if ($booking_bulk_delete_enabled): ?>
    <div class="list-bulk-toolbar" data-bulk-toolbar role="region" aria-label="Bulk booking deletion">
        <strong data-bulk-count>0 bookings selected</strong>
        <div class="list-bulk-toolbar-actions">
            <button class="btn btn-secondary" type="button" data-bulk-cancel>Cancel</button>
            <button class="btn btn-danger" type="button" data-bulk-delete-selected disabled>Delete Selected</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <?php if ($booking_bulk_delete_enabled): ?>
                    <th class="list-bulk-select-column">
                        <input type="checkbox" data-bulk-select-all aria-label="Select all visible bookings">
                    </th>
                    <?php endif; ?>
                    <th style="width: 60px;">No.</th>
                    <th>Date</th>
                    <th>Customer Type</th>
                    <th>Customer/ Supplier</th>
                    <th>Phone / Email</th>
                    <th>Passenger Name</th>
                    <th>Ticket Service</th>
                    <th>Sector / PNR / Tkt</th>
                    
                    <th>Payment Mode</th>
                    <th>Status</th>
                    <th style="width: 80px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)):
                        $booking_customer_type = trim((string) ($row['customer_type'] ?? '')) ?: 'Walk-in Customer';
                        $booking_customer_type_class = 'badge-other';
                        if (in_array($booking_customer_type, ['Walk-in Customer', 'Walk-in', 'Customer'], true)) {
                            $booking_customer_type_class = 'badge-walk-in';
                        } elseif ($booking_customer_type === 'B2B') {
                            $booking_customer_type_class = 'badge-b2b';
                        } elseif ($booking_customer_type === 'Corporate') {
                            $booking_customer_type_class = 'badge-corporate';
                        } elseif ($booking_customer_type === 'User') {
                            $booking_customer_type_class = 'badge-user';
                        }
                    ?>
                    <tr>
                        <?php if ($booking_bulk_delete_enabled): ?>
                        <td class="list-bulk-select-column">
                            <input type="checkbox" data-bulk-row value="<?php echo (int) $row['id']; ?>" aria-label="Select booking #<?php echo (int) $row['id']; ?>">
                        </td>
                        <?php endif; ?>
                        <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($row["serial_no"]); ?></td>
                        <td><?php echo htmlspecialchars($row["booking_date"]); ?></td>
                        <td>
                            <span class="badge booking-customer-type <?php echo $booking_customer_type_class; ?>">
                                <?php echo htmlspecialchars($booking_customer_type); ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row["customer_name"]); ?></span>
                            <?php if (!empty($row["supplier_name"])): ?>
                                <br><span style="font-size: 11px; color: var(--text-secondary);">Sup: <?php echo htmlspecialchars($row["supplier_name"]); ?></span>
                            <?php endif; ?>
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
                        <td style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row["passenger_name"]); ?></td>
                        <td>
                            <?php
                            $list_document_labels = ['ticket' => 'Flight Ticket', 'hotel' => 'Hotel', 'visa' => 'Visa', 'passport' => 'Passport', 'insurance' => 'Insurance', 'package' => 'Tour Package', 'other' => 'Other Service'];
                            $list_document_type = strtolower(trim((string) ($row['document_type'] ?? '')));
                            $list_service_label = $list_document_labels[$list_document_type] ?? ($row['service_type'] ?: 'Flight Ticket');
                            ?>
                            <span style="font-weight: 500;"><?php echo htmlspecialchars($list_service_label); ?></span>
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
                            <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $booking_bulk_delete_enabled ? '12' : '11'; ?>" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No bookings found in database. Click "Add Booking" to create one.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($booking_bulk_delete_enabled): ?>
    <div class="list-bulk-confirm-backdrop" data-bulk-confirm role="dialog" aria-modal="true" aria-labelledby="bookingBulkDeleteTitle">
        <div class="list-bulk-confirm-card">
            <div class="list-bulk-confirm-icon" aria-hidden="true">!</div>
            <h2 id="bookingBulkDeleteTitle">Delete selected bookings?</h2>
            <p data-bulk-confirm-message>The selected bookings will be permanently deleted. This action cannot be undone.</p>
            <div class="list-bulk-confirm-error" data-bulk-error role="alert"></div>
            <div class="list-bulk-confirm-actions">
                <button class="btn btn-secondary" type="button" data-bulk-confirm-cancel>Cancel</button>
                <button class="btn btn-danger" type="button" data-bulk-confirm-delete>Delete</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelector('.search').addEventListener('input', function() {
    var searchVal = this.value.toLowerCase();
    var rows = document.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
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
<?php include(__DIR__ . '/../../includes/booking_export_modal.php'); ?>
</body>
</html>
