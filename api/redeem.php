<?php
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
// === DEBUG LOGGING ===
file_put_contents(__DIR__ . '/debug.log', "===== NEW REQUEST =====\n", FILE_APPEND);
file_put_contents(__DIR__ . '/debug.log', "SESSION:\n" . print_r($_SESSION ?? [], true), FILE_APPEND);
file_put_contents(__DIR__ . '/debug.log', "RAW POST:\n" . file_get_contents("php://input") . "\n", FILE_APPEND);

// === ERROR REPORTING ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === SESSION SETUP FOR CROSS-DOMAIN ===
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
    'http://localhost:3000',
    'http://157.245.192.63:3000',
    'https://vouchers.cohere.ph',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === ENSURE LOGGED IN ===
if (empty($_SESSION['user_email'])) {
    error_log("🔒 Unauthorized: No session found.");
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$email = strtolower(trim($_SESSION['user_email'] ?? ''));
error_log("✅ Logged in as: $email");

// === READ POST JSON ===
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);
$code = $input['code'] ?? null;

if (!$code) {
    error_log("❌ Missing 'code' in request.");
    http_response_code(400);
    echo json_encode(["error" => "Voucher code is required"]);
    exit;
}

error_log("🎟 Attempting to redeem code: $code");

try {
    // === LOAD GOOGLE API ===
    require __DIR__ . '/vendor/autoload.php';

    // === USE PHPMailer Namespaces ===


    $client = new Google_Client();
    $client->setApplicationName('Voucher Redeemer');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->setAccessType('offline');

    $service = new Google_Service_Sheets($client);
    $spreadsheetId = '1LNRd-zCFV9YF_ZsSZI26x4W1E9KOUfB8pquG2g9ofrU';
    $sheetName = 'Vouchers';
    $range = "$sheetName!A2:E";

    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $rows = $response->getValues();

    $found = false;
    foreach ($rows as $i => $row) {
        if (count($row) >= 2 && $row[0] === $code && strtolower(trim($row[1])) === $email) {
            $found = true;
            $rowIndex = $i + 2; // because A2 = row 2
            break;
        }
    }

    if (!$found) {
        error_log("❌ Voucher not found or doesn't belong to user.");
        http_response_code(404);
        echo json_encode(["error" => "Voucher not found or does not belong to you"]);
        exit;
    }

    // === MARK AS USED IN SHEET ===
    $updateRange = "$sheetName!E$rowIndex";
    $body = new Google_Service_Sheets_ValueRange([
        'values' => [['TRUE']],
    ]);
    $service->spreadsheets_values->update(
        $spreadsheetId,
        $updateRange,
        $body,
        ['valueInputOption' => 'RAW']
    );

    error_log("✅ Sheet updated at $updateRange");
    echo json_encode(["message" => "Voucher redeemed successfully"]);

    // === SEND EMAIL NOTIFICATION ===
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'cohere.ph';
    $mail->SMTPAuth = true;
    $mail->Username = 'send_email@cohere.ph';  // Replace with valid sender
    $mail->Password = 'Cohere123456';         // Replace with real password or app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 2525;

    $mail->setFrom('noreply@cohere.ph', 'Cohere Vouchers');
// Use BCC instead of direct recipients
$mail->addBCC('andrewvincentt@gmail.com');
$mail->addBCC('som@cohere.ph');
$mail->addBCC('jovin.lumapat@cohere.ph');


    $mail->isHTML(true);
    $mail->Subject = 'Voucher Redeemed';
    $mail->Body = "<p>Voucher <strong>$code</strong> was redeemed by <strong>$email</strong>.</p>";

    $mail->send();
    error_log("📧 Email notification sent.");

} catch (Exception $e) {
    error_log("❌ ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Internal server error",
        "details" => $e->getMessage()
    ]);
}
