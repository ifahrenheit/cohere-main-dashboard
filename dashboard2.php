<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// dashboard2.php (or sidebar include)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_theme'])) {
    $_SESSION['dashboard_theme'] = $_POST['dashboard_theme'];

    // Redirect to the selected dashboard
    if ($_POST['dashboard_theme'] === 'dashboard1') {
        header("Location: dashboard.php");
        exit();
    } else {
        header("Location: dashboard2.php");
        exit();
    }
}

session_regenerate_id(true);

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    $currentUrl = $_SERVER['REQUEST_URI'];
    header("Location: ../login.php?redirect=" . urlencode($currentUrl));
    exit();
}

$email = $_SESSION['user_email'];

// Required for cross-subdomain session sharing
setcookie(session_name(), session_id(), [
  'expires' => time() + 86400,
  'domain' => '.cohere.ph',
  'path' => '/',
  'secure' => true,
  'httponly' => true,
  'samesite' => 'None'
]);

// DB connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user data and group_name
$stmt = $conn->prepare("
    SELECT 
        u.personid, 
        u.fname, 
        u.lname, 
        u.companyid,
        g.group_name
    FROM userdata u
    LEFT JOIN gsheet_employees g ON u.email = g.email
    WHERE u.email = ?
");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die("User not found.");
}

$personID = $user['personid'];
$_SESSION['personid'] = $personID;
$_SESSION['group_name'] = $user['group_name'] ?? null; // Store group_name in session

// Role-based body class
$role = $_SESSION['role'] ?? 'Employee';
$roleNormalized = strtolower($role);
$bgClass = ($roleNormalized === 'admin') ? 'admin-bg' : 'default-bg';

// Get last uploaded date
$lastUploadedDate = 'No records found';
$query = "SELECT DATE_SUB(DATE(MAX(date)), INTERVAL 1 DAY) AS last_uploaded_date FROM dailytimerecord";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    if ($row['last_uploaded_date']) {
        $lastUploadedDate = date('F j, Y', strtotime($row['last_uploaded_date']));
    }
}

// Handle filters
$startDate = $_GET['start_date'] ?? '2025-01-01';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$filterStartDatetime = date('Y-m-d 00:00:00', strtotime($startDate));
$filterEndDatetime = date('Y-m-d 23:59:59', strtotime($endDate));

