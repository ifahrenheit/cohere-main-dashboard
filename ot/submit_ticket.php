<?php
// ot/submit_ticket.php
// Combined OT Request + RD Request + Ticket Submission

require_once 'config.php';

$current_user = getCurrentUserOT();

if (!$current_user) {
    die("Error: Please <a href='/login.php'>log in</a> to access this page.");
}

$current_employee_id = $current_user['EmployeeID'] ?? 
                       $current_user['employeeID'] ?? 
                       $_SESSION['employeeID'] ?? null;

if (!$current_employee_id) {
    die("Error: Employee ID not found.");
}

$is_team_lead = isTeamLead();
$is_manager = isManagerOrAbove();

$success_message = '';
$error_message = '';

$allowed_daily_ot_emails = [
    'analito.palban@cohere.ph',
    'jonathan.calumpang@cohere.ph',
    'jason.bunac@cohere.ph'
];

$current_user_email = $current_user['Email'] ?? $current_user['email'] ?? '';
$can_file_daily_ot = in_array(strtolower($current_user_email), array_map('strtolower', $allowed_daily_ot_emails));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ot'])) {
    $work_day_type = htmlspecialchars(trim($_POST['work_day_type'] ?? ''));
    $ot_date = htmlspecialchars(trim($_POST['ot_date'] ?? ''));
    $start_time = htmlspecialchars(trim($_POST['start_time'] ?? ''));
    $end_time = htmlspecialchars(trim($_POST['end_time'] ?? ''));
    $ticket_numbers_raw = trim($_POST['ticket_numbers'] ?? '');
    
    // Fields specific to work day type
    $ot_type = ''; // For regular OT
    $regular_rate = ''; // For regular OT
    $work_category = 'REGULAR'; // For RD work
    
    if ($work_day_type === 'REGULAR') {
        $ot_type = htmlspecialchars(trim($_POST['ot_type'] ?? ''));
        $regular_rate = htmlspecialchars(trim($_POST['regular_rate'] ?? ''));
    } else if ($work_day_type === 'REST_DAY') {
        $work_category = htmlspecialchars(trim($_POST['work_category'] ?? 'REGULAR'));
    }
    
    // Validate required fields
    if (empty($work_day_type) || empty($ot_date) || empty($start_time) || empty($end_time)) {
        $error_message = "Please fill in all required fields.";
    } elseif ($work_day_type === 'REGULAR' && (empty($ot_type) || empty($regular_rate))) {
        $error_message = "Please select OT Type and Regular Rate for regular work days.";
    } else {
        // Determine if this is a no-ticket work type
        $is_no_ticket_ot = false;
        if ($work_day_type === 'REGULAR') {
            $is_no_ticket_ot = in_array($ot_type, ['TEAM_MEETING', 'OVERHEAD_OT', 'DAILY_OT', 'QA_REFRESHER']);
        } else {
            $is_no_ticket_ot = in_array($work_category, ['OVERHEAD', 'TEAM_MEETING']);
        }
        
        // Validate tickets if needed
        if (empty($ticket_numbers_raw) && !$is_no_ticket_ot) {
            $error_message = "Please enter at least one ticket number.";
        } else {
            // Check for existing request
            // Check for existing request with overlapping times
// Check for existing request with overlapping times
if ($work_day_type === 'REGULAR') {
    $check_stmt = $conn->prepare("
        SELECT id FROM ot_requests 
        WHERE employee_id = ? 
        AND ot_date = ? 
        AND ot_type = ? 
        AND status IN ('Pending', 'Approved') 
        AND deleted_at IS NULL
        AND NOT (end_time <= ? OR start_time >= ?)
    ");
    $check_stmt->bind_param("sssss", 
        $current_employee_id, 
        $ot_date, 
        $ot_type,
        $start_time,  // Existing ends before/at new start = no overlap
        $end_time     // Existing starts after/at new end = no overlap
    );
} else {
    $check_stmt = $conn->prepare("
        SELECT id FROM rd_requests 
        WHERE employee_id = ? 
        AND rd_date = ? 
        AND status IN ('Pending', 'Approved') 
        AND deleted_at IS NULL
        AND NOT (end_time <= ? OR start_time >= ?)
    ");
    $check_stmt->bind_param("ssss", 
        $current_employee_id, 
        $ot_date,
        $start_time,
        $end_time
    );
}

$check_stmt->execute();
$check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
    if ($work_day_type === 'REGULAR') {
        $error_message = "You already have a pending or approved OT request that overlaps with this time range.";
    } else {
        $error_message = "You already have a pending or approved RD request that overlaps with this time range.";
    }
} else {
                // Process tickets if needed
                if ($is_no_ticket_ot) {
                    $ticket_numbers = [];
                } else {
                    $ticket_numbers = preg_split('/[\s,\n\r\t]+/', $ticket_numbers_raw, -1, PREG_SPLIT_NO_EMPTY);
                    
                    // VALIDATION: Check if all tickets contain only numbers AND minimum length
                    $invalid_tickets = [];
                    $min_length = 5; // Minimum ticket number length
                    
                    foreach ($ticket_numbers as $ticket) {
                        $ticket = trim($ticket);
                        
                        // Check if ticket contains only digits
                        if (!ctype_digit($ticket)) {
                            $invalid_tickets[] = $ticket . " (contains non-numeric characters)";
                        }
                        // Check if ticket meets minimum length requirement
                        elseif (strlen($ticket) < $min_length) {
                            $invalid_tickets[] = $ticket . " (too short - minimum $min_length digits required)";
                        }
                    }
                    
                    if (!empty($invalid_tickets)) {
                        $error_message = "Invalid ticket number(s) detected!<br><br><strong>Requirements:</strong><br>" .
                                       "✓ Only numbers allowed<br>" .
                                       "✓ Minimum $min_length digits<br><br>" .
                                       "<strong>Invalid tickets:</strong><br>" . 
                                       implode('<br>', array_map('htmlspecialchars', $invalid_tickets)) . 
                                       "<br><br><strong>Valid examples:</strong> 112334, 221312, 123456";
                    }
                }
                
                if (!isset($error_message) || empty($error_message)) {
                    // Tickets are valid, proceed with processing
                    $start_datetime = strtotime("$ot_date $start_time");
                    $end_datetime = strtotime("$ot_date $end_time");
                    
                    if ($end_datetime <= $start_datetime) {
                        $end_datetime += 86400;
                    }
                    
                    $total_ot_hours = ($end_datetime - $start_datetime) / 3600;
                    $ticket_count = count($ticket_numbers);
                    $hours_per_ticket = $ticket_count > 0 ? $total_ot_hours / $ticket_count : 0;
                    
                    $supervisor_query = "SELECT supervisor_id FROM Employees WHERE EmployeeID = '$current_employee_id'";
                    $supervisor_result = $conn->query($supervisor_query);
                    $supervisor_id = null;
                    if ($supervisor_result && $supervisor_result->num_rows > 0) {
                        $supervisor_data = $supervisor_result->fetch_assoc();
                        $supervisor_id = $supervisor_data['supervisor_id'];
                    }
                    
                    $conn->begin_transaction();
                    
                    try {
                        $tickets_submitted_flag = $is_no_ticket_ot ? 0 : 1;
                        $request_id = 0;
                        
                        if ($work_day_type === 'REGULAR') {
                            // ========== INSERT INTO ot_requests ==========
                            $insert_ot_request = $conn->prepare("
                                INSERT INTO ot_requests 
                                (employee_id, ot_date, ot_type, regular_rate, start_time, end_time, status, timestamp, tickets_submitted)
                                VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?)
                            ");
                            $insert_ot_request->bind_param("ssssssi", 
                                $current_employee_id, $ot_date, $ot_type, $regular_rate, $start_time, $end_time, $tickets_submitted_flag
                            );
                            $insert_ot_request->execute();
                            $request_id = $conn->insert_id;
                            $insert_ot_request->close();
                            
                        } else {
                            // ========== INSERT INTO rd_requests ==========
                            $insert_rd_request = $conn->prepare("
                                INSERT INTO rd_requests 
                                (employee_id, rd_date, start_time, end_time, work_category, status, created_at)
                                VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
                            ");
                            $insert_rd_request->bind_param("sssss", 
                                $current_employee_id, $ot_date, $start_time, $end_time, $work_category
                            );
                            $insert_rd_request->execute();
                            $request_id = $conn->insert_id;
                            $insert_rd_request->close();
                        }
                        
                        $inserted = 0;
                        
                        // Only insert tickets if not Team Meeting or Overhead work
                        if (!$is_no_ticket_ot && $ticket_count > 0) {
                            foreach ($ticket_numbers as $ticket_number) {
                                $ticket_number = $conn->real_escape_string(trim($ticket_number));
                                
                                // Determine which table to link to
                                if ($work_day_type === 'REGULAR') {
                                    $ot_request_id_value = $request_id;
                                    $rd_request_id_value = 'NULL';
                                } else {
                                    $ot_request_id_value = 'NULL';
                                    $rd_request_id_value = $request_id;
                                }
                                
                                $insert_ticket = "INSERT INTO ot_tickets 
                                    (ot_request_id, rd_request_id, agent_id, supervisor_id, team, ot_date, ot_start_time, ot_end_time, ot_hours,
                                     ticket_type, priority, status, ticket_number, customer_name, issue_description, resolution_notes)
                                    VALUES 
                                    ($ot_request_id_value,
                                     $rd_request_id_value,
                                     '$current_employee_id',
                                     " . ($supervisor_id ? "'$supervisor_id'" : "NULL") . ",
                                     '" . ($work_day_type === 'REGULAR' ? $ot_type : 'RD') . "',
                                     '$ot_date',
                                     '$start_time',
                                     '$end_time',
                                     $hours_per_ticket,
                                     'general', 'medium', 'pending',
                                     '$ticket_number', '', 'OT ticket', '')";
                                
                                if ($conn->query($insert_ticket)) {
                                    $inserted++;
                                }
                            }
                        }
                        
                        $conn->commit();
                        
                        // Success message based on work type
                        if ($work_day_type === 'REGULAR') {
                            if ($is_no_ticket_ot) {
                                if ($ot_type === 'TEAM_MEETING') {
                                    $ot_type_display = 'Team Meeting';
                                } elseif ($ot_type === 'QA_REFRESHER') {
                                    $ot_type_display = 'QA Refresher Training';
                                } else {
                                    $ot_type_display = 'Overhead OT';
                                }
                                $success_message = "Successfully submitted $ot_type_display request! Your manager will review it soon.";
                            } else {
                                $success_message = "Successfully submitted OT request with $inserted ticket(s)! Your manager will review it soon.";
                            }
                        } else {
                            if ($is_no_ticket_ot) {
                                $work_display = ($work_category === 'TEAM_MEETING') ? 'RD Team Meeting' : 'RD Overhead Work';
                                $success_message = "Successfully submitted $work_display request! Your manager will review it soon.";
                            } else {
                                $success_message = "Successfully submitted Rest Day work request with $inserted ticket(s)! Your manager will review it soon.";
                            }
                        }
                        $_POST = [];
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            }
            
            $check_stmt->close();
        }
    }
}

// Fetch user's OT and RD requests with ticket count
$my_requests_query = "
    SELECT 
        otr.id,
        otr.ot_date as work_date,
        otr.ot_type as work_type,
        otr.start_time,
        otr.end_time,
        otr.status,
        otr.regular_rate,
        otr.timestamp as created_at,
        otr.approved_at,
        otr.deleted_at,
        otr.tickets_submitted,
        CONCAT(approver.FirstName, ' ', approver.LastName) as approver_name,
        COUNT(ott.id) as ticket_count,
        TIMESTAMPDIFF(HOUR, 
            CONCAT(otr.ot_date, ' ', otr.start_time), 
            IF(otr.end_time < otr.start_time, 
               DATE_ADD(CONCAT(otr.ot_date, ' ', otr.end_time), INTERVAL 1 DAY),
               CONCAT(otr.ot_date, ' ', otr.end_time)
            )
        ) as duration_hours,
        'REGULAR' as day_type
    FROM ot_requests otr
    LEFT JOIN ot_tickets ott ON otr.id = ott.ot_request_id
    LEFT JOIN Employees approver ON otr.approver = approver.EmployeeID
    WHERE otr.employee_id = '$current_employee_id'
    GROUP BY otr.id
    
    UNION ALL
    
    SELECT 
        rdr.id,
        rdr.rd_date as work_date,
        CASE 
            WHEN rdr.work_category = 'OVERHEAD' THEN 'RD-OVERHEAD'
            WHEN rdr.work_category = 'TEAM_MEETING' THEN 'RD-MEETING'
            ELSE 'RD'
        END as work_type,
        rdr.start_time,
        rdr.end_time,
        rdr.status,
        'N/A' as regular_rate,
        rdr.created_at,
        rdr.approved_at,
        rdr.deleted_at,
        0 as tickets_submitted,
        rdr.approver_name,
        COUNT(ott.id) as ticket_count,
        TIMESTAMPDIFF(HOUR, 
            CONCAT(rdr.rd_date, ' ', rdr.start_time), 
            IF(rdr.end_time < rdr.start_time, 
               DATE_ADD(CONCAT(rdr.rd_date, ' ', rdr.end_time), INTERVAL 1 DAY),
               CONCAT(rdr.rd_date, ' ', rdr.end_time)
            )
        ) as duration_hours,
        'REST_DAY' as day_type
    FROM rd_requests rdr
    LEFT JOIN ot_tickets ott ON rdr.id = ott.rd_request_id
    WHERE rdr.employee_id = '$current_employee_id'
    GROUP BY rdr.id
    
    ORDER BY work_date DESC, start_time DESC
    LIMIT 20";
$my_requests = $conn->query($my_requests_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit OT/RD Request - OT Tracker</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    <style>
        .form-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .form-section h3 {
            color: var(--primary-color);
            margin: 0 0 1.5rem 0;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .form-grid.two-col {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .form-grid.three-col {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-group label .required {
            color: #f44336;
            margin-left: 0.25rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        
        textarea.form-control {
            resize: vertical;
            font-family: 'Courier New', monospace;
        }
        
        .form-divider {
            margin: 2rem 0;
            border: none;
            border-top: 2px dashed #e0e0e0;
        }
        
        .form-hint {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--bg-light);
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .requests-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .requests-table thead th {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .requests-table thead th:first-child {
            border-radius: 8px 0 0 0;
        }
        
        .requests-table thead th:last-child {
            border-radius: 0 8px 0 0;
        }
        
        .requests-table tbody tr {
            background: white;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .requests-table tbody tr:hover {
            background: var(--bg-light);
        }
        
        .requests-table tbody td {
            padding: 1rem;
        }
        
        .status-badge {
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        @media (max-width: 768px) {
            .form-grid.two-col,
            .form-grid.three-col {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
        }
    </style>
    
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const ticketTextarea = document.querySelector('textarea[name="ticket_numbers"]');
        const form = document.querySelector('form');
        const workDayTypeSelect = document.getElementById('work_day_type');
        const otTypeSelect = document.getElementById('ot_type');
        const workCategorySelect = document.getElementById('work_category');
        const ticketSection = document.getElementById('ticket-section');
        const otTypeSection = document.getElementById('ot-type-section');
        const workCategorySection = document.getElementById('work-category-section');
        const regularRateSection = document.getElementById('regular-rate-section');
        
        // Function to toggle sections based on work day type
        function toggleSections() {
            const workDayType = workDayTypeSelect.value;
            
            if (workDayType === 'REGULAR') {
                // Show OT Type and Regular Rate
                otTypeSection.style.display = 'block';
                regularRateSection.style.display = 'block';
                workCategorySection.style.display = 'none';
                
                // Make OT Type required
                otTypeSelect.setAttribute('required', 'required');
                document.querySelector('select[name="regular_rate"]').setAttribute('required', 'required');
                workCategorySelect.removeAttribute('required');
                
                // Check if we need tickets
                toggleTicketSection();
                
            } else if (workDayType === 'REST_DAY') {
                // Show Work Category
                otTypeSection.style.display = 'none';
                regularRateSection.style.display = 'none';
                workCategorySection.style.display = 'block';
                
                // Make Work Category required
                workCategorySelect.setAttribute('required', 'required');
                otTypeSelect.removeAttribute('required');
                document.querySelector('select[name="regular_rate"]').removeAttribute('required');
                
                // Check if we need tickets
                toggleTicketSectionForRD();
                
            } else {
                // Nothing selected
                otTypeSection.style.display = 'none';
                regularRateSection.style.display = 'none';
                workCategorySection.style.display = 'none';
                ticketSection.style.display = 'none';
            }
        }
        
        // Function to toggle ticket section for Regular OT
        function toggleTicketSection() {
            const noTicketTypes = ['TEAM_MEETING', 'OVERHEAD_OT', 'DAILY_OT', 'QA_REFRESHER'];
            if (noTicketTypes.includes(otTypeSelect.value)) {
                ticketSection.style.display = 'none';
                ticketTextarea.removeAttribute('required');
            } else {
                ticketSection.style.display = 'block';
                ticketTextarea.setAttribute('required', 'required');
            }
        }
        
        // Function to toggle ticket section for RD work
        function toggleTicketSectionForRD() {
            const noTicketCategories = ['OVERHEAD', 'TEAM_MEETING'];
            if (noTicketCategories.includes(workCategorySelect.value)) {
                ticketSection.style.display = 'none';
                ticketTextarea.removeAttribute('required');
            } else {
                ticketSection.style.display = 'block';
                ticketTextarea.setAttribute('required', 'required');
            }
        }
        
        // Add event listeners
        if (workDayTypeSelect) {
            workDayTypeSelect.addEventListener('change', toggleSections);
            // Initialize on page load
            toggleSections();
        }
        
        if (otTypeSelect) {
            otTypeSelect.addEventListener('change', toggleTicketSection);
        }
        
        if (workCategorySelect) {
            workCategorySelect.addEventListener('change', toggleTicketSectionForRD);
        }
        
        if (ticketTextarea && form) {
            // Real-time validation feedback
            ticketTextarea.addEventListener('blur', function() {
                const workDayType = workDayTypeSelect.value;
                let skipValidation = false;
                
                if (workDayType === 'REGULAR') {
                    const noTicketTypes = ['TEAM_MEETING', 'OVERHEAD_OT', 'DAILY_OT', 'QA_REFRESHER'];
                    skipValidation = noTicketTypes.includes(otTypeSelect.value);
                } else if (workDayType === 'REST_DAY') {
                    const noTicketCategories = ['OVERHEAD', 'TEAM_MEETING'];
                    skipValidation = noTicketCategories.includes(workCategorySelect.value);
                }
                
                if (!skipValidation) {
                    validateTickets(this.value, false);
                }
            });
            
            // Form submission validation
            form.addEventListener('submit', function(e) {
                const workDayType = workDayTypeSelect.value;
                let skipValidation = false;
                
                // Check if we should skip ticket validation
                if (workDayType === 'REGULAR') {
                    const noTicketTypes = ['TEAM_MEETING', 'OVERHEAD_OT', 'DAILY_OT', 'QA_REFRESHER'];
                    skipValidation = noTicketTypes.includes(otTypeSelect.value);
                } else if (workDayType === 'REST_DAY') {
                    const noTicketCategories = ['OVERHEAD', 'TEAM_MEETING'];
                    skipValidation = noTicketCategories.includes(workCategorySelect.value);
                }
                
                if (skipValidation) {
                    return true;
                }
                
                const ticketValue = ticketTextarea.value.trim();
                
                if (ticketValue === '') {
                    e.preventDefault();
                    alert('⚠️ Please enter at least one ticket number');
                    ticketTextarea.focus();
                    return false;
                }
                
                if (!validateTickets(ticketValue, true)) {
                    e.preventDefault();
                    ticketTextarea.focus();
                    return false;
                }
            });
            
            function validateTickets(value, showAlert) {
                // Split by newlines, commas, spaces, tabs
                const tickets = value.split(/[\s,\n\r\t]+/).filter(t => t.trim() !== '');
                const invalidTickets = [];
                const minLength = 5; // Minimum ticket number length
                
                tickets.forEach(ticket => {
                    ticket = ticket.trim();
                    
                    // Check if ticket contains only digits
                    if (!/^\d+$/.test(ticket)) {
                        invalidTickets.push(ticket + ' (contains non-numeric characters)');
                    }
                    // Check if ticket meets minimum length
                    else if (ticket.length < minLength) {
                        invalidTickets.push(ticket + ' (too short - minimum ' + minLength + ' digits required)');
                    }
                });
                
                if (invalidTickets.length > 0) {
                    if (showAlert) {
                        alert('❌ Invalid ticket number(s) detected!\n\n' +
                              'Requirements:\n' +
                              '✓ Only numbers allowed\n' +
                              '✓ Minimum ' + minLength + ' digits\n\n' +
                              'Invalid tickets:\n' + invalidTickets.join('\n') + '\n\n' +
                              'Valid examples:\n112334\n221312\n123456');
                    }
                    return false;
                }
                
                return true;
            }
        }
    });
    </script>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to OT Dashboard</a>
        </div>

        <div class="ot-header">
            <h1>📋 Submit OT/RD Request</h1>
            <p>Submit your overtime or rest day hours - <?php echo htmlspecialchars($current_user['full_name']); ?></p>
            <span class="role-badge">👤 Production Agent</span>
        </div>

        <div class="content">
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <span style="font-size: 1.5rem;">✓</span>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <span style="font-size: 1.5rem;">⚠</span>
                    <span><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <!-- Form Section -->
            <div class="form-section">
                <form method="POST" action="">
                    <h3>
                        <span>⏰</span>
                        Work Information
                    </h3>
                    
                    <div class="form-grid two-col">
                        <div class="form-group">
                            <label for="work_day_type">
                                Work Day Type<span class="required">*</span>
                            </label>
                            <select name="work_day_type" id="work_day_type" class="form-control" required>
                                <option value="">-- Select Day Type --</option>
                                <option value="REGULAR" <?php echo (isset($_POST['work_day_type']) && $_POST['work_day_type'] === 'REGULAR') ? 'selected' : ''; ?>>Regular Work Day (OT)</option>
                                <option value="REST_DAY" <?php echo (isset($_POST['work_day_type']) && $_POST['work_day_type'] === 'REST_DAY') ? 'selected' : ''; ?>>Rest Day (RD)</option>
                            </select>
                            <div class="form-hint">
                                💡 <em>Select "Rest Day" if you worked on your scheduled day off</em>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="ot_date">
                                Work Date<span class="required">*</span>
                            </label>
                            <input type="date" 
                                   name="ot_date" 
                                   id="ot_date"
                                   class="form-control" 
                                   required 
                                   value="<?php echo $_POST['ot_date'] ?? ''; ?>">
                        </div>
                    </div>

                    <!-- OT Type Section (for Regular Work Days) -->
                    <div id="ot-type-section" style="display: none; margin-top: 1.5rem;">
                        <div class="form-group">
                            <label for="ot_type">
                                OT Type<span class="required">*</span>
                            </label>
                            <select name="ot_type" id="ot_type" class="form-control">
                                <option value="">-- Select OT Type --</option>
                                <option value="PRE" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'PRE') ? 'selected' : ''; ?>>PRE (Before Shift)</option>
                                <option value="POST" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'POST') ? 'selected' : ''; ?>>POST (After Shift)</option>
                                <option value="OVERHEAD_OT" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'OVERHEAD_OT') ? 'selected' : ''; ?>>Overhead OT</option>
                                <option value="TEAM_MEETING" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'TEAM_MEETING') ? 'selected' : ''; ?>>Team Meeting</option>
                                <option value="QA_REFRESHER" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'QA_REFRESHER') ? 'selected' : ''; ?>>QA Refresher Training</option>
                                <?php if ($can_file_daily_ot): ?>
                                    <option value="DAILY_OT" <?php echo (isset($_POST['ot_type']) && $_POST['ot_type'] === 'DAILY_OT') ? 'selected' : ''; ?>>Daily OT</option>
                                <?php endif; ?>
                            </select>
                            <div class="form-hint">
                                💡 <em>Note: Overhead OT, Team Meeting, QA Refresher Training<?php echo $can_file_daily_ot ? ', and Daily OT' : ''; ?> do not require ticket numbers</em>
                            </div>
                        </div>
                    </div>

                    <!-- Work Category Section (for Rest Days) -->
                    <div id="work-category-section" style="display: none; margin-top: 1.5rem;">
                        <div class="form-group">
                            <label for="work_category">
                                Type of Work<span class="required">*</span>
                            </label>
                            <select name="work_category" id="work_category" class="form-control">
                                <option value="REGULAR" <?php echo (isset($_POST['work_category']) && $_POST['work_category'] === 'REGULAR') ? 'selected' : ''; ?>>Regular Work</option>
                                <option value="OVERHEAD" <?php echo (isset($_POST['work_category']) && $_POST['work_category'] === 'OVERHEAD') ? 'selected' : ''; ?>>Overhead Work</option>
                                <option value="TEAM_MEETING" <?php echo (isset($_POST['work_category']) && $_POST['work_category'] === 'TEAM_MEETING') ? 'selected' : ''; ?>>Team Meeting</option>
                            </select>
                            <div class="form-hint">
                                💡 <em>Note: Overhead work and Team Meeting on RD do not require ticket numbers</em>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid three-col" style="margin-top: 1.5rem;">
                        <div class="form-group">
                            <label for="start_time">
                                Start Time<span class="required">*</span>
                            </label>
                            <select name="start_time" id="start_time" class="form-control" required>
                                <option value="">-- Select Start Time --</option>
                                <?php 
                                for ($i = 0; $i < 24; $i++) {
                                    foreach (['00', '30'] as $m) {
                                        $time = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$m:00";
                                        $display = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$m";
                                        $selected = (isset($_POST['start_time']) && $_POST['start_time'] === $time) ? 'selected' : '';
                                        echo "<option value='$time' $selected>$display</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_time">
                                End Time<span class="required">*</span>
                            </label>
                            <select name="end_time" id="end_time" class="form-control" required>
                                <option value="">-- Select End Time --</option>
                                <?php 
                                for ($i = 0; $i < 24; $i++) {
                                    foreach (['00', '30'] as $m) {
                                        $time = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$m:00";
                                        $display = str_pad($i, 2, '0', STR_PAD_LEFT) . ":$m";
                                        $selected = (isset($_POST['end_time']) && $_POST['end_time'] === $time) ? 'selected' : '';
                                        echo "<option value='$time' $selected>$display</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="regular-rate-section" style="display: none;">
                            <label for="regular_rate">
                                Regular Rate<span class="required">*</span>
                            </label>
                            <select name="regular_rate" class="form-control">
                                <option value="">-- Select --</option>
                                <option value="Yes" <?php echo (isset($_POST['regular_rate']) && $_POST['regular_rate'] === 'Yes') ? 'selected' : ''; ?>>Yes</option>
                                <option value="No" <?php echo (isset($_POST['regular_rate']) && $_POST['regular_rate'] === 'No') ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                    </div>

                    <hr class="form-divider">

                    <div id="ticket-section" style="display: none;">
                        <h3>
                            <span>🎫</span>
                            Ticket Numbers
                        </h3>
                        
                        <div class="form-group">
                            <label for="ticket_numbers">
                                Enter Ticket Numbers<span class="required">*</span>
                            </label>
                            <textarea 
                                name="ticket_numbers" 
                                rows="8" 
                                class="form-control" 
                                placeholder="Enter one ticket per line:&#10;&#10;123456&#10;123457&#10;123458&#10;&#10;Or comma-separated: 123456, 123457, 123458&#10;&#10;⚠️ REQUIREMENTS:&#10;• Only numbers (no letters, no special characters)&#10;• Minimum 5 digits&#10;• No time entries (7, 8, AM, PM)&#10;• No text (chat, email, No Tix, pre-ot)"
                            ><?php echo $_POST['ticket_numbers'] ?? ''; ?></textarea>
                            <div class="form-hint">
                                💡 <em>Tip: You can paste multiple tickets at once (one per line or comma-separated)</em><br>
                                ✅ <strong>Valid:</strong> 112334, 221312, 123456 (minimum 5 digits)<br>
                                ❌ <strong>Invalid:</strong> 7, 8, gyg123, pre-ot, chat, email, No Tix, AM, PM
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="submit_ot" class="btn btn-primary" style="flex: 1;">
                            ✓ Submit Request
                        </button>
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

            <!-- My Requests History -->
            <div class="card">
                <div class="card-header">
                    <h2>📝 My Recent Requests</h2>
                    <a href="view_tickets.php" class="btn btn-secondary btn-sm">View All Tickets</a>
                </div>
                
                <?php if ($my_requests && $my_requests->num_rows > 0): ?>
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day Type</th>
                                <th>Work Type</th>
                                <th>Time Range</th>
                                <th>Duration</th>
                                <th>Tickets</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($req = $my_requests->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo formatDate($req['work_date']); ?></td>
                                    <td>
                                        <?php if ($req['day_type'] === 'REST_DAY'): ?>
                                            <span style="background: #d4af37; color: #1a1a1a; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                🌙 REST DAY
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #e3f2fd; color: #1976d2; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                📅 REGULAR
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($req['work_type'] === 'TEAM_MEETING'): ?>
                                            <span style="background: #e3f2fd; color: #1976d2; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                🤝 MEETING
                                            </span>
                                        <?php elseif ($req['work_type'] === 'OVERHEAD_OT'): ?>
                                            <span style="background: #f3e5f5; color: #7b1fa2; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                ⚙️ OVERHEAD
                                            </span>
                                        <?php elseif ($req['work_type'] === 'QA_REFRESHER'): ?>
                                            <span style="background: #e8f5e9; color: #2e7d32; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                📚 QA TRAINING
                                            </span>   
                                        <?php elseif ($req['work_type'] === 'RD-OVERHEAD'): ?>
                                            <span style="background: #f3e5f5; color: #7b1fa2; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                ⚙️ RD-OVERHEAD
                                            </span>
                                        <?php elseif ($req['work_type'] === 'RD-MEETING'): ?>
                                            <span style="background: #e3f2fd; color: #1976d2; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                🤝 RD-MEETING
                                            </span>
                                        <?php elseif ($req['work_type'] === 'RD'): ?>
                                            <span style="background: #d4af37; color: #1a1a1a; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                RD
                                            </span>
                                        
                                        <?php elseif ($req['work_type'] === 'DAILY_OT'): ?>
                                            <span style="background: #4a90e2; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                📅 DAILY OT
                                            </span>
                                        
                                        <?php else: ?>
                                            <span style="background: var(--primary-light); color: var(--primary-color); padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                                                <?php echo htmlspecialchars($req['work_type']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.9rem; color: var(--text-medium);">
                                            <?php echo formatTime($req['start_time']); ?> - 
                                            <?php echo formatTime($req['end_time']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color: var(--primary-color); font-size: 1.1rem;">
                                            <?php echo number_format($req['duration_hours'], 1); ?>
                                        </strong>
                                        <span style="font-size: 0.8rem; color: var(--text-light);"> hrs</span>
                                    </td>
                                    <td>
                                        <?php if (in_array($req['work_type'], ['TEAM_MEETING', 'OVERHEAD_OT', 'DAILY_OT', 'QA_REFRESHER', 'RD-OVERHEAD', 'RD-MEETING'])): ?>
                                            <span style="color: var(--text-light); font-size: 0.85rem; font-style: italic;">
                                                N/A
                                            </span>
                                        <?php else: ?>
                                            <strong><?php echo $req['ticket_count']; ?></strong> 
                                            <span style="color: var(--text-light); font-size: 0.85rem;">tickets</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
    <?php
    // Check if soft deleted (rejected)
    if ($req['deleted_at'] !== null) {
        $display_status = 'Rejected';
    } else {
        $display_status = $req['status'];
    }
    ?>
    <span class="status-badge status-<?php echo strtolower($display_status); ?>">
        <?php 
        if ($display_status === 'Pending') echo '⏳ ';
        elseif ($display_status === 'Approved') echo '✓ ';
        elseif ($display_status === 'Rejected') echo '✗ ';
        echo htmlspecialchars($display_status); 
        ?>
    </span>
</td>
                                    <td style="font-size: 0.85rem; color: var(--text-light);">
                                        <?php echo formatDateTime($req['created_at']); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>📋 No requests yet. Submit your first one above!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>