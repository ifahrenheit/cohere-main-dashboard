<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View All Requests</title>
  <link rel="stylesheet" href="style.css">
  <!-- Optionally include Bootstrap CSS if using Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Optional custom styles for the nav bar */
    .navbar {
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-light bg-light px-3">
    <a class="navbar-brand" href="#">Requests Dashboard</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#requestsNav"
            aria-controls="requestsNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="requestsNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" href="display_ot.php">Overtime Requests</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="display_cws.php">Change Work Schedule Requests</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="display_fts.php">FTS Requests</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="display_rdwork.php">RD Work Requests</a>
        </li>
      </ul>
    </div>
  </nav>

  <!-- Main Content or additional links can be added here -->
  <div class="container">
    <h1>Welcome to the Requests Dashboard</h1>
    <p>Use the navigation bar above to check all the request pages.</p>
  </div>

  <!-- Optionally include Bootstrap JS if using Bootstrap -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

