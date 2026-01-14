<?php
require_once 'includes/functions.php';

// Get filter parameters
$selectedTeam = isset($_GET['team']) ? $_GET['team'] : 'all';
$selectedWeek = isset($_GET['week']) ? $_GET['week'] : 'all';
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : 'all';
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$selectedChannel = isset($_GET['channel']) ? $_GET['channel'] : 'all';
$viewTeamDetails = isset($_GET['view_team']) ? $_GET['view_team'] : '';

// Build WHERE clause
$whereConditions = ["1=1"];
$params = [];
$paramTypes = "";

// Excluded supervisors
$excludedSupervisors = ['#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir', 'TL'];
$excludeList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $excludedSupervisors)) . "'";
$whereConditions[] = "team_lead NOT IN ($excludeList)";
$whereConditions[] = "team_lead IS NOT NULL";
$whereConditions[] = "team_lead != ''";

if ($selectedTeam !== 'all') {
    $whereConditions[] = "team_lead = ?";
    $params[] = $selectedTeam;
    $paramTypes .= "s";
}

if ($selectedWeek !== 'all') {
    $whereConditions[] = "week_number = ?";
    $params[] = $selectedWeek;
    $paramTypes .= "i";
}

if ($selectedMonth !== 'all') {
    $whereConditions[] = "month_name = ?";
    $params[] = $selectedMonth;
    $paramTypes .= "s";
}

if ($selectedYear !== 'all') {
    $whereConditions[] = "YEAR(survey_date) = ?";
    $params[] = $selectedYear;
    $paramTypes .= "i";
}

if ($selectedChannel !== 'all') {
    $whereConditions[] = "channel_type = ?";
    $params[] = $selectedChannel;
    $paramTypes .= "s";
}

// Date range filter
if (!empty($startDate)) {
    $whereConditions[] = "survey_date >= ?";
    $params[] = $startDate;
    $paramTypes .= "s";
}

