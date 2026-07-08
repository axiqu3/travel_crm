<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=my_bookings_export_' . date('Y-m-d') . '.csv');

// Prepend UTF-8 BOM for Excel compatibility on Windows
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Output column headers
fputcsv($output, [
    'ID', 'Serial No', 'Booking Date', 'Passenger Name', 'Customer/Agency Name', 
    'From City', 'To City', 'Departure Date', 'Departure Time', 
    'Arrival Date', 'Arrival Time', 'PNR', 'Ticket Number', 
    'Flight Number', 'Airline Name', 'Flight Class', 'Terminal', 
    'Seat Number', 'Baggage', 'Booking Reference', 'Fare Basis', 
    'Service Type', 'Supplier Name', 'Buying Cost', 'Selling Cost', 
    'Profit', 'Payment Method', 'Status'
]);

// Fetch bookings (filtered to show only the user's bookings)
$user_name = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? '');
$query = "SELECT * FROM bookings WHERE assigned_user = '$user_name' OR created_by = '$user_name' ORDER BY id DESC";
$result = mysqli_query($db, $query);

while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        $row['id'], 
        $row['serial_no'], 
        $row['booking_date'], 
        $row['passenger_name'], 
        $row['customer_name'],
        $row['from_city'], 
        $row['to_city'], 
        $row['departure_date'], 
        $row['departure_time'],
        $row['arrival_date'], 
        $row['arrival_time'], 
        $row['pnr'], 
        $row['ticket_number'],
        $row['flight_number'], 
        $row['airline_name'], 
        $row['flight_class'], 
        $row['terminal'],
        $row['seat_number'], 
        $row['baggage'], 
        $row['booking_ref'], 
        $row['fare_basis'],
        $row['service_type'], 
        $row['supplier_name'], 
        $row['buying_cost'], 
        $row['selling_cost'],
        $row['profit'], 
        $row['payment_method'], 
        $row['status']
    ]);
}

fclose($output);
exit;
?>
