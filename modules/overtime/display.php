<?php
// modules/overtime/display.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ✅ Include session check and database
require_once __DIR__ . '/../../includes/session_check.php';
require_once __DIR__ . '/../../config/db_connection.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ✅ Role Check using centralized function
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];
$is_authorized = checkRole($allowed_roles, false); // Don't die, just check

// Filters
$search_query = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$approver_filter = $_GET['approver'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Base Query
$sql = "SELECT o.*, 
               CONCAT(e.FirstName, ' ', e.LastName) AS employee_name,
               CASE
                   WHEN o.end_time < o.start_time 
                       THEN TIME_TO_SEC(TIMEDIFF(ADDTIME(o.end_time, '24:00:00'), o.start_time)) / 3600
                   ELSE TIME_TO_SEC(TIMEDIFF(o.end_time, o.start_time)) / 3600
               END AS ot_hours
        FROM ot_requests o
        LEFT JOIN Employees e ON o.employee_id = e.EmployeeID
        WHERE o.deleted_at IS NULL";

// Apply Filters
if (!empty($search_query)) {
    $search = $conn->real_escape_string($search_query);
    $sql .= " AND (o.employee_id LIKE '%$search%' OR CONCAT(e.FirstName, ' ', e.LastName) LIKE '%$search%')";
}

if (!empty($type_filter)) {
    $type = $conn->real_escape_string($type_filter);
    $sql .= " AND o.ot_type = '$type'";
}

if (!empty($status_filter) && strtolower($status_filter) !== 'all') {
    $status = $conn->real_escape_string($status_filter);
    $sql .= " AND o.status = '$status'";
}

if (!empty($approver_filter) && strtolower($approver_filter) !== 'all') {
    $approver = $conn->real_escape_string($approver_filter);
    $sql .= " AND o.approver_name = '$approver'";
}

if (!empty($start_date) && !empty($end_date)) {
    $start = $conn->real_escape_string($start_date);
    $end = $conn->real_escape_string($end_date);
    $sql .= " AND o.ot_date BETWEEN '$start' AND '$end'";
}

$sql .= " ORDER BY COALESCE(o.timestamp, o.ot_date) DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Pagination Count
$count_sql = "SELECT COUNT(*) as count FROM ot_requests o 
              LEFT JOIN Employees e ON o.employee_id = e.EmployeeID 
              WHERE o.deleted_at IS NULL";
$count_result = $conn->query($count_sql);
$total_records = $count_result->fetch_assoc()['count'] ?? 0;
$total_pages = ceil($total_records / $limit);

// Fetch Approvers for dropdown
$approvers_result = $conn->query("
    SELECT DISTINCT approver_name 
    FROM ot_requests 
    WHERE approver_name IS NOT NULL 
      AND approver_name != '' 
      AND approver_name != 'Andrew Vincent Tacdoro'
    ORDER BY approver_name ASC
");

?>
<!DOCTYPE html>
<html>
<head>
    <title>OT Requests</title>
    <!-- ✅ UPDATED PATH - CSS -->
    <link rel="stylesheet" type="text/css" href="../../style.css">
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
        .filter-row select, .filter-row input {
            margin-right: 8px;
        }
    </style>
    <script>
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this OT request? This action will be logged.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                // ✅ UPDATED PATH - Delete script
                form.action = 'delete.php';
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
    <h2>OT Requests</h2>
    <div class="logout-btn">
        <!-- ✅ UPDATED PATHS - Navigation -->
        <a href="../../dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
        <a href="../../logout.php"><button>Logout</button></a>
    </div>
</div>

<div class="container">
    <form method="GET" class="form-container filter-row">
        <input type="text" name="search" placeholder="Search by Employee" value="<?= htmlspecialchars($search_query); ?>">

        <label>Status:</label>
        <select name="status">
            <?php
            $statuses = ['All', 'Approved', 'Rejected'];
            foreach ($statuses as $status) {
                $selected = (strtolower($status_filter) === strtolower($status)) ? 'selected' : '';
                echo "<option value='$status' $selected>$status</option>";
            }
            ?>
        </select>

        <label>Approver:</label>
        <select name="approver">
            <option value="all">All</option>
            <?php while ($a = $approvers_result->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($a['approver_name']); ?>"
                    <?= ($a['approver_name'] === $approver_filter) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($a['approver_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>From:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <label>To:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

        <button type="submit">Filter</button>
        <!-- ✅ UPDATED PATH - Download script -->
        <button type="submit" formaction="download.php">Download CSV</button>
    </form>

    <table>
        <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>OT Date</th>
            <th>Start Time</th>
            <th>End Time</th>
            <th>OT Hours</th>
            <th>OT Type</th>
            <th>Regular Rate</th>
            <th>Status</th>
            <th>Approver</th>
            <th>Decision Timestamp</th>
            <?php if ($is_authorized): ?><th>Action</th><?php endif; ?>
        </tr>

        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['employee_id']); ?></td>
                <td><?= htmlspecialchars($row['employee_name'] ?? 'Unknown'); ?></td>
                <td><?= htmlspecialchars($row['ot_date']); ?></td>
                <td><?= htmlspecialchars($row['start_time']); ?></td>
                <td><?= htmlspecialchars($row['end_time']); ?></td>
                <td><?= number_format((float)$row['ot_hours'], 2); ?></td>
                <td><?= htmlspecialchars($row['ot_type']); ?></td>
                <td><?= ($row['regular_rate'] === 'Yes') ? 'Yes' : 'No'; ?></td>
                <td><?= htmlspecialchars($row['status']); ?></td>
                <td><?= htmlspecialchars($row['approver_name'] ?? 'Unknown'); ?></td>
                <td>
                    <?php
                    if ($row['status'] === 'Approved' && !empty($row['approved_at'])) {
                        echo htmlspecialchars($row['approved_at']);
                    } elseif ($row['status'] === 'Rejected' && !empty($row['decision_at'])) {
                        echo htmlspecialchars($row['decision_at']);
                    } else {
                        echo "Pending";
                    }
                    ?>
                </td>
                <?php if ($is_authorized): ?>
                <td><button class="delete-button" onclick="confirmDelete(<?= $row['id']; ?>)">Delete</button></td>
                <?php endif; ?>
            </tr>
        <?php endwhile; ?>
    </table>

    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?= $i; ?>&search=<?= urlencode($search_query); ?>&status=<?= urlencode($status_filter); ?>&approver=<?= urlencode($approver_filter); ?>&start_date=<?= urlencode($start_date); ?>&end_date=<?= urlencode($end_date); ?>">
                <?= $i; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>
</body>
</html>

<?php $conn->close(); ?>