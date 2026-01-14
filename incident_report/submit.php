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
    $incident_date = $_POST['incident_date'] ?? '';
    $employee_id = $_POST['employee_id'] ?? '';
    $summary = $_POST['summary'] ?? '';
    
    // Validate required fields
    if (empty($incident_date) || empty($employee_id) || empty($summary)) {
        throw new Exception('All required fields must be filled');
    }
    
    // Get employee name (agent involved)
    $stmt = $conn->prepare("SELECT FirstName, LastName, Email FROM Employees WHERE EmployeeID = ?");
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();

    if (!$employee) {
        throw new Exception('Employee not found');
    }

    $employee_name = $employee['FirstName'] . ' ' . $employee['LastName'];
    $employee_email = $employee['Email'];

    // Get submitter info (person filing the report)
    $submitter_email = $_SESSION['user_email'];
    $stmt = $conn->prepare("SELECT EmployeeID, FirstName, LastName FROM Employees WHERE Email = ?");
    $stmt->bind_param("s", $submitter_email);
    $stmt->execute();
    $submitter = $stmt->get_result()->fetch_assoc();

    $submitted_by_id = $submitter ? $submitter['EmployeeID'] : 'UNKNOWN';
    $submitted_by_name = $submitter ? $submitter['FirstName'] . ' ' . $submitter['LastName'] : 'Unknown User';

    // Generate report number
    $report_number = generateReportNumber();

    // Check if HR escalation was requested
    $escalate_to_hr = isset($_POST['escalate_to_hr']) && $_POST['escalate_to_hr'] == '1';
    $initial_status = $escalate_to_hr ? 'pending_hr' : 'pending';

    // Insert incident report
    $stmt = $conn->prepare("
        INSERT INTO incident_reports (report_number, incident_date, employee_id, employee_name, submitted_by_id, submitted_by_name, summary, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssss", $report_number, $incident_date, $employee_id, $employee_name, $submitted_by_id, $submitted_by_name, $summary, $initial_status);
    $stmt->execute();
    $report_id = $conn->insert_id;
    
    // Handle file uploads
    $uploaded_files = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $files = $_FILES['attachments'];
        $file_count = count($files['name']);
        
        // Limit to 4 files
        if ($file_count > 4) {
            throw new Exception('Maximum 4 attachments allowed');
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
                $new_filename = $report_number . '_' . ($i + 1) . '_' . time() . '.' . $extension;
                $file_path = UPLOAD_DIR . $new_filename;
                
                // Move uploaded file
                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Insert attachment record
                    $stmt = $conn->prepare("
                        INSERT INTO incident_attachments (report_id, file_name, file_path, file_size, mime_type)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("issis", $report_id, $new_filename, $file_path, $file_size, $mime_type);
                    $stmt->execute();
                    
                    $uploaded_files[] = $new_filename;
                }
            }
        }
    }
    
    // Send email notification
    sendIncidentEmail($report_number, $incident_date, $employee_id, $employee_name, $employee_email, $submitted_by_id, $summary, $uploaded_files);
    
    // If escalated to HR, also send HR notification
    if ($escalate_to_hr) {
        $system_comment = "This incident was escalated to HR upon submission and requires written explanation from the agent.";
        sendHRNotification($report_number, $submitted_by_name, $system_comment, $employee_id, 'pending_hr');
    }

    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Incident report submitted successfully',
        'report_number' => $report_number
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function sendIncidentEmail($report_number, $incident_date, $employee_id, $employee_name, $employee_email, $submitted_by_id, $summary, $attachments) {
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

        // Find and add the supervisor of the agent involved
        $conn_sup = getDBConnection();
        $stmt_sup = $conn_sup->prepare("
            SELECT sm.supervisor_email 
            FROM supervisor_mapping sm
            INNER JOIN Employees e ON sm.agent_email = e.Email
            WHERE e.EmployeeID = ?
        ");
        $stmt_sup->bind_param("s", $employee_id);
        $stmt_sup->execute();
        $supervisor_result = $stmt_sup->get_result();

        if ($supervisor = $supervisor_result->fetch_assoc()) {
            $supervisor_email = $supervisor['supervisor_email'];
            $mail->addAddress($supervisor_email);
        }
        $stmt_sup->close();

        // The submitter's team should be notified, not the agent's team
        $stmt_group = $conn_sup->prepare("
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
        $conn_sup->close();

        // Add BCC recipients if defined
        if (defined('INCIDENT_EMAIL_BCC') && !empty(INCIDENT_EMAIL_BCC)) {
            $bcc_recipients = explode(',', INCIDENT_EMAIL_BCC);
            foreach ($bcc_recipients as $bcc) {
                $mail->addBCC(trim($bcc));
            }
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "New Incident Report: $report_number";
        $mail->Body    = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff; }
                .header { background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h2 { margin: 0; font-size: 24px; }
                .content { background: #f9f9f9; padding: 30px 20px; border: 1px solid #ddd; }
                .field { margin-bottom: 20px; background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #ff6b35; }
                .label { font-weight: bold; color: #0f2557; font-size: 14px; text-transform: uppercase; margin-bottom: 5px; }
                .value { color: #666; margin-top: 8px; font-size: 15px; }
                .cta-section { background: #fff3cd; border: 2px solid #ff6b35; border-radius: 8px; padding: 25px; margin: 25px 0; text-align: center; }
                .btn-primary { background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold; font-size: 16px; }
                .footer { background: #333; color: white; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; font-size: 12px; }
                .urgent-note { background: #fff; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>New Incident Report Submitted</h2>
                    <p style='margin: 10px 0 0 0; font-size: 14px;'>Report #$report_number</p>
                </div>
                <div class='content'>
                    <div class='urgent-note'><strong>Action Required:</strong> This incident requires your attention.</div>
                    <div class='field'><div class='label'>Date of Incident</div><div class='value'>" . date('F j, Y', strtotime($incident_date)) . "</div></div>
                    <div class='field'><div class='label'>Agent Involved</div><div class='value'>$employee_name</div></div>
                    <div class='field'><div class='label'>Summary</div><div class='value'>" . nl2br(htmlspecialchars($summary)) . "</div></div>
                    <div class='field'><div class='label'>Attachments</div><div class='value'>" . (empty($attachments) ? 'None' : count($attachments) . ' file(s)') . "</div></div>
                    <div class='cta-section'>
                        <a href='https://dashboard.cohere.ph/incident_report/view_report.php?id=$report_number' class='btn-primary'>📊 View Report</a>
                    </div>
                </div>
                <div class='footer'>⚡ Incident Report System</div>
            </div>
        </body>
        </html>
        ";
        
        $messageId = "<incident-{$report_number}@dashboard.cohere.ph>";
        $mail->MessageID = $messageId;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email send failed: {$mail->ErrorInfo}");
        return false;
    }
}

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
        
        $mail->isHTML(true);
        $mail->Subject = "Re: New Incident Report: $report_number - HR ACTION REQUIRED";
        $mail->Body    = "<html><body><h2>HR Escalation</h2><p>Report: $report_number</p><p>Agent: {$incident['employee_name']}</p><p>Note: $comment</p><a href='https://dashboard.cohere.ph/incident_report/view_report.php?id=$report_number'>View Report</a></body></html>";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("HR notification failed: {$mail->ErrorInfo}");
        return false;
    }
}
?>