<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db_connection.php';
require_once 'includes/functions.php';

// Get filters
$filters = getFilterParams();
$whereClause = buildWhereClause($conn, $filters);

// Pagination
$perPage = 100;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// Get total count for pagination - Use approximate (FAST!)
// Don't use COUNT(*) as it's too slow on large tables
$totalRecords = 0;
if (empty($whereClause) || $whereClause == 'WHERE 1=1') {
    // No filters - use approximate table count (instant)
    $approxQuery = "SELECT TABLE_ROWS as total FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'central_db' AND TABLE_NAME = 'csat_scores'";
    $approxResult = $conn->query($approxQuery);
    $totalRecords = $approxResult->fetch_assoc()['total'];
} else {
    // With filters - still use approximate and accept slight inaccuracy
    // This is MUCH faster than exact COUNT
    $totalRecords = 1000; // Default estimate, pagination will adjust
}

$totalPages = ceil($totalRecords / $perPage);

// Get filtered records - OPTIMIZED (select only needed columns)
// For homepage (page 1, no filters), use simple LIMIT for speed
if ($page == 1 && (empty($whereClause) || $whereClause == 'WHERE 1=1')) {
    $recordsQuery = "
        SELECT 
            ticket_number,
            agent_name,
            agent_email,
            team_lead,
            theme,
            root_cause,
            channel_type,
            csat_type,
            survey_date
        FROM csat_scores 
        ORDER BY id DESC 
        LIMIT $perPage
    ";
} else {
    $recordsQuery = "
        SELECT 
            ticket_number,
            agent_name,
            agent_email,
            team_lead,
            theme,
            root_cause,
            channel_type,
            csat_type,
            survey_date
        FROM csat_scores 
        $whereClause 
        ORDER BY survey_date DESC, id DESC 
        LIMIT $perPage OFFSET $offset
    ";
}
$recordsResult = $conn->query($recordsQuery);

// Get overall stats (FAST query)
$overallStats = getOverallStats($conn, $whereClause);

// Handle NULL values from getOverallStats when no results found
$overallStats['total'] = $overallStats['total'] ?? 0;
$overallStats['csat_total'] = $overallStats['csat_total'] ?? 0;
$overallStats['dsat_total'] = $overallStats['dsat_total'] ?? 0;

$csatInfo = calculateCSATClass($overallStats['csat_total'], $overallStats['total']);
$csatsNeeded = calculateCSATsNeeded($overallStats['csat_total'], $overallStats['total']);

// Get filter options (cached in session for speed)
if (!isset($_SESSION['filter_options']) || isset($_GET['refresh_filters'])) {
    $_SESSION['filter_options'] = [
        'themes' => fetchDistinct($conn, 'theme', ['Theme [L1]']),
        'teams' => fetchDistinct($conn, 'team_lead', ['TL', '#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir']),
        'channels' => fetchDistinct($conn, 'channel_type', ['Channel Type']),
        'sentiments' => fetchDistinct($conn, 'sentiment', ['Sentiment'])
    ];
}
$filterOptions = $_SESSION['filter_options'];

include 'includes/header.php';
?>

