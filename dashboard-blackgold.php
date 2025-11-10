<?php
session_start();

// Handle logout
if (isset($_POST['logout'])) {
    // Destroy the session to log out the user
    session_unset();
    session_destroy();

    // Redirect to login page after logout
    header("Location: login.php");
    exit();
}

// Ensure the user is logged in
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php"); // Redirect to login if not logged in
    exit();
}

// Assuming 'email' is stored in the session
$email = $_SESSION['user_email']; // Retrieve the email from the session

// Database connection setup
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get the search term if available
$search = isset($_GET['search']) ? TRIM($_GET['search']) : '';

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Retrieve and validate input
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Get EmployeeID for the logged-in user (by email)
$sql = "SELECT EmployeeID FROM Employees WHERE Email = '$email'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // Assuming only one result will be found for email
    $row = $result->fetch_assoc();
    $employeeID = $row['EmployeeID'];
} else {
    die("User not found!");
}

$where_conditions = [];

// Build WHERE clause for filtering
$where_conditions[] = "e.employeeID = '$employeeID'"; // Only show records for the logged-in user

// Add search filter if available
if (!empty($search)) {
    $where_conditions[] = "(e.employeeID LIKE '%$search%' OR e.firstName LIKE '%$search%' OR e.lastName LIKE '%$search%')";
}
// Sanitize and validate date inputs
if (!empty($start_date) && strtotime($start_date)) {
    $start_date = date('Y-m-d', strtotime($start_date)); // Format to Y-m-d
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') >= '$start_date'";
}

if (!empty($end_date) && strtotime($end_date)) {
    $end_date = date('Y-m-d', strtotime($end_date)); // Format to Y-m-d
    $where_conditions[] = "STR_TO_DATE(t.day, '%m/%d/%Y') <= '$end_date'";
}

// Base SQL query to fetch employee data with attendance, filtering by employeeID
$sql = "SELECT DISTINCT
            e.employeeID,
            e.firstName,
            e.lastName,
            t.day,
            t.time,
            t.type
        FROM
            Employees e
        JOIN
            Test t ON e.employeeID = t.companyid";

// Modify the SQL query if there are filters
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

// Append ORDER BY and LIMIT/OFFSET
$sql .= " ORDER BY e.employeeID ASC, t.day ASC, STR_TO_DATE(t.time, '%H:%i') ASC LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);

// Output the data in a table format
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance - Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #1a1a1a;
            color: #fff;
            margin: 0;
            padding: 0;
        }

        h1 {
            text-align: center;
            color: #FFD700;
            padding: 20px;
            background-color: #333;
        }

        .container {
            width: 80%;
            margin: 0 auto;
            padding: 20px;
        }

        .form-container {
            background-color: #333;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        label {
            color: #FFD700;
            margin-right: 10px;
        }

        input[type="date"] {
            padding: 5px;
            margin: 5px 0;
            background-color: #444;
            border: 1px solid #FFD700;
            color: #fff;
            border-radius: 4px;
        }

        button {
            background-color: #FFD700;
            color: #333;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }

        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        table, th, td {
            border: 1px solid #FFD700;
        }

        th, td {
            padding: 10px;
            text-align: center;
        }

        th {
            background-color: #333;
            color: #FFD700;
        }

        tr:nth-child(even) {
            background-color: #444;
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
        }

        .pagination a {
            color: #FFD700;
            padding: 8px 16px;
            text-decoration: none;
            margin: 0 5px;
            border-radius: 4px;
        }

        .pagination a.disabled {
            color: #555;
            pointer-events: none;
        }

        .logout-btn {
            background-color: #f44336;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: block;
            margin: 30px auto;
            width: 100px;
        }

        .logout-btn:hover {
            background-color: #d32f2f;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Employee Attendance Dashboard</h1>

    <!-- Start Date and End Date Filters -->
    <div class="form-container">
        <form method="GET" action="">
            <label for="start_date">Start Date:</label>
            <input type="date" name="start_date" value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
            <label for="end_date">End Date:</label>
            <input type="date" name="end_date" value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- Table with Results -->
    <table>
        <tr>
            <th>Employee ID</th>
            <th>First Name</th>
            <th>Last Name</th>
            <th>Day</th>
            <th>Time</th>
            <th>Type</th>
        </tr>
        <?php
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "<tr>
                        <td>{$row['employeeID']}</td>
                        <td>{$row['firstName']}</td>
                        <td>{$row['lastName']}</td>
                        <td>{$row['day']}</td>
                        <td>{$row['time']}</td>
                        <td>{$row['type']}</td>
                      </tr>";
            }
        } else {
            echo "<tr><td colspan='6'>No records found.</td></tr>";
        }
        ?>
    </table>

    <!-- Pagination -->
    <div class="pagination">
        <?php
        // Pagination logic here...
        ?>
    </div>

    <!-- Logout Button -->
    <form method="POST" action="">
        <input type="submit" name="logout" value="Logout" class="logout-btn">
    </form>
</div>

</body>
</html>

