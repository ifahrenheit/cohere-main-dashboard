<?php
ini_set('session.cookie_domain', '.cohere.ph');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
session_start();
session_regenerate_id(true);
session_destroy();  // Destroy the session
header("Location: login.php");  // Redirect to the login page
exit();
?>

