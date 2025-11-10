<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../db_connection.php";

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // CORS preflight request
    exit(0);
}

// Actual GET request handling
$sql = "SELECT MAX(created) AS last_uploaded_date FROM dailytimerecord";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode(["last_uploaded_date" => $row['last_uploaded_date']]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to fetch last uploaded date"]);
}

$conn->close();
