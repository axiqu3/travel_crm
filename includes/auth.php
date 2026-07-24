<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function check_auth($required_role = null) {
    $current_script = $_SERVER['SCRIPT_NAME'];
    $base_url = "/";
    if (stripos($current_script, '/admin_page/') !== false) {
        $parts = preg_split('~/admin_page/~i', $current_script);
        $base_url = $parts[0] . "/";
    } elseif (stripos($current_script, '/user_page/') !== false) {
        $parts = preg_split('~/user_page/~i', $current_script);
        $base_url = $parts[0] . "/";
    }

    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_url . "login.php");
        exit;
    }

    // Check if profile is completed for non-admin users
    if ($_SESSION['user_role'] !== 'admin' && (!isset($_SESSION['profile_completed']) || $_SESSION['profile_completed'] == 0)) {
        // Allow access to complete_profile.php and logout.php, redirect everything else
        if (strpos($current_script, 'complete_profile.php') === false && strpos($current_script, 'logout.php') === false) {
            header("Location: " . $base_url . "user_page/complete_profile.php");
            exit;
        }
    }

    if ($required_role !== null && $_SESSION['user_role'] !== $required_role) {
        // Enforce role check if specified (e.g. admin restriction)
        header("Location: " . $base_url . "login.php");
        exit;
    }
}
?>
