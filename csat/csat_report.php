<?php
require_once 'includes/functions.php';

// Get filter parameters
$selectedWeek = isset($_GET['week']) ? $_GET['week'] : 'all';
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : 'all';
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$selectedChannel = isset($_GET['channel']) ? $_GET['channel'] : 'all';
$selectedTenure = isset($_GET['tenure']) ? $_GET['tenure'] : 'all';

// Build WHERE clause
$whereConditions = ["1=1"];
$params = [];
$paramTypes = "";

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

if ($selectedTenure !== 'all') {
    $whereConditions[] = "tenure = ?";
    $params[] = $selectedTenure;
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

// Determine grouping based on filters
$groupBy = "DATE_FORMAT(survey_date, '%Y-%m')"; // Default: monthly
$labelFormat = '%b %Y'; // Default: Jan 2025
$timeLabel = "Month";

if ($selectedWeek !== 'all') {
    // If week is selected, group by day
    $groupBy = "DATE(survey_date)";
    $labelFormat = '%b %d';
    $timeLabel = "Day";
} elseif (!empty($startDate) && !empty($endDate)) {
    // If date range spans less than 60 days, group by week
    $daysDiff = (strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24);
    if ($daysDiff <= 60) {
        $groupBy = "YEARWEEK(survey_date, 1)";
        $labelFormat = 'Week %v %Y';
        $timeLabel = "Week";
    }
}

// Query 1: Overall performance over time
$overallQuery = "
    SELECT 
        $groupBy as period,
        DATE_FORMAT(survey_date, '$labelFormat') as label,
        COUNT(*) as total,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat,
        SUM(CASE WHEN csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as dsat,
        ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END)/COUNT(*))*100,2) as pct
    FROM csat_scores
    $whereClause
    GROUP BY period, label
    ORDER BY period
";

$stmt1 = $conn->prepare($overallQuery);
if (!empty($params)) {
    $stmt1->bind_param($paramTypes, ...$params);
}
$stmt1->execute();
$result1 = $stmt1->get_result();

$data = [];
$labels = [];
$maxTotal = 0;

while ($row = $result1->fetch_assoc()) {
    $labels[] = $row['label'];
    $data[] = $row;
    $maxTotal = max($maxTotal, $row['total']);
}

// Query 2: Tenure comparison
$tenureQuery = "
    SELECT 
        $groupBy as period,
        tenure,
        COUNT(*) as total,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat,
        ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END)/COUNT(*))*100,2) as pct
    FROM csat_scores
    $whereClause
        AND tenure IN ('Tenured', 'Non-tenured')
    GROUP BY period, tenure
    ORDER BY period, tenure
";

$stmt2 = $conn->prepare($tenureQuery);
if (!empty($params)) {
    $stmt2->bind_param($paramTypes, ...$params);
}
$stmt2->execute();
$result2 = $stmt2->get_result();

$tenureData = [];
$maxTenureTotal = 0;

while ($row = $result2->fetch_assoc()) {
    $period = $row['period'];
    if (!isset($tenureData[$period])) {
        $tenureData[$period] = [];
    }
    $tenureData[$period][$row['tenure']] = $row;
    $maxTenureTotal = max($maxTenureTotal, $row['total']);
}

// Query 3: Channel comparison
$channelQuery = "
    SELECT 
        $groupBy as period,
        channel_type,
        COUNT(*) as total,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat,
        ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END)/COUNT(*))*100,2) as pct
    FROM csat_scores
    $whereClause
        AND channel_type IS NOT NULL
        AND channel_type != ''
    GROUP BY period, channel_type
    ORDER BY period, channel_type
";

$stmt3 = $conn->prepare($channelQuery);
if (!empty($params)) {
    $stmt3->bind_param($paramTypes, ...$params);
}
$stmt3->execute();
$result3 = $stmt3->get_result();

$channelData = [];
$maxChannelTotal = 0;

