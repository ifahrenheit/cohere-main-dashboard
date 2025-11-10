<?php
include 'db_connection.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO logs (companyid, day, time, type) VALUES (?, ?, ?, ?)");

foreach ($data as $row) {
    $companyid = $row['companyid'];
    $day = date('Y-m-d', strtotime($row['day']));
    $time = date('H:i:s', strtotime($row['time']));
    $type = $row['type'];

    $stmt->bind_param("ssss", $companyid, $day, $time, $type);
    $stmt->execute();
}

echo json_encode(['status' => 'success']);

