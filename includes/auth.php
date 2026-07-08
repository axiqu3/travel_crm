<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function check_auth($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /travel_crm/login.php");
        exit;
    }
    if ($required_role !== null && $_SESSION['user_role'] !== $required_role) {
        // Enforce role check if specified (e.g. admin restriction)
        header("Location: /travel_crm/login.php");
        exit;
    }
}
?>
