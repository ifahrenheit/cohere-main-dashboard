<?php
include '../db_connection.php';

header('Content-Type: application/json');

try {
    // Aggregate quiz answers per username
    $stmt = $conn->prepare("
        SELECT 
            username,
            MAX(companyid) AS companyid,
            MAX(fullname) AS fullname,
            MAX(email) AS email,
            COUNT(*) AS total_questions,
            SUM(is_correct) AS total_correct,
            ROUND(SUM(is_correct)/COUNT(*)*100,2) AS score_percentage,
            SUM(duration_seconds) AS duration_seconds
        FROM quiz_answers
        GROUP BY username
        ORDER BY fullname
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
