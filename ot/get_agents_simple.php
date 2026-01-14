<?php
// ot/get_agents_simple.php
// SIMPLEST POSSIBLE VERSION

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Step 1: Check if we can even get here
if (!isset($_GET['step'])) {
    echo json_encode(['status' => 'PHP works', 'step' => 1]);
    exit;
}

// Step 2: Try to load config
if ($_GET['step'] == 2) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        echo json_encode(['status' => 'Config loaded', 'step' => 2, 'conn_exists' => isset($conn)]);
    } else {
        echo json_encode(['error' => 'Config not found at: ' . __DIR__ . '/config.php']);
    }
    exit;
}

// Step 3: Try simple query
if ($_GET['step'] == 3) {
    require_once __DIR__ . '/config.php';
    
    $result = $conn->query("SELECT EmployeeID, FirstName, LastName FROM Employees WHERE role='Employee' LIMIT 5");
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode(['status' => 'Query works', 'step' => 3, 'count' => count($data), 'data' => $data]);
    exit;
}

// Step 4: Try prepared statement
if ($_GET['step'] == 4) {
    require_once __DIR__ . '/config.php';
    
    $search = "%jer%";
    $sql = "SELECT EmployeeID, FirstName, LastName FROM Employees WHERE role='Employee' AND FirstName LIKE ? LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode(['status' => 'Prepared statement works', 'step' => 4, 'count' => count($data), 'data' => $data]);
    exit;
}
?>