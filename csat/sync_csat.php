<?php
// ot/sync_csat_incremental.php
// Sync ONLY NEW CSAT data from Google Sheets (fast incremental sync)
// This should run hourly - only processes NEW rows since last sync

require_once __DIR__ . '/../ot/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db_connection.php';

function syncCSATIncremental() {
    global $conn;

    // Google Sheets config
    $serviceAccountFile = __DIR__ . '/../ot/automation-app-466709-b0e854313d71.json';
    $spreadsheetId = '1-hIS_DvG7bxjHPNaoq8LLlQ7vwID3KtCDdVN5PhvLxw';
    
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $debug_log = [];

    try {
        // Get the last row we processed
        $cache_dir = __DIR__ . '/../ot/cache';
        if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);
        
        $last_row_file = $cache_dir . '/last_csat_row.txt';
        $last_row = file_exists($last_row_file) ? (int)file_get_contents($last_row_file) : 1;
        
        $debug_log[] = "Last processed row: $last_row";
        
        $client = new Google_Client();
        $client->setApplicationName('OT Tracker CSAT Sync Incremental');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig($serviceAccountFile);

        $service = new Google_Service_Sheets($client);
        
        // Get total rows in sheet
        $metadata = $service->spreadsheets->get($spreadsheetId);
        $sheet = null;
        foreach ($metadata->getSheets() as $s) {
            if ($s->getProperties()->getTitle() == 'Imported') {
                $sheet = $s;
                break;
            }
        }
        
        if (!$sheet) {
            return ['success' => false, 'message' => 'Sheet "Imported" not found'];
        }
        
        $totalRows = $sheet->getProperties()->getGridProperties()->getRowCount();
        $debug_log[] = "Total rows in sheet: $totalRows";
        
        // Only fetch NEW rows (from last_row + 1 to end)
        $startRow = $last_row + 1;
        
        if ($startRow > $totalRows) {
            $debug_log[] = "No new rows to process";
            return [
                'success' => true,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'message' => 'Already up to date',
                'debug' => $debug_log
            ];
        }
        
        $range = "Imported!A{$startRow}:O{$totalRows}";
        $debug_log[] = "Fetching range: $range";
        
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        if (empty($values)) {
            $debug_log[] = "No new data in range";
            return [
                'success' => true,
                'imported' => 0,
                'updated' => 0,
                'message' => 'No new data',
                'debug' => $debug_log
            ];
        }

        $debug_log[] = "Processing " . count($values) . " new rows";

        $conn->begin_transaction();
        
        // Process in batches for better performance
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

                // Skip if ticket_number is missing
                if (empty($ticket_number)) {
                    $skipped++;
                    if (count($errors) < 10) {
                        $errors[] = "Row " . ($startRow + $i + $index) . ": Missing ticket_number";
                    }
                    continue;
                }

                // Use INSERT ... ON DUPLICATE KEY UPDATE for better performance
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
            
            // Small delay between batches to avoid overwhelming database
            usleep(10000); // 10ms
        }

        $conn->commit();

        // Update last processed row
        $newLastRow = $startRow + count($values) - 1;
        file_put_contents($last_row_file, $newLastRow);
        file_put_contents($cache_dir . '/last_csat_sync.txt', date('Y-m-d H:i:s'));
        
        $debug_log[] = "Updated last_row to: $newLastRow";

        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'total_processed' => $processedRows,
            'new_last_row' => $newLastRow,
            'debug' => $debug_log,
            'sample_errors' => array_slice($errors, 0, 10),
            'last_sync' => date('Y-m-d H:i:s')
        ];

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'debug' => $debug_log ?? [],
            'trace' => $e->getTraceAsString()
        ];
    }
}

// Run directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    echo json_encode(syncCSATIncremental(), JSON_PRETTY_PRINT);
}