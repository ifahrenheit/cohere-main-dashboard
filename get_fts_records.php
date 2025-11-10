<?php
function getFTSRecordsForAllEmployees(): array {
    $host = 'localhost';
    $username = 'root';
    $password = 'Rootpass123!@#';
    $database = 'central_db';

    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        die("DB connection failed: " . $conn->connect_error);
    }

    // Fetch all employees
    $employeesResult = $conn->query("SELECT EmployeeID, FirstName, LastName FROM Employees");
    $employees = [];
    while ($row = $employeesResult->fetch_assoc()) {
        $employees[$row['EmployeeID']] = $row;
    }

    // Fetch all time records
    $query = "SELECT companyid, day, time, type FROM Timerecords ORDER BY day, time";
    $result = $conn->query($query);

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[$row['companyid']][] = $row;
    }

    $finalRecords = [];
    $filterStart = strtotime($_GET['start_date'] ?? '2000-01-01');
    $filterEnd = strtotime($_GET['end_date'] ?? '2100-01-01');

    foreach ($records as $employeeId => $logs) {
        $previousIN = null;

        foreach ($logs as $log) {
            $date = $log['day'];
            $time = $log['time'];
            $type = $log['type'];
            $currentTimestamp = strtotime("$date $time");

            $name = $employees[$employeeId]['FirstName'] . ' ' . $employees[$employeeId]['LastName'];

            if ($type === "IN") {
                if ($previousIN) {
                    $prevInTime = strtotime($previousIN['day'] . ' ' . $previousIN['time']);
                    $timeDiff = $currentTimestamp - $prevInTime;

                    if ($timeDiff > 15 * 3600) {
                        $ftsDate = strtotime($previousIN['day']);
                        if ($ftsDate >= $filterStart && $ftsDate <= $filterEnd) {
                            $finalRecords[] = [
                                'EmployeeID' => $employeeId,
                                'Name' => $name,
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

        if ($previousIN) {
            $recordDate = strtotime($previousIN['day']);
            if ($recordDate >= $filterStart && $recordDate <= $filterEnd) {
                $finalRecords[] = [
                    'EmployeeID' => $employeeId,
                    'Name' => $employees[$employeeId]['FirstName'] . ' ' . $employees[$employeeId]['LastName'],
                    'Day' => $previousIN['day'],
                    'TimeIN' => $previousIN['time'],
                    'TimeOUT' => 'FTS OUT'
                ];
            }
        }
    }

    usort($finalRecords, fn($a, $b) => strtotime($a['Day']) <=> strtotime($b['Day']));
    return $finalRecords;
}

