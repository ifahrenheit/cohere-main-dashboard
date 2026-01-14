<?php
// ot/ticket_detail.php
// View detailed information about a single OT ticket

require_once 'config.php';

$current_user = getCurrentUserOT();

if (!$current_user) {
    die("Error: Please <a href='/login.php'>log in</a> to access this page.");
}

$current_employee_id = $current_user['EmployeeID'] ?? 
                       $current_user['employeeID'] ?? 
                       $_SESSION['employeeID'] ?? null;

if (!$current_employee_id) {
    die("Error: Employee ID not found.");
}

$is_team_lead = isTeamLead();
$is_manager = isManagerOrAbove();

// Get ticket ID from URL
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$ticket_id) {
    header("Location: view_tickets.php");
    exit();
}

// Fetch ticket details with duplicate detection
$query = "SELECT 
            ot.*, 
            CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
            e.Email as agent_email,
            e.team as agent_team,
            CONCAT(s.FirstName, ' ', s.LastName) as supervisor_name,
            s.Email as supervisor_email,
            (SELECT COUNT(*) FROM ot_tickets t2 
             WHERE t2.ticket_number = ot.ticket_number 
             AND t2.agent_id = ot.agent_id 
             AND t2.ot_date = ot.ot_date
             AND t2.id != ot.id) as duplicate_count
          FROM ot_tickets ot
          LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
          LEFT JOIN Employees s ON ot.supervisor_id = s.EmployeeID
          WHERE ot.id = " . $ticket_id;

$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Ticket not found.");
}

$ticket = $result->fetch_assoc();

// Check if user has permission to view this ticket
if (!$is_manager && !$is_team_lead && $ticket['agent_id'] != $current_employee_id) {
    die("You don't have permission to view this ticket.");
}

// For team leads, check if agent is supervised
if ($is_team_lead && !$is_manager) {
    $supervised_ids = getSupervisedAgentIDs($current_employee_id);
    if (!in_array($ticket['agent_id'], $supervised_ids) && $ticket['agent_id'] != $current_employee_id) {
        die("You don't have permission to view this ticket.");
    }
}

// Get related duplicate tickets if any
$related_tickets = [];
if ($ticket['duplicate_count'] > 0 && !empty($ticket['ticket_number'])) {
    $dup_query = "SELECT 
                    ot.id, ot.created_at, ot.ot_date, ot.ot_start_time, ot.ot_end_time, ot.ot_hours,
                    CONCAT(e.FirstName, ' ', e.LastName) as agent_name
                  FROM ot_tickets ot
                  LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
                  WHERE ot.ticket_number = '" . $conn->real_escape_string($ticket['ticket_number']) . "'
                  AND ot.agent_id = '" . $conn->real_escape_string($ticket['agent_id']) . "'
                  AND ot.ot_date = '" . $conn->real_escape_string($ticket['ot_date']) . "'
                  AND ot.id != " . $ticket_id . "
                  ORDER BY ot.created_at DESC";
    $dup_result = $conn->query($dup_query);
    if ($dup_result) {
        while ($dup = $dup_result->fetch_assoc()) {
            $related_tickets[] = $dup;
        }
    }
}

