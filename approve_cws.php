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

// Apply filters based on role
if ($role === 'SOM Approver') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Manager' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Manager') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Employee' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Director' || $role === 'Admin') {
    // Directors/Admins see all
}

$sql .= " ORDER BY STR_TO_DATE(c.original_date, '%Y-%m-%d') DESC, e.FirstName ASC";

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
          <th>Employee Name</th>
          <th>Original Date</th>
          <th>Original Time</th>
          <th>New Date</th>
          <th>New Time</th>
          <th>Reason</th>
          <th>Status</th>
          <th>Action</th>
          <th>SOM</th>
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
    // unified helper: send POST to process script and remove row on OK
    function handleAction(recordId, action, button) {
        if (!recordId) return;

        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = "Processing...";

        const body = "cws_id=" + encodeURIComponent(recordId) + "&action=" + encodeURIComponent(action);

        fetch("process_cws.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body
        })
        .then(response => response.text())
        .then(text => {
            const data = text.trim();

            if (data === "OK") {
                // Use the same local variable recordId — no global/undeclared names
                const row = document.getElementById("row-" + recordId);
                if (row) row.remove();
            } else {
                // Keep button enabled again so user can retry
                alert("Server response: " + data);
                button.disabled = false;
                button.textContent = originalText;
            }
        })
        .catch(err => {
            // Network or other fatal JS errors
            console.error("Approval request failed:", err);
            alert("Error: " + err);
            button.disabled = false;
            button.textContent = originalText;
        });
    }

    // wire up approve buttons
    document.querySelectorAll(".approveBtn").forEach(btn => {
        btn.addEventListener("click", function() {
            const id = this.getAttribute("data-id");
            handleAction(id, "approve", this);
        });
    });

    // wire up reject buttons
    document.querySelectorAll(".rejectBtn").forEach(btn => {
        btn.addEventListener("click", function() {
            const id = this.getAttribute("data-id");
            handleAction(id, "reject", this);
        });
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>