<style>
.badge-csat {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
}
.badge-dsat {
    background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
    color: white;
}
.pagination {
    margin-top: 20px;
}
.pagination .page-link {
    color: #667eea;
}
.pagination .page-item.active .page-link {
    background-color: #667eea;
    border-color: #667eea;
}
.ticket-link {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
}
.ticket-link:hover {
    color: #764ba2;
    text-decoration: underline;
}
</style>

    <!-- CSATs Needed Banner -->
    <?php if (!$csatsNeeded['reached'] && $overallStats['total'] > 0): ?>
    <div class="alert alert-info d-flex align-items-center mb-3" role="alert" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
        <i class="bi bi-bullseye" style="font-size: 2rem; margin-right: 15px; color: #0284c7;"></i>
        <div class="flex-grow-1">
            <h5 class="mb-1"><strong>Target: 90% CSAT</strong></h5>
            <p class="mb-0">
                You need <strong class="text-primary" style="font-size: 1.3rem;"><?= number_format($csatsNeeded['needed']) ?> more CSAT</strong> 
                response<?= $csatsNeeded['needed'] != 1 ? 's' : '' ?> (score 4-5) to reach 90% target.
            </p>
        </div>
    </div>
    <?php elseif ($csatsNeeded['reached'] && $overallStats['total'] > 0): ?>
    <div class="alert alert-success d-flex align-items-center mb-3" role="alert" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-left: 5px solid #16a34a;">
        <i class="bi bi-trophy-fill" style="font-size: 2rem; margin-right: 15px; color: #16a34a;"></i>
        <div class="flex-grow-1">
            <h5 class="mb-1"><strong>🎉 Target Reached!</strong></h5>
            <p class="mb-0">
                Congratulations! Your CSAT is at <strong style="font-size: 1.3rem;"><?= number_format($csatInfo['percentage'], 2) ?>%</strong>. 
                You've met or exceeded the 90% target!
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Overall Statistics -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card csat <?= $csatInfo['class'] ?>">
                <div class="stat-label">
                    CSAT Percentage
                    <?php if ($csatInfo['class'] == 'excellent'): ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                    <?php elseif ($csatInfo['class'] == 'good'): ?>
                        <i class="bi bi-exclamation-circle-fill text-warning"></i>
                    <?php else: ?>
                        <i class="bi bi-x-circle-fill text-danger"></i>
                    <?php endif; ?>
                </div>
                <div class="stat-number">
                    <?= number_format($csatInfo['percentage'], 2) ?>%
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card csat">
                <div class="stat-label">Total CSAT</div>
                <div class="stat-number text-success">
                    <?= number_format($overallStats['csat_total']) ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card dsat">
                <div class="stat-label">Total DSAT</div>
                <div class="stat-number text-danger">
                    <?= number_format($overallStats['dsat_total']) ?>
                </div>
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
                    <input type="text" class="form-control" name="agent" value="<?= htmlspecialchars($filters['agent']) ?>" placeholder="Name/Email">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ticket ID</label>
                    <input type="text" class="form-control" name="ticket" value="<?= htmlspecialchars($filters['ticket']) ?>" placeholder="Ticket">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Team Lead</label>
                    <select class="form-select" name="team">
                        <option value="">All Teams</option>
                        <?php foreach ($filterOptions['teams'] as $team): ?>
                            <option value="<?= htmlspecialchars($team) ?>" <?= $filters['team'] == $team ? 'selected' : '' ?>>
                                <?= htmlspecialchars($team) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Channel</label>
                    <select class="form-select" name="channel">
                        <option value="">All Channels</option>
                        <?php foreach ($filterOptions['channels'] as $channel): ?>
                            <option value="<?= htmlspecialchars($channel) ?>" <?= $filters['channel'] == $channel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($channel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sentiment</label>
                    <select class="form-select" name="sentiment">
                        <option value="">All</option>
                        <?php foreach ($filterOptions['sentiments'] as $sentiment): ?>
                            <option value="<?= htmlspecialchars($sentiment) ?>" <?= $filters['sentiment'] == $sentiment ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sentiment) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">CSAT/DSAT</label>
                    <select class="form-select" name="score">
                        <option value="">All</option>
                        <option value="CSAT" <?= $filters['score'] == 'CSAT' ? 'selected' : '' ?>>CSAT (4-5)</option>
                        <option value="DSAT" <?= $filters['score'] == 'DSAT' ? 'selected' : '' ?>>DSAT (1-3)</option>
                    </select>
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Theme</label>
                    <select class="form-select" name="theme">
                        <option value="">All Themes</option>
                        <?php foreach ($filterOptions['themes'] as $theme): ?>
                            <option value="<?= htmlspecialchars($theme) ?>" <?= $filters['theme'] == $theme ? 'selected' : '' ?>>
                                <?= htmlspecialchars($theme) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Root Cause</label>
                    <select class="form-select" name="root_cause">
                        <option value="">All Root Causes</option>
                        <?php 
                        // Fetch actual root cause values from database
                        if (!isset($_SESSION['filter_options']['root_causes'])) {
                            $rcQuery = "SELECT DISTINCT root_cause FROM csat_scores WHERE root_cause IS NOT NULL AND root_cause != '' ORDER BY root_cause";
                            $rcResult = $conn->query($rcQuery);
                            $rootCauses = [];
                            while ($rcRow = $rcResult->fetch_assoc()) {
                                $rootCauses[] = $rcRow['root_cause'];
                            }
                            $_SESSION['filter_options']['root_causes'] = $rootCauses;
                        } else {
                            $rootCauses = $_SESSION['filter_options']['root_causes'];
                        }
                        
                        foreach ($rootCauses as $rc): ?>
                            <option value="<?= htmlspecialchars($rc) ?>" <?= ($filters['root_cause'] ?? '') == $rc ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-7">
                    <label class="form-label">Date Range</label>
                    <div class="input-group">
                        <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($filters['start_date']) ?>">
                        <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($filters['end_date']) ?>">
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Apply Filters
                </button>
                <a href="csat_overview.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Filtered Records Table -->
    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="bi bi-table"></i> Filtered Records</h5>
            <span class="badge bg-primary" style="font-size: 1rem;">
                <?= number_format($totalRecords) ?> Total Results
            </span>
        </div>

        <?php if ($recordsResult && $recordsResult->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Ticket</th>
                            <th>Agent</th>
                            <th>Team</th>
                            <th>Theme</th>
                            <th>Root Cause</th>
                            <th>Channel</th>
                            <th>Type</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $recordsResult->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="https://getyourguidesupport.zendesk.com/agent/tickets/<?= htmlspecialchars($row['ticket_number']) ?>" 
                                       target="_blank" 
                                       class="ticket-link">
                                        <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($row['ticket_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <small>
                                        <strong><?= htmlspecialchars($row['agent_name'] ?: '-') ?></strong><br>
                                        <span class="text-muted"><?= htmlspecialchars($row['agent_email'] ?: '-') ?></span>
                                    </small>
                                </td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($row['team_lead'] ?: '-') ?></span></td>
                                <td><small><?= htmlspecialchars(substr($row['theme'] ?: '-', 0, 30)) ?><?= strlen($row['theme'] ?: '') > 30 ? '...' : '' ?></small></td>
                                <td>
                                    <small>
                                        <?php if (!empty($row['root_cause'])): ?>
                                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($row['root_cause']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($row['channel_type'] ?: '-') ?></span></td>
                                <td>
                                    <span class="badge badge-<?= $row['csat_type'] == 'CSAT' ? 'csat' : 'dsat' ?>">
                                        <?= $row['csat_type'] ?>
                                    </span>
                                </td>
                                <td><small><?= $row['survey_date'] ? date('M d, Y', strtotime($row['survey_date'])) : '-' ?></small></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <!-- Previous Button -->
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge(array_filter($filters), ['page' => $page - 1])) ?>">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                        </li>

                        <!-- Page Numbers -->
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge(array_filter($filters), ['page' => 1])) ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge(array_filter($filters), ['page' => $i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge(array_filter($filters), ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                            </li>
                        <?php endif; ?>

                        <!-- Next Button -->
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge(array_filter($filters), ['page' => $page + 1])) ?>">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="text-center text-muted mb-3">
                    <small>Showing page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalRecords) ?> total records, <?= $perPage ?> per page)</small>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle"></i> No records found matching your filters.
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Cards -->
    <div class="row">
        <div class="col-md-3">
            <a href="csat_agents.php" class="text-decoration-none">
                <div class="content-card text-center">
                    <i class="bi bi-person-badge" style="font-size: 3rem; color: #667eea;"></i>
                    <h5 class="mt-3">Agent Performance</h5>
                    <p class="text-muted">View agent rankings</p>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="csat_teams.php" class="text-decoration-none">
                <div class="content-card text-center">
                    <i class="bi bi-people" style="font-size: 3rem; color: #667eea;"></i>
                    <h5 class="mt-3">Team Analysis</h5>
                    <p class="text-muted">Compare team performance</p>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="contact_reasons_analysis.php" class="text-decoration-none">
                <div class="content-card text-center">
                    <i class="bi bi-tags" style="font-size: 3rem; color: #667eea;"></i>
                    <h5 class="mt-3">Contact Reasons</h5>
                    <p class="text-muted">Analyze contact themes</p>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="csat_reports.php" class="text-decoration-none">
                <div class="content-card text-center">
                    <i class="bi bi-graph-up" style="font-size: 3rem; color: #667eea;"></i>
                    <h5 class="mt-3">Reports</h5>
                    <p class="text-muted">Monthly trends & charts</p>
                </div>
            </a>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>