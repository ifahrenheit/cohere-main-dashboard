<?php
session_start();

// Roles with access
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver'];

// ✅ Check role OR supervisor OR QA
if (
    !isset($_SESSION['role']) ||
    (
        !in_array($_SESSION['role'], $allowed_roles) &&
        empty($_SESSION['is_supervisor']) &&
        empty($_SESSION['is_qa'])
    )
) {
    echo "<h2>Access denied. You do not have permission to view this page.</h2>";
    echo "<p>Redirecting to login page in 5 seconds...</p>";
    echo '<meta http-equiv="refresh" content="5;url=/login.php">';
    echo '<script>setTimeout(() => { window.location.href="/login.php"; }, 5000);</script>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Quiz Results</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #f4f4f4;
      font-family: Arial, sans-serif;
    }
    .container {
      margin-top: 30px;
    }
    #controls {
      margin: 15px 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    #searchInput {
      padding: 8px;
      width: 250px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    #exportBtn {
      padding: 8px 15px;
      background: #2c3e50;
      color: #fff;
      border: none;
      cursor: pointer;
      border-radius: 4px;
    }
    #exportBtn:hover { background: #1a252f; }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      margin-top: 10px;
    }
    th, td {
      padding: 12px 15px;
      border: 1px solid #ddd;
      text-align: left;
      cursor: pointer;
    }
    th {
      background-color: #2c3e50;
      color: white;
    }
    tr:nth-child(even) { background-color: #f9f9f9; }
    tr:hover { background-color: #f1f1f1; }
    a.view-link {
      color: #2980b9;
      text-decoration: none;
    }
    a.view-link:hover { text-decoration: underline; }
    .pass { background-color: #d4edda !important; }
    .fail { background-color: #f8d7da !important; }
    #summary {
      margin-top: 20px;
      font-weight: bold;
      text-align: center;
    }
    tfoot td {
      font-weight: bold;
      background: #e9ecef;
    }
  </style>
</head>
<body>

<!-- ✅ Navigation Tabs -->
<nav class="mt-3 sticky-top bg-white shadow-sm">
  <ul class="nav nav-tabs justify-content-center">
    <li class="nav-item">
      <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'pageresult.php' ? 'active' : '' ?>" href="pageresult.php">
        📊 Quiz Results
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'performance_tracker.php' ? 'active' : '' ?>" href="performance_tracker.php">
        📈 Performance Tracker
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'questions_performance.php' ? 'active' : '' ?>" href="questions_performance.php">
        🧩 Questions Performance
      </a>
    </li>
  </ul>
</nav>

<div class="container">
  <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
    <h1 class="mb-0">📊 Quiz Results</h1>
    <a href="/dashboard.php" class="btn btn-secondary btn-sm">⬅ Back to Dashboard</a>
  </div>

  <div id="controls">
    <input type="text" id="searchInput" placeholder="Search results...">
    <button id="exportBtn">Export to CSV</button>
  </div>

  <table id="resultsTable" class="table table-bordered table-hover">
    <thead class="table-dark">
      <tr>
        <th>Company ID</th>
        <th>Full Name</th>
        <th>Email</th>
        <th>Total Questions</th>
        <th>Correct</th>
        <th>Score (%)</th>
        <th>Duration</th>
        <th>View</th>
      </tr>
    </thead>
    <tbody></tbody>
    <tfoot>
      <tr>
        <td colspan="3">Grand Totals</td>
        <td id="grandQuestions">0</td>
        <td id="grandCorrect">0</td>
        <td id="avgScore">0%</td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>

  <div id="summary">Loading summary...</div>
</div>

<script>
  function formatDuration(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}m ${secs}s`;
  }

  // Fetch results from PHP
  fetch('fetch_results.php')
    .then(response => response.json())
    .then(data => {
      const tbody = document.querySelector('#resultsTable tbody');
      tbody.innerHTML = '';

      let totalUsers = data.length;
      let passed = 0;
      let totalQuestions = 0;
      let totalCorrect = 0;
      let totalScore = 0;

      data.forEach(row => {
        const tr = document.createElement('tr');
        if (row.score_percentage >= 70) {
          tr.classList.add('pass');
          passed++;
        } else {
          tr.classList.add('fail');
        }

        tr.innerHTML = `
          <td>${row.companyid}</td>
          <td>${row.fullname}</td>
          <td>${row.email}</td>
          <td>${row.total_questions}</td>
          <td>${row.total_correct}</td>
          <td>${row.score_percentage}%</td>
          <td>${row.duration_seconds > 0 ? formatDuration(row.duration_seconds) : 'N/A'}</td>
          <td><a class="view-link" href="view_answers.php?companyid=${encodeURIComponent(row.companyid)}" target="_blank">View</a></td>
        `;
        tbody.appendChild(tr);

        totalQuestions += parseInt(row.total_questions);
        totalCorrect += parseInt(row.total_correct);
        totalScore += parseFloat(row.score_percentage);
      });

      document.getElementById('grandQuestions').innerText = totalQuestions;
      document.getElementById('grandCorrect').innerText = totalCorrect;
      document.getElementById('avgScore').innerText = (totalScore / totalUsers).toFixed(1) + "%";
      document.getElementById('summary').innerText =
        `Total Users: ${totalUsers} | Passed: ${passed} | Failed: ${totalUsers - passed}`;
    })
    .catch(error => {
      console.error('Error loading quiz results:', error);
      document.querySelector('#resultsTable tbody').innerHTML =
        '<tr><td colspan="8">Failed to load results.</td></tr>';
      document.getElementById('summary').innerText = 'No summary available.';
    });

  // Search filter
  document.getElementById('searchInput').addEventListener('keyup', function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#resultsTable tbody tr');
    rows.forEach(row => {
      row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
    });
  });

  // Column sorting
  let sortDirections = {};
  document.querySelectorAll('#resultsTable th').forEach((header, index) => {
    header.addEventListener('click', () => {
      const tbody = document.querySelector('#resultsTable tbody');
      const rows = Array.from(tbody.querySelectorAll('tr'));
      sortDirections[index] = !sortDirections[index];
      const ascending = sortDirections[index];
      const sorted = rows.sort((a, b) => {
        let valA = a.cells[index].innerText.replace('%','').trim();
        let valB = b.cells[index].innerText.replace('%','').trim();
        if (!isNaN(valA) && !isNaN(valB)) {
          return ascending ? (valA - valB) : (valB - valA);
        }
        return ascending 
          ? valA.localeCompare(valB) 
          : valB.localeCompare(valA);
      });
      tbody.innerHTML = '';
      tbody.append(...sorted);
    });
  });

  // Export to CSV
  document.getElementById('exportBtn').addEventListener('click', () => {
    const rows = document.querySelectorAll('#resultsTable tr');
    const csv = Array.from(rows).map(row =>
      Array.from(row.cells).map(cell => `"${cell.innerText}"`).join(',')
    ).join('\n');

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'quiz_results.csv';
    a.click();
    window.URL.revokeObjectURL(url);
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
