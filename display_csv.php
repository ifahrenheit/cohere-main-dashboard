<?php

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
//session_regenerate_id(true); // Start the session

// Check if there is a success message to display
if (isset($_SESSION['success_message'])) {
    echo "<p style='color: green;'>" . $_SESSION['success_message'] . "</p>";
    unset($_SESSION['success_message']); // Clear the message after displaying
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/php-error.log');



// Database connection
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";  // Use central_db for NovSched table

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['file']['tmp_name'];
        
        if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {
            $header = fgetcsv($handle); // Get the header row
            // Assume the dates are from column 4 to the end
            $dateColumns = array_slice($header, 4); // Get columns from the 5th column onward

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Extract employee-specific data (columns before the dates)
                $tl = $data[0];
                $idNumber = $data[1];
                $agentList = $data[2];

                // Insert the data into NovSched table for each date and shift
                foreach ($dateColumns as $index => $date) {
                    $shift = $data[4 + $index]; // Get the shift for this date
                    $formattedDate = date("Y-m-d", strtotime($date)); // Convert to SQL date format
                    $sql = "INSERT INTO NovSched (tl, id_number, agent_list, shift_date, shift) 
                            VALUES ('$tl', '$idNumber', '$agentList', '$formattedDate', '$shift')";
                    $conn->query($sql);
                }
            }
            fclose($handle);
            echo "CSV data has been successfully inserted into the NovSched table in central_db.";
        } else {
            echo "Error opening the CSV file.";
        }
    } else {
        echo "No file uploaded or upload error.";
    }
}

$conn->close();
?>

