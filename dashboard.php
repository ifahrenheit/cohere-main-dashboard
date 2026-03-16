<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// Must be before ANY output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_theme'])) {
    $_SESSION['dashboard_theme'] = $_POST['dashboard_theme'];

    if ($_POST['dashboard_theme'] === 'dashboard2') {
        header("Location: dashboard2.php");
        exit();
    } else {
        header("Location: dashboard.php");
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


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COHERE Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?php echo htmlspecialchars($bgClass, ENT_QUOTES, 'UTF-8'); ?>">

<nav class="navbar navbar-expand-lg px-3 custom-navbar">
    <a class="navbar-brand text-blue" href="#">DASHBOARD</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse d-flex justify-content-between align-items-center" id="navbarNav">
        <!-- Left Side: Admin/Management Features -->
        <ul class="navbar-nav">
            <!-- Admin Only Dropdown -->
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-danger fw-bold" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Admin Panel
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="admin_login_as.php">🔐 Login As</a></li>
                        <li><a class="dropdown-item" href="manage_employee.php">➕ Manage Employee</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="https://webhook.cohere.ph/employees" target="_blank">👥 Employee Database</a></li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- Return to Admin -->
            <?php if (isset($_SESSION['original_admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link text-success fw-bold" href="switch_back.php">🔁 Return to Admin</a>
                </li>
            <?php endif; ?>

            <!-- Employee Database for Manager/Director -->
            <?php if (in_array($_SESSION['role'], ['Manager', 'Director']) || $_SESSION['user_email'] === 'honey.cortes@cohere.ph'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="https://webhook.cohere.ph/employees" target="_blank">👥 Employee Database</a>
                </li>
            <?php endif; ?>

            <!-- Quiz -->
            <?php if (
                in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
                ($_SESSION['is_supervisor'] ?? false) ||
                ($_SESSION['is_qa'] ?? false)
            ): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="quizDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Quiz</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="quiz/pageresult.php" target="_blank">Quiz Results</a></li>
                        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) || ($_SESSION['is_qa'] ?? false)): ?>
                            <li><a class="dropdown-item" href="/quiz/questions.php">Quiz Admin</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- Tools Dropdown -->
            <?php 
            $allowed_rta_emails = [
                'frances.jackson@cohere.ph',
                'june.edano@cohere.ph',
                'jesus.berongoy@cohere.ph',
            ];

            $allowed_sga_emails = [
                'anamarie.munez@cohere.ph',
                'honey.cortes@cohere.ph',
            ];

            $is_rta = in_array($_SESSION['user_email'], $allowed_rta_emails);
            $is_sga = in_array($_SESSION['user_email'], $allowed_sga_emails);

            // Define overhead groups that should have access
            $overhead_groups = [
                'Finest',
                'RTA',
                'QA',
                'IT',
                'TL',
                'Trainer',
                'BO TL'
            ];

            // Check if user's group is in overhead groups
            $is_overhead = false;
            if (isset($_SESSION['group_name']) && in_array($_SESSION['group_name'], $overhead_groups)) {
                $is_overhead = true;
            }

            $has_tools_access = (
                in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
                in_array($_SESSION['user_email'], $allowed_rta_emails) ||
                $is_overhead ||
                $is_sga
            );

            if ($has_tools_access): 
            ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="toolsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Tools</a>
                    <ul class="dropdown-menu">
                        <?php if (
                            in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
                            ($_SESSION['is_supervisor'] ?? false)
                        ): ?>
                            <li><a class="dropdown-item" href="#" onclick="event.preventDefault(); showIframe('memoSearch')">Memo Search</a></li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        
                        <li><a class="dropdown-item" href="#" onclick="event.preventDefault(); showIframe('incidentReport')">File Incident Report</a></li>
                        
                        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director']) || $is_overhead || $is_sga): ?>
                            <li><a class="dropdown-item" href="#" onclick="event.preventDefault(); showIframe('incidentDashboard')">Incident Dashboard</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

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

            <!-- Supervisor Break Logs - Admin, Manager, Director, RTA -->
            <?php 
            $has_supervisor_access = (
                in_array($_SESSION['role'], ['Admin', 'Manager', 'Director']) ||
                ($_SESSION['user_group'] ?? null) === 'RTA'
            );

            if ($has_supervisor_access): 
            ?>
                <li class="nav-item">
                    <a class="nav-link" href="supervisor-break-logs.php">Break Logs</a>
                </li>
            <?php endif; ?>

            <!-- Approvals Dropdown -->
            <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="approvalDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Approvals</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="approve_rdwork.php">Approve RDW</a></li>
                        <li><a class="dropdown-item" href="approve_cws.php">Approve CWS</a></li>
                        <li><a class="dropdown-item" href="approve_fts.php">Approve FTS</a></li>
                        <li><a class="dropdown-item" href="approve_ot.php">Approve OT</a></li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- Display Requests -->
            <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="displayDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Display Requests</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="display_rdwork.php">Show RDW Requests</a></li>
                        <li><a class="dropdown-item" href="display_cws.php">Show CWS Requests</a></li>
                        <li><a class="dropdown-item" href="display_fts.php">Show FTS Requests</a></li>
                        <li><a class="dropdown-item" href="display_ot.php">Show OT Requests</a></li>
                    </ul>
                </li>
            <?php endif; ?>
        </ul>

        <!-- Right Side: Employee Features -->
        <ul class="navbar-nav">
            <!-- E-Vouchers -->
            <?php /* if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver', 'Employee'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('voucherApp')">E-Vouchers</a>
                </li>
            <?php endif; */?>

            <!-- File Requests Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="fileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">File Requests</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="ot/submit_ticket.php">File RDW</a></li>
                    <li><a class="dropdown-item" href="submit_cws.php">File CWS</a></li>
                    <li><a class="dropdown-item" href="submit_fts.php">File FTS</a></li>
                    <li><a class="dropdown-item" href="ot/submit_ticket.php">File OT</a></li>
                </ul>
            </li>

            <!-- OT Tickets Tracker -->
            <li class="nav-item">
                <a class="nav-link" href="ot/index.php" target="_blank">OT Tracker</a>
            </li>

            <!-- Break Tracker - Numa & Arctic Only -->
<?php
// Check user's account type for Break Tracker access
$break_tracker_access = false;

// Try multiple session variables
$user_account = $_SESSION['account_type'] ?? $_SESSION['account'] ?? null;

// If not found, try to extract from user_group (e.g., "Numa CS" → "Numa")
if (!$user_account && isset($_SESSION['user_group'])) {
    $user_group = $_SESSION['user_group'];
    
    if (stripos($user_group, 'Numa') !== false) {
        $user_account = 'Numa';
    } elseif (stripos($user_group, 'Arctic') !== false) {
        $user_account = 'Arctic';
    } elseif (stripos($user_group, 'GYG') !== false) {
        $user_account = 'GYG';
    }
}

// If still not found, query database as last resort
if (!$user_account && isset($_SESSION['user_email'])) {
    $stmt = $conn->prepare("SELECT account FROM gsheet_employees WHERE email = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param("s", $_SESSION['user_email']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_account = $row['account'];
    }
    $stmt->close();
}

$allowed_accounts = [
    'Arctic',
    'Numa',
    // 'GYG',  // Uncomment to enable GYG access
];

// Also allow admins/managers/directors to access
if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])) {
    $break_tracker_access = true;
} else if ($user_account && in_array($user_account, $allowed_accounts)) {
    $break_tracker_access = true;
}

