<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // Fetch enquiry details
    $res = mysqli_query($db, "SELECT * FROM enquiries WHERE id = $id");
    $enquiry = mysqli_fetch_assoc($res);

    if ($enquiry) {
        $name = mysqli_real_escape_string($db, $enquiry['customer_name']);
        $mobile = mysqli_real_escape_string($db, $enquiry['mobile']);
        $email = mysqli_real_escape_string($db, $enquiry['email']);
        $notes = mysqli_real_escape_string($db, $enquiry['description']);

        // Check if customer already exists (by mobile or email)
        $exists = false;
        if (!empty($mobile)) {
            $check_mob = mysqli_query($db, "SELECT id FROM customer_master WHERE mobile = '$mobile'");
            if (mysqli_num_rows($check_mob) > 0) {
                $exists = true;
            }
        }
        if (!$exists && !empty($email)) {
            $check_email = mysqli_query($db, "SELECT id FROM customer_master WHERE email = '$email'");
            if (mysqli_num_rows($check_email) > 0) {
                $exists = true;
            }
        }

        if (!$exists) {
            // Insert into customer_master
            $created_by = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? 'System');
            $ins = "INSERT INTO customer_master (customer_type, name, mobile, email, address, created_by) 
                    VALUES ('Walk-in Customer', '$name', '$mobile', '$email', '$notes', '$created_by')";
            mysqli_query($db, $ins);
        }

        // Update enquiry status to 'Converted'
        mysqli_query($db, "UPDATE enquiries SET status = 'Converted' WHERE id = $id");

        header("Location: list.php?customer_added=1");
        exit;
    }
}
header("Location: list.php");
exit;
?>
