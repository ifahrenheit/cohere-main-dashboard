<?php

date_default_timezone_set('Asia/Manila'); // Set timezone

require '/var/www/html/cohere_dashboard/vendor/autoload.php'; // Ensure PHPMailer is loaded

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'db_connection.php';

$today = date('d'); // Get today's day
//$today = '23'; // Simulate it's the 8th and 23rd of the month

if (in_array($today, [8, 9, 10, 11, 12, 13])) {
    $start_date = date('Y-m-23', mktime(0, 0, 0, date('m') - 1, 1, date('Y')));
    $end_date = date('Y-m-07');
} elseif (in_array($today, [23, 24, 25, 26, 27, 28])) {
    $start_date = date('Y-m-08');
    $end_date = date('Y-m-22');
} else {
    echo "Not a CWS email schedule date, exiting...\n";
    exit();
}

echo "Filtering CWS records from $start_date to $end_date\n";

$query = "SELECT c.employee_id, e.Firstname, e.Lastname, c.original_date, c.original_time, 
                 c.new_date, c.new_time, c.reason, c.status, c.approver_name, c.approved_at
          FROM cws_requests c
          LEFT JOIN Employees e ON c.employee_id = e.EmployeeID
          WHERE c.new_date BETWEEN '$start_date' AND '$end_date'";
$result = $conn->query($query);

if ($result === false) {
    echo "Query error: " . $conn->error . "\n";
    exit();
}

if ($result->num_rows > 0) {
    echo "CWS records found: " . $result->num_rows . "\n";

    $email_body = "<h3>CWS Records from $start_date to $end_date</h3>";
    $email_body .= "<table border='1' cellpadding='5' cellspacing='0'>";
    $email_body .= "<tr>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Original Date</th>
                        <th>Original Time</th>
                        <th>New Date</th>
                        <th>New Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Approver</th>
                        <th>Approved At</th>
                    </tr>";

    while ($row = $result->fetch_assoc()) {
        $email_body .= "<tr>
            <td>{$row['employee_id']}</td>
            <td>{$row['Firstname']} {$row['Lastname']}</td>
            <td>{$row['original_date']}</td>
            <td>{$row['original_time']}</td>
            <td>{$row['new_date']}</td>
            <td>{$row['new_time']}</td>
            <td>{$row['reason']}</td>
            <td>{$row['status']}</td>
            <td>{$row['approver_name']}</td>
            <td>{$row['approved_at']}</td>
        </tr>";
    }

    $email_body .= "</table>";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'cohere.ph';
        $mail->SMTPAuth = true;
        $mail->Username = 'send_email@cohere.ph';
        $mail->Password = 'Cohere123456';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 2525;
        $mail->setFrom('send_email@cohere.ph', 'CWS System');
        $mail->addAddress('andrewvincentt@gmail.com');
        $mail->addAddress('payroll@cohere.ph');
        $mail->Subject = "CWS Records from $start_date to $end_date";
        $mail->isHTML(true);
        $mail->Body = $email_body;

        $mail->send();
        echo "CWS email sent successfully!\n";
    } catch (Exception $e) {
        echo "Error sending email: " . $mail->ErrorInfo . "\n";
    }
} else {
    echo "No CWS records found for the selected date range.\n";
}

$conn->close();

?>

