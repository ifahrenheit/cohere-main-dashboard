<?php
// ot/get_agents_diagnostic.php
// This will show us WHY no results are returned

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Find config.php
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
    echo json_encode(['error' => 'Config or connection issue']);
    exit;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : 'test';
$search_param = "%$query%";

// DIAGNOSTIC MODE - Show what's in the database
if (isset($_GET['debug'])) {
    
    // Test 1: Check if Employees table exists
    $test1 = $conn->query("SHOW TABLES LIKE 'Employees'");
    
    // Test 2: Count total employees
    $test2 = $conn->query("SELECT COUNT(*) as total FROM Employees");
    $total = $test2 ? $test2->fetch_assoc()['total'] : 0;
    
    // Test 3: Count employees with role='Employee'
    $test3 = $conn->query("SELECT COUNT(*) as total FROM Employees WHERE role='Employee'");
    $total_with_role = $test3 ? $test3->fetch_assoc()['total'] : 0;
    
    // Test 4: Show all unique role values
    $test4 = $conn->query("SELECT DISTINCT role FROM Employees");
    $roles = [];
    if ($test4) {
        while ($row = $test4->fetch_assoc()) {
            $roles[] = $row['role'];
        }
    }
    
    // Test 5: Show first 5 employees with their roles
    $test5 = $conn->query("SELECT EmployeeID, FirstName, LastName, role FROM Employees LIMIT 5");
    $sample = [];
    if ($test5) {
        while ($row = $test5->fetch_assoc()) {
            $sample[] = $row;
        }
    }
    
    // Test 6: Check column names
    $test6 = $conn->query("SHOW COLUMNS FROM Employees");
    $columns = [];
    if ($test6) {
        while ($row = $test6->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    
    echo json_encode([
        'debug_mode' => true,
        'table_exists' => $test1 && $test1->num_rows > 0,
        'total_employees' => $total,
        'employees_with_role_Employee' => $total_with_role,
        'all_role_values' => $roles,
        'sample_employees' => $sample,
        'column_names' => $columns,
        'search_query' => $query
    ], JSON_PRETTY_PRINT);
    exit;
}

// NORMAL MODE - Try to search
try {
    $sql = "SELECT DISTINCT
                e.EmployeeID as id,
                CONCAT(e.FirstName, ' ', e.LastName) as name,
                e.role
            FROM Employees e
            WHERE e.role = 'Employee'
            AND (
                e.FirstName LIKE ?
                OR e.LastName LIKE ?
                OR CONCAT(e.FirstName, ' ', e.LastName) LIKE ?
                OR CAST(e.EmployeeID AS CHAR) LIKE ?
            )
            ORDER BY e.FirstName, e.LastName
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();

    $agents = [];
    while ($row = $result->fetch_assoc()) {
        $agents[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'role' => $row['role']  // Include role to verify
        ];
    }

    $stmt->close();
    
    // If no results, show why
    if (count($agents) === 0) {
        // Try query without role filter
        $sql2 = "SELECT COUNT(*) as total FROM Employees 
                 WHERE (FirstName LIKE ? OR LastName LIKE ? OR CONCAT(FirstName, ' ', LastName) LIKE ?)";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("sss", $search_param, $search_param, $search_param);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $total_without_role = $result2->fetch_assoc()['total'];
        $stmt2->close();
        
        echo json_encode([
            'results' => [],
            'info' => 'No results found',
            'search_query' => $query,
            'matches_without_role_filter' => $total_without_role,
            'hint' => 'Try ?debug=1 to see database structure'
        ]);
    } else {
        echo json_encode($agents);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>