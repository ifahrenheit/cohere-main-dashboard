<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

require_once 'db_connection.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$search_query = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$allowed_roles = ['admin', 'manager', 'director', 'som approver'];
$is_admin_or_approver = isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), $allowed_roles);

// Base query
$sql = "
    SELECT f.*,
           e.SOM AS som_approver
    FROM fts_requests f
    LEFT JOIN Employees e ON f.employeeID = e.EmployeeID
    WHERE f.deleted_at IS NULL
";

// Apply filters
if (!empty($search_query)) {
    $search_escaped = $conn->real_escape_string($search_query);
    $sql .= " AND (f.employeeID LIKE '%$search_escaped%' OR f.employee_name LIKE '%$search_escaped%')";
}
if (!empty($type_filter)) {
    $sql .= " AND f.fts_type = '" . $conn->real_escape_string($type_filter) . "'";
}
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND f.fts_date BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
}

$sql .= " ORDER BY f.fts_date DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Pagination
$count_sql = "SELECT COUNT(*) as count FROM fts_requests f LEFT JOIN Employees e ON f.employeeID = e.EmployeeID WHERE f.deleted_at IS NULL";
$total_records = $conn->query($count_sql)->fetch_assoc()['count'] ?? 0;
$total_pages = ceil($total_records / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>FTS Requests</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <script>
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this FTS request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_fts.php';
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
    <h2>FTS Requests</h2>
    <div class="logout-btn">
        <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
        <a href="logout.php"><button>Logout</button></a>
    </div>
</div>
<div class="container">
    <form method="GET" class="form-container">
        <div class="form-content">
            <input type="text" name="search" placeholder="Search by Employee" value="<?php echo htmlspecialchars($search_query); ?>">
            <select name="type">
                <option value="">All Types</option>
                <option value="IN" <?php if ($type_filter == 'IN') echo 'selected'; ?>>IN</option>
                <option value="OUT" <?php if ($type_filter == 'OUT') echo 'selected'; ?>>OUT</option>
            </select>
            <label>From:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            <label>To:</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            <button type="submit">Filter</button>
            <button type="submit" formaction="download_fts.php">Download CSV</button>
        </div>
    </form>

    <table>
        <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>FTS Date</th>
            <th>FTS Time</th>
            <th>Type</th>
            <th>Status</th>
            <th>Approver</th>
            <th>Decision Timestamp</th>
            <?php if ($is_admin_or_approver): ?><th>Action</th><?php endif; ?>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['employeeID']); ?></td>
            <td><?php echo htmlspecialchars($row['employee_name'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($row['fts_date']); ?></td>
            <td><?php echo htmlspecialchars($row['fts_time']); ?></td>
            <td><?php echo htmlspecialchars($row['fts_type']); ?></td>
            <td><?php echo htmlspecialchars($row['status']); ?></td>
            <td>
                <?php 
                if (!empty($row['approver_name'])) {
                    echo htmlspecialchars($row['approver_name']);
                } else {
                    echo !empty($row['som_approver']) ? htmlspecialchars($row['som_approver']) : 'Unknown';
                }
                ?>
            </td>
            <td><?php echo $row['approved_at'] ? htmlspecialchars($row['approved_at']) : 'Pending'; ?></td>
            <?php if ($is_admin_or_approver): ?>
            <td><button onclick="confirmDelete(<?php echo (int)$row['id']; ?>)">Delete</button></td>
            <?php endif; ?>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&type=<?php echo urlencode($type_filter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>
</body>
</html>

<?php $conn->close(); ?>
