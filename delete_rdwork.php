<?php
session_start();
require_once 'db_connection.php';

$allowed_roles = ['admin', 'manager', 'director', 'som approver'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowed_roles)) {
    die('Unauthorized access');
}

if (empty($_POST['id'])) {
    die('Invalid request');
}

$id = (int)$_POST['id'];
$deleted_by = $_SESSION['username'] ?? $_SESSION['role'] ?? 'system';

/**
 * OPTIONAL SAFETY:
 * Prevent deleting approved RD work
 */
$stmt = $conn->prepare("
    UPDATE rd_requests
    SET deleted_at = NOW(),
        deleted_by = ?
    WHERE id = ?
    AND status != 'Approved'
    AND deleted_at IS NULL
    ");
$stmt->bind_param("si", $deleted_by, $id);
$stmt->execute();

$stmt->close();
$conn->close();

header("Location: display_rdwork.php");
exit;
