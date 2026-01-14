<?php
// Authentication checker for Flask integration
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    echo json_encode([
        'authenticated' => false,
        'redirect' => 'https://dashboard.cohere.ph/login.php?redirect=' . urlencode($_SERVER['HTTP_REFERER'] ?? '')
    ]);
    exit;
}

// Check if user has required role (Admin, Manager, Director)
$role = $_SESSION['role'] ?? 'Employee';
$allowedRoles = ['Admin', 'Manager', 'Director'];

if (!in_array($role, $allowedRoles)) {
    echo json_encode([
        'authenticated' => true,
        'authorized' => false,
        'message' => 'Access denied. Only Admin, Manager, and Director roles can access this page.'
    ]);
    exit;
}

// User is authenticated and authorized
echo json_encode([
    'authenticated' => true,
    'authorized' => true,
    'user' => [
        'email' => $_SESSION['user_email'],
        'name' => $_SESSION['employee_name'] ?? $_SESSION['full_name'] ?? 'Unknown',
        'role' => $role,
        'employee_id' => $_SESSION['employeeID'] ?? $_SESSION['employee_id'] ?? null
    ]
]);
?>
