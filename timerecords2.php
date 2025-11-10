<?php
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$database = "central_db";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Pagination settings
$limit = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Get filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Query base
$query = "SELECT t.companyid, e.FirstName, e.LastName, t.day, t.time, t.type 
          FROM Timerecords t
          JOIN Employees e ON t.companyid = e.EmployeeID
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) as total FROM Timerecords t JOIN Employees e ON t.companyid = e.EmployeeID WHERE 1=1";

$params = [];
$types = "";

// Apply search filter
if (!empty($search)) {
//    $query .= " AND (e.FirstName LIKE ? OR e.LastName LIKE ? OR t.companyid LIKE ?)";
//    $countQuery .= " AND (e.FirstName LIKE ? OR e.LastName LIKE ? OR t.companyid LIKE ?)";
    $query .= " AND (CONCAT(e.FirstName, ' ', e.LastName) LIKE ? OR e.FirstName LIKE ? OR e.LastName LIKE ? OR t.companyid LIKE ?)";
    $countQuery .= " AND (CONCAT(e.FirstName, ' ', e.LastName) LIKE ? OR e.FirstName LIKE ? OR e.LastName LIKE ? OR t.companyid LIKE ?)";    
    $searchParam = "%$search%";
    $params[] = &$searchParam;
    $params[] = &$searchParam;
    $params[] = &$searchParam;
    $params[] = &$searchParam;
    $types .= "ssss";
}

// Apply date filter
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND t.day BETWEEN ? AND ?";
    $countQuery .= " AND t.day BETWEEN ? AND ?";
    $params[] = &$start_date;
    $params[] = &$end_date;
    $types .= "ss";
}

// Get total count for pagination
$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$countResult = $stmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// Add pagination to query
$query .= " ORDER BY t.companyid, t.day, t.time";
$stmt = $conn->prepare($query);

// Dynamically bind parameters
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
} 

$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[$row['companyid']][] = $row;
}
//-------------------------------
$finalRecords = [];
$filterStart = strtotime($_GET['start_date'] ?? '2000-01-01');
$filterEnd = strtotime($_GET['end_date'] ?? '2100-01-01');

foreach ($records as $employeeId => $logs) {
    $previousIN = null;

    foreach ($logs as $log) {
        $date = $log['day'];
        $time = $log['time'];
        $type = $log['type'];
        $name = $log['FirstName'] . ' ' . $log['LastName'];
        $currentTimestamp = strtotime("$date $time");

        if ($type === "IN") {
            if ($previousIN) {
                $prevInTime = strtotime($previousIN['day'] . ' ' . $previousIN['time']);
                $timeDiff = $currentTimestamp - $prevInTime;

                if ($timeDiff > 15 * 3600) {
                    $ftsDate = strtotime($previousIN['day']);
                    if ($ftsDate >= $filterStart && $ftsDate <= $filterEnd) {
                        $finalRecords[] = [
                            'EmployeeID' => $employeeId,
                            'Name' => $previousIN['FirstName'] . ' ' . $previousIN['LastName'],
                            'Day' => $previousIN['day'],
                            'TimeIN' => $previousIN['time'],
                            'TimeOUT' => 'FTS OUT'
                        ];
                    }
                    $previousIN = null;
                }
            }
            $previousIN = $log;

        } elseif ($type === "OUT") {
            $outTimestamp = $currentTimestamp;

            // Determine the expected IN date for this OUT
            $prevDate = ($time < "12:00:00") ? date("Y-m-d", strtotime("$date -1 day")) : $date;

            if ($previousIN) {
                $inTimestamp = strtotime($previousIN['day'] . ' ' . $previousIN['time']);
                $timeDiff = $outTimestamp - $inTimestamp;

                if ($timeDiff <= 15 * 3600) {
                    $recordDate = strtotime($previousIN['day']);
                    if ($recordDate >= $filterStart && $recordDate <= $filterEnd) {
                        $finalRecords[] = [
                            'EmployeeID' => $employeeId,
                            'Name' => $name,
                            'Day' => $previousIN['day'],
                            'TimeIN' => $previousIN['time'],
                            'TimeOUT' => $time
                        ];
                    }
                    $previousIN = null;
                } else {
                    $recordDate = strtotime($prevDate);
                    if ($recordDate >= $filterStart && $recordDate <= $filterEnd) {
                        $finalRecords[] = [
                            'EmployeeID' => $employeeId,
                            'Name' => $name,
                            'Day' => $prevDate,
                            'TimeIN' => 'FTS IN',
                            'TimeOUT' => $time
                        ];
                    }
                }
            } else {
                // No matching IN found
                $recordDate = strtotime($prevDate);
                if ($recordDate >= $filterStart && $recordDate <= $filterEnd) {
                    $finalRecords[] = [
                        'EmployeeID' => $employeeId,
                        'Name' => $name,
                        'Day' => $prevDate,
                        'TimeIN' => 'FTS IN',
                        'TimeOUT' => $time
                    ];
                }
            }
        }
    }

    // Handle any unpaired IN left over
    if ($previousIN) {
        $recordDate = strtotime($previousIN['day']);

    // Check if it's the end of the filter range
    $isEndDate = $recordDate === $filterEnd;
    $inTime = strtotime($previousIN['time']);

    // 3:00 PM = 15 * 3600 seconds = 54000
    if (!($isEndDate && $inTime >= 54000)) {
        if ($recordDate >= $filterStart && $recordDate <= $filterEnd) {
            $finalRecords[] = [
                'EmployeeID' => $employeeId,
                'Name' => $previousIN['FirstName'] . ' ' . $previousIN['LastName'],
                'Day' => $previousIN['day'],
                'TimeIN' => $previousIN['time'],
                'TimeOUT' => 'FTS OUT'
            ];
        }
    }
}
}
//-------------------------------

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Time Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">

    <h2 class="mb-4">Employee Time Records</h2>
    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control" placeholder="Search name or ID..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-3">
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="col-md-3">
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Search</button>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
<button onclick="copyTable()">Copy Table</button>
 <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Day</th>
                <th>Time IN</th>
                <th>Time OUT</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($finalRecords as $record) : ?>
                <tr>
                    <td><?= htmlspecialchars($record['EmployeeID']) ?></td>
                    <td><?= htmlspecialchars($record['Name']) ?></td>
                    <td><?= htmlspecialchars($record['Day']) ?></td>
                    <td><?= htmlspecialchars($record['TimeIN']) ?></td>
                    <td><?= htmlspecialchars($record['TimeOUT']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyTable() {
    let table = document.querySelector("table"); // get the first table on the page
    if (!table) return alert("No table found!");

    let range = document.createRange();
    range.selectNode(table);

    let selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);

    try {
        document.execCommand("copy");
        alert("Table copied to clipboard!");
    } catch (err) {
        alert("Failed to copy table.");
    }

    selection.removeAllRanges(); // Clean up
}
</script>

</body>
</html>

