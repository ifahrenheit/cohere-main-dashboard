<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
include 'db_connection.php';

// Allowed roles
$fullAccessRoles = ['Director', 'SOM Approver', 'Manager', 'Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $fullAccessRoles)) {
    echo "Access Denied!";
    exit;
}

// Ensure approver name exists
if (!isset($_SESSION['full_name'])) {
    echo "Error: Approver name missing.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['id'], $_POST['action'])) {
    $id = intval($_POST['id']);
    $action = $_POST['action'];
    $approver_name = $_SESSION['full_name'];

    // Validate action
    if (!in_array($action, ['approve', 'reject'])) {
        echo "Invalid action.";
        exit;
    }

    $status = ($action === 'approve') ? 'Approved' : 'Rejected';

    // Fetch request details
    $query = "SELECT * FROM fts_requests WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        echo "Request not found.";
        exit;
    }

    // Update record
    $sql = "UPDATE fts_requests 
        SET status = ?, approved_at = NOW(), approver_name = ? 
        WHERE id = ? AND status = 'Pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $status, $approver_name, $id);

    if ($stmt->execute()) {
        echo "OK"; // ✅ Respond immediately to AJAX

        // -------- Background Email (doesn’t block AJAX) --------
        ignore_user_abort(true);
        fastcgi_finish_request(); // ✅ immediately ends response to browser if PHP-FPM

        $to = "andrewvincentt@gmail.com";
        $subject = "FTS Request Update";
        $message = "
            <html>
            <head><title>FTS Request Update</title></head>
            <body>
                <p><strong>Employee ID:</strong> {$request['employeeID']}</p>
                <p><strong>FTS Date:</strong> {$request['fts_date']}</p>
                <p><strong>FTS Time:</strong> {$request['fts_time']}</p>
                <p><strong>FTS Type:</strong> {$request['fts_type']}</p>
                <p><strong>Status:</strong> {$status}</p>
                <p><strong>Approved/Rejected by:</strong> {$approver_name}</p>
            </body>
            </html>
        ";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: no-reply@cohere.ph\r\n";

        @mail($to, $subject, $message, $headers); // run after response
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
} else {
    echo "Invalid request.";
}
