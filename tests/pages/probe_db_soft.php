<?php

// The other shape: mcm_db_connect() reports failure by returning null and
// leaves the caller to decide what to do, which is what the login and
// registration code needs so it can show its own message instead of a 500.
require_once(__DIR__ . '/inc/bootstrap.php');

$db_connection = mcm_db_connect('probe_db_soft');

echo 'connection=' . ($db_connection === null ? 'null' : get_class($db_connection)) . "\n";
echo "request completed\n";
