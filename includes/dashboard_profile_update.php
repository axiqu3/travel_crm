<?php

if (!function_exists('dashboard_profile_csrf_token')) {
    function dashboard_profile_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['dashboard_profile_csrf_token'])) {
            $_SESSION['dashboard_profile_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['dashboard_profile_csrf_token'];
    }
}

if (!function_exists('dashboard_profile_update')) {
    function dashboard_profile_update($db, $user_id, $data) {
        $user_id = (int) $user_id;
        $session_token = dashboard_profile_csrf_token();
        $submitted_token = (string) ($data['dashboard_profile_csrf_token'] ?? '');
        if ($user_id < 1 || $submitted_token === '' || !hash_equals($session_token, $submitted_token)) {
            return ['success' => false, 'message' => 'Your session expired. Refresh the dashboard and try again.'];
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $dob = trim((string) ($data['dob'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $state = trim((string) ($data['state'] ?? ''));
        $country = trim((string) ($data['country'] ?? ''));
        $zip_code = trim((string) ($data['zip_code'] ?? ''));
        $current_password = (string) ($data['current_password'] ?? '');
        $new_password = (string) ($data['new_password'] ?? '');
        $confirm_password = (string) ($data['confirm_password'] ?? '');

        if ($name === '' || mb_strlen($name) > 255) {
            return ['success' => false, 'message' => 'Enter a valid profile name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            return ['success' => false, 'message' => 'Enter a valid email address.'];
        }
        if (mb_strlen($phone) > 50 || mb_strlen($city) > 100 || mb_strlen($state) > 100 || mb_strlen($country) > 100 || mb_strlen($zip_code) > 20) {
            return ['success' => false, 'message' => 'One or more profile fields are too long.'];
        }
        if ($dob !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$date || $date->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
                return ['success' => false, 'message' => 'Enter a valid date of birth.'];
            }
        }

        $user_stmt = mysqli_prepare($db, 'SELECT email, password FROM users WHERE id = ? LIMIT 1');
        if (!$user_stmt) {
            return ['success' => false, 'message' => 'Unable to load your profile.'];
        }
        mysqli_stmt_bind_param($user_stmt, 'i', $user_id);
        mysqli_stmt_execute($user_stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($user_stmt));
        mysqli_stmt_close($user_stmt);
        if (!$user) {
            return ['success' => false, 'message' => 'Profile account not found.'];
        }

        $email_stmt = mysqli_prepare($db, 'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        if (!$email_stmt) {
            return ['success' => false, 'message' => 'Unable to validate the email address.'];
        }
        mysqli_stmt_bind_param($email_stmt, 'si', $email, $user_id);
        mysqli_stmt_execute($email_stmt);
        $email_exists = mysqli_num_rows(mysqli_stmt_get_result($email_stmt)) > 0;
        mysqli_stmt_close($email_stmt);
        if ($email_exists) {
            return ['success' => false, 'message' => 'That email address is already used by another account.'];
        }

        $password_hash = '';
        if ($new_password !== '' || $confirm_password !== '' || $current_password !== '') {
            $stored_password = (string) ($user['password'] ?? '');
            $current_password_matches = password_verify($current_password, $stored_password)
                || ($stored_password !== '' && hash_equals($stored_password, $current_password));
            if (!$current_password_matches) {
                return ['success' => false, 'message' => 'Current password is incorrect.'];
            }
            if (strlen($new_password) < 8) {
                return ['success' => false, 'message' => 'New password must contain at least 8 characters.'];
            }
            if (!hash_equals($new_password, $confirm_password)) {
                return ['success' => false, 'message' => 'New password and confirmation do not match.'];
            }
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        }

        if ($password_hash !== '') {
            $update_stmt = mysqli_prepare(
                $db,
                "UPDATE users
                 SET name = ?, email = ?, phone = ?, dob = NULLIF(?, ''),
                     address = ?, city = ?, state = ?, country = ?, zip_code = ?, password = ?
                 WHERE id = ?"
            );
            if ($update_stmt) {
                mysqli_stmt_bind_param(
                    $update_stmt,
                    'ssssssssssi',
                    $name,
                    $email,
                    $phone,
                    $dob,
                    $address,
                    $city,
                    $state,
                    $country,
                    $zip_code,
                    $password_hash,
                    $user_id
                );
            }
        } else {
            $update_stmt = mysqli_prepare(
                $db,
                "UPDATE users
                 SET name = ?, email = ?, phone = ?, dob = NULLIF(?, ''),
                     address = ?, city = ?, state = ?, country = ?, zip_code = ?
                 WHERE id = ?"
            );
            if ($update_stmt) {
                mysqli_stmt_bind_param(
                    $update_stmt,
                    'sssssssssi',
                    $name,
                    $email,
                    $phone,
                    $dob,
                    $address,
                    $city,
                    $state,
                    $country,
                    $zip_code,
                    $user_id
                );
            }
        }

        if (!$update_stmt || !mysqli_stmt_execute($update_stmt)) {
            if ($update_stmt) {
                mysqli_stmt_close($update_stmt);
            }
            return ['success' => false, 'message' => 'Unable to update your profile.'];
        }
        mysqli_stmt_close($update_stmt);

        $old_email = (string) ($user['email'] ?? '');
        $customer_stmt = mysqli_prepare(
            $db,
            'UPDATE customer_master
             SET name = ?, email = ?, mobile = ?, address = ?
             WHERE email = ?'
        );
        if ($customer_stmt) {
            mysqli_stmt_bind_param($customer_stmt, 'sssss', $name, $email, $phone, $address, $old_email);
            mysqli_stmt_execute($customer_stmt);
            mysqli_stmt_close($customer_stmt);
        }

        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        return [
            'success' => true,
            'message' => $password_hash !== ''
                ? 'Profile details and password updated successfully.'
                : 'Profile details updated successfully.',
        ];
    }
}
