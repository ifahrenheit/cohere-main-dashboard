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
$syncMessage = '';

// ✅ Handle Sync Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['syncUsers'])) {
    // Execute the sync script
    $syncScriptPath = __DIR__ . '/sync_users_include.php';
    
    if (file_exists($syncScriptPath)) {
        // Capture output from sync script
        ob_start();
        
        try {
            // Include and execute the sync script
            include $syncScriptPath;
            $syncOutput = ob_get_clean();
            
            // Parse the output to get summary
            preg_match('/Successfully synced:\s*(\d+)/', $syncOutput, $syncedMatch);
            preg_match('/Skipped \(duplicates\):\s*(\d+)/', $syncOutput, $skippedMatch);
            preg_match('/Errors:\s*(\d+)/', $syncOutput, $errorsMatch);
            
            $synced = $syncedMatch[1] ?? 0;
            $skipped = $skippedMatch[1] ?? 0;
            $errors = $errorsMatch[1] ?? 0;
            
            // Redirect to refresh data
            header("Location: manage_employee.php?synced=1&new=$synced&skipped=$skipped&errors=$errors");
            exit;
            
        } catch (Exception $e) {
            ob_end_clean();
            $syncMessage = "<div class='alert alert-danger'>❌ Sync failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $syncMessage = "<div class='alert alert-danger'>❌ Sync script not found. Please ensure 'sync_users_include.php' is in the same directory.</div>";
    }
}

// Show sync message after redirect
if (isset($_GET['synced']) && $_GET['synced'] == '1') {
    $synced = $_GET['new'] ?? 0;
    $skipped = $_GET['skipped'] ?? 0;
    $errors = $_GET['errors'] ?? 0;
    
    if ($synced > 0) {
        $syncMessage = "<div class='alert alert-success'>
            ✅ Sync completed successfully!<br>
            <strong>Synced:</strong> $synced new users | 
            <strong>Skipped:</strong> $skipped duplicates | 
            <strong>Errors:</strong> $errors
        </div>";
    } elseif ($errors > 0) {
        $syncMessage = "<div class='alert alert-danger'>
            ❌ Sync completed with errors!<br>
            <strong>Synced:</strong> $synced | <strong>Errors:</strong> $errors
        </div>";
    } else {
        $syncMessage = "<div class='alert alert-info'>
            ℹ️ No new users to sync. All users are already in the Employees table.
        </div>";
    }
}

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
            
            // Redirect back with filter preserved
            $redirectUrl = "manage_employee.php?updated=1";
            if (isset($_POST['filter']) && $_POST['filter'] === 'unassigned') {
                $redirectUrl .= "&filter=unassigned";
            }
            header("Location: $redirectUrl");
            exit;
        } else {
            $message = "<div class='alert alert-danger'>❌ Error: ".$stmt->error."</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>⚠ Please fill in all required fields.</div>";
    }
}

// Show success message after redirect
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = "<div class='alert alert-success'>✅ Employee updated successfully!</div>";
}

// ✅ Filter handling
$filter = $_GET['filter'] ?? '';
$isFiltered = ($filter === 'unassigned');

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

// Build query params for maintaining filter in sort links
$queryParams = $isFiltered ? "&filter=unassigned" : "";

// ✅ Fetch ACTIVE Employees only (joined with gsheet_employees)
$employees = [];
$sql = "SELECT e.EmployeeID, e.FirstName, e.LastName, e.Email, e.som_email, e.SOM, e.role 
        FROM Employees e
        INNER JOIN gsheet_employees ge ON e.Email = ge.email
        WHERE ge.status IN ('Active', 'Training', 'Pending')";

// Add filter condition if filtering for unassigned approvers
if ($isFiltered) {
    $sql .= " AND (e.som_email IS NULL OR e.som_email = '')";
}

$sql .= " ORDER BY e.$sortColumn $sortOrder";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// ✅ Calculate unassigned approver count (always from full dataset)
$unassignedApproverCount = 0;
$countResult = $conn->query("SELECT COUNT(*) as count
                             FROM Employees e
                             INNER JOIN gsheet_employees ge ON e.Email = ge.email
                             WHERE ge.status IN ('Active', 'Training', 'Pending')
                             AND (e.som_email IS NULL OR e.som_email = '')");
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $unassignedApproverCount = $countRow['count'];
}

