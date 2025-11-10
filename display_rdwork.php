<?php
require_once 'db_connection.php'; // Ensure database connection

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$search_query = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build the SQL query for RD Work requests
$sql = "SELECT r.*,
               CONCAT(e.FirstName, ' ', e.LastName) AS employee_name,
               TIMEDIFF(r.end_time, r.start_time) AS rd_hours
        FROM rd_requests r
        LEFT JOIN Employees e ON r.employee_id = e.EmployeeID
        WHERE 1=1";

if (!empty($search_query)) {
    $sql .= " AND (r.employee_id LIKE '%" . $conn->real_escape_string($search_query) . "%'
                OR CONCAT(e.FirstName, ' ', e.LastName) LIKE '%" . $conn->real_escape_string($search_query) . "%')";
}

if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND r.rd_date BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
}

$sql .= " ORDER BY COALESCE(r.created_at, r.rd_date) DESC LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);

// Get total records for pagination (note: filters not applied here to mimic display_ot.php)
$total_records = $conn->query("SELECT COUNT(*) as count FROM rd_requests WHERE 1=1")->fetch_assoc()['count'];
$total_pages = ceil($total_records / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>RD Work Requests</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="header">
        <h2>RD Work Requests</h2>
        <div class="logout-btn">
            <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>   
    <div class="container">
        <form method="GET" class="form-container">
            <div class="form-content">
                <input type="text" name="search" placeholder="Search by Employee" value="<?php echo htmlspecialchars($search_query); ?>">
                <label>From:</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                <label>To:</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                <button type="submit">Filter</button>
                <button type="submit" formaction="download_rdwork.php">Download CSV</button>
            </div>
        </form>
    
<table>
    <tr>
        <th>Employee ID</th>
        <th>Employee Name</th>
        <th>RD Date</th>
        <th>Start Time</th>
        <th>End Time</th>
        <th>Duration (hrs)</th>
        <th>Status</th>
        <th>Approver</th>
        <th>Approved At</th>
    </tr>
    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['employee_id']; ?></td>
            <td><?php echo !empty($row['employee_name']) ? $row['employee_name'] : 'Unknown'; ?></td>
            <td><?php echo $row['rd_date']; ?></td>
            <td><?php echo date('H:i', strtotime($row['start_time'])); ?></td>
            <td><?php echo date('H:i', strtotime($row['end_time'])); ?></td>
            <td>
                <?php
                $start = strtotime($row['start_time']);
                $end = strtotime($row['end_time']);
                $duration = ($end >= $start) ? round(($end - $start) / 3600, 2) : round(((24 * 3600) - $start + $end) / 3600, 2);
                echo number_format($duration, 2);
                ?>
            </td>
            <td><?php echo $row['status']; ?></td>
            <td><?php echo !empty($row['approver_name']) ? $row['approver_name'] : 'Unknown'; ?></td>
            <td><?php echo !empty($row['approved_at']) ? $row['approved_at'] : 'Pending'; ?></td>
        </tr>
    <?php endwhile; ?>
</table>
    
	<div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>

