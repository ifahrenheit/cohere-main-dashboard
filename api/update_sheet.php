<?php
require __DIR__ . '/vendor/autoload.php';

// Load credentials
$client = new Google_Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->addScope(Google_Service_Sheets::SPREADSHEETS);

$service = new Google_Service_Sheets($client);

// Replace with your real spreadsheet ID
$spreadsheetId = '1LNRd-zCFV9YF_ZsSZI26x4W1E9KOUfB8pquG2g9ofrU';
// Replace with correct sheet/tab name
$sheetName = 'Vouchers'; // Or whatever your sheet tab is named

function updateVoucherStatus($code, $newValue = true)
{
    global $service, $spreadsheetId, $sheetName;

    // Read entire sheet
    $range = $sheetName . '!A2:E';
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues();

    foreach ($values as $rowIndex => $row) {
        if (isset($row[0]) && $row[0] === $code) {
            $updateRange = $sheetName . '!E' . ($rowIndex + 2); // E column for 'used'
            $body = new Google_Service_Sheets_ValueRange([
                'values' => [[strtoupper($newValue ? 'TRUE' : 'FALSE')]]
            ]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $service->spreadsheets_values->update($spreadsheetId, $updateRange, $body, $params);
            return true;
        }
    }
    return false;
}
