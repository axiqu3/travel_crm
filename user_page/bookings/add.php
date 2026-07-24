<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();
require_once(__DIR__ . "/../../includes/enquiry_workflow.php");
require_once(__DIR__ . "/../../includes/customer_search.php");

$max_serial_res = mysqli_query($db, "SELECT MAX(CAST(serial_no AS UNSIGNED)) AS max_serial FROM bookings WHERE serial_no REGEXP '^[0-9]+$'");
$max_serial_row = mysqli_fetch_assoc($max_serial_res);
$max_serial_val = intval($max_serial_row['max_serial'] ?? 0);

$default_serial = $max_serial_val + 1;

$text = "";
$message = "";
$message_type = "";
$ticket_path = "";
$document_type = "";
$booking_return_to_dashboard = ($_GET['return_to'] ?? $_POST['return_to'] ?? '') === 'dashboard';
$booking_csrf_token = enquiry_csrf_token();
$booking_enquiry_id = (int) ($_POST['enquiry_id'] ?? $_GET['enquiry_id'] ?? 0);
$booking_enquiry = null;
$booking_enquiry_prefill = [];
if ($booking_enquiry_id > 0) {
    $booking_enquiry = enquiry_fetch($db, $booking_enquiry_id, true);
    if (!$booking_enquiry) {
        http_response_code(404);
        exit('Enquiry not found or access denied.');
    }
    if (!enquiry_can_convert($booking_enquiry['status'], $booking_enquiry['booking_id'] ?? null)) {
        http_response_code(409);
        exit('Only a Confirmed, unconverted enquiry can be converted to a booking.');
    }
    $booking_enquiry_prefill = [
        'customer_name' => $booking_enquiry['customer_name'] ?? '',
        'mobile' => $booking_enquiry['mobile'] ?? '',
        'email' => $booking_enquiry['email'] ?? '',
        'service_type' => $booking_enquiry['service_type'] ?? 'Other',
        'from_location' => $booking_enquiry['from_location'] ?? '',
        'to_location' => $booking_enquiry['to_location'] ?? '',
        'service_date' => $booking_enquiry['final_service_date'] ?: ($booking_enquiry['travel_date'] ?? ''),
        'passenger_count' => (int) ($booking_enquiry['passenger_count'] ?? 1),
        'remarks' => enquiry_clean_description($booking_enquiry['description'] ?? ''),
        'confirmation_note' => trim((string) ($booking_enquiry['confirmation_note'] ?? '')),
        'selling_cost' => $booking_enquiry['final_selling_amount'] ?? '',
        'assigned_user' => $booking_enquiry['assigned_user'] ?? '',
    ];
    $enquiry_document_types = [
        'Flight' => 'ticket',
        'Hotel' => 'hotel',
        'Visa' => 'visa',
        'Passport' => 'passport',
        'Insurance' => 'insurance',
        'Tour Package' => 'package',
        'Other' => 'other',
    ];
    $document_type = $enquiry_document_types[$booking_enquiry_prefill['service_type']] ?? 'other';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_GET['customer_name'] = $booking_enquiry_prefill['customer_name'];
        $_GET['mobile'] = $booking_enquiry_prefill['mobile'];
        $_GET['email'] = $booking_enquiry_prefill['email'];
        $_GET['customer_type'] = 'Walk-in Customer';
    }
}

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

function normalize_ticket_text($text, $preserve_layout = false) {
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

function clean_ocr_value($value) {
    $value = preg_replace('/[ \t]+/', ' ', trim($value));
    $value = preg_replace('/\s*(?:\||,|;)\s*$/', '', $value);
    return trim($value);
}

function document_value($text, $patterns) {
    if (empty($text)) return "";
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return clean_ocr_value($matches[1] ?? "");
        }
    }
    return "";
}

function document_group($text, $pattern, $group = 1) {
    if (empty($text)) return "";
    return preg_match($pattern, $text, $matches) ? clean_ocr_value($matches[$group] ?? "") : "";
}

function document_sum($text, $pattern) {
    if (!preg_match_all($pattern, $text, $matches)) return 0;
    return array_sum(array_map('intval', $matches[1]));
}

function document_count($text, $pattern) {
    return preg_match_all($pattern, $text, $matches) ?: 0;
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

function extract_hotel_document_fields($text) {
    $fields = [
        'confirmation_no' => '', 'name' => '', 'country' => '', 'city' => '',
        'address' => '', 'check_in' => '', 'check_out' => '', 'nights' => 0,
        'rooms' => 0, 'room_type' => '', 'meal_plan' => '', 'nationality' => '',
        'adults' => 0, 'children' => 0, 'guest_names' => '', 'booking_details' => ''
    ];
    if (trim((string) $text) === '') return $fields;

    $fields['confirmation_no'] = document_value($text, [
        '/Booking\s*(?:ID|No)\s*[:#-]?\s*([A-Z0-9-]+)/i',
        '/Voucher\s*(?:Number|No)\s*[:#-]?\s*([A-Z0-9-]+)/i'
    ]);
    $fields['address'] = document_value($text, ['/Address\s*[:#-]\s*([^\n]+)/i']);

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
                $fields['city'] = clean_ocr_value($match[1]);
                $fields['country'] = clean_ocr_value($match[2]);
                $location_index = $i;
                break;
            }
        }
        if ($location_index >= 0) {
            for ($i = $location_index - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '' || !preg_match('/[A-Za-z0-9]/', $line)) continue;
                if (preg_match('/^(?:[kK★*]\s*){2,}$/u', $line)) continue;
                $fields['name'] = clean_ocr_value($line);
                break;
            }
        }
    }

    if ($fields['name'] === '') {
        $fields['name'] = document_value($text, ['/Hotel\s*(?:Name)?\s*[:#-]\s*([^\n]+)/i']);
    }
    if ($fields['city'] === '') $fields['city'] = document_value($text, ['/City\s*[:#-]\s*([^\n]+)/i']);
    if ($fields['country'] === '') $fields['country'] = document_value($text, ['/Country\s*[:#-]\s*([^\n]+)/i']);

    $date_pattern = '\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}|\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2}';
    if (preg_match('/Check\s*[- ]?in(?:\s*Date)?[\s\S]{0,300}/i', $text, $window)
        && preg_match_all('/\b(' . $date_pattern . ')\b/i', $window[0], $dates)) {
        $fields['check_in'] = document_date($dates[1][0] ?? '');
        $fields['check_out'] = document_date($dates[1][1] ?? '');
    }
    if ($fields['check_in'] === '') {
        $fields['check_in'] = document_date(document_value($text, ['/Check\s*[- ]?in(?:\s*Date)?\s*[:#-]?\s*(' . $date_pattern . ')/i']));
    }
    if ($fields['check_out'] === '') {
        $fields['check_out'] = document_date(document_value($text, ['/Check\s*[- ]?out(?:\s*Date)?\s*[:#-]?\s*(' . $date_pattern . ')/i']));
    }

    $fields['nights'] = (int) document_value($text, ['/No\.?[ \t]*Of[ \t]*Nights[ \t]*[:#-]?[ \t]*(\d+)/i']);
    if (!$fields['nights'] && preg_match('/No\.?[ \t]*Of[ \t]*Nights[^\n]*\n[^\n]*?\b(\d+)\s*$/im', $text, $match)) {
        $fields['nights'] = (int) $match[1];
    }
    if (!$fields['nights'] && $fields['check_in'] && $fields['check_out']) {
        $fields['nights'] = max(0, (int) ((strtotime($fields['check_out']) - strtotime($fields['check_in'])) / 86400));
    }

    $fields['rooms'] = document_count($text, '/^\s*Room\s+\d+\b/mi');
    if (!$fields['rooms']) $fields['rooms'] = (int) document_value($text, ['/No\.?\s*of\s*Rooms\s*[:#-]?\s*(\d+)/i']);
    $fields['nationality'] = document_value($text, ['/Nationality\s*:\s*([A-Za-z ]+?)(?=\s+\d+\s*Adults?\b|\r?$)/im']);
    $fields['adults'] = document_sum($text, '/\b(\d+)\s*Adults?\b/i');
    $fields['children'] = document_sum($text, '/\b(\d+)\s*Child(?:ren)?\b/i');
    $fields['meal_plan'] = document_value($text, [
        '/Meal\s*Plan\s*[:#-]?\s*([^\n]+)/i',
        '/\((Room Only|Breakfast|Half Board|Full Board|All Inclusive)\)/i'
    ]);

    foreach ($lines as $line) {
        $candidate = clean_ocr_value($line);
        if (preg_match('/\b(?:single|double|twin|triple|quad|suite|studio|villa|apartment)\b.*\broom\b|\broom\b.*\b(?:single|double|twin|triple|quad|executive|deluxe|standard|superior)\b/i', $candidate)) {
            if (preg_match('/^Room\s+\d+\b/i', $candidate) || preg_match('/^\(?Room Only\)?$/i', $candidate)) continue;
            $fields['room_type'] = trim(preg_replace('/\s+Adults?\s+Name.*$/i', '', $candidate));
            break;
        }
    }
    if ($fields['room_type'] === '') {
        $fields['room_type'] = document_value($text, ['/Room\s*Type\s*[:#-]?\s*([^\n]+)/i']);
    }

    $guests = [];
    foreach ($lines as $line) {
        $candidate = clean_ocr_value($line);
        if (preg_match('/^(?:Mrs|Mr|Ms|Miss|Mstr)\.?\s+[A-Za-z][A-Za-z .\-]{2,80}$/i', $candidate)) {
            $guests[] = $candidate;
        }
    }
    $fields['guest_names'] = implode(', ', array_values(array_unique($guests)));
    if ($fields['guest_names'] === '') {
        $fields['guest_names'] = document_value($text, ['/Guest\s*Names?\s*[:#-]?\s*([^\n]+)/i']);
    }
    $detail_parts = [];
    if ($fields['nationality'] !== '') $detail_parts[] = 'Nationality: ' . $fields['nationality'];
    if (preg_match('/\bOffer\s*:\s*([^\r\n]+)/i', $text, $match)) {
        $detail_parts[] = 'Offer: ' . clean_ocr_value($match[1]);
    }
    $fields['booking_details'] = implode('; ', $detail_parts);

    return $fields;
}

function extract_passport_document_fields($text) {
    $layout = extract_layout_fields($text);
    $value = function($patterns, $layout_keys) use ($text, $layout) {
        foreach ($layout_keys as $lk) {
            if (isset($layout[$lk]) && $layout[$lk] !== '') {
                return $layout[$lk];
            }
        }
        return document_value($text, $patterns);
    };

    $fields = [
        'number' => $value(['/Passport\s*(?:No\.?|Number)\s*[:#-]?\s*([A-Z0-9<]{5,20})/i'], ['Passport Number', 'Passport No', 'Passport No.']),
        'type' => $value(['/Passport\s*Type\s*[:#-]?\s*([^\n]+)/i', '/Type\s*[:#-]?\s*([A-Z]{1,3})\b/i'], ['Passport Type', 'Type']),
        'issuing_country' => $value(['/Issuing\s*Country\s*[:#-]?\s*([^\n]+)/i', '/Country\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i'], ['Issuing Country', 'Country of Issue', 'Issuing State']),
        'country_code' => $value(['/Country\s*Code\s*[:#-]?\s*([A-Z]{3})\b/i'], ['Country Code', 'Country Code / Code de l\'état']),
        'full_name' => $value(['/Full\s*Name\s*[:#-]?\s*([^\n]+)/i'], ['Full Name', 'Name']),
        'surname' => $value(['/Surname\s*[:#-]?\s*([^\n]+)/i'], ['Surname', 'Surname / Nom']),
        'given_names' => $value(['/Given\s*Names?\s*[:#-]?\s*([^\n]+)/i'], ['Given Names', 'Given Name', 'Given Names / Prénoms']),
        'nationality' => $value(['/Nationality\s*[:#-]?\s*([^\n]+)/i'], ['Nationality', 'Nationalité']),
        'gender' => $value(['/(?:Sex|Gender)\s*[:#-]?\s*(Male|Female|M|F|X)\b/i'], ['Sex', 'Gender', 'Sexe']),
        'date_of_birth' => document_date($value(['/(?:Date\s*of\s*Birth|DOB)\s*[:#-]?\s*([^\n]+)/i'], ['Date of Birth', 'DOB', 'Date de naissance'])),
        'place_of_birth' => $value(['/Place\s*of\s*Birth\s*[:#-]?\s*([^\n]+)/i'], ['Place of Birth', 'Lieu de naissance']),
        'date_of_issue' => document_date($value(['/Date\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i', '/Issue\s*Date\s*[:#-]?\s*([^\n]+)/i'], ['Date of Issue', 'Issue Date', 'Date de délivrance'])),
        'date_of_expiry' => document_date($value(['/(?:Date\s*of\s*Expiry|Expiry\s*Date|Expiration\s*Date)\s*[:#-]?\s*([^\n]+)/i'], ['Date of Expiry', 'Expiry Date', 'Date d\'expiration'])),
        'place_of_issue' => $value(['/Place\s*of\s*Issue\s*[:#-]?\s*([^\n]+)/i'], ['Place of Issue']),
        'authority' => $value(['/Authority\s*[:#-]?\s*([^\n]+)/i'], ['Authority', 'Autorité']),
        'mrz_line1' => '', 'mrz_line2' => ''
    ];
    if (preg_match_all('/^\s*([A-Z0-9<]{30,50})\s*$/m', strtoupper($text), $mrz)) {
        $fields['mrz_line1'] = $mrz[1][0] ?? '';
        $fields['mrz_line2'] = $mrz[1][1] ?? '';
    }
    if ($fields['full_name'] === '') $fields['full_name'] = trim($fields['given_names'] . ' ' . $fields['surname']);
    return $fields;
}

