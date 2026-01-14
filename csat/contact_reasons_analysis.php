<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
date_default_timezone_set('Asia/Manila');

// Include database connection
require_once '../config/db_connection.php';

// Get filter parameters
$selectedTeam = isset($_GET['team']) ? $_GET['team'] : 'all';
$selectedWeek = isset($_GET['week']) ? $_GET['week'] : 'all';
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : 'all';
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Build WHERE clause based on filters
$whereConditions = ["1=1"];
$params = [];
$paramTypes = "";

if ($selectedTeam !== 'all') {
    $whereConditions[] = "team_lead = ?";
    $params[] = $selectedTeam;
    $paramTypes .= "s";
}

if ($selectedWeek !== 'all') {
    $whereConditions[] = "week_number = ?";
    $params[] = $selectedWeek;
    $paramTypes .= "i";
}

if ($selectedMonth !== 'all') {
    $whereConditions[] = "month_name = ?";
    $params[] = $selectedMonth;
    $paramTypes .= "s";
}

if ($selectedYear !== 'all') {
    $whereConditions[] = "YEAR(survey_date) = ?";
    $params[] = $selectedYear;
    $paramTypes .= "i";
}

// Date range filter (overrides week/month/year if provided)
if (!empty($startDate)) {
    $whereConditions[] = "survey_date >= ?";
    $params[] = $startDate;
    $paramTypes .= "s";
}

if (!empty($endDate)) {
    $whereConditions[] = "survey_date <= ?";
    $params[] = $endDate;
    $paramTypes .= "s";
}

$whereClause = implode(" AND ", $whereConditions);

// Fetch teams for filter dropdown
$teamsQuery = "SELECT DISTINCT team_lead FROM csat_scores WHERE team_lead IS NOT NULL AND team_lead != '' ORDER BY team_lead";
$teamsResult = $conn->query($teamsQuery);
$teams = [];
while ($row = $teamsResult->fetch_assoc()) {
    $teams[] = $row['team_lead'];
}

// Fetch weeks for filter dropdown
$weeksQuery = "SELECT DISTINCT week_number FROM csat_scores WHERE week_number IS NOT NULL ORDER BY week_number";
$weeksResult = $conn->query($weeksQuery);
$weeks = [];
while ($row = $weeksResult->fetch_assoc()) {
    $weeks[] = $row['week_number'];
}

// Fetch months for filter dropdown
$monthsQuery = "SELECT DISTINCT month_name FROM csat_scores WHERE month_name IS NOT NULL ORDER BY FIELD(month_name, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December')";
$monthsResult = $conn->query($monthsQuery);
$months = [];
while ($row = $monthsResult->fetch_assoc()) {
    $months[] = $row['month_name'];
}

// Fetch years for filter dropdown
$yearsQuery = "SELECT DISTINCT YEAR(survey_date) as year FROM csat_scores WHERE survey_date IS NOT NULL ORDER BY year DESC";
$yearsResult = $conn->query($yearsQuery);
$years = [];
while ($row = $yearsResult->fetch_assoc()) {
    $years[] = $row['year'];
}

// Fetch contact reasons data
$dataQuery = "
    SELECT 
        theme as contact_reason,
        COUNT(*) as total_count,
        SUM(CASE WHEN csat_score IN (1, 2, 3) THEN 1 ELSE 0 END) as detractors,
        SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) as promoters,
        ROUND(
            (SUM(CASE WHEN csat_score IN (4, 5) THEN 1 ELSE 0 END) * 100.0 / 
            NULLIF(COUNT(*), 0)), 
            2
        ) as csat_percentage
    FROM csat_scores
    WHERE $whereClause
        AND theme IS NOT NULL 
        AND theme != ''
    GROUP BY theme
    ORDER BY total_count DESC
";

$stmt = $conn->prepare($dataQuery);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$contactReasons = [];
$detractors = [];
$promoters = [];
$totals = [];
$csatPercentages = [];

while ($row = $result->fetch_assoc()) {
    $contactReasons[] = $row['contact_reason'];
    $detractors[] = (int)$row['detractors'];
    $promoters[] = (int)$row['promoters'];
    $totals[] = (int)$row['total_count'];
    $csatPercentages[] = (float)$row['csat_percentage'];
}

$conn->close();

// Include the header
include 'includes/header.php';
?>

