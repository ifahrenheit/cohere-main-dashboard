<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
session_regenerate_id(true);
include 'db_connection.php'; // Ensure this sets $conn

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    if (empty($email)) {
        $error_message = "Email is required.";
    } else {
        // Check if the email exists in the database
        $stmt = $conn->prepare("SELECT EmployeeID, FirstName FROM Employees WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Generate a reset token
            $token = bin2hex(random_bytes(32));
            $expires = date("U") + 3600; // Token expiry (1 hour)

            // Store token and expiry in the database
            $update = $conn->prepare("UPDATE Employees SET ResetToken = ?, ResetTokenExpiry = ? WHERE Email = ?");
            $update->bind_param("sis", $token, $expires, $email);
            $update->execute();

            // Send password reset email
            $resetLink = "http://dashboard.cohere.ph/reset_form.php?token=$token";
            $subject = "Password Reset Request";
            $email_body = "<p>Hello,</p><p>Please reset your password by clicking the link below:</p><p><a href='$resetLink'>$resetLink</a></p>";

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'cohere.ph';
                $mail->SMTPAuth = true;
                $mail->Username = 'send_email@cohere.ph';
                $mail->Password = 'Cohere123456';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 2525;

                $mail->setFrom('send_email@cohere.ph', 'Dashboard System');
                $mail->addAddress($email);

                $mail->Subject = $subject;
                $mail->isHTML(true);
                $mail->Body = $email_body;

                $mail->send();
                $success_message = "Password reset link has been sent to your email.";
            } catch (Exception $e) {
                $error_message = "Error sending reset email: " . $mail->ErrorInfo;
            }
        } else {
            $error_message = "No user found with that email.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset | Cohere</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }
        .input-group {
            margin-bottom: 1rem;
            text-align: left;
        }
        .input-group label {
            display: block;
            margin-bottom: 0.5rem;
        }
        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .submit-btn {
            width: 100%;
            padding: 10px;
            background: #004AAD;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .error-message {
            color: red;
            margin-bottom: 1rem;
        }
        .success-message {
            color: green;
            margin-bottom: 1rem;
        }
        .message {
            margin-top: 1rem;
        }
        .message.success {
            color: green;
        }
        .message.error {
            color: red;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Reset Your Password</h2>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php elseif (!empty($success_message)): ?>
            <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="input-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>

            <button type="submit" class="submit-btn">Request Password Reset</button>
        </form>

        <p style="margin-top:1rem; font-size:0.9rem;">Remember your password? <a href="login.php">Login</a></p>
    </div>
</body>
</html>
