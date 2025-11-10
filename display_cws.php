<?php
require 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// Allowed roles
$allowed_roles = ['admin', 'manager', 'director', 'som approver'];
$user_role = strtolower($_SESSION['role'] ?? '');
$is_authorized = in_array($user_role, $allowed_roles);

// Filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Base query
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
           c.approved_at,
           c.deleted_at
    FROM cws_requests c
    LEFT JOIN Employees e ON c.employee_id = e.EmployeeID
    WHERE c.deleted_at IS NULL
";

// Apply filters
if (!empty($search)) {
    $search = $conn->real_escape_string($search);
    $sql .= " AND (e.FirstName LIKE '%$search%' OR e.LastName LIKE '%$search%' OR c.employee_id LIKE '%$search%')";
}
if (!empty($start_date)) {
    $sql .= " AND c.original_date >= '" . $conn->real_escape_string($start_date) . "'";
}
if (!empty($end_date)) {
    $sql .= " AND c.original_date <= '" . $conn->real_escape_string($end_date) . "'";
}

$sql .= " ORDER BY COALESCE(c.approved_at, c.original_date) DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Pagination
$count_sql = "SELECT COUNT(*) as count FROM cws_requests c 
              LEFT JOIN Employees e ON c.employee_id = e.EmployeeID 
              WHERE c.deleted_at IS NULL";
$count_result = $conn->query($count_sql);
$total_records = $count_result->fetch_assoc()['count'] ?? 0;
$total_pages = ceil($total_records / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>CWS Requests</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .delete-button {
            background-color: #e74c3c;
            color: white;
            border: none;
            padding: 6px 10px;
            cursor: pointer;
            border-radius: 4px;
        }
        .delete-button:hover {
            background-color: #c0392b;
        }
    </style>
    <script>
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this CWS request? This action will be logged.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_cws.php';

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
        <h2>CWS Requests</h2>
        <div class="logout-btn">
            <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <div class="container">
        <form method="GET" class="form-container">
            <div class="form-content">
                <input type="text" name="search" placeholder="Search by Employee" value="<?php echo htmlspecialchars($search); ?>">
                <label>From:</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                <label>To:</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                <button type="submit">Filter</button>
                <button type="submit" formaction="download_cws.php">Download CSV</button>
            </div>
        </form>

        <table>
            <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Original Date</th>
                <th>Original Time</th>
                <th>New Date</th>
                <th>New Time</th>
                <th>CWS Hours</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Approver</th>
                <th>Approved At</th>
                <?php if ($is_authorized): ?><th>Action</th><?php endif; ?>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['employee_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['employee_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($row['original_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['original_time']); ?></td>
                    <td><?php echo htmlspecialchars($row['new_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['new_time']); ?></td>
                    <td><?php echo htmlspecialchars($row['cws_hours']); ?></td>
                    <td><?php echo htmlspecialchars($row['reason']); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td><?php echo htmlspecialchars($row['approver_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo $row['approved_at'] ? htmlspecialchars($row['approved_at']) : 'Pending'; ?></td>
                    <?php if ($is_authorized): ?>
                    <td>
                        <button class="delete-button" onclick="confirmDelete(<?php echo $row['id']; ?>)">Delete</button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endwhile; ?>
        </table>

        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>
