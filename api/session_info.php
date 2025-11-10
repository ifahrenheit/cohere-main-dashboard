<?php
// ✅ Show errors while debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Cross-subdomain session support
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// ✅ Set content type
header('Content-Type: application/json');

// ✅ Not logged in? Return 401
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// ✅ Load DB
require_once __DIR__ . '/../db_connection.php';

// ✅ Default session info
$userEmail = $_SESSION['user_email'];
$role = $_SESSION['role'] ?? 'Employee';
$isSupervisor = false;
$supervisedAgents = [];

// ✅ Fetch supervised agents if role is not Admin/Manager/Director/SOM Approver
if (!in_array($role, ['Admin', 'Manager', 'Director', 'SOM Approver'])) {
    $stmt = $conn->prepare("SELECT agent_email FROM supervisor_mapping WHERE supervisor_email = ?");
    $stmt->bind_param("s", $userEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
    $supervisedAgents[] = trim(strtolower($row['agent_email']));
}
    $stmt->close();

    if (!empty($supervisedAgents)) {
        $isSupervisor = true;
    }
}

echo json_encode([
    'email' => $userEmail,
    'employee_id' => $_SESSION['employeeID'] ?? null,
    'full_name' => $_SESSION['full_name'] ?? '',
    'role' => $role,
    'is_supervisor' => $isSupervisor,
    'supervised_agents' => $supervisedAgents,
]);
