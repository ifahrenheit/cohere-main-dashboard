<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../db_connection.php';

$view = $_GET['view'] ?? 'daily';
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

switch ($view) {
    case 'weekly':
        $periodExpr = "CONCAT(YEAR(qa.answered_at), '-W', WEEK(qa.answered_at))";
        $dateCondition = "qa.answered_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)";
        break;

    case 'monthly':
        $periodExpr = "DATE_FORMAT(qa.answered_at, '%Y-%m')";
        $dateCondition = "qa.answered_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        break;

    case 'daily':
    default:
        $periodExpr = "DATE_FORMAT(qa.answered_at, '%Y-%m-%d')";
        if ($start_date && $end_date) {
            $dateCondition = "qa.answered_at BETWEEN '$start_date' AND '$end_date'";
        } else {
            $dateCondition = "qa.answered_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)";
        }
        break;
}

$sql = "
    SELECT 
        qa.fullname,
        qa.username,
        qa.email,
        ud.companyid,
        sm.supervisor_email,
        $periodExpr AS period,
        COUNT(*) AS total_answers,
        SUM(qa.is_correct) AS correct_answers,
        ROUND((SUM(qa.is_correct) / COUNT(*)) * 100, 2) AS accuracy_percent,
        ROUND(AVG(qa.duration_seconds), 2) AS avg_duration
    FROM quiz_answers qa
    LEFT JOIN supervisor_mapping sm 
        ON qa.email = sm.agent_email
    LEFT JOIN userdata ud
        ON qa.email = ud.email
    WHERE $dateCondition
    GROUP BY qa.fullname, qa.username, qa.email, ud.companyid, sm.supervisor_email, period
    ORDER BY period DESC, ud.companyid, qa.fullname
";

$result = $conn->query($sql);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);

$conn->close();
?>
