<?php
include 'db_connection.php';
session_start();


// ✅ Allow roles
$fullAccessRoles = ['Manager', 'Director', 'Admin', 'SOM Approver'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $fullAccessRoles)) {
    die("Access Denied! You do not have permission to approve requests.");
}

$role = $_SESSION['role'] ?? '';
$userEmail = $_SESSION['user_email'] ?? '';

// ✅ Base query
$sql = "
    SELECT 
        r.id, 
        r.employee_id, 
        e.FirstName, 
        e.LastName, 
        e.role AS requester_role,
        e.som_email,
        e.SOM,
        r.rd_date, 
        r.start_time, 
        r.end_time, 
        r.status
    FROM rd_requests r
    LEFT JOIN Employees e ON r.employee_id = e.EmployeeID
    WHERE r.status = 'Pending'
";

// ✅ Role-based filters
if ($role === 'SOM Approver') {
    $sql .= " AND e.role = 'Manager' AND e.som_email = '$userEmail'";
} elseif ($role === 'Manager') {
    $sql .= " AND e.role = 'Employee' AND e.som_email = '$userEmail'";
} elseif (in_array($role, ['Director', 'Admin'])) {
    // can view all
}

$sql .= " ORDER BY STR_TO_DATE(r.rd_date, '%Y-%m-%d') DESC, r.start_time DESC";

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
    <title>Approve RD Work Requests</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="header">
        Pending RD Work Requests
        <div class="logout-btn">
            <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <div class="container">
        <?php if ($result->num_rows > 0): ?>
        <table>
            <tr>
                <th>Employee ID</th>
                <th>Employee Name</th>
                <th>RD Date</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Duration (hrs)</th>
                <th>Status</th>
                <th>SOM</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()): 
                $start_time = strtotime($row['start_time']);
                $end_time = strtotime($row['end_time']);
                if ($end_time < $start_time) $end_time += 86400;
                $duration = round(($end_time - $start_time) / 3600, 2);
            ?>
            <tr id="row-<?= $row['id']; ?>">
                <td><?= htmlspecialchars($row['employee_id']); ?></td>
                <td><?= htmlspecialchars($row['FirstName'] . " " . $row['LastName']); ?></td>
                <td><?= htmlspecialchars($row['rd_date']); ?></td>
                <td><?= date("H:i", $start_time); ?></td>
                <td><?= date("H:i", $end_time % 86400); ?></td>
                <td><?= $duration . " hrs"; ?></td>
                <td><?= htmlspecialchars($row['status']); ?></td>
                <td><?= htmlspecialchars($row['SOM']); ?></td>
                <td>
                    <button class="approveBtn" data-id="<?= $row['id']; ?>">Approve</button>
                    <button class="rejectBtn" data-id="<?= $row['id']; ?>">Reject</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
        <?php else: ?>
            <p class="no-data">No pending RD Work requests found.</p>
        <?php endif; ?>
    </div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function handleAction(id, action, button) {
        button.disabled = true;
        button.textContent = "Processing...";

        // ✅ Fixed headers and parameter encoding
        fetch("process_rdwork.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `rd_id=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}`
        })
        .then(response => response.text())
        .then(text => {
            if (text.trim() === "SUCCESS") {
    alert("Request " + action + "d successfully!");
    document.getElementById("row-" + id).remove();
} else {
    alert("Server response: " + text);
    button.disabled = false;
    button.textContent = action.charAt(0).toUpperCase() + action.slice(1);
}

        })
        .catch(error => {
            alert("Error: " + error);
            button.disabled = false;
            button.textContent = action.charAt(0).toUpperCase() + action.slice(1);
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
