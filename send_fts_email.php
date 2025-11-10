<?php
date_default_timezone_set('Asia/Manila'); // Set timezone

require '/var/www/html/cohere_dashboard/vendor/autoload.php'; // Ensure PHPMailer is loaded

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'db_connection.php';

    $today = date('d'); // Get today's day
 //   $today = '25'; // Simulate 


// Determine date range based on the day of the month
if (in_array($today, [8, 9, 10, 11, 12, 13])) {
    $start_date = date('Y-m-23', strtotime('last month')); // Previous month's 23rd
    $end_date = date('Y-m-07'); // Current month's 7th
} elseif (in_array($today, [23, 24, 25, 26, 27, 28])) {
    $start_date = date('Y-m-08'); // Current month's 8th
    $end_date = date('Y-m-22'); // Current month's 22nd
} else {
    exit("Not a scheduled email date. Exiting...\n");
}

echo "Filtering FTS records from $start_date to $end_date\n";

// Fetch FTS records within the specified date range
$query = "SELECT * FROM fts_requests WHERE fts_date BETWEEN '$start_date' AND '$end_date'";
$result = $conn->query($query);

if ($result === false) {
    exit("Query error: " . $conn->error . "\n");
}

if ($result->num_rows > 0) {
    echo "FTS records found: " . $result->num_rows . "\n";

    // Build email body
    $email_body = "<h3>FTS Records from $start_date to $end_date</h3>";
    $email_body .= "<table border='1' cellpadding='5' cellspacing='0'>";
    $email_body .= "<tr>
        <th>ID</th>
        <th>Agent Name</th>
        <th>Date</th>
        <th>Time</th>
        <th>FTS Type</th>
        <th>Status</th>
        <th>Approved By</th>
    </tr>";

    while ($row = $result->fetch_assoc()) {
        $email_body .= "<tr>
            <td>{$row['employeeID']}</td>
            <td>{$row['employee_name']}</td>
            <td>{$row['fts_date']}</td>
            <td>{$row['fts_time']}</td>
            <td>{$row['fts_type']}</td>
            <td>{$row['status']}</td>
            <td>{$row['approver_name']}</td>
        </tr>";
    }
    $email_body .= "</table>";

    // Send email
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'cohere.ph'; // Replace with actual SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'send_email@cohere.ph';
        $mail->Password = 'Cohere123456';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 2525;
        $mail->setFrom('send_email@cohere.ph', 'FTS System');
        $mail->addAddress('andrewvincentt@gmail.com');
        $mail->addAddress('payroll@cohere.ph');
        $mail->Subject = "FTS Records from $start_date to $end_date";
        $mail->isHTML(true);
        $mail->Body = $email_body;

        $mail->send();
        echo "FTS email sent successfully!\n";
    } catch (Exception $e) {
        echo "Error sending email: " . $mail->ErrorInfo . "\n";
    }
} else {
    echo "No FTS records found for the selected date range.\n";
}

$conn->close();
?>

