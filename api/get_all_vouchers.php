<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// === SESSION SETUP ===
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

setcookie(session_name(), session_id(), [
  'domain' => '.cohere.ph',
  'path' => '/',
  'secure' => true,
  'samesite' => 'None'
]);

// === CORS HEADERS ===
$allowed_origins = [
    'https://vouchers.cohere.ph',
    'http://localhost:3000',
    'http://157.245.192.63:3000',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Only GET requests allowed."]);
    exit;
}

// === CHECK ADMIN ROLE ===
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Manager', 'SOM Approver', 'Director'])) {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden: Admin access required"]);
    exit;
}

// === GOOGLE SHEETS ===
require __DIR__ . '/vendor/autoload.php';

$client = new Google_Client();
$client->setApplicationName('All Voucher Viewer');
$client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->setAccessType('offline');

$service = new Google_Service_Sheets($client);
$spreadsheetId = '1LNRd-zCFV9YF_ZsSZI26x4W1E9KOUfB8pquG2g9ofrU';
$sheetName = 'Vouchers';
$range = "$sheetName!A2:E";

try {
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $rows = $response->getValues();

    $allVouchers = [];

    foreach ($rows as $row) {
        if (count($row) < 5) continue;

        $allVouchers[] = [
            'code' => $row[0],
            'email' => $row[1],
            'issue_date' => $row[2],
            'expires_at' => $row[3],
            'used' => strtolower(trim($row[4])) === 'true',
        ];
    }

    echo json_encode($allVouchers);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Failed to load vouchers",
        "details" => $e->getMessage()
    ]);
}
