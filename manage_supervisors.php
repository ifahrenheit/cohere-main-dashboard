<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// Restrict to Admins only
if ($_SESSION['role'] !== 'Admin') {
    die("Access denied.");
}

// Fetch only ACTIVE users from gsheet_employees
$users = [];
$sql = "SELECT u.* 
        FROM userdata u
        INNER JOIN gsheet_employees ge ON u.email = ge.email
        WHERE u.email IS NOT NULL 
        AND ge.status = 'Active'
        ORDER BY u.fname, u.lname";

$result = $conn->query($sql);

if (!$result) {
    die("Query failed: " . $conn->error);
}

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Fetch supervisor mappings
$supervisors = [];
$result = $conn->query("SELECT agent_email, supervisor_email FROM supervisor_mapping");

while ($row = $result->fetch_assoc()) {
    $supervisors[$row['agent_email']] = $row['supervisor_email'];
}

// Calculate unassigned count
$unassignedCount = 0;
foreach ($users as $user) {
    if (!isset($supervisors[$user['email']])) {
        $unassignedCount++;
    }
}

// Get active supervisors for dropdown
$activeSupervisors = [];
$supResult = $conn->query("SELECT u.email, u.fname, u.lname 
                           FROM userdata u
                           INNER JOIN gsheet_employees ge ON u.email = ge.email
                           WHERE u.email IS NOT NULL 
                           AND ge.status = 'Active'
                           ORDER BY u.fname, u.lname");

while ($row = $supResult->fetch_assoc()) {
    $activeSupervisors[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Assignment - Cohere</title>
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
            cursor: pointer;
            user-select: none;
            position: relative;
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

        .supervisor-cell {
            font-size: 13px;
            color: #4a5568;
        }

        .action-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .action-form select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            color: #2d3748;
            background: white;
            min-width: 200px;
            transition: all 0.3s;
        }

        .action-form select:focus {
            outline: none;
            border-color: #0d4081ff;
            box-shadow: 0 0 0 3px rgba(13, 64, 129, 0.1);
        }

        .btn-assign {
            padding: 8px 20px;
            background: linear-gradient(135deg, #050f38ff 0%, #0d4081ff 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .btn-assign:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 64, 129, 0.4);
        }

        .btn-assign:active {
            transform: translateY(0);
        }

        .no-results {
            padding: 40px;
            text-align: center;
            color: #718096;
            font-size: 14px;
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

        @media (max-width: 1200px) {
            .action-form {
                flex-direction: column;
                align-items: stretch;
            }

            .action-form select {
                width: 100%;
            }
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
        }


    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h2>👥 Employee Management</h2>
            <a href="dashboard.php" class="btn-back">⬅ Back to Dashboard</a>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav-tabs">
            <li><a href="manage_employee.php">Manage Employees</a></li>
            <li><a href="manage_add_employee.php">Add Employee</a></li>
            <li><a href="manage_userdata.php">Manage Userdata</a></li>
            <li><a href="manage_supervisors.php" class="active">Supervisor Assignment</a></li>
        </ul>

        <div class="header">
            <h1>Supervisor Assignment</h1>
            <p>Manage active employees and their supervisors</p>
            <div class="stats">
                <div class="stat-card">
                    <div class="label">Active Employees</div>
                    <div class="value"><?= count($users) ?></div>
                </div>
                <div class="stat-card <?= $unassignedCount > 0 ? 'warning' : '' ?>">
                    <div class="label">Unassigned Employees</div>
                    <div class="value"><?= $unassignedCount ?></div>
                </div>
            </div>
        </div>

        <div class="search-section">
            <input type="text" 
                   id="searchInput" 
                   class="search-box" 
                   placeholder="🔍 Search by name, email, or company ID...">
        </div>

        <div class="table-container">
            <table id="usersTable">
                <thead>
                    <tr>
                        <th class="sortable" data-column="0" data-type="text">Company ID</th>
                        <th class="sortable" data-column="1" data-type="text">Full Name</th>
                        <th class="sortable" data-column="2" data-type="text">Email</th>
                        <th class="sortable" data-column="3" data-type="text">Role</th>
                        <th class="sortable" data-column="4" data-type="text">Current Supervisor</th>
                        <th>Assign Supervisor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="no-results">
                                No active employees found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td data-sort="<?= htmlspecialchars($u['companyid'] ?? '') ?>">
                                    <strong><?= htmlspecialchars($u['companyid'] ?? '—') ?></strong>
                                </td>
                                <td data-sort="<?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?>">
                                    <?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?>
                                    <span class="badge-active">ACTIVE</span>
                                </td>
                                <td class="email-cell" data-sort="<?= htmlspecialchars($u['email'] ?? '') ?>">
                                    <?= htmlspecialchars($u['email'] ?? '—') ?>
                                </td>
                                <td data-sort="<?= htmlspecialchars($u['role'] ?? '') ?>">
                                    <span class="role-badge <?= strtolower($u['role'] ?? '') ?>">
                                        <?= htmlspecialchars($u['role'] ?? 'Agent') ?>
                                    </span>
                                </td>
                                <td class="supervisor-cell" data-sort="<?= htmlspecialchars($supervisors[$u['email']] ?? '') ?>">
                                    <?php if (isset($supervisors[$u['email']])): ?>
                                        <?= htmlspecialchars($supervisors[$u['email']]) ?>
                                    <?php else: ?>
                                        <em style="color: #cbd5e0;">Not assigned</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="update_supervisor.php" method="POST" class="action-form">
                                        <input type="hidden" name="agent_email" value="<?= htmlspecialchars($u['email']) ?>">
                                        
                                        <select name="supervisor_email" required>
                                            <option value="">— Select Supervisor —</option>
                                            <?php foreach ($activeSupervisors as $sup): ?>
                                                <?php 
                                                $selected = (isset($supervisors[$u['email']]) && $supervisors[$u['email']] === $sup['email']) ? 'selected' : '';
                                                ?>
                                                <option value="<?= htmlspecialchars($sup['email']) ?>" <?= $selected ?>>
                                                    <?= htmlspecialchars($sup['fname'] . ' ' . $sup['lname']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="btn-assign">Assign</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                
                if (rows.length === 1 && rows[0].querySelector('.no-results')) {
                    return;
                }

                rows.sort((a, b) => {
                    const aCell = a.cells[column];
                    const bCell = b.cells[column];
                    
                    let aValue = aCell.dataset.sort || aCell.textContent.trim();
                    let bValue = bCell.dataset.sort || bCell.textContent.trim();
                    
                    if (!aValue || aValue === '—' || aValue === 'Not assigned') aValue = '';
                    if (!bValue || bValue === '—' || bValue === 'Not assigned') bValue = '';
                    
                    let comparison = 0;
                    
                    if (type === 'number') {
                        comparison = parseFloat(aValue) - parseFloat(bValue);
                    } else {
                        comparison = aValue.toLowerCase().localeCompare(bValue.toLowerCase());
                    }
                    
                    return direction === 'asc' ? comparison : -comparison;
                });

                rows.forEach(row => this.tbody.appendChild(row));
            }

            updateHeaderClasses(activeHeader) {
                this.headers.forEach(header => {
                    header.classList.remove('sort-asc', 'sort-desc');
                });
                
                activeHeader.classList.add(
                    this.currentSort.direction === 'asc' ? 'sort-asc' : 'sort-desc'
                );
            }
        }

        const table = document.getElementById('usersTable');
        new TableSort(table);
    </script>
</body>
</html>