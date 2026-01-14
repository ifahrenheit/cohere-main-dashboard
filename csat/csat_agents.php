<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db_connection.php';
require_once 'includes/functions.php';

// Get filter parameters
$searchAgent = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedTeam = isset($_GET['team']) ? $_GET['team'] : 'all';
$selectedWeek = isset($_GET['week']) ? $_GET['week'] : 'all';
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : 'all';
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$selectedChannel = isset($_GET['channel']) ? $_GET['channel'] : 'all';
$selectedTenure = isset($_GET['tenure']) ? $_GET['tenure'] : 'all';
$selectedRootCause = isset($_GET['root_cause']) ? $_GET['root_cause'] : 'all';
$viewAgent = isset($_GET['view_agent']) ? $_GET['view_agent'] : '';

// Build WHERE clause
$whereConditions = ["1=1"];
$params = [];
$paramTypes = "";

// Excluded supervisors - DON'T add to WHERE, only use in HAVING clause
$excludedSupervisors = ['#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir', 'TL'];
$excludeList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $excludedSupervisors)) . "'";
// NOTE: Exclusion applied in HAVING clause instead of WHERE to count all agent tickets

if (!empty($searchAgent)) {
    $whereConditions[] = "(a.agent_email LIKE ? OR a.agent_name LIKE ?)";
    $params[] = "%$searchAgent%";
    $params[] = "%$searchAgent%";
    $paramTypes .= "ss";
}

if ($selectedTeam !== 'all') {
    $whereConditions[] = "latest.team_lead = ?";
    $params[] = $selectedTeam;
    $paramTypes .= "s";
}

if ($selectedWeek !== 'all') {
    $whereConditions[] = "a.week_number = ?";
    $params[] = $selectedWeek;
    $paramTypes .= "i";
}

if ($selectedMonth !== 'all') {
    $whereConditions[] = "a.month_name = ?";
    $params[] = $selectedMonth;
    $paramTypes .= "s";
}

if ($selectedYear !== 'all') {
    $whereConditions[] = "YEAR(a.survey_date) = ?";
    $params[] = $selectedYear;
    $paramTypes .= "i";
}

if ($selectedChannel !== 'all') {
    $whereConditions[] = "a.channel_type = ?";
    $params[] = $selectedChannel;
    $paramTypes .= "s";
}

// Note: Tenure filter will be applied after query execution since tenure is from subquery

if (!empty($startDate)) {
    $whereConditions[] = "a.survey_date >= ?";
    $params[] = $startDate;
    $paramTypes .= "s";
}

