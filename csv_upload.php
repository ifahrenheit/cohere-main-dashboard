<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV to JSON Upload</title>
</head>
<body>

<h2>Upload CSV to Convert to JSON for Keycloak</h2>

<!-- Form to upload the CSV file -->
<form action="upload_csv.php" method="POST" enctype="multipart/form-data">
    <label for="csvFile">Select CSV File:</label>
    <input type="file" name="csvFile" id="csvFile" required>
    <br><br>
    <button type="submit" name="submit">Upload CSV and Convert to JSON</button>
</form>

</body>
</html>
