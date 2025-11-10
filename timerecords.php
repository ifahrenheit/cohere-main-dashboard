<?php
// Enable error reporting for debugging
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

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Retrieve filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

//--------------------------------------------------------------
// Base SQL Query (Ensure this is properly defined before appending filters)
$sql = "
SELECT employeeID, firstName, lastName, day,
       MAX(time_in) AS time_in, MAX(time_out) AS time_out
FROM (
    -- Get all IN and OUT records for each employee
    SELECT
        e.EmployeeID AS employeeID,
        e.FirstName AS firstName,
        e.LastName AS lastName,
        t1.day AS day,
        t1.time AS time_in,
        t2.time AS time_out
    FROM Employees e
    LEFT JOIN (
        SELECT companyid, day, time
        FROM Timerecords
        WHERE type = 'IN'
    ) t1 ON e.EmployeeID = t1.companyid
    LEFT JOIN (
        SELECT companyid, day, time
        FROM Timerecords
        WHERE type = 'OUT'
    ) t2 ON e.EmployeeID = t2.companyid
        AND (
            (t1.day = t2.day AND t1.time < '15:00:00') -- Regular IN/OUT same day
            OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t1.time >= '15:00:00') -- Late shift IN
            OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t2.time < '09:00:00') -- Morning OUT
        )

    UNION ALL

    -- Get OUT records that do not have a corresponding IN
    SELECT
        e.EmployeeID AS employeeID,
        e.FirstName AS firstName,
        e.LastName AS lastName,
        t2.day AS day,
        NULL AS time_in,
        t2.time AS time_out
    FROM Employees e
    LEFT JOIN (
        SELECT companyid, day, time
        FROM Timerecords
        WHERE type = 'OUT'
    ) t2 ON e.EmployeeID = t2.companyid
    WHERE NOT EXISTS (
        SELECT 1 FROM Timerecords t1
        WHERE t1.companyid = t2.companyid
        AND (
            -- Exclude if there is an IN on the same day
            t1.day = t2.day
            -- Exclude if there was an IN after 15:00 the previous day
            OR (t1.day = DATE_SUB(t2.day, INTERVAL 1 DAY) AND t1.time >= '15:00:00')
        )
    )
    -- Keep OUT-only records if they happen before 9:00 AM the next day
    OR EXISTS (
        SELECT 1 FROM Timerecords next_day_out
        WHERE next_day_out.companyid = t2.companyid
        AND next_day_out.day = DATE_ADD(t2.day, INTERVAL 1 DAY)
        AND next_day_out.type = 'OUT'
        AND next_day_out.time < '09:00:00'
    )
) AS combined_results
-- ✅ Apply Search Filters
WHERE (employeeID LIKE ? OR firstName LIKE ? OR lastName LIKE ?)
-- ✅ Group by Employee and Day to merge duplicates
GROUP BY employeeID, firstName, lastName, day
-- ✅ Filter out rest days (no IN and no OUT)
HAVING (MAX(time_in) IS NOT NULL OR MAX(time_out) IS NOT NULL)
ORDER BY day, employeeID
LIMIT ? OFFSET ?
";

// Initialize parameters
$params = [];
$types = "";

// Search Filters
if (!empty($search)) {
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param);
    $types .= "sss"; // 3 string parameters (employeeID, firstName, lastName)
}

// Pagination
array_push($params, $limit, $offset);
$types .= "ii"; // 2 integer parameters (LIMIT and OFFSET)

// Debugging (optional)
var_dump($params);
var_dump($types);

// Prepare and execute the query
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

//-----------------------------------------------------------
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Attendance</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
<h1>Employee Attendance Records</h1>

<form method="GET">
    <input type="text" name="search" placeholder="Search Employee ID or Name" value="<?= htmlspecialchars($search) ?>">
    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
    <button type="submit">Search</button>
</form>

<a href="download_fts_data.php?search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" target="_blank">Filtered Download</a>

<table>
    <tr>
        <th>Employee ID</th>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Day</th>
        <th>Time IN</th>
        <th>Time OUT</th>
    </tr>
    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['employeeID'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['firstName'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['lastName'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['day'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['time_in'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['time_out'] ?? '-') ?></td>
        </tr>
    <?php endwhile; ?>
</table>

<?php $conn->close(); ?>
</body>
</html>
