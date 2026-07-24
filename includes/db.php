<?php

$db_config = [
    'host' => '127.0.0.1',
    'username' => 'root',
    'password' => '',
    'database' => 'travel_crm',
    'port' => 3307,
];

$local_config_file = __DIR__ . '/db.local.php';
if (is_file($local_config_file)) {
    $local_config = require $local_config_file;
    if (is_array($local_config)) {
        $db_config = array_merge($db_config, $local_config);
    }
}

$db = mysqli_connect(
    $db_config['host'],
    $db_config['username'],
    $db_config['password'],
    $db_config['database'],
    (int) $db_config['port']
);

if (!$db) {
    die('Database Error');
}


function ensure_column_exists($db, $table, $column, $definition) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

    if ($table === '' || $column === '') {
        return false;
    }

    $result = mysqli_query($db, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result && mysqli_num_rows($result) > 0) {
        return true;
    }

    return mysqli_query($db, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function ensure_index_exists($db, $table, $index_name, $definition) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $index_name = preg_replace('/[^a-zA-Z0-9_]/', '', $index_name);

    if ($table === '' || $index_name === '') {
        return false;
    }

    $result = mysqli_query($db, "SHOW INDEX FROM `$table` WHERE Key_name = '$index_name'");
    if ($result && mysqli_num_rows($result) > 0) {
        return true;
    }

    return mysqli_query($db, "ALTER TABLE `$table` ADD INDEX `$index_name` ($definition)");
}


// Auto-migrate schema on demand or if not yet initialized
$flag_file = __DIR__ . '/db_initialized.flag';
if (file_exists($flag_file) && !isset($_GET['run_migrations'])) {
    goto skip_migrations;
}

