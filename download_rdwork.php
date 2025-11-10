<?php
require_once 'db_connection.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filters
$search_query = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Base query (no LIMIT/OFFSET for full export)
$sql = "SELECT r.employee_id,
               CONCAT(e.FirstName, ' ', e.LastName) AS employee_name,
               r.rd_date,
               r.start_time,
               r.end_time,
               TIMEDIFF(r.end_time, r.start_time) AS rd_hours,
               r.status,
               r.approver_name,
               r.approved_at
        FROM rd_requests r
        LEFT JOIN Employees e ON r.employee_id = e.EmployeeID
        WHERE 1=1";

// Apply filters safely
if (!empty($search_query)) {
    $search_esc = $conn->real_escape_string($search_query);
    $sql .= " AND (r.employee_id LIKE '%$search_esc%' OR CONCAT(e.FirstName, ' ', e.LastName) LIKE '%$search_esc%')";
}
if (!empty($start_date) && !empty($end_date)) {
    $start_esc = $conn->real_escape_string($start_date);
    $end_esc = $conn->real_escape_string($end_date);
    $sql .= " AND r.rd_date BETWEEN '$start_esc' AND '$end_esc'";
}

$sql .= " ORDER BY COALESCE(r.created_at, r.rd_date) DESC";

$result = $conn->query($sql);
if ($result === false) {
    die("Error: " . $conn->error);
}

// Send headers to force download as CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=rd_work_requests.csv');

$output = fopen('php://output', 'w');

// CSV header row
fputcsv($output, [
    'Employee ID',
    'Employee Name',
    'RD Date',
    'Start Time',
    'End Time',
    'Duration (hrs)',
    'Status',
    'Approver',
    'Approved At'
]);

// Write data rows
while ($row = $result->fetch_assoc()) {
    // Calculate duration in hours (handling crossing midnight)
    $start = strtotime($row['start_time']);
    $end = strtotime($row['end_time']);
    $duration = ($end >= $start) 
        ? round(($end - $start) / 3600, 2) 
        : round(((24 * 3600) - $start + $end) / 3600, 2);

    fputcsv($output, [
        $row['employee_id'],
        !empty($row['employee_name']) ? $row['employee_name'] : 'Unknown',
        $row['rd_date'],
        date('H:i', strtotime($row['start_time'])),
        date('H:i', strtotime($row['end_time'])),
        number_format($duration, 2),
        $row['status'],
        !empty($row['approver_name']) ? $row['approver_name'] : 'Unknown',
        !empty($row['approved_at']) ? $row['approved_at'] : 'Pending',
    ]);
}

fclose($output);
exit;
?>
