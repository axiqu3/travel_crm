<?php

/**
 * Shared Booking List export helpers.
 *
 * The project intentionally has no spreadsheet dependency. These helpers write
 * the small subset of XLSX/SpreadsheetML needed by the booking export and use
 * stored ZIP entries, so they also work when PHP's optional zip extension is
 * unavailable.
 */

function booking_export_service_case_sql($alias = 'b')
{
    $document = "LOWER(TRIM(COALESCE($alias.document_type, '')))";
    $service = "LOWER(TRIM(COALESCE($alias.service_type, '')))";

    return "CASE
        WHEN $document = 'hotel' THEN 'hotel'
        WHEN $document = 'visa' THEN 'visa'
        WHEN $document = 'passport' THEN 'passport'
        WHEN $document = 'insurance' THEN 'insurance'
        WHEN $document IN ('package', 'tour package', 'holiday') THEN 'package'
        WHEN $document = 'other' THEN 'other'
        WHEN $service IN ('hotel', 'hotel voucher') THEN 'hotel'
        WHEN $service = 'visa' THEN 'visa'
        WHEN $service = 'passport' THEN 'passport'
        WHEN $service IN ('insurance', 'travel insurance') THEN 'insurance'
        WHEN $service IN ('package', 'tour package', 'holiday package') THEN 'package'
        WHEN $service IN ('other', 'other service') THEN 'other'
        WHEN $service IN ('ticket', 'flight', 'flight ticket') THEN 'flight'
        WHEN $document IN ('ticket', 'flight', 'flight ticket') AND $service = '' THEN 'flight'
        ELSE 'unknown'
    END";
}

function booking_export_service_label(array $booking)
{
    $document = strtolower(trim((string) ($booking['document_type'] ?? '')));
    $service = strtolower(trim((string) ($booking['service_type'] ?? '')));

    if ($document === 'hotel') return 'Hotel';
    if ($document === 'visa') return 'Visa';
    if ($document === 'passport') return 'Passport';
    if ($document === 'insurance') return 'Insurance';
    if (in_array($document, ['package', 'tour package', 'holiday'], true)) return 'Tour Package';
    if ($document === 'other') return 'Other Service';
    if (in_array($service, ['hotel', 'hotel voucher'], true)) return 'Hotel';
    if ($service === 'visa') return 'Visa';
    if ($service === 'passport') return 'Passport';
    if (in_array($service, ['insurance', 'travel insurance'], true)) return 'Insurance';
    if (in_array($service, ['package', 'tour package', 'holiday package'], true)) return 'Tour Package';
    if (in_array($service, ['other', 'other service'], true)) return 'Other Service';
    if (in_array($service, ['ticket', 'flight', 'flight ticket'], true)) return 'Flight Ticket';
    if (in_array($document, ['ticket', 'flight', 'flight ticket'], true) && $service === '') return 'Flight Ticket';

    return trim((string) ($booking['service_type'] ?? '')) ?: 'Other Service';
}

