<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';

    $allowed_statuses = ["New", "Seen", "Replied", "Follow-up", "Converted", "Cancelled", "Booked"];

    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid enquiry ID."]);
        exit;
    }

    if (!in_array($status, $allowed_statuses)) {
        echo json_encode(["success" => false, "message" => "Invalid status option."]);
        exit;
    }

    // Get current status to revert to on failure
    $check_query = mysqli_query($db, "SELECT status FROM enquiries WHERE id = $id");
    $enquiry = mysqli_fetch_assoc($check_query);
    if (!$enquiry) {
        echo json_encode(["success" => false, "message" => "Enquiry not found."]);
        exit;
    }
    $current_status = $enquiry['status'];

    $status_db = mysqli_real_escape_string($db, $status);
    if (strtolower($status) === 'seen') {
        $update_query = "UPDATE enquiries SET status = '$status_db', updated_at = updated_at WHERE id = $id";
    } else {
        $update_query = "UPDATE enquiries SET status = '$status_db', updated_at = NOW() WHERE id = $id";
    }

    if (mysqli_query($db, $update_query)) {
        echo json_encode(["success" => true, "message" => "Status updated successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Database error: " . mysqli_error($db), "current_status" => $current_status]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}
?>
