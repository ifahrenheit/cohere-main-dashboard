<?php
include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true);

if (!isset($_SESSION['employeeID'])) {
    header("Location: login.php");
    exit();
}

$employee_id = $_SESSION['employeeID'];
$message = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $rd_date = htmlspecialchars(trim($_POST['rd_date']));
    $start_time = htmlspecialchars(trim($_POST['start_time']));
    $end_time = htmlspecialchars(trim($_POST['end_time']));

    if (!empty($rd_date) && !empty($start_time) && !empty($end_time)) {
        // Check if the RD Work request already exists
        $check_stmt = $conn->prepare("SELECT * FROM rd_requests WHERE employee_id = ? AND rd_date = ? AND start_time = ? AND end_time = ?");
        $check_stmt->bind_param("ssss", $employee_id, $rd_date, $start_time, $end_time);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            $message = "You have already submitted an RD Work request for this date and time.";
        } else {
            // Insert the RD Work request
            $stmt = $conn->prepare("INSERT INTO rd_requests (employee_id, rd_date, start_time, end_time, status)
                                    VALUES (?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("ssss", $employee_id, $rd_date, $start_time, $end_time);

            if ($stmt->execute()) {
                header("Location: submit_rdwork.php?success=1"); // Redirect to prevent resubmission
                exit();
            } else {
                $message = "Error submitting request: " . $stmt->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else {
        $message = "All fields are required.";
    }
}

// Fetch logged-in user's RD Work requests
$fetch_stmt = $conn->prepare("SELECT rd_date, start_time, end_time, status FROM rd_requests WHERE employee_id = ?");
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
    <title>Submit RD Work Request</title>
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
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
            width: 400px;
            margin: 30px auto;
            text-align: center;
        }

        .container.full-width {
            width: calc(100% - 40px); /* Full width with 20px margin on each side */
            max-width: 1200px; /* Prevents it from being too wide on large screens */
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

        .ot-records {
            width: 50%;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

    </style>
</head>
<body>
    <div class="header">
        RD Work Form
        <div class="logout-btn">
            <a href="dashboard.php"><button>Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($message)): ?>
            <p class="<?= strpos($message, 'Error') !== false ? 'error' : 'success' ?>"><?= $message; ?></p>
        <?php endif; ?>

        <form method="POST" action="submit_rdwork.php">
            <label for="rd_date">RD Work Date:</label>
            <input type="date" name="rd_date" required>

<label for="start_time">Start Time:</label>
<select name="start_time" required>
    <?php
    for ($i = 0; $i < 24; $i++) {
        foreach (['00', '30'] as $minute) {
            $timeValue = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$minute:00";
            $timeLabel = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$minute";
            echo "<option value=\"$timeValue\">$timeLabel</option>";
        }
    }
    ?>
</select>


<label for="end_time">End Time:</label>
<select name="end_time" required>
    <?php 
    for ($hour = 0; $hour < 24; $hour++) {
        foreach (['00', '30'] as $minute) {
            $time_value = str_pad($hour, 2, '0', STR_PAD_LEFT) . ":$minute:00";
            $time_label = str_pad($hour, 2, '0', STR_PAD_LEFT) . ":$minute";
            echo "<option value='$time_value'>$time_label</option>";
        }
    }
    ?>
</select>

            <button type="submit">Submit Request</button>
        </form>
    </div>

    <!-- Display logged-in user's filed RD Work requests -->
    <div class="container full-width">
        <h3>Your Filed RD Work Requests</h3>
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>RD Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['rd_date']) ?></td>
                            <td><?= htmlspecialchars($row['start_time']) ?></td>
                            <td><?= htmlspecialchars($row['end_time']) ?></td>
                            <td><?= htmlspecialchars($row['status']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No RD Work requests found.</p>
        <?php endif; ?>
    </div>
</body>
</html>

