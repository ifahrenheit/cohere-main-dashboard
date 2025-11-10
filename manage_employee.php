<?php
// Show errors while developing (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

require_once 'db_connection.php';

// 🔒 Access control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("HTTP/1.1 403 Forbidden");
    echo "<h2 style='color:red;'>Access denied. Admins only.</h2>";
    exit;
}

$message = '';

// ✅ Handle Update Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateEmployee'])) {
    $employeeID = trim($_POST['EmployeeID']);
    $firstName  = trim($_POST['FirstName']);
    $lastName   = trim($_POST['LastName']);
    $email      = trim($_POST['Email']);
    $som        = trim($_POST['SOM']);
    $somEmail   = trim($_POST['som_email']);
    $role       = trim($_POST['role']);

    if (!empty($employeeID) && !empty($firstName) && !empty($lastName) && !empty($email)) {
        $stmt = $conn->prepare("
            UPDATE Employees 
            SET FirstName = ?, LastName = ?, Email = ?, SOM = ?, som_email = ?, role = ?
            WHERE EmployeeID = ?
        ");
        if ($stmt->execute([$firstName, $lastName, $email, $som, $somEmail, $role, $employeeID])) {
            $message = "<div class='alert alert-success'>✅ Employee updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger'>❌ Error: ".$stmt->error."</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>⚠ Please fill in all required fields.</div>";
    }
}

// ✅ Sorting
$allowedSorts = ["EmployeeID", "FirstName", "LastName", "SOM", "role"];
$sortColumn = "FirstName"; // default sort
$sortOrder = "ASC"; // default order

if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
    $sortColumn = $_GET['sort'];
}
if (isset($_GET['order']) && in_array(strtoupper($_GET['order']), ["ASC", "DESC"])) {
    $sortOrder = strtoupper($_GET['order']);
}

// Flip order when clicking same column again
$nextOrder = ($sortOrder === "ASC") ? "DESC" : "ASC";

// ✅ Fetch Employees
$employees = [];
$result = $conn->query("SELECT EmployeeID, FirstName, LastName, Email, som_email, SOM, role 
                        FROM Employees 
                        ORDER BY $sortColumn $sortOrder");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Employees | Cohere</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0 text-primary">👥 Employee Management</h2>
        <a href="dashboard.php" class="btn btn-outline-danger btn-sm">⬅ Back to Dashboard</a>
    </div>

<!-- Navigation Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage_employee.php' ? 'active' : '' ?>" href="manage_employee.php">Manage Employees</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'add_employee.php' ? 'active' : '' ?>" href="add_employee.php">Add Employee</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage_userdata.php' ? 'active' : '' ?>" href="manage_userdata.php">Manage Userdata</a>
    </li>
</ul>


    <!-- Status Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-info"><?= $message ?></div>
    <?php endif; ?>

    <!-- Employee Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <strong>Employee List</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><a href="?sort=EmployeeID&order=<?= ($sortColumn === 'EmployeeID') ? $nextOrder : 'ASC' ?>">EmployeeID</a></th>
                        <th><a href="?sort=FirstName&order=<?= ($sortColumn === 'FirstName') ? $nextOrder : 'ASC' ?>">First Name</a></th>
                        <th><a href="?sort=LastName&order=<?= ($sortColumn === 'LastName') ? $nextOrder : 'ASC' ?>">Last Name</a></th>
                        <th>Email</th>
                        <th><a href="?sort=SOM&order=<?= ($sortColumn === 'SOM') ? $nextOrder : 'ASC' ?>">SOM</a></th>
                        <th>SOM Email</th>
                        <th><a href="?sort=role&order=<?= ($sortColumn === 'role') ? $nextOrder : 'ASC' ?>">Role</a></th>
                        <th style="width:100px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= htmlspecialchars($emp['EmployeeID']) ?></td>
                        <td><?= htmlspecialchars($emp['FirstName']) ?></td>
                        <td><?= htmlspecialchars($emp['LastName']) ?></td>
                        <td><?= htmlspecialchars($emp['Email']) ?></td>
                        <td><?= htmlspecialchars($emp['SOM'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($emp['som_email'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($emp['role']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                data-bs-toggle="modal"
                                data-bs-target="#editModal"
                                data-employee='<?= json_encode($emp) ?>'>
                                ✏️ Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Edit Employee</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3 p-3">
            <input type="hidden" name="EmployeeID" id="editEmployeeID">
            <div class="col-md-6">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="FirstName" id="editFirstName" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="LastName" id="editLastName" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="Email" id="editEmail" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">SOM</label>
                <input type="text" class="form-control" name="SOM" id="editSOM">
            </div>
            <div class="col-md-6">
                <label class="form-label">SOM Email</label>
                <input type="email" class="form-control" name="som_email" id="editSomEmail">
            </div>
            <div class="col-md-6">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" id="editRole">
                    <option value="Employee">Employee</option>
                    <option value="Manager">Manager</option>
                    <option value="Director">Director</option>
                    <option value="Admin">Admin</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="updateEmployee" class="btn btn-success">💾 Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">❌ Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-fill modal with employee data
const editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    const emp = JSON.parse(button.getAttribute('data-employee'));

    document.getElementById('editEmployeeID').value = emp.EmployeeID;
    document.getElementById('editFirstName').value = emp.FirstName;
    document.getElementById('editLastName').value = emp.LastName;
    document.getElementById('editEmail').value = emp.Email;
    document.getElementById('editSOM').value = emp.SOM ?? '';
    document.getElementById('editSomEmail').value = emp.som_email ?? '';
    document.getElementById('editRole').value = emp.role;
});
</script>
</body>
</html>
