<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$message_type = "";

// Fetch existing details
$fetch_query = "SELECT * FROM bookings WHERE id = $id";
$res = mysqli_query($db, $fetch_query);
$booking = mysqli_fetch_assoc($res);

if (!$booking) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Booking not found.</h2><a href='list.php'>Back to list</a></div>";
    exit;
}

// Handle Update Request
if (isset($_POST["update_booking"])) {
    $serial_no = mysqli_real_escape_string($db, $_POST["serial_no"]);
    $booking_date = mysqli_real_escape_string($db, $_POST["booking_date"]);
    $passenger_name = mysqli_real_escape_string($db, $_POST["passenger_name"]);
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $from_city = mysqli_real_escape_string($db, $_POST["from_city"]);
    $to_city = mysqli_real_escape_string($db, $_POST["to_city"]);
    $departure_date = mysqli_real_escape_string($db, $_POST["departure_date"]);
    $departure_time = mysqli_real_escape_string($db, $_POST["departure_time"]);
    $arrival_date = mysqli_real_escape_string($db, $_POST["arrival_date"]);
    $arrival_time = mysqli_real_escape_string($db, $_POST["arrival_time"]);
    $pnr = mysqli_real_escape_string($db, $_POST["pnr"]);
    $ticket_number = mysqli_real_escape_string($db, $_POST["ticket_number"]);
    $flight_number = mysqli_real_escape_string($db, $_POST["flight_number"]);
    $airline_name = mysqli_real_escape_string($db, $_POST["airline_name"]);
    $flight_class = mysqli_real_escape_string($db, $_POST["flight_class"]);
    $terminal = mysqli_real_escape_string($db, $_POST["terminal"]);
    $seat_number = mysqli_real_escape_string($db, $_POST["seat_number"]);
    $baggage = mysqli_real_escape_string($db, $_POST["baggage"]);
    $booking_ref = mysqli_real_escape_string($db, $_POST["booking_ref"]);
    $fare_basis = mysqli_real_escape_string($db, $_POST["fare_basis"]);
    $service_type = mysqli_real_escape_string($db, $_POST["service_type"]);
    $supplier_name = mysqli_real_escape_string($db, $_POST["supplier_name"]);
    $buying_cost = floatval($_POST["buying_cost"]);
    $selling_cost = floatval($_POST["selling_cost"]);
    $profit = $selling_cost - $buying_cost;
    $payment_method = mysqli_real_escape_string($db, $_POST["payment_method"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"]);
    $customer_type = mysqli_real_escape_string($db, $_POST["customer_type"] ?? "Walk-in Customer");
    $status = mysqli_real_escape_string($db, $_POST["status"]);
    $updated_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $customer_master_id = isset($_POST["customer_master_id"]) ? intval($_POST["customer_master_id"]) : 0;

    // Check existing or auto-create in customer_master if no customer_master_id is set
    if ($customer_master_id === 0 && $customer_name !== '') {
        // Since edit form only has name and type, let's look up by name first
        $escaped_name = mysqli_real_escape_string($db, $customer_name);
        $check_q = mysqli_query($db, "SELECT id FROM customer_master WHERE name = '$escaped_name' LIMIT 1");
        
        if (mysqli_num_rows($check_q) > 0) {
            $check_row = mysqli_fetch_assoc($check_q);
            $customer_master_id = intval($check_row['id']);
        } else {
            // Create a new record in customer_master
            $type = ($customer_type && $customer_type !== '') ? $customer_type : 'Walk-in Customer';
            $insert_cust_sql = "INSERT INTO customer_master (customer_type, name, mobile, email, created_by) 
                                VALUES ('$type', '$customer_name', '', '', '$updated_by')";
            if (mysqli_query($db, $insert_cust_sql)) {
                $customer_master_id = mysqli_insert_id($db);
                
                // Log customer creation activity
                $log_user = $_SESSION['user_name'] ?? 'System';
                mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) 
                                   VALUES ('$log_user', 'Created Customer #$customer_master_id ($customer_name) via Booking Edit Form', 'Customer Master', NOW())");
            }
        }
    }

    $update_query = "UPDATE bookings SET
        serial_no = '$serial_no',
        booking_date = " . ($booking_date ? "'$booking_date'" : "NULL") . ",
        passenger_name = '$passenger_name',
        customer_name = '$customer_name',
        customer_type = '$customer_type',
        from_city = '$from_city',
        to_city = '$to_city',
        departure_date = " . ($departure_date ? "'$departure_date'" : "NULL") . ",
        departure_time = '$departure_time',
        arrival_date = " . ($arrival_date ? "'$arrival_date'" : "NULL") . ",
        arrival_time = '$arrival_time',
        pnr = '$pnr',
        ticket_number = '$ticket_number',
        flight_number = '$flight_number',
        airline_name = '$airline_name',
        flight_class = '$flight_class',
        terminal = '$terminal',
        seat_number = '$seat_number',
        baggage = '$baggage',
        booking_ref = '$booking_ref',
        fare_basis = '$fare_basis',
        service_type = '$service_type',
        supplier_name = '$supplier_name',
        buying_cost = $buying_cost,
        selling_cost = $selling_cost,
        profit = $profit,
        payment_method = '$payment_method',
        assigned_user = '$assigned_user',
        status = '$status',
        updated_by = '$updated_by',
        updated_at = NOW(),
        customer_master_id = " . ($customer_master_id > 0 ? $customer_master_id : "NULL") . "
    WHERE id = $id";

    if (mysqli_query($db, $update_query)) {
        // Log activity
        $log_user = $_SESSION['user_name'] ?? 'System';
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$log_user', 'Edited Booking #$id (Passenger: $passenger_name)', 'Booking', NOW())";
        mysqli_query($db, $log_query);

        header("Location: view.php?id=$id&success=1");
        exit;
    } else {
        $message = "Database Error: " . mysqli_error($db);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Booking | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <script>
        function calculateProfit() {
            const buy = parseFloat(document.getElementById('buying_cost').value) || 0;
            const sell = parseFloat(document.getElementById('selling_cost').value) || 0;
            const profit = sell - buy;
            document.getElementById('profit').value = profit.toFixed(2);
        }
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const customerInputs = document.querySelectorAll('input[name="customer_name"]');
            
            customerInputs.forEach(input => {
                const parent = input.parentElement;
                parent.style.position = 'relative';
                
                const dropdown = document.createElement('div');
                dropdown.className = 'autocomplete-dropdown';
                parent.appendChild(dropdown);
                
                const form = parent.closest('form');
                const typeSelect = form ? (form.querySelector('select[name="customer_type"]') || form.querySelector('select#customer_type')) : null;
                const idInput = form ? form.querySelector('input[name="customer_master_id"]') : null;
                
                input.setAttribute('autocomplete', 'off');

                input.addEventListener('input', function() {
                    // Clear the hidden customer_master_id as the user is typing/changing it manually
                    if (idInput) {
                        idInput.value = "";
                    }
                    
                    const query = input.value.trim().toLowerCase();
                    if (query.length === 0) {
                        dropdown.style.display = 'none';
                        return;
                    }
                    
                    // Fetch matching customers from backend search endpoint
                    fetch(`../master/search_customer.php?q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length === 0) {
                                dropdown.style.display = 'none';
                                return;
                            }
                            
                            dropdown.innerHTML = '';
                            data.slice(0, 8).forEach(cust => {
                                const item = document.createElement('div');
                                item.className = 'autocomplete-item';
                                
                                let badgeClass = 'badge-walk-in';
                                if (cust.customer_type === 'B2B') {
                                    badgeClass = 'badge-b2b';
                                } else if (cust.customer_type === 'Corporate') {
                                    badgeClass = 'badge-corporate';
                                } else if (cust.customer_type === 'User') {
                                    badgeClass = 'badge-user';
                                }
                                
                                const mobileText = cust.mobile ? ` | Phone: ${cust.mobile}` : '';
                                const companyText = cust.company_name ? ` (${cust.company_name})` : '';
                                
                                item.innerHTML = `
                                    <div class="autocomplete-info">
                                        <span class="autocomplete-name">${escapeHtml(cust.name)}${escapeHtml(companyText)}</span>
                                        <span class="autocomplete-mobile">${escapeHtml(mobileText)}</span>
                                    </div>
                                    <span class="badge ${badgeClass}">${escapeHtml(cust.customer_type)}</span>
                                `;
                                
                                item.addEventListener('click', function(e) {
                                    input.value = cust.name;
                                    
                                    // Store customer_master_id in hidden input
                                    if (idInput) {
                                        idInput.value = cust.id;
                                    }
                                    
                                    // Autofill Type
                                    if (typeSelect) {
                                        let typeVal = cust.customer_type;
                                        if (typeVal === 'Walk-in') {
                                            if (typeSelect.querySelector('option[value="Walk-in Customer"]')) {
                                                typeSelect.value = 'Walk-in Customer';
                                            } else {
                                                typeSelect.value = 'Walk-in';
                                            }
                                        } else {
                                            typeSelect.value = typeVal;
                                        }
                                        // Trigger change event to ensure any conditional layouts update
                                        typeSelect.dispatchEvent(new Event('change'));
                                    }
                                    
                                    dropdown.style.display = 'none';
                                });
                                
                                dropdown.appendChild(item);
                            });
                            
                            dropdown.style.display = 'block';
                        })
                        .catch(err => {
                            console.error('Error fetching customers:', err);
                        });
                });
                
                input.addEventListener('focus', function() {
                    if (input.value.trim().length > 0) {
                        input.dispatchEvent(new Event('input'));
                    }
                });
                
                document.addEventListener('click', function(e) {
                    if (!parent.contains(e.target)) {
                        dropdown.style.display = 'none';
                    }
                });
            });
            
            function escapeHtml(text) {
                if (!text) return '';
                return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
            }
        });
    </script>
    <style>
        /* Autocomplete CSS */
        .autocomplete-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 9999;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .autocomplete-item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background 0.15s;
            text-align: left;
        }
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        .autocomplete-item:hover {
            background: #f8fafc;
        }
        .autocomplete-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .autocomplete-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }
        .autocomplete-mobile {
            font-size: 11px;
            color: #64748b;
        }
        .badge-walk-in {
            background: #e0f2fe;
            color: #0369a1;
        }
        .badge-b2b {
            background: #fef9c3;
            color: #854d0e;
        }
        .badge-corporate {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-user {
            background: #dcfce7;
            color: #166534;
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
        <h1>Edit Booking #<?php echo $booking['id']; ?></h1>
        <a href="view.php?id=<?php echo $booking['id']; ?>" class="btn btn-secondary">Cancel & Back</a>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" id="customer_master_id" name="customer_master_id" value="<?php echo htmlspecialchars($booking['customer_master_id'] ?? ''); ?>">
        <div class="card">
            <h2 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Passenger & Trip Information</h2>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="passenger_name">Passenger Name</label>
                    <input type="text" id="passenger_name" name="passenger_name" value="<?php echo htmlspecialchars($booking['passenger_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="pnr">PNR Code</label>
                    <input type="text" id="pnr" name="pnr" value="<?php echo htmlspecialchars($booking['pnr']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="serial_no">Serial Number</label>
                    <input type="text" id="serial_no" name="serial_no" value="<?php echo htmlspecialchars($booking['serial_no']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="booking_date">Booking Date</label>
                    <input type="date" id="booking_date" name="booking_date" value="<?php echo htmlspecialchars($booking['booking_date']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="from_city">From (Origin)</label>
                    <input type="text" id="from_city" name="from_city" value="<?php echo htmlspecialchars($booking['from_city']); ?>">
                </div>
                <div class="form-group">
                    <label for="to_city">To (Destination)</label>
                    <input type="text" id="to_city" name="to_city" value="<?php echo htmlspecialchars($booking['to_city']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="departure_date">Departure Date</label>
                    <input type="date" id="departure_date" name="departure_date" value="<?php echo htmlspecialchars($booking['departure_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="departure_time">Departure Time</label>
                    <input type="text" id="departure_time" name="departure_time" value="<?php echo htmlspecialchars($booking['departure_time']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="arrival_date">Arrival Date</label>
                    <input type="date" id="arrival_date" name="arrival_date" value="<?php echo htmlspecialchars($booking['arrival_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="arrival_time">Arrival Time</label>
                    <input type="text" id="arrival_time" name="arrival_time" value="<?php echo htmlspecialchars($booking['arrival_time']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="flight_number">Flight Number</label>
                    <input type="text" id="flight_number" name="flight_number" value="<?php echo htmlspecialchars($booking['flight_number']); ?>">
                </div>
                <div class="form-group">
                    <label for="airline_name">Airline Name</label>
                    <input type="text" id="airline_name" name="airline_name" value="<?php echo htmlspecialchars($booking['airline_name']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ticket_number">Ticket Number</label>
                    <input type="text" id="ticket_number" name="ticket_number" value="<?php echo htmlspecialchars($booking['ticket_number']); ?>">
                </div>
                <div class="form-group">
                    <label for="flight_class">Class</label>
                    <input type="text" id="flight_class" name="flight_class" value="<?php echo htmlspecialchars($booking['flight_class']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="terminal">Terminal</label>
                    <input type="text" id="terminal" name="terminal" value="<?php echo htmlspecialchars($booking['terminal']); ?>">
                </div>
                <div class="form-group">
                    <label for="seat_number">Seat Number</label>
                    <input type="text" id="seat_number" name="seat_number" value="<?php echo htmlspecialchars($booking['seat_number']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="baggage">Baggage Limit</label>
                    <input type="text" id="baggage" name="baggage" value="<?php echo htmlspecialchars($booking['baggage']); ?>">
                </div>
                <div class="form-group">
                    <label for="booking_ref">Booking Reference</label>
                    <input type="text" id="booking_ref" name="booking_ref" value="<?php echo htmlspecialchars($booking['booking_ref']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="fare_basis">Fare Basis</label>
                    <input type="text" id="fare_basis" name="fare_basis" value="<?php echo htmlspecialchars($booking['fare_basis']); ?>">
                </div>
                <div class="form-group">
                    <label for="service_type">Service Type</label>
                    <input type="text" id="service_type" name="service_type" value="<?php echo htmlspecialchars($booking['service_type']); ?>" required>
                </div>
            </div>

            <h2 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-top: 24px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Vendor & Financial Information</h2>

            <div class="form-row">
                <div class="form-group">
                    <label for="customer_name">Customer / Agency</label>
                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($booking['customer_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="supplier_name">Supplier</label>
                    <input type="text" id="supplier_name" name="supplier_name" value="<?php echo htmlspecialchars($booking['supplier_name']); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="buying_cost">Buying Cost (₹)</label>
                    <input type="text" id="buying_cost" name="buying_cost" value="<?php echo htmlspecialchars($booking['buying_cost']); ?>" oninput="calculateProfit()" required>
                </div>
                <div class="form-group">
                    <label for="selling_cost">Selling Cost (₹)</label>
                    <input type="text" id="selling_cost" name="selling_cost" value="<?php echo htmlspecialchars($booking['selling_cost']); ?>" oninput="calculateProfit()" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="profit">Profit Margin (₹)</label>
                    <input type="text" id="profit" name="profit" value="<?php echo htmlspecialchars($booking['profit']); ?>" readonly style="background: #f8fafc; font-weight: 700; color: var(--status-booked-text);">
                </div>
                <div class="form-group">
                    <label for="payment_method">Payment Mode</label>
                    <select id="payment_method" name="payment_method">
                        <option value="Cash" <?php echo $booking['payment_method'] == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="UPI" <?php echo $booking['payment_method'] == 'UPI' ? 'selected' : ''; ?>>UPI</option>
                        <option value="Bank Transfer" <?php echo $booking['payment_method'] == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="assigned_user">Assigned Agent</label>
                    <input type="text" id="assigned_user" name="assigned_user" value="<?php echo htmlspecialchars($booking['assigned_user']); ?>">
                </div>
                <div class="form-group">
                    <label for="customer_type">Customer Type</label>
                    <select id="customer_type" name="customer_type" required>
                        <option value="">Select Customer Type</option>
                        <option value="Walk-in Customer" <?php echo ($booking['customer_type'] ?? '') == 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                        <option value="B2B" <?php echo ($booking['customer_type'] ?? '') == 'B2B' ? 'selected' : ''; ?>>B2B</option>
                        <option value="Corporate" <?php echo ($booking['customer_type'] ?? '') == 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                        <option value="User" <?php echo ($booking['customer_type'] ?? '') == 'User' ? 'selected' : ''; ?>>User</option>
                        <option value="Other" <?php echo ($booking['customer_type'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Booking Status</label>
                    <select id="status" name="status">
                        <option value="Booked" <?php echo $booking['status'] == 'Booked' ? 'selected' : ''; ?>>Booked</option>
                        <option value="Pending" <?php echo $booking['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Cancelled" <?php echo $booking['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                <button type="submit" name="update_booking" style="padding: 12px 30px;">Save Updates</button>
            </div>
        </div>
    </form>
</div>

</body>
</html>

