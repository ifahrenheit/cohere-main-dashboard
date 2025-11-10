<?php
include '../db_connection.php';

header('Content-Type: application/json');

try {
    $stmt = $conn->prepare("
        SELECT 
            qa.companyid,
            qa.fullname,
            qa.email,
            SUM(qs.total_questions) AS total_questions,
            SUM(qs.correct_answers) AS total_correct,
            ROUND((SUM(qs.correct_answers) / SUM(qs.total_questions)) * 100, 2) AS score_percentage,
            SUM(qs.duration_seconds) AS duration_seconds
        FROM quiz_sessions qs
        LEFT JOIN (
            SELECT 
                companyid,
                fullname,
                email,
                session_id
            FROM quiz_answers
            GROUP BY companyid, fullname, email, session_id
        ) qa ON qs.session_id = qa.session_id
        GROUP BY qa.companyid, qa.fullname, qa.email
        ORDER BY qa.fullname
    ");

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
