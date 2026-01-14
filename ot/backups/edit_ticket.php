<?php
// ot/edit_ticket.php
// Edit OT ticket (supervisors/managers only)

require_once 'config.php';

$current_user = getCurrentUserOT();

if (!$current_user) {
    die("Error: Please <a href='/login.php'>log in</a> to access this page.");
}

$is_supervisor = isSupervisorOrManager();

if (!$is_supervisor) {
    die("Access Denied: Only supervisors and managers can edit tickets.");
}

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$ticket_id) {
    header("Location: view_tickets.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ot_date = $conn->real_escape_string($_POST['ot_date']);
    $ot_start_time = $conn->real_escape_string($_POST['ot_start_time']);
    $ot_end_time = $conn->real_escape_string($_POST['ot_end_time']);
    $ticket_number = $conn->real_escape_string($_POST['ticket_number']);
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $status = $conn->real_escape_string($_POST['status']);
    $issue_description = $conn->real_escape_string($_POST['issue_description']);
    $resolution_notes = $conn->real_escape_string($_POST['resolution_notes']);
    
    $query = "UPDATE ot_tickets SET 
              ot_date = '$ot_date',
              ot_start_time = '$ot_start_time',
              ot_end_time = '$ot_end_time',
              ticket_number = '$ticket_number',
              customer_name = '$customer_name',
              status = '$status',
              issue_description = '$issue_description',
              resolution_notes = '$resolution_notes',
              updated_at = CURRENT_TIMESTAMP
              WHERE id = $ticket_id";
    
    if ($conn->query($query)) {
        $success_message = "Ticket updated successfully!";
    } else {
        $error_message = "Error updating ticket: " . $conn->error;
    }
}

// Fetch ticket details
$query = "SELECT 
            ot.*, 
            CONCAT(e.FirstName, ' ', e.LastName) as agent_name
          FROM ot_tickets ot
          LEFT JOIN Employees e ON ot.agent_id = e.EmployeeID
          WHERE ot.id = $ticket_id";

$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Ticket not found.");
}

$ticket = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Ticket #<?php echo $ticket['id']; ?></title>
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
            <a href="ticket_detail.php?id=<?php echo $ticket_id; ?>" class="back-link">← Back to Ticket Detail</a>
        </div>

        <div class="page-header">
            <h1>✏️ Edit Ticket #<?php echo $ticket['id']; ?></h1>
            <p>Editing ticket for <?php echo htmlspecialchars($ticket['agent_name']); ?></p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" action="">
                <!-- OT Time Information -->
                <div class="form-section">
                    <h3>⏰ Overtime Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">OT Date</label>
                            <input type="date" name="ot_date" class="form-control" 
                                   value="<?php echo $ticket['ot_date']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Start Time</label>
                            <input type="time" name="ot_start_time" class="form-control" 
                                   value="<?php echo $ticket['ot_start_time']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">End Time</label>
                            <input type="time" name="ot_end_time" class="form-control" 
                                   value="<?php echo $ticket['ot_end_time']; ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Ticket Information -->
                <div class="form-section">
                    <h3>🎫 Ticket Details</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Ticket Number</label>
                            <input type="text" name="ticket_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($ticket['ticket_number']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Customer Name</label>
                            <input type="text" name="customer_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($ticket['customer_name']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Status</label>
                            <select name="status" class="form-control" required>
                                <?php foreach (TICKET_STATUS as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo $ticket['status'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Issue & Resolution -->
                <div class="form-section">
                    <h3>📋 Issue & Resolution</h3>
                    
                    <div class="form-group">
                        <label>Issue Description</label>
                        <textarea name="issue_description" class="form-control" rows="4"><?php echo htmlspecialchars($ticket['issue_description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" rows="4"><?php echo htmlspecialchars($ticket['resolution_notes']); ?></textarea>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        ✓ Update Ticket
                    </button>
                    <a href="ticket_detail.php?id=<?php echo $ticket_id; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>