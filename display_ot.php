<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
require_once 'db_connection.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Role Check
$allowed_roles = ['admin', 'manager', 'director', 'som approver'];
$user_role = strtolower($_SESSION['role'] ?? '');
$is_authorized = in_array($user_role, $allowed_roles);

// Filters
$search_query = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$approver_filter = $_GET['approver'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = intval($_GET['per_page'] ?? 25); // Default to 25
$allowed_limits = [25, 50, 100, 500];
if (!in_array($limit, $allowed_limits)) {
    $limit = 25; // Fallback to 25 if invalid value
}
$offset = ($page - 1) * $limit;

// Base Query with SOM approver (including soft-deleted records)
$sql = "SELECT o.*, 
               CONCAT(e.FirstName, ' ', e.LastName) AS employee_name,
               e.SOM AS som_approver,
               CASE
                   WHEN o.end_time < o.start_time 
                       THEN TIME_TO_SEC(TIMEDIFF(ADDTIME(o.end_time, '24:00:00'), o.start_time)) / 3600
                   ELSE TIME_TO_SEC(TIMEDIFF(o.end_time, o.start_time)) / 3600
               END AS ot_hours
        FROM ot_requests o
        LEFT JOIN Employees e ON o.employee_id = e.EmployeeID
        WHERE 1=1";

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

// Pagination Count (also including soft-deleted)
$count_sql = "SELECT COUNT(*) as count FROM ot_requests o 
              LEFT JOIN Employees e ON o.employee_id = e.EmployeeID 
              WHERE 1=1";
$total_records = $conn->query($count_sql)->fetch_assoc()['count'] ?? 0;
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
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .delete-button { background-color: #e74c3c; color: white; border: none; padding: 6px 10px; cursor: pointer; border-radius: 4px; }
        .delete-button:hover { background-color: #c0392b; }
        .filter-row select, .filter-row input { margin-right: 8px; }
        .soft-deleted-row { background-color: #ffe6e6; border-left: 4px solid #dc3545; }
        .deleted-badge { background-color: #dc3545; color: white; padding: 3px 8px; border-radius: 3px; font-size: 10px; font-weight: bold; margin-left: 8px; }
        table { border-collapse: collapse; }
        td small { line-height: 1.4; }
        .pagination { margin-top: 20px; display: flex; gap: 5px; justify-content: center; align-items: center; }
        .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: #333; border-radius: 4px; transition: all 0.3s; }
        .pagination a:hover { background-color: #f0f0f0; border-color: #007bff; }
    </style>
    <script>
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this OT request? This action will be logged.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_ot.php';
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
        <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
        <a href="logout.php"><button>Logout</button></a>
    </div>
</div>

<div class="container">
    <form method="GET" class="form-container filter-row">
        <input type="text" name="search" placeholder="Search by Employee" value="<?= htmlspecialchars($search_query); ?>">

        <label>Status:</label>
        <select name="status">
            <?php
            $statuses = ['All', 'Approved', 'Rejected', 'Deleted', 'Pending'];
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
                <option value="<?= htmlspecialchars($a['approver_name']); ?>" <?= ($a['approver_name'] === $approver_filter) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($a['approver_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>From:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <label>To:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

        <label>Per Page:</label>
        <select name="per_page">
            <?php
            $per_page_options = [25, 50, 100, 500];
            foreach ($per_page_options as $option) {
                $selected = ($limit === $option) ? 'selected' : '';
                echo "<option value='$option' $selected>$option</option>";
            }
            ?>
        </select>

        <button type="submit">Filter</button>
        <button type="submit" formaction="download_ot.php">Download CSV</button>
    </form>

    <div style="margin: 10px 0; color: #666; font-size: 14px;">
        <?php
        $start_record = $offset + 1;
        $end_record = min($offset + $limit, $total_records);
        echo "Showing $start_record-$end_record of $total_records records";
        ?>
    </div>

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
            <th>Deleted Info</th>
            <?php if ($is_authorized): ?><th>Action</th><?php endif; ?>
        </tr>

        <?php while ($row = $result->fetch_assoc()): ?>
            <tr <?= !empty($row['deleted_at']) ? 'class="soft-deleted-row"' : ''; ?>>
                <td><?= htmlspecialchars($row['employee_id']); ?></td>
                <td><?= htmlspecialchars($row['employee_name'] ?? 'Unknown'); ?></td>
                <td><?= htmlspecialchars($row['ot_date']); ?></td>
                <td><?= htmlspecialchars($row['start_time']); ?></td>
                <td><?= htmlspecialchars($row['end_time']); ?></td>
                <td><?= number_format((float)$row['ot_hours'], 2); ?></td>
                <td><?= htmlspecialchars($row['ot_type']); ?></td>
                <td><?= ($row['regular_rate'] === 'Yes') ? 'Yes' : 'No'; ?></td>
                <td>
                    <?php if (!empty($row['deleted_at'])): ?>
                        <span style="color: #999; text-decoration: line-through;">
                            Previously <?= htmlspecialchars($row['status']); ?>
                        </span>
                        <span class="deleted-badge">DELETED</span>
                    <?php else: ?>
                        <?= htmlspecialchars($row['status']); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    if (!empty($row['approver_name'])) {
                        echo htmlspecialchars($row['approver_name']);
                    } else {
                        echo !empty($row['som_approver']) ? htmlspecialchars($row['som_approver']) : 'Unknown';
                    }
                    ?>
                </td>
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
                <td>
                    <?php if (!empty($row['deleted_at'])): ?>
                        <small style="color: #dc3545;">
                            By: <?= htmlspecialchars($row['deleted_by'] ?? 'Unknown'); ?><br>
                            At: <?= htmlspecialchars($row['deleted_at']); ?>
                        </small>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <?php if ($is_authorized): ?>
                <td>
                    <?php if (empty($row['deleted_at'])): ?>
                        <button class="delete-button" onclick="confirmDelete(<?= (int)$row['id']; ?>)">Delete</button>
                    <?php else: ?>
                        <span style="color: #999;">Already Deleted</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endwhile; ?>
    </table>

    <div class="pagination">
        <?php
        // Build base URL with all parameters except page
        $base_url = "?search=" . urlencode($search_query) . 
                    "&status=" . urlencode($status_filter) . 
                    "&approver=" . urlencode($approver_filter) . 
                    "&start_date=" . urlencode($start_date) . 
                    "&end_date=" . urlencode($end_date) . 
                    "&per_page=" . $limit;
        
        // First and Previous
        if ($page > 1) {
            echo "<a href='$base_url&page=1'>&laquo; First</a>";
            echo "<a href='$base_url&page=" . ($page - 1) . "'>&lsaquo; Prev</a>";
        }
        
        // Page numbers - show current page and 2 pages on each side
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            $active_class = ($i === $page) ? 'style="font-weight: bold; background-color: #007bff; color: white;"' : '';
            echo "<a href='$base_url&page=$i' $active_class>$i</a>";
        }
        
        // Next and Last
        if ($page < $total_pages) {
            echo "<a href='$base_url&page=" . ($page + 1) . "'>Next &rsaquo;</a>";
            echo "<a href='$base_url&page=$total_pages'>Last &raquo;</a>";
        }
        ?>
    </div>
</div>
</body>
</html>

<?php $conn->close(); ?>