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
    $original_date   = htmlspecialchars(trim($_POST['original_date']));
    $original_time   = htmlspecialchars(trim($_POST['original_time']));
    $new_date        = htmlspecialchars(trim($_POST['new_date']));
    $new_time        = htmlspecialchars(trim($_POST['new_time']));
    $reason          = htmlspecialchars(trim($_POST['reason']));

    if (!empty($original_date) && !empty($original_time) && !empty($new_date) && !empty($new_time) && !empty($reason)) {
        // Check if a Pending or Approved request for this date/time exists
        $check_stmt = $conn->prepare("
    SELECT status 
    FROM cws_requests 
    WHERE employee_id = ? 
      AND original_date = ? 
      AND original_time = ? 
      AND deleted_at IS NULL
");
        $check_stmt->bind_param("sss", $employee_id, $original_date, $original_time);
        $check_stmt->execute();
        $result_check = $check_stmt->get_result();

        $found_pending_or_approved = false;

        while ($row = $result_check->fetch_assoc()) {
            if ($row['status'] === 'Pending' || $row['status'] === 'Approved') {
                $found_pending_or_approved = true;
                break;
            }
        }

        if ($found_pending_or_approved) {
            $message = "You have already submitted a change work schedule request for this date and time.";
        } else {
            // Insert the new CWS request
            $stmt = $conn->prepare("INSERT INTO cws_requests (employee_id, original_date, original_time, new_date, new_time, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("ssssss", $employee_id, $original_date, $original_time, $new_date, $new_time, $reason);

            if ($stmt->execute()) {
                header("Location: submit_cws.php?success=1"); // Prevent form resubmission
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

// Fetch logged-in user's CWS requests
$fetch_stmt = $conn->prepare("SELECT original_date, original_time, new_date, new_time, reason, status, approver_name, approved_at, created_at FROM cws_requests WHERE employee_id = ?");
$fetch_stmt->bind_param("s", $employee_id);
$fetch_stmt->execute();
$result = $fetch_stmt->get_result();
$fetch_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Submit Change Work Schedule Request</title>
  <link rel="stylesheet" href="style.css" />
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
          box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
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
      input, textarea {
          width: 100%;
          padding: 8px;
          margin-bottom: 10px;
          border: 1px solid #ccc;
          border-radius: 5px;
      }
      table {
          width: 100%;
          border-collapse: collapse;
          margin-top: 20px;
      }
      table, th, td {
          border: 1px solid #ddd;
      }
      th, td {
          padding: 10px;
          text-align: center;
      }
      .error {
          color: red;
          font-weight: bold;
          margin-bottom: 10px;
      }
      .success {
          color: green;
          font-weight: bold;
          margin-bottom: 10px;
      }
  </style>
</head>
<body>
  <div class="header">
      Change Work Schedule Request
      <div class="logout-btn">
          <a href="dashboard.php"><button>Back to Dashboard</button></a>
          <a href="logout.php"><button>Logout</button></a>
      </div>
  </div>

  <div class="container">
      <?php if (!empty($message)): ?>
          <p class="<?= strpos($message, 'Error') !== false ? 'error' : 'success' ?>">
              <?= htmlspecialchars($message); ?>
          </p>
      <?php endif; ?>

      <form method="POST" action="submit_cws.php">
          <label for="original_date">Original Date:</label>
          <input type="date" name="original_date" required />

          <label for="original_time">Original Time:</label>
          <input type="text" name="original_time" required />

          <label for="new_date">New Date:</label>
          <input type="date" name="new_date" required />

          <label for="new_time">New Time:</label>
          <input type="text" name="new_time" required />

          <label for="reason">Reason:</label>
          <textarea name="reason" rows="4" placeholder="Provide a reason for the schedule change" required></textarea>

          <button type="submit">Submit Request</button>
      </form>
  </div>

  <div class="container full-width">
      <h3>Your Submitted Change Work Schedule Requests</h3>
      <?php if ($result->num_rows > 0): ?>
          <table>
              <thead>
                  <tr>
                      <th>Original Date</th>
                      <th>Original Time</th>
                      <th>New Date</th>
                      <th>New Time</th>
                      <th>Reason</th>
                      <th>Status</th>
                      <th>Approver</th>
                      <th>Approved At</th>
                      <th>Submitted At</th>
                  </tr>
              </thead>
              <tbody>
                  <?php while ($row = $result->fetch_assoc()): ?>
                      <tr>
                          <td><?= htmlspecialchars($row['original_date']); ?></td>
                          <td><?= htmlspecialchars($row['original_time']); ?></td>
                          <td><?= htmlspecialchars($row['new_date']); ?></td>
                          <td><?= htmlspecialchars($row['new_time']); ?></td>
                          <td><?= htmlspecialchars($row['reason']); ?></td>
                          <td><?= htmlspecialchars($row['status']); ?></td>
                          <td><?= htmlspecialchars($row['approver_name']); ?></td>
                          <td><?= htmlspecialchars($row['approved_at']); ?></td>
                          <td><?= htmlspecialchars($row['created_at']); ?></td>
                      </tr>
                  <?php endwhile; ?>
              </tbody>
          </table>
      <?php else: ?>
          <p>No requests found.</p>
      <?php endif; ?>
  </div>
</body>
</html>
