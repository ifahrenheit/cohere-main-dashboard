<?php
session_start();
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'get_questions') {
    $result = $conn->query("SELECT id, question, choice_a, choice_b, choice_c, choice_d FROM quiz_questions ORDER BY RAND() LIMIT 5");

    $questions = [];
    while ($row = $result->fetch_assoc()) {
        $questions[] = $row;
    }

    echo json_encode($questions);
    exit;
}

if ($action === 'submit_answers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    file_put_contents('log_submit.txt', "Error: Failed to prepare statement: " . $conn->error, FILE_APPEND);


    $personid = $data['personid'] ?? 0;
    $answers = $data['answers'] ?? [];

    if (!$personid || !is_array($answers) || empty($answers)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        exit;
    }

    try {
        foreach ($answers as $entry) {
            $qid = (int)($entry['question_id'] ?? 0);
            $selected = $entry['selected_answer'] ?? '';

            if (!$qid || !$selected) {
                continue; // skip invalid entry
            }

            $q = $conn->prepare("SELECT correct_answer FROM quiz_questions WHERE id = ?");
            $q->bind_param("i", $qid);
            $q->execute();
            $res = $q->get_result();

            if ($res->num_rows === 0) {
                throw new Exception("Question ID $qid not found");
            }

            $row = $res->fetch_assoc();
            $correct = $row['correct_answer'];
            $is_correct = strtoupper($selected) === strtoupper($correct) ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO quiz_answers (personid, question_id, selected_answer, correct_answer, is_correct)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iissi", $personid, $qid, $selected, $correct, $is_correct);
            if (!$stmt->execute()) {
                throw new Exception("Insert failed for question ID $qid: " . $stmt->error);
            }
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
