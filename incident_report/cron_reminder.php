<?php
// Cron job script to send reminders for stale incidents
// Run this daily via cron

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Don't output HTML
header('Content-Type: text/plain');

$conn = getDBConnection();

try {
    // Find incidents that:
    // 1. Status is pending or reviewed
    // 2. No activity (comments or status update) in last 72 hours
    // 3. No reminder sent in last 24 hours (to avoid spam)
    
    $sql = "
        SELECT 
            ir.id,
            ir.report_number,
            ir.incident_date,
            ir.employee_name,
            ir.employee_id,
            ir.summary,
            ir.status,
            ir.updated_at,
            ir.submitted_by_name,
            ir.submitted_by_id,
            ir.last_reminder_sent,
            COALESCE(MAX(ic.created_at), ir.updated_at) as last_activity,
            TIMESTAMPDIFF(HOUR, COALESCE(MAX(ic.created_at), ir.updated_at), NOW()) as hours_since_activity
        FROM incident_reports ir
        LEFT JOIN incident_comments ic ON ir.id = ic.report_id
        WHERE ir.status IN ('pending', 'reviewed')
        GROUP BY ir.id
        HAVING hours_since_activity >= 72
            AND (ir.last_reminder_sent IS NULL OR TIMESTAMPDIFF(HOUR, ir.last_reminder_sent, NOW()) >= 24)
        ORDER BY hours_since_activity DESC
    ";
    
    $result = $conn->query($sql);
    $stale_incidents = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($stale_incidents)) {
        echo date('Y-m-d H:i:s') . " - No stale incidents found.\n";
        exit(0);
    }
    
    echo date('Y-m-d H:i:s') . " - Found " . count($stale_incidents) . " stale incident(s).\n";
    
    // Send reminder email
    $email_sent = sendStaleIncidentReminder($stale_incidents);
    
    if ($email_sent) {
        // Update last_reminder_sent timestamp
        $report_ids = array_column($stale_incidents, 'id');
        $ids_string = implode(',', $report_ids);
        
        $update_sql = "UPDATE incident_reports SET last_reminder_sent = NOW() WHERE id IN ($ids_string)";
        $conn->query($update_sql);
        
        echo date('Y-m-d H:i:s') . " - Reminder email sent successfully.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Failed to send reminder email.\n";
    }
    
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
} finally {
    $conn->close();
}

