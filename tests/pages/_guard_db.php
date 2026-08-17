<?php

/**
 * A throw-away movie_lists table for the ownership cases.
 *
 * The suite has no database and is not getting one. SQLite in memory is the
 * whole of it: the table is the one column layout the ownership query cares
 * about, so the query under test is a real prepared statement against a real
 * driver, and the fixture disappears with the request.
 *
 * Returns null where the runtime has no SQLite driver, which the suite reports
 * as a skip rather than a failure.
 *
 * @return PDO|null
 */
function mcm_guard_test_db()
{
	if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
		return null;
	}

	$db = new PDO('sqlite::memory:');
	$db->exec('CREATE TABLE movie_lists (movie_list_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, list_name TEXT)');
	// List 11 belongs to user 7, list 12 to user 8, and 99 exists nowhere.
	$db->exec("INSERT INTO movie_lists (movie_list_id, user_id, list_name) VALUES (11, 7, 'mine'), (12, 8, 'theirs')");

	return $db;
}

/**
 * Render a boolean for a report line.
 *
 * @param bool $value
 * @return string
 */
function mcm_guard_bool($value)
{
	return $value ? 'true' : 'false';
}
