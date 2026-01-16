<?php
session_start();
echo "<h2>Session Debug Info</h2>";
echo "<pre>";
echo "Role: " . ($_SESSION['role'] ?? 'not set') . "\n";
echo "Email: " . ($_SESSION['user_email'] ?? 'not set') . "\n";
echo "Is Supervisor: " . (($_SESSION['is_supervisor'] ?? false) ? 'Yes' : 'No') . "\n";
echo "User Group: " . ($_SESSION['user_group'] ?? 'not set') . "\n";
echo "Is QA: " . (($_SESSION['is_qa'] ?? false) ? 'Yes' : 'No') . "\n";
echo "</pre>";
?>