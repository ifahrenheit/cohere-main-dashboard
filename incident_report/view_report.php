<?php
session_start();

// ✅ Set permissive CSP to allow browser extensions
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' data: https://cdn.jsdelivr.net; connect-src 'self' https:;");
header("Cache-Control: no-cache, must-revalidate");

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    // Save the current URL to redirect back after login
    $currentUrl = $_SERVER['REQUEST_URI'];
    header("Location: ../login.php?redirect=" . urlencode($currentUrl));
    exit();
}

$report_number = $_GET['id'] ?? '';

if (empty($report_number)) {
    die("Report number not provided");
}

$conn = getDBConnection();

// Get report details
$stmt = $conn->prepare("SELECT * FROM incident_reports WHERE report_number = ?");
$stmt->bind_param("s", $report_number);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    die("Report not found");
}

// Get attachments
$stmt = $conn->prepare("SELECT * FROM incident_attachments WHERE report_id = ? ORDER BY uploaded_at");
$stmt->bind_param("i", $report['id']);
$stmt->execute();
$attachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get comments
$stmt = $conn->prepare("SELECT * FROM incident_comments WHERE report_id = ? ORDER BY created_at ASC");
$stmt->bind_param("i", $report['id']);
$stmt->execute();
$comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get current user info
$user_email = $_SESSION['user_email'];
$stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName FROM Employees WHERE Email = ?");
$stmt->bind_param("s", $user_email);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();

// Set current employee ID from database query
$current_employee_id = $current_user['EmployeeID'] ?? null;
$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['Admin', 'Manager', 'Director']);

// Function to check if user can edit report
function canEditReport($report, $current_employee_id, $is_admin) {
    return $is_admin || ($report['submitted_by_id'] == $current_employee_id);
}

