<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();

if (!isset($_SESSION['original_admin'])) {
    die("No original session found.");
}

// Restore the original admin session
foreach ($_SESSION['original_admin'] as $key => $value) {
    $_SESSION[$key] = $value;
}

unset($_SESSION['original_admin']);
unset($_SESSION['is_supervisor']);
unset($_SESSION['supervised_agents']);

header("Location: dashboard.php");
exit;
