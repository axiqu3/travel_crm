<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$enquiry_id = isset($_GET['enquiry_id']) ? intval($_GET['enquiry_id']) : (isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0);

$max_serial_res = mysqli_query($db, "SELECT MAX(CAST(serial_no AS UNSIGNED)) AS max_serial FROM bookings WHERE serial_no REGEXP '^[0-9]+$'");
$max_serial_row = mysqli_fetch_assoc($max_serial_res);
$max_serial_val = intval($max_serial_row['max_serial'] ?? 0);

$max_id_res = mysqli_query($db, "SELECT MAX(id) AS max_id FROM bookings");
$max_id_row = mysqli_fetch_assoc($max_id_res);
$max_id_val = intval($max_id_row['max_id'] ?? 0);

$default_serial = max($max_serial_val, $max_id_val) + 1;

$message = "";
$message_type = "";
$text = "";
$ticket_path = "";
$document_type = "";

// OCR functions
function ocr_command_exists($command) {
    if (file_exists($command) && !is_dir($command)) {
        return true;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return false;
    }
    $check = stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ';
    $output = [];
    $code = 1;
    @exec($check . escapeshellarg($command), $output, $code);
    return $code === 0;
}

function find_ocr_command($commands) {
    foreach ($commands as $command) {
        if (ocr_command_exists($command)) {
            return $command;
        }
    }
    return null;
}

