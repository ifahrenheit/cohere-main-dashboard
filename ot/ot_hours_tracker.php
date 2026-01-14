<?php
// ot_hours_tracker.php - Admin/Manager Hours Tracker
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
require_once 'config.php';

// ✅ Admin and Manager Only Access
$allowedRoles = ['Admin', 'Manager', 'Director'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    // Capture current page with query string
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /login.php?redirect=$currentUrl");
    exit();
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to first day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default to today
$search_query = $_GET['search'] ?? '';
$approver_filter = $_GET['approver'] ?? []; // Changed from som_filter, now array
$teamlead_filter = $_GET['teamlead'] ?? []; // New Team Lead filter
$ot_type_filter = $_GET['ot_type'] ?? []; // New OT Type filter
$status_filter = $_GET['status'] ?? 'all'; // Default to 'all' (Approved + Pending)

$ot_type_filter = $_GET['ot_type'] ?? []; // New OT Type filter

// Convert to array if needed
if (!is_array($ot_type_filter)) {
    $ot_type_filter = !empty($ot_type_filter) ? [$ot_type_filter] : [];
}

// Convert to array if needed
if (!is_array($approver_filter)) {
    $approver_filter = !empty($approver_filter) ? [$approver_filter] : [];
}
if (!is_array($teamlead_filter)) {
    $teamlead_filter = !empty($teamlead_filter) ? [$teamlead_filter] : [];
}
if (!is_array($ot_type_filter)) {
    $ot_type_filter = !empty($ot_type_filter) ? [$ot_type_filter] : [];
}

// Build the query to get agent hours SUMMARY
// Build the UNION subquery with conditional OT type filter
$union_subquery = "SELECT employee_id, start_time, end_time, status, deleted_at, ot_date, ot_type FROM ot_requests";
if (!empty($ot_type_filter)) {
    $ot_placeholders = implode(',', array_fill(0, count($ot_type_filter), '?'));
    $union_subquery .= " WHERE ot_type COLLATE utf8mb4_unicode_ci IN ($ot_placeholders)";
}
$union_subquery .= "
        UNION ALL
        SELECT employee_id, start_time, end_time, status, deleted_at, rd_date as ot_date, CONCAT('RD_', work_category) as ot_type FROM rd_requests";
if (!empty($ot_type_filter)) {
    $union_subquery .= " WHERE 'RD' COLLATE utf8mb4_unicode_ci IN ($ot_placeholders)";
}

$summary_sql = "
    SELECT 
        e.EmployeeID,
        CONCAT(e.FirstName, ' ', e.LastName) AS agent_name,
        COALESCE(
            (SELECT CONCAT(som.FirstName, ' ', som.LastName) 
             FROM Employees som 
             WHERE som.Email COLLATE utf8mb4_unicode_ci = e.som_email COLLATE utf8mb4_unicode_ci
             LIMIT 1), 
            e.SOM,
            'N/A'
        ) AS som_name,
        e.som_email,
        COALESCE(
            (SELECT CONCAT(sup.FirstName, ' ', sup.LastName) 
             FROM supervisor_mapping sm
             JOIN Employees sup ON sm.supervisor_email COLLATE utf8mb4_unicode_ci = sup.Email COLLATE utf8mb4_unicode_ci
             WHERE sm.agent_email COLLATE utf8mb4_unicode_ci = e.Email COLLATE utf8mb4_unicode_ci
             LIMIT 1), 
            'N/A'
        ) AS team_lead,
        COALESCE(SUM(
            CASE
                WHEN o.end_time < o.start_time THEN
                    TIMESTAMPDIFF(SECOND, o.start_time, ADDTIME(o.end_time, '24:00:00')) / 3600
                ELSE
                    TIMESTAMPDIFF(SECOND, o.start_time, o.end_time) / 3600
            END
        ), 0) AS total_ot_hours,
        COUNT(o.employee_id) AS total_tickets,
        GROUP_CONCAT(DISTINCT o.status ORDER BY o.status SEPARATOR ', ') AS status_list,
        SUM(CASE WHEN o.status COLLATE utf8mb4_unicode_ci = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN o.status COLLATE utf8mb4_unicode_ci = 'Pending' THEN 1 ELSE 0 END) AS pending_count
    FROM Employees e
    LEFT JOIN (
        $union_subquery
    ) o ON CAST(e.EmployeeID AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(o.employee_id AS CHAR) COLLATE utf8mb4_unicode_ci
        AND (
            CASE 
                WHEN ? = 'all' THEN o.status COLLATE utf8mb4_unicode_ci IN ('Approved', 'Pending')
                WHEN ? = 'approved' THEN o.status COLLATE utf8mb4_unicode_ci = 'Approved'
                WHEN ? = 'pending' THEN o.status COLLATE utf8mb4_unicode_ci = 'Pending'
            END
        )
        AND o.deleted_at IS NULL
        AND o.ot_date BETWEEN ? AND ?
        AND (
            CASE
                WHEN o.end_time < o.start_time THEN
                    TIMESTAMPDIFF(SECOND, o.start_time, ADDTIME(o.end_time, '24:00:00')) / 3600
                ELSE
                    TIMESTAMPDIFF(SECOND, o.start_time, o.end_time) / 3600
            END
        ) >= 0.5
    WHERE e.role COLLATE utf8mb4_unicode_ci IN ('Employee', 'Team Lead')
";

$summary_params = [];
$summary_types = "";

// Add OT type filter params first (for both UNION parts if needed)
if (!empty($ot_type_filter)) {
    foreach ($ot_type_filter as $ot_type) {
        $summary_params[] = $ot_type;
        $summary_types .= "s";
    }
    // Add again for the RD part of UNION
    foreach ($ot_type_filter as $ot_type) {
        $summary_params[] = $ot_type;
        $summary_types .= "s";
    }
}

// Add status and date params
$summary_params[] = $status_filter;
$summary_params[] = $status_filter;
$summary_params[] = $status_filter;
$summary_params[] = $start_date;
$summary_params[] = $end_date;
$summary_types .= "sssss";

if (!empty($search_query)) {
    $summary_sql .= " AND (CONCAT(e.FirstName, ' ', e.LastName) COLLATE utf8mb4_unicode_ci LIKE ? 
              OR e.FirstName COLLATE utf8mb4_unicode_ci LIKE ? 
              OR e.LastName COLLATE utf8mb4_unicode_ci LIKE ? 
              OR CAST(e.EmployeeID AS CHAR) COLLATE utf8mb4_unicode_ci LIKE ?)";
    $search_param = "%$search_query%";
    $summary_params[] = $search_param;
    $summary_params[] = $search_param;
    $summary_params[] = $search_param;
    $summary_params[] = $search_param;
    $summary_types .= "ssss";  // Changed from "sss" to "ssss"
}

if (!empty($approver_filter)) {
    $placeholders = implode(',', array_fill(0, count($approver_filter), '?'));
    $summary_sql .= " AND e.som_email COLLATE utf8mb4_unicode_ci IN ($placeholders)";
    foreach ($approver_filter as $email) {
        $summary_params[] = $email;
        $summary_types .= "s";
    }
}

if (!empty($teamlead_filter)) {
    // Subquery to get agents under selected team leads
    $tl_placeholders = implode(',', array_fill(0, count($teamlead_filter), '?'));
    $summary_sql .= " AND e.Email IN (
        SELECT sm.agent_email 
        FROM supervisor_mapping sm 
        WHERE sm.supervisor_email COLLATE utf8mb4_unicode_ci IN ($tl_placeholders)
    )";
    foreach ($teamlead_filter as $tl_email) {
        $summary_params[] = $tl_email;
        $summary_types .= "s";
    }
}

