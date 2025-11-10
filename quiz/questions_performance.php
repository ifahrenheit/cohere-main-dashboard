<?php
session_start();

// ✅ Access control (same logic as other dashboard pages)
$allowed_roles = ['Admin', 'Manager', 'Director', 'SOM Approver', 'Supervisor', 'QA'];

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
    echo '<script>setTimeout(() => { window.location.href=\"/login.php\"; }, 5000);</script>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Question Performance Tracker</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f4f4f4;
      padding: 20px;
      margin: 0;
    }
    h1 {
      margin-bottom: 15px;
      color: #333;
    }
    #controls {
      margin-bottom: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    select, #searchInput, button {
      padding: 6px 10px;
      font-size: 14px;
    }
    #searchInput {
      width: 250px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      margin-top: 10px;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 8px;
      text-align: center;
    }
    th {
      background-color: #2c3e50;
      color: white;
    }
    tr:nth-child(even) {
      background: #f9f9f9;
    }
    .low { background-color: #f8d7da !important; }
    .high { background-color: #d4edda !important; }
    .loading {
      text-align: center;
      font-style: italic;
      color: #666;
    }
  </style>
</head>
<body>

  <h1>Question Performance Tracker</h1>

  <div id="controls">
    <div>
      <label for="viewMode">View:</label>
      <select id="viewMode">
        <option value="daily" selected>Daily (last 14 days)</option>
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
      </select>
    </div>
    <div>
      <input type="text" id="searchInput" placeholder="Search question...">
      <button id="downloadBtn">⬇️ Download CSV</button>
    </div>
  </div>

  <table id="questionTable">
    <thead>
      <tr><th>Loading...</th></tr>
    </thead>
    <tbody class="loading"><tr><td>Loading question data...</td></tr></tbody>
  </table>

  <script>
async function loadQuestionData(view = "daily") {
  const table = document.querySelector("#questionTable");
  const thead = table.querySelector("thead");
  const tbody = table.querySelector("tbody");

  tbody.innerHTML = "<tr><td colspan='99'>Loading...</td></tr>";

  try {
    const res = await fetch(`/quiz/fetch_question_performance.php?view=${view}`);
    const data = await res.json();

    if (!data.length) {
      tbody.innerHTML = "<tr><td colspan='99'>No data found for this view.</td></tr>";
      thead.innerHTML = "<tr><th>Question</th></tr>";
      return;
    }

    // Get unique sorted periods (dates/weeks/months)
    const periods = [...new Set(data.map(r => r.period))].sort();

    // Build table header
    let header = "<tr><th>Question</th>";
    periods.forEach(p => { header += `<th>${p}</th>`; });
    header += "</tr>";
    thead.innerHTML = header;
    tbody.innerHTML = "";

    // Get unique questions
    const questions = [...new Set(data.map(r => r.question_text))];

    // Create table rows
    questions.forEach(q => {
      let row = `<tr><td style='text-align:left'>${q}</td>`;
      periods.forEach(p => {
        const correct = data.find(r => r.question_text === q && r.period === p && r.is_correct == 1);
        const incorrect = data.find(r => r.question_text === q && r.period === p && r.is_correct == 0);

        const correctCount = correct ? parseInt(correct.count) : 0;
        const incorrectCount = incorrect ? parseInt(incorrect.count) : 0;
        const total = correctCount + incorrectCount;

        const accuracy = total > 0 ? (correctCount / total) * 100 : 0;
        const cls = accuracy < 50 ? "low" : "high";

        row += `<td class="${cls}">${correctCount}/${total}</td>`;
      });
      row += "</tr>";
      tbody.innerHTML += row;
    });

  } catch (err) {
    console.error("Error loading question data:", err);
    tbody.innerHTML = "<tr><td colspan='99'>Failed to load question performance.</td></tr>";
  }
}

// Switch view
document.getElementById("viewMode").addEventListener("change", (e) => {
  loadQuestionData(e.target.value);
});

// Search filter
document.getElementById("searchInput").addEventListener("keyup", function() {
  const filter = this.value.toLowerCase();
  document.querySelectorAll("#questionTable tbody tr").forEach(row => {
    row.style.display = row.innerText.toLowerCase().includes(filter) ? "" : "none";
  });
});

// CSV download
document.getElementById("downloadBtn").addEventListener("click", async () => {
  const view = document.getElementById("viewMode").value;
  try {
    const res = await fetch(`/quiz/fetch_question_performance.php?view=${view}`);
    const data = await res.json();

    if (!data.length) {
      alert("No data to download.");
      return;
    }

    const periods = [...new Set(data.map(r => r.period))].sort();
    let csv = ["Question," + periods.join(",")];
    const questions = [...new Set(data.map(r => r.question_text))];

    questions.forEach(q => {
      let row = [q];
      periods.forEach(p => {
        const correct = data.find(r => r.question_text === q && r.period === p && r.is_correct == 1);
        const incorrect = data.find(r => r.question_text === q && r.period === p && r.is_correct == 0);
        const correctCount = correct ? parseInt(correct.count) : 0;
        const incorrectCount = incorrect ? parseInt(incorrect.count) : 0;
        const total = correctCount + incorrectCount;
        row.push(`${correctCount}/${total}`);
      });
      csv.push(row.join(","));
    });

    const blob = new Blob([csv.join("\n")], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `question_performance_${view}_${new Date().toISOString().split("T")[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  } catch (err) {
    console.error("Download error:", err);
    alert("Failed to download CSV.");
  }
});

// Default load
loadQuestionData();
</script>


</body>
</html>