function run_ocr_command($command) {
    $output = [];
    $code = 0;
    @exec($command . " 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

function normalize_ticket_text($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function clean_ocr_value($value) {
    $value = preg_replace('/[ \t]+/', ' ', trim($value));
    $value = preg_replace('/\s*(?:\||,|;)\s*$/', '', $value);
    return trim($value);
}

function extract_ticket_text($target, $upload_real, &$error = "") {
    $target_real = realpath($target);
    if (!$target_real) {
        $error = "Uploaded file could not be read.";
        return "";
    }

    $extension = strtolower(pathinfo($target_real, PATHINFO_EXTENSION));
    $output_base = $upload_real . DIRECTORY_SEPARATOR . "ocr_" . time() . "_" . uniqid();
    $tesseract = find_ocr_command([
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe\\tesseract.exe',
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        'tesseract'
    ]);

    if ($extension === 'pdf') {
        $pdftotext = find_ocr_command([
            'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
            'pdftotext'
        ]);

        if ($pdftotext) {
            $text_file = $output_base . ".txt";
            [$code] = run_ocr_command('"' . $pdftotext . '" -layout "' . $target_real . '" "' . $text_file . '"');
            if ($code === 0 && file_exists($text_file)) {
                $text = file_get_contents($text_file);
                unlink($text_file);
                if (trim($text) !== "") {
                    return normalize_ticket_text($text);
                }
            }
        }
    }

    if (!$tesseract) {
        $error = "Tesseract OCR is not installed or not found. Upload saved, but OCR could not run.";
        return "";
    }

    [$code, $cmd_output] = run_ocr_command('"' . $tesseract . '" "' . $target_real . '" "' . $output_base . '" -l eng --psm 6');
    $text_file = $output_base . ".txt";

    if ($code === 0 && file_exists($text_file)) {
        $text = file_get_contents($text_file);
        unlink($text_file);
        return normalize_ticket_text($text);
    }

    $error = $extension === 'pdf'
        ? "PDF text/OCR failed. Try uploading the ticket page as an image, or install Poppler for PDF extraction."
        : "OCR processing failed. " . trim($cmd_output);
    return "";
}

function val($text, $key) {
    if (empty($text)) return "";
    
    // Check multiple passengers
    if ($key === 'Passenger') {
        if (preg_match_all('/([a-zA-Z][a-zA-Z \t.\-]{2,})\r?\nTicket\s*(?:number|No)/i', $text, $matches)) {
            return implode(", ", array_map('clean_ocr_value', $matches[1]));
        }
    }
    
    // Check multiple ticket numbers
    if ($key === 'Ticket') {
        if (preg_match_all('/Ticket\s*(?:number|No|Number|TKT)\s*[:\-]?\s*([0-9]{5,16})/i', $text, $matches)) {
            return implode(", ", array_map('clean_ocr_value', $matches[1]));
        }
    }

    // Check same-line origin & destination with left-quote separator
    if ($key === 'From') {
        if (preg_match('/^([a-zA-Z]+)[ \t]*[‘’\'´`•-][ \t]*([a-zA-Z ]+?)(?:\s+(?:Cabin|Seat|Economy|Class)|$)/imu', $text, $m)) {
            return clean_ocr_value($m[1]);
        }
    }
    if ($key === 'To') {
        if (preg_match('/^([a-zA-Z]+)[ \t]*[‘’\'´`•-][ \t]*([a-zA-Z ]+?)(?:\s+(?:Cabin|Seat|Economy|Class)|$)/imu', $text, $m)) {
            return clean_ocr_value($m[2]);
        }
    }
    
    $line = '[a-zA-Z0-9 \t\/\-.,]';
    $date = '[0-9]{1,2}[ \t]*[a-zA-Z]{3,9}[ \t]*[0-9]{2,4}|[0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4}|[0-9]{4}[\/\-][0-9]{1,2}[\/\-][0-9]{1,2}';
    $time = '[0-9]{1,2}:[0-9]{2}(?:\s*[aApPmM]*)?';
    
    $patterns = [
        'Passenger' => [
            '/\b(?:Mstr|Mr|Ms|Mrs|Miss|Mses)\.?\s+([a-zA-Z][a-zA-Z \t.\-]{3,})/i',
            '/(?:Passenger|Pax|Traveller|Traveler|Name)(?:\s*Name)?\s*[:\-]?\s*([a-zA-Z][a-zA-Z \t\/.\-]{2,})/i',
            '/^[ \t]*(?:Mstr|Mr|Ms|Mrs|Miss|Mses)\.?\s+([a-zA-Z \t\/.\-]+)/im'
        ],
        'PNR' => [
            '/(?:PNR|Booking\s*(?:Ref|Reference)|Airline\s*PNR)\s*[:\-#]?\s*([A-Z0-9]{5,8})/i'
        ],
        'Ticket' => [
            '/(?:Ticket|TKT|E-?Ticket)(?:\s*(?:Number|No|#))?\s*[:\-#]?\s*([0-9]{3}[\- ]?[0-9]{6,13}|[0-9]{10,16})/i',
            '/\b([0-9]{3}[\- ]?[0-9]{10})\b/'
        ],
        'From' => [
            '/(?:\d{1,2}\s*[a-zA-Z]{3}\s*\d{0,4})?\s+([a-zA-Z ]+)\s*(?:\([^)]+\))?\s+\d{2}:\d{2}\s+\d{2}:\d{2}\s+[a-zA-Z ]+\s+\d{2}:\d{2}/i',
            '/([a-zA-Z ]+)\s+\d+h\s+\d+m\s+(?:\+\s+)?(?:Non\s*Stop|Stop|Direct)\s+[a-zA-Z\s]+/i',
            '/(?:From|Origin|Departure)\s*[:\-]?\s*(' . $line . '+)/i',
            '/\b([a-zA-Z]{3,15})\s*(?:‘|’|\'|‘|’|\-|to|>|\/)\s*[a-zA-Z ]{3,15}\b/i',
            '/\b([A-Z]{3})\s*(?:-|to|>)\s*[A-Z]{3}\b/i'
        ],
        'To' => [
            '/(?:\d{1,2}\s*[a-zA-Z]{3}\s*\d{0,4})?\s+[a-zA-Z ]+\s*(?:\([^)]+\))?\s+\d{2}:\d{2}\s+\d{2}:\d{2}\s+([a-zA-Z ]+)\s+\d{2}:\d{2}/i',
            '/[a-zA-Z\s]+\s+\d+h\s+\d+m\s+(?:\+\s+)?(?:Non\s*Stop|Stop|Direct)\s+([a-zA-Z ]+)/i',
            '/(?:To|Destination|Arrival)\s*[:\-]?\s*(' . $line . '+)/i',
            '/\b[a-zA-Z]{3,15}\s*(?:‘|’|\'|‘|’|\-|to|>|\/)\s*([a-zA-Z ]{3,15})\b/i',
            '/\b[A-Z]{3}\s*(?:-|to|>)\s*([A-Z]{3})\b/i'
        ],
        'Departure Date' => [
            '/([0-9]{1,2}[ \t]*[a-zA-Z]{3,9}[ \t]*[0-9]{2,4})\s+[a-zA-Z ]+\s*(?:\([^)]+\))?\s+\d{2}:\d{2}/i',
            '/([a-zA-Z]{3}\s+\d{1,2})\s+[a-zA-Z]{3}\s+\d{1,2}/i',
            '/(?:Dep|Departure|Depart|Date)\s*(?:Date)?\s*[:\-]?\s*(' . $date . ')/i',
            '/\b(' . $date . ')\b/i'
        ],
        'Departure Time' => [
            '/[a-zA-Z ]+\s*(?:\([^)]+\))?\s+(\d{2}:\d{2})\s+\d{2}:\d{2}/i',
            '/(\d{2}:\d{2})\s+\d{2}:\d{2}/',
            '/(?:Dep|Departs|Departure|Depart)(?:\s*Time)?\s*[:\-]?\s*(?:' . $date . ')?\s*(' . $time . ')/i',
            '/\b(' . $time . ')\b/'
        ],
        'Flight Number' => [
            '/(?:Flight|Flt)(?:\s*(?:Number|No))?\s*[:\-#]?\s*([A-Z0-9]{2}\s*[0-9]{2,5}[A-Z]?)/i',
            '/\b(6E\s*[0-9]{3,5})\b/i',
            '/\b([A-Z]{2,3}\s*[0-9]{3,5})\b/i',
            '/\b((?=[A-Z0-9]*[A-Z])[A-Z0-9]{2}\s*[0-9]{2,5}[A-Z]?)\b/'
        ],
        'Airline' => [
            '/^(?!Flight|Flt|No|Booking|Ticket|Passenger)([a-zA-Z ]{3,40})\s+\b[A-Z]{2}\d{2,5}\b/im',
            '/\b(Air India Express|Air India|IndiGo|Emirates|Etihad Airways|Etihad|Qatar Airways|Vistara|SpiceJet|Akasa Air|Oman Air|Saudia|Flydubai)\b/i',
            '/Airline(?: Name)?\s*[:\-]?\s*([a-zA-Z][a-zA-Z \t.&-]+)/i'
        ],
    ];

    $pList = isset($patterns[$key]) ? $patterns[$key] : ["/$key" . "[: ]*(.*)/i"];
    foreach ($pList as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $val = clean_ocr_value($m[1]);
            if ($val !== "") {
                return $val;
            }
        }
    }
    
    if ($key === 'Airline') {
        $flight = val($text, 'Flight Number');
        if (!empty($flight)) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $flight), 0, 2));
            $mappings = [
                '6E' => 'IndiGo',
                'EY' => 'Etihad Airways',
                'EK' => 'Emirates',
                'AI' => 'Air India',
                'IX' => 'Air India Express',
                'SG' => 'SpiceJet',
                'QP' => 'Akasa Air',
                'WY' => 'Oman Air',
                'SV' => 'Saudia',
                'FZ' => 'Flydubai',
                'QR' => 'Qatar Airways',
                'UK' => 'Vistara'
            ];
            if (isset($mappings[$prefix])) {
                return $mappings[$prefix];
            }
        }
    }
    return "";
}

