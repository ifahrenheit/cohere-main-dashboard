<?php
// Common functions used across all pages

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

// Include database connection
// Path from /var/www/html/cohere_dashboard/csat/ to /var/www/html/cohere_dashboard/config/
require_once __DIR__ . '/../../config/db_connection.php';

// Check if connection exists
if (!isset($conn)) {
    die("Database connection variable \$conn not found. Check your db_connection.php file.");
}

// Fetch distinct values for filters
function fetchDistinct($conn, $column, $excludeHeaders = []) {
    $columnEsc = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $res = $conn->query("
        SELECT DISTINCT $columnEsc 
        FROM csat_scores 
        WHERE $columnEsc IS NOT NULL 
          AND $columnEsc != '' 
        ORDER BY $columnEsc
    ");
    
    if (!$res) {
        return [];
    }
    
    $arr = [];
    while ($row = $res->fetch_assoc()) {
        $value = $row[$columnEsc];
        if (!in_array($value, $excludeHeaders, true)) {
            $arr[] = $value;
        }
    }
    return $arr;
}

// Get filter parameters from URL
function getFilterParams() {
    return [
        'agent' => isset($_GET['agent']) ? trim($_GET['agent']) : '',
        'ticket' => isset($_GET['ticket']) ? trim($_GET['ticket']) : '',
        'team' => isset($_GET['team']) ? trim($_GET['team']) : '',
        'score' => isset($_GET['score']) ? trim($_GET['score']) : '',
        'channel' => isset($_GET['channel']) ? trim($_GET['channel']) : '',
        'sentiment' => isset($_GET['sentiment']) ? trim($_GET['sentiment']) : '',
        'theme' => isset($_GET['theme']) ? trim($_GET['theme']) : '',
        'root_cause' => isset($_GET['root_cause']) ? trim($_GET['root_cause']) : '',
        'start_date' => isset($_GET['start_date']) ? trim($_GET['start_date']) : '',
        'end_date' => isset($_GET['end_date']) ? trim($_GET['end_date']) : '',
        'week_number' => isset($_GET['week_number']) ? trim($_GET['week_number']) : '',
        'week_year' => isset($_GET['week_year']) ? trim($_GET['week_year']) : date('Y')
    ];
}

// Build WHERE clause from filters
function buildWhereClause($conn, $filters) {
    $whereConditions = [];
    
    if (!empty($filters['agent'])) {
        $agentEsc = $conn->real_escape_string($filters['agent']);
        $whereConditions[] = "(agent_name LIKE '%$agentEsc%' OR agent_email LIKE '%$agentEsc%')";
    }
    
    if (!empty($filters['ticket'])) {
        $ticketEsc = $conn->real_escape_string($filters['ticket']);
        $whereConditions[] = "ticket_number LIKE '%$ticketEsc%'";
    } else {
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startEsc = $conn->real_escape_string($filters['start_date']);
            $endEsc = $conn->real_escape_string($filters['end_date']);
            $whereConditions[] = "survey_date BETWEEN '$startEsc' AND '$endEsc'";
        }
    }
    
    if (!empty($filters['team'])) {
        $teamEsc = $conn->real_escape_string($filters['team']);
        $whereConditions[] = "team_lead LIKE '%$teamEsc%'";
    }
    
    if (!empty($filters['score'])) {
        if ($filters['score'] === 'CSAT') {
            $whereConditions[] = "csat_type = 'CSAT'";
        } elseif ($filters['score'] === 'DSAT') {
            $whereConditions[] = "csat_type = 'DSAT'";
        }
    }
    
    if (!empty($filters['channel'])) {
        $channelEsc = $conn->real_escape_string($filters['channel']);
        $whereConditions[] = "channel_type = '$channelEsc'";
    }
    
    if (!empty($filters['sentiment'])) {
        $sentimentEsc = $conn->real_escape_string($filters['sentiment']);
        $whereConditions[] = "sentiment = '$sentimentEsc'";
    }
    
    if (!empty($filters['theme'])) {
        $themeEsc = $conn->real_escape_string($filters['theme']);
        $whereConditions[] = "theme LIKE '%$themeEsc%'";
    }
    
    if (!empty($filters['root_cause'])) {
        $rootCauseEsc = $conn->real_escape_string($filters['root_cause']);
        $whereConditions[] = "root_cause = '$rootCauseEsc'";
    }
    
    return !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
}

// Get overall statistics
function getOverallStats($conn, $whereClause) {
    $query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN csat_type='CSAT' THEN 1 ELSE 0 END) as csat_total,
            SUM(CASE WHEN csat_type='DSAT' THEN 1 ELSE 0 END) as dsat_total,
            ROUND(AVG(csat_score),2) as overall_avg
        FROM csat_scores
        $whereClause
    ";
    $result = $conn->query($query);
    
    if (!$result) {
        return ['total' => 0, 'csat_total' => 0, 'dsat_total' => 0, 'overall_avg' => 0];
    }
    
    $row = $result->fetch_assoc();
    
    // Handle NULL values from SUM() when no results match
    return [
        'total' => (int)($row['total'] ?? 0),
        'csat_total' => (int)($row['csat_total'] ?? 0),
        'dsat_total' => (int)($row['dsat_total'] ?? 0),
        'overall_avg' => (float)($row['overall_avg'] ?? 0)
    ];
}

// Calculate CSAT percentage and class
function calculateCSATClass($csatTotal, $total) {
    if ($total == 0) return ['percentage' => 0, 'class' => 'poor'];
    
    $percentage = ($csatTotal / $total) * 100;
    $class = ($percentage >= 90) ? 'excellent' : (($percentage >= 88) ? 'good' : 'poor');
    
    return ['percentage' => $percentage, 'class' => $class];
}

// Calculate CSATs needed for 90% target
function calculateCSATsNeeded($csatTotal, $total) {
    if ($total == 0) return ['needed' => 0, 'reached' => false];
    
    $needed = (9 * $total) - (10 * $csatTotal);
    
    return [
        'needed' => max(0, $needed),
        'reached' => $needed <= 0
    ];
}
?>