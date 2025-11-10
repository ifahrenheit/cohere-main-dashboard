<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

$allowedRoles = ['Director', 'SOM Approver', 'Manager', 'Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    die("Access Denied! You do not have permission to approve requests.");
}

$role = $_SESSION['role'] ?? '';
$userEmail = $_SESSION['user_email'] ?? '';

// Base query
$sql = "
    SELECT 
        f.id, 
        f.employeeID, 
        f.fts_date, 
        f.fts_time, 
        f.fts_type, 
        f.status,
        CONCAT(e.FirstName, ' ', e.LastName) AS employee_name, 
        e.SOM, 
        e.role AS requester_role,
        e.som_email
    FROM fts_requests f
    JOIN Employees e ON f.employeeID = e.EmployeeID
    WHERE f.status = 'Pending'
";

// Role-based filtering
if ($role === 'SOM Approver') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Manager' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Manager') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Employee' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Director' || $role === 'Admin') {
    // Directors/Admins see all
}

$sql .= " ORDER BY STR_TO_DATE(f.fts_date, '%Y-%m-%d') DESC, e.FirstName ASC";
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
    <title>Approve FTS Requests</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        Pending FTS Requests
        <div class="logout-btn">
            <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <div class="page-container">
        <table id="ftsTable">
            <tr>
                <th>Employee ID</th>
                <th>Employee Name</th>
                <th>FTS Date</th>
                <th>FTS Time</th>
                <th>FTS Type</th>
                <th>Status</th>
                <th>Action</th>
                <th>SOM</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()): ?>
                <tr id="row-<?= (int)$row['id']; ?>">
                    <td><?= htmlspecialchars($row['employeeID']); ?></td>
                    <td><?= htmlspecialchars($row['employee_name']); ?></td>
                    <td><?= htmlspecialchars($row['fts_date']); ?></td>
                    <td><?= htmlspecialchars($row['fts_time']); ?></td>
                    <td><?= htmlspecialchars($row['fts_type']); ?></td>
                    <td class="status"><?= htmlspecialchars($row['status']); ?></td>
                    <td>
                        <button class="approveBtn" data-id="<?= (int)$row['id']; ?>">Approve</button>
                        <button class="rejectBtn" data-id="<?= (int)$row['id']; ?>">Reject</button>
                    </td>
                    <td><?= htmlspecialchars($row['SOM']); ?></td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function handleAction(id, action, button) {
        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = "Processing...";

        fetch("process_fts.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action)
        })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "OK") {
                const row = document.getElementById("row-" + id);
                if (row) row.remove();
            } else {
                alert("Server response: " + data);
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
        btn.addEventListener("click", () => handleAction(btn.dataset.id, "approve", btn));
    });

    document.querySelectorAll(".rejectBtn").forEach(btn => {
        btn.addEventListener("click", () => handleAction(btn.dataset.id, "reject", btn));
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
