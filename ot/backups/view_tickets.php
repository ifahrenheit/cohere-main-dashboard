<?php
// ot/view_tickets.php
// View all OT tickets with role-based permissions and duplicate detection

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

$current_user['EmployeeID'] = $current_employee_id;
$is_team_lead = isTeamLead();
$is_manager = isManagerOrAbove();

// Get filter parameters
$view_type = isset($_GET['view']) ? $_GET['view'] : 'default';
$show_duplicates = isset($_GET['show_duplicates']) ? true : false;
$filter_team = isset($_GET['team']) ? $_GET['team'] : '';
$filter_agent = isset($_GET['agent']) ? $_GET['agent'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query with duplicate detection
$select_clause = "SELECT 
            ot.*, 
            CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
            e.team as agent_team,
            CONCAT(s.FirstName, ' ', s.LastName) as supervisor_name";

$from_clause = "FROM ot_tickets ot
          LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
          LEFT JOIN Employees s ON ot.supervisor_id = s.EmployeeID";

$where_clause = "WHERE 1=1";

// Apply role-based view filter
if ($is_manager) {
    // Managers/Directors/Admin - view ALL tickets
    // No additional filter
} elseif ($is_team_lead) {
    // Team Leads - view their supervised agents' tickets
    $supervised_ids = getSupervisedAgentIDs($current_employee_id);
    if (empty($supervised_ids)) {
        $where_clause .= " AND 1=0"; // Show nothing if no supervised agents
    } else {
        $supervised_ids_str = "'" . implode("','", array_map(function($id) use ($conn) {
            return $conn->real_escape_string($id);
        }, $supervised_ids)) . "'";
        $where_clause .= " AND ot.agent_id IN ($supervised_ids_str)";
    }
} else {
    // Regular agents - only see their own tickets
    $where_clause .= " AND ot.agent_id = '" . $conn->real_escape_string($current_employee_id) . "'";
}

// Apply team lead filter
if (!empty($filter_team)) {
    // Filter by team lead - get all agents supervised by this team lead
    $supervised_agent_ids = getSupervisedAgentIDs($filter_team);
    
    if (!empty($supervised_agent_ids)) {
        $agent_ids_str = "'" . implode("','", array_map(function($id) use ($conn) {
            return $conn->real_escape_string($id);
        }, $supervised_agent_ids)) . "'";
        $where_clause .= " AND ot.agent_id IN ($agent_ids_str)";
    } else {
        // If no supervised agents found, show no results
        $where_clause .= " AND 1=0";
    }
}

// Apply agent filter
if (!empty($filter_agent)) {
    $where_clause .= " AND ot.agent_id = '" . $conn->real_escape_string($filter_agent) . "'";
}

// Apply status filter
if (!empty($filter_status)) {
    $where_clause .= " AND ot.status = '" . $conn->real_escape_string($filter_status) . "'";
}

// Apply date filters
if (!empty($filter_date_from)) {
    $where_clause .= " AND ot.ot_date >= '" . $conn->real_escape_string($filter_date_from) . "'";
}

if (!empty($filter_date_to)) {
    $where_clause .= " AND ot.ot_date <= '" . $conn->real_escape_string($filter_date_to) . "'";
}

// Filter for duplicates only if requested - use a subquery approach
if ($show_duplicates && ($is_team_lead || $is_manager)) {
    $where_clause .= " AND EXISTS (
        SELECT 1 FROM ot_tickets t2 
        WHERE t2.ticket_number = ot.ticket_number 
        AND t2.agent_id = ot.agent_id 
        AND t2.ot_date = ot.ot_date
        AND t2.id != ot.id
    )";
}

// Add duplicate count to select
$select_clause .= ", (SELECT COUNT(*) FROM ot_tickets t2 
         WHERE t2.ticket_number = ot.ticket_number 
         AND t2.agent_id = ot.agent_id 
         AND t2.ot_date = ot.ot_date
         AND t2.id != ot.id) as duplicate_count";

$order_clause = "ORDER BY ot.ot_date DESC, ot.created_at DESC";

// Build final query
$query = "$select_clause $from_clause $where_clause $order_clause";

$tickets = $conn->query($query);

// Check for SQL error
if (!$tickets) {
    die("Database error: " . $conn->error . "<br><br>Query: " . htmlspecialchars($query));
}

// Get summary statistics
$stats_query = "SELECT COUNT(*) as total, SUM(ot.ot_hours) as total_hours 
                $from_clause 
                $where_clause";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get all teams (team leads)
$teams = getAllTeams();

