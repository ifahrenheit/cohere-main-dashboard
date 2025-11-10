<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
require_once "../db_connection.php";

// 🛑 Fix: Properly close the OPTIONS request check
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// 🟢 Now this runs for GET requests
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$search = $_GET['search'] ?? '';

if (!$startDate || !$endDate) {
    http_response_code(400);
    echo json_encode(["error" => "Missing start_date or end_date"]);
    exit;
}

$start = $startDate . " 00:00:00";
$end = $endDate . " 23:59:59";

$sql = "
    SELECT 
        u.companyid AS EmployeeID,
        u.fname AS FirstName,
        u.lname AS LastName,
        DATE(d.date) AS Day,
        TIME(d.date) AS Time,
        d.type AS Type
    FROM dailytimerecord d
    JOIN userdata u ON d.personid = u.personid
    WHERE d.date BETWEEN ? AND ?
";

$params = [$start, $end];
$types = "ss";

// Add search filters if applicable
if (!empty($search)) {
    $sql .= " AND (
        u.fname LIKE ? OR 
        u.lname LIKE ? OR 
        u.companyid LIKE ? OR 
        CONCAT(u.fname, ' ', u.lname) LIKE ?
    )";
    $searchParam = "%" . $search . "%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

$sql .= " ORDER BY u.companyid, d.date";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to prepare statement: " . $conn->error]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
$conn->close();
