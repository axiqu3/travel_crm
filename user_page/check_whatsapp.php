<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

// Initialize cURL session to fetch messages from the Node.js service
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:3000/api/messages");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds timeout

$response = curl_exec($ch);

// If the service is unreachable or errors out
if ($response === false) {
    curl_close($ch);
    header("Location: enquiry.php?whatsapp_error=" . urlencode("WhatsApp service is not running. Please start it first."));
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    header("Location: enquiry.php?whatsapp_error=" . urlencode("WhatsApp service returned HTTP status code: " . $http_code));
    exit;
}

$messages = json_decode($response, true);
if (!is_array($messages)) {
    header("Location: enquiry.php?whatsapp_error=" . urlencode("Failed to parse response from WhatsApp service."));
    exit;
}

$imported_count = 0;

foreach ($messages as $msg) {
    $senderNumber = $msg['senderNumber'] ?? '';
    $messageText = $msg['messageText'] ?? '';
    $pushName = $msg['pushName'] ?? '';

    if (empty($senderNumber)) {
        continue;
    }

    // customer_name = pushName (fallback to WhatsApp: senderNumber)
    $customer_name = !empty($pushName) ? $pushName : "WhatsApp: " . $senderNumber;

    // Escape database inputs safely
    $customer_name_db = mysqli_real_escape_string($db, $customer_name);
    $mobile_db = mysqli_real_escape_string($db, $senderNumber);
    $subject_db = mysqli_real_escape_string($db, "WhatsApp Enquiry");
    $description_db = mysqli_real_escape_string($db, $messageText);
    $source_db = mysqli_real_escape_string($db, "WhatsApp");
    $status_db = mysqli_real_escape_string($db, "New");
    $created_by_db = mysqli_real_escape_string($db, "WhatsApp Importer");

    // Prevent duplicate imports within 1 day (same mobile, description, source) using robust matching
    $mobile_condition = get_mobile_matching_sql($db, 'mobile', $senderNumber);
    $dup_check = mysqli_query($db, "SELECT id FROM enquiries WHERE $mobile_condition AND description = '$description_db' AND source = 'WhatsApp' AND created_at >= NOW() - INTERVAL 1 DAY");
    if (mysqli_num_rows($dup_check) > 0) {
        continue;
    }

    // Insert new WhatsApp Enquiry
    $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by, notified) 
              VALUES ('$customer_name_db', '$mobile_db', '', '$subject_db', '$description_db', '$source_db', '$status_db', '', '$created_by_db', 0)";

    if (mysqli_query($db, $query)) {
        $imported_count++;
    }
}

// Redirect back to enquiry.php with success import count
header("Location: enquiry.php?whatsapp_import=" . $imported_count);
exit;