<style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #7c3aed;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #d97706;
            --info-color: #0891b2;
            --dark-bg: #1e293b;
            --card-bg: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
        }

        .container-fluid {
            max-width: 1600px;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .form-select {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 0.625rem 1rem;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }

        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-apply-filter {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0.625rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-apply-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(37, 99, 235, 0.3);
        }

        .btn-reset {
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
            padding: 0.625rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-reset:hover {
            background: var(--border-color);
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .chart-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .icon-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: var(--danger-color);
        }

        .icon-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: var(--success-color);
        }

        .icon-info {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: var(--info-color);
        }

        .icon-warning {
            background: linear-gradient(135deg, #fed7aa, #fdba74);
            color: var(--warning-color);
        }

        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .data-table th {
            background: #0f172a;
            color: white;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.875rem;
            white-space: nowrap;
            position: relative;
        }

        .data-table th:first-child {
            text-align: left;
        }

        .sortable {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }

        .sortable:hover {
            background: #1e3a8a !important;
        }

        .sort-arrows {
            display: inline-flex;
            flex-direction: column;
            margin-left: 0.5rem;
            font-size: 0.7rem;
            line-height: 0.5;
            opacity: 0.5;
        }

        .sortable.sort-asc .sort-arrows .bi-chevron-up,
        .sortable.sort-desc .sort-arrows .bi-chevron-down {
            opacity: 1;
            color: #60a5fa;
        }

        .sort-arrows i {
            display: block;
        }

        .data-table td {
            padding: 0.875rem 1rem;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9375rem;
        }

        .data-table td:first-child {
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
        }

        .data-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:last-child td:first-child {
            border-bottom-left-radius: 12px;
        }

        .data-table tbody tr:last-child td:last-child {
            border-bottom-right-radius: 12px;
        }

        .row-label {
            background: #0f172a !important;
            font-weight: 700;
            color: white !important;
            text-align: center !important;
        }

        .detractors-cell {
            color: var(--danger-color);
            font-weight: 600;
        }

        .promoters-cell {
            color: var(--success-color);
            font-weight: 600;
        }

        .total-cell {
            color: var(--info-color);
            font-weight: 600;
        }

        .percentage-cell {
            font-weight: 700;
        }

        .percentage-high {
            color: var(--success-color);
        }

        .percentage-medium {
            color: var(--warning-color);
        }

        .percentage-low {
            color: var(--danger-color);
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .data-table {
                font-size: 0.8125rem;
            }

            .data-table th,
            .data-table td {
                padding: 0.625rem 0.5rem;
            }
        }
    </style>

    <!-- Page Content -->
    <div class="container-fluid">
        <?php if ($selectedTeam !== 'all' || $selectedWeek !== 'all' || $selectedMonth !== 'all' || $selectedYear !== date('Y') || !empty($startDate) || !empty($endDate)): ?>
        <!-- Active Filters Banner -->
        <div class="content-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7;">
            <strong><i class="bi bi-funnel-fill"></i> Active Filters:</strong>
            <?php if ($selectedTeam !== 'all'): ?>
                <span class="badge bg-info ms-2"><i class="bi bi-people-fill"></i> Team: <?= htmlspecialchars($selectedTeam) ?></span>
            <?php endif; ?>
            <?php if ($selectedWeek !== 'all'): ?>
                <span class="badge bg-primary ms-2"><i class="bi bi-calendar-week"></i> Week: <?= htmlspecialchars($selectedWeek) ?></span>
            <?php endif; ?>
            <?php if ($selectedMonth !== 'all'): ?>
                <span class="badge bg-success ms-2"><i class="bi bi-calendar-month"></i> Month: <?= htmlspecialchars($selectedMonth) ?></span>
            <?php endif; ?>
            <?php if ($selectedYear !== date('Y')): ?>
                <span class="badge bg-secondary ms-2"><i class="bi bi-calendar3"></i> Year: <?= htmlspecialchars($selectedYear) ?></span>
            <?php endif; ?>
            <?php if (!empty($startDate) || !empty($endDate)): ?>
                <span class="badge bg-warning ms-2"><i class="bi bi-calendar-range"></i> 
                    <?= !empty($startDate) ? date('M d, Y', strtotime($startDate)) : '...' ?> - 
                    <?= !empty($endDate) ? date('M d, Y', strtotime($endDate)) : '...' ?>
                </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="content-card">
            <form method="GET" action="" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="filter-label"><i class="bi bi-people-fill"></i> Team</label>
                        <select name="team" class="form-select" id="teamFilter">
                            <option value="all" <?= $selectedTeam === 'all' ? 'selected' : '' ?>>All Teams</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= htmlspecialchars($team) ?>" <?= $selectedTeam === $team ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($team) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="filter-label"><i class="bi bi-calendar-week"></i> Week Number</label>
                        <select name="week" class="form-select" id="weekFilter">
                            <option value="all" <?= $selectedWeek === 'all' ? 'selected' : '' ?>>All Weeks</option>
                            <?php foreach ($weeks as $week): ?>
                                <option value="<?= htmlspecialchars($week) ?>" <?= $selectedWeek == $week ? 'selected' : '' ?>>
                                    Week <?= htmlspecialchars($week) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="filter-label"><i class="bi bi-calendar-month"></i> Month</label>
                        <select name="month" class="form-select" id="monthFilter">
                            <option value="all" <?= $selectedMonth === 'all' ? 'selected' : '' ?>>All Months</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?= htmlspecialchars($month) ?>" <?= $selectedMonth === $month ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($month) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="filter-label"><i class="bi bi-calendar3"></i> Year</label>
                        <select name="year" class="form-select" id="yearFilter">
                            <option value="all" <?= $selectedYear === 'all' ? 'selected' : '' ?>>All Years</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $selectedYear == $year ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="filter-label"><i class="bi bi-calendar-range"></i> Date Range</label>
                        <div class="input-group">
                            <input type="date" class="form-control" name="start_date" id="startDate" value="<?= htmlspecialchars($startDate) ?>" placeholder="Start Date">
                            <input type="date" class="form-control" name="end_date" id="endDate" value="<?= htmlspecialchars($endDate) ?>" placeholder="End Date">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-apply-filter">
                            <i class="bi bi-funnel-fill"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-reset">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Main Data Table -->
        <div class="content-card">
            <div class="chart-title">
                <span class="chart-icon icon-info">
                    <i class="bi bi-table"></i>
                </span>
                Contact Reasons Metrics Overview
            </div>

            <?php if (empty($contactReasons)): ?>
                <div class="no-data">
                    <i class="bi bi-inbox"></i>
                    <h3>No Data Available</h3>
                    <p>Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table" id="contactTable">
                        <thead>
                            <tr>
                                <th style="background: #0f172a;" class="sortable" data-column="0">
                                    Contact Reasons 
                                    <span class="sort-arrows">
                                        <i class="bi bi-chevron-up"></i>
                                        <i class="bi bi-chevron-down"></i>
                                    </span>
                                </th>
                                <th style="background: #0f172a;" class="sortable" data-column="1">
                                    Detractors
                                    <span class="sort-arrows">
                                        <i class="bi bi-chevron-up"></i>
                                        <i class="bi bi-chevron-down"></i>
                                    </span>
                                </th>
                                <th style="background: #0f172a;" class="sortable" data-column="2">
                                    Promoters
                                    <span class="sort-arrows">
                                        <i class="bi bi-chevron-up"></i>
                                        <i class="bi bi-chevron-down"></i>
                                    </span>
                                </th>
                                <th style="background: #0f172a;" class="sortable" data-column="3">
                                    Total (%)
                                    <span class="sort-arrows">
                                        <i class="bi bi-chevron-up"></i>
                                        <i class="bi bi-chevron-down"></i>
                                    </span>
                                </th>
                                <th style="background: #0f172a;" class="sortable" data-column="4">
                                    CSAT%
                                    <span class="sort-arrows">
                                        <i class="bi bi-chevron-up"></i>
                                        <i class="bi bi-chevron-down"></i>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php 
                            $totalDetractors = 0;
                            $totalPromoters = 0;
                            $totalReturns = 0;
                            
                            for ($i = 0; $i < count($contactReasons); $i++): 
                                $totalDetractors += $detractors[$i];
                                $totalPromoters += $promoters[$i];
                                $totalReturns += $totals[$i];
                            endfor;
                            
                            for ($i = 0; $i < count($contactReasons); $i++): 
                                $weightPercentage = $totalReturns > 0 ? round(($totals[$i] / $totalReturns) * 100, 2) : 0;
                            ?>
                            <tr>
                                <td style="text-align: left; font-weight: 600;" data-value="<?= htmlspecialchars($contactReasons[$i]) ?>"><?= htmlspecialchars($contactReasons[$i]) ?></td>
                                <td class="detractors-cell" data-value="<?= $detractors[$i] ?>"><?= number_format($detractors[$i]) ?></td>
                                <td class="promoters-cell" data-value="<?= $promoters[$i] ?>"><?= number_format($promoters[$i]) ?></td>
                                <td class="total-cell" data-value="<?= $totals[$i] ?>">
                                    <?= number_format($totals[$i]) ?> <span style="color: #64748b; font-size: 0.875rem;">(<?= number_format($weightPercentage, 2) ?>%)</span>
                                </td>
                                <td class="percentage-cell <?= $csatPercentages[$i] >= 80 ? 'percentage-high' : ($csatPercentages[$i] >= 60 ? 'percentage-medium' : 'percentage-low') ?>" data-value="<?= $csatPercentages[$i] ?>">
                                    <?= number_format($csatPercentages[$i], 2) ?>%
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <!-- Total Row -->
                            <tr style="background: #0f172a; color: white; font-weight: 700;">
                                <td style="text-align: left;">Total</td>
                                <td><?= number_format($totalDetractors) ?></td>
                                <td><?= number_format($totalPromoters) ?></td>
                                <td><?= number_format($totalReturns) ?> <span style="color: #94a3b8;">(100.00%)</span></td>
                                <td class="<?= ($totalReturns > 0 ? ($totalPromoters / $totalReturns * 100) : 0) >= 80 ? 'percentage-high' : (($totalReturns > 0 ? ($totalPromoters / $totalReturns * 100) : 0) >= 60 ? 'percentage-medium' : 'percentage-low') ?>">
                                    <?= $totalReturns > 0 ? number_format(($totalPromoters / $totalReturns * 100), 2) : '0.00' ?>%
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter reset logic - when one time filter changes, reset others
        document.addEventListener('DOMContentLoaded', function() {
            const weekFilter = document.getElementById('weekFilter');
            const monthFilter = document.getElementById('monthFilter');
            const yearFilter = document.getElementById('yearFilter');
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');

            // When week is selected, reset month and date range
            weekFilter.addEventListener('change', function() {
                if (this.value !== 'all') {
                    monthFilter.value = 'all';
                    startDate.value = '';
                    endDate.value = '';
                }
            });

            // When month is selected, reset week and date range
            monthFilter.addEventListener('change', function() {
                if (this.value !== 'all') {
                    weekFilter.value = 'all';
                    startDate.value = '';
                    endDate.value = '';
                }
            });

            // When year is selected, reset date range (but not week/month)
            yearFilter.addEventListener('change', function() {
                if (this.value !== 'all') {
                    startDate.value = '';
                    endDate.value = '';
                }
            });

            // When date range is used, reset week, month, and year
            startDate.addEventListener('change', function() {
                if (this.value) {
                    weekFilter.value = 'all';
                    monthFilter.value = 'all';
                    yearFilter.value = 'all';
                }
            });

            endDate.addEventListener('change', function() {
                if (this.value) {
                    weekFilter.value = 'all';
                    monthFilter.value = 'all';
                    yearFilter.value = 'all';
                }
            });
        });

        // Sorting functionality
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.getElementById('contactTable');
            if (!table) return;
            
            const headers = table.querySelectorAll('.sortable');
            const tbody = document.getElementById('tableBody');
            
            let currentSort = {
                column: null,
                direction: 'asc'
            };

            headers.forEach(header => {
                header.addEventListener('click', function() {
                    const column = parseInt(this.getAttribute('data-column'));
                    
                    // Determine sort direction
                    if (currentSort.column === column) {
                        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort.direction = 'asc';
                        currentSort.column = column;
                    }
                    
                    // Remove all sort classes
                    headers.forEach(h => {
                        h.classList.remove('sort-asc', 'sort-desc');
                    });
                    
                    // Add sort class to current header
                    this.classList.add(currentSort.direction === 'asc' ? 'sort-asc' : 'sort-desc');
                    
                    // Sort the table
                    sortTable(column, currentSort.direction);
                });
            });

            function sortTable(column, direction) {
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                rows.sort((a, b) => {
                    const aValue = a.children[column].getAttribute('data-value');
                    const bValue = b.children[column].getAttribute('data-value');
                    
                    let aVal, bVal;
                    
                    // Check if values are numbers
                    if (column === 0) {
                        // String comparison for contact reasons
                        aVal = aValue.toLowerCase();
                        bVal = bValue.toLowerCase();
                        
                        if (direction === 'asc') {
                            return aVal > bVal ? 1 : -1;
                        } else {
                            return aVal < bVal ? 1 : -1;
                        }
                    } else {
                        // Numeric comparison
                        aVal = parseFloat(aValue);
                        bVal = parseFloat(bValue);
                        
                        if (direction === 'asc') {
                            return aVal - bVal;
                        } else {
                            return bVal - aVal;
                        }
                    }
                });
                
                // Clear tbody and append sorted rows
                tbody.innerHTML = '';
                rows.forEach(row => tbody.appendChild(row));
            }
        });
    </script>

<?php include 'includes/footer.php'; ?>