// Function to check if user can edit comment
function canEditComment($comment, $current_employee_id, $is_admin) {
    return $is_admin || ($comment['employee_id'] == $current_employee_id);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Report - <?= htmlspecialchars($report_number) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .report-header {
            border-bottom: 3px solid #ff6b35;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .report-number {
            font-size: 32px;
            font-weight: bold;
            color: #0f2557;
        }
        .badge-pending {
            background: #ffc107;
            color: #000;
        }
        .badge-reviewed {
            background: #0f2557;
        }
        .badge-resolved {
            background: #28a745;
        }
        .badge-pending_hr {
            background: #ff6b35;
        }
        .badge-resolved_hr {
            background: #6c757d;
        }
        .info-section {
            margin-bottom: 30px;
        }
        .info-label {
            font-weight: bold;
            color: #0f2557;
            margin-bottom: 5px;
        }
        .info-value {
            color: #666;
            font-size: 16px;
        }
        .summary-box {
            background: #f8f9fa;
            border-left: 4px solid #ff6b35;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .attachment-item {
            border: 2px solid #0f2557;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.3s;
            cursor: pointer;
        }
        .attachment-item:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
            border-color: #ff6b35;
        }
        .attachment-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .attachment-info {
            padding: 10px;
            background: #f8f9fa;
            text-align: center;
            font-size: 12px;
            color: #0f2557;
            font-weight: 600;
        }
        .btn-print {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            color: white;
            border: none;
        }
        .btn-print:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #e55a2b 100%);
            color: white;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        
        /* Comments Section */
        .comments-section {
            margin-top: 40px;
            border-top: 3px solid #0f2557;
            padding-top: 30px;
        }
        .comment-item {
            background: #f8f9fa;
            border-left: 4px solid #0f2557;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .comment-item:hover {
            border-left-color: #ff6b35;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .comment-author {
            font-weight: bold;
            color: #0f2557;
            font-size: 16px;
        }
        .comment-date {
            color: #999;
            font-size: 13px;
        }
        .comment-text {
            color: #333;
            line-height: 1.6;
        }
        .comment-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            border: 2px solid #0f2557;
            margin-top: 20px;
        }
        .comment-form textarea {
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .comment-form textarea:focus {
            border-color: #ff6b35;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }
        .btn-comment {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            border: none;
            color: white;
            padding: 10px 30px;
            font-weight: bold;
        }
        .btn-comment:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #e55a2b 100%);
            transform: translateY(-2px);
        }
        .no-comments {
            text-align: center;
            color: #999;
            padding: 30px;
            font-style: italic;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            body {
                background: white;
            }
            .attachment-item img {
                max-height: 300px;
            }
        }
        
        /* Lightbox styles */
        .lightbox {
            display: none;
            position: fixed;
            z-index: 9999;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 37, 87, 0.95);
            justify-content: center;
            align-items: center;
        }
        .lightbox img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        .lightbox.active {
            display: flex;
        }
        .close-lightbox {
            position: absolute;
            top: 20px;
            right: 40px;
            color: #ff6b35;
            font-size: 40px;
            cursor: pointer;
            background: none;
            border: none;
            font-weight: bold;
        }
        .close-lightbox:hover {
            color: #e55a2b;
        }

        /* Edit Buttons and Indicators */
        .btn-edit {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            color: white;
            border: none;
            padding: 5px 15px;
            font-size: 14px;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #e55a2b 100%);
            transform: translateY(-2px);
        }

        .btn-edit-comment {
            background: none;
            border: none;
            color: #ff6b35;
            font-size: 12px;
            padding: 2px 8px;
            cursor: pointer;
            margin-left: 10px;
        }

        .btn-edit-comment:hover {
            color: #e55a2b;
            text-decoration: underline;
        }

        .edited-indicator {
            font-size: 0.85em;
            color: #666;
            font-style: italic;
            margin-left: 15px;
        }

        .edited-indicator-small {
            font-size: 0.75em;
            color: #999;
            font-style: italic;
            margin-left: 8px;
        }

        .history-link, .history-link-small {
            color: #0f2557;
            text-decoration: none;
            margin-left: 5px;
        }

        .history-link:hover, .history-link-small:hover {
            color: #ff6b35;
            text-decoration: underline;
        }

        #summary-edit-form textarea,
        [id^="comment-edit-form-"] textarea,
        #incident-date-input {
            border: 2px solid #0f2557;
            border-radius: 5px;
        }

        #summary-edit-form textarea:focus,
        [id^="comment-edit-form-"] textarea:focus,
        #incident-date-input:focus {
            border-color: #ff6b35;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        .history-item {
            border-left: 3px solid #0f2557;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        .history-item:hover {
            border-left-color: #ff6b35;
        }

        .history-header {
            font-weight: bold;
            color: #0f2557;
            margin-bottom: 8px;
        }

        .history-diff {
            background: #fff;
            padding: 10px;
            border-radius: 3px;
            margin-top: 8px;
        }

        .old-value {
            background: #ffebee;
            padding: 5px;
            border-radius: 3px;
            text-decoration: line-through;
            color: #c62828;
        }

        .new-value {
            background: #e8f5e9;
            padding: 5px;
            border-radius: 3px;
            color: #2e7d32;
        }

        .preview-container {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .preview-item {
            position: relative;
            width: 100px;
            height: 100px;
            border: 2px solid #0f2557;
            border-radius: 8px;
            overflow: hidden;
        }
        .preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-item .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }
        .preview-item .remove-btn:hover {
            background: #e55a2b;
        }
        .comment-attachment {
            display: inline-block;
            margin: 5px;
            border: 2px solid #0f2557;
            border-radius: 5px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .comment-attachment:hover {
            transform: scale(1.05);
            border-color: #ff6b35;
        }
        .comment-attachment img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            display: block;
        }
        .comment-attachments {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="report-container">
        <!-- Header -->
        <div class="report-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="report-number">📋 <?= htmlspecialchars($report['report_number']) ?></h1>
                    <span class="badge badge-<?= $report['status'] ?> fs-6 mt-2"><?= strtoupper($report['status']) ?></span>
                </div>
                <div class="no-print">
                    <button class="btn-print">🖨️ Print Report</button>
                    <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
        </div>

        <!-- Report Details -->
        <div class="row">
            <div class="col-md-6 info-section">
                <div class="info-label">Date of Incident</div>
                <div id="incident-date-display">
                    <div class="info-value">📅 <?= date('F j, Y', strtotime($report['incident_date'])) ?></div>
                    <?php if (canEditReport($report, $current_employee_id, $is_admin)): ?>
                        <button onclick="editIncidentDate()" class="btn btn-sm btn-edit mt-1 no-print" style="font-size: 12px; padding: 3px 10px;">
                            ✏️ Edit Date
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Hidden edit form -->
                <div id="incident-date-edit-form" style="display: none;">
                    <input type="date" id="incident-date-input" class="form-control" value="<?= $report['incident_date'] ?>" max="<?= date('Y-m-d') ?>">
                    <div class="mt-2">
                        <button onclick="saveIncidentDate()" class="btn btn-sm btn-success">💾 Save</button>
                        <button onclick="cancelIncidentDateEdit()" class="btn btn-sm btn-secondary">❌ Cancel</button>
                    </div>
                </div>
            </div>
            <div class="col-md-6 info-section">
                <div class="info-label">Reported On</div>
                <div class="info-value">🕐 <?= date('F j, Y g:i A', strtotime($report['created_at'])) ?></div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 info-section">
                <div class="info-label">Agent Involved</div>
                <div class="info-value">👤 <?= htmlspecialchars($report['employee_name']) ?></div>
            </div>
            <div class="col-md-6 info-section">
                <div class="info-label">Employee ID</div>
                <div class="info-value">🆔 <?= htmlspecialchars($report['employee_id']) ?></div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 info-section">
                <div class="info-label">Reported By</div>
                <div class="info-value">📝 <?= htmlspecialchars($report['submitted_by_name'] ?? 'N/A') ?></div>
            </div>
            <div class="col-md-6 info-section">
                <div class="info-label">Submitter ID</div>
                <div class="info-value">🆔 <?= htmlspecialchars($report['submitted_by_id'] ?? 'N/A') ?></div>
            </div>
        </div>

        <!-- Summary -->
        <div class="info-section">
            <div class="info-label">
                Incident Summary
                <?php if ($report['edited_at']): ?>
                    <span class="edited-indicator" title="Last edited <?= date('M j, Y g:i A', strtotime($report['edited_at'])) ?>">
                        (Edited <?= date('M j, Y g:i A', strtotime($report['edited_at'])) ?>)
                        <a href="#" onclick="showEditHistory('report', <?= $report['id'] ?>); return false;" class="history-link">View history</a>
                    </span>
                <?php endif; ?>
            </div>
            <div class="summary-box" id="summary-display">
                <?= nl2br(htmlspecialchars($report['summary'])) ?>
            </div>
            
            <?php if (canEditReport($report, $current_employee_id, $is_admin)): ?>
                <button onclick="editReport()" class="btn btn-sm btn-edit mt-2 no-print">
                    ✏️ Edit Summary
                </button>
            <?php endif; ?>
            
            <!-- Hidden edit form -->
            <div id="summary-edit-form" style="display: none;">
                <textarea id="summary-edit-textarea" class="form-control" rows="6"><?= htmlspecialchars($report['summary']) ?></textarea>
                <div class="mt-2">
                    <button onclick="saveReportEdit()" class="btn btn-sm btn-success">💾 Save</button>
                    <button onclick="cancelReportEdit()" class="btn btn-sm btn-secondary">❌ Cancel</button>
                </div>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
            <div class="info-section">
                <div class="info-label">Attachments (<?= count($attachments) ?>)</div>
                <div class="attachments-grid">
                    <?php foreach ($attachments as $attachment): ?>
                        <div class="attachment-item">
                            <img src="uploads/<?= htmlspecialchars($attachment['file_name']) ?>" alt="Attachment">
                            <div class="attachment-info">
                                <?= number_format($attachment['file_size'] / 1024, 0) ?> KB
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No attachments for this report.</div>
        <?php endif; ?>

        <!-- Status Timeline -->
        <div class="info-section">
            <div class="info-label">Status Timeline</div>
            <div class="mt-3">
                <div class="d-flex align-items-center mb-2">
                    <div class="badge bg-success me-2">✓</div>
                    <div>Report Created - <?= date('M j, Y g:i A', strtotime($report['created_at'])) ?></div>
                </div>
                <?php if ($report['updated_at'] != $report['created_at']): ?>
                    <div class="d-flex align-items-center mb-2">
                        <div class="badge bg-primary me-2">✓</div>
                        <div>Last Updated - <?= date('M j, Y g:i A', strtotime($report['updated_at'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section no-print">
            <h3 class="mb-4" style="color: #0f2557;">💬 Comments & Updates</h3>
            
            <!-- Comments List -->
            <div id="commentsList">
                <?php if (empty($comments)): ?>
                    <div class="no-comments">No comments yet. Be the first to comment!</div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item" data-comment-id="<?= $comment['id'] ?>">
                            <div class="comment-header">
                                <div class="comment-author">
                                    👤 <?= htmlspecialchars($comment['employee_name']) ?>
                                    <span class="badge bg-secondary ms-2"><?= htmlspecialchars($comment['employee_id']) ?></span>
                                    <?php if ($comment['edited_at']): ?>
                                        <span class="edited-indicator-small" title="Last edited <?= date('M j, Y g:i A', strtotime($comment['edited_at'])) ?>">
                                            (Edited)
                                            <a href="#" onclick="showEditHistory('comment', <?= $comment['id'] ?>); return false;" class="history-link-small">history</a>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-date">
                                    <?= date('M j, Y g:i A', strtotime($comment['created_at'])) ?>
                                    <?php if (canEditComment($comment, $current_employee_id, $is_admin)): ?>
                                        <button onclick="editComment(<?= $comment['id'] ?>)" class="btn-edit-comment no-print">✏️ Edit</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="comment-text" id="comment-display-<?= $comment['id'] ?>">
                                <?= nl2br(htmlspecialchars($comment['comment'])) ?>
                            </div>
                            
                            <!-- Hidden edit form -->
                            <div id="comment-edit-form-<?= $comment['id'] ?>" style="display: none;">
                                <textarea id="comment-edit-textarea-<?= $comment['id'] ?>" class="form-control" rows="4"><?= htmlspecialchars($comment['comment']) ?></textarea>
                                <div class="mt-2">
                                    <button onclick="saveCommentEdit(<?= $comment['id'] ?>)" class="btn btn-sm btn-success">💾 Save</button>
                                    <button onclick="cancelCommentEdit(<?= $comment['id'] ?>)" class="btn btn-sm btn-secondary">❌ Cancel</button>
                                </div>
                            </div>
                            
                            <?php
                            // Get attachments for this comment
                            $stmt_attach = $conn->prepare("SELECT * FROM incident_comment_attachments WHERE comment_id = ? ORDER BY uploaded_at");
                            $stmt_attach->bind_param("i", $comment['id']);
                            $stmt_attach->execute();
                            $comment_attachments = $stmt_attach->get_result()->fetch_all(MYSQLI_ASSOC);
                            $stmt_attach->close();
                            
                            if (!empty($comment_attachments)):
                            ?>
                                <div class="comment-attachments">
                                    <small style="color: #666; font-weight: 600;">📎 Attachments:</small><br>
                                    <?php foreach ($comment_attachments as $attach): ?>
                                        <div class="comment-attachment">
                                            <img src="uploads/<?= htmlspecialchars($attach['file_name']) ?>" alt="Attachment">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add Comment Form -->
            <div class="comment-form">
                <h5 style="color: #0f2557; margin-bottom: 15px;">Add a Comment</h5>
                <form id="commentForm" method="POST" action="#">
                    <input type="hidden" name="report_number" value="<?= htmlspecialchars($report_number) ?>">
                    <div class="mb-3">
                        <textarea 
                            class="form-control" 
                            id="comment" 
                            name="comment" 
                            rows="4" 
                            placeholder="Explain what happened, provide coaching details, or add any relevant updates..."
                            required
                        ></textarea>
                    </div>

                    <!-- Attachment Input -->
                    <div class="mb-3">
                        <label for="comment_attachments" class="form-label" style="color: #0f2557; font-weight: 600;">
                            📎 Add Attachments (Optional)
                        </label>
                        <input type="file" class="form-control" id="comment_attachments" multiple accept="image/*">
                        <small class="text-muted">Up to 4 images. Files will be compressed automatically.</small>
                        <div id="commentPreviewContainer" class="preview-container mt-2"></div>
                    </div>
                    
                    <?php 
                        // Check if user is HR
                        $hr_emails = explode(',', HR_EMAIL_RECIPIENTS);
                        $is_hr = in_array($_SESSION['user_email'], $hr_emails);

                        // Check if user is SGA
                        $allowed_sga_emails = [
                            'anamarie.munez@cohere.ph',
                            'honey.cortes@cohere.ph',
                        ];
                        $is_sga = in_array($_SESSION['user_email'], $allowed_sga_emails);

                        if (in_array($_SESSION['role'], ['Admin', 'Manager', 'Director']) || ($_SESSION['is_supervisor'] ?? false) || $is_hr || $is_sga): 
                        ?>
                    <div class="mb-3">
                        <label for="status_action" class="form-label" style="color: #0f2557; font-weight: 600;">
                            📊 Update Status (Optional)
                        </label>
                        <select class="form-select" id="status_action" name="status_action">
                            <option value="">Post comment only (no status change)</option>
                            
                            <?php if (!$is_hr && !$is_sga): ?>
                                <!-- Regular users see all options -->
                                <option value="reviewed">Post comment and mark as Reviewed</option>
                                <option value="resolved">Post comment and mark as Resolved ✅</option>
                                <option value="pending_hr" style="background-color: #fff3cd;">📋 Post comment and mark as Pending HR (Written Explanation)</option>
                                <option value="resolved_hr" style="background-color: #d4edda;">✅ Post comment and mark as Resolved HR (Completed)</option>
                            <?php else: ?>
                                <!-- HR and SGA users ONLY see HR options -->
                                <option value="pending_hr" style="background-color: #fff3cd;">📋 Post comment and mark as Pending HR (Written Explanation)</option>
                                <option value="resolved_hr" style="background-color: #d4edda;">✅ Post comment and mark as Resolved HR (Completed)</option>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">Choose an action to update the incident status while commenting</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-comment" id="submitComment">
                            <span id="submitText">💬 Post Comment</span>
                            <span id="submitSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php $conn->close(); ?>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox">
        <button class="close-lightbox">×</button>
        <img id="lightbox-img" src="" alt="Full size image">
    </div>

    <!-- Edit History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%); color: white;">
                    <h5 class="modal-title">📜 Edit History</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historyContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
let compressedCommentFiles = [];

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    
    // File input change handler for comments
    document.getElementById('comment_attachments').addEventListener('change', async function(e) {
        const files = Array.from(e.target.files);
        
        if (files.length + compressedCommentFiles.length > 4) {
            alert('You can only upload up to 4 images');
            e.target.value = '';
            return;
        }

        for (let file of files) {
            if (!file.type.startsWith('image/')) {
                alert('Only image files are allowed');
                continue;
            }

            const compressed = await compressImage(file);
            compressedCommentFiles.push(compressed);
        }

        updateCommentPreview();
        e.target.value = '';
    });

    // Handle comment submission
    document.getElementById('commentForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = document.getElementById('submitComment');
        const submitText = document.getElementById('submitText');
        const submitSpinner = document.getElementById('submitSpinner');
        const commentField = document.getElementById('comment');
        const statusActionField = document.getElementById('status_action');
        
        const statusAction = statusActionField ? statusActionField.value : '';
        const commentText = commentField.value.trim();
        
        if (statusAction && !commentText) {
            alert('⚠️ Comment is required when updating status.');
            commentField.focus();
            return;
        }
        
        if (!commentText) {
            alert('⚠️ Please enter a comment.');
            commentField.focus();
            return;
        }
        
        submitBtn.disabled = true;
        submitText.classList.add('d-none');
        submitSpinner.classList.remove('d-none');
        
        const formData = new FormData(this);
        
        // Add compressed images
        compressedCommentFiles.forEach((item, index) => {
            formData.append('comment_attachments[]', item.file);
        });
        
        try {
            const response = await fetch('add_comment.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                location.reload();
            } else {
                alert('❌ Error: ' + result.message);
            }
        } catch (error) {
            alert('❌ Error posting comment: ' + error.message);
        } finally {
            submitBtn.disabled = false;
            submitText.classList.remove('d-none');
            submitSpinner.classList.add('d-none');
        }
    });

    // Print button
    const printBtn = document.querySelector('.btn-print');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
    
    // Attachment items - use event delegation for dynamically loaded content
    document.addEventListener('click', function(e) {
        const attachmentItem = e.target.closest('.attachment-item');
        if (attachmentItem) {
            const img = attachmentItem.querySelector('img');
            if (img) {
                openLightbox(img.src);
            }
            return;
        }
        
        const commentAttachment = e.target.closest('.comment-attachment');
        if (commentAttachment) {
            const img = commentAttachment.querySelector('img');
            if (img) {
                openLightbox(img.src);
            }
            return;
        }
        
        if (e.target.id === 'lightbox' || e.target.classList.contains('close-lightbox')) {
            closeLightbox();
        }
    });
    
    // Escape key to close lightbox
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });
});

