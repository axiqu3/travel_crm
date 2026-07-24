<?php

if (!function_exists('search_active_customer_master')) {
    /**
     * Search the shared customer master.
     *
     * Customer suggestions are intentionally not scoped by created_by: every
     * authenticated booking user must be able to select any active master record.
     */
    function search_active_customer_master($db, $query, $limit = 10, $customer_type = '') {
        $query = trim((string) $query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(50, (int) $limit));
        $customer_type = trim((string) $customer_type);
        $search = '%' . $query . '%';
        $sql = "SELECT id, name, mobile, email, customer_type, company_name
                FROM customer_master
                WHERE status = 'Active'
                  AND (
                        ? = ''
                     OR customer_type = ?
                     OR (
                            ? = 'Walk-in Customer'
                        AND customer_type IN ('Walk-in', 'Customer')
                     )
                  )
                  AND (
                        name LIKE ?
                     OR mobile LIKE ?
                     OR company_name LIKE ?
                  )
                ORDER BY name ASC, id DESC
                LIMIT $limit";
        $stmt = mysqli_prepare($db, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ssssss',
            $customer_type,
            $customer_type,
            $customer_type,
            $search,
            $search,
            $search
        );
        $results = [];

        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $results[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'mobile' => (string) ($row['mobile'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'customer_type' => (string) ($row['customer_type'] ?? ''),
                    'company_name' => (string) ($row['company_name'] ?? ''),
                ];
            }
        }

        mysqli_stmt_close($stmt);
        return $results;
    }
}

if (!function_exists('booking_customer_type_allows_creation')) {
    function booking_customer_type_allows_creation($customer_type) {
        return trim((string) $customer_type) === 'Walk-in Customer';
    }
}

if (!function_exists('booking_selected_customer_master_id')) {
    /**
     * Return the selected active master ID only when its name and type still
     * match the submitted booking fields. Legacy walk-in type names remain
     * selectable, but only the current Walk-in Customer type may be created.
     */
    function booking_selected_customer_master_id($db, $customer_id, $name, $customer_type) {
        $customer_id = (int) $customer_id;
        $name = trim((string) $name);
        $customer_type = trim((string) $customer_type);
        if ($customer_id < 1 || $name === '' || $customer_type === '') {
            return 0;
        }

        $stmt = mysqli_prepare(
            $db,
            "SELECT id, customer_type
             FROM customer_master
             WHERE id = ? AND name = ? AND status = 'Active'
             LIMIT 1"
        );
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 'is', $customer_id, $name);
        $resolved_id = 0;
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            $customer = mysqli_fetch_assoc($result);
            $stored_type = trim((string) ($customer['customer_type'] ?? ''));
            $type_matches = $stored_type === $customer_type;

            if ($customer_type === 'Walk-in Customer') {
                $type_matches = in_array(
                    $stored_type,
                    ['Walk-in Customer', 'Walk-in', 'Customer'],
                    true
                );
            }

            if ($customer && $type_matches) {
                $resolved_id = (int) $customer['id'];
            }
        }

        mysqli_stmt_close($stmt);
        return $resolved_id;
    }
}
