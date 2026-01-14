<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    header("Location: ../login.php");
    exit();
}

$conn = getDBConnection();

// Access control: Determine what incidents user can see
$user_role = $_SESSION['role'] ?? 'Employee';
$user_email = $_SESSION['user_email'];
$is_supervisor = $_SESSION['is_supervisor'] ?? false;

// Managers, Directors, Admins can see ALL incidents
$can_see_all = in_array($user_role, ['Admin', 'Manager', 'Director']);

// ADD THIS: Check if user is HR (specific emails)
$hr_emails = explode(',', HR_EMAIL_RECIPIENTS);
$is_hr = in_array($user_email, $hr_emails);

// Check if user is SGA (specific emails)
$allowed_sga_emails = [
    'anamarie.munez@cohere.ph',
    'honey.cortes@cohere.ph',
];
$is_sga = in_array($user_email, $allowed_sga_emails);

// Check if user is in a group (except TL group)
// Skip group check for SGA users - they only see HR incidents
$user_group = null;

if (!$is_sga) {
    $stmt_user_group = $conn->prepare("
        SELECT group_name 
        FROM gsheet_employees 
        WHERE email = ? 
            AND group_name IS NOT NULL 
            AND group_name != 'TL'
            AND status = 'Active'
    ");
    $stmt_user_group->bind_param("s", $user_email);
    $stmt_user_group->execute();
    $user_group_result = $stmt_user_group->get_result();

    if ($group_row = $user_group_result->fetch_assoc()) {
        $user_group = $group_row['group_name'];
    }
    $stmt_user_group->close();
}

// If user is HR or SGA, show only HR-escalated incidents
if (!$can_see_all && !$is_supervisor && !$user_group && ($is_hr || $is_sga)) {
    $sql .= " AND ir.status IN ('pending_hr', 'resolved_hr')";
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build query
$sql = "SELECT ir.* FROM incident_reports ir WHERE 1=1";
$params = [];
$types = "";

// If user is a supervisor (not manager/admin/director), filter by supervised agents
if (!$can_see_all && $is_supervisor) {
    $sql .= " AND ir.employee_id IN (
        SELECT e.EmployeeID 
        FROM supervisor_mapping sm
        INNER JOIN Employees e ON sm.agent_email = e.Email
        WHERE sm.supervisor_email = ?
    )";
    $params[] = $user_email;
    $types .= "s";
}

if (!$can_see_all && !$is_supervisor && $user_group) {
    $sql .= " AND ir.employee_id IN (
        SELECT employee_id 
        FROM gsheet_employees 
        WHERE group_name = ? 
            AND status = 'Active'
    )";
    $params[] = $user_group;
    $types .= "s";
}

// If user is HR or SGA, show only HR-escalated incidents
if (!$can_see_all && !$is_supervisor && !$user_group && ($is_hr || $is_sga)) {
    $sql .= " AND ir.status IN ('pending_hr', 'resolved_hr')";
}

if ($status_filter !== 'all') {
    $sql .= " AND ir.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search)) {
    $sql .= " AND (report_number LIKE ? OR employee_name LIKE ? OR summary LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($start_date)) {
    $sql .= " AND incident_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if (!empty($end_date)) {
    $sql .= " AND incident_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics (filtered by supervisor if applicable)
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN ir.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN ir.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
    SUM(CASE WHEN ir.status = 'resolved' THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN ir.status = 'pending_hr' THEN 1 ELSE 0 END) as pending_hr,
    SUM(CASE WHEN ir.status = 'resolved_hr' THEN 1 ELSE 0 END) as resolved_hr
    FROM incident_reports ir
    WHERE 1=1";

// Apply same supervisor filter
if (!$can_see_all && $is_supervisor) {
    $stats_sql .= " AND ir.employee_id IN (
        SELECT e.EmployeeID 
        FROM supervisor_mapping sm
        INNER JOIN Employees e ON sm.agent_email = e.Email
        WHERE sm.supervisor_email = '$user_email'
    )";
}

// ADD THIS: Apply group filter
if (!$can_see_all && !$is_supervisor && $user_group) {
    $stats_sql .= " AND ir.employee_id IN (
        SELECT employee_id 
        FROM gsheet_employees 
        WHERE group_name = '$user_group' 
            AND status = 'Active'
    )";
}

