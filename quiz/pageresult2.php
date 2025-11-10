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
    echo '<script>setTimeout(() => { window.location.href=\"/login.php\"; }, 5000);</script>';
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
      font-family: Arial, sans-serif;
      background: #f4f4f4;
      padding: 20px;
      margin: 0;
    }
    h1 {
      margin-bottom: 15px;
      color: #333;
      text-align: center;
    }
    #controls {
      margin-bottom: 20px;
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 10px;
      align-items: center;
    }
    select, input, button {
      padding: 6px 10px;
      font-size: 14px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
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
    .pass { background-color: #d4edda !important; }
    .fail { background-color: #f8d7da !important; }
    .supervisor-row {
      background-color: #e8e8e8 !important;
      font-weight: bold;
    }
    .back-link {
      display: inline-block;
      margin-bottom: 15px;
      text-decoration: none;
      color: #2c3e50;
      font-weight: bold;
    }
    .back-link:hover {
      text-decoration: underline;
    }
    #dateRangeControls {
      display: none;
      gap: 5px;
      align-items: center;
    }
  </style>
</head>
<body>

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
      <button id="downloadBtn">⬇️ Download CSV</button>
    </div>
  </div>

  <table id="trackerTable">
    <thead></thead>
    <tbody></tbody>
  </table>

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
        const periods = [...new Set(data.map(r => r.period))].sort();
        const supervisors = [...new Set(data.map(r => r.supervisor_email))];

        const thead = document.querySelector("#trackerTable thead");
        const tbody = document.querySelector("#trackerTable tbody");
        thead.innerHTML = "";
        tbody.innerHTML = "";

        if (!data.length) {
          tbody.innerHTML = "<tr><td colspan='99'>No data found.</td></tr>";
          return;
        }

        // header
        let header = "<tr><th>Supervisor</th><th>Agent</th>";
        periods.forEach(p => header += `<th>${p}</th>`);
        header += "</tr>";
        thead.innerHTML = header;

        supervisors.forEach(sup => {
          let namePart = sup ? sup.split("@")[0] : "";
          let name = namePart ? namePart.split(".")[0] : "";
          name = name.charAt(0).toUpperCase() + name.slice(1);

          let supRow = `<tr class="supervisor-row"><td colspan="${periods.length+2}">Team ${name}</td></tr>`;
          tbody.innerHTML += supRow;

          let agents = [...new Set(data.filter(r => r.supervisor_email === sup).map(r => r.fullname))];
          agents.sort((a, b) => a.localeCompare(b));

          agents.forEach(agent => {
            let row = `<tr><td>${name}</td><td>${agent}</td>`;
            periods.forEach(p => {
              const record = data.find(r => r.supervisor_email === sup && r.fullname === agent && r.period === p);
              if (record) {
                const cls = parseFloat(record.accuracy_percent) >= 70 ? "pass" : "fail";
                row += `<td class="${cls}">${record.accuracy_percent}%</td>`;
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

  // 🔄 View mode switch
  document.getElementById("viewMode").addEventListener("change", (e) => {
    const view = e.target.value;
    const dateControls = document.getElementById("dateRangeControls");

    if (view === "daily") {
      dateControls.style.display = "flex";
    } else {
      dateControls.style.display = "none";
    }

    loadData(view);
  });

  // 🔍 Date range filter (only for daily)
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

  // ⬇️ CSV download
  document.getElementById("downloadBtn").addEventListener("click", () => {
    const view = document.getElementById("viewMode").value;
    const start = document.getElementById("startDate").value;
    const end = document.getElementById("endDate").value;

    let url = "/quiz/fetch_results_timeseries.php?view=" + view;
    if (view === "daily") {
      if (start) url += "&start_date=" + start;
      if (end) url += "&end_date=" + end;
    }

    fetch(url)
      .then(res => res.json())
      .then(data => {
        if (!data.length) return alert("No data to download.");
        const periods = [...new Set(data.map(r => r.period))].sort();
        let csv = ["Supervisor,Agent," + periods.join(",")];
        const supervisors = [...new Set(data.map(r => r.supervisor_email))];

        supervisors.forEach(sup => {
          let agents = [...new Set(data.filter(r => r.supervisor_email === sup).map(r => r.fullname))];
          agents.sort((a, b) => a.localeCompare(b));
          agents.forEach(agent => {
            let row = [sup, agent];
            periods.forEach(p => {
              const record = data.find(r => r.supervisor_email === sup && r.fullname === agent && r.period === p);
              row.push(record ? record.accuracy_percent : "-");
            });
            csv.push(row.join(","));
          });
        });

        const blob = new Blob([csv.join("\n")], { type: "text/csv" });
        const urlBlob = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = urlBlob;
        a.download = `performance_tracker_${view}_${new Date().toISOString().split("T")[0]}.csv`;
        a.click();
        URL.revokeObjectURL(urlBlob);
      });
  });

  // Load default daily view
  document.getElementById("dateRangeControls").style.display = "flex";
  loadData("daily");
  </script>

</body>
</html>
