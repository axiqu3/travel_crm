<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

// Initialize cURL session to fetch messages from the Node.js service
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:3000/api/messages");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds timeout

$response = curl_exec($ch);

// If the service is unreachable or errors out
if ($response === false) {
    curl_close($ch);
    if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
        echo json_encode(["success" => false, "message" => "WhatsApp service is not running. Please start it first."]);
        exit;
    }
    header("Location: list.php?whatsapp_error=" . urlencode("WhatsApp service is not running. Please start it first."));
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
        echo json_encode(["success" => false, "message" => "WhatsApp service returned HTTP status code: " . $http_code]);
        exit;
    }
    header("Location: list.php?whatsapp_error=" . urlencode("WhatsApp service returned HTTP status code: " . $http_code));
    exit;
}

$messages = json_decode($response, true);
if (!is_array($messages)) {
    if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
        echo json_encode(["success" => false, "message" => "Failed to parse response from WhatsApp service."]);
        exit;
    }
    header("Location: list.php?whatsapp_error=" . urlencode("Failed to parse response from WhatsApp service."));
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
    $media_path_db = mysqli_real_escape_string($db, $msg['mediaPath'] ?? '');
    $media_type_db = mysqli_real_escape_string($db, $msg['mediaType'] ?? 'none');
    $original_filename_db = mysqli_real_escape_string($db, $msg['originalFilename'] ?? '');
    $media_duration_db = intval($msg['mediaDuration'] ?? 0);

    // Determine message type
    $is_manual_outgoing = (($msg['direction'] ?? 'incoming') === 'outgoing_manual');
    $direction_db = $is_manual_outgoing ? 'outgoing' : 'incoming';
    $sent_by_db = $is_manual_outgoing ? "'Phone (Manual)'" : "NULL";

    // Check if an enquiries row already exists for this mobile number using robust matching
    $mobile_condition = get_mobile_matching_sql($db, 'mobile', $senderNumber);
    $check_exist = mysqli_query($db, "SELECT id FROM enquiries WHERE $mobile_condition LIMIT 1");
    
    if (mysqli_num_rows($check_exist) > 0) {
        $row_exist = mysqli_fetch_assoc($check_exist);
        $enquiry_id = $row_exist['id'];
        
        // Prevent duplicate imports within 1 day (same mobile, message_text)
        $msg_mobile_condition = get_mobile_matching_sql($db, 'mobile', $senderNumber);
        $dup_check = mysqli_query($db, "SELECT id FROM enquiry_messages WHERE $msg_mobile_condition AND message_text = '$description_db' AND created_at >= NOW() - INTERVAL 1 DAY");
        if (mysqli_num_rows($dup_check) > 0) {
            continue;
        }
        
        // Insert message into enquiry_messages
        $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, sent_by, media_path, media_type, original_filename, media_duration) 
                      VALUES ($enquiry_id, '$mobile_db', '$direction_db', '$description_db', $sent_by_db, '$media_path_db', '$media_type_db', '$original_filename_db', $media_duration_db)";
                      
        // Update existing enquiry description preview
        if (!$is_manual_outgoing) {
            $update_enq = "UPDATE enquiries SET description = '$description_db', notified = 0, status = 'New', updated_at = NOW() WHERE id = $enquiry_id";
        } else {
            $update_enq = "UPDATE enquiries SET description = '$description_db', updated_at = NOW() WHERE id = $enquiry_id";
        }
        
        if (mysqli_query($db, $msg_query) && mysqli_query($db, $update_enq)) {
            $imported_count++;
        }
    } else {
        // Insert new WhatsApp Enquiry
        $notified_val = $is_manual_outgoing ? 1 : 0;
        
        $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by, notified) 
                  VALUES ('$customer_name_db', '$mobile_db', '', '$subject_db', '$description_db', '$source_db', '$status_db', '', '$created_by_db', $notified_val)";
                  
        if (mysqli_query($db, $query)) {
            $enquiry_id = mysqli_insert_id($db);
            
            // Insert the first message into enquiry_messages
            $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, sent_by, media_path, media_type, original_filename, media_duration) 
                          VALUES ($enquiry_id, '$mobile_db', '$direction_db', '$description_db', $sent_by_db, '$media_path_db', '$media_type_db', '$original_filename_db', $media_duration_db)";
            mysqli_query($db, $msg_query);
            
            $imported_count++;
        }
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    echo json_encode(["success" => true, "imported" => $imported_count]);
    exit;
}
// Redirect back to list.php with success import count
header("Location: list.php?whatsapp_import=" . $imported_count);
exit;
