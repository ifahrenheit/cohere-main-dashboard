<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph'); // ✅ Note the leading dot!
ini_set('session.cookie_samesite', 'None');     // ✅ Required for cross-origin cookies
ini_set('session.cookie_secure', '1');          // ✅ Must be HTTPS
session_start();

if ($_SESSION['role'] !== 'Admin') {
    die("Access denied.");
}

$agent = $_POST['agent_email'];
$supervisor = $_POST['supervisor_email'];

// Remove existing mapping
$stmt = $conn->prepare("DELETE FROM supervisor_mapping WHERE agent_email = ?");
$stmt->bind_param("s", $agent);
$stmt->execute();

// Insert new mapping if supervisor selected
if (!empty($supervisor)) {
    $stmt = $conn->prepare("INSERT INTO supervisor_mapping (supervisor_email, agent_email) VALUES (?, ?)");
    $stmt->bind_param("ss", $supervisor, $agent);
    $stmt->execute();
}

header("Location: manage_users.php");
exit;
