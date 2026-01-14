<?php
session_start();

// Handle theme selection from dropdown
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_theme'])) {
    $_SESSION['dashboard_theme'] = $_POST['dashboard_theme'];
    // Redirect immediately to avoid re-submitting the form
    header("Location: dashboard_main.php");
    exit();
}

// Default to dashboard1 if not set
$theme = $_SESSION['dashboard_theme'] ?? 'dashboard1';

// Redirect based on theme
if ($theme === 'dashboard2') {
    header("Location: dashboard2.php");
    exit();
} else {
    header("Location: dashboard.php");
    exit();
}
