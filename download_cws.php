<?php
require 'db_connection.php';

// Get filters from GET
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Base query (same as your main query)
$sql = "
    SELECT c.id, 
           c.employee_id, 
           CONCAT(e.FirstName, ' ', e.LastName) AS employee_name, 
           c.original_date, 
           c.original_time, 
           c.new_date, 
           c.new_time, 
           CASE 
               WHEN c.new_time < c.original_time THEN 
                   TIMEDIFF(ADDTIME(c.new_time, '24:00:00'), c.original_time)
               ELSE 
                   TIMEDIFF(c.new_time, c.original_time)
           END AS cws_hours,
           c.reason, 
           c.status, 
           c.approver_name, 
           c.approved_at
    FROM cws_requests c
    LEFT JOIN Employees e ON c.employee_id = e.EmployeeID
    WHERE 1=1
";

// Apply search filters
if (!empty($search)) {
    $search_escaped = $conn->real_escape_string($search);
    $sql .= " AND (e.FirstName LIKE '%$search_escaped%' OR e.LastName LIKE '%$search_escaped%' OR c.employee_id LIKE '%$search_escaped%')";
}
if (!empty($start_date)) {
    $start_date_escaped = $conn->real_escape_string($start_date);
    $sql .= " AND c.original_date >= '$start_date_escaped'";
}
if (!empty($end_date)) {
    $end_date_escaped = $conn->real_escape_string($end_date);
    $sql .= " AND c.original_date <= '$end_date_escaped'";
}

$result = $conn->query($sql);
if ($result === false) {
    die("Error: " . $conn->error);
}

// Set headers to force download of CSV file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=cws_requests.csv');

// Open output stream
$output = fopen('php://output', 'w');

// Output the column headings
fputcsv($output, [
    'Employee ID',
    'Name',
    'Original Date',
    'Original Time',
    'New Date',
    'New Time',
    'CWS Hours',
    'Reason',
    'Status',
    'Approver',
    'Approved At'
]);

// Output rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['employee_id'],
        !empty($row['employee_name']) ? $row['employee_name'] : 'Unknown',
        $row['original_date'],
        $row['original_time'],
        $row['new_date'],
        $row['new_time'],
        $row['cws_hours'],
        $row['reason'],
        $row['status'],
        $row['approver_name'] ?? 'Unknown',
        $row['approved_at'] ?? 'Pending'
    ]);
}

// Close output stream
fclose($output);
exit;
?>