// Image compression function
async function compressImage(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;
                
                const maxDimension = 1920;
                if (width > maxDimension || height > maxDimension) {
                    if (width > height) {
                        height = (height / width) * maxDimension;
                        width = maxDimension;
                    } else {
                        width = (width / height) * maxDimension;
                        height = maxDimension;
                    }
                }
                
                canvas.width = width;
                canvas.height = height;
                
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);
                
                canvas.toBlob(function(blob) {
                    const compressedFile = new File([blob], file.name, {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    });
                    
                    resolve({
                        file: compressedFile,
                        preview: canvas.toDataURL('image/jpeg', 0.8)
                    });
                }, 'image/jpeg', 0.8);
            };
            img.src = e.target.result;
        };
        
        reader.readAsDataURL(file);
    });
}

// Update comment preview
function updateCommentPreview() {
    const container = document.getElementById('commentPreviewContainer');
    container.innerHTML = '';
    
    compressedCommentFiles.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        
        const img = document.createElement('img');
        img.src = item.preview;
        
        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-btn';
        removeBtn.innerHTML = '×';
        removeBtn.type = 'button';
        removeBtn.addEventListener('click', () => removeCommentImage(index));
        
        div.appendChild(img);
        div.appendChild(removeBtn);
        container.appendChild(div);
    });
}

