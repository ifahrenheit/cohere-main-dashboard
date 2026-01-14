<?php
require_once 'config.php';

$current_user = getCurrentUserOT();
$current_employee_id = $current_user['EmployeeID'] ?? $_SESSION['employeeID'];

echo "<h1>Supervisor Relationship Debug</h1>";
echo "<h2>Current User: " . htmlspecialchars($current_user['full_name']) . " (ID: $current_employee_id)</h2>";
echo "<h3>Role: " . htmlspecialchars($current_user['role']) . "</h3>";

// Check if this user is a supervisor
echo "<hr><h2>1. Is Team Lead/Supervisor?</h2>";
echo "isTeamLead(): " . (isTeamLead() ? "YES" : "NO") . "<br>";
echo "isManagerOrAbove(): " . (isManagerOrAbove() ? "YES" : "NO") . "<br>";

// Check supervised agents from Employees table
echo "<hr><h2>2. Agents with supervisor_id = $current_employee_id</h2>";
$query = "SELECT EmployeeID, FirstName, LastName, Email, role, supervisor_id, team 
          FROM Employees 
          WHERE supervisor_id = '$current_employee_id'";
echo "Query: " . htmlspecialchars($query) . "<br><br>";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>EmployeeID</th><th>Name</th><th>Email</th><th>Role</th><th>Team</th><th>Supervisor ID</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['EmployeeID']) . "</td>";
        echo "<td>" . htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['role']) . "</td>";
        echo "<td>" . htmlspecialchars($row['team'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['supervisor_id'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ No agents found with supervisor_id = $current_employee_id</p>";
}

// Check if there's a supervisor mapping table
echo "<hr><h2>3. Check for supervisor_mapping table</h2>";
$tables_query = "SHOW TABLES LIKE '%supervisor%'";
$tables_result = $conn->query($tables_query);
if ($tables_result && $tables_result->num_rows > 0) {
    echo "<p>✅ Found supervisor-related tables:</p><ul>";
    while ($table = $tables_result->fetch_array()) {
        echo "<li>" . htmlspecialchars($table[0]) . "</li>";
        
        // Show structure of the table
        $desc_query = "DESCRIBE " . $table[0];
        $desc_result = $conn->query($desc_query);
        echo "<table border='1' cellpadding='5' style='margin-left: 20px;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        while ($col = $desc_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
            echo "</tr>";
        }
        echo "</table><br>";
    }
    echo "</ul>";
} else {
    echo "<p>❌ No supervisor mapping table found</p>";
}

// Check session supervised_agents
echo "<hr><h2>4. Session supervised_agents data</h2>";
if (isset($_SESSION['supervised_agents'])) {
    echo "<pre>";
    print_r($_SESSION['supervised_agents']);
    echo "</pre>";
} else {
    echo "<p>❌ No supervised_agents in session</p>";
}

// Check all employees with this user as team lead/supervisor
echo "<hr><h2>5. Alternative: Check by team</h2>";
if (!empty($current_user['team'])) {
    $team_query = "SELECT EmployeeID, FirstName, LastName, team, role, supervisor_id 
                   FROM Employees 
                   WHERE team = '" . $conn->real_escape_string($current_user['team']) . "'
                   AND role NOT IN ('manager', 'director', 'admin', 'team lead', 'supervisor')";
    echo "Query: " . htmlspecialchars($team_query) . "<br><br>";
    $team_result = $conn->query($team_query);
    
    if ($team_result && $team_result->num_rows > 0) {
        echo "<p>✅ Found " . $team_result->num_rows . " agents in team: " . htmlspecialchars($current_user['team']) . "</p>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>EmployeeID</th><th>Name</th><th>Team</th><th>Role</th><th>Supervisor ID</th></tr>";
        while ($row = $team_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['EmployeeID']) . "</td>";
            echo "<td>" . htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) . "</td>";
            echo "<td>" . htmlspecialchars($row['team'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['role']) . "</td>";
            echo "<td>" . htmlspecialchars($row['supervisor_id'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No agents found in team: " . htmlspecialchars($current_user['team']) . "</p>";
    }
}

echo "<hr><h2>6. OT Tickets submitted</h2>";
$tickets_query = "SELECT COUNT(*) as count, agent_id FROM ot_tickets GROUP BY agent_id";
$tickets_result = $conn->query($tickets_query);
if ($tickets_result && $tickets_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Agent ID</th><th>Ticket Count</th></tr>";
    while ($row = $tickets_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['agent_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['count']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>