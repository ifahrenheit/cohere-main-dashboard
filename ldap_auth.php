<?php
// ldap_auth.php

session_start();

$config = include 'ldap_config.php';

function authenticate_user($username, $password) {
    global $config;

    // Connect to LDAP server
    $ldap_conn = ldap_connect($config['ldap_server']);
    if (!$ldap_conn) {
        die('Could not connect to LDAP server.');
    }

    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

    // Bind using the admin account
    $admin_bind = @ldap_bind($ldap_conn, $config['ldap_admin_user'], $config['ldap_admin_password']);
    if (!$admin_bind) {
        die('LDAP admin bind failed.');
    }

    // Search for the user
    $search_filter = "(sAMAccountName=$username)";
    $search = @ldap_search($ldap_conn, $config['ldap_dn'], $search_filter, ['dn', 'memberOf']);

    if (!$search) {
        die('LDAP search failed.');
    }

    $entries = ldap_get_entries($ldap_conn, $search);

    if ($entries['count'] == 0) {
        die('Error: User not found in LDAP.');
    }

    // Get user's DN
    $user_dn = $entries[0]['dn'];

    // Authenticate the user
    $bind = @ldap_bind($ldap_conn, $user_dn, $password);

    if (!$bind) {
        echo "Authentication failed.";
        return false;
    }

    // Fetch user's groups
    $groups = [];
    if (!empty($entries[0]['memberof'])) {
        for ($i = 0; $i < $entries[0]['memberof']['count']; $i++) {
            $groups[] = $entries[0]['memberof'][$i];
        }
    }

    ldap_unbind($ldap_conn);

    // Check if user is in "Shifts Operations Manager" group
    $user_role = 'Employee';  // Default role
    foreach ($groups as $group) {
        if (strpos($group, 'CN=Shifts Operations Manager') !== false) {
            $user_role = 'Shifts Operations Manager';
            break;
        }
    }

    // Store user data in session
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $user_role;

    return true;
}

// Example usage
$username = 'atacdoro';  // Replace with dynamic input
$password = 'H3llfire10!@#'; // Replace with real input

if (authenticate_user($username, $password)) {
    echo "Login successful! <br>";
    echo "User Role: " . $_SESSION['role'] . "<br>";
} else {
    echo "Login failed!";
}
?>

