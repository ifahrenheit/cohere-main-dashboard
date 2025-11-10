<?php
// Secure session setup (especially for cross-origin access from React)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => 'dashboard.cohere.ph',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);
session_start();

// Allow CORS from your React frontend
header('Access-Control-Allow-Origin: https://app.cohere.ph');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Define allowed roles
$allowedRoles = ['SOM Approver', 'Manager', 'Admin', 'Director'];

// Validate role from session
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    echo json_encode(['authorized' => false]);
    exit;
}

// If authorized, return basic user info
echo json_encode([
    'authorized' => true,
    'employeeID' => $_SESSION['employeeID'],
    'name' => $_SESSION['full_name'],
    'role' => $_SESSION['role']
]);