// Get all agents (based on role)
if ($is_manager) {
    $agents_query = "SELECT EmployeeID, FirstName, LastName, team FROM Employees WHERE IsVerified = 1 ORDER BY FirstName, LastName";
} elseif ($is_team_lead) {
    $supervised_ids = getSupervisedAgentIDs($current_employee_id);
    if (!empty($supervised_ids)) {
        $supervised_ids_str = "'" . implode("','", array_map(function($id) use ($conn) {
            return $conn->real_escape_string($id);
        }, $supervised_ids)) . "'";
        $agents_query = "SELECT EmployeeID, FirstName, LastName, team FROM Employees WHERE EmployeeID IN ($supervised_ids_str) ORDER BY FirstName, LastName";
    } else {
        $agents_query = "SELECT EmployeeID, FirstName, LastName, team FROM Employees WHERE EmployeeID = '$current_employee_id'";
    }
} else {
    $agents_query = "SELECT EmployeeID, FirstName, LastName, team FROM Employees WHERE EmployeeID = '$current_employee_id'";
}
$agents_result = $conn->query($agents_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View OT Tickets</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <style>
        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e0e0e0;
            flex-wrap: wrap;
        }
        .view-tab {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: none;
            cursor: pointer;
            color: #666;
            font-weight: 500;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        .view-tab:hover {
            color: #667eea;
            background: #f8f9fa;
        }
        .view-tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
            font-weight: 600;
        }
        .duplicate-row {
            background: #fff3cd !important;
        }
        .duplicate-badge {
            background: #ff9800;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .filters-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .filter-group label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.25rem;
            font-weight: 500;
            display: block;
        }
        .stats-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .stats-bar .stat {
            text-align: center;
        }
        .stats-bar .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .stats-bar .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to OT Dashboard</a>
        </div>

        <div class="page-header">
            <div>
                <h1>📋 OT Tickets <?php if ($show_duplicates) echo "- Duplicate Review"; ?></h1>
                <p>
                    <?php 
                    if ($is_manager) {
                        echo "All company tickets";
                    } elseif ($is_team_lead) {
                        echo "Your team's tickets";
                    } else {
                        echo "Your overtime tickets";
                    }
                    ?>
                </p>
            </div>
            <?php if (!$is_team_lead && !$is_manager): ?>
            <div>
                <a href="submit_ticket.php" class="btn btn-primary">+ Submit Tickets</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($is_team_lead || $is_manager): ?>
        <div class="view-tabs">
            <?php if ($is_manager): ?>
                <a href="?view=all" class="view-tab <?php echo !$show_duplicates ? 'active' : ''; ?>">
                    🌐 All Company Tickets
                </a>
            <?php elseif ($is_team_lead): ?>
                <a href="?view=supervised" class="view-tab <?php echo !$show_duplicates ? 'active' : ''; ?>">
                    👥 Team Tickets
                </a>
            <?php endif; ?>
            <a href="?show_duplicates=1" class="view-tab <?php echo $show_duplicates ? 'active' : ''; ?>" style="color: #ff9800;">
                ⚠️ Duplicates Only
            </a>
        </div>
        <?php endif; ?>

        <div class="stats-bar">
            <div class="stat">
                <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat">
                <div class="stat-value"><?php echo number_format($stats['total_hours'] ?? 0, 1); ?></div>
                <div class="stat-label">Total OT Hours</div>
            </div>
        </div>

        <div class="filters-section">
            <h3 style="margin-top: 0;">🔍 Filters</h3>
            <form method="GET" action="">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_type); ?>">
                <?php if ($show_duplicates): ?>
                    <input type="hidden" name="show_duplicates" value="1">
                <?php endif; ?>
                
                <div class="filters-grid">
    <?php if ($is_manager): ?>
    <!-- Team Lead filter - Managers only -->
    <div class="filter-group">
        <label>Team Lead</label>
        <select name="team" class="form-control">
            <option value="">All Team Leads</option>
            <?php foreach ($teams as $team): ?>
                <option value="<?php echo htmlspecialchars($team['id']); ?>" 
                        <?php echo $filter_team === $team['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($team['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <?php if ($is_team_lead || $is_manager): ?>
    <!-- Agent filter - Team Leads and Managers -->
    <div class="filter-group">
        <label>Agent</label>
        <select name="agent" class="form-control">
            <option value="">All Agents</option>
            <?php 
            if ($agents_result) {
                $agents_result->data_seek(0);
                while ($agent = $agents_result->fetch_assoc()): 
            ?>
                <option value="<?php echo $agent['EmployeeID']; ?>" 
                        <?php echo $filter_agent == $agent['EmployeeID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($agent['FirstName'] . ' ' . $agent['LastName']); ?>
                </option>
            <?php 
                endwhile;
            }
            ?>
        </select>
    </div>
    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <?php foreach (TICKET_STATUS as $key => $label): ?>
                                <option value="<?php echo $key; ?>" 
                                        <?php echo $filter_status === $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="?view=<?php echo htmlspecialchars($view_type); ?><?php echo $show_duplicates ? '&show_duplicates=1' : ''; ?>" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Tickets (<?php echo $tickets ? $tickets->num_rows : 0; ?>)</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary btn-sm">🖨️ Print</button>
                </div>
            </div>
            
            <?php if ($tickets && $tickets->num_rows > 0): ?>
    <?php
    // Group tickets by submission (agent_id, date, time range)
    $grouped_tickets = [];
    $tickets->data_seek(0);
    while ($ticket = $tickets->fetch_assoc()) {
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
    
    <style>
        .tickets-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .tickets-table thead th {
            background: #667eea;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
        }
        
        .tickets-table thead th:first-child {
            border-radius: 8px 0 0 0;
        }
        
        .tickets-table thead th:last-child {
            border-radius: 0 8px 0 0;
        }
        
        /* Submission header row */
        .submission-group-row {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 3px solid #667eea;
        }
        
        .submission-group-row td {
            padding: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        .submission-group-row .ot-hours {
            color: #667eea;
            font-size: 1.3rem;
            font-weight: 700;
        }
        
        /* Individual ticket rows */
        .ticket-item-row {
            background: white;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .ticket-item-row:hover {
            background: #f8f9fa;
        }
        
        .ticket-item-row.duplicate {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
        }
        
        .ticket-item-row.duplicate:hover {
            background: #ffe8a3;
        }
        
        .ticket-item-row td {
            padding: 0.75rem 1rem;
        }
        
        .ticket-item-row td:first-child {
            padding-left: 3rem; /* Indent to show it's a child */
        }
        
        /* Spacing between groups */
        .group-spacer {
            height: 1rem;
            background: #f5f5f5;
        }
        
        .ticket-number-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 1rem;
        }
        
        @media (max-width: 768px) {
            .tickets-table {
                font-size: 0.85rem;
            }
            
            .tickets-table th,
            .tickets-table td {
                padding: 0.5rem;
            }
        }
    </style>
    
    <table class="tickets-table">
        <thead>
            <tr>
                <th>Date / Agent</th>
                <?php if ($is_team_lead || $is_manager): ?>
                <th>Supervisor</th>
                <?php endif; ?>
                <th>Ticket #</th>
                <th style="text-align: center;">OT Hours</th>
                <th>Time Range</th>
                <th style="text-align: center;">Status</th>
                <th style="text-align: center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped_tickets as $group_index => $group): ?>
                <?php 
                $first_ticket = $group['info'];
                $ticket_count = count($group['tickets']);
                
                // Calculate ACTUAL total hours from time range
                $start_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_start_time']);
                $end_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_end_time']);
                $total_hours = ($end_time - $start_time) / 3600;
                ?>
                
                <!-- Submission Group Header -->
                <tr class="submission-group-row">
                    <td>
                        <div style="font-size: 0.95rem; color: #667eea; font-weight: 700;">
                            <?php echo formatDate($first_ticket['ot_date']); ?>
                        </div>
                        <div style="font-size: 1rem; margin-top: 0.25rem;">
                            <?php echo htmlspecialchars($first_ticket['agent_name']); ?>
                        </div>
                    </td>
                    <?php if ($is_team_lead || $is_manager): ?>
                    <td style="font-weight: 500;">
                        <?php echo htmlspecialchars($first_ticket['supervisor_name'] ?? 'N/A'); ?>
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
                    <td colspan="2"></td>
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
                                <span class="duplicate-badge" style="margin-left: 0.5rem;">
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
                        <td style="text-align: center;">
                            <span class="status-<?php echo $ticket['status']; ?>" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">
                                <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <a href="ticket_detail.php?id=<?php echo $ticket['id']; ?>" class="btn-view" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">View</a>
                            <?php if ($is_team_lead || $is_manager): ?>
                                <a href="edit_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn-edit" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Spacer between groups -->
                <?php if ($group_index < count($grouped_tickets) - 1): ?>
                <tr class="group-spacer">
                    <td colspan="<?php echo ($is_team_lead || $is_manager) ? '7' : '6'; ?>"></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="no-data">
        <p>📋 No tickets found matching your filters.</p>
        <?php if (!$show_duplicates && !$is_team_lead && !$is_manager): ?>
            <a href="submit_ticket.php" class="btn btn-primary">Submit New Tickets</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
        </div>
    </div>
</body>
</html>