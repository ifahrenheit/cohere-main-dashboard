<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get form data
    $report_number = $_POST['report_number'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $status_action = trim($_POST['status_action'] ?? '');
    
    // Validate required fields
    if (empty($report_number) || empty($comment)) {
        throw new Exception('Comment cannot be empty');
    }
    
    // Validate status action if provided
    $valid_statuses = ['reviewed', 'resolved', 'pending_hr', 'resolved_hr'];
    if (!empty($status_action) && !in_array($status_action, $valid_statuses)) {
        throw new Exception('Invalid status action: ' . $status_action);
    }
    
    // Get report ID, agent ID, and submitter ID
    $stmt = $conn->prepare("
        SELECT ir.id, ir.employee_id, ir.submitted_by_id, e.Email as reporter_email 
        FROM incident_reports ir
        LEFT JOIN Employees e ON ir.employee_id = e.EmployeeID
        WHERE ir.report_number = ?
    ");
    $stmt->bind_param("s", $report_number);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    
    if (!$report) {
        throw new Exception('Report not found');
    }
    
    $report_id = $report['id'];
    $reporter_email = $report['reporter_email'];
    $agent_employee_id = $report['employee_id'];
    $submitted_by_id = $report['submitted_by_id'];
    
    // Get current user info
    $user_email = $_SESSION['user_email'];
    $stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName FROM Employees WHERE Email = ?");
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $employee_id = $user['EmployeeID'];
    $employee_name = $user['FirstName'] . ' ' . $user['LastName'];
    
    // Insert comment
    $stmt = $conn->prepare("
        INSERT INTO incident_comments (report_id, employee_id, employee_name, comment)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $report_id, $employee_id, $employee_name, $comment);
    $stmt->execute();
    $comment_id = $conn->insert_id;
    
    // Handle file uploads for comment attachments
    $uploaded_files = 0;
    if (isset($_FILES['comment_attachments']) && !empty($_FILES['comment_attachments']['name'][0])) {
        $files = $_FILES['comment_attachments'];
        $file_count = count($files['name']);
        
        // Limit to 4 files
        if ($file_count > 4) {
            $file_count = 4;
        }
        
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $files['tmp_name'][$i];
                $original_name = basename($files['name'][$i]);
                $file_size = $files['size'][$i];
                $mime_type = mime_content_type($tmp_name);
                
                // Validate file type
                if (!in_array($mime_type, ALLOWED_TYPES)) {
                    continue;
                }
                
                // Validate file size
                if ($file_size > MAX_FILE_SIZE) {
                    continue;
                }
                
                // Generate unique filename
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $new_filename = $report_number . '_comment_' . $comment_id . '_' . ($i + 1) . '_' . time() . '.' . $extension;
                $file_path = UPLOAD_DIR . $new_filename;
                
                // Move uploaded file
                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Insert attachment record
                    $stmt_attach = $conn->prepare("
                        INSERT INTO incident_comment_attachments (comment_id, file_name, file_path, file_size, mime_type)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt_attach->bind_param("issis", $comment_id, $new_filename, $file_path, $file_size, $mime_type);
                    $stmt_attach->execute();
                    $stmt_attach->close();
                    $uploaded_files++;
                }
            }
        }
    }
    
    // Update incident status if requested
    $status_updated = false;
    if (!empty($status_action)) {
        $stmt = $conn->prepare("UPDATE incident_reports SET status = ? WHERE report_number = ?");
        $stmt->bind_param("ss", $status_action, $report_number);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $status_updated = true;
        }
    }
    
    // Send email notification (pass both agent_employee_id and submitted_by_id)
    sendCommentNotification($report_number, $employee_name, $comment, $reporter_email, $agent_employee_id, $submitted_by_id, $status_action);

    // If status changed to HR escalation, send separate HR notification
    if (in_array($status_action, ['pending_hr', 'resolved_hr'])) {
        sendHRNotification($report_number, $employee_name, $comment, $agent_employee_id, $status_action);
    }
    
    $conn->close();
    
    $message = 'Comment added successfully';
    if ($uploaded_files > 0) {
        $message .= ' with ' . $uploaded_files . ' attachment(s)';
    }
    if ($status_updated) {
        $message .= ' and status updated to ' . ucfirst($status_action);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'employee_name' => $employee_name,
        'employee_id' => $employee_id,
        'comment' => $comment,
        'created_at' => date('M j, Y g:i A'),
        'status_updated' => $status_updated,
        'new_status' => $status_action,
        'attachments_uploaded' => $uploaded_files
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Function to send email notification for new comment
function sendCommentNotification($report_number, $commenter_name, $comment, $reporter_email, $agent_employee_id, $submitted_by_id, $status_action = '') {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);

        // Send to managers mailing list
        $mail->addAddress(INCIDENT_EMAIL_TO);

        // Create connection for lookups
        $conn_lookup = getDBConnection();
        
        // Find and add the supervisor of the agent involved
        $stmt_sup = $conn_lookup->prepare("
            SELECT sm.supervisor_email 
            FROM supervisor_mapping sm
            INNER JOIN Employees e ON sm.agent_email = e.Email
            WHERE e.EmployeeID = ?
        ");
        $stmt_sup->bind_param("s", $agent_employee_id);
        $stmt_sup->execute();
        $supervisor_result = $stmt_sup->get_result();

        if ($supervisor = $supervisor_result->fetch_assoc()) {
            $supervisor_email = $supervisor['supervisor_email'];
            $mail->addAddress($supervisor_email);
        }
        $stmt_sup->close();

        // Find and add SUBMITTER's group members (except TL group)
        if ($submitted_by_id) {
            $stmt_group = $conn_lookup->prepare("
                SELECT DISTINCT g2.email
                FROM gsheet_employees g1
                INNER JOIN gsheet_employees g2 ON g1.group_name = g2.group_name
                WHERE g1.employee_id = ?
                    AND g1.group_name IS NOT NULL
                    AND g1.group_name != ''
                    AND g1.group_name != 'TL'
                    AND g2.status = 'Active'
                    AND g2.email IS NOT NULL
            ");
            $stmt_group->bind_param("s", $submitted_by_id);
            $stmt_group->execute();
            $group_result = $stmt_group->get_result();

            while ($group_member = $group_result->fetch_assoc()) {
                $mail->addAddress($group_member['email']);
            }
            $stmt_group->close();
        }
        
        $conn_lookup->close();

        // Add BCC recipients if defined
        if (defined('INCIDENT_EMAIL_BCC') && !empty(INCIDENT_EMAIL_BCC)) {
            $bcc_recipients = explode(',', INCIDENT_EMAIL_BCC);
            foreach ($bcc_recipients as $bcc) {
                $mail->addBCC(trim($bcc));
            }
        }
        
        // EMAIL THREADING
        $originalMessageId = "<incident-{$report_number}@dashboard.cohere.ph>";
        $mail->addCustomHeader('In-Reply-To', $originalMessageId);
        $mail->addCustomHeader('References', $originalMessageId);
        
        // Build status badge HTML
        $statusBadgeHtml = '';
        if (!empty($status_action)) {
            $statusBadgeHtml = "<div class='comment-badge' style='background: #28a745; margin-left: 10px;'>STATUS: " . strtoupper($status_action) . " ✅</div>";
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Re: New Incident Report: $report_number";
        $mail->Body    = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff; }
                .header { background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%); color: white; padding: 25px 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h2 { margin: 0; font-size: 22px; }
                .content { background: #f9f9f9; padding: 25px 20px; border: 1px solid #ddd; }
                .comment-badge { display: inline-block; background: #ff6b35; color: white; padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-bottom: 15px; }
                .commenter-info { background: white; padding: 15px; border-left: 4px solid #0f2557; border-radius: 5px; margin-bottom: 20px; }
                .comment-box { background: white; border-left: 4px solid #ff6b35; padding: 20px; margin: 20px 0; border-radius: 5px; font-size: 15px; line-height: 1.8; }
                .btn-primary { background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold; font-size: 15px; }
                .footer { background: #333; color: white; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>New Comment on Incident Report</h2>
                    <p style='margin: 8px 0 0 0; font-size: 14px;'>Report #$report_number</p>
                </div>
                <div class='content'>
                    <div class='comment-badge'>NEW COMMENT</div>
                    $statusBadgeHtml
                    
                    <div class='commenter-info'>
                        <strong style='color: #0f2557; font-size: 16px;'>$commenter_name</strong>
                        <div style='color: #999; font-size: 13px; margin-top: 5px;'>Posted " . date('M j, Y \a\t g:i A') . "</div>
                    </div>
                    
                    <div class='comment-box'>" . nl2br(htmlspecialchars($comment)) . "</div>
                    
                    <div style='text-align: center; margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 8px;'>
                        <p style='margin: 0 0 15px 0; color: #666;'>View the full conversation and reply:</p>
                        <a href='https://dashboard.cohere.ph/incident_report/view_report.php?id=$report_number' class='btn-primary'>View Report</a>
                    </div>
                </div>
                <div class='footer'>Incident Report System</div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Comment notification email failed: {$mail->ErrorInfo}");
        return false;
    }
}

// Function to send HR notification
function sendHRNotification($report_number, $commenter_name, $comment, $agent_employee_id, $status_action) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("
            SELECT ir.*, e.FirstName, e.LastName, g.group_name
            FROM incident_reports ir
            LEFT JOIN Employees e ON ir.employee_id = e.EmployeeID
            LEFT JOIN gsheet_employees g ON ir.employee_id COLLATE utf8mb4_unicode_ci = g.employee_id
            WHERE ir.report_number = ?
        ");
        $stmt->bind_param("s", $report_number);
        $stmt->execute();
        $incident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        if (!$incident) {
            return false;
        }
        
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        
        $hr_recipients = explode(',', HR_EMAIL_RECIPIENTS);
        foreach ($hr_recipients as $hr_email) {
            $mail->addAddress(trim($hr_email));
        }
        
        $originalMessageId = "<incident-{$report_number}@dashboard.cohere.ph>";
        $mail->addCustomHeader('In-Reply-To', $originalMessageId);
        $mail->addCustomHeader('References', $originalMessageId);
        
        $status_text = $status_action === 'pending_hr' ? 'PENDING HR - WRITTEN EXPLANATION REQUIRED' : 'RESOLVED HR - COMPLETED';
        $status_color = $status_action === 'pending_hr' ? '#ffc107' : '#28a745';
        
        $mail->isHTML(true);
        $mail->Subject = "Re: New Incident Report: $report_number - HR ACTION REQUIRED";
        $mail->Body    = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <div style='max-width: 600px; margin: 0 auto; background: white;'>
                <div style='background: linear-gradient(135deg, #dc3545 0%, #ff6b35 100%); color: white; padding: 25px; text-align: center;'>
                    <h2 style='margin: 0;'>📋 HR Escalation</h2>
                    <p style='margin: 8px 0 0 0;'>Report #$report_number</p>
                </div>
                <div style='padding: 25px; background: #f9f9f9;'>
                    <div style='background: {$status_color}; color: white; padding: 10px 20px; border-radius: 20px; display: inline-block; font-weight: bold; margin-bottom: 20px;'>{$status_text}</div>
                    <div style='background: white; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0;'>
                        <strong style='color: #dc3545;'>⚠️ Action Required</strong>
                        <p>This incident requires written explanation from the agent.</p>
                    </div>
                    <div style='background: white; padding: 15px; margin: 15px 0;'>
                        <p><strong>Date:</strong> " . date('F j, Y', strtotime($incident['incident_date'])) . "</p>
                        <p><strong>Agent:</strong> {$incident['employee_name']} ({$incident['employee_id']})</p>
                        <p><strong>Group:</strong> {$incident['group_name']}</p>
                        <p><strong>Summary:</strong> {$incident['summary']}</p>
                    </div>
                    <p><strong>Latest Comment from {$commenter_name}:</strong></p>
                    <div style='background: #fff3cd; border-left: 4px solid #ff6b35; padding: 20px; margin: 20px 0;'>" . nl2br(htmlspecialchars($comment)) . "</div>
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='https://dashboard.cohere.ph/incident_report/view_report.php?id=$report_number' style='background: linear-gradient(135deg, #dc3545 0%, #ff6b35 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>📊 View Full Report</a>
                    </div>
                </div>
                <div style='background: #333; color: white; padding: 15px; text-align: center; font-size: 12px;'>⚡ HR Escalation Notification</div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("HR notification failed: {$mail->ErrorInfo}");
        return false;
    }
}
?>