<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

// Handle CSV Exports BEFORE outputting any HTML
if (isset($_POST["export_daily_report"])) {
    $date = mysqli_real_escape_string($db, $_POST["date"]);
    if (empty($date)) $date = date('Y-m-d');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=daily_report_' . $date . '.csv');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Daily Booking Report']);
    fputcsv($output, ['Date:', $date]);
    fputcsv($output, []);

    fputcsv($output, [
        'Serial No', 'Booking Date', 'Passenger Name', 'Customer Name', 
        'PNR', 'Ticket Number', 'Service Type', 'Supplier Name', 
        'Buying Cost', 'Selling Cost', 'Profit', 'Assigned User', 'Status'
    ]);

    $sql = "SELECT * FROM bookings WHERE DATE(booking_date) = '$date' ORDER BY id DESC";
    $result = mysqli_query($db, $sql);
    $total_buy = 0; $total_sell = 0; $total_profit = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['serial_no'], $row['booking_date'], $row['passenger_name'], $row['customer_name'],
            $row['pnr'], $row['ticket_number'], $row['service_type'], $row['supplier_name'],
            $row['buying_cost'], $row['selling_cost'], $row['profit'],
            $row['assigned_user'], $row['status']
        ]);
        $total_buy += floatval($row['buying_cost']);
        $total_sell += floatval($row['selling_cost']);
        $total_profit += floatval($row['profit']);
    }

    fputcsv($output, []);
    fputcsv($output, [
        'Total Summary', '', '', '', '', '', '', 'Total:',
        $total_buy, $total_sell, $total_profit, '', ''
    ]);

    fclose($output);
    exit;
}

if (isset($_POST["export_monthly_report"])) {
    $year = intval($_POST["year"]);
    if ($year <= 0) $year = intval(date('Y'));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=monthly_summary_report_' . $year . '.csv');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Monthly Summary Report']);
    fputcsv($output, ['Year:', $year]);
    fputcsv($output, []);

    fputcsv($output, [
        'Month', 'Booking Count', 'Total Buying Cost (₹)', 'Total Selling Cost (₹)', 'Total Profit (₹)'
    ]);

    $sql = "SELECT 
                MONTHNAME(booking_date) as month_name, 
                COUNT(*) as count, 
                SUM(buying_cost) as total_buy, 
                SUM(selling_cost) as total_sell, 
                SUM(profit) as total_profit 
            FROM bookings 
            WHERE YEAR(booking_date) = $year
            GROUP BY MONTH(booking_date)
            ORDER BY MONTH(booking_date) ASC";
            
    $result = mysqli_query($db, $sql);
    $grand_count = 0; $grand_buy = 0; $grand_sell = 0; $grand_profit = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['month_name'], $row['count'], $row['total_buy'], $row['total_sell'], $row['total_profit']
        ]);
        $grand_count += intval($row['count']);
        $grand_buy += floatval($row['total_buy']);
        $grand_sell += floatval($row['total_sell']);
        $grand_profit += floatval($row['total_profit']);
    }

    fputcsv($output, []);
    fputcsv($output, [
        'Grand Total', $grand_count, $grand_buy, $grand_sell, $grand_profit
    ]);

    fclose($output);
    exit;
}

if (isset($_POST["export_user_report"])) {
    $agent = mysqli_real_escape_string($db, $_POST["agent"]);
    $start_date = mysqli_real_escape_string($db, $_POST["start_date"]);
    $end_date = mysqli_real_escape_string($db, $_POST["end_date"]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=user_report_' . ($agent == 'all' ? 'all' : strtolower(str_replace(' ', '_', $agent))) . '_' . date('Y-m-d') . '.csv');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, ['User Wise Booking Report']);
    fputcsv($output, ['Agent:', $agent == 'all' ? 'All Agents' : $agent]);
    fputcsv($output, ['Date Range:', ($start_date ?: 'Any') . ' to ' . ($end_date ?: 'Any')]);
    fputcsv($output, []);

    fputcsv($output, [
        'Serial No', 'Booking Date', 'Passenger Name', 'Customer Name', 
        'PNR', 'Ticket Number', 'Service Type', 'Supplier Name', 
        'Buying Cost', 'Selling Cost', 'Profit', 'Payment Method', 
        'Assigned User', 'Status'
    ]);

    $where = [];
    if ($agent !== 'all') {
        $where[] = "assigned_user = '$agent'";
    }
    if (!empty($start_date)) {
        $where[] = "booking_date >= '$start_date'";
    }
    if (!empty($end_date)) {
        $where[] = "booking_date <= '$end_date'";
    }

    $sql = "SELECT * FROM bookings";
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY id DESC";

    $result = mysqli_query($db, $sql);
    $total_buy = 0; $total_sell = 0; $total_profit = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['serial_no'], $row['booking_date'], $row['passenger_name'], $row['customer_name'],
            $row['pnr'], $row['ticket_number'], $row['service_type'], $row['supplier_name'],
            $row['buying_cost'], $row['selling_cost'], $row['profit'], $row['payment_method'],
            $row['assigned_user'], $row['status']
        ]);
        $total_buy += floatval($row['buying_cost']);
        $total_sell += floatval($row['selling_cost']);
        $total_profit += floatval($row['profit']);
    }

    fputcsv($output, []);
    fputcsv($output, [
        'Total Summary', '', '', '', '', '', '', 'Total:',
        $total_buy, $total_sell, $total_profit, '', '', ''
    ]);

    fclose($output);
    exit;
}