// Fetch logs for the logged-in user
$stmt = $conn->prepare("
    SELECT 
        u.companyid AS EmployeeID,
        u.fname AS FirstName,
        u.lname AS LastName,
        DATE(t.date) AS Day,
        TIME(t.date) AS Time,
        t.type AS Type
    FROM dailytimerecord t
    JOIN userdata u ON t.personid = u.personid
    WHERE t.personid = ?
    AND t.date BETWEEN ? AND ?
    ORDER BY u.companyid, u.fname, u.lname, t.date DESC
");
$stmt->bind_param("sss", $personID, $filterStartDatetime, $filterEndDatetime);
$stmt->execute();
$result = $stmt->get_result();

$finalRecords = [];
while ($row = $result->fetch_assoc()) {
    $finalRecords[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>COHERE Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<script>
// Add small-screen class immediately if needed
if (window.innerWidth <= 1400) {
    document.documentElement.classList.add('small-screen');
}
</script>

<style>
/* Body & theme */
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin:0; transition: background 0.3s; }
body.dark-mode { background-color: #121212; color: #e1e1e1; }

/* Sidebar */
#sidebar { min-width: 250px; max-width: 250px; background: #1e5a96; color: #fff; height: 100vh; position: fixed; transition: all 0.3s; overflow-y:auto; }
#sidebar.collapsed { margin-left: -250px; }
#sidebar .nav-link { color: #fff; font-size: 0.95rem; }
#sidebar .nav-link:hover { background-color: #14416f; border-radius: 5px; }
#sidebar .nav-link.active { background-color: #14416f; font-weight: bold; }
#sidebar .nav-item .submenu { padding-left: 15px; }

/* Content */
#content { margin-left: 250px; transition: all 0.3s; padding: 20px; }

/* Iframe containers - EXACT SAME RULES AS #content */
.iframe-container,
#voucherApp,
#memoSearch,
#incidentReport,
#incidentDashboard {
    margin-left: 250px;
    transition: all 0.3s;
    padding: 20px;
}

/* Topbar */
#topbar { height: 60px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); display:flex; align-items:center; justify-content:space-between; padding:0 20px; position: sticky; top:0; z-index: 1000; transition: background 0.3s; }
body.dark-mode #topbar { background: #1e1e1e; color:#e1e1e1; }

/* Cards */
.card { border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); transition: transform 0.2s; }
.card:hover { transform: translateY(-3px); }
#headcount-card { background: linear-gradient(135deg, #1e5a96 0%, #2980b9 100%); color: #fff; text-align:center; }
#last-upload-card { background:#dc3545; color:#fff; text-align:center; }
#pending-card { background:#17a2b8; color:#fff; text-align:center; }

/* Tables */
.table { background-color: #fff; border-radius: 8px; overflow: hidden; transition: background 0.3s; }
.table-hover tbody tr:hover { background-color: #f1f5f9; }
body.dark-mode .table { background:#1e1e1e; color:#e1e1e1; }
body.dark-mode .table-hover tbody tr:hover { background-color:#2a2a2a; }

/* Filters */
form .form-control { border-radius: 5px; }

/* Buttons */
.btn-primary { background-color: #1e5a96; border-color: #1e5a96; }
.btn-danger { background-color: #dc3545; border-color: #dc3545; }
.btn-outline-secondary { border-radius: 5px; }

/* Iframes */
iframe { border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); width:100%; }

/* Logo */
.logo-container img { max-width: 220px; margin-top: 30px; }

/* Sidebar toggle */
#sidebarCollapse { cursor: pointer; font-size: 1.2rem; color: #1e5a96; }
body.dark-mode #sidebarCollapse { color: #fff; }

/* Dark Mode Toggle */
#darkModeToggle { cursor:pointer; }

/* ===========================
   Dark Mode Fixes (Patch)
=========================== */
body.dark-mode #topbar {
    background-color: #1e1e1e !important;
    color: #e1e1e1 !important;
}

body.dark-mode #topbar #sidebarCollapse {
    color: #fff !important;
}

body.dark-mode .table {
    background-color: #1e1e1e !important;
    color: #e1e1e1 !important;
    border-color: #333 !important;
}

body.dark-mode .table-hover tbody tr:hover {
    background-color: #2a2a2a !important;
}

body.dark-mode form .form-control {
    background-color: #2a2a2a !important;
    color: #e1e1e1 !important;
    border: 1px solid #555 !important;
}

body.dark-mode .submenu,
body.dark-mode .dropdown-menu {
    background-color: #1e1e1e !important;
    color: #e1e1e1 !important;
    border: 1px solid #333 !important;
}

body.dark-mode .submenu .nav-link,
body.dark-mode .dropdown-item {
    color: #e1e1e1 !important;
}

body.dark-mode .submenu .nav-link:hover,
body.dark-mode .dropdown-item:hover {
    background-color: #333 !important;
}

/* Dark mode table header & rows */
body.dark-mode .table thead {
    background-color: #2a2a2a !important;
    color: #e1e1e1 !important;
    border-bottom: 1px solid #555 !important;
}

body.dark-mode .table th {
    background-color: #2a2a2a !important;
    color: #e1e1e1 !important;
}

body.dark-mode .table td {
    background-color: #1e1e1e !important;
    color: #e1e1e1 !important;
}

body.dark-mode .table-hover tbody tr:hover {
    background-color: #333 !important;
}

body.dark-mode .dashboard-container .legend {
    color: #cfcfcf !important;  /* light gray, visible but not too harsh */
}

/* ===== Sidebar Push Layout (All Screen Sizes) ===== */

/* Default (desktop - screens wider than 1400px) */
#sidebar {
    position: fixed;
    left: 0;
    top: 0;
    height: 100vh;
    z-index: 1000;
    margin-left: 0;
}

#content {
    margin-left: 250px;
    transition: margin-left 0.3s ease;
}

/* Collapsed sidebar (when collapsed class is added on desktop) */
#sidebar.collapsed {
    margin-left: -250px;
}

#sidebar.collapsed ~ #content,
#sidebar.collapsed ~ .iframe-container,
#sidebar.collapsed ~ #voucherApp,
#sidebar.collapsed ~ #memoSearch,
#sidebar.collapsed ~ #incidentReport,
#sidebar.collapsed ~ #incidentDashboard {
    margin-left: 0;
}