function detect_document_type($text, $filename = '') {
    $combined = strtolower($text . ' ' . $filename);
    if (preg_match('/\b(visa|immigration|embassy|consulate|entry permit)\b/i', $combined)) {
        return 'visa';
    }
    if (preg_match('/\b(holiday|package|tour|resort|all inclusive|travel package)\b/i', $combined)) {
        return 'holiday';
    }
    if (preg_match('/\b(voucher|e-voucher|hotel voucher|booking voucher)\b/i', $combined)) {
        return 'voucher';
    }
    if (preg_match('/\b(passport|nationality|date of birth|place of birth)\b/i', $combined)) {
        return 'passport';
    }
    return 'ticket';
}

// Helper function to resolve field values (POST vs OCR vs Default)
function get_field_value($field_name, $extracted_key, $text, $default = '') {
    if (isset($_POST[$field_name])) {
        return $_POST[$field_name];
    }
    if (!empty($text) && !empty($extracted_key)) {
        $extracted = val($text, $extracted_key);
        if ($extracted !== '') {
            return $extracted;
        }
    }
    return $default;
}

// Handle File OCR Extraction
if (isset($_POST["extract_ticket"])) {
    if (isset($_FILES["ticket"]) && $_FILES["ticket"]["error"] == UPLOAD_ERR_OK) {
        $upload = __DIR__ . "/../../uploads/tickets/";
        if (!file_exists($upload)) {
            mkdir($upload, 0777, true);
        }
        
        $name = time() . "_" . basename($_FILES["ticket"]["name"]);
        $target = $upload . $name;
        
        if (move_uploaded_file($_FILES["ticket"]["tmp_name"], $target)) {
            $upload_real = realpath($upload);
            $ocr_error = "";
            $text = extract_ticket_text($target, $upload_real, $ocr_error);
            $ticket_path = "uploads/tickets/" . $name;
            $document_type = detect_document_type($text, $_FILES["ticket"]["name"] ?? '');

            if (!empty($text)) {
                $message = "Ticket extracted successfully. Extracted fields are populated below.";
                $message_type = "success";
            } else {
                $message = ($ocr_error ?: "OCR did not find readable text.") . " Please fill details manually or paste text.";
                $message_type = "error";
            }
        } else {
            $message = "Failed to upload ticket image.";
            $message_type = "error";
        }
    }
}

