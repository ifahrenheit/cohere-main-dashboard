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
  <title>Performance Tracker</title>
<style>
  body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f5f6fa;
    margin: 0;
    padding: 30px;
  }

  .container {
    width: 95%;
    margin: 0 auto;
  }

  .back-link {
    display: block;
    text-align: center;
    margin-bottom: 20px;
    color: #2c3e50;
    text-decoration: none;
    font-weight: bold;
  }

  .back-link:hover {
    text-decoration: underline;
  }

  h1 {
    text-align: center;
    color: #2c3e50;
    margin-bottom: 20px;
  }

  #controls {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
  }

  select, input, button {
    padding: 6px 10px;
    font-size: 14px;
  }

  /* ✅ Table */
  table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
  }

  th, td {
    border: 1px solid #ddd;
    padding: 8px 10px;
    text-align: center;
    white-space: nowrap;
  }

  th {
    background-color: #2c3e50;
    color: #fff;
    font-weight: 600;
  }

  tr:nth-child(even) {
    background: #f9f9f9;
  }

  /* ✅ Left-align agent name */
  td:nth-child(2),
  th:nth-child(2) {
    text-align: left;
    padding-left: 15px;
  }

  /* ✅ Table container scrollable if too wide */
  .table-container {
    overflow-x: auto;
    margin-top: 10px;
  }

  /* ✅ Highlight rows on hover */
  tr:hover {
    background-color: #f1f1f1;
  }

  /* ✅ Performance colors */
  .pass { background-color: #d4edda !important; }
  .fail { background-color: #f8d7da !important; }

  /* ✅ Supervisor row always left-aligned */
  .supervisor-row {
    background-color: #e8e8e8 !important;
    font-weight: bold;
    text-align: left !important;
  }

  #dateRangeControls {
    display: flex;
    gap: 5px;
    align-items: center;
  }

  /* ✅ Modal styles */
  #answerModal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.6);
  }

  #answerModalContent {
    background: #fff;
    margin: 5% auto;
    padding: 20px;
    width: 80%;
    max-height: 80vh;
    overflow-y: auto;
    border-radius: 8px;
  }

  #closeModal {
    float: right;
    cursor: pointer;
    color: #555;
    font-size: 18px;
  }

  iframe {
    width: 100%;
    height: 70vh;
    border: none;
  }
</style>
</head>
<body>

<div class="container">
  <a href="/dashboard.php" class="back-link">← Back to Dashboard</a>
  <h1>Performance Tracker</h1>

  <div id="controls">
    <div>
      <label for="viewMode">View:</label>
      <select id="viewMode">
        <option value="daily" selected>Daily (Date Range)</option>
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
      </select>
    </div>

    <div id="dateRangeControls">
      <label for="startDate">Start:</label>
      <input type="date" id="startDate">
      <label for="endDate">End:</label>
      <input type="date" id="endDate">
      <button id="filterBtn">🔍 Apply</button>
    </div>

    <div>
      <input type="text" id="searchInput" placeholder="Search agent/supervisor...">
     <!-- <button id="downloadBtn">⬇️ Download CSV</button> -->
    </div>
  </div>

  <table id="trackerTable">
    <thead></thead>
    <tbody></tbody>
  </table>
</div>

<!-- ✅ Modal -->
<div id="answerModal">
  <div id="answerModalContent">
    <span id="closeModal">&times;</span>
    <iframe id="answerFrame"></iframe>
  </div>
</div>

