<?php
/**
 * Shared Bulk Import Handler for Travel CRM
 * Centralizes duplicate checks, validations, OCR parser, imports, and history view.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF Token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper to sanitize database inserts
function esc($val) {
    global $db;
    return mysqli_real_escape_string($db, trim((string)$val));
}

function validate_booking_import_record_local($row) {
    $document_type = strtolower(trim((string)($row['document_type'] ?? 'ticket')));
    $passenger = trim((string)($row['passenger_name'] ?? ''));
    $customer = trim((string)($row['customer_name'] ?? ''));
    $errors = [];

    $required = function($value, $label) use (&$errors) {
        if (trim((string)$value) === '') $errors[] = "Missing $label";
    };
    $valid_date = function($value, $label, $required_date = true) use (&$errors) {
        $value = trim((string)$value);
        if ($value === '') {
            if ($required_date) $errors[] = "Missing $label";
            return null;
        }
        $timestamp = strtotime($value);
        if (!$timestamp || $timestamp < strtotime('1900-01-01')) {
            $errors[] = "Invalid $label (use YYYY-MM-DD)";
            return null;
        }
        return $timestamp;
    };

    $required($passenger, 'Passenger Name');
    $required($customer, 'Customer / Agency Name');

    if ($document_type === 'passport') {
        $passport_number = trim((string)($row['passport_number'] ?? $row['ticket_number'] ?? ''));
        $required($passport_number, 'Passport Number');
        if ($passport_number !== '' && !preg_match('/^[A-Z0-9<]{5,20}$/i', $passport_number)) {
            $errors[] = 'Invalid Passport Number';
        }
        $required($row['passport_issuing_country'] ?? '', 'Issuing Country');
        $required($row['passport_full_name'] ?? $passenger, 'Passport Full Name');
        $required($row['passport_nationality'] ?? '', 'Nationality');
        $valid_date($row['passport_date_of_birth'] ?? '', 'Date of Birth');
        $issue = $valid_date($row['passport_date_of_issue'] ?? '', 'Date of Issue');
        $expiry = $valid_date($row['passport_date_of_expiry'] ?? '', 'Date of Expiry');
        if ($issue && $expiry && $expiry <= $issue) $errors[] = 'Passport Expiry Date must be after Issue Date';
        return $errors;
    }

    if ($document_type === 'visa') {
        $required($row['visa_passport_no'] ?? $row['ticket_number'] ?? '', 'Passport Number');
        $required($row['visa_type'] ?? '', 'Visa Type');
        $required($row['visa_country'] ?? '', 'Visa Country');
        $valid_date($row['visa_submission_date'] ?? $row['travel_date'] ?? '', 'Issue / Submission Date');
        $valid_date($row['visa_delivery_date'] ?? '', 'Valid Until', false);
        return $errors;
    }

    if ($document_type === 'hotel') {
        $required($row['hotel_confirmation_no'] ?? $row['pnr'] ?? '', 'Confirmation Number');
        $required($row['hotel_name'] ?? '', 'Hotel Name');
        $required($row['hotel_city'] ?? '', 'Hotel City');
        $start = $valid_date($row['hotel_check_in'] ?? $row['travel_date'] ?? '', 'Check-in Date');
        $end = $valid_date($row['hotel_check_out'] ?? '', 'Check-out Date');
        if ($start && $end && $end < $start) $errors[] = 'Check-out Date must not be before Check-in Date';
        return $errors;
    }

    if ($document_type === 'insurance') {
        $required($row['insurance_policy_no'] ?? $row['pnr'] ?? '', 'Policy Number');
        $required($row['insurance_provider'] ?? '', 'Insurance Provider');
        $start = $valid_date($row['insurance_start_date'] ?? $row['travel_date'] ?? '', 'Coverage Start Date');
        $end = $valid_date($row['insurance_end_date'] ?? '', 'Coverage End Date');
        if ($start && $end && $end < $start) $errors[] = 'Coverage End Date must not be before Start Date';
        return $errors;
    }

    if ($document_type === 'package') {
        $required($row['package_voucher_no'] ?? $row['pnr'] ?? '', 'Voucher Number');
        $required($row['package_name'] ?? '', 'Package Name');
        $required($row['package_destinations'] ?? '', 'Destination');
        $start = $valid_date($row['package_start_date'] ?? $row['travel_date'] ?? '', 'Travel Start Date');
        $end = $valid_date($row['package_end_date'] ?? '', 'Travel End Date');
        if ($start && $end && $end < $start) $errors[] = 'Travel End Date must not be before Start Date';
        return $errors;
    }

    // Flight/legacy spreadsheet validation remains strict.
    $pnr = trim((string)($row['pnr'] ?? ''));
    $ticket = trim((string)($row['ticket_number'] ?? ''));
    $required($pnr, 'PNR Code');
    if ($pnr !== '' && !preg_match('/^[a-zA-Z0-9]{5,8}$/', $pnr)) {
        $errors[] = 'Invalid PNR (must be 5-8 alphanumeric characters)';
    }
    $required($ticket, 'Ticket Number');
    if ($ticket !== '' && !preg_match('/^\d{10,16}$/', $ticket)) {
        $errors[] = 'Invalid Ticket Number (must be 10-16 digits)';
    }
    $valid_date($row['travel_date'] ?? '', 'Travel Date');
    $valid_date($row['arrival_date'] ?? '', 'Arrival Date', false);
    return $errors;
}

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF Token Validation
    $req_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($req_token) || !hash_equals($_SESSION['csrf_token'], $req_token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF verification failed.']);
        exit;
    }

    $action = $_GET['action'];

    if ($action === 'fetch_google_sheet') {
        $url = $_POST['url'] ?? '';
        if (empty($url)) {
            echo json_encode(['status' => 'error', 'message' => 'Google Sheet URL is required.']);
            exit;
        }

        // Convert Google Sheets edit link to CSV export link
        $export_url = $url;
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/i', $url, $matches)) {
            $spreadsheet_id = $matches[1];
            $export_url = "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}/export?format=csv";
        }

        // Fetch CSV contents
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
            ]
        ]);
        $csv_data = @file_get_contents($export_url, false, $ctx);
        if ($csv_data === false) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch Google Sheet data. Please make sure the sheet is public (Anyone with the link can view).']);
            exit;
        }

        echo json_encode(['status' => 'success', 'csv_data' => $csv_data]);
        exit;
    }

    if ($action === 'ocr_process') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'No file uploaded or file upload error.']);
            exit;
        }

        $file_name = $_FILES['file']['name'];
        $file_tmp = $_FILES['file']['tmp_name'];
        $file_size = $_FILES['file']['size'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Security check: Uploaded file verification
        $allowed_exts = ['png', 'jpg', 'jpeg', 'pdf', 'txt'];
        if (!in_array($ext, $allowed_exts, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Unsupported file type. Allowed: PNG, JPG, JPEG, PDF, TXT']);
            exit;
        }

        if ($file_size > 10 * 1024 * 1024) { // 10MB limit
            echo json_encode(['status' => 'error', 'message' => 'File size exceeds maximum upload limit of 10MB.']);
            exit;
        }

        $upload_dir = __DIR__ . "/../uploads/tickets/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $new_name = time() . "_" . uniqid() . "_" . preg_replace('/[^a-zA-Z0-9._-]/', '', $file_name);
        $target = $upload_dir . $new_name;

        if (move_uploaded_file($file_tmp, $target)) {
            $ocr_error = "";
            $text = extract_ticket_text_local($target, realpath($upload_dir), $ocr_error);

            if (!empty($text)) {
                $doc_type = detect_document_type($text, $file_name);
                $service_labels = [
                    'ticket' => 'Flight',
                    'hotel' => 'Voucher',
                    'visa' => 'Visa',
                    'passport' => 'Passport',
                    'insurance' => 'Insurance',
                    'package' => 'Holiday Package',
                    'other' => 'Other'
                ];
                $detected_service_type = $service_labels[$doc_type] ?? 'Flight';

                $global_pnr = val_local($text, 'PNR');
                $global_flight = val_local($text, 'Flight Number');
                $global_airline = val_local($text, 'Airline');
                $global_from = val_local($text, 'From');
                $global_to = val_local($text, 'To');
                $global_dep_date = val_local($text, 'Departure Date');
                $global_dep_time = val_local($text, 'Departure Time');
                $global_arr_date = val_local($text, 'Arrival Date');
                $global_arr_time = val_local($text, 'Arrival Time');

                // Only flight itineraries can contain multiple booking sections. Splitting a
                // passport/visa/etc. can separate its labels from their values and make the
                // document fall back to the Flight preview.
                $ticket_sections = $doc_type === 'ticket'
                    ? split_ocr_text_into_tickets_local($text)
                    : [$text];
                $tickets_extracted = [];

                foreach ($ticket_sections as $part) {
                    // A single-document upload must inherit the type detected from the full
                    // file. Previously this variable was only set for multi-ticket PDFs,
                    // producing a PHP warning and invalid JSON for ordinary passport PDFs.
                    $part_doc_type = $doc_type;
                    $p_passenger = val_local($part, 'Passenger');
                    if (empty($p_passenger)) {
                        if (preg_match('/\b(?:Mr|Mrs|Ms|Miss|Mstr)\b\.?\s+([a-zA-Z][a-zA-Z \t.\-]{3,})/i', $part, $m)) {
                            $p_passenger = clean_ocr_value_local($m[1]);
                        }
                    }
                    if (empty($p_passenger)) {
                        $first_line = trim(explode("\n", $part)[0]);
                        if (strlen($first_line) >= 3 && strlen($first_line) <= 45 && !preg_match('/[:=0-9]/', $first_line) && !preg_match('/\b(?:overview|details|summary|booking|pnr|airline|flight|date|from|to|class|seat|cabin|economy|baggage|pax|passenger)\b/i', $first_line)) {
                            $p_passenger = clean_ocr_value_local($first_line);
                        }
                    }

                    $p_pnr = val_local($part, 'PNR') ?: $global_pnr;
                    $p_ticket = val_local($part, 'Ticket');
                    if (empty($p_ticket)) {
                        if (preg_match('/\b([0-9]{10,16})\b/', $part, $m)) {
                            $p_ticket = $m[1];
                        }
                    }
                    $p_flight = val_local($part, 'Flight Number') ?: $global_flight;
                    $p_airline = val_local($part, 'Airline') ?: $global_airline;
                    $p_from = val_local($part, 'From') ?: $global_from;
                    $p_to = val_local($part, 'To') ?: $global_to;
                    $p_dep_date = val_local($part, 'Departure Date') ?: $global_dep_date;
                    $p_dep_time = val_local($part, 'Departure Time') ?: $global_dep_time;
                    $p_arr_date = val_local($part, 'Arrival Date') ?: $global_arr_date;
                    $p_arr_time = val_local($part, 'Arrival Time') ?: $global_arr_time;

                    // Detect service type of individual part if split
                    $part_service_type = $detected_service_type;
                    if (count($ticket_sections) > 1) {
                        $part_doc_type = detect_document_type($part);
                        $part_service_type = $service_labels[$part_doc_type] ?? $detected_service_type;
                    }

                    $formatted_date = "";
                    if (!empty($p_dep_date)) {
                        $ts = strtotime($p_dep_date);
                        if ($ts) {
                            $formatted_date = date('Y-m-d', $ts);
                        }
                    }

                    $formatted_arr_date = "";
                    if (!empty($p_arr_date)) {
                        $ts_arr = strtotime($p_arr_date);
                        if ($ts_arr) {
                            $formatted_arr_date = date('Y-m-d', $ts_arr);
                        }
                    }

                    $rec = [
                        'document_type' => $part_doc_type,
                        'service_type' => $part_service_type,
                        'passenger_name' => $p_passenger,
                        'customer_name' => '',
                        'customer_type' => 'Walk-in Customer',
                        'pnr' => $p_pnr,
                        'ticket_number' => $p_ticket,
                        'travel_date' => $formatted_date,
                        'from_city' => $p_from,
                        'to_city' => $p_to,
                        'departure_time' => $p_dep_time,
                        'arrival_date' => $formatted_arr_date,
                        'arrival_time' => $p_arr_time,
                        'flight_number' => $p_flight,
                        'airline_name' => $p_airline,
                        'ocr_confidence' => 85,
                        'file_path' => "uploads/tickets/" . $new_name
                    ];

                    if ($part_doc_type === 'passport') {
                        $p_ocr = extract_passport_document_fields_local($part);
                        $rec['passport_number'] = $p_ocr['number'];
                        $rec['passport_type'] = $p_ocr['type'];
                        $rec['passport_issuing_country'] = $p_ocr['issuing_country'];
                        $rec['passport_country_code'] = $p_ocr['country_code'];
                        $rec['passport_full_name'] = $p_ocr['full_name'];
                        $rec['passport_surname'] = $p_ocr['surname'];
                        $rec['passport_given_names'] = $p_ocr['given_names'];
                        $rec['passport_nationality'] = $p_ocr['nationality'];
                        $rec['passport_gender'] = $p_ocr['gender'];
                        $rec['passport_date_of_birth'] = $p_ocr['date_of_birth'];
                        $rec['passport_place_of_birth'] = $p_ocr['place_of_birth'];
                        $rec['passport_date_of_issue'] = $p_ocr['date_of_issue'];
                        $rec['passport_date_of_expiry'] = $p_ocr['date_of_expiry'];
                        $rec['passport_place_of_issue'] = $p_ocr['place_of_issue'];
                        $rec['passport_authority'] = $p_ocr['authority'];
                        $rec['passport_mrz_line1'] = $p_ocr['mrz_line1'];
                        $rec['passport_mrz_line2'] = $p_ocr['mrz_line2'];
                        
                        $rec['passenger_name'] = $p_ocr['full_name'];
                        $rec['customer_name'] = $p_ocr['full_name'];
                        $rec['ticket_number'] = $p_ocr['number'];
                        $rec['pnr'] = $p_ocr['number'];
                        $rec['travel_date'] = $p_ocr['date_of_issue'] ?: ($p_ocr['date_of_expiry'] ?: $p_ocr['date_of_birth']);
                    } elseif ($part_doc_type === 'visa') {
                        $v_type = document_value_local($part, ['/Visa\s*(?:Category|Type)\s*[:#-]?\s*([^\n]+)/i', '/(Tourism\s*-\s*(?:Single|Multi)[^\n]*)/i']);
                        $v_country = preg_match('/U\.?A\.?E\.?|United Arab Emirates/i', $part) ? 'United Arab Emirates' : '';
                        $v_entry_type = stripos($part, 'Multi') !== false ? 'Multi' : (stripos($part, 'Single') !== false ? 'Single' : '');
                        $v_duration_days = intval(document_value_local($part, ['/(\d+)\s*Days/i']));
                        $v_submission_date = document_date(document_value_local($part, ['/Issue\s*Date\s*[:#-]?\s*([0-9.\/-]+)/i', '/Date\s*&\s*Place\s*Of\s*Issue\s*:\s*([0-9.\/-]+)/i']));
                        $v_issue_place = document_value_local($part, ['/Issue\s*Place\s*[:#-]?\s*([^\n]+)/i', '/Date\s*&\s*Place\s*Of\s*Issue\s*:\s*[0-9.\/-]+\s+([A-Za-z ]+)/i']);
                        $v_delivery_date = document_date(document_value_local($part, ['/Valid\s*Until\s*[:#-]?\s*([0-9.\/-]+)/i']));
                        $v_uid_no = document_value_local($part, ['/U\.?I\.?D\.?\s*(?:No\.?)?\s*[:#-]?\s*([A-Z0-9-]+)/i']);
                        $v_full_name = document_value_local($part, ['/\n\s*((?:Mr|Mrs|Ms)\.?\s+[A-Z][A-Z ]+)\s*\n\s*Full\s*Name:/i', '/Full\s*Name\s*:\s*([^\n]+)/i']);
                        $v_nationality = document_value_local($part, ['/Nationality\s*[:#-]?\s*([^\n]+)/i']);
                        $v_place_of_birth = document_value_local($part, ['/Place\s*of\s*Birth\s*[:#-]?\s*([^\n]+)/i']);
                        $v_date_of_birth = document_date(document_value_local($part, ['/(?:Date\s*of\s*Birth|DOB)\s*[:#-]?\s*([0-9.\/-]+)/i']));
                        $v_passport_type = document_value_local($part, ['/Passport\s*(?:No\.?|Number)\s*:\s*([A-Za-z]+)\s*\//i', '/Passport\s*Type\s*[:#-]?\s*([^\n]+)/i']);
                        $v_passport_no = document_value_local($part, ['/Passport\s*(?:No\.?|Number)\s*:\s*[A-Za-z]+\s*\/\s*([A-Z0-9-]+)/i', '/Passport\s*(?:No\.?|Number)\s*[:#-]?\s*([A-Z0-9-]+)/i']);
                        $v_profession = document_value_local($part, ['/Profession\s*[:#-]?\s*([^\n]+)/i']);

                        $rec['visa_type'] = $v_type;
                        $rec['visa_country'] = $v_country;
                        $rec['visa_entry_type'] = $v_entry_type;
                        $rec['visa_duration_days'] = $v_duration_days;
                        $rec['visa_submission_date'] = $v_submission_date;
                        $rec['visa_issue_place'] = $v_issue_place;
                        $rec['visa_delivery_date'] = $v_delivery_date;
                        $rec['visa_uid_no'] = $v_uid_no;
                        $rec['visa_full_name'] = $v_full_name;
                        $rec['visa_nationality'] = $v_nationality;
                        $rec['visa_place_of_birth'] = $v_place_of_birth;
                        $rec['visa_date_of_birth'] = $v_date_of_birth;
                        $rec['visa_passport_type'] = $v_passport_type;
                        $rec['visa_passport_no'] = $v_passport_no;
                        $rec['visa_profession'] = $v_profession;
                        
                        $rec['passenger_name'] = $v_full_name;
                        $rec['customer_name'] = $v_full_name;
                        $rec['ticket_number'] = $v_passport_no;
                        $rec['pnr'] = $v_uid_no ?: $v_passport_no;
                        $rec['travel_date'] = $v_submission_date;
                    } elseif ($part_doc_type === 'hotel') {
                        $h_ocr = extract_hotel_document_fields_local($part);
                        $rec['hotel_name'] = $h_ocr['name'];
                        $rec['hotel_country'] = $h_ocr['country'];
                        $rec['hotel_city'] = $h_ocr['city'];
                        $rec['hotel_address'] = $h_ocr['address'];
                        $rec['hotel_check_in'] = $h_ocr['check_in'];
                        $rec['hotel_check_out'] = $h_ocr['check_out'];
                        $rec['hotel_room_type'] = $h_ocr['room_type'];
                        $rec['hotel_rooms_count'] = $h_ocr['rooms'];
                        $rec['hotel_nights_count'] = $h_ocr['nights'];
                        $rec['hotel_meal_plan'] = $h_ocr['meal_plan'];
                        $rec['hotel_adults_count'] = $h_ocr['adults'];
                        $rec['hotel_children_count'] = $h_ocr['children'];
                        $rec['hotel_guest_names'] = $h_ocr['guest_names'];
                        $rec['hotel_booking_details'] = $h_ocr['booking_details'];
                        $rec['hotel_confirmation_no'] = $h_ocr['confirmation_no'];
                        
                        $rec['passenger_name'] = $h_ocr['guest_names'];
                        $rec['customer_name'] = $h_ocr['guest_names'];
                        $rec['ticket_number'] = $h_ocr['confirmation_no'];
                        $rec['pnr'] = $h_ocr['confirmation_no'];
                        $rec['travel_date'] = $h_ocr['check_in'];
                    } elseif ($part_doc_type === 'insurance') {
                        $i_ocr = extract_insurance_document_fields_local($part);
                        $rec['insurance_policy_no'] = $i_ocr['policy_no'];
                        $rec['insurance_provider'] = $i_ocr['provider'];
                        $rec['insurance_insured_name'] = $i_ocr['insured_name'];
                        $rec['insurance_coverage_type'] = $i_ocr['coverage_type'];
                        $rec['insurance_passport_no'] = $i_ocr['passport_no'];
                        $rec['insurance_destination'] = $i_ocr['destination'];
                        $rec['insurance_issue_date'] = $i_ocr['issue_date'];
                        $rec['insurance_start_date'] = $i_ocr['start_date'];
                        $rec['insurance_end_date'] = $i_ocr['end_date'];
                        $rec['insurance_days'] = $i_ocr['days'];
                        $rec['insurance_sum_insured'] = $i_ocr['sum_insured'];
                        $rec['insurance_premium_amount'] = $i_ocr['premium'];
                        $rec['insurance_emergency_no'] = $i_ocr['emergency_no'];
                        $rec['insurance_certificate_no'] = $i_ocr['certificate_no'];
                        $rec['insurance_coverage_details'] = $i_ocr['coverage_details'];
                        $rec['insurance_remarks'] = $i_ocr['remarks'];
                        
                        $rec['passenger_name'] = $i_ocr['insured_name'];
                        $rec['customer_name'] = $i_ocr['insured_name'];
                        $rec['ticket_number'] = $i_ocr['policy_no'];
                        $rec['pnr'] = $i_ocr['policy_no'];
                        $rec['travel_date'] = $i_ocr['start_date'];
                    } elseif ($part_doc_type === 'package') {
                        $p_ocr = extract_package_document_fields_local($part);
                        $rec['package_voucher_no'] = $p_ocr['voucher_no'];
                        $rec['package_name'] = $p_ocr['name'];
                        $rec['package_destinations'] = $p_ocr['destination'];
                        $rec['package_country'] = $p_ocr['country'];
                        $rec['package_guest_names'] = $p_ocr['guest_names'];
                        $rec['package_start_date'] = $p_ocr['start_date'];
                        $rec['package_end_date'] = $p_ocr['end_date'];
                        $rec['package_days_count'] = $p_ocr['days'];
                        $rec['package_nights_count'] = $p_ocr['nights'];
                        
                        $rec['passenger_name'] = $p_ocr['guest_names'];
                        $rec['customer_name'] = $p_ocr['guest_names'];
                        $rec['ticket_number'] = $p_ocr['voucher_no'];
                        $rec['pnr'] = $p_ocr['voucher_no'];
                        $rec['travel_date'] = $p_ocr['start_date'];
                    }

                    $tickets_extracted[] = $rec;
                }

                echo json_encode([
                    'status' => 'success',
                    'file_path' => "uploads/tickets/" . $new_name,
                    'text' => $text,
                    'tickets' => $tickets_extracted
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'OCR did not extract any text: ' . $ocr_error,
                    'file_path' => "uploads/tickets/" . $new_name
                ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to store uploaded file.']);
        }
        exit;
    }

    if ($action === 'check_duplicates_and_validate') {
        $raw_data = file_get_contents('php://input');
        $payload = json_decode($raw_data, true);
        $records = $payload['records'] ?? [];

        $results = [];

        foreach ($records as $index => $row) {
            $passenger = trim($row['passenger_name'] ?? '');
            $customer = trim($row['customer_name'] ?? '');
            $pnr = trim($row['pnr'] ?? '');
            $ticket = trim($row['ticket_number'] ?? '');
            $date = trim($row['travel_date'] ?? '');
            $customer_type = trim($row['customer_type'] ?? 'Walk-in Customer');

            // 1. Validate against the uploaded document model. Passport numbers,
            // voucher numbers and policy numbers must not be judged as flight tickets.
            $errors = validate_booking_import_record_local($row);

            $is_valid = empty($errors);

            // 2. Duplicate Detection
            $is_duplicate = false;
            $dup_reason = "";
            $existing_id = null;
            $existing_details = "";

            if ($is_valid) {
                $db_passenger = esc($passenger);
                $db_pnr = esc($pnr);
                $db_ticket = esc($ticket);
                $db_date = date('Y-m-d', strtotime($date));

                // Check PNR duplicate
                $q = "SELECT id, passenger_name, customer_name, ticket_number, departure_date, pnr FROM bookings WHERE pnr = '$db_pnr' LIMIT 1";
                $res = mysqli_query($db, $q);
                if ($res && mysqli_num_rows($res) > 0) {
                    $is_duplicate = true;
                    $row_dup = mysqli_fetch_assoc($res);
                    $dup_reason = "PNR code duplicate";
                    $existing_id = $row_dup['id'];
                    $existing_details = "ID: {$row_dup['id']} | Pax: {$row_dup['passenger_name']} | Ticket: {$row_dup['ticket_number']} | Date: {$row_dup['departure_date']}";
                }

                // Check Ticket Number duplicate
                if (!$is_duplicate) {
                    $q = "SELECT id, passenger_name, customer_name, ticket_number, departure_date, pnr FROM bookings WHERE ticket_number = '$db_ticket' LIMIT 1";
                    $res = mysqli_query($db, $q);
                    if ($res && mysqli_num_rows($res) > 0) {
                        $is_duplicate = true;
                        $row_dup = mysqli_fetch_assoc($res);
                        $dup_reason = "Ticket Number duplicate";
                        $existing_id = $row_dup['id'];
                        $existing_details = "ID: {$row_dup['id']} | Pax: {$row_dup['passenger_name']} | PNR: {$row_dup['pnr']} | Date: {$row_dup['departure_date']}";
                    }
                }

                // Check Passenger + Travel Date duplicate
                if (!$is_duplicate) {
                    $q = "SELECT id, passenger_name, customer_name, ticket_number, departure_date, pnr FROM bookings WHERE passenger_name = '$db_passenger' AND departure_date = '$db_date' LIMIT 1";
                    $res = mysqli_query($db, $q);
                    if ($res && mysqli_num_rows($res) > 0) {
                        $is_duplicate = true;
                        $row_dup = mysqli_fetch_assoc($res);
                        $dup_reason = "Passenger & Travel Date duplicate";
                        $existing_id = $row_dup['id'];
                        $existing_details = "ID: {$row_dup['id']} | PNR: {$row_dup['pnr']} | Ticket: {$row_dup['ticket_number']} | Date: {$row_dup['departure_date']}";
                    }
                }
            }

            $results[] = [
                'index' => $index,
                'valid' => $is_valid,
                'errors' => $errors,
                'is_duplicate' => $is_duplicate,
                'duplicate_reason' => $dup_reason,
                'existing_id' => $existing_id,
                'existing_details' => $existing_details
            ];
        }

        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }

    if ($action === 'import_records') {
        $raw_data = file_get_contents('php://input');
        $payload = json_decode($raw_data, true);
        $records = $payload['records'] ?? [];
        $file_name = esc($payload['file_name'] ?? 'Manual/Direct');
        $file_type = esc($payload['file_type'] ?? 'JSON');

        $start_time = microtime(true);

        $total = count($records);
        $imported = 0;
        $failed = 0;
        $duplicates = 0;

        $detailed_log = [];

        // Begin Transaction
        mysqli_begin_transaction($db);

        try {
            // Get Serial Numbers
            $max_serial_res = mysqli_query($db, "SELECT MAX(CAST(serial_no AS UNSIGNED)) AS max_serial FROM bookings WHERE serial_no REGEXP '^[0-9]+$'");
            $max_serial_row = mysqli_fetch_assoc($max_serial_res);
            $max_serial_val = intval($max_serial_row['max_serial'] ?? 0);
            $next_serial = $max_serial_val + 1;

            $username = $_SESSION['user_name'] ?? 'System';

            foreach ($records as $row) {
                $row_action = $row['action'] ?? 'insert'; // 'insert', 'replace', or 'skip'

                if ($row_action === 'skip') {
                    $duplicates++;
                    $detailed_log[] = [
                        'passenger_name' => $row['passenger_name'],
                        'ticket_number' => $row['ticket_number'],
                        'pnr' => $row['pnr'],
                        'status' => 'Skipped',
                        'message' => 'Duplicate row skipped by user preference'
                    ];
                    continue;
                }

                $passenger_name = esc($row['passenger_name']);
                $customer_name = esc($row['customer_name']);
                $customer_type = esc($row['customer_type'] ?? 'Walk-in Customer');
                $customer_mobile = esc($row['customer_mobile'] ?? '');
                $customer_email = esc($row['customer_email'] ?? '');
                $pnr = esc($row['pnr']);
                $ticket_number = esc($row['ticket_number']);
                $travel_date = date('Y-m-d', strtotime($row['travel_date']));
                
                $arrival_date_raw = trim($row['arrival_date'] ?? '');
                $arrival_date = "";
                if (!empty($arrival_date_raw)) {
                    $ts_arr = strtotime($arrival_date_raw);
                    if ($ts_arr) {
                        $arrival_date = date('Y-m-d', $ts_arr);
                    }
                }
                $arrival_time = esc($row['arrival_time'] ?? '');
                
                $from_city = esc($row['from_city'] ?? '');
                $to_city = esc($row['to_city'] ?? '');
                $departure_time = esc($row['departure_time'] ?? '');
                $flight_number = esc($row['flight_number'] ?? '');
                $airline_name = esc($row['airline_name'] ?? '');
                $buying_cost = floatval($row['buying_cost'] ?? 0);
                $selling_cost = floatval($row['selling_cost'] ?? 0);
                $profit = $selling_cost - $buying_cost;
                $supplier_name = esc($row['supplier_name'] ?? 'Bulk Supplier');
                $payment_method = esc($row['payment_method'] ?? 'Cash');
                $status = esc($row['status'] ?? 'Booked');
                $remarks = esc($row['remarks'] ?? 'Imported in bulk');
                $ticket_path = esc($row['file_path'] ?? '');

                // Create or find customer
                $customer_master_id = 0;
                $customer_search = mysqli_query($db, "SELECT id FROM customer_master WHERE name = '$customer_name' LIMIT 1");
                if ($customer_search && mysqli_num_rows($customer_search) > 0) {
                    $cust_row = mysqli_fetch_assoc($customer_search);
                    $customer_master_id = $cust_row['id'];
                } else {
                    mysqli_query($db, "INSERT INTO customer_master (customer_type, name, mobile, email, created_by) VALUES ('$customer_type', '$customer_name', '$customer_mobile', '$customer_email', '$username')");
                    $customer_master_id = mysqli_insert_id($db);
                }

                $service_type = esc($row['service_type'] ?? 'Flight');

                // Document specific fields
                $doc_fields = [
                    // Passport
                    'passport_number', 'passport_type', 'passport_issuing_country', 'passport_country_code', 'passport_full_name',
                    'passport_surname', 'passport_given_names', 'passport_nationality', 'passport_gender', 'passport_date_of_birth',
                    'passport_place_of_birth', 'passport_date_of_issue', 'passport_date_of_expiry', 'passport_place_of_issue',
                    'passport_authority', 'passport_mrz_line1', 'passport_mrz_line2',
                    // Visa
                    'visa_type', 'visa_country', 'visa_app_no', 'visa_submission_date', 'visa_delivery_date', 'visa_entry_type',
                    'visa_duration_days', 'visa_issue_place', 'visa_uid_no', 'visa_full_name', 'visa_nationality',
                    'visa_place_of_birth', 'visa_date_of_birth', 'visa_passport_type', 'visa_passport_no', 'visa_profession',
                    // Hotel
                    'hotel_name', 'hotel_country', 'hotel_city', 'hotel_address', 'hotel_check_in', 'hotel_check_out',
                    'hotel_room_type', 'hotel_rooms_count', 'hotel_nights_count', 'hotel_meal_plan', 'hotel_adults_count',
                    'hotel_children_count', 'hotel_guest_names', 'hotel_booking_details', 'hotel_confirmation_no',
                    // Insurance
                    'insurance_policy_no', 'insurance_provider', 'insurance_insured_name', 'insurance_coverage_type',
                    'insurance_passport_no', 'insurance_destination', 'insurance_issue_date', 'insurance_start_date', 'insurance_end_date',
                    'insurance_days', 'insurance_sum_insured', 'insurance_premium_amount', 'insurance_emergency_no',
                    'insurance_certificate_no', 'insurance_coverage_details', 'insurance_remarks',
                    // Package
                    'package_voucher_no', 'package_name', 'package_destinations', 'package_country', 'package_guest_names',
                    'package_start_date', 'package_end_date', 'package_days_count', 'package_nights_count', 'package_adults_count',
                    'package_children_count', 'package_infants_count', 'package_accommodation', 'package_room_type', 'package_meals',
                    'package_transportation', 'package_pickup_details', 'package_dropoff_details', 'package_confirmation_no',
                    'package_itinerary', 'package_inclusions', 'package_exclusions', 'package_details', 'package_terms'
                ];

                if ($row_action === 'replace') {
                    $dup_id = intval($row['existing_id']);
                    // Update booking
                    $update_cols = [];
                    $base_updates = [
                        'passenger_name' => $passenger_name,
                        'customer_name' => $customer_name,
                        'customer_type' => $customer_type,
                        'from_city' => $from_city,
                        'to_city' => $to_city,
                        'departure_date' => $travel_date,
                        'departure_time' => $departure_time,
                        'arrival_date' => $arrival_date ? $arrival_date : null,
                        'arrival_time' => $arrival_time,
                        'pnr' => $pnr,
                        'ticket_number' => $ticket_number,
                        'flight_number' => $flight_number,
                        'airline_name' => $airline_name,
                        'service_type' => $service_type,
                        'document_type' => esc($row['document_type'] ?? 'ticket'),
                        'buying_cost' => $buying_cost,
                        'selling_cost' => $selling_cost,
                        'profit' => $profit,
                        'supplier_name' => $supplier_name,
                        'payment_method' => $payment_method,
                        'status' => $status,
                        'remarks' => $remarks,
                        'customer_master_id' => $customer_master_id
                    ];
                    foreach ($base_updates as $col => $val) {
                        if ($val === null) {
                            $update_cols[] = "$col = NULL";
                        } else {
                            $update_cols[] = "$col = '" . mysqli_real_escape_string($db, $val) . "'";
                        }
                    }
                    foreach ($doc_fields as $f) {
                        if (isset($row[$f])) {
                            $val = esc($row[$f]);
                            if ($val === '') {
                                $update_cols[] = "$f = ''";
                            } else {
                                $update_cols[] = "$f = '" . mysqli_real_escape_string($db, $val) . "'";
                            }
                        }
                    }
                    $q = "UPDATE bookings SET " . implode(', ', $update_cols) . " WHERE id = $dup_id";

                    if (mysqli_query($db, $q)) {
                        $imported++;
                        mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$username', 'Updated Booking #$dup_id (Passenger: $passenger_name) via Bulk Import', 'Booking', NOW())");
                        $detailed_log[] = [
                            'passenger_name' => $passenger_name,
                            'ticket_number' => $ticket_number,
                            'pnr' => $pnr,
                            'status' => 'Replaced',
                            'message' => "Replaced Booking ID: $dup_id"
                        ];
                    } else {
                        throw new Exception("Failed to update duplicate record: " . mysqli_error($db));
                    }

                } else {
                    // Insert new booking
                    $serial = $next_serial++;
                    $columns = [
                        'serial_no' => $serial,
                        'booking_date' => date('Y-m-d H:i:s'),
                        'passenger_name' => $passenger_name,
                        'customer_name' => $customer_name,
                        'customer_type' => $customer_type,
                        'from_city' => $from_city,
                        'to_city' => $to_city,
                        'departure_date' => $travel_date,
                        'departure_time' => $departure_time,
                        'arrival_date' => $arrival_date ? $arrival_date : null,
                        'arrival_time' => $arrival_time,
                        'pnr' => $pnr,
                        'ticket_number' => $ticket_number,
                        'flight_number' => $flight_number,
                        'airline_name' => $airline_name,
                        'service_type' => $service_type,
                        'supplier_name' => $supplier_name,
                        'buying_cost' => $buying_cost,
                        'selling_cost' => $selling_cost,
                        'profit' => $profit,
                        'payment_method' => $payment_method,
                        'assigned_user' => $username,
                        'status' => $status,
                        'remarks' => $remarks,
                        'ticket_path' => $ticket_path ? $ticket_path : null,
                        'created_by' => $username,
                        'customer_master_id' => $customer_master_id > 0 ? $customer_master_id : null,
                        'document_type' => esc($row['document_type'] ?? 'ticket')
                    ];

                    foreach ($doc_fields as $f) {
                        if (isset($row[$f])) {
                            $columns[$f] = esc($row[$f]);
                        }
                    }

                    $col_names = implode(', ', array_keys($columns));
                    $col_vals = [];
                    foreach ($columns as $val) {
                        if ($val === null) {
                            $col_vals[] = 'NULL';
                        } elseif (is_numeric($val) && !is_string($val)) {
                            $col_vals[] = $val;
                        } else {
                            $col_vals[] = "'" . mysqli_real_escape_string($db, $val) . "'";
                        }
                    }
                    $col_vals_str = implode(', ', $col_vals);
                    $q = "INSERT INTO bookings ($col_names) VALUES ($col_vals_str)";

                    if (mysqli_query($db, $q)) {
                        $imported_id = mysqli_insert_id($db);
                        $imported++;

                        // Add attachment if OCR processed ticket
                        if (!empty($ticket_path)) {
                            $row_document_type = strtolower(trim((string)($row['document_type'] ?? 'ticket')));
                            $file_type_map = [
                                'ticket' => 'Ticket', 'visa' => 'Visa', 'hotel' => 'Voucher',
                                'package' => 'Voucher', 'insurance' => 'Other', 'passport' => 'Passport',
                                'other' => 'Other'
                            ];
                            $attachment_type = $file_type_map[$row_document_type] ?? 'Other';
                            $att_name = "Imported $attachment_type (" . basename($ticket_path) . ")";
                            mysqli_query($db, "INSERT INTO booking_attachments (booking_id, file_path, file_name, file_type) VALUES ($imported_id, '$ticket_path', '$att_name', '$attachment_type')");
                        }

                        mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$username', 'Created Booking #$imported_id (Passenger: $passenger_name) via Bulk Import', 'Booking', NOW())");
                        $detailed_log[] = [
                            'passenger_name' => $passenger_name,
                            'ticket_number' => $ticket_number,
                            'pnr' => $pnr,
                            'status' => 'Imported',
                            'message' => "Created Booking ID: $imported_id"
                        ];
                    } else {
                        throw new Exception("Failed to insert record: " . mysqli_error($db));
                    }
                }
            }

            $end_time = microtime(true);
            $proc_time_ms = round(($end_time - $start_time) * 1000);

            // Log activity
            mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$username', 'Bulk Import Completed: $imported bookings processed', 'Booking', NOW())");

            // Save history
            $json_log = esc(json_encode($detailed_log));
            mysqli_query($db, "INSERT INTO import_history (username, file_name, file_type, total_records, imported_records, failed_records, duplicate_records, processing_time_ms, details) VALUES ('$username', '$file_name', '$file_type', $total, $imported, $failed, $duplicates, $proc_time_ms, '$json_log')");

            // Commit
            mysqli_commit($db);

            echo json_encode([
                'status' => 'success',
                'summary' => [
                    'total' => $total,
                    'imported' => $imported,
                    'failed' => $failed,
                    'duplicates' => $duplicates,
                    'time_ms' => $proc_time_ms
                ]
            ]);

        } catch (Exception $e) {
            mysqli_rollback($db);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_import_history') {
        $q = "SELECT id, import_date, username, file_name, file_type, total_records, imported_records, failed_records, duplicate_records, processing_time_ms FROM import_history ORDER BY id DESC LIMIT 50";
        $res = mysqli_query($db, $q);
        $list = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $list[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'history' => $list]);
        exit;
    }

    if ($action === 'get_import_details') {
        $id = intval($_GET['id']);
        $res = mysqli_query($db, "SELECT details FROM import_history WHERE id = $id");
        if ($res && mysqli_num_rows($res) > 0) {
            $row = mysqli_fetch_assoc($res);
            echo json_encode(['status' => 'success', 'details' => json_decode($row['details'], true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Details not found.']);
        }
        exit;
    }

    if ($action === 'download_sample') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bookings_bulk_import_template.csv');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Passenger Name', 'Customer/Agency Name', 'Customer Type', 'Customer Mobile', 'Customer Email', 
            'PNR', 'Ticket Number', 'Travel Date', 'From City', 'To City', 
            'Departure Time', 'Flight Number', 'Airline Name', 'Buying Cost', 'Selling Cost', 
            'Supplier Name', 'Payment Method', 'Status', 'Remarks'
        ]);
        fputcsv($output, [
            'John Doe', 'Direct Client', 'Walk-in Customer', '9876543210', 'john@example.com', 
            'PNR123', '9991234567890', date('Y-m-d'), 'Mumbai', 'Dubai', 
            '10:30', 'EK501', 'Emirates', '15000', '18000', 
            'Supplier A', 'UPI', 'Booked', 'Family trip booking'
        ]);
        fclose($output);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid endpoint action.']);
    exit;
}

// Local helper functions for OCR processing
function ocr_command_exists_local($command) {
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

function find_ocr_command_local($commands) {
    foreach ($commands as $command) {
        if (ocr_command_exists_local($command)) {
            return $command;
        }
    }
    return null;
}

function run_ocr_command_local($command) {
    $output = [];
    $code = 0;
    @exec($command . " 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

function normalize_ticket_text_local($text, $preserve_layout = false) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (!$preserve_layout) {
        $text = preg_replace('/[ \t]+/', ' ', $text);
    } else {
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            $line = rtrim($line);
        }
        $text = implode("\n", $lines);
    }
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function clean_ocr_value($value) {
    $value = preg_replace('/[ \t]+/', ' ', trim($value));
    $value = preg_replace('/\s*(?:\||,|;)\s*$/', '', $value);
    return trim($value);
}

function document_date($value) {
    if (empty($value)) return "";
    $value = str_replace('.', '-', trim($value));
    $date = DateTime::createFromFormat('d-m-Y', $value)
        ?: DateTime::createFromFormat('d/m/Y', $value)
        ?: DateTime::createFromFormat('Y-m-d', $value);
    if (!$date) {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : "";
    }
    return $date->format('Y-m-d');
}

function detect_document_type($text, $filename = '') {
    $combined = strtolower($text . ' ' . $filename);
    $signals = [
        'ticket' => ['electronic flight ticket' => 4, 'e-ticket' => 4, 'airline pnr' => 3, 'ticket number' => 2, 'flight number' => 2, 'baggage allowance' => 2, 'departure' => 1, 'arrival' => 1],
        'hotel' => ['hotel voucher' => 4, 'hotel booking' => 4, 'check-in' => 2, 'check in' => 2, 'check-out' => 2, 'check out' => 2, 'room type' => 2, 'meal plan' => 1, 'no. of nights' => 2],
        'visa' => ['evisa' => 5, 'e-visa' => 5, 'entry permit no' => 4, 'entry permit' => 3, 'visa type' => 3, 'valid until' => 2, 'uid no' => 2],
        'passport' => ['passport' => 2, 'passport no' => 2, 'passport number' => 2, 'date of birth' => 1, 'place of birth' => 1, 'date of issue' => 2, 'date of expiry' => 2, 'given names' => 2, 'surname' => 2, 'authority' => 1, 'p<' => 4],
        'insurance' => ['travel insurance' => 5, 'insurance certificate' => 5, 'policy number' => 3, 'policy no' => 3, 'insured person' => 3, 'insurance provider' => 2, 'coverage period' => 2, 'coverage start' => 2, 'coverage end' => 2, 'sum insured' => 2, 'premium' => 1, 'emergency assistance' => 2],
        'package' => ['tour package' => 5, 'holiday package' => 5, 'package voucher' => 5, 'tour voucher' => 5, 'package name' => 3, 'itinerary' => 2, 'inclusions' => 2, 'exclusions' => 2, 'accommodation' => 1, 'sightseeing' => 2, 'pickup' => 1, 'drop-off' => 1, 'number of nights' => 1],
    ];
    $scores = array_fill_keys(array_keys($signals), 0);
    $matches = array_fill_keys(array_keys($signals), 0);
    foreach ($signals as $type => $keywords) {
        foreach ($keywords as $keyword => $weight) {
            if (strpos($combined, $keyword) !== false) {
                $scores[$type] += $weight;
                $matches[$type]++;
            }
        }
    }

    if ($matches['visa'] >= 2 && preg_match('/\b(?:e-?visa|entry permit)\b/i', $combined)) return 'visa';
    if ($matches['insurance'] >= 2 && preg_match('/\b(?:travel insurance|insurance certificate)\b/i', $combined)) return 'insurance';
    if ($matches['package'] >= 2 && preg_match('/\b(?:tour package|holiday package|package voucher|tour voucher)\b/i', $combined)) return 'package';
    if (preg_match('/^\s*P<[A-Z0-9<]{20,50}\s*$/mi', $text)
        || preg_match('/^\s*[A-Z0-9<]{40,44}\s*\R\s*[A-Z0-9<]{40,44}\s*$/mi', $text)) {
        return 'passport';
    }

    // Specific document identities take precedence over shared passport/hotel vocabulary.
    foreach (['visa', 'insurance', 'package', 'hotel', 'passport', 'ticket'] as $type) {
        $minimum = in_array($type, ['visa', 'insurance', 'package'], true) ? 5 : 4;
        if ($scores[$type] >= $minimum && $matches[$type] >= 2) {
            $higher = true;
            foreach ($scores as $other => $score) {
                if ($other !== $type && $score > $scores[$type] + 2) $higher = false;
            }
            if ($higher) return $type;
        }
    }
    return 'other';
}

function document_value_local($text, $patterns) {
    if (empty($text)) return "";
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return clean_ocr_value_local($matches[1] ?? "");
        }
    }
    return "";
}

function document_group_local($text, $pattern, $group = 1) {
    if (empty($text)) return "";
    return preg_match($pattern, $text, $matches) ? clean_ocr_value_local($matches[$group] ?? "") : "";
}

function document_sum_local($text, $pattern) {
    if (!preg_match_all($pattern, $text, $matches)) return 0;
    return array_sum(array_map('intval', $matches[1]));
}

function document_count_local($text, $pattern) {
    return preg_match_all($pattern, $text, $matches) ?: 0;
}

function extract_hotel_document_fields_local($text) {
    $fields = [
        'confirmation_no' => '', 'name' => '', 'country' => '', 'city' => '',
        'address' => '', 'check_in' => '', 'check_out' => '', 'nights' => 0,
        'rooms' => 0, 'room_type' => '', 'meal_plan' => '', 'nationality' => '',
        'adults' => 0, 'children' => 0, 'guest_names' => '', 'booking_details' => ''
    ];
    if (trim((string) $text) === '') return $fields;

    $fields['confirmation_no'] = document_value_local($text, [
        '/Booking\s*(?:ID|No)\s*[:#-]?\s*([A-Z0-9-]+)/i',
        '/Voucher\s*(?:Number|No)\s*[:#-]?\s*([A-Z0-9-]+)/i'
    ]);
    $fields['address'] = document_value_local($text, ['/Address\s*[:#-]\s*([^\n]+)/i']);

    $lines = preg_split('/\r?\n/', $text);
    $address_index = -1;
    foreach ($lines as $index => $line) {
        if (stripos($line, 'Address') !== false) {
            $address_index = $index;
            break;
        }
    }
    if ($address_index >= 0) {
        $location_index = -1;
        for ($i = $address_index - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if (preg_match('/^([^,\n]{2,50})\s*,\s*([^,\n]{2,50})$/', $line, $match)) {
                $fields['city'] = clean_ocr_value_local($match[1]);
                $fields['country'] = clean_ocr_value_local($match[2]);
                $location_index = $i;
                break;
            }
        }
        if ($location_index >= 0) {
            for ($i = $location_index - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '' || !preg_match('/[A-Za-z0-9]/', $line)) continue;
                if (preg_match('/^(?:[kK★*]\s*){2,}$/u', $line)) continue;
                $fields['name'] = clean_ocr_value_local($line);
                break;
            }
        }
    }

    if ($fields['name'] === '') {
        $fields['name'] = document_value_local($text, ['/Hotel\s*(?:Name)?\s*[:#-]\s*([^\n]+)/i']);
    }
    if ($fields['city'] === '') $fields['city'] = document_value_local($text, ['/City\s*[:#-]\s*([^\n]+)/i']);
    if ($fields['country'] === '') $fields['country'] = document_value_local($text, ['/Country\s*[:#-]\s*([^\n]+)/i']);

    $date_pattern = '\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}|\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2}';
    if (preg_match('/Check\s*[- ]?in(?:\s*Date)?[\s\S]{0,300}/i', $text, $window)
        && preg_match_all('/\b(' . $date_pattern . ')\b/i', $window[0], $dates)) {
        $fields['check_in'] = document_date($dates[1][0] ?? '');
        $fields['check_out'] = document_date($dates[1][1] ?? '');
    }
    if ($fields['check_in'] === '') {
        $fields['check_in'] = document_date(document_value_local($text, ['/Check\s*[- ]?in(?:\s*Date)?\s*[:#-]?\s*(' . $date_pattern . ')/i']));
    }
    if ($fields['check_out'] === '') {
        $fields['check_out'] = document_date(document_value_local($text, ['/Check\s*[- ]?out(?:\s*Date)?\s*[:#-]?\s*(' . $date_pattern . ')/i']));
    }

    $fields['nights'] = (int) document_value_local($text, ['/No\.?[ \t]*Of[ \t]*Nights[ \t]*[:#-]?[ \t]*(\d+)/i']);
    if (!$fields['nights'] && preg_match('/No\.?[ \t]*Of[ \t]*Nights[^\n]*\n[^\n]*?\b(\d+)\s*$/im', $text, $match)) {
        $fields['nights'] = (int) $match[1];
    }
    if (!$fields['nights'] && $fields['check_in'] && $fields['check_out']) {
        $fields['nights'] = max(0, (int) ((strtotime($fields['check_out']) - strtotime($fields['check_in'])) / 86400));
    }

    $fields['rooms'] = document_count_local($text, '/^\s*Room\s+\d+\b/mi');
    if (!$fields['rooms']) $fields['rooms'] = (int) document_value_local($text, ['/No\.?\s*of\s*Rooms\s*[:#-]?\s*(\d+)/i']);
    $fields['nationality'] = document_value_local($text, ['/Nationality\s*:\s*([A-Za-z ]+?)(?=\s+\d+\s*Adults?\b|\r?$)/im']);
    $fields['adults'] = document_sum_local($text, '/\b(\d+)\s*Adults?\b/i');
    $fields['children'] = document_sum_local($text, '/\b(\d+)\s*Child(?:ren)?\b/i');
    $fields['meal_plan'] = document_value_local($text, [
        '/Meal\s*Plan\s*[:#-]?\s*([^\n]+)/i',
        '/\((Room Only|Breakfast|Half Board|Full Board|All Inclusive)\)/i'
    ]);

    foreach ($lines as $line) {
        $candidate = clean_ocr_value_local($line);
        if (preg_match('/\b(?:single|double|twin|triple|quad|suite|studio|villa|apartment)\b.*\broom\b|\broom\b.*\b(?:single|double|twin|triple|quad|executive|deluxe|standard|superior)\b/i', $candidate)) {
            if (preg_match('/^Room\s+\d+\b/i', $candidate) || preg_match('/^\(?Room Only\)?$/i', $candidate)) continue;
            $fields['room_type'] = trim(preg_replace('/\s+Adults?\s+Name.*$/i', '', $candidate));
            break;
        }
    }
    if ($fields['room_type'] === '') {
        $fields['room_type'] = document_value_local($text, ['/Room\s*Type\s*[:#-]?\s*([^\n]+)/i']);
    }

    $guests = [];
    foreach ($lines as $line) {
        $candidate = clean_ocr_value_local($line);
        if (preg_match('/^(?:Mrs|Mr|Ms|Miss|Mstr)\.?\s+[A-Za-z][A-Za-z .\-]{2,80}$/i', $candidate)) {
            $guests[] = $candidate;
        }
    }
    $fields['guest_names'] = implode(', ', array_values(array_unique($guests)));
    if ($fields['guest_names'] === '') {
        $fields['guest_names'] = document_value_local($text, ['/Guest\s*Names?\s*[:#-]?\s*([^\n]+)/i']);
    }
    $detail_parts = [];
    if ($fields['nationality'] !== '') $detail_parts[] = 'Nationality: ' . $fields['nationality'];
    if (preg_match('/\bOffer\s*:\s*([^\r\n]+)/i', $text, $match)) {
        $detail_parts[] = 'Offer: ' . clean_ocr_value_local($match[1]);
    }
    $fields['booking_details'] = implode('; ', $detail_parts);

    return $fields;
}

function extract_passport_document_fields_local($text) {
    $layout = extract_layout_fields($text);
    $value = function($patterns, $layout_keys) use ($text, $layout) {
        foreach ($layout_keys as $lk) {
            if (isset($layout[$lk]) && $layout[$lk] !== '') {
                return $layout[$lk];
            }
        }
        return document_value_local($text, $patterns);
    };

    $fields = [
        'number' => $value(['/Passport\s*(?:No\.?|Number)\s*[:#-]?\s*([A-Z0-9<]{5,20})/i'], ['passport number', 'passport no', 'passport no.']),
        'type' => $value(['/Passport\s*Type\s*[:#-]?\s*([^\n]+)/i', '/Type\s*[:#-]?\s*([A-Z]{1,3})\b/i'], ['passport type', 'type']),
        'issuing_country' => $value(['/Issuing\s*Country\s*[:#-]?\s*([^\n]+)/i', '/Country\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i'], ['issuing country', 'country of issue', 'issuing state']),
        'country_code' => $value(['/Country\s*Code\s*[:#-]?\s*([A-Z]{3})\b/i'], ['country code']),
        'full_name' => $value(['/Full\s*Name\s*[:#-]?\s*([^\n]+)/i'], ['full name', 'name']),
        'surname' => $value(['/Surname\s*[:#-]?\s*([^\n]+)/i'], ['surname', 'surname / nom']),
        'given_names' => $value(['/Given\s*Names?\s*[:#-]?\s*([^\n]+)/i'], ['given names', 'given name', 'given names / prénoms']),
        'nationality' => $value(['/Nationality\s*[:#-]?\s*([^\n]+)/i'], ['nationality', 'nationalité']),
        'gender' => $value(['/(?:Sex|Gender)\s*[:#-]?\s*(Male|Female|M|F|X)\b/i'], ['sex', 'gender', 'sexe']),
        'date_of_birth' => document_date($value(['/(?:Date\s*of\s*Birth|DOB)\s*[:#-]?\s*([^\n]+)/i'], ['date of birth', 'dob', 'date de naissance'])),
        'place_of_birth' => $value(['/Place\s*of\s*Birth\s*[:#-]?\s*([^\n]+)/i'], ['place of birth', 'lieu de naissance']),
        'date_of_issue' => document_date($value(['/Date\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i', '/Issue\s*Date\s*[:#-]?\s*([^\n]+)/i'], ['date of issue', 'issue date', 'date de délivrance'])),
        'date_of_expiry' => document_date($value(['/(?:Date\s*of\s*Expiry|Expiry\s*Date|Expiration\s*Date)\s*[:#-]?\s*([^\n]+)/i'], ['date of expiry', 'expiry date', 'date d\'expiration'])),
        'place_of_issue' => $value(['/Place\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i'], ['place of issue']),
        'authority' => $value(['/Authority\s*[:#-]?\s*([^\n]+)/i'], ['authority', 'autorité']),
        'mrz_line1' => '', 'mrz_line2' => ''
    ];
    if (preg_match_all('/^\s*([A-Z0-9<]{30,50})\s*$/m', strtoupper($text), $mrz)) {
        $fields['mrz_line1'] = $mrz[1][0] ?? '';
        $fields['mrz_line2'] = $mrz[1][1] ?? '';
    }

    // TD3 passport MRZ fallback. Scans often preserve these two machine-readable
    // lines more reliably than the small printed labels above them.
    if (strlen($fields['mrz_line1']) >= 30 && strlen($fields['mrz_line2']) >= 30) {
        $mrz1 = str_pad($fields['mrz_line1'], 44, '<');
        $mrz2 = str_pad($fields['mrz_line2'], 44, '<');
        $mrz_text = function($value) {
            return trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $value)));
        };
        $mrz_date = function($value, $is_expiry = false) {
            if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts)) return '';
            $year = (int)$parts[1];
            if ($is_expiry) {
                $year += 2000;
            } else {
                $current_two_digit_year = (int)date('y');
                $year += $year > $current_two_digit_year ? 1900 : 2000;
            }
            $month = (int)$parts[2];
            $day = (int)$parts[3];
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
        };

        $name_parts = explode('<<', substr($mrz1, 5), 2);
        $mrz_surname = $mrz_text($name_parts[0] ?? '');
        $mrz_given_names = $mrz_text($name_parts[1] ?? '');
        if ($fields['type'] === '') $fields['type'] = $mrz_text(substr($mrz1, 0, 1));
        if ($fields['country_code'] === '') $fields['country_code'] = $mrz_text(substr($mrz1, 2, 3));
        if ($fields['issuing_country'] === '') $fields['issuing_country'] = $fields['country_code'];
        if ($fields['surname'] === '') $fields['surname'] = $mrz_surname;
        if ($fields['given_names'] === '') $fields['given_names'] = $mrz_given_names;
        if ($fields['number'] === '') $fields['number'] = $mrz_text(substr($mrz2, 0, 9));
        if ($fields['nationality'] === '') $fields['nationality'] = $mrz_text(substr($mrz2, 10, 3));
        if ($fields['date_of_birth'] === '') $fields['date_of_birth'] = $mrz_date(substr($mrz2, 13, 6));
        if ($fields['gender'] === '') $fields['gender'] = $mrz_text(substr($mrz2, 20, 1));
        if ($fields['date_of_expiry'] === '') $fields['date_of_expiry'] = $mrz_date(substr($mrz2, 21, 6), true);
    }
    if ($fields['full_name'] === '') $fields['full_name'] = trim($fields['given_names'] . ' ' . $fields['surname']);
    return $fields;
}

function extract_insurance_document_fields_local($text) {
    $value = function($patterns) use ($text) { return document_value_local($text, $patterns); };
    $start = document_date($value(['/Coverage\s*(?:Start|From)\s*(?:Date)?\s*[:#-]?\s*([^\n]+)/i', '/Policy\s*Start\s*[:#-]?\s*([^\n]+)/i']));
    $end = document_date($value(['/Coverage\s*(?:End|To)\s*(?:Date)?\s*[:#-]?\s*([^\n]+)/i', '/Policy\s*End\s*[:#-]?\s*([^\n]+)/i']));
    $days = (int) $value(['/Number\s*of\s*Days\s*[:#-]?\s*(\d+)/i', '/Duration\s*[:#-]?\s*(\d+)\s*Days/i']);
    if (!$days && $start && $end) $days = max(0, (int) ((strtotime($end) - strtotime($start)) / 86400) + 1);
    return [
        'policy_no' => $value(['/Policy\s*(?:Number|No\.?)\s*[:#-]?\s*([A-Z0-9\/-]+)/i']),
        'provider' => $value(['/Insurance\s*Provider\s*[:#-]?\s*([^\n]+)/i', '/Insurer\s*[:#-]?\s*([^\n]+)/i']),
        'insured_name' => $value(['/Insured\s*(?:Person\s*)?(?:Name)?\s*[:#-]?\s*([^\n]+)/i', '/Name\s*of\s*Insured\s*[:#-]?\s*([^\n]+)/i']),
        'coverage_type' => $value(['/(?:Insurance\s*Type(?:\s*\/\s*Plan)?|Plan|Coverage\s*Type)\s*[:#-]?\s*([^\n]+)/i']),
        'passport_no' => $value(['/Passport\s*(?:Number|No\.?)\s*[:#-]?\s*([A-Z0-9-]+)/i']),
        'destination' => $value(['/Destination\s*(?:Country)?\s*[:#-]?\s*([^\n]+)/i']),
        'issue_date' => document_date($value(['/(?:Policy\s*)?Issue\s*Date\s*[:#-]?\s*([^\n]+)/i'])),
        'start_date' => $start, 'end_date' => $end, 'days' => $days,
        'sum_insured' => $value(['/Sum\s*Insured\s*[:#-]?\s*(?:[A-Z]{3}|[$₹€£])?\s*([0-9,.]+)/iu']),
        'premium' => $value(['/Premium(?:\s*Amount)?\s*[:#-]?\s*(?:[A-Z]{3}|[$₹€£])?\s*([0-9,.]+)/iu']),
        'emergency_no' => $value(['/Emergency\s*Assistance(?:\s*(?:Number|No\.))?\s*[:#-]?\s*([^\n]+)/i']),
        'certificate_no' => $value(['/Certificate\s*(?:Number|No\.)\s*[:#-]?\s*([A-Z0-9\/-]+)/i']),
        'coverage_details' => $value(['/Coverage\s*Details\s*[:#-]?\s*([^\n]+)/i']),
        'remarks' => $value(['/Remarks?\s*[:#-]?\s*([^\n]+)/i'])
    ];
}

function extract_package_document_fields_local($text) {
    $value = function($patterns) use ($text) { return document_value_local($text, $patterns); };
    $start = document_date($value(['/(?:Travel|Tour|Package)\s*Start\s*(?:Date)?\s*[:#-]?\s*([^\n]+)/i', '/From\s*Date\s*[:#-]?\s*([^\n]+)/i']));
    $end = document_date($value(['/(?:Travel|Tour|Package)\s*End\s*(?:Date)?\s*[:#-]?\s*([^\n]+)/i', '/To\s*Date\s*[:#-]?\s*([^\n]+)/i']));
    $days = (int) $value(['/Number\s*of\s*Days\s*[:#-]?\s*(\d+)/i', '/\b(\d+)\s*Days\b/i']);
    $nights = (int) $value(['/Number\s*of\s*Nights\s*[:#-]?\s*(\d+)/i', '/\b(\d+)\s*Nights\b/i']);
    if ($start && $end) {
        $difference = max(0, (int) ((strtotime($end) - strtotime($start)) / 86400));
        if (!$nights) $nights = $difference;
        if (!$days) $days = $difference + 1;
    }
    return [
        'voucher_no' => $value(['/(?:Package|Tour)\s*(?:Voucher|Reference|Booking)\s*(?:Number|No\.)?\s*[:#-]?\s*([A-Z0-9\/-]+)/i']),
        'name' => $value(['/Package\s*Name\s*[:#-]?\s*([^\n]+)/i', '/(?:Tour|Holiday)\s*Package\s*[:#-]\s*([^\n]+)/i']),
        'destination' => $value(['/Destinations?\s*[:#-]?\s*([^\n]+)/i']),
        'country' => $value(['/Country\s*[:#-]?\s*([^\n]+)/i']),
        'guest_names' => $value(['/Guest\s*Names?\s*[:#-]?\s*([^\n]+)/i']),
        'start_date' => $start, 'end_date' => $end, 'days' => $days, 'nights' => $nights
    ];
}

function normalize_time_value($time) {
    if (preg_match('/([0-9]{1,2}):([0-9]{2})(?:\s*([aApP][mM]))?/', $time, $m)) {
        $hours = intval($m[1]);
        $minutes = intval($m[2]);
        if (isset($m[3]) && !empty($m[3])) {
            $am_pm = strtolower($m[3]);
            if ($am_pm === 'pm' && $hours < 12) {
                $hours += 12;
            } elseif ($am_pm === 'am' && $hours === 12) {
                $hours = 0;
            }
        }
        return sprintf('%02d:%02d', $hours, $minutes);
    }
    return '';
}

function extract_layout_fields($text) {
    $lines = explode("\n", $text);
    $fields = [];
    $active_labels = [];
    
    $known_labels = [
        'passenger name', 'passenger', 'pax', 'traveller name',
        'pnr', 'pnr code', 'airline pnr', 'booking reference',
        'ticket number', 'ticket no', 'e-ticket number',
        'airline name', 'airline',
        'flight number', 'flight no',
        'from', 'from (origin)', 'origin', 'departure airport',
        'to', 'to (destination)', 'to', 'destination', 'arrival airport',
        'departure date', 'departure time', 'arrival date', 'arrival time',
        // Passport labels
        'passport number', 'passport no', 'passport no.', 'passport details', 'passport type', 'type', 'issuing country',
        'country of issue', 'issuing state', 'country code', 'full name', 'name', 'surname', 'surname / nom',
        'given names', 'given name', 'given names / prénoms', 'nationality', 'nationalité', 'sex', 'gender', 'sexe',
        'date of birth', 'dob', 'date de naissance', 'place of birth', 'lieu de naissance', 'date of issue',
        'issue date', 'date de délivrance', 'date of expiry', 'expiry date', 'date d\'expiration', 'place of issue',
        'authority', 'autorité',
        // Visa labels
        'visa category', 'visa type', 'entry type', 'duration', 'days', 'valid until', 'valid to', 'uid no', 'uid no.',
        'visa app no', 'visa application number', 'visa number', 'visa no', 'country', 'submission date',
        'delivery date', 'profession',
        // Hotel labels
        'guest names', 'guest name', 'confirmation no', 'confirmation number', 'hotel name', 'check-in', 'check-out',
        'check in', 'check out', 'nights', 'rooms', 'meal plan', 'meal', 'adults', 'children',
        // Insurance labels
        'insured name', 'policy number', 'policy no', 'policy no.', 'provider', 'destination', 'start date', 'end date', 'sum insured',
        // Package labels
        'voucher no', 'voucher number', 'package name', 'destinations'
    ];
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (trim($line) === '') continue;
        
        preg_match_all('/[^\s]+(?:\s[^\s]+)*/', $line, $matches, PREG_OFFSET_CAPTURE);
        $cols = [];
        foreach ($matches[0] as $match) {
            $cols[] = [
                'text' => trim($match[0]),
                'start' => $match[1],
                'end' => $match[1] + strlen($match[0])
            ];
        }
        
        foreach ($cols as $col) {
            $norm = strtolower(trim(preg_replace('/[:#\-]+/', ' ', $col['text'])));
            $norm = preg_replace('/\s+/', ' ', $norm);
            if (in_array($norm, $known_labels, true)) {
                $active_labels[] = [
                    'label' => $norm,
                    'start' => $col['start'],
                    'end' => $col['end'],
                    'line_idx' => $i
                ];
            }
        }
        
        foreach ($cols as $col) {
            $norm = strtolower(trim(preg_replace('/[:#\-]+/', ' ', $col['text'])));
            $norm = preg_replace('/\s+/', ' ', $norm);
            if (in_array($norm, $known_labels, true)) {
                continue;
            }
            
            $best_label = null;
            $min_vdist = 9999;
            foreach ($active_labels as $al) {
                $vdist = $i - $al['line_idx'];
                if ($vdist <= 0 || $vdist > 6) continue;
                
                $overlaps = (max($col['start'], $al['start']) < min($col['end'], $al['end']));
                $close_start = (abs($col['start'] - $al['start']) <= 10);
                
                if ($overlaps || $close_start) {
                    if ($vdist < $min_vdist) {
                        $min_vdist = $vdist;
                        $best_label = $al['label'];
                    }
                }
            }
            
            if ($best_label !== null) {
                if (!isset($fields[$best_label])) {
                    $fields[$best_label] = $col['text'];
                }
            }
        }
        
        // Split-based count matching rule
        $trimmed = trim($line);
        $line_cols = preg_split('/\s{2,}/', $trimmed);
        
        $is_label_row = false;
        foreach ($line_cols as $c) {
            $norm = strtolower(trim(preg_replace('/[:#\-]+/', ' ', $c)));
            $norm = preg_replace('/\s+/', ' ', $norm);
            if (in_array($norm, $known_labels, true)) {
                $is_label_row = true;
                break;
            }
        }
        
        if ($is_label_row) {
            $next_val_line = '';
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (trim($lines[$j]) !== '') {
                    $next_val_line = trim($lines[$j]);
                    break;
                }
            }
            
            if ($next_val_line !== '') {
                $val_cols = preg_split('/\s{2,}/', $next_val_line);
                if (count($line_cols) === count($val_cols)) {
                    for ($k = 0; $k < count($line_cols); $k++) {
                        $lbl_norm = strtolower(trim(preg_replace('/[:#\-]+/', ' ', $line_cols[$k])));
                        $lbl_norm = preg_replace('/\s+/', ' ', $lbl_norm);
                        if (in_array($lbl_norm, $known_labels, true)) {
                            if (!isset($fields[$lbl_norm])) {
                                $fields[$lbl_norm] = trim($val_cols[$k]);
                            }
                        }
                    }
                }
            }
        }
    }
    
    return $fields;
}

