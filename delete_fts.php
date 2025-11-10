<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true);
require_once 'db_connection.php';

if (!isset($_SESSION['role']) || !isset($_SESSION['full_name'])) {
    die("Unauthorized access.");
}

$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];
$user_role = $_SESSION['role'];
$deleted_by = $_SESSION['full_name'];

if (!in_array($user_role, $allowed_roles)) {
    die("Access denied.");
}

$fts_id = $_POST['id'] ?? null;

if (!$fts_id) {
    die("Invalid request.");
}

// Check if the FTS request exists and is not already deleted
$sql = "SELECT * FROM fts_requests WHERE id = ? AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $fts_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("FTS request not found or already deleted.");
}

// Soft delete the FTS request
$deleteSql = "UPDATE fts_requests SET deleted_at = NOW(), deleted_by = ? WHERE id = ?";
$deleteStmt = $conn->prepare($deleteSql);
$deleteStmt->bind_param("si", $deleted_by, $fts_id);
$deleteStmt->execute();

// Log deletion in fts_deletion_logs
$logSql = "INSERT INTO fts_deletion_logs (fts_id, deleted_by, deleted_at) VALUES (?, ?, NOW())";
$logStmt = $conn->prepare($logSql);
$logStmt->bind_param("is", $fts_id, $deleted_by);
$logStmt->execute();

header("Location: display_fts.php?delete_success=true");
exit();