// Function to send reminder email
function sendStaleIncidentReminder($incidents) {
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
        $mail->addAddress(INCIDENT_EMAIL_TO); // managers@cohere.ph

        // Collect all unique supervisors from the stale incidents
        $supervisor_emails = [];
        
        foreach ($incidents as $incident) {
            $conn_sup = getDBConnection(); // Fresh connection for each lookup
            $stmt_sup = $conn_sup->prepare("
                SELECT sm.supervisor_email 
                FROM supervisor_mapping sm
                INNER JOIN Employees e ON sm.agent_email = e.Email
                WHERE e.EmployeeID = ?
            ");
            $stmt_sup->bind_param("s", $incident['employee_id']);
            $stmt_sup->execute();
            $result_sup = $stmt_sup->get_result();
            
            if ($supervisor = $result_sup->fetch_assoc()) {
                $supervisor_emails[$supervisor['supervisor_email']] = true;
            }
            $stmt_sup->close();
            $conn_sup->close();
        }

        // Add each unique supervisor
        foreach (array_keys($supervisor_emails) as $supervisor_email) {
            $mail->addAddress($supervisor_email);
        }

        // Collect all group members from stale incidents (except TL group)
        // Use the SUBMITTER's group, not the agent's group
        $group_emails = [];

        foreach ($incidents as $incident) {
            $submitted_by_id = $incident['submitted_by_id'] ?? null;
            
            if ($submitted_by_id) {
                $conn_group = getDBConnection(); // Fresh connection for each lookup
                $stmt_group = $conn_group->prepare("
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
                $result_group = $stmt_group->get_result();
                
                while ($group_member = $result_group->fetch_assoc()) {
                    $group_emails[$group_member['email']] = true;
                }
                $stmt_group->close();
                $conn_group->close();
            }
        }

        // Add each unique group member
        foreach (array_keys($group_emails) as $group_email) {
            $mail->addAddress($group_email);
        }

        // Add BCC if defined
        if (defined('INCIDENT_EMAIL_BCC') && !empty(INCIDENT_EMAIL_BCC)) {
            $bcc_recipients = explode(',', INCIDENT_EMAIL_BCC);
            foreach ($bcc_recipients as $bcc) {
                $mail->addBCC(trim($bcc));
            }
        }
        
        // Build incident list HTML
        $incident_rows = '';
        foreach ($incidents as $incident) {
            $hours = $incident['hours_since_activity'];
            $days = floor($hours / 24);
            $status_badge_color = $incident['status'] === 'pending' ? '#ffc107' : '#0f2557';
            
            $incident_rows .= "
                <tr style='border-bottom: 1px solid #e0e0e0;'>
                    <td style='padding: 12px;'>
                        <a href='https://dashboard.cohere.ph/incident_report/view_report.php?id={$incident['report_number']}' 
                           style='color: #0f2557; font-weight: bold; text-decoration: none;'>
                            {$incident['report_number']}
                        </a>
                    </td>
                    <td style='padding: 12px;'>" . date('M j, Y', strtotime($incident['incident_date'])) . "</td>
                    <td style='padding: 12px;'>{$incident['employee_name']}</td>
                    <td style='padding: 12px;'>
                        <span style='background: {$status_badge_color}; color: white; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;'>
                            " . strtoupper($incident['status']) . "
                        </span>
                    </td>
                    <td style='padding: 12px; color: #dc3545; font-weight: bold;'>{$days} days ago</td>
                    <td style='padding: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>
                        " . htmlspecialchars(substr($incident['summary'], 0, 100)) . (strlen($incident['summary']) > 100 ? '...' : '') . "
                    </td>
                </tr>
            ";
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Incident Report Reminder: " . count($incidents) . " Stale Incident(s) Require Attention";
        $mail->Body    = "
        <html>
        <head>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333;
                }
                .container { 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 20px;
                }
                .header { 
                    background: linear-gradient(135deg, #dc3545 0%, #ff6b35 100%); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                    border-radius: 10px 10px 0 0; 
                }
                .content { 
                    background: #f9f9f9; 
                    padding: 30px 20px; 
                    border: 1px solid #ddd; 
                }
                .alert-box {
                    background: #fff3cd;
                    border-left: 4px solid #ff6b35;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 5px;
                }
                table {
                    width: 100%;
                    background: white;
                    border-collapse: collapse;
                    margin: 20px 0;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                }
                th {
                    background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%);
                    color: white;
                    padding: 12px;
                    text-align: left;
                    font-size: 12px;
                    text-transform: uppercase;
                }
                td {
                    padding: 12px;
                }
                .footer { 
                    background: #333; 
                    color: white; 
                    padding: 15px; 
                    text-align: center; 
                    border-radius: 0 0 10px 10px; 
                    font-size: 12px; 
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>⏰ Stale Incident Report Reminder</h2>
                    <p style='margin: 10px 0 0 0; font-size: 16px;'>" . count($incidents) . " incident(s) need attention</p>
                </div>
                <div class='content'>
                    <div class='alert-box'>
                        <strong>⚠️ Action Required:</strong> The following incidents have been in <strong>pending</strong> or <strong>reviewed</strong> status for more than 72 hours without any updates or comments.
                    </div>
                    
                    <p style='font-size: 15px; color: #666;'>
                        Please review these incidents and take appropriate action:
                    </p>
                    
                    <ul style='color: #666; line-height: 1.8;'>
                        <li>Add coaching feedback or explanation</li>
                        <li>Update the incident status</li>
                        <li>Request additional information if needed</li>
                    </ul>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>IR Number</th>
                                <th>Incident Date</th>
                                <th>Agent</th>
                                <th>Status</th>
                                <th>Last Activity</th>
                                <th>Summary</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$incident_rows}
                        </tbody>
                    </table>
                    
                    <p style='text-align: center; margin-top: 30px;'>
                        <a href='https://dashboard.cohere.ph/incident_report/dashboard.php' 
                           style='background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>
                            📊 View All Incidents
                        </a>
                    </p>
                </div>
                <div class='footer'>
                    ⚡ Automated Reminder from Incident Report System<br>
                    This email is sent daily when incidents require attention
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Reminder email failed: {$mail->ErrorInfo}");
        return false;
    }
}
?>