$summary_sql .= " GROUP BY e.EmployeeID, e.FirstName, e.LastName, e.SOM, e.som_email
          HAVING total_ot_hours >= 0.5
          ORDER BY total_ot_hours DESC";

$summary_stmt = $conn->prepare($summary_sql);
$summary_stmt->bind_param($summary_types, ...$summary_params);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();

// Build the query to get detailed OT records (including RD requests)
// ✅ CHANGED: Calculate OT hours as decimal instead of TIME_FORMAT
// Build the query to get detailed OT records (including RD requests)
// ✅ CHANGED: Calculate OT hours as decimal instead of TIME_FORMAT
$sql = "
    SELECT 
        e.EmployeeID as employee_id,
        CONCAT(e.FirstName, ' ', e.LastName) AS agent_name,
        o.ot_date as date,
        o.start_time,
        o.end_time,
        CASE
            WHEN o.end_time < o.start_time THEN
                TIMESTAMPDIFF(SECOND, o.start_time, ADDTIME(o.end_time, '24:00:00')) / 3600
            ELSE
                TIMESTAMPDIFF(SECOND, o.start_time, o.end_time) / 3600
        END AS ot_hours,
        o.ot_type,
        o.regular_rate,
        o.status,
        o.approver_name,
        o.approved_at,
        COALESCE(
            (SELECT CONCAT(som.FirstName, ' ', som.LastName) 
             FROM Employees som 
             WHERE som.Email COLLATE utf8mb4_unicode_ci = e.som_email COLLATE utf8mb4_unicode_ci
             LIMIT 1), 
            e.SOM,
            'N/A'
        ) AS som_name,
        e.som_email,
        'OT' as request_type
    FROM ot_requests o
    JOIN Employees e ON CAST(e.EmployeeID AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(o.employee_id AS CHAR) COLLATE utf8mb4_unicode_ci
    WHERE (
        CASE 
            WHEN ? = 'all' THEN o.status COLLATE utf8mb4_unicode_ci IN ('Approved', 'Pending')
            WHEN ? = 'approved' THEN o.status COLLATE utf8mb4_unicode_ci = 'Approved'
            WHEN ? = 'pending' THEN o.status COLLATE utf8mb4_unicode_ci = 'Pending'
        END
    )
    AND o.deleted_at IS NULL
    AND o.ot_date BETWEEN ? AND ?
    AND e.role COLLATE utf8mb4_unicode_ci IN ('Employee', 'Team Lead')
    AND (
        CASE
            WHEN o.end_time < o.start_time THEN
                TIMESTAMPDIFF(SECOND, o.start_time, ADDTIME(o.end_time, '24:00:00')) / 3600
            ELSE
                TIMESTAMPDIFF(SECOND, o.start_time, o.end_time) / 3600
        END
    ) >= 0.5
    
    UNION ALL
    
    SELECT 
        e.EmployeeID as employee_id,
        CONCAT(e.FirstName, ' ', e.LastName) AS agent_name,
        r.rd_date as date,
        r.start_time,
        r.end_time,
        CASE
            WHEN r.end_time < r.start_time THEN
                TIMESTAMPDIFF(SECOND, r.start_time, ADDTIME(r.end_time, '24:00:00')) / 3600
            ELSE
                TIMESTAMPDIFF(SECOND, r.start_time, r.end_time) / 3600
        END AS ot_hours,
        CONCAT('RD_', r.work_category) as ot_type,
        'N/A' as regular_rate,
        r.status,
        r.approver_name,
        r.approved_at,
        COALESCE(
            (SELECT CONCAT(som.FirstName, ' ', som.LastName) 
             FROM Employees som 
             WHERE som.Email COLLATE utf8mb4_unicode_ci = e.som_email COLLATE utf8mb4_unicode_ci
             LIMIT 1), 
            e.SOM,
            'N/A'
        ) AS som_name,
        e.som_email,
        'RD' as request_type
    FROM rd_requests r
    JOIN Employees e ON CAST(e.EmployeeID AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(r.employee_id AS CHAR) COLLATE utf8mb4_unicode_ci
    WHERE (
        CASE 
            WHEN ? = 'all' THEN r.status COLLATE utf8mb4_unicode_ci IN ('Approved', 'Pending')
            WHEN ? = 'approved' THEN r.status COLLATE utf8mb4_unicode_ci = 'Approved'
            WHEN ? = 'pending' THEN r.status COLLATE utf8mb4_unicode_ci = 'Pending'
        END
    )
    AND r.deleted_at IS NULL
    AND r.rd_date BETWEEN ? AND ?
    AND e.role COLLATE utf8mb4_unicode_ci IN ('Employee', 'Team Lead')
    AND (
        CASE
            WHEN r.end_time < r.start_time THEN
                TIMESTAMPDIFF(SECOND, r.start_time, ADDTIME(r.end_time, '24:00:00')) / 3600
            ELSE
                TIMESTAMPDIFF(SECOND, r.start_time, r.end_time) / 3600
        END
    ) >= 0.5
";

// Add filters - need status filter 3 times for ot_requests and 3 times for rd_requests
$params = [$status_filter, $status_filter, $status_filter, $start_date, $end_date, 
           $status_filter, $status_filter, $status_filter, $start_date, $end_date];
$types = "ssssssssss";

if (!empty($search_query)) {
    $sql = "SELECT * FROM ($sql) as combined 
            WHERE (combined.agent_name COLLATE utf8mb4_unicode_ci LIKE ? 
                   OR CAST(combined.employee_id AS CHAR) COLLATE utf8mb4_unicode_ci LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($approver_filter)) {
    $placeholders = implode(',', array_fill(0, count($approver_filter), '?'));
    if (empty($search_query)) {
        $sql = "SELECT * FROM ($sql) as combined WHERE combined.som_email COLLATE utf8mb4_unicode_ci IN ($placeholders)";
    } else {
        $sql .= " AND combined.som_email COLLATE utf8mb4_unicode_ci IN ($placeholders)";
    }
    foreach ($approver_filter as $email) {
        $params[] = $email;
        $types .= "s";
    }
}

if (!empty($teamlead_filter)) {
    // Get agents under selected team leads
    $tl_placeholders = implode(',', array_fill(0, count($teamlead_filter), '?'));
    $tl_condition = "EXISTS (
        SELECT 1 
        FROM supervisor_mapping sm 
        JOIN Employees emp ON CAST(emp.EmployeeID AS CHAR) COLLATE utf8mb4_unicode_ci = combined.employee_id COLLATE utf8mb4_unicode_ci
        WHERE sm.agent_email COLLATE utf8mb4_unicode_ci = emp.Email COLLATE utf8mb4_unicode_ci
        AND sm.supervisor_email COLLATE utf8mb4_unicode_ci IN ($tl_placeholders)
    )";
    
    if (empty($search_query) && empty($approver_filter)) {
        $sql = "SELECT * FROM ($sql) as combined WHERE $tl_condition";
    } else {
        $sql .= " AND $tl_condition";
    }
    
    foreach ($teamlead_filter as $tl_email) {
        $params[] = $tl_email;
        $types .= "s";
    }
}

if (!empty($ot_type_filter)) {
    // Filter by OT type
    $ot_placeholders = implode(',', array_fill(0, count($ot_type_filter), '?'));
    
    if (empty($search_query) && empty($approver_filter) && empty($teamlead_filter)) {
        $sql = "SELECT * FROM ($sql) as combined WHERE combined.ot_type COLLATE utf8mb4_unicode_ci IN ($ot_placeholders)";
    } else {
        $sql .= " AND combined.ot_type COLLATE utf8mb4_unicode_ci IN ($ot_placeholders)";
    }
    
    foreach ($ot_type_filter as $ot_type) {
        $params[] = $ot_type;
        $types .= "s";
    }
}

// Close the subquery if we added filters
if (!empty($search_query) || !empty($approver_filter) || !empty($teamlead_filter) || !empty($ot_type_filter)) {
    $sql .= " ORDER BY combined.date DESC, combined.start_time DESC";
} else {
    $sql .= " ORDER BY date DESC, start_time DESC";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get unique Approvers for filter dropdown - get actual names from som_email
$approver_query = "SELECT DISTINCT 
                CONCAT(som.FirstName, ' ', som.LastName) as approver_name,
                e.som_email
              FROM Employees e
              LEFT JOIN Employees som ON e.som_email COLLATE utf8mb4_unicode_ci = som.Email COLLATE utf8mb4_unicode_ci
              WHERE e.som_email IS NOT NULL 
              AND e.som_email COLLATE utf8mb4_unicode_ci != ''
              AND som.FirstName IS NOT NULL
              AND e.som_email NOT IN (
                  'juneroy.jacob@cohere.ph',
                  'andrew.tacdoro@cohere.ph'
              )
              AND LOWER(som.FirstName) NOT IN ('jed', 'cong')
              ORDER BY approver_name";
$approver_result = $conn->query($approver_query);
$approvers = [];
while ($row = $approver_result->fetch_assoc()) {
    $approvers[] = [
        'name' => $row['approver_name'],
        'email' => $row['som_email']
    ];
}

// Get unique Team Leads for filter dropdown - from supervisor_mapping
$teamlead_query = "SELECT DISTINCT 
                    CONCAT(e.FirstName, ' ', e.LastName) as tl_name,
                    sm.supervisor_email
                  FROM supervisor_mapping sm
                  JOIN Employees e ON sm.supervisor_email COLLATE utf8mb4_unicode_ci = e.Email COLLATE utf8mb4_unicode_ci
                  WHERE sm.supervisor_email IS NOT NULL
                  AND sm.supervisor_email NOT IN (
                      'aireen.castro@cohere.ph',
                      'remir.figuracion@cohere.ph',
                      'andrew.tacdoro@cohere.ph',
                      'jericho.garcia@cohere.ph'
                  )
                  ORDER BY tl_name";
$teamlead_result = $conn->query($teamlead_query);
$teamleads = [];
while ($row = $teamlead_result->fetch_assoc()) {
    $teamleads[] = [
        'name' => $row['tl_name'],
        'email' => $row['supervisor_email']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OT & RD Hours Tracker - Admin View</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .hours-tracker-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #d4af37;
        }
        
        .filters-section {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            border: 2px solid #d4af37;
        }
        
        .filter-group {
    position: relative;
    display: flex;
    flex-direction: column;
}
        
        .filter-group label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #d4af37;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 8px;
            border: 2px solid #d4af37;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            color: #1a1a1a;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #f4d03f;
            box-shadow: 0 0 5px rgba(212, 175, 55, 0.5);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, #d4af37 0%, #f4d03f 100%);
            color: #1a1a1a;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-filter:hover {
            background: linear-gradient(135deg, #f4d03f 0%, #d4af37 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4);
        }
        
        .btn-reset {
            padding: 10px 20px;
            background: #1a1a1a;
            color: #d4af37;
            border: 2px solid #d4af37;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            background: #d4af37;
            color: #1a1a1a;
            transform: translateY(-2px);
        }
        
        .btn-copy {
            padding: 10px 20px;
            background: linear-gradient(135deg, #d4af37 0%, #f4d03f 100%);
            color: #1a1a1a;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .btn-copy:hover {
            background: linear-gradient(135deg, #f4d03f 0%, #d4af37 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4);
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #d4af37;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #d4af37;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3);
            border-color: #f4d03f;
        }
        
        .stat-card h3 {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
            color: #d4af37;
        }
        
        .tracker-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 1200px;
        }
        
        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 3px solid #d4af37;
        }
        
        .tab-button {
            padding: 12px 30px;
            background: transparent;
            color: #1a1a1a;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: bold;
            font-size: 15px;
            transition: all 0.3s ease;
            margin-bottom: -3px;
        }
        
        .tab-button:hover {
            background: linear-gradient(180deg, #fff9e6 0%, #ffffff 100%);
            color: #d4af37;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #d4af37;
            border-bottom: 3px solid #d4af37;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .tracker-table thead {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #d4af37;
        }
        
        .tracker-table th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border: 2px solid #d4af37;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 13px;
        }
        
        .tracker-table td {
            padding: 12px;
            border: 1px solid #ddd;
            background: white;
            color: #1a1a1a;
        }
        
        .tracker-table tbody tr:hover {
            background: linear-gradient(90deg, #fff9e6 0%, #ffffff 100%) !important;
            border-left: 3px solid #d4af37;
        }
        
        .tracker-table tbody tr:nth-child(even) {
            background: #fafafa;
        }
        
        .hours-cell {
            font-weight: bold;
            color: #d4af37;
            font-size: 15px;
        }
        
        .tickets-cell {
            text-align: center;
            font-weight: bold;
            color: #1a1a1a;
        }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #d4af37;
            padding: 15px 25px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
            border: 2px solid #d4af37;
            display: none;
            z-index: 1000;
            font-weight: bold;
        }
        
        .toast.show {
            display: block;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }
        
        /* Multi-select dropdown styles */
        .multi-select-dropdown {
            position: relative;
        }
        
        /* The yellow button */
        .multi-select-button {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d4af37;
            background: #000;
            color: #fff;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .multi-select-button:hover {
            border-color: #f4d03f;
        }
        
        /* THE FIX: Dropdown should float below input */
        .multi-select-dropdown-content {
            position: absolute;
            top: calc(100% + 4px);      /* keeps dropdown BELOW the button */
            left: 0;
            width: 100%;

            background: #1d1d1d;
            border: 1px solid #d4af37;
            border-radius: 6px;
            max-height: 250px;
            overflow-y: auto;

            padding: 8px 0;
            z-index: 9999;

            display: none; /* hidden by default */
            color: #fff; /* <<< add this */
        }
        
        .multi-select-dropdown-content label {
        color: #fff !important;   /* <<< ensures labels are readable */
        }
        
        .multi-select-dropdown-content.show {
            display: block;
        }
        
        /* Checkbox items */
        .multi-select-item {
            padding: 6px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .multi-select-item:hover {
        background: #2a2a2a;
        }
        
        .multi-select-item input[type="checkbox"] {
            margin-right: 8px;
            width: auto;
        }
        
        .multi-select-item label {
            margin: 0;
            cursor: pointer;
            color: #1a1a1a;
        }
        
        /* Dropdown container */
.autocomplete-suggestions {
    position: absolute;
    top: calc(100% + 2px);   /* Keeps it BELOW input without pushing layout */
    left: 0;
    width: 100%;
    background: white;
    border: 1px solid #ccc;
    border-top: none;
    z-index: 9999;
    max-height: 200px;
    overflow-y: auto;
    border-radius: 0 0 6px 6px;
}

/* Each item */
.autocomplete-suggestions div {
    padding: 8px 10px;
    cursor: pointer;
}

.autocomplete-suggestions div:hover {
    background: #f2f2f2;
}
        
        .autocomplete-suggestions.show {
            display: block;
        }

        /* The container holding the suggestions */
.suggestions-container {
    max-height: 260px;
    overflow-y: auto;
    overflow-x: hidden;
    background: #1b1b1b;
    border: 1px solid #3a3a3a;
    border-radius: 6px;
}

.suggestion-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #2b2b2b;
    background: #1b1b1b;
    transition: background 0.15s ease;
}

.suggestion-item:hover {
    background: #242424;
}

/* Name line – clear & readable */
.suggestion-name {
    font-size: 0.85rem;      /* smaller */
    font-weight: 400;
    color: #ffffff;          /* white */
    margin-bottom: 2px;      /* small gap */
    line-height: 1.1;
}

/* ID line – smaller & very light */
.suggestion-id {
    font-size: 0.70rem;      /* significantly smaller */
    color: #9a9a9a;          /* soft gray */
    line-height: 1;
    margin-left: 4px;        /* small indent for style */
}

        
        .no-suggestions {
            padding: 10px 12px;
            color: #999;
            font-style: italic;
            text-align: center;
        }

        /* Make all dropdown text smaller and not bold */
.multi-select-dropdown-content,
.multi-select-dropdown-content * {
    font-size: 13px !important;
    font-weight: normal !important;
}

/* Fix label color (you had black #1a1a1a which looked bold + unreadable) */
.multi-select-item label {
    color: #fff !important;
}

#search {
    font-size: 13px;
    font-weight: normal;
}

/* Container */
#agent-suggestions {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    width: 100%;
    
    background: #1d1d1d;         /* dark background */
    border: 1px solid #d4af37;   /* gold border */
    border-radius: 6px;
    max-height: 250px;
    overflow-y: auto;
    
    padding: 8px 0;
    z-index: 9999;
    
    display: none;               /* hidden by default */
    color: #fff;                 /* make default text white */
    font-size: 13px;
    font-weight: normal;
}

/* Show dropdown */
#agent-suggestions.show {
    display: block !important;
}

/* Individual items (lighter text for readability) */
#agent-suggestions div,
#agent-suggestions li,
#agent-suggestions p,
#agent-suggestions a {
    padding: 6px 12px;
    cursor: pointer;
    color: #f0f0f0 !important; /* lighter text */
    font-size: 13px;
    font-weight: normal;
}