if (!empty($endDate)) {
    $whereConditions[] = "a.survey_date <= ?";
    $params[] = $endDate;
    $paramTypes .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Hardcode ALL filter values - NO database queries for instant page load
// Update these arrays manually when new values appear in your data

$teams = ['Aiello', 'Alyssa', 'Crestine', 'Dana', 'Espinosa', 'Hannah', 'Jenny', 'Jess', 'Jhunrey', 'Joyce', 'Karen', 'Kath', 'Ken', 'Kent', 'Laarni', 'Lanz', 'Lou', 'Mae', 'Maricar', 'Meg', 'Nique', 'Racel', 'Raf', 'Regine', 'Ria', 'Rose', 'Roxanne', 'Shane', 'Sigrid'];
// NOTE: Excluded supervisors NOT in this list: '#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir', 'TL'

$weeks = range(1, 53);
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$years = [2025, 2024, 2023, 2022, 2021, 2020];
$channels = ['Call', 'Chat', 'Email', 'Phone'];
$tenures = ['Non-tenured', 'Tenured', 'Training'];
$rootCauses = ['Cancellation', 'CSR Clarity', 'Cx Solutions', 'Payment', 'Refund', 'Reschedule', 'Supplier Solution'];

// Agent performance query - OPTIMIZED with derived table instead of correlated subqueries
$agentQuery = "
    SELECT 
        a.agent_email,
        latest.agent_name,
        latest.team_lead,
        latest.tenure,
        COUNT(*) as total_responses,
        SUM(CASE WHEN a.csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as dsat_count,
        SUM(CASE WHEN a.csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat_count,
        ROUND((SUM(CASE WHEN a.csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage,
        SUM(CASE WHEN a.channel_type = 'Chat' THEN 1 ELSE 0 END) as chat_count,
        SUM(CASE WHEN a.channel_type = 'Email' THEN 1 ELSE 0 END) as email_count,
        SUM(CASE WHEN a.channel_type = 'Call' OR a.channel_type = 'Phone' THEN 1 ELSE 0 END) as call_count,
        MAX(a.survey_date) as latest_date
    FROM csat_scores a
    INNER JOIN (
        SELECT 
            cs.agent_email,
            cs.agent_name,
            cs.team_lead,
            cs.tenure
        FROM csat_scores cs
        INNER JOIN (
            SELECT agent_email, MAX(survey_date) as max_date
            FROM csat_scores
            WHERE agent_email IS NOT NULL AND agent_email != ''
            GROUP BY agent_email
        ) latest_date ON cs.agent_email = latest_date.agent_email 
                      AND cs.survey_date = latest_date.max_date
        GROUP BY cs.agent_email, cs.agent_name, cs.team_lead, cs.tenure
    ) latest ON a.agent_email = latest.agent_email
    $whereClause
        AND a.agent_email IS NOT NULL
        AND a.agent_email != ''
        AND latest.team_lead NOT IN ($excludeList)
    GROUP BY a.agent_email, latest.agent_name, latest.team_lead, latest.tenure
    ORDER BY csat_percentage DESC, total_responses DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($agentQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $agentResult = $stmt->get_result();
    }
} else {
    $agentResult = $conn->query($agentQuery);
}

// Wilson Score Confidence Interval Function
// This gives a more reliable score that accounts for sample size
function calculateWilsonScore($positive, $total, $confidence = 1.96) {
    if ($total == 0) return 0;
    
    $p = $positive / $total;
    $z = $confidence; // 1.96 for 95% confidence
    
    $numerator = $p + ($z * $z) / (2 * $total) - $z * sqrt(($p * (1 - $p) + ($z * $z) / (4 * $total)) / $total);
    $denominator = 1 + ($z * $z) / $total;
    
    $score = ($numerator / $denominator) * 100;
    
    return max(0, min(100, $score)); // Clamp between 0 and 100
}

$agentsData = [];
$totalAgents = 0;
$totalResponses = 0;
$totalCSAT = 0;
$totalDSAT = 0;

while ($row = $agentResult->fetch_assoc()) {
    // Calculate Wilson Score for each agent
    $row['wilson_score'] = calculateWilsonScore($row['csat_count'], $row['total_responses']);
    
    // Apply tenure filter (post-query filtering since tenure comes from subquery)
    if ($selectedTenure !== 'all' && $row['tenure'] !== $selectedTenure) {
        continue; // Skip agents that don't match tenure filter
    }
    
    $agentsData[] = $row;
    $totalAgents++;
    $totalResponses += $row['total_responses'];
    $totalCSAT += $row['csat_count'];
    $totalDSAT += $row['dsat_count'];
}

$overallCSAT = $totalResponses > 0 ? round(($totalCSAT / $totalResponses) * 100, 2) : 0;

// Get count of responses without agent emails (unassigned) - FAST with index
$unassignedQuery = "
    SELECT COUNT(*) as unassigned_count
    FROM csat_scores
    WHERE (agent_email IS NULL OR agent_email = '')
";
$unassignedResult = $conn->query($unassignedQuery);
$unassignedRow = $unassignedResult->fetch_assoc();
$unassignedCount = $unassignedRow['unassigned_count'] ?? 0;

// Get count of excluded supervisor responses - FAST with index on team_lead
$excludedQuery = "
    SELECT COUNT(*) as excluded_count
    FROM csat_scores
    WHERE team_lead IN ($excludeList)
";
$excludedResult = $conn->query($excludedQuery);
$excludedRow = $excludedResult->fetch_assoc();
$excludedCount = $excludedRow['excluded_count'] ?? 0;

// If viewing specific agent details
$agentTickets = [];
$agentName = '';
$ticketTotalRecords = 0;
$ticketPage = isset($_GET['ticket_page']) ? (int)$_GET['ticket_page'] : 1;
$ticketPerPage = 50; // 50 tickets per page for speed
$ticketOffset = ($ticketPage - 1) * $ticketPerPage;

if (!empty($viewAgent)) {
    // Get agent name for display
    $nameQuery = "SELECT agent_name FROM csat_scores WHERE agent_email = ? AND agent_name IS NOT NULL AND agent_name != '' ORDER BY survey_date DESC LIMIT 1";
    $stmtName = $conn->prepare($nameQuery);
    $stmtName->bind_param("s", $viewAgent);
    $stmtName->execute();
    $nameResult = $stmtName->get_result();
    if ($nameRow = $nameResult->fetch_assoc()) {
        $agentName = $nameRow['agent_name'];
    }
    
    // Build ticket WHERE clause without table aliases (single table query)
    $ticketWhereConditions = ["1=1"];
    $ticketParams = [];
    $ticketParamTypes = "";
    
    if (!empty($searchAgent)) {
        $ticketWhereConditions[] = "(agent_email LIKE ? OR agent_name LIKE ?)";
        $ticketParams[] = "%$searchAgent%";
        $ticketParams[] = "%$searchAgent%";
        $ticketParamTypes .= "ss";
    }
    
    if ($selectedTeam !== 'all') {
        $ticketWhereConditions[] = "team_lead = ?";
        $ticketParams[] = $selectedTeam;
        $ticketParamTypes .= "s";
    }
    
    if ($selectedWeek !== 'all') {
        $ticketWhereConditions[] = "week_number = ?";
        $ticketParams[] = $selectedWeek;
        $ticketParamTypes .= "i";
    }
    
    if ($selectedMonth !== 'all') {
        $ticketWhereConditions[] = "month_name = ?";
        $ticketParams[] = $selectedMonth;
        $ticketParamTypes .= "s";
    }
    
    if ($selectedYear !== 'all') {
        $ticketWhereConditions[] = "YEAR(survey_date) = ?";
        $ticketParams[] = $selectedYear;
        $ticketParamTypes .= "i";
    }
    
    if ($selectedChannel !== 'all') {
        $ticketWhereConditions[] = "channel_type = ?";
        $ticketParams[] = $selectedChannel;
        $ticketParamTypes .= "s";
    }
    
    if ($selectedRootCause !== 'all') {
        $ticketWhereConditions[] = "root_cause = ?";
        $ticketParams[] = $selectedRootCause;
        $ticketParamTypes .= "s";
    }
    
    if (!empty($startDate)) {
        $ticketWhereConditions[] = "survey_date >= ?";
        $ticketParams[] = $startDate;
        $ticketParamTypes .= "s";
    }
    
    if (!empty($endDate)) {
        $ticketWhereConditions[] = "survey_date <= ?";
        $ticketParams[] = $endDate;
        $ticketParamTypes .= "s";
    }
    
    // Add agent email filter
    $ticketWhereConditions[] = "agent_email = ?";
    $ticketParams[] = $viewAgent;
    $ticketParamTypes .= "s";
    
    $ticketWhereClause = "WHERE " . implode(" AND ", $ticketWhereConditions);
    
    // Get total count for pagination (fast with agent_email index)
    $countQuery = "SELECT COUNT(*) as total FROM csat_scores $ticketWhereClause";
    $stmtCount = $conn->prepare($countQuery);
    if ($stmtCount) {
        $stmtCount->bind_param($ticketParamTypes, ...$ticketParams);
        $stmtCount->execute();
        $countResult = $stmtCount->get_result();
        $ticketTotalRecords = $countResult->fetch_assoc()['total'];
        $stmtCount->close();
    }
    
    $ticketTotalPages = ceil($ticketTotalRecords / $ticketPerPage);
    
    // Get tickets for current page
    $ticketQuery = "
        SELECT 
            ticket_number,
            survey_date,
            csat_score,
            csat_type,
            channel_type,
            theme,
            sentiment,
            root_cause,
            agent_name
        FROM csat_scores
        $ticketWhereClause
        ORDER BY survey_date DESC, id DESC
        LIMIT $ticketPerPage OFFSET $ticketOffset
    ";
    
    $stmtTicket = $conn->prepare($ticketQuery);
    if ($stmtTicket) {
        $stmtTicket->bind_param($ticketParamTypes, ...$ticketParams);
        $stmtTicket->execute();
        $ticketResult = $stmtTicket->get_result();
        while ($row = $ticketResult->fetch_assoc()) {
            $agentTickets[] = $row;
        }
    }
    
    // Get ALL root causes for summary (not paginated) - FAST query
    $rootCauseSummary = [];
    $summaryQuery = "
        SELECT 
            root_cause,
            COUNT(*) as count
        FROM csat_scores
        $ticketWhereClause
            AND root_cause IS NOT NULL
            AND root_cause != ''
        GROUP BY root_cause
        ORDER BY count DESC
    ";
    
    $stmtSummary = $conn->prepare($summaryQuery);
    if ($stmtSummary) {
        $stmtSummary->bind_param($ticketParamTypes, ...$ticketParams);
        $stmtSummary->execute();
        $summaryResult = $stmtSummary->get_result();
        while ($row = $summaryResult->fetch_assoc()) {
            $rootCauseSummary[$row['root_cause']] = $row['count'];
        }
    }
}

include 'includes/header.php';

// TEMPORARY DEBUG - Remove after fixing
if (isset($_GET['debug'])) {
    echo "<div class='alert alert-warning'><strong>DEBUG MODE</strong><br>";
    echo "WHERE Clause: " . htmlspecialchars($whereClause) . "<br>";
    echo "Exclude List: " . htmlspecialchars($excludeList) . "<br>";
    echo "Query being executed (first 500 chars):<br><code>" . htmlspecialchars(substr($agentQuery, 0, 500)) . "...</code>";
    echo "</div>";
}
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
        border-left: 4px solid #1e40af;
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

    .agent-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
    }

    .agent-table thead th {
        background: linear-gradient(135deg, #1e3a8a, #1e40af);
        color: white;
        padding: 1rem;
        text-align: center;
        font-weight: 600;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
        cursor: pointer;
        user-select: none;
        transition: background 0.2s;
    }

    .agent-table thead th:hover {
        background: linear-gradient(135deg, #1e40af, #2563eb);
    }

    .agent-table thead th.sortable {
        cursor: pointer;
    }

    .sort-arrows {
        display: inline-flex;
        flex-direction: column;
        margin-left: 0.25rem;
        font-size: 0.625rem;
        line-height: 0.5;
    }

    .sort-arrows i {
        color: rgba(255, 255, 255, 0.4);
        transition: color 0.2s;
    }

    .sort-arrows i.active {
        color: #60a5fa;
    }

    .agent-table thead th:first-child {
        text-align: left;
        border-top-left-radius: 10px;
    }

    .agent-table thead th:last-child {
        border-top-right-radius: 10px;
    }

    .agent-table tbody td {
        padding: 0.875rem;
        border-bottom: 1px solid #e9ecef;
        text-align: center;
    }

    .agent-table tbody td:first-child {
        text-align: left;
    }

    .agent-table tbody tr:hover {
        background-color: #f8f9fa;
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

    .rank-1 { background: linear-gradient(135deg, #ffd700, #ffed4e); color: #000; }
    .rank-2 { background: linear-gradient(135deg, #c0c0c0, #e8e8e8); color: #000; }
    .rank-3 { background: linear-gradient(135deg, #cd7f32, #e89b60); color: #fff; }
    .rank-other { background: #6c757d; color: white; }

    .csat-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .csat-excellent { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
    .csat-good { background: linear-gradient(135deg, #ffc107, #fd7e14); color: white; }
    .csat-poor { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; }

    .agent-name-link {
        color: #1e40af;
        text-decoration: none;
        font-weight: 700;
        transition: all 0.2s;
    }

    .agent-name-link:hover {
        color: #1e3a8a;
        text-decoration: underline;
    }

    .search-box {
        position: relative;
    }

    .search-box .bi-search {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }

    .search-box input {
        padding-left: 2.5rem;
    }

    .filter-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
    }

    .btn-apply-filter {
        background: linear-gradient(135deg, #1e3a8a, #1e40af);
        color: white;
        border: none;
        padding: 0.625rem 2rem;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-apply-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(30, 64, 175, 0.3);
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

    .btn-copy {
        background: linear-gradient(135deg, #059669, #10b981);
        color: white;
        border: none;
        padding: 0.625rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-copy:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(5, 150, 105, 0.3);
    }

    .btn-copy.copied {
        background: linear-gradient(135deg, #1e40af, #2563eb);
    }

    .btn-copy i {
        font-size: 1.1rem;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #1e40af;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 1rem;
        padding: 0.5rem 1rem;
        border: 2px solid #1e40af;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .back-link:hover {
        background: #1e40af;
        color: white;
    }

    .ticket-table {
        font-size: 0.8125rem;
    }

    .ticket-table th {
        background: #f8f9fa;
        font-weight: 600;
    }

    .ticket-link {
        color: #1e40af;
        text-decoration: none;
        font-weight: 600;
    }

    .ticket-link:hover {
        text-decoration: underline;
    }

    .performance-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.875rem;
    }

    .trend-up { color: #28a745; }
    .trend-down { color: #dc3545; }
</style>

<!-- Active Filters Banner -->
<?php if (!empty($searchAgent) || $selectedTeam !== 'all' || $selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== date('Y') || $selectedChannel !== 'all' || $selectedTenure !== 'all' || !empty($startDate) || !empty($endDate)): ?>
<div class="content-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
    <strong><i class="bi bi-funnel-fill"></i> Active Filters:</strong>
    <?php if (!empty($searchAgent)): ?>
        <span class="badge bg-primary ms-2"><i class="bi bi-search"></i> Search: <?= htmlspecialchars($searchAgent) ?></span>
    <?php endif; ?>
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
    <?php if ($selectedTenure !== 'all'): ?>
        <span class="badge bg-purple ms-2" style="background: #6f42c1;"><i class="bi bi-person-badge"></i> Tenure: <?= htmlspecialchars($selectedTenure) ?></span>
    <?php endif; ?>
    <?php if (!empty($startDate) || !empty($endDate)): ?>
        <span class="badge bg-dark ms-2"><i class="bi bi-calendar-range"></i> 
            <?= !empty($startDate) ? date('M d, Y', strtotime($startDate)) : '...' ?> - 
            <?= !empty($endDate) ? date('M d, Y', strtotime($endDate)) : '...' ?>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Search and Filters -->
<div class="content-card">
    <h5 class="mb-3"><i class="bi bi-search"></i> Search & Filter Agents</h5>
    <form method="GET" action="" id="filterForm">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="filter-label"><i class="bi bi-person-search"></i> Search Agent</label>
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($searchAgent) ?>" placeholder="Name or email...">
                </div>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-people-fill"></i> Team</label>
                <select name="team" class="form-select">
                    <option value="all" <?= $selectedTeam === 'all' ? 'selected' : '' ?>>All Teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= htmlspecialchars($team) ?>" <?= $selectedTeam === $team ? 'selected' : '' ?>>
                            <?= htmlspecialchars($team) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="filter-label"><i class="bi bi-calendar-week"></i> Week</label>
                <select name="week" class="form-select" id="weekFilter">
                    <option value="all" <?= $selectedWeek === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($weeks as $week): ?>
                        <option value="<?= htmlspecialchars($week) ?>" <?= $selectedWeek == $week ? 'selected' : '' ?>>
                            <?= htmlspecialchars($week) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-calendar-month"></i> Month</label>
                <select name="month" class="form-select" id="monthFilter">
                    <option value="all" <?= $selectedMonth === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($months as $month): ?>
                        <option value="<?= htmlspecialchars($month) ?>" <?= $selectedMonth === $month ? 'selected' : '' ?>>
                            <?= htmlspecialchars($month) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="filter-label"><i class="bi bi-calendar3"></i> Year</label>
                <select name="year" class="form-select" id="yearFilter">
                    <option value="all" <?= $selectedYear === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $selectedYear == $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-chat-dots"></i> Channel</label>
                <select name="channel" class="form-select">
                    <option value="all" <?= $selectedChannel === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= htmlspecialchars($channel) ?>" <?= $selectedChannel === $channel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($channel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="filter-label"><i class="bi bi-person-badge"></i> Tenure</label>
                <select name="tenure" class="form-select">
                    <option value="all" <?= $selectedTenure === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($tenures as $tenure): ?>
                        <option value="<?= htmlspecialchars($tenure) ?>" <?= $selectedTenure === $tenure ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tenure) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="filter-label">&nbsp;</label>
                <button type="submit" class="btn btn-apply-filter w-100">
                    <i class="bi bi-search"></i>
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
                <a href="csat_agents.php" class="btn btn-reset w-100">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Exclusion Notice Banner -->
<?php if ($unassignedCount > 0 || $excludedCount > 0): ?>
<div class="alert alert-warning" style="background: linear-gradient(135deg, #fff3cd, #ffeaa7); border-left: 5px solid #ffc107;">
    <i class="bi bi-info-circle"></i> <strong>Data Exclusions:</strong> 
    <?php if ($unassignedCount > 0): ?>
        <span class="badge bg-warning text-dark"><?= number_format($unassignedCount) ?></span> responses have no agent email assigned.
    <?php endif; ?>
    <?php if ($excludedCount > 0): ?>
        <span class="badge bg-secondary"><?= number_format($excludedCount) ?></span> responses are excluded because they are assigned to excluded Team Leads.
    <?php endif; ?>
    <br>
    Currently showing <strong><?= number_format($totalResponses) ?> responses</strong> from <strong><?= number_format($totalAgents) ?> agents</strong>.
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-box-label"><i class="bi bi-people"></i> Total Agents</div>
        <div class="stat-box-value"><?= number_format($totalAgents) ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-box-label"><i class="bi bi-chat-square-text"></i> Total Responses</div>
        <div class="stat-box-value"><?= number_format($totalResponses) ?></div>
    </div>
    <div class="stat-box" style="border-left-color: #28a745;">
        <div class="stat-box-label"><i class="bi bi-emoji-smile"></i> Overall CSAT%</div>
        <div class="stat-box-value text-success"><?= number_format($overallCSAT, 2) ?>%</div>
    </div>
    <div class="stat-box" style="border-left-color: #dc3545;">
        <div class="stat-box-label"><i class="bi bi-emoji-frown"></i> Total DSAT</div>
        <div class="stat-box-value text-danger"><?= number_format($totalDSAT) ?></div>
    </div>
</div>

<!-- Agent Performance Table -->
<?php if (empty($viewAgent)): ?>
<div class="content-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Agent Performance Rankings</h5>
        <button id="copyTableBtn" class="btn-copy" onclick="copyTableToClipboard()">
            <i class="bi bi-clipboard"></i>
            <span id="copyBtnText">Copy to Clipboard</span>
        </button>
    </div>
    
    <?php if (empty($agentsData)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-info-circle"></i> No agent data available for the selected filters.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="agent-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th class="sortable" data-column="1" style="text-align: left;">
                            Agent Name
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="2">
                            Email
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="3">
                            Team
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="4">
                            Total
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="5">
                            CSAT
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="6">
                            DSAT
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="7">
                            CSAT %
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="8" title="Wilson Score: Confidence-adjusted rating that accounts for sample size. More reliable than raw CSAT% for comparing agents with different response volumes.">
                            Wilson Score <i class="bi bi-info-circle" style="font-size: 0.75rem;"></i>
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="9">
                            <i class="bi bi-chat-dots"></i> Chat
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="10">
                            <i class="bi bi-envelope"></i> Email
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="11">
                            <i class="bi bi-telephone"></i> Calls
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                        <th class="sortable" data-column="12">
                            Tenure
                            <span class="sort-arrows">
                                <i class="bi bi-chevron-up"></i>
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($agentsData as $agent): 
                        $csatClass = $agent['csat_percentage'] >= 90 ? 'csat-excellent' : 
                                    ($agent['csat_percentage'] >= 80 ? 'csat-good' : 'csat-poor');
                        
                        $rankClass = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other'));
                        
                        // Build query string for drill-down
                        $drillDownParams = http_build_query(array_filter([
                            'view_agent' => $agent['agent_email'],
                            'search' => $searchAgent,
                            'team' => $selectedTeam !== 'all' ? $selectedTeam : null,
                            'week' => $selectedWeek !== 'all' ? $selectedWeek : null,
                            'month' => $selectedMonth !== 'all' ? $selectedMonth : null,
                            'year' => $selectedYear !== 'all' ? $selectedYear : null,
                            'channel' => $selectedChannel !== 'all' ? $selectedChannel : null,
                            'start_date' => $startDate,
                            'end_date' => $endDate
                        ]));
                    ?>
                    <tr>
                        <td>
                            <span class="rank-badge <?= $rankClass ?>"><?= $rank ?></span>
                        </td>
                        <td style="text-align: left;" data-value="<?= htmlspecialchars($agent['agent_name']) ?>">
                            <a href="?<?= $drillDownParams ?>" class="agent-name-link">
                                <?= htmlspecialchars($agent['agent_name']) ?>
                            </a>
                        </td>
                        <td data-value="<?= htmlspecialchars($agent['agent_email']) ?>">
                            <small><?= htmlspecialchars($agent['agent_email']) ?></small>
                        </td>
                        <td data-value="<?= htmlspecialchars($agent['team_lead']) ?>">
                            <span class="badge bg-info"><?= htmlspecialchars($agent['team_lead']) ?></span>
                        </td>
                        <td data-value="<?= $agent['total_responses'] ?>">
                            <?= number_format($agent['total_responses']) ?>
                        </td>
                        <td data-value="<?= $agent['csat_count'] ?>">
                            <span class="text-success fw-bold"><?= number_format($agent['csat_count']) ?></span>
                        </td>
                        <td data-value="<?= $agent['dsat_count'] ?>">
                            <span class="text-danger fw-bold"><?= number_format($agent['dsat_count']) ?></span>
                        </td>
                        <td data-value="<?= $agent['csat_percentage'] ?>">
                            <span class="csat-badge <?= $csatClass ?>">
                                <?= number_format($agent['csat_percentage'], 2) ?>%
                            </span>
                        </td>
                        <td data-value="<?= round($agent['wilson_score'], 2) ?>">
                            <span class="badge" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; font-size: 0.875rem; padding: 0.375rem 0.75rem;">
                                <?= number_format($agent['wilson_score'], 2) ?>%
                            </span>
                        </td>
                        <td data-value="<?= $agent['chat_count'] ?>">
                            <span class="badge bg-primary"><?= number_format($agent['chat_count']) ?></span>
                        </td>
                        <td data-value="<?= $agent['email_count'] ?>">
                            <span class="badge bg-info"><?= number_format($agent['email_count']) ?></span>
                        </td>
                        <td data-value="<?= $agent['call_count'] ?>">
                            <span class="badge bg-secondary"><?= number_format($agent['call_count']) ?></span>
                        </td>
                        <td data-value="<?= htmlspecialchars($agent['tenure']) ?>">
                            <small><?= htmlspecialchars($agent['tenure']) ?></small>
                        </td>
                    </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Individual Agent Details -->
<?php if (!empty($viewAgent) && !empty($agentTickets)): ?>
<div class="content-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0">
            <i class="bi bi-person-circle"></i> 
            <?= htmlspecialchars($agentName) ?> (<?= htmlspecialchars($viewAgent) ?>) - Individual Tickets
        </h5>
        <a href="?<?= http_build_query(array_filter([
            'search' => $searchAgent,
            'team' => $selectedTeam !== 'all' ? $selectedTeam : null,
            'week' => $selectedWeek !== 'all' ? $selectedWeek : null,
            'month' => $selectedMonth !== 'all' ? $selectedMonth : null,
            'year' => $selectedYear !== 'all' ? $selectedYear : null,
            'channel' => $selectedChannel !== 'all' ? $selectedChannel : null,
            'start_date' => $startDate,
            'end_date' => $endDate
        ])) ?>" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to All Agents
        </a>
    </div>
    
    <!-- Root Cause Summary Box -->
    <?php if (!empty($rootCauseSummary)): ?>
    <div class="alert alert-info" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="bi bi-pie-chart-fill"></i> <strong>Root Cause Summary for <?= htmlspecialchars($agentName) ?>:</strong></h6>
            <?php if ($selectedRootCause !== 'all'): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['root_cause' => 'all', 'ticket_page' => 1])) ?>" 
                   class="btn btn-sm btn-warning">
                    <i class="bi bi-x-circle"></i> Clear Filter: <?= htmlspecialchars($selectedRootCause) ?>
                </a>
            <?php endif; ?>
        </div>
        <div class="row">
            <?php foreach ($rootCauseSummary as $cause => $count): ?>
                <div class="col-md-3 mb-2">
                    <a href="?<?= http_build_query(array_merge($_GET, ['root_cause' => $cause, 'ticket_page' => 1])) ?>" 
                       style="text-decoration: none;">
                        <span class="badge <?= $selectedRootCause === $cause ? 'bg-success' : 'bg-primary' ?>" 
                              style="font-size: 0.9rem; padding: 0.5rem 1rem; cursor: pointer; transition: all 0.2s;" 
                              onmouseover="this.style.opacity='0.8'; this.style.transform='scale(1.05)'" 
                              onmouseout="this.style.opacity='1'; this.style.transform='scale(1)'"
                              title="Click to filter tickets by this root cause">
                            <?= htmlspecialchars($cause) ?>: <strong><?= $count ?></strong>
                            <?php if ($selectedRootCause === $cause): ?>
                                <i class="bi bi-check-circle-fill"></i>
                            <?php endif; ?>
                        </span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table class="table table-hover ticket-table">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Date</th>
                    <th>Score</th>
                    <th>Type</th>
                    <th>Channel</th>
                    <th>Theme</th>
                    <th>Sentiment</th>
                    <th>Root Cause</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agentTickets as $ticket): ?>
                <tr>
                    <td>
                        <a href="https://getyourguidesupport.zendesk.com/agent/tickets/<?= htmlspecialchars($ticket['ticket_number']) ?>" 
                           target="_blank" 
                           class="ticket-link">
                            <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($ticket['ticket_number']) ?>
                        </a>
                    </td>
                    <td><small><?= date('M d, Y', strtotime($ticket['survey_date'])) ?></small></td>
                    <td>
                        <span class="badge" style="background: <?= $ticket['csat_score'] >= 4 ? '#28a745' : ($ticket['csat_score'] == 3 ? '#ffc107' : '#dc3545') ?>;">
                            <?= $ticket['csat_score'] ?>/5
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $ticket['csat_type'] == 'CSAT' ? 'bg-success' : 'bg-danger' ?>">
                            <?= $ticket['csat_type'] ?>
                        </span>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($ticket['channel_type']) ?></span></td>
                    <td><small><?= htmlspecialchars($ticket['theme']) ?></small></td>
                    <td><small><?= htmlspecialchars($ticket['sentiment']) ?></small></td>
                    <td>
                        <small>
                            <?php 
                            $rootCause = $ticket['root_cause'];
                            if (empty($rootCause) || is_null($rootCause)) {
                                echo '<em style="color: #6c757d;">No root cause</em>';
                            } else {
                                echo htmlspecialchars($rootCause);
                            }
                            ?>
                        </small>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination for Tickets -->
    <?php if ($ticketTotalPages > 1): ?>
    <div class="mt-3">
        <nav aria-label="Ticket pagination">
            <ul class="pagination justify-content-center">
                <!-- Previous Button -->
                <li class="page-item <?= $ticketPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['ticket_page' => $ticketPage - 1])) ?>">
                        <i class="bi bi-chevron-left"></i> Previous
                    </a>
                </li>

                <!-- Page Numbers -->
                <?php
                $startPage = max(1, $ticketPage - 2);
                $endPage = min($ticketTotalPages, $ticketPage + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['ticket_page' => 1])) ?>">1</a>
                    </li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?= $i == $ticketPage ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['ticket_page' => $i])) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($endPage < $ticketTotalPages): ?>
                    <?php if ($endPage < $ticketTotalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['ticket_page' => $ticketTotalPages])) ?>"><?= $ticketTotalPages ?></a>
                    </li>
                <?php endif; ?>

                <!-- Next Button -->
                <li class="page-item <?= $ticketPage >= $ticketTotalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['ticket_page' => $ticketPage + 1])) ?>">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="text-center text-muted mb-3">
            <small>
                Showing page <?= $ticketPage ?> of <?= $ticketTotalPages ?> 
                (<?= number_format($ticketTotalRecords) ?> total tickets, <?= $ticketPerPage ?> per page)
            </small>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Copy table to clipboard function
function copyTableToClipboard() {
    const table = document.querySelector('.agent-table');
    if (!table) return;
    
    const btn = document.getElementById('copyTableBtn');
    const btnText = document.getElementById('copyBtnText');
    const originalText = btnText.textContent;
    
    try {
        let data = [];
        
        // Get headers (excluding Rank column)
        const headers = [];
        const headerCells = table.querySelectorAll('thead th');
        headerCells.forEach((th, index) => {
            if (index > 0) { // Skip Rank column
                // Remove sort arrows from header text
                let headerText = th.textContent.trim();
                headerText = headerText.replace(/[\u25B2\u25BC]/g, '').trim(); // Remove arrow symbols
                headers.push(headerText);
            }
        });
        data.push(headers.join('\t'));
        
        // Get body rows
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const rowData = [];
            const cells = row.querySelectorAll('td');
            
            cells.forEach((cell, index) => {
                if (index > 0) { // Skip Rank column
                    // Get data-value if exists, otherwise get text content
                    let value = cell.dataset.value || cell.textContent.trim();
                    
                    // Clean up the value
                    value = value.replace(/\s+/g, ' ').trim();
                    
                    // Remove commas from numbers for proper formatting
                    if (!isNaN(value.replace(/,/g, ''))) {
                        value = value.replace(/,/g, '');
                    }
                    
                    rowData.push(value);
                }
            });
            
            data.push(rowData.join('\t'));
        });
        
        // Join all rows with newlines
        const textData = data.join('\n');
        
        // Copy to clipboard
        navigator.clipboard.writeText(textData).then(() => {
            // Success feedback
            btn.classList.add('copied');
            btnText.innerHTML = '<i class="bi bi-check-circle"></i> Copied!';
            
            // Reset after 2 seconds
            setTimeout(() => {
                btn.classList.remove('copied');
                btnText.textContent = originalText;
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy: ', err);
            alert('Failed to copy to clipboard. Please try again.');
        });
        
    } catch (error) {
        console.error('Error copying table: ', error);
        alert('Error copying table. Please try again.');
    }
}

// Sorting functionality
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.agent-table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    const headers = table.querySelectorAll('th.sortable');
    
    let currentSort = { column: 7, ascending: false }; // Default sort by CSAT% desc
    
    // Initialize sort arrows
    updateSortArrows();
    
    headers.forEach(header => {
        header.addEventListener('click', function() {
            const column = parseInt(this.dataset.column);
            
            // Toggle sort direction if same column, otherwise default to descending
            if (currentSort.column === column) {
                currentSort.ascending = !currentSort.ascending;
            } else {
                currentSort.column = column;
                currentSort.ascending = false;
            }
            
            sortTable(column, currentSort.ascending);
            updateSortArrows();
        });
    });
    
    function sortTable(column, ascending) {
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            const aCell = a.cells[column];
            const bCell = b.cells[column];
            
            let aValue = aCell.dataset.value || aCell.textContent.trim();
            let bValue = bCell.dataset.value || bCell.textContent.trim();
            
            // Try to parse as numbers
            const aNum = parseFloat(aValue.replace(/,/g, ''));
            const bNum = parseFloat(bValue.replace(/,/g, ''));
            
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return ascending ? aNum - bNum : bNum - aNum;
            }
            
            // String comparison
            if (ascending) {
                return aValue.localeCompare(bValue);
            } else {
                return bValue.localeCompare(aValue);
            }
        });
        
        // Re-append rows in sorted order
        rows.forEach(row => tbody.appendChild(row));
        
        // Update rank badges
        updateRanks();
    }
    
    function updateRanks() {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach((row, index) => {
            const rankBadge = row.querySelector('.rank-badge');
            const rank = index + 1;
            rankBadge.textContent = rank;
            
            // Update badge class
            rankBadge.className = 'rank-badge';
            if (rank === 1) {
                rankBadge.classList.add('rank-1');
            } else if (rank === 2) {
                rankBadge.classList.add('rank-2');
            } else if (rank === 3) {
                rankBadge.classList.add('rank-3');
            } else {
                rankBadge.classList.add('rank-other');
            }
        });
    }
    
    function updateSortArrows() {
        headers.forEach(header => {
            const column = parseInt(header.dataset.column);
            const arrows = header.querySelectorAll('.sort-arrows i');
            
            arrows.forEach(arrow => arrow.classList.remove('active'));
            
            if (column === currentSort.column) {
                const activeArrow = currentSort.ascending ? arrows[0] : arrows[1];
                activeArrow.classList.add('active');
            }
        });
    }
});

// Filter reset logic
document.addEventListener('DOMContentLoaded', function() {
    const weekFilter = document.getElementById('weekFilter');
    const monthFilter = document.getElementById('monthFilter');
    const yearFilter = document.getElementById('yearFilter');
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');

    weekFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            monthFilter.value = 'all';
            startDate.value = '';
            endDate.value = '';
        }
    });

    monthFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            weekFilter.value = 'all';
            startDate.value = '';
            endDate.value = '';
        }
    });

    yearFilter.addEventListener('change', function() {
        if (this.value !== 'all') {
            startDate.value = '';
            endDate.value = '';
        }
    });

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