<?php
include 'db_connection.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);

        $stmt = $conn->prepare("UPDATE Employees SET Password = ?, IsVerified = 1, VerificationToken = NULL WHERE VerificationToken = ?");
        $stmt->bind_param("ss", $password, $token);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo "Password set successfully. You can now <a href='login.php'>login</a>.";
        } else {
            echo "Invalid or expired token.";
        }
    } else {
        // Show password form
        echo '
        <form method="post">
            <label>Set your password:</label><br>
            <input type="password" name="password" required>
            <button type="submit">Save Password</button>
        </form>';
    }
} else {
    echo "No token provided.";
}
?>

