<?php
require_once(__DIR__ . '/../../includes/db.php');
require_once(__DIR__ . '/../../includes/auth.php');
require_once(__DIR__ . '/../../includes/booking_export.php');

check_auth();

$allowedServices = ['all', 'flight', 'hotel', 'visa', 'passport', 'insurance', 'package', 'other'];
$selectedService = strtolower(trim((string) ($_GET['export_service'] ?? 'all')));
if (!in_array($selectedService, $allowedServices, true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Please select a valid service type.']);
    exit;
}

$isValidDate = static function ($value) {
    if ($value === '') return true;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};

$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$customerType = trim((string) ($_GET['customer_type'] ?? ''));
$userName = (string) ($_SESSION['user_name'] ?? '');

if (!$isValidDate($startDate) || !$isValidDate($endDate) || strlen($customerType) > 100 || $userName === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'One or more export filters are invalid.']);
    exit;
}

$where = ['b.created_by = ?'];
$params = [$userName];
if ($startDate !== '') {
    $where[] = 'b.booking_date >= ?';
    $params[] = $startDate;
}
if ($endDate !== '') {
    $where[] = 'b.booking_date <= ?';
    $params[] = $endDate;
}
if ($customerType !== '') {
    $where[] = 'b.customer_type = ?';
    $params[] = $customerType;
}
if ($selectedService !== 'all') {
    $where[] = booking_export_service_case_sql('b') . ' = ?';
    $params[] = $selectedService;
}

$sql = "SELECT b.*,
        COALESCE(NULLIF(cm.mobile, ''), NULLIF(cmn.mobile, ''), NULLIF(c.mobile, ''), '') AS customer_phone,
        COALESCE(NULLIF(cm.email, ''), NULLIF(cmn.email, ''), NULLIF(c.email, ''), '') AS customer_email
    FROM bookings b
    LEFT JOIN customer_master cm ON cm.id = b.customer_master_id
    LEFT JOIN customer_master cmn ON cmn.id = (
        SELECT MAX(cm2.id) FROM customer_master cm2 WHERE cm2.name = b.customer_name
    )
    LEFT JOIN customers c ON c.id = (
        SELECT MAX(c2.id) FROM customers c2 WHERE c2.name = b.customer_name
    )
    WHERE " . implode(' AND ', $where) . '
    ORDER BY CAST(b.serial_no AS UNSIGNED) DESC, b.id DESC';

$statement = mysqli_prepare($db, $sql);
if (!$statement || !mysqli_stmt_execute($statement, $params)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'The booking export could not be generated.']);
    exit;
}

$result = mysqli_stmt_get_result($statement);
$bookings = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
mysqli_stmt_close($statement);

if (!$bookings) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'No matching bookings were found for this export.']);
    exit;
}

$filename = 'bookings_' . $selectedService . '_' . date('Y-m-d') . '.xlsx';
$workbook = booking_export_xlsx(booking_export_columns($selectedService), $bookings);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($workbook));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $workbook;
exit;
