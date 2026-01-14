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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - Cohere</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-header h2 {
            color: white;
            font-size: 28px;
            font-weight: 700;
        }

        .btn-back {
            background: white;
            color: #050f38ff;
            padding: 8px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-back:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
        }

        .nav-tabs {
            background: white;
            border-radius: 12px 12px 0 0;
            padding: 10px 20px 0;
            margin-bottom: 0;
            display: flex;
            gap: 10px;
            list-style: none;
        }

        .nav-tabs li a {
            display: block;
            padding: 12px 24px;
            color: #050f38ff;
            text-decoration: none;
            font-weight: 500;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s;
        }

        .nav-tabs li a:hover {
            background: #f7fafc;
        }

        .nav-tabs li a.active {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
        }

        .content-wrapper {
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        .section-title {
            color: #1a202c;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .section-subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .form-card {
            background: #f7fafc;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-label {
            display: block;
            color: #2d3748;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-label.required::after {
            content: " *";
            color: #ef4444;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #0d4081ff;
            box-shadow: 0 0 0 3px rgba(13, 64, 129, 0.1);
        }

        .form-input.is-invalid {
            border-color: #ef4444;
        }

        .error-text {
            color: #ef4444;
            font-size: 13px;
            margin-top: 5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .btn-submit {
            padding: 12px 32px;
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 64, 129, 0.4);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success {
            background: #d1fae5;
            color: #047857;
        }

        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .alert-warning {
            background: #fef3c7;
            color: #b45309;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 30px;
        }

        .table-header {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            padding: 16px 20px;
            font-weight: 600;
            font-size: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f7fafc;
        }

        thead th {
            padding: 14px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            color: #4a5568;
            letter-spacing: 0.5px;
        }

        tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background-color 0.2s;
        }

        tbody tr:hover {
            background-color: #f7fafc;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody td {
            padding: 16px 20px;
            font-size: 14px;
            color: #2d3748;
        }

        .email-cell {
            color: #0d4081ff;
            font-weight: 500;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #e6fffa;
            color: #047857;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .content-wrapper {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <h2>👥 Employee Management</h2>
        <a href="dashboard.php" class="btn-back">⬅ Back to Dashboard</a>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav-tabs">
        <li><a href="manage_employee.php">Manage Employees</a></li>
        <li><a href="add_employee.php" class="active">Add Employee</a></li>
        <li><a href="manage_userdata.php">Manage Userdata</a></li>
        <li><a href="manage_supervisors.php">Supervisor Assignment</a></li>
    </ul>

    <div class="content-wrapper">
        <h1 class="section-title">Add New Employee</h1>
        <p class="section-subtitle">Fill in the employee information below</p>

        <!-- Status Messages -->
        <?php if (!empty($message)): ?>
            <?= $message ?>
        <?php endif; ?>

        <!-- Add Employee Form -->
        <div class="form-card">
            <form method="post" action="">
                <div class="form-row">
                    <div>
                        <label class="form-label required">Employee ID</label>
                        <input type="text" 
                               name="EmployeeID" 
                               class="form-input <?= isset($fieldErrors['EmployeeID']) ? 'is-invalid' : '' ?>" 
                               value="<?= htmlspecialchars($formValues['EmployeeID']) ?>" 
                               required>
                        <?php if (isset($fieldErrors['EmployeeID'])): ?>
                            <div class="error-text"><?= $fieldErrors['EmployeeID'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label required">First Name</label>
                        <input type="text" 
                               name="FirstName" 
                               class="form-input" 
                               value="<?= htmlspecialchars($formValues['FirstName']) ?>" 
                               required>
                    </div>
                    <div>
                        <label class="form-label required">Last Name</label>
                        <input type="text" 
                               name="LastName" 
                               class="form-input" 
                               value="<?= htmlspecialchars($formValues['LastName']) ?>" 
                               required>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label class="form-label required">Email</label>
                        <input type="email" 
                               name="Email" 
                               class="form-input <?= isset($fieldErrors['Email']) ? 'is-invalid' : '' ?>" 
                               value="<?= htmlspecialchars($formValues['Email']) ?>" 
                               required>
                        <?php if (isset($fieldErrors['Email'])): ?>
                            <div class="error-text"><?= $fieldErrors['Email'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label">Approver (optional)</label>
                        <input type="email" 
                               name="som_email" 
                               class="form-input" 
                               value="<?= htmlspecialchars($formValues['som_email']) ?>"
                               placeholder="Enter approver email">
                    </div>
                </div>

                <div style="margin-top: 30px;">
                    <button type="submit" class="btn-submit">
                        💾 Save Employee
                    </button>
                </div>
            </form>
        </div>

        <!-- Recently Added Employees -->
        <h2 class="section-title" style="margin-top: 40px;">Recently Added Employees</h2>
        <p class="section-subtitle">Last 10 employees added to the system</p>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Approver</th>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #718096;">
                                No employees found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($emp['EmployeeID']) ?></strong></td>
                                <td><?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?></td>
                                <td class="email-cell"><?= htmlspecialchars($emp['Email']) ?></td>
                                <td>
                                    <?php if (!empty($emp['som_email'])): ?>
                                        <?= htmlspecialchars($emp['som_email']) ?>
                                    <?php else: ?>
                                        <em style="color: #cbd5e0;">Not assigned</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="role-badge">
                                        <?= htmlspecialchars($emp['role']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>