/* Hover effect */
#agent-suggestions div:hover,
#agent-suggestions li:hover,
#agent-suggestions a:hover,
#agent-suggestions p:hover {
    background: #2a2a2a;
}


    </style>
</head>
<body>
    <div class="header">
        OT & RD Hours Tracker - Admin View
        <div class="logout-btn">
            <a href="dashboard.php"><button class="btn-back">Back to Dashboard</button></a>
            <a href="logout.php"><button>Logout</button></a>
        </div>
    </div>

    <div class="hours-tracker-container">
        <!-- Filters Section -->
        <form method="GET" action="">
            <input type="hidden" name="active_tab" id="active_tab" value="<?= $_GET['active_tab'] ?? 'summary' ?>">
            <div class="filters-section">
                <div class="filter-group">
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($start_date) ?>" required>
                </div>
                
                <div class="filter-group">
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>" required>
                </div>
                
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All (Approved + Pending)</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved Only</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Only</option>
                    </select>
                </div>
                
                <div class="filter-group" style="position: relative;">
                    <label for="search">Search Agent:</label>
                    <input type="text" id="search" name="search"
                        placeholder="Type name or ID..."
                        autocomplete="off"
                        value="<?= htmlspecialchars($search_query) ?>"
                        onkeyup="searchAgents(this.value)"
                        onfocus="searchAgents(this.value)">
                    
                    <!-- Suggestions appear BELOW the input -->
                    <div id="agent-suggestions" class="autocomplete-suggestions"></div>
                </div>


                
                <div class="filter-group multi-select-dropdown">
                    <label>Filter by Team Lead:</label>
                    <button type="button" class="multi-select-button" onclick="toggleDropdown('teamlead')">
                        <span id="teamlead-selected"><?= empty($teamlead_filter) ? 'All Team Leads' : count($teamlead_filter) . ' selected' ?></span>
                        <span>▼</span>
                    </button>
                    <div id="teamlead-dropdown" class="multi-select-dropdown-content">
                        <?php foreach ($teamleads as $tl): ?>
                            <div class="multi-select-item">
                                <input type="checkbox" 
                                       name="teamlead[]" 
                                       value="<?= htmlspecialchars($tl['email']) ?>" 
                                       id="tl_<?= htmlspecialchars($tl['email']) ?>"
                                       <?= in_array($tl['email'], $teamlead_filter) ? 'checked' : '' ?>
                                       onchange="updateSelection('teamlead')">
                                <label for="tl_<?= htmlspecialchars($tl['email']) ?>">
                                    <?= htmlspecialchars($tl['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="filter-group multi-select-dropdown">
                    <label>Filter by Approver:</label>
                    <button type="button" class="multi-select-button" onclick="toggleDropdown('approver')">
                        <span id="approver-selected"><?= empty($approver_filter) ? 'All Approvers' : count($approver_filter) . ' selected' ?></span>
                        <span>▼</span>
                    </button>
                    <div id="approver-dropdown" class="multi-select-dropdown-content">
                        <?php foreach ($approvers as $approver): ?>
                            <div class="multi-select-item">
                                <input type="checkbox" 
                                       name="approver[]" 
                                       value="<?= htmlspecialchars($approver['email']) ?>" 
                                       id="app_<?= htmlspecialchars($approver['email']) ?>"
                                       <?= in_array($approver['email'], $approver_filter) ? 'checked' : '' ?>
                                       onchange="updateSelection('approver')">
                                <label for="app_<?= htmlspecialchars($approver['email']) ?>">
                                    <?= htmlspecialchars($approver['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="filter-group multi-select-dropdown">
                    <label>Filter by OT Type:</label>
                    <button type="button" class="multi-select-button" onclick="toggleDropdown('ottype')">
                        <span id="ottype-selected"><?= empty($ot_type_filter) ? 'All OT Types' : count($ot_type_filter) . ' selected' ?></span>
                        <span>▼</span>
                    </button>
                    <div id="ottype-dropdown" class="multi-select-dropdown-content">
                        <!-- Regular Day OT Types -->
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="PRE" 
                                id="ottype_PRE"
                                <?= in_array('PRE', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_PRE">PRE</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="POST" 
                                id="ottype_POST"
                                <?= in_array('POST', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_POST">POST</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="DAILY_OT" 
                                id="ottype_DAILY_OT"
                                <?= in_array('DAILY_OT', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_DAILY_OT">Daily OT</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="OVERHEAD_OT" 
                                id="ottype_OVERHEAD_OT"
                                <?= in_array('OVERHEAD_OT', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_OVERHEAD_OT">Overhead OT</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="TEAM_MEETING" 
                                id="ottype_TEAM_MEETING"
                                <?= in_array('TEAM_MEETING', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_TEAM_MEETING">Team Meeting</label>
                        </div>
                        
                        <!-- Rest Day OT Types -->
                        <div class="multi-select-item" style="border-top: 2px solid #d4af37; margin-top: 8px; padding-top: 8px;">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="RD_REGULAR" 
                                id="ottype_RD_REGULAR"
                                <?= in_array('RD_REGULAR', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_RD_REGULAR">RD Regular Work</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="RD_OVERHEAD" 
                                id="ottype_RD_OVERHEAD"
                                <?= in_array('RD_OVERHEAD', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_RD_OVERHEAD">RD Overhead Work</label>
                        </div>
                        <div class="multi-select-item">
                            <input type="checkbox" 
                                name="ot_type[]" 
                                value="RD_TEAM_MEETING" 
                                id="ottype_RD_TEAM_MEETING"
                                <?= in_array('RD_TEAM_MEETING', $ot_type_filter) ? 'checked' : '' ?>
                                onchange="updateSelection('ottype')">
                            <label for="ottype_RD_TEAM_MEETING">RD Team Meeting</label>
                        </div>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Apply Filters</button>
                    <a href="ot_hours_tracker.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <?php
        // Calculate SUMMARY statistics
        $summary_total_agents = 0;
        $summary_total_hours = 0;
        $summary_total_tickets = 0;
        $summary_total_approved = 0;
        $summary_total_pending = 0;
        $summary_data = [];
        
        while ($row = $summary_result->fetch_assoc()) {
            $summary_data[] = $row;
            $summary_total_agents++;
            $summary_total_hours += floatval($row['total_ot_hours'] ?? 0);
            $summary_total_tickets += intval($row['total_tickets'] ?? 0);
            $summary_total_approved += intval($row['approved_count'] ?? 0);
            $summary_total_pending += intval($row['pending_count'] ?? 0);
        }
        
        // Calculate DETAILED statistics - ✅ CHANGED: No longer converting from time format
        $total_records = 0;
        $total_hours = 0;
        $unique_agents = [];
        $data = [];
        $detailed_approved_count = 0;
        $detailed_pending_count = 0;
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $total_records++;
            
            // Count approved and pending
            if ($row['status'] === 'Approved') {
                $detailed_approved_count++;
            } elseif ($row['status'] === 'Pending') {
                $detailed_pending_count++;
            }
            
            // ✅ CHANGED: OT hours is already in decimal format, just add it
            $total_hours += floatval($row['ot_hours']);
            
            // Track unique agents
            if (!in_array($row['employee_id'], $unique_agents)) {
                $unique_agents[] = $row['employee_id'];
            }
        }
        
        $total_agents = count($unique_agents);
        
        // Status label for display
        $status_label = [
            'all' => 'Approved + Pending',
            'approved' => 'Approved Only',
            'pending' => 'Pending Only'
        ][$status_filter] ?? 'All';
        
        // Get active tab from URL parameter
        $active_tab = $_GET['active_tab'] ?? 'summary';
        
        // Close statements
        $summary_stmt->close();
        $stmt->close();
        $conn->close();
        ?>

        <div style="text-align: center; margin-bottom: 15px; padding: 12px; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); border-radius: 6px; border: 2px solid #d4af37;">
            <strong style="color: #d4af37;">📊 Showing:</strong> <span style="color: #ffffff;"><?= htmlspecialchars($status_label) ?></span> | 
            <strong style="color: #d4af37;">📅 Date Range:</strong> <span style="color: #ffffff;"><?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></span>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-button <?= $active_tab === 'summary' ? 'active' : '' ?>" onclick="switchTab('summary')">
                📊 Summary View
            </button>
            <button class="tab-button <?= $active_tab === 'detailed' ? 'active' : '' ?>" onclick="switchTab('detailed')">
                📋 Detailed View
            </button>
        </div>

        <!-- SUMMARY TAB -->
        <div id="summary-tab" class="tab-content <?= $active_tab === 'summary' ? 'active' : '' ?>">
            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="stat-card">
                    <h3>Total Agents</h3>
                    <div class="stat-value"><?= $summary_total_agents ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total OT Hours</h3>
                    <div class="stat-value"><?= number_format($summary_total_hours, 2) ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Approved</h3>
                    <div class="stat-value"><?= $summary_total_approved ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Pending</h3>
                    <div class="stat-value"><?= $summary_total_pending ?></div>
                </div>
            </div>

            <!-- Copy Button -->
            <button class="btn-copy" onclick="copyTableToClipboard('summaryTable')">
                📋 Copy Summary Data
            </button>

            <!-- Summary Table -->
            <?php if (count($summary_data) > 0): ?>
                <div class="table-wrapper">
                <table class="tracker-table" id="summaryTable">
                    <thead>
                        <tr>
                            <th>Agent Name</th>
                            <th>Total OT Hours</th>
                            <th>Total Tickets</th>
                            <th>Status</th>
                            <th>Team Lead</th>
                            <th>Approver</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td class="hours-cell">
                                    <?= number_format($row['total_ot_hours'] ?? 0, 2) ?>
                                </td>
                                <td class="tickets-cell">
                                    <?= number_format($row['total_tickets'] ?? 0) ?>
                                </td>
                                <td>
                                    <?php 
                                    $statuses = $row['status_list'] ? explode(', ', $row['status_list']) : [];
                                    foreach ($statuses as $status): 
                                        $bgColor = $status === 'Approved' ? '#28a745' : ($status === 'Pending' ? '#ffc107' : '#dc3545');
                                    ?>
                                        <span style="background: <?= $bgColor ?>; color: white; padding: 3px 6px; border-radius: 3px; font-weight: bold; font-size: 10px; margin-right: 4px; display: inline-block; margin-bottom: 2px;"><?= htmlspecialchars($status) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td><?= htmlspecialchars($row['team_lead']) ?></td>
                                <td><?= htmlspecialchars($row['som_name'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <p>📭 No data found for the selected filters.</p>
                    <p>Try adjusting your date range or search criteria.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- DETAILED TAB -->
        <div id="detailed-tab" class="tab-content <?= $active_tab === 'detailed' ? 'active' : '' ?>">
            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="stat-card">
                    <h3>Total Agents</h3>
                    <div class="stat-value"><?= $total_agents ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total OT Hours</h3>
                    <div class="stat-value"><?= number_format($total_hours, 2) ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Approved</h3>
                    <div class="stat-value"><?= $detailed_approved_count ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Pending</h3>
                    <div class="stat-value"><?= $detailed_pending_count ?></div>
                </div>
            </div>

            <!-- Copy Button -->
            <button class="btn-copy" onclick="copyTableToClipboard('detailedTable')">
                📋 Copy Detailed Data
            </button>

            <!-- Results Table -->
            <?php if (count($data) > 0): ?>
                <div class="table-wrapper">
                <table class="tracker-table" id="detailedTable">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>OT Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>OT Hours</th>
                        <th>OT Type</th>
                        <th>Status</th>
                        <th>Approver</th>
                        <th>Approved At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['employee_id']) ?></td>
                            <td><?= htmlspecialchars($row['agent_name']) ?></td>
                            <td><?= htmlspecialchars($row['date']) ?></td>
                            <td><?= htmlspecialchars($row['start_time']) ?></td>
                            <td><?= htmlspecialchars($row['end_time']) ?></td>
                            <td class="hours-cell"><?= number_format($row['ot_hours'], 2) ?></td>
                            <td>
                                <?php
                                $badge_color = '#2d2d2d'; // default dark gray
                                $text_color = '#d4af37';  // default gold
                                $display_type = $row['ot_type'];
                                $has_incentive = false;
                                
                                // Check if it's an RD type
                                if (strpos($display_type, 'RD_') === 0) {
                                    // Format display: RD_REGULAR → RD Regular Work
                                    $work_type = str_replace('RD_', '', $display_type);
                                    
                                    if ($work_type === 'REGULAR') {
                                        $display_type = 'RD Regular Work';
                                        $badge_color = '#28a745'; // green for incentive-eligible
                                        $text_color = '#ffffff';
                                        $has_incentive = true;
                                    } elseif ($work_type === 'OVERHEAD') {
                                        $display_type = 'RD Overhead Work';
                                        $badge_color = '#d4af37'; // gold
                                        $text_color = '#1a1a1a';
                                    } elseif ($work_type === 'TEAM_MEETING') {
                                        $display_type = 'RD Team Meeting';
                                        $badge_color = '#d4af37'; // gold
                                        $text_color = '#1a1a1a';
                                    }
                                } elseif ($row['ot_type'] === 'PRE') {
                                    $badge_color = '#1a1a1a'; // black
                                    $text_color = '#d4af37';  // gold
                                } elseif ($row['ot_type'] === 'POST') {
                                    $badge_color = '#1a1a1a'; // black
                                    $text_color = '#d4af37';  // gold
                                } elseif ($row['ot_type'] === 'DAILY_OT') {
                                    $display_type = 'Daily OT';
                                    $badge_color = '#4a90e2'; // blue
                                    $text_color = '#ffffff';  // white
                                } elseif ($row['ot_type'] === 'OVERHEAD_OT') {
                                    $display_type = 'Overhead OT';
                                    $badge_color = '#6c757d'; // gray
                                    $text_color = '#ffffff';
                                } elseif ($row['ot_type'] === 'TEAM_MEETING') {
                                    $display_type = 'Team Meeting';
                                    $badge_color = '#ffc107'; // yellow
                                    $text_color = '#1a1a1a';
                                }
                                ?>
                                <span style="background: <?= $badge_color ?>; color: <?= $text_color ?>; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px;">
                                    <?= htmlspecialchars($display_type) ?>
                                </span>
                            </td>
                            <td><span style="background: <?= $row['status'] === 'Approved' ? '#28a745' : ($row['status'] === 'Pending' ? '#ffc107' : '#dc3545') ?>; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px;"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td><?= htmlspecialchars($row['approver_name'] ?? 'N/A') ?></td>
                            <td><?= $row['approved_at'] ? date('Y-m-d H:i', strtotime($row['approved_at'])) : 'N/A' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <p>📭 No data found for the selected filters.</p>
                <p>Try adjusting your date range or search criteria.</p>
            </div>
        <?php endif; ?>
        </div>
        <!-- END DETAILED TAB -->

    </div>
    <!-- END hours-tracker-container -->

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        ✅ Table data copied to clipboard!
    </div>

    <script>

        // Tab switching function
        function switchTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Highlight selected button
            event.target.classList.add('active');
            
            // Update hidden field to remember active tab
            document.getElementById('active_tab').value = tabName;
        }
        
        function copyTableToClipboard(tableId) {
            const table = document.getElementById(tableId);
            if (!table) {
                alert('No data to copy!');
                return;
            }
            
            let text = '';
            
            // Copy headers
            const headers = table.querySelectorAll('thead th');
            headers.forEach((header, index) => {
                text += header.textContent;
                if (index < headers.length - 1) text += '\t';
            });
            text += '\n';
            
            // Copy rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach((cell, index) => {
                    text += cell.textContent.trim();
                    if (index < cells.length - 1) text += '\t';
                });
                text += '\n';
            });
            
            // Copy to clipboard
            navigator.clipboard.writeText(text).then(() => {
                showToast();
            }).catch(err => {
                console.error('Failed to copy:', err);
                alert('Failed to copy to clipboard. Please try again.');
            });
        }
        
        function showToast() {
            const toast = document.getElementById('toast');
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        // Multi-select dropdown functions
        function toggleDropdown(type) {
            const dropdown = document.getElementById(type + '-dropdown');
            dropdown.classList.toggle('show');
            
            // Close other dropdowns
            const allDropdowns = document.querySelectorAll('.multi-select-dropdown-content');
            allDropdowns.forEach(dd => {
                if (dd.id !== type + '-dropdown') {
                    dd.classList.remove('show');
                }
            });
        }
        
        function updateSelection(type) {
            const checkboxes = document.querySelectorAll(`input[name="${type}[]"]:checked`);
            const selectedText = document.getElementById(type + '-selected');
            
            if (checkboxes.length === 0) {
                if (type === 'teamlead') {
                    selectedText.textContent = 'All Team Leads';
                } else if (type === 'approver') {
                    selectedText.textContent = 'All Approvers';
                } else if (type === 'ottype') {
                    selectedText.textContent = 'All OT Types';
                }
            } else {
                selectedText.textContent = checkboxes.length + ' selected';
            }
        }
        
        // Close dropdowns when clicking outside
document.addEventListener('click', function(event) {

    // Close multi-select dropdowns
    if (!event.target.closest('.multi-select-dropdown')) {
        const allDropdowns = document.querySelectorAll('.multi-select-dropdown-content');
        allDropdowns.forEach(dd => dd.classList.remove('show'));
    }

    // Close AGENT AUTOCOMPLETE suggestions when clicking outside
    if (
        !event.target.closest('#search') && 
        !event.target.closest('#agent-suggestions')
    ) {
        const suggestions = document.getElementById('agent-suggestions');
        if (suggestions) {
            suggestions.innerHTML = "";        // <-- CLEAR suggestions
            suggestions.classList.remove('show'); // <-- HIDE suggestions
        }
    }
});

        
        // Autocomplete search for agents
        let searchTimeout;
        function searchAgents(query) {
    clearTimeout(searchTimeout);
    
    const suggestionsDiv = document.getElementById('agent-suggestions');

    // Hide + CLEAR suggestions if query is too short or empty
    if (query.length < 3) {
        suggestionsDiv.innerHTML = "";        // <-- CLEAR old suggestions
        suggestionsDiv.classList.remove('show');
        return;
    }

    // Debounce the search
    searchTimeout = setTimeout(() => {
        fetch('get_agents.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                displaySuggestions(data);
            })
            .catch(error => {
                console.error('Error fetching agents:', error);
            });
    }, 300);
}

        
        function displaySuggestions(agents) {
            const suggestionsDiv = document.getElementById('agent-suggestions');
            
            if (agents.length === 0) {
                suggestionsDiv.innerHTML = '<div class="no-suggestions">No agents found</div>';
                suggestionsDiv.classList.add('show');
                return;
            }
            
            let html = '';
            agents.forEach(agent => {
                html += `
                    <div class="suggestion-item" onclick="selectAgent('${agent.name}', '${agent.id}')">
                        <div class="suggestion-name">${agent.name}</div>
                        <div class="suggestion-id">ID: ${agent.id}</div>
                    </div>
                `;
            });



            
            suggestionsDiv.innerHTML = html;
            suggestionsDiv.classList.add('show');
        }
        
        function selectAgent(name, id) {
            // Set the search input value
            document.getElementById('search').value = name;
            
            // Hide suggestions
            const suggestionsDiv = document.getElementById('agent-suggestions');
            suggestionsDiv.classList.remove('show');
        }

    
    </script>
</body>
</html>