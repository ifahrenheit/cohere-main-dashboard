<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true);
include 'db_connection.php';

$error_message = '';
$success_message = '';
$show_form = true;

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT Email, ResetTokenExpiry FROM Employees WHERE ResetToken = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if ($user['ResetTokenExpiry'] < time()) {
            $error_message = "This password reset link has expired.";
            $show_form = false;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $new_password = $_POST['new_password'] ?? '';

            if (empty($new_password)) {
                $error_message = "Password is required.";
            } else {
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

                $update = $conn->prepare("UPDATE Employees SET Password = ?, ResetToken = NULL, ResetTokenExpiry = NULL WHERE ResetToken = ?");
                $update->bind_param("ss", $hashedPassword, $token);
                if ($update->execute()) {
                    $success_message = "Your password has been successfully reset.";
                    $show_form = false;
                } else {
                    $error_message = "Something went wrong. Please try again.";
                }
                $update->close();
            }
        }
    } else {
        $error_message = "Invalid reset link.";
        $show_form = false;
    }
    $stmt->close();
} else {
    $error_message = "Invalid or missing token.";
    $show_form = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | Cohere</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .form-container { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); width: 400px; text-align: center; }
        .input-group { margin-bottom: 1rem; text-align: left; }
        .input-group label { display: block; margin-bottom: 0.5rem; }
        .input-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .submit-btn { width: 100%; padding: 10px; background: #004AAD; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .message { margin-top: 1rem; }
        .message.error { color: red; }
        .message.success { color: green; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Reset Your Password</h2>

        <?php if (!empty($error_message)): ?>
            <div class="message error"><?= htmlspecialchars($error_message) ?></div>
            <?php elseif (!empty($success_message)): ?>
    <div class="message success">
        <?= htmlspecialchars($success_message) ?><br><br>
        <a href="login.php" style="color: #004AAD; text-decoration: underline;">Return to Login</a>
    </div>
<?php endif; ?>

        <?php if ($show_form): ?>
            <form method="post">
                <div class="input-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <button type="submit" class="submit-btn">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
