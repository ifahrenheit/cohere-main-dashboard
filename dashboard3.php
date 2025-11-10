<?php
session_start();
session_regenerate_id(true);

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Ensure the user is logged in
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['user_email'];
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? TRIM($_GET['search']) : '';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$sql = "SELECT EmployeeID FROM Employees WHERE Email = '$email'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $employeeID = $row['EmployeeID'];
} else {
    die("User not found!");
}

$where_conditions = [];
$where_conditions[] = "e.employeeID = '$employeeID'";
if (!empty($search)) {
    $where_conditions[] = "(e.employeeID LIKE '%$search%' OR e.firstName LIKE '%$search%' OR e.lastName LIKE '%$search%')";
}
if (!empty($start_date) && strtotime($start_date)) {
    $start_date = date('Y-m-d', strtotime($start_date));
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') >= '$start_date'";
}
if (!empty($end_date) && strtotime($end_date)) {
    $end_date = date('Y-m-d', strtotime($end_date));
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') <= '$end_date'";
}

$sql = "SELECT DISTINCT e.employeeID, e.firstName, e.lastName, STR_TO_DATE(t.day, '%m/%d/%Y') AS formatted_day, DATE_FORMAT(STR_TO_DATE(t.day, '%m/%d/%Y'), '%d-%b-%y') AS day, t.time, t.type FROM Employees e JOIN Test t ON e.employeeID = t.companyid";
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql .= " ORDER BY formatted_day ASC, STR_TO_DATE(t.time, '%H:%i') ASC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COHERE Dashboard</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">COHERE Dashboard
        <div class="logout-btn">
            <a href="logout.php"><button> Logout</button></a>
        </div>
    </div>

    <div class="container">
        <div class="logo-container">
            <img src="https://cohere.ph/img/cohere-logo.jpg" alt="Cohere Logo">
        </div>
        
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Shifts Operations Manager') : ?>
            <div class="approve-btn">
                <a href="approve_fts.php"><button>Approve FTS Requests</button></a>
            </div>
        <?php endif; ?>
        
        <div class="form-container">
            <form method="GET" action="">
                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <div class="fts">
            <form action="submit_fts.php" method="POST">
                <label for="fts_date">FTS Date:</label>
                <input type="date" name="fts_date" required>
                <label for="fts_type">FTS Type:</label>
                <select name="fts_type" required>
                    <option value="IN">IN</option>
                    <option value="OUT">OUT</option>
                </select>
                <button type="submit">File FTS</button>
            </form>
        </div>

        <div class="ot-form">
            <form action="submit_ot.php" method="POST">
                <label for="ot_date">OT Date:</label>
                <input type="date" name="ot_date" required>
                <label for="ot_type">OT Type:</label>
                <select name="ot_type" required>
                    <option value="PRE">PRE</option>
                    <option value="POST">POST</option>
                </select>
                <label for="regular_rate">Regular Rate?</label>
                <select name="regular_rate" required>
                    <option value="YES">YES</option>
                    <option value="NO">NO</option>
                </select>
                <button type="submit">Submit OT Request</button>
            </form>
        </div>
    </div>
</body>
</html>
