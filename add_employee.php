<?php
// Debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

require_once 'db_connection.php';
mysqli_report(MYSQLI_REPORT_OFF);

// ✅ Access Control: Only Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("HTTP/1.1 403 Forbidden");
    echo "<h2 style='color:red;'>Access denied. Admins only.</h2>";
    exit;
}

$message = '';
$fieldErrors = [];
$formValues = [
    'EmployeeID' => '',
    'FirstName'  => '',
    'LastName'   => '',
    'Email'      => '',
    'som_email'  => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues['EmployeeID'] = trim($_POST['EmployeeID']);
    $formValues['FirstName']  = trim($_POST['FirstName']);
    $formValues['LastName']   = trim($_POST['LastName']);
    $formValues['Email']      = trim($_POST['Email']);
    $formValues['som_email']  = trim($_POST['som_email']);

    $employeeID = $formValues['EmployeeID'];
    $firstName  = $formValues['FirstName'];
    $lastName   = $formValues['LastName'];
    $email      = $formValues['Email'];
    $somEmail   = $formValues['som_email'];

    if ($employeeID && $firstName && $lastName && $email) {
        // 🔎 Pre-check for duplicates
        $check = $conn->prepare("SELECT EmployeeID, Email FROM Employees WHERE EmployeeID = ? OR Email = ?");
        $check->bind_param("ss", $employeeID, $email);
        $check->execute();
        $result = $check->get_result();

        $duplicateID = false;
        $duplicateEmail = false;

        while ($row = $result->fetch_assoc()) {
            if ($row['EmployeeID'] === $employeeID) $duplicateID = true;
            if ($row['Email'] === $email) $duplicateEmail = true;
        }
        $check->close();

        if ($duplicateID || $duplicateEmail) {
            $msgParts = [];
            if ($duplicateID) {
                $fieldErrors['EmployeeID'] = "Employee ID already exists.";
                $msgParts[] = "ID <strong>" . htmlspecialchars($employeeID) . "</strong>";
            }
            if ($duplicateEmail) {
                $fieldErrors['Email'] = "Email already exists.";
                $msgParts[] = "Email <strong>" . htmlspecialchars($email) . "</strong>";
            }
            $message = "<div class='alert alert-warning'>⚠️ Employee with " . implode(" and ", $msgParts) . " already exists.</div>";
        } else {
            // Insert new record
            $stmt = $conn->prepare("
                INSERT INTO Employees (
                    EmployeeID, FirstName, LastName, Email, som_email, Picture,
                    role, is_qa, SOM, Password, IsVerified, VerificationToken,
                    ResetToken, ResetTokenExpiry
                ) VALUES (
                    ?, ?, ?, ?, ?, NULL,
                    'Employee', 0, ?, '', 0, NULL,
                    NULL, NULL
                )
            ");

            $som = !empty($somEmail) ? $somEmail : NULL;

            if ($stmt) {
                $stmt->bind_param("ssssss", $employeeID, $firstName, $lastName, $email, $somEmail, $som);
                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>✅ Employee added successfully!</div>";
                    // clear form values on success
                    $formValues = [
                        'EmployeeID' => '',
                        'FirstName'  => '',
                        'LastName'   => '',
                        'Email'      => '',
                        'som_email'  => ''
                    ];
                } else {
                    $message = "<div class='alert alert-danger'>❌ Error: " . htmlspecialchars($stmt->error) . "</div>";
                }
                $stmt->close();
            } else {
                $message = "<div class='alert alert-danger'>❌ Error: could not prepare statement.</div>";
            }
        }
    } else {
        $message = "<div class='alert alert-warning'>⚠️ Please fill in all required fields.</div>";
    }
}

// ✅ Fetch last 10 employees
$employees = [];
$result = $conn->query("SELECT EmployeeID, FirstName, LastName, Email, som_email, role FROM Employees ORDER BY EmployeeID DESC LIMIT 10");
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
    <title>Add Employee - Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .error-text { color: red; font-size: 0.85em; }
        .is-invalid { border-color: red; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="text-primary">
            <i class="fas fa-users me-2"></i> Employee Management
        </h2>
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


    <!-- Add Employee Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-bold">
            <i class="fas fa-user-plus me-2"></i> Add Employee
        </div>
        <div class="card-body">
            <?= $message ?>
            <form method="post" action="">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="EmployeeID" 
                               class="form-control <?= isset($fieldErrors['EmployeeID']) ? 'is-invalid' : '' ?>" 
                               value="<?= htmlspecialchars($formValues['EmployeeID']) ?>" required>
                        <?php if (isset($fieldErrors['EmployeeID'])): ?>
                            <div class="error-text"><?= $fieldErrors['EmployeeID'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="FirstName" class="form-control" 
                               value="<?= htmlspecialchars($formValues['FirstName']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="LastName" class="form-control" 
                               value="<?= htmlspecialchars($formValues['LastName']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="Email" 
                               class="form-control <?= isset($fieldErrors['Email']) ? 'is-invalid' : '' ?>" 
                               value="<?= htmlspecialchars($formValues['Email']) ?>" required>
                        <?php if (isset($fieldErrors['Email'])): ?>
                            <div class="error-text"><?= $fieldErrors['Email'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SOM Email (optional)</label>
                        <input type="email" name="som_email" class="form-control" 
                               value="<?= htmlspecialchars($formValues['som_email']) ?>">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recently Added Employees -->
    <h4 class="mb-3"><i class="fas fa-clock me-2"></i> Recently Added Employees</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white shadow-sm">
            <thead class="table-light">
                <tr>
                    <th>EmployeeID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>SOM Email</th>
                    <th>Role</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="5" class="text-center">No employees found.</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><?= htmlspecialchars($emp['EmployeeID']) ?></td>
                            <td><?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?></td>
                            <td><?= htmlspecialchars($emp['Email']) ?></td>
                            <td><?= htmlspecialchars($emp['som_email'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($emp['role']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
