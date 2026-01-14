<?php
// Path to upload folder
$targetDir = "/var/www/html/cohere_dashboard/uploads/";
$allowedTypes = ["text/csv", "application/csv", "text/plain"];  // Allow only CSV

// Check if the form is submitted and file is uploaded
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csvFile"])) {
    // Get file details
    $fileName = $_FILES["csvFile"]["name"];
    $fileTmpName = $_FILES["csvFile"]["tmp_name"];
    $fileSize = $_FILES["csvFile"]["size"];
    $fileError = $_FILES["csvFile"]["error"];
    $fileType = $_FILES["csvFile"]["type"];
    
    // Validate file type (must be CSV)
    if (!in_array($fileType, $allowedTypes)) {
        echo "Only CSV files are allowed.";
        exit;
    }
    
    // Check if there were any errors during file upload
    if ($fileError === UPLOAD_ERR_OK) {
        // Generate a unique name for the file to avoid conflicts
        $newFileName = uniqid("", true) . "_" . basename($fileName);
        $targetFilePath = $targetDir . $newFileName;

        // Move the uploaded file to the target directory
        if (move_uploaded_file($fileTmpName, $targetFilePath)) {
            echo "File uploaded successfully!<br>";
            echo "File saved as: " . $newFileName . "<br>";

            // Process CSV to JSON (Keycloak format)
            processCSV($targetFilePath);
        } else {
            echo "Error uploading the file.";
        }
    } else {
        echo "Error during file upload: " . $fileError;
    }
} else {
    echo "No file uploaded or invalid request.";
}

// Function to process CSV file and convert it to JSON for Keycloak
function processCSV($filePath) {
    // Open the CSV file
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        $users = [];
        
        // Read the CSV header (optional) and data
        $header = fgetcsv($handle);  // Skip the first line (header)
        
        // Loop through each row of the CSV
        while (($row = fgetcsv($handle)) !== FALSE) {
            // Ensure that there are 4 columns (Name, Email, Username, Password)
            if (count($row) == 4) {
                list($name, $email, $username, $password) = $row;
                
                // Split the name into first and last names
                $nameParts = explode(" ", $name);
                $firstName = $nameParts[0];
                $lastName = isset($nameParts[1]) ? $nameParts[1] : "";  // Handle single name case
                
                // Create a user object for Keycloak
                $user = [
                    "username" => $email,
                    "firstName" => $firstName,
                    "lastName" => $lastName,
                    "email" => $email,
                    "enabled" => true,
                    "emailVerified" => false,
                    "attributes" => [],
                    "requiredActions" => ["VERIFY_EMAIL", "UPDATE_PASSWORD"],
                    "credentials" => [
                        [
                            "type" => "password",
                            "value" => $password
                        ]
                    ]
                ];
                $users[] = $user;
            }
        }

        fclose($handle);  // Close the CSV file

        // Convert the users array to JSON and save to a file
        $jsonFileName = "/var/www/html/cohere_dashboard/uploads/users_" . uniqid() . ".json";
        file_put_contents($jsonFileName, json_encode($users, JSON_PRETTY_PRINT));
        echo "Users have been converted to JSON. <a href='$jsonFileName'>Download JSON File</a>";
    } else {
        echo "Failed to open CSV file.";
    }
}
?>
