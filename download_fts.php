<?php
require_once 'db_connection.php'; // Ensure database connection

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=fts_requests.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Employee ID', 'Employee Name', 'FTS Date', 'FTS Time', 'Type', 'Status', 'Approver', 'Approved At']);

$search_query = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$sql = "SELECT * FROM fts_requests WHERE 1=1";

if (!empty($search_query)) {
    $sql .= " AND (employeeID LIKE '%" . $conn->real_escape_string($search_query) . "%'
                OR employee_name LIKE '%" . $conn->real_escape_string($search_query) . "%')";
}
if (!empty($type_filter)) {
    $sql .= " AND fts_type = '" . $conn->real_escape_string($type_filter) . "'";
}
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND fts_date BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
}

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['employeeID'],
        $row['employee_name'] ?? 'N/A',
        $row['fts_date'],
        $row['fts_time'],
        $row['fts_type'],
        $row['status'],
        $row['approver_name'] ?? 'Unknown',
        $row['approved_at'] ?? 'Pending'
    ]);
}

fclose($output);
$conn->close();
exit;
?>

