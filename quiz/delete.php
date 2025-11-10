<?php
// quiz/delete.php
session_start();
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

$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
$id = $_GET['id'] ?? 0;

$conn->query("DELETE FROM quiz_questions WHERE id = " . (int)$id);
header("Location: questions.php");
exit;