<?php

// Stand-in for a page that cannot be served without the database.
// mcm_db_or_fail() either returns a usable connection or ends the request, so
// during an outage the line after it must never run - that is the difference
// between one bounded failure and a second, unrelated one.
require_once(__DIR__ . '/inc/bootstrap.php');

$db_connection = mcm_db_or_fail('probe_db');

echo "the query would run here\n";
