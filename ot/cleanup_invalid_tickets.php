<?php
/**
 * cleanup_invalid_tickets.php
 * Web interface to delete invalid tickets from the database
 * 
 * Usage: Upload this file to your /ot/ directory and access via browser
 * Example: https://yoursite.com/ot/cleanup_invalid_tickets.php
 */

require_once 'config.php';

// Security: Only allow authorized users
$current_user = getCurrentUserOT();
if (!$current_user || !isManagerOrAbove()) {
    die("Error: Only managers can access this cleanup tool. <a href='index.php'>Back to Dashboard</a>");
}

$action = $_GET['action'] ?? 'preview';
$results = [];
$error = '';

// Preview invalid tickets
if ($action === 'preview') {
    $preview_query = "
        SELECT 
            id,
            ot_request_id,
            agent_id,
            ticket_number,
            LENGTH(ticket_number) as length,
            ot_date,
            CASE 
                WHEN ticket_number REGEXP '[^0-9]' THEN 'Contains non-numeric characters'
                WHEN LENGTH(ticket_number) < 5 THEN CONCAT('Too short (', LENGTH(ticket_number), ' digits)')
                ELSE 'Unknown issue'
            END as issue
        FROM ot_tickets 
        WHERE ticket_number REGEXP '[^0-9]' 
           OR LENGTH(ticket_number) < 5
        ORDER BY LENGTH(ticket_number), ticket_number
    ";
    
    $preview_result = $conn->query($preview_query);
    
    if ($preview_result) {
        while ($row = $preview_result->fetch_assoc()) {
            $results[] = $row;
        }
    }
    
    // Get count statistics
    $count_query = "
        SELECT 
            COUNT(*) as invalid_count,
            (SELECT COUNT(*) FROM ot_tickets) as total_count
        FROM ot_tickets 
        WHERE ticket_number REGEXP '[^0-9]' 
           OR LENGTH(ticket_number) < 5
    ";
    $count_result = $conn->query($count_query);
    $stats = $count_result->fetch_assoc();
}

// Delete invalid tickets
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        
        // Create backup first
        $backup_query = "CREATE TABLE IF NOT EXISTS ot_tickets_backup_" . date('Ymd_His') . " SELECT * FROM ot_tickets";
        
        if ($conn->query($backup_query)) {
            // Perform deletion
            $delete_query = "
                DELETE FROM ot_tickets 
                WHERE ticket_number REGEXP '[^0-9]' 
                   OR LENGTH(ticket_number) < 5
                   OR TRIM(ticket_number) = ''
            ";
            
            if ($conn->query($delete_query)) {
                $deleted_count = $conn->affected_rows;
                $results['success'] = "Successfully deleted $deleted_count invalid ticket(s). Backup table created.";
                $action = 'complete';
            } else {
                $error = "Error deleting tickets: " . $conn->error;
            }
        } else {
            $error = "Error creating backup: " . $conn->error;
        }
    } else {
        $error = "Confirmation required to delete tickets.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Invalid Tickets - OT Tracker</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    <style>
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-radius: 8px;
        }
        
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-radius: 8px;
        }
        
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-radius: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: var(--text-light);
            margin-top: 0.5rem;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .preview-table th {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: left;
        }
        
        .preview-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .preview-table tr:hover {
            background: #f5f5f5;
        }
        
        .issue-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .issue-nonumeric {
            background: #ffebee;
            color: #c62828;
        }
        
        .issue-short {
            background: #fff3e0;
            color: #e65100;
        }
        
        .confirm-section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-right: 1rem;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to OT Dashboard</a>
        </div>

        <div class="ot-header">
            <h1>🧹 Cleanup Invalid Tickets</h1>
            <p>Remove tickets that don't meet validation requirements</p>
            <span class="role-badge">👨‍💼 Manager Only</span>
        </div>

        <div class="content">
            <?php if ($error): ?>
                <div class="error-box">
                    <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'preview'): ?>
                <div class="warning-box">
                    <h3>⚠️ Validation Rules</h3>
                    <p>Tickets must meet these requirements:</p>
                    <ul>
                        <li><strong>Numeric only:</strong> No letters, no special characters</li>
                        <li><strong>Minimum length:</strong> At least 5 digits</li>
                    </ul>
                    <p><strong>Invalid examples:</strong> 7, 8, gyg123, pre-ot, chat, email, No Tix, AM, PM, 1234</p>
                    <p><strong>Valid examples:</strong> 112334, 221312, 123456</p>
                </div>

                <?php if (isset($stats)): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($stats['total_count']); ?></div>
                            <div class="stat-label">Total Tickets</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #dc3545;">
                                <?php echo number_format($stats['invalid_count']); ?>
                            </div>
                            <div class="stat-label">Invalid Tickets</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #28a745;">
                                <?php echo number_format($stats['total_count'] - $stats['invalid_count']); ?>
                            </div>
                            <div class="stat-label">Valid Tickets</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (count($results) > 0): ?>
                    <h2>📋 Invalid Tickets Found (<?php echo count($results); ?>)</h2>
                    
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Ticket Number</th>
                                <th>Length</th>
                                <th>Issue</th>
                                <th>OT Date</th>
                                <th>Agent ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $ticket): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ticket['id']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($ticket['length']); ?> digits</td>
                                    <td>
                                        <span class="issue-badge <?php echo strpos($ticket['issue'], 'short') !== false ? 'issue-short' : 'issue-nonumeric'; ?>">
                                            <?php echo htmlspecialchars($ticket['issue']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($ticket['ot_date']); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['agent_id']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="confirm-section">
                        <h3>⚠️ Confirm Deletion</h3>
                        <p><strong>This action will:</strong></p>
                        <ul>
                            <li>Create a backup table with timestamp</li>
                            <li>Delete <?php echo count($results); ?> invalid ticket(s)</li>
                            <li>This action cannot be undone (but backup will be available)</li>
                        </ul>
                        
                        <form method="POST" action="?action=delete" onsubmit="return confirm('Are you absolutely sure you want to delete <?php echo count($results); ?> invalid tickets? A backup will be created.');">
                            <input type="hidden" name="confirm" value="yes">
                            <button type="submit" class="btn-danger">🗑️ Delete Invalid Tickets</button>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>

                <?php else: ?>
                    <div class="success-box">
                        <h3>✅ All Clear!</h3>
                        <p>No invalid tickets found. All tickets meet validation requirements.</p>
                        <a href="index.php" class="btn btn-primary">Back to Dashboard</a>
                    </div>
                <?php endif; ?>

            <?php elseif ($action === 'complete'): ?>
                <div class="success-box">
                    <h3>✅ Cleanup Complete!</h3>
                    <p><?php echo htmlspecialchars($results['success']); ?></p>
                    <p><strong>Next steps:</strong></p>
                    <ul>
                        <li>Review the remaining tickets in the system</li>
                        <li>Check that all OT requests still have valid data</li>
                        <li>The backup table is available if you need to restore</li>
                    </ul>
                    <a href="?action=preview" class="btn btn-primary">Run Cleanup Again</a>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>