// Handle Raw Text Paste Extraction
if (isset($_POST["extract_paste"])) {
    $text = $_POST["pasted_text"] ?? "";
    $document_type = detect_document_type($text);
    if (!empty($text)) {
        $message = "Pasted text parsed successfully. Extracted fields are populated below.";
        $message_type = "success";
    } else {
        $message = "Pasted text is empty.";
        $message_type = "error";
    }
}

// Handle Saving Booking to DB
if (isset($_POST["save_booking"])) {
    $created_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $ticket_path = mysqli_real_escape_string($db, $_POST["ticket_path"] ?? "");
    $document_type = mysqli_real_escape_string($db, $_POST["document_type"] ?? "ticket");

    $file_type_map = [
        'ticket'  => 'Ticket',
        'visa'    => 'Visa',
        'holiday' => 'Voucher',
        'voucher' => 'Voucher',
        'passport'=> 'Passport'
    ];
    $attachment_type = $file_type_map[$document_type] ?? 'Other';

    $serial_no = mysqli_real_escape_string($db, $_POST["serial_no"]);
    $booking_date = mysqli_real_escape_string($db, $_POST["booking_date"]);
    $passenger_name = mysqli_real_escape_string($db, $_POST["passenger_name"]);
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $customer_type = mysqli_real_escape_string($db, $_POST["customer_type"] ?? "Walk-in Customer");
    $customer_mobile = mysqli_real_escape_string($db, $_POST["customer_mobile"] ?? "");
    $customer_email = mysqli_real_escape_string($db, $_POST["customer_email"] ?? "");
    
    $from_city = mysqli_real_escape_string($db, $_POST["from_city"] ?? "");
    $to_city = mysqli_real_escape_string($db, $_POST["to_city"] ?? "");
    $departure_date = mysqli_real_escape_string($db, $_POST["departure_date"]);
    
    $pnr = mysqli_real_escape_string($db, $_POST["pnr"] ?? "");
    $ticket_number = mysqli_real_escape_string($db, $_POST["ticket_number"] ?? "");
    $flight_number = mysqli_real_escape_string($db, $_POST["flight_number"] ?? "");
    $airline_name = mysqli_real_escape_string($db, $_POST["airline_name"] ?? "");
    $service_type = mysqli_real_escape_string($db, $_POST["service_type"]);
    $supplier_name = mysqli_real_escape_string($db, $_POST["supplier_name"]);
    
    $buying_cost = floatval($_POST["buying_cost"] ?? 0);
    $selling_cost = floatval($_POST["selling_cost"] ?? 0);
    $profit = floatval($_POST["profit"] ?? 0);
    
    $payment_method = mysqli_real_escape_string($db, $_POST["payment_method"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"] ?? $_SESSION['user_name'] ?? '');
    $status = mysqli_real_escape_string($db, $_POST["status"] ?? "Booked");
    $remarks = mysqli_real_escape_string($db, $_POST["remarks"] ?? "");
    
    $customer_master_id = isset($_POST["customer_master_id"]) ? intval($_POST["customer_master_id"]) : 0;
    $enquiry_id = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;

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
                                VALUES ('$type', '$customer_name', '$customer_mobile', '$customer_email', '$created_by')";
            if (mysqli_query($db, $insert_cust_sql)) {
                $customer_master_id = mysqli_insert_id($db);
                
                // Log customer creation activity
                $log_user = $_SESSION['user_name'] ?? 'System';
                mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) 
                                   VALUES ('$log_user', 'Created Customer #$customer_master_id ($customer_name) via Booking Form', 'Customer Master', NOW())");
            }
        }
    }

    $query = "INSERT INTO bookings (
        serial_no, booking_date, passenger_name, customer_name, customer_type, 
        from_city, to_city, departure_date, departure_time,
        arrival_date, arrival_time, pnr, ticket_number,
        flight_number, airline_name, flight_class, terminal,
        seat_number, baggage, booking_ref, fare_basis,
        service_type, supplier_name, buying_cost, selling_cost, 
        profit, payment_method, assigned_user, status, remarks, ticket_path,
        created_by, customer_master_id
    ) VALUES (
        '$serial_no', " . ($booking_date ? "'$booking_date'" : "NULL") . ", '$passenger_name', '$customer_name', '$customer_type',
        '$from_city', '$to_city', " . ($departure_date ? "'$departure_date'" : "NULL") . ", '',
        NULL, '', '$pnr', '$ticket_number',
        '$flight_number', '$airline_name', '', '',
        '', '', '', '',
        '$service_type', '$supplier_name', $buying_cost, $selling_cost, 
        $profit, '$payment_method', '$assigned_user', '$status', '$remarks', " . ($ticket_path ? "'$ticket_path'" : "NULL") . ",
        '$created_by', " . ($customer_master_id > 0 ? $customer_master_id : "NULL") . "
    )";

    if (mysqli_query($db, $query)) {
        $booking_id = mysqli_insert_id($db);

        // Update customer details in customer_master if they exist
        if ($customer_master_id > 0 && ($customer_mobile !== '' || $customer_email !== '')) {
            $update_parts = [];
            if ($customer_mobile !== '') $update_parts[] = "mobile = '$customer_mobile'";
            if ($customer_email !== '') $update_parts[] = "email = '$customer_email'";
            mysqli_query($db, "UPDATE customer_master SET " . implode(", ", $update_parts) . " WHERE id = $customer_master_id");
        }

        if (!empty($ticket_path)) {
            $att_name = "Uploaded " . $attachment_type . " (" . basename($ticket_path) . ")";
            $db_ticket_path = mysqli_real_escape_string($db, $ticket_path);
            mysqli_query($db, "INSERT INTO booking_attachments (booking_id, file_path, file_name, file_type) VALUES ($booking_id, '$db_ticket_path', '$att_name', '$attachment_type')");
        }

        $user_for_log = $_SESSION['user_name'] ?? 'System';
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', 'Created Booking #$booking_id (Passenger: $passenger_name) from Chat', 'Booking', NOW())";
        mysqli_query($db, $log_query);

        if ($enquiry_id > 0) {
            mysqli_query($db, "UPDATE enquiries SET status = 'Booked' WHERE id = $enquiry_id");
            header("Location: chat.php?id=" . $enquiry_id . "&booking_added=1");
        } else {
            header("Location: ../bookings/list.php?success=1");
        }
        exit;
    } else {
        $message = "Database Error: " . mysqli_error($db);
        $message_type = "error";
    }
}

