<?php
require_once(__DIR__ . "/../../includes/db.php");

// Handle Sample CSV download
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=bookings_import_sample.csv');
    
    // UTF-8 BOM
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Serial No', 'Booking Date', 'Passenger Name', 'Customer/Agency Name', 
        'From City', 'To City', 'Departure Date', 'Departure Time', 
        'Arrival Date', 'Arrival Time', 'PNR', 'Ticket Number', 
        'Flight Number', 'Airline Name', 'Flight Class', 'Terminal', 
        'Seat Number', 'Baggage', 'Booking Reference', 'Fare Basis', 
        'Service Type', 'Supplier Name', 'Buying Cost', 'Selling Cost', 
        'Payment Method', 'Assigned User', 'Status'
    ]);
    
    // Dummy row example
    fputcsv($output, [
        'SR1001', date('Y-m-d'), 'Mr. John Doe', 'Direct Client', 
        'Mumbai', 'Dubai', date('Y-m-d'), '10:30', 
        date('Y-m-d'), '13:00', 'PNR123', '999-1234567890', 
        'EK501', 'Emirates', 'Economy', 'T2', 
        '12A', '30KG', 'REF12345', 'FLEX', 
        'Flight', 'Supplier A', '15000', '18000', 
        'UPI', 'Agent Name', 'Booked'
    ]);
    
    fclose($output);
    exit;
}

$message = "";
$message_type = "";

