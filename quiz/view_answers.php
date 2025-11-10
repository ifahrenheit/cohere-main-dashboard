<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../db_connection.php';

$companyid = $_GET['companyid'] ?? '';
$filter_date = $_GET['date'] ?? '';

if (!$companyid) {
    echo json_encode(["error" => "Missing companyid"]);
    exit;
}

header('Content-Type: text/html');

// ✅ Fetch answers + question text (with optional date filter)
$sql = "
    SELECT 
        qa.question_id,
        q.question AS question_text,
        qa.selected_answer,
        qa.correct_answer,
        qa.is_correct,
        qa.answered_at
    FROM quiz_answers qa
    JOIN quiz_questions q ON qa.question_id = q.id
    WHERE qa.companyid = ?
";

$params = [$companyid];
$types = "s";

if (!empty($filter_date)) {
    $sql .= " AND DATE(qa.answered_at) = ? ";
    $params[] = $filter_date;
    $types .= "s";
}

$sql .= " ORDER BY qa.answered_at DESC, qa.question_id";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// ✅ Calculate stats
$total = $result->num_rows;
$correct = 0;
$data = [];

while ($row = $result->fetch_assoc()) {
    if ($row['is_correct']) $correct++;
    $data[] = $row;
}

$score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Answers for <?= htmlspecialchars($companyid) ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            padding: 30px;
            background-color: #f5f7fa;
        }
        h2 {
            margin-bottom: 5px;
            color: #003366;
        }
        .summary {
            margin-bottom: 25px;
            font-weight: bold;
            background: #fff;
            border-left: 5px solid #003366;
            padding: 12px 18px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid #e0e0e0;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #003366;
            color: #ffffff;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        tr:nth-child(even) { background-color: #f9fbfc; }
        .correct { background-color: #eaf8ec; } /* light green */
        .wrong { background-color: #fdeaea; }   /* light red */
        .question { font-weight: bold; color: #333; }
        .answer { padding-left: 10px; color: #444; }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #777;
        }
    </style>
</head>
<body>
    <h2>Answers for <?= htmlspecialchars($companyid) ?></h2>
    <?php if (!empty($filter_date)): ?>
        <p><strong>Date:</strong> <?= htmlspecialchars($filter_date) ?></p>
    <?php endif; ?>

    <div class="summary">
        Total Answered: <?= $total ?> |
        Correct: <?= $correct ?> |
        Score: <?= $score ?>%
    </div>

    <table>
        <thead>
            <tr>
                <th>Question</th>
                <th>Selected Answer</th>
                <th>Correct Answer</th>
                <th>Result</th>
                <th>Answered At</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($total > 0): ?>
                <?php foreach ($data as $row): ?>
                    <tr class="<?= $row['is_correct'] ? 'correct' : 'wrong' ?>">
                        <td class="question"><?= nl2br(htmlspecialchars($row['question_text'])) ?></td>
                        <td class="answer"><?= htmlspecialchars($row['selected_answer']) ?></td>
                        <td class="answer"><?= htmlspecialchars($row['correct_answer']) ?></td>
                        <td><?= $row['is_correct'] ? '✅' : '❌' ?></td>
                        <td><?= htmlspecialchars($row['answered_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="no-data">No answers found for this company ID<?= $filter_date ? ' on this date' : '' ?>.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