function booking_export_columns($service)
{
    $common = [
        'serial_no' => 'Serial No.',
        'booking_date' => 'Booking Date',
        'export_service_label' => 'Document/Service Type',
        'customer_type' => 'Customer Type',
        'customer_name' => 'Customer/Agency',
        'customer_phone' => 'Customer Phone',
        'customer_email' => 'Customer Email',
        'supplier_name' => 'Supplier',
        'buying_cost' => 'Buying Cost',
        'selling_cost' => 'Selling Cost',
        'profit' => 'Profit',
        'payment_method' => 'Payment Method',
        'assigned_user' => 'Assigned Agent',
        'remarks' => 'Remarks',
    ];

    $specific = [
        'flight' => [
            'passenger_name' => 'Passenger Name',
            'pnr' => 'PNR',
            'ticket_number' => 'Ticket Number',
            'flight_number' => 'Flight Number',
            'airline_name' => 'Airline',
            'from_city' => 'Origin',
            'to_city' => 'Destination',
            'departure_date' => 'Departure Date',
            'departure_time' => 'Departure Time',
            'arrival_date' => 'Arrival Date',
            'arrival_time' => 'Arrival Time',
        ],
        'hotel' => [
            'hotel_confirmation_no' => 'Booking/Voucher ID',
            'hotel_name' => 'Hotel Name',
            'hotel_country' => 'Country',
            'hotel_city' => 'City',
            'hotel_address' => 'Address',
            'hotel_check_in' => 'Check In',
            'hotel_check_out' => 'Check Out',
            'hotel_nights_count' => 'Nights',
            'hotel_rooms_count' => 'Rooms',
            'hotel_room_type' => 'Room Type',
            'hotel_meal_plan' => 'Meal Plan',
            'hotel_adults_count' => 'Adults',
            'hotel_children_count' => 'Children',
            'hotel_guest_names' => 'Guest Names',
            'hotel_booking_details' => 'Booking Details',
        ],
        'visa' => [
            'visa_app_no' => 'Entry Permit Number',
            'visa_type' => 'Visa Type',
            'visa_country' => 'Destination Country',
            'visa_entry_type' => 'Entry Type',
            'visa_duration_days' => 'Duration',
            'visa_submission_date' => 'Issue Date',
            'visa_issue_place' => 'Issue Place',
            'visa_delivery_date' => 'Valid Until',
            'visa_uid_no' => 'UID Number',
            'visa_full_name' => 'Full Name',
            'visa_nationality' => 'Nationality',
            'visa_place_of_birth' => 'Place of Birth',
            'visa_date_of_birth' => 'Date of Birth',
            'visa_passport_type' => 'Passport Type',
            'visa_passport_no' => 'Passport Number',
            'visa_profession' => 'Profession',
        ],
        'passport' => [
            'passport_number' => 'Passport Number', 'passport_type' => 'Passport Type',
            'passport_issuing_country' => 'Issuing Country', 'passport_country_code' => 'Country Code',
            'passport_full_name' => 'Full Name', 'passport_surname' => 'Surname',
            'passport_given_names' => 'Given Names', 'passport_nationality' => 'Nationality',
            'passport_gender' => 'Gender', 'passport_date_of_birth' => 'Date of Birth',
            'passport_place_of_birth' => 'Place of Birth', 'passport_date_of_issue' => 'Date of Issue',
            'passport_date_of_expiry' => 'Date of Expiry', 'passport_place_of_issue' => 'Place of Issue',
            'passport_authority' => 'Authority', 'passport_mrz_line1' => 'MRZ Line 1', 'passport_mrz_line2' => 'MRZ Line 2',
        ],
        'insurance' => [
            'insurance_policy_no' => 'Policy Number', 'insurance_provider' => 'Insurance Provider',
            'insurance_insured_name' => 'Insured Person', 'insurance_coverage_type' => 'Insurance Type/Plan',
            'insurance_passport_no' => 'Passport Number', 'insurance_destination' => 'Destination',
            'insurance_issue_date' => 'Policy Issue Date', 'insurance_start_date' => 'Coverage Start',
            'insurance_end_date' => 'Coverage End', 'insurance_days' => 'Number of Days',
            'insurance_sum_insured' => 'Sum Insured', 'insurance_premium_amount' => 'Premium Amount',
            'insurance_emergency_no' => 'Emergency Assistance', 'insurance_certificate_no' => 'Certificate Number',
            'insurance_coverage_details' => 'Coverage Details', 'insurance_remarks' => 'Insurance Remarks',
        ],
        'package' => [
            'package_voucher_no' => 'Package/Voucher Number', 'package_name' => 'Package Name',
            'package_destinations' => 'Destination', 'package_country' => 'Country',
            'package_guest_names' => 'Passenger/Guest Names', 'package_start_date' => 'Travel Start',
            'package_end_date' => 'Travel End', 'package_days_count' => 'Days', 'package_nights_count' => 'Nights',
            'package_adults_count' => 'Adults', 'package_children_count' => 'Children', 'package_infants_count' => 'Infants',
            'package_accommodation' => 'Hotel/Accommodation', 'package_room_type' => 'Room Type',
            'package_meals' => 'Meal Plan', 'package_transportation' => 'Transportation',
            'package_pickup_details' => 'Pickup Details', 'package_dropoff_details' => 'Drop-off Details',
            'package_confirmation_no' => 'Confirmation Number', 'package_itinerary' => 'Itinerary',
            'package_inclusions' => 'Inclusions', 'package_exclusions' => 'Exclusions',
            'package_details' => 'Package Details', 'package_terms' => 'Terms and Conditions',
        ],
        'other' => [
            'other_service_name' => 'Service Name/Type',
            'other_reference_no' => 'Reference Number',
            'passenger_name' => 'Passenger/Customer Name',
            'other_country' => 'Country',
            'other_city' => 'City',
            'other_from' => 'From',
            'other_to' => 'To',
            'other_service_date' => 'Service Date',
            'other_end_date' => 'End Date',
            'other_details' => 'Service Details',
        ],
    ];

    if ($service === 'all') {
        return array_merge($common, $specific['flight'], $specific['hotel'], $specific['visa'], $specific['passport'], $specific['insurance'], $specific['package'], $specific['other']);
    }

    return array_merge($common, $specific[$service]);
}

function booking_export_safe_text($value)
{
    $value = (string) ($value ?? '');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

    // Excel treats these prefixes as formulas even in data imported as text.
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        $value = "'" . $value;
    }

    return $value;
}

