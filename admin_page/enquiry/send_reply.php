<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enquiry_id = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;
    $reply_message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';

    if ($enquiry_id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid enquiry ID."]);
        exit;
    }

    $has_file = isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK;

    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] !== UPLOAD_ERR_NO_FILE && $_FILES['attachment_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "message" => "File upload failed with error code: " . $_FILES['attachment_file']['error']]);
        exit;
    }

    if (empty($reply_message) && !$has_file) {
        echo json_encode(["success" => false, "message" => "Reply message or file cannot be empty."]);
        exit;
    }

    // Look up the mobile number from the enquiries table
    $query = "SELECT mobile FROM enquiries WHERE id = $enquiry_id";
    $result = mysqli_query($db, $query);
    $row = mysqli_fetch_assoc($result);

    if (!$row) {
        echo json_encode(["success" => false, "message" => "Enquiry not found."]);
        exit;
    }

    $mobile = trim($row['mobile']);
    if (empty($mobile)) {
        echo json_encode(["success" => false, "message" => "This enquiry has no mobile number."]);
        exit;
    }

    if ($has_file) {
        $file = $_FILES['attachment_file'];
        $cfile = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
        
        $post_fields = [
            'number' => $mobile,
            'caption' => $reply_message,
            'file' => $cfile
        ];

        $ch = curl_init('http://localhost:3000/api/send-media');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds for files
    } else {
        // Make cURL POST request to Node.js listener for text-only message
        $post_data = json_encode([
            "number" => $mobile,
            "message" => $reply_message
        ]);

        $ch = curl_init('http://localhost:3000/api/send-message');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post_data)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds timeout
    }

    $response = curl_exec($ch);
    $curl_error_code = curl_errno($ch);
    curl_close($ch);

    if ($curl_error_code !== 0) {
        echo json_encode(["success" => false, "message" => "WhatsApp service is offline. Please make sure the Node.js listener is running."]);
        exit;
    }

    $response_data = json_decode($response, true);

    if (isset($response_data['success']) && $response_data['success'] === true) {
        // Update enquiry status to "Replied" in database
        $update_query = "UPDATE enquiries SET status = 'Replied', updated_at = NOW() WHERE id = $enquiry_id";
        if (mysqli_query($db, $update_query)) {
            // Log to enquiry_messages
            $mobile_db = mysqli_real_escape_string($db, $mobile);
            
            // Set message text (use caption, fallback if none, or placeholder)
            $msg_text = $reply_message;
            if ($has_file && empty($msg_text)) {
                $msg_text = ($response_data['media_type'] === 'image') ? '[Image]' : '[Document: ' . $response_data['original_filename'] . ']';
            }
            $reply_message_db = mysqli_real_escape_string($db, $msg_text);
            $sent_by_db = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'Admin');
            
            $media_path_db = $has_file ? "'" . mysqli_real_escape_string($db, $response_data['media_path']) . "'" : "NULL";
            $media_type_db = $has_file ? "'" . mysqli_real_escape_string($db, $response_data['media_type']) . "'" : "'none'";
            $original_filename_db = $has_file ? "'" . mysqli_real_escape_string($db, $response_data['original_filename']) . "'" : "NULL";
            
            $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text, sent_by, media_path, media_type, original_filename) 
                          VALUES ($enquiry_id, '$mobile_db', 'outgoing', '$reply_message_db', '$sent_by_db', $media_path_db, $media_type_db, $original_filename_db)";
            
            mysqli_query($db, $msg_query);

            // Log activity
            $user_for_log = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'Admin');
            $log_action = $has_file ? "Sent WhatsApp Media to Enquiry #$enquiry_id" : "Sent WhatsApp Reply to Enquiry #$enquiry_id";
            mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', '$log_action', 'Enquiry', NOW())");

            $new_msg_id = mysqli_insert_id($db);
            echo json_encode([
                "success" => true,
                "message" => "Reply sent successfully and status updated to Replied.",
                "data" => [
                    "id" => $new_msg_id,
                    "message_text" => $msg_text,
                    "direction" => "outgoing",
                    "sent_by" => $_SESSION['user_name'] ?? 'Admin',
                    "created_at" => date('Y-m-d H:i:s'),
                    "media_path" => $has_file ? $response_data['media_path'] : null,
                    "media_type" => $has_file ? $response_data['media_type'] : 'none',
                    "original_filename" => $has_file ? $response_data['original_filename'] : null,
                    "media_duration" => 0
                ]
            ]);
        } else {
            echo json_encode(["success" => false, "message" => "Reply sent, but failed to update status in database: " . mysqli_error($db)]);
        }
    } else {
        $err_msg = isset($response_data['message']) ? $response_data['message'] : 'Failed to send WhatsApp message.';
        echo json_encode(["success" => false, "message" => $err_msg]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}
?>
