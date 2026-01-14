<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ot/view_tickets.php
// View all OT tickets with filters and CSAT scores

require_once 'config.php';

// ✅ Auto-sync CSAT every 5 minutes
$last_sync_file = __DIR__ . '/cache/last_csat_sync.txt';
$sync_interval = 300; // 5 minutes

if (!file_exists($last_sync_file) || (time() - filemtime($last_sync_file)) > $sync_interval) {
   // require_once 'sync_csat.php';
    // syncCSATFromGoogleSheets();
}

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

// Get filter parameters
$filter_start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default: First day of current month
$filter_end_date = $_GET['end_date'] ?? date('Y-m-t'); // Default: Last day of current month
$filter_status = $_GET['status'] ?? '';
$filter_team_lead = $_GET['team_lead'] ?? '';
$show_duplicates = isset($_GET['show_duplicates']) && $_GET['show_duplicates'] == '1';
$filter_ot_status = $_GET['ot_status'] ?? '';
$filter_csat = $_GET['csat'] ?? '';

// Build base query filter based on user role
if ($is_manager) {
    $base_filter = "1=1";
} elseif ($is_team_lead) {
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
    $base_filter = "ot.agent_id = '" . $conn->real_escape_string($current_employee_id) . "'";
}

// Build the main query
$query = "SELECT 
            ot.*, 
            CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
            CONCAT(s.FirstName, ' ', s.LastName) as supervisor_name,
            supervisor.FirstName as team_lead_name,
            e.team,
            e.supervisor_id as agent_supervisor_id,
            otr.status as ot_approval_status,
            otr.ot_type as ot_request_type,
            otr.regular_rate,
            CONCAT(approver.FirstName, ' ', approver.LastName) as ot_approver_name,
            csat.csat_score,
            csat.csat_type,
            csat.survey_date as csat_date,
            (SELECT COUNT(*) FROM ot_tickets t2 
             WHERE t2.ticket_number = ot.ticket_number 
             AND t2.agent_id = ot.agent_id 
             AND t2.ot_date = ot.ot_date
             AND t2.id != ot.id) as duplicate_count
          FROM ot_tickets ot
          LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
            LEFT JOIN Employees s ON ot.supervisor_id = s.EmployeeID
            LEFT JOIN supervisor_mapping sm ON e.Email = sm.agent_email
            LEFT JOIN Employees supervisor ON sm.supervisor_email = supervisor.Email
          LEFT JOIN ot_requests otr ON ot.ot_request_id = otr.id
          LEFT JOIN Employees approver ON otr.approver = approver.EmployeeID
          LEFT JOIN csat_scores csat ON ot.ticket_number COLLATE utf8mb4_unicode_ci = csat.ticket_number COLLATE utf8mb4_unicode_ci
          WHERE $base_filter
          AND (otr.deleted_at IS NULL OR ot.ot_request_id IS NULL)";

// Apply date range filter
if ($filter_start_date) {
    $query .= " AND ot.ot_date >= '" . $conn->real_escape_string($filter_start_date) . "'";
}
if ($filter_end_date) {
    $query .= " AND ot.ot_date <= '" . $conn->real_escape_string($filter_end_date) . "'";
}

// Apply other filters
if ($filter_status) {
    $query .= " AND ot.status = '" . $conn->real_escape_string($filter_status) . "'";
}

if ($filter_ot_status) {
    $query .= " AND otr.status = '" . $conn->real_escape_string($filter_ot_status) . "'";
}

if ($filter_csat) {
    $query .= " AND csat.csat_type = '" . $conn->real_escape_string($filter_csat) . "'";
}

if ($show_duplicates) {
    $query .= " HAVING duplicate_count > 0";
}

// Team lead filter (only for managers)
if ($is_manager && $filter_team_lead) {
    $tl_supervised = getSupervisedAgentIDs($filter_team_lead);
    if (!empty($tl_supervised)) {
        $tl_ids = "'" . implode("','", array_map(function($id) use ($conn) {
            return $conn->real_escape_string($id);
        }, $tl_supervised)) . "'";
        $query .= " AND ot.agent_id IN ($tl_ids)";
    }
}

