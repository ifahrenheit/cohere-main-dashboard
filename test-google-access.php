<?php
require __DIR__ . '/api/vendor/autoload.php';

$client = new Google_Client();
$client->setApplicationName('Test Access');
$client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
$client->setAuthConfig(__DIR__ . '/api/credentials.json');
$client->setAccessType('offline');

$service = new Google_Service_Sheets($client);
$spreadsheetId = '1LNRd-zCFV9YF_ZsSZI26x4W1E9KOUfB8pquG2g9ofrU';
$range = 'Vouchers!A1';

$response = $service->spreadsheets_values->get($spreadsheetId, $range);
echo "Success! A1 = " . $response->getValues()[0][0];

