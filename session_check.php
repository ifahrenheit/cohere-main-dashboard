<?php
// Allow cross-subdomain session cookies
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');

session_start();
header('Content-Type: application/json');

// Block access if not logged in
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

require_once 'db_connection.php'; // Uses $conn

$email = $_SESSION['user_email'];
$employeeID = $_SESSION['employeeID'] ?? null;
$fullName = $_SESSION['full_name'] ?? '';
$role = $_SESSION['role'] ?? 'Employee';

$isSupervisor = false;
$supervisedAgents = [];

// Query supervisor_mapping table for this user
$stmt = $conn->prepare("SELECT agent_email FROM supervisor_mapping WHERE supervisor_email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $supervisedAgents[] = $row['agent_email'];
}

if (count($supervisedAgents) > 0) {
    $isSupervisor = true;
}

echo json_encode([
    'email' => $email,
    'employee_id' => $employeeID,
    'full_name' => $fullName,
    'role' => $role,
    'is_supervisor' => $isSupervisor,
    'supervised_agents' => $supervisedAgents
]);