$success = isset($_GET['success']) ? true : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OT Ticket #<?php echo $ticket['id']; ?></title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            background: #f5f5f5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2.5rem;
            border-radius: 12px;
            margin-bottom: 2.5rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .ticket-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }
        
        .ticket-header .ticket-meta {
            margin-top: 0.75rem;
            opacity: 0.95;
            font-size: 1rem;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            margin-bottom: 2.5rem;
        }
        
        @media (max-width: 1200px) {
            .detail-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .detail-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border-top: 5px solid #667eea;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .detail-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .detail-section h3 {
            margin: 0 0 1.5rem 0;
            color: #667eea;
            font-size: 1.15rem;
            font-weight: 700;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .detail-row {
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
        }
        
        .detail-row:last-child {
            margin-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }
        
        .detail-value {
            font-size: 1.1rem;
            color: #333;
            line-height: 1.6;
            font-weight: 500;
        }
        
        .detail-value.large {
            font-size: 2.5rem;
            color: #667eea;
            font-weight: 700;
            margin-top: 0.5rem;
        }
        
        .duplicate-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe8a3 100%);
            border: 2px solid #ff9800;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 2px 12px rgba(255, 152, 0, 0.2);
        }
        
        .duplicate-warning h3 {
            margin-top: 0;
            color: #ff9800;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .related-tickets-list {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0 0 0;
        }
        
        .related-tickets-list li {
            background: white;
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            border-radius: 8px;
            border-left: 4px solid #ff9800;
            transition: transform 0.2s;
        }
        
        .related-tickets-list li:hover {
            transform: translateX(5px);
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2.5rem;
            padding-top: 2rem;
            border-top: 2px solid #e0e0e0;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 2px solid #f0f0f0;
            background: #fafafa;
            border-radius: 12px 12px 0 0;
        }
        
        .card-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .duplicate-badge-inline {
            background: #ff9800;
            color: white;
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 0.75rem;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-resolved {
            background: #4caf50;
            color: white;
        }
        
        .status-open {
            background: #2196f3;
            color: white;
        }
        
        .status-in_progress {
            background: #ff9800;
            color: white;
        }
        
        .status-closed {
            background: #757575;
            color: white;
        }
        
        @media print {
            .header-nav, .action-buttons {
                display: none;
            }
            
            body {
                background: white;
            }
            
            .detail-section {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="view_tickets.php" class="back-link">← Back to Tickets</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✓ Ticket submitted successfully!</div>
        <?php endif; ?>

        <?php if ($ticket['duplicate_count'] > 0 && ($is_team_lead || $is_manager)): ?>
            <div class="duplicate-warning">
                <h3>⚠️ Duplicate Ticket Detected</h3>
                <p>This ticket number appears <strong><?php echo ($ticket['duplicate_count'] + 1); ?> times</strong> for this agent on this date.</p>
                <p><strong>Possible reasons:</strong></p>
                <ul>
                    <li>Customer called back multiple times (legitimate)</li>
                    <li>Agent accidentally submitted the same ticket multiple times</li>
                    <li>Data entry error</li>
                </ul>
                <?php if (!empty($related_tickets)): ?>
                    <p><strong>Related duplicate entries:</strong></p>
                    <ul class="related-tickets-list">
                        <?php foreach ($related_tickets as $rel): ?>
                            <li>
                                <a href="ticket_detail.php?id=<?php echo $rel['id']; ?>">
                                    Ticket #<?php echo $rel['id']; ?>
                                </a> - 
                                Submitted: <?php echo formatDateTime($rel['created_at']); ?> - 
                                OT: <?php echo formatTime($rel['ot_start_time']); ?> to <?php echo formatTime($rel['ot_end_time']); ?> 
                                (<?php echo number_format($rel['ot_hours'], 2); ?> hrs)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="ticket-header">
            <h1>🎫 Ticket #<?php echo $ticket['id']; ?></h1>
            <div class="ticket-meta">
                Submitted by <?php echo htmlspecialchars($ticket['agent_name']); ?> on 
                <?php echo formatDateTime($ticket['created_at']); ?>
            </div>
        </div>

        <div class="detail-grid">
            <!-- Agent Information -->
            <div class="detail-section">
                <h3>👤 Agent Information</h3>
                <div class="detail-row">
                    <span class="detail-label">Agent Name</span>
                    <div class="detail-value"><?php echo htmlspecialchars($ticket['agent_name']); ?></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <div class="detail-value"><?php echo htmlspecialchars($ticket['agent_email']); ?></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Team</span>
                    <div class="detail-value"><?php echo htmlspecialchars($ticket['agent_team'] ?? 'N/A'); ?></div>
                </div>
                <?php if ($ticket['supervisor_name']): ?>
                <div class="detail-row">
                    <span class="detail-label">Supervisor</span>
                    <div class="detail-value"><?php echo htmlspecialchars($ticket['supervisor_name']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- OT Information -->
            <div class="detail-section">
                <h3>⏰ Overtime Details</h3>
                <div class="detail-row">
                    <span class="detail-label">OT Date</span>
                    <div class="detail-value"><?php echo formatDate($ticket['ot_date']); ?></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Time</span>
                    <div class="detail-value"><?php echo formatTime($ticket['ot_start_time']); ?></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">End Time</span>
                    <div class="detail-value"><?php echo formatTime($ticket['ot_end_time']); ?></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total OT Hours</span>
                    <div class="detail-value large">
                        <?php echo number_format($ticket['ot_hours'], 2); ?> hrs
                    </div>
                </div>
            </div>

            <!-- Ticket Information -->
            <div class="detail-section">
                <h3>🎫 Ticket Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Ticket Number</span>
                    <div class="detail-value">
                        <?php echo htmlspecialchars($ticket['ticket_number'] ?? 'N/A'); ?>
                        <?php if ($ticket['duplicate_count'] > 0 && ($is_team_lead || $is_manager)): ?>
                            <span class="duplicate-badge-inline">
                                DUP x<?php echo ($ticket['duplicate_count'] + 1); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <div class="detail-value">
                        <span class="status-<?php echo $ticket['status']; ?>" style="padding: 0.5rem 1rem; border-radius: 20px; display: inline-block;">
                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Customer Name</span>
                    <div class="detail-value"><?php echo htmlspecialchars($ticket['customer_name'] ?: 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Issue Description (if exists) -->
        <?php if (!empty($ticket['issue_description']) && $ticket['issue_description'] != 'OT ticket'): ?>
        <div class="card">
            <div class="card-header">
                <h3>📋 Issue Description</h3>
            </div>
            <div style="padding: 1.5rem;">
                <?php echo nl2br(htmlspecialchars($ticket['issue_description'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resolution Notes (if exists) -->
        <?php if (!empty($ticket['resolution_notes'])): ?>
        <div class="card">
            <div class="card-header">
                <h3>✅ Resolution Notes</h3>
            </div>
            <div style="padding: 1.5rem;">
                <?php echo nl2br(htmlspecialchars($ticket['resolution_notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="view_tickets.php" class="btn btn-secondary">← Back to List</a>
            <?php if ($is_team_lead || $is_manager): ?>
                <a href="edit_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-primary">✏️ Edit Ticket</a>
                <a href="delete_ticket.php?id=<?php echo $ticket['id']; ?>" 
                   class="btn btn-danger" 
                   onclick="return confirm('Are you sure you want to delete this ticket?');">
                    🗑️ Delete Ticket
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
        </div>
    </div>
</body>
</html>