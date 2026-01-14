<?php
/**
 * ONE-TIME Full Re-sync with Root Cause
 * This will process ALL rows from Google Sheets and update root_cause
 * Safe to run - uses ON DUPLICATE KEY UPDATE so won't create duplicates
 */

require_once __DIR__ . '/../ot/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db_connection.php';

echo "<h2>One-Time Full CSAT Re-sync with Root Cause</h2>";
echo "<p><strong>This will re-process ALL rows from Google Sheets to populate root_cause</strong></p>";
echo "<p>Safe to run - uses ON DUPLICATE KEY UPDATE (won't create duplicates)</p>";
echo "<hr>";

$serviceAccountFile = __DIR__ . '/../ot/automation-app-466709-b0e854313d71.json';
$spreadsheetId = '1-hIS_DvG7bxjHPNaoq8LLlQ7vwID3KtCDdVN5PhvLxw';

$imported = 0;
$updated = 0;
$skipped = 0;
$errors = [];

try {
    echo "<p>Connecting to Google Sheets...</p>";
    flush();
    
    $client = new Google_Client();
    $client->setApplicationName('OT Tracker CSAT Full Resync');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($serviceAccountFile);

    $service = new Google_Service_Sheets($client);
    
    // Get total rows
    $metadata = $service->spreadsheets->get($spreadsheetId);
    $sheet = null;
    foreach ($metadata->getSheets() as $s) {
        if ($s->getProperties()->getTitle() == 'Imported') {
            $sheet = $s;
            break;
        }
    }
    
    if (!$sheet) {
        die("Sheet 'Imported' not found");
    }
    
    $totalRows = $sheet->getProperties()->getGridProperties()->getRowCount();
    echo "<p>Total rows in sheet: " . number_format($totalRows) . "</p>";
    
    // Fetch ALL data rows (starting from row 2 to skip header)
    $range = "Imported!A2:O{$totalRows}";
    echo "<p>Fetching range: {$range}</p>";
    flush();
    
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues();

    if (empty($values)) {
        die("No data found in sheet");
    }

    $totalDataRows = count($values);
    echo "<p><strong>Found " . number_format($totalDataRows) . " data rows</strong></p>";
    echo "<p>Processing in batches of 500...</p>";
    echo "<hr>";
    flush();

    $conn->begin_transaction();
    
    $batchSize = 500;
    $processedRows = 0;

    for ($i = 0; $i < count($values); $i += $batchSize) {
        $batch = array_slice($values, $i, $batchSize);
        
        foreach ($batch as $index => $row) {
            $theme = $row[0] ?? null;
            $root_cause = $row[1] ?? null;
            $date_raw = $row[2] ?? null;
            $ticket_number = $row[3] ?? null;
            $channel_type = $row[4] ?? null;
            $agent_name = $row[5] ?? null;
            $agent_email = $row[6] ?? null;
            $csat_score = isset($row[7]) ? intval($row[7]) : null;
            $sentiment = $row[8] ?? null;
            $week_number = isset($row[9]) ? intval($row[9]) : null;
            $month_name = $row[10] ?? null;
            $team_lead = $row[11] ?? null;
            $tenure = $row[12] ?? null;
            $agent_group = $row[13] ?? null;
            $batch_val = $row[14] ?? null;

            // Parse date
            if (!empty($date_raw)) {
                try {
                    $date_obj = new DateTime($date_raw);
                    $survey_date = $date_obj->format('Y-m-d');
                } catch (Exception $e) {
                    $survey_date = null;
                }
            } else {
                $survey_date = null;
            }

            // Skip if ticket_number is invalid
            if (empty($ticket_number) 
                || in_array($ticket_number, ['Email', 'Chat', 'Phone', 'Call', 'Ticket ID', 'Channel Type'])
                || strpos($ticket_number, '@') !== false
                || strlen($ticket_number) < 5
                || !is_numeric($ticket_number)) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = "Row " . ($i + $index + 2) . ": Invalid ticket_number: " . $ticket_number;
                }
                continue;
            }

            // Insert or update with root_cause
            $insert_stmt = $conn->prepare("
                INSERT INTO csat_scores
                (theme, root_cause, ticket_number, agent_name, agent_email, channel_type,
                 csat_score, sentiment, survey_date, week_number, month_name,
                 team_lead, tenure, agent_group, batch, comments)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')
                ON DUPLICATE KEY UPDATE
                    theme = VALUES(theme),
                    root_cause = VALUES(root_cause),
                    agent_name = VALUES(agent_name),
                    agent_email = VALUES(agent_email),
                    channel_type = VALUES(channel_type),
                    csat_score = VALUES(csat_score),
                    sentiment = VALUES(sentiment),
                    survey_date = VALUES(survey_date),
                    week_number = VALUES(week_number),
                    month_name = VALUES(month_name),
                    team_lead = VALUES(team_lead),
                    tenure = VALUES(tenure),
                    agent_group = VALUES(agent_group),
                    batch = VALUES(batch),
                    updated_at = NOW()
            ");
            
            $insert_stmt->bind_param(
                "ssssssissssssss",
                $theme, $root_cause, $ticket_number, $agent_name, $agent_email, $channel_type,
                $csat_score, $sentiment, $survey_date, $week_number, $month_name,
                $team_lead, $tenure, $agent_group, $batch_val
            );
            
            $result = $insert_stmt->execute();
            
            if ($conn->affected_rows == 1) {
                $imported++;
            } elseif ($conn->affected_rows == 2) {
                $updated++;
            }
            
            $insert_stmt->close();
            $processedRows++;
        }
        
        // Progress indicator
        if (($i + $batchSize) % 5000 == 0 || ($i + $batchSize) >= count($values)) {
            $progress = min($i + $batchSize, count($values));
            echo "<p>Processed " . number_format($progress) . " / " . number_format($totalDataRows) . " rows... (Imported: {$imported}, Updated: {$updated})</p>";
            flush();
        }
        
        usleep(10000); // 10ms delay between batches
    }

    $conn->commit();

    echo "<hr>";
    echo "<h3>✅ Re-sync Complete!</h3>";
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr style='background: #ddd;'><th>Metric</th><th>Count</th></tr>";
    echo "<tr><td>Total Rows in Sheet</td><td>" . number_format($totalDataRows) . "</td></tr>";
    echo "<tr><td>New Records Imported</td><td style='background: #ccffcc;'><strong>" . number_format($imported) . "</strong></td></tr>";
    echo "<tr><td>Existing Records Updated</td><td style='background: #cce5ff;'><strong>" . number_format($updated) . "</strong></td></tr>";
    echo "<tr><td>Skipped (invalid)</td><td>" . number_format($skipped) . "</td></tr>";
    echo "</table>";

    if (!empty($errors)) {
        echo "<h4>Sample Errors:</h4><ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    }

    // Verification query
    echo "<hr>";
    echo "<h3>Verification - Root Cause by Month:</h3>";
    $verifyQuery = "
        SELECT 
            DATE_FORMAT(survey_date, '%Y-%m') as month,
            COUNT(*) as total_records,
            SUM(CASE WHEN root_cause IS NOT NULL AND root_cause != '' THEN 1 ELSE 0 END) as has_root_cause,
            ROUND((SUM(CASE WHEN root_cause IS NOT NULL AND root_cause != '' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as percentage
        FROM csat_scores
        WHERE survey_date >= '2025-01-01'
        GROUP BY DATE_FORMAT(survey_date, '%Y-%m')
        ORDER BY month
    ";
    
    $verifyResult = $conn->query($verifyQuery);
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #ddd;'><th>Month</th><th>Total Records</th><th>Has Root Cause</th><th>Percentage</th></tr>";
    while ($row = $verifyResult->fetch_assoc()) {
        $bgColor = $row['has_root_cause'] > 0 ? '#ccffcc' : '#ffcccc';
        echo "<tr>";
        echo "<td>" . $row['month'] . "</td>";
        echo "<td>" . number_format($row['total_records']) . "</td>";
        echo "<td style='background: {$bgColor};'><strong>" . number_format($row['has_root_cause']) . "</strong></td>";
        echo "<td>" . $row['percentage'] . "%</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h3>✅ Done! Your incremental sync will continue working normally.</h3>";
    echo "<p>This was a one-time re-sync. Your hourly incremental sync (sync_csat_incremental.php) will continue processing only new rows.</p>";

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    echo "<h3 style='color: red;'>Error:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>