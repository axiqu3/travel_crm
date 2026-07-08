<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"] ?? '');
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"] ?? '');
    $email = mysqli_real_escape_string($db, $_POST["email"] ?? '');
    $subject = mysqli_real_escape_string($db, $_POST["subject"] ?? '');
    $description = mysqli_real_escape_string($db, $_POST["description"] ?? '');
    $source = "WhatsApp";
    $status = "New";
    
    $created_by = $_SESSION['user_name'] ?? 'System';
    $assigned_user = $created_by; 

    if (empty($customer_name)) {
        echo json_encode(["success" => false, "message" => "Customer Name is required."]);
        exit;
    }

    $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by) 
              VALUES ('$customer_name', '$mobile', '$email', '$subject', '$description', '$source', '$status', '$assigned_user', '$created_by')";
              
    if (mysqli_query($db, $query)) {
        echo json_encode(["success" => true, "message" => "Enquiry imported successfully!"]);
    } else {
        echo json_encode(["success" => false, "message" => "Error creating enquiry: " . mysqli_error($db)]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}
?>
