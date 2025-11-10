<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

require_once 'db_connection.php'; // DB connection

// Ensure session has required info
if (!isset($_SESSION['role']) || !isset($_SESSION['full_name'])) {
    die("Unauthorized access.");
}

$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];
$user_role = $_SESSION['role'];
$deleted_by = $_SESSION['full_name'];

if (!in_array($user_role, $allowed_roles)) {
    die("Access denied.");
}

$cws_request_id = $_POST['id'] ?? null;

if (!$cws_request_id) {
    die("Invalid request.");
}

// Check if the CWS request exists and is not already deleted
$sql = "SELECT * FROM cws_requests WHERE id = ? AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cws_request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("CWS request not found or already deleted.");
}

// Soft delete the CWS request
$deleteSql = "UPDATE cws_requests SET deleted_at = NOW(), deleted_by = ? WHERE id = ?";
$deleteStmt = $conn->prepare($deleteSql);
$deleteStmt->bind_param("si", $deleted_by, $cws_request_id);
$deleteStmt->execute();

// Log deletion in cws_deletion_logs (make sure this table exists!)
$logSql = "INSERT INTO cws_deletion_logs (cws_request_id, deleted_by, deleted_at) VALUES (?, ?, NOW())";
$logStmt = $conn->prepare($logSql);
$logStmt->bind_param("is", $cws_request_id, $deleted_by);
$logStmt->execute();

// Redirect back to display page
header("Location: display_cws.php?delete_success=true");
exit();
?>
