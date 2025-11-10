<?php
$mysqli = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($mysqli->connect_error) die('Connect Error: ' . $mysqli->connect_error);

echo "Starting sync...\n";
$start = microtime(true);

// Create temporary table with shift numbers assigned based on IN log time gaps, using only recent 3 days of data
$createTemp = "
CREATE TEMPORARY TABLE temp_logs_with_shift AS
WITH sorted_logs AS (
    SELECT *,
           DATE(DATE_SUB(date, INTERVAL 15 HOUR)) AS shift_date,
           LAG(date) OVER (PARTITION BY personid ORDER BY date) AS prev_date
    FROM dailytimerecord
    -- Removed the date filter here to select all data
),
shift_marks AS (
    SELECT *,
           CASE 
             WHEN type = 'in' AND (prev_date IS NULL OR TIMESTAMPDIFF(HOUR, prev_date, date) > 4)
             THEN 1
             ELSE 0
           END AS is_new_shift
    FROM sorted_logs
),
numbered_shifts AS (
    SELECT *,
           SUM(is_new_shift) OVER (PARTITION BY personid ORDER BY date) AS shift_num
    FROM shift_marks
)
SELECT personid, date, type, locid, created, shift_date, shift_num,
       ROW_NUMBER() OVER (
           PARTITION BY personid, shift_date, shift_num, type
           ORDER BY 
               CASE WHEN type = 'in' THEN date END ASC,
               CASE WHEN type = 'out' THEN date END DESC
       ) AS rn
FROM numbered_shifts;
";


if (!$mysqli->query($createTemp)) {
    die("Temp table creation failed: " . $mysqli->error);
}

// Insert only new records that don't already exist
$insertFiltered = "
INSERT IGNORE INTO dailytimerecordsfiltered (personid, date, type, locid, created)
SELECT personid, date, type, locid, created
FROM temp_logs_with_shift
WHERE rn = 1;
";


if (!$mysqli->query($insertFiltered)) {
    die("Insert failed: " . $mysqli->error);
}


echo "Sync completed in " . round(microtime(true) - $start, 2) . "s\n";
$mysqli->close();
?>
