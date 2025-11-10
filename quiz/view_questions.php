<?php
// quiz/view_questions.php
session_start();

// ✅ Ensure user is logged in
if (!isset($_SESSION['user_email'])) {
    header("Location: /dashboard.php");
    exit;
}

// ✅ Database connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ✅ Fetch user info from DB
$email = $_SESSION['user_email'];
$stmt = $conn->prepare("SELECT role, is_qa, is_supervisor FROM userdata WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$role = $user['role'] ?? '';
$is_qa = $user['is_qa'] ?? 0;
$is_supervisor = $user['is_supervisor'] ?? 0;

// ✅ Allow overheads: Admin, Manager, Director, QA, Supervisors
$allowed_roles = ['Admin', 'Manager', 'Director'];
if (!in_array($role, $allowed_roles) && !$is_qa && !$is_supervisor) {
    header("Location: /dashboard.php");
    exit;
}

// ✅ Fetch quiz questions
$result = $conn->query("SELECT id, question, choice_a, choice_b, choice_c, choice_d, correct_answer, is_active 
                        FROM quiz_questions 
                        ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Questions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.answer-text { display: none; }</style>
</head>
<body class="bg-light p-4">
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">🧠 Quiz Questions (Read-Only)</h2>
        <a href="/dashboard.php" class="btn btn-secondary">⬅ Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="mb-4 p-3 border rounded <?= $row['is_active'] ? '' : 'bg-light text-muted' ?>">
                        <h5 class="fw-bold mb-3"><?= htmlspecialchars($row['question']) ?></h5>
                        <ul class="list-unstyled mb-2">
                            <li>🅐 <?= htmlspecialchars($row['choice_a']) ?></li>
                            <li>🅑 <?= htmlspecialchars($row['choice_b']) ?></li>
                            <?php if (!empty($row['choice_c'])): ?>
                                <li>🅒 <?= htmlspecialchars($row['choice_c']) ?></li>
                            <?php endif; ?>
                            <?php if (!empty($row['choice_d'])): ?>
                                <li>🅓 <?= htmlspecialchars($row['choice_d']) ?></li>
                            <?php endif; ?>
                        </ul>
                        <p class="mb-0">
                            ✅ <strong>Correct Answer:</strong>
                            <span class="answer-text text-success" id="answer-<?= $row['id'] ?>">
                                <?= htmlspecialchars($row['correct_answer']) ?>
                            </span>
                            <button class="btn btn-sm btn-outline-primary ms-2 toggle-answer" 
                                    data-target="answer-<?= $row['id'] ?>">Show</button>
                        </p>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-center text-muted m-0">No questions available.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-answer').forEach(btn => {
    btn.addEventListener('click', function() {
        const target = document.getElementById(this.dataset.target);
        const visible = target.style.display === 'inline';
        target.style.display = visible ? 'none' : 'inline';
        this.textContent = visible ? 'Show' : 'Hide';
    });
});
</script>
</body>
</html>
