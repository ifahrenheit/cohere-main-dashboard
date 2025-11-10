<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // PHPMailer installed via Composer
include 'db_connection.php';

if (!isset($_SESSION['employeeID']) || empty($_SESSION['employeeID'])) {
    die("Session Error: Employee ID is missing!");
}

$employee_id = $_SESSION['employeeID'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fts_date   = trim($_POST['fts_date'] ?? '');
    $fts_hour   = trim($_POST['fts_hour'] ?? '');
    $fts_minute = trim($_POST['fts_minute'] ?? '');
    $fts_type   = trim($_POST['fts_type'] ?? '');

    if (empty($fts_date) || empty($fts_hour) || empty($fts_minute) || empty($fts_type)) {
        die("Error: Missing required fields.");
    }

    $fts_time = sprintf("%02d:%02d:00", $fts_hour, $fts_minute);

    // ✅ Get employee details including som_email
    $stmt = $conn->prepare("SELECT firstname, lastname, role, SOM, som_email 
                            FROM Employees 
                            WHERE employeeID = ?");
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $stmt->bind_result($firstname, $lastname, $role, $som_name, $som_email);
    $stmt->fetch();
    $stmt->close();

    if (empty($firstname) || empty($lastname)) {
        die("Error: Employee details not found.");
    }

    $employee_name = "$firstname $lastname";

    // ✅ Determine approver
    $approver_email = "";
    $approver_name  = "";

    if ($role === "Shifts Operations Manager") {
        // SOM → send to SOM Approver
        $stmt = $conn->prepare("SELECT email, CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) 
                                AS full_name 
                                FROM Employees 
                                WHERE role = 'SOM Approver' 
                                LIMIT 1");
        $stmt->execute();
        $stmt->bind_result($approver_email, $approver_name);
        $stmt->fetch();
        $stmt->close();
    } else {
        if (!empty($som_email)) {
            // ✅ Use som_email directly
            $approver_email = $som_email;

            // Get approver name using som_email
            $stmt = $conn->prepare("SELECT CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) 
                                    AS full_name 
                                    FROM Employees 
                                    WHERE Email = ?");
            $stmt->bind_param("s", $approver_email);
            $stmt->execute();
            $stmt->bind_result($approver_name);
            $stmt->fetch();
            $stmt->close();
        } else {
            // Fallback: use SOM field name if som_email is missing
            $stmt = $conn->prepare("SELECT email, CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) 
                                    AS full_name 
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

    // ✅ Insert the request
    $stmt = $conn->prepare("INSERT INTO fts_requests (employeeID, employee_name, fts_date, fts_time, fts_type, status, approver)
                            VALUES (?, ?, ?, ?, ?, 'Pending', ?)");
    $stmt->bind_param("ssssss", $employee_id, $employee_name, $fts_date, $fts_time, $fts_type, $approver_name);
    $stmt->execute();
    $stmt->close();

    // ✅ Send email notification
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'cohere.ph';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'send_email@cohere.ph';
        $mail->Password   = 'Cohere123456';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 2525;

        $mail->setFrom('send_email@cohere.ph', 'Cohere Notification');
        $mail->addAddress($approver_email);
        $mail->isHTML(true);
        $mail->Subject = 'New FTS Request from ' . $employee_name;
        $mail->Body    = "Hello,<br><br>You have a new FTS request to review.<br><br>
                          <strong>Date:</strong> $fts_date<br>
                          <strong>Time:</strong> $fts_time<br>
                          <strong>Type:</strong> $fts_type<br>
                          <strong>From:</strong> $employee_name<br><br>
                          Please log in to approve or reject.";

        $mail->send();
        echo "<script>alert('FTS Request Submitted & Email Sent!'); window.location.href='submit_fts.php';</script>";
        exit();
    } catch (Exception $e) {
        echo "FTS Request Submitted, but email could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
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
