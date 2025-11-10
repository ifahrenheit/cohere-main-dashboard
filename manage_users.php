<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


include 'db_connection.php';
ini_set('session.cookie_domain', '.cohere.ph'); // ✅ Note the leading dot!
ini_set('session.cookie_samesite', 'None');     // ✅ Required for cross-origin cookies
ini_set('session.cookie_secure', '1');          // ✅ Must be HTTPS
session_start();

// Optional: restrict to Admins only
if ($_SESSION['role'] !== 'Admin') {
    die("Access denied.");
}

// Fetch all users
$users = [];
$result = $conn->query("SELECT * FROM userdata WHERE email IS NOT NULL");

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Fetch supervisor mappings
$supervisors = [];
$result = $conn->query("SELECT agent_email, supervisor_email FROM supervisor_mapping");

while ($row = $result->fetch_assoc()) {
    $supervisors[$row['agent_email']] = $row['supervisor_email'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: 6px; }
    </style>
</head>
<body>
    <h2>User List</h2>
    <table>
        <thead>
            <tr>
                <th>Company ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Supervisor</th>
                <th>Action</th>
            </tr>
        </thead>
<tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['companyid'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['role'] ?? '—') ?></td>
            <td><?= htmlspecialchars($supervisors[$u['email']] ?? '—') ?></td>
            <td>
                <form action="update_supervisor.php" method="POST">
                    <input type="hidden" name="agent_email" value="<?= htmlspecialchars($u['email']) ?>">

                    <?php
                    // Sort a copy of the users list for the dropdown
                    $sortedUsers = $users;
                    usort($sortedUsers, function ($a, $b) {
                        $nameA = strtolower($a['fname'] . ' ' . $a['lname']);
                        $nameB = strtolower($b['fname'] . ' ' . $b['lname']);
                        return $nameA <=> $nameB;
                    });
                    ?>

                    <select name="supervisor_email" required>
                        <option value="">— Select Supervisor —</option>
                        <?php foreach ($sortedUsers as $sup): ?>
                            <?php if (empty($sup['email'])) continue; ?>
                            <option value="<?= htmlspecialchars($sup['email']) ?>">
                                <?= htmlspecialchars($sup['fname'] . ' ' . $sup['lname']) ?> (<?= htmlspecialchars($sup['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Assign</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>

    </table>
</body>
</html>
