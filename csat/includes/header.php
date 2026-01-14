<?php
// Navigation Header - Include this at the top of each page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSAT Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
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
            color: #1e40af;
            font-weight: 700;
        }
        .nav-pills .nav-link {
            color: #1e40af;
            font-weight: 600;
            border-radius: 10px;
            margin: 0 5px;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: white;
        }
        .nav-pills .nav-link:hover {
            background: rgba(30, 64, 175, 0.1);
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
        .content-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            margin-bottom: 25px;
        }
        .chart-container {
            position: relative;
            height: 350px;
            margin-top: 20px;
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
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
    </style>
</head>
<body>
<div class="main-container">
    <!-- Header -->
    <div class="header-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1><i class="bi bi-graph-up-arrow"></i> CSAT Dashboard</h1>
                <p class="mb-0 text-muted">Customer Satisfaction Analytics</p>
            </div>
            <a href="../dashboard.php" class="btn btn-outline-primary">
                <i class="bi bi-house"></i> Back to Dashboard
            </a>
        </div>
        
        <!-- Navigation -->
        <ul class="nav nav-pills">
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'index.php' || $current_page == 'csat_overview.php' ? 'active' : '' ?>" href="csat_overview.php">
                    <i class="bi bi-speedometer2"></i> Overview
                </a>
            </li>
            <a class="nav-link" href="csat_agents.php">
                    <i class="bi bi-person-badge"></i> Agents
                </a>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'csat_teams.php' ? 'active' : '' ?>" href="csat_teams.php">
                    <i class="bi bi-people"></i> Teams
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'csat_channels.php' ? 'active' : '' ?>" href="csat_channels.php">
                    <i class="bi bi-chat-dots"></i> Channels
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'csat_report.php' ? 'active' : '' ?>" href="csat_report.php">
                    <i class="bi bi-calendar3"></i> Monthly Report
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'contact_reasons_analysis.php' ? 'active' : '' ?>" href="contact_reasons_analysis.php">
                    <i class="bi bi-bar-chart-line-fill"></i> Contact Reasons
                </a>
            </li>
        </ul>
    </div>