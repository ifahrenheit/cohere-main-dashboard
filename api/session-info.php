<?php
// === Secure session across subdomains ===
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// ✅ Set session cookie again explicitly for cross-subdomain access
setcookie(session_name(), session_id(), [
    'domain' => '.cohere.ph',
    'path' => '/',
    'secure' => true,
    'samesite' => 'None'
]);

// === CORS headers for cross-origin fetch ===
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['https://vouchers.cohere.ph'];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json');

// === Preflight request support ===
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === Actual session check ===
if (isset($_SESSION['user_email'])) {
    echo json_encode([
        "email" => $_SESSION['user_email'],
        "name" => $_SESSION['full_name'],
        "role" => $_SESSION['role'],
        "employeeID" => $_SESSION['employeeID'],
    ]);
} else {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
}
