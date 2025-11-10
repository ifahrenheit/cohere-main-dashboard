<?php
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$database = "central_db";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Retrieve filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$params = [];
$types = "";
$where_conditions = [];

if (!empty($search)) {
    $where_conditions[] = "(e.EmployeeID LIKE ? OR e.FirstName LIKE ? OR e.LastName LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param);
    $types .= "sss";
}

if (!empty($start_date)) {
    $where_conditions[] = "t.day >= ?";
    array_push($params, $start_date);
    $types .= "s";
}
if (!empty($end_date)) {
    $where_conditions[] = "t.day <= ?";
    array_push($params, $end_date);
    $types .= "s";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$sql = "SELECT e.EmployeeID, e.FirstName, e.LastName, t.day, 
    MAX(CASE WHEN t.type = 'IN' THEN t.time END) AS Time_IN, 
    MAX(CASE WHEN t.type = 'OUT' THEN t.time END) AS Time_OUT
FROM Employees e
JOIN Timerecords t ON e.EmployeeID = t.companyid
$where_sql
GROUP BY e.EmployeeID, e.FirstName, e.LastName, t.day
ORDER BY t.day DESC";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=timerecords.csv");

$output = fopen("php://output", "w");
fputcsv($output, ["Employee ID", "First Name", "Last Name", "Day", "Time IN", "Time OUT"]);

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
$conn->close();
exit();
?>

