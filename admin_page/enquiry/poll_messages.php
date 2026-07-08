<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

$enquiry_id = isset($_GET['enquiry_id']) ? intval($_GET['enquiry_id']) : 0;
$since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

if ($enquiry_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid enquiry ID."]);
    exit;
}

// Fetch mobile number to group by mobile format if applicable
$query = "SELECT mobile FROM enquiries WHERE id = $enquiry_id";
$res = mysqli_query($db, $query);
$enquiry = mysqli_fetch_assoc($res);
if (!$enquiry) {
    echo json_encode(["success" => false, "message" => "Enquiry not found."]);
    exit;
}

$mobile = trim($enquiry['mobile'] ?? '');
if ($mobile !== '') {
    $mobile_condition = get_mobile_matching_sql($db, 'mobile', $mobile);
    $msg_query = "SELECT * FROM enquiry_messages WHERE (enquiry_id = $enquiry_id OR $mobile_condition) AND id > $since_id ORDER BY id ASC";
} else {
    $msg_query = "SELECT * FROM enquiry_messages WHERE enquiry_id = $enquiry_id AND id > $since_id ORDER BY id ASC";
}

$msg_result = mysqli_query($db, $msg_query);
$messages = [];
while ($row = mysqli_fetch_assoc($msg_result)) {
    $messages[] = [
        "id" => intval($row['id']),
        "direction" => $row['direction'],
        "message_text" => $row['message_text'],
        "sent_by" => $row['sent_by'],
        "created_at" => $row['created_at'],
        "media_path" => $row['media_path'],
        "media_type" => $row['media_type'] ?: 'none',
        "original_filename" => $row['original_filename'],
        "media_duration" => intval($row['media_duration'] ?? 0)
    ];
}

echo json_encode(["success" => true, "messages" => $messages]);
?>