$stats = $conn->query($stats_sql)->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Reports Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .dashboard-header {
            background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
            border-top: 4px solid #0f2557;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(255, 107, 53, 0.3);
        }
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #ff6b35;
        }
        .report-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            border-left: 4px solid #0f2557;
        }
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            border-left-color: #ff6b35;
        }
        .badge-pending {
            background: #ffc107;
            color: #000;
        }
        .badge-reviewed {
            background: #0f2557;
        }
        .badge-resolved {
            background: #28a745;
        }
        .report-number {
            font-weight: bold;
            color: #0f2557;
            font-size: 18px;
        }
        .report-date {
            color: #666;
            font-size: 14px;
        }
        .report-summary {
            color: #333;
            margin: 10px 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #e55a2b 100%);
            transform: translateY(-2px);
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #0f2557;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            color: #666;
            font-weight: 600;
            border: none;
            padding: 12px 25px;
        }
        .nav-tabs .nav-link.active {
            color: #0f2557;
            background: white;
            border-bottom: 3px solid #ff6b35;
        }
        .nav-tabs .nav-link:hover {
            color: #ff6b35;
            border-color: transparent;
        }
        
        /* Table View */
        .table-responsive {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%);
            color: white;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            padding: 15px 10px;
        }
        .table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s;
        }
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        .table tbody td {
            padding: 15px 10px;
            vertical-align: middle;
        }
        .btn-copy {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .btn-copy:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #e55a2b 100%);
            color: white;
        }
        .copy-success {
            display: none;
            color: #28a745;
            font-weight: bold;
            margin-left: 10px;
        }

        /* Table Column Widths and Text Handling */
        .table tbody td:nth-child(1) {
            width: 12%;
            white-space: nowrap;
        }
        .table tbody td:nth-child(2) {
            width: 20%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .table tbody td:nth-child(3) {
            width: 35%;
        }
        .table tbody td:nth-child(4) {
            width: 20%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .table tbody td:nth-child(5) {
            width: 13%;
            white-space: nowrap;
        }

        /* Table Column Widths and Text Handling */
        .table tbody td:nth-child(1) {
            width: 10%;
            white-space: nowrap;
        }
        .table tbody td:nth-child(2) { /* Agent Involved */
            width: 18%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }
        .table tbody td:nth-child(3) { /* Summary */
            width: 30%;
        }
        .table tbody td:nth-child(4) { /* Reported By */
            width: 18%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }
        .table tbody td:nth-child(5) { /* Status */
            width: 10%;
            white-space: nowrap;
        }
        .table tbody td:nth-child(6) { /* IR Number */
            width: 14%;
            white-space: nowrap;
            font-family: monospace;
            font-size: 13px;
            color: #0f2557;
            font-weight: 600;
        }

        .badge-pending_hr {
            background: #ff6b35;
        }
        .badge-resolved_hr {
            background: #6c757d;
        }
    </style>
</head>
<body>
    <div class="dashboard-header">
        <div class="container">
            <h1>📊 Incident Reports Dashboard</h1>
            <p>View and manage all incident reports</p>
        </div>
    </div>

    <div class="container">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Total Reports</div>
                    <div class="stat-number" style="color: #0f2557;"><?= $stats['total'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Pending</div>
                    <div class="stat-number" style="color: #ffc107;"><?= $stats['pending'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Reviewed</div>
                    <div class="stat-number" style="color: #0f2557;"><?= $stats['reviewed'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Resolved</div>
                    <div class="stat-number" style="color: #28a745;"><?= $stats['resolved'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Pending HR</div>
                    <div class="stat-number" style="color: #ff6b35;"><?= $stats['pending_hr'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-label">Resolved HR</div>
                    <div class="stat-number" style="color: #6c757d;"><?= $stats['resolved_hr'] ?></div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed" <?= $status_filter === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="pending_hr" <?= $status_filter === 'pending_hr' ? 'selected' : '' ?>>Pending HR</option>
                        <option value="resolved_hr" <?= $status_filter === 'resolved_hr' ? 'selected' : '' ?>>Resolved HR</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Report #, Name, or Summary" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="cards-tab" data-bs-toggle="tab" data-bs-target="#cards-view" type="button">
                    📋 Cards View
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="table-tab" data-bs-toggle="tab" data-bs-target="#table-view" type="button">
                    📊 Table View
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Cards View -->
            <div class="tab-pane fade show active" id="cards-view" role="tabpanel">
                <div class="reports-container">
                    <?php if (empty($reports)): ?>
                        <div class="alert alert-info text-center">
                            No incident reports found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <div class="report-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="report-number"><?= htmlspecialchars($report['report_number']) ?></div>
                                        <div class="report-date">
                                            📅 <?= date('F j, Y', strtotime($report['incident_date'])) ?> | 
                                            👤 <?= htmlspecialchars($report['employee_name']) ?> (<?= htmlspecialchars($report['employee_id']) ?>)
                                        </div>
                                        <div class="report-summary">
                                            <?= htmlspecialchars($report['summary']) ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column align-items-end gap-2">
                                        <span class="badge badge-<?= $report['status'] ?>"><?= strtoupper($report['status']) ?></span>
                                        <a href="view_report.php?id=<?= $report['report_number'] ?>" class="btn btn-sm btn-primary" target="_blank">View Details</a>
                                        <?php if ($can_see_all || $is_supervisor || $user_group || $is_hr || $is_sga): ?>
                                            <select class="form-select form-select-sm" style="width: 140px;" onchange="updateStatus('<?= $report['report_number'] ?>', this.value)">
                                                <?php if (!$is_hr): ?>
                                                    <!-- Regular users see all options -->
                                                    <option value="pending" <?= $report['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="reviewed" <?= $report['status'] === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                                    <option value="resolved" <?= $report['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                                    <option value="pending_hr" <?= $report['status'] === 'pending_hr' ? 'selected' : '' ?>>Pending HR</option>
                                                    <option value="resolved_hr" <?= $report['status'] === 'resolved_hr' ? 'selected' : '' ?>>Resolved HR</option>
                                                <?php else: ?>
                                                    <!-- HR users ONLY see HR options -->
                                                    <option value="pending_hr" <?= $report['status'] === 'pending_hr' ? 'selected' : '' ?>>Pending HR</option>
                                                    <option value="resolved_hr" <?= $report['status'] === 'resolved_hr' ? 'selected' : '' ?>>Resolved HR</option>
                                                <?php endif; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        Reported by: <?= htmlspecialchars($report['submitted_by_name'] ?? 'N/A') ?> | 
                                        <?= date('M j, Y g:i A', strtotime($report['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table View -->
            <div class="tab-pane fade" id="table-view" role="tabpanel">
                <?php if (empty($reports)): ?>
                    <div class="alert alert-info text-center">
                        No incident reports found.
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <button class="btn btn-copy" onclick="copyTableToClipboard()">
                            📋 Copy Table
                        </button>
                        <span class="copy-success" id="copySuccess">✅ Copied to clipboard!</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="reportsTable">
    <thead>
        <tr>
            <th>Date of Incident</th>
            <th>Agent Involved</th>
            <th>Summary</th>
            <th>Reported By</th>
            <th>Status</th>
            <th>IR Number</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($reports as $report): ?>
            <tr>
                <td><?= date('Y-m-d', strtotime($report['incident_date'])) ?></td>
                <td><?= htmlspecialchars($report['employee_name']) ?></td>
                <td><?= htmlspecialchars($report['summary']) ?></td>
                <td><?= htmlspecialchars($report['submitted_by_name'] ?? 'N/A') ?></td>
                <td><span class="badge badge-<?= $report['status'] ?>"><?= strtoupper($report['status']) ?></span></td>
                <td><?= htmlspecialchars($report['report_number']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function updateStatus(reportNumber, newStatus) {
            if (!confirm('Are you sure you want to update the status?')) {
                location.reload();
                return;
            }

            try {
                const response = await fetch('update_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        report_number: reportNumber,
                        status: newStatus
                    })
                });

                const result = await response.json();

                if (result.success) {
                    location.reload();
                } else {
                    alert('Error updating status: ' + result.message);
                    location.reload();
                }
            } catch (error) {
                alert('Error: ' + error.message);
                location.reload();
            }
        }

        function copyTableToClipboard() {
            const table = document.getElementById('reportsTable');
            let text = '';
            
            // Get headers
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            text += headers.join('\t') + '\n';
            
            // Get rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = Array.from(row.querySelectorAll('td')).map(td => {
                    // Remove badge HTML and get just the text
                    return td.textContent.trim();
                });
                text += cells.join('\t') + '\n';
            });
            
            // Copy to clipboard
            navigator.clipboard.writeText(text).then(() => {
                const successMsg = document.getElementById('copySuccess');
                successMsg.style.display = 'inline';
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 3000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>