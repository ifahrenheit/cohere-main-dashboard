<?php
// quiz/questions.php
session_start();

// Roles with access
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];

// ✅ Check role OR QA flag
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

// DB connection
$conn = new mysqli('localhost', 'root', 'Rootpass123!@#', 'central_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle activation toggle
if (isset($_GET['toggle_id'])) {
    $id = (int) $_GET['toggle_id'];
    $conn->query("UPDATE quiz_questions SET is_active = IF(is_active=1,0,1) WHERE id = $id");
    header("Location: questions.php");
    exit;
}

$result = $conn->query("SELECT * FROM quiz_questions ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quiz Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        h2 {
            font-weight: 600;
        }
        .table thead th {
            background-color: #212529;
            color: #fff;
            text-align: center;
        }
        .table td {
            vertical-align: middle;
        }
        .status-active {
            color: #28a745;
            font-weight: 600;
        }
        .status-inactive {
            color: #dc3545;
            font-weight: 600;
        }
        .btn-group .btn {
            border-radius: 8px !important;
            font-weight: 500;
            padding: 4px 10px;
        }
        .btn-warning {
            background-color: #ffc107;
            border: none;
            color: #000;
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        .btn-outline-danger, .btn-outline-success {
            border-width: 1.5px;
        }
        .btn-outline-danger:hover, .btn-outline-success:hover {
            color: #fff;
        }
    </style>
</head>
<body class="p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">📋 Quiz Questions</h2>
        <a href="/dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
        </a>
    </div>

    <a href="add.php" class="btn btn-success mb-3">
        <i class="bi bi-plus-circle"></i> Add New Question
    </a>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle shadow-sm">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Question</th>
                    <th>Choices</th>
                    <th>Answer</th>
                    <th>Status</th>
                    <th style="width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="text-center"><?= $row['id'] ?></td>
                        <td><?= nl2br(htmlspecialchars($row['question'])) ?></td>
                        <td>
                            <div>A. <?= htmlspecialchars($row['choice_a']) ?></div>
                            <div>B. <?= htmlspecialchars($row['choice_b']) ?></div>
                            <div>C. <?= htmlspecialchars($row['choice_c']) ?></div>
                            <div>D. <?= htmlspecialchars($row['choice_d']) ?></div>
                        </td>
                        <td class="text-center"><strong><?= htmlspecialchars($row['correct_answer']) ?></strong></td>
                        <td class="text-center">
                            <?php if ($row['is_active']): ?>
                                <span class="status-active">Active</span>
                            <?php else: ?>
                                <span class="status-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group" role="group">
                                <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm d-flex align-items-center gap-1">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </a>
                                <?php if ($row['is_active']): ?>
                                    <a href="questions.php?toggle_id=<?= $row['id'] ?>"
                                       class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1"
                                       onclick="return confirm('Are you sure you want to deactivate this question?');">
                                        <i class="bi bi-x-circle"></i> Deactivate
                                    </a>
                                <?php else: ?>
                                    <a href="questions.php?toggle_id=<?= $row['id'] ?>"
                                       class="btn btn-outline-success btn-sm d-flex align-items-center gap-1"
                                       onclick="return confirm('Activate this question?');">
                                        <i class="bi bi-check-circle"></i> Activate
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