if (!empty($endDate)) {
    $whereConditions[] = "survey_date <= ?";
    $params[] = $endDate;
    $paramTypes .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Fetch filter options
$teamsQuery = "SELECT DISTINCT team_lead FROM csat_scores WHERE team_lead NOT IN ($excludeList) AND team_lead IS NOT NULL AND team_lead != '' ORDER BY team_lead";
$teamsResult = $conn->query($teamsQuery);
$teams = [];
while ($row = $teamsResult->fetch_assoc()) {
    $teams[] = $row['team_lead'];
}

$weeksQuery = "SELECT DISTINCT week_number FROM csat_scores WHERE week_number IS NOT NULL ORDER BY week_number";
$weeksResult = $conn->query($weeksQuery);
$weeks = [];
while ($row = $weeksResult->fetch_assoc()) {
    $weeks[] = $row['week_number'];
}

$monthsQuery = "SELECT DISTINCT month_name FROM csat_scores WHERE month_name IS NOT NULL ORDER BY FIELD(month_name, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December')";
$monthsResult = $conn->query($monthsQuery);
$months = [];
while ($row = $monthsResult->fetch_assoc()) {
    $months[] = $row['month_name'];
}

$yearsQuery = "SELECT DISTINCT YEAR(survey_date) as year FROM csat_scores WHERE survey_date IS NOT NULL ORDER BY year DESC";
$yearsResult = $conn->query($yearsQuery);
$years = [];
while ($row = $yearsResult->fetch_assoc()) {
    $years[] = $row['year'];
}

$channelsQuery = "SELECT DISTINCT channel_type FROM csat_scores WHERE channel_type IS NOT NULL AND channel_type != '' ORDER BY channel_type";
$channelsResult = $conn->query($channelsQuery);
$channels = [];
while ($row = $channelsResult->fetch_assoc()) {
    $channels[] = $row['channel_type'];
}

// Team performance query with detailed metrics
$teamQuery = "
    SELECT 
        team_lead,
        COUNT(*) as total_responses,
        SUM(CASE WHEN csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as dsat_count,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat_count,
        ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage,
        COUNT(DISTINCT agent_name) as agent_count,
        SUM(CASE WHEN channel_type = 'Chat' THEN 1 ELSE 0 END) as chat_count,
        SUM(CASE WHEN channel_type = 'Email' THEN 1 ELSE 0 END) as email_count,
        SUM(CASE WHEN channel_type = 'Call' OR channel_type = 'Phone' THEN 1 ELSE 0 END) as call_count
    FROM csat_scores
    $whereClause
    GROUP BY team_lead
    ORDER BY csat_percentage DESC
";

// Execute query with prepared statement if there are parameters
if (!empty($params)) {
    $stmt = $conn->prepare($teamQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $teamResult = $stmt->get_result();
    } else {
        die("Prepare failed: " . $conn->error);
    }
} else {
    $teamResult = $conn->query($teamQuery);
}

// Calculate totals
$totalResponses = 0;
$totalCSAT = 0;
$totalDSAT = 0;
$totalAgents = 0;
$totalChat = 0;
$totalEmail = 0;
$totalCall = 0;

$teamsData = [];
while ($row = $teamResult->fetch_assoc()) {
    $teamsData[] = $row;
    $totalResponses += $row['total_responses'];
    $totalCSAT += $row['csat_count'];
    $totalDSAT += $row['dsat_count'];
    $totalAgents += $row['agent_count'];
    $totalChat += $row['chat_count'];
    $totalEmail += $row['email_count'];
    $totalCall += $row['call_count'];
}

$overallCSATPercentage = $totalResponses > 0 ? round(($totalCSAT / $totalResponses) * 100, 2) : 0;

// If viewing team details, fetch agent performance for that team
$agentsData = [];
if (!empty($viewTeamDetails)) {
    $agentWhereConditions = $whereConditions;
    $agentWhereConditions[] = "team_lead = ?";
    $agentParams = $params;
    $agentParams[] = $viewTeamDetails;
    $agentParamTypes = $paramTypes . "s";
    
    $agentWhereClause = "WHERE " . implode(" AND ", $agentWhereConditions);
    
    $agentQuery = "
        SELECT 
            agent_name,
            agent_email,
            COUNT(*) as total_responses,
            SUM(CASE WHEN csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as dsat_count,
            SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat_count,
            ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage,
            SUM(CASE WHEN channel_type = 'Chat' THEN 1 ELSE 0 END) as chat_count,
            SUM(CASE WHEN channel_type = 'Email' THEN 1 ELSE 0 END) as email_count,
            SUM(CASE WHEN channel_type = 'Call' OR channel_type = 'Phone' THEN 1 ELSE 0 END) as call_count
        FROM csat_scores
        $agentWhereClause
            AND agent_name IS NOT NULL
            AND agent_name != ''
        GROUP BY agent_name, agent_email
        ORDER BY csat_percentage DESC
    ";
    
    if (!empty($agentParams)) {
        $stmtAgent = $conn->prepare($agentQuery);
        if ($stmtAgent) {
            $stmtAgent->bind_param($agentParamTypes, ...$agentParams);
            $stmtAgent->execute();
            $agentResult = $stmtAgent->get_result();
            while ($row = $agentResult->fetch_assoc()) {
                $agentsData[] = $row;
            }
        }
    }
}

include 'includes/header.php';
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-box {
        background: white;
        border-radius: 10px;
        padding: 1.25rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        border-left: 4px solid #667eea;
    }

    .stat-box.csat {
        border-left-color: #28a745;
    }

    .stat-box.dsat {
        border-left-color: #dc3545;
    }

    .stat-box.avg {
        border-left-color: #ffc107;
    }

    .stat-box-label {
        font-size: 0.875rem;
        color: #6c757d;
        font-weight: 600;
        text-transform: uppercase;
    }

    .stat-box-value {
        font-size: 2rem;
        font-weight: 700;
        margin-top: 0.5rem;
    }

    .team-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.9rem;
    }

    .team-table thead th {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 1rem;
        text-align: center;
        font-weight: 600;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .team-table thead th:first-child {
        text-align: left;
        border-top-left-radius: 10px;
    }

    .team-table thead th:last-child {
        border-top-right-radius: 10px;
    }

    .team-table tbody td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e9ecef;
        text-align: center;
    }

    .team-table tbody td:first-child {
        text-align: left;
        font-weight: 600;
        color: #495057;
    }

    .team-table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .team-table tfoot td {
        background: #0f172a;
        color: white;
        padding: 1rem;
        font-weight: 700;
        text-align: center;
        border: none;
    }

    .team-table tfoot td:first-child {
        text-align: left;
        border-bottom-left-radius: 10px;
    }

    .team-table tfoot td:last-child {
        border-bottom-right-radius: 10px;
    }

    .rank-badge {
        display: inline-block;
        width: 30px;
        height: 30px;
        line-height: 30px;
        border-radius: 50%;
        text-align: center;
        font-weight: 700;
        font-size: 0.875rem;
    }

    .rank-1 {
        background: linear-gradient(135deg, #ffd700, #ffed4e);
        color: #000;
    }

    .rank-2 {
        background: linear-gradient(135deg, #c0c0c0, #e8e8e8);
        color: #000;
    }

    .rank-3 {
        background: linear-gradient(135deg, #cd7f32, #e89b60);
        color: #fff;
    }

    .rank-other {
        background: #6c757d;
        color: white;
    }

    .csat-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .csat-excellent {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
    }

    .csat-good {
        background: linear-gradient(135deg, #ffc107, #fd7e14);
        color: white;
    }

    .csat-poor {
        background: linear-gradient(135deg, #dc3545, #e74c3c);
        color: white;
    }

    .team-name-link {
        color: #667eea;
        text-decoration: none;
        font-weight: 700;
        transition: all 0.2s;
        cursor: pointer;
    }

    .team-name-link:hover {
        color: #764ba2;
        text-decoration: underline;
    }

    .agent-details-card {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-left: 5px solid #667eea;
        padding: 1.5rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
    }

    .agent-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.85rem;
        background: white;
        border-radius: 10px;
        overflow: hidden;
    }

    .agent-table thead th {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 0.875rem;
        text-align: center;
        font-weight: 600;
        font-size: 0.8125rem;
    }

    .agent-table thead th:first-child {
        text-align: left;
    }

    .agent-table tbody td {
        padding: 0.75rem;
        border-bottom: 1px solid #e9ecef;
        text-align: center;
    }

    .agent-table tbody td:first-child {
        text-align: left;
    }

    .agent-table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #667eea;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 1rem;
        padding: 0.5rem 1rem;
        border: 2px solid #667eea;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .back-link:hover {
        background: #667eea;
        color: white;
    }

    .filter-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
    }

    .btn-apply-filter {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        padding: 0.625rem 2rem;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-apply-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
    }

    .btn-reset {
        background: white;
        color: #495057;
        border: 2px solid #e9ecef;
        padding: 0.625rem 2rem;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-reset:hover {
        background: #f8f9fa;
    }
</style>

<!-- Active Filters Banner -->
<?php if ($selectedTeam !== 'all' || $selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== date('Y') || $selectedChannel !== 'all' || !empty($startDate) || !empty($endDate)): ?>
<div class="content-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
    <strong><i class="bi bi-funnel-fill"></i> Active Filters:</strong>
    <?php if ($selectedTeam !== 'all'): ?>
        <span class="badge bg-info ms-2"><i class="bi bi-people-fill"></i> Team: <?= htmlspecialchars($selectedTeam) ?></span>
    <?php endif; ?>
    <?php if ($selectedWeek !== 'all'): ?>
        <span class="badge bg-primary ms-2"><i class="bi bi-calendar-week"></i> Week: <?= htmlspecialchars($selectedWeek) ?></span>
    <?php endif; ?>
    <?php if ($selectedMonth !== 'all'): ?>
        <span class="badge bg-success ms-2"><i class="bi bi-calendar-month"></i> Month: <?= htmlspecialchars($selectedMonth) ?></span>
    <?php endif; ?>
    <?php if ($selectedYear !== date('Y')): ?>
        <span class="badge bg-secondary ms-2"><i class="bi bi-calendar3"></i> Year: <?= htmlspecialchars($selectedYear) ?></span>
    <?php endif; ?>
    <?php if ($selectedChannel !== 'all'): ?>
        <span class="badge bg-warning ms-2"><i class="bi bi-chat-dots"></i> Channel: <?= htmlspecialchars($selectedChannel) ?></span>
    <?php endif; ?>
    <?php if (!empty($startDate) || !empty($endDate)): ?>
        <span class="badge bg-dark ms-2"><i class="bi bi-calendar-range"></i> 
            <?= !empty($startDate) ? date('M d, Y', strtotime($startDate)) : '...' ?> - 
            <?= !empty($endDate) ? date('M d, Y', strtotime($endDate)) : '...' ?>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="content-card">
    <h5 class="mb-3"><i class="bi bi-funnel"></i> Filters</h5>
    <form method="GET" action="" id="filterForm">
        <div class="row g-3">
            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-people-fill"></i> Team</label>
                <select name="team" class="form-select" id="teamFilter">
                    <option value="all" <?= $selectedTeam === 'all' ? 'selected' : '' ?>>All Teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= htmlspecialchars($team) ?>" <?= $selectedTeam === $team ? 'selected' : '' ?>>
                            <?= htmlspecialchars($team) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-calendar-week"></i> Week</label>
                <select name="week" class="form-select" id="weekFilter">
                    <option value="all" <?= $selectedWeek === 'all' ? 'selected' : '' ?>>All Weeks</option>
                    <?php foreach ($weeks as $week): ?>
                        <option value="<?= htmlspecialchars($week) ?>" <?= $selectedWeek == $week ? 'selected' : '' ?>>
                            Week <?= htmlspecialchars($week) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-calendar-month"></i> Month</label>
                <select name="month" class="form-select" id="monthFilter">
                    <option value="all" <?= $selectedMonth === 'all' ? 'selected' : '' ?>>All Months</option>
                    <?php foreach ($months as $month): ?>
                        <option value="<?= htmlspecialchars($month) ?>" <?= $selectedMonth === $month ? 'selected' : '' ?>>
                            <?= htmlspecialchars($month) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-calendar3"></i> Year</label>
                <select name="year" class="form-select" id="yearFilter">
                    <option value="all" <?= $selectedYear === 'all' ? 'selected' : '' ?>>All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $selectedYear == $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-chat-dots"></i> Channel</label>
                <select name="channel" class="form-select" id="channelFilter">
                    <option value="all" <?= $selectedChannel === 'all' ? 'selected' : '' ?>>All Channels</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= htmlspecialchars($channel) ?>" <?= $selectedChannel === $channel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($channel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label">&nbsp;</label>
                <button type="submit" class="btn btn-apply-filter w-100">
                    <i class="bi bi-search"></i> Apply
                </button>
            </div>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-md-10">
                <label class="filter-label"><i class="bi bi-calendar-range"></i> Date Range</label>
                <div class="input-group">
                    <input type="date" class="form-control" name="start_date" id="startDate" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="date" class="form-control" name="end_date" id="endDate" value="<?= htmlspecialchars($endDate) ?>">
                </div>
            </div>
            <div class="col-md-2">
                <label class="filter-label">&nbsp;</label>
                <a href="csat_teams.php" class="btn btn-reset w-100">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Overall Statistics -->
<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-box-label"><i class="bi bi-people"></i> Total Teams</div>
        <div class="stat-box-value"><?= count($teamsData) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-box-label"><i class="bi bi-chat-square-text"></i> Total Responses</div>
        <div class="stat-box-value"><?= number_format($totalResponses) ?></div>
    </div>
    <div class="stat-box csat">
        <div class="stat-box-label"><i class="bi bi-emoji-smile"></i> Overall CSAT%</div>
        <div class="stat-box-value text-success"><?= number_format($overallCSATPercentage, 2) ?>%</div>
    </div>
    <div class="stat-box">
        <div class="stat-box-label"><i class="bi bi-person"></i> Total Agents</div>
        <div class="stat-box-value"><?= number_format($totalAgents) ?></div>
    </div>
</div>

<!-- Team Performance Table -->
<div class="content-card">
    <h5 class="mb-4"><i class="bi bi-table"></i> Detailed Team Performance Comparison</h5>
    
    <?php if (empty($teamsData)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-info-circle"></i> No team data available for the selected filters.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="team-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Team Lead</th>
                        <th>Total Responses</th>
                        <th>CSAT Count</th>
                        <th>DSAT Count</th>
                        <th>CSAT %</th>
                        <th><i class="bi bi-chat-dots"></i> Chat</th>
                        <th><i class="bi bi-envelope"></i> Email</th>
                        <th><i class="bi bi-telephone"></i> Calls</th>
                        <th>Agents</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($teamsData as $team): 
                        $csatClass = $team['csat_percentage'] >= 90 ? 'csat-excellent' : 
                                    ($team['csat_percentage'] >= 80 ? 'csat-good' : 'csat-poor');
                        
                        $rankClass = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other'));
                    ?>
                    <tr>
                        <td>
                            <span class="rank-badge <?= $rankClass ?>"><?= $rank ?></span>
                        </td>
                        <td>
                            <a href="?view_team=<?= urlencode($team['team_lead']) ?><?= $selectedWeek !== 'all' ? '&week=' . urlencode($selectedWeek) : '' ?><?= $selectedMonth !== 'all' ? '&month=' . urlencode($selectedMonth) : '' ?><?= $selectedYear !== 'all' ? '&year=' . urlencode($selectedYear) : '' ?><?= $selectedChannel !== 'all' ? '&channel=' . urlencode($selectedChannel) : '' ?><?= !empty($startDate) ? '&start_date=' . urlencode($startDate) : '' ?><?= !empty($endDate) ? '&end_date=' . urlencode($endDate) : '' ?>" class="team-name-link">
                                <?= htmlspecialchars($team['team_lead']) ?>
                            </a>
                        </td>
                        <td><?= number_format($team['total_responses']) ?></td>
                        <td><span class="text-success fw-bold"><?= number_format($team['csat_count']) ?></span></td>
                        <td><span class="text-danger fw-bold"><?= number_format($team['dsat_count']) ?></span></td>
                        <td>
                            <span class="csat-badge <?= $csatClass ?>">
                                <?= number_format($team['csat_percentage'], 2) ?>%
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?= number_format($team['chat_count']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-info"><?= number_format($team['email_count']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= number_format($team['call_count']) ?></span>
                        </td>
                        <td><?= $team['agent_count'] ?></td>
                    </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><strong>TOTAL</strong></td>
                        <td><strong><?= number_format($totalResponses) ?></strong></td>
                        <td><strong><?= number_format($totalCSAT) ?></strong></td>
                        <td><strong><?= number_format($totalDSAT) ?></strong></td>
                        <td><strong><?= number_format($overallCSATPercentage, 2) ?>%</strong></td>
                        <td><strong><?= number_format($totalChat) ?></strong></td>
                        <td><strong><?= number_format($totalEmail) ?></strong></td>
                        <td><strong><?= number_format($totalCall) ?></strong></td>
                        <td><strong><?= $totalAgents ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Agent Details Section (if team is selected) -->
<?php if (!empty($viewTeamDetails) && !empty($agentsData)): ?>
<div class="content-card agent-details-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-person-badge"></i> 
            Agent Performance - <?= htmlspecialchars($viewTeamDetails) ?> Team
        </h5>
        <a href="csat_teams.php<?= $selectedWeek !== 'all' ? '?week=' . urlencode($selectedWeek) : '' ?><?= $selectedMonth !== 'all' ? ($selectedWeek !== 'all' ? '&' : '?') . 'month=' . urlencode($selectedMonth) : '' ?><?= $selectedYear !== 'all' ? (($selectedWeek !== 'all' || $selectedMonth !== 'all') ? '&' : '?') . 'year=' . urlencode($selectedYear) : '' ?><?= $selectedChannel !== 'all' ? (($selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== 'all') ? '&' : '?') . 'channel=' . urlencode($selectedChannel) : '' ?><?= !empty($startDate) ? (($selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== 'all' || $selectedChannel !== 'all') ? '&' : '?') . 'start_date=' . urlencode($startDate) : '' ?><?= !empty($endDate) ? '&end_date=' . urlencode($endDate) : '' ?>" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Teams
        </a>
    </div>
    
    <div class="table-responsive">
        <table class="agent-table">
            <thead>
                <tr>
                    <th>Agent Name</th>
                    <th>Email</th>
                    <th>Total Responses</th>
                    <th>CSAT Count</th>
                    <th>DSAT Count</th>
                    <th>CSAT %</th>
                    <th><i class="bi bi-chat-dots"></i> Chat</th>
                    <th><i class="bi bi-envelope"></i> Email</th>
                    <th><i class="bi bi-telephone"></i> Calls</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agentsData as $agent): 
                    $agentCsatClass = $agent['csat_percentage'] >= 90 ? 'csat-excellent' : 
                                     ($agent['csat_percentage'] >= 80 ? 'csat-good' : 'csat-poor');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($agent['agent_name'] ?: 'N/A') ?></strong></td>
                    <td><small><?= htmlspecialchars($agent['agent_email'] ?: 'N/A') ?></small></td>
                    <td><?= number_format($agent['total_responses']) ?></td>
                    <td><span class="text-success fw-bold"><?= number_format($agent['csat_count']) ?></span></td>
                    <td><span class="text-danger fw-bold"><?= number_format($agent['dsat_count']) ?></span></td>
                    <td>
                        <span class="csat-badge <?= $agentCsatClass ?>">
                            <?= number_format($agent['csat_percentage'], 2) ?>%
                        </span>
                    </td>
                    <td><span class="badge bg-primary"><?= number_format($agent['chat_count']) ?></span></td>
                    <td><span class="badge bg-info"><?= number_format($agent['email_count']) ?></span></td>
                    <td><span class="badge bg-secondary"><?= number_format($agent['call_count']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// Filter reset logic
document.addEventListener('DOMContentLoaded', function() {
    const weekFilter = document.getElementById('weekFilter');
    const monthFilter = document.getElementById('monthFilter');
    const yearFilter = document.getElementById('yearFilter');
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');

    // When week is selected, reset month and date range
    weekFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            monthFilter.value = 'all';
            startDate.value = '';
            endDate.value = '';
        }
    });

    // When month is selected, reset week and date range
    monthFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            weekFilter.value = 'all';
            startDate.value = '';
            endDate.value = '';
        }
    });

    // When year is selected, reset date range
    yearFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            startDate.value = '';
            endDate.value = '';
        }
    });

    // When date range is used, reset week, month, and year
    startDate.addEventListener('change', function() {
        if (this.value) {
            weekFilter.value = 'all';
            monthFilter.value = 'all';
            yearFilter.value = 'all';
        }
    });

    endDate.addEventListener('change', function() {
        if (this.value) {
            weekFilter.value = 'all';
            monthFilter.value = 'all';
            yearFilter.value = 'all';
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>