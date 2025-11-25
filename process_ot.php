<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

header("Content-Type: text/plain");

if (!isset($_SESSION['employeeID']) || empty($_SESSION['employeeID'])) {
    exit("Error: Not logged in");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    exit("Error: Invalid request");
}

$ot_id      = $_POST['id'] ?? '';
$action     = $_POST['action'] ?? '';
$start_time = $_POST['start_time'] ?? null;
$end_time   = $_POST['end_time'] ?? null;

if (empty($ot_id) || empty($action)) {
    exit("Error: Missing parameters");
}

if (!in_array($action, ['approve', 'reject'])) {
    exit("Error: Invalid action");
}

// Approver details
$approver_id = $_SESSION['employeeID'];
$stmt = $conn->prepare("SELECT FirstName, LastName FROM Employees WHERE EmployeeID = ?");
$stmt->bind_param("s", $approver_id);
$stmt->execute();
$stmt->bind_result($fname, $lname);
$stmt->fetch();
$stmt->close();
$approver_name = trim("$fname $lname");

$status      = ($action === 'approve') ? 'Approved' : 'Rejected';
$decision_at = date('Y-m-d H:i:s');
$approved_at = ($action === 'approve') ? $decision_at : null;

if ($action === "approve") {
    // Approve OT: remove deleted_at filter
    $stmt = $conn->prepare("
        UPDATE ot_requests
        SET status = ?, 
            approver_name = ?, 
            approver = ?, 
            approved_at = ?, 
            decision_at = ?, 
            timestamp = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param(
        "sssssi", 
        $status, 
        $approver_name, 
        $approver_id,
        $approved_at, 
        $decision_at, 
        $ot_id
    );

} else {
    // Reject OT
    $stmt = $conn->prepare("
        UPDATE ot_requests
        SET status = ?, 
            approver_name = ?, 
            approver = ?, 
            approved_at = NULL, 
            decision_at = ?, 
            deleted_at = NOW(), 
            deleted_by = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "sssssi", 
        $status,
        $approver_name,
        $approver_id,
        $decision_at,
        $approver_id,
        $ot_id
    );
}

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
