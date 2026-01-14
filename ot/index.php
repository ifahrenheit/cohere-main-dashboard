<?php
// ot/index.php
// OT Tracker Dashboard

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

// Get statistics based on role
$stats = [];

// Build base query filter based on user role
if ($is_manager) {
    // Managers/Directors/Admin see ALL tickets
    $base_filter = "1=1";
} elseif ($is_team_lead) {
    // Team leads see only their supervised agents' tickets
    $supervised_ids = getSupervisedAgentIDs($current_employee_id);
    if (empty($supervised_ids)) {
        $base_filter = "ot.agent_id = '" . $conn->real_escape_string($current_employee_id) . "'";
    } else {
        $supervised_ids_str = "'" . implode("','", array_map(function($id) use ($conn) {
            return $conn->real_escape_string($id);
        }, $supervised_ids)) . "'";
        $base_filter = "ot.agent_id IN ($supervised_ids_str)";
    }
} else {
    // Regular agents see only their own tickets
    $base_filter = "ot.agent_id = '" . $conn->real_escape_string($current_employee_id) . "'";
}

// Total tickets (exclude deleted OT requests only)
$query = "SELECT COUNT(DISTINCT ot.id) as total 
          FROM ot_tickets ot
          LEFT JOIN ot_requests otr ON ot.ot_request_id = otr.id
          WHERE $base_filter
          AND (otr.deleted_at IS NULL OR ot.ot_request_id IS NULL)";
$result = $conn->query($query);
$stats['total_tickets'] = $result->fetch_assoc()['total'];

// Total OT hours this month (from ot_requests - only non-deleted)
$base_filter_requests = str_replace('ot.agent_id', 'otr.employee_id', $base_filter);
$query = "SELECT SUM(TIMESTAMPDIFF(MINUTE, 
          CONCAT(otr.ot_date, ' ', otr.start_time), 
          IF(otr.end_time < otr.start_time,
             DATE_ADD(CONCAT(otr.ot_date, ' ', otr.end_time), INTERVAL 1 DAY),
             CONCAT(otr.ot_date, ' ', otr.end_time))
          ) / 60) as total_hours 
          FROM ot_requests otr
          WHERE MONTH(otr.ot_date) = MONTH(CURDATE()) 
          AND YEAR(otr.ot_date) = YEAR(CURDATE())
          AND otr.deleted_at IS NULL
          AND $base_filter_requests";
$result = $conn->query($query);
$stats['total_hours'] = $result->fetch_assoc()['total_hours'] ?? 0;

// Pending OT approvals (only for managers/TLs)
if ($is_team_lead || $is_manager) {
    $query = "SELECT COUNT(DISTINCT otr.id) as pending_approvals
              FROM ot_requests otr
              WHERE otr.status = 'Pending'
              AND otr.deleted_at IS NULL
              AND $base_filter_requests";
    $result = $conn->query($query);
    $stats['pending_approvals'] = $result->fetch_assoc()['pending_approvals'];
}

// Duplicate tickets count (for team leads and managers)
if ($is_team_lead || $is_manager) {
    $dup_query = "SELECT COUNT(DISTINCT ot.id) as dup_count 
                  FROM ot_tickets ot
                  LEFT JOIN ot_requests otr ON ot.ot_request_id = otr.id
                  WHERE EXISTS (
                      SELECT 1 FROM ot_tickets t2 
                      LEFT JOIN ot_requests otr2 ON t2.ot_request_id = otr2.id
                      WHERE ot.ticket_number = t2.ticket_number 
                      AND ot.agent_id = t2.agent_id 
                      AND ot.ot_date = t2.ot_date
                      AND ot.id != t2.id
                      AND (otr2.deleted_at IS NULL OR t2.ot_request_id IS NULL)
                  )
                  AND $base_filter
                  AND (otr.deleted_at IS NULL OR ot.ot_request_id IS NULL)";
    $dup_result = $conn->query($dup_query);
    $stats['duplicate_tickets'] = $dup_result->fetch_assoc()['dup_count'];
}

