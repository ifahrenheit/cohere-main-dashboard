<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Optional: show startup errors too
ini_set('display_startup_errors', 1);

session_start();
date_default_timezone_set('Asia/Manila');

// Include database connection
require_once '../config/db_connection.php';

// Get filter parameters
$searchAgent = isset($_GET['agent']) ? trim($_GET['agent']) : '';
$searchTicket = isset($_GET['ticket']) ? trim($_GET['ticket']) : '';
$searchTeam = isset($_GET['team']) ? trim($_GET['team']) : '';
$searchScore = isset($_GET['score']) ? trim($_GET['score']) : '';
$searchChannel = isset($_GET['channel']) ? trim($_GET['channel']) : '';
$searchSentiment = isset($_GET['sentiment']) ? trim($_GET['sentiment']) : '';
$searchTheme = isset($_GET['theme']) ? trim($_GET['theme']) : '';
$startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$weekNumber = isset($_GET['week_number']) ? trim($_GET['week_number']) : '';
$weekYear = isset($_GET['week_year']) ? trim($_GET['week_year']) : date('Y');

// Calculate date range from week number if provided (but not if searching by ticket)
if (!empty($weekNumber) && empty($startDate) && empty($endDate) && empty($searchTicket)) {
    $dto = new DateTime();
    $dto->setISODate($weekYear, $weekNumber);
    $startDate = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $endDate = $dto->format('Y-m-d');
}

