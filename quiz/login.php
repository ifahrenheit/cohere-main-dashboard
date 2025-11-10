<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include '../db_connection.php';
session_start();

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

$response = ['success' => false, 'message' => 'Invalid credentials'];

if (!empty($email) && !empty($password)) {
    $stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName, role, Email, Password FROM Employees WHERE Email = ? AND IsVerified = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['Password'])) {
            // Set session values
            $_SESSION['user_email'] = $user['Email'];
            $_SESSION['employeeID'] = $user['EmployeeID'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['FirstName'] . ' ' . $user['LastName'];
            $_SESSION['is_qa']       = (bool)$user['is_qa'];   // ✅ add this line

            // Success response
            $response = [
                'success' => true,
                'message' => 'Login successful',
                'employeeID' => $user['EmployeeID'],
                'email' => $user['Email'],
                'role' => $user['role'],
                'name' => $user['FirstName'] . ' ' . $user['LastName'],
                'is_qa'       => (bool)$user['is_qa']          // ✅ include in response (optional)
            ];
        } else {
            $response['message'] = 'Incorrect password.';
        }
    } else {
        $response['message'] = 'Email not found or account not verified.';
    }

    $stmt->close();
} else {
    $response['message'] = 'Email and password are required.';
}

echo json_encode($response);
