
<?php
// Database connection and setup
ini_set('display_errors', 1);
error_reporting(E_ALL);

$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to fetch data from NovSched table
$sql = "SELECT DISTINCT tl, id_number, agent_list, shift_date, shift
        FROM DecSched
        WHERE shift IS NOT NULL AND shift != ''
        AND agent_list IS NOT NULL AND agent_list != ''
        ORDER BY agent_list, shift_date";

$result = $conn->query($sql);

// Start HTML structure
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>DecSched Data</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
<h1>Schedule Data for December 2024</h1>";

if ($result->num_rows > 0) {
    echo "<table>
            <tr>
                <th>Team Leader</th>
                <th>ID Number</th>
                <th>Agent List</th>
                <th>Shift Date</th>
                <th>Shift</th>
            </tr>";

    // Output data of each row
    while($row = $result->fetch_assoc()) {
        // Check if the date is valid
        $shiftDate = $row['shift_date'];
        $displayDate = ($shiftDate && $shiftDate !== "1970-01-01") ? htmlspecialchars($shiftDate) : "";

        echo "<tr>
                <td>" . htmlspecialchars($row['tl']) . "</td>
                <td>" . htmlspecialchars($row['id_number']) . "</td>
                <td>" . htmlspecialchars($row['agent_list']) . "</td>
                <td>" . $displayDate . "</td>
                <td>" . htmlspecialchars($row['shift']) . "</td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<p>No data found in the DecSched table.</p>";
}

// Close the connection
$conn->close();

echo "</body>
</html>";
