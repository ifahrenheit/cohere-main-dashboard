<?php
// File: quiz/get_personid.php
include '../db_connection.php';
header('Content-Type: application/json');

$username = $_GET['username'] ?? '';

if (!$username) {
    echo json_encode(['success' => false, 'message' => 'Missing username']);
    exit;
}

$stmt = $conn->prepare("SELECT personid FROM userdata WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'personid' => $row['personid']]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}
