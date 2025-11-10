<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ✅ Use correct LDAP URI
$ldap_host = "ldap://172.12.6.4:389"; // Don't add a second argument

// ✅ Full bind DN (adjust if needed)
$ldap_user = "CN=Andrew Vincent Tacdoro,OU=Service Accounts,DC=cohere,DC=local";
$ldap_pass = "H3llfire10!@#";

// ✅ Connect to LDAP
$ldap_conn = ldap_connect($ldap_host);

if (!$ldap_conn) {
    die("❌ Could not connect to LDAP server.");
}

// Set LDAP options
ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
ldap_set_option($ldap_conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

// ✅ Attempt to bind
if (@ldap_bind($ldap_conn, $ldap_user, $ldap_pass)) {
    echo "✅ LDAP bind successful!";
} else {
    echo "❌ LDAP bind failed.<br>";
    echo "Error: " . ldap_error($ldap_conn);
}
?>
