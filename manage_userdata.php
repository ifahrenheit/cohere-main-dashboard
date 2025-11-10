<?php
ini_set('display_errors', 1);
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

$message = "";

// ✅ Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateUser'])) {
    $companyid = $_POST['companyid'];
    $username  = $_POST['username'];
    $fname     = $_POST['fname'];
    $lname     = $_POST['lname'];
    $email     = $_POST['email'];
    $role      = $_POST['role'];
    $som       = $_POST['SOM'];
    $is_qa     = isset($_POST['is_qa']) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE userdata 
                            SET username=?, fname=?, lname=?, email=?, role=?, SOM=?, is_qa=? 
                            WHERE companyid=?");
    $stmt->bind_param("ssssssis", $username, $fname, $lname, $email, $role, $som, $is_qa, $companyid);

    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>✅ User updated successfully!</div>";
    } else {
        $message = "<div class='alert alert-danger'>❌ Error: ".$stmt->error."</div>";
    }
}

// ✅ Fetch Userdata
$users = [];
$result = $conn->query("SELECT companyid, username, fname, lname, email, role, SOM, is_qa FROM userdata ORDER BY lname, fname");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Userdata | Cohere</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <div class="container py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-4 text-primary">🗂 Manage Userdata</h2>
        <a href="dashboard.php" class="btn btn-outline-danger btn-sm">⬅ Back to Dashboard</a>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="manage_employee.php">Manage Employees</a>
            
        </li>
        <li class="nav-item">
            <a class="nav-link" href="add_employee.php">Add Employee</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="manage_userdata.php">Manage Userdata</a>
        </li>
    </ul>

    <?= $message ?>

    <!-- Userdata Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <strong>Userdata List</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>CompanyID</th>
                        <th>Username</th>
                        <th>First</th>
                        <th>Last</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>SOM</th>
                        <th>Is QA</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['companyid']) ?></td>
                        <td><?= htmlspecialchars($u['username'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['fname'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['lname'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['role'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['SOM'] ?? '') ?></td>
                        <td><?= $u['is_qa'] ? '✅' : '❌' ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                data-bs-toggle="modal"
                                data-bs-target="#editModal"
                                data-user='<?= json_encode($u) ?>'>
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
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3 p-3">
            <input type="hidden" name="companyid" id="editCompanyID">
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" id="editUsername">
            </div>
            <div class="col-md-6">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="fname" id="editFname">
            </div>
            <div class="col-md-6">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="lname" id="editLname">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" id="editEmail">
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
            <div class="col-md-6">
                <label class="form-label">SOM</label>
                <input type="text" class="form-control" name="SOM" id="editSOM">
            </div>
            <div class="col-md-6">
                <label class="form-label">Is QA</label><br>
                <input type="checkbox" name="is_qa" id="editIsQA"> QA User
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="updateUser" class="btn btn-success">💾 Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">❌ Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    const user = JSON.parse(button.getAttribute('data-user'));

    document.getElementById('editCompanyID').value = user.companyid;
    document.getElementById('editUsername').value = user.username ?? '';
    document.getElementById('editFname').value = user.fname ?? '';
    document.getElementById('editLname').value = user.lname ?? '';
    document.getElementById('editEmail').value = user.email ?? '';
    document.getElementById('editRole').value = user.role ?? 'Employee';
    document.getElementById('editSOM').value = user.SOM ?? '';
    document.getElementById('editIsQA').checked = user.is_qa == 1;
});
</script>
</body>
</html>
