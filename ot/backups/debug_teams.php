<?php
require_once 'config.php';

echo "<h1>Team Data Debug</h1>";

// Check teams in Employees table
echo "<h2>1. Teams from Employees table</h2>";
$query = "SELECT DISTINCT team, COUNT(*) as count FROM Employees WHERE team IS NOT NULL AND team != '' GROUP BY team ORDER BY team";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Team</th><th>Employee Count</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['team']) . "</td><td>" . $row['count'] . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ No teams found in Employees table</p>";
}

// Check if there are team leads with supervised agents
echo "<hr><h2>2. Team Leads and Their Agents</h2>";
$tl_query = "SELECT EmployeeID, FirstName, LastName, Email, role FROM Employees WHERE role IN ('team lead', 'supervisor', 'tl') ORDER BY FirstName";
$tl_result = $conn->query($tl_query);

if ($tl_result && $tl_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Team Lead</th><th>Email</th><th>Supervised Agents</th></tr>";
    while ($tl = $tl_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($tl['FirstName'] . ' ' . $tl['LastName']) . "</td>";
        echo "<td>" . htmlspecialchars($tl['Email']) . "</td>";
        
        // Get supervised agents from supervisor_mapping
        $map_query = "SELECT COUNT(*) as count FROM supervisor_mapping WHERE supervisor_email = '" . $conn->real_escape_string($tl['Email']) . "'";
        $map_result = $conn->query($map_query);
        $count = $map_result->fetch_assoc()['count'];
        
        echo "<td>" . $count . " agents</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No team leads found</p>";
}

// Suggest team names
echo "<hr><h2>3. Suggested Solution</h2>";
echo "<p><strong>Option A:</strong> Use Team Lead names as team names</p>";
echo "<p><strong>Option B:</strong> Create custom team names (Sales, Support, Technical, etc.)</p>";
echo "<p><strong>Option C:</strong> Import teams from another system</p>";
?>