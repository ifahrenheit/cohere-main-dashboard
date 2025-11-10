<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Database connection
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

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
	    Test t ON e.employeeID = t.companyid
	ORDER BY
	    t.day ASC, t.time ASC";

$result = $conn->query($sql);

// Set headers to prompt download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=employee_data.csv');

// Open output stream
$output = fopen('php://output', 'w');

// Write column headers
fputcsv($output, array('Employee ID', 'First Name', 'Last Name', 'Day', 'Time', 'Type'));

// Write rows to CSV
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
}

fclose($output);
$conn->close();
?>

