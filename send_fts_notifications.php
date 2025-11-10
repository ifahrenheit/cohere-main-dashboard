<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '/var/www/html/cohere_dashboard/vendor/autoload.php'; // Ensure PHPMailer is loaded

echo "⏳ Starting FTS Notification Script...\n";

require_once 'get_fts_records.php';
$finalRecords = getFTSRecordsForAllEmployees();

// Check if dashboard.php returned a valid array
if (!is_array($finalRecords)) {
    echo "❌ Error: dashboard.php did not return an array. Got:\n";
    var_dump($finalRecords);
    exit(1);
}

// Check if there are records to report
if (empty($finalRecords)) {
    echo "⚠️ No FTS records found in current date range.\n";
    // Uncomment below to test with fake data
    /*
    $finalRecords = [
        [
            'EmployeeID' => 'EMP123',
            'Name' => 'Test Agent',
            'Day' => '2025-04-09',
            'TimeIN' => '08:00:00',
            'TimeOUT' => 'FTS OUT'
        ]
    ];
    echo "🧪 Using test FTS record.\n";
    */
}

$agentNotices = [];
foreach ($finalRecords as $record) {
    $hasFTS = $record['TimeIN'] === 'FTS IN' || $record['TimeOUT'] === 'FTS OUT';
    if ($hasFTS) {
        $empId = $record['EmployeeID'];
        $date = $record['Day'];  // Assuming there's a 'Day' field in the record

        // Initialize employee if not set
        if (!isset($agentNotices[$empId])) {
            // Fetch email from Employees table (assuming it's in $finalRecords or available)
            // If email is not in $record, you need to fetch it from the database separately or include it in $finalRecords.
            $agentNotices[$empId]['Name'] = $record['Name'];
            $agentNotices[$empId]['Email'] = $record['Email'] ?? 'Email not available'; // Add email field here
        }

        // Group by both EmployeeID and Date
        $agentNotices[$empId]['RecordsByDate'][$date][] = $record;
    }
}

if (empty($agentNotices)) {
    echo "✅ No FTS entries to send.\n";
    exit;
}

// Build email content (in HTML format)
$subject = "📋 FTS Report Summary";

// Start HTML structure
$message = "<html><body>";
$message .= "<h2>FTS IN/OUT Records Summary</h2>";

foreach ($agentNotices as $employeeId => $notice) {
    // Ensure email is included in the notice
    $email = isset($notice['Email']) ? $notice['Email'] : 'Email not available'; 

    $message .= "<h3>Agent: {$notice['Name']} (ID: $employeeId)</h3>";
    $message .= "<p><strong>Email:</strong> {$email}</p>"; // Display email in the email content

    // Iterate over records by date and display them
    foreach ($notice['RecordsByDate'] as $date => $records) {
        $message .= "<p><strong>Date: $date</strong></p>";
        foreach ($records as $r) {
            $message .= "<p><strong>IN:</strong> {$r['TimeIN']}<br>
                        <strong>OUT:</strong> {$r['TimeOUT']}</p>";
        }
        $message .= "<hr>"; // Add a separator between each date's records
    }
}

$message .= "<p>Please review the entries and take necessary action.</p>";
$message .= "</body></html>"; // Close the HTML structure

echo "📨 Preparing to send FTS email...\n";

$mail = new PHPMailer(true);

try {
    // Enable SMTP debugging
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = 'html';  // 'echo' or 'error_log' if preferred

    // SMTP configuration
    $mail->isSMTP();
    $mail->Host = 'cohere.ph';
    $mail->SMTPAuth = true;
    $mail->Username = 'send_email@cohere.ph';
    $mail->Password = 'Cohere123456';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 2525;

    // Sender and recipient
    $mail->setFrom('send_email@cohere.ph', 'FTS Notifier');
    $mail->addAddress('andrewvincentt@gmail.com', 'Andrew');

    // Email content
    $mail->isHTML(true); // Set to true for HTML content
    $mail->Subject = $subject;
    $mail->Body    = $message;

    // Send it!
    $mail->send();
    echo "✅ Message has been sent successfully.\n";

} catch (Exception $e) {
    echo "❌ Mailer Error: {$mail->ErrorInfo}\n";
}
?>

