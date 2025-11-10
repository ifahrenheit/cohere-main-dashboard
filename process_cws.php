<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

if (!isset($_SESSION['employeeID'])) {
    die("Session Error: Employee ID missing!");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cws_id = $_POST['cws_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (empty($cws_id) || empty($action)) {
        die("Error: Missing required parameters.");
    }

    if (!in_array($action, ['approve', 'reject'])) {
        die("Error: Invalid action.");
    }

    // Status
    $status = ($action === 'approve') ? 'Approved' : 'Rejected';
    $decision_at = date('Y-m-d H:i:s');

    // Get approver details (with SOM)
    $approver_id = $_SESSION['employeeID'];
    $stmt = $conn->prepare("SELECT FirstName, LastName, SOM FROM Employees WHERE EmployeeID = ?");
    $stmt->bind_param("s", $approver_id);
    $stmt->execute();
    $stmt->bind_result($fname, $lname, $som);
    $stmt->fetch();
    $stmt->close();

    $approver_name = trim($fname . ' ' . $lname);

    // Update request
    $stmt = $conn->prepare("
        UPDATE cws_requests
        SET status = ?, approver_name = ?, approved_at = ?, som = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $status, $approver_name, $decision_at, $som, $cws_id);

    if ($stmt->execute()) {
        echo "OK";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>