if ($break_tracker_access): 
?>
    <li class="nav-item">
        <a class="nav-link" href="break-log.php">⏱ Break Tracker</a>
    </li>
<?php endif; ?>

            <!-- Logout Button -->
            <li class="nav-item">
                <a href="logout.php" class="btn btn-logout">Logout</a>
            </li>
        </ul>
    </div>
</nav>

<br>
    <div style="margin-bottom: 10px; max-width: 180px;">
        <form method="POST">
            <select name="dashboard_theme"
                    class="form-select form-select-sm"
                    onchange="this.form.submit()">
                <option value="dashboard1" selected>Theme 1</option>
                <option value="dashboard2">Theme 2</option>
            </select>
        </form>
    </div>

<!-- Main Dashboard -->
<div class="container" id="mainDashboard" style="position: relative;">
    <!-- Headcount Card - Upper Right -->
    <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
    <div style="position: absolute; top: 0px; right: 0; z-index: 100; width: 200px;">
        <div class="card shadow-sm border-0">
            <div class="card-body text-center" style="background: linear-gradient(135deg, #1e5a96 0%, #2980b9 100%); color: white; border-radius: 10px; padding: 12px;">
                <h6 class="mb-1" style="font-size: 0.9em;">Current Headcount</h6>
                <small id="live-clock" style="opacity: 0.8; display: block; margin-bottom: 8px; font-size: 0.65em;"></small> <!-- Smaller clock -->
                
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
                
                <div style="font-size: 2.0em; font-weight: bold; margin: 6px 0;"><?= $headcount ?></div> <!-- Smaller number -->
                <div style="font-size: 0.65em; margin-bottom: 6px;">On Shift</div> <!-- Shorter text -->
                
                <a href="headcount.php" class="btn btn-light btn-sm" target="_blank" style="font-size: 0.65em; padding: 3px 10px;">Details →</a> <!-- Smaller button -->
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logo - Centered -->
    <div class="logo-container text-center mt-4">
        <img src="https://cohere.ph/img/cohere-logo.jpg" alt="Cohere Logo" class="img-fluid" style="max-width: 400px;">
    </div>

    <!--
    <div class="last-uploaded-container text-center my-3">
        <strong>Last Uploaded Date:</strong> <?= htmlspecialchars($lastUploadedDate, ENT_QUOTES, 'UTF-8') ?>
    </div>
    -->

    <div class="dashboard-container mb-4 mx-auto" style="max-width: 800px;">
        <form method="GET" class="d-flex gap-3 flex-wrap justify-content-center">
            <div>
                <label for="start_date"><strong>Start Date:</strong></label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="end_date"><strong>End Date:</strong></label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="align-self-end">
                <button type="submit" class="btn btn-danger">Search</button>
            </div>
        </form>
        <div class="legend mt-3 text-center">
            📌 <strong>Cut-off period:</strong> Every 23rd–7th and 8th–22nd of the month
        </div>
    </div>

    <h3 class="mb-3">Raw Time Records</h3>
    <table class="table table-bordered table-hover">
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
                        <td><?= htmlspecialchars($record['EmployeeID'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['FirstName'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['LastName'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['Day'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['Time'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($record['Type'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- E-Voucher iFrame View -->
<div id="voucherApp" class="container" style="display: none;">
    <div class="text-end mt-3">
        <button onclick="hideIframe()" class="btn btn-outline-secondary btn-sm">← Back to Dashboard</button>
    </div>
    <iframe 
        src="https://vouchers.cohere.ph" 
        width="100%" 
        height="800px" 
        style="border: none;"
    ></iframe>
</div>

<!-- Memo Search iFrame View -->
<div id="memoSearch" class="container" style="display: none;">
    <div class="text-end mt-3">
        <button onclick="hideIframe()" class="btn btn-outline-secondary btn-sm">← Back to Dashboard</button>
    </div>
    <iframe 
        src="https://dashboard.cohere.ph/memos/" 
        width="100%" 
        height="800px" 
        style="border: none;"
    ></iframe>
</div>

<!-- Incident Report iFrame -->
<div id="incidentReport" class="container" style="display: none;">
    <div class="text-end mt-3">
        <button onclick="hideIframe()" class="btn btn-outline-secondary btn-sm">← Back to Dashboard</button>
    </div>
    <iframe 
        src="incident_report/form.php" 
        width="100%" 
        height="900px" 
        style="border: none;"
    ></iframe>
</div>

<!-- Incident Dashboard iFrame -->
<div id="incidentDashboard" class="container" style="display: none;">
    <div class="text-end mt-3">
        <button onclick="hideIframe()" class="btn btn-outline-secondary btn-sm">← Back to Dashboard</button>
    </div>
    <iframe 
        src="incident_report/dashboard.php" 
        width="100%" 
        height="900px" 
        style="border: none;"
    ></iframe>
</div>

<!-- All JavaScript at the end -->
<script>
// Live clock update
function updateClock() {
    const now = new Date();
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                  'July', 'August', 'September', 'October', 'November', 'December'];
    
    const month = months[now.getMonth()];
    const day = now.getDate();
    const year = now.getFullYear();
    
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    
    const timeString = `${month} ${day}, ${year} - ${hours}:${minutes}:${seconds} ${ampm}`;
    
    const clockElement = document.getElementById('live-clock');
    if (clockElement) {
        clockElement.textContent = timeString;
    }
}

// Update clock every second if element exists
if (document.getElementById('live-clock')) {
    updateClock();
    setInterval(updateClock, 1000);
}

// Iframe functions
function showIframe(id) {
    document.getElementById('mainDashboard').style.display = 'none';
    document.querySelectorAll('.container').forEach(el => {
        if (el.id !== 'mainDashboard') el.style.display = 'none';
    });
    const target = document.getElementById(id);
    if (target) {
        target.style.display = 'block';
        target.scrollIntoView({ behavior: 'smooth' });
    }
}

function hideIframe() {
    document.querySelectorAll('.container').forEach(el => {
        if (el.id !== 'mainDashboard') el.style.display = 'none';
    });
    document.getElementById('mainDashboard').style.display = 'block';
}
</script>

<!-- Bootstrap Bundle - Load only ONCE -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


$conn->close();
</body>
</html>