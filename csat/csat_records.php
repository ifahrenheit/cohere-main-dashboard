<?php
require_once 'includes/functions.php';

// Get filters
$filters = getFilterParams();
$whereClause = buildWhereClause($conn, $filters);

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 100; // Reduced to 100 for faster loading
$offset = ($page - 1) * $perPage;

// Fetch records (LIMIT for speed)
$recordsQuery = "
    SELECT *
    FROM csat_scores
    $whereClause
    ORDER BY survey_date DESC, id DESC
    LIMIT $perPage OFFSET $offset
";
$recordsResult = $conn->query($recordsQuery);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM csat_scores $whereClause";
$countResult = $conn->query($countQuery);
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $perPage);

include 'includes/header.php';
?>

    <div class="content-card">
        <h5 class="mb-3"><i class="bi bi-table"></i> CSAT Records</h5>
        <p class="text-muted">
            <i class="bi bi-info-circle"></i> Showing <?= $recordsResult->num_rows ?> records 
            (Page <?= $page ?> of <?= $totalPages ?>, Total: <?= number_format($totalRecords) ?>)
        </p>
        
        <?php if ($recordsResult->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Ticket</th>
                            <th>Agent</th>
                            <th>Team</th>
                            <th>Theme</th>
                            <th>Channel</th>
                            <th>Score</th>
                            <th>Type</th>
                            <th>Sentiment</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $recordsResult->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['ticket_number']) ?></strong></td>
                                <td><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($row['team_lead'] ?: 'N/A') ?></span></td>
                                <td><small><?= htmlspecialchars($row['theme'] ?: '-') ?></small></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($row['channel_type'] ?: '-') ?></span></td>
                                <td><span class="badge bg-dark"><?= $row['csat_score'] ?>/5</span></td>
                                <td>
                                    <?php if ($row['csat_type'] == 'CSAT'): ?>
                                        <span class="badge badge-csat">CSAT</span>
                                    <?php else: ?>
                                        <span class="badge badge-dsat">DSAT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['sentiment'] == 'Positive'): ?>
                                        <span class="badge bg-success">😊 <?= $row['sentiment'] ?></span>
                                    <?php elseif ($row['sentiment'] == 'Negative'): ?>
                                        <span class="badge bg-danger">😞 <?= $row['sentiment'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">😐 <?= $row['sentiment'] ?: 'Neutral' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= date('M d, Y', strtotime($row['survey_date'])) ?></small></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page - 1 ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page + 1 ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> No records found.
            </div>
        <?php endif; ?>
    </div>

<?php include 'includes/footer.php'; ?>