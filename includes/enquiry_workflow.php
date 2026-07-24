<?php

if (!defined('ENQUIRY_WORKFLOW_LOADED')) {
    define('ENQUIRY_WORKFLOW_LOADED', true);

    date_default_timezone_set('Asia/Kolkata');
    if (isset($db) && $db instanceof mysqli) {
        @mysqli_query($db, "SET time_zone = '+05:30'");
    }

    function enquiry_service_types() {
        return ['Flight', 'Hotel', 'Visa', 'Passport', 'Insurance', 'Tour Package', 'Other'];
    }

    function enquiry_priorities() {
        return ['Low', 'Medium', 'High'];
    }

    function enquiry_statuses() {
        return ['New', 'Follow-up', 'Confirmed', 'Converted', 'Cancelled', 'Closed'];
    }

    function enquiry_active_statuses() {
        return ['New', 'Follow-up', 'Confirmed'];
    }

    function enquiry_contact_methods() {
        return ['Phone', 'WhatsApp', 'Direct', 'Email', 'Other'];
    }

    function enquiry_follow_up_results() {
        return ['Interested', 'Need More Time', 'Not Responding', 'Quotation Sent', 'Not Interested'];
    }

    function enquiry_manual_source_sql($alias = 'e') {
        $prefix = $alias !== '' ? preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.' : '';
        return "(LOWER(COALESCE({$prefix}source, '')) IN ('manual', 'direct') OR COALESCE({$prefix}source, '') = '')";
    }

    function enquiry_current_user_name() {
        return trim((string) ($_SESSION['user_name'] ?? ''));
    }

    function enquiry_is_admin() {
        return (($_SESSION['user_role'] ?? '') === 'admin');
    }

    function enquiry_owner_sql($db, $alias = 'e') {
        if (enquiry_is_admin()) {
            return '1=1';
        }
        $prefix = $alias !== '' ? preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.' : '';
        $name = mysqli_real_escape_string($db, enquiry_current_user_name());
        return "({$prefix}created_by = '$name' OR {$prefix}assigned_user = '$name')";
    }

    function enquiry_normalize_status($status, $booking_id = null) {
        $status = trim((string) $status);
        if (in_array($status, ['Seen', 'Replied'], true)) {
            return 'Follow-up';
        }
        if ($status === 'Booked' && !empty($booking_id)) {
            return 'Converted';
        }
        return in_array($status, enquiry_statuses(), true) ? $status : 'New';
    }

    function enquiry_extract_service_type($enquiry) {
        $stored = trim((string) ($enquiry['service_type'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $description = (string) ($enquiry['description'] ?? '');
        if (preg_match('/^\[Service Type:\s*([^\]]+)\]/i', $description, $matches)) {
            return trim($matches[1]);
        }
        return trim((string) ($enquiry['subject'] ?? '')) ?: 'Other';
    }

    function enquiry_clean_description($description) {
        return trim((string) preg_replace('/^\[Service Type:\s*[^\]]+\]\s*/i', '', (string) $description));
    }

    function enquiry_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function enquiry_validate_csrf($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
    }

    function enquiry_parse_date($value, $include_time = false) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $formats = $include_time
            ? ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i']
            : ['Y-m-d'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Kolkata'));
            if ($date && $date->format($format) === $value) {
                return $date->format($include_time ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }
        return false;
    }

    function enquiry_fetch($db, $id, $manual_only = true, $for_update = false) {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $conditions = ['e.id = ?'];
        if ($manual_only) {
            $conditions[] = enquiry_manual_source_sql('e');
        }
        $conditions[] = enquiry_owner_sql($db, 'e');
        $sql = 'SELECT e.* FROM enquiries e WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1';
        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = mysqli_prepare($db, $sql);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);
        if ($row) {
            $row['status'] = enquiry_normalize_status($row['status'] ?? '', $row['booking_id'] ?? null);
            $row['service_type'] = enquiry_extract_service_type($row);
        }
        return $row;
    }

    function enquiry_log($db, $action) {
        $username = enquiry_current_user_name() ?: 'System';
        $module = 'Enquiry';
        $action = mb_substr(trim((string) $action), 0, 255);
        $stmt = mysqli_prepare($db, 'INSERT INTO activity_log (username, action, module, activity_date) VALUES (?, ?, ?, NOW())');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sss', $username, $action, $module);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }

    function enquiry_whatsapp_number($mobile) {
        $digits = preg_replace('/\D+/', '', (string) $mobile);
        if (preg_match('/^[6-9][0-9]{9}$/', $digits)) {
            return '91' . $digits;
        }
        if (preg_match('/^0([6-9][0-9]{9})$/', $digits, $matches)) {
            return '91' . $matches[1];
        }
        if (preg_match('/^91[6-9][0-9]{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^[1-9][0-9]{10,14}$/', $digits)) {
            return $digits;
        }
        return '';
    }

    function enquiry_whatsapp_url($mobile, $customer = '', $service = '') {
        $number = enquiry_whatsapp_number($mobile);
        if ($number === '') {
            return '';
        }
        $message = 'Hello ' . trim((string) $customer) . ', regarding your ' . trim((string) $service) . ' enquiry...';
        return 'https://web.whatsapp.com/send?phone=' . rawurlencode($number) . '&text=' . rawurlencode($message);
    }

    function enquiry_status_badge_class($status) {
        $status = enquiry_normalize_status($status);
        return 'status-' . strtolower(str_replace(' ', '-', $status));
    }

    function enquiry_can_follow_up($status) {
        return in_array(enquiry_normalize_status($status), ['New', 'Follow-up', 'Confirmed'], true);
    }

    function enquiry_can_confirm($status) {
        return in_array(enquiry_normalize_status($status), ['New', 'Follow-up'], true);
    }

    function enquiry_can_convert($status, $booking_id = null) {
        return enquiry_normalize_status($status, $booking_id) === 'Confirmed' && empty($booking_id);
    }

    function enquiry_can_edit($status) {
        return in_array(enquiry_normalize_status($status), ['New', 'Follow-up', 'Confirmed'], true);
    }

    function enquiry_can_close($status) {
        return in_array(enquiry_normalize_status($status), ['New', 'Follow-up'], true);
    }

    function enquiry_redirect_path($path) {
        header('Location: ' . $path);
        exit;
    }

    function enquiry_json($success, $message, $extra = [], $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => (bool) $success, 'message' => (string) $message], $extra));
        exit;
    }

    function enquiry_sync_customer_master($db, $customer_name, $mobile, $email = '', $created_by = '') {
        $customer_name = trim((string) $customer_name);
        $mobile = trim((string) $mobile);
        $email = trim((string) $email);
        if ($customer_name === '' && $mobile === '') {
            return;
        }

        $check_res = null;
        if (function_exists('get_mobile_matching_sql') && $mobile !== '') {
            $mobile_cond = get_mobile_matching_sql($db, 'mobile', $mobile);
            $check_res = mysqli_query($db, "SELECT id FROM customer_master WHERE $mobile_cond LIMIT 1");
        } elseif ($customer_name !== '') {
            $name_esc = mysqli_real_escape_string($db, $customer_name);
            $check_res = mysqli_query($db, "SELECT id FROM customer_master WHERE name = '$name_esc' LIMIT 1");
        }

        if ($check_res && ($row = mysqli_fetch_assoc($check_res))) {
            $cust_id = (int) $row['id'];
            $updates = [];
            if ($customer_name !== '') $updates[] = "name = '" . mysqli_real_escape_string($db, $customer_name) . "'";
            if ($mobile !== '') $updates[] = "mobile = '" . mysqli_real_escape_string($db, $mobile) . "'";
            if ($email !== '') $updates[] = "email = '" . mysqli_real_escape_string($db, $email) . "'";
            if ($updates) {
                mysqli_query($db, "UPDATE customer_master SET " . implode(', ', $updates) . " WHERE id = $cust_id");
            }
        } else {
            $name_esc = mysqli_real_escape_string($db, $customer_name);
            $mobile_esc = mysqli_real_escape_string($db, $mobile);
            $email_esc = mysqli_real_escape_string($db, $email);
            $user_esc = mysqli_real_escape_string($db, $created_by ?: (enquiry_current_user_name() ?: 'System'));
            mysqli_query($db, "INSERT INTO customer_master (customer_type, name, mobile, email, created_by) VALUES ('Walk-in Customer', '$name_esc', '$mobile_esc', '$email_esc', '$user_esc')");
        }
    }
}

if (!function_exists('enquiry_list_wa_icon')) {
    function enquiry_list_wa_icon() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.04 2a9.84 9.84 0 0 0-8.5 14.78L2 22l5.36-1.48A9.9 9.9 0 1 0 12.04 2Zm0 17.98a8.1 8.1 0 0 1-4.12-1.13l-.3-.18-3.18.88.85-3.1-.2-.32a8.1 8.1 0 1 1 6.95 3.85Zm4.45-6.07c-.24-.12-1.44-.71-1.66-.79-.22-.08-.38-.12-.55.12-.16.25-.63.8-.78.96-.14.17-.28.19-.52.07-1.42-.71-2.35-1.27-3.29-2.88-.25-.43.25-.4.71-1.33.08-.16.04-.3-.02-.43-.06-.12-.55-1.32-.75-1.8-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.43.06-.65.3-.22.25-.85.83-.85 2.03s.87 2.36.99 2.52c.12.17 1.71 2.62 4.15 3.67 1.54.67 2.15.72 2.92.61.47-.07 1.44-.59 1.64-1.16.2-.57.2-1.06.14-1.16-.06-.11-.22-.17-.46-.29Z"/></svg>';
    }
}
