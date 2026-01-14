<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Session configuration MUST come first
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// Load dependencies
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db_connection.php';

if (!isset($_SESSION['employeeID'])) {
    header("Location: login.php");
    exit();
}

$employee_id = $_SESSION['employeeID'];
$message = "";

// Simplified email function that we know works
function sendEmailToApprover($conn, $employee_id, $original_date, $original_time, $new_date, $new_time, $reason) {
    // Get employee details
    $emp_stmt = $conn->prepare("SELECT CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as name, 
                                       Email as email, 
                                       role, 
                                       SOM, 
                                       som_email 
                                FROM Employees 
                                WHERE EmployeeID = ?");
    $emp_stmt->bind_param("s", $employee_id);
    $emp_stmt->execute();
    $emp_result = $emp_stmt->get_result();
    $employee = $emp_result->fetch_assoc();
    $emp_stmt->close();
    
    if (!$employee) {
        error_log("CWS Error: Employee not found");
        return false;
    }
    
    $employee_name = $employee['name'];
    $employee_role = $employee['role'];
    $som_email = $employee['som_email'] ?? '';
    
    // Determine approver
    $approver_email = "";
    $approver_name = "";
    
    if ($employee_role === "Shifts Operations Manager" || $employee_role === "SOM") {
        $approver_stmt = $conn->prepare("SELECT Email, CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as name 
                                         FROM Employees 
                                         WHERE role = 'SOM Approver' 
                                         LIMIT 1");
        $approver_stmt->execute();
        $approver_result = $approver_stmt->get_result();
        $approver = $approver_result->fetch_assoc();
        $approver_stmt->close();
        
        if ($approver) {
            $approver_email = $approver['Email'];
            $approver_name = $approver['name'];
        }
    } else {
        if (!empty($som_email)) {
            $approver_email = $som_email;
            
            $approver_stmt = $conn->prepare("SELECT CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as name 
                                            FROM Employees 
                                            WHERE Email = ?");
            $approver_stmt->bind_param("s", $approver_email);
            $approver_stmt->execute();
            $approver_result = $approver_stmt->get_result();
            $approver = $approver_result->fetch_assoc();
            $approver_stmt->close();
            
            if ($approver) {
                $approver_name = $approver['name'];
            } else {
                $approver_name = "Supervisor";
            }
        }
    }
    
    if (empty($approver_email)) {
        error_log("CWS Error: No approver email found");
        return false;
    }
    
    // Simple email body (avoiding complex HTML that might cause issues)
    $subject = "New CWS Request - " . $employee_name . " - " . date('M j, Y', strtotime($original_date));
    
    $email_body = "
    <html>
    <body style='font-family: Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #4CAF50;'>New Change Work Schedule Request</h2>
            
            <p>Hello <strong>" . htmlspecialchars($approver_name) . "</strong>,</p>
            
            <p>You have a new Change Work Schedule request that requires your review.</p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>Employee:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($employee_name) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>Employee ID:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($employee_id) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>Original Date:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($original_date) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>Original Time:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($original_time) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>New Date:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($new_date) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>New Time:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($new_time) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'><strong>Reason:</strong></td>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . nl2br(htmlspecialchars($reason)) . "</td>
                </tr>
            </table>
            
            <p style='text-align: center; margin: 30px 0;'>
                <a href='https://dashboard.cohere.ph' 
                   style='background-color: #4CAF50; color: white; padding: 12px 24px; 
                          text-decoration: none; border-radius: 5px; display: inline-block;'>
                    Review Request on Dashboard
                </a>
            </p>
            
            <p style='background-color: #fff3cd; padding: 15px; border-radius: 5px;'>
                <strong>Action Required:</strong> Please log in to the dashboard to approve or reject this request.
            </p>
            
            <hr style='margin-top: 30px;'>
            <p style='font-size: 12px; color: #777; text-align: center;'>
                CWS Request System - This is an automated notification<br>
                <a href='https://dashboard.cohere.ph'>Go to Dashboard</a>
            </p>
        </div>
    </body>
    </html>
    ";
    
    // Send email
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'cohere.ph';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'send_email@cohere.ph';
        $mail->Password   = 'Cohere123456';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 2525;
        
        $mail->setFrom('send_email@cohere.ph', 'CWS Request System');
        $mail->addAddress($approver_email, $approver_name);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $email_body;
        
        $mail->send();
        
        // Log success
        file_put_contents('email_debug.txt', 
            date('Y-m-d H:i:s') . " - CWS Email sent to: $approver_email for $employee_name\n", 
            FILE_APPEND);
        
        return true;
        
    } catch (Exception $e) {
        error_log("CWS Email error: " . $e->getMessage());
        file_put_contents('email_debug.txt', 
            date('Y-m-d H:i:s') . " - CWS EMAIL FAILED: " . $e->getMessage() . "\n", 
            FILE_APPEND);
        return false;
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $original_date   = htmlspecialchars(trim($_POST['original_date']));
    $original_time   = htmlspecialchars(trim($_POST['original_time']));
    $new_date        = htmlspecialchars(trim($_POST['new_date']));
    $new_time        = htmlspecialchars(trim($_POST['new_time']));
    $reason          = htmlspecialchars(trim($_POST['reason']));

    if (!empty($original_date) && !empty($original_time) && !empty($new_date) && !empty($new_time) && !empty($reason)) {
        // Check for existing request
        $check_stmt = $conn->prepare("SELECT status FROM cws_requests WHERE employee_id = ? AND original_date = ? AND original_time = ? AND deleted_at IS NULL");
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
        $check_stmt->close();

        if ($found_pending_or_approved) {
            $message = "You have already submitted a change work schedule request for this date and time.";
        } else {
            // Insert request
            $stmt = $conn->prepare("INSERT INTO cws_requests (employee_id, original_date, original_time, new_date, new_time, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("ssssss", $employee_id, $original_date, $original_time, $new_date, $new_time, $reason);

            if ($stmt->execute()) {
                // Send email (wrapped in try-catch)
                try {
                    sendEmailToApprover($conn, $employee_id, $original_date, $original_time, $new_date, $new_time, $reason);
                } catch (Exception $e) {
                    error_log("Email exception: " . $e->getMessage());
                }
                
                $stmt->close();
                $conn->close();
                
                header("Location: submit_cws.php?success=1");
                exit();
            } else {
                $message = "Error submitting request: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $message = "All fields are required.";
    }
}

// Fetch user's CWS requests
$fetch_stmt = $conn->prepare("SELECT original_date, original_time, new_date, new_time, reason, status, approver_name, approved_at, created_at FROM cws_requests WHERE employee_id = ? ORDER BY created_at DESC");
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

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
          <p class="success">✅ Your request has been submitted successfully! Your supervisor has been notified via email.</p>
      <?php endif; ?>

      <form method="POST" action="submit_cws.php">
          <label for="original_date">Original Date:</label>
          <input type="date" name="original_date" required />

          <label for="original_time">Original Time:</label>
          <input type="text" name="original_time" placeholder="e.g., 8:00 AM - 5:00 PM" required />

          <label for="new_date">New Date:</label>
          <input type="date" name="new_date" required />

          <label for="new_time">New Time:</label>
          <input type="text" name="new_time" placeholder="e.g., 9:00 AM - 6:00 PM" required />

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