function extract_insurance_document_fields($text) {
    $value = function($patterns) use ($text) { return document_value($text, $patterns); };
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

function extract_package_document_fields($text) {
    $value = function($patterns) use ($text) { return document_value($text, $patterns); };
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
        'guest_names' => $value(['/(?:Passenger|Guest)\s*Names?\s*[:#-]?\s*([^\n]+)/i']),
        'start_date' => $start, 'end_date' => $end, 'days' => $days, 'nights' => $nights,
        'adults' => (int) $value(['/Adults?\s*[:#-]?\s*(\d+)/i', '/\b(\d+)\s*Adults?\b/i']),
        'children' => (int) $value(['/Children\s*[:#-]?\s*(\d+)/i', '/\b(\d+)\s*Children\b/i']),
        'infants' => (int) $value(['/Infants?\s*[:#-]?\s*(\d+)/i', '/\b(\d+)\s*Infants?\b/i']),
        'accommodation' => $value(['/(?:Hotel|Accommodation)\s*[:#-]?\s*([^\n]+)/i']),
        'room_type' => $value(['/Room\s*Type\s*[:#-]?\s*([^\n]+)/i']),
        'meal_plan' => $value(['/(?:Meal\s*Plan|Meals)\s*[:#-]?\s*([^\n]+)/i']),
        'transportation' => $value(['/Transportation\s*[:#-]?\s*([^\n]+)/i']),
        'pickup' => $value(['/Pick\s*[- ]?up\s*(?:Details)?\s*[:#-]?\s*([^\n]+)/i']),
        'dropoff' => $value(['/Drop\s*[- ]?off\s*(?:Details)?\s*[:#-]?\s*([^\n]+)/i']),
        'confirmation_no' => $value(['/Confirmation\s*(?:Number|No\.)\s*[:#-]?\s*([A-Z0-9\/-]+)/i']),
        'itinerary' => $value(['/Itinerary\s*[:#-]?\s*([^\n]+)/i']),
        'inclusions' => $value(['/Inclusions?\s*[:#-]?\s*([^\n]+)/i']),
        'exclusions' => $value(['/Exclusions?\s*[:#-]?\s*([^\n]+)/i']),
        'details' => $value(['/Package\s*Details\s*[:#-]?\s*([^\n]+)/i']),
        'terms' => $value(['/Terms\s*(?:and|&)\s*Conditions\s*[:#-]?\s*([^\n]+)/i'])
    ];
}

function post_text($db, $key, $default = '') {
    return mysqli_real_escape_string($db, trim((string) ($_POST[$key] ?? $default)));
}

