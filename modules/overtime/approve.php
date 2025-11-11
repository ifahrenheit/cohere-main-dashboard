<?php
// modules/overtime/approve.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ INCLUDE SESSION CHECK FIRST
require_once __DIR__ . '/../../includes/session_check.php';

// ✅ THEN DATABASE
require_once __DIR__ . '/../../config/db_connection.php';

// ✅ CHECK ROLE-BASED ACCESS
$fullAccessRoles = ['Manager', 'Director', 'Admin', 'SOM Approver'];
checkRole($fullAccessRoles); // Will die if user doesn't have permission

// ✅ Get current user info (now centralized!)
$currentUser = getCurrentUser();
$role = $currentUser['role'];
$userEmail = $currentUser['email'];

// ✅ Base query
$sql = "
    SELECT 
        o.id, 
        o.employee_id, 
        e.FirstName, 
        e.LastName, 
        e.role AS requester_role,
        e.som_email,
        e.SOM,
        o.ot_date, 
        o.ot_type, 
        o.regular_rate, 
        o.start_time, 
        o.end_time, 
        o.status
    FROM ot_requests o
    JOIN Employees e ON o.employee_id = e.EmployeeID
    WHERE o.status = 'Pending'
";

// ✅ Role-based filters
if ($role === 'SOM Approver') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Manager' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Manager') {
    $safeEmail = $conn->real_escape_string($userEmail);
    $sql .= " AND e.role = 'Employee' AND e.som_email = '{$safeEmail}'";
} elseif ($role === 'Director' || $role === 'Admin') {
    // Directors/Admins see all
}

$sql .= " ORDER BY STR_TO_DATE(o.ot_date, '%Y-%m-%d') DESC, o.start_time DESC";

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
  <title>Approve OT Requests</title>
  <link rel="stylesheet" href="../../style.css">
</head>
<body>
  <div class="header">
    Pending Overtime Requests
    <div class="logout-btn">
      <a href="../../dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
      <a href="../../logout.php"><button>Logout</button></a>
    </div>
  </div>

  <div class="container">
  <table>
    <tr>
      <th>Employee ID</th>
      <th>Employee Name</th>
      <th>OT Date</th>
      <th>OT Type</th>
      <th>Regular Rate</th>
      <th>Start Time</th>
      <th>End Time</th>
      <th>Duration (hrs)</th>
      <th>Status</th>
      <th>Action</th>
      <th>SOM</th>
    </tr>

    <?php while ($row = $result->fetch_assoc()): 
        $start_time = strtotime($row['start_time']);
        $end_time   = strtotime($row['end_time']);
        if ($end_time < $start_time) $end_time += 86400;
        $duration = round(($end_time - $start_time) / 3600, 2);
    ?>
      <tr id="row-<?= htmlspecialchars($row['id']); ?>">
        <td><?= htmlspecialchars($row['employee_id']); ?></td>
        <td><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']); ?></td>
        <td><?= htmlspecialchars($row['ot_date']); ?></td>
        <td><?= htmlspecialchars($row['ot_type']); ?></td>
        <td><?= htmlspecialchars($row['regular_rate']); ?></td>
        <td><?= date("H:i", $start_time); ?></td>
        <td><?= date("H:i", $end_time % 86400); ?></td>
        <td><?= $duration . " hrs"; ?></td>
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
  function handleAction(id, action, button) {
    button.disabled = true;
    button.textContent = "Processing...";

    fetch("process.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action)
    })
    .then(res => res.text())
    .then(data => {
      if (data.trim() === "OK") {
        button.closest("tr").remove();
      } else {
        alert("Server response: " + data);
        button.disabled = false;
        button.textContent = action;
      }
    })
    .catch(err => {
      alert("Error: " + err);
      button.disabled = false;
      button.textContent = action;
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