/* Small screens approach using html class */
html.small-screen #sidebar {
    margin-left: -250px;
}

html.small-screen #content,
html.small-screen .iframe-container,
html.small-screen #voucherApp,
html.small-screen #memoSearch,
html.small-screen #incidentReport,
html.small-screen #incidentDashboard {
    margin-left: 0;
}

/* When user opens sidebar on small screen (removes collapsed class) */
html.small-screen #sidebar:not(.collapsed) {
    margin-left: 0;
}

html.small-screen #sidebar:not(.collapsed) ~ #content,
html.small-screen #sidebar:not(.collapsed) ~ .iframe-container,
html.small-screen #sidebar:not(.collapsed) ~ #voucherApp,
html.small-screen #sidebar:not(.collapsed) ~ #memoSearch,
html.small-screen #sidebar:not(.collapsed) ~ #incidentReport,
html.small-screen #sidebar:not(.collapsed) ~ #incidentDashboard {
    margin-left: 250px;
}

/* Fallback media query for browsers without JavaScript */
@media (max-width: 1400px) {
    #sidebar {
        margin-left: -250px;
    }

    #content,
    .iframe-container,
    #voucherApp,
    #memoSearch,
    #incidentReport,
    #incidentDashboard {
        margin-left: 0;
    }

    #sidebar:not(.collapsed) {
        margin-left: 0;
    }

    #sidebar:not(.collapsed) ~ #content,
    #sidebar:not(.collapsed) ~ .iframe-container,
    #sidebar:not(.collapsed) ~ #voucherApp,
    #sidebar:not(.collapsed) ~ #memoSearch,
    #sidebar:not(.collapsed) ~ #incidentReport,
    #sidebar:not(.collapsed) ~ #incidentDashboard {
        margin-left: 250px;
    }
}
</style>
</head>
<body>
<!-- Sidebar -->
<nav id="sidebar">
    <div class="p-3 d-flex flex-column" style="height:100%; justify-content: space-between;">
        <div>
            <h3 class="text-center mb-4">COHERE</h3>
            <ul class="nav flex-column">
                                <li class="nav-item mt-2">
                    <form method="POST">
                        <select name="dashboard_theme" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="dashboard1" <?= ($_SESSION['dashboard_theme'] ?? 'dashboard2') === 'dashboard1' ? 'selected' : '' ?>>
                                Theme 1
                            </option>
                            <option value="dashboard2" <?= ($_SESSION['dashboard_theme'] ?? 'dashboard2') === 'dashboard2' ? 'selected' : '' ?>>
                                Theme 2
                            </option>
                        </select>
                    </form>
                </li>

                <!-- Admin Menu -->
                <?php if ($_SESSION['role']==='Admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="admin_login_as.php">Login As</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_employee.php">Manage Employee</a></li>
                    <li class="nav-item"><a class="nav-link" href="https://webhook.cohere.ph/employees" target="_blank">Employee DB</a></li>
                <?php endif; ?>

                <!-- SGA Access (honey.cortes) -->
                <?php if ($_SESSION['user_email'] === 'honey.cortes@cohere.ph'): ?>
                    <li class="nav-item"><a class="nav-link" href="https://webhook.cohere.ph/employees" target="_blank">Employee DB</a></li>
                <?php endif; ?>

                <!-- Return to Admin -->
                <?php if (isset($_SESSION['original_admin'])): ?>
                    <li class="nav-item mt-2">
                        <a class="nav-link text-success fw-bold" href="switch_back.php">Return to Admin</a>
                    </li>
                <?php endif; ?>

                <?php if (in_array($_SESSION['role'], ['Manager', 'Director'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="https://webhook.cohere.ph/employees" target="_blank">Employee Database</a>
                    </li>
                <?php endif; ?>

                <!-- Quiz -->
                <?php if (
                    in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
                    ($_SESSION['is_supervisor'] ?? false) ||
                    ($_SESSION['is_qa'] ?? false)
                ): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="toggleSubmenu('quizSub')">Quiz ▼</a>
                        <ul class="submenu list-unstyled" id="quizSub" style="display:none;">
                            <li><a class="nav-link" href="quiz/pageresult.php" target="_blank">Quiz Results</a></li>
                            <?php if (in_array($_SESSION['role'], ['Admin','Manager','Director','SOM Approver']) || ($_SESSION['is_qa'] ?? false)): ?>
                                <li><a class="nav-link" href="/quiz/questions.php">Quiz Admin</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Tools -->
                <?php 
                    $allowed_rta_emails = ['frances.jackson@cohere.ph','june.edano@cohere.ph','jesus.berongoy@cohere.ph'];
                    $allowed_sga_emails = ['anamarie.munez@cohere.ph','honey.cortes@cohere.ph'];
                    $is_rta = in_array($_SESSION['user_email'],$allowed_rta_emails);
                    $is_sga = in_array($_SESSION['user_email'],$allowed_sga_emails);
                    $overhead_groups = ['Finest','RTA','QA','IT','TL','Trainer','BO TL'];
                    $is_overhead = isset($_SESSION['group_name']) && in_array($_SESSION['group_name'],$overhead_groups);
                    $has_tools_access = in_array($_SESSION['role'], ['Admin','Manager','Director','SOM Approver']) || $is_rta || $is_overhead || $is_sga;
                ?>
                <?php if ($has_tools_access): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="toggleSubmenu('toolsSub')">🛠 Tools ▼</a>
                        <ul class="submenu list-unstyled" id="toolsSub" style="display:none;">
                            <?php if (in_array($_SESSION['role'], ['Admin','Manager','Director','SOM Approver']) || ($_SESSION['is_supervisor'] ?? false)): ?>
                                <li><a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('memoSearch')">Memo Search</a></li>
                            <?php endif; ?>
                            <li><a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('incidentReport')">File Incident Report</a></li>
                            <?php if (in_array($_SESSION['role'], ['Admin','Manager','Director']) || $is_overhead || $is_sga): ?>
                                <li><a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('incidentDashboard')">Incident Dashboard</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- E-Vouchers -->
                <?php /* TEMPORARILY HIDDEN
                if (in_array($_SESSION['role'], ['Admin','Manager','Director','SOM Approver','Employee'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="toggleSubmenu('voucherSub')">E-Vouchers ▼</a>
                        <ul class="submenu list-unstyled" id="voucherSub" style="display:none;">
                            <li><a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('voucherApp')">Open E-Vouchers</a></li>
                        </ul>
                    </li>
                <?php endif; */ ?>

                <!-- OT Tracker -->
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="toggleSubmenu('otSub')">OT Tracker ▼</a>
                    <ul class="submenu list-unstyled" id="otSub" style="display:none;">
                        <li><a class="nav-link" href="ot/index.php" target="_blank">Open OT Tracker</a></li>
                    </ul>
                </li>

                <!-- File Requests -->
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="toggleSubmenu('fileSub')">File Requests ▼</a>
                    <ul class="submenu list-unstyled" id="fileSub" style="display:none;">
                        <li><a class="nav-link" href="ot/submit_ticket.php">File RDW/OT</a></li>
                        <li><a class="nav-link" href="submit_cws.php">File CWS</a></li>
                        <li><a class="nav-link" href="submit_fts.php">File FTS</a></li>
                    </ul>
                </li>

                <!-- Coaching -->
                <?php if (
                    in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
                    ($_SESSION['is_supervisor'] ?? false) ||
                    ($_SESSION['user_group'] ?? '') === 'QA'
                ): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="coaching/index.php">Coaching</a>
                    </li>
                <?php endif; ?>

                <!-- Approvals -->
                <?php if (in_array($_SESSION['role'], ['Admin','Manager','Director'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="toggleSubmenu('approvalsSub')">Approvals ▼</a>
                        <ul class="submenu list-unstyled" id="approvalsSub" style="display:none;">
                            <li><a class="nav-link" href="approve_rdwork.php">Approve RDW</a></li>
                            <li><a class="nav-link" href="approve_cws.php">Approve CWS</a></li>
                            <li><a class="nav-link" href="approve_fts.php">Approve FTS</a></li>
                            <li><a class="nav-link" href="approve_ot.php">Approve OT</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Display Requests -->
                <?php if (in_array($_SESSION['role'], ['Admin','Manager','Director'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="toggleSubmenu('displaySub')">Display Requests ▼</a>
                        <ul class="submenu list-unstyled" id="displaySub" style="display:none;">
                            <li><a class="nav-link" href="display_rdwork.php">Show RDW Requests</a></li>
                            <li><a class="nav-link" href="display_cws.php">Show CWS Requests</a></li>
                            <li><a class="nav-link" href="display_fts.php">Show FTS Requests</a></li>
                            <li><a class="nav-link" href="display_ot.php">Show OT Requests</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Logout -->
                <li class="nav-item mt-3"><a class="nav-link btn-danger" href="logout.php">Logout</a></li>

                <!-- Dark Mode -->
                <li class="nav-item mt-3"><span class="nav-link" id="darkModeToggle">🌙 Dark Mode</span></li>

            </ul>
        </div>

        <!-- Current Headcount Card (Bottom of Sidebar) -->
        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
        <div style="margin-top: 20px;">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center" 
                    style="background: linear-gradient(135deg, #daa909ff 0%, #056eb4ff 100%);
                            color: #ffffff; border-radius: 10px; padding: 12px;">
                    <h6 class="mb-1" style="font-size: 0.9em; color: #ffffff;">Current Headcount</h6>
                    <small id="sidebar-clock" style="opacity: 0.8; display: block; margin-bottom: 8px; font-size: 0.65em; color:#ffffff;"></small>
                    
                    <?php
                    $conn_headcount = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
                    mysqli_query($conn_headcount, "SET time_zone = '+08:00'");
                    $countQuery = "
                        SELECT COUNT(DISTINCT personid) as people_on_shift
                        FROM dailytimerecordsfiltered d1
                        WHERE type = 'in'
                        AND date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
                        AND NOT EXISTS (
                            SELECT 1 
                            FROM dailytimerecordsfiltered d2 
                            WHERE d2.personid = d1.personid 
                            AND d2.date > d1.date
                            AND d2.date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
                        )
                    ";
                    $result = mysqli_query($conn_headcount, $countQuery);
                    $row = mysqli_fetch_assoc($result);
                    $headcount = $row['people_on_shift'];
                    mysqli_close($conn_headcount);
                    ?>
                    
                    <div style="font-size: 2.0em; font-weight: bold; margin: 6px 0; color: #ffffff;"><?= $headcount ?></div>
                    <div style="font-size: 0.65em; margin-bottom: 6px; color:#ffffff;">On Shift</div>
                    
                    <a href="headcount.php" class="btn btn-light btn-sm" target="_blank" 
                    style="font-size: 0.65em; padding: 3px 10px;">Details →</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</nav>

<!-- Main Content -->
<div id="content">
    <!-- Topbar -->
    <div id="topbar" class="d-flex justify-content-between align-items-center px-3 py-2 shadow-sm bg-light">
        <div>
            <span id="sidebarCollapse" style="cursor:pointer; font-size:1.5em;">&#9776;</span>
        </div>
        <div class="user-info" style="font-size:0.9em;">
            <?= htmlspecialchars($user['fname'].' '.$user['lname'], ENT_QUOTES) ?> 
            (<?= htmlspecialchars($_SESSION['role'], ENT_QUOTES) ?>) | 
            <span id="live-clock"></span>
        </div>
    </div>

    <!-- Logo -->
    <div class="logo-container text-center my-4">
        <img src="https://cohere.ph/img/cohere-logo.jpg" 
             alt="Cohere Logo" 
             class="img-fluid" 
             style="max-width: 300px;">
    </div>

    <!-- Date Filter Form -->
    <div class="dashboard-container mb-4" style="max-width: 700px; margin: 0 auto;">
        <form method="GET" class="d-flex flex-wrap justify-content-center align-items-end gap-3">
            <div>
                <label for="start_date" class="form-label"><strong>Start Date:</strong></label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate, ENT_QUOTES) ?>">
            </div>
            <div>
                <label for="end_date" class="form-label"><strong>End Date:</strong></label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate, ENT_QUOTES) ?>">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>
        <div class="legend mt-2 text-center text-muted" style="font-size: 0.9em;">
        Cut-off period: Every 23rd–7th and 8th–22nd of the month
        </div>
    </div>

    <!-- Time Records Table -->
    <div class="table-responsive mb-4 px-3">
        <h4 class="mb-3">Raw Time Records</h4>
        <table class="table table-bordered table-hover table-striped align-middle">
            <thead class="table-light">
                <tr>
                    <th>Employee ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($finalRecords)): ?>
                    <tr><td colspan="6" class="text-center">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($finalRecords as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['EmployeeID'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($record['FirstName'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($record['LastName'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($record['Day'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($record['Time'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($record['Type'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Iframes (voucher, memo, incident) -->
<?php
$iframeViews = ['voucherApp', 'memoSearch', 'incidentReport', 'incidentDashboard'];
foreach($iframeViews as $view):
?>
<div id="<?= $view ?>" class="iframe-container" style="display:none;">
    <div class="text-end mt-3">
        <button onclick="hideIframe()" class="btn btn-outline-secondary btn-sm">← Back</button>
    </div>
    <iframe src="<?= ($view==='voucherApp') ? 'https://vouchers.cohere.ph' : ($view==='memoSearch' ? 'https://dashboard.cohere.ph/memos/' : ($view==='incidentReport' ? 'incident_report/form.php' : 'incident_report/dashboard.php')) ?>" height="<?= ($view==='incidentReport'||$view==='incidentDashboard')?'900px':'800px' ?>"></iframe>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle - simplified, CSS handles margins automatically
document.getElementById('sidebarCollapse').addEventListener('click', function(){
    document.getElementById('sidebar').classList.toggle('collapsed');
});

// Update small-screen class on resize
window.addEventListener('resize', function() {
    if (window.innerWidth <= 1400) {
        document.documentElement.classList.add('small-screen');
    } else {
        document.documentElement.classList.remove('small-screen');
        // On large screens, ensure sidebar is visible
        document.getElementById('sidebar').classList.remove('collapsed');
    }
});

// Update small-screen class on resize
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const isSidebarOpen = !sidebar.classList.contains('collapsed');
    
    if (window.innerWidth <= 1400) {
        document.documentElement.classList.add('small-screen');
        
        // Update margins based on sidebar state
        content.style.marginLeft = isSidebarOpen ? '250px' : '0';
        
        // Update any visible iframe container
        document.querySelectorAll('.iframe-container').forEach(el => {
            if (el.style.display === 'block') {
                el.style.marginLeft = isSidebarOpen ? '250px' : '0';
            }
        });
    } else {
        document.documentElement.classList.remove('small-screen');
        
        // On large screens, remove collapsed class if it exists
        sidebar.classList.remove('collapsed');
        
        // Update margins
        content.style.marginLeft = '250px';
        
        // Update any visible iframe container
        document.querySelectorAll('.iframe-container').forEach(el => {
            if (el.style.display === 'block') {
                el.style.marginLeft = '250px';
            }
        });
    }
});

// Sidebar clock update
function updateSidebarClock(){
    const now=new Date();
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let hours=now.getHours(); const ampm=hours>=12?'PM':'AM';
    hours=hours%12||12;
    const str=`${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()} - ${hours}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')} ${ampm}`;
    const el=document.getElementById('sidebar-clock');
    if(el) el.textContent=str;
}
setInterval(updateSidebarClock,1000); updateSidebarClock();

// Submenu toggle
function toggleSubmenu(id){
    const sub=document.getElementById(id);
    sub.style.display=(sub.style.display==='none')?'block':'none';
}

// Live Clock
function updateClock(){
    const now=new Date();
    const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
    let hours=now.getHours(); const ampm=hours>=12?'PM':'AM';
    hours=hours%12||12;
    const timeStr=`${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()} - ${hours}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')} ${ampm}`;
    document.getElementById('live-clock').textContent=timeStr;
}
setInterval(updateClock,1000); updateClock();

// Iframe functions - simplified, CSS handles margins
function showIframe(id){
    // Hide main content
    document.getElementById('content').style.display = 'none';
    
    // Hide all iframe containers
    document.querySelectorAll('.iframe-container').forEach(el=>{
        el.style.display = 'none';
    });
    
    // Show the requested iframe container
    const targetContainer = document.getElementById(id);
    if(targetContainer){
        targetContainer.style.display = 'block';
        targetContainer.scrollIntoView({behavior:'smooth'});
    }
}

function hideIframe(){
    // Hide all iframe containers
    document.querySelectorAll('.iframe-container').forEach(el=>{
        el.style.display = 'none';
    });
    
    // Show main content
    document.getElementById('content').style.display = 'block';
}

// Dark Mode Toggle
document.getElementById('darkModeToggle').addEventListener('click', function(){
    document.body.classList.toggle('dark-mode');
});
</script>

</body>
</html>