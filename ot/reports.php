<?php
// ot/reports.php
// OT Reports and Analytics

require_once 'config.php';

$current_user = getCurrentUserOT();

if (!$current_user) {
    die("Error: Please <a href='/login.php'>log in</a> to access this page.");
}

$is_supervisor = isSupervisorOrManager();

if (!$is_supervisor) {
    die("Access Denied: Only supervisors and managers can view reports.");
}

// Get date range filters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of current month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$selected_team = isset($_GET['team']) ? $_GET['team'] : '';

// Build base query with date filter
$date_filter = "ot.ot_date BETWEEN '" . $conn->real_escape_string($date_from) . "' 
                AND '" . $conn->real_escape_string($date_to) . "'";

if (!empty($selected_team)) {
    $date_filter .= " AND ot.team = '" . $conn->real_escape_string($selected_team) . "'";
}

// 1. Overall Statistics
$stats_query = "SELECT 
                COUNT(*) as total_tickets,
                SUM(ot_hours) as total_hours,
                AVG(ot_hours) as avg_hours,
                COUNT(DISTINCT agent_id) as unique_agents
                FROM ot_tickets ot
                WHERE $date_filter";
$stats_result = $conn->query($stats_query);
$overall_stats = $stats_result->fetch_assoc();

// 2. Top Agents by Ticket Count
$top_agents_query = "SELECT 
                        CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
                        e.team,
                        COUNT(*) as ticket_count,
                        SUM(ot.ot_hours) as total_hours,
                        AVG(ot.ot_hours) as avg_hours
                     FROM ot_tickets ot
                     LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
                     WHERE $date_filter
                     GROUP BY ot.agent_id
                     ORDER BY ticket_count DESC
                     LIMIT 10";
$top_agents_result = $conn->query($top_agents_query);

// 3. Agents with Duplicates
$duplicates_query = "SELECT 
                        CONCAT(e.FirstName, ' ', e.LastName) as agent_name,
                        e.team,
                        ot.ticket_number,
                        ot.ot_date,
                        COUNT(*) as duplicate_count
                     FROM ot_tickets ot
                     LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
                     WHERE $date_filter
                     GROUP BY ot.agent_id, ot.ticket_number, ot.ot_date
                     HAVING duplicate_count > 1
                     ORDER BY duplicate_count DESC, agent_name";
$duplicates_result = $conn->query($duplicates_query);

// 4. Daily Summary
$daily_query = "SELECT 
                    ot.ot_date,
                    COUNT(*) as ticket_count,
                    SUM(ot.ot_hours) as total_hours,
                    COUNT(DISTINCT ot.agent_id) as agent_count
                FROM ot_tickets ot
                WHERE $date_filter
                GROUP BY ot.ot_date
                ORDER BY ot.ot_date DESC";
$daily_result = $conn->query($daily_query);

// 5. Team Statistics
$team_query = "SELECT 
                    e.team,
                    COUNT(*) as ticket_count,
                    SUM(ot.ot_hours) as total_hours,
                    COUNT(DISTINCT ot.agent_id) as agent_count
               FROM ot_tickets ot
               LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
               WHERE $date_filter AND e.team IS NOT NULL
               GROUP BY e.team
               ORDER BY ticket_count DESC";
$team_result = $conn->query($team_query);

