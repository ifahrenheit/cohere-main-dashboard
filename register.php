<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true);
require 'db_connection.php';
require 'vendor/autoload.php'; // Ensure PHPMailer is loaded

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error_message = '';
$success_message = '';

// Ensure correct charset for MySQL
$conn->set_charset("utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Debug log
    error_log("Submitted email: '$email'");

    if (empty($email) || empty($password)) {
        $error_message = "Email and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT EmployeeID, IsVerified FROM Employees WHERE Email = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $error_message = "Server error. Please try again later.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if ($row['IsVerified'] == 1) {
                    $error_message = "This email is already registered and verified.";
                } else {
                    $token = bin2hex(random_bytes(32));
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                    $update = $conn->prepare("UPDATE Employees SET Password = ?, VerificationToken = ?, IsVerified = 0 WHERE Email = ?");
                    $update->bind_param("sss", $hashedPassword, $token, $email);
                    $update->execute();
                    $update->close();

                    $verificationLink = "http://dashboard.cohere.ph/verify.php?token=$token";
                    $subject = "Verify your Cohere Account";
                    $email_body = "<p>Hello,</p><p>Please verify your email by clicking the link below:</p><p><a href='$verificationLink'>$verificationLink</a></p>";

                    $mail = new PHPMailer(true);
                    try {
                        $mail->SMTPDebug = 3;
                        $mail->Debugoutput = function($str, $level) {
                            error_log("SMTP Debug [$level]: $str", 3, __DIR__ . '/phpmailer_debug.log');
                        };
                        $mail->Timeout = 10;

                        $mail->isSMTP();
                        $mail->Host = 'in-v3.mailjet.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = '4e7ce29521efe1831e569a6f2ce278c2';
                        $mail->Password = '868d337b1bffc679f64ba24abb0981d3';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 2525;

                        $mail->setFrom('send_email@cohere.ph', 'Dashboard System');
                        $mail->addAddress($email);

                        $mail->Subject = $subject;
                        $mail->isHTML(true);
                        $mail->Body = $email_body;

                        $mail->send();
                        $success_message = "Registration successful! Please check your email to verify your account.";
                    } catch (Exception $e) {
                        $error_message = "Error sending verification email: " . $mail->ErrorInfo;
                    }
                }
            } else {
                // Debug: check all email entries
                $debug_result = $conn->query("SELECT Email, LENGTH(Email) FROM Employees WHERE Email LIKE '%juvy.angot%'");
                while ($debug_row = $debug_result->fetch_assoc()) {
                    error_log("Found email: '" . $debug_row['Email'] . "' (length: " . $debug_row['LENGTH(Email)'] . ")");
                }

                $error_message = "This email is not registered.";
            }
            $stmt->close();
        }
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Cohere</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .form-container { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); width: 400px; text-align: center; }
        .input-group { margin-bottom: 1rem; text-align: left; }
        .input-group label { display: block; margin-bottom: 0.5rem; }
        .input-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .submit-btn { width: 100%; padding: 10px; background: #004AAD; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .message { margin-top: 1rem; }
        .message.success { color: green; }
        .message.error { color: red; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Register</h2>
        <?php if ($error_message): ?>
            <div class="message error"><?= $error_message ?></div>
        <?php elseif ($success_message): ?>
            <div class="message success"><?= $success_message ?></div>
        <?php endif; ?>
        <form method="post" action="">
            <div class="input-group">
                <label for="email">Company Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="input-group">
                <label for="password">Create Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="submit-btn">Register</button>
        </form>
        <p style="margin-top:1rem; font-size:0.9rem;">Already registered? <a href="login.php">Login</a></p>
    </div>
</body>
</html>
