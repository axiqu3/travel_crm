<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = isset($_POST['ids']) ? $_POST['ids'] : [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'No items selected.']);
        exit;
    }
    
    // Clean and validate IDs
    $clean_ids = array_map('intval', $ids);
    $ids_str = implode(',', $clean_ids);
    
    // Execute deletion (Cascading deletes related messages via DB constraint)
    $query = "DELETE FROM enquiries WHERE id IN ($ids_str)";
    if (mysqli_query($db, $query)) {
        // Log activity
        $user_for_log = $_SESSION['user_name'] ?? 'System';
        $count = count($clean_ids);
        mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('$user_for_log', 'Bulk Deleted $count Enquiries', 'Enquiry', NOW())");
        
        echo json_encode(['success' => true, 'message' => "$count enquiries deleted successfully."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($db)]);
    }
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
?>