// Populate formatted departure date if parsed from GDS or OCR
$dep_val = get_field_value('departure_date', 'Departure Date', $text);
if (!empty($dep_val) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dep_val)) {
    $parsed_time = strtotime($dep_val);
    $dep_val = $parsed_time ? date('Y-m-d', $parsed_time) : '';
}

// Preselect service type
$detected_doc_type = detect_document_type($text, $_FILES["ticket"]["name"] ?? '');
$service_val = get_field_value('service_type', '', $text, '');
if ($service_val === '') {
    $service_val = ($detected_doc_type === 'visa') ? 'Visa' : (($detected_doc_type === 'holiday') ? 'Holiday Package' : (($detected_doc_type === 'voucher') ? 'Voucher' : (($detected_doc_type === 'passport') ? 'Passport' : 'Flight')));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Booking from Chat | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <script>
        function calculateProfit() {
            var buying = parseFloat(document.getElementById('buying_cost').value) || 0;
            var selling = parseFloat(document.getElementById('selling_cost').value) || 0;
            var profit = selling - buying;
            document.getElementById('profit').value = profit.toFixed(2);
        }

        function submitOcr() {
            document.getElementById('ocrForm').submit();
        }

        document.addEventListener("DOMContentLoaded", function() {
            const dropZone = document.getElementById("dropZone");
            const fileInput = document.getElementById("ticket_file");

            if (dropZone && fileInput) {
                dropZone.addEventListener("dragover", function(e) {
                    e.preventDefault();
                    dropZone.style.borderColor = "var(--accent-color)";
                    dropZone.style.background = "rgba(59, 130, 246, 0.05)";
                });

                dropZone.addEventListener("dragleave", function(e) {
                    e.preventDefault();
                    dropZone.style.borderColor = "var(--border-dark)";
                    dropZone.style.background = "#f8fafc";
                });

                dropZone.addEventListener("drop", function(e) {
                    e.preventDefault();
                    if (e.dataTransfer.files.length > 0) {
                        fileInput.files = e.dataTransfer.files;
                        submitOcr();
                    }
                });
            }
        });

        // Smart Autocomplete Customer Master integration
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
                const mobileInput = form ? (form.querySelector('input[name="customer_mobile"]') || form.querySelector('input#customer_mobile')) : null;
                const emailInput = form ? (form.querySelector('input[name="customer_email"]') || form.querySelector('input#customer_email')) : null;
                const idInput = form ? form.querySelector('input[name="customer_master_id"]') : null;
                
                input.setAttribute('autocomplete', 'off');

                // Disable input and set placeholder on load based on typeSelect state
                function handleTypeState() {
                    if (typeSelect) {
                        const selectedType = typeSelect.value;
                        if (!selectedType) {
                            input.disabled = true;
                            input.placeholder = "Select customer type first...";
                            input.value = "";
                            if (idInput) idInput.value = "";
                            if (mobileInput) mobileInput.value = "";
                            if (emailInput) emailInput.value = "";
                        } else {
                            input.disabled = false;
                            input.placeholder = "Type to search " + selectedType + "...";
                        }
                    }
                }

                if (typeSelect) {
                    typeSelect.addEventListener('change', handleTypeState);
                    handleTypeState(); // Initial check
                }

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
                    
                    const selectedType = typeSelect ? typeSelect.value : '';
                    if (!selectedType) {
                        dropdown.style.display = 'none';
                        return;
                    }
                    
                    // Fetch matching customers from backend search endpoint
                    fetch(`../master/search_customer.php?q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            // Filter matches strictly by selected customer type
                            const matches = data.filter(cust => {
                                let dbType = cust.customer_type;
                                if (dbType === 'Walk-in') dbType = 'Walk-in Customer'; // safety check
                                return dbType === selectedType;
                            });
                            
                            if (matches.length === 0) {
                                dropdown.style.display = 'none';
                                return;
                            }
                            
                            dropdown.innerHTML = '';
                            matches.slice(0, 8).forEach(cust => {
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
                                    
                                    // Autofill Mobile
                                    if (mobileInput) {
                                        mobileInput.value = cust.mobile || '';
                                    }
                                    
                                    // Autofill Email
                                    if (emailInput) {
                                        emailInput.value = cust.email || '';
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
        .booking-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        .span-2 {
            grid-column: span 2;
        }
        .span-4 {
            grid-column: span 4;
        }
        .ticket-drop-zone {
            border: 2px dashed var(--border-dark);
            border-radius: 12px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            background: #f8fafc;
            transition: border-color 0.2s, background 0.2s;
        }
        .ticket-drop-zone:hover {
            border-color: var(--accent-color);
            background: rgba(59, 130, 246, 0.05);
        }
        @media (max-width: 900px) {
            .booking-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .span-4 {
                grid-column: span 2;
            }
        }
        @media (max-width: 600px) {
            .booking-grid {
                grid-template-columns: 1fr;
            }
            .span-2, .span-4 {
                grid-column: span 1;
            }
        }

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
    <a href="../bookings/list.php">Bookings</a>
    <a href="../bookings/add.php">Add Booking</a>
    <a href="../bookings/reports.php">Reports</a>
    <a href="list.php" class="active">Enquiry</a>
    <a href="../tasks/index.php">Tasks</a>
    <a href="../admin/activity.php">Activity</a>
    <a href="../../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <div style="flex: 1;"></div>
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <div>
            <h1 style="margin: 0;">✈ Add Booking (from Chat)</h1>
            <p style="margin: 4px 0 0 0; color: var(--text-secondary); font-size: 12px;">Create a new booking and link/update customer details</p>
        </div>
        <div>
            <?php if ($enquiry_id > 0): ?>
                <a href="chat.php?id=<?php echo $enquiry_id; ?>" class="btn btn-secondary">Back to Chat</a>
            <?php else: ?>
                <a href="list.php" class="btn btn-secondary">Back to Enquiries</a>
            <?php endif; ?>
        </div>
    </div>

    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type === 'success' ? '#166534' : '#991b1b'; ?>; 
            border: 1px solid <?php echo $message_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Ticket Parsers Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 1000px; margin: 0 auto 24px auto;">
        
        <!-- Box 1: OCR Ticket Upload -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between; border: 1px solid var(--border-dark); border-radius: 12px;">
            <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 13px; color: var(--accent-color); text-transform: uppercase; font-weight: 700;">📂 Upload Ticket (OCR)</h3>
            <form method="POST" enctype="multipart/form-data" id="ocrForm">
                <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($enquiry_id); ?>">
                <div class="ticket-drop-zone" onclick="document.getElementById('ticket_file').click()" id="dropZone">
                    <div class="ticket-drop-icon" style="font-size: 28px;">📄</div>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: var(--text-secondary);">Drag & drop ticket image/PDF or click to browse</p>
                    <input type="file" id="ticket_file" name="ticket" style="display: none;" onchange="submitOcr()">
                    <input type="hidden" name="extract_ticket" value="1">
                </div>
            </form>
        </div>

        <!-- Box 2: Paste Ticket Text -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between; border: 1px solid var(--border-dark); border-radius: 12px;">
            <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 13px; color: var(--accent-color); text-transform: uppercase; font-weight: 700;">📝 Paste Ticket Text</h3>
            <form method="POST">
                <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($enquiry_id); ?>">
                <textarea name="pasted_text" placeholder="Paste ticket details or copied GDS/PNR text here..." style="width: 100%; height: 75px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-dark); border-radius: 8px; padding: 8px; color: var(--text-primary); font-family: inherit; font-size: 12px; resize: none; outline: none;"></textarea>
                <button type="submit" name="extract_paste" value="1" class="btn" style="width: 100%; margin-top: 8px; padding: 8px; font-size: 12px; border-radius: 6px; font-weight: 600; cursor: pointer;">Extract Fields</button>
            </form>
        </div>

    </div>

    <!-- Main Form Card -->
    <div class="card" style="max-width: 1000px; margin: 0 auto;">
        <form method="POST">
            <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($enquiry_id); ?>">
            <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">
            <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($document_type); ?>">
            <input type="hidden" name="customer_master_id" value="">

            <div class="booking-grid">
                <div class="form-group field-group-always">
                    <label for="serial_no">Serial No</label>
                    <input type="text" id="serial_no" name="serial_no" placeholder="e.g. 5022" value="<?php echo htmlspecialchars(get_field_value('serial_no', '', $text, $default_serial)); ?>" required>
                </div>
                <div class="form-group field-group-always">
                    <label for="booking_date">Booking Date</label>
                    <input type="date" id="booking_date" name="booking_date" value="<?php echo htmlspecialchars(get_field_value('booking_date', '', $text, date('Y-m-d'))); ?>" required>
                </div>
                <div class="form-group field-group-always">
                    <label for="customer_name">Customer Name</label>
                    <input type="text" id="customer_name" name="customer_name" placeholder="Customer Name" value="<?php echo htmlspecialchars(get_field_value('customer_name', '', $text, $_GET['customer_name'] ?? '')); ?>" required>
                </div>
                <div class="form-group field-group-always">
                    <label for="customer_type">Customer Type</label>
                    <select id="customer_type" name="customer_type" required>
                        <?php $cust_type_val = get_field_value('customer_type', '', $text, $_GET['customer_type'] ?? 'Walk-in Customer'); ?>
                        <option value="">Select Customer Type</option>
                        <option value="Walk-in Customer" <?php echo $cust_type_val === 'Walk-in Customer' ? 'selected' : ''; ?>>Walk-in Customer</option>
                        <option value="B2B" <?php echo $cust_type_val === 'B2B' ? 'selected' : ''; ?>>B2B</option>
                        <option value="Corporate" <?php echo $cust_type_val === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                        <option value="User" <?php echo $cust_type_val === 'User' ? 'selected' : ''; ?>>User</option>
                        <option value="Other" <?php echo $cust_type_val === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group field-group-always">
                    <label for="customer_mobile">Phone / Mobile</label>
                    <input type="text" id="customer_mobile" name="customer_mobile" placeholder="Phone Number" value="<?php echo htmlspecialchars(get_field_value('customer_mobile', '', $text, $_GET['mobile'] ?? '')); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="customer_email">Email Address</label>
                    <input type="email" id="customer_email" name="customer_email" placeholder="Email" value="<?php echo htmlspecialchars(get_field_value('customer_email', '', $text, $_GET['email'] ?? '')); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="service_type">Service Type</label>
                    <select id="service_type" name="service_type" required>
                        <option value="Flight" <?php echo $service_val === 'Flight' ? 'selected' : ''; ?>>Flight Ticket</option>
                        <option value="Visa" <?php echo $service_val === 'Visa' ? 'selected' : ''; ?>>Visa</option>
                        <option value="Holiday Package" <?php echo $service_val === 'Holiday Package' ? 'selected' : ''; ?>>Holiday Package</option>
                        <option value="Voucher" <?php echo $service_val === 'Voucher' ? 'selected' : ''; ?>>Hotel Voucher / Booking</option>
                        <option value="Passport" <?php echo $service_val === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                        <option value="Bus" <?php echo $service_val === 'Bus' ? 'selected' : ''; ?>>Bus Ticket</option>
                        <option value="Cab" <?php echo $service_val === 'Cab' ? 'selected' : ''; ?>>Cab Booking</option>
                        <option value="Insurance" <?php echo $service_val === 'Insurance' ? 'selected' : ''; ?>>Travel Insurance</option>
                    </select>
                </div>
                <div class="form-group field-group-always">
                    <label for="passenger_name">Passenger Name</label>
                    <input type="text" id="passenger_name" name="passenger_name" placeholder="Passenger Name" value="<?php echo htmlspecialchars(get_field_value('passenger_name', 'Passenger', $text)); ?>" required>
                </div>

                <div class="form-group field-group-always">
                    <label for="from_city">From (Origin)</label>
                    <input type="text" id="from_city" name="from_city" placeholder="Origin City" value="<?php echo htmlspecialchars(get_field_value('from_city', 'From', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="to_city">To (Destination)</label>
                    <input type="text" id="to_city" name="to_city" placeholder="Destination City" value="<?php echo htmlspecialchars(get_field_value('to_city', 'To', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="departure_date">Departure Date</label>
                    <input type="date" id="departure_date" name="departure_date" value="<?php echo htmlspecialchars($dep_val); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="pnr">PNR Code</label>
                    <input type="text" id="pnr" name="pnr" placeholder="PNR / Reference" value="<?php echo htmlspecialchars(get_field_value('pnr', 'PNR', $text)); ?>">
                </div>

                <div class="form-group field-group-always">
                    <label for="ticket_number">Ticket Number</label>
                    <input type="text" id="ticket_number" name="ticket_number" placeholder="Ticket Number" value="<?php echo htmlspecialchars(get_field_value('ticket_number', 'Ticket', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="flight_number">Flight/Vehicle Number</label>
                    <input type="text" id="flight_number" name="flight_number" placeholder="Flight No" value="<?php echo htmlspecialchars(get_field_value('flight_number', 'Flight Number', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="airline_name">Airline / Carrier</label>
                    <input type="text" id="airline_name" name="airline_name" placeholder="Airline Name" value="<?php echo htmlspecialchars(get_field_value('airline_name', 'Airline', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="supplier_name">Supplier Name</label>
                    <input type="text" id="supplier_name" name="supplier_name" placeholder="Supplier Name" value="<?php echo htmlspecialchars(get_field_value('supplier_name', '', $text)); ?>" required>
                </div>

                <div class="form-group field-group-always">
                    <label for="buying_cost">Buying Cost (₹)</label>
                    <input type="number" step="0.01" id="buying_cost" name="buying_cost" placeholder="0.00" oninput="calculateProfit()" value="<?php echo htmlspecialchars(get_field_value('buying_cost', '', $text)); ?>" required>
                </div>
                <div class="form-group field-group-always">
                    <label for="selling_cost">Selling Cost (₹)</label>
                    <input type="number" step="0.01" id="selling_cost" name="selling_cost" placeholder="0.00" oninput="calculateProfit()" value="<?php echo htmlspecialchars(get_field_value('selling_cost', '', $text)); ?>" required>
                </div>
                <div class="form-group field-group-always">
                    <label for="profit">Profit Margin (₹)</label>
                    <input type="number" step="0.01" id="profit" name="profit" placeholder="0.00" readonly style="background: #f8fafc; font-weight: 700; color: var(--status-booked-text);" value="<?php echo htmlspecialchars(get_field_value('profit', '', $text)); ?>">
                </div>
                <div class="form-group field-group-always">
                    <label for="payment_method">Payment Mode</label>
                    <select id="payment_method" name="payment_method" required>
                        <?php $pm_val = get_field_value('payment_method', '', $text, 'Cash'); ?>
                        <option value="Cash" <?php echo $pm_val === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Credit" <?php echo $pm_val === 'Credit' ? 'selected' : ''; ?>>Credit</option>
                        <option value="UPI" <?php echo $pm_val === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                        <option value="Bank Transfer" <?php echo $pm_val === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    </select>
                </div>

                <div class="form-group field-group-always">
                    <label for="status">Booking Status</label>
                    <select id="status" name="status" required>
                        <?php $status_val = get_field_value('status', '', $text, 'Booked'); ?>
                        <option value="Booked" <?php echo $status_val === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                        <option value="Issued" <?php echo $status_val === 'Issued' ? 'selected' : ''; ?>>Issued</option>
                        <option value="On Hold" <?php echo $status_val === 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                        <option value="Cancelled" <?php echo $status_val === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group field-group-always">
                    <label for="assigned_user">Assigned Agent</label>
                    <input type="text" id="assigned_user" name="assigned_user" value="<?php echo htmlspecialchars(get_field_value('assigned_user', '', $text, $_SESSION['user_name'] ?? '')); ?>">
                </div>
                <div class="form-group field-group-always span-2">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="2" placeholder="Booking Remarks" style="min-height: 38px;"><?php echo htmlspecialchars(get_field_value('remarks', '', $text)); ?></textarea>
                </div>

                <button type="submit" name="save_booking" class="span-4" style="margin-top: 15px; background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; transition: background 0.15s;">
                    Confirm & Save Booking
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>

