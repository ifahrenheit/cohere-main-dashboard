<?php
// Database connection setup
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Retrieve search parameters from URL
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Initialize where conditions array
$where_conditions = [];

// Build WHERE clause based on search parameters

if (!empty($search)) {
    $where_conditions[] = "(e.employeeID LIKE '%$search%' OR e.firstName LIKE '%$search%' OR e.lastName LIKE '%$search%')";
}

if (!empty($start_date) && strtotime($start_date)) {
    // Convert to the correct format
    $start_date = date('Y-m-d', strtotime($start_date));
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') >= '$start_date'";
}

if (!empty($end_date) && strtotime($end_date)) {
    // Convert to the correct format
    $end_date = date('Y-m-d', strtotime($end_date));
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') <= '$end_date'";
}

// Base SQL query to fetch the filtered data
$sql = "SELECT DISTINCT
            e.employeeID,
            e.firstName,
            e.lastName,
            t.day,
            t.time,
            t.type
        FROM
            Employees e
        JOIN
            Test t ON e.employeeID = t.companyid";

// Add WHERE clause if filters are applied
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

// Add ORDER BY clause for sorting by last name, day, and time
$sql .= " ORDER BY
            e.employeeID ASC,
            STR_TO_DATE(t.day, '%m/%d/%Y') ASC,
            STR_TO_DATE(t.time, '%H:%i') ASC";
// Debugging: Uncomment to check the query being executed
//echo "<pre>" . $sql . "</pre>";

// Execute the query
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
        // Get start_date and end_date from URL parameters
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : 'Start_Date';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : 'End_Date';

    // Replace slashes or invalid characters for filenames, if needed
    $start_date = str_replace('/', '-', $start_date);
    $end_date = str_replace('/', '-', $end_date);

    // Set headers for CSV download
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment;filename=filtered_attendance_{$start_date}_{$end_date}.csv");

    // Open output stream
    $output = fopen('php://output', 'w');

    // Output CSV column headers
    fputcsv($output, ['Employee ID', 'First Name', 'Last Name', 'Day', 'Time', 'Type']);

    // Output each row
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['employeeID'],
            $row['firstName'],
            $row['lastName'],
            $row['day'],
            $row['time'],
            $row['type']
        ]);
    }

    fclose($output);
} else {
    echo "No records found for the specified criteria.";
}

$conn->close();
?>

