<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];

if ($query !== '') {
    $escaped_query = mysqli_real_escape_string($db, $query);
    
    // Search by name, mobile, or company_name (only active customers)
    $sql = "SELECT id, name, mobile, email, customer_type, company_name 
            FROM customer_master 
            WHERE status = 'Active' 
              AND (name LIKE '%$escaped_query%' 
                OR mobile LIKE '%$escaped_query%' 
                OR company_name LIKE '%$escaped_query%')
            LIMIT 10";
            
    $res = mysqli_query($db, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $results[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'mobile' => $row['mobile'],
                'email' => $row['email'],
                'customer_type' => $row['customer_type'],
                'company_name' => $row['company_name']
            ];
        }
    }
}

echo json_encode($results);
exit;
