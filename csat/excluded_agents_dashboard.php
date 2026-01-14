<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db_connection.php';

$pageTitle = "Excluded Agents Analysis";
include 'includes/header.php';

// Excluded supervisors
$excludedSupervisors = ['#N/A', 'Aireen', 'Baroy', 'Kim', 'Mozo', 'Remir', 'TL'];
$excludeList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $excludedSupervisors)) . "'";
?>

<style>
    body {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .content-card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .page-header {
        background: linear-gradient(135deg, #1e3a8a, #1e40af);
        color: white;
        padding: 2rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    
    .table-responsive {
        max-height: 500px;
        overflow-y: auto;
    }
    
    .summary-box {
        background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        border-left: 5px solid #0284c7;
        padding: 1.5rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
    }
    
    .badge-excluded {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
    }
    
    .badge-transfer {
        background: linear-gradient(135deg, #ffc107, #ff9800);
        color: white;
    }
</style>

<div class="container-fluid">
    <div class="page-header">
        <h2><i class="bi bi-exclamation-triangle"></i> Excluded Agents Analysis</h2>
        <p class="mb-0">Detailed breakdown of the 1,043 excluded responses and agent transfer history</p>
    </div>

    <?php
    // Query 1: Summary by excluded team lead
    $summaryQuery = "
        SELECT 
            current_tl,
            COUNT(DISTINCT agent_email) as num_agents,
            SUM(total_tickets) as total_tickets,
            ROUND(AVG(csat_percentage), 2) as avg_csat
        FROM (
            SELECT 
                agent_email,
                (
                    SELECT team_lead 
                    FROM csat_scores 
                    WHERE agent_email = a.agent_email 
                        AND team_lead IS NOT NULL 
                        AND team_lead != ''
                    ORDER BY survey_date DESC 
                    LIMIT 1
                ) as current_tl,
                COUNT(*) as total_tickets,
                ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage
            FROM csat_scores a
            WHERE agent_email IS NOT NULL 
                AND agent_email != ''
            GROUP BY agent_email
            HAVING current_tl IN ($excludeList)
        ) subq
        GROUP BY current_tl
        ORDER BY num_agents DESC
    ";
    
    $summaryResult = $conn->query($summaryQuery);
    ?>

    <!-- Summary by Excluded TL -->
    <div class="content-card">
        <h4 class="mb-4"><i class="bi bi-bar-chart"></i> Summary by Excluded Team Lead</h4>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead style="background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white;">
                    <tr>
                        <th>Excluded Team Lead</th>
                        <th class="text-center">Number of Agents</th>
                        <th class="text-center">Total Tickets</th>
                        <th class="text-center">Avg CSAT%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $summaryResult->fetch_assoc()): ?>
                    <tr>
                        <td><span class="badge badge-excluded"><?= htmlspecialchars($row['current_tl']) ?></span></td>
                        <td class="text-center"><strong><?= number_format($row['num_agents']) ?></strong></td>
                        <td class="text-center"><?= number_format($row['total_tickets']) ?></td>
                        <td class="text-center"><?= number_format($row['avg_csat'], 2) ?>%</td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // Query 2: All excluded agents detail
    $agentsQuery = "
        SELECT 
            agent_email,
            (
                SELECT agent_name 
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND agent_name IS NOT NULL 
                    AND agent_name != ''
                ORDER BY survey_date DESC 
                LIMIT 1
            ) as agent_name,
            (
                SELECT team_lead 
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND team_lead IS NOT NULL 
                    AND team_lead != ''
                ORDER BY survey_date DESC 
                LIMIT 1
            ) as current_team_lead,
            COUNT(*) as total_tickets,
            MIN(survey_date) as first_ticket_date,
            MAX(survey_date) as last_ticket_date,
            ROUND((SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as csat_percentage,
            COUNT(DISTINCT team_lead) as num_teams_worked_under
        FROM csat_scores a
        WHERE agent_email IS NOT NULL 
            AND agent_email != ''
        GROUP BY agent_email
        HAVING current_team_lead IN ($excludeList)
        ORDER BY total_tickets DESC
    ";
    
    $agentsResult = $conn->query($agentsQuery);
    ?>

    <!-- All Excluded Agents List -->
    <div class="content-card">
        <h4 class="mb-4"><i class="bi bi-people"></i> All Excluded Agents (<?= $agentsResult->num_rows ?>)</h4>
        <div class="summary-box">
            <strong><i class="bi bi-info-circle"></i> Note:</strong> These agents are currently assigned to excluded team leads. 
            All their historical tickets (including from previous valid TLs) are excluded from the main dashboard.
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead style="background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white;">
                    <tr>
                        <th>Agent Name</th>
                        <th>Email</th>
                        <th>Current TL</th>
                        <th class="text-center">Total Tickets</th>
                        <th class="text-center">CSAT%</th>
                        <th class="text-center"># Teams</th>
                        <th>First Ticket</th>
                        <th>Last Ticket</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $agentsResult->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['agent_name']) ?></td>
                        <td><small><?= htmlspecialchars($row['agent_email']) ?></small></td>
                        <td><span class="badge badge-excluded"><?= htmlspecialchars($row['current_team_lead']) ?></span></td>
                        <td class="text-center"><strong><?= number_format($row['total_tickets']) ?></strong></td>
                        <td class="text-center"><?= number_format($row['csat_percentage'], 2) ?>%</td>
                        <td class="text-center">
                            <?php if ($row['num_teams_worked_under'] > 1): ?>
                                <span class="badge badge-transfer"><?= $row['num_teams_worked_under'] ?> teams</span>
                            <?php else: ?>
                                <?= $row['num_teams_worked_under'] ?>
                            <?php endif; ?>
                        </td>
                        <td><small><?= date('M d, Y', strtotime($row['first_ticket_date'])) ?></small></td>
                        <td><small><?= date('M d, Y', strtotime($row['last_ticket_date'])) ?></small></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // Query 3: Agents who transferred TO excluded TLs
    $transfersQuery = "
        SELECT 
            agent_email,
            (
                SELECT agent_name 
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                ORDER BY survey_date DESC 
                LIMIT 1
            ) as agent_name,
            (
                SELECT team_lead 
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND team_lead IS NOT NULL 
                    AND team_lead != ''
                ORDER BY survey_date DESC 
                LIMIT 1
            ) as current_tl,
            (
                SELECT team_lead 
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND team_lead IS NOT NULL 
                    AND team_lead != ''
                    AND team_lead NOT IN ($excludeList)
                ORDER BY survey_date DESC 
                LIMIT 1
            ) as previous_tl,
            (
                SELECT COUNT(*)
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND team_lead NOT IN ($excludeList)
            ) as tickets_under_previous,
            (
                SELECT COUNT(*)
                FROM csat_scores 
                WHERE agent_email = a.agent_email 
                    AND team_lead IN ($excludeList)
            ) as tickets_under_current
        FROM csat_scores a
        WHERE agent_email IS NOT NULL 
            AND agent_email != ''
        GROUP BY agent_email
        HAVING current_tl IN ($excludeList)
            AND previous_tl IS NOT NULL
        ORDER BY (tickets_under_previous + tickets_under_current) DESC
    ";
    
    $transfersResult = $conn->query($transfersQuery);
    ?>

    <!-- Agents Transferred TO Excluded TLs -->
    <div class="content-card">
        <h4 class="mb-4"><i class="bi bi-arrow-right-circle"></i> Agents Who Transferred TO Excluded TLs (<?= $transfersResult->num_rows ?>)</h4>
        <div class="summary-box">
            <strong><i class="bi bi-exclamation-circle"></i> Important:</strong> These agents previously worked under VALID team leads, 
            but were later transferred TO excluded team leads. All their historical tickets (including the good ones) are now excluded.
        </div>
        
        <?php if ($transfersResult->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead style="background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white;">
                    <tr>
                        <th>Agent Name</th>
                        <th>Email</th>
                        <th>Previous TL (Valid)</th>
                        <th>Current TL (Excluded)</th>
                        <th class="text-center">Tickets Under Previous</th>
                        <th class="text-center">Tickets Under Current</th>
                        <th class="text-center">Total Tickets</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $transfersResult->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['agent_name']) ?></td>
                        <td><small><?= htmlspecialchars($row['agent_email']) ?></small></td>
                        <td><span class="badge bg-success"><?= htmlspecialchars($row['previous_tl']) ?></span></td>
                        <td><span class="badge badge-excluded"><?= htmlspecialchars($row['current_tl']) ?></span></td>
                        <td class="text-center"><?= number_format($row['tickets_under_previous']) ?></td>
                        <td class="text-center"><?= number_format($row['tickets_under_current']) ?></td>
                        <td class="text-center"><strong><?= number_format($row['tickets_under_previous'] + $row['tickets_under_current']) ?></strong></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted">No agents found who transferred FROM valid TLs TO excluded TLs.</p>
        <?php endif; ?>
    </div>

    <div class="content-card">
        <h5><i class="bi bi-question-circle"></i> What This Means</h5>
        <ul>
            <li><strong>Excluded Agents:</strong> These agents' current team lead is in the exclusion list, so ALL their tickets are excluded from the main dashboard.</li>
            <li><strong>Transfer Impact:</strong> If an agent transferred TO an excluded TL, even their good performance under previous valid TLs is now excluded.</li>
            <li><strong>Total Impact:</strong> 1,043 responses are excluded because these agents' most recent team lead is excluded.</li>
        </ul>
        <p class="mb-0"><a href="csat_agents.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Back to Agent Dashboard</a></p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>