while ($row = $result3->fetch_assoc()) {
    $period = $row['period'];
    if (!isset($channelData[$period])) {
        $channelData[$period] = [];
    }
    $channelData[$period][$row['channel_type']] = $row;
    $maxChannelTotal = max($maxChannelTotal, $row['total']);
}

// Prepare data arrays for charts
$periods = array_column($data, 'period');
$csatCounts = array_column($data, 'csat');
$dsatCounts = array_column($data, 'dsat');
$totals = array_column($data, 'total');
$csatPcts = array_column($data, 'pct');

// Tenure arrays
$tenuredCounts = [];
$nonTenuredCounts = [];
$tenureTotals = [];
$tenuredPcts = [];
$nonTenuredPcts = [];

foreach ($periods as $period) {
    $tenured = isset($tenureData[$period]['Tenured']) ? $tenureData[$period]['Tenured']['total'] : 0;
    $nonTenured = isset($tenureData[$period]['Non-tenured']) ? $tenureData[$period]['Non-tenured']['total'] : 0;
    
    $tenuredCounts[] = $tenured;
    $nonTenuredCounts[] = $nonTenured;
    $tenureTotals[] = $tenured + $nonTenured;
    
    $tenuredPcts[] = isset($tenureData[$period]['Tenured']) ? $tenureData[$period]['Tenured']['pct'] : null;
    $nonTenuredPcts[] = isset($tenureData[$period]['Non-tenured']) ? $tenureData[$period]['Non-tenured']['pct'] : null;
}

// Channel arrays
$emailCounts = [];
$chatCounts = [];
$phoneCounts = [];
$emailPcts = [];
$chatPcts = [];
$phonePcts = [];

foreach ($periods as $period) {
    $emailCounts[] = isset($channelData[$period]['Email']) ? $channelData[$period]['Email']['total'] : 0;
    $chatCounts[] = isset($channelData[$period]['Chat']) ? $channelData[$period]['Chat']['total'] : 0;
    $phoneCounts[] = isset($channelData[$period]['Phone']) ? $channelData[$period]['Phone']['total'] : 0;
    
    $emailPcts[] = isset($channelData[$period]['Email']) ? $channelData[$period]['Email']['pct'] : null;
    $chatPcts[] = isset($channelData[$period]['Chat']) ? $channelData[$period]['Chat']['pct'] : null;
    $phonePcts[] = isset($channelData[$period]['Phone']) ? $channelData[$period]['Phone']['pct'] : null;
}

include 'includes/header.php';
?>