// Remove image
function removeCommentImage(index) {
    compressedCommentFiles.splice(index, 1);
    updateCommentPreview();
}

// Lightbox functions
function openLightbox(src) {
    document.getElementById('lightbox').classList.add('active');
    document.getElementById('lightbox-img').src = src;
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
}

// Edit Report
function editReport() {
    document.getElementById('summary-display').style.display = 'none';
    document.getElementById('summary-edit-form').style.display = 'block';
    document.querySelector('.btn-edit').style.display = 'none';
}

function cancelReportEdit() {
    document.getElementById('summary-display').style.display = 'block';
    document.getElementById('summary-edit-form').style.display = 'none';
    document.querySelector('.btn-edit').style.display = 'inline-block';
}

async function saveReportEdit() {
    const newSummary = document.getElementById('summary-edit-textarea').value.trim();
    
    if (!newSummary) {
        alert('Summary cannot be empty');
        return;
    }
    
    const formData = new FormData();
    formData.append('report_id', <?= $report['id'] ?>);
    formData.append('summary', newSummary);
    formData.append('incident_date', '<?= $report['incident_date'] ?>');
    
    try {
        const response = await fetch('edit_report.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error saving changes: ' + error.message);
    }
}

// Edit Incident Date
function editIncidentDate() {
    document.getElementById('incident-date-display').style.display = 'none';
    document.getElementById('incident-date-edit-form').style.display = 'block';
}