<script>
function loadData(view="daily", startDate="", endDate="") {
  let url = "/quiz/fetch_results_timeseries.php?view=" + view;
  if (view === "daily") {
    if (startDate) url += "&start_date=" + startDate;
    if (endDate) url += "&end_date=" + endDate;
  }

  fetch(url)
    .then(res => res.json())
    .then(data => {
      const thead = document.querySelector("#trackerTable thead");
      const tbody = document.querySelector("#trackerTable tbody");
      thead.innerHTML = "";
      tbody.innerHTML = "";

      if (!data.length) {
        tbody.innerHTML = "<tr><td colspan='99'>No data found.</td></tr>";
        return;
      }

      const periods = [...new Set(data.map(r => r.period))].sort();
      const supervisors = [...new Set(data.map(r => r.supervisor_email))].sort();

      let header = "<tr><th>Agent ID</th><th>Agent Name</th>";
      periods.forEach(p => header += `<th>${p}</th>`);
      header += "</tr>";
      thead.innerHTML = header;

      supervisors.forEach(sup => {
        let namePart = sup ? sup.split("@")[0] : "";
        let name = namePart ? namePart.split(".")[0] : "";
        name = name.charAt(0).toUpperCase() + name.slice(1);

        let supRow = `<tr class="supervisor-row"><td colspan="${periods.length+2}">Team ${name}</td></tr>`;
        tbody.innerHTML += supRow;

        let agentRecords = data.filter(r => r.supervisor_email === sup);
        let agents = [...new Set(agentRecords.map(r => r.fullname))].sort((a,b)=>a.localeCompare(b));

        agents.forEach(agent => {
          let recordSample = agentRecords.find(r => r.fullname === agent);
          let companyId = recordSample ? (recordSample.companyid || '-') : '-';
          let row = `<tr><td>${companyId}</td><td>${agent}</td>`;

          periods.forEach(p => {
            const record = data.find(r => r.supervisor_email === sup && r.fullname === agent && r.period === p);
            if (record) {
              const cls = parseFloat(record.accuracy_percent) >= 70 ? "pass" : "fail";
              const link = `/quiz/view_answers.php?companyid=${encodeURIComponent(record.companyid)}&date=${encodeURIComponent(p)}`;
              row += `<td class="${cls}">
                        <a href="#" 
                           onclick="openModal('${link}'); return false;" 
                           style="color: #003366; text-decoration: none; font-weight: bold;">
                           ${record.accuracy_percent}%
                        </a>
                      </td>`;
            } else {
              row += "<td>-</td>";
            }
          });

          row += "</tr>";
          tbody.innerHTML += row;
        });
      });
    })
    .catch(err => {
      console.error("Error loading data:", err);
      document.querySelector("#trackerTable tbody").innerHTML =
        "<tr><td colspan='99'>Failed to load results.</td></tr>";
    });
}

// ✅ Open Modal
function openModal(url) {
  const modal = document.getElementById("answerModal");
  const iframe = document.getElementById("answerFrame");
  iframe.src = url;
  modal.style.display = "block";
}

// ✅ Close Modal
document.getElementById("closeModal").addEventListener("click", () => {
  document.getElementById("answerModal").style.display = "none";
  document.getElementById("answerFrame").src = "";
});

window.onclick = function(event) {
  const modal = document.getElementById("answerModal");
  if (event.target == modal) {
    modal.style.display = "none";
    document.getElementById("answerFrame").src = "";
  }
};

// 🔄 Default last 14 days
function getDefaultDates() {
  const end = new Date();
  const start = new Date();
  start.setDate(end.getDate() - 13); // 14-day range

  const format = d => d.toISOString().split("T")[0];
  return { start: format(start), end: format(end) };
}

document.getElementById("filterBtn").addEventListener("click", () => {
  const start = document.getElementById("startDate").value;
  const end = document.getElementById("endDate").value;
  loadData("daily", start, end);
});

// 🔍 Search filter
document.getElementById("searchInput").addEventListener("keyup", function() {
  const filter = this.value.toLowerCase();
  const rows = document.querySelectorAll("#trackerTable tbody tr");
  rows.forEach(row => {
    row.style.display = row.innerText.toLowerCase().includes(filter) ? "" : "none";
  });
});

// ✅ Initialize with default 14 days
window.addEventListener("DOMContentLoaded", () => {
  const { start, end } = getDefaultDates();
  document.getElementById("startDate").value = start;
  document.getElementById("endDate").value = end;
  loadData("daily", start, end);
});
</script>
</body>
</html>
