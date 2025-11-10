<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database connection settings
$servername = "localhost";
$username = "root";
$password = "Rootpass123!@#";
$dbname = "central_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Path to the CSV file
$csvFile = 'uploads/yourfile.csv'; // Replace 'yourfile.csv' with the actual file name
if (!file_exists($csvFile)) {
    die("CSV file not found.");
}

$successCount = 0; // Track the number of successful inserts

// Open the CSV file and process it
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    $header = fgetcsv($handle); // Read the header row

    // Define which columns you want to import (modify based on your CSV structure)
    $dateColumns = array_slice($header, 3); // Assuming dates start from the 4th column

    // Prepare the SQL statement for checking duplicates
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM DecSched WHERE tl = ? AND id_number = ? AND shift_date = ?");

    // Prepare the SQL statement for inserting data
    $insertStmt = $conn->prepare("INSERT INTO DecSched (tl, id_number, agent_list, shift_date, shift) VALUES (?, ?, ?, ?, ?)");

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Retrieve main columns for each row
        $tl = $data[0];
        $idNumber = $data[1];
        $agentList = $data[2];

        // Loop through each date column
        foreach ($dateColumns as $index => $date) {
            if (!empty($date) && preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
                $formattedDate = DateTime::createFromFormat('n/j/Y', $date);
                if ($formattedDate) {
                    $formattedDate = $formattedDate->format('Y-m-d');
                } else {
                    echo "Invalid date conversion for: $date<br>";
                    continue;
                }

                // Check if the shift column exists
                if (isset($data[3 + $index])) {
                    $shift = $data[3 + $index];
                } else {
                    echo "Warning: No shift data for date: $formattedDate<br>";
                    continue;
                }

                // Check if the record already exists in the database
                $checkStmt->bind_param("sss", $tl, $idNumber, $formattedDate);
                $checkStmt->execute();
                $checkStmt->store_result(); // Store the result to clear the statement
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->free_result(); // Free the result set to avoid "Commands out of sync" error

                // If the record does not exist, insert it
                if ($count == 0) {
                    // Bind and execute the insert statement
                    $insertStmt->bind_param("sssss", $tl, $idNumber, $agentList, $formattedDate, $shift);
                    if ($insertStmt->execute()) {
                        $successCount++; // Increment on a successful insert
                    } else {
                        echo "Error: " . $insertStmt->error . "<br>";
                    }
                } else {
                    echo "Duplicate entry for tl: $tl, id_number: $idNumber, shift_date: $formattedDate. Skipping insert.<br>";
                }
            } else {
                echo "Invalid date format: $date<br>";
            }
        }
    }

    // Close file handle
    fclose($handle);

    // Close the prepared statements
    $checkStmt->close();
    $insertStmt->close();

    // Display success message if any rows were inserted
    if ($successCount > 0) {
        echo "<p>Successfully inserted $successCount rows into the database.</p>";
    } else {
        echo "<p>No rows were inserted.</p>";
    }
} else {
    echo "Error opening CSV file.";
}

// Close the database connection
$conn->close();
?>

