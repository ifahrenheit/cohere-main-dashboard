<?php
// receive_logs.php
header('Content-Type: application/json');

// Include DB connection (one folder above)
require_once __DIR__ . '/../db_connection.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests are allowed.']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
    exit();
}

// Prepare statement
$stmt = $conn->prepare("INSERT INTO logs (companyid, day, time, type, created_at) VALUES (?, ?, ?, ?, NOW())");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

foreach ($data as $entry) {
    $companyid = $entry['companyid'] ?? '';
    $day = $entry['day'] ?? '';
    $time = $entry['time'] ?? '';
    $type = $entry['type'] ?? '';

    // Validate and format date to YYYY-MM-DD
    $day_parts = date_parse_from_format('Y-m-d', $day);
    if (!checkdate($day_parts['month'], $day_parts['day'], $day_parts['year'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Invalid date format for entry: $day"]);
        exit();
    }

    $stmt->bind_param("ssss", $companyid, $day, $time, $type);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Execute failed: ' . $stmt->error]);
        exit();
    }
}

$stmt->close();
$conn->close();

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Logs inserted successfully.']);