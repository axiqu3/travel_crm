<?php

$db = mysqli_connect(
"127.0.0.1",
"root",
"",
"travel_crm",
3307
);

if(!$db)
{
die("Database Error");
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

ensure_column_exists($db, 'enquiries', 'notified', 'TINYINT DEFAULT 0');
ensure_column_exists($db, 'enquiries', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
ensure_column_exists($db, 'bookings', 'customer_master_id', 'INT NULL');
ensure_column_exists($db, 'customer_master', 'status', "VARCHAR(50) DEFAULT 'Active'");
ensure_column_exists($db, 'enquiry_messages', 'media_path', 'VARCHAR(255) NULL DEFAULT NULL');
ensure_column_exists($db, 'enquiry_messages', 'media_type', "VARCHAR(50) DEFAULT 'none'");
ensure_column_exists($db, 'enquiry_messages', 'original_filename', 'VARCHAR(255) NULL DEFAULT NULL');
ensure_column_exists($db, 'enquiry_messages', 'media_duration', 'INT DEFAULT 0');
ensure_column_exists($db, 'users', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
ensure_column_exists($db, 'users', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
ensure_column_exists($db, 'users', 'updated_by', "VARCHAR(255) DEFAULT ''");

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
?>