function post_date($db, $key) {
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') return '';
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? mysqli_real_escape_string($db, $value) : '';
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
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
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
                    return normalize_ticket_text($text, true);
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
    $upload_extension = strtolower(pathinfo((string) ($_FILES['ticket']['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed_document_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];
    if (isset($_FILES["ticket"]) && $_FILES["ticket"]["error"] == UPLOAD_ERR_OK && in_array($upload_extension, $allowed_document_extensions, true)) {
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
            $_SESSION['verified_booking_document'] = ['ticket_path' => $ticket_path];

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
        $message = "Please select a valid PDF or image document.";
        $message_type = "error";
    }
}

function ensure_booking_remarks_column($db) {
    $result = mysqli_query($db, "SHOW COLUMNS FROM bookings LIKE 'remarks'");
    if ($result && mysqli_num_rows($result) == 0) {
        mysqli_query($db, "ALTER TABLE bookings ADD COLUMN remarks TEXT NULL");
    }
}

function val($text, $key) {
    if (empty($text)) return "";
    
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
            '/ARRIVAL\s*\n\s*[A-Z]{3}\s*\n\s*[A-Z\s]+\s*\n\s*\d{2}:\d{2}\s*\n\s*(' . $date . ')/i',
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
    
    $pList = isset($patterns[$key]) ? $patterns[$key] : ["/$key" . "[: ]*(.*)/i"];
    
    foreach ($pList as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $val = clean_ocr_value($m[1]);
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

// Handle Saving Booking to DB
if (isset($_POST["save_booking"])) {
    ensure_booking_remarks_column($db);
    mysqli_report(MYSQLI_REPORT_OFF);

    if ($booking_enquiry_id > 0 && !enquiry_validate_csrf($_POST['csrf_token'] ?? '')) {
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Your session token expired. Refresh and try again.']);
        } else {
            header('Location: ../enquiry/view.php?id=' . $booking_enquiry_id . '&error=' . rawurlencode('Your session token expired. Refresh and try again.'));
        }
        exit;
    }

    $created_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $is_manual_booking = ($_POST["booking_mode"] ?? '') === 'manual';
    $posted_ticket_path = (string) ($_POST["ticket_path"] ?? '');
    if (!$is_manual_booking && (empty($_SESSION['verified_booking_document']['ticket_path']) || !hash_equals((string) $_SESSION['verified_booking_document']['ticket_path'], $posted_ticket_path))) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Document verification expired. Please upload the document again.']);
        exit;
    }
    if ($is_manual_booking) {
        $posted_ticket_path = '';
    }
    $ticket_path = mysqli_real_escape_string($db, $posted_ticket_path);
    $requested_type = (string) ($_POST["document_type"] ?? 'other');
    $document_type = in_array($requested_type, ['ticket', 'hotel', 'visa', 'passport', 'insurance', 'package', 'other'], true) ? $requested_type : 'other';

    $file_type_map = [
        'ticket'  => 'Ticket',
        'hotel'   => 'Hotel',
        'visa'    => 'Visa',
        'passport' => 'Passport',
        'insurance' => 'Insurance',
        'package' => 'Tour Package',
        'other'   => 'Other'
    ];
    $attachment_type = $file_type_map[$document_type] ?? 'Other';

    $serial_no = post_text($db, "serial_no");
    $booking_date = post_date($db, "booking_date");
    $passenger_name = post_text($db, "passenger_name");
    $customer_name_for_selection = trim((string) ($_POST["customer_name"] ?? ''));
    $customer_name = post_text($db, "customer_name");
    $customer_mobile = isset($_POST["customer_mobile"]) ? mysqli_real_escape_string($db, $_POST["customer_mobile"]) : "";
    $customer_email = isset($_POST["customer_email"]) ? mysqli_real_escape_string($db, $_POST["customer_email"]) : "";
    // Enquiry conversion must not depend only on JavaScript pre-filling the form.
    // Keep fields editable, but recover any missing customer details from the
    // enquiry that is being converted.
    if ($booking_enquiry_id > 0 && $booking_enquiry) {
        if ($customer_name === '') {
            $customer_name_for_selection = trim((string) ($booking_enquiry['customer_name'] ?? ''));
            $customer_name = mysqli_real_escape_string($db, $customer_name_for_selection);
        }
        if ($passenger_name === '') {
            $passenger_name = mysqli_real_escape_string($db, trim((string) ($booking_enquiry['customer_name'] ?? '')));
        }
        if (trim($customer_mobile) === '') {
            $customer_mobile = mysqli_real_escape_string($db, trim((string) ($booking_enquiry['mobile'] ?? '')));
        }
        if (trim($customer_email) === '') {
            $customer_email = mysqli_real_escape_string($db, trim((string) ($booking_enquiry['email'] ?? '')));
        }
    }
    $from_city = post_text($db, "from_city");
    $to_city = post_text($db, "to_city");
    $departure_date = post_date($db, "departure_date");
    $departure_time = post_text($db, "departure_time");
    $arrival_date = post_date($db, "arrival_date");
    $arrival_time = post_text($db, "arrival_time");
    $pnr = post_text($db, "pnr");
    $ticket_number = post_text($db, "ticket_number");
    $flight_number = post_text($db, "flight_number");
    $airline_name = post_text($db, "airline_name");
    $service_labels = ['ticket' => 'Flight', 'hotel' => 'Hotel', 'visa' => 'Visa', 'passport' => 'Passport', 'insurance' => 'Insurance', 'package' => 'Tour Package', 'other' => 'Other Service'];
    $service_type = mysqli_real_escape_string($db, $service_labels[$document_type]);
    $supplier_name = post_text($db, "supplier_name");
    $buying_cost = floatval($_POST["buying_cost"] ?? 0);
    $selling_cost = floatval($_POST["selling_cost"] ?? 0);
    $profit = $selling_cost - $buying_cost;
    $payment_method = post_text($db, "payment_method", "Cash");
    $assigned_user = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
    $customer_type_for_selection = trim((string) ($_POST["customer_type"] ?? "Walk-in Customer"));
    $customer_type = mysqli_real_escape_string($db, $customer_type_for_selection);

    $flight_class = "";
    $terminal = "";
    $seat_number = "";
    $fare_basis = "";
    $baggage = "";
    $booking_ref = "";
    $status = "Booked";
    $remarks = mysqli_real_escape_string($db, $_POST["remarks"] ?? "");

    $hotel_name = post_text($db, 'hotel_name');
    $hotel_country = post_text($db, 'hotel_country');
    $hotel_city = post_text($db, 'hotel_city');
    $hotel_address = post_text($db, 'hotel_address');
    $hotel_check_in = post_date($db, 'hotel_check_in');
    $hotel_check_out = post_date($db, 'hotel_check_out');
    $hotel_room_type = post_text($db, 'hotel_room_type');
    $hotel_rooms_count = intval($_POST['hotel_rooms_count'] ?? 0);
    $hotel_nights_count = intval($_POST['hotel_nights_count'] ?? 0);
    $hotel_meal_plan = post_text($db, 'hotel_meal_plan');
    $hotel_adults_count = intval($_POST['hotel_adults_count'] ?? 0);
    $hotel_children_count = intval($_POST['hotel_children_count'] ?? 0);
    $hotel_guest_names = post_text($db, 'hotel_guest_names');
    $hotel_booking_details = post_text($db, 'hotel_booking_details');
    $hotel_confirmation_no = post_text($db, 'hotel_confirmation_no');

    $visa_type = post_text($db, 'visa_type');
    $visa_country = post_text($db, 'visa_country');
    $visa_app_no = post_text($db, 'visa_app_no');
    $visa_submission_date = post_date($db, 'visa_submission_date');
    $visa_delivery_date = post_date($db, 'visa_delivery_date');
    $visa_entry_type = post_text($db, 'visa_entry_type');
    $visa_duration_days = intval($_POST['visa_duration_days'] ?? 0);
    $visa_issue_place = post_text($db, 'visa_issue_place');
    $visa_uid_no = post_text($db, 'visa_uid_no');
    $visa_full_name = post_text($db, 'visa_full_name');
    $visa_nationality = post_text($db, 'visa_nationality');
    $visa_place_of_birth = post_text($db, 'visa_place_of_birth');
    $visa_date_of_birth = post_date($db, 'visa_date_of_birth');
    $visa_passport_type = post_text($db, 'visa_passport_type');
    $visa_passport_no = post_text($db, 'visa_passport_no');
    $visa_profession = post_text($db, 'visa_profession');

    $passport_number = post_text($db, 'passport_number');
    $passport_type = post_text($db, 'passport_type');
    $passport_issuing_country = post_text($db, 'passport_issuing_country');
    $passport_country_code = post_text($db, 'passport_country_code');
    $passport_full_name = post_text($db, 'passport_full_name');
    $passport_surname = post_text($db, 'passport_surname');
    $passport_given_names = post_text($db, 'passport_given_names');
    $passport_nationality = post_text($db, 'passport_nationality');
    $passport_gender = post_text($db, 'passport_gender');
    $passport_date_of_birth = post_date($db, 'passport_date_of_birth');
    $passport_place_of_birth = post_text($db, 'passport_place_of_birth');
    $passport_date_of_issue = post_date($db, 'passport_date_of_issue');
    $passport_date_of_expiry = post_date($db, 'passport_date_of_expiry');
    $passport_place_of_issue = post_text($db, 'passport_place_of_issue');
    $passport_authority = post_text($db, 'passport_authority');
    $passport_mrz_line1 = post_text($db, 'passport_mrz_line1');
    $passport_mrz_line2 = post_text($db, 'passport_mrz_line2');

    $insurance_policy_no = post_text($db, 'insurance_policy_no');
    $insurance_provider = post_text($db, 'insurance_provider');
    $insurance_insured_name = post_text($db, 'insurance_insured_name');
    $insurance_coverage_type = post_text($db, 'insurance_coverage_type');
    $insurance_passport_no = post_text($db, 'insurance_passport_no');
    $insurance_destination = post_text($db, 'insurance_destination');
    $insurance_issue_date = post_date($db, 'insurance_issue_date');
    $insurance_start_date = post_date($db, 'insurance_start_date');
    $insurance_end_date = post_date($db, 'insurance_end_date');
    $insurance_days = intval($_POST['insurance_days'] ?? 0);
    $insurance_sum_insured = is_numeric($_POST['insurance_sum_insured'] ?? null) ? (float) $_POST['insurance_sum_insured'] : 0;
    $insurance_premium_amount = is_numeric($_POST['insurance_premium_amount'] ?? null) ? (float) $_POST['insurance_premium_amount'] : 0;
    $insurance_emergency_no = post_text($db, 'insurance_emergency_no');
    $insurance_certificate_no = post_text($db, 'insurance_certificate_no');
    $insurance_coverage_details = post_text($db, 'insurance_coverage_details');
    $insurance_remarks = post_text($db, 'insurance_remarks');

    $package_voucher_no = post_text($db, 'package_voucher_no');
    $package_name = post_text($db, 'package_name');
    $package_destinations = post_text($db, 'package_destinations');
    $package_country = post_text($db, 'package_country');
    $package_guest_names = post_text($db, 'package_guest_names');
    $package_start_date = post_date($db, 'package_start_date');
    $package_end_date = post_date($db, 'package_end_date');
    $package_days_count = intval($_POST['package_days_count'] ?? 0);
    $package_nights_count = intval($_POST['package_nights_count'] ?? 0);
    $package_adults_count = intval($_POST['package_adults_count'] ?? 0);
    $package_children_count = intval($_POST['package_children_count'] ?? 0);
    $package_infants_count = intval($_POST['package_infants_count'] ?? 0);
    $package_accommodation = post_text($db, 'package_accommodation');
    $package_room_type = post_text($db, 'package_room_type');
    $package_meals = post_text($db, 'package_meals');
    $package_transportation = post_text($db, 'package_transportation');
    $package_pickup_details = post_text($db, 'package_pickup_details');
    $package_dropoff_details = post_text($db, 'package_dropoff_details');
    $package_confirmation_no = post_text($db, 'package_confirmation_no');
    $package_itinerary = post_text($db, 'package_itinerary');
    $package_inclusions = post_text($db, 'package_inclusions');
    $package_exclusions = post_text($db, 'package_exclusions');
    $package_details = post_text($db, 'package_details');
    $package_terms = post_text($db, 'package_terms');

    $other_service_name = post_text($db, 'other_service_name');
    $other_reference_no = post_text($db, 'other_reference_no');
    $other_service_date = post_date($db, 'other_service_date');
    $other_end_date = post_date($db, 'other_end_date');
    $other_country = post_text($db, 'other_country');
    $other_city = post_text($db, 'other_city');
    $other_from = post_text($db, 'other_from');
    $other_to = post_text($db, 'other_to');
    $other_details = post_text($db, 'other_details');

    if ($document_type === 'visa' && $passenger_name === '') $passenger_name = $visa_full_name;
    if ($document_type === 'hotel' && $passenger_name === '') $passenger_name = $hotel_guest_names;
    if ($document_type === 'passport' && $passenger_name === '') $passenger_name = $passport_full_name;
    if ($document_type === 'insurance' && $passenger_name === '') $passenger_name = $insurance_insured_name;
    if ($document_type === 'package' && $passenger_name === '') $passenger_name = $package_guest_names;

    $required_by_type = [
        'ticket' => ['Passenger name' => $passenger_name, 'Ticket number' => $ticket_number, 'Flight number' => $flight_number, 'Airline' => $airline_name, 'Origin' => $from_city, 'Destination' => $to_city, 'Departure date' => $departure_date, 'Arrival date' => $arrival_date],
        'hotel' => ['Booking/Voucher ID' => $hotel_confirmation_no, 'Hotel name' => $hotel_name, 'Country' => $hotel_country, 'City' => $hotel_city, 'Check in' => $hotel_check_in, 'Check out' => $hotel_check_out, 'Room type' => $hotel_room_type, 'Guest names' => $hotel_guest_names],
        'visa' => ['Entry permit number' => $visa_app_no, 'Visa type' => $visa_type, 'Country' => $visa_country, 'Issue date' => $visa_submission_date, 'Valid until' => $visa_delivery_date, 'Full name' => $visa_full_name, 'Nationality' => $visa_nationality, 'Date of birth' => $visa_date_of_birth, 'Passport number' => $visa_passport_no],
        'passport' => ['Passport number' => $passport_number, 'Issuing country' => $passport_issuing_country, 'Full name' => $passport_full_name, 'Nationality' => $passport_nationality, 'Date of birth' => $passport_date_of_birth, 'Date of issue' => $passport_date_of_issue, 'Date of expiry' => $passport_date_of_expiry],
        'insurance' => ['Policy number' => $insurance_policy_no, 'Insurance provider' => $insurance_provider, 'Insured person name' => $insurance_insured_name, 'Insurance type / plan' => $insurance_coverage_type, 'Coverage start date' => $insurance_start_date, 'Coverage end date' => $insurance_end_date],
        'package' => ['Package name' => $package_name, 'Destination' => $package_destinations, 'Passenger / guest names' => $package_guest_names, 'Travel start date' => $package_start_date, 'Travel end date' => $package_end_date, 'Adults' => $package_adults_count],
        'other' => ['Service name' => $other_service_name, 'Service date' => $other_service_date],
    ];
    $missing_fields = [];
    foreach ($required_by_type[$document_type] as $label => $value) {
        if (trim((string) $value) === '') $missing_fields[] = $label;
    }
    if ($document_type === 'package' && $package_adults_count < 1) $missing_fields[] = 'Adults';
    if ($document_type === 'passport' && $passport_date_of_issue && $passport_date_of_expiry && $passport_date_of_expiry < $passport_date_of_issue) $missing_fields[] = 'Date of expiry after date of issue';
    if ($document_type === 'insurance' && $insurance_start_date && $insurance_end_date && $insurance_end_date < $insurance_start_date) $missing_fields[] = 'Coverage end date after start date';
    if ($document_type === 'package' && $package_start_date && $package_end_date && $package_end_date < $package_start_date) $missing_fields[] = 'Travel end date after start date';
    if ($customer_name === '') $missing_fields[] = 'Customer / Agency';
    if ($supplier_name === '') $missing_fields[] = 'Supplier';
    if (!isset($_POST['buying_cost']) || $_POST['buying_cost'] === '' || !is_numeric($_POST['buying_cost'])) $missing_fields[] = 'Valid Buying Cost';
    if (!isset($_POST['selling_cost']) || $_POST['selling_cost'] === '' || !is_numeric($_POST['selling_cost'])) $missing_fields[] = 'Valid Selling Cost';
    if ($missing_fields) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Please fill: ' . implode(', ', $missing_fields)]);
        exit;
    }

    // Ignore posted values for inactive document sections.
    $type_variables = [
        'ticket' => ['from_city','to_city','departure_date','departure_time','arrival_date','arrival_time','pnr','ticket_number','flight_number','airline_name','flight_class','terminal','seat_number','fare_basis','baggage','booking_ref'],
        'hotel' => ['hotel_name','hotel_country','hotel_city','hotel_address','hotel_check_in','hotel_check_out','hotel_room_type','hotel_rooms_count','hotel_nights_count','hotel_meal_plan','hotel_adults_count','hotel_children_count','hotel_guest_names','hotel_booking_details','hotel_confirmation_no'],
        'visa' => ['visa_type','visa_country','visa_app_no','visa_submission_date','visa_delivery_date','visa_entry_type','visa_duration_days','visa_issue_place','visa_uid_no','visa_full_name','visa_nationality','visa_place_of_birth','visa_date_of_birth','visa_passport_type','visa_passport_no','visa_profession'],
        'passport' => ['passport_number','passport_type','passport_issuing_country','passport_country_code','passport_full_name','passport_surname','passport_given_names','passport_nationality','passport_gender','passport_date_of_birth','passport_place_of_birth','passport_date_of_issue','passport_date_of_expiry','passport_place_of_issue','passport_authority','passport_mrz_line1','passport_mrz_line2'],
        'insurance' => ['insurance_policy_no','insurance_provider','insurance_insured_name','insurance_coverage_type','insurance_passport_no','insurance_destination','insurance_issue_date','insurance_start_date','insurance_end_date','insurance_days','insurance_sum_insured','insurance_premium_amount','insurance_emergency_no','insurance_certificate_no','insurance_coverage_details','insurance_remarks'],
        'package' => ['package_voucher_no','package_name','package_destinations','package_country','package_guest_names','package_start_date','package_end_date','package_days_count','package_nights_count','package_adults_count','package_children_count','package_infants_count','package_accommodation','package_room_type','package_meals','package_transportation','package_pickup_details','package_dropoff_details','package_confirmation_no','package_itinerary','package_inclusions','package_exclusions','package_details','package_terms'],
        'other' => ['other_service_name','other_reference_no','other_service_date','other_end_date','other_country','other_city','other_from','other_to','other_details'],
    ];
    $numeric_variables = ['hotel_rooms_count','hotel_nights_count','hotel_adults_count','hotel_children_count','visa_duration_days','insurance_days','insurance_sum_insured','insurance_premium_amount','package_days_count','package_nights_count','package_adults_count','package_children_count','package_infants_count'];
    foreach ($type_variables as $type => $variables) {
        if ($type === $document_type) continue;
        foreach ($variables as $variable) $$variable = in_array($variable, $numeric_variables, true) ? 0 : '';
    }

    $enquiry_id = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;
    mysqli_begin_transaction($db);
    if ($enquiry_id > 0) {
        $locked_enquiry = enquiry_fetch($db, $enquiry_id, true, true);
        if (!$locked_enquiry || !enquiry_can_convert($locked_enquiry['status'], $locked_enquiry['booking_id'] ?? null)) {
            mysqli_rollback($db);
            $conversion_message = 'Only an authorized Confirmed, unconverted enquiry can create a booking.';
            if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $conversion_message]);
            } else {
                header('Location: ../enquiry/view.php?id=' . $enquiry_id . '&error=' . rawurlencode($conversion_message));
            }
            exit;
        }
    }

    $customer_master_id = booking_selected_customer_master_id(
        $db,
        $_POST["customer_master_id"] ?? 0,
        $customer_name_for_selection,
        $customer_type_for_selection
    );
    if ($customer_master_id === 0 && !booking_customer_type_allows_creation($customer_type_for_selection)) {
        mysqli_rollback($db);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => "Select an existing $customer_type_for_selection customer from the suggestions. Only Walk-in Customer can be created from a booking.",
        ]);
        exit;
    }

    // Only a Walk-in Customer may be matched or created from the booking form.
    if ($customer_master_id === 0 && $customer_name !== '' && booking_customer_type_allows_creation($customer_type_for_selection)) {
        $escaped_mobile = mysqli_real_escape_string($db, $customer_mobile);
        $check_q = null;
        if ($customer_mobile !== '') {
            $check_q = mysqli_query(
                $db,
                "SELECT id FROM customer_master
                 WHERE mobile = '$escaped_mobile'
                   AND customer_type IN ('Walk-in Customer', 'Walk-in', 'Customer')
                 LIMIT 1"
            );
        }
        
        if ($check_q && mysqli_num_rows($check_q) > 0) {
            $check_row = mysqli_fetch_assoc($check_q);
            $customer_master_id = intval($check_row['id']);
        } else {
            // Create a new record in customer_master
            $type = 'Walk-in Customer';
            $insert_cust_sql = "INSERT INTO customer_master (customer_type, name, mobile, email, created_by) 
                                VALUES ('$type', '$customer_name', '$customer_mobile', '$customer_email', '$created_by')";
            if (mysqli_query($db, $insert_cust_sql)) {
                $customer_master_id = mysqli_insert_id($db);
                
                // Log customer creation activity
                $log_user = $_SESSION['user_name'] ?? 'System';
                mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) 
                                   VALUES ('$log_user', 'Created Customer #$customer_master_id ($customer_name) via Booking Form', 'Customer Master', NOW())");

                // Also save in customers table to keep the agent's customer list in sync
                $escaped_name = mysqli_real_escape_string($db, $customer_name);
                $escaped_email = mysqli_real_escape_string($db, $customer_email);
                $escaped_remarks = mysqli_real_escape_string($db, $remarks ?? '');
                mysqli_query($db, "INSERT INTO customers (name, mobile, email, notes) VALUES ('$escaped_name', '$escaped_mobile', '$escaped_email', '$escaped_remarks')");
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
        created_by, customer_master_id, document_type,
        hotel_name, hotel_country, hotel_city, hotel_address, hotel_check_in, hotel_check_out,
        hotel_room_type, hotel_rooms_count, hotel_nights_count, hotel_meal_plan, hotel_adults_count,
        hotel_children_count, hotel_guest_names, hotel_booking_details, hotel_confirmation_no,
        visa_type, visa_country, visa_app_no, visa_submission_date, visa_delivery_date, visa_entry_type,
        visa_duration_days, visa_issue_place, visa_uid_no, visa_full_name, visa_nationality,
        visa_place_of_birth, visa_date_of_birth, visa_passport_type, visa_passport_no, visa_profession,
        passport_number, passport_type, passport_issuing_country, passport_country_code, passport_full_name,
        passport_surname, passport_given_names, passport_nationality, passport_gender, passport_date_of_birth,
        passport_place_of_birth, passport_date_of_issue, passport_date_of_expiry, passport_place_of_issue,
        passport_authority, passport_mrz_line1, passport_mrz_line2,
        insurance_policy_no, insurance_provider, insurance_insured_name, insurance_coverage_type,
        insurance_passport_no, insurance_destination, insurance_issue_date, insurance_start_date, insurance_end_date,
        insurance_days, insurance_sum_insured, insurance_premium_amount, insurance_emergency_no,
        insurance_certificate_no, insurance_coverage_details, insurance_remarks,
        package_voucher_no, package_name, package_destinations, package_country, package_guest_names,
        package_start_date, package_end_date, package_days_count, package_nights_count, package_adults_count,
        package_children_count, package_infants_count, package_accommodation, package_room_type, package_meals,
        package_transportation, package_pickup_details, package_dropoff_details, package_confirmation_no,
        package_itinerary, package_inclusions, package_exclusions, package_details, package_terms,
        other_service_name, other_reference_no, other_service_date, other_end_date, other_country, other_city,
        other_from, other_to, other_details
    ) VALUES (
        '$serial_no', " . ($booking_date ? "'$booking_date'" : "NULL") . ", '$passenger_name', '$customer_name', '$customer_type',
        '$from_city', '$to_city', " . ($departure_date ? "'$departure_date'" : "NULL") . ", '$departure_time',
        " . ($arrival_date ? "'$arrival_date'" : "NULL") . ", '$arrival_time', '$pnr', '$ticket_number',
        '$flight_number', '$airline_name', '$flight_class', '$terminal',
        '$seat_number', '$baggage', '$booking_ref', '$fare_basis',
        '$service_type', '$supplier_name', $buying_cost, $selling_cost, 
        $profit, '$payment_method', '$assigned_user', '$status', '$remarks', " . ($ticket_path ? "'$ticket_path'" : "NULL") . ",
        '$created_by', " . ($customer_master_id > 0 ? $customer_master_id : "NULL") . ", '$document_type',
        '$hotel_name', '$hotel_country', '$hotel_city', '$hotel_address', " . ($hotel_check_in ? "'$hotel_check_in'" : "NULL") . ", " . ($hotel_check_out ? "'$hotel_check_out'" : "NULL") . ",
        '$hotel_room_type', $hotel_rooms_count, $hotel_nights_count, '$hotel_meal_plan', $hotel_adults_count,
        $hotel_children_count, '$hotel_guest_names', '$hotel_booking_details', '$hotel_confirmation_no',
        '$visa_type', '$visa_country', '$visa_app_no', " . ($visa_submission_date ? "'$visa_submission_date'" : "NULL") . ", " . ($visa_delivery_date ? "'$visa_delivery_date'" : "NULL") . ", '$visa_entry_type',
        $visa_duration_days, '$visa_issue_place', '$visa_uid_no', '$visa_full_name', '$visa_nationality',
        '$visa_place_of_birth', " . ($visa_date_of_birth ? "'$visa_date_of_birth'" : "NULL") . ", '$visa_passport_type', '$visa_passport_no', '$visa_profession',
        '$passport_number', '$passport_type', '$passport_issuing_country', '$passport_country_code', '$passport_full_name',
        '$passport_surname', '$passport_given_names', '$passport_nationality', '$passport_gender', " . ($passport_date_of_birth ? "'$passport_date_of_birth'" : "NULL") . ",
        '$passport_place_of_birth', " . ($passport_date_of_issue ? "'$passport_date_of_issue'" : "NULL") . ", " . ($passport_date_of_expiry ? "'$passport_date_of_expiry'" : "NULL") . ", '$passport_place_of_issue',
        '$passport_authority', '$passport_mrz_line1', '$passport_mrz_line2',
        '$insurance_policy_no', '$insurance_provider', '$insurance_insured_name', '$insurance_coverage_type',
        '$insurance_passport_no', '$insurance_destination', " . ($insurance_issue_date ? "'$insurance_issue_date'" : "NULL") . ", " . ($insurance_start_date ? "'$insurance_start_date'" : "NULL") . ", " . ($insurance_end_date ? "'$insurance_end_date'" : "NULL") . ",
        $insurance_days, $insurance_sum_insured, $insurance_premium_amount, '$insurance_emergency_no',
        '$insurance_certificate_no', '$insurance_coverage_details', '$insurance_remarks',
        '$package_voucher_no', '$package_name', '$package_destinations', '$package_country', '$package_guest_names',
        " . ($package_start_date ? "'$package_start_date'" : "NULL") . ", " . ($package_end_date ? "'$package_end_date'" : "NULL") . ", $package_days_count, $package_nights_count, $package_adults_count,
        $package_children_count, $package_infants_count, '$package_accommodation', '$package_room_type', '$package_meals',
        '$package_transportation', '$package_pickup_details', '$package_dropoff_details', '$package_confirmation_no',
        '$package_itinerary', '$package_inclusions', '$package_exclusions', '$package_details', '$package_terms',
        '$other_service_name', '$other_reference_no', " . ($other_service_date ? "'$other_service_date'" : "NULL") . ", " . ($other_end_date ? "'$other_end_date'" : "NULL") . ", '$other_country', '$other_city',
        '$other_from', '$other_to', '$other_details'
    )";

    if (mysqli_query($db, $query)) {
        $booking_id = mysqli_insert_id($db);

        if ($enquiry_id > 0) {
            $link_booking = mysqli_prepare($db, 'UPDATE bookings SET enquiry_id = ? WHERE id = ? AND enquiry_id IS NULL');
            mysqli_stmt_bind_param($link_booking, 'ii', $enquiry_id, $booking_id);
            $booking_linked = mysqli_stmt_execute($link_booking) && mysqli_stmt_affected_rows($link_booking) === 1;
            mysqli_stmt_close($link_booking);
            $new_status = 'Converted';
            $link_enquiry = mysqli_prepare($db, "UPDATE enquiries SET status = ?, booking_id = ?, next_follow_up_at = NULL, updated_at = NOW() WHERE id = ? AND status = 'Confirmed' AND booking_id IS NULL");
            mysqli_stmt_bind_param($link_enquiry, 'sii', $new_status, $booking_id, $enquiry_id);
            $enquiry_linked = mysqli_stmt_execute($link_enquiry) && mysqli_stmt_affected_rows($link_enquiry) === 1;
            mysqli_stmt_close($link_enquiry);
            if (!$booking_linked || !$enquiry_linked) {
                mysqli_rollback($db);
                $conversion_message = 'The enquiry was not converted because its booking link could not be secured.';
                if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => $conversion_message]);
                } else {
                    header('Location: ../enquiry/view.php?id=' . $enquiry_id . '&error=' . rawurlencode($conversion_message));
                }
                exit;
            }
        }

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

        if ($enquiry_id > 0) {
            enquiry_log($db, "Enquiry #$enquiry_id converted to booking #$booking_id");
        }
        mysqli_commit($db);

        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'booking_id' => $booking_id, 'enquiry_id' => $enquiry_id]);
            exit;
        }

        if ($enquiry_id > 0) {
            header("Location: ../enquiry/view.php?id=" . $enquiry_id . "&success=converted");
        } else {
            header("Location: list.php?success=1");
        }
        exit;
    } else {
        mysqli_rollback($db);
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'The booking could not be saved. Please review the fields and try again.']);
            exit;
        }
        error_log('Booking insert failed: ' . mysqli_error($db));
        $message = "The booking could not be saved. Please review the fields and try again.";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Booking | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/premium-booking-form.css">
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
                const selectionNote = document.createElement('small');
                selectionNote.style.display = 'block';
                selectionNote.style.marginTop = '5px';
                selectionNote.style.color = '#64748b';
                parent.appendChild(selectionNote);
                const selectionError = document.createElement('small');
                selectionError.style.display = 'none';
                selectionError.style.marginTop = '5px';
                selectionError.style.color = '#dc2626';
                selectionError.style.fontWeight = '600';
                parent.appendChild(selectionError);

                function clearSelectionError() {
                    selectionError.textContent = '';
                    selectionError.style.display = 'none';
                    input.setCustomValidity('');
                }
                
                input.setAttribute('autocomplete', 'off');

                // Disable input and set placeholder on load based on typeSelect state
                function handleTypeState() {
                    if (typeSelect) {
                        const selectedType = typeSelect.value;
                        if (!selectedType) {
                            input.disabled = true;
                            input.placeholder = "Select customer type first...";
                            selectionNote.textContent = '';
                            input.value = "";
                            if (idInput) idInput.value = "";
                            if (mobileInput) mobileInput.value = "";
                            if (emailInput) emailInput.value = "";
                        } else {
                            input.disabled = false;
                            const allowsCreation = selectedType === 'Walk-in Customer';
                            input.placeholder = allowsCreation
                                ? "Type to search or enter a new Walk-in Customer..."
                                : "Type to search and select an existing " + selectedType + "...";
                            selectionNote.textContent = allowsCreation
                                ? 'A new customer can be created here only for Walk-in Customer.'
                                : 'You must select an existing customer from the suggestions.';
                        }
                    }
                }

                if (typeSelect) {
                    let previousType = typeSelect.value;
                    typeSelect.addEventListener('change', function() {
                        if (typeSelect.value !== previousType) {
                            input.value = "";
                            clearSelectionError();
                            if (idInput) idInput.value = "";
                            if (mobileInput) mobileInput.value = "";
                            if (emailInput) emailInput.value = "";
                            dropdown.style.display = 'none';
                            previousType = typeSelect.value;
                        }
                        handleTypeState();
                    });
                    handleTypeState(); // Initial check
                }

                input.addEventListener('input', function() {
                    clearSelectionError();
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
                    fetch(`../customers/search_customer.php?q=${encodeURIComponent(query)}&customer_type=${encodeURIComponent(selectedType)}`)
                        .then(response => response.json())
                        .then(data => {
                            // Filter matches strictly by selected customer type
                            const matches = data.filter(cust => {
                                let dbType = cust.customer_type;
                                if (dbType === 'Walk-in' || dbType === 'Customer') {
                                    dbType = 'Walk-in Customer';
                                }
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
                                    clearSelectionError();
                                    
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

                if (form) {
                    form.addEventListener('submit', function(event) {
                        const selectedType = typeSelect ? typeSelect.value : '';
                        if (selectedType && selectedType !== 'Walk-in Customer' && (!idInput || !idInput.value)) {
                            event.preventDefault();
                            event.stopImmediatePropagation();
                            const errorMessage = 'New ' + selectedType + ' customer cannot be added here. Select an existing customer from the suggestions.';
                            selectionError.textContent = errorMessage;
                            selectionError.style.display = 'block';
                            input.setCustomValidity(errorMessage);
                            input.reportValidity();
                        } else {
                            clearSelectionError();
                        }
                    }, true);
                }
                
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
.uploaded-ticket-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 18px;
    border: 1px solid #bbf7d0;
    border-radius: 12px;
    background: #f0fdf4;
    color: #166534;
}
.uploaded-ticket-summary strong {
    display: block;
    margin-bottom: 3px;
}
.uploaded-ticket-summary span {
    font-size: 13px;
    word-break: break-word;
}
.uploaded-ticket-summary a {
    flex: 0 0 auto;
    padding: 9px 13px;
    border: 1px solid #86efac;
    border-radius: 8px;
    color: #166534;
    background: #ffffff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
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

@media (max-width: 900px) {
    .booking-add-screen .main,
    .booking-add-screen .main.sidebar-locked,
    html.sidebar-pref-locked .booking-add-screen .main {
        margin-left: 0 !important;
    }

    .uploaded-ticket-summary {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>
<body class="booking-add-screen">

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<?php
$ticket_sections = $document_type === 'ticket' ? split_ocr_text_into_tickets($text) : [$text];
$ticket_count = count($ticket_sections);
?>

<div class="main">
    <!-- Page Header and Actions -->
    <div class="booking-header">
        <h1>Add Booking</h1>
        <div class="header-actions">
            <?php if ($ticket_count <= 1): ?>
                <button type="submit" name="save_booking" form="single-booking-form" id="header-save-booking" class="btn btn-primary" style="<?php echo empty($ticket_path) && !$booking_enquiry ? 'display:none;' : ''; ?>">Save Booking</button>
            <?php endif; ?>
            <a href="add.php<?php echo $booking_return_to_dashboard ? '?return_to=dashboard' : ''; ?>" class="btn btn-outline">Clear / New</a>
            <a href="<?php echo $booking_return_to_dashboard ? '../dashboard.php' : 'list.php'; ?>" class="btn btn-secondary">Close / Back</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Top Card: Upload & OCR -->
    <form method="POST" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">

        <div class="booking-card">
            <?php if ($booking_enquiry): ?>
                <h2>Enquiry Details Loaded</h2>
                <div class="uploaded-ticket-summary" role="status">
                    <div>
                        <strong>Enquiry #<?php echo (int) $booking_enquiry_id; ?> is ready for conversion</strong>
                        <span>Available customer and service details are filled below. Complete any missing booking fields manually.</span>
                    </div>
                    <a href="../enquiry/view.php?id=<?php echo (int) $booking_enquiry_id; ?>">Back to Enquiry</a>
                </div>
            <?php elseif (empty($ticket_path)): ?>
                <h2 id="booking-entry-title">Upload Booking Document</h2>
                <p id="booking-entry-description" style="color: var(--text-secondary, #64748b); margin-bottom: 16px; font-size: 13px;">
                    Upload a document for automatic extraction, or choose Manual Booking to enter the details yourself.
                </p>
                <div class="upload-card-content" id="booking-upload-area">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="ticket">Upload Document File</label>
                        <div id="ticket-drop-zone" class="ticket-drop-zone" tabindex="0">
                            <input type="file" id="ticket" name="ticket" accept="image/*,.pdf" hidden>
                            <div class="ticket-drop-icon">📄</div>
                            <p style="margin: 4px 0; font-weight: 600; font-size: 13px;">Drag & drop ticket here</p>
                            <p style="margin: 0; font-size: 11px; color: var(--text-secondary, #64748b);">
                                or click to browse • paste image with Ctrl+V
                            </p>
                            <div id="ticket-preview" style="margin-top: 8px; font-size: 12px;"></div>
                        </div>
                        <p id="ticket-upload-error" style="color: #ef4444; font-size: 11px; margin-top: 6px; margin-bottom: 0;"></p>
                    </div>
                    <div style="display: flex; gap: 8px; align-self: flex-end;">
                        <button type="submit" name="extract_ticket" id="extract-btn" formnovalidate class="btn btn-primary" style="display: none;" aria-hidden="true" tabindex="-1">Extract Document</button>
                        <button type="button" id="manual-booking-btn" class="btn btn-outline" onclick="showManualBooking()">Manual Booking</button>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $verification_labels = [
                    'ticket' => 'Verified Flight Ticket', 'hotel' => 'Verified Hotel', 'visa' => 'Verified Visa',
                    'passport' => 'Verified Passport', 'insurance' => 'Verified Insurance',
                    'package' => 'Verified Tour Package', 'other' => 'Other Service – Manual Verification Required'
                ];
                ?>
                <h2><?php echo htmlspecialchars($verification_labels[$document_type] ?? $verification_labels['other']); ?></h2>
                <div class="uploaded-ticket-summary" role="status">
                    <div>
                        <strong>✓ File processed successfully</strong>
                        <span><?php echo htmlspecialchars(basename($ticket_path)); ?></span>
                    </div>
                    <a href="add.php<?php echo $booking_return_to_dashboard ? '?return_to=dashboard' : ''; ?>">Upload Different File</a>
                </div>
            <?php endif; ?>

            <div class="form-group" id="doc-type-group" style="<?php echo $booking_enquiry ? 'display:block;' : 'display:none;'; ?> margin-top: 16px;">
                <label for="document_type">Service Type</label>
                <select id="document_type" name="document_type">
                    <option value="">-- Select service type --</option>
                    <option value="ticket">Flight Ticket</option>
                    <option value="hotel">Hotel</option>
                    <option value="visa">Visa</option>
                    <option value="passport">Passport</option>
                    <option value="insurance">Insurance</option>
                    <option value="package">Tour Package</option>
                    <option value="other">Other Service</option>
                    <?php if (false): ?>
                    <option value="">-- Select document type --</option>
                    <option value="ticket">✈️ Flight Ticket</option>
                    <option value="hotel">🏨 Hotel</option>
                    <option value="other">⚙️ Other Service</option>
                    <option value="visa">🛂 Visa</option>
                    <?php endif; ?>
                </select>
                <p id="detected-type-msg" style="font-size: 12px; color: var(--status-booked-text, #0369a1); margin-top: 6px; margin-bottom: 0;"></p>
            </div>
        </div>
    </form>
    <div id="booking-details-form" style="<?php echo empty($ticket_path) && !$booking_enquiry ? 'display: none;' : ''; ?>">
        <?php if ($ticket_count > 1): 
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($booking_csrf_token); ?>">
                <input type="hidden" name="customer_master_id" value="">

                <div class="booking-card" style="border-left: 4px solid var(--accent-color, #0d283f);">
                    <h2 class="underlined" style="color: var(--accent-color, #0d283f);">
                        Ticket #<?php echo ($i + 1); ?>: <?php echo htmlspecialchars($p_passenger ?: 'Passenger Details'); ?>
                    </h2>
                    
                    <div class="booking-grid">
                        <div class="col-span-4 grid-section-header">Basic Details</div>
                        <div class="form-group field-group-always">
                            <label>Serial No</label>
                            <input type="text" name="serial_no" value="<?php echo htmlspecialchars($p_serial); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label>Booking Date</label>
                            <input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label>Customer Type<span class="required-asterisk">*</span></label>
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
                            <label>Customer / Agency<span class="required-asterisk">*</span></label>
                            <input type="text" name="customer_name" value="<?php echo htmlspecialchars($_GET['customer_name'] ?? ''); ?>" required>
                        </div>

                        <div class="col-span-4 grid-section-header">Customer Details</div>
                        <div class="form-group field-group-always col-span-2">
                            <label>Customer Phone / Mobile</label>
                            <input type="text" name="customer_mobile" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>">
                        </div>
                        <div class="form-group field-group-always col-span-2">
                            <label>Customer Email</label>
                            <input type="email" name="customer_email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                        </div>

                        <div class="col-span-4 grid-section-header">Passenger & Document Details</div>
                        
                        <div class="form-group field-group col-span-2" data-types="ticket,visa,holiday,voucher,passport">
                            <label>Passenger Name</label>
                            <input type="text" name="passenger_name" value="<?php echo htmlspecialchars($p_passenger); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label>PNR Code</label>
                            <input type="text" name="pnr" value="<?php echo htmlspecialchars($p_pnr); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label>Ticket Number</label>
                            <input type="text" name="ticket_number" value="<?php echo htmlspecialchars($p_ticket); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket">
                            <label>Flight Number</label>
                            <input type="text" name="flight_number" value="<?php echo htmlspecialchars($p_flight); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label>Airline Name</label>
                            <input type="text" name="airline_name" value="<?php echo htmlspecialchars($p_airline); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label>From (Origin)</label>
                            <input type="text" name="from_city" value="<?php echo htmlspecialchars($p_from); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday,voucher">
                            <label>To (Destination)</label>
                            <input type="text" name="to_city" value="<?php echo htmlspecialchars($p_to); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket,holiday,voucher">
                            <label>Departure Date</label>
                            <input type="date" name="departure_date" 
                                   value="<?php 
                                        if ($p_dep_date) {
                                            $parsed_time = strtotime($p_dep_date);
                                            if ($parsed_time) echo date('Y-m-d', $parsed_time);
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday">
                            <label>Departure Time</label>
                            <input type="text" name="departure_time" value="<?php echo htmlspecialchars($p_dep_time); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket,holiday">
                            <label>Arrival Date</label>
                            <input type="date" name="arrival_date" 
                                   value="<?php 
                                        if ($p_arr_date) {
                                            $parsed_time = strtotime($p_arr_date);
                                            if ($parsed_time) echo date('Y-m-d', $parsed_time);
                                        }
                                   ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label>Arrival Time</label>
                            <input type="text" name="arrival_time" value="<?php echo htmlspecialchars($p_arr_time); ?>">
                        </div>
                        
                        <input type="hidden" name="assigned_user" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                    </div>

                    <!-- Bottom Cost details for Multi-Passenger Ticket Card -->
                    <div class="bottom-split-layout">
                        <!-- Supplier Details Card -->
                        <div class="booking-card" style="margin-bottom: 0; box-shadow: none; border-color: #cbd5e1; background: #fafbfc; padding: 16px;">
                            <h2 style="font-size: 13px; margin-bottom: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px;">Supplier Details</h2>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <div class="form-group field-group-always">
                                    <label>Supplier<span class="required-asterisk">*</span></label>
                                    <input type="text" name="supplier_name" required>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div class="form-group field-group-always">
                                        <label>Buying Cost (₹)<span class="required-asterisk">*</span></label>
                                        <input type="text" id="buying_cost_<?php echo $i; ?>" name="buying_cost" placeholder="0.00" oninput="calculateTicketProfit(<?php echo $i; ?>)" required>
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
                                </div>
                            </div>
                        </div>

                        <!-- Customer & Selling Details Card -->
                        <div class="booking-card" style="margin-bottom: 0; box-shadow: none; border-color: #cbd5e1; background: #fafbfc; padding: 16px;">
                            <h2 style="font-size: 13px; margin-bottom: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px;">Customer & Selling Details</h2>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div class="form-group field-group-always">
                                        <label>Selling Cost (₹)<span class="required-asterisk">*</span></label>
                                        <input type="text" id="selling_cost_<?php echo $i; ?>" name="selling_cost" placeholder="0.00" oninput="calculateTicketProfit(<?php echo $i; ?>)" required>
                                    </div>
                                    <div class="form-group field-group-always">
                                        <label>Profit Margin (₹)</label>
                                        <input type="text" id="profit_<?php echo $i; ?>" name="profit" placeholder="0.00" readonly style="background: #f1f5f9; font-weight: 700; color: #10b981;">
                                    </div>
                                </div>
                                <div class="form-group field-group-always">
                                    <label>Remarks</label>
                                    <textarea name="remarks" rows="2" placeholder="Remarks" style="min-height: 36px;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="service_type" value="<?php echo ($document_type === 'visa') ? 'Visa' : (($document_type === 'holiday') ? 'Holiday Package' : (($document_type === 'voucher') ? 'Voucher' : (($document_type === 'passport') ? 'Passport' : 'Flight'))); ?>">

                    <button type="submit" name="save_booking" class="btn btn-primary" style="width: 100%; margin-top: 20px; height: 40px; font-size: 14px;">Confirm & Save Ticket #<?php echo ($i + 1); ?></button>
                </div>
            </form>
            <?php endfor; ?>

        <?php else: ?>
            <!-- Fallback single ticket layout -->
            <form method="POST" id="single-booking-form" autocomplete="off">
                <input type="hidden" name="booking_mode" value="<?php echo $booking_enquiry ? 'manual' : 'document'; ?>">
                <input type="hidden" name="ticket_path" value="<?php echo htmlspecialchars($ticket_path); ?>">
                <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($document_type); ?>">
                <input type="hidden" name="enquiry_id" value="<?php echo htmlspecialchars($_GET['enquiry_id'] ?? $_POST['enquiry_id'] ?? ''); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($booking_csrf_token); ?>">
                <input type="hidden" name="customer_master_id" value="">

                <div class="booking-card">
                    <h2 class="underlined" id="booking-details-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px; color: #10b981; display: inline-block; vertical-align: middle; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Booking Details
                    </h2>
                    
                    <div class="booking-grid">
                        <div class="col-span-4 grid-section-header">Basic Details</div>
                        <div class="form-group field-group-always">
                            <label for="serial_no">Serial No</label>
                            <input type="text" id="serial_no" name="serial_no" placeholder="e.g. 5022" value="<?php echo htmlspecialchars($default_serial); ?>">
                        </div>
                        <div class="form-group field-group-always">
                            <label for="booking_date">Booking Date</label>
                            <input type="date" id="booking_date" name="booking_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <input type="hidden" name="assigned_user" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">

                        <div class="col-span-4 grid-section-header" id="auto-fields-heading">Auto-Filled Document Fields</div>

                        <div class="form-group field-group col-span-2" data-types="ticket">
                            <label for="passenger_name">Passenger Name<span class="required-asterisk">*</span></label>
                            <input type="text" id="passenger_name" name="passenger_name" data-required="1" placeholder="Passenger Name(s)" 
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
                            <label for="ticket_number">Ticket Number<span class="required-asterisk">*</span></label>
                            <input type="text" id="ticket_number" name="ticket_number" data-required="1" placeholder="Ticket Number(s)" 
                                   value="<?php echo htmlspecialchars(val($text, 'Ticket')); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket">
                            <label for="flight_number">Flight Number<span class="required-asterisk">*</span></label>
                            <input type="text" id="flight_number" name="flight_number" data-required="1" placeholder="Flight Number" 
                                   value="<?php echo htmlspecialchars(val($text, 'Flight Number')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="airline_name">Airline Name<span class="required-asterisk">*</span></label>
                            <input type="text" id="airline_name" name="airline_name" data-required="1" placeholder="Airline Name" 
                                   value="<?php echo htmlspecialchars(val($text, 'Airline')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="from_city">From (Origin)<span class="required-asterisk">*</span></label>
                            <input type="text" id="from_city" name="from_city" data-required="1" placeholder="Origin City" 
                                   value="<?php echo htmlspecialchars(val($text, 'From')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="to_city">To (Destination)<span class="required-asterisk">*</span></label>
                            <input type="text" id="to_city" name="to_city" data-required="1" placeholder="Destination City" 
                                   value="<?php echo htmlspecialchars(val($text, 'To')); ?>">
                        </div>

                        <div class="form-group field-group" data-types="ticket">
                            <label for="departure_date">Departure Date<span class="required-asterisk">*</span></label>
                            <input type="date" id="departure_date" name="departure_date" data-required="1" 
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
                        <div class="form-group field-group" data-types="ticket">
                            <label for="departure_time">Departure Time</label>
                            <input type="text" id="departure_time" name="departure_time" placeholder="Departure Time" 
                                   value="<?php echo htmlspecialchars(val($text, 'Departure Time')); ?>">
                        </div>
                        <div class="form-group field-group" data-types="ticket">
                            <label for="arrival_date">Arrival Date<span class="required-asterisk">*</span></label>
                            <input type="date" id="arrival_date" name="arrival_date" data-required="1" 
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

                        <!-- Hotel fields: matched to the uploaded hotel confirmation sample -->
                        <?php $hotel_ocr = extract_hotel_document_fields($text); ?>
                        <div class="form-group field-group" data-types="hotel"><label>Booking / Voucher ID<span class="required-asterisk">*</span></label><input type="text" name="hotel_confirmation_no" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['confirmation_no']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="hotel"><label>Hotel Name<span class="required-asterisk">*</span></label><input type="text" name="hotel_name" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['name']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Country<span class="required-asterisk">*</span></label><input type="text" name="hotel_country" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['country']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>City<span class="required-asterisk">*</span></label><input type="text" name="hotel_city" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['city']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="hotel"><label>Hotel Address</label><input type="text" name="hotel_address" value="<?php echo htmlspecialchars($hotel_ocr['address']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Check In<span class="required-asterisk">*</span></label><input type="date" name="hotel_check_in" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['check_in']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Check Out<span class="required-asterisk">*</span></label><input type="date" name="hotel_check_out" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['check_out']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>No. of Nights</label><input type="number" min="0" name="hotel_nights_count" value="<?php echo (int) $hotel_ocr['nights']; ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>No. of Rooms<span class="required-asterisk">*</span></label><input type="number" min="1" name="hotel_rooms_count" data-required="1" value="<?php echo (int) $hotel_ocr['rooms']; ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Room Type<span class="required-asterisk">*</span></label><input type="text" name="hotel_room_type" data-required="1" value="<?php echo htmlspecialchars($hotel_ocr['room_type']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Meal Plan</label><input type="text" name="hotel_meal_plan" value="<?php echo htmlspecialchars($hotel_ocr['meal_plan']); ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Adults<span class="required-asterisk">*</span></label><input type="number" min="1" name="hotel_adults_count" data-required="1" value="<?php echo (int) $hotel_ocr['adults']; ?>"></div>
                        <div class="form-group field-group" data-types="hotel"><label>Children</label><input type="number" min="0" name="hotel_children_count" value="<?php echo (int) $hotel_ocr['children']; ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="hotel"><label>Guest Names<span class="required-asterisk">*</span></label><textarea name="hotel_guest_names" data-required="1" rows="2"><?php echo htmlspecialchars($hotel_ocr['guest_names']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="hotel"><label>Booking Details</label><textarea name="hotel_booking_details" rows="2"><?php echo htmlspecialchars($hotel_ocr['booking_details']); ?></textarea></div>

                        <!-- Visa fields: matched to the supplied UAE eVisa -->
                        <div class="form-group field-group" data-types="visa"><label>Entry Permit No.<span class="required-asterisk">*</span></label><input type="text" name="visa_app_no" data-required="1" value="<?php echo htmlspecialchars(document_value($text, ['/Entry\s*Permit\s*No\.?\s*[:#-]?\s*([0-9\/.-]+)/i'])); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="visa"><label>Visa Type<span class="required-asterisk">*</span></label><input type="text" name="visa_type" data-required="1" value="<?php echo htmlspecialchars(document_value($text, ['/Visa\s*(?:Category|Type)\s*[:#-]?\s*([^\n]+)/i', '/(Tourism\s*-\s*(?:Single|Multi)[^\n]*)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Destination Country<span class="required-asterisk">*</span></label><input type="text" name="visa_country" data-required="1" value="<?php echo preg_match('/U\.?A\.?E\.?|United Arab Emirates/i', $text) ? 'United Arab Emirates' : ''; ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Entry Type</label><input type="text" name="visa_entry_type" value="<?php echo stripos($text, 'Multi') !== false ? 'Multi' : (stripos($text, 'Single') !== false ? 'Single' : ''); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Duration (Days)</label><input type="number" min="0" name="visa_duration_days" value="<?php echo htmlspecialchars(document_value($text, ['/(\d+)\s*Days/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Issue Date<span class="required-asterisk">*</span></label><input type="date" name="visa_submission_date" data-required="1" value="<?php echo htmlspecialchars(document_date(document_value($text, ['/Issue\s*Date\s*[:#-]?\s*([0-9.\/-]+)/i', '/Date\s*&\s*Place\s*Of\s*Issue\s*:\s*([0-9.\/-]+)/i']))); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Issue Place</label><input type="text" name="visa_issue_place" value="<?php echo htmlspecialchars(document_value($text, ['/Issue\s*Place\s*[:#-]?\s*([^\n]+)/i', '/Date\s*&\s*Place\s*Of\s*Issue\s*:\s*[0-9.\/-]+\s+([A-Za-z ]+)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Valid Until<span class="required-asterisk">*</span></label><input type="date" name="visa_delivery_date" data-required="1" value="<?php echo htmlspecialchars(document_date(document_value($text, ['/Valid\s*Until\s*[:#-]?\s*([0-9.\/-]+)/i']))); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>UID No.</label><input type="text" name="visa_uid_no" value="<?php echo htmlspecialchars(document_value($text, ['/U\.?I\.?D\.?\s*(?:No\.?)?\s*[:#-]?\s*([A-Z0-9-]+)/i'])); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="visa"><label>Full Name<span class="required-asterisk">*</span></label><input type="text" name="visa_full_name" data-required="1" value="<?php echo htmlspecialchars(document_value($text, ['/\n\s*((?:Mr|Mrs|Ms)\.?\s+[A-Z][A-Z ]+)\s*\n\s*Full\s*Name:/i', '/Full\s*Name\s*:\s*([^\n]+)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Nationality<span class="required-asterisk">*</span></label><input type="text" name="visa_nationality" data-required="1" value="<?php echo htmlspecialchars(document_value($text, ['/Nationality\s*[:#-]?\s*([^\n]+)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Place of Birth</label><input type="text" name="visa_place_of_birth" value="<?php echo htmlspecialchars(document_value($text, ['/Place\s*of\s*Birth\s*[:#-]?\s*([^\n]+)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Date of Birth<span class="required-asterisk">*</span></label><input type="date" name="visa_date_of_birth" data-required="1" value="<?php echo htmlspecialchars(document_date(document_value($text, ['/(?:Date\s*of\s*Birth|DOB)\s*[:#-]?\s*([0-9.\/-]+)/i']))); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Passport Type</label><input type="text" name="visa_passport_type" value="<?php echo htmlspecialchars(document_value($text, ['/Passport\s*(?:No\.?|Number)\s*:\s*([A-Za-z]+)\s*\//i', '/Passport\s*Type\s*[:#-]?\s*([^\n]+)/i'])); ?>"></div>
                        <div class="form-group field-group" data-types="visa"><label>Passport No.<span class="required-asterisk">*</span></label><input type="text" name="visa_passport_no" data-required="1" value="<?php echo htmlspecialchars(document_value($text, ['/Passport\s*(?:No\.?|Number)\s*:\s*[A-Za-z]+\s*\/\s*([A-Z0-9-]+)/i', '/Passport\s*(?:No\.?|Number)\s*[:#-]?\s*([A-Z0-9-]+)/i'])); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="visa"><label>Profession</label><input type="text" name="visa_profession" value="<?php echo htmlspecialchars(document_value($text, ['/Profession\s*[:#-]?\s*([^\n]+)/i'])); ?>"></div>

                        <?php
                        $passport_ocr = extract_passport_document_fields($text);
                        $insurance_ocr = extract_insurance_document_fields($text);
                        $package_ocr = extract_package_document_fields($text);
                        ?>
                        <div class="form-group field-group" data-types="passport"><label>Passport Number<span class="required-asterisk">*</span></label><input type="text" name="passport_number" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['number']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Passport Type</label><input type="text" name="passport_type" value="<?php echo htmlspecialchars($passport_ocr['type']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Issuing Country<span class="required-asterisk">*</span></label><input type="text" name="passport_issuing_country" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['issuing_country']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Country Code</label><input type="text" name="passport_country_code" value="<?php echo htmlspecialchars($passport_ocr['country_code']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="passport"><label>Full Name<span class="required-asterisk">*</span></label><input type="text" name="passport_full_name" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['full_name']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Surname</label><input type="text" name="passport_surname" value="<?php echo htmlspecialchars($passport_ocr['surname']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Given Names</label><input type="text" name="passport_given_names" value="<?php echo htmlspecialchars($passport_ocr['given_names']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Nationality<span class="required-asterisk">*</span></label><input type="text" name="passport_nationality" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['nationality']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Gender</label><input type="text" name="passport_gender" value="<?php echo htmlspecialchars($passport_ocr['gender']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Date of Birth<span class="required-asterisk">*</span></label><input type="date" name="passport_date_of_birth" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['date_of_birth']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Place of Birth</label><input type="text" name="passport_place_of_birth" value="<?php echo htmlspecialchars($passport_ocr['place_of_birth']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Date of Issue<span class="required-asterisk">*</span></label><input type="date" name="passport_date_of_issue" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['date_of_issue']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Date of Expiry<span class="required-asterisk">*</span></label><input type="date" name="passport_date_of_expiry" data-required="1" value="<?php echo htmlspecialchars($passport_ocr['date_of_expiry']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Place of Issue</label><input type="text" name="passport_place_of_issue" value="<?php echo htmlspecialchars($passport_ocr['place_of_issue']); ?>"></div>
                        <div class="form-group field-group" data-types="passport"><label>Authority</label><input type="text" name="passport_authority" value="<?php echo htmlspecialchars($passport_ocr['authority']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="passport"><label>MRZ Line 1</label><input type="text" name="passport_mrz_line1" value="<?php echo htmlspecialchars($passport_ocr['mrz_line1']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="passport"><label>MRZ Line 2</label><input type="text" name="passport_mrz_line2" value="<?php echo htmlspecialchars($passport_ocr['mrz_line2']); ?>"></div>

                        <div class="form-group field-group" data-types="insurance"><label>Policy Number<span class="required-asterisk">*</span></label><input type="text" name="insurance_policy_no" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['policy_no']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Insurance Provider<span class="required-asterisk">*</span></label><input type="text" name="insurance_provider" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['provider']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="insurance"><label>Insured Person Name<span class="required-asterisk">*</span></label><input type="text" name="insurance_insured_name" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['insured_name']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Insurance Type / Plan<span class="required-asterisk">*</span></label><input type="text" name="insurance_coverage_type" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['coverage_type']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Passport Number</label><input type="text" name="insurance_passport_no" value="<?php echo htmlspecialchars($insurance_ocr['passport_no']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Destination Country</label><input type="text" name="insurance_destination" value="<?php echo htmlspecialchars($insurance_ocr['destination']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Policy Issue Date</label><input type="date" name="insurance_issue_date" value="<?php echo htmlspecialchars($insurance_ocr['issue_date']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Coverage Start Date<span class="required-asterisk">*</span></label><input type="date" class="insurance-start-date" name="insurance_start_date" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['start_date']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Coverage End Date<span class="required-asterisk">*</span></label><input type="date" class="insurance-end-date" name="insurance_end_date" data-required="1" value="<?php echo htmlspecialchars($insurance_ocr['end_date']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Number of Days</label><input type="number" min="0" class="insurance-days" name="insurance_days" value="<?php echo (int) $insurance_ocr['days']; ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Sum Insured</label><input type="text" name="insurance_sum_insured" value="<?php echo htmlspecialchars($insurance_ocr['sum_insured']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Premium Amount</label><input type="text" name="insurance_premium_amount" value="<?php echo htmlspecialchars($insurance_ocr['premium']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Emergency Assistance Number</label><input type="text" name="insurance_emergency_no" value="<?php echo htmlspecialchars($insurance_ocr['emergency_no']); ?>"></div>
                        <div class="form-group field-group" data-types="insurance"><label>Certificate Number</label><input type="text" name="insurance_certificate_no" value="<?php echo htmlspecialchars($insurance_ocr['certificate_no']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="insurance"><label>Coverage Details</label><textarea name="insurance_coverage_details" rows="2"><?php echo htmlspecialchars($insurance_ocr['coverage_details']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="insurance"><label>Insurance Remarks</label><textarea name="insurance_remarks" rows="2"><?php echo htmlspecialchars($insurance_ocr['remarks']); ?></textarea></div>

                        <div class="form-group field-group" data-types="package"><label>Package / Voucher Number</label><input type="text" name="package_voucher_no" value="<?php echo htmlspecialchars($package_ocr['voucher_no']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Package Name<span class="required-asterisk">*</span></label><input type="text" name="package_name" data-required="1" value="<?php echo htmlspecialchars($package_ocr['name']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Destination<span class="required-asterisk">*</span></label><input type="text" name="package_destinations" data-required="1" value="<?php echo htmlspecialchars($package_ocr['destination']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Country</label><input type="text" name="package_country" value="<?php echo htmlspecialchars($package_ocr['country']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Passenger / Guest Names<span class="required-asterisk">*</span></label><textarea name="package_guest_names" data-required="1" rows="2"><?php echo htmlspecialchars($package_ocr['guest_names']); ?></textarea></div>
                        <div class="form-group field-group" data-types="package"><label>Travel Start Date<span class="required-asterisk">*</span></label><input type="date" class="package-start-date" name="package_start_date" data-required="1" value="<?php echo htmlspecialchars($package_ocr['start_date']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Travel End Date<span class="required-asterisk">*</span></label><input type="date" class="package-end-date" name="package_end_date" data-required="1" value="<?php echo htmlspecialchars($package_ocr['end_date']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Number of Days</label><input type="number" min="0" class="package-days" name="package_days_count" value="<?php echo (int) $package_ocr['days']; ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Number of Nights</label><input type="number" min="0" class="package-nights" name="package_nights_count" value="<?php echo (int) $package_ocr['nights']; ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Adults<span class="required-asterisk">*</span></label><input type="number" min="1" name="package_adults_count" data-required="1" value="<?php echo (int) $package_ocr['adults']; ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Children</label><input type="number" min="0" name="package_children_count" value="<?php echo (int) $package_ocr['children']; ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Infants</label><input type="number" min="0" name="package_infants_count" value="<?php echo (int) $package_ocr['infants']; ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Hotel / Accommodation</label><input type="text" name="package_accommodation" value="<?php echo htmlspecialchars($package_ocr['accommodation']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Room Type</label><input type="text" name="package_room_type" value="<?php echo htmlspecialchars($package_ocr['room_type']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Meal Plan</label><input type="text" name="package_meals" value="<?php echo htmlspecialchars($package_ocr['meal_plan']); ?>"></div>
                        <div class="form-group field-group" data-types="package"><label>Transportation</label><input type="text" name="package_transportation" value="<?php echo htmlspecialchars($package_ocr['transportation']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Pickup Details</label><textarea name="package_pickup_details" rows="2"><?php echo htmlspecialchars($package_ocr['pickup']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Drop-off Details</label><textarea name="package_dropoff_details" rows="2"><?php echo htmlspecialchars($package_ocr['dropoff']); ?></textarea></div>
                        <div class="form-group field-group" data-types="package"><label>Confirmation Number</label><input type="text" name="package_confirmation_no" value="<?php echo htmlspecialchars($package_ocr['confirmation_no']); ?>"></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Itinerary</label><textarea name="package_itinerary" rows="2"><?php echo htmlspecialchars($package_ocr['itinerary']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Inclusions</label><textarea name="package_inclusions" rows="2"><?php echo htmlspecialchars($package_ocr['inclusions']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Exclusions</label><textarea name="package_exclusions" rows="2"><?php echo htmlspecialchars($package_ocr['exclusions']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Package Details</label><textarea name="package_details" rows="2"><?php echo htmlspecialchars($package_ocr['details']); ?></textarea></div>
                        <div class="form-group field-group col-span-2" data-types="package"><label>Terms and Conditions</label><textarea name="package_terms" rows="2"><?php echo htmlspecialchars($package_ocr['terms']); ?></textarea></div>

                        <div class="form-group field-group col-span-2" data-types="other"><label>Service Name / Type<span class="required-asterisk">*</span></label><input type="text" name="other_service_name" data-required="1"></div>
                        <div class="form-group field-group" data-types="other"><label>Reference No.</label><input type="text" name="other_reference_no"></div>
                        <div class="form-group field-group col-span-2" data-types="other"><label>Passenger / Customer Name</label><input type="text" name="passenger_name"></div>
                        <div class="form-group field-group" data-types="other"><label>Country</label><input type="text" name="other_country"></div>
                        <div class="form-group field-group" data-types="other"><label>City</label><input type="text" name="other_city"></div>
                        <div class="form-group field-group" data-types="other"><label>From</label><input type="text" name="other_from"></div>
                        <div class="form-group field-group" data-types="other"><label>To</label><input type="text" name="other_to"></div>
                        <div class="form-group field-group" data-types="other"><label>Service Date<span class="required-asterisk">*</span></label><input type="date" name="other_service_date" data-required="1"></div>
                        <div class="form-group field-group" data-types="other"><label>End Date</label><input type="date" name="other_end_date"></div>
                        <div class="form-group field-group col-span-4" data-types="other"><label>Service Details</label><textarea name="other_details" rows="2"><?php echo htmlspecialchars($document_type === 'other' ? mb_substr($text, 0, 1000) : ''); ?></textarea></div>
                    </div>
                </div>

                <div class="bottom-split-layout">
                    <!-- Supplier Details Card -->
                    <div class="booking-card">
                        <h2>Supplier Details</h2>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div class="form-group field-group-always">
                                <label for="supplier_name">Supplier<span class="required-asterisk">*</span></label>
                                <input type="text" id="supplier_name" name="supplier_name" placeholder="Supplier Name" required>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group field-group-always">
                                    <label for="buying_cost">Buying Cost (₹)<span class="required-asterisk">*</span></label>
                                    <input type="text" id="buying_cost" name="buying_cost" placeholder="0.00" oninput="calculateProfit()" required>
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
                            </div>
                        </div>
                    </div>

                    <!-- Customer & Selling Details Card -->
                    <div class="booking-card">
                        <h2>Customer & Selling Details</h2>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px;">
                                <div class="form-group field-group-always">
                                    <label for="customer_type">Customer Type<span class="required-asterisk">*</span></label>
                                    <select id="customer_type" name="customer_type" required>
                                        <option value="">Select Type</option>
                                        <option value="Walk-in Customer">Walk-in Customer</option>
                                        <option value="B2B">B2B</option><option value="Corporate">Corporate</option><option value="User">User</option><option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group field-group-always">
                                    <label for="customer_name">Customer / Agency<span class="required-asterisk">*</span></label>
                                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($_GET['customer_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group field-group-always"><label for="customer_mobile">Phone / Mobile</label><input type="text" id="customer_mobile" name="customer_mobile" value="<?php echo htmlspecialchars($_GET['mobile'] ?? ''); ?>"></div>
                                <div class="form-group field-group-always"><label for="customer_email">Email</label><input type="email" id="customer_email" name="customer_email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>"></div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group field-group-always">
                                    <label for="selling_cost">Selling Cost (₹)<span class="required-asterisk">*</span></label>
                                    <input type="text" id="selling_cost" name="selling_cost" placeholder="0.00" oninput="calculateProfit()" required>
                                </div>
                                <div class="form-group field-group-always">
                                    <label for="profit">Profit Margin (₹)</label>
                                    <input type="text" id="profit" name="profit" placeholder="0.00" readonly style="background: #f1f5f9; font-weight: 700; color: #10b981;">
                                </div>
                            </div>
                            <div class="form-group field-group-always">
                                <label for="remarks">Remarks</label>
                                <textarea id="remarks" name="remarks" rows="2" placeholder="Remarks" style="min-height: 36px;"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="service_type" name="service_type" value="Flight">
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const zone = document.getElementById('ticket-drop-zone');
    const input = document.getElementById('ticket');
    const preview = document.getElementById('ticket-preview');
    const errorEl = document.getElementById('ticket-upload-error');
    const uploadForm = input ? input.form : null;
    const extractButton = document.getElementById('extract-btn');
    if (!zone || !input) return;

    const allowedTypes = ['image/', 'application/pdf'];
    const maxSize = 10 * 1024 * 1024; // 10MB
    let extractionStarted = false;

    function showError(msg) {
        if (errorEl) errorEl.textContent = msg || '';
    }

    function isAllowed(file) {
        if (file.type === 'application/pdf') return true;
        return allowedTypes.some(t => file.type.startsWith(t));
    }

    function startAutomaticExtraction() {
        if (extractionStarted || !uploadForm) return;
        extractionStarted = true;
        zone.setAttribute('aria-busy', 'true');

        const status = document.createElement('div');
        status.style.marginTop = '8px';
        status.style.color = '#2563eb';
        status.style.fontWeight = '700';
        status.textContent = 'Extracting document automatically...';
        preview.appendChild(status);

        if (extractButton && typeof uploadForm.requestSubmit === 'function') {
            uploadForm.requestSubmit(extractButton);
            return;
        }

        const extractionFlag = document.createElement('input');
        extractionFlag.type = 'hidden';
        extractionFlag.name = 'extract_ticket';
        extractionFlag.value = '1';
        uploadForm.appendChild(extractionFlag);
        uploadForm.submit();
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

        startAutomaticExtraction();
    }

    function detectTypeFromFilename(filename) {
        const name = filename.toLowerCase();
        if (name.includes('visa')) return 'visa';
        if (name.includes('passport')) return 'passport';
        if (name.includes('insurance') || name.includes('policy')) return 'insurance';
        if (name.includes('package') || name.includes('tour') || name.includes('holiday')) return 'package';
        if (name.includes('hotel') || name.includes('voucher')) return 'hotel';
        if (name.includes('ticket') || name.includes('flight') || name.includes('pnr')) return 'ticket';
        return 'other';
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
let manualBookingMode = false;

function showManualBooking() {
    const detailsForm = document.getElementById('booking-details-form');
    const documentTypeGroup = document.getElementById('doc-type-group');
    const documentTypeSelect = document.getElementById('document_type');
    const saveButton = document.getElementById('header-save-booking');
    const manualButton = document.getElementById('manual-booking-btn');
    const uploadArea = document.getElementById('booking-upload-area');
    const entryTitle = document.getElementById('booking-entry-title');
    const entryDescription = document.getElementById('booking-entry-description');
    const detectedMessage = document.getElementById('detected-type-msg');

    manualBookingMode = true;
    if (uploadArea) uploadArea.style.display = 'none';
    if (entryTitle) entryTitle.textContent = 'Manual Booking';
    if (entryDescription) entryDescription.textContent = 'Select a service type to display the booking details form.';
    if (detailsForm) detailsForm.style.display = 'none';
    if (documentTypeGroup) documentTypeGroup.style.display = 'block';
    if (saveButton) saveButton.style.display = 'none';
    if (manualButton) manualButton.style.display = 'none';
    if (detectedMessage) detectedMessage.textContent = '';

    document.querySelectorAll('input[name="booking_mode"]').forEach(function(input) {
        input.value = 'manual';
    });
    document.querySelectorAll('input[name="ticket_path"]').forEach(function(input) {
        input.value = '';
    });

    if (documentTypeSelect) {
        documentTypeSelect.value = '';
        document.querySelectorAll('input[name="document_type"]').forEach(function(input) {
            input.value = '';
        });
        documentTypeSelect.focus();
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

function calculateProfit() {
    var buying = parseFloat(document.getElementById('buying_cost').value) || 0;
    var selling = parseFloat(document.getElementById('selling_cost').value) || 0;
    
    var profit = selling - buying;
    var profitInput = document.getElementById('profit');
    if (profitInput) {
        profitInput.value = profit.toFixed(2);
    }
}

const typeLabels = {
    ticket: 'Flight Ticket',
    hotel: 'Hotel',
    visa: 'Visa',
    passport: 'Passport',
    insurance: 'Insurance',
    package: 'Tour Package',
    other: 'Other Service',
};

function applyDocumentType(type) {
    if (!type) return;

    const docTypeSelect = document.getElementById('document_type');
    const detectedMsg = document.getElementById('detected-type-msg');
    const heading = document.getElementById('auto-fields-heading');

    if (docTypeSelect) docTypeSelect.value = type;
    if (detectedMsg) detectedMsg.textContent = 'Showing fields for: ' + (typeLabels[type] || type);
    if (heading) heading.textContent = (typeLabels[type] || 'Document') + ' Fields';

    const detailsTitle = document.getElementById('booking-details-title');
    if (detailsTitle) {
        // Keep the SVG check circle in the header
        detailsTitle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px; color: #10b981; display: inline-block; vertical-align: middle; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> ' + (typeLabels[type] || 'Booking') + ' Details';
    }

    // Sync all hidden input document_type and service_type values
    document.querySelectorAll('input[name="document_type"]').forEach(function(input) {
        input.value = type;
    });
    document.querySelectorAll('input[name="service_type"]').forEach(function(input) {
        input.value = typeLabels[type] || type;
    });

    document.querySelectorAll('.field-group').forEach(function(row) {
        const allowed = (row.getAttribute('data-types') || '').split(',');
        const active = allowed.includes(type);
        row.style.display = active ? '' : 'none';
        row.querySelectorAll('input, select, textarea').forEach(function(control) {
            control.disabled = !active;
            control.required = active && control.getAttribute('data-required') === '1';
        });
    });

    document.querySelectorAll('.field-group-always').forEach(function(row) {
        row.style.display = '';
    });

    const extractBtn = document.getElementById('extract-btn');
    if (extractBtn) {
        extractBtn.textContent = 'Extract ' + (typeLabels[type] || type);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const docTypeSelect = document.getElementById('document_type');

    // Hide document-specific fields until type is chosen
    document.querySelectorAll('.field-group').forEach(function(row) {
        row.style.display = 'none';
        row.querySelectorAll('input, select, textarea').forEach(function(control) {
            control.disabled = true;
            control.required = false;
        });
    });

    if (docTypeSelect) {
        docTypeSelect.addEventListener('change', function() {
            if (manualBookingMode) {
                const detailsForm = document.getElementById('booking-details-form');
                const saveButton = document.getElementById('header-save-booking');
                const hasServiceType = this.value !== '';
                if (detailsForm) detailsForm.style.display = hasServiceType ? 'block' : 'none';
                if (saveButton) saveButton.style.display = hasServiceType ? '' : 'none';
                if (hasServiceType) {
                    applyDocumentType(this.value);
                    setTimeout(function() {
                        detailsForm?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 50);
                }
                return;
            }
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

    function bindDateRange(startSelector, endSelector, daysSelector, nightsSelector, inclusiveDays) {
        const start = document.querySelector(startSelector);
        const end = document.querySelector(endSelector);
        const days = document.querySelector(daysSelector);
        const nights = nightsSelector ? document.querySelector(nightsSelector) : null;
        function updateDuration() {
            if (!start || !end || !start.value || !end.value) return;
            const difference = Math.round((new Date(end.value + 'T00:00:00') - new Date(start.value + 'T00:00:00')) / 86400000);
            if (difference < 0) return;
            if (days) days.value = difference + (inclusiveDays ? 1 : 0);
            if (nights) nights.value = difference;
        }
        if (start) start.addEventListener('change', updateDuration);
        if (end) end.addEventListener('change', updateDuration);
    }
    bindDateRange('.insurance-start-date', '.insurance-end-date', '.insurance-days', null, true);
    bindDateRange('.package-start-date', '.package-end-date', '.package-days', '.package-nights', true);

    const detailsFormContainer = document.getElementById('booking-details-form');
    if (detailsFormContainer && "<?php echo (!empty($ticket_path) || $booking_enquiry) ? '1' : ''; ?>") {
        setTimeout(function() {
            detailsFormContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
    
    if (detailsFormContainer) {
        detailsFormContainer.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            
            e.preventDefault();
            
            // Find submit button in the form, in the header, or matching the form ID attribute
            const submitBtn = form.querySelector('button[name="save_booking"]') || 
                              document.querySelector('button[name="save_booking"][form="' + form.id + '"]') || 
                              document.querySelector('button[name="save_booking"]');
            
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
                    if (data.enquiry_id) {
                        window.location.href = '../enquiry/view.php?id=' + encodeURIComponent(data.enquiry_id) + '&success=converted';
                        return;
                    }
                    const card = form.querySelector('.booking-card') || form.querySelector('.card') || form;
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const prefill = <?php echo json_encode($booking_enquiry_prefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (!prefill || !Object.keys(prefill).length) return;
    const setAll = (name, value, force = false) => {
        if (value === null || value === undefined || value === '') return;
        document.querySelectorAll('[name="' + name + '"]').forEach(el => {
            if (force || !el.value) el.value = value;
        });
    };
    const typeMap = {Flight:'ticket',Hotel:'hotel',Visa:'visa',Passport:'passport',Insurance:'insurance','Tour Package':'package',Other:'other'};
    const type = typeMap[prefill.service_type] || 'other';
    document.querySelectorAll('[name="document_type"]').forEach(el => {
        if (el.tagName === 'SELECT') { el.value = type; el.dispatchEvent(new Event('change', {bubbles:true})); }
    });
    const apply = () => {
        setAll('customer_name', prefill.customer_name); setAll('customer_mobile', prefill.mobile); setAll('customer_email', prefill.email); setAll('customer_type', 'Walk-in Customer', true);
        setAll('passenger_name', prefill.customer_name);
        setAll('from_city', prefill.from_location); setAll('to_city', prefill.to_location); setAll('departure_date', prefill.service_date);
        setAll('other_from', prefill.from_location); setAll('other_to', prefill.to_location); setAll('other_service_date', prefill.service_date);
        setAll('other_service_name', prefill.service_type === 'Other' ? 'Other Service' : prefill.service_type);
        setAll('hotel_city', prefill.to_location || prefill.from_location); setAll('hotel_check_in', prefill.service_date); setAll('hotel_guest_names', prefill.customer_name);
        setAll('visa_country', prefill.to_location); setAll('visa_submission_date', prefill.service_date); setAll('visa_full_name', prefill.customer_name);
        setAll('passport_full_name', prefill.customer_name);
        setAll('insurance_destination', prefill.to_location); setAll('insurance_start_date', prefill.service_date); setAll('insurance_insured_name', prefill.customer_name);
        setAll('package_destinations', prefill.to_location); setAll('package_start_date', prefill.service_date); setAll('package_guest_names', prefill.customer_name);
        setAll('package_adults_count', prefill.passenger_count, true); setAll('hotel_adults_count', prefill.passenger_count, true); setAll('no_of_adults', prefill.passenger_count, true);
        setAll('selling_cost', prefill.selling_cost); setAll('assigned_user', prefill.assigned_user);
        const notes = [prefill.remarks || '', prefill.confirmation_note || '', prefill.passenger_count ? 'Passenger count: ' + prefill.passenger_count : ''].filter(Boolean).join('\n');
        setAll('remarks', notes.trim()); setAll('other_details', notes.trim());
    };
    apply(); setTimeout(apply, 150);
});
</script>
</body>
</html>
