<?php
date_default_timezone_set('Asia/Manila'); // Set timezone

require '/var/www/html/cohere_dashboard/vendor/autoload.php'; // Ensure PHPMailer is loaded

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'db_connection.php';

$today = date('d'); // Get today's day (e.g., 08 or 24)
//$today = '8'; // Simulate it's the 8th of the month

echo "Today: $today\n"; // Debugging

if (in_array($today, [8, 9, 10, 11, 12, 13])) {
    $start_date = date('Y-m-23', mktime(0, 0, 0, date('m') - 1, 1, date('Y')));
    $end_date = date('Y-m-07');
} elseif (in_array($today, [23, 24, 25, 26, 27, 28])) {
    $start_date = date('Y-m-08');
    $end_date = date('Y-m-22');
} else {
    echo "Not an OT email schedule date, exiting...\n";
    exit();
}

echo "Filtering OT records from $start_date to $end_date\n"; // Debugging

$query = "SELECT o.employee_id, e.Firstname, e.Lastname, o.ot_date, o.ot_type, o.regular_rate, o.start_time, o.end_time, o.status 
          FROM ot_requests o 
          LEFT JOIN Employees e ON o.employee_id = e.EmployeeID 
          WHERE o.ot_date BETWEEN '$start_date' AND '$end_date'";
$result = $conn->query($query);

if ($result === false) {
    echo "Query error: " . $conn->error . "\n";
    exit();
}

if ($result->num_rows > 0) {
    echo "OT records found: " . $result->num_rows . "\n";
    
    $email_body = "<h3>OT Records from $start_date to $end_date</h3>";
    $email_body .= "<table border='1' cellpadding='5' cellspacing='0'>";
    $email_body .= "<tr><th>Employee ID</th><th>Employee Name</th><th>OT Date</th><th>OT Type</th><th>Regular Rate</th><th>Start Time</th><th>End Time</th><th>Duration (hrs)</th><th>Status</th></tr>";

    while ($row = $result->fetch_assoc()) {
        $start_time = strtotime($row['start_time']);
        $end_time = strtotime($row['end_time']);
        $duration = ($end_time < $start_time) ? (($end_time + 86400 - $start_time) / 3600) : (($end_time - $start_time) / 3600);

        $email_body .= "<tr>
            <td>{$row['employee_id']}</td>
            <td>{$row['Firstname']} {$row['Lastname']}</td>
            <td>{$row['ot_date']}</td>
            <td>{$row['ot_type']}</td>
            <td>{$row['regular_rate']}</td>
            <td>" . date("H:i", $start_time) . "</td>
            <td>" . date("H:i", $end_time) . "</td>
            <td>" . number_format($duration, 2) . "</td>
            <td>{$row['status']}</td>
        </tr>";
    }
    $email_body .= "</table>";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'cohere.ph'; // Replace with actual SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'send_email@cohere.ph';
        $mail->Password = 'Cohere123456';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 2525;
        $mail->setFrom('send_email@cohere.ph', 'OT System');
        $mail->addAddress('andrewvincentt@gmail.com');
        $mail->addAddress('payroll@cohere.ph');
        $mail->Subject = "OT Records from $start_date to $end_date";
        $mail->isHTML(true);
        $mail->Body = $email_body;

        $mail->send();
        echo "OT email sent successfully!\n";
    } catch (Exception $e) {
        echo "Error sending email: " . $mail->ErrorInfo . "\n";
    }
} else {
    echo "No OT records found for the selected date range.\n";
}

$conn->close();
?>

