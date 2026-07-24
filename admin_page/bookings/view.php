<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";
$message_type = "";
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$current_user_name = mysqli_real_escape_string($db, (string) ($_SESSION['user_name'] ?? ''));
$booking_owner_sql = $is_admin
    ? ''
    : " AND created_by = '$current_user_name'";

// Authorize the booking before allowing view, upload, attachment deletion, or
// booking deletion. Admins can access every booking; users can access only
// bookings created by them.
$query = "SELECT * FROM bookings WHERE id = $id$booking_owner_sql LIMIT 1";
$result = mysqli_query($db, $query);
$booking = $result ? mysqli_fetch_assoc($result) : null;

if (!$booking) {
    http_response_code(404);
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Booking not found.</h2><a href='list.php'>Back to list</a></div>";
    exit;
}

// Handle Delete Booking Request
if (isset($_POST['delete'])) {
    $delete_query = "DELETE FROM bookings WHERE id = $id$booking_owner_sql";
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

// Fetch attachments
$attachments_query = mysqli_query($db, "SELECT * FROM booking_attachments WHERE booking_id = $id ORDER BY id DESC");



$selling_cost = floatval($booking['selling_cost']);
$booking_status = $booking['status'] ?: 'Booked';
$booking_status_class = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $booking_status), '-'));
$booking_date_display = !empty($booking['booking_date']) ? date('d M Y', strtotime($booking['booking_date'])) : 'Not set';
$booking_document_type = strtolower(trim((string) ($booking['document_type'] ?? '')));
$route_display = trim(($booking['from_city'] ?: '') . ((!empty($booking['from_city']) || !empty($booking['to_city'])) ? ' to ' : '') . ($booking['to_city'] ?: ''));
$route_display = $route_display ?: ($booking['customer_name'] ?: 'Not set');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Info | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<main class="main booking-view-page booking-compact-view">
    <header class="booking-page-topbar">
        <div>
            <p class="booking-page-eyebrow">Booking workspace</p>
            <h1>Booking details</h1>
            <p class="booking-page-subtitle">Review the service, documents, payment and account history in one place.</p>
        </div>
        <div class="booking-page-actions">
            <a href="list.php" class="booking-btn">Back to bookings</a>
        </div>
    </header>

    <?php if (!empty($message)): ?>
        <div class="booking-alert <?php echo $message_type === 'success' ? 'is-success' : 'is-error'; ?>" role="alert">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Top Card: Header Details -->
    <section class="view-header-card booking-hero">
        <div class="booking-hero-main">
            <div class="booking-hero-labels">
                <span class="booking-reference">Booking #<?php echo htmlspecialchars($booking['serial_no'] ?: $booking['id']); ?></span>
                <span class="booking-status status-<?php echo htmlspecialchars($booking_status_class); ?>"><?php echo htmlspecialchars($booking_status); ?></span>
            </div>
            <h2><?php echo htmlspecialchars($booking['passenger_name'] ?: $booking['customer_name'] ?: 'Unnamed traveller'); ?></h2>
            <p class="booking-hero-customer">Customer: <?php echo htmlspecialchars($booking['customer_name'] ?: 'Not specified'); ?></p>
        </div>
        <div class="booking-hero-meta" aria-label="Booking summary">
            <div><span>Service</span><strong><?php echo htmlspecialchars($booking['service_type'] ?: 'Travel'); ?></strong></div>
            <div><span>PNR / Reference</span><strong><?php echo htmlspecialchars($booking['pnr'] ?: $booking['booking_ref'] ?: 'Not set'); ?></strong></div>
            <div><span>Route / Customer</span><strong><?php echo htmlspecialchars($route_display); ?></strong></div>
            <div><span>Booking date</span><strong><?php echo htmlspecialchars($booking_date_display); ?></strong></div>
        </div>
    </section>

    <!-- Main Content Grid -->
    <div class="bottom-grid booking-content-grid">
        
        <!-- Left: Flight & Ticket & Attachments -->
        <div class="booking-main-column">
            
            <!-- Booking Details Card -->
            <div class="card booking-section">
                <?php if ($booking_document_type === 'ticket' || $booking['service_type'] === 'Flight'): ?>
                    <h2>Flight and ticket details</h2>
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
                            <h4>Class</h4>
                            <p><?php echo htmlspecialchars($booking["flight_class"] ?: '-'); ?></p>
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
                            <p><?php echo htmlspecialchars($booking["service_type"]); ?></p>
                        </div>
                    </div>
                <?php elseif ($booking_document_type === 'hotel' || $booking['service_type'] === 'Hotel'): ?>
                    <h2>Hotel voucher details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <div class="info-card-item">
                            <h4>Passenger Name(s)</h4>
                            <p><?php echo htmlspecialchars($booking["passenger_name"]); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Hotel Name</h4>
                            <p><?php echo htmlspecialchars($booking["hotel_name"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Location / City</h4>
                            <p><?php echo htmlspecialchars($booking["hotel_location"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Check-in Date</h4>
                            <p><?php echo htmlspecialchars($booking["hotel_check_in"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Check-out Date</h4>
                            <p><?php echo htmlspecialchars($booking["hotel_check_out"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Room Type</h4>
                            <p><?php echo htmlspecialchars($booking["hotel_room_type"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Number of Rooms</h4>
                            <p><?php echo $booking["hotel_rooms_count"] !== null ? htmlspecialchars($booking["hotel_rooms_count"]) : '-'; ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Confirmation Number</h4>
                            <p style="font-family: monospace;"><?php echo htmlspecialchars($booking["hotel_confirmation_no"] ?: '-'); ?></p>
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
                            <p><?php echo htmlspecialchars($booking["service_type"]); ?></p>
                        </div>
                    </div>
                <?php elseif ($booking_document_type === 'visa' || $booking['service_type'] === 'Visa'): ?>
                    <h2>Visa details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <div class="info-card-item">
                            <h4>Passenger Name(s)</h4>
                            <p><?php echo htmlspecialchars($booking["passenger_name"]); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Visa Type</h4>
                            <p><?php echo htmlspecialchars($booking["visa_type"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Destination Country</h4>
                            <p><?php echo htmlspecialchars($booking["visa_country"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Application Number</h4>
                            <p style="font-family: monospace;"><?php echo htmlspecialchars($booking["visa_app_no"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Submission Date</h4>
                            <p><?php echo htmlspecialchars($booking["visa_submission_date"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Expected Delivery Date</h4>
                            <p><?php echo htmlspecialchars($booking["visa_delivery_date"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Validity From</h4>
                            <p><?php echo htmlspecialchars($booking["visa_valid_from"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Validity To</h4>
                            <p><?php echo htmlspecialchars($booking["visa_valid_to"] ?: '-'); ?></p>
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
                            <p><?php echo htmlspecialchars($booking["service_type"]); ?></p>
                        </div>
                    </div>
                <?php elseif ($booking_document_type === 'passport' || $booking['service_type'] === 'Passport'): ?>
                    <h2>Passport details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <?php
                        $passport_view_fields = [
                            'Passport Number' => 'passport_number', 'Passport Type' => 'passport_type',
                            'Issuing Country' => 'passport_issuing_country', 'Country Code' => 'passport_country_code',
                            'Full Name' => 'passport_full_name', 'Surname' => 'passport_surname',
                            'Given Names' => 'passport_given_names', 'Nationality' => 'passport_nationality',
                            'Gender' => 'passport_gender', 'Date of Birth' => 'passport_date_of_birth',
                            'Place of Birth' => 'passport_place_of_birth', 'Date of Issue' => 'passport_date_of_issue',
                            'Date of Expiry' => 'passport_date_of_expiry', 'Place of Issue' => 'passport_place_of_issue',
                            'Authority' => 'passport_authority', 'MRZ Line 1' => 'passport_mrz_line1', 'MRZ Line 2' => 'passport_mrz_line2'
                        ];
                        foreach ($passport_view_fields as $label => $field): ?>
                            <div class="info-card-item"><h4><?php echo htmlspecialchars($label); ?></h4><p><?php echo htmlspecialchars($booking[$field] ?: '-'); ?></p></div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($booking_document_type === 'insurance' || in_array($booking['service_type'], ['Insurance', 'Travel Insurance'], true)): ?>
                    <h2>Travel insurance details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <div class="info-card-item">
                            <h4>Passenger Name(s)</h4>
                            <p><?php echo htmlspecialchars($booking["passenger_name"]); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Insurance Provider</h4>
                            <p><?php echo htmlspecialchars($booking["insurance_provider"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Policy Number</h4>
                            <p style="font-family: monospace;"><?php echo htmlspecialchars($booking["insurance_policy_no"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Coverage Type</h4>
                            <p><?php echo htmlspecialchars($booking["insurance_coverage_type"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Destination</h4>
                            <p><?php echo htmlspecialchars($booking["insurance_destination"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Coverage Start Date</h4>
                            <p><?php echo htmlspecialchars($booking["insurance_start_date"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Coverage End Date</h4>
                            <p><?php echo htmlspecialchars($booking["insurance_end_date"] ?: '-'); ?></p>
                        </div>
                        <?php
                        $insurance_extra_fields = [
                            'Insured Person Name' => 'insurance_insured_name',
                            'Passport Number' => 'insurance_passport_no',
                            'Policy Issue Date' => 'insurance_issue_date',
                            'Number of Days' => 'insurance_days',
                            'Premium Amount' => 'insurance_premium_amount',
                            'Emergency Assistance Number' => 'insurance_emergency_no',
                            'Certificate Number' => 'insurance_certificate_no',
                            'Coverage Details' => 'insurance_coverage_details',
                            'Remarks' => 'insurance_remarks'
                        ];
                        foreach ($insurance_extra_fields as $label => $field): ?>
                            <div class="info-card-item"><h4><?php echo htmlspecialchars($label); ?></h4><p><?php echo htmlspecialchars((string) ($booking[$field] ?? '-')); ?></p></div>
                        <?php endforeach; ?>
                        <div class="info-card-item">
                            <h4>Sum Insured</h4>
                            <p><?php echo $booking["insurance_sum_insured"] !== null ? '₹' . number_format($booking["insurance_sum_insured"], 2) : '-'; ?></p>
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
                            <p><?php echo htmlspecialchars($booking["service_type"]); ?></p>
                        </div>
                    </div>
                <?php elseif ($booking_document_type === 'package' || in_array($booking['service_type'], ['Tour Package', 'Holiday Package'], true)): ?>
                    <h2>Holiday package details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <div class="info-card-item">
                            <h4>Passenger Name(s)</h4>
                            <p><?php echo htmlspecialchars($booking["passenger_name"]); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Package Name</h4>
                            <p><?php echo htmlspecialchars($booking["package_name"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Destination(s)</h4>
                            <p><?php echo htmlspecialchars($booking["package_destinations"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Package Type</h4>
                            <p><?php echo htmlspecialchars($booking["package_type"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Start Date</h4>
                            <p><?php echo htmlspecialchars($booking["package_start_date"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>End Date</h4>
                            <p><?php echo htmlspecialchars($booking["package_end_date"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Number of Adults</h4>
                            <p><?php echo $booking["package_adults_count"] !== null ? htmlspecialchars($booking["package_adults_count"]) : '-'; ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Number of Children</h4>
                            <p><?php echo $booking["package_children_count"] !== null ? htmlspecialchars($booking["package_children_count"]) : '-'; ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Accommodation</h4>
                            <p><?php echo htmlspecialchars($booking["package_accommodation"] ?: '-'); ?></p>
                        </div>
                        <div class="info-card-item">
                            <h4>Meals</h4>
                            <p><?php echo htmlspecialchars($booking["package_meals"] ?: '-'); ?></p>
                        </div>
                        <?php
                        $package_extra_fields = [
                            'Package / Voucher Number' => 'package_voucher_no',
                            'Country' => 'package_country',
                            'Passenger / Guest Names' => 'package_guest_names',
                            'Number of Days' => 'package_days_count',
                            'Number of Nights' => 'package_nights_count',
                            'Infants' => 'package_infants_count',
                            'Room Type' => 'package_room_type',
                            'Transportation' => 'package_transportation',
                            'Pickup Details' => 'package_pickup_details',
                            'Drop-off Details' => 'package_dropoff_details',
                            'Confirmation Number' => 'package_confirmation_no',
                            'Inclusions' => 'package_inclusions',
                            'Exclusions' => 'package_exclusions',
                            'Package Details' => 'package_details',
                            'Terms and Conditions' => 'package_terms'
                        ];
                        foreach ($package_extra_fields as $label => $field): ?>
                            <div class="info-card-item"><h4><?php echo htmlspecialchars($label); ?></h4><p><?php echo htmlspecialchars((string) ($booking[$field] ?? '-')); ?></p></div>
                        <?php endforeach; ?>
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
                            <p><?php echo htmlspecialchars($booking["service_type"]); ?></p>
                        </div>
                    </div>
                    <?php if (!empty($booking["package_itinerary"])): ?>
                        <div style="margin-top: 16px; padding: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-dark); border-radius: 8px;">
                            <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px; font-weight: 600;">Package Itinerary / Notes</h4>
                            <p style="font-size: 13px; margin: 0; color: var(--text-main); white-space: pre-wrap;"><?php echo htmlspecialchars($booking["package_itinerary"]); ?></p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <h2>Other service details</h2>
                    <hr style="margin: 16px 0;">
                    <div class="info-grid">
                        <div class="info-card-item"><h4>Service Name / Type</h4><p><?php echo htmlspecialchars($booking['other_service_name'] ?: $booking['service_type'] ?: '-'); ?></p></div>
                        <div class="info-card-item"><h4>Reference Number</h4><p><?php echo htmlspecialchars($booking['other_reference_no'] ?: '-'); ?></p></div>
                        <div class="info-card-item"><h4>Service Date</h4><p><?php echo htmlspecialchars($booking['other_service_date'] ?: '-'); ?></p></div>
                        <div class="info-card-item"><h4>End Date</h4><p><?php echo htmlspecialchars($booking['other_end_date'] ?: '-'); ?></p></div>
                        <div class="info-card-item"><h4>Country / City</h4><p><?php echo htmlspecialchars(trim(($booking['other_country'] ?: '') . ' ' . ($booking['other_city'] ?: '')) ?: '-'); ?></p></div>
                        <div class="info-card-item"><h4>From / To</h4><p><?php echo htmlspecialchars(trim(($booking['other_from'] ?: '') . ' / ' . ($booking['other_to'] ?: ''), ' /') ?: '-'); ?></p></div>
                    </div>
                    <?php if (!empty($booking['other_details'])): ?><p style="white-space: pre-wrap;"><?php echo htmlspecialchars($booking['other_details']); ?></p><?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($booking["remarks"])): ?>
                    <div style="margin-top: 24px; padding: 16px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-dark); border-radius: 8px;">
                        <h4 style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px; font-weight: 600;">Remarks / Special Notes</h4>
                        <p style="font-size: 14px; margin: 0; color: var(--text-main); white-space: pre-wrap;"><?php echo htmlspecialchars($booking["remarks"]); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Attachments Card -->
            <div class="card booking-section booking-attachments-card">
                <h2>Documents and attachments</h2>
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
                                                &nbsp;•&nbsp; Uploaded: <?php $uploaded_at = $att['uploaded_at'] ?? $att['created_at'] ?? null; echo $uploaded_at ? date('M d, Y', strtotime($uploaded_at)) : 'Unknown'; ?>
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
                                        <option value="Insurance">Insurance</option>
                                        <option value="Tour Package">Tour Package</option>
                                        <option value="Other">Other Document</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="custom_name">Custom Label / File Name (Optional)</label>
                                <input type="text" id="custom_name" name="custom_name" placeholder="e.g. Passenger Visa PDF">
                            </div>
                            <button type="submit" name="upload_attachment" class="booking-upload-button">
                                Upload document
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Financial Card & Log Details -->
        <div class="booking-side-column">
            
            <!-- Financial Card -->
            <div class="card booking-finance-card">
                <h2 style="color: white; font-size: 18px; margin-bottom: 20px;">Payment summary</h2>
                
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
            <div class="card booking-log-card">
                <h2>Activity history</h2>
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
                        <button type="submit" name="delete" class="btn btn-danger booking-delete-button">
                            Delete booking
                        </button>
                    </form>
                </div>
            </div>
            
        </div>

    </div>

</main>

</body>
</html>