function clean_ocr_value_local($value) {
    $value = preg_replace('/[ \t]+/', ' ', trim($value));
    $value = preg_replace('/\s*(?:\||,|;)\s*$/', '', $value);
    return trim($value);
}

function extract_ticket_text_local($target, $upload_real, &$error = "") {
    $target_real = realpath($target);
    if (!$target_real) {
        $error = "Uploaded file could not be read.";
        return "";
    }

    $extension = strtolower(pathinfo($target_real, PATHINFO_EXTENSION));
    $output_base = $upload_real . DIRECTORY_SEPARATOR . "ocr_" . time() . "_" . uniqid();

    // Natively read txt files
    if ($extension === 'txt') {
        $text = file_get_contents($target_real);
        unlink($target_real);
        return normalize_ticket_text_local($text);
    }

    // Natively read PDF files if pdftotext is available
    if ($extension === 'pdf') {
        $pdftotext = find_ocr_command_local([
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
            'pdftotext'
        ]);

        if ($pdftotext) {
            $text_file = $output_base . ".txt";
            [$code] = run_ocr_command_local('"' . $pdftotext . '" -layout "' . $target_real . '" "' . $text_file . '"');
            if ($code === 0 && file_exists($text_file)) {
                $text = file_get_contents($text_file);
                unlink($text_file);
                // Image-only PDFs commonly yield just a form-feed character. Require
                // actual letters/numbers before accepting native PDF extraction.
                if (preg_match('/[\p{L}\p{N}]{3}/u', (string)$text)) {
                    return normalize_ticket_text_local($text, true);
                }
            }
        }
    }
    $tesseract = find_ocr_command_local([
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe\\tesseract.exe',
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files\\(x86)\\Tesseract-OCR\\tesseract.exe',
        'tesseract'
    ]);

    if (!$tesseract) {
        $error = "Tesseract OCR is not installed or not found. Upload saved, but OCR could not run.";
        return "";
    }

    // Tesseract cannot read a PDF file directly. For scanned PDFs, render every
    // page first and OCR the page images. This also removes the browser/CDN as a
    // single point of failure for passport scans.
    if ($extension === 'pdf') {
        $pdftoppm = find_ocr_command_local([
            'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe',
            'C:\\Program Files\\poppler\\bin\\pdftoppm.exe',
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftoppm.exe',
            'pdftoppm'
        ]);

        if (!$pdftoppm) {
            $error = 'This PDF has no embedded text and Poppler pdftoppm is not available to render its scanned pages.';
            return "";
        }

        $page_prefix = $output_base . '_page';
        [$render_code, $render_output] = run_ocr_command_local(
            '"' . $pdftoppm . '" -jpeg -r 250 "' . $target_real . '" "' . $page_prefix . '"'
        );
        $page_files = glob($page_prefix . '-*.jpg') ?: [];
        natsort($page_files);
        $page_texts = [];
        $ocr_messages = [];

        foreach ($page_files as $page_index => $page_file) {
            $page_output = $output_base . '_ocr_' . ($page_index + 1);
            [$page_code, $page_message] = run_ocr_command_local(
                '"' . $tesseract . '" "' . $page_file . '" "' . $page_output . '" -l eng --psm 6'
            );
            $page_text_file = $page_output . '.txt';
            if ($page_code === 0 && file_exists($page_text_file)) {
                $page_text = trim((string)file_get_contents($page_text_file));
                if ($page_text !== '') $page_texts[] = $page_text;
            } elseif (trim($page_message) !== '') {
                $ocr_messages[] = trim($page_message);
            }
            if (file_exists($page_text_file)) @unlink($page_text_file);
            @unlink($page_file);
        }

        if ($page_texts) {
            return normalize_ticket_text_local(implode("\n\n", $page_texts));
        }

        $details = trim(implode(' ', array_filter([$render_output, implode(' ', $ocr_messages)])));
        $error = 'Scanned PDF page OCR did not extract any text.' . ($details !== '' ? ' ' . $details : '');
        return "";
    }

    [$code, $cmd_output] = run_ocr_command_local('"' . $tesseract . '" "' . $target_real . '" "' . $output_base . '" -l eng --psm 6');
    $text_file = $output_base . ".txt";

    if ($code === 0 && file_exists($text_file)) {
        $text = file_get_contents($text_file);
        unlink($text_file);
        return normalize_ticket_text_local($text);
    }

    $error = "OCR processing failed. " . trim($cmd_output);
    return "";
}

