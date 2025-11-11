<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

if (!isset($_SESSION['employeeID'])) {
    header("Location: login.php");
    exit();
}

$employee_id = $_SESSION['employeeID'];
$message = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $ot_date      = htmlspecialchars(trim($_POST['ot_date'] ?? ''));
    $ot_type      = htmlspecialchars(trim($_POST['ot_type'] ?? ''));
    $regular_rate = htmlspecialchars(trim($_POST['regular_rate'] ?? ''));
    $start_time   = htmlspecialchars(trim($_POST['start_time'] ?? ''));
    $end_time     = htmlspecialchars(trim($_POST['end_time'] ?? ''));

    // Basic required fields check
    if (empty($ot_date) || empty($ot_type) || empty($regular_rate) || empty($start_time) || empty($end_time)) {
        $message = "All fields are required. Please select valid start and end times.";
    } else {
        // ✅ Build DateTime objects for start & end
        $startDateTime = new DateTime($ot_date . ' ' . $start_time);
        $endDateTime   = new DateTime($ot_date . ' ' . $end_time);

        // ✅ If end time is earlier or equal, assume it's next day
        if ($endDateTime <= $startDateTime) {
            $endDateTime->modify('+1 day');
        }

        // ✅ Final check
        if ($endDateTime <= $startDateTime) {
            $message = "End time must be later than start time.";
        } else {
            // Check for existing non-deleted Pending or Approved OT for the same date/type
            $check_stmt = $conn->prepare("
                SELECT id FROM ot_requests 
                WHERE employee_id = ? AND ot_date = ? AND ot_type = ? 
                  AND status IN ('Pending', 'Approved') 
                  AND deleted_at IS NULL
            ");
            $check_stmt->bind_param("sss", $employee_id, $ot_date, $ot_type);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $message = "You already submitted an OT request for this date that's still pending or approved.";
            } else {
                // Insert the OT request (store times as originally selected)
                $stmt = $conn->prepare("
                    INSERT INTO ot_requests 
                    (employee_id, ot_date, ot_type, regular_rate, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending')
                ");
                $stmt->bind_param("ssssss", $employee_id, $ot_date, $ot_type, $regular_rate, $start_time, $end_time);

                if ($stmt->execute()) {
                    header("Location: submit_ot.php?success=1");
                    exit();
                } else {
                    $message = "Error submitting request: " . $stmt->error;
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    }
}

// Fetch logged-in user's OT requests (including soft deleted)
$fetch_stmt = $conn->prepare("
    SELECT ot_date, ot_type, regular_rate, start_time, end_time, status, deleted_at 
    FROM ot_requests 
    WHERE employee_id = ?
    ORDER BY ot_date DESC
");
$fetch_stmt->bind_param("s", $employee_id);
$fetch_stmt->execute();
$result = $fetch_stmt->get_result();
$fetch_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit OT Request</title>
<link rel="stylesheet" href="style.css">
<style>
body {
    font-family: Arial, sans-serif;
    background-color: #f4f4f4;
    margin: 0;
    padding: 0;
}
.container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0px 0px 10px rgba(0,0,0,0.1);
    width: 400px;
    margin: 30px auto;
    text-align: center;
}
.container.full-width {
    width: calc(100% - 40px);
    max-width: 1200px;
    margin: 30px auto;
    padding: 30px;
}
label {
    font-weight: bold;
    display: block;
    margin: 10px 0 5px;
    text-align: left;
}
select, input {
    width: 100%;
    padding: 8px;
    margin-bottom: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
}
.ot-records table {
    width: 100%;
    border-collapse: collapse;
}
.ot-records th, .ot-records td {
    padding: 8px;
    border: 1px solid #ccc;
    text-align: center;
}
.ot-records th {
    background-color: #003366;
    color: white;
}
.deleted {
    background-color: #f8d7da; /* light red */
    color: #721c24;
    font-weight: bold;
}
</style>
</head>
<body>
<div class="header">
    Overtime Request Form
    <div class="logout-btn">
        <a href="dashboard.php"><button>Back to Dashboard</button></a>
        <a href="logout.php"><button>Logout</button></a>
    </div>
</div>

<div class="container">
    <?php if (!empty($message)): ?>
        <p class="<?= strpos($message, 'Error') !== false ? 'error' : 'success' ?>"><?= $message; ?></p>
    <?php endif; ?>

    <form method="POST" action="submit_ot.php">
        <label for="ot_date">OT Date:</label>
        <input type="date" name="ot_date" required>

        <label for="ot_type">OT Type:</label>
        <select name="ot_type" required>
            <option value="" disabled selected>-- Select OT Type --</option>
            <option value="PRE">PRE</option>
            <option value="POST">POST</option>
        </select>

        <label for="regular_rate">Regular Rate:</label>
        <select name="regular_rate" required>
            <option value="" disabled selected>-- Select Regular Rate --</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
        </select>

        <label for="start_time">Start Time of OT:</label>
        <select name="start_time" required>
            <option value="" disabled selected>-- Select Start Time --</option>
            <?php for ($i=0;$i<24;$i++): foreach(['00','30'] as $m): 
                $time = str_pad($i,2,'0',STR_PAD_LEFT).":$m:00"; ?>
                <option value="<?= $time ?>"><?= str_pad($i,2,'0',STR_PAD_LEFT).":$m" ?></option>
            <?php endforeach; endfor; ?>
        </select>

        <label for="end_time">End Time of OT:</label>
        <select name="end_time" required>
            <option value="" disabled selected>-- Select End Time --</option>
            <?php for ($i=0;$i<24;$i++): foreach(['00','30'] as $m): 
                $time = str_pad($i,2,'0',STR_PAD_LEFT).":$m:00"; ?>
                <option value="<?= $time ?>"><?= str_pad($i,2,'0',STR_PAD_LEFT).":$m" ?></option>
            <?php endforeach; endfor; ?>
        </select>

        <button type="submit">Submit Request</button>
    </form>
</div>

<!-- Display logged-in user's filed OT requests -->
<div class="container full-width ot-records">
    <h3>Your Filed OT Requests</h3>
    <?php if ($result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>OT Date</th>
                    <th>OT Type</th>
                    <th>Regular Rate</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr class="<?= $row['deleted_at'] ? 'deleted' : '' ?>">
                        <td><?= htmlspecialchars($row['ot_date']) ?></td>
                        <td><?= htmlspecialchars($row['ot_type']) ?></td>
                        <td><?= htmlspecialchars($row['regular_rate']) ?></td>
                        <td><?= htmlspecialchars($row['start_time']) ?></td>
                        <td><?= htmlspecialchars($row['end_time']) ?></td>
                        <td><?= $row['deleted_at'] ? 'Deleted' : htmlspecialchars($row['status']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No OT requests found.</p>
    <?php endif; ?>
</div>

</body>
</html>