function cancelIncidentDateEdit() {
    document.getElementById('incident-date-display').style.display = 'block';
    document.getElementById('incident-date-edit-form').style.display = 'none';
}

async function saveIncidentDate() {
    const newDate = document.getElementById('incident-date-input').value;
    
    if (!newDate) {
        alert('Please select a date');
        return;
    }
    
    const formData = new FormData();
    formData.append('report_id', <?= $report['id'] ?>);
    formData.append('incident_date', newDate);
    formData.append('summary', `<?= str_replace(["\r", "\n", "'"], ["", "", "\\'"], $report['summary']) ?>`);
    
    try {
        const response = await fetch('edit_report.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error saving date: ' + error.message);
    }
}

// Edit Comment
function editComment(commentId) {
    document.getElementById('comment-display-' + commentId).style.display = 'none';
    document.getElementById('comment-edit-form-' + commentId).style.display = 'block';
}

function cancelCommentEdit(commentId) {
    document.getElementById('comment-display-' + commentId).style.display = 'block';
    document.getElementById('comment-edit-form-' + commentId).style.display = 'none';
}

async function saveCommentEdit(commentId) {
    const newComment = document.getElementById('comment-edit-textarea-' + commentId).value.trim();
    
    if (!newComment) {
        alert('Comment cannot be empty');
        return;
    }
    
    const formData = new FormData();
    formData.append('comment_id', commentId);
    formData.append('comment', newComment);
    
    try {
        const response = await fetch('edit_comment.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error saving changes: ' + error.message);
    }
}

