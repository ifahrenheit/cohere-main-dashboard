<?php
require_once 'includes/functions.php';

// Get filters
$filters = getFilterParams();

// Build WHERE clause for channel stats
$channelWhereClause = "WHERE 1=1";
if (!empty($filters['start_date']) && !empty($filters['end_date']) && empty($filters['ticket'])) {
    $startEsc = $conn->real_escape_string($filters['start_date']);
    $endEsc = $conn->real_escape_string($filters['end_date']);
    $channelWhereClause .= " AND survey_date BETWEEN '$startEsc' AND '$endEsc'";
}

// Channel stats query (FAST)
$channelStatsQuery = "
    SELECT 
        channel_type,
        COUNT(*) as total,
        SUM(CASE WHEN csat_type='CSAT' THEN 1 ELSE 0 END) as csat_count,
        SUM(CASE WHEN csat_type='DSAT' THEN 1 ELSE 0 END) as dsat_count,
        ROUND((SUM(CASE WHEN csat_type='CSAT' THEN 1 ELSE 0 END)/COUNT(*))*100,2) as csat_percentage,
        ROUND(AVG(csat_score),2) as avg_score
    FROM csat_scores
    $channelWhereClause
    GROUP BY channel_type
    ORDER BY csat_percentage DESC
";
$channelStatsResult = $conn->query($channelStatsQuery);

// Sentiment stats query (FAST)
$sentimentStatsQuery = "
    SELECT 
        sentiment,
        COUNT(*) as total,
        ROUND(AVG(csat_score),2) as avg_score
    FROM csat_scores
    WHERE sentiment IS NOT NULL AND sentiment != ''
    GROUP BY sentiment
    ORDER BY avg_score DESC
";
$sentimentStatsResult = $conn->query($sentimentStatsQuery);

include 'includes/header.php';
?>

    <!-- Channel Analysis -->
    <div class="content-card">
        <h5 class="mb-3"><i class="bi bi-chat-dots"></i> Channel Performance</h5>
        
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <canvas id="channelChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="mb-3">CSAT by Channel</h6>
                <div class="row">
                    <?php
                    $channelStatsResult->data_seek(0);
                    while ($channel = $channelStatsResult->fetch_assoc()):
                        $class = ($channel['csat_percentage'] >= 90) ? 'success' : (($channel['csat_percentage'] >= 88) ? 'warning' : 'danger');
                    ?>
                        <div class="col-md-6 mb-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <div class="text-muted small"><?= htmlspecialchars($channel['channel_type']) ?></div>
                                    <div class="display-6 text-<?= $class ?> fw-bold"><?= $channel['csat_percentage'] ?>%</div>
                                    <small class="text-muted"><?= $channel['csat_count'] ?>/<?= $channel['total'] ?> CSAT</small>
                                    <div class="mt-2">
                                        <small class="text-muted">Avg Score: <?= $channel['avg_score'] ?>/5</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sentiment Analysis -->
    <div class="content-card">
        <h5 class="mb-3"><i class="bi bi-emoji-smile"></i> Sentiment Analysis</h5>
        
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <canvas id="sentimentChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="mb-3">Average Score by Sentiment</h6>
                <div class="row">
                    <?php
                    $sentimentStatsResult->data_seek(0);
                    while ($sent = $sentimentStatsResult->fetch_assoc()):
                        $sentIcon = $sent['sentiment'] == 'Positive' ? '😊' : ($sent['sentiment'] == 'Negative' ? '😞' : '😐');
                        $sentColor = $sent['sentiment'] == 'Positive' ? 'success' : ($sent['sentiment'] == 'Negative' ? 'danger' : 'warning');
                    ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border-<?= $sentColor ?>">
                                <div class="card-body text-center">
                                    <div class="text-muted small"><?= $sentIcon ?> <?= htmlspecialchars($sent['sentiment']) ?></div>
                                    <div class="display-6 text-<?= $sentColor ?> fw-bold"><?= $sent['avg_score'] ?>/5</div>
                                    <small class="text-muted"><?= $sent['total'] ?> responses</small>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

<script>
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

<?php include 'includes/footer.php'; ?>