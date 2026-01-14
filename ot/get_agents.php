<?php
// ot/get_agents.php
header('Content-Type: application/json');

// Load config
$config_paths = [
    __DIR__ . '/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config.php'
];

$config_found = false;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_found = true;
        break;
    }
}

if (!$config_found || !isset($conn)) {
    echo json_encode(['error' => 'Config or DB connection not found']);
    exit;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_param = "%$query%";

try {

    // UPDATED: Include Team Lead role
    $sql = "SELECT 
                EmployeeID AS id,
                CONCAT(FirstName, ' ', LastName) AS name,
                role
            FROM Employees
            WHERE 
                role IN ('Employee', 'Team Lead', '')
                AND (
                    FirstName LIKE ?
                    OR LastName LIKE ?
                    OR CONCAT(FirstName, ' ', LastName) LIKE ?
                    OR EmployeeID LIKE ?
                )
            ORDER BY FirstName, LastName
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $res = $stmt->get_result();

    $agents = [];
    while ($row = $res->fetch_assoc()) {
        $agents[] = $row;
    }

    echo json_encode($agents);

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>