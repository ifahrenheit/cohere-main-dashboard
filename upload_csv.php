<?php
session_start(); // Start the session

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['file']['tmp_name'];
        $originalFileName = $_FILES['file']['name'];

        // Sanitize the file name
        $originalFileName = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $originalFileName);

        $uploadDir = 'uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $destination = $uploadDir . $originalFileName;

        // Ensure unique filename by appending timestamp if file exists
        if (file_exists($destination)) {
            $fileInfo = pathinfo($originalFileName);
            $destination = $uploadDir . $fileInfo['filename'] . "_" . time() . "." . $fileInfo['extension'];
        }

        if (move_uploaded_file($fileTmpPath, $destination)) {
            // Make the file executable
            chmod($destination, 0755);

            // Set success message
            $_SESSION['success_message'] = "File uploaded successfully as '$originalFileName'!";

            // Redirect to display page
            header("Location: display_csv.php?file=" . urlencode($destination));
            exit();
        } else {
            echo "Error moving the uploaded file.";
        }
    } else {
        echo "No file uploaded or upload error.";
    }
} else {
    echo "Invalid request.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload CSV</title>
</head>
<body>
    <h2>Upload a CSV File</h2>
    <form action="upload_csv.php" method="post" enctype="multipart/form-data">
        <label for="file">Choose CSV file:</label>
        <input type="file" name="file" id="file" accept=".csv" required>
        <br><br>
        <button type="submit">Upload</button>
    </form>
</body>
</html>