$teams = getAllTeams();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OT Reports & Analytics</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to Dashboard</a>
        </div>

        <div class="report-header">
            <h1>📊 OT Reports & Analytics</h1>
            <p>Overtime productivity tracking and duplicate detection</p>
        </div>

        <!-- Date Range Filter -->
        <div class="filters-section">
            <h3 style="margin-top: 0;">📅 Report Period</h3>
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group">
                        <label>Team</label>
                        <select name="team" class="form-control">
                            <option value="">All Teams</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?php echo htmlspecialchars($team); ?>" 
                                        <?php echo $selected_team === $team ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($team); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Overall Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Tickets</h3>
                <div class="stat-value"><?php echo number_format($overall_stats['total_tickets']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total OT Hours</h3>
                <div class="stat-value"><?php echo number_format($overall_stats['total_hours'], 1); ?></div>
            </div>
            <div class="stat-card">
                <h3>Average Hours/Ticket</h3>
                <div class="stat-value"><?php echo number_format($overall_stats['avg_hours'], 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Agents</h3>
                <div class="stat-value"><?php echo number_format($overall_stats['unique_agents']); ?></div>
            </div>
        </div>

        <!-- Top Agents -->
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h2>🏆 Top Agents by Ticket Count</h2>
                <button onclick="window.print()" class="btn btn-secondary btn-sm">🖨️ Print</button>
            </div>
            <?php if ($top_agents_result && $top_agents_result->num_rows > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Agent Name</th>
                            <th>Team</th>
                            <th>Tickets</th>
                            <th>Total Hours</th>
                            <th>Avg Hours/Ticket</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        while ($agent = $top_agents_result->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><strong><?php echo $rank++; ?></strong></td>
                                <td><?php echo htmlspecialchars($agent['agent_name']); ?></td>
                                <td><?php echo htmlspecialchars($agent['team'] ?? 'N/A'); ?></td>
                                <td><strong><?php echo number_format($agent['ticket_count']); ?></strong></td>
                                <td><?php echo number_format($agent['total_hours'], 2); ?> hrs</td>
                                <td><?php echo number_format($agent['avg_hours'], 2); ?> hrs</td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">No data available for selected period.</div>
            <?php endif; ?>
        </div>

        <!-- Duplicate Tickets Alert -->
        <?php if ($duplicates_result && $duplicates_result->num_rows > 0): ?>
        <div class="card duplicate-alert" style="margin-bottom: 2rem;">
            <div class="card-header" style="background: #ff9800; color: white;">
                <h2>⚠️ Duplicate Tickets Detected (<?php echo $duplicates_result->num_rows; ?>)</h2>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Agent Name</th>
                        <th>Team</th>
                        <th>Ticket Number</th>
                        <th>Date</th>
                        <th>Occurrences</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($dup = $duplicates_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dup['agent_name']); ?></td>
                            <td><?php echo htmlspecialchars($dup['team'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo htmlspecialchars($dup['ticket_number']); ?></strong></td>
                            <td><?php echo formatDate($dup['ot_date']); ?></td>
                            <td>
                                <span style="background: #ff9800; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600;">
                                    <?php echo $dup['duplicate_count']; ?>x
                                </span>
                            </td>
                            <td>
                                <a href="view_tickets.php?show_duplicates=1&date_from=<?php echo $dup['ot_date']; ?>&date_to=<?php echo $dup['ot_date']; ?>" 
                                   class="btn-view">Review</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Team Statistics -->
        <?php if ($team_result && $team_result->num_rows > 0): ?>
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h2>👥 Team Performance</h2>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Total Tickets</th>
                        <th>Total Hours</th>
                        <th>Active Agents</th>
                        <th>Avg Tickets/Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($team = $team_result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($team['team']); ?></strong></td>
                            <td><?php echo number_format($team['ticket_count']); ?></td>
                            <td><?php echo number_format($team['total_hours'], 2); ?> hrs</td>
                            <td><?php echo number_format($team['agent_count']); ?></td>
                            <td><?php echo number_format($team['ticket_count'] / $team['agent_count'], 1); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Daily Summary -->
        <div class="card">
            <div class="card-header">
                <h2>📅 Daily Summary</h2>
            </div>
            <?php if ($daily_result && $daily_result->num_rows > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tickets</th>
                            <th>Total Hours</th>
                            <th>Active Agents</th>
                            <th>Avg Hours/Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($daily = $daily_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo formatDate($daily['ot_date']); ?></td>
                                <td><?php echo number_format($daily['ticket_count']); ?></td>
                                <td><?php echo number_format($daily['total_hours'], 2); ?> hrs</td>
                                <td><?php echo number_format($daily['agent_count']); ?></td>
                                <td><?php echo number_format($daily['total_hours'] / $daily['agent_count'], 2); ?> hrs</td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">No data available for selected period.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>