// Handle CSV Parsing and Import
if (isset($_POST["import_excel"])) {
    if (isset($_FILES["csv_file"]) && $_FILES["csv_file"]["error"] == UPLOAD_ERR_OK) {
        $file = $_FILES["csv_file"]["tmp_name"];
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            // Read and skip the header row
            $headers = fgetcsv($handle, 1000, ",");
            
            $success_count = 0;
            $fail_count = 0;
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // If row is empty or too short, skip
                if (empty($data) || count($data) < 5) continue;
                
                // Read columns matching header structure
                $serial_no = mysqli_real_escape_string($db, $data[0] ?? '');
                $booking_date = mysqli_real_escape_string($db, $data[1] ?? '');
                $passenger_name = mysqli_real_escape_string($db, $data[2] ?? '');
                $customer_name = mysqli_real_escape_string($db, $data[3] ?? '');
                $from_city = mysqli_real_escape_string($db, $data[4] ?? '');
                $to_city = mysqli_real_escape_string($db, $data[5] ?? '');
                $departure_date = mysqli_real_escape_string($db, $data[6] ?? '');
                $departure_time = mysqli_real_escape_string($db, $data[7] ?? '');
                $arrival_date = mysqli_real_escape_string($db, $data[8] ?? '');
                $arrival_time = mysqli_real_escape_string($db, $data[9] ?? '');
                $pnr = mysqli_real_escape_string($db, $data[10] ?? '');
                $ticket_number = mysqli_real_escape_string($db, $data[11] ?? '');
                $flight_number = mysqli_real_escape_string($db, $data[12] ?? '');
                $airline_name = mysqli_real_escape_string($db, $data[13] ?? '');
                $flight_class = mysqli_real_escape_string($db, $data[14] ?? '');
                $terminal = mysqli_real_escape_string($db, $data[15] ?? '');
                $seat_number = mysqli_real_escape_string($db, $data[16] ?? '');
                $baggage = mysqli_real_escape_string($db, $data[17] ?? '');
                $booking_ref = mysqli_real_escape_string($db, $data[18] ?? '');
                $fare_basis = mysqli_real_escape_string($db, $data[19] ?? '');
                $service_type = mysqli_real_escape_string($db, $data[20] ?? 'Flight');
                $supplier_name = mysqli_real_escape_string($db, $data[21] ?? '');
                $buying_cost = floatval($data[22] ?? 0);
                $selling_cost = floatval($data[23] ?? 0);
                $payment_method = mysqli_real_escape_string($db, $data[24] ?? 'Cash');
                $assigned_user = mysqli_real_escape_string($db, $data[25] ?? '');
                $status = mysqli_real_escape_string($db, $data[26] ?? 'Booked');
                
                // Auto calculate profit
                $profit = $selling_cost - $buying_cost;

                // Format dates safely
                $db_booking_date = !empty($booking_date) ? "'$booking_date'" : "NULL";
                $db_departure_date = !empty($departure_date) ? "'$departure_date'" : "NULL";
                $db_arrival_date = !empty($arrival_date) ? "'$arrival_date'" : "NULL";

                $query = "INSERT INTO bookings (
                    serial_no, booking_date, passenger_name, customer_name, 
                    from_city, to_city, departure_date, departure_time,
                    arrival_date, arrival_time, pnr, ticket_number,
                    flight_number, airline_name, flight_class, terminal,
                    seat_number, baggage, booking_ref, fare_basis,
                    service_type, supplier_name, buying_cost, selling_cost, 
                    profit, payment_method, assigned_user, status
                ) VALUES (
                    '$serial_no', $db_booking_date, '$passenger_name', '$customer_name',
                    '$from_city', '$to_city', $db_departure_date, '$departure_time',
                    $db_arrival_date, '$arrival_time', '$pnr', '$ticket_number',
                    '$flight_number', '$airline_name', '$flight_class', '$terminal',
                    '$seat_number', '$baggage', '$booking_ref', '$fare_basis',
                    '$service_type', '$supplier_name', $buying_cost, $selling_cost, 
                    $profit, '$payment_method', '$assigned_user', '$status'
                )";
                
                if (mysqli_query($db, $query)) {
                    $success_count++;
                } else {
                    $fail_count++;
                }
            }
            fclose($handle);
            $message = "Import process completed! Successfully imported: <strong>$success_count</strong> records." . ($fail_count > 0 ? " Failed to import: <strong>$fail_count</strong> records." : "");
            $message_type = $success_count > 0 ? "success" : "error";
        } else {
            $message = "Error: Could not open the uploaded file.";
            $message_type = "error";
        }
    } else {
        $message = "Error: Invalid file uploaded. Please select a valid CSV file.";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Bookings | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
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
        <h1>Import Bookings (Excel / CSV)</h1>
        <a href="list.php" class="btn btn-secondary">Back to Bookings</a>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="cards-row" style="grid-template-columns: 2fr 1fr; align-items: start;">
        <!-- Left Card: Upload Form -->
        <div class="card">
            <h2 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 12px;">📤 Upload CSV File</h2>
            <p style="color: var(--text-secondary); margin-bottom: 20px;">
                Please select your saved Excel spreadsheet (in CSV format) to import booking records into the system database. 
                Dates must be in <code style="font-family: monospace; font-weight: 600;">YYYY-MM-DD</code> format.
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="csv_file" style="font-weight: 700;">Select CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required style="padding: 10px;">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="import_excel" style="padding: 11px 24px;">Start Import</button>
                    <a href="import.php?download_sample=1" class="btn btn-secondary" style="padding: 11px 20px;">Download Sample CSV Template</a>
                </div>
            </form>
        </div>

        <!-- Right Card: Instruction Panel -->
        <div class="card" style="background: #f8fafc; border-color: var(--border-dark);">
            <h2 style="font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px;">💡 CSV File Layout Rules</h2>
            <ol style="margin-left: 18px; line-height: 1.6; color: var(--text-secondary); font-size: 12px; display: flex; flex-direction: column; gap: 8px;">
                <li>The first row of your CSV file must contain the exact column headers.</li>
                <li>Fields like <strong>Passenger Name</strong>, <strong>Customer Name</strong>, <strong>Buying Cost</strong>, and <strong>Selling Cost</strong> are required.</li>
                <li><strong>Profit margin</strong> is calculated automatically upon import.</li>
                <li>Ensure numbers don't contain currency symbols (₹, $) or commas.</li>
                <li>Allowed values for <strong>Status</strong>: <code style="font-family: monospace;">Booked</code>, <code style="font-family: monospace;">Pending</code>, <code style="font-family: monospace;">Cancelled</code>.</li>
            </ol>
        </div>
    </div>
</div>

</body>
</html>