if (isset($_POST["export_profit_report"])) {
    $start_date = mysqli_real_escape_string($db, $_POST["start_date"]);
    $end_date = mysqli_real_escape_string($db, $_POST["end_date"]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=profit_report_' . date('Y-m-d') . '.csv');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Profit Report']);
    fputcsv($output, ['Date Range:', ($start_date ?: 'Any') . ' to ' . ($end_date ?: 'Any')]);
    fputcsv($output, []);

    fputcsv($output, [
        'Serial No', 'Booking Date', 'Passenger Name', 'Customer Name', 
        'PNR', 'Service Type', 'Supplier Name', 'Buying Cost', 
        'Selling Cost', 'Profit', 'Assigned User', 'Status'
    ]);

    $where = [];
    if (!empty($start_date)) {
        $where[] = "booking_date >= '$start_date'";
    }
    if (!empty($end_date)) {
        $where[] = "booking_date <= '$end_date'";
    }

    $sql = "SELECT * FROM bookings";
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY id DESC";

    $result = mysqli_query($db, $sql);
    $total_buy = 0; $total_sell = 0; $total_profit = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['serial_no'], $row['booking_date'], $row['passenger_name'], $row['customer_name'],
            $row['pnr'], $row['service_type'], $row['supplier_name'],
            $row['buying_cost'], $row['selling_cost'], $row['profit'],
            $row['assigned_user'], $row['status']
        ]);
        $total_buy += floatval($row['buying_cost']);
        $total_sell += floatval($row['selling_cost']);
        $total_profit += floatval($row['profit']);
    }

    fputcsv($output, []);
    fputcsv($output, [
        'Total Summary', '', '', '', '', '', 'Total:',
        $total_buy, $total_sell, $total_profit, '', ''
    ]);

    fclose($output);
    exit;
}

if (isset($_POST["export_supplier_report"])) {
    $supplier = mysqli_real_escape_string($db, $_POST["supplier"]);
    $start_date = mysqli_real_escape_string($db, $_POST["start_date"]);
    $end_date = mysqli_real_escape_string($db, $_POST["end_date"]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=supplier_report_' . ($supplier == 'all' ? 'all' : strtolower(str_replace(' ', '_', $supplier))) . '_' . date('Y-m-d') . '.csv');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Supplier Booking Report']);
    fputcsv($output, ['Supplier:', $supplier == 'all' ? 'All Suppliers' : $supplier]);
    fputcsv($output, ['Date Range:', ($start_date ?: 'Any') . ' to ' . ($end_date ?: 'Any')]);
    fputcsv($output, []);

    $where = [];
    if (!empty($start_date)) $where[] = "booking_date >= '$start_date'";
    if (!empty($end_date)) $where[] = "booking_date <= '$end_date'";

    if ($supplier === 'all') {
        fputcsv($output, ['Supplier Name', 'Booking Count', 'Total Buying Cost (₹)', 'Total Selling Cost (₹)', 'Total Profit (₹)']);
        
        $sql = "SELECT supplier_name, COUNT(*) as count, SUM(buying_cost) as total_buy, SUM(selling_cost) as total_sell, SUM(profit) as total_profit FROM bookings";
        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " GROUP BY supplier_name ORDER BY total_profit DESC";
        
        $result = mysqli_query($db, $sql);
        $grand_count = 0; $grand_buy = 0; $grand_sell = 0; $grand_profit = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            fputcsv($output, [
                $row['supplier_name'] ?: 'Unknown', $row['count'], $row['total_buy'], $row['total_sell'], $row['total_profit']
            ]);
            $grand_count += intval($row['count']);
            $grand_buy += floatval($row['total_buy']);
            $grand_sell += floatval($row['total_sell']);
            $grand_profit += floatval($row['total_profit']);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Grand Total', $grand_count, $grand_buy, $grand_sell, $grand_profit]);
    } else {
        fputcsv($output, [
            'Serial No', 'Booking Date', 'Passenger Name', 'Customer Name', 
            'PNR', 'Ticket Number', 'Service Type', 'Supplier Name', 
            'Buying Cost', 'Selling Cost', 'Profit', 'Assigned User', 'Status'
        ]);
        
        $where[] = "supplier_name = '$supplier'";
        $sql = "SELECT * FROM bookings WHERE " . implode(" AND ", $where) . " ORDER BY id DESC";
        
        $result = mysqli_query($db, $sql);
        $total_buy = 0; $total_sell = 0; $total_profit = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            fputcsv($output, [
                $row['serial_no'], $row['booking_date'], $row['passenger_name'], $row['customer_name'],
                $row['pnr'], $row['ticket_number'], $row['service_type'], $row['supplier_name'],
                $row['buying_cost'], $row['selling_cost'], $row['profit'],
                $row['assigned_user'], $row['status']
            ]);
            $total_buy += floatval($row['buying_cost']);
            $total_sell += floatval($row['selling_cost']);
            $total_profit += floatval($row['profit']);
        }
        
        fputcsv($output, []);
        fputcsv($output, [
            'Total Summary', '', '', '', '', '', '', 'Total:',
            $total_buy, $total_sell, $total_profit, '', ''
        ]);
    }

    fclose($output);
    exit;
}

