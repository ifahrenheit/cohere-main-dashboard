<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph'); // ✅ Note the leading dot!
ini_set('session.cookie_samesite', 'None');     // ✅ Required for cross-origin cookies
ini_set('session.cookie_secure', '1');          // ✅ Must be HTTPS
session_start();


session_regenerate_id(true);

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['user_email'];

// Required for cross-subdomain session sharing
setcookie(session_name(), session_id(), [
  'domain' => '.cohere.ph',        // ✅ Key part: allows ALL subdomains
  'path' => '/',
  'secure' => true,                // ✅ Needed because you're using HTTPS
  'samesite' => 'None'             // ✅ Allow cross-site
]);

// DB connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user data
$stmt = $conn->prepare("SELECT personid, fname, lname, companyid FROM userdata WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die("User not found.");
}

$personID = $user['personid'];
$_SESSION['personid'] = $personID;

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
    ORDER BY u.companyid, u.fname, u.lname, t.date
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

<!-- HTML and Bootstrap -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COHERE Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg px-3 custom-navbar">
    <a class="navbar-brand text-blue" href="#">Dashboard</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
<!-- -->

  <div class="collapse navbar-collapse d-flex justify-content-between align-items-center" id="navbarNav">
    <!-- Left Side: Admin Tabs -->
    <ul class="navbar-nav">
        <?php if ($_SESSION['role'] === 'Admin'): ?>
            <li class="nav-item">
                <a class="nav-link text-red-600 font-semibold" href="admin_login_as.php">🔐 Admin: Login As</a>
            </li>
        <?php endif; ?>

        <?php if (isset($_SESSION['original_admin'])): ?>
            <li class="nav-item">
                <a class="nav-link text-green-600 font-semibold" href="switch_back.php">
                    🔁 Return to Admin (<?= $_SESSION['original_admin']['user_email'] ?>)
                </a>
            </li>
        <?php endif; ?>

        <?php if (
            in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
            ($_SESSION['is_supervisor'] ?? false)
        ): ?>
            <li class="nav-item">
                <a class="nav-link" href="quiz/pageresult.php" target="_blank"> Quiz Results</a>
            </li>
        <?php endif; ?>

        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver'])): ?>
            <li class="nav-item">
                <a class="nav-link" href="/quiz/questions.php">Quiz Admin</a>
            </li>
        <?php endif; ?>

        <?php if (
            in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver']) ||
            ($_SESSION['is_supervisor'] ?? false)
        ): ?>
            <li class="nav-item">
                <a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('memoSearch')">Memo Search</a>
            </li>
        <?php endif; ?>

        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="approvalDropdown" data-bs-toggle="dropdown">Approvals</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="approve_rdwork.php">Approve RDW</a></li>
                    <li><a class="dropdown-item" href="approve_cws.php">Approve CWS</a></li>
                    <li><a class="dropdown-item" href="approve_fts.php">Approve FTS</a></li>
                    <li><a class="dropdown-item" href="approve_ot.php">Approve OT</a></li>
                </ul>
            </li>
        <?php endif; ?>
    </ul>

    <!-- Right Side: Employee Tabs -->
    <ul class="navbar-nav">
        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director', 'SOM Approver', 'Employee'])): ?>
            <li class="nav-item">
                <a class="nav-link" href="#" onclick="event.preventDefault(); showIframe('voucherApp')">E-Vouchers</a>
            </li>
        <?php endif; ?>

        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="fileDropdown" data-bs-toggle="dropdown">File Requests</a>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="submit_rdwork.php">File RDW</a></li>
                <li><a class="dropdown-item" href="submit_cws.php">File CWS</a></li>
                <li><a class="dropdown-item" href="submit_ot.php">File OT</a></li>
                <li><a class="dropdown-item" href="submit_fts.php">File FTS</a></li>
            </ul>
        </li>

        <?php if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="displayDropdown" data-bs-toggle="dropdown">Display Requests</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="display_ot.php">OT Requests</a></li>
                    <li><a class="dropdown-item" href="display_cws.php">CWS Requests</a></li>
                    <li><a class="dropdown-item" href="display_fts.php">FTS Requests</a></li>
                    <li><a class="dropdown-item" href="display_rdwork.php">RDW Requests</a></li>
                </ul>
            </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="logout.php" class="btn btn-logout ms-3">Logout</a>
        </li>
    </ul>
</div>


<!-- -->

</nav>

<!-- Main Dashboard -->
<div class="container" id="mainDashboard">
    <div class="logo-container text-center mt-4">
        <img src="https://cohere.ph/img/cohere-logo.jpg" alt="Cohere Logo" class="img-fluid">
    </div>

    <div class="last-uploaded-container text-center my-3">
        <strong>Last Uploaded Date:</strong> <?= htmlspecialchars($lastUploadedDate) ?>
    </div>

    <div class="dashboard-container mb-4">
        <form method="GET" class="d-flex gap-3 flex-wrap">
            <div>
                <label for="start_date"><strong>Start Date:</strong></label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div>
                <label for="end_date"><strong>End Date:</strong></label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="align-self-end">
                <button type="submit" class="btn btn-danger">Search</button>
            </div>
        </form>
        <div class="legend mt-3">
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
                        <td><?= htmlspecialchars($record['EmployeeID']) ?></td>
                        <td><?= htmlspecialchars($record['FirstName']) ?></td>
                        <td><?= htmlspecialchars($record['LastName']) ?></td>
                        <td><?= htmlspecialchars($record['Day']) ?></td>
                        <td><?= htmlspecialchars($record['Time']) ?></td>
                        <td><?= htmlspecialchars($record['Type']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- E-Voucher iFrame View -->
 <!-- Voucher App iFrame View -->
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

<!-- JavaScript -->
<script>
function showIframe(id) {
    // Hide main dashboard
    document.getElementById('mainDashboard').style.display = 'none';

    // Hide all iframes first
    document.querySelectorAll('.container').forEach(el => {
        if (el.id !== 'mainDashboard') el.style.display = 'none';
    });

    // Show the requested iframe
    const target = document.getElementById(id);
    if (target) {
        target.style.display = 'block';
        target.scrollIntoView({ behavior: 'smooth' });
    }
}

function hideIframe() {
    // Hide all iframe containers
    document.querySelectorAll('.container').forEach(el => {
        if (el.id !== 'mainDashboard') el.style.display = 'none';
    });

    // Show main dashboard again
    document.getElementById('mainDashboard').style.display = 'block';
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
