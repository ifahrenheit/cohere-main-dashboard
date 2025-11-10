<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// ✅ Adjusted path since db_connection.php is outside /quiz folder
require_once '../db_connection.php';

$view = $_GET['view'] ?? 'daily';

// Determine date grouping
switch ($view) {
    case 'weekly':
        $date_format = '%x-W%v'; // ISO week format (e.g., 2025-W41)
        $interval = '8 WEEK';
        break;
    case 'monthly':
        $date_format = '%Y-%m';
        $interval = '12 MONTH';
        break;
    default:
        $date_format = '%Y-%m-%d';
        $interval = '14 DAY';
        break;
}

$answers_table = 'quiz_answers';
$questions_table = 'quiz_questions';

// ✅ Use correct column name: q.question
$sql = "
    SELECT 
        q.question AS question_text,
        DATE_FORMAT(a.answered_at, '$date_format') AS period,
        a.is_correct,
        COUNT(*) AS count
    FROM $answers_table a
    JOIN $questions_table q ON a.question_id = q.id
    WHERE a.answered_at >= DATE_SUB(NOW(), INTERVAL $interval)
    GROUP BY q.question, period, a.is_correct
    ORDER BY period ASC, q.question ASC
";

// ✅ For mysqli connection
try {
    $result = $conn->query($sql);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode($data);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
