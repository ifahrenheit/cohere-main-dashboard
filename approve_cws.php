<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// Allowed roles
$allowedRoles = ['Manager', 'Director', 'Admin', 'SOM Approver'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    die("Access Denied! You do not have permission to approve requests.");
}

$role = $_SESSION['role'] ?? '';
$userEmail = $_SESSION['user_email'] ?? '';

// Sorting
$allowedSort = [
    'employee_name' => "e.FirstName",
    'original_date' => "STR_TO_DATE(c.original_date, '%Y-%m-%d')",
    'som' => "e.SOM"
];

$sort = $_GET['sort'] ?? 'original_date';
$order = $_GET['order'] ?? 'desc';

if (!isset($allowedSort[$sort])) {
    $sort = 'original_date';
}

$order = ($order === 'asc') ? 'ASC' : 'DESC';

// Base SQL
$sql = "
    SELECT 
        c.id, 
        c.employee_id, 
        c.original_date, 
        c.original_time, 
        c.new_date, 
        c.new_time, 
        c.reason, 
        c.status,
        e.FirstName, 
        e.LastName, 
        e.SOM, 
        e.role AS requester_role,
        e.som_email
    FROM cws_requests c
    JOIN Employees e ON c.employee_id = e.EmployeeID
    WHERE c.status = 'Pending'
";

// ✅ Role-based filtering
if (in_array($role, ['SOM Approver', 'Manager'])) {
    // Managers & SOM Approvers see only their direct reports
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.som_email = '{$safeEmail}'";
}
elseif ($role === 'Director') {
    // Directors see only Managers' requests
    $sql .= " AND e.role = 'Manager'";
}
elseif ($role === 'Admin') {
    // Admins see everything
    // no filter
}


// Apply sorting
$sql .= " ORDER BY {$allowedSort[$sort]} $order";

$result = $conn->query($sql);
if (!$result) {
    die("SQL Error: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approve Change Work Schedule Requests</title>
<link rel="stylesheet" href="style.css">
<style>
    th {
        color: #fff;
    }
    th a {
        text-decoration: none;
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .sort-arrow {
        font-size: 14px;
        opacity: 0.6;
    }
    th a:hover .sort-arrow {
        opacity: 1;
    }
</style>
</head>
<body>
<div class="header">
    Pending Change Work Schedule Requests
    <div class="logout-btn">
        <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
        <a href="logout.php"><button>Logout</button></a>
    </div>
</div>

<div class="container">
<table>
    <tr>
        <th>Employee ID</th>
        <th>
            <a href="?sort=employee_name&order=<?= ($sort=='employee_name' && $order=='ASC') ? 'desc' : 'asc' ?>">
                Employee Name
                <span class="sort-arrow"><?= ($sort=='employee_name' ? ($order=='ASC' ? '▲' : '▼') : '↕') ?></span>
            </a>
        </th>
        <th>
            <a href="?sort=original_date&order=<?= ($sort=='original_date' && $order=='ASC') ? 'desc' : 'asc' ?>">
                Original Date
                <span class="sort-arrow"><?= ($sort=='original_date' ? ($order=='ASC' ? '▲' : '▼') : '↕') ?></span>
            </a>
        </th>
        <th>Original Time</th>
        <th>New Date</th>
        <th>New Time</th>
        <th>Reason</th>
        <th>Status</th>
        <th>Action</th>
        <th>
            <a href="?sort=som&order=<?= ($sort=='som' && $order=='ASC') ? 'desc' : 'asc' ?>">
                SOM
                <span class="sort-arrow"><?= ($sort=='som' ? ($order=='ASC' ? '▲' : '▼') : '↕') ?></span>
            </a>
        </th>
    </tr>

    <?php while ($row = $result->fetch_assoc()): ?>
    <tr id="row-<?= htmlspecialchars($row['id']); ?>">
        <td><?= htmlspecialchars($row['employee_id']); ?></td>
        <td><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']); ?></td>
        <td><?= htmlspecialchars($row['original_date']); ?></td>
        <td><?= htmlspecialchars($row['original_time']); ?></td>
        <td><?= htmlspecialchars($row['new_date']); ?></td>
        <td><?= htmlspecialchars($row['new_time']); ?></td>
        <td class="reason"><?= nl2br(htmlspecialchars(html_entity_decode($row['reason']))); ?></td>
        <td><?= htmlspecialchars($row['status']); ?></td>
        <td>
            <button class="approveBtn" data-id="<?= htmlspecialchars($row['id']); ?>">Approve</button>
            <button class="rejectBtn" data-id="<?= htmlspecialchars($row['id']); ?>">Reject</button>
        </td>
        <td><?= htmlspecialchars($row['SOM']); ?></td>
    </tr>
    <?php endwhile; ?>
</table>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function handleAction(recordId, action, button) {
        if (!recordId) return;

        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = "Processing...";

        fetch("process_cws.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "cws_id=" + encodeURIComponent(recordId) + "&action=" + encodeURIComponent(action)
        })
        .then(res => res.text())
        .then(text => {
            if (text.trim() === "OK") {
                const row = document.getElementById("row-" + recordId);
                if (row) row.remove();
            } else {
                alert("Server response: " + text);
                button.disabled = false;
                button.textContent = originalText;
            }
        })
        .catch(err => {
            alert("Error: " + err);
            button.disabled = false;
            button.textContent = originalText;
        });
    }

    document.querySelectorAll(".approveBtn").forEach(btn => {
        btn.addEventListener("click", function() {
            handleAction(this.dataset.id, "approve", this);
        });
    });

    document.querySelectorAll(".rejectBtn").forEach(btn => {
        btn.addEventListener("click", function() {
            handleAction(this.dataset.id, "reject", this);
        });
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>