// Total active employees count
$totalActiveCount = 0;
$totalResult = $conn->query("SELECT COUNT(*) as count
                             FROM Employees e
                             INNER JOIN gsheet_employees ge ON e.Email = ge.email
                             WHERE ge.status IN ('Active', 'Training', 'Pending')");
if ($totalResult) {
    $totalRow = $totalResult->fetch_assoc();
    $totalActiveCount = $totalRow['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees | Cohere</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .header {
            background: white;
            padding: 30px 40px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .header h1 {
            color: #1a202c;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            color: #718096;
            font-size: 14px;
        }

        .stats {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            flex: 1;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: block;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
        }

        .stat-card.active {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
        }

        .stat-card .label {
            font-size: 12px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            margin-top: 5px;
        }

        .filter-badge {
            display: inline-block;
            background: #fef3c7;
            color: #b45309;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .clear-filter {
            background: none;
            border: none;
            color: #b45309;
            text-decoration: underline;
            cursor: pointer;
            margin-left: 10px;
            font-size: 12px;
        }

        .clear-filter:hover {
            color: #92400e;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
        }

        thead th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
        }

        thead th a {
            color: white;
            text-decoration: none;
            transition: opacity 0.2s;
        }

        thead th a:hover {
            opacity: 0.8;
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

        .approver-cell {
            font-size: 13px;
            color: #4a5568;
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

        .role-badge.admin {
            background: #fef3c7;
            color: #b45309;
        }

        .role-badge.manager {
            background: #dbeafe;
            color: #1e40af;
        }

        .role-badge.director {
            background: #fce7f3;
            color: #be185d;
        }

        .btn-edit {
            padding: 6px 16px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-edit:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }

        .modal-header {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            border: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .alert {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-sync {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-sync:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-sync:active {
            transform: translateY(0);
        }

        .sync-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
            }

            table {
                font-size: 12px;
            }

            thead th, tbody td {
                padding: 12px 10px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
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
        <li><a href="manage_employee.php" class="active">Manage Employees</a></li>
        <li><a href="manage_add_employee.php">Add Employee</a></li>
        <li><a href="manage_userdata.php">Manage Userdata</a></li>
        <li><a href="manage_supervisors.php">Supervisor Assignment</a></li>
    </ul>

    <div class="header">
        <div class="sync-container">
            <div>
                <h1>Active Employees</h1>
                <p>Showing only active employees from the system</p>
            </div>
            <form method="post" style="margin: 0;">
                <button type="submit" name="syncUsers" class="btn-sync" onclick="return confirm('Sync users from userdata to Employees table?')">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                        <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                    </svg>
                    Sync Users
                </button>
            </form>
        </div>
        
        <?php if ($isFiltered): ?>
            <div class="filter-badge">
                🔍 Filtered: Showing only unassigned approvers
                <a href="manage_employee.php" class="clear-filter">✖ Clear Filter</a>
            </div>
        <?php endif; ?>

        <div class="stats">
            <a href="manage_employee.php" class="stat-card <?= !$isFiltered ? 'active' : '' ?>" style="color: white;">
                <div class="label">Total Active Employees</div>
                <div class="value"><?= $totalActiveCount ?></div>
            </a>
            <a href="?filter=unassigned" class="stat-card warning <?= $isFiltered ? 'active' : '' ?>" style="color: white;">
                <div class="label">Unassigned Approver</div>
                <div class="value"><?= $unassignedApproverCount ?></div>
            </a>
        </div>
    </div>

    <!-- Status Messages -->
    <?php if (!empty($syncMessage)): ?>
        <?= $syncMessage ?>
    <?php endif; ?>
    
    <?php if (!empty($message)): ?>
        <?= $message ?>
    <?php endif; ?>

    <!-- Employee Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><a href="?sort=EmployeeID&order=<?= ($sortColumn === 'EmployeeID') ? $nextOrder : 'ASC' ?><?= $queryParams ?>">Employee ID <?= $sortColumn === 'EmployeeID' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?></a></th>
                    <th><a href="?sort=FirstName&order=<?= ($sortColumn === 'FirstName') ? $nextOrder : 'ASC' ?><?= $queryParams ?>">First Name <?= $sortColumn === 'FirstName' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?></a></th>
                    <th><a href="?sort=LastName&order=<?= ($sortColumn === 'LastName') ? $nextOrder : 'ASC' ?><?= $queryParams ?>">Last Name <?= $sortColumn === 'LastName' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?></a></th>
                    <th>Email</th>
                    <th><a href="?sort=SOM&order=<?= ($sortColumn === 'SOM') ? $nextOrder : 'ASC' ?><?= $queryParams ?>">SOM <?= $sortColumn === 'SOM' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?></a></th>
                    <th>Approver</th>
                    <th><a href="?sort=role&order=<?= ($sortColumn === 'role') ? $nextOrder : 'ASC' ?><?= $queryParams ?>">Role <?= $sortColumn === 'role' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?></a></th>
                    <th style="width:100px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: #718096;">
                        <?php if ($isFiltered): ?>
                            🎉 Great! No employees with unassigned approvers found.
                        <?php else: ?>
                            No active employees found.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($emp['EmployeeID']) ?></strong></td>
                        <td><?= htmlspecialchars($emp['FirstName']) ?></td>
                        <td><?= htmlspecialchars($emp['LastName']) ?></td>
                        <td class="email-cell"><?= htmlspecialchars($emp['Email']) ?></td>
                        <td><?= htmlspecialchars($emp['SOM'] ?? '—') ?></td>
                        <td class="approver-cell">
                            <?php if (!empty($emp['som_email'])): ?>
                                <?= htmlspecialchars($emp['som_email']) ?>
                            <?php else: ?>
                                <em style="color: #cbd5e0;">Not assigned</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="role-badge <?= strtolower($emp['role'] ?? '') ?>">
                                <?= htmlspecialchars($emp['role']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-edit"
                                data-bs-toggle="modal"
                                data-bs-target="#editModal"
                                data-employee='<?= json_encode($emp) ?>'>
                                ✏️ Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Edit Employee</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3 p-3">
            <input type="hidden" name="EmployeeID" id="editEmployeeID">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
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
                <label class="form-label">Approver</label>
                <input type="email" class="form-control" name="som_email" id="editSomEmail" placeholder="Enter approver email">
            </div>
            <div class="col-md-6">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" id="editRole">
                    <option value="Employee">Employee</option>
                    <option value="Manager">Manager</option>
                    <option value="Director">Director</option>
                    <option value="Admin">Admin</option>
                    <option value="Team Lead">Team Lead</option>
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
if (editModal) {
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
}
</script>
</body>
</html>