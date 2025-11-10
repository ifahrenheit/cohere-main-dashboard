<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// ✅ Access Control: Only Admin/Manager/Director can view all timesheets
$allowedRoles = ['Admin', 'Manager', 'Director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    die("Access Denied. Only managers and admins can view all timesheets.");
}

// DB connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle filters
$startDate = $_GET['start_date'] ?? '2025-01-01';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$sortBy = $_GET['sort_by'] ?? 'date_desc'; // Default: newest first
$employeeFilter = $_GET['employee_filter'] ?? ''; // Optional: filter by specific employee

$filterStartDatetime = date('Y-m-d 00:00:00', strtotime($startDate));
$filterEndDatetime = date('Y-m-d 23:59:59', strtotime($endDate));

// Build sort clause
$sortClause = '';
switch ($sortBy) {
    case 'date_asc':
        $sortClause = 'ORDER BY t.date ASC';
        break;
    case 'date_desc':
        $sortClause = 'ORDER BY t.date DESC';
        break;
    case 'employee_name':
        $sortClause = 'ORDER BY u.fname ASC, u.lname ASC, t.date DESC';
        break;
    case 'time_only':
        $sortClause = 'ORDER BY TIME(t.date) ASC, t.date DESC';
        break;
    default:
        $sortClause = 'ORDER BY t.date DESC';
}

// Build WHERE clause
$whereConditions = "t.date BETWEEN ? AND ?";
$params = [$filterStartDatetime, $filterEndDatetime];
$paramTypes = "ss";

if (!empty($employeeFilter)) {
    $whereConditions .= " AND u.personid = ?";
    $params[] = $employeeFilter;
    $paramTypes .= "i";
}

// Fetch ALL time records (not filtered by personid)
$query = "
    SELECT 
        u.personid,
        u.companyid AS EmployeeID,
        u.fname AS FirstName,
        u.lname AS LastName,
        DATE(t.date) AS Day,
        TIME(t.date) AS Time,
        t.type AS Type,
        t.date AS FullDateTime
    FROM dailytimerecord t
    JOIN userdata u ON t.personid = u.personid
    WHERE {$whereConditions}
    {$sortClause}
    LIMIT 20
";

$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$allRecords = [];
while ($row = $result->fetch_assoc()) {
    $allRecords[] = $row;
}

// Get list of all employees for filter dropdown
$employeeQuery = "SELECT personid, CONCAT(fname, ' ', lname) AS full_name, companyid FROM userdata ORDER BY fname, lname";
$employeeResult = $conn->query($employeeQuery);
$allEmployees = [];
while ($empRow = $employeeResult->fetch_assoc()) {
    $allEmployees[] = $empRow;
}

// Count records
$totalRecords = count($allRecords);

$conn->close();

// Role-based styling
$role = $_SESSION['role'] ?? 'Employee';
$roleNormalized = strtolower($role);
$bgClass = ($roleNormalized === 'admin') ? 'admin-bg' : 'default-bg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Timesheets - COHERE Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .sort-badge {
            background-color: #007bff;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            margin-left: 10px;
        }
        .record-count {
            color: #6c757d;
            font-size: 0.95rem;
            margin-top: 10px;
        }
        .table th {
            background-color: #e9ecef;
            font-weight: 600;
            text-align: center;
        }
        .table td {
            vertical-align: middle;
        }
        .type-badge {
            font-weight: 600;
            padding: 5px 8px;
            border-radius: 3px;
        }
        .type-in {
            background-color: #d4edda;
            color: #155724;
        }
        .type-out {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>

<body class="<?php echo htmlspecialchars($bgClass, ENT_QUOTES, 'UTF-8'); ?>">

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg px-3 custom-navbar">
    <a class="navbar-brand text-blue" href="dashboard.php">← DASHBOARD</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a href="logout.php" class="btn btn-logout">Logout</a>
            </li>
        </ul>
    </div>
</nav>

<!-- Main Content -->
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📊 All Employees Time Records</h2>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <!-- Start Date -->
            <div class="col-md-2">
                <label for="start_date" class="form-label"><strong>Start Date:</strong></label>
                <input type="date" name="start_date" id="start_date" class="form-control" 
                       value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <!-- End Date -->
            <div class="col-md-2">
                <label for="end_date" class="form-label"><strong>End Date:</strong></label>
                <input type="date" name="end_date" id="end_date" class="form-control" 
                       value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <!-- Employee Filter -->
            <div class="col-md-3">
                <label for="employee_filter" class="form-label"><strong>Filter by Employee:</strong></label>
                <select name="employee_filter" id="employee_filter" class="form-control">
                    <option value="">All Employees</option>
                    <?php foreach ($allEmployees as $emp): ?>
                        <option value="<?= htmlspecialchars($emp['personid'], ENT_QUOTES, 'UTF-8') ?>" 
                                <?= ($employeeFilter == $emp['personid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['full_name'] . ' (' . $emp['companyid'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort By -->
            <div class="col-md-3">
                <label for="sort_by" class="form-label"><strong>Sort By:</strong></label>
                <select name="sort_by" id="sort_by" class="form-control">
                    <option value="date_desc" <?= ($sortBy === 'date_desc') ? 'selected' : '' ?>>📅 Newest First</option>
                    <option value="date_asc" <?= ($sortBy === 'date_asc') ? 'selected' : '' ?>>📅 Oldest First</option>
                    <option value="employee_name" <?= ($sortBy === 'employee_name') ? 'selected' : '' ?>>👤 Employee Name</option>
                    <option value="time_only" <?= ($sortBy === 'time_only') ? 'selected' : '' ?>>🕐 Time of Day</option>
                </select>
            </div>

            <!-- Search Button -->
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-danger w-100">🔍 Search</button>
            </div>
        </form>

        <div class="record-count">
            <strong>Total Records:</strong> <?= $totalRecords ?> records found
            <?php if (!empty($sortBy)): ?>
                <span class="sort-badge"><?= htmlspecialchars($sortBy === 'date_desc' ? 'Newest First' : ($sortBy === 'date_asc' ? 'Oldest First' : ($sortBy === 'employee_name' ? 'By Name' : 'By Time')), ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Time Records Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover table-bordered">
            <thead class="table-light">
                <tr>
                    <th class="text-center">Employee ID</th>
                    <th class="text-center">First Name</th>
                    <th class="text-center">Last Name</th>
                    <th class="text-center">📅 Date</th>
                    <th class="text-center">🕐 Time</th>
                    <th class="text-center">Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allRecords)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <strong>No records found for the selected criteria.</strong>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allRecords as $record): ?>
                        <tr>
                            <td class="text-center">
                                <?= htmlspecialchars($record['EmployeeID'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($record['FirstName'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($record['LastName'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <?= htmlspecialchars($record['Day'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <?= htmlspecialchars($record['Time'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <span class="type-badge <?= (strtolower($record['Type']) === 'in') ? 'type-in' : 'type-out' ?>">
                                    <?= htmlspecialchars(strtoupper($record['Type']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>