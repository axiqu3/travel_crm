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

    $customer_name = $_POST["customer_name"] ?? '';
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

    if (empty($customer_name) && empty($mobile)) {
        echo json_encode(["success" => false, "message" => "Customer Name or Mobile is required."]);
        exit;
    }

    // Try to resolve customer name robustly
    $new_resolved_name = '';
    // 1. Try to find the name from customer_master first
    $cm_match_sql = get_mobile_matching_sql($db, 'mobile', $mobile);
    $cm_query = mysqli_query($db, "SELECT name FROM customer_master WHERE $cm_match_sql LIMIT 1");
    if ($cm_query && mysqli_num_rows($cm_query) > 0) {
        $cm_row = mysqli_fetch_assoc($cm_query);
        $new_resolved_name = $cm_row['name'];
    }
    
    // 2. Use webhook customer_name (it's incoming, so it's the customer's pushName)
    if (empty($new_resolved_name) && !empty($customer_name)) {
        $new_resolved_name = $customer_name;
    }
    
    // 3. Fallback
    if (empty($new_resolved_name)) {
        $new_resolved_name = $mobile;
    }
    
    $resolved_customer_name_db = mysqli_real_escape_string($db, $new_resolved_name);

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
        
        // Fetch current enquiry name to see if it is generic/admin and needs update
        $current_enq_q = mysqli_query($db, "SELECT customer_name FROM enquiries WHERE id = $enquiry_id");
        $current_enq = mysqli_fetch_assoc($current_enq_q);
        $current_name = $current_enq['customer_name'] ?? '';
        
        $is_generic = (is_numeric($current_name) || empty($current_name) || in_array(strtolower($current_name), ['asheii', 'ashi', 'admin', 'admin user', 'phone (manual)']) || strpos(strtolower($current_name), 'whatsapp:') === 0);
        
        $update_name_sql = "";
        // If the current name is generic/admin, and we resolved a better name that is not generic/admin, update it!
        if ($is_generic && !empty($new_resolved_name)) {
            $is_new_name_valid = !(is_numeric($new_resolved_name) || empty($new_resolved_name) || in_array(strtolower($new_resolved_name), ['asheii', 'ashi', 'admin', 'admin user', 'phone (manual)']) || strpos(strtolower($new_resolved_name), 'whatsapp:') === 0);
            if ($is_new_name_valid && strtolower($current_name) !== strtolower($new_resolved_name)) {
                $update_name_sql = ", customer_name = '$resolved_customer_name_db'";
            }
        }

        // Insert message into enquiry_messages
        $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, media_path, media_type, original_filename, media_duration) 
                      VALUES ($enquiry_id, '$mobile', 'incoming', '$description', '$media_path', '$media_type', '$original_filename', $media_duration)";
                      
        // Update existing enquiry description preview and set notified to 0, status to 'New'
        $update_enq = "UPDATE enquiries SET description = '$description', notified = 0, status = 'New', updated_at = NOW() $update_name_sql WHERE id = $enquiry_id";
        
        if (mysqli_query($db, $msg_query) && mysqli_query($db, $update_enq)) {
            echo json_encode(["success" => true, "message" => "Message added to existing conversation thread."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error appending message: " . mysqli_error($db)]);
        }
    } else {
        // Insert new WhatsApp Enquiry
        $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by) 
                  VALUES ('$resolved_customer_name_db', '$mobile', '$email', '$subject', '$description', '$source', '$status', '$assigned_user', '$created_by')";
                  
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
