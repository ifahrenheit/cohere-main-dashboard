<?php
// Database connection (replace with your actual database credentials)
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";

try {
    // Create a PDO instance
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL query to fetch combined data from NovSched, Employees, and Test tables
    $sql = "SELECT DISTINCT
                ns.id_number,
                ns.agent_list,
                ns.shift_date,
                ns.shift,
                e.employeeID,
                e.firstName,
                e.lastName,
                t.day,
                t.time,
                t.type
            FROM
                NovSched ns
            JOIN
                Employees e ON e.employeeID = ns.id_number
            JOIN
                Test t ON e.employeeID = t.companyid
            WHERE
                ns.shift IS NOT NULL AND ns.shift != ''
                AND ns.agent_list IS NOT NULL AND ns.agent_list != ''
                AND t.day = ns.shift_date
            ORDER BY
                ns.shift_date, ns.agent_list, t.day, t.time";

    // Execute the query
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    // Fetch all results
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // If an error occurs, show the error message
    echo "Error: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance and Shift Schedule</title>
    <link rel="stylesheet" href="styles.css"> <!-- Optional for styling -->
</head>
<body>
    <h1>Employee Attendance and Shift Schedule</h1>

    <?php if ($results): ?>
        <table border="1">
            <thead>
                <tr>
                    <th>ID Number</th>
                    <th>Agent List</th>
                    <th>Shift Date</th>
                    <th>Shift</th>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Attendance Day</th>
                    <th>Attendance Time</th>
                    <th>Attendance Type</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                        <td><?php echo htmlspecialchars($row['agent_list']); ?></td>
                        <td><?php echo htmlspecialchars($row['shift_date']); ?></td>
                        <td><?php echo htmlspecialchars($row['shift']); ?></td>
                        <td><?php echo htmlspecialchars($row['employeeID']); ?></td>
                        <td><?php echo htmlspecialchars($row['firstName']) . ' ' . htmlspecialchars($row['lastName']); ?></td>
                        <td><?php echo htmlspecialchars($row['day']); ?></td>
                        <td><?php echo htmlspecialchars($row['time']); ?></td>
                        <td><?php echo htmlspecialchars($row['type']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No records found.</p>
    <?php endif; ?>

</body>
</html>

<?php
// Close the database connection
$pdo = null;
?>