// Recent tickets (last 10) - Show ALL statuses
$query = "SELECT 
            ot.*, 
            CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
            CONCAT(s.FirstName, ' ', s.LastName) as supervisor_name,
            COALESCE(
                (SELECT sup.FirstName 
                 FROM supervisor_mapping sm
                 JOIN Employees sup ON sm.supervisor_email = sup.Email
                 WHERE sm.agent_email = e.Email
                 LIMIT 1), 
                'N/A'
            ) as team,
            otr.status as ot_approval_status,
            otr.ot_type,
            (SELECT COUNT(*) FROM ot_tickets t2 
             LEFT JOIN ot_requests otr2 ON t2.ot_request_id = otr2.id
             WHERE t2.ticket_number = ot.ticket_number 
             AND t2.agent_id = ot.agent_id 
             AND t2.ot_date = ot.ot_date
             AND t2.id != ot.id
             AND (otr2.deleted_at IS NULL OR t2.ot_request_id IS NULL)) as duplicate_count
          FROM ot_tickets ot
          LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
          LEFT JOIN Employees s ON ot.supervisor_id = s.EmployeeID
          LEFT JOIN ot_requests otr ON ot.ot_request_id = otr.id
          WHERE $base_filter
          AND (otr.deleted_at IS NULL OR ot.ot_request_id IS NULL)
          ORDER BY ot.created_at DESC LIMIT 10";