// Fetch agents for dropdown
$agents_res = mysqli_query($db, "
    SELECT DISTINCT name FROM users 
    UNION 
    SELECT DISTINCT assigned_user FROM bookings WHERE assigned_user != '' AND assigned_user IS NOT NULL
    ORDER BY name ASC
");

// Fetch suppliers for dropdown
$suppliers_res = mysqli_query($db, "
    SELECT DISTINCT supplier_name FROM bookings WHERE supplier_name != '' AND supplier_name IS NOT NULL
    ORDER BY supplier_name ASC
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reports console | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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
        <h1>Export Reports Suite</h1>
        <a href="list.php" class="btn btn-secondary">Back to Bookings</a>
    </div>
    
    <hr>

    <div class="cards-row" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
        
        <!-- 1. Daily Report Card -->
        <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 280px;">
            <div>
                <h3 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 8px;">📅 Daily Report</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 12px;">Export all booking transactions completed on a single chosen business day.</p>
                <form method="POST">
                    <div class="form-group">
                        <label for="daily_date">Select Date</label>
                        <input type="date" id="daily_date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" name="export_daily_report" style="width: 100%; padding: 11px;">Export Daily CSV</button>
                </form>
            </div>
        </div>

        <!-- 2. Monthly Report Card -->
        <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 280px;">
            <div>
                <h3 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 8px;">📈 Monthly Summary Report</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 12px;">Group bookings and revenue totals month-by-month for a specified fiscal year.</p>
                <form method="POST">
                    <div class="form-group">
                        <label for="monthly_year">Select Year</label>
                        <select id="monthly_year" name="year">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" name="export_monthly_report" style="width: 100%; padding: 11px;">Export Monthly CSV</button>
                </form>
            </div>
        </div>

        <!-- 3. Profit Report Card -->
        <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 280px;">
            <div>
                <h3 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 8px;">💰 Profit Report</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 12px;">Export a detailed profit summary of all bookings, calculating buying, selling costs, and margins.</p>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="profit_start">Start Date</label>
                            <input type="date" id="profit_start" name="start_date">
                        </div>
                        <div class="form-group">
                            <label for="profit_end">End Date</label>
                            <input type="date" id="profit_end" name="end_date">
                        </div>
                    </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" name="export_profit_report" style="width: 100%; padding: 11px; background: #16a34a;">Export Profit CSV</button>
                </form>
            </div>
        </div>

        <!-- 4. User Wise Report Card -->
        <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 280px;">
            <div>
                <h3 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 8px;">👤 User Wise Report</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 12px;">Export all booking transactions logged by or assigned to a specific agent.</p>
                <form method="POST">
                    <div class="form-group">
                        <label for="agent">Select Agent / User</label>
                        <select id="agent" name="agent" style="width: 100%;">
                            <option value="all">All Agents</option>
                            <?php while ($agent_row = mysqli_fetch_assoc($agents_res)): ?>
                                <option value="<?php echo htmlspecialchars($agent_row['name']); ?>"><?php echo htmlspecialchars($agent_row['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="user_start">Start Date</label>
                            <input type="date" id="user_start" name="start_date">
                        </div>
                        <div class="form-group">
                            <label for="user_end">End Date</label>
                            <input type="date" id="user_end" name="end_date">
                        </div>
                    </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" name="export_user_report" style="width: 100%; padding: 11px;">Export User CSV</button>
                </form>
            </div>
        </div>

        <!-- 5. Supplier Wise Report Card -->
        <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 280px;">
            <div>
                <h3 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 8px;">✈ Supplier Wise Report</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 12px;">Summarize margins grouped by supplier, or export transactions for a specific supplier.</p>
                <form method="POST">
                    <div class="form-group">
                        <label for="supplier">Select Supplier</label>
                        <select id="supplier" name="supplier" style="width: 100%;">
                            <option value="all">All Suppliers (Summary)</option>
                            <?php while ($sup_row = mysqli_fetch_assoc($suppliers_res)): ?>
                                <option value="<?php echo htmlspecialchars($sup_row['supplier_name']); ?>"><?php echo htmlspecialchars($sup_row['supplier_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="supplier_start">Start Date</label>
                            <input type="date" id="supplier_start" name="start_date">
                        </div>
                        <div class="form-group">
                            <label for="supplier_end">End Date</label>
                            <input type="date" id="supplier_end" name="end_date">
                        </div>
                    </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" name="export_supplier_report" style="width: 100%; padding: 11px; background: #eab308; color: #1e293b;">Export Supplier CSV</button>
                </form>
            </div>
        </div>

    </div>
</div>

</body>
</html>

