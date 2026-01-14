<?php
session_start();

echo "<h2>Current PHP Session Settings</h2>";

echo "session.gc_maxlifetime = " . ini_get('session.gc_maxlifetime') . " seconds<br>";
echo "session.cookie_lifetime = " . ini_get('session.cookie_lifetime') . " seconds<br>";

echo "<br><strong>Your session ID:</strong> " . session_id();
