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

// ✅ Fetch ACTIVE, TRAINING, and PENDING Userdata (joined with gsheet_employees)
$users = [];
$result = $conn->query("SELECT u.companyid, u.username, u.fname, u.lname, 
                               COALESCE(u.email, ge.email) as email, 
                               u.role, u.SOM, u.is_qa 
                        FROM userdata u
                        INNER JOIN gsheet_employees ge ON u.companyid = ge.employee_id
                        WHERE ge.status IN ('Active', 'Training', 'Pending')
                        ORDER BY u.lname, u.fname");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Calculate stats
$totalUsers = count($users);
$unassignedEmail = 0;
$unassignedSOM = 0;

foreach ($users as $user) {
    if (empty($user['email']) || is_null($user['email'])) {
        $unassignedEmail++;
    }
    if (empty($user['SOM']) || is_null($user['SOM'])) {
        $unassignedSOM++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Userdata | Cohere</title>
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
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
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

        .search-section {
            background: white;
            padding: 20px 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .search-box {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-box:focus {
            outline: none;
            border-color: #0d4081ff;
            box-shadow: 0 0 0 3px rgba(13, 64, 129, 0.1);
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
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }

        thead th:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        thead th.sortable::after {
            content: ' ⇅';
            opacity: 0.5;
            font-size: 14px;
            margin-left: 5px;
        }

        thead th.sort-asc::after {
            content: ' ▲';
            opacity: 1;
            color: #ffd700;
        }

        thead th.sort-desc::after {
            content: ' ▼';
            opacity: 1;
            color: #ffd700;
        }

        thead th:last-child {
            cursor: default;
        }

        thead th:last-child::after {
            content: none;
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

        .badge-active {
            display: inline-block;
            padding: 2px 8px;
            background: #d1fae5;
            color: #047857;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-dialog {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
        }

        .btn-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-body {
            padding: 30px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            color: #2d3748;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #0d4081ff;
            box-shadow: 0 0 0 3px rgba(13, 64, 129, 0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-success {
            padding: 10px 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-secondary {
            padding: 10px 24px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #4b5563;
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

        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
            }

            .form-row {
                grid-template-columns: 1fr;
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
        <li><a href="manage_employee.php">Manage Employees</a></li>
        <li><a href="manage_add_employee.php">Add Employee</a></li>
        <li><a href="manage_userdata.php" class="active">Manage Userdata</a></li>
        <li><a href="manage_supervisors.php">Supervisor Assignment</a></li>
    </ul>

    <div class="header">
        <h1>Active User Data</h1>
        <p>Manage active user accounts and their information</p>
        <div class="stats">
            <div class="stat-card">
                <div class="label">Active Users</div>
                <div class="value"><?= $totalUsers ?></div>
            </div>
            <div class="stat-card <?= $unassignedEmail > 0 ? 'warning' : '' ?>">
                <div class="label">Unassigned Email Address</div>
                <div class="value"><?= $unassignedEmail ?></div>
            </div>
            <div class="stat-card <?= $unassignedSOM > 0 ? 'warning' : '' ?>">
                <div class="label">Unassigned SOM</div>
                <div class="value"><?= $unassignedSOM ?></div>
            </div>
        </div>
    </div>

    <!-- Status Messages -->
    <?php if (!empty($message)): ?>
        <?= $message ?>
    <?php endif; ?>

    <!-- Search Section -->
    <div class="search-section">
        <input type="text" 
               id="searchInput" 
               class="search-box" 
               placeholder="🔍 Search by company ID, name, email, or role...">
    </div>

    <!-- Userdata Table -->
    <div class="table-container">
        <table id="usersTable">
            <thead>
                <tr>
                    <th class="sortable" data-column="0" data-type="text">Company ID</th>
                    <th class="sortable" data-column="1" data-type="text">Username</th>
                    <th class="sortable" data-column="2" data-type="text">First Name</th>
                    <th class="sortable" data-column="3" data-type="text">Last Name</th>
                    <th class="sortable" data-column="4" data-type="text">Email</th>
                    <th class="sortable" data-column="5" data-type="text">Role</th>
                    <th class="sortable" data-column="6" data-type="text">SOM</th>
                    <th class="sortable" data-column="7" data-type="text">Is QA</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: #718096;">
                        No active users found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td data-sort="<?= htmlspecialchars($u['companyid']) ?>">
                            <strong><?= htmlspecialchars($u['companyid']) ?></strong>
                        </td>
                        <td data-sort="<?= htmlspecialchars($u['username'] ?? '') ?>">
                            <?= htmlspecialchars($u['username'] ?? '—') ?>
                        </td>
                        <td data-sort="<?= htmlspecialchars($u['fname'] ?? '') ?>">
                            <?= htmlspecialchars($u['fname'] ?? '—') ?>
                            <span class="badge-active">ACTIVE</span>
                        </td>
                        <td data-sort="<?= htmlspecialchars($u['lname'] ?? '') ?>">
                            <?= htmlspecialchars($u['lname'] ?? '—') ?>
                        </td>
                        <td class="email-cell" data-sort="<?= htmlspecialchars($u['email'] ?? '') ?>">
                            <?php if (!empty($u['email'])): ?>
                                <?= htmlspecialchars($u['email']) ?>
                            <?php else: ?>
                                <em style="color: #cbd5e0;">Not assigned</em>
                            <?php endif; ?>
                        </td>
                        <td data-sort="<?= htmlspecialchars($u['role'] ?? '') ?>">
                            <span class="role-badge <?= strtolower($u['role'] ?? '') ?>">
                                <?= htmlspecialchars($u['role'] ?? '—') ?>
                            </span>
                        </td>
                        <td data-sort="<?= htmlspecialchars($u['SOM'] ?? '') ?>">
                            <?php if (!empty($u['SOM'])): ?>
                                <?= htmlspecialchars($u['SOM']) ?>
                            <?php else: ?>
                                <em style="color: #cbd5e0;">Not assigned</em>
                            <?php endif; ?>
                        </td>
                        <td data-sort="<?= $u['is_qa'] ? '1' : '0' ?>" style="text-align: center; font-size: 18px;">
                            <?= $u['is_qa'] ? '✅' : '❌' ?>
                        </td>
                        <td>
                            <button class="btn-edit"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
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
<div class="modal" id="editModal">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">Edit User</h5>
                <button type="button" class="btn-close" onclick="closeEditModal()">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="companyid" id="editCompanyID">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" id="editUsername">
                    </div>
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" name="fname" id="editFname">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-control" name="lname" id="editLname">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="editEmail">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role" id="editRole">
                            <option value="Employee">Employee</option>
                            <option value="Manager">Manager</option>
                            <option value="Director">Director</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SOM</label>
                        <input type="text" class="form-control" name="SOM" id="editSOM">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">QA Status</label>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_qa" id="editIsQA">
                        <label for="editIsQA">This user is a QA user</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="updateUser" class="btn-success">💾 Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeEditModal()">❌ Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Search functionality
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('#usersTable tbody tr');

    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Table sorting functionality
class TableSort {
    constructor(table) {
        this.table = table;
        this.tbody = table.querySelector('tbody');
        this.headers = table.querySelectorAll('th.sortable');
        this.currentSort = { column: null, direction: 'asc' };
        
        this.init();
    }

    init() {
        this.headers.forEach(header => {
            header.addEventListener('click', () => {
                const column = parseInt(header.dataset.column);
                const type = header.dataset.type;
                
                // Toggle direction if clicking same column
                if (this.currentSort.column === column) {
                    this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    this.currentSort.direction = 'asc';
                }
                
                this.currentSort.column = column;
                this.sortTable(column, type, this.currentSort.direction);
                this.updateHeaderClasses(header);
            });
        });
    }

    sortTable(column, type, direction) {
        const rows = Array.from(this.tbody.querySelectorAll('tr'));
        
        // Don't sort if it's the "no results" row
        if (rows.length === 1 && rows[0].querySelector('[colspan]')) {
            return;
        }

        rows.sort((a, b) => {
            const aCell = a.cells[column];
            const bCell = b.cells[column];
            
            // Get sort value from data-sort attribute or text content
            let aValue = aCell.dataset.sort || aCell.textContent.trim();
            let bValue = bCell.dataset.sort || bCell.textContent.trim();
            
            // Handle empty values
            if (!aValue || aValue === '—') aValue = '';
            if (!bValue || bValue === '—') bValue = '';
            
            // Compare values
            let comparison = 0;
            
            if (type === 'number') {
                comparison = parseFloat(aValue) - parseFloat(bValue);
            } else {
                comparison = aValue.toLowerCase().localeCompare(bValue.toLowerCase());
            }
            
            return direction === 'asc' ? comparison : -comparison;
        });

        // Re-append rows in sorted order
        rows.forEach(row => this.tbody.appendChild(row));
    }

    updateHeaderClasses(activeHeader) {
        // Remove all sort classes
        this.headers.forEach(header => {
            header.classList.remove('sort-asc', 'sort-desc');
        });
        
        // Add appropriate class to active header
        activeHeader.classList.add(
            this.currentSort.direction === 'asc' ? 'sort-asc' : 'sort-desc'
        );
    }
}

// Initialize table sorting
const table = document.getElementById('usersTable');
new TableSort(table);

// Modal functions
function openEditModal(user) {
    document.getElementById('editCompanyID').value = user.companyid;
    document.getElementById('editUsername').value = user.username ?? '';
    document.getElementById('editFname').value = user.fname ?? '';
    document.getElementById('editLname').value = user.lname ?? '';
    document.getElementById('editEmail').value = user.email ?? '';
    document.getElementById('editRole').value = user.role ?? 'Employee';
    document.getElementById('editSOM').value = user.SOM ?? '';
    document.getElementById('editIsQA').checked = user.is_qa == 1;
    
    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}

// Close modal when clicking outside
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>
</body>
</html>