<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true);

// Define roles with access to approval functions
$fullAccessRoles = ['Manager', 'Director', 'Admin'];

// Check if user is logged in and has the right role
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $fullAccessRoles)) {
    die("Access Denied! You do not have permission to approve requests.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rd_id'], $_POST['action'])) {
    $rd_id = $_POST['rd_id'];
    $action = $_POST['action'];
    
    // Determine the new status based on the action provided
    if ($action === 'approve') {
        $new_status = 'Approved';
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';
    } else {
        die("Invalid action.");
    }
    
    // Get the approver's full name. If the session variable is not set, query the Employees table.
    if (isset($_SESSION['employee_name']) && !empty($_SESSION['employee_name'])) {
        $approver_name = $_SESSION['employee_name'];
    } else {
        // Retrieve the name from the Employees table using the session's employee_id
        $employee_id = $_SESSION['employee_id'];
        $stmt = $conn->prepare("SELECT FirstName, LastName FROM Employees WHERE EmployeeID = ?");
        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $stmt->bind_result($firstName, $lastName);
        if ($stmt->fetch()) {
            $approver_name = $firstName . " " . $lastName;
        } else {
            $approver_name = $employee_id; // Fallback if the name isn't found
        }
        $stmt->close();
    }
    
    // Update the RD Work request record: set status, approver_name, and approved_at (current timestamp)
    $stmt = $conn->prepare("UPDATE rd_requests SET status = ?, approver_name = ?, approved_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $new_status, $approver_name, $rd_id);
    
    if ($stmt->execute()) {
    echo "SUCCESS";
} else {
    echo "Error updating record: " . $stmt->error;
}

    $stmt->close();
} else {
    die("Invalid request.");
}

$conn->close();
?>

