<?php

if (!function_exists('profile_image_csrf_token')) {
    function profile_image_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['profile_image_csrf_token'])) {
            $_SESSION['profile_image_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['profile_image_csrf_token'];
    }
}

if (!function_exists('profile_image_public_path')) {
    function profile_image_public_path($path) {
        $path = str_replace('\\', '/', trim((string) $path));
        return preg_match('~^uploads/profile_images/[a-zA-Z0-9._-]+$~', $path)
            ? $path
            : '';
    }
}

if (!function_exists('profile_image_for_user')) {
    function profile_image_for_user($db, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return '';
        }

        $stmt = mysqli_prepare($db, 'SELECT profile_image FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        $path = '';
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
            $path = profile_image_public_path($user['profile_image'] ?? '');
        }
        mysqli_stmt_close($stmt);
        return $path;
    }
}

if (!function_exists('profile_information_for_user')) {
    function profile_information_for_user($db, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return [];
        }

        $stmt = mysqli_prepare(
            $db,
            'SELECT name, email, role, phone, dob, address, city, state, country, zip_code, profile_image
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        $profile = [];
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            $profile = mysqli_fetch_assoc($result) ?: [];
            if ($profile) {
                $profile['profile_image'] = profile_image_public_path($profile['profile_image'] ?? '');
            }
        }
        mysqli_stmt_close($stmt);
        return $profile;
    }
}

if (!function_exists('profile_image_upload')) {
    function profile_image_upload($db, $user_id, $file, $submitted_token) {
        $user_id = (int) $user_id;
        $session_token = profile_image_csrf_token();
        if (
            $user_id < 1
            || !is_string($submitted_token)
            || !hash_equals($session_token, $submitted_token)
        ) {
            return ['success' => false, 'message' => 'Your session expired. Refresh the dashboard and try again.'];
        }

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = $upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE
                ? 'The profile image is too large.'
                : 'Choose a profile image to upload.';
            return ['success' => false, 'message' => $message];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Profile image must be 5MB or smaller.'];
        }

        $temporary_path = (string) ($file['tmp_name'] ?? '');
        if ($temporary_path === '' || !is_uploaded_file($temporary_path)) {
            return ['success' => false, 'message' => 'The uploaded profile image could not be verified.'];
        }

        $image_info = @getimagesize($temporary_path);
        $mime = is_array($image_info) ? (string) ($image_info['mime'] ?? '') : '';
        $allowed_mime_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed_mime_types[$mime])) {
            return ['success' => false, 'message' => 'Use a JPG, PNG, or WebP profile image.'];
        }

        $upload_directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_images';
        if (!is_dir($upload_directory) && !mkdir($upload_directory, 0775, true) && !is_dir($upload_directory)) {
            return ['success' => false, 'message' => 'The profile image folder could not be created.'];
        }

        $extension = $allowed_mime_types[$mime];
        $filename = 'user_' . $user_id . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
        $absolute_path = $upload_directory . DIRECTORY_SEPARATOR . $filename;
        $public_path = 'uploads/profile_images/' . $filename;
        if (!move_uploaded_file($temporary_path, $absolute_path)) {
            return ['success' => false, 'message' => 'The profile image could not be saved.'];
        }

        $old_path = profile_image_for_user($db, $user_id);
        $stmt = mysqli_prepare($db, 'UPDATE users SET profile_image = ? WHERE id = ?');
        if (!$stmt) {
            @unlink($absolute_path);
            return ['success' => false, 'message' => 'The profile image could not be updated.'];
        }

        mysqli_stmt_bind_param($stmt, 'si', $public_path, $user_id);
        $updated = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$updated) {
            @unlink($absolute_path);
            return ['success' => false, 'message' => 'The profile image could not be updated.'];
        }

        if ($old_path !== '' && $old_path !== $public_path) {
            $old_absolute_path = $upload_directory . DIRECTORY_SEPARATOR . basename($old_path);
            if (is_file($old_absolute_path)) {
                @unlink($old_absolute_path);
            }
        }

        $_SESSION['profile_image'] = $public_path;
        return [
            'success' => true,
            'message' => 'Profile image updated successfully.',
            'path' => $public_path,
        ];
    }
}
