<?php
// quiz/edit.php
session_start();

// ✅ Restrict access by role
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];
if (
    !isset($_SESSION['user_email']) ||
    (
        !in_array($_SESSION['role'], $allowed_roles) &&
        empty($_SESSION['is_qa'])
    )
) {
    header("Location: /dashboard.php");
    exit;
}

// ✅ Include shared DB connection outside quiz/
require_once __DIR__ . '/../db_connection.php';

// ✅ Get question ID safely
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ✅ Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['question']);
    $a = trim($_POST['choice_a']);
    $b = trim($_POST['choice_b']);
    $c = trim($_POST['choice_c']);
    $d = trim($_POST['choice_d']);
    $answer = strtoupper(trim($_POST['correct_answer']));

    $stmt = $conn->prepare("
        UPDATE quiz_questions 
        SET question = ?, choice_a = ?, choice_b = ?, choice_c = ?, choice_d = ?, correct_answer = ? 
        WHERE id = ?
    ");
    $stmt->bind_param("ssssssi", $question, $a, $b, $c, $d, $answer, $id);
    $stmt->execute();

    header("Location: questions.php");
    exit;
}

// ✅ Fetch existing question
$stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    echo "<div class='alert alert-danger'>Question not found.</div>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Question</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <h2 class="mb-4">Edit Question</h2>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">Question</label>
            <textarea name="question" class="form-control" required><?= htmlspecialchars($row['question']) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Choice A</label>
            <input type="text" name="choice_a" class="form-control" value="<?= htmlspecialchars($row['choice_a']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Choice B</label>
            <input type="text" name="choice_b" class="form-control" value="<?= htmlspecialchars($row['choice_b']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Choice C</label>
            <input type="text" name="choice_c" class="form-control" value="<?= htmlspecialchars($row['choice_c']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Choice D</label>
            <input type="text" name="choice_d" class="form-control" value="<?= htmlspecialchars($row['choice_d']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Correct Answer (A/B/C/D)</label>
            <input type="text" name="correct_answer" class="form-control" maxlength="1" value="<?= htmlspecialchars($row['correct_answer']) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary">Update</button>
        <a href="questions.php" class="btn btn-secondary">Cancel</a>
    </form>
</body>
</html>
