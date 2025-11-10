<?php
session_start();
session_regenerate_id(true);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db_connection.php'; // Ensure this sets $conn

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error_message = "Email and password are required.";
    } else {
        // Check user from DB
        $stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName, role, Password, IsVerified FROM Employees WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (!$row['IsVerified']) {
                $error_message = "Please verify your email before logging in.";
            } elseif (password_verify($password, $row['Password'])) {
                $_SESSION['employeeID'] = $row['EmployeeID'];
                $_SESSION['user_email'] = $email;
                $_SESSION['full_name'] = $row['FirstName'] . ' ' . $row['LastName'];
                $_SESSION['role'] = $row['role'] ?? 'Employee';
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error_message = "Invalid password.";
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
    <title>Login | Cohere</title>
    <style>
         body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: linear-gradient(to right, #004AAD, #FFA500);
        }
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }

        .logo img {
            width: 150px;
            margin-bottom: 1rem;
        }

        h2 {
            color: #004AAD;
            margin-bottom: 1rem;
        }

        .input-group {
            margin-bottom: 1rem;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }

        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
        }

        .error-message {
            color: red;
            margin-bottom: 1rem;
        }

        .login-btn {
            width: 100%;
            padding: 10px;
            background: #004AAD;
            color: white;
            font-size: 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .login-btn:hover {
            background: #003080;
        }
    </style>
</head>
<body>
    <div class="login-container">
    <div class="logo">
            <img src="https://cohere.ph/img/cohere-logo.jpg" alt="Cohere Logo">
        </div>
    
    <h2>Login to Cohere</h2>
           <?php if (!empty($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="input-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>

            <div class="input-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="login-btn">Login</button>
        </form>

        <p style="margin-top: 1rem;">
            Don't have an account? <a href="register.php">Register</a>
        </p>
        <p style="font-size: 0.9rem;">
            Forgot your password? <a href="password_reset.php">Reset it</a>
        </p>
    </div>
</body>
</html>