// Show Edit History
async function showEditHistory(type, id) {
    const modal = new bootstrap.Modal(document.getElementById('historyModal'));
    const content = document.getElementById('historyContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>';
    modal.show();
    
    try {
        const response = await fetch(`get_edit_history.php?type=${type}&id=${id}`);
        const result = await response.json();
        
        if (result.success && result.history.length > 0) {
            let html = '';
            
            result.history.forEach(item => {
                if (type === 'report') {
                    html += `
                        <div class="history-item">
                            <div class="history-header">
                                ${item.editor_name || 'Unknown'} edited ${item.field_name}
                                <span style="float: right; color: #999; font-size: 0.9em;">
                                    ${new Date(item.edited_at).toLocaleString()}
                                </span>
                            </div>
                            <div class="history-diff">
                                <div><strong>From:</strong></div>
                                <div class="old-value mb-2">${item.old_value || '(empty)'}</div>
                                <div><strong>To:</strong></div>
                                <div class="new-value">${item.new_value || '(empty)'}</div>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="history-item">
                            <div class="history-header">
                                ${item.editor_name || 'Unknown'} edited this comment
                                <span style="float: right; color: #999; font-size: 0.9em;">
                                    ${new Date(item.edited_at).toLocaleString()}
                                </span>
                            </div>
                            <div class="history-diff">
                                <div><strong>Previous version:</strong></div>
                                <div class="old-value mb-2">${item.old_content.replace(/\n/g, '<br>')}</div>
                                <div><strong>Updated version:</strong></div>
                                <div class="new-value">${item.new_content.replace(/\n/g, '<br>')}</div>
                            </div>
                        </div>
                    `;
                }
            });
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<p class="text-center text-muted">No edit history available</p>';
        }
    } catch (error) {
        content.innerHTML = '<p class="text-center text-danger">Error loading history</p>';
    }
}
    </script>
</body>
</html>