<style>
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
<?php if ($selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== date('Y') || $selectedChannel !== 'all' || $selectedTenure !== 'all' || !empty($startDate) || !empty($endDate)): ?>
<div class="content-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
    <strong><i class="bi bi-funnel-fill"></i> Active Filters:</strong>
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
        <span class="badge bg-info ms-2"><i class="bi bi-person-badge"></i> Tenure: <?= htmlspecialchars($selectedTenure) ?></span>
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
    <h5 class="mb-3"><i class="bi bi-funnel"></i> Report Filters</h5>
    <form method="GET" action="" id="filterForm">
        <div class="row g-3">
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
                <select name="channel" class="form-select">
                    <option value="all" <?= $selectedChannel === 'all' ? 'selected' : '' ?>>All Channels</option>
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
                    <option value="Tenured" <?= $selectedTenure === 'Tenured' ? 'selected' : '' ?>>Tenured</option>
                    <option value="Non-tenured" <?= $selectedTenure === 'Non-tenured' ? 'selected' : '' ?>>Non-tenured</option>
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
                <a href="csat_reports.php" class="btn btn-reset w-100">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<div class="content-card">
    <h5 class="mb-4"><i class="bi bi-graph-up"></i> Performance Reports</h5>
    
    <?php if (empty($data)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-info-circle"></i> No data available for the selected filters.
        </div>
    <?php else: ?>
    
    <!-- Overall Performance Chart -->
    <h6 class="mb-3 mt-4"><i class="bi bi-bar-chart-line"></i> Overall Performance by <?= $timeLabel ?></h6>
    <div class="chart-container" style="height: 450px;">
        <canvas id="monthlyChart"></canvas>
    </div>
    
    <!-- Tenure Performance Chart -->
    <h6 class="mb-3 mt-5"><i class="bi bi-person-badge"></i> Performance by Tenure</h6>
    <div class="chart-container" style="height: 450px;">
        <canvas id="tenureChart"></canvas>
    </div>
    
    <!-- Channel Performance Chart -->
    <h6 class="mb-3 mt-5"><i class="bi bi-chat-dots"></i> Channel Performance</h6>
    <div class="chart-container" style="height: 550px;">
        <canvas id="channelChart"></canvas>
    </div>
    
    <!-- Summary Table -->
    <div class="table-responsive mt-5">
        <h6 class="mb-3"><i class="bi bi-table"></i> Summary Data</h6>
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr>
                    <th><?= $timeLabel ?></th>
                    <th>Total Responses</th>
                    <th>CSAT Count</th>
                    <th>DSAT Count</th>
                    <th>CSAT %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['label']) ?></strong></td>
                    <td><?= number_format($row['total']) ?></td>
                    <td><span class="badge bg-success"><?= number_format($row['csat']) ?></span></td>
                    <td><span class="badge bg-danger"><?= number_format($row['dsat']) ?></span></td>
                    <td>
                        <span class="badge bg-<?= $row['pct'] >= 90 ? 'success' : ($row['pct'] >= 80 ? 'warning' : 'danger') ?>">
                            <?= number_format($row['pct'], 2) ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script>
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

<?php if (!empty($data)): ?>
// Register the plugin
Chart.register(ChartDataLabels);

// Chart 1: Overall Performance
const ctx = document.getElementById('monthlyChart');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            {
                label: 'CSAT Count (4-5)',
                data: <?= json_encode($csatCounts) ?>,
                backgroundColor: 'rgba(40, 167, 69, 0.7)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 1,
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'center',
                    align: 'center',
                    color: 'white',
                    font: { weight: 'bold', size: 11 },
                    formatter: function(value) {
                        return value > 0 ? value : '';
                    }
                }
            },
            {
                label: 'DSAT Count (1-3)',
                data: <?= json_encode($dsatCounts) ?>,
                backgroundColor: 'rgba(220, 53, 69, 0.7)',
                borderColor: 'rgba(220, 53, 69, 1)',
                borderWidth: 1,
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'center',
                    align: 'center',
                    color: 'white',
                    font: { weight: 'bold', size: 11 },
                    formatter: function(value) {
                        return value > 0 ? value : '';
                    }
                }
            },
            {
                label: 'Total (Hidden)',
                data: <?= json_encode($dsatCounts) ?>,
                backgroundColor: 'rgba(0, 0, 0, 0)',
                borderColor: 'rgba(0, 0, 0, 0)',
                borderWidth: 0,
                yAxisID: 'y',
                stack: 'Stack 0',
                datalabels: {
                    display: true,
                    anchor: 'start',
                    align: 'end',
                    color: 'black',
                    font: { weight: 'bold', size: 12 },
                    offset: 4,
                    formatter: function(value, context) {
                        const total = <?= json_encode($totals) ?>[context.dataIndex];
                        return total > 0 ? total : '';
                    }
                }
            },
            {
                label: 'CSAT %',
                data: <?= json_encode($csatPcts) ?>,
                type: 'line',
                borderColor: 'rgba(102, 126, 234, 1)',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                yAxisID: 'y1',
                pointRadius: 7,
                pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                datalabels: {
                    display: true,
                    align: 'top',
                    offset: 8,
                    backgroundColor: 'rgba(102, 126, 234, 0.95)',
                    borderRadius: 5,
                    color: 'white',
                    font: { weight: 'bold', size: 12 },
                    padding: { top: 4, bottom: 4, left: 6, right: 6 },
                    formatter: function(value) {
                        return value > 0 ? value + '%' : '';
                    }
                }
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    filter: function(item) {
                        return item.text !== 'Total (Hidden)';
                    }
                }
            },
            tooltip: {
                enabled: true,
                filter: function(item) {
                    return item.datasetIndex !== 2;
                }
            }
        },
        scales: {
            x: { stacked: true },
            y: {
                stacked: true,
                beginAtZero: true,
                max: <?= ceil($maxTotal * 1.5) ?>,
                position: 'left',
                title: { display: true, text: 'Response Count', font: { size: 14, weight: 'bold' } }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                max: 100,
                title: { display: true, text: 'CSAT %', font: { size: 14, weight: 'bold' } },
                grid: { drawOnChartArea: false }
            }
        }
    }
});

