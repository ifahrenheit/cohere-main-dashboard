<?php

// Set the path to your CSV file
$csvFile = 'users.csv'; // Make sure this is the correct path to your CSV file
$jsonFile = 'users.json'; // The JSON file to be generated

// Open the CSV file
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    // Read the headers from the CSV
    $headers = fgetcsv($handle);

    // Prepare the array to hold user data
    $users = array();

    // Read each row of the CSV and convert to JSON structure
    while (($row = fgetcsv($handle)) !== FALSE) {
        $user = array(
            "username" => $row[1],  // Assuming email is the second column
            "firstName" => explode(' ', $row[0])[0],  // First name from the Name column
            "lastName" => implode(' ', array_slice(explode(' ', $row[0]), 1)), // Last name from the Name column
            "email" => $row[1],  // Email from the second column
            "enabled" => true,
            "emailVerified" => false,
            "attributes" => new stdClass(),  // Empty object for attributes
            "requiredActions" => ["VERIFY_EMAIL", "UPDATE_PASSWORD"],  // Required actions for Keycloak
            "credentials" => [
                [
                    "type" => "password",
                    "value" => $row[3],  // Default password from the CSV
                    "temporary" => false
                ]
            ]
        );

        $users[] = $user;
    }

    // Close the CSV file
    fclose($handle);

    // Write the JSON data to a file
    file_put_contents($jsonFile, json_encode($users, JSON_PRETTY_PRINT));

    echo "CSV to JSON conversion completed! The JSON is saved as '$jsonFile'.\n";
} else {
    echo "Unable to open CSV file.";
}

?>
