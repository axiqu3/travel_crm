<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$max_serial_res = mysqli_query($db, "SELECT MAX(CAST(serial_no AS UNSIGNED)) AS max_serial FROM bookings WHERE serial_no REGEXP '^[0-9]+$'");
$max_serial_row = mysqli_fetch_assoc($max_serial_res);
$max_serial_val = intval($max_serial_row['max_serial'] ?? 0);

$max_id_res = mysqli_query($db, "SELECT MAX(id) AS max_id FROM bookings");
$max_id_row = mysqli_fetch_assoc($max_id_res);
$max_id_val = intval($max_id_row['max_id'] ?? 0);

$default_serial = max($max_serial_val, $max_id_val) + 1;

$text = "";
$message = "";
$message_type = "";
$ticket_path = "";
$document_type = "";

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

// Handle OCR extraction
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
                $message = ($ocr_error ?: "OCR did not find readable text.") . " Please fill or paste the ticket details below.";
                $message_type = "error";
            }
        } else {
            $message = "Failed to upload ticket image.";
            $message_type = "error";
        }
    } else {
        $message = "Failed to extract ticket. Please select a valid ticket file.";
        $message_type = "error";
    }
}

function ensure_booking_remarks_column($db) {
    $result = mysqli_query($db, "SHOW COLUMNS FROM bookings LIKE 'remarks'");
    if ($result && mysqli_num_rows($result) == 0) {
        mysqli_query($db, "ALTER TABLE bookings ADD COLUMN remarks TEXT NULL");
    }
}

// Function to safely extract regex values
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
        'Arrival Date' => [
            '/[a-zA-Z]{3}\s+\d{1,2}\s+([a-zA-Z]{3}\s+\d{1,2})/i',
            '/Arr(?:ival)?\s*(?:Date)?\s*[:\-]?\s*(' . $date . ')/i'
        ],
        'Arrival Time' => [
            '/[a-zA-Z ]+\s+(\d{2}:\d{2})\s*$/mi',
            '/\d{2}:\d{2}\s+(\d{2}:\d{2})/',
            '/Arr(?:ival)?(?:\s*Time)?\s*[:\-]?\s*(?:' . $date . ')?\s*(' . $time . ')/i'
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
    if (preg_match('/\b(pnr|flight|airline|boarding|e-ticket|departure|arrival)\b/i', $combined)) {
        return 'ticket';
    }

    return 'ticket';
}

function split_ocr_text_into_tickets($text) {
    if (empty($text)) return [];
    
    $text = str_replace("\r", "", $text);
    $lines = explode("\n", $text);
    $split_indices = [];
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        $is_ticket_line = false;
        if (stripos($line, 'ticket') !== false && preg_match('/\b\d{10,16}\b/', $line)) {
            $is_ticket_line = true;
        } elseif (preg_match('/^\s*\d{10,16}\s*$/', $line)) {
            $is_ticket_line = true;
        }
        
        if ($is_ticket_line) {
            for ($j = $i - 1; $j >= 0; $j--) {
                $prev_line = trim($lines[$j]);
                if (empty($prev_line)) continue;
                
                if (stripos($prev_line, 'ticket') !== false || preg_match('/\b\d{10,16}\b/', $prev_line)) {
                    break;
                }
                
                if (strlen($prev_line) >= 3 && strlen($prev_line) <= 45 && !preg_match('/[:=]/', $prev_line) && !preg_match('/\b(?:overview|details|summary|booking|pnr|airline|flight|date|from|to|class|seat|cabin|economy|baggage|pax|passenger)\b/i', $prev_line)) {
                    $split_indices[] = $j;
                    break;
                }
                break;
            }
        }
    }
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^(?:Name of Passenger|Passenger Name|Passenger:|pax name|\b(?:Mr|Mrs|Ms|Miss|Mstr)\b\.?\s+[A-Z])/i', $line)) {
            if (!in_array($i, $split_indices, true)) {
                $split_indices[] = $i;
            }
        }
    }
    
    sort($split_indices);
    $split_indices = array_unique($split_indices);
    
    $tickets = [];
    if (empty($split_indices) || (count($split_indices) === 1 && $split_indices[0] === 0)) {
        if (strpos($text, "\f") !== false) {
            $parts = explode("\f", $text);
        } else {
            $parts = preg_split('/(?=Name of Passenger|Passenger Name|Passenger:|pax name|eticket|electronic ticket|boarding pass|flight ticket|\b(?:Mr|Mrs|Ms|Miss|Mstr)\b\.?\s+[A-Z])/i', $text);
        }
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) > 40) {
                $tickets[] = $part;
            }
        }
    } else {
        $current_segment = [];
        for ($i = 0; $i < count($lines); $i++) {
            if (in_array($i, $split_indices, true) && !empty($current_segment)) {
                $tickets[] = implode("\n", $current_segment);
                $current_segment = [];
            }
            $current_segment[] = $lines[$i];
        }
        if (!empty($current_segment)) {
            $tickets[] = implode("\n", $current_segment);
        }
    }
    
    $cleaned_tickets = [];
    foreach ($tickets as $ticket) {
        $ticket = trim($ticket);
        if (strlen($ticket) > 40) {
            $cleaned_tickets[] = $ticket;
        }
    }
    
    return empty($cleaned_tickets) ? [$text] : $cleaned_tickets;
}

