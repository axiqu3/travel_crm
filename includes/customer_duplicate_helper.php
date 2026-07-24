<?php

/**
 * Shared duplicate-customer utilities.
 *
 * This file deliberately does not include db.php. Every caller must establish
 * authentication and a database connection before loading the helper.
 */

function normalize_phone($phone) {
    $digits = preg_replace('/[^0-9]/', '', trim((string) $phone));
    if ($digits === '') {
        return '';
    }

    // The CRM primarily stores Indian contact numbers. Comparing the final ten
    // digits makes +91, 0091, leading-zero and local formats equivalent.
    return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
}

function normalize_email($email) {
    return strtolower(trim((string) $email));
}

function normalize_customer_name($name) {
    $name = preg_replace('/\s+/', ' ', trim((string) $name));
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function customer_duplicate_table_config($table) {
    $tables = [
        'customer_master' => [
            'table' => 'customer_master',
            'customer_type_sql' => 'customer_type',
            'status_sql' => "status = 'Active'",
        ],
        'customers' => [
            'table' => 'customers',
            'customer_type_sql' => "'Walk-in Customer'",
            'status_sql' => '1 = 1',
        ],
    ];

    return $tables[$table] ?? null;
}

function customer_names_are_similar($left, $right) {
    $left = normalize_customer_name($left);
    $right = normalize_customer_name($right);
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }

    // Levenshtein works on bytes, so keep it bounded and use it only as a
    // conservative typo detector.
    if (strlen($left) > 255 || strlen($right) > 255) {
        return false;
    }

    $max_distance = max(strlen($left), strlen($right)) >= 12 ? 2 : 1;
    return levenshtein($left, $right) <= $max_distance;
}

/**
 * Search customer_master or customers using prepared queries only.
 *
 * exact: normalized phone OR case-insensitive email is identical.
 * possible: name is similar and both records have at least one populated
 * contact identifier, but neither identifier is an exact match.
 */
function search_duplicate_customers($db, $table, $name, $mobile, $email, $exclude_id = null) {
    $config = customer_duplicate_table_config($table);
    if (!$config) {
        return [];
    }

    $normalized_phone = normalize_phone($mobile);
    $normalized_email = normalize_email($email);
    $normalized_name = normalize_customer_name($name);

    // Names alone are not sufficient to block insertion, and empty contact
    // values must never match other empty contact values.
    if ($normalized_phone === '' && $normalized_email === '') {
        return [];
    }

    $conditions = [];
    $params = [];
    $types = '';
    $phone_sql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), CHAR(9), '')";

    if ($normalized_phone !== '') {
        if (strlen($normalized_phone) >= 10) {
            $conditions[] = "RIGHT($phone_sql, 10) = ?";
        } else {
            $conditions[] = "$phone_sql = ?";
        }
        $params[] = $normalized_phone;
        $types .= 's';
    }

    if ($normalized_email !== '') {
        $conditions[] = 'LOWER(TRIM(email)) = ?';
        $params[] = $normalized_email;
        $types .= 's';
    }

    if ($normalized_name !== '') {
        $conditions[] = 'LOWER(TRIM(name)) = ?';
        $params[] = $normalized_name;
        $types .= 's';

        if (strlen($normalized_name) >= 3) {
            $conditions[] = 'SOUNDEX(name) = SOUNDEX(?)';
            $params[] = $normalized_name;
            $types .= 's';
        }
    }

    $sql = 'SELECT id, name, mobile, email, '
        . $config['customer_type_sql'] . ' AS customer_type '
        . 'FROM ' . $config['table'] . ' '
        . 'WHERE ' . $config['status_sql'] . ' AND (' . implode(' OR ', $conditions) . ')';

    if ($exclude_id !== null && (int) $exclude_id > 0) {
        $sql .= ' AND id <> ?';
        $params[] = (int) $exclude_id;
        $types .= 'i';
    }
    $sql .= ' ORDER BY id ASC LIMIT 20';

    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    $query_result = mysqli_stmt_get_result($stmt);
    $matches = [];
    while ($row = mysqli_fetch_assoc($query_result)) {
        $row_phone = normalize_phone($row['mobile'] ?? '');
        $row_email = normalize_email($row['email'] ?? '');
        $phone_matches = $normalized_phone !== '' && $row_phone !== '' && $normalized_phone === $row_phone;
        $email_matches = $normalized_email !== '' && $row_email !== '' && $normalized_email === $row_email;

        $match_type = 'none';
        $matched_by = [];
        if ($phone_matches || $email_matches) {
            $match_type = 'exact';
            if ($phone_matches) {
                $matched_by[] = 'mobile';
            }
            if ($email_matches) {
                $matched_by[] = 'email';
            }
        } else {
            $input_has_identifier = $normalized_phone !== '' || $normalized_email !== '';
            $row_has_identifier = $row_phone !== '' || $row_email !== '';
            if ($input_has_identifier && $row_has_identifier && customer_names_are_similar($name, $row['name'])) {
                $match_type = 'possible';
                $matched_by[] = 'similar_name';
            }
        }

        if ($match_type !== 'none') {
            $matches[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'customer_type' => (string) ($row['customer_type'] ?? 'Walk-in Customer'),
                'match_type' => $match_type,
                'matched_by' => $matched_by,
            ];
        }
    }
    mysqli_stmt_close($stmt);

    usort($matches, function ($left, $right) {
        if ($left['match_type'] === $right['match_type']) {
            return $left['id'] <=> $right['id'];
        }
        return $left['match_type'] === 'exact' ? -1 : 1;
    });

    return $matches;
}

function customer_duplicate_first_exact($duplicates) {
    foreach ($duplicates as $duplicate) {
        if (($duplicate['match_type'] ?? '') === 'exact') {
            return $duplicate;
        }
    }
    return null;
}

function customer_duplicate_match_type($duplicates) {
    if (customer_duplicate_first_exact($duplicates)) {
        return 'exact';
    }
    return !empty($duplicates) ? 'possible' : 'none';
}

function customer_duplicate_find_by_id($db, $table, $customer_id) {
    $config = customer_duplicate_table_config($table);
    $customer_id = (int) $customer_id;
    if (!$config || $customer_id <= 0) {
        return null;
    }

    $sql = 'SELECT id, name, mobile, email, '
        . $config['customer_type_sql'] . ' AS customer_type '
        . 'FROM ' . $config['table'] . ' WHERE id = ? AND ' . $config['status_sql'] . ' LIMIT 1';
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $customer = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);
    return $customer;
}

function customer_duplicate_log($db, $username, $action, $module = 'Customer Master') {
    $stmt = mysqli_prepare(
        $db,
        'INSERT INTO activity_log (username, action, module, activity_date) VALUES (?, ?, ?, NOW())'
    );
    if (!$stmt) {
        return false;
    }
    $username = trim((string) $username) ?: 'System';
    $action = substr(trim((string) $action), 0, 255);
    $module = substr(trim((string) $module), 0, 100);
    mysqli_stmt_bind_param($stmt, 'sss', $username, $action, $module);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $saved;
}

function customer_duplicate_safe_result($duplicate) {
    return [
        'id' => (int) ($duplicate['id'] ?? 0),
        'name' => (string) ($duplicate['name'] ?? ''),
        'customer_type' => (string) ($duplicate['customer_type'] ?? 'Walk-in Customer'),
        'mobile' => (string) ($duplicate['mobile'] ?? ''),
        'email' => (string) ($duplicate['email'] ?? ''),
        'match_type' => (string) ($duplicate['match_type'] ?? 'possible'),
        'matched_by' => array_values($duplicate['matched_by'] ?? []),
    ];
}