$recent_tickets = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OT Tracker - Dashboard</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    <style>
        .ot-approval-badge {
            padding: 0.25rem 0.65rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 0.5rem;
        }
        
        .ot-approval-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .ot-approval-badge.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .ot-approval-badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="/dashboard.php" class="back-link">← Back to Main Dashboard</a>
        </div>

        <div class="ot-header">
            <h1>⏰ OT Tracker Dashboard</h1>
            <p>Track overtime tickets and hours - <?php echo htmlspecialchars($current_user['full_name'] ?? 'User'); ?></p>
            <span class="role-badge">
                <?php 
                if ($is_manager) {
                    echo "👔 " . ucfirst($current_user['role']) . " - Full Access";
                } elseif ($is_team_lead) {
                    echo "👥 Team Lead - Team View";
                } else {
                    echo "👤 Agent - Personal View";
                }
                ?>
            </span>
        </div>

        <div class="content">
            <?php if (!$is_team_lead && !$is_manager): ?>
                <!-- Agent Action Section -->
                <div class="agent-action-section">
                    <div>
                        <h2 style="margin: 0 0 0.5rem 0; font-size: 1.5rem;">📋 Submit Your OT Tickets</h2>
                        <p style="margin: 0; opacity: 0.9; font-size: 1rem;">
                            Track your overtime work by submitting ticket numbers and hours worked.
                        </p>
                        <p style="margin: 0.5rem 0 0 0; opacity: 0.8; font-size: 0.85rem;">
                            💡 Tip: You can submit multiple tickets at once!
                        </p>
                    </div>
                    <div>
                        <a href="submit_ticket.php" class="btn" style="background: white; color: var(--primary-color); 
                           padding: 1rem 2rem; font-size: 1.1rem; font-weight: 700; 
                           border-radius: 8px; text-decoration: none; display: inline-block;
                           box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: transform 0.2s;">
                            ➕ Submit OT Tickets
                        </a>
                    </div>
                </div>

                <!-- Quick Stats for Agent -->
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div class="stat-card">
                        <h3>Your Total Tickets</h3>
                        <p class="stat-value"><?php echo number_format($stats['total_tickets']); ?></p>
                        <p class="stat-label">All time</p>
                    </div>
                    
                    <div class="stat-card">
                        <h3>OT Hours This Month</h3>
                        <p class="stat-value"><?php echo number_format($stats['total_hours'], 1); ?></p>
                        <p class="stat-label"><?php echo date('F Y'); ?></p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Manager/Team Lead Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Total Tickets</h3>
                        <p class="stat-value"><?php echo number_format($stats['total_tickets']); ?></p>
                        <p class="stat-label">
                            <?php 
                            if ($is_manager) echo "All agents";
                            elseif ($is_team_lead) echo "Your team";
                            else echo "Your tickets";
                            ?>
                        </p>
                    </div>
                    
                    <div class="stat-card">
                        <h3>OT Hours This Month</h3>
                        <p class="stat-value"><?php echo number_format($stats['total_hours'], 1); ?></p>
                        <p class="stat-label"><?php echo date('F Y'); ?></p>
                    </div>
                    
                    <?php if (isset($stats['pending_approvals']) && $stats['pending_approvals'] > 0): ?>
                    <div class="stat-card warning">
                        <h3>⏳ Pending OT Approvals</h3>
                        <p class="stat-value"><?php echo number_format($stats['pending_approvals']); ?></p>
                        <p class="stat-label">
                            <a href="/modules/overtime/approve.php" style="color: #ff9800; text-decoration: underline;">Review approvals</a>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if (($is_team_lead || $is_manager) && isset($stats['duplicate_tickets']) && $stats['duplicate_tickets'] > 0): ?>
                    <div class="stat-card warning">
                        <h3>⚠️ Duplicate Tickets</h3>
                        <p class="stat-value"><?php echo number_format($stats['duplicate_tickets']); ?></p>
                        <p class="stat-label">
                            <a href="view_tickets.php?show_duplicates=1" style="color: #ff9800; text-decoration: underline;">Review duplicates</a>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <h2>Recent OT Tickets</h2>
                    <a href="view_tickets.php" class="btn btn-secondary btn-sm">View All</a>
                </div>
                
                <?php if ($recent_tickets && $recent_tickets->num_rows > 0): ?>
                    <?php
                    // Group tickets by submission (agent_id, date, time range)
                    $grouped_tickets = [];
                    $recent_tickets->data_seek(0);
                    while ($ticket = $recent_tickets->fetch_assoc()) {
                        $key = $ticket['agent_id'] . '|' . $ticket['ot_date'] . '|' . $ticket['ot_start_time'] . '|' . $ticket['ot_end_time'];
                        if (!isset($grouped_tickets[$key])) {
                            $grouped_tickets[$key] = [
                                'info' => $ticket,
                                'tickets' => []
                            ];
                        }
                        $grouped_tickets[$key]['tickets'][] = $ticket;
                    }
                    ?>
                    
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Date / Agent</th>
                                <?php if ($is_team_lead || $is_manager): ?>
                                <th>Team</th>
                                <?php endif; ?>
                                <th>Ticket #</th>
                                <th style="text-align: center;">OT Hours</th>
                                <th>Time Range</th>
                                <th style="text-align: center;">OT Approval</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $group_count = 0; ?>
                            <?php foreach ($grouped_tickets as $group): ?>
                                <?php 
                                $first_ticket = $group['info'];
                                $ticket_count = count($group['tickets']);
                                
                                // Calculate ACTUAL total hours from time range
                                $start_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_start_time']);
                                $end_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_end_time']);
                                
                                if ($end_time <= $start_time) {
                                    $end_time += 86400;
                                }
                                
                                $total_hours = ($end_time - $start_time) / 3600;
                                ?>
                                
                                <!-- Submission Group Header -->
                                <tr class="submission-group-row">
                                    <td>
                                        <div style="font-size: 0.95rem; color: var(--primary-color); font-weight: 700;">
                                            <?php echo formatDate($first_ticket['ot_date']); ?>
                                        </div>
                                        <div style="font-size: 1rem; margin-top: 0.25rem;">
                                            <?php echo htmlspecialchars($first_ticket['agent_name']); ?>
                                        </div>
                                    </td>
                                    <?php if ($is_team_lead || $is_manager): ?>
                                    <td style="font-weight: 500;">
                                        <?php echo htmlspecialchars($first_ticket['team'] ?? 'N/A'); ?>
                                    </td>
                                    <?php endif; ?>
                                    <td style="color: #999; font-size: 0.85rem; font-weight: 500;">
                                        <?php echo $ticket_count; ?> ticket<?php echo $ticket_count > 1 ? 's' : ''; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="ot-hours"><?php echo number_format($total_hours, 2); ?></span>
                                        <span style="font-size: 0.75rem; color: #999;"> hrs</span>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.95rem;">
                                            <?php echo formatTime($first_ticket['ot_start_time']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #999;">
                                            to <?php echo formatTime($first_ticket['ot_end_time']); ?>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($first_ticket['ot_approval_status'] === 'Pending'): ?>
                                            <span class="ot-approval-badge pending">⏳ Pending</span>
                                        <?php elseif ($first_ticket['ot_approval_status'] === 'Approved'): ?>
                                            <span class="ot-approval-badge approved">✓ Approved</span>
                                        <?php elseif ($first_ticket['ot_approval_status'] === 'Rejected'): ?>
                                            <span class="ot-approval-badge rejected">✗ Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td></td>
                                </tr>
                                
                                <!-- Individual Tickets -->
                                <?php foreach ($group['tickets'] as $ticket): ?>
                                    <?php
                                    $is_duplicate = (isset($ticket['duplicate_count']) && $ticket['duplicate_count'] > 0);
                                    ?>
                                    <tr class="ticket-item-row<?php echo $is_duplicate ? ' duplicate' : ''; ?>">
                                        <td class="ticket-number-cell">
                                            🎫 <?php echo htmlspecialchars($ticket['ticket_number'] ?? 'N/A'); ?>
                                            <?php if (($is_team_lead || $is_manager) && $is_duplicate): ?>
                                                <span class="duplicate-badge">
                                                    DUP x<?php echo ($ticket['duplicate_count'] + 1); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($is_team_lead || $is_manager): ?>
                                        <td></td>
                                        <?php endif; ?>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td style="text-align: center;">
                                            <a href="ticket_detail.php?id=<?php echo $ticket['id']; ?>" class="btn-view" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- Spacer between groups -->
                                <?php $group_count++; ?>
                                <?php if ($group_count < count($grouped_tickets)): ?>
                                <tr class="group-spacer">
                                    <td colspan="<?php echo ($is_team_lead || $is_manager) ? '7' : '6'; ?>"></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>📋 No OT tickets yet!</p>
                        <?php if (!$is_team_lead && !$is_manager): ?>
                            <a href="submit_ticket.php" class="btn btn-primary">Submit Your First Ticket</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h2>Quick Actions</h2>
                <div class="action-buttons">
                    <?php if (!$is_team_lead && !$is_manager): ?>
                        <!-- Agent Actions -->
                        <a href="view_tickets.php" class="btn btn-secondary">
                            📋 View All My Tickets
                        </a>
                        <a href="submit_ticket.php" class="btn btn-primary">
                            ➕ Submit More Tickets
                        </a>
                    <?php else: ?>
                        <!-- Supervisor Actions -->
                        <a href="view_tickets.php" class="btn btn-secondary">
        📋 View All Tickets
    </a>

    <!-- NEW: Team Leads & Managers can file OT -->
    <a href="submit_ticket.php" class="btn btn-primary">
        ➕ File OT
    </a>

    <?php if ($is_manager): ?>
        <a href="/modules/overtime/approve.php" class="btn" style="background: #2196f3; color: white;">
            ✓ Approve OT Hours
        </a>
    <?php endif; ?>

    <?php if ($is_team_lead || $is_manager): ?>
        <a href="view_tickets.php?show_duplicates=1" class="btn" style="background: #ff9800; color: white;">
            ⚠️ Review Duplicates
        </a>
    <?php endif; ?>

    <?php if ($is_manager): ?>
        <a href="reports.php" class="btn btn-success">
            📊 Reports & Analytics
        </a>
    <?php endif; ?>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>