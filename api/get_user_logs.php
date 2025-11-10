<?php
// api/get_user_logs.php

ini_set('session.cookie_samesite', 'Lax');
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$email = $_SESSION['user_email'];

$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get user info
$stmt = $conn->prepare("SELECT personid FROM userdata WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    echo json_encode(['error' => 'User not found']);
    exit;
}

$personID = $user['personid'];
$startDate = $_GET['start_date'] ?? '2025-01-01';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$filterStartDatetime = date('Y-m-d 00:00:00', strtotime($startDate));
$filterEndDatetime = date('Y-m-d 23:59:59', strtotime($endDate));

$stmt = $conn->prepare("
    SELECT 
        u.companyid AS EmployeeID,
        u.fname AS FirstName,
        u.lname AS LastName,
        DATE(t.date) AS Day,
        TIME(t.date) AS Time,
        t.type AS Type
    FROM dailytimerecord t
    JOIN userdata u ON t.personid = u.personid
    WHERE t.personid = ?
    AND t.date BETWEEN ? AND ?
    ORDER BY u.companyid, u.fname, u.lname, t.date
");
$stmt->bind_param("sss", $personID, $filterStartDatetime, $filterEndDatetime);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

echo json_encode([
    'records' => $records,
    'start_date' => $startDate,
    'end_date' => $endDate
]);
?>
