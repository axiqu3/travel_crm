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

// Fetch customer master mobile/email if available
$cust_mobile = "";
$cust_email = "";
if (!empty($booking['customer_master_id'])) {
    $cust_id = intval($booking['customer_master_id']);
    $cust_res = mysqli_query($db, "SELECT mobile, email FROM customer_master WHERE id = $cust_id LIMIT 1");
    if ($cust_res && $cust_row = mysqli_fetch_assoc($cust_res)) {
        $cust_mobile = $cust_row['mobile'];
        $cust_email = $cust_row['email'];
    }
}

// Handle Update Request
if (isset($_POST["update_booking"])) {
    $updated_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $service_type = mysqli_real_escape_string($db, $_POST["service_type"] ?? "Flight");
    $ticket_path = $booking['ticket_path']; // Default to existing path

    $serial_no = mysqli_real_escape_string($db, $_POST["serial_no"]);
    $booking_date = mysqli_real_escape_string($db, $_POST["booking_date"]);
    $passenger_name = mysqli_real_escape_string($db, $_POST["passenger_name"]);
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $customer_mobile = isset($_POST["customer_mobile"]) ? mysqli_real_escape_string($db, $_POST["customer_mobile"]) : "";
    $customer_email = isset($_POST["customer_email"]) ? mysqli_real_escape_string($db, $_POST["customer_email"]) : "";
    $supplier_name = mysqli_real_escape_string($db, $_POST["supplier_name"]);
    $buying_cost = floatval($_POST["buying_cost"] ?? 0);
    $selling_cost = floatval($_POST["selling_cost"] ?? 0);
    $profit = floatval($_POST["profit"] ?? 0);
    $payment_method = mysqli_real_escape_string($db, $_POST["payment_method"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"] ?? '');
    $customer_type = mysqli_real_escape_string($db, $_POST["customer_type"] ?? "Walk-in Customer");
    $status = mysqli_real_escape_string($db, $_POST["status"] ?? "Booked");
    $remarks = mysqli_real_escape_string($db, $_POST["remarks"] ?? "");

    // Validation
    $errors = [];
    if (empty($passenger_name)) $errors[] = "Passenger Name is required.";
    if (empty($customer_name)) $errors[] = "Customer Name is required.";
    if (empty($supplier_name)) $errors[] = "Supplier Name is required.";

    // Flat fields
    $from_city = mysqli_real_escape_string($db, $_POST["from_city"] ?? "");
    $to_city = mysqli_real_escape_string($db, $_POST["to_city"] ?? "");
    $departure_date = mysqli_real_escape_string($db, $_POST["departure_date"] ?? "");
    $departure_time = mysqli_real_escape_string($db, $_POST["departure_time"] ?? "");
    $arrival_date = mysqli_real_escape_string($db, $_POST["arrival_date"] ?? "");
    $arrival_time = mysqli_real_escape_string($db, $_POST["arrival_time"] ?? "");
    $pnr = mysqli_real_escape_string($db, $_POST["pnr"] ?? "");
    $ticket_number = mysqli_real_escape_string($db, $_POST["ticket_number"] ?? "");
    $flight_number = mysqli_real_escape_string($db, $_POST["flight_number"] ?? "");
    $airline_name = mysqli_real_escape_string($db, $_POST["airline_name"] ?? "");
    $flight_class = mysqli_real_escape_string($db, $_POST["flight_class"] ?? "");

    // Hotel fields
    $hotel_name = mysqli_real_escape_string($db, $_POST["hotel_name"] ?? "");
    $hotel_location = mysqli_real_escape_string($db, $_POST["hotel_location"] ?? "");
    $hotel_check_in = mysqli_real_escape_string($db, $_POST["hotel_check_in"] ?? "");
    $hotel_check_out = mysqli_real_escape_string($db, $_POST["hotel_check_out"] ?? "");
    $hotel_room_type = mysqli_real_escape_string($db, $_POST["hotel_room_type"] ?? "");
    $hotel_rooms_count = !empty($_POST["hotel_rooms_count"]) ? intval($_POST["hotel_rooms_count"]) : "NULL";
    $hotel_confirmation_no = mysqli_real_escape_string($db, $_POST["hotel_confirmation_no"] ?? "");

    // Visa fields
    $visa_type = mysqli_real_escape_string($db, $_POST["visa_type"] ?? "");
    $visa_country = mysqli_real_escape_string($db, $_POST["visa_country"] ?? "");
    $visa_app_no = mysqli_real_escape_string($db, $_POST["visa_app_no"] ?? "");
    $visa_submission_date = mysqli_real_escape_string($db, $_POST["visa_submission_date"] ?? "");
    $visa_delivery_date = mysqli_real_escape_string($db, $_POST["visa_delivery_date"] ?? "");
    $visa_valid_from = mysqli_real_escape_string($db, $_POST["visa_valid_from"] ?? "");
    $visa_valid_to = mysqli_real_escape_string($db, $_POST["visa_valid_to"] ?? "");

    // Travel Insurance fields
    $insurance_provider = mysqli_real_escape_string($db, $_POST["insurance_provider"] ?? "");
    $insurance_policy_no = mysqli_real_escape_string($db, $_POST["insurance_policy_no"] ?? "");
    $insurance_coverage_type = mysqli_real_escape_string($db, $_POST["insurance_coverage_type"] ?? "");
    $insurance_destination = mysqli_real_escape_string($db, $_POST["insurance_destination"] ?? "");
    $insurance_start_date = mysqli_real_escape_string($db, $_POST["insurance_start_date"] ?? "");
    $insurance_end_date = mysqli_real_escape_string($db, $_POST["insurance_end_date"] ?? "");
    $insurance_sum_insured = !empty($_POST["insurance_sum_insured"]) ? floatval($_POST["insurance_sum_insured"]) : "NULL";

    // Holiday Package fields
    $package_name = mysqli_real_escape_string($db, $_POST["package_name"] ?? "");
    $package_destinations = mysqli_real_escape_string($db, $_POST["package_destinations"] ?? "");
    $package_type = mysqli_real_escape_string($db, $_POST["package_type"] ?? "");
    $package_start_date = mysqli_real_escape_string($db, $_POST["package_start_date"] ?? "");
    $package_end_date = mysqli_real_escape_string($db, $_POST["package_end_date"] ?? "");
    $package_adults_count = !empty($_POST["package_adults_count"]) ? intval($_POST["package_adults_count"]) : "NULL";
    $package_children_count = !empty($_POST["package_children_count"]) ? intval($_POST["package_children_count"]) : "NULL";
    $package_accommodation = mysqli_real_escape_string($db, $_POST["package_accommodation"] ?? "");
    $package_meals = mysqli_real_escape_string($db, $_POST["package_meals"] ?? "");
    $package_itinerary = mysqli_real_escape_string($db, $_POST["package_itinerary"] ?? "");

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = "error";
    } else {
        $customer_master_id = isset($_POST["customer_master_id"]) ? intval($_POST["customer_master_id"]) : 0;

        // Check existing or auto-create in customer_master if no customer_master_id is set
        if ($customer_master_id === 0 && $customer_name !== '') {
            $escaped_mobile = mysqli_real_escape_string($db, $customer_mobile);
            $check_q = null;
            if ($customer_mobile !== '') {
                $check_q = mysqli_query($db, "SELECT id FROM customer_master WHERE mobile = '$escaped_mobile' LIMIT 1");
            }
            
            if ($check_q && mysqli_num_rows($check_q) > 0) {
                $check_row = mysqli_fetch_assoc($check_q);
                $customer_master_id = intval($check_row['id']);
            } else {
                // Create a new record in customer_master
                $type = ($customer_type && $customer_type !== '') ? $customer_type : 'Walk-in Customer';
                $insert_cust_sql = "INSERT INTO customer_master (customer_type, name, mobile, email, created_by) 
                                    VALUES ('$type', '$customer_name', '$customer_mobile', '$customer_email', '$updated_by')";
                if (mysqli_query($db, $insert_cust_sql)) {
                    $customer_master_id = mysqli_insert_id($db);
                    
                    // Log customer creation activity
                    $log_user = $_SESSION['user_name'] ?? 'System';
                    mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) 
                                       VALUES ('$log_user', 'Created Customer #$customer_master_id ($customer_name) via Booking Form', 'Customer Master', NOW())");
                }
            }
        }

        // Update customer details in customer_master if they exist
        if ($customer_master_id > 0 && ($customer_mobile !== '' || $customer_email !== '')) {
            $update_parts = [];
            if ($customer_mobile !== '') $update_parts[] = "mobile = '$customer_mobile'";
            if ($customer_email !== '') $update_parts[] = "email = '$customer_email'";
            mysqli_query($db, "UPDATE customer_master SET " . implode(", ", $update_parts) . " WHERE id = $customer_master_id");
        }

        $update_query = "UPDATE bookings SET
            serial_no = '$serial_no',
            booking_date = " . ($booking_date ? "'$booking_date'" : "NULL") . ",
            passenger_name = '$passenger_name',
            customer_name = '$customer_name',
            customer_type = '$customer_type',
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
            ticket_path = " . ($ticket_path ? "'$ticket_path'" : "NULL") . ",
            customer_master_id = " . ($customer_master_id > 0 ? $customer_master_id : "NULL") . ",
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
            hotel_name = '$hotel_name',
            hotel_location = '$hotel_location',
            hotel_check_in = " . ($hotel_check_in ? "'$hotel_check_in'" : "NULL") . ",
            hotel_check_out = " . ($hotel_check_out ? "'$hotel_check_out'" : "NULL") . ",
            hotel_room_type = '$hotel_room_type',
            hotel_rooms_count = $hotel_rooms_count,
            hotel_confirmation_no = '$hotel_confirmation_no',
            visa_type = '$visa_type',
            visa_country = '$visa_country',
            visa_app_no = '$visa_app_no',
            visa_submission_date = " . ($visa_submission_date ? "'$visa_submission_date'" : "NULL") . ",
            visa_delivery_date = " . ($visa_delivery_date ? "'$visa_delivery_date'" : "NULL") . ",
            visa_valid_from = " . ($visa_valid_from ? "'$visa_valid_from'" : "NULL") . ",
            visa_valid_to = " . ($visa_valid_to ? "'$visa_valid_to'" : "NULL") . ",
            insurance_provider = '$insurance_provider',
            insurance_policy_no = '$insurance_policy_no',
            insurance_coverage_type = '$insurance_coverage_type',
            insurance_destination = '$insurance_destination',
            insurance_start_date = " . ($insurance_start_date ? "'$insurance_start_date'" : "NULL") . ",
            insurance_end_date = " . ($insurance_end_date ? "'$insurance_end_date'" : "NULL") . ",
            insurance_sum_insured = $insurance_sum_insured,
            package_name = '$package_name',
            package_destinations = '$package_destinations',
            package_type = '$package_type',
            package_start_date = " . ($package_start_date ? "'$package_start_date'" : "NULL") . ",
            package_end_date = " . ($package_end_date ? "'$package_end_date'" : "NULL") . ",
            package_adults_count = $package_adults_count,
            package_children_count = $package_children_count,
            package_accommodation = '$package_accommodation',
            package_meals = '$package_meals',
            package_itinerary = '$package_itinerary'
        WHERE id = $id";

        if (mysqli_query($db, $update_query)) {
            // Save attachment if new file was uploaded
            if (isset($uploaded_document) && $uploaded_document && !empty($ticket_path)) {
                $att_name = "Uploaded " . $attachment_type . " (" . basename($ticket_path) . ")";
                $db_ticket_path = mysqli_real_escape_string($db, $ticket_path);
                mysqli_query($db, "INSERT INTO booking_attachments (booking_id, file_path, file_name, file_type) VALUES ($id, '$db_ticket_path', '$att_name', '$attachment_type')");
            }

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
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Booking | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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
            
            const selectEl = document.getElementById('service_type');
            if (selectEl) {
                selectEl.addEventListener('change', handleServiceTypeChange);
                handleServiceTypeChange();
            }

        });

        function handleServiceTypeChange() {
            const select = document.getElementById('service_type');
            if (!select) return;
            
            const val = select.value;

            // Hide all service specific sections first
            document.querySelectorAll('.service-section').forEach(sec => {
                sec.style.display = 'none';
            });

            // Show the corresponding active section
            let activeClass = '';
            if (val === 'Flight') activeClass = 'flight-fields';
            else if (val === 'Hotel') activeClass = 'hotel-fields';
            else if (val === 'Visa') activeClass = 'visa-fields';
            else if (val === 'Travel Insurance') activeClass = 'insurance-fields';
            else if (val === 'Holiday Package') activeClass = 'holiday-fields';

            if (activeClass !== '') {
                const activeSec = document.querySelector('.' + activeClass);
                if (activeSec) activeSec.style.display = 'grid';
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
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

    <form method="POST" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" id="customer_master_id" name="customer_master_id" value="<?php echo htmlspecialchars($booking['customer_master_id'] ?? ''); ?>">
        
        <div class="card">
            <h2>Passenger & Booking Details</h2>
            
            <div class="booking-grid">
                <div class="form-group">
                    <label for="serial_no">Serial No</label>
                    <input type="text" id="serial_no" name="serial_no" value="<?php echo htmlspecialchars($booking['serial_no']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="booking_date">Booking Date</label>
                    <input type="date" id="booking_date" name="booking_date" value="<?php echo htmlspecialchars($booking['booking_date']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="customer_type">Customer Type</label>
                    <select id="customer_type" name="customer_type" required>
                        <option value="">Select Customer Type</option>
                        <option value="Walk-in Customer" <?php echo (($booking['customer_type'] ?? '') === 'Walk-in Customer') ? 'selected' : ''; ?>>Walk-in Customer</option>
                        <option value="B2B" <?php echo (($booking['customer_type'] ?? '') === 'B2B') ? 'selected' : ''; ?>>B2B</option>
                        <option value="Corporate" <?php echo (($booking['customer_type'] ?? '') === 'Corporate') ? 'selected' : ''; ?>>Corporate</option>
                        <option value="User" <?php echo (($booking['customer_type'] ?? '') === 'User') ? 'selected' : ''; ?>>User</option>
                        <option value="Other" <?php echo (($booking['customer_type'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="customer_name">Customer / Agency Name</label>
                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($booking['customer_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="passenger_name">Passenger Name</label>
                    <input type="text" id="passenger_name" name="passenger_name" value="<?php echo htmlspecialchars($booking['passenger_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="customer_mobile">Customer Phone / Mobile</label>
                    <input type="text" id="customer_mobile" name="customer_mobile" value="<?php echo htmlspecialchars($cust_mobile); ?>">
                </div>
                <div class="form-group">
                    <label for="customer_email">Customer Email</label>
                    <input type="email" id="customer_email" name="customer_email" value="<?php echo htmlspecialchars($cust_email); ?>">
                </div>
                <div class="form-group">
                    <label for="service_type">Service Type</label>
                    <select id="service_type" name="service_type" required>
                        <option value="Flight" <?php echo ($booking['service_type'] === 'Flight') ? 'selected' : ''; ?>>Flight</option>
                        <option value="Hotel" <?php echo ($booking['service_type'] === 'Hotel') ? 'selected' : ''; ?>>Hotel</option>
                        <option value="Visa" <?php echo ($booking['service_type'] === 'Visa') ? 'selected' : ''; ?>>Visa</option>
                        <option value="Travel Insurance" <?php echo ($booking['service_type'] === 'Travel Insurance') ? 'selected' : ''; ?>>Travel Insurance</option>
                        <option value="Holiday Package" <?php echo ($booking['service_type'] === 'Holiday Package') ? 'selected' : ''; ?>>Holiday Package</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="supplier_name">Supplier Name</label>
                    <input type="text" id="supplier_name" name="supplier_name" value="<?php echo htmlspecialchars($booking['supplier_name']); ?>" required>
                </div>
                
                <!-- SERVICE SPECIFIC SECTIONS -->
                <!-- Flight Section -->
                <div class="booking-grid service-section flight-fields" style="grid-column: span 4; display: none; margin-top: 0; padding-top: 0;">
                    <h4 class="span-4" style="font-size: 12px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 4px;">Flight Information</h4>
                    <div class="form-group">
                        <label for="from_city">From (Origin)</label>
                        <input type="text" id="from_city" name="from_city" placeholder="Origin City" value="<?php echo htmlspecialchars($booking['from_city']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="to_city">To (Destination)</label>
                        <input type="text" id="to_city" name="to_city" placeholder="Destination City" value="<?php echo htmlspecialchars($booking['to_city']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="departure_date">Departure Date</label>
                        <input type="date" id="departure_date" name="departure_date" value="<?php echo htmlspecialchars($booking['departure_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="departure_time">Departure Time</label>
                        <input type="text" id="departure_time" name="departure_time" placeholder="HH:MM" value="<?php echo htmlspecialchars($booking['departure_time']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="arrival_date">Arrival Date</label>
                        <input type="date" id="arrival_date" name="arrival_date" value="<?php echo htmlspecialchars($booking['arrival_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="arrival_time">Arrival Time</label>
                        <input type="text" id="arrival_time" name="arrival_time" placeholder="HH:MM" value="<?php echo htmlspecialchars($booking['arrival_time']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="airline_name">Airline Name</label>
                        <input type="text" id="airline_name" name="airline_name" placeholder="Airline" value="<?php echo htmlspecialchars($booking['airline_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="flight_number">Flight Number</label>
                        <input type="text" id="flight_number" name="flight_number" placeholder="Flight No" value="<?php echo htmlspecialchars($booking['flight_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="pnr">PNR Code</label>
                        <input type="text" id="pnr" name="pnr" placeholder="PNR" value="<?php echo htmlspecialchars($booking['pnr']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ticket_number">Ticket Number</label>
                        <input type="text" id="ticket_number" name="ticket_number" placeholder="Ticket No" value="<?php echo htmlspecialchars($booking['ticket_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="flight_class">Class</label>
                        <select id="flight_class" name="flight_class">
                            <option value="Economy" <?php echo ($booking['flight_class'] === 'Economy') ? 'selected' : ''; ?>>Economy</option>
                            <option value="Premium Economy" <?php echo ($booking['flight_class'] === 'Premium Economy') ? 'selected' : ''; ?>>Premium Economy</option>
                            <option value="Business" <?php echo ($booking['flight_class'] === 'Business') ? 'selected' : ''; ?>>Business</option>
                            <option value="First" <?php echo ($booking['flight_class'] === 'First') ? 'selected' : ''; ?>>First Class</option>
                        </select>
                    </div>
                </div>

                <!-- Hotel Section -->
                <div class="booking-grid service-section hotel-fields" style="grid-column: span 4; display: none; margin-top: 0; padding-top: 0;">
                    <h4 class="span-4" style="font-size: 12px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 4px;">Hotel Information</h4>
                    <div class="form-group span-2">
                        <label for="hotel_name">Hotel Name</label>
                        <input type="text" id="hotel_name" name="hotel_name" placeholder="Hotel Name" value="<?php echo htmlspecialchars($booking['hotel_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group span-2">
                        <label for="hotel_location">Location / City</label>
                        <input type="text" id="hotel_location" name="hotel_location" placeholder="City" value="<?php echo htmlspecialchars($booking['hotel_location'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="hotel_check_in">Check-in Date</label>
                        <input type="date" id="hotel_check_in" name="hotel_check_in" value="<?php echo htmlspecialchars($booking['hotel_check_in'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="hotel_check_out">Check-out Date</label>
                        <input type="date" id="hotel_check_out" name="hotel_check_out" value="<?php echo htmlspecialchars($booking['hotel_check_out'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="hotel_room_type">Room Type</label>
                        <input type="text" id="hotel_room_type" name="hotel_room_type" placeholder="e.g. Deluxe Suite" value="<?php echo htmlspecialchars($booking['hotel_room_type'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="hotel_rooms_count">Number of Rooms</label>
                        <input type="number" id="hotel_rooms_count" name="hotel_rooms_count" placeholder="1" value="<?php echo htmlspecialchars($booking['hotel_rooms_count'] ?? ''); ?>">
                    </div>
                    <div class="form-group span-2">
                        <label for="hotel_confirmation_no">Confirmation Number</label>
                        <input type="text" id="hotel_confirmation_no" name="hotel_confirmation_no" placeholder="Confirmation No" value="<?php echo htmlspecialchars($booking['hotel_confirmation_no'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Visa Section -->
                <div class="booking-grid service-section visa-fields" style="grid-column: span 4; display: none; margin-top: 0; padding-top: 0;">
                    <h4 class="span-4" style="font-size: 12px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 4px;">Visa Information</h4>
                    <div class="form-group">
                        <label for="visa_type">Visa Type</label>
                        <select id="visa_type" name="visa_type">
                            <option value="Tourist" <?php echo ($booking['visa_type'] === 'Tourist') ? 'selected' : ''; ?>>Tourist</option>
                            <option value="Business" <?php echo ($booking['visa_type'] === 'Business') ? 'selected' : ''; ?>>Business</option>
                            <option value="Student" <?php echo ($booking['visa_type'] === 'Student') ? 'selected' : ''; ?>>Student</option>
                            <option value="Work" <?php echo ($booking['visa_type'] === 'Work') ? 'selected' : ''; ?>>Work</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="visa_country">Destination Country</label>
                        <input type="text" id="visa_country" name="visa_country" placeholder="Country" value="<?php echo htmlspecialchars($booking['visa_country'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="visa_app_no">Application Number</label>
                        <input type="text" id="visa_app_no" name="visa_app_no" placeholder="Application No" value="<?php echo htmlspecialchars($booking['visa_app_no'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="visa_submission_date">Submission Date</label>
                        <input type="date" id="visa_submission_date" name="visa_submission_date" value="<?php echo htmlspecialchars($booking['visa_submission_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="visa_delivery_date">Expected Delivery Date</label>
                        <input type="date" id="visa_delivery_date" name="visa_delivery_date" value="<?php echo htmlspecialchars($booking['visa_delivery_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="visa_valid_from">Validity From</label>
                        <input type="date" id="visa_valid_from" name="visa_valid_from" value="<?php echo htmlspecialchars($booking['visa_valid_from'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="visa_valid_to">Validity To</label>
                        <input type="date" id="visa_valid_to" name="visa_valid_to" value="<?php echo htmlspecialchars($booking['visa_valid_to'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Travel Insurance Section -->
                <div class="booking-grid service-section insurance-fields" style="grid-column: span 4; display: none; margin-top: 0; padding-top: 0;">
                    <h4 class="span-4" style="font-size: 12px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 4px;">Travel Insurance Information</h4>
                    <div class="form-group span-2">
                        <label for="insurance_provider">Insurance Provider</label>
                        <input type="text" id="insurance_provider" name="insurance_provider" placeholder="Provider Name" value="<?php echo htmlspecialchars($booking['insurance_provider'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="insurance_policy_no">Policy Number</label>
                        <input type="text" id="insurance_policy_no" name="insurance_policy_no" placeholder="Policy No" value="<?php echo htmlspecialchars($booking['insurance_policy_no'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="insurance_coverage_type">Coverage Type</label>
                        <select id="insurance_coverage_type" name="insurance_coverage_type">
                            <option value="Individual" <?php echo ($booking['insurance_coverage_type'] === 'Individual') ? 'selected' : ''; ?>>Individual</option>
                            <option value="Family" <?php echo ($booking['insurance_coverage_type'] === 'Family') ? 'selected' : ''; ?>>Family</option>
                            <option value="Group" <?php echo ($booking['insurance_coverage_type'] === 'Group') ? 'selected' : ''; ?>>Group</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label_for="insurance_destination">Destination Country/Region</label>
                        <input type="text" id="insurance_destination" name="insurance_destination" placeholder="Destination(s)" value="<?php echo htmlspecialchars($booking['insurance_destination'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="insurance_start_date">Coverage Start Date</label>
                        <input type="date" id="insurance_start_date" name="insurance_start_date" value="<?php echo htmlspecialchars($booking['insurance_start_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="insurance_end_date">Coverage End Date</label>
                        <input type="date" id="insurance_end_date" name="insurance_end_date" value="<?php echo htmlspecialchars($booking['insurance_end_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group span-2">
                        <label for="insurance_sum_insured">Sum Insured / Coverage Amount (₹)</label>
                        <input type="number" step="0.01" id="insurance_sum_insured" name="insurance_sum_insured" placeholder="0.00" value="<?php echo htmlspecialchars($booking['insurance_sum_insured'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Holiday Package Section -->
                <div class="booking-grid service-section holiday-fields" style="grid-column: span 4; display: none; margin-top: 0; padding-top: 0;">
                    <h4 class="span-4" style="font-size: 12px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 4px;">Holiday Package Information</h4>
                    <div class="form-group span-2">
                        <label for="package_name">Package Name</label>
                        <input type="text" id="package_name" name="package_name" placeholder="Package Name" value="<?php echo htmlspecialchars($booking['package_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group span-2">
                        <label for="package_destinations">Destination(s)</label>
                        <input type="text" id="package_destinations" name="package_destinations" placeholder="e.g. Kerala, Goa" value="<?php echo htmlspecialchars($booking['package_destinations'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_type">Package Type</label>
                        <select id="package_type" name="package_type">
                            <option value="Domestic" <?php echo ($booking['package_type'] === 'Domestic') ? 'selected' : ''; ?>>Domestic</option>
                            <option value="International" <?php echo ($booking['package_type'] === 'International') ? 'selected' : ''; ?>>International</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="package_start_date">Start Date</label>
                        <input type="date" id="package_start_date" name="package_start_date" value="<?php echo htmlspecialchars($booking['package_start_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_end_date">End Date</label>
                        <input type="date" id="package_end_date" name="package_end_date" value="<?php echo htmlspecialchars($booking['package_end_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_adults_count">Number of Adults</label>
                        <input type="number" id="package_adults_count" name="package_adults_count" placeholder="2" value="<?php echo htmlspecialchars($booking['package_adults_count'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_children_count">Number of Children</label>
                        <input type="number" id="package_children_count" name="package_children_count" placeholder="0" value="<?php echo htmlspecialchars($booking['package_children_count'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="package_accommodation">Accommodation Included</label>
                        <select id="package_accommodation" name="package_accommodation">
                            <option value="Yes" <?php echo ($booking['package_accommodation'] === 'Yes') ? 'selected' : ''; ?>>Yes</option>
                            <option value="No" <?php echo ($booking['package_accommodation'] === 'No') ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label for="package_meals">Meals Included</label>
                        <select id="package_meals" name="package_meals">
                            <option value="No Meals" <?php echo ($booking['package_meals'] === 'No Meals') ? 'selected' : ''; ?>>No Meals</option>
                            <option value="Breakfast Only" <?php echo ($booking['package_meals'] === 'Breakfast Only') ? 'selected' : ''; ?>>Breakfast Only</option>
                            <option value="Half Board" <?php echo ($booking['package_meals'] === 'Half Board') ? 'selected' : ''; ?>>Half Board</option>
                            <option value="Full Board" <?php echo ($booking['package_meals'] === 'Full Board') ? 'selected' : ''; ?>>Full Board</option>
                            <option value="All Inclusive" <?php echo ($booking['package_meals'] === 'All Inclusive') ? 'selected' : ''; ?>>All Inclusive</option>
                        </select>
                    </div>
                    <div class="form-group span-4">
                        <label for="package_itinerary">Itinerary Notes</label>
                        <textarea id="package_itinerary" name="package_itinerary" rows="3" placeholder="Package details and itinerary notes..."><?php echo htmlspecialchars($booking['package_itinerary'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- Financials -->
                <div class="form-group">
                    <label for="buying_cost">Buying Cost (₹)</label>
                    <input type="text" id="buying_cost" name="buying_cost" value="<?php echo htmlspecialchars($booking['buying_cost']); ?>" oninput="calculateProfit()" required>
                </div>
                <div class="form-group">
                    <label for="selling_cost">Selling Cost (₹)</label>
                    <input type="text" id="selling_cost" name="selling_cost" value="<?php echo htmlspecialchars($booking['selling_cost']); ?>" oninput="calculateProfit()" required>
                </div>
                <div class="form-group">
                    <label for="profit">Profit Margin (₹)</label>
                    <input type="text" id="profit" name="profit" value="<?php echo htmlspecialchars($booking['profit']); ?>" readonly style="background: #f8fafc; font-weight: 700; color: var(--status-booked-text);">
                </div>
                <div class="form-group">
                    <label for="payment_method">Payment Mode</label>
                    <select id="payment_method" name="payment_method">
                        <option value="Cash" <?php echo $booking['payment_method'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Credit" <?php echo $booking['payment_method'] === 'Credit' ? 'selected' : ''; ?>>Credit</option>
                        <option value="UPI" <?php echo $booking['payment_method'] === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                        <option value="Bank Transfer" <?php echo $booking['payment_method'] === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Booking Status</label>
                    <select id="status" name="status" required>
                        <option value="Booked" <?php echo $booking['status'] === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                        <option value="Issued" <?php echo $booking['status'] === 'Issued' ? 'selected' : ''; ?>>Issued</option>
                        <option value="On Hold" <?php echo $booking['status'] === 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                        <option value="Cancelled" <?php echo $booking['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assigned_user">Assigned Agent</label>
                    <input type="text" id="assigned_user" name="assigned_user" value="<?php echo htmlspecialchars($booking['assigned_user'] ?? ''); ?>">
                </div>
                <div class="form-group span-2">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="2" placeholder="Remarks" style="min-height: 34px;"><?php echo htmlspecialchars($booking['remarks'] ?? ''); ?></textarea>
                </div>

                <div class="span-4" style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <button type="submit" name="update_booking" class="btn" style="background: var(--accent-color); color: white; padding: 12px 30px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer;">Save Updates</button>
                </div>
            </div>
        </div>
    </form>
</div>

</body>
</html>

