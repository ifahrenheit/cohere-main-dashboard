<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../db_connection.php';
header('Content-Type: application/json');

$log = "Script started\n";

// Parse raw JSON input
$input = file_get_contents('php://input');
$log .= "Raw input: $input\n";

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $log .= "❌ JSON decode error: " . json_last_error_msg() . "\n";
    file_put_contents("log_submit.txt", $log, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate input
$username         = isset($data['username']) ? strtolower(trim($data['username'])) : null;
$session_id       = $data['session_id'] ?? null;
$answers          = $data['answers'] ?? null;
$duration_seconds = isset($data['duration_seconds']) ? (int)$data['duration_seconds'] : null;
$start_time = isset($data['start_time']) ? date('Y-m-d H:i:s', strtotime($data['start_time'])) : null;


$log .= "Parsed username: $username\n";
$log .= "Parsed session_id: $session_id\n";

if (!$username || !$session_id) {
    $log .= "❌ Missing username or session_id\n";
    file_put_contents("log_submit.txt", $log, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Missing username or session_id']);
    exit;
}

if (!is_array($answers) || count($answers) === 0) {
    $log .= "❌ Invalid or missing answers array\n";
    file_put_contents("log_submit.txt", $log, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid answers']);
    exit;
}

// Fetch user
try {
    $stmt = $conn->prepare("
        SELECT companyid, CONCAT(fname, ' ', lname) AS fullname, email, username 
        FROM userdata 
        WHERE LOWER(username) = LOWER(?) LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $log .= "❌ User not found: $username\n";
        file_put_contents("log_submit.txt", $log, FILE_APPEND);
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $companyid = $user['companyid'];
    $fullname  = $user['fullname'];
    $email     = $user['email'];
    $username  = $user['username'];

} catch (Exception $e) {
    $log .= "❌ DB Error: {$e->getMessage()}\n";
    file_put_contents("log_submit.txt", $log, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// Prepare response
$response = [
    'success' => true,
    'score' => 0,
    'total' => count($answers),
    'correct_answers' => [],
    'duration_seconds' => $duration_seconds
];

// Process answers
foreach ($answers as $answer) {
    $question_id     = isset($answer['question_id']) ? (int)$answer['question_id'] : null;
    $selected_answer = $answer['selected_answer'] ?? null;

    if (!$question_id || !$selected_answer) {
        $log .= "⚠️ Skipping invalid answer: " . json_encode($answer) . "\n";
        continue;
    }

    try {
        // Get correct answer
        $stmt = $conn->prepare("SELECT correct_answer FROM quiz_questions WHERE id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->bind_result($correct_answer);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            $log .= "❌ Question not found: ID $question_id\n";
            continue;
        }

        $is_correct = strtolower($selected_answer) === strtolower($correct_answer) ? 1 : 0;
        if ($is_correct) $response['score']++;

// Insert into quiz_answers
$insert = $conn->prepare("
    INSERT INTO quiz_answers (
        companyid, fullname, email, username, session_id,
        question_id, selected_answer, correct_answer,
        is_correct, answered_at, duration_seconds, start_time
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
");

$insert->bind_param(
    "sssssissiis",
    $companyid,
    $fullname,
    $email,
    $username,
    $session_id,
    $question_id,
    $selected_answer,
    $correct_answer,
    $is_correct,
    $duration_seconds,
    $start_time
);



        $insert->execute();
        $insert->close();

        $log .= "✅ QID $question_id: '$selected_answer' vs '$correct_answer' — " . ($is_correct ? "Correct" : "Wrong") . "\n";

        $response['correct_answers'][] = [
            'question_id'      => $question_id,
            'selected_answer'  => $selected_answer,
            'correct_answer'   => $correct_answer,
            'is_correct'       => $is_correct
        ];

    } catch (Exception $e) {
        $log .= "❌ Error saving answer QID $question_id: {$e->getMessage()}\n";
        $response['success'] = false;
    }
}

// Save session summary
try {
    $score_percent = round(($response['score'] / $response['total']) * 100, 2);

    $stmt = $conn->prepare("
        INSERT INTO quiz_sessions (
            session_id, username, duration_seconds, attempted_at,
            score_percent, total_questions, correct_answers
        ) VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ");
    $stmt->bind_param(
        "ssidii",
        $session_id,
        $username,
        $duration_seconds,
        $score_percent,
        $response['total'],
        $response['score']
    );
    $stmt->execute();
    $stmt->close();

    $log .= "📝 Session recorded: Score {$response['score']} / {$response['total']} ({$score_percent}%), Duration {$duration_seconds}s\n";

} catch (Exception $e) {
    $log .= "❌ Failed to save session: {$e->getMessage()}\n";
    $response['success'] = false;
}

// Log and respond
$log .= "=== Quiz Submit End ===\n\n";
file_put_contents("log_submit.txt", $log, FILE_APPEND);
echo json_encode($response);
?>