$query .= " ORDER BY ot.created_at DESC, ot.ot_date DESC";

$tickets_result = $conn->query($query);

// Get summary stats - tickets
$stats_query = "SELECT 
                COUNT(DISTINCT ot.id) as total_tickets,
                COUNT(DISTINCT CASE WHEN csat.csat_type = 'CSAT' THEN ot.id END) as csat_count,
                COUNT(DISTINCT CASE WHEN csat.csat_type = 'DSAT' THEN ot.id END) as dsat_count,
                COUNT(DISTINCT CASE WHEN (SELECT COUNT(*) FROM ot_tickets t2 
                    WHERE t2.ticket_number = ot.ticket_number 
                    AND t2.agent_id = ot.agent_id 
                    AND t2.ot_date = ot.ot_date 
                    AND t2.id != ot.id) > 0 THEN ot.id END) as duplicates
               FROM ot_tickets ot
               LEFT JOIN ot_requests otr ON ot.ot_request_id = otr.id
               LEFT JOIN csat_scores csat ON ot.ticket_number COLLATE utf8mb4_unicode_ci = csat.ticket_number COLLATE utf8mb4_unicode_ci
               WHERE $base_filter
               AND (otr.deleted_at IS NULL OR ot.ot_request_id IS NULL)";

// Apply date range to stats
if ($filter_start_date) {
    $stats_query .= " AND ot.ot_date >= '" . $conn->real_escape_string($filter_start_date) . "'";
}
if ($filter_end_date) {
    $stats_query .= " AND ot.ot_date <= '" . $conn->real_escape_string($filter_end_date) . "'";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Calculate OT hours by status (filtered by date range)
$ot_hours_filter = $is_manager ? "1=1" : 
                   ($is_team_lead ? "otr.employee_id IN (" . (empty($supervised_ids) ? "''" : $supervised_ids_str) . ")" : 
                   "otr.employee_id = '" . $conn->real_escape_string($current_employee_id) . "'");

$hours_query = "SELECT 
                SUM(CASE WHEN otr.status = 'Pending' THEN 
                    TIMESTAMPDIFF(MINUTE, 
                        CONCAT(otr.ot_date, ' ', otr.start_time), 
                        IF(otr.end_time < otr.start_time, 
                           DATE_ADD(CONCAT(otr.ot_date, ' ', otr.end_time), INTERVAL 1 DAY),
                           CONCAT(otr.ot_date, ' ', otr.end_time)
                        )
                    ) / 60
                ELSE 0 END) as pending_hours,
                SUM(CASE WHEN otr.status = 'Approved' THEN 
                    TIMESTAMPDIFF(MINUTE, 
                        CONCAT(otr.ot_date, ' ', otr.start_time), 
                        IF(otr.end_time < otr.start_time, 
                           DATE_ADD(CONCAT(otr.ot_date, ' ', otr.end_time), INTERVAL 1 DAY),
                           CONCAT(otr.ot_date, ' ', otr.end_time)
                        )
                    ) / 60
                ELSE 0 END) as approved_hours
               FROM ot_requests otr
               WHERE otr.deleted_at IS NULL
               AND $ot_hours_filter";

// Apply date range to OT hours
if ($filter_start_date) {
    $hours_query .= " AND otr.ot_date >= '" . $conn->real_escape_string($filter_start_date) . "'";
}
if ($filter_end_date) {
    $hours_query .= " AND otr.ot_date <= '" . $conn->real_escape_string($filter_end_date) . "'";
}

$hours_result = $conn->query($hours_query);
$hours_stats = $hours_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View OT Tickets - OT Tracker</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    <style>
        .ot-status-badge {
            padding: 0.25rem 0.65rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .ot-status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .ot-status-badge.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .ot-status-badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .csat-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .csat-badge.csat {
            background: #4caf50;
            color: white;
        }
        
        .csat-badge.dsat {
            background: #f44336;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to OT Dashboard</a>
        </div>

        <div class="ot-header">
            <h1>📋 OT Tickets</h1>
            <p>View and manage overtime tickets - <?php echo htmlspecialchars($current_user['full_name']); ?></p>
            <span class="role-badge">
                <?php 
                if ($is_manager) {
                    echo "👔 " . ucfirst($current_user['role']) . " - All Tickets";
                } elseif ($is_team_lead) {
                    echo "👥 Team Lead - Team Tickets";
                } else {
                    echo "👤 Agent - My Tickets";
                }
                ?>
            </span>
        </div>

        <div class="content">
            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($stats['total_tickets']); ?></div>
                    <div class="stat-label">Total Tickets</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($hours_stats['pending_hours'] ?? 0, 1); ?></div>
                    <div class="stat-label">Pending OT Hours</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($hours_stats['approved_hours'] ?? 0, 1); ?></div>
                    <div class="stat-label">Approved OT Hours</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($stats['csat_count']); ?></div>
                    <div class="stat-label">CSAT</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($stats['dsat_count']); ?></div>
                    <div class="stat-label">DSAT</div>
                </div>
                <?php if ($is_team_lead || $is_manager): ?>
                <div class="stat">
                    <div class="stat-value"><?php echo number_format($stats['duplicates']); ?></div>
                    <div class="stat-label">Duplicates</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($filter_start_date); ?>">
                        </div>

                        <div class="filter-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($filter_end_date); ?>">
                        </div>

                        <div class="filter-group">
                            <label>OT Approval Status</label>
                            <select name="ot_status" class="form-control">
                                <option value="">All OT Statuses</option>
                                <option value="Pending" <?php echo $filter_ot_status === 'Pending' ? 'selected' : ''; ?>>⏳ Pending OT</option>
                                <option value="Approved" <?php echo $filter_ot_status === 'Approved' ? 'selected' : ''; ?>>✓ Approved OT</option>
                                <option value="Rejected" <?php echo $filter_ot_status === 'Rejected' ? 'selected' : ''; ?>>✗ Rejected OT</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>CSAT Status</label>
                            <select name="csat" class="form-control">
                                <option value="">All CSAT</option>
                                <option value="CSAT" <?php echo $filter_csat === 'CSAT' ? 'selected' : ''; ?>>😊 CSAT (4-5)</option>
                                <option value="DSAT" <?php echo $filter_csat === 'DSAT' ? 'selected' : ''; ?>>😞 DSAT (1-3)</option>
                            </select>
                        </div>

                        <?php if ($is_manager): ?>
                        <div class="filter-group">
                            <label>Team Lead</label>
                            <select name="team_lead" class="form-control">
                                <option value="">All Teams</option>
                                <?php
                                $teams = getAllTeams();
                                foreach ($teams as $team):
                                ?>
                                    <option value="<?php echo htmlspecialchars($team['id']); ?>" 
                                            <?php echo $filter_team_lead == $team['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($team['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <?php if ($is_team_lead || $is_manager): ?>
                        <div class="filter-group">
                            <label>Duplicates Only</label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                                <input type="checkbox" name="show_duplicates" value="1" 
                                       <?php echo $show_duplicates ? 'checked' : ''; ?>>
                                Show only duplicates
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="view_tickets.php" class="btn btn-secondary">Clear Filters</a>
                    </div>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="card">
                <div class="card-header">
                    <h2>All Tickets</h2>
                    <div style="font-size: 0.85rem; color: var(--text-light); margin-top: 0.5rem;">
                        Showing: <?php echo date('M d, Y', strtotime($filter_start_date)); ?> to <?php echo date('M d, Y', strtotime($filter_end_date)); ?>
                    </div>
                    <?php if (!$is_team_lead && !$is_manager): ?>
                    <a href="submit_ticket.php" class="btn btn-primary btn-sm">➕ Submit New Tickets</a>
                    <?php endif; ?>
                </div>

                <?php if ($tickets_result && $tickets_result->num_rows > 0): ?>
                    <?php
                    // Group tickets by submission
                    $grouped_tickets = [];
                    $tickets_result->data_seek(0);
                    while ($ticket = $tickets_result->fetch_assoc()) {
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
                                <th>Team Lead</th>
                                <?php endif; ?>
                                <th>Ticket #</th>
                                <th style="text-align: center;">CSAT</th>
                                <th style="text-align: center;">OT Hours</th>
                                <th>Time Range</th>
                                <th style="text-align: center;">OT Status</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $group_count = 0; ?>
                            <?php foreach ($grouped_tickets as $group): ?>
                                <?php 
                                $first_ticket = $group['info'];
                                $ticket_count = count($group['tickets']);
                                
                                // Calculate total hours
                                $start_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_start_time']);
                                $end_time = strtotime($first_ticket['ot_date'] . ' ' . $first_ticket['ot_end_time']);
                                
                                if ($end_time <= $start_time) {
                                    $end_time += 86400;
                                }
                                
                                $total_hours = ($end_time - $start_time) / 3600;
                                ?>
                                
                                <!-- Group Header -->
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
                                        <?php echo htmlspecialchars($first_ticket['team_lead_name'] ?? 'N/A'); ?>
                                    </td>
                                    <?php endif; ?>
                                    <td style="color: #999; font-size: 0.85rem; font-weight: 500;">
                                        <?php echo $ticket_count; ?> ticket<?php echo $ticket_count > 1 ? 's' : ''; ?>
                                    </td>
                                    <td></td>
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
                                            <span class="ot-status-badge pending">⏳ Pending</span>
                                        <?php elseif ($first_ticket['ot_approval_status'] === 'Approved'): ?>
                                            <span class="ot-status-badge approved">✓ Approved</span>
                                            <?php if ($first_ticket['ot_approver_name']): ?>
                                                <div style="font-size: 0.75rem; color: #999; margin-top: 0.25rem;">
                                                    by <?php echo htmlspecialchars($first_ticket['ot_approver_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($first_ticket['ot_approval_status'] === 'Rejected'): ?>
                                            <span class="ot-status-badge rejected">✗ Rejected</span>
                                            <?php if ($first_ticket['ot_approver_name']): ?>
                                                <div style="font-size: 0.75rem; color: #999; margin-top: 0.25rem;">
                                                    by <?php echo htmlspecialchars($first_ticket['ot_approver_name']); ?>
                                                </div>
                                            <?php endif; ?>
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
                                        <!-- CSAT Column -->
                                        <td style="text-align: center;">
                                            <?php if ($ticket['csat_type']): ?>
                                                <?php if ($ticket['csat_type'] === 'CSAT'): ?>
                                                    <span class="csat-badge csat">
                                                        😊 CSAT (<?php echo $ticket['csat_score']; ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="csat-badge dsat">
                                                        😞 DSAT (<?php echo $ticket['csat_score']; ?>)
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 0.85rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td style="text-align: center;">
                                            <a href="ticket_detail.php?id=<?php echo $ticket['id']; ?>" 
                                               class="btn-view" 
                                               style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- Spacer -->
                                <?php $group_count++; ?>
                                <?php if ($group_count < count($grouped_tickets)): ?>
                                <tr class="group-spacer">
                                    <td colspan="<?php echo ($is_team_lead || $is_manager) ? '8' : '7'; ?>"></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <h3>📋 No Tickets Found</h3>
                        <p>
                            No tickets found for the selected date range.
                        </p>
                        <?php if (!$is_team_lead && !$is_manager): ?>
                            <a href="submit_ticket.php" class="btn btn-primary">Submit Your First Ticket</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>