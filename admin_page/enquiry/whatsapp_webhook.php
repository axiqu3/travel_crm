<?php
define('API_SECRET', 'wa_crm_secret_2026');

require_once(__DIR__ . "/../../includes/db.php");


header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify API secret token
    $api_secret = $_POST["api_secret"] ?? '';
    if ($api_secret !== API_SECRET) {
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit;
    }

    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"] ?? '');
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"] ?? '');
    $email = mysqli_real_escape_string($db, $_POST["email"] ?? '');
    $subject = mysqli_real_escape_string($db, $_POST["subject"] ?? '');
    $description = mysqli_real_escape_string($db, $_POST["description"] ?? '');
    $media_path = mysqli_real_escape_string($db, $_POST["media_path"] ?? '');
    $media_type = mysqli_real_escape_string($db, $_POST["media_type"] ?? 'none');
    $original_filename = mysqli_real_escape_string($db, $_POST["original_filename"] ?? '');
    $media_duration = intval($_POST["media_duration"] ?? 0);
    $source = "WhatsApp";
    $status = "New";
    
    $created_by = 'WhatsApp Webhook';
    $assigned_user = ""; 

    if (empty($customer_name)) {
        echo json_encode(["success" => false, "message" => "Customer Name is required."]);
        exit;
    }

    // Check if an enquiries row already exists for this mobile number using robust matching
    $mobile_condition = get_mobile_matching_sql($db, 'mobile', $mobile);
    $check_exist = mysqli_query($db, "SELECT id FROM enquiries WHERE $mobile_condition LIMIT 1");
    
    if (mysqli_num_rows($check_exist) > 0) {
        $row_exist = mysqli_fetch_assoc($check_exist);
        $enquiry_id = $row_exist['id'];
        
        // Prevent duplicate imports within 1 day (same mobile, message_text)
        $msg_mobile_condition = get_mobile_matching_sql($db, 'mobile', $mobile);
        $dup_check = mysqli_query($db, "SELECT id FROM enquiry_messages WHERE $msg_mobile_condition AND message_text = '$description' AND created_at >= NOW() - INTERVAL 1 DAY");
        if (mysqli_num_rows($dup_check) > 0) {
            echo json_encode(["success" => true, "message" => "Enquiry already imported (duplicate)."]);
            exit;
        }
        
        // Insert message into enquiry_messages
        $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, media_path, media_type, original_filename, media_duration) 
                      VALUES ($enquiry_id, '$mobile', 'incoming', '$description', '$media_path', '$media_type', '$original_filename', $media_duration)";
                      
        // Update existing enquiry description preview and set notified to 0, status to 'New'
        $update_enq = "UPDATE enquiries SET description = '$description', notified = 0, status = 'New', updated_at = NOW() WHERE id = $enquiry_id";
        
        if (mysqli_query($db, $msg_query) && mysqli_query($db, $update_enq)) {
 
            echo json_encode(["success" => true, "message" => "Message added to existing conversation thread."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error appending message: " . mysqli_error($db)]);
        }
    } else {
        // Insert new WhatsApp Enquiry
        $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by) 
                  VALUES ('$customer_name', '$mobile', '$email', '$subject', '$description', '$source', '$status', '$assigned_user', '$created_by')";
                  
        if (mysqli_query($db, $query)) {
            $enquiry_id = mysqli_insert_id($db);
            
            // Insert the first message into enquiry_messages
            $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, media_path, media_type, original_filename, media_duration) 
                          VALUES ($enquiry_id, '$mobile', 'incoming', '$description', '$media_path', '$media_type', '$original_filename', $media_duration)";
            mysqli_query($db, $msg_query);
            

            echo json_encode(["success" => true, "message" => "Enquiry imported successfully!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Error creating enquiry: " . mysqli_error($db)]);
        }
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}
?>
