<?php
// quiz/get_questions.php
header('Content-Type: application/json');

// Connect to the database
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Only fetch active questions
$result = $conn->query("SELECT * FROM quiz_questions WHERE is_active = 1 ORDER BY id ASC");

if (!$result) {
    echo json_encode(['error' => 'Query failed: ' . $conn->error]);
    exit;
}

$questions = [];
while ($row = $result->fetch_assoc()) {
    $questions[] = [
        'id' => $row['id'],
        'question' => $row['question'],
        'choices' => [
            'A' => $row['choice_a'],
            'B' => $row['choice_b'],
            'C' => $row['choice_c'],
            'D' => $row['choice_d'],
        ]
    ];
}

if (empty($questions)) {
    echo json_encode(['error' => 'No active questions found']);
    exit;
}

echo json_encode($questions);
?>