// Chart 2: Tenure Performance
const tenureCtx = document.getElementById('tenureChart');
new Chart(tenureCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            {
                label: 'Tenured',
                data: <?= json_encode($tenuredCounts) ?>,
                backgroundColor: 'rgba(13, 71, 161, 0.7)',
                borderColor: 'rgba(13, 71, 161, 1)',
                borderWidth: 1,
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'center',
                    align: 'center',
                    color: 'white',
                    font: { weight: 'bold', size: 11 },
                    formatter: function(value) { return value > 0 ? value : ''; }
                }
            },
            {
                label: 'Non-tenured',
                data: <?= json_encode($nonTenuredCounts) ?>,
                backgroundColor: 'rgba(255, 152, 0, 0.7)',
                borderColor: 'rgba(255, 152, 0, 1)',
                borderWidth: 1,
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'center',
                    align: 'center',
                    color: 'white',
                    font: { weight: 'bold', size: 11 },
                    formatter: function(value) { return value > 0 ? value : ''; }
                }
            },
            {
                label: 'Total (Hidden)',
                data: <?= json_encode($nonTenuredCounts) ?>,
                backgroundColor: 'rgba(0, 0, 0, 0)',
                yAxisID: 'y',
                stack: 'Stack 0',
                datalabels: {
                    display: true,
                    anchor: 'start',
                    align: 'end',
                    color: 'black',
                    font: { weight: 'bold', size: 12 },
                    offset: 4,
                    formatter: function(value, context) {
                        const total = <?= json_encode($tenureTotals) ?>[context.dataIndex];
                        return total > 0 ? total : '';
                    }
                }
            },
            {
                label: 'Tenured CSAT %',
                data: <?= json_encode($tenuredPcts) ?>,
                type: 'line',
                borderColor: 'rgba(13, 71, 161, 1)',
                borderWidth: 3,
                yAxisID: 'y1',
                pointRadius: 6,
                spanGaps: true,
                datalabels: {
                    display: true,
                    align: 'top',
                    offset: 10,
                    backgroundColor: 'rgba(13, 71, 161, 0.9)',
                    borderRadius: 4,
                    color: 'white',
                    font: { weight: 'bold', size: 10 },
                    padding: { top: 3, bottom: 3, left: 5, right: 5 },
                    formatter: function(value) { return value > 0 ? value + '%' : ''; }
                }
            },
            {
                label: 'Non-tenured CSAT %',
                data: <?= json_encode($nonTenuredPcts) ?>,
                type: 'line',
                borderColor: 'rgba(255, 152, 0, 1)',
                borderWidth: 3,
                yAxisID: 'y1',
                pointRadius: 6,
                spanGaps: true,
                datalabels: {
                    display: true,
                    align: 'bottom',
                    offset: 10,
                    backgroundColor: 'rgba(255, 152, 0, 0.9)',
                    borderRadius: 4,
                    color: 'white',
                    font: { weight: 'bold', size: 10 },
                    padding: { top: 3, bottom: 3, left: 5, right: 5 },
                    formatter: function(value) { return value > 0 ? value + '%' : ''; }
                }
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    filter: function(item) { return item.text !== 'Total (Hidden)'; }
                }
            },
            tooltip: {
                filter: function(item) { return item.datasetIndex !== 2; }
            }
        },
        scales: {
            x: { stacked: true },
            y: {
                stacked: true,
                beginAtZero: true,
                max: <?= ceil($maxTenureTotal * 1.5) ?>,
                title: { display: true, text: 'Response Count', font: { size: 14, weight: 'bold' } }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                max: 100,
                title: { display: true, text: 'CSAT %', font: { size: 14, weight: 'bold' } },
                grid: { drawOnChartArea: false }
            }
        }
    }
});

