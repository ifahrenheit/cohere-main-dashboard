<?php
// includes/session_check.php
// Centralized session management and authentication check

// ==========================================
// 1. SESSION INITIALIZATION
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_domain', '.cohere.ph');
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    session_start();
}

// ==========================================
// 2. AUTHENTICATION CHECK
// ==========================================
function checkAuth($redirect = true) {
    if (!isset($_SESSION['user_email']) || empty($_SESSION['user_email'])) {
        if ($redirect) {
            header("Location: /login.php");
            exit();
        }
        return false;
    }
    return true;
}

// ==========================================
// 3. ROLE-BASED ACCESS CHECK
// ==========================================
function checkRole($allowedRoles = [], $dieOnFail = true) {
    if (!checkAuth(false)) {
        if ($dieOnFail) {
            die("Access Denied: Not logged in.");
        }
        return false;
    }
    
    if (empty($allowedRoles)) {
        return true; // No role restriction
    }
    
    $userRole = $_SESSION['role'] ?? '';
    
    if (!in_array($userRole, $allowedRoles)) {
        if ($dieOnFail) {
            die("Access Denied: You do not have permission to access this page.");
        }
        return false;
    }
    
    return true;
}

// ==========================================
// 4. GET CURRENT USER INFO
// ==========================================
function getCurrentUser() {
    if (!checkAuth(false)) {
        return null;
    }
    
    return [
        'email' => $_SESSION['user_email'] ?? '',
        'personid' => $_SESSION['personid'] ?? '',
        'employeeID' => $_SESSION['employeeID'] ?? '',
        'role' => $_SESSION['role'] ?? 'Employee',
        'firstname' => $_SESSION['firstname'] ?? '',
        'lastname' => $_SESSION['lastname'] ?? '',
    ];
}

// ==========================================
// 5. AUTO-CHECK ON INCLUDE (Optional)
// ==========================================
// Automatically check if user is logged in when this file is included
// Comment out if you want manual control
// checkAuth();

?>