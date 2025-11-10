<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ✅ Configure session cookie for cross-subdomain use
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

// ✅ Detect API requests
$is_api = false;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';

$allowed_origins = [
    'http://localhost:3000',
    'http://157.245.192.63:3000',
    'https://vouchers.cohere.ph'
];

if (
    stripos($accept, 'application/json') !== false ||
    in_array($origin, $allowed_origins)
) {
    $is_api = true;

    // ✅ Send CORS headers
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Content-Type: application/json");

    // ✅ Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

require 'db_connection.php'; // Ensure $conn is initialized

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ Handle JSON body for API
    if ($is_api && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        $_POST['email'] = $input['email'] ?? '';
        $_POST['password'] = $input['password'] ?? '';
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error_message = 'Email and password are required.';
    } else {
        $stmt = $conn->prepare("
            SELECT EmployeeID, FirstName, LastName, role, Password, IsVerified 
            FROM Employees 
            WHERE LOWER(Email) = ?
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (!$user['IsVerified']) {
                $error_message = "Please verify your email before logging in.";
            } elseif (password_verify($password, $user['Password'])) {
                // ✅ Set session variables
                session_regenerate_id(true);   

                $_SESSION['employeeID'] = $user['EmployeeID'];
                $_SESSION['employee_id'] = $user['EmployeeID']; // lowercase alias
                $_SESSION['employee_name'] = $user['FirstName'] . ' ' . $user['LastName'];
                $_SESSION['user_email'] = $email;
                $_SESSION['full_name'] = $user['FirstName'] . ' ' . $user['LastName'];
                $_SESSION['role'] = $user['role'] ?? 'Employee';

                // ✅ Load supervised agents if user is a supervisor
                $agents = [];
                $stmt2 = $conn->prepare("SELECT agent_email FROM supervisor_mapping WHERE supervisor_email = ?");
                $stmt2->bind_param("s", $email);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                while ($row = $result2->fetch_assoc()) {
                    $agents[] = $row['agent_email'];
                }
                $stmt2->close();

                $_SESSION['supervised_agents'] = $agents;
                $_SESSION['is_supervisor'] = !empty($agents);

                // ✅ Check if QA from Employees table
                $isQa = false;
                if ($stmt3 = $conn->prepare("SELECT is_qa FROM Employees WHERE LOWER(Email) = ?")) {
                    $stmt3->bind_param("s", $email);
                    $stmt3->execute();
                    $stmt3->bind_result($isQaValue);
                    if ($stmt3->fetch()) {
                        $isQa = (bool)$isQaValue;
                    }
                    $stmt3->close();
                }
                $_SESSION['is_qa'] = $isQa;


                // ✅ Set secure cross-subdomain cookie
                setcookie(session_name(), session_id(), [
                    'domain' => '.cohere.ph',
                    'path' => '/',
                    'secure' => true,
                    'samesite' => 'None'
                ]);

                if ($is_api) {
                    echo json_encode(["message" => "Login successful"]);
                    exit;
                } else {
                    header("Location: dashboard.php");
                    exit;
                }
            } else {
                $error_message = "Invalid password.";
            }
        } else {
            $error_message = "No user found with that email.";
        }

        $stmt->close();
    }

    if ($is_api) {
        http_response_code(401);
        echo json_encode(["error" => $error_message]);
        exit;
    }
}
?>


<?php if (!$is_api): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Cohere</title>
    <style>
        body {
            font-family: sans-serif;
            background: linear-gradient(to right, #004AAD, #FFA500);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            width: 360px;
        }
        .login-container img {
            width: 150px;
            margin: 0 auto 1rem;
            display: block;
        }
        h2 { text-align: center; color: #004AAD; }
        .input-group { margin-bottom: 1rem; }
        label { display: block; font-weight: bold; }
        input {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .error-message {
            color: red;
            margin-bottom: 1rem;
            text-align: center;
        }
        button {
            width: 100%;
            padding: 10px;
            background: #004AAD;
            color: white;
            font-weight: bold;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover { background: #003080; }
        .links {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="login-container">
    <img src="https://cohere.ph/img/cohere-logo.jpg" alt="Cohere Logo">
    <h2>Login to Cohere</h2>
    <?php if ($error_message): ?>
        <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <form method="post" action="">
        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit">Login</button>
    </form>
    <div class="links">
        <p><a href="register.php">Register</a> | <a href="password_reset.php">Forgot Password?</a></p>
    </div>
</div>
</body>
</html>
<?php endif; ?>
