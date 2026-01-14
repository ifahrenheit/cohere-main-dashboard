<?php
session_start();

// Check if a theme parameter is provided
if (isset($_GET['theme'])) {
    if ($_GET['theme'] === '2') {
        $_SESSION['dashboard_theme'] = 'dashboard2';
    } else {
        $_SESSION['dashboard_theme'] = 'dashboard1';
    }
}

// Redirect back to dashboard
header('Location: dashboard_main.php');
exit;
