<?php
// ot/submit_ticket.php
// Simplified bulk OT ticket submission - allows duplicates

require_once 'config.php';

$current_user = getCurrentUserOT();

// Safety check
if (!$current_user) {
    die("Error: Please <a href='/login.php'>log in</a> to access this page.");
}

$current_employee_id = $current_user['EmployeeID'] ?? 
                       $current_user['employeeID'] ?? 
                       $_SESSION['employeeID'] ?? null;

if (!$current_employee_id) {
    die("Error: Employee ID not found.");
}

$success_message = '';
$warning_message = '';
$error_message = '';
$ticket_stats = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supervisor_id = !empty($current_user['supervisor_id']) ? $conn->real_escape_string($current_user['supervisor_id']) : NULL;
    $team = !empty($current_user['team']) ? $conn->real_escape_string($current_user['team']) : '';
    
    $ot_date = $conn->real_escape_string($_POST['ot_date']);
    $ot_start_time = $conn->real_escape_string($_POST['ot_start_time']);
    $ot_end_time = $conn->real_escape_string($_POST['ot_end_time']);
    
    // Get bulk ticket numbers
    $ticket_numbers_raw = $_POST['ticket_numbers'];
    
    // Validation
    if (empty($ot_date) || empty($ot_start_time) || empty($ot_end_time) || empty($ticket_numbers_raw)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Parse ticket numbers (split by newline, space, comma, or tab)
        // Parse ticket numbers (split by newline, space, comma, or tab)
$ticket_numbers = preg_split('/[\s,\n\r\t]+/', trim($ticket_numbers_raw), -1, PREG_SPLIT_NO_EMPTY);

if (empty($ticket_numbers)) {
    $error_message = "Please enter at least one ticket number.";
} else {
    // Calculate total OT hours
    $start_datetime = strtotime("$ot_date $ot_start_time");
    $end_datetime = strtotime("$ot_date $ot_end_time");
    $total_ot_hours = ($end_datetime - $start_datetime) / 3600; // Convert seconds to hours
    
    // Calculate hours per ticket (divide total OT by number of tickets)
    $ticket_count = count($ticket_numbers);
    $hours_per_ticket = $total_ot_hours / $ticket_count;
    
    $inserted = 0;
    $failed = 0;
    $duplicates_found = [];
    
    // Process ALL tickets (including duplicates)
    foreach ($ticket_numbers as $ticket_number) {
        $ticket_number = $conn->real_escape_string(trim($ticket_number));
        
        // Check if this exact ticket exists for this agent on this date (for warning only)
        $check_query = "SELECT id, ticket_number FROM ot_tickets 
                       WHERE agent_id = '$current_employee_id' 
                       AND ticket_number = '$ticket_number' 
                       AND ot_date = '$ot_date'";
        $check_result = $conn->query($check_query);
        
        if ($check_result && $check_result->num_rows > 0) {
            $duplicates_found[] = $ticket_number;
            // Continue anyway - don't skip
        }
        
        // Insert ticket with calculated hours per ticket
        $query = "INSERT INTO ot_tickets 
                  (agent_id, supervisor_id, team, ot_date, ot_start_time, ot_end_time, ot_hours,
                   ticket_type, priority, status, ticket_number, customer_name, 
                   issue_description, resolution_notes)
                  VALUES 
                  ('$current_employee_id', " . ($supervisor_id ? "'$supervisor_id'" : "NULL") . ", '$team', 
                   '$ot_date', '$ot_start_time', '$ot_end_time', '$hours_per_ticket',
                   'general', 'medium', 'resolved', '$ticket_number', '', 
                   'OT ticket', '')";
        
        if ($conn->query($query)) {
            $inserted++;
        } else {
            $failed++;
        }
    }
            
            // Build success/warning message
            $ticket_stats = [
                'total_submitted' => count($ticket_numbers),
                'inserted' => $inserted,
                'duplicates_found' => count($duplicates_found),
                'failed' => $failed
            ];
            
            if ($inserted > 0) {
                $success_message = "✓ Successfully submitted $inserted ticket(s)!";
            }
            
            if (!empty($duplicates_found)) {
                $warning_message = "⚠️ Notice: " . count($duplicates_found) . " duplicate ticket number(s) detected and submitted: " . implode(', ', array_slice(array_unique($duplicates_found), 0, 10));
                if (count($duplicates_found) > 10) {
                    $warning_message .= " and " . (count($duplicates_found) - 10) . " more...";
                }
                $warning_message .= "<br><small>These will be flagged for supervisor review.</small>";
            }
            
            if ($failed > 0) {
                $error_message = "❌ $failed ticket(s) failed to submit.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit OT Tickets</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <style>
        .form-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .form-section h3 {
            margin-top: 0;
            color: #667eea;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .required::after {
            content: " *";
            color: #e74c3c;
        }
        .help-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }
        .info-box ul {
            margin: 0.5rem 0 0 1.5rem;
            padding: 0;
        }
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .ticket-counter {
            background: #fff;
            border: 2px solid #667eea;
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 0.5rem;
            font-weight: 600;
            color: #667eea;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-item {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 3px solid #667eea;
            text-align: center;
        }
        .stat-item .number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }
        .stat-item .label {
            font-size: 0.85rem;
            color: #666;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="back-link">← Back to OT Dashboard</a>
        </div>

        <div class="page-header">
            <h1>📝 Submit OT Tickets (Bulk Entry)</h1>
            <p>Quick ticket entry for overtime productivity tracking</p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
                
                <?php if (!empty($ticket_stats)): ?>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="number"><?php echo $ticket_stats['inserted']; ?></div>
                        <div class="label">Inserted</div>
                    </div>
                    <?php if ($ticket_stats['duplicates_found'] > 0): ?>
                    <div class="stat-item">
                        <div class="number" style="color: #ff9800;"><?php echo $ticket_stats['duplicates_found']; ?></div>
                        <div class="label">Duplicates (Flagged)</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($warning_message): ?>
            <div class="alert-warning"><?php echo $warning_message; ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="info-box">
            <strong>ℹ️ How to use Bulk Entry:</strong>
            <ul>
                <li>Enter all ticket numbers in the text area below</li>
                <li>Separate by space, comma, or new line (one per line is easiest)</li>
                <li>Duplicate ticket numbers are ALLOWED (customer may call back multiple times)</li>
                <li>Duplicates will be flagged for supervisor review to verify legitimacy</li>
                <li>All tickets will share the same OT date and time</li>
            </ul>
        </div>

        <div class="card">
            <form method="POST" action="" id="ticketForm">
                <!-- OT Time Information -->
                <div class="form-section">
                    <h3>⏰ Overtime Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">OT Date</label>
                            <input type="date" name="ot_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Start Time</label>
                            <input type="time" name="ot_start_time" class="form-control" required>
                            <p class="help-text">When did your OT start?</p>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">End Time</label>
                            <input type="time" name="ot_end_time" class="form-control" required>
                            <p class="help-text">When did your OT end?</p>
                        </div>
                    </div>
                    
                    <div id="hoursDisplay" class="ticket-counter" style="display: none;">
                        Total OT Hours: <span id="totalHours">0.00</span> hrs
                    </div>
                </div>

                <!-- Bulk Ticket Numbers -->
                <div class="form-section">
                    <h3>🎫 Ticket Numbers (Bulk Entry)</h3>
                    
                    <div class="form-group">
                        <label class="required">Paste all ticket numbers here</label>
                        <textarea name="ticket_numbers" id="ticketNumbers" class="form-control" rows="10" 
                                  placeholder="Enter ticket numbers separated by space or new line:&#10;&#10;112233&#10;122334&#10;123451&#10;112233&#10;&#10;or: 112233 122334 123451 112233" 
                                  required></textarea>
                        <p class="help-text">
                            💡 Tip: Copy from your spreadsheet or list and paste here. Duplicates are allowed.
                        </p>
                    </div>
                    
                    <div class="ticket-counter">
                        <span id="ticketCount">0</span> ticket(s) detected
                        <span id="duplicateWarning" style="display: none; color: #ff9800; margin-left: 1rem;">
                            (<span id="duplicateCount">0</span> duplicate(s) - will be flagged for review)
                        </span>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <span class="icon">✓</span> Submit All Tickets
                    </button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Real-time ticket counter with duplicate detection
        const ticketNumbersField = document.getElementById('ticketNumbers');
        const ticketCountDisplay = document.getElementById('ticketCount');
        const duplicateWarning = document.getElementById('duplicateWarning');
        const duplicateCountDisplay = document.getElementById('duplicateCount');
        const startTimeField = document.querySelector('input[name="ot_start_time"]');
        const endTimeField = document.querySelector('input[name="ot_end_time"]');
        const hoursDisplay = document.getElementById('hoursDisplay');
        const totalHoursDisplay = document.getElementById('totalHours');
        
        ticketNumbersField.addEventListener('input', function() {
            const text = this.value.trim();
            if (text) {
                // Split by whitespace, comma, or newline
                const tickets = text.split(/[\s,\n\r\t]+/).filter(t => t.length > 0);
                const uniqueTickets = [...new Set(tickets)];
                
                ticketCountDisplay.textContent = tickets.length; // Show total count
                
                if (tickets.length !== uniqueTickets.length) {
                    const duplicateCount = tickets.length - uniqueTickets.length;
                    duplicateCountDisplay.textContent = duplicateCount;
                    duplicateWarning.style.display = 'inline';
                } else {
                    duplicateWarning.style.display = 'none';
                }
            } else {
                ticketCountDisplay.textContent = '0';
                duplicateWarning.style.display = 'none';
            }
        });
        
        // Calculate OT hours
        function calculateHours() {
            if (startTimeField.value && endTimeField.value) {
                const start = new Date('2000-01-01 ' + startTimeField.value);
                const end = new Date('2000-01-01 ' + endTimeField.value);
                const diff = (end - start) / (1000 * 60 * 60);
                
                if (diff > 0) {
                    totalHoursDisplay.textContent = diff.toFixed(2);
                    hoursDisplay.style.display = 'block';
                } else {
                    hoursDisplay.style.display = 'none';
                }
            }
        }
        
        startTimeField.addEventListener('change', calculateHours);
        endTimeField.addEventListener('change', calculateHours);
        
        // Form validation
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            const ticketText = ticketNumbersField.value.trim();
            if (!ticketText) {
                e.preventDefault();
                alert('Please enter at least one ticket number!');
                return false;
            }
        });
    </script>
</body>
</html>