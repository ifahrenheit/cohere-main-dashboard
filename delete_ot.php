<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
require_once 'db_connection.php'; // Ensure this connects to your database

// Only allow logged-in users with proper roles
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];
$user_role = $_SESSION['role'] ?? '';
$deleted_by = $_SESSION['full_name'] ?? '';

if (!$user_role || !in_array($user_role, $allowed_roles)) {
    die("Access denied.");
}

// Get OT request ID from POST
$ot_request_id = $_POST['id'] ?? null;

if (!$ot_request_id) {
    die("Invalid request.");
}

// Check if the OT request exists and is not already deleted
$sql = "SELECT * FROM ot_requests WHERE id = ? AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $ot_request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("OT request not found or already deleted.");
}

// Soft delete: mark as deleted
$deleteSql = "UPDATE ot_requests SET deleted_at = NOW(), deleted_by = ? WHERE id = ?";
$deleteStmt = $conn->prepare($deleteSql);
$deleteStmt->bind_param("si", $deleted_by, $ot_request_id);
$deleteStmt->execute();

// Optional: Log deletion for audit purposes
$logSql = "INSERT INTO ot_deletion_logs (ot_request_id, deleted_by, deleted_at) VALUES (?, ?, NOW())";
$logStmt = $conn->prepare($logSql);
$logStmt->bind_param("is", $ot_request_id, $deleted_by);
$logStmt->execute();

// Redirect back to OT display page with success message
header("Location: display_ot.php?delete_success=1");
exit();
