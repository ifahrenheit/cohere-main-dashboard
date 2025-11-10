<?php
require 'db_connection.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $message = "Invalid or missing verification token.";
} else {
    // Look for the token
    $stmt = $conn->prepare("SELECT Email FROM Employees WHERE VerificationToken = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Mark as verified
        $update = $conn->prepare("UPDATE Employees SET IsVerified = 1, VerificationToken = NULL WHERE VerificationToken = ?");
        $update->bind_param("s", $token);
        $update->execute();

        $message = "Your account has been verified successfully! You may now <a href='login.php'>log in</a>.";
    } else {
        $message = "Invalid or expired verification token.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .message-box { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); width: 400px; text-align: center; }
        .message-box a { color: #004AAD; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="message-box">
        <h2>Email Verification</h2>
        <p><?= $message ?></p>
    </div>
</body>
</html>

