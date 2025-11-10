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

// ===== LOAD GOOGLE CLIENT =====
require __DIR__ . '/vendor/autoload.php';

// ===== CORS CONFIGURATION =====
$allowed_origins = [
    'http://localhost:3000',
    'http://157.245.192.63:3000',
    'https://vouchers.cohere.ph',
    'https://dashboard.cohere.ph', // if needed
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// ===== ONLY ALLOW GET REQUESTS =====
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Only GET requests are allowed."]);
    exit;
}

// ===== SESSION CHECK (USER MUST BE LOGGED IN) =====
if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized. Please log in."]);
    exit;
}

$email = strtolower(trim($_SESSION['user_email']));

// ===== GOOGLE SHEETS SETUP =====
$client = new Google_Client();
$client->setApplicationName('Voucher Viewer');
$client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
$client->setAuthConfig(__DIR__ . '/credentials.json'); // Path to your credentials
$client->setAccessType('offline');

$service = new Google_Service_Sheets($client);

// ===== SHEET CONFIG =====
$spreadsheetId = '1LNRd-zCFV9YF_ZsSZI26x4W1E9KOUfB8pquG2g9ofrU';
$sheetName = 'Vouchers';
$range = "$sheetName!A2:E";

// ===== FETCH + FILTER USER VOUCHERS =====
try {
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $rows = $response->getValues();

    $userVouchers = [];

    foreach ($rows as $row) {
        if (count($row) < 5) continue; // Incomplete row

        if (strtolower(trim($row[1])) === $email) {
            $userVouchers[] = [
                'code' => $row[0],
                'email' => $row[1],
                'issue_date' => $row[2],
                'expires_at' => $row[3],
                'used' => filter_var($row[4] ?? '', FILTER_VALIDATE_BOOLEAN),
            ];
        }
    }

    echo json_encode($userVouchers);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Failed to fetch vouchers.",
        "details" => $e->getMessage()
    ]);
}