// Build WHERE clause for filters
$whereConditions = [];
if (!empty($searchAgent)) {
    $whereConditions[] = "(cs.agent_name LIKE '%" . $conn->real_escape_string($searchAgent) . "%' OR cs.agent_email LIKE '%" . $conn->real_escape_string($searchAgent) . "%')";
}
if (!empty($searchTicket)) {
    $whereConditions[] = "cs.ticket_number LIKE '%" . $conn->real_escape_string($searchTicket) . "%'";
} else {
    if (!empty($startDate) && !empty($endDate)) {
        $whereConditions[] = "cs.survey_date BETWEEN '" . $conn->real_escape_string($startDate) . "' AND '" . $conn->real_escape_string($endDate) . "'";
    }
}
if (!empty($searchTeam)) {
    $whereConditions[] = "cs.team_lead LIKE '%" . $conn->real_escape_string($searchTeam) . "%'";
}
if (!empty($searchScore)) {
    if ($searchScore == 'CSAT') {
        $whereConditions[] = "cs.csat_type = 'CSAT'";
    } elseif ($searchScore == 'DSAT') {
        $whereConditions[] = "cs.csat_type = 'DSAT'";
    }
}
if (!empty($searchChannel)) {
    $whereConditions[] = "cs.channel_type = '" . $conn->real_escape_string($searchChannel) . "'";
}
if (!empty($searchSentiment)) {
    $whereConditions[] = "cs.sentiment = '" . $conn->real_escape_string($searchSentiment) . "'";
}
if (!empty($searchTheme)) {
    $whereConditions[] = "cs.theme LIKE '%" . $conn->real_escape_string($searchTheme) . "%'";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Query for individual CSAT records
$recordsQuery = "
    SELECT 
        cs.ticket_number,
        cs.agent_name,
        cs.agent_email,
        cs.team_lead,
        cs.theme,
        cs.channel_type,
        cs.sentiment,
        cs.csat_score,
        cs.survey_date,
        cs.csat_type,
        cs.tenure,
        cs.agent_group,
        cs.batch,
        WEEK(cs.survey_date, 1) as week_number,
        YEAR(cs.survey_date) as year
    FROM csat_scores cs
    $whereClause
    ORDER BY cs.survey_date DESC, cs.id DESC
    LIMIT 1000
";
$recordsResult = $conn->query($recordsQuery);

// Get unique values for filters
$channelsQuery = "SELECT DISTINCT channel_type FROM csat_scores WHERE channel_type IS NOT NULL AND channel_type != '' ORDER BY channel_type";
$channelsResult = $conn->query($channelsQuery);
$channels = [];
while ($row = $channelsResult->fetch_assoc()) {
    $channels[] = $row['channel_type'];
}

$sentimentsQuery = "SELECT DISTINCT sentiment FROM csat_scores WHERE sentiment IS NOT NULL AND sentiment != '' ORDER BY sentiment";
$sentimentsResult = $conn->query($sentimentsQuery);
$sentiments = [];
while ($row = $sentimentsResult->fetch_assoc()) {
    $sentiments[] = $row['sentiment'];
}

$teamsQuery = "SELECT DISTINCT team_lead FROM csat_scores WHERE team_lead IS NOT NULL AND team_lead != '' ORDER BY team_lead";
$teamsResult = $conn->query($teamsQuery);
$teams = [];
while ($row = $teamsResult->fetch_assoc()) {
    $teams[] = $row['team_lead'];
}

$themesQuery = "SELECT DISTINCT theme FROM csat_scores WHERE theme IS NOT NULL AND theme != '' ORDER BY theme";
$themesResult = $conn->query($themesQuery);
$themes = [];
while ($row = $themesResult->fetch_assoc()) {
    $themes[] = $row['theme'];
}

// Query for team statistics
$teamWhereClause = "";
if (empty($searchTicket) && !empty($startDate) && !empty($endDate)) {
    $teamWhereClause = "WHERE survey_date BETWEEN '" . $conn->real_escape_string($startDate) . "' AND '" . $conn->real_escape_string($endDate) . "'";
}

$teamStatsQuery = "
    SELECT 
        team_lead as team_name,
        COUNT(*) as total_responses,
        SUM(CASE WHEN csat_type = 'CSAT' THEN 1 ELSE 0 END) as csat_count,
        SUM(CASE WHEN csat_type = 'DSAT' THEN 1 ELSE 0 END) as dsat_count,
        ROUND(AVG(csat_score), 2) as avg_score,
        ROUND((SUM(CASE WHEN csat_type = 'CSAT' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage,
        ROUND((SUM(CASE WHEN csat_type = 'DSAT' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as dsat_percentage
    FROM csat_scores
    $teamWhereClause
    GROUP BY team_lead
    HAVING team_lead IS NOT NULL AND team_lead != ''
    ORDER BY csat_percentage DESC
";
$teamStatsResult = $conn->query($teamStatsQuery);

// Channel statistics
$channelStatsQuery = "
    SELECT 
        channel_type,
        COUNT(*) as total,
        SUM(CASE WHEN csat_type = 'CSAT' THEN 1 ELSE 0 END) as csat_count,
        ROUND((SUM(CASE WHEN csat_type = 'CSAT' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage
    FROM csat_scores
    WHERE channel_type IS NOT NULL AND channel_type != ''
    $teamWhereClause
    GROUP BY channel_type
    ORDER BY csat_percentage DESC
";
$channelStatsResult = $conn->query($channelStatsQuery);

// Sentiment statistics
$sentimentStatsQuery = "
    SELECT 
        sentiment,
        COUNT(*) as total,
        ROUND(AVG(csat_score), 2) as avg_score
    FROM csat_scores
    WHERE sentiment IS NOT NULL AND sentiment != ''
    $teamWhereClause
    GROUP BY sentiment
    ORDER BY avg_score DESC
";
$sentimentStatsResult = $conn->query($sentimentStatsQuery);

// Calculate overall statistics
$overallQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN csat_type = 'CSAT' THEN 1 ELSE 0 END) as csat_total,
        SUM(CASE WHEN csat_type = 'DSAT' THEN 1 ELSE 0 END) as dsat_total,
        ROUND(AVG(csat_score), 2) as overall_avg
    FROM csat_scores
    $whereClause
";
$overallResult = $conn->query($overallQuery);
$overallStats = $overallResult->fetch_assoc();

// Calculate CSAT percentage
$csatPercentage = $overallStats['total'] > 0 ? ($overallStats['csat_total'] / $overallStats['total']) * 100 : 0;

// Determine conditional class
$csatClass = '';
if ($csatPercentage >= 90) {
    $csatClass = 'excellent';
} elseif ($csatPercentage >= 88) {
    $csatClass = 'good';
} else {
    $csatClass = 'poor';
}

// Calculate CSATs needed to reach 90%
$csatsNeeded = 0;
$targetReached = false;
if ($overallStats['total'] > 0) {
    $csatsNeeded = (9 * $overallStats['total']) - (10 * $overallStats['csat_total']);
    if ($csatsNeeded <= 0) {
        $targetReached = true;
        $csatsNeeded = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSAT/DSAT Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .main-container {
            max-width: 1600px;
            margin: 0 auto;
        }
        .header-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header-section h1 {
            margin: 0;
            color: #667eea;
            font-weight: 700;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .stats-card .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }
        .stats-card .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stats-card.csat {
            border-left: 5px solid #28a745;
        }
        .stats-card.csat.excellent {
            border-left: 5px solid #28a745;
            background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%);
        }
        .stats-card.csat.excellent .stat-number {
            color: #28a745 !important;
        }
        .stats-card.csat.good {
            border-left: 5px solid #ffc107;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        .stats-card.csat.good .stat-number {
            color: #f59e0b !important;
        }
        .stats-card.csat.poor {
            border-left: 5px solid #dc3545;
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
        }
        .stats-card.csat.poor .stat-number {
            color: #dc3545 !important;
        }
        .stats-card.dsat {
            border-left: 5px solid #dc3545;
        }
        .stats-card.total {
            border-left: 5px solid #667eea;
        }
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        .content-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            margin-bottom: 25px;
        }
        .table-responsive {
            margin-top: 20px;
        }
        table {
            font-size: 0.85rem;
        }
        .badge-csat {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .badge-dsat {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
        }
        .nav-tabs .nav-link {
            color: #667eea;
            font-weight: 600;
        }
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
        }
        .team-comparison-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #667eea;
        }
        .progress {
            height: 20px;
            margin-bottom: 8px;
        }
        .chart-container {
            position: relative;
            height: 350px;
            margin-top: 20px;
        }
        .mini-stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 2px solid #e9ecef;
        }
        .mini-stat-card .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
    </style>
</head>
<body>
<div class="main-container">
    <!-- Header -->
    <div class="header-section">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-graph-up-arrow"></i> CSAT/DSAT Analysis</h1>
                <p class="mb-0 text-muted">Customer Satisfaction Tracking Dashboard</p>
            </div>
            <a href="../dashboard.php" class="btn btn-outline-primary">
                <i class="bi bi-house"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- CSATs Needed Banner -->
    <?php if (!$targetReached && $overallStats['total'] > 0): ?>
    <div class="alert alert-info d-flex align-items-center mb-3" role="alert" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
        <i class="bi bi-bullseye" style="font-size: 2rem; margin-right: 15px; color: #0284c7;"></i>
        <div class="flex-grow-1">
            <h5 class="mb-1"><strong>Target: 90% CSAT</strong></h5>
            <p class="mb-0">
                You need <strong class="text-primary" style="font-size: 1.3rem;"><?= number_format($csatsNeeded) ?> more CSAT</strong> 
                response<?= $csatsNeeded != 1 ? 's' : '' ?> (score 4-5) to reach 90% target.
            </p>
        </div>
    </div>
    <?php elseif ($targetReached && $overallStats['total'] > 0): ?>
    <div class="alert alert-success d-flex align-items-center mb-3" role="alert" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-left: 5px solid #16a34a;">
        <i class="bi bi-trophy-fill" style="font-size: 2rem; margin-right: 15px; color: #16a34a;"></i>
        <div class="flex-grow-1">
            <h5 class="mb-1"><strong>🎉 Target Reached!</strong></h5>
            <p class="mb-0">
                Congratulations! Your CSAT is at <strong style="font-size: 1.3rem;"><?= number_format($csatPercentage, 2) ?>%</strong>. 
                You've met or exceeded the 90% target!
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Overall Statistics -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card csat <?= $csatClass ?>">
                <div class="stat-label">
                    CSAT Percentage
                    <?php if ($csatClass == 'excellent'): ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                    <?php elseif ($csatClass == 'good'): ?>
                        <i class="bi bi-exclamation-circle-fill text-warning"></i>
                    <?php else: ?>
                        <i class="bi bi-x-circle-fill text-danger"></i>
                    <?php endif; ?>
                </div>
                <div class="stat-number">
                    <?= number_format($csatPercentage, 2) ?>%
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card csat">
                <div class="stat-label">Total CSAT</div>
                <div class="stat-number text-success"><?= number_format($overallStats['csat_total']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card dsat">
                <div class="stat-label">Total DSAT</div>
                <div class="stat-number text-danger"><?= number_format($overallStats['dsat_total']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card total">
                <div class="stat-label">Total Responses</div>
                <div class="stat-number text-primary"><?= number_format($overallStats['total']) ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <h5 class="mb-3"><i class="bi bi-funnel"></i> Filters</h5>
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Agent</label>
                    <input type="text" class="form-control" name="agent" value="<?= htmlspecialchars($searchAgent) ?>" placeholder="Name/Email">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ticket ID</label>
                    <input type="text" class="form-control" name="ticket" id="ticketSearch" value="<?= htmlspecialchars($searchTicket) ?>" placeholder="Ticket">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Team Lead</label>
                    <select class="form-select" name="team">
                        <option value="">All Teams</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= htmlspecialchars($team) ?>" <?= $searchTeam == $team ? 'selected' : '' ?>>
                                <?= htmlspecialchars($team) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Channel</label>
                    <select class="form-select" name="channel">
                        <option value="">All Channels</option>
                        <?php foreach ($channels as $channel): ?>
                            <option value="<?= htmlspecialchars($channel) ?>" <?= $searchChannel == $channel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($channel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sentiment</label>
                    <select class="form-select" name="sentiment">
                        <option value="">All</option>
                        <?php foreach ($sentiments as $sentiment): ?>
                            <option value="<?= htmlspecialchars($sentiment) ?>" <?= $searchSentiment == $sentiment ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sentiment) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">CSAT/DSAT</label>
                    <select class="form-select" name="score">
                        <option value="">All</option>
                        <option value="CSAT" <?= $searchScore == 'CSAT' ? 'selected' : '' ?>>CSAT (4-5)</option>
                        <option value="DSAT" <?= $searchScore == 'DSAT' ? 'selected' : '' ?>>DSAT (1-3)</option>
                    </select>
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Theme</label>
                    <select class="form-select" name="theme">
                        <option value="">All Themes</option>
                        <?php foreach ($themes as $theme): ?>
                            <option value="<?= htmlspecialchars($theme) ?>" <?= $searchTheme == $theme ? 'selected' : '' ?>>
                                <?= htmlspecialchars($theme) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Week</label>
                    <select class="form-select" name="week_number" id="weekNumber">
                        <option value="">Select Week</option>
                        <?php for ($w = 1; $w <= 53; $w++): ?>
                            <option value="<?= $w ?>" <?= $weekNumber == $w ? 'selected' : '' ?>>Week <?= $w ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Year</label>
                    <select class="form-select" name="week_year" id="weekYear">
                        <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
                            <option value="<?= $y ?>" <?= $weekYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end justify-content-center">
                    <span class="text-muted">OR</span>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Date Range</label>
                    <div class="input-group">
                        <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($startDate) ?>" id="startDate">
                        <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($endDate) ?>" id="endDate">
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Apply Filters
                </button>
                <a href="?" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Tabs -->
    <div class="content-card">
        <ul class="nav nav-tabs" id="csatTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="records-tab" data-bs-toggle="tab" data-bs-target="#records" type="button">
                    <i class="bi bi-table"></i> Records
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="teams-tab" data-bs-toggle="tab" data-bs-target="#teams" type="button">
                    <i class="bi bi-people"></i> Teams
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="channels-tab" data-bs-toggle="tab" data-bs-target="#channels" type="button">
                    <i class="bi bi-chat-dots"></i> Channels
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="sentiment-tab" data-bs-toggle="tab" data-bs-target="#sentiment" type="button">
                    <i class="bi bi-emoji-smile"></i> Sentiment
                </button>
            </li>
        </ul>

        <div class="tab-content" id="csatTabsContent">
            <!-- Individual Records Tab -->
            <div class="tab-pane fade show active" id="records" role="tabpanel">
                <div class="table-responsive">
                    <p class="mt-3 text-muted">
                        <i class="bi bi-info-circle"></i> Showing <?= $recordsResult->num_rows ?> records (limited to 1000)
                    </p>
                    <?php if ($recordsResult->num_rows > 0): ?>
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Ticket</th>
                                    <th>Agent</th>
                                    <th>Team</th>
                                    <th>Theme</th>
                                    <th>Channel</th>
                                    <th>Score</th>
                                    <th>Type</th>
                                    <th>Sentiment</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $recordsResult->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['ticket_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['agent_name']) ?></td>
                                        <td><span class="badge bg-info"><?= htmlspecialchars($row['team_lead'] ?: 'N/A') ?></span></td>
                                        <td><small><?= htmlspecialchars($row['theme'] ?: '-') ?></small></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['channel_type'] ?: '-') ?></span></td>
                                        <td><span class="badge bg-dark"><?= $row['csat_score'] ?>/5</span></td>
                                        <td>
                                            <?php if ($row['csat_type'] == 'CSAT'): ?>
                                                <span class="badge badge-csat">CSAT</span>
                                            <?php else: ?>
                                                <span class="badge badge-dsat">DSAT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['sentiment'] == 'Positive'): ?>
                                                <span class="badge bg-success">😊 <?= $row['sentiment'] ?></span>
                                            <?php elseif ($row['sentiment'] == 'Negative'): ?>
                                                <span class="badge bg-danger">😞 <?= $row['sentiment'] ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">😐 <?= $row['sentiment'] ?: 'Neutral' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?= date('M d', strtotime($row['survey_date'])) ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> No records found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Team Comparison Tab -->
            <div class="tab-pane fade" id="teams" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="chart-container">
                            <canvas id="teamChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h5 class="mb-3">Team Rankings</h5>
                        <?php
                        $teamStatsResult->data_seek(0);
                        $rank = 1;
                        while ($team = $teamStatsResult->fetch_assoc()):
                        ?>
                            <div class="team-comparison-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">
                                        <span class="badge bg-primary">#<?= $rank++ ?></span>
                                        <?= htmlspecialchars($team['team_name']) ?>
                                    </h6>
                                    <span class="badge bg-success"><?= $team['csat_percentage'] ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar bg-success" style="width: <?= $team['csat_percentage'] ?>%">
                                        <?= $team['csat_count'] ?>
                                    </div>
                                    <div class="progress-bar bg-danger" style="width: <?= $team['dsat_percentage'] ?>%">
                                        <?= $team['dsat_count'] ?>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Total: <?= $team['total_responses'] ?> | Avg: <?= $team['avg_score'] ?>/5
                                </small>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <!-- Channel Analysis Tab -->
            <div class="tab-pane fade" id="channels" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="channelChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="mb-3">CSAT by Channel</h5>
                        <div class="row">
                            <?php
                            $channelStatsResult->data_seek(0);
                            while ($channel = $channelStatsResult->fetch_assoc()):
                            ?>
                                <div class="col-md-6 mb-3">
                                    <div class="mini-stat-card">
                                        <div class="text-muted small"><?= htmlspecialchars($channel['channel_type']) ?></div>
                                        <div class="number"><?= $channel['csat_percentage'] ?>%</div>
                                        <small class="text-muted"><?= $channel['csat_count'] ?>/<?= $channel['total'] ?> CSAT</small>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sentiment Analysis Tab -->
            <div class="tab-pane fade" id="sentiment" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="sentimentChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="mb-3">Average Score by Sentiment</h5>
                        <div class="row">
                            <?php
                            $sentimentStatsResult->data_seek(0);
                            while ($sent = $sentimentStatsResult->fetch_assoc()):
                                $sentIcon = $sent['sentiment'] == 'Positive' ? '😊' : ($sent['sentiment'] == 'Negative' ? '😞' : '😐');
                                $sentColor = $sent['sentiment'] == 'Positive' ? '#28a745' : ($sent['sentiment'] == 'Negative' ? '#dc3545' : '#ffc107');
                            ?>
                                <div class="col-md-6 mb-3">
                                    <div class="mini-stat-card" style="border-color: <?= $sentColor ?>">
                                        <div class="text-muted small"><?= $sentIcon ?> <?= htmlspecialchars($sent['sentiment']) ?></div>
                                        <div class="number" style="color: <?= $sentColor ?>"><?= $sent['avg_score'] ?>/5</div>
                                        <small class="text-muted"><?= $sent['total'] ?> responses</small>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Team Chart
<?php
$teamStatsResult->data_seek(0);
$teamNames = [];
$csatCounts = [];
$dsatCounts = [];
while ($team = $teamStatsResult->fetch_assoc()) {
    $teamNames[] = $team['team_name'];
    $csatCounts[] = $team['csat_count'];
    $dsatCounts[] = $team['dsat_count'];
}
?>

const teamCtx = document.getElementById('teamChart');
new Chart(teamCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($teamNames) ?>,
        datasets: [
            {
                label: 'CSAT',
                data: <?= json_encode($csatCounts) ?>,
                backgroundColor: 'rgba(40, 167, 69, 0.8)'
            },
            {
                label: 'DSAT',
                data: <?= json_encode($dsatCounts) ?>,
                backgroundColor: 'rgba(220, 53, 69, 0.8)'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
    }
});

// Channel Chart
<?php
$channelStatsResult->data_seek(0);
$channelNames = [];
$channelPercentages = [];
while ($channel = $channelStatsResult->fetch_assoc()) {
    $channelNames[] = $channel['channel_type'];
    $channelPercentages[] = $channel['csat_percentage'];
}
?>

const channelCtx = document.getElementById('channelChart');
new Chart(channelCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($channelNames) ?>,
        datasets: [{
            data: <?= json_encode($channelPercentages) ?>,
            backgroundColor: ['#667eea', '#764ba2', '#f093fb', '#4facfe']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            title: { display: true, text: 'CSAT % by Channel' }
        }
    }
});

// Sentiment Chart
<?php
$sentimentStatsResult->data_seek(0);
$sentimentNames = [];
$sentimentScores = [];
$sentimentColors = [];
while ($sent = $sentimentStatsResult->fetch_assoc()) {
    $sentimentNames[] = $sent['sentiment'];
    $sentimentScores[] = $sent['avg_score'];
    $sentimentColors[] = $sent['sentiment'] == 'Positive' ? '#28a745' : ($sent['sentiment'] == 'Negative' ? '#dc3545' : '#ffc107');
}
?>

const sentimentCtx = document.getElementById('sentimentChart');
new Chart(sentimentCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($sentimentNames) ?>,
        datasets: [{
            label: 'Avg Score',
            data: <?= json_encode($sentimentScores) ?>,
            backgroundColor: <?= json_encode($sentimentColors) ?>
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 5 } }
    }
});
</script>
</body>
</html>

<?php $conn->close(); ?>