function split_ocr_text_into_tickets_local($text) {
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
        if (preg_match('/^(?:Name of Passenger|Passenger Name|Passenger:|pax name|\b(?:Mr|Mrs|Ms|Miss|Mstr)\b\.?\s+[a-zA-Z])/i', $line)) {
            // Skip table header lines containing typical table keywords
            if (preg_match('/\b(?:segment|flight|baggage|status|check\s*in|check-in|hand|gate|pax|passenger)\b/i', $line)) {
                continue;
            }
            if (!in_array($i, $split_indices, true)) {
                $split_indices[] = $i;
            }
        }
    }
    
    sort($split_indices);
    $split_indices = array_unique($split_indices);
    
    $tickets = [];
    if (count($split_indices) <= 1) {
        $tickets[] = $text;
    } else {
        $current_segment = [];
        $first_split_seen = false;
        for ($i = 0; $i < count($lines); $i++) {
            if (in_array($i, $split_indices, true)) {
                if (!$first_split_seen) {
                    $first_split_seen = true;
                } else {
                    $tickets[] = implode("\n", $current_segment);
                    $current_segment = [];
                }
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

function val_local($text, $key) {
    if (empty($text)) return "";
    
    // Check multiple passengers
    if ($key === 'Passenger') {
        if (preg_match_all('/([a-zA-Z][a-zA-Z \t.\-]{2,})\r?\nTicket\s*(?:number|No)/i', $text, $matches)) {
            return implode(", ", array_map('clean_ocr_value_local', $matches[1]));
        }
    }
    
    // Check multiple ticket numbers
    if ($key === 'Ticket') {
        if (preg_match_all('/Ticket\s*(?:number|No|Number|TKT)\s*[:\-]?\s*([0-9]{5,16})/i', $text, $matches)) {
            return implode(", ", array_map('clean_ocr_value_local', $matches[1]));
        }
    }
    
    // 1. First use the structured layout-field map.
    static $layout_cache = [];
    $text_hash = md5($text);
    if (!isset($layout_cache[$text_hash])) {
        $layout_cache[$text_hash] = extract_layout_fields($text);
    }
    $layout_fields = $layout_cache[$text_hash];
    
    $aliases = [];
    switch ($key) {
        case 'Passenger':
            $aliases = ['passenger name', 'passenger', 'pax', 'traveller name'];
            break;
        case 'PNR':
            $aliases = ['pnr code', 'airline pnr', 'pnr', 'booking reference'];
            break;
        case 'Ticket':
            $aliases = ['ticket number', 'ticket no', 'e-ticket number'];
            break;
        case 'Airline':
            $aliases = ['airline name', 'airline'];
            break;
        case 'Flight Number':
            $aliases = ['flight number', 'flight no'];
            break;
        case 'From':
            $aliases = ['from (origin)', 'from', 'origin', 'departure airport'];
            break;
        case 'To':
            $aliases = ['to (destination)', 'to', 'destination', 'arrival airport'];
            break;
        case 'Departure Date':
            $aliases = ['departure date'];
            break;
        case 'Departure Time':
            $aliases = ['departure time'];
            break;
        case 'Arrival Date':
            $aliases = ['arrival date'];
            break;
        case 'Arrival Time':
            $aliases = ['arrival time'];
            break;
    }
    
    foreach ($aliases as $alias) {
        if (isset($layout_fields[$alias]) && trim($layout_fields[$alias]) !== '') {
            $val = trim($layout_fields[$alias]);
            
            if ($key === 'PNR') {
                if (preg_match('/\b([A-Z0-9]{5,8})\b/i', $val, $m)) {
                    return strtoupper($m[1]);
                }
                continue;
            }
            if ($key === 'Departure Date' || $key === 'Arrival Date') {
                $val = document_date($val);
            }
            if ($key === 'Departure Time' || $key === 'Arrival Time') {
                $val = normalize_time_value($val);
            }
            if ($val !== "") {
                return $val;
            }
        }
    }
    
    // 2. If the field is missing, use a field-specific regex fallback.
    $line = '[a-zA-Z0-9 \t\/\-.,()]';
    $date = '[0-9]{1,2}[ \t]*[a-zA-Z]{3,9}[ \t]*[0-9]{2,4}|[0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4}|[0-9]{4}[\/\-][0-9]{1,2}[\/\-][0-9]{1,2}';
    $time = '[0-9]{1,2}:[0-9]{2}(?:[ \t]*[aApPmM]*)?';
    $months = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
    
    $patterns = [
        'Passenger' => [
            '/\b(?:Mstr|Mr|Ms|Mrs|Miss|Mses)\.?\s+([a-zA-Z][a-zA-Z \t.\-]{1,30}?)(?:\s+(?:[A-Z]{3}\s*(?:\?|\-|to|>|\/|✈)\s*[A-Z]{3}|\b[A-Z]{2}\d{2,5}\b|Cabin|Seat|Economy|Class|CONFIRMED))/i',
            '/\b(?:Mstr|Mr|Ms|Mrs|Miss|Mses)\.?\s+([a-zA-Z][a-zA-Z \t.\-]{3,})/i',
            '/(?:Passenger|Pax|Traveller|Traveler|Name)(?:\s*Name)?\s*[:\-]?\s*([a-zA-Z][a-zA-Z \t\/.\-]{2,})/i',
            '/^[ \t]*(?:Mstr|Mr|Ms|Mrs|Miss|Mses)\.?\s+([a-zA-Z \t\/.\-]+)/im'
        ],
        'PNR' => [
            '/(?:PNR|Booking\s*(?:Ref|Reference)|Airline\s*PNR)(?:\s*Code)?\s*[:\-#]?\s*(\b[A-Z0-9]{5,8}\b)/i'
        ],
        'Ticket' => [
            '/(?:Ticket|TKT|E-?Ticket)(?:\s*(?:Number|No|#|Number\(s\)|number\s*\(s\)))?\s*[:\-#\r\n\s]*\s*([0-9]{3}[\- ]?[0-9]{6,13}|[0-9]{10,16})/i',
            '/\b([0-9]{3}[\- ]?[0-9]{10})\b/'
        ],
        'From' => [
            '/DEPARTURE\s*\n\s*([A-Z]{3})/i',
            '/(?:\d{1,2}\s*[a-zA-Z]{3}\s*\d{0,4})?\s+([a-zA-Z ]+)\s*(?:\([^)]+\))?\s+\d{2}:\d{2}\s+\d{2}:\d{2}\s+[a-zA-Z ]+\s+\d{2}:\d{2}/i',
            '/([a-zA-Z ]+)\s+\d+h\s+\d+m\s+(?:\+\s+)?(?:Non\s*Stop|Stop|Direct)\s+[a-zA-Z\s]+/i',
            '/(?:From|Origin|Departure(?!\s*(?:Date|Time|Terminal|Gate)))(?:\s*\([^)]+\))?\s*[:\-]?\s*(' . $line . '+)/i',
            '/\b([a-zA-Z]{3,15})\s+(?:‘|’|\'|‘|’|\-|to|>|\/|\?|✈)\s+([a-zA-Z ]{3,15})\b/i',
            '/\b([A-Z]{3})\s*(?:-|to|>)\s*[A-Z]{3}\b/i'
        ],
        'To' => [
            '/ARRIVAL\s*\n\s*([A-Z]{3})/i',
            '/(?:\d{1,2}\s*[a-zA-Z]{3}\s*\d{0,4})?\s+[a-zA-Z ]+\s*(?:\([^)]+\))?\s+\d{2}:\d{2}\s+\d{2}:\d{2}\s+([a-zA-Z ]+)\s+\d{2}:\d{2}/i',
            '/[a-zA-Z\s]+\s+\d+h\s+\d+m\s+(?:\+\s+)?(?:Non\s*Stop|Stop|Direct)\s+([a-zA-Z ]+)/i',
            '/(?:To|Destination|Arrival(?!\s*(?:Date|Time|Terminal|Gate)))(?:\s*\([^)]+\))?\s*[:\-]?\s*(' . $line . '+)/i',
            '/\b[a-zA-Z]{3,15}\s+(?:‘|’|\'|‘|’|\-|to|>|\/|\?|✈)\s+([a-zA-Z ]{3,15})\b/i',
            '/\b[A-Z]{3}\s*(?:-|to|>)\s*([A-Z]{3})\b/i'
        ],
        'Departure Date' => [
            '/DEPARTURE\s*\n\s*[A-Z]{3}\s*\n\s*[A-Z\s]+\s*\n\s*\d{2}:\d{2}\s*\n\s*(' . $date . ')/i',
            '/([0-9]{1,2}[ \t]*[a-zA-Z]{3,9}[ \t]*[0-9]{2,4})\s+[a-zA-Z ]+\s*(?:\([^)]+\))?\s+\d{2}:\d{2}/i',
            '/\b((?:' . $months . ')[a-z]*\s+\d{1,2})\s+\b(?:' . $months . ')[a-z]*\s+\d{1,2}\b/i',
            '/(?:Dep|Departure|Depart|Date)\s*(?:Date)?\s*[:\-]?\s*(' . $date . ')/i',
            '/\b(' . $date . ')\b/i'
        ],
        'Departure Time' => [
            '/DEPARTURE\s*\n\s*[A-Z]{3}\s*\n\s*[A-Z\s]+\s*\n\s*(' . $time . ')/i',
            '/[a-zA-Z ]+\s*(?:\([^)]+\))?\s+(\d{2}:\d{2})\s+\d{2}:\d{2}/i',
            '/(\d{2}:\d{2})\s+\d{2}:\d{2}/',
            '/(?:Dep|Departs|Departure|Depart)(?:\s*Time)?\s*[:\-]?\s*(?:' . $date . ')?\s*(' . $time . ')/i',
            '/\b(' . $time . ')\b/'
        ],
        'Arrival Date' => [
            '/ARRIVAL\s*\n\s*[A-Z]{3}\s*\n\s*[A-Z\s]+\s*\n\s*(' . $date . ')/i',
            '/\b(?:' . $months . ')[a-z]*\s+\d{1,2}\s+\b((?:' . $months . ')[a-z]*\s+\d{1,2})\b/i',
            '/Arr(?:ival)?\s*(?:Date)?\s*[:\-]?\s*(' . $date . ')/i'
        ],
        'Arrival Time' => [
            '/ARRIVAL\s*\n\s*[A-Z]{3}\s*\n\s*[A-Z\s]+\s*\n\s*(' . $time . ')/i',
            '/\b(?:Arr|Arrival|To|Destination)\b[a-zA-Z ]*\s+(\d{2}:\d{2})\s*$/mi',
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
    
    $pList = $patterns[$key] ?? ["/$key" . "[: ]*(.*)/i"];
    
    foreach ($pList as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $val = clean_ocr_value_local($m[1]);
            if ($val !== "") {
                if ($key === 'PNR') {
                    if (preg_match('/\b([A-Z0-9]{5,8})\b/i', $val, $pm)) {
                        return strtoupper($pm[1]);
                    }
                    continue;
                }
                if ($key === 'Departure Date' || $key === 'Arrival Date') {
                    $val = document_date($val);
                }
                if ($key === 'Departure Time' || $key === 'Arrival Time') {
                    $val = normalize_time_value($val);
                }
                if ($val !== "") {
                    return $val;
                }
            }
        }
    }
    
    if ($key === 'Airline') {
        $flight = val_local($text, 'Flight Number');
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Import Center | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <!-- Stylesheets relative to call location -->
    <link rel="stylesheet" href="../../assets/css/style.css">
    
    <!-- Third-party Javascript libraries -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    
    <script>
        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
        }
        if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");
    </script>
    <script src="../../assets/js/sidebar.js" defer></script>

    <style>
        .import-tab-btn {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 4px;
            border-bottom: none;
        }
        .import-tab-btn.active {
            background: #fff;
            color: var(--accent-color);
            border-color: var(--border-dark);
            border-bottom: 2px solid #fff;
            position: relative;
            z-index: 10;
        }
        .tab-content {
            background: #fff;
            border: 1px solid var(--border-dark);
            border-radius: 0 12px 12px 12px;
            padding: 24px;
            margin-top: -1px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
        }
        .upload-dragzone {
            border: 2px dashed #cbd5e1;
            background: #f8fafc;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .upload-dragzone:hover, .upload-dragzone.dragover {
            border-color: var(--accent-color);
            background: #f0f9ff;
        }
        .upload-icon {
            font-size: 48px;
            color: #64748b;
        }
        .btn-glow {
            box-shadow: 0 4px 14px 0 rgba(99, 102, 241, 0.4);
        }
        .progress-bar-container {
            width: 100%;
            background-color: #e2e8f0;
            border-radius: 9999px;
            height: 20px;
            overflow: hidden;
            margin: 16px 0;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: var(--accent-color);
            width: 0%;
            transition: width 0.3s ease;
        }
        .progress-label {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 13px;
        }
        .preview-table th, .preview-table td {
            border: 1px solid var(--border-dark);
            padding: 10px;
            text-align: left;
        }
        .preview-table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #334155;
        }
        .preview-table tr.has-error {
            background-color: #fef2f2;
        }
        .preview-table tr.has-dup {
            background-color: #fffbeb;
        }
        .badge {
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 9999px;
            display: inline-block;
        }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-success { background: #dcfce7; color: #166534; }
        .cell-editable {
            outline: none;
            padding: 4px;
            border-radius: 4px;
            transition: background 0.1s;
        }
        .cell-editable:focus {
            background: #fff;
            border: 1px solid var(--accent-color);
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2);
        }
        .mapping-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin: 20px 0;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border-dark);
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 24px;
            border: 1px solid #888;
            width: 80%;
            max-width: 900px;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover { color: black; }
    </style>
</head>
<body>

<?php include(__DIR__ . "/sidebar.php"); ?>

<div class="main">


    <div class="dashboard-title-row">
        <h1>Enterprise Bulk Import Center</h1>
        <div class="d-flex gap-2">
            <a href="?action=download_sample&csrf_token=<?= $csrf_token ?>" class="btn btn-secondary">📥 Download Sample Template</a>
            <a href="list.php" class="btn btn-secondary">Back to Bookings</a>
        </div>
    </div>
    
    <hr>

    <!-- Tabs Container -->
    <div style="margin-bottom: 0;">
        <button class="import-tab-btn active" onclick="switchTab('import')">📤 Bulk Import Center</button>
        <button class="import-tab-btn" onclick="switchTab('history')">📜 Import History Log</button>
    </div>

    <!-- Tab 1: Bulk Import Center -->
    <div id="tab-import" class="tab-content">
        <!-- Step 1: Upload Zone -->
        <div id="step-upload">
            <div class="cards-row" style="grid-template-columns: 2fr 1fr; align-items: start;">
                <div>
                    <div class="card">
                        <div class="upload-dragzone" id="drop-zone" onclick="document.getElementById('file-input').click()">
                            <span class="upload-icon">📤</span>
                            <h3>Drag & Drop Files Here</h3>
                            <p style="color: #64748b; font-size: 13px;">Supports Excel (.xlsx, .xls), CSV, PDF (multi-page ticket), Images (.jpg, .png) or ZIP archives containing ticket scans</p>
                            <span class="btn" style="padding: 8px 16px;">Browse Files</span>
                            <input type="file" id="file-input" style="display:none;" accept=".csv,.xlsx,.xls,.pdf,.zip,.jpg,.jpeg,.png" multiple>
                        </div>
                    </div>

                    <!-- Google Sheets Import Panel -->
                    <div class="card" style="margin-top: 20px;">
                        <h2 style="font-size: 14px; font-weight: 700; color: var(--accent-color); margin-bottom: 12px;">🔗 Import from Google Sheets</h2>
                        <p style="color: #64748b; font-size: 12px; margin-bottom: 12px;">Paste the link to your shared Google Sheet (set to "Anyone with the link can view") to load data.</p>
                        <div class="d-flex gap-2">
                            <input type="text" id="gss-url" placeholder="https://docs.google.com/spreadsheets/d/.../edit?usp=sharing" style="flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border-dark);">
                            <button onclick="fetchGoogleSheet()" style="padding: 10px 20px;">Fetch & Load</button>
                        </div>
                    </div>
                </div>

                <div class="card" style="background: #f8fafc; border-color: var(--border-dark);">
                    <h2 style="font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px;">💡 Instructions & Rules</h2>
                    <ul style="padding-left: 16px; font-size: 12px; line-height: 1.6; color: #475569; display: flex; flex-direction: column; gap: 8px;">
                        <li><strong>Excel/CSV Headers</strong>: Automatically maps if header names match PNR, Ticket, Passenger, Date, Customer. Otherwise, a manual mapper will display.</li>
                        <li><strong>PDF Extraction</strong>: Reads native PDF text data. For scanned image-only PDFs, runs AI OCR character extraction on each page.</li>
                        <li><strong>Image & ZIP uploads</strong>: Batches files automatically to extract passenger name, ticket number, PNR, and travel dates.</li>
                        <li><strong>Duplicate checks</strong>: Cross-references database for existing PNR, Ticket, and Passenger + Date. Prompts manual replacement.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Step 2: Column Mapping Screen -->
        <div id="step-mapping" style="display: none;">
            <div class="card">
                <h3 style="margin-bottom: 12px; color: var(--accent-color);">🗺️ Manual Column Mapping</h3>
                <p style="font-size: 13px; color: #64748b;">We couldn't automatically map all required columns. Please select which columns in your spreadsheet represent each field.</p>
                <div class="mapping-grid" id="mapping-fields">
                    <!-- Dynamic select dropdowns will be rendered here -->
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button class="btn btn-secondary" onclick="resetImport()">Cancel</button>
                    <button class="btn" onclick="applyMapping()">Apply Mapping & Preview</button>
                </div>
            </div>
        </div>

        <!-- Step 3: Import Progress -->
        <div id="step-progress" style="display: none;">
            <div class="card" style="text-align: center; padding: 40px 20px;">
                <h3 id="progress-status">Reading Uploaded Files...</h3>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" id="progress-bar"></div>
                    <div class="progress-label" id="progress-percentage">0%</div>
                </div>
                <p id="progress-detail" style="color: #64748b; font-size: 12px;"></p>
            </div>
        </div>

        <!-- Step 4: Preview & Edit -->
        <div id="step-preview" style="display: none;">
            <div class="card">
                <div class="d-flex justify-content-between align-items-center" style="margin-bottom: 12px;">
                    <div>
                        <h3 style="color: var(--accent-color); margin:0;">📋 Bulk Import Preview Table</h3>
                        <p style="font-size: 12px; color: #64748b; margin:0;">Double-click or click inside cells to manually edit and fix validation errors before database commit.</p>
                    </div>
                    <span id="preview-counts" class="badge badge-success" style="font-size: 13px;">0 Records Loaded</span>
                </div>
                
                <div id="preview-tables-container" style="display: flex; flex-direction: column; gap: 24px;"></div>

                <div class="d-flex gap-2 justify-content-end" style="margin-top: 20px;">
                    <button class="btn btn-secondary" onclick="resetImport()">Cancel & Clear</button>
                    <button class="btn btn-glow" id="btn-submit-import" onclick="executeImport()">Confirm & Import Bookings</button>
                </div>
            </div>
        </div>

        <!-- Step 5: Summary Report -->
        <div id="step-summary" style="display: none;">
            <div class="card" style="text-align: center; padding: 40px 20px;">
                <span style="font-size: 64px; color: #10b981;">🎉</span>
                <h2 style="margin-top: 12px; color: #0f766e;">Bulk Import Completed!</h2>
                <p style="color: #64748b; margin-bottom: 24px;">The bookings have been processed in a database transaction successfully.</p>
                
                <div class="cards-row" style="grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 24px; max-width: 800px; margin-left: auto; margin-right: auto;">
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid var(--border-dark);">
                        <h4 style="color: #64748b; font-size: 12px; margin:0;">Total Rows</h4>
                        <h2 id="sum-total" style="margin: 4px 0 0 0;">0</h2>
                    </div>
                    <div style="background: #ecfdf5; padding: 16px; border-radius: 8px; border: 1px solid #a7f3d0;">
                        <h4 style="color: #047857; font-size: 12px; margin:0;">Success</h4>
                        <h2 id="sum-imported" style="margin: 4px 0 0 0; color: #047857;">0</h2>
                    </div>
                    <div style="background: #fef3c7; padding: 16px; border-radius: 8px; border: 1px solid #fde68a;">
                        <h4 style="color: #b45309; font-size: 12px; margin:0;">Duplicates</h4>
                        <h2 id="sum-duplicates" style="margin: 4px 0 0 0; color: #b45309;">0</h2>
                    </div>
                    <div style="background: #fef2f2; padding: 16px; border-radius: 8px; border: 1px solid #fecaca;">
                        <h4 style="color: #b91c1c; font-size: 12px; margin:0;">Failed</h4>
                        <h2 id="sum-failed" style="margin: 4px 0 0 0; color: #b91c1c;">0</h2>
                    </div>
                    <div style="background: #f1f5f9; padding: 16px; border-radius: 8px; border: 1px solid #cbd5e1;">
                        <h4 style="color: #475569; font-size: 12px; margin:0;">Time Taken</h4>
                        <h2 id="sum-time" style="margin: 4px 0 0 0; font-size: 16px; padding: 6px 0;">0 ms</h2>
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-secondary" onclick="downloadImportReport()">📥 Download detailed CSV Report</button>
                    <button class="btn" onclick="resetImport()">Start New Import</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Import History Log -->
    <div id="tab-history" class="tab-content" style="display: none;">
        <div class="card">
            <h3 style="color: var(--accent-color); margin-bottom: 12px;">📜 System Import Logs</h3>
            <div style="overflow-x: auto;">
                <table class="preview-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Import ID</th>
                            <th>Date & Time</th>
                            <th>User</th>
                            <th>File Name</th>
                            <th>File Type</th>
                            <th>Total Records</th>
                            <th>Success</th>
                            <th>Duplicates</th>
                            <th>Failed</th>
                            <th>Proc. Time</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
                        <tr>
                            <td colspan="11" style="text-align: center; color: #64748b;">Loading history logs...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Import Details Modal -->
<div id="details-modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modal-title" style="color: var(--accent-color); margin-bottom: 8px;">Import Details</h3>
        <p id="modal-meta" style="font-size: 12px; color: #64748b; margin-bottom: 16px;"></p>
        
        <div style="overflow-y: auto; max-height: 400px; border: 1px solid var(--border-dark); border-radius: 8px;">
            <table class="preview-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Passenger Name</th>
                        <th>Ticket Number</th>
                        <th>PNR</th>
                        <th>Status</th>
                        <th>Log/Reason</th>
                    </tr>
                </thead>
                <tbody id="modal-tbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // State management variables
    const csrfToken = "<?= $csrf_token ?>";
    let importedRows = [];
    let headersList = [];
    let detectedMapping = {};
    let finalPayload = [];
    let currentFileName = "Manual/Direct";
    let currentFileType = "CSV/Excel";
    let currentSourceFilePath = '';
    let latestReportLog = [];

    // Switch between Import and History Tab
    function switchTab(tab) {
        document.querySelectorAll('.import-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(cont => cont.style.display = 'none');
        
        if (tab === 'import') {
            document.querySelector('.import-tab-btn:nth-child(1)').classList.add('active');
            document.getElementById('tab-import').style.display = 'block';
        } else {
            document.querySelector('.import-tab-btn:nth-child(2)').classList.add('active');
            document.getElementById('tab-history').style.display = 'block';
            loadImportHistory();
        }
    }

    // Drag and drop event listeners
    const dropZone = document.getElementById('drop-zone');
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        }, false);
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        }, false);
    });

    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        handleFiles(files);
    }, false);

    document.getElementById('file-input').addEventListener('change', function(e) {
        handleFiles(this.files);
    });

    // Master function handling file uploads
    function handleFiles(files) {
        if (files.length === 0) return;
        
        // Save metadata
        currentFileName = files[0].name;
        const ext = currentFileName.split('.').pop().toLowerCase();
        
        if (ext === 'xlsx' || ext === 'xls' || ext === 'csv') {
            currentFileType = ext.toUpperCase();
            readSpreadsheet(files[0]);
        } else if (ext === 'pdf') {
            currentFileType = 'PDF';
            readPdf(files[0]);
        } else if (ext === 'zip') {
            currentFileType = 'ZIP';
            readZip(files[0]);
        } else if (['png', 'jpg', 'jpeg'].includes(ext)) {
            currentFileType = 'IMAGE';
            readImages(files);
        } else {
            alert("Unsupported file type. Please upload a valid CSV, Excel, PDF, Image, or ZIP.");
        }
    }

    // Google Sheets link loader
    function fetchGoogleSheet() {
        const url = document.getElementById('gss-url').value.trim();
        if (!url) {
            alert("Please paste a valid Google Sheets URL first.");
            return;
        }

        showProgress("Connecting to Google Sheets...", 20);
        currentFileName = "Google Sheet Sync";
        currentFileType = "Google Sheet";

        const fd = new FormData();
        fd.append('url', url);
        fd.append('csrf_token', csrfToken);

        fetch('?action=fetch_google_sheet', {
            method: 'POST',
            body: fd
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // Parse CSV contents client-side using SheetJS
                const workbook = XLSX.read(data.csv_data, {type: 'string', cellDates: true});
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: ""});
                processSpreadsheetRows(rows);
            } else {
                resetImport();
                alert(data.message);
            }
        })
        .catch(err => {
            resetImport();
            alert("Error fetching Google Sheet: " + err.message);
        });
    }

    // Parse Excel and CSV files client-side
    function readSpreadsheet(file) {
        showProgress("Reading Spreadsheet File...", 10);
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array', cellDates: true});
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: ""});
                processSpreadsheetRows(rows);
            } catch (err) {
                resetImport();
                alert("Failed to parse Excel file: " + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    // Processes extracted Excel/CSV raw rows
    function processSpreadsheetRows(rows) {
        if (rows.length < 2) {
            resetImport();
            alert("Spreadsheet file must contain at least a header row and one data row.");
            return;
        }

        headersList = rows[0].map(h => String(h).trim());
        importedRows = rows.slice(1);

        // Required keys
        const required = ['passenger_name', 'customer_name', 'pnr', 'ticket_number', 'travel_date'];
        
        // Auto column mapping logic
        detectedMapping = {};
        const mappingKeywords = {
            passenger_name: ['passenger', 'passenger name', 'pax', 'pax name', 'name'],
            customer_name: ['customer', 'customer name', 'agency', 'agency name', 'client'],
            pnr: ['pnr', 'pnr code', 'booking ref', 'booking reference', 'airline pnr'],
            ticket_number: ['ticket', 'ticket number', 'ticket no', 'tkt', 'tkt no'],
            travel_date: ['travel date', 'departure date', 'date', 'travel_date', 'departure_date']
        };

        // Other optional columns we can capture automatically
        const optionalKeywords = {
            customer_type: ['customer type', 'type'],
            customer_mobile: ['mobile', 'phone', 'customer mobile', 'contact'],
            customer_email: ['email', 'customer email'],
            from_city: ['from', 'origin', 'from city'],
            to_city: ['to', 'destination', 'to city'],
            departure_time: ['time', 'dep time', 'departure time'],
            arrival_date: ['arrival date', 'arr date', 'arrival_date'],
            arrival_time: ['arrival time', 'arr time', 'arrival_time'],
            service_type: ['service type', 'service_type', 'service'],
            flight_number: ['flight', 'flight no', 'flight number'],
            airline_name: ['airline', 'carrier', 'airline name'],
            buying_cost: ['buy', 'buying cost', 'cost'],
            selling_cost: ['sell', 'selling cost', 'price'],
            supplier_name: ['supplier', 'vendor'],
            payment_method: ['payment', 'payment method'],
            status: ['status'],
            remarks: ['remarks', 'notes', 'comment']
        };

        // Check exact match
        required.forEach(field => {
            const index = headersList.findIndex(h => mappingKeywords[field].includes(h.toLowerCase()));
            if (index !== -1) detectedMapping[field] = index;
        });

        // Scan optional fields
        Object.keys(optionalKeywords).forEach(field => {
            const index = headersList.findIndex(h => optionalKeywords[field].includes(h.toLowerCase()));
            if (index !== -1) detectedMapping[field] = index;
        });

        // Check if all required fields are mapped
        const missingRequired = required.filter(f => detectedMapping[f] === undefined);
        if (missingRequired.length > 0) {
            // Render manual mapping screen
            showMappingScreen(missingRequired);
        } else {
            // Proceed to duplicate check & preview
            applyMapping();
        }
    }

    // Renders manual column mapping dropdowns
    function showMappingScreen(missingFields) {
        document.getElementById('step-upload').style.display = 'none';
        document.getElementById('step-progress').style.display = 'none';
        document.getElementById('step-mapping').style.display = 'block';

        const container = document.getElementById('mapping-fields');
        container.innerHTML = '';

        const fieldsToMap = ['passenger_name', 'customer_name', 'pnr', 'ticket_number', 'travel_date'];
        const labels = {
            passenger_name: 'Passenger Name *',
            customer_name: 'Customer / Agency Name *',
            pnr: 'PNR Code *',
            ticket_number: 'Ticket Number *',
            travel_date: 'Travel Date (Departure Date) *'
        };

        fieldsToMap.forEach(field => {
            const formGroup = document.createElement('div');
            formGroup.className = 'form-group';
            
            const label = document.createElement('label');
            label.textContent = labels[field];
            
            const select = document.createElement('select');
            select.id = 'map-' + field;
            select.className = 'form-control';
            select.innerHTML = '<option value="">-- Choose Column --</option>';

            headersList.forEach((header, index) => {
                const isSelected = detectedMapping[field] === index ? 'selected' : '';
                select.innerHTML += `<option value="${index}" ${isSelected}>Column ${index + 1}: ${header}</option>`;
            });

            formGroup.appendChild(label);
            formGroup.appendChild(select);
            container.appendChild(formGroup);
        });
    }

    // Applies column configurations and checks database constraints
    function applyMapping() {
        const required = ['passenger_name', 'customer_name', 'pnr', 'ticket_number', 'travel_date'];
        
        // If manual screen was active, read selections
        if (document.getElementById('step-mapping').style.display === 'block') {
            let hasError = false;
            required.forEach(field => {
                const val = document.getElementById('map-' + field).value;
                if (val === '') {
                    alert('Please map all required columns before proceeding.');
                    hasError = true;
                    return;
                }
                detectedMapping[field] = parseInt(val);
            });
            if (hasError) return;
        }

        document.getElementById('step-mapping').style.display = 'none';
        showProgress("Normalizing and Validating Row Cells...", 40);

        // Convert raw rows to payload structure
        finalPayload = importedRows.map(row => {
            const record = {};
            
            // Required columns
            record.passenger_name = cleanCellVal(row[detectedMapping.passenger_name]);
            record.customer_name = cleanCellVal(row[detectedMapping.customer_name]);
            record.pnr = cleanCellVal(row[detectedMapping.pnr]).toUpperCase();
            record.ticket_number = cleanCellVal(row[detectedMapping.ticket_number]).replace(/[^0-9]/g, '');
            record.travel_date = formatExcelDate(row[detectedMapping.travel_date]);

            // Optional columns
            const optionalFields = [
                'customer_type', 'customer_mobile', 'customer_email', 'from_city', 'to_city', 
                'departure_time', 'arrival_date', 'arrival_time', 'service_type', 'flight_number', 'airline_name', 'buying_cost', 'selling_cost', 
                'supplier_name', 'payment_method', 'status', 'remarks'
            ];

            optionalFields.forEach(field => {
                if (detectedMapping[field] !== undefined) {
                    let val = row[detectedMapping[field]];
                    if (field === 'buying_cost' || field === 'selling_cost') {
                        val = parseFloat(String(val).replace(/[^0-9.]/g, '')) || 0;
                    } else if (field === 'arrival_date') {
                        val = formatExcelDate(val);
                    } else {
                        val = cleanCellVal(val);
                    }
                    record[field] = val;
                } else {
                    // Default values
                    if (field === 'customer_type') record.customer_type = 'Walk-in Customer';
                    else if (field === 'buying_cost' || field === 'selling_cost') record[field] = 0;
                    else if (field === 'status') record.status = 'Booked';
                    else if (field === 'payment_method') record.payment_method = 'Cash';
                    else if (field === 'service_type') record.service_type = 'Flight';
                    else record[field] = '';
                }
            });

            return record;
        });

        // Run validation & duplicate check server-side in batches
        checkDuplicatesAndValidateServer();
    }

    // Helper functions for cell formatting
    function cleanCellVal(val) {
        if (val === undefined || val === null) return '';
        return String(val).trim();
    }

    function formatExcelDate(val) {
        if (!val) return '';
        if (val instanceof Date) {
            return val.toISOString().split('T')[0];
        }
        const str = String(val).trim();
        // check if YYYY-MM-DD
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;
        // Try standard parse
        const ts = Date.parse(str);
        if (!isNaN(ts)) {
            return new Date(ts).toISOString().split('T')[0];
        }
        return str; // return raw, server validation will catch it
    }

    // PDF processing client-side & server-side layout parsing
    async function readPdf(file) {
        showProgress("Extracting layout text from PDF...", 10);
        finalPayload = [];
        currentSourceFilePath = '';
        let directPdfError = null;
        
        try {
            // First attempt: Server-side native extraction (preserves columns and layout)
            await uploadOcrFileToServer(file, file.name);
            
            if (finalPayload.length > 0) {
                showProgress("Validating extracted PDF bookings...", 90);
                checkDuplicatesAndValidateServer();
                return;
            }
        } catch (err) {
            directPdfError = err;
            currentSourceFilePath = err.filePath || '';
            console.warn("Direct PDF upload failed, falling back to page rendering: ", err);
        }
        
        // Second attempt fallback: Browser-side rendering & OCR for scanned PDFs
        showProgress("Analyzing PDF pages for scanned content...", 25);
        const reader = new FileReader();
        reader.onload = async function() {
            try {
                if (!window.pdfjsLib) {
                    throw new Error((directPdfError ? directPdfError.message + ' ' : '') + 'The PDF rendering library could not be loaded.');
                }
                const typedarray = new Uint8Array(this.result);
                const pdf = await pdfjsLib.getDocument(typedarray).promise;
                const numPages = pdf.numPages;
                
                showProgress(`Processing 1 of ${numPages} PDF page(s)...`, 30);

                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    const progress = Math.round(30 + (pageNum / numPages) * 60);
                    showProgress(`Rendering and OCR on PDF Page ${pageNum} of ${numPages}...`, progress);

                    const page = await pdf.getPage(pageNum);
                    const textContent = await page.getTextContent();
                    const pageText = textContent.items.map(item => item.str).join(" ");
                    
                    if (pageText.trim().length > 50) {
                        // Digital PDF page fallback: post page text directly
                        await processPdfPageText(pageText, pageNum);
                    } else {
                        // Scanned PDF: Render page to canvas, convert to blob, and send to OCR API
                        const viewport = page.getViewport({scale: 2.0});
                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        await page.render({canvasContext: context, viewport: viewport}).promise;
                        
                        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                        await uploadOcrFileToServer(blob, `page_${pageNum}.jpg`);
                    }
                }

                if (finalPayload.length === 0) {
                    throw new Error("No bookings extracted from PDF.");
                }
                checkDuplicatesAndValidateServer();

            } catch (err) {
                resetImport();
                alert("Failed to process PDF: " + err.message);
            }
        };
        reader.onerror = function() {
            resetImport();
            alert("Failed to process PDF: The selected file could not be read.");
        };
        reader.readAsArrayBuffer(file);
    }

    // Digital PDF text parser caller
    async function processPdfPageText(text, pageNum) {
        const blob = new Blob([text], {type: 'text/plain'});
        return uploadOcrFileToServer(blob, `page_${pageNum}.txt`);
    }

    // Parse ZIP archives containing multiple ticket images
    function readZip(file) {
        showProgress("Extracting ZIP archive...", 15);
        JSZip.loadAsync(file).then(async function(zip) {
            const files = [];
            zip.forEach(function (relativePath, zipEntry) {
                if (!zipEntry.dir && /\.(png|jpe?g)$/i.test(zipEntry.name)) {
                    files.push(zipEntry);
                }
            });
            if (files.length === 0) {
                resetImport();
                alert("No supported images (.png, .jpg, .jpeg) found in ZIP archive.");
                return;
            }

            finalPayload = [];
            const count = files.length;
            
            for (let i = 0; i < count; i++) {
                const entry = files[i];
                const progress = Math.round(15 + ((i + 1) / count) * 75);
                showProgress(`Extracting and OCR processing ZIP image ${i+1} of ${count}...`, progress);

                const blob = await entry.async("blob");
                await uploadOcrFileToServer(blob, entry.name);
            }

            checkDuplicatesAndValidateServer();
        }).catch(err => {
            resetImport();
            alert("Failed to read ZIP file: " + err.message);
        });
    }

    // Process multiple images uploaded
    async function readImages(files) {
        finalPayload = [];
        const count = files.length;
        for (let i = 0; i < count; i++) {
            const progress = Math.round(10 + ((i + 1) / count) * 80);
            showProgress("OCR Processing Image " + (i+1) + " of " + count + "...", progress);
            await uploadOcrFileToServer(files[i], files[i].name);
        }
        checkDuplicatesAndValidateServer();
    }

    // Core function to upload image blobs to Tesseract OCR endpoint
    async function uploadOcrFileToServer(blob, filename) {
        const fd = new FormData();
        fd.append('file', blob, filename);
        fd.append('csrf_token', csrfToken);

        const res = await fetch('?action=ocr_process', {
            method: 'POST',
            body: fd
        });
        const responseText = await res.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (err) {
            throw new Error(`Server returned an invalid OCR response for ${filename}.`);
        }
        if (!res.ok || data.status !== 'success') {
            const error = new Error(data.message || `OCR failed for ${filename}.`);
            error.filePath = data.file_path || '';
            throw error;
        }
        const tickets = Array.isArray(data.tickets) ? data.tickets : [];
        tickets.forEach(ticket => {
            if (currentFileType === 'PDF' && currentSourceFilePath) {
                ticket.file_path = currentSourceFilePath;
            }
            finalPayload.push(ticket);
        });
        return tickets.length;
    }

    // Batch validation and duplicate checker on the server
    function checkDuplicatesAndValidateServer() {
        showProgress("Verifying records & duplicate analysis...", 80);

        fetch('?action=check_duplicates_and_validate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({records: finalPayload})
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // Merge validation results into payload
                data.results.forEach(res => {
                    const idx = res.index;
                    finalPayload[idx].valid = res.valid;
                    finalPayload[idx].errors = res.errors;
                    finalPayload[idx].is_duplicate = res.is_duplicate;
                    finalPayload[idx].duplicate_reason = res.duplicate_reason;
                    finalPayload[idx].existing_id = res.existing_id;
                    finalPayload[idx].existing_details = res.existing_details;
                    
                    // Set default action for duplicates
                    finalPayload[idx].action = res.is_duplicate ? 'skip' : 'insert';
                });
                renderPreviewTable();
            } else {
                resetImport();
                alert("Validation error: " + data.message);
            }
        })
        .catch(err => {
            resetImport();
            alert("Failed to validate records: " + err.message);
        });
    }

    // Renders the editable preview table
    function renderPreviewTable() {
        document.getElementById('step-upload').style.display = 'none';
        document.getElementById('step-mapping').style.display = 'none';
        document.getElementById('step-progress').style.display = 'none';
        document.getElementById('step-preview').style.display = 'block';

        const container = document.getElementById('preview-tables-container');
        container.innerHTML = '';

        document.getElementById('preview-counts').textContent = finalPayload.length + " Records Loaded";

        // Group rows by service_type
        const groups = {};
        const documentServiceLabels = {
            ticket: 'Flight',
            passport: 'Passport',
            visa: 'Visa',
            hotel: 'Voucher',
            insurance: 'Insurance',
            package: 'Holiday Package',
            other: 'Other'
        };
        finalPayload.forEach((row, index) => {
            const detectedDocumentType = String(row.document_type || '').toLowerCase();
            const st = documentServiceLabels[detectedDocumentType] || row.service_type || 'Other';
            row.service_type = st;
            if (!groups[st]) groups[st] = [];
            groups[st].push({row, index});
        });

        // Loop through each service type group
        Object.keys(groups).forEach(serviceType => {
            const items = groups[serviceType];
            if (items.length === 0) return;

            const groupDiv = document.createElement('div');
            groupDiv.className = 'group-section';
            groupDiv.style.marginBottom = '24px';

            const groupTitle = document.createElement('h4');
            groupTitle.style.color = 'var(--accent-color)';
            groupTitle.style.marginBottom = '12px';
            groupTitle.style.fontSize = '15px';
            groupTitle.style.fontWeight = 'bold';
            groupTitle.style.display = 'flex';
            groupTitle.style.justifyContent = 'space-between';
            groupTitle.innerHTML = `<span>📂 ${serviceType} Bookings (${items.length})</span>`;
            groupDiv.appendChild(groupTitle);

            const tableWrap = document.createElement('div');
            tableWrap.style.overflowX = 'auto';
            tableWrap.style.border = '1px solid var(--border-dark)';
            tableWrap.style.borderRadius = '8px';

            const table = document.createElement('table');
            table.className = 'preview-table';

            let headers = '';
            if (serviceType === 'Passport') {
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Passport No *</th>
                        <th>Type</th>
                        <th>Issuing Country *</th>
                        <th>Country Code</th>
                        <th>Full Name *</th>
                        <th>Customer Name *</th>
                        <th>Surname</th>
                        <th>Given Names</th>
                        <th>Nationality *</th>
                        <th>Gender</th>
                        <th>DOB *</th>
                        <th>Place of Birth</th>
                        <th>Issue Date *</th>
                        <th>Expiry Date *</th>
                        <th>Place of Issue</th>
                        <th>Authority</th>
                        <th>MRZ Line 1</th>
                        <th>MRZ Line 2</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            } else if (serviceType === 'Visa') {
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Full Name *</th>
                        <th>Customer Name</th>
                        <th>Passport No *</th>
                        <th>UID/App No</th>
                        <th>Visa Type *</th>
                        <th>Country *</th>
                        <th>Issue Date *</th>
                        <th>Valid Until *</th>
                        <th>Entry Type</th>
                        <th>Duration</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            } else if (serviceType === 'Voucher') {
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Guest Names *</th>
                        <th>Customer Name</th>
                        <th>Confirmation No *</th>
                        <th>Hotel Name *</th>
                        <th>City *</th>
                        <th>Country</th>
                        <th>Check-in *</th>
                        <th>Check-out *</th>
                        <th>Nights</th>
                        <th>Rooms</th>
                        <th>Meal Plan</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            } else if (serviceType === 'Holiday Package') {
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Guest Names *</th>
                        <th>Customer Name</th>
                        <th>Voucher No *</th>
                        <th>Package Name *</th>
                        <th>Destinations *</th>
                        <th>Country</th>
                        <th>Start Date *</th>
                        <th>End Date *</th>
                        <th>Days</th>
                        <th>Nights</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            } else if (serviceType === 'Insurance') {
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Insured Name *</th>
                        <th>Customer Name</th>
                        <th>Policy No *</th>
                        <th>Provider *</th>
                        <th>Passport No</th>
                        <th>Destination</th>
                        <th>Start Date *</th>
                        <th>End Date *</th>
                        <th>Days</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            } else {
                // Default: Flight / Other
                headers = `
                    <tr>
                        <th>#</th>
                        <th>Passenger Name *</th>
                        <th>Customer Name</th>
                        <th>PNR *</th>
                        <th>Ticket Number *</th>
                        <th>Dep Date *</th>
                        <th>Dep Time</th>
                        <th>Arr Date</th>
                        <th>Arr Time</th>
                        <th>Route (From-To)</th>
                        <th>Flight / Airline</th>
                        <th>Cost (Buy/Sell)</th>
                        <th>Validation</th>
                        <th>Duplicate / Action</th>
                    </tr>
                `;
            }

            table.innerHTML = `<thead>${headers}</thead>`;
            const tbody = document.createElement('tbody');

            items.forEach((item, itemIdx) => {
                const row = item.row;
                const index = item.index;

                const tr = document.createElement('tr');
                if (!row.valid) tr.className = 'has-error';
                else if (row.is_duplicate) tr.className = 'has-dup';

                // Validation Badge
                let valBadge = `<span class="badge badge-success">✓ Valid</span>`;
                if (!row.valid) {
                    valBadge = `<span class="badge badge-danger" title="${row.errors.join(', ')}">⚠ ${row.errors[0]}</span>`;
                }

                // Duplicate / Action Select
                let dupAction = `None`;
                if (row.is_duplicate) {
                    dupAction = `
                        <div style="font-size: 11px; margin-bottom: 4px; color: #92400e;">
                            <strong>Duplicate</strong> (${row.duplicate_reason})<br>
                            <a href="#" onclick="alert('Existing Booking: \\n${row.existing_details}'); return false;" style="text-decoration: underline;">View Existing</a>
                        </div>
                        <select onchange="updateRowAction(${index}, this.value)" style="padding: 4px; font-size: 11px; border-radius: 4px; border: 1px solid #d97706; background: #fff;">
                            <option value="skip" ${row.action === 'skip' ? 'selected' : ''}>Skip (Default)</option>
                            <option value="replace" ${row.action === 'replace' ? 'selected' : ''}>Replace (Overwrite)</option>
                        </select>
                    `;
                } else if (!row.valid) {
                    dupAction = `<span class="badge badge-danger">Cannot Import</span>`;
                } else {
                    dupAction = `<span class="badge badge-success">New Insert</span>`;
                }

                let cellsHtml = '';

                if (serviceType === 'Passport') {
                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_number', this.innerText)">${escapeHtml(row.passport_number || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_type', this.innerText)">${escapeHtml(row.passport_type || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_issuing_country', this.innerText)">${escapeHtml(row.passport_issuing_country || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_country_code', this.innerText)">${escapeHtml(row.passport_country_code || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_full_name', this.innerText)">${escapeHtml(row.passport_full_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_surname', this.innerText)">${escapeHtml(row.passport_surname || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_given_names', this.innerText)">${escapeHtml(row.passport_given_names || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_nationality', this.innerText)">${escapeHtml(row.passport_nationality || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_gender', this.innerText)">${escapeHtml(row.passport_gender || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_date_of_birth', this.innerText)">${escapeHtml(row.passport_date_of_birth || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_place_of_birth', this.innerText)">${escapeHtml(row.passport_place_of_birth || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_date_of_issue', this.innerText)">${escapeHtml(row.passport_date_of_issue || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_date_of_expiry', this.innerText)">${escapeHtml(row.passport_date_of_expiry || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_place_of_issue', this.innerText)">${escapeHtml(row.passport_place_of_issue || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_authority', this.innerText)">${escapeHtml(row.passport_authority || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_mrz_line1', this.innerText)">${escapeHtml(row.passport_mrz_line1 || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passport_mrz_line2', this.innerText)">${escapeHtml(row.passport_mrz_line2 || '')}</td>
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                } else if (serviceType === 'Visa') {
                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_full_name', this.innerText)">${escapeHtml(row.visa_full_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_passport_no', this.innerText)">${escapeHtml(row.visa_passport_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_uid_no', this.innerText)">${escapeHtml(row.visa_uid_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_type', this.innerText)">${escapeHtml(row.visa_type || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_country', this.innerText)">${escapeHtml(row.visa_country || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_submission_date', this.innerText)">${escapeHtml(row.visa_submission_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_delivery_date', this.innerText)">${escapeHtml(row.visa_delivery_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_entry_type', this.innerText)">${escapeHtml(row.visa_entry_type || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'visa_duration_days', this.innerText)">${escapeHtml(row.visa_duration_days || '0')}</td>
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                } else if (serviceType === 'Voucher') {
                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_guest_names', this.innerText)">${escapeHtml(row.hotel_guest_names || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_confirmation_no', this.innerText)">${escapeHtml(row.hotel_confirmation_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_name', this.innerText)">${escapeHtml(row.hotel_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_city', this.innerText)">${escapeHtml(row.hotel_city || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_country', this.innerText)">${escapeHtml(row.hotel_country || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_check_in', this.innerText)">${escapeHtml(row.hotel_check_in || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_check_out', this.innerText)">${escapeHtml(row.hotel_check_out || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_nights_count', this.innerText)">${escapeHtml(row.hotel_nights_count || '0')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_rooms_count', this.innerText)">${escapeHtml(row.hotel_rooms_count || '0')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'hotel_meal_plan', this.innerText)">${escapeHtml(row.hotel_meal_plan || '')}</td>
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                } else if (serviceType === 'Holiday Package') {
                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_guest_names', this.innerText)">${escapeHtml(row.package_guest_names || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_voucher_no', this.innerText)">${escapeHtml(row.package_voucher_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_name', this.innerText)">${escapeHtml(row.package_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_destinations', this.innerText)">${escapeHtml(row.package_destinations || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_country', this.innerText)">${escapeHtml(row.package_country || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_start_date', this.innerText)">${escapeHtml(row.package_start_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_end_date', this.innerText)">${escapeHtml(row.package_end_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_days_count', this.innerText)">${escapeHtml(row.package_days_count || '0')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'package_nights_count', this.innerText)">${escapeHtml(row.package_nights_count || '0')}</td>
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                } else if (serviceType === 'Insurance') {
                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_insured_name', this.innerText)">${escapeHtml(row.insurance_insured_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_policy_no', this.innerText)">${escapeHtml(row.insurance_policy_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_provider', this.innerText)">${escapeHtml(row.insurance_provider || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_passport_no', this.innerText)">${escapeHtml(row.insurance_passport_no || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_destination', this.innerText)">${escapeHtml(row.insurance_destination || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_start_date', this.innerText)">${escapeHtml(row.insurance_start_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_end_date', this.innerText)">${escapeHtml(row.insurance_end_date || '')}</td>
                        <td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'insurance_days', this.innerText)">${escapeHtml(row.insurance_days || '0')}</td>
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                } else {
                    // Flight / Other
                    const passengerCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'passenger_name', this.innerText)">${escapeHtml(row.passenger_name)}</td>`;
                    const customerCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'customer_name', this.innerText)">${escapeHtml(row.customer_name)}</td>`;
                    const pnrCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'pnr', this.innerText)">${escapeHtml(row.pnr)}</td>`;
                    const ticketCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'ticket_number', this.innerText)">${escapeHtml(row.ticket_number)}</td>`;
                    const dateCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'travel_date', this.innerText)">${escapeHtml(row.travel_date)}</td>`;
                    
                    const depTimeCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'departure_time', this.innerText)">${escapeHtml(row.departure_time || '')}</td>`;
                    const arrDateCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'arrival_date', this.innerText)">${escapeHtml(row.arrival_date || '')}</td>`;
                    const arrTimeCell = `<td class="cell-editable" contenteditable="true" onblur="updateRowField(${index}, 'arrival_time', this.innerText)">${escapeHtml(row.arrival_time || '')}</td>`;
                    const routeCell = `<td>${escapeHtml(row.from_city)} - ${escapeHtml(row.to_city)}</td>`;
                    const flightCell = `<td>${escapeHtml(row.flight_number)} / ${escapeHtml(row.airline_name)}</td>`;
                    const costCell = `<td>B: ${row.buying_cost} / S: ${row.selling_cost}</td>`;

                    cellsHtml = `
                        <td>${itemIdx + 1}</td>
                        ${passengerCell}
                        ${customerCell}
                        ${pnrCell}
                        ${ticketCell}
                        ${dateCell}
                        ${depTimeCell}
                        ${arrDateCell}
                        ${arrTimeCell}
                        ${routeCell}
                        ${flightCell}
                        ${costCell}
                        <td>${valBadge}</td>
                        <td>${dupAction}</td>
                    `;
                }

                tr.innerHTML = cellsHtml;
                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            tableWrap.appendChild(table);
            groupDiv.appendChild(tableWrap);
            container.appendChild(groupDiv);
        });
    }

    // Event updates from cell editing
    function updateRowField(index, field, value) {
        const row = finalPayload[index];
        row[field] = value.trim();

        if (field === 'passport_full_name') row.passenger_name = row.passport_full_name;
        if (field === 'passport_number') {
            row.ticket_number = row.passport_number;
            row.pnr = row.passport_number;
        }
        if (field === 'passport_date_of_issue') row.travel_date = row.passport_date_of_issue;

        if (field === 'visa_full_name') row.passenger_name = row.visa_full_name;
        if (field === 'visa_passport_no') row.ticket_number = row.visa_passport_no;
        if (field === 'visa_submission_date') row.travel_date = row.visa_submission_date;

        if (field === 'hotel_guest_names') row.passenger_name = row.hotel_guest_names;
        if (field === 'hotel_confirmation_no') {
            row.ticket_number = row.hotel_confirmation_no;
            row.pnr = row.hotel_confirmation_no;
        }
        if (field === 'hotel_check_in') row.travel_date = row.hotel_check_in;

        if (field === 'insurance_insured_name') row.passenger_name = row.insurance_insured_name;
        if (field === 'insurance_policy_no') {
            row.ticket_number = row.insurance_policy_no;
            row.pnr = row.insurance_policy_no;
        }
        if (field === 'insurance_start_date') row.travel_date = row.insurance_start_date;

        if (field === 'package_guest_names') row.passenger_name = row.package_guest_names;
        if (field === 'package_voucher_no') {
            row.ticket_number = row.package_voucher_no;
            row.pnr = row.package_voucher_no;
        }
        if (field === 'package_start_date') row.travel_date = row.package_start_date;

        // Re-validate row after changes
        revalidateRow(index);
    }

    function updateRowAction(index, actionVal) {
        finalPayload[index].action = actionVal;
    }

    // Re-evaluates a row client-side and triggers a partial re-validation UI refresh
    function revalidateRow(index) {
        const row = finalPayload[index];
        // The server validates against row.document_type. A passport number must
        // never be revalidated with the flight-only PNR/ticket-number rules.
        fetch('?action=check_duplicates_and_validate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({records: [row]})
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const res = data.results[0];
                finalPayload[index].valid = res.valid;
                finalPayload[index].errors = res.errors;
                finalPayload[index].is_duplicate = res.is_duplicate;
                finalPayload[index].duplicate_reason = res.duplicate_reason;
                finalPayload[index].existing_id = res.existing_id;
                finalPayload[index].existing_details = res.existing_details;
                
                // Keep chosen replace action if duplicate, otherwise default
                if (res.is_duplicate) {
                    if (finalPayload[index].action !== 'replace') {
                        finalPayload[index].action = 'skip';
                    }
                } else {
                    finalPayload[index].action = 'insert';
                }
                
                renderPreviewTable();
            }
        });
    }

    // Execute bulk import in transaction
    function executeImport() {
        // Filter out completely invalid rows that are not duplicates skipped
        const invalidRows = finalPayload.filter(row => !row.valid);
        if (invalidRows.length > 0) {
            alert("Please fix all validation errors before proceeding with import.");
            return;
        }

        const confirmImport = confirm("Are you sure you want to process these " + finalPayload.length + " bookings?");
        if (!confirmImport) return;

        showProgress("Writing transaction to database...", 90);

        fetch('?action=import_records', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                records: finalPayload,
                file_name: currentFileName,
                file_type: currentFileType
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                latestReportLog = finalPayload; // save for download report
                showSummaryScreen(data.summary);
            } else {
                document.getElementById('step-progress').style.display = 'none';
                document.getElementById('step-preview').style.display = 'block';
                alert("Import failed: " + data.message);
            }
        })
        .catch(err => {
            document.getElementById('step-progress').style.display = 'none';
            document.getElementById('step-preview').style.display = 'block';
            alert("Database Error during import: " + err.message);
        });
    }

    // Show import summary statistics
    function showSummaryScreen(sum) {
        document.getElementById('step-preview').style.display = 'none';
        document.getElementById('step-progress').style.display = 'none';
        document.getElementById('step-summary').style.display = 'block';

        document.getElementById('sum-total').textContent = sum.total;
        document.getElementById('sum-imported').textContent = sum.imported;
        document.getElementById('sum-duplicates').textContent = sum.duplicates;
        document.getElementById('sum-failed').textContent = sum.failed;
        document.getElementById('sum-time').textContent = sum.time_ms + " ms";
    }

    // Detailed import report generator
    function downloadImportReport() {
        let csv = "\xEF\xBB\xBFPassenger Name,Customer Name,PNR,Ticket Number,Service Type,Travel Date,Import Action,Status,Message\n";
        
        latestReportLog.forEach(row => {
            const action = row.action || 'insert';
            let status = 'Succeeded';
            let msg = action === 'replace' ? "Overwrote Booking #" + row.existing_id : 'Inserted Booking';
            
            if (action === 'skip') {
                status = 'Skipped';
                msg = "Duplicate detected: " + row.duplicate_reason;
            }

            csv += `"${escapeCsv(row.passenger_name)}","${escapeCsv(row.customer_name)}","${escapeCsv(row.pnr)}","${escapeCsv(row.ticket_number)}","${escapeCsv(row.service_type || 'Flight')}","${escapeCsv(row.travel_date)}","${action}","${status}","${msg}"\n`;
        });

        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", "Import_Report_" + new Date().toISOString().split('T')[0] + ".csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Fetch and load historical logs
    function loadImportHistory() {
        const tbody = document.getElementById('history-tbody');
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; color: #64748b;">Loading history logs...</td></tr>';

        fetch('?action=get_import_history&csrf_token=' + csrfToken)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                tbody.innerHTML = '';
                if (data.history.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; color: #64748b;">No import history logs found.</td></tr>';
                    return;
                }
                data.history.forEach(log => {
                    tbody.innerHTML += `
                        <tr>
                            <td>#${log.id}</td>
                            <td>${log.import_date}</td>
                            <td>${escapeHtml(log.username)}</td>
                            <td title="${escapeHtml(log.file_name)}">${escapeHtml(log.file_name.substring(0, 25))}${log.file_name.length > 25 ? '...' : ''}</td>
                            <td><span class="badge" style="background:#e2e8f0; color:#334155;">${escapeHtml(log.file_type)}</span></td>
                            <td><strong>${log.total_records}</strong></td>
                            <td><span class="badge badge-success">${log.imported_records}</span></td>
                            <td><span class="badge badge-warning">${log.duplicate_records}</span></td>
                            <td><span class="badge badge-danger">${log.failed_records}</span></td>
                            <td>${log.processing_time_ms} ms</td>
                            <td><button onclick="viewHistoryDetails(${log.id})" style="padding: 4px 8px; font-size:11px;">View Log</button></td>
                        </tr>
                    `;
                });
            }
        });
    }

    // Modal Details Loader
    function viewHistoryDetails(id) {
        const modal = document.getElementById('details-modal');
        const tbody = document.getElementById('modal-tbody');
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading details...</td></tr>';
        modal.style.display = 'block';

        fetch(`?action=get_import_details&id=${id}&csrf_token=${csrfToken}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('modal-title').textContent = "Import Details Log #" + id;
                tbody.innerHTML = '';
                
                data.details.forEach(item => {
                    let badgeClass = 'badge-success';
                    if (item.status === 'Skipped') badgeClass = 'badge-warning';
                    if (item.status === 'Failed') badgeClass = 'badge-danger';

                    tbody.innerHTML += `
                        <tr>
                            <td>${escapeHtml(item.passenger_name)}</td>
                            <td>${escapeHtml(item.ticket_number)}</td>
                            <td>${escapeHtml(item.pnr)}</td>
                            <td><span class="badge ${badgeClass}">${item.status}</span></td>
                            <td>${escapeHtml(item.message)}</td>
                        </tr>
                    `;
                });
            }
        });
    }

    function closeModal() {
        document.getElementById('details-modal').style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('details-modal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    // Helper functions
    function showProgress(status, percent) {
        document.getElementById('step-upload').style.display = 'none';
        document.getElementById('step-mapping').style.display = 'none';
        document.getElementById('step-preview').style.display = 'none';
        document.getElementById('step-summary').style.display = 'none';
        document.getElementById('step-progress').style.display = 'block';

        document.getElementById('progress-status').textContent = status;
        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('progress-percentage').textContent = percent + '%';
    }

    function resetImport() {
        document.getElementById('file-input').value = '';
        document.getElementById('gss-url').value = '';
        importedRows = [];
        headersList = [];
        detectedMapping = {};
        finalPayload = [];

        document.getElementById('step-mapping').style.display = 'none';
        document.getElementById('step-preview').style.display = 'none';
        document.getElementById('step-progress').style.display = 'none';
        document.getElementById('step-summary').style.display = 'none';
        document.getElementById('step-upload').style.display = 'block';
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function escapeCsv(text) {
        if (!text) return '';
        return String(text).replace(/"/g, '""');
    }
</script>
</body>
</html>
