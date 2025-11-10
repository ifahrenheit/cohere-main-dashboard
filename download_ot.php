<?php
require_once 'db_connection.php'; // Ensure database connection

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ✅ Force fresh CSV download each time
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ✅ Force download as CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=ot_requests.csv');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header row
fputcsv($output, [
    'Employee ID', 'Name', 'OT Date', 'Start Time', 'End Time',
    'OT Hours', 'OT Type', 'Regular Rate', 'Status', 'Approver', 'Approved At'
]);

// Retrieve and sanitize filters
$search_query = $conn->real_escape_string($_GET['search'] ?? '');
$type_filter  = $conn->real_escape_string($_GET['type'] ?? '');
$start_date   = $conn->real_escape_string($_GET['start_date'] ?? '');
$end_date     = $conn->real_escape_string($_GET['end_date'] ?? '');

// Build base query
$sql = "
    SELECT
        o.employee_id,
        CONCAT(e.FirstName, ' ', e.LastName) AS name,
        o.ot_date,
        o.start_time,
        o.end_time,
        CASE
            WHEN o.end_time < o.start_time THEN
                TIME_FORMAT(TIMEDIFF(ADDTIME(o.end_time, '24:00:00'), o.start_time), '%H:%i')
            ELSE
                TIME_FORMAT(TIMEDIFF(o.end_time, o.start_time), '%H:%i')
        END AS ot_hours,
        o.ot_type,
        o.regular_rate,
        o.status,
        o.approver_name,
        o.approved_at
    FROM ot_requests o
    LEFT JOIN Employees e ON o.employee_id = e.EmployeeID
    WHERE 1=1
";

$sql .= " AND o.deleted_at IS NULL ";


// Apply filters
if (!empty($search_query)) {
    $sql .= " AND (
        o.employee_id LIKE '%$search_query%' 
        OR e.FirstName LIKE '%$search_query%' 
        OR e.LastName LIKE '%$search_query%'
    )";
}
if (!empty($type_filter)) {
    $sql .= " AND o.ot_type = '$type_filter'";
}
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND o.ot_date BETWEEN '$start_date' AND '$end_date'";
}

// Sort newest first
$sql .= " ORDER BY o.ot_date DESC, o.start_time DESC";

// Run query
$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Output each row to CSV
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['employee_id'],
        $row['name'] ?? 'N/A',
        $row['ot_date'],
        $row['start_time'],
        $row['end_time'],
        $row['ot_hours'],
        $row['ot_type'],
        $row['regular_rate'],
        $row['status'],
        $row['approver_name'] ?? 'Unknown',
        $row['approved_at'] ?? 'Pending'
    ]);
}

fclose($output);
$conn->close();
exit;
?>
