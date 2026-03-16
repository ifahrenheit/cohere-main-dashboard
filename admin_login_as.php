<?php
// ✅ Cross-subdomain session support
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

require 'db_connection.php';

// ✅ Allow only Admins to access
if ($_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    die("Forbidden");
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_id'])) {
    $employee_id = $_POST['employee_id'];

    file_put_contents('/tmp/debug.txt', "Searching for: [$email] Length: " . strlen($email) . "\n", FILE_APPEND);

    
    // DEBUG: Show what we received
    echo "<pre style='background: yellow; padding: 10px; margin: 20px;'>";
    echo "DEBUG INFO:\n";
    echo "Posted email: " . htmlspecialchars($email) . "\n";
    echo "Email length: " . strlen($email) . "\n";
    echo "Email hex: " . bin2hex($email) . "\n";
    echo "</pre>";

    // ✅ Fetch the user to impersonate
    $stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName, Email, role, is_qa FROM Employees WHERE EmployeeID = ?");
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // DEBUG: Show query result
    echo "<pre style='background: lightblue; padding: 10px; margin: 20px;'>";
    echo "QUERY RESULT:\n";
    if ($user) {
        echo "Found user: " . print_r($user, true);
    } else {
        echo "NO USER FOUND\n";
        
        // Let's check what's actually in the database
        $debug = $conn->query("SELECT EmployeeID, Email, FirstName, LastName FROM Employees WHERE Email LIKE '%jose%rizal%' OR Email LIKE '%260113%'");
        echo "\nSearching for similar emails:\n";
        while ($row = $debug->fetch_assoc()) {
            echo "  - " . $row['Email'] . " (ID: " . $row['EmployeeID'] . ")\n";
        }
    }
    echo "</pre>";
    
    // Comment out the rest temporarily to see debug info
    // die("DEBUG - stopping here");

    if (!$user) {
        $message = "User not found.";
    } else {
        // ✅ Store the original Admin session (only if not already impersonating)
        if (!isset($_SESSION['original_admin'])) {
            $_SESSION['original_admin'] = [
                'employeeID' => $_SESSION['employeeID'],
                'employee_id' => $_SESSION['employee_id'],
                'employee_name' => $_SESSION['employee_name'],
                'user_email' => $_SESSION['user_email'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ];
        }

        // ✅ Overwrite session with impersonated user
        $_SESSION['employeeID'] = $user['EmployeeID'];
        $_SESSION['employee_id'] = $user['EmployeeID'];
        $_SESSION['employee_name'] = $user['FirstName'] . ' ' . $user['LastName'];
        $_SESSION['user_email'] = $user['Email'];
        $_SESSION['full_name'] = $user['FirstName'] . ' ' . $user['LastName'];
        $_SESSION['role'] = $user['role'] ?? 'Employee';
        $_SESSION['is_qa'] = (bool)$user['is_qa'];   // 👈 add this

        // ✅ Check user's overhead group AND account type from gsheet_employees
        $_SESSION['user_group'] = null;
        $_SESSION['account_type'] = null;
        if ($stmt_group = $conn->prepare("SELECT group_name, account FROM gsheet_employees WHERE email = ? AND status = 'Active'")) {
            $stmt_group->bind_param("s", $user['Email']);
            $stmt_group->execute();
            $group_result = $stmt_group->get_result();
            if ($group_row = $group_result->fetch_assoc()) {
                $_SESSION['user_group'] = $group_row['group_name'];
                $_SESSION['account_type'] = $group_row['account'];  // ← ADD THIS LINE
            }
            $stmt_group->close();
        }

        // ✅ Load supervisor mapping (optional)
        $_SESSION['is_supervisor'] = false;
        $_SESSION['supervised_agents'] = [];

        $q = $conn->prepare("SELECT agent_email FROM supervisor_mapping WHERE supervisor_email = ?");
        $q->bind_param("s", $user['Email']);
        $q->execute();
        $res = $q->get_result();
        while ($row = $res->fetch_assoc()) {
            $_SESSION['supervised_agents'][] = $row['agent_email'];
        }
        if (!empty($_SESSION['supervised_agents'])) {
            $_SESSION['is_supervisor'] = true;
        }

        $q->close();

        header("Location: dashboard.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login As User (Admin Only)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>
<body class="bg-gray-100 p-8 font-sans">
  <div class="max-w-xl mx-auto bg-white p-6 rounded shadow">
    <h2 class="text-2xl font-bold mb-4 text-gray-700">🔐 Admin: Login As</h2>

    <?php if (!empty($message)): ?>
      <p class="text-red-600 mb-4"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <label for="email" class="block text-sm font-semibold">Select a user to impersonate:</label>
      <select name="employee_id" id="employee_id" required class="w-full border p-2 rounded">
  <?php
  $users = $conn->query("SELECT EmployeeID, Email, FirstName, LastName, role FROM Employees WHERE IsVerified = 1 ORDER BY FirstName, LastName");
  while ($u = $users->fetch_assoc()):
  ?>
    <option value="<?= htmlspecialchars($u['EmployeeID']) ?>">
            <?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) ?> (<?= $u['role'] ?>)
          </option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
        Impersonate User
      </button>
    </form>

    <?php if (isset($_SESSION['original_admin'])): ?>
      <div class="mt-6 text-sm text-center">
        <p class="text-gray-600">
          ✅ Currently impersonating <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong><br>
          <a href="switch_back.php" class="text-green-700 underline">Return to Admin (<?= htmlspecialchars($_SESSION['original_admin']['user_email']) ?>)</a>
        </p>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
