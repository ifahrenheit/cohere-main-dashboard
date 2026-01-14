<?php
date_default_timezone_set('Asia/Manila');
require 'config/db_connection.php'; // Ensure $conn is initialized

// Set MySQL timezone to Philippine Time
mysqli_query($conn, "SET time_zone = '+08:00'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Headcount - On Shift</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e5a96 0%, #2980b9 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 900px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 2em;
        }
        
        .timestamp {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 30px;
        }
        
        .headcount-box {
            background: linear-gradient(135deg, #1e5a96 0%, #2980b9 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(30, 90, 150, 0.3);
        }
        
        .headcount-label {
            font-size: 1.2em;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .headcount-number {
            font-size: 4em;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .details-section {
            margin-top: 20px;
        }
        
        .details-section h2 {
            color: #1e5a96;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th {
            background: #f5f8fc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #1e5a96;
            border-bottom: 2px solid #2980b9;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f5f8fc;
        }
        
        .refresh-btn {
            background: linear-gradient(135deg, #1e5a96 0%, #2980b9 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 1em;
            margin-top: 20px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(30, 90, 150, 0.3);
        }
        
        .refresh-btn:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1e5a96 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 90, 150, 0.4);
        }
        
        .auto-refresh {
            color: #666;
            font-size: 0.85em;
            margin-top: 10px;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Current Headcount</h1>
        <div class="timestamp" id="live-clock"></div>

        <?php
        try {
            // Get headcount
            $countQuery = "
    SELECT COUNT(DISTINCT personid) as people_on_shift
    FROM dailytimerecordsfiltered d1
    WHERE type = 'in'
      AND date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
      AND NOT EXISTS (
        SELECT 1 
        FROM dailytimerecordsfiltered d2 
        WHERE d2.personid = d1.personid 
          AND d2.date > d1.date
          AND d2.date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
      )
";
            
            $result = mysqli_query($conn, $countQuery);
            
            if (!$result) {
                throw new Exception(mysqli_error($conn));
            }
            
            $row = mysqli_fetch_assoc($result);
            $headcount = $row['people_on_shift'];

            // Display headcount
            echo '<div class="headcount-box">';
            echo '<div class="headcount-label">People Currently On Shift</div>';
            echo '<div class="headcount-number">' . $headcount . '</div>';
            echo '<div class="headcount-label">Employees</div>';
            echo '</div>';

            // Get details of people on shift
            $detailsQuery = "
    SELECT personid, 
           DATE_FORMAT(date, '%h:%i %p') as time_in,
           DATE_FORMAT(date, '%m/%d %h:%i %p') as time_in_full,
           TIMESTAMPDIFF(HOUR, date, NOW()) as hours_on_shift,
           TIMESTAMPDIFF(MINUTE, date, NOW()) as minutes_on_shift
    FROM dailytimerecordsfiltered d1
    WHERE type = 'in'
      AND date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
      AND NOT EXISTS (
        SELECT 1 
        FROM dailytimerecordsfiltered d2 
        WHERE d2.personid = d1.personid 
          AND d2.date > d1.date
          AND d2.date >= DATE_SUB(NOW(), INTERVAL 18 HOUR)
      )
    ORDER BY date DESC
";
            
            $result = mysqli_query($conn, $detailsQuery);
            
            if (!$result) {
                throw new Exception(mysqli_error($conn));
            }

            if (mysqli_num_rows($result) > 0) {
                echo '<div class="details-section">';
                echo '<h2>Currently On Shift</h2>';
                echo '<table>';
                echo '<thead><tr><th>Person ID</th><th>Time In</th><th>Duration</th></tr></thead>';
                echo '<tbody>';
                
                while ($emp = mysqli_fetch_assoc($result)) {
                    $hours = floor($emp['minutes_on_shift'] / 60);
                    $minutes = $emp['minutes_on_shift'] % 60;
                    $duration = $hours . 'h ' . $minutes . 'm';
                    
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($emp['personid']) . '</td>';
                    echo '<td>' . htmlspecialchars($emp['time_in']) . '</td>';
                    echo '<td>' . $duration . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
                echo '</div>';
            }

            mysqli_close($conn);

        } catch (Exception $e) {
            echo '<div class="error">Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>

        <button class="refresh-btn" onclick="location.reload()">🔄 Refresh</button>
        <div class="auto-refresh">
            Page auto-refreshes every 30 seconds
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                          'July', 'August', 'September', 'October', 'November', 'December'];
            
            const month = months[now.getMonth()];
            const day = now.getDate();
            const year = now.getFullYear();
            
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            
            const timeString = `${month} ${day}, ${year} - ${hours}:${minutes}:${seconds} ${ampm}`;
            
            document.getElementById('live-clock').textContent = timeString;
        }
        
        // Update immediately
        updateClock();
        
        // Update every second
        setInterval(updateClock, 1000);
        
        // Auto-refresh page every 30 seconds for headcount update
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>