// Auto-create enquiries table if not exists
mysqli_query($db, "CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    mobile VARCHAR(50) DEFAULT '',
    email VARCHAR(255) DEFAULT '',
    subject VARCHAR(255) DEFAULT '',
    description TEXT,
    source VARCHAR(100) DEFAULT 'Direct',
    status VARCHAR(50) DEFAULT 'New',
    assigned_user VARCHAR(255) DEFAULT '',
    created_by VARCHAR(255) DEFAULT '',
    notified TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Auto-create customer_master table if not exists
mysqli_query($db, "CREATE TABLE IF NOT EXISTS customer_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_type VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    mobile VARCHAR(100) DEFAULT '',
    email VARCHAR(255) DEFAULT '',
    address TEXT DEFAULT NULL,
    company_name VARCHAR(255) DEFAULT '',
    gst_number VARCHAR(100) DEFAULT '',
    created_by VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

ensure_index_exists($db, 'customer_master', 'idx_customer_master_mobile', 'mobile');
ensure_index_exists($db, 'customer_master', 'idx_customer_master_email', 'email');
ensure_index_exists($db, 'customers', 'idx_customers_mobile', 'mobile');
ensure_index_exists($db, 'customers', 'idx_customers_email', 'email');

ensure_column_exists($db, 'enquiries', 'notified', 'TINYINT DEFAULT 0');
ensure_column_exists($db, 'enquiries', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
ensure_column_exists($db, 'enquiries', 'service_type', 'VARCHAR(50) NULL');
ensure_column_exists($db, 'enquiries', 'from_location', 'VARCHAR(150) NULL');
ensure_column_exists($db, 'enquiries', 'to_location', 'VARCHAR(150) NULL');
ensure_column_exists($db, 'enquiries', 'travel_date', 'DATE NULL');
ensure_column_exists($db, 'enquiries', 'passenger_count', "INT NOT NULL DEFAULT 1");
ensure_column_exists($db, 'enquiries', 'priority', "VARCHAR(20) NOT NULL DEFAULT 'Medium'");
ensure_column_exists($db, 'enquiries', 'next_follow_up_at', 'DATETIME NULL');
ensure_column_exists($db, 'enquiries', 'confirmed_at', 'DATETIME NULL');
ensure_column_exists($db, 'enquiries', 'final_service_date', 'DATE NULL');
ensure_column_exists($db, 'enquiries', 'final_selling_amount', 'DECIMAL(12,2) NULL');
ensure_column_exists($db, 'enquiries', 'confirmation_note', 'TEXT NULL');
ensure_column_exists($db, 'enquiries', 'booking_id', 'INT NULL');
ensure_column_exists($db, 'bookings', 'customer_master_id', 'INT NULL');
ensure_column_exists($db, 'customer_master', 'status', "VARCHAR(50) DEFAULT 'Active'");
ensure_column_exists($db, 'enquiry_messages', 'media_path', 'VARCHAR(255) NULL DEFAULT NULL');
ensure_column_exists($db, 'enquiry_messages', 'media_type', "VARCHAR(50) DEFAULT 'none'");
ensure_column_exists($db, 'enquiry_messages', 'original_filename', 'VARCHAR(255) NULL DEFAULT NULL');
ensure_column_exists($db, 'enquiry_messages', 'media_duration', 'INT DEFAULT 0');
ensure_column_exists($db, 'users', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
ensure_column_exists($db, 'users', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
ensure_column_exists($db, 'users', 'updated_by', "VARCHAR(255) DEFAULT ''");
ensure_column_exists($db, 'users', 'phone', "VARCHAR(50) DEFAULT NULL");
ensure_column_exists($db, 'users', 'address', "TEXT DEFAULT NULL");
ensure_column_exists($db, 'users', 'city', "VARCHAR(100) DEFAULT NULL");
ensure_column_exists($db, 'users', 'state', "VARCHAR(100) DEFAULT NULL");
ensure_column_exists($db, 'users', 'country', "VARCHAR(100) DEFAULT NULL");
ensure_column_exists($db, 'users', 'zip_code', "VARCHAR(20) DEFAULT NULL");
ensure_column_exists($db, 'users', 'dob', "DATE DEFAULT NULL");
ensure_column_exists($db, 'users', 'profile_completed', "TINYINT(1) DEFAULT 0");

// WhatsApp URL generator helper
function get_whatsapp_url($mobile, $message = '') {
    // Clean all non-digit characters
    $clean = preg_replace('/[^0-9]/', '', $mobile);
    if (empty($clean)) {
        return '';
    }
    // If length is 10, prepend Indian country code 91
    if (strlen($clean) === 10) {
        $clean = '91' . $clean;
    } elseif (strlen($clean) === 11 && strpos($clean, '0') === 0) {
        // If 11 digits starting with 0, replace 0 with 91
        $clean = '91' . substr($clean, 1);
    }
    
    $url = 'https://wa.me/' . $clean;
    if (!empty($message)) {
        $url .= '?text=' . urlencode($message);
    }
    return $url;
}

// Helper to generate SQL condition matching various formatting styles of a mobile number
function get_mobile_matching_sql($db, $mobile_field, $search_mobile) {
    $clean = preg_replace('/[^0-9]/', '', $search_mobile);
    if (empty($clean)) {
        return "1=0";
    }
    
    $db_clean_expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($mobile_field, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
    
    $len = strlen($clean);
    if ($len >= 10) {
        $last_10 = substr($clean, -10);
        $escaped_last_10 = mysqli_real_escape_string($db, $last_10);
        return "($mobile_field IS NOT NULL AND $mobile_field <> '' AND RIGHT($db_clean_expr, 10) = '$escaped_last_10')";
    } elseif ($len >= 9) {
        $last_9 = substr($clean, -9);
        $escaped_last_9 = mysqli_real_escape_string($db, $last_9);
        return "($mobile_field IS NOT NULL AND $mobile_field <> '' AND RIGHT($db_clean_expr, 9) = '$escaped_last_9')";
    } else {
        $escaped_clean = mysqli_real_escape_string($db, $clean);
        return "($mobile_field IS NOT NULL AND $mobile_field <> '' AND $db_clean_expr = '$escaped_clean')";
    }
}

// Generate the WhatsApp dropdown HTML component
function get_whatsapp_dropdown($mobile, $vars = []) {
    $clean = preg_replace('/[^0-9]/', '', $mobile);
    if (empty($clean)) {
        return '<span class="wa-no-number">No WhatsApp Number</span>';
    }
    
    $attrs = ' data-mobile="' . htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') . '"';
    $fields = ['customer', 'passenger', 'pnr', 'travel-date', 'service', 'balance', 'booking-no'];
    foreach ($fields as $field) {
        $val = isset($vars[$field]) ? $vars[$field] : '';
        $attrs .= ' data-' . $field . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
    }
    
    $html = '<div class="wa-dropdown-container">';
    $html .= '  <button class="wa-btn" type="button"' . $attrs . '>';
    $html .= '    <svg class="wa-icon" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">';
    $html .= '      <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.977h.004c4.368 0 7.927-3.56 7.93-7.928a7.886 7.886 0 0 0-2.327-5.615zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.69-4.98c-.204-.104-1.207-.596-1.394-.664-.189-.07-.326-.104-.462.104-.137.207-.53.664-.65.804-.12.137-.24.154-.444.053-.204-.1-.864-.319-1.646-1.018-.607-.542-1.018-1.213-1.137-1.418-.12-.204-.013-.315.088-.416.09-.091.204-.24.306-.36.1-.12.133-.2.2-.333.067-.133.033-.25-.017-.35-.05-.1-462-1.114-.63-1.523-.164-.397-.335-.343-.462-.35-.126-.007-.271-.007-.416-.007a.81.81 0 0 0-.588.275c-.204.207-.78.761-.78 1.857 0 1.095.8 2.153.91 2.302.112.15 1.573 2.4 3.81 3.364.533.23 1.0.367 1.343.475.534.17 1.02.146 1.402.089.426-.064 1.207-.493 1.378-.967.172-.474.172-.88.12-.967-.05-.084-.189-.133-.393-.237z"/>';
    $html .= '    </svg>';
    $html .= '    Open WhatsApp ▼';
    $html .= '  </button>';
    $html .= '  <div class="wa-dropdown-menu">';
    $html .= '    <a class="wa-opt" data-template="booking_confirmation">Booking Confirmation</a>';
    $html .= '    <a class="wa-opt" data-template="payment_reminder">Payment Reminder</a>';
    $html .= '    <a class="wa-opt" data-template="follow_up">Follow-up</a>';
    $html .= '    <a class="wa-opt" data-template="general_greeting">General Greeting</a>';
    $html .= '    <div class="wa-divider"></div>';
    $html .= '    <a class="wa-opt" data-template="custom_message">Custom Message</a>';
    $html .= '  </div>';
    $html .= '</div>';
    return $html;
}

// Auto-ensure customer_type column in bookings table
ensure_column_exists($db, 'bookings', 'customer_type', "VARCHAR(100) DEFAULT 'Walk-in Customer'");

// Auto-ensure new service-specific columns in bookings table
ensure_column_exists($db, 'bookings', 'hotel_name', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'hotel_location', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'hotel_check_in', "DATE NULL");
ensure_column_exists($db, 'bookings', 'hotel_check_out', "DATE NULL");
ensure_column_exists($db, 'bookings', 'hotel_room_type', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'hotel_rooms_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'hotel_confirmation_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'document_type', "VARCHAR(30) DEFAULT 'ticket'");
ensure_column_exists($db, 'bookings', 'hotel_country', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'hotel_city', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'hotel_address', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'hotel_nights_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'hotel_meal_plan', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'hotel_adults_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'hotel_children_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'hotel_guest_names', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'hotel_booking_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'visa_type', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_country', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_app_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_submission_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'visa_delivery_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'visa_valid_from', "DATE NULL");
ensure_column_exists($db, 'bookings', 'visa_valid_to', "DATE NULL");
ensure_column_exists($db, 'bookings', 'visa_entry_type', "VARCHAR(50) NULL");
ensure_column_exists($db, 'bookings', 'visa_duration_days', "INT NULL");
ensure_column_exists($db, 'bookings', 'visa_issue_place', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_uid_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_full_name', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'visa_nationality', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_place_of_birth', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'visa_date_of_birth', "DATE NULL");
ensure_column_exists($db, 'bookings', 'visa_passport_type', "VARCHAR(50) NULL");
ensure_column_exists($db, 'bookings', 'visa_passport_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'visa_profession', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'other_service_name', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'other_reference_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'other_service_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'other_end_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'other_country', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'other_city', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'other_from', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'other_to', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'other_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'passport_number', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'passport_type', "VARCHAR(50) NULL");
ensure_column_exists($db, 'bookings', 'passport_issuing_country', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'passport_country_code', "VARCHAR(10) NULL");
ensure_column_exists($db, 'bookings', 'passport_full_name', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'passport_surname', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'passport_given_names', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'passport_nationality', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'passport_gender', "VARCHAR(20) NULL");
ensure_column_exists($db, 'bookings', 'passport_date_of_birth', "DATE NULL");
ensure_column_exists($db, 'bookings', 'passport_place_of_birth', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'passport_date_of_issue', "DATE NULL");
ensure_column_exists($db, 'bookings', 'passport_date_of_expiry', "DATE NULL");
ensure_column_exists($db, 'bookings', 'passport_place_of_issue', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'passport_authority', "VARCHAR(150) NULL");
ensure_column_exists($db, 'bookings', 'passport_mrz_line1', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'passport_mrz_line2', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_provider', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'insurance_policy_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_insured_name', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'insurance_coverage_type', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_passport_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_destination', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'insurance_issue_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'insurance_start_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'insurance_end_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'insurance_days', "INT NULL");
ensure_column_exists($db, 'bookings', 'insurance_sum_insured', "DECIMAL(12,2) NULL");
ensure_column_exists($db, 'bookings', 'insurance_premium_amount', "DECIMAL(12,2) NULL");
ensure_column_exists($db, 'bookings', 'insurance_emergency_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_certificate_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'insurance_coverage_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'insurance_remarks', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_voucher_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_name', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'package_destinations', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'package_country', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_guest_names', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_type', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_start_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'package_end_date', "DATE NULL");
ensure_column_exists($db, 'bookings', 'package_days_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'package_nights_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'package_adults_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'package_children_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'package_infants_count', "INT NULL");
ensure_column_exists($db, 'bookings', 'package_accommodation', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'package_room_type', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_meals', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_transportation', "VARCHAR(255) NULL");
ensure_column_exists($db, 'bookings', 'package_pickup_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_dropoff_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_confirmation_no', "VARCHAR(100) NULL");
ensure_column_exists($db, 'bookings', 'package_itinerary', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_inclusions', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_exclusions', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_details', "TEXT NULL");
ensure_column_exists($db, 'bookings', 'package_terms', "TEXT NULL");

// Auto-create import_history table if not exists
mysqli_query($db, "CREATE TABLE IF NOT EXISTS import_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    username VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    total_records INT DEFAULT 0,
    imported_records INT DEFAULT 0,
    failed_records INT DEFAULT 0,
    duplicate_records INT DEFAULT 0,
    processing_time_ms INT DEFAULT 0,
    details LONGTEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// System Settings initialization
mysqli_query($db, "CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

mysqli_query($db, "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company_name', 'Travel CRM')");

// Add performance indexes
ensure_index_exists($db, 'bookings', 'idx_bookings_booking_date', 'booking_date');
ensure_index_exists($db, 'bookings', 'idx_bookings_customer_master_id', 'customer_master_id');
ensure_index_exists($db, 'bookings', 'idx_bookings_customer_name', 'customer_name');
ensure_index_exists($db, 'bookings', 'idx_bookings_assigned_user', 'assigned_user');
ensure_index_exists($db, 'bookings', 'idx_bookings_created_by', 'created_by');
ensure_index_exists($db, 'bookings', 'idx_bookings_customer_type', 'customer_type');
ensure_index_exists($db, 'bookings', 'idx_bookings_service_type', 'service_type');
ensure_index_exists($db, 'bookings', 'idx_bookings_status', 'status');
ensure_index_exists($db, 'customer_master', 'idx_customer_master_name', 'name');
ensure_index_exists($db, 'customer_master', 'idx_customer_master_status', 'status');
ensure_index_exists($db, 'customer_master', 'idx_customer_master_customer_type', 'customer_type');
ensure_index_exists($db, 'enquiries', 'idx_enquiries_created_at', 'created_at');
ensure_index_exists($db, 'enquiries', 'idx_enquiries_updated_at', 'updated_at');
ensure_index_exists($db, 'enquiries', 'idx_enquiries_service_type', 'service_type');
ensure_index_exists($db, 'enquiries', 'idx_enquiries_priority', 'priority');
ensure_index_exists($db, 'tasks', 'idx_tasks_assigned_user_id', 'assigned_user_id');
ensure_index_exists($db, 'tasks', 'idx_tasks_status', 'status');

// Create flag file to mark initialization complete
@file_put_contents($flag_file, date('Y-m-d H:i:s'));

skip_migrations:

// This lightweight profile field must also be ensured for installations that
// already have the migration flag from an earlier version.
ensure_column_exists($db, 'users', 'profile_image', "VARCHAR(255) DEFAULT NULL");

$settings = [];
$settings_res = mysqli_query($db, "SELECT * FROM settings");
if ($settings_res) {
    while ($row = mysqli_fetch_assoc($settings_res)) {
        $settings[$row['key']] = $row['value'];
    }
}
define('COMPANY_NAME', $settings['company_name'] ?? 'Travel CRM');