function booking_export_column_name($number)
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function booking_export_xml_text($value)
{
    return htmlspecialchars(booking_export_safe_text($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function booking_export_field_value(array $booking, $field)
{
    $fallbacks = [
        'hotel_confirmation_no' => ['hotel_confirmation_no', 'hotel_conf_no', 'voucher_number', 'booking_ref'],
        'hotel_name' => ['hotel_name', 'hotel'],
        'hotel_country' => ['hotel_country', 'country'],
        'hotel_city' => ['hotel_city', 'hotel_location', 'city'],
        'hotel_check_in' => ['hotel_check_in', 'check_in'],
        'hotel_check_out' => ['hotel_check_out', 'check_out'],
        'hotel_nights_count' => ['hotel_nights_count', 'no_of_nights'],
        'hotel_rooms_count' => ['hotel_rooms_count', 'no_of_rooms'],
        'hotel_room_type' => ['hotel_room_type', 'room_type'],
        'hotel_meal_plan' => ['hotel_meal_plan', 'meals_plan'],
        'hotel_adults_count' => ['hotel_adults_count', 'no_of_adults'],
        'hotel_children_count' => ['hotel_children_count', 'no_of_children'],
        'hotel_guest_names' => ['hotel_guest_names', 'guests', 'passenger_name'],
        'hotel_booking_details' => ['hotel_booking_details', 'booking_details'],
        'visa_submission_date' => ['visa_submission_date', 'visa_valid_from'],
        'visa_delivery_date' => ['visa_delivery_date', 'visa_valid_to'],
        'visa_full_name' => ['visa_full_name', 'passenger_name'],
        'other_service_name' => ['other_service_name', 'other_service_type', 'service_type'],
        'other_reference_no' => ['other_reference_no', 'ref_no', 'booking_ref'],
        'other_service_date' => ['other_service_date', 'from_date'],
        'other_details' => ['other_details', 'remarks'],
    ];

    foreach ($fallbacks[$field] ?? [$field] as $candidate) {
        if (isset($booking[$candidate]) && $booking[$candidate] !== '') {
            return $booking[$candidate];
        }
    }
    return '';
}

function booking_export_build_sheet(array $columns, array $bookings)
{
    $numeric_fields = [
        'buying_cost', 'selling_cost', 'profit', 'insurance_days', 'insurance_sum_insured',
        'insurance_premium_amount', 'package_days_count', 'package_nights_count',
        'package_adults_count', 'package_children_count', 'package_infants_count'
    ];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
    $xml .= '<cols>';
    foreach ($columns as $field => $heading) {
        $maxLength = mb_strlen($heading, 'UTF-8');
        foreach ($bookings as $booking) {
            $value = $field === 'export_service_label'
                ? booking_export_service_label($booking)
                : booking_export_field_value($booking, $field);
            $maxLength = max($maxLength, mb_strlen((string) $value, 'UTF-8'));
        }
        $width = max(12, min(40, $maxLength + 2));
        $index = array_search($field, array_keys($columns), true);
        $columnNumber = $index + 1;
        $xml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    $xml .= '<row r="1" ht="24" customHeight="1">';
    $columnNumber = 1;
    foreach ($columns as $heading) {
        $cell = booking_export_column_name($columnNumber++) . '1';
        $xml .= '<c r="' . $cell . '" t="inlineStr" s="1"><is><t>' . booking_export_xml_text($heading) . '</t></is></c>';
    }
    $xml .= '</row>';

    $rowNumber = 2;
    foreach ($bookings as $booking) {
        $booking['export_service_label'] = booking_export_service_label($booking);
        $xml .= '<row r="' . $rowNumber . '">';
        $columnNumber = 1;
        foreach ($columns as $field => $heading) {
            $cell = booking_export_column_name($columnNumber++) . $rowNumber;
            $value = booking_export_field_value($booking, $field);
            if (in_array($field, $numeric_fields, true) && $value !== '' && $value !== null && is_numeric($value)) {
                $xml .= '<c r="' . $cell . '" s="2"><v>' . (float) $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . booking_export_xml_text($value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $rowNumber++;
    }

    $lastColumn = booking_export_column_name(count($columns));
    $lastRow = max(1, $rowNumber - 1);
    $xml .= '</sheetData><autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>';
    $xml .= '</worksheet>';
    return $xml;
}

function booking_export_zip(array $files)
{
    $output = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', $name);
        $crc = crc32($contents);
        $size = strlen($contents);
        $nameLength = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0);
        $output .= $local . $name . $contents;

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local) + $nameLength + $size;
        $count++;
    }

    $centralOffset = strlen($output);
    $output .= $central;
    $output .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $centralOffset, 0);
    return $output;
}

function booking_export_xlsx(array $columns, array $bookings)
{
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Travel CRM</dc:creator><cp:lastModifiedBy>Travel CRM</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Travel CRM</Application></Properties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Bookings" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F2747"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => booking_export_build_sheet($columns, $bookings),
    ];

    return booking_export_zip($files);
}
