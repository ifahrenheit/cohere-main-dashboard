<?php
session_start(); // ✅ Start session to store employeeID
include 'db_connection.php'; // ✅ Include database connection

// LDAP Server Configuration
define('LDAP_SERVER', 'ldap://172.12.6.4'); 
define('LDAP_PORT', 389);  // 636 for SSL (LDAPS)
define('LDAP_ENCRYPTION', 'tls');
define('LDAP_VERSION', 3);

// Bind Credentials
define('LDAP_BIND_USER', 'CN=Andrew Vincent Tacdoro,CN=Users,DC=cohere,DC=local'); 
define('LDAP_BIND_PASS', 'H3llfire10$!$!'); 

// User Lookup Settings
define('LDAP_BASE_DN', 'CN=Users,DC=cohere,DC=local'); 
define('LDAP_USER_FILTER', '(&(objectClass=user)(sAMAccountName={username}))'); 
define('LDAP_USER_ATTRIBUTES', ['cn', 'sn', 'givenName', 'mail', 'sAMAccountName']);

// Connect to LDAP
$ldap_conn = ldap_connect(LDAP_SERVER, LDAP_PORT);
if (!$ldap_conn) {
    die("Could not connect to LDAP server.");
}

// Set LDAP Options
ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, LDAP_VERSION);
ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);  // No need to redefine this constant

// Enable TLS if required
if (LDAP_ENCRYPTION === 'tls') {
    if (!ldap_start_tls($ldap_conn)) {
        die("Failed to start TLS encryption: " . ldap_error($ldap_conn));
    }
}

// Bind to LDAP Server
$bind = @ldap_bind($ldap_conn, LDAP_BIND_USER, LDAP_BIND_PASS);
if (!$bind) {
    die("LDAP bind failed: " . ldap_error($ldap_conn));
}

// Function to authenticate a user
function authenticate_user($username, $password, $ldap_conn) {
    $filter = str_replace("{username}", $username, LDAP_USER_FILTER);
    $search = ldap_search($ldap_conn, LDAP_BASE_DN, $filter, LDAP_USER_ATTRIBUTES);
    
    if (!$search) {
        die("LDAP search failed: " . ldap_error($ldap_conn));
    }

    $entries = ldap_get_entries($ldap_conn, $search);
    
    // Debugging: Print LDAP search results
    echo "<pre>";
    print_r($entries);
    echo "</pre>";

    if ($entries["count"] > 0) {
        $user_dn = $entries[0]["dn"];
        $user_mail = $entries[0]["mail"][0] ?? 'No email found';

        // Authenticate using the found DN
        if (@ldap_bind($ldap_conn, $user_dn, $password)) {
            return ["status" => "success", "dn" => $user_dn, "email" => $user_mail];
        } else {
            return ["status" => "failed", "error" => ldap_error($ldap_conn)];
        }
    } else {
        return ["status" => "failed", "error" => "User not found."];
    }
}

// Example usage with your username 'atacdoro'
$username = "atacdoro";  // Your sAMAccountName
$password = "H3llfire10$!$!";  // Replace with user input (password)

$result = authenticate_user($username, $password, $ldap_conn);
if ($result["status"] === "success") {
    echo "User authenticated! Email: " . $result["email"];
} else {
    echo "Authentication failed: " . $result["error"];
}

// Close the connection
ldap_unbind($ldap_conn);
?>

