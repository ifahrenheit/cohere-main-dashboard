<?php
require_once("db_connection.php"); // Include your database connection

// Get search and pagination parameters from URL
$search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 1000; // Default 1000 rows
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Set CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="filtered_attendance.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write column headers
fputcsv($output, ["Employee ID", "First Name", "Last Name", "Day", "Time IN", "Time OUT"]);

// Prepare SQL statement (SAME AS test.php)
$sql = "SELECT employeeID, firstName, lastName, day,
               MAX(time_in) AS time_in, MAX(time_out) AS time_out
        FROM (
            SELECT e.EmployeeID AS employeeID, e.FirstName AS firstName, e.LastName AS lastName,
                   t1.day AS day, t1.time AS time_in, t2.time AS time_out
            FROM Employees e
            LEFT JOIN (SELECT companyid, day, time FROM Timerecords WHERE type = 'IN') t1
                ON e.EmployeeID = t1.companyid
            LEFT JOIN (SELECT companyid, day, time FROM Timerecords WHERE type = 'OUT') t2
                ON e.EmployeeID = t2.companyid
                AND (
                    (t1.day = t2.day AND t1.time < '15:00:00')
                    OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t1.time >= '15:00:00')
                    OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t2.time < '09:00:00')
                )
            UNION ALL
            SELECT e.EmployeeID AS employeeID, e.FirstName AS firstName, e.LastName AS lastName,
                   t2.day AS day, NULL AS time_in, t2.time AS time_out
            FROM Employees e
            LEFT JOIN (SELECT companyid, day, time FROM Timerecords WHERE type = 'OUT') t2
                ON e.EmployeeID = t2.companyid
            WHERE NOT EXISTS (
                SELECT 1 FROM Timerecords t1 WHERE t1.companyid = t2.companyid
                AND (t1.day = t2.day OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t1.time >= '15:00:00'))
            )
            OR EXISTS (
                SELECT 1 FROM Timerecords next_day_out
                WHERE next_day_out.companyid = t2.companyid
                AND next_day_out.day = DATE_ADD(t2.day, INTERVAL 1 DAY)
                AND next_day_out.type = 'OUT' AND next_day_out.time < '09:00:00'
            )
        ) AS combined_results
        WHERE (employeeID LIKE ? OR firstName LIKE ? OR lastName LIKE ?)
        GROUP BY employeeID, firstName, lastName, day
        HAVING (MAX(time_in) IS NOT NULL OR MAX(time_out) IS NOT NULL)
        ORDER BY day, employeeID";
        

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $search, $search, $search);
$stmt->execute();
$result = $stmt->get_result();

// Write rows to CSV
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>

