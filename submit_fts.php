<?php
// Session configuration MUST come first
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db_connection.php';

if (!isset($_SESSION['employeeID']) || empty($_SESSION['employeeID'])) {
    die("Session Error: Employee ID is missing!");
}

$employee_id = $_SESSION['employeeID'];

// Professional email function for FTS
function sendFTSEmailToApprover($conn, $employee_id, $employee_name, $employee_role, $fts_date, $fts_time, $fts_type, $approver_email, $approver_name) {
    
    // Create professional email body
    $subject = "New FTS Request - " . $employee_name . " - " . date('M j, Y', strtotime($fts_date));
    
    $email_body = "
    <html>
    <body style='font-family: Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #2196F3; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;'>
                <h2 style='margin: 0;'>New Failure to Swipe Request</h2>
            </div>
            
            <div style='background-color: #f9f9f9; padding: 25px; border: 1px solid #ddd; border-top: none;'>
                <p style='font-size: 16px; margin-bottom: 20px;'>
                    Hello <strong>" . htmlspecialchars($approver_name) . "</strong>,
                </p>
                
                <p>You have a new Failure to Swipe request that <strong>requires your review and approval</strong>.</p>
                
                <div style='background-color: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0;'>
                    <strong style='color: #1976d2;'>Employee Information</strong>
                </div>
                
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 12px; border: 1px solid #ddd; background-color: #f5f5f5;'><strong>Employee:</strong></td>
                        <td style='padding: 12px; border: 1px solid #ddd;'>" . htmlspecialchars($employee_name) . " 
                            <span style='background-color: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold;'>" . htmlspecialchars($employee_role) . "</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 12px; border: 1px solid #ddd; background-color: #f5f5f5;'><strong>Employee ID:</strong></td>
                        <td style='padding: 12px; border: 1px solid #ddd;'>" . htmlspecialchars($employee_id) . "</td>
                    </tr>
                </table>
                
                <div style='background-color: #fff3e0; padding: 15px; border-left: 4px solid #ff9800; margin: 20px 0;'>
                    <strong style='color: #e65100;'>FTS Details</strong>
                </div>
                
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 12px; border: 1px solid #ddd; background-color: #f5f5f5;'><strong>FTS Date:</strong></td>
                        <td style='padding: 12px; border: 1px solid #ddd;'>" . htmlspecialchars(date('l, F j, Y', strtotime($fts_date))) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px; border: 1px solid #ddd; background-color: #f5f5f5;'><strong>FTS Time:</strong></td>
                        <td style='padding: 12px; border: 1px solid #ddd;'><strong>" . htmlspecialchars($fts_time) . "</strong></td>
                    </tr>
                    <tr>
                        <td style='padding: 12px; border: 1px solid #ddd; background-color: #f5f5f5;'><strong>FTS Type:</strong></td>
                        <td style='padding: 12px; border: 1px solid #ddd;'>
                            <span style='background-color: " . ($fts_type == 'IN' ? '#4CAF50' : '#f44336') . "; color: white; padding: 6px 12px; border-radius: 4px; font-weight: bold;'>" . htmlspecialchars($fts_type) . "</span>
                        </td>
                    </tr>
                </table>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='https://dashboard.cohere.ph' 
                       style='background-color: #2196F3; color: white; padding: 14px 28px; 
                              text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; font-size: 16px;'>
                        Review Request on Dashboard
                    </a>
                </p>
                
                <div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>
                    <strong>Action Required:</strong> Please log in to the dashboard to approve or reject this request. 
                    " . htmlspecialchars($employee_name) . " is waiting for your response.
                </div>
            </div>
            
            <div style='margin-top: 20px; font-size: 12px; color: #777; text-align: center; padding-top: 15px; border-top: 1px solid #ddd;'>
                <p><strong>FTS Request System</strong></p>
                <p>This is an automated notification. Please do not reply to this email.</p>
                <p style='margin-top: 10px;'>
                    <a href='https://dashboard.cohere.ph' style='color: #2196F3; text-decoration: none;'>Go to Dashboard</a> | 
                    <a href='https://cohere.ph' style='color: #2196F3; text-decoration: none;'>Visit Website</a>
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email using PHPMailer
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'cohere.ph';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'send_email@cohere.ph';
        $mail->Password   = 'Cohere123456';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 2525;
        
        $mail->setFrom('send_email@cohere.ph', 'FTS Request System');
        $mail->addAddress($approver_email, $approver_name);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $email_body;
        
        // Plain text version
        $plain_text = "New Failure to Swipe Request\n\n";
        $plain_text .= "Hello $approver_name,\n\n";
        $plain_text .= "Employee: " . $employee_name . " (" . $employee_role . ") - ID: " . $employee_id . "\n\n";
        $plain_text .= "FTS Date: " . $fts_date . "\n";
        $plain_text .= "FTS Time: " . $fts_time . "\n";
        $plain_text .= "FTS Type: " . $fts_type . "\n\n";
        $plain_text .= "Please visit https://dashboard.cohere.ph to review and approve/reject this request.";
        
        $mail->AltBody = $plain_text;
        
        $mail->send();
        
        // Log success
        file_put_contents('email_debug.txt', 
            date('Y-m-d H:i:s') . " - FTS Email sent to: $approver_email ($approver_name) for $employee_name\n", 
            FILE_APPEND);
        
        return true;
        
    } catch (Exception $e) {
        error_log("FTS Email error: " . $e->getMessage());
        file_put_contents('email_debug.txt', 
            date('Y-m-d H:i:s') . " - FTS EMAIL FAILED to: $approver_email - Error: {$mail->ErrorInfo}\n", 
            FILE_APPEND);
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fts_date   = trim($_POST['fts_date'] ?? '');
    $fts_hour   = trim($_POST['fts_hour'] ?? '');
    $fts_minute = trim($_POST['fts_minute'] ?? '');
    $fts_type   = trim($_POST['fts_type'] ?? '');

    if (empty($fts_date) || empty($fts_hour) || empty($fts_minute) || empty($fts_type)) {
        die("Error: Missing required fields.");
    }

    $fts_time = sprintf("%02d:%02d:00", $fts_hour, $fts_minute);

    // Get employee details including som_email
    $stmt = $conn->prepare("SELECT FirstName, LastName, role, SOM, som_email 
                            FROM Employees 
                            WHERE EmployeeID = ?");
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $stmt->bind_result($firstname, $lastname, $role, $som_name, $som_email);
    $stmt->fetch();
    $stmt->close();

    if (empty($firstname) || empty($lastname)) {
        die("Error: Employee details not found.");
    }

    $employee_name = "$firstname $lastname";

    // Determine approver
    $approver_email = "";
    $approver_name  = "";

    if ($role === "Shifts Operations Manager" || $role === "SOM") {
        // SOM → send to SOM Approver
        $stmt = $conn->prepare("SELECT Email, CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as full_name 
                                FROM Employees 
                                WHERE role = 'SOM Approver' 
                                LIMIT 1");
        $stmt->execute();
        $stmt->bind_result($approver_email, $approver_name);
        $stmt->fetch();
        $stmt->close();
    } else {
        if (!empty($som_email)) {
            // Use som_email directly
            $approver_email = $som_email;

            // Get approver name using som_email
            $stmt = $conn->prepare("SELECT CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as full_name 
                                    FROM Employees 
                                    WHERE Email = ?");
            $stmt->bind_param("s", $approver_email);
            $stmt->execute();
            $stmt->bind_result($approver_name);
            $stmt->fetch();
            $stmt->close();
        } else {
            // Fallback: use SOM field name if som_email is missing
            $stmt = $conn->prepare("SELECT Email, CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) as full_name 
                                    FROM Employees 
                                    WHERE CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) = ?");
            $stmt->bind_param("s", $som_name);
            $stmt->execute();
            $stmt->bind_result($approver_email, $approver_name);
            $stmt->fetch();
            $stmt->close();
        }
    }

    if (empty($approver_email)) {
        die("Error: Approver email not found.");
    }

    // Insert the request
    $stmt = $conn->prepare("INSERT INTO fts_requests (employeeID, employee_name, fts_date, fts_time, fts_type, status, approver)
                            VALUES (?, ?, ?, ?, ?, 'Pending', ?)");
    $stmt->bind_param("ssssss", $employee_id, $employee_name, $fts_date, $fts_time, $fts_type, $approver_name);
    $stmt->execute();
    $stmt->close();

    // Send professional email notification (wrapped in try-catch)
    try {
        sendFTSEmailToApprover($conn, $employee_id, $employee_name, $role, $fts_date, $fts_time, $fts_type, $approver_email, $approver_name);
    } catch (Exception $e) {
        error_log("FTS Email exception: " . $e->getMessage());
    }

    echo "<script>alert('FTS Request Submitted & Email Sent!'); window.location.href='submit_fts.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File FTS Request</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1); width: 400px; margin: 30px auto; text-align: center; }
        .container.full-width { width: calc(100% - 40px); max-width: 1200px; margin: 30px auto; padding: 30px; }
        label { font-weight: bold; display: block; margin: 10px 0 5px; text-align: left; }
        select, input { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .time-container { display: flex; align-items: center; justify-content: center; }
        .time-container select { width: auto; flex: none; }
        .time-container span { margin: 0 5px; font-size: 1.2em; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 10px; text-align: center; }
    </style>
</head>
<body>

    <div class="header">
        Failure to Swipe Request Form
        <div class="logout-btn">
            <a href="dashboard.php"><button>Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <!-- FTS Form -->
    <div class="container">
        <form action="submit_fts.php" method="POST">
            <label for="fts_date">FTS Date:</label>
            <input type="date" name="fts_date" required>

            <label for="fts_time">FTS Time:</label>
            <div class="time-container">
                <select name="fts_hour" required>
                    <option value="" disabled selected>HH</option>
                    <?php for ($i = 0; $i < 24; $i++): ?>
                        <option value="<?= str_pad($i, 2, '0', STR_PAD_LEFT); ?>">
                            <?= str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <span>:</span>
                <select name="fts_minute" required>
                    <option value="" disabled selected>MM</option>
                    <?php for ($i = 0; $i < 60; $i++): ?>
                        <option value="<?= str_pad($i, 2, '0', STR_PAD_LEFT); ?>">
                            <?= str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <label for="fts_type">FTS Type:</label>
            <select name="fts_type" required>
                <option value="IN">IN</option>
                <option value="OUT">OUT</option>
            </select>

            <button type="submit">File FTS</button>
        </form>
    </div>

    <!-- FTS Status Table -->
    <div class="container full-width">
        <h3>Your FTS Requests</h3>
        <table class="status-table">
            <tr>
                <th>FTS Date</th>
                <th>FTS Time</th>
                <th>FTS Type</th>
                <th>Status</th>
                <th>Approved At</th>
            </tr>
            <?php
            $stmt = $conn->prepare("SELECT fts_date, fts_time, fts_type, status, approved_at 
                                    FROM fts_requests 
                                    WHERE employeeID = ? 
                                    ORDER BY created_at DESC");
            $stmt->bind_param("s", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['fts_date']); ?></td>
                    <td><?= htmlspecialchars($row['fts_time']); ?></td>
                    <td><?= htmlspecialchars($row['fts_type']); ?></td>
                    <td><?= htmlspecialchars($row['status']); ?></td>
                    <td><?= $row['approved_at'] ? htmlspecialchars($row['approved_at']) : 'Pending'; ?></td>
                </tr>
            <?php
                endwhile;
            else:
            ?>
                <tr>
                    <td colspan="5">No FTS requests found.</td>
                </tr>
            <?php
            endif;
            $stmt->close();
            $conn->close();
            ?>
        </table>
    </div>

</body>
</html>