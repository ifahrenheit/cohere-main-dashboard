<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db_connection.php';

$pageTitle = "CSAT Agents";
include 'includes/header.php';

// Excluded supervisors
$excludedSupervisors = ['#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir', 'TL'];
$excludeList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $excludedSupervisors)) . "'";

// ULTRA SIMPLE - Just get agents, no filters at all for now
$agentQuery = "
    SELECT 
        agent_email,
        agent_name,
        team_lead,
        tenure,
        COUNT(*) as total_responses,
        SUM(CASE WHEN csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as dsat_count,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as csat_count,
        ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage
    FROM csat_scores
    WHERE agent_email IS NOT NULL
        AND agent_email != ''
        AND team_lead NOT IN ($excludeList)
    GROUP BY agent_email, agent_name, team_lead, tenure
    ORDER BY csat_percentage DESC
    LIMIT 200
";

$agentResult = $conn->query($agentQuery);
$agents = [];
while ($row = $agentResult->fetch_assoc()) {
    $agents[] = $row;
}

$totalAgents = count($agents);
?>

<style>
    body {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        min-height: 100vh;
    }
    
    .page-header {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    table {
        background: white;
        border-radius: 10px;
        overflow: hidden;
    }
</style>

<div class="container-fluid py-4">
    <div class="page-header">
        <h2>🎯 CSAT Agent Performance (Minimal Version)</h2>
        <p>Showing <?= $totalAgents ?> agents</p>
        <p class="text-muted">Ultra-simple version for testing - filters temporarily removed</p>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead style="background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white;">
                <tr>
                    <th>Agent Name</th>
                    <th>Email</th>
                    <th>Team</th>
                    <th>Tenure</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">CSAT</th>
                    <th class="text-center">DSAT</th>
                    <th class="text-center">CSAT %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $agent): ?>
                <tr>
                    <td><?= htmlspecialchars($agent['agent_name']) ?></td>
                    <td><small><?= htmlspecialchars($agent['agent_email']) ?></small></td>
                    <td><?= htmlspecialchars($agent['team_lead']) ?></td>
                    <td><?= htmlspecialchars($agent['tenure']) ?></td>
                    <td class="text-center"><?= number_format($agent['total_responses']) ?></td>
                    <td class="text-center"><?= number_format($agent['csat_count']) ?></td>
                    <td class="text-center"><?= number_format($agent['dsat_count']) ?></td>
                    <td class="text-center">
                        <strong><?= number_format($agent['csat_percentage'], 2) ?>%</strong>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>