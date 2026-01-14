<?php
// ot/delete_ticket.php
// Delete OT ticket (Supervisor/Manager only)

require_once 'config.php';

$current_user = getCurrentUser();
$is_supervisor = isSupervisorOrManager();

// Only supervisors/managers can delete tickets
if (!$is_supervisor) {
    echo "<script>alert('You do not have permission to delete tickets.'); window.location.href='index.php';</script>";
    exit();
}

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$ticket_id) {
    header('Location: view_tickets.php');
    exit();
}

// Verify ticket exists
$query = "SELECT id FROM ot_tickets WHERE id = " . $conn->real_escape_string($ticket_id);
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    echo "<script>alert('Ticket not found.'); window.location.href='view_tickets.php';</script>";
    exit();
}

// Handle confirmation
if (isset($_POST['confirm_delete'])) {
    // Delete ticket
    $delete_query = "DELETE FROM ot_tickets WHERE id = " . $conn->real_escape_string($ticket_id);
    
    if ($conn->query($delete_query)) {
        echo "<script>alert('Ticket deleted successfully.'); window.location.href='view_tickets.php';</script>";
        exit();
    } else {
        $error = "Error deleting ticket: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete OT Ticket</title>
    <link rel="stylesheet" href="/coaching/assets/css/style.css">
    <link rel="stylesheet" href="assets/css/ot-styles.css">
    
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="ticket_detail.php?id=<?php echo $ticket_id; ?>" class="back-link">← Back to Ticket</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="warning-box">
                <div class="warning-icon">⚠️</div>
                <h2>Delete OT Ticket #<?php echo $ticket_id; ?>?</h2>
                <p><strong>Warning:</strong> This action cannot be undone!</p>
                <p>Are you sure you want to permanently delete this OT ticket?</p>
                <p>All ticket information, including hours tracked and notes, will be lost.</p>
                
                <form method="POST" action="">
                    <div class="delete-actions">
                        <button type="submit" name="confirm_delete" class="btn btn-danger" style="padding: 1rem 2rem;">
                            🗑️ Yes, Delete Ticket
                        </button>
                        <a href="ticket_detail.php?id=<?php echo $ticket_id; ?>" class="btn btn-secondary" style="padding: 1rem 2rem;">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>