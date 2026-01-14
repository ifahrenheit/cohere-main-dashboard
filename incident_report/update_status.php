<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Check if user is logged in and has permission
if (!isset($_SESSION['user_email']) || !in_array($_SESSION['role'], ['Admin', 'Manager', 'Director'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$report_number = $input['report_number'] ?? '';
$new_status = $input['status'] ?? '';

// Validate input
if (empty($report_number) || empty($new_status)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Validate status value
$valid_statuses = ['pending', 'reviewed', 'resolved'];
if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Update status
    $stmt = $conn->prepare("UPDATE incident_reports SET status = ? WHERE report_number = ?");
    $stmt->bind_param("ss", $new_status, $report_number);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $conn->close();
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Report not found or status unchanged']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>