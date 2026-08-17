<?php

// Exercises mcm_db_execute()'s failure path. A statement has to fail for that,
// and the site's own database is not available here - but the helper does not
// care which driver reported the failure, so an in-memory SQLite database
// standing in for one is enough.
//
// ?mode=silent picks the error mode the older runtimes default to, where a
// failing statement returns false instead of throwing. Both shapes reach the
// same log line and the same generic response, and both are worth proving
// because the site's runtime is not pinned. ?mode=ok runs a statement that
// works, so the helper is shown not to disturb a request that has nothing
// wrong with it.
require_once(__DIR__ . '/inc/bootstrap.php');

$mode   = isset($_GET['mode']) ? $_GET['mode'] : '';
$silent = ($mode === 'silent');
$ok     = ($mode === 'ok');

$db_connection = new PDO('sqlite::memory:');
$db_connection->setAttribute(PDO::ATTR_ERRMODE, $silent ? PDO::ERRMODE_SILENT : PDO::ERRMODE_EXCEPTION);
$db_connection->exec('CREATE TABLE mcm_probe (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
$db_connection->exec("INSERT INTO mcm_probe (id, name) VALUES (1, 'taken')");

// Fails when it runs, not when it is prepared, which is the case the helper is
// there for. Under ?mode=ok the same statement is given a name nobody has.
$query = $db_connection->prepare('INSERT INTO mcm_probe (id, name) VALUES (:id, :name)');
$query->bindValue(':id', 2, PDO::PARAM_INT);
$query->bindValue(':name', $ok ? 'free' : 'taken', PDO::PARAM_STR);
$result = mcm_db_execute($query, 'probe_db_query: inserting a name that is already taken');

echo 'result=' . ($result === true ? 'true' : 'not true') . "\n";
echo 'rows=' . $db_connection->query('SELECT count(*) FROM mcm_probe')->fetchColumn() . "\n";
echo "the page carried on\n";
