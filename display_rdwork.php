<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // 🔥 REQUIRED

require_once 'db_connection.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$search_query = $_GET['search'] ?? '';
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 15;
$offset       = ($page - 1) * $limit;

// ROLE CHECK — SAME AS FTS
$allowed_roles = ['admin', 'manager', 'director', 'som approver'];
$is_admin_or_approver = isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), $allowed_roles);

// BASE QUERY — HIDE SOFT-DELETED ONLY
$sql = "SELECT r.*,
               CONCAT(e.FirstName, ' ', e.LastName) AS employee_name,
               TIMEDIFF(r.end_time, r.start_time) AS rd_hours,
               e.SOM
        FROM rd_requests r
        LEFT JOIN Employees e ON r.employee_id = e.EmployeeID
        WHERE r.deleted_at IS NULL";


// FILTERS
if (!empty($search_query)) {
    $q = $conn->real_escape_string($search_query);
    $sql .= " AND (
        r.employee_id LIKE '%$q%' OR CONCAT(e.FirstName, ' ', e.LastName) LIKE '%$q%'
    )";
}

if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND r.rd_date BETWEEN '" . $conn->real_escape_string($start_date) . "' 
                                AND '" . $conn->real_escape_string($end_date) . "'";
}

$sql .= " ORDER BY COALESCE(r.created_at, r.rd_date) DESC
          LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);

// PAGINATION COUNT
$count_sql = "SELECT COUNT(*) AS count FROM rd_requests WHERE deleted_at IS NULL";
$total_records = $conn->query($count_sql)->fetch_assoc()['count'];
$total_pages = ceil($total_records / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>RD Work Requests</title>
    <link rel="stylesheet" href="style.css">

    <script>
    function confirmDelete(id) {
        if (confirm('Are you sure you want to delete this RD Work request?\n\nThis action allows the agent to refile.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'delete_rdwork.php';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'id';
            input.value = id;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
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
    <?php if ($is_admin_or_approver): ?><th>Action</th><?php endif; ?>
</tr>

<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?php echo $row['employee_id']; ?></td>
    <td><?php echo $row['employee_name'] ?? 'Unknown'; ?></td>
    <td><?php echo $row['rd_date']; ?></td>
    <td><?php echo date('H:i', strtotime($row['start_time'])); ?></td>
    <td><?php echo date('H:i', strtotime($row['end_time'])); ?></td>
    <td><?php 
        $start = strtotime($row['start_time']);
        $end   = strtotime($row['end_time']);
        $hours = ($end >= $start) ? ($end - $start)/3600 : ((86400 - $start + $end)/3600);
        echo number_format($hours, 2);
    ?></td>
    <td><?php echo $row['status']; ?></td>
    <td><?php
        echo !empty($row['approver_name']) 
            ? $row['approver_name'] 
            : (!empty($row['SOM']) ? $row['SOM'] : 'Unknown');
    ?></td>
    <td><?php echo $row['approved_at'] ?? 'Pending'; ?></td>

    <?php if ($is_admin_or_approver): ?>
    <td>
        <button onclick="confirmDelete(<?php echo (int)$row['id']; ?>)">Delete</button>
    </td>
    <?php endif; ?>
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
<?php $conn->close(); ?>
