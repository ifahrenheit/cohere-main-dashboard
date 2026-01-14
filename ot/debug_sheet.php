<?php
require_once __DIR__ . '/../vendor/autoload.php';

$serviceAccountFile = __DIR__ . '/automation-app-466709-b0e854313d71.json';
$spreadsheetId = '1-hIS_DvG7bxjHPNaoq8LLlQ7vwID3KtCDdVN5PhvLxw';
$range = 'CSAT!A2:E';

$client = new Google_Client();
$client->setApplicationName('Debug Sheet');
$client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
$client->setAuthConfig($serviceAccountFile);

$service = new Google_Service_Sheets($client);
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();

echo "Total rows: " . count($values) . "\n\n";
echo "First 10 rows:\n";

for ($i = 0; $i < 10 && $i < count($values); $i++) {
    $ticket = $values[$i][0] ?? 'EMPTY';
    $name = $values[$i][1] ?? 'EMPTY';
    $email = $values[$i][2] ?? 'EMPTY';
    $score = $values[$i][3] ?? 'EMPTY';
    $company = $values[$i][4] ?? 'EMPTY';
    
    echo "\n--- Row " . ($i + 2) . " ---\n";
    echo "Ticket: [$ticket] (empty=" . (empty(trim($ticket)) ? 'YES' : 'NO') . ")\n";
    echo "Name: [$name]\n";
    echo "Email: [$email]\n";
    echo "Score: [$score] (as int=" . intval($score) . ", valid=" . (intval($score) >= 1 && intval($score) <= 5 ? 'YES' : 'NO') . ")\n";
    echo "Company: [$company]\n";
}
?>
