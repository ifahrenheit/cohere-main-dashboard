<?php
// quiz/add.php
session_start();

// ✅ Allow Admins, Managers, Directors, SOM Approvers, and QA users
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

// ✅ Database connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ✅ Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['question']);
    $a = trim($_POST['choice_a']);
    $b = trim($_POST['choice_b']);
    $c = trim($_POST['choice_c']);
    $d = trim($_POST['choice_d']);
    $answer = strtoupper(trim($_POST['correct_answer']));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // ✅ Insert into database
    $stmt = $conn->prepare("INSERT INTO quiz_questions 
        (question, choice_a, choice_b, choice_c, choice_d, correct_answer, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $question, $a, $b, $c, $d, $answer, $is_active);
    $stmt->execute();

    header("Location: questions.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Question</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">➕ Add New Question</h2>
            <a href="questions.php" class="btn btn-secondary">⬅ Back</a>
        </div>

        <form method="post" class="card p-4 shadow-sm">
            <div class="mb-3">
                <label class="form-label">Question</label>
                <textarea name="question" class="form-control" rows="3" required></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choice A</label>
                    <input type="text" name="choice_a" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choice B</label>
                    <input type="text" name="choice_b" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choice C</label>
                    <input type="text" name="choice_c" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choice D</label>
                    <input type="text" name="choice_d" class="form-control">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Correct Answer (A/B/C/D)</label>
                <input type="text" name="correct_answer" class="form-control" maxlength="1" required>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                <label class="form-check-label" for="is_active">
                    Mark as Active
                </label>
            </div>

            <button type="submit" class="btn btn-primary">💾 Save Question</button>
            <a href="questions.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</body>
</html>