// Handle Saving Booking to DB
if (isset($_POST["save_booking"])) {
    ensure_booking_remarks_column($db);

    $created_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $ticket_path = mysqli_real_escape_string($db, $_POST["ticket_path"] ?? "");
    $document_type = mysqli_real_escape_string($db, $_POST["document_type"] ?? 'ticket');

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
    $customer_mobile = isset($_POST["customer_mobile"]) ? mysqli_real_escape_string($db, $_POST["customer_mobile"]) : "";
    $customer_email = isset($_POST["customer_email"]) ? mysqli_real_escape_string($db, $_POST["customer_email"]) : "";
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
    $service_type = mysqli_real_escape_string($db, $_POST["service_type"]);
    $supplier_name = mysqli_real_escape_string($db, $_POST["supplier_name"]);
    $buying_cost = floatval($_POST["buying_cost"]);
    $selling_cost = floatval($_POST["selling_cost"]);
    $profit = floatval($_POST["profit"]);
    $payment_method = mysqli_real_escape_string($db, $_POST["payment_method"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"]);
    $customer_type = mysqli_real_escape_string($db, $_POST["customer_type"] ?? "Walk-in Customer");

    $flight_class = "";
    $terminal = "";
    $seat_number = "";
    $fare_basis = "";
    $status = "Booked";
    $remarks = mysqli_real_escape_string($db, $_POST["remarks"] ?? "");

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
        '$from_city', '$to_city', " . ($departure_date ? "'$departure_date'" : "NULL") . ", '$departure_time',
        " . ($arrival_date ? "'$arrival_date'" : "NULL") . ", '$arrival_time', '$pnr', '$ticket_number',
        '$flight_number', '$airline_name', '$flight_class', '$terminal',
        '$seat_number', '$baggage', '$booking_ref', '$fare_basis',
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
        $log_query = "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', 'Created Booking #$booking_id (Passenger: $passenger_name)', 'Booking', NOW())";
        mysqli_query($db, $log_query);

        $enquiry_id = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;

        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'booking_id' => $booking_id, 'enquiry_id' => $enquiry_id]);
            exit;
        }

        if ($enquiry_id > 0) {
            header("Location: ../enquiry/chat.php?id=" . $enquiry_id . "&booking_added=1");
        } else {
            header("Location: list.php?success=1");
        }
        exit;
    } else {
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => mysqli_error($db)]);
            exit;
        }
        $message = "Database Error: " . mysqli_error($db);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Booking | Travel CRM</title>
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
.ticket-drop-zone {
    border: 2px dashed var(--border-dark);
    border-radius: 12px;
    padding: 28px 20px;
    text-align: center;
    cursor: pointer;
    background: #f8fafc;
    transition: border-color 0.2s, background 0.2s;
}
.ticket-drop-zone:hover,
.ticket-drop-zone.drag-over,
.ticket-drop-zone:focus {
    border-color: var(--accent-color);
    background: rgba(59, 130, 246, 0.06);
}
.ticket-drop-zone.has-file {
    border-style: solid;
    background: #fff;
}
.ticket-drop-icon {
    font-size: 32px;
    line-height: 1;
}
.booking-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 10px;
}
.booking-grid .form-group {
    margin-bottom: 0;
}
.booking-grid .span-2 {
    grid-column: span 2;
}
.booking-grid .span-3 {
    grid-column: span 3;
}
.booking-grid .span-4 {
    grid-column: span 4;
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
    <a href="list.php">Bookings</a>
    <a href="add.php" class="active">Add Booking</a>
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
        <h1>Add Booking</h1>
        <a href="list.php" class="btn btn-secondary">Back to Bookings</a>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">

        <!-- Top Card: Upload & OCR -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Upload Ticket & OCR Console</h2>
            <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 14px;">
                Upload your ticket image or PDF to extract details. Correct any extracted fields below, fill in manual inputs, and save.
                    <div class="form-row" style="align-items: flex-end; gap: 16px;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label for="ticket">Upload Ticket File</label>
                    <div id="ticket-drop-zone" class="ticket-drop-zone" tabindex="0">
                        <input type="file" id="ticket" name="ticket" accept="image/*,.pdf" hidden>
                        <div class="ticket-drop-icon">📄</div>
                        <p style="margin: 8px 0 4px; font-weight: 600;">Drag & drop ticket here</p>
                        <p style="margin: 0; font-size: 12px; color: var(--text-secondary);">
                            or click to browse • paste image with Ctrl+V
                        </p>
                        <div id="ticket-preview" style="margin-top: 12px; font-size: 13px;"></div>
                    </div>
                    <p id="ticket-upload-error" style="color: #ef4444; font-size: 12px; margin-top: 6px;"></p>
                </div>
                <div class="d-flex gap-2" style="margin-bottom: 0; align-self: flex-end;">
                    <button type="submit" name="extract_ticket" id="extract-btn" formnovalidate style="padding: 11px 20px; background: var(--accent-color); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Extract Document</button>
                    <button type="button" onclick="window.location.href='add.php'" style="padding: 11px 20px; background: rgba(255,255,255,0.08); color: white; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; font-weight: 600; cursor: pointer;">Clear</button>
                    <button type="button" id="manual-btn" onclick="showManualForm()" style="padding: 11px 20px; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; <?php echo !empty($text) ? 'display: none;' : ''; ?>">Enter Details Manually</button>
                </div>
            </div>

            <div class="form-group" id="doc-type-group" style="display:none; margin-top: 16px;">
                <label for="document_type">Document Type</label>
                <select id="document_type" name="document_type">
                    <option value="">-- Select document type --</option>
                    <option value="ticket">✈️ Flight Ticket</option>
                    <option value="visa">🛂 Visa</option>
                    <option value="holiday">🏖️ Holiday Package</option>
                    <option value="voucher">🎫 Voucher</option>
                    <option value="passport">📕 Passport</option>
                </select>
                <p id="detected-type-msg" style="font-size: 12px; color: var(--status-booked-text); margin-top: 6px;"></p>
            </div>

            <?php if (!empty($ticket_path)): ?>
                <div style="margin-top: 16px; font-size: 13px; color: var(--status-booked-text); font-weight: 600;">
                    📄 Uploaded Ticket Path: <span style="font-family: monospace;"><?php echo htmlspecialchars($ticket_path); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($text)): ?>
                <div style="margin-top: 24px; padding: 16px; background: #f8fafc; border: 1px solid var(--border-dark); border-radius: 12px;">
                    <h3 style="font-size: 14px; margin-bottom: 8px; color: var(--text-main);">Extracted Raw Text:</h3>
                    <pre style="white-space: pre-wrap; font-family: monospace; font-size: 12px; max-height: 250px; overflow-y: auto; color: var(--text-secondary);"><?php echo htmlspecialchars($text); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <div id="booking-details-form" style="<?php echo empty($text) ? 'display: none;' : ''; ?>">
        <?php
        $ticket_sections = split_ocr_text_into_tickets($text);
        $ticket_count = count($ticket_sections);
        
        if ($ticket_count > 1):
            $global_pnr = val($text, 'PNR');
            $global_flight = val($text, 'Flight Number');
            $global_airline = val($text, 'Airline');
            $global_from = val($text, 'From');
            $global_to = val($text, 'To');
            $global_dep_date = val($text, 'Departure Date');
            $global_dep_time = val($text, 'Departure Time');
            $global_arr_date = val($text, 'Arrival Date');
            $global_arr_time = val($text, 'Arrival Time');
        ?>
            <!-- Render Multi-Ticket Sections -->
            <?php for ($i = 0; $i < $ticket_count; $i++): 
                $part = $ticket_sections[$i];
                $p_serial = $default_serial + $i;
                
                $p_passenger = val($part, 'Passenger');
                if (empty($p_passenger)) {
                    if (preg_match('/\b(?:Mr|Mrs|Ms|Miss|Mstr)\b\.?\s+([a-zA-Z][a-zA-Z \t.\-]{3,})/i', $part, $m)) {
                        $p_passenger = clean_ocr_value($m[1]);
                    }
                }
                if (empty($p_passenger)) {
                    $first_line = trim(explode("\n", $part)[0]);
                    if (strlen($first_line) >= 3 && strlen($first_line) <= 45 && !preg_match('/[:=0-9]/', $first_line) && !preg_match('/\b(?:overview|details|summary|booking|pnr|airline|flight|date|from|to|class|seat|cabin|economy|baggage|pax|passenger)\b/i', $first_line)) {
                        $p_passenger = clean_ocr_value($first_line);
                    }
                }
                $p_pnr = val($part, 'PNR') ?: $global_pnr;
                $p_ticket = val($part, 'Ticket');
                if (empty($p_ticket)) {
                    if (preg_match('/\b([0-9]{10,16})\b/', $part, $m)) {
                        $p_ticket = $m[1];
                    }
                }
                $p_flight = val($part, 'Flight Number') ?: $global_flight;
                $p_airline = val($part, 'Airline') ?: $global_airline;
                $p_from = val($part, 'From') ?: $global_from;
                $p_to = val($part, 'To') ?: $global_to;
                $p_dep_date = val($part, 'Departure Date') ?: $global_dep_date;
                $p_dep_time = val($part, 'Departure Time') ?: $global_dep_time;
                $p_arr_date = val($part, 'Arrival Date') ?: $global_arr_date;
                $p_arr_time = val($part, 'Arrival Time') ?: $global_arr_time;
            ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">
                <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($document_type); ?>">
                <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($_GET['enquiry_id'] ?? $_POST['enquiry_id'] ?? ''); ?>">
                <input type="hidden" name="customer_master_id" value="">

                <div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--accent-color);">
                    <h3 style="font-size: 14px; margin: 0 0 16px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 6px;">Ticket #<?php echo ($i + 1); ?>: <?php echo htmlspecialchars($p_passenger ?: 'Passenger Details'); ?></h3>
                    
                    <div class="booking-grid">
                        <div class="form-group field-group-always">
                            <label>Serial No</label>
                            <input type="text" name="serial_no" value="<?php echo htmlspecialchars($p_serial); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label>Booking Date</label>
                            <input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label>Customer / Agency</label>
                            <input type="text" name="customer_name" value="<?php echo htmlspecialchars($_GET['customer_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Customer Type</label>
                            <select name="customer_type" required>
                                <option value="">Select Customer Type</option>
                                <option value="Walk-in Customer" <?php echo (($_GET['customer_type'] ?? '') === 'Walk-in Customer') ? 'selected' : ''; ?>>Walk-in Customer</option>
                                <option value="B2B" <?php echo (($_GET['customer_type'] ?? '') === 'B2B') ? 'selected' : ''; ?>>B2B</option>
                                <option value="Corporate" <?php echo (($_GET['customer_type'] ?? '') === 'Corporate') ? 'selected' : ''; ?>>Corporate</option>
                                <option value="User" <?php echo (($_GET['customer_type'] ?? '') === 'User') ? 'selected' : ''; ?>>User</option>
                                <option value="Other" <?php echo (($_GET['customer_type'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Customer Phone / Mobile</label>
                            <input type="text" name="customer_mobile" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label>Customer Email</label>
                            <input type="email" name="customer_email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                        </div>

                        <div class="form-group field-group span-2">
                            <label>Passenger Name</label>
                            <input type="text" name="passenger_name" value="<?php echo htmlspecialchars($p_passenger); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>PNR Code</label>
                            <input type="text" name="pnr" value="<?php echo htmlspecialchars($p_pnr); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>Ticket Number</label>
                            <input type="text" name="ticket_number" value="<?php echo htmlspecialchars($p_ticket); ?>">
                        </div>

                        <div class="form-group field-group">
                            <label>Flight Number</label>
                            <input type="text" name="flight_number" value="<?php echo htmlspecialchars($p_flight); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>Airline Name</label>
                            <input type="text" name="airline_name" value="<?php echo htmlspecialchars($p_airline); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>From (Origin)</label>
                            <input type="text" name="from_city" value="<?php echo htmlspecialchars($p_from); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>To (Destination)</label>
                            <input type="text" name="to_city" value="<?php echo htmlspecialchars($p_to); ?>">
                        </div>

                        <div class="form-group field-group">
                            <label>Departure Date</label>
                            <input type="date" name="departure_date" 
                                   value="<?php 
                                        if ($p_dep_date) {
                                            $parsed_time = strtotime($p_dep_date);
                                            if ($parsed_time) echo date('Y-m-d', $parsed_time);
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>Departure Time</label>
                            <input type="text" name="departure_time" value="<?php echo htmlspecialchars($p_dep_time); ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>Arrival Date</label>
                            <input type="date" name="arrival_date" 
                                   value="<?php 
                                        if ($p_arr_date) {
                                            $parsed_time = strtotime($p_arr_date);
                                            if ($parsed_time) echo date('Y-m-d', $parsed_time);
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group">
                            <label>Arrival Time</label>
                            <input type="text" name="arrival_time" value="<?php echo htmlspecialchars($p_arr_time); ?>">
                        </div>

                        <div class="form-group field-group-always">
                            <label>Supplier</label>
                            <input type="text" name="supplier_name" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Buying Cost (₹)</label>
                            <input type="text" id="buying_cost_<?php echo $i; ?>" name="buying_cost" placeholder="0.00" oninput="calculateTicketProfit(<?php echo $i; ?>)" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Selling Cost (₹)</label>
                            <input type="text" id="selling_cost_<?php echo $i; ?>" name="selling_cost" placeholder="0.00" oninput="calculateTicketProfit(<?php echo $i; ?>)" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Profit Margin (₹)</label>
                            <input type="text" id="profit_<?php echo $i; ?>" name="profit" placeholder="0.00" readonly style="background: #f8fafc; font-weight: 700; color: var(--status-booked-text);">
                        </div>

                        <div class="form-group field-group-always">
                            <label>Payment Mode</label>
                            <select name="payment_method">
                                <option value="Cash">Cash</option>
                                <option value="Credit">Credit</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="form-group field-group-always">
                            <label>Assigned Agent</label>
                            <input type="text" name="assigned_user" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group field-group-always span-2">
                            <label>Remarks</label>
                            <textarea name="remarks" rows="2" placeholder="Remarks" style="min-height: 34px;"></textarea>
                        </div>
                        
                        <input type="hidden" name="service_type" value="<?php echo ($document_type === 'visa') ? 'Visa' : (($document_type === 'holiday') ? 'Holiday Package' : (($document_type === 'voucher') ? 'Voucher' : (($document_type === 'passport') ? 'Passport' : 'Flight'))); ?>">

                        <button type="submit" name="save_booking" class="span-4" style="margin-top: 10px; background: #10b981; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;">Confirm & Save Ticket #<?php echo ($i + 1); ?></button>
                    </div>
                </div>
            </form>
            <?php endfor; ?>

        <?php else: ?>
            <!-- Fallback single ticket layout -->
            <form method="POST" autocomplete="off">
                <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">
                <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($document_type); ?>">
                <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($_GET['enquiry_id'] ?? $_POST['enquiry_id'] ?? ''); ?>">
                <input type="hidden" name="customer_master_id" value="">

                <div class="card">
                    <h2>Booking Details</h2>
                    
                    <div class="booking-grid">
                        <div class="form-group field-group-always">
                            <label for="serial_no">Serial No</label>
                            <input type="text" id="serial_no" name="serial_no" placeholder="e.g. 5022" value="<?php echo htmlspecialchars($default_serial); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label for="booking_date">Booking Date</label>
                            <input type="date" id="booking_date" name="booking_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label for="customer_name">Customer / Agency</label>
                            <input type="text" id="customer_name" name="customer_name" placeholder="Customer Name" value="<?php echo htmlspecialchars($_GET['customer_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="customer_type">Customer Type</label>
                            <select id="customer_type" name="customer_type" required>
                                <option value="">Select Customer Type</option>
                                <option value="Walk-in Customer" <?php echo (($_GET['customer_type'] ?? '') === 'Walk-in Customer') ? 'selected' : ''; ?>>Walk-in Customer</option>
                                <option value="B2B" <?php echo (($_GET['customer_type'] ?? '') === 'B2B') ? 'selected' : ''; ?>>B2B</option>
                                <option value="Corporate" <?php echo (($_GET['customer_type'] ?? '') === 'Corporate') ? 'selected' : ''; ?>>Corporate</option>
                                <option value="User" <?php echo (($_GET['customer_type'] ?? '') === 'User') ? 'selected' : ''; ?>>User</option>
                                <option value="Other" <?php echo (($_GET['customer_type'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="customer_mobile">Customer Phone / Mobile</label>
                            <input type="text" id="customer_mobile" name="customer_mobile" placeholder="Phone Number" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label for="customer_email">Customer Email</label>
                            <input type="email" id="customer_email" name="customer_email" placeholder="Email Address" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                        </div>

                        <h3 id="auto-fields-heading" class="span-4" style="font-size: 13px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 6px;">Auto-Filled Document Fields</h3>

                        <div class="form-group field-group span-2" data-types="ticket,visa,holiday,voucher,passport">
                            <label for="passenger_name">Passenger Name</label>
                            <input type="text" id="passenger_name" name="passenger_name" placeholder="Passenger Name(s)" 
                                   value="<?php 
                                        $p_passenger = val($text, 'Passenger');
                                        if (empty($p_passenger) && !empty($text)) {
                                            $first_line = trim(explode("\n", $text)[0]);
                                            if (strlen($first_line) >= 3 && strlen($first_line) <= 45 && !preg_match('/[:=0-9]/', $first_line) && !preg_match('/\b(?:overview|details|summary|booking|pnr|airline|flight|date|from|to|class|seat|cabin|economy|baggage|pax|passenger)\b/i', $first_line)) {
                                                $p_passenger = clean_ocr_value($first_line);
                                            }
                                        }
                                        echo htmlspecialchars($p_passenger); 
                                   ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="pnr">PNR Code</label>
                            <input type="text" id="pnr" name="pnr" placeholder="PNR" 
                                   value="<?php echo htmlspecialchars(val($text, 'PNR')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="ticket_number">Ticket Number</label>
                            <input type="text" id="ticket_number" name="ticket_number" placeholder="Ticket Number(s)" 
                                   value="<?php echo htmlspecialchars(val($text, 'Ticket')); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket">
                            <label for="flight_number">Flight Number</label>
                            <input type="text" id="flight_number" name="flight_number" placeholder="Flight Number" 
                                   value="<?php echo htmlspecialchars(val($text, 'Flight Number')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="airline_name">Airline Name</label>
                            <input type="text" id="airline_name" name="airline_name" placeholder="Airline Name" 
                                   value="<?php echo htmlspecialchars(val($text, 'Airline')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="from_city">From (Origin)</label>
                            <input type="text" id="from_city" name="from_city" placeholder="Origin City" 
                                   value="<?php echo htmlspecialchars(val($text, 'From')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday,voucher">
                            <label for="to_city">To (Destination)</label>
                            <input type="text" id="to_city" name="to_city" placeholder="Destination City" 
                                   value="<?php echo htmlspecialchars(val($text, 'To')); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket,holiday,voucher">
                            <label for="departure_date">Departure Date</label>
                            <input type="date" id="departure_date" name="departure_date" 
                                   value="<?php 
                                        $dep_date_raw = val($text, 'Departure Date');
                                        if ($dep_date_raw) {
                                            $parsed_time = strtotime($dep_date_raw);
                                            if ($parsed_time) {
                                                echo date('Y-m-d', $parsed_time);
                                            }
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday">
                            <label for="departure_time">Departure Time</label>
                            <input type="text" id="departure_time" name="departure_time" placeholder="Departure Time" 
                                   value="<?php echo htmlspecialchars(val($text, 'Departure Time')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday">
                            <label for="arrival_date">Arrival Date</label>
                            <input type="date" id="arrival_date" name="arrival_date" 
                                   value="<?php 
                                        $arr_date_raw = val($text, 'Arrival Date');
                                        if ($arr_date_raw) {
                                            $parsed_time = strtotime($arr_date_raw);
                                            if ($parsed_time) {
                                                echo date('Y-m-d', $parsed_time);
                                            }
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="arrival_time">Arrival Time</label>
                            <input type="text" id="arrival_time" name="arrival_time" placeholder="Arrival Time" 
                                   value="<?php echo htmlspecialchars(val($text, 'Arrival Time')); ?>">
                        </div>

                        <h3 class="span-4" style="font-size: 13px; margin: 12px 0 4px 0; color: var(--accent-color); text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--border-dark); padding-bottom: 6px;">Manually Entered Fields</h3>

                        <div class="form-group field-group-always">
                            <label for="supplier_name">Supplier</label>
                            <input type="text" id="supplier_name" name="supplier_name" placeholder="Supplier Name" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="buying_cost">Buying Cost (₹)</label>
                            <input type="text" id="buying_cost" name="buying_cost" placeholder="0.00" oninput="calculateProfit()" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="selling_cost">Selling Cost (₹)</label>
                            <input type="text" id="selling_cost" name="selling_cost" placeholder="0.00" oninput="calculateProfit()" required>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="profit">Profit Margin (₹)</label>
                            <input type="text" id="profit" name="profit" placeholder="0.00" readonly style="background: #f8fafc; font-weight: 700; color: var(--status-booked-text);">
                        </div>

                        <div class="form-group field-group-always">
                            <label for="payment_method">Payment Mode</label>
                            <select id="payment_method" name="payment_method">
                                <option value="Cash">Cash</option>
                                <option value="Credit">Credit</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="form-group field-group-always">
                            <label for="assigned_user">Assigned Agent</label>
                            <input type="text" id="assigned_user" name="assigned_user" placeholder="Agent Name" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group field-group span-2" data-types="visa,holiday,voucher,passport">
                            <label for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="2" placeholder="Remarks" style="min-height: 34px;"></textarea>
                        </div>
                        
                        <input type="hidden" id="service_type" name="service_type" value="Flight">

                        <button type="submit" name="save_booking" class="span-4" style="margin-top: 10px; background: #10b981; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;">Confirm & Save Booking</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
        </div>
    </form>
</div>

<script>
(function () {
    const zone = document.getElementById('ticket-drop-zone');
    const input = document.getElementById('ticket');
    const preview = document.getElementById('ticket-preview');
    const errorEl = document.getElementById('ticket-upload-error');
    if (!zone || !input) return;

    const allowedTypes = ['image/', 'application/pdf'];
    const maxSize = 10 * 1024 * 1024; // 10MB

    function showError(msg) {
        if (errorEl) errorEl.textContent = msg || '';
    }

    function isAllowed(file) {
        if (file.type === 'application/pdf') return true;
        return allowedTypes.some(t => file.type.startsWith(t));
    }

    function setFile(file) {
        if (!file) return;

        if (!isAllowed(file)) {
            showError('Only images and PDF files are allowed.');
            return;
        }
        if (file.size > maxSize) {
            showError('File is too large. Max size is 10MB.');
            return;
        }

        showError('');
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;

        zone.classList.add('has-file');
        preview.innerHTML =
            '<strong>' + file.name + '</strong> (' + (file.size / 1024).toFixed(1) + ' KB)';

        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.style.maxWidth = '120px';
            img.style.maxHeight = '80px';
            img.style.marginTop = '8px';
            img.style.borderRadius = '6px';
            preview.appendChild(img);
        }

        var docTypeGroup = document.getElementById('doc-type-group');
        if (docTypeGroup) docTypeGroup.style.display = 'block';

        const detected = detectTypeFromFilename(file.name);
        if (detected && typeof applyDocumentType === 'function') {
            applyDocumentType(detected);
        }
    }

    function detectTypeFromFilename(filename) {
        const name = filename.toLowerCase();
        if (name.includes('visa')) return 'visa';
        if (name.includes('holiday') || name.includes('package') || name.includes('tour')) return 'holiday';
        if (name.includes('voucher')) return 'voucher';
        if (name.includes('passport')) return 'passport';
        if (name.includes('ticket') || name.includes('flight') || name.includes('pnr')) return 'ticket';
        return null;
    }

    zone.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        if (input.files[0]) setFile(input.files[0]);
    });

    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        zone.classList.add('drag-over');
    });

    zone.addEventListener('dragleave', () => {
        zone.classList.remove('drag-over');
    });

    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });

    zone.addEventListener('paste', (e) => {
        const items = e.clipboardData ? e.clipboardData.items : [];
        for (const item of items) {
            if (item.kind === 'file') {
                const file = item.getAsFile();
                if (file) {
                    e.preventDefault();
                    setFile(file);
                    break;
                }
            }
        }
    });
})();
</script>

<script>
function showManualForm() {
    const formEl = document.getElementById('booking-details-form');
    if (formEl) {
        formEl.style.display = 'block';
    }
    const manualBtn = document.getElementById('manual-btn');
    if (manualBtn) {
        manualBtn.style.display = 'none';
    }
}

function calculateTicketProfit(i) {
    const buy = parseFloat(document.getElementById('buying_cost_' + i).value) || 0;
    const sell = parseFloat(document.getElementById('selling_cost_' + i).value) || 0;
    const profitInput = document.getElementById('profit_' + i);
    if (profitInput) {
        profitInput.value = (sell - buy).toFixed(2);
    }
}

const typeLabels = {
    ticket: 'Flight Ticket',
    visa: 'Visa',
    holiday: 'Holiday Package',
    voucher: 'Voucher',
    passport: 'Passport'
};

function applyDocumentType(type) {
    if (!type) return;

    const docTypeSelect = document.getElementById('document_type');
    const serviceTypeInput = document.getElementById('service_type');
    const detectedMsg = document.getElementById('detected-type-msg');
    const heading = document.getElementById('auto-fields-heading');

    if (docTypeSelect) docTypeSelect.value = type;
    if (serviceTypeInput) serviceTypeInput.value = typeLabels[type] || type;
    if (detectedMsg) detectedMsg.textContent = 'Showing fields for: ' + (typeLabels[type] || type);
    if (heading) heading.textContent = 'Auto-Filled ' + (typeLabels[type] || 'Document') + ' Fields';

    document.querySelectorAll('.field-group').forEach(function(row) {
        const allowed = (row.getAttribute('data-types') || '').split(',');
        row.style.display = allowed.includes(type) ? '' : 'none';
    });

    document.querySelectorAll('.field-group-always').forEach(function(row) {
        row.style.display = '';
    });

    const extractBtn = document.getElementById('extract-btn');
    if (extractBtn) {
        extractBtn.textContent = 'Extract ' + (typeLabels[type] || 'Document');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const docTypeSelect = document.getElementById('document_type');

    // Hide document-specific fields until type is chosen
    document.querySelectorAll('.field-group').forEach(function(row) {
        row.style.display = 'none';
    });

    if (docTypeSelect) {
        docTypeSelect.addEventListener('change', function() {
            applyDocumentType(this.value);
        });
    }

    var savedType = "<?php echo htmlspecialchars($document_type ?? ''); ?>";
    if (savedType) {
        var docTypeGroup = document.getElementById('doc-type-group');
        if (docTypeGroup) docTypeGroup.style.display = 'block';
        if (typeof applyDocumentType === 'function') {
            applyDocumentType(savedType);
        }
    }

    const detailsFormContainer = document.getElementById('booking-details-form');
    if (detailsFormContainer) {
        detailsFormContainer.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            
            e.preventDefault();
            
            const submitBtn = form.querySelector('button[name="save_booking"]');
            const originalBtnText = submitBtn ? submitBtn.textContent : 'Confirm & Save';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }
            
            const formData = new FormData(form);
            formData.append('ajax', '1');
            formData.append('save_booking', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const card = form.querySelector('.card') || form;
                    card.style.transition = 'all 0.5s ease';
                    card.style.borderColor = '#10b981';
                    card.style.background = '#ecfdf5';
                    
                    const alertDiv = document.createElement('div');
                    alertDiv.style.padding = '12px';
                    alertDiv.style.background = '#dcfce7';
                    alertDiv.style.color = '#166534';
                    alertDiv.style.borderRadius = '8px';
                    alertDiv.style.marginTop = '12px';
                    alertDiv.style.fontWeight = '600';
                    alertDiv.style.textAlign = 'center';
                    alertDiv.textContent = '✓ Booking Saved Successfully!';
                    form.appendChild(alertDiv);
                    
                    if (submitBtn) submitBtn.style.display = 'none';
                    
                    setTimeout(() => {
                        card.style.opacity = '0';
                        card.style.transform = 'translateY(-20px)';
                        setTimeout(() => {
                            form.remove();
                            const remainingForms = detailsFormContainer.querySelectorAll('form');
                            if (remainingForms.length === 0) {
                                const redirectUrl = window.location.pathname.includes('bookings_add.php') ? 'bookings.php?success=1' : 'list.php?success=1';
                                window.location.href = redirectUrl;
                            }
                        }, 500);
                    }, 1000);
                } else {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                    alert('Error saving booking: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
                console.error('Error:', error);
                alert('An error occurred while saving the booking.');
            });
        });
    }
});
</script>
</body>
</html>

