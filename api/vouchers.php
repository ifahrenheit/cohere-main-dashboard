<?php
// ===== SESSION SETUP FOR CROSS-ORIGIN ACCESS =====
ini_set('session.cookie_domain', '.cohere.ph'); // ✅ Note the leading dot!
ini_set('session.cookie_samesite', 'None');     // ✅ Required for cross-origin cookies
ini_set('session.cookie_secure', '1');          // ✅ Must be HTTPS
session_start();

// Reinforce secure session cookie again (PHP sometimes needs both ini + setcookie)
// Required for cross-subdomain session sharing
setcookie(session_name(), session_id(), [
  'domain' => '.cohere.ph',        // ✅ Key part: allows ALL subdomains
  'path' => '/',
  'secure' => true,                // ✅ Needed because you're using HTTPS
  'samesite' => 'None'             // ✅ Allow cross-site
]);
$allowed_origins = [
    'http://localhost:3000',
    'http://157.245.192.63:3000',
    'https://vouchers.cohere.ph',
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Uncomment during testing:
// $_SESSION['user_email'] = 'andrew.tacdoro@cohere.ph';

if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$email = $_SESSION['user_email'];

// Google Sheet CSV URL
$csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSo0q4M-r9XmYDY8RfSog7Aun50qH3_Z3XaEdN4rwxGbsycmPrEJgmXB-APrntPXxrhnnuyGNprELSc/pub?output=csv';

// Fetch and parse
$data = array_map('str_getcsv', file($csvUrl));
$headers = array_map('trim', $data[0]);
$rows = array_slice($data, 1);

// Convert to associative array
$vouchers = array_map(function($row) use ($headers) {
    return array_combine($headers, $row);
}, $rows);

// Filter by email
$userVouchers = array_values(array_filter($vouchers, fn($v) => trim($v['email']) === $email));

// Optional: convert fields like "used" to boolean
foreach ($userVouchers as &$v) {
    $v['used'] = strtolower(trim($v['used'])) === 'true';
}

echo json_encode($userVouchers);
