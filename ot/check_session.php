<?php
session_start();
echo "<h1>Session Debug</h1>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<hr>";
echo "<h2>Employee ID Check:</h2>";

// Check all possible variations
$possible_keys = ['employeeID', 'EmployeeID', 'employee_id', 'user_id', 'id'];

foreach ($possible_keys as $key) {
    if (isset($_SESSION[$key])) {
        echo "<p style='color: green; font-weight: bold;'>✅ FOUND: \$_SESSION['$key'] = " . $_SESSION[$key] . "</p>";
    } else {
        echo "<p style='color: gray;'>❌ Not found: \$_SESSION['$key']</p>";
    }
}

echo "<hr>";
echo "<h3>All Session Keys:</h3>";
echo "<pre>";
print_r(array_keys($_SESSION));
echo "</pre>";
?>