// Chart 3: Channel Performance
const channelCtx = document.getElementById('channelChart');
new Chart(channelCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            {
                label: 'Email',
                data: <?= json_encode($emailCounts) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'end',
                    align: 'end',
                    color: 'black',
                    font: { weight: 'bold', size: 10 },
                    formatter: function(value) { return value > 0 ? value : ''; }
                }
            },
            {
                label: 'Chat',
                data: <?= json_encode($chatCounts) ?>,
                backgroundColor: 'rgba(153, 102, 255, 0.7)',
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'end',
                    align: 'end',
                    color: 'black',
                    font: { weight: 'bold', size: 10 },
                    formatter: function(value) { return value > 0 ? value : ''; }
                }
            },
            {
                label: 'Phone',
                data: <?= json_encode($phoneCounts) ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.7)',
                yAxisID: 'y',
                datalabels: {
                    display: true,
                    anchor: 'end',
                    align: 'end',
                    color: 'black',
                    font: { weight: 'bold', size: 10 },
                    formatter: function(value) { return value > 0 ? value : ''; }
                }
            },
            {
                label: 'Email CSAT %',
                data: <?= json_encode($emailPcts) ?>,
                type: 'line',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 2,
                yAxisID: 'y1',
                pointRadius: 5,
                spanGaps: true,
                datalabels: {
                    align: 'bottom',
                    offset: 25,
                    backgroundColor: 'rgba(54, 162, 235, 0.9)',
                    borderRadius: 4,
                    color: 'white',
                    font: { weight: 'bold', size: 9 },
                    padding: { top: 3, bottom: 3, left: 5, right: 5 },
                    formatter: function(value) { return value > 0 ? value + '%' : ''; }
                }
            },
            {
                label: 'Chat CSAT %',
                data: <?= json_encode($chatPcts) ?>,
                type: 'line',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 2,
                borderDash: [5, 5],
                yAxisID: 'y1',
                pointRadius: 5,
                spanGaps: true,
                datalabels: {
                    align: 'top',
                    offset: 25,
                    backgroundColor: 'rgba(153, 102, 255, 0.9)',
                    borderRadius: 4,
                    color: 'white',
                    font: { weight: 'bold', size: 9 },
                    padding: { top: 3, bottom: 3, left: 5, right: 5 },
                    formatter: function(value) { return value > 0 ? value + '%' : ''; }
                }
            },
            {
                label: 'Phone CSAT %',
                data: <?= json_encode($phonePcts) ?>,
                type: 'line',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 2,
                borderDash: [10, 5],
                yAxisID: 'y1',
                pointRadius: 5,
                spanGaps: true,
                datalabels: {
                    align: 'center',
                    backgroundColor: 'rgba(255, 99, 132, 0.9)',
                    borderRadius: 4,
                    color: 'white',
                    font: { weight: 'bold', size: 9 },
                    padding: { top: 3, bottom: 3, left: 5, right: 5 },
                    formatter: function(value) { return value > 0 ? value + '%' : ''; }
                }
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 40, bottom: 40 } },
        scales: {
            y: {
                beginAtZero: true,
                max: <?= ceil($maxChannelTotal * 1.5) ?>,
                title: { display: true, text: 'Response Volume', font: { size: 14, weight: 'bold' } }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                max: 100,
                title: { display: true, text: 'CSAT %', font: { size: 14, weight: 'bold' } },
                grid: { drawOnChartArea: false }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>