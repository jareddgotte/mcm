<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// Reordering is a write like any other: it needs the method, the session and the
// token, and the order it changes belongs to one account.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();
$user_id = mcm_current_user_id();

// Kill the script if someone got here improperly
$stop_state_json = isset($_POST['stop_state']) ? $_POST['stop_state'] : '';
$start_pos = isset($_POST['start_pos']) ? $_POST['start_pos'] : '';
$stop_pos = isset($_POST['stop_pos']) ? $_POST['stop_pos'] : '';

// What the page sends is a JSON array of list identifiers and the two positions
// that bound the part of it that moved. None of it is typed by anybody, so a
// value that is not the shape described gets the bounded refusal and its detail
// goes to the log.
//
// The checks below are what the "=== ''" tests they replace could never do:
// json_decode() answers null for malformed JSON and never '', so a truncated or
// hand-written body used to get all the way to a loop that indexed into null.
$stop_state = is_string($stop_state_json) ? json_decode($stop_state_json, true) : null;
// A JSON object rather than an array would be positional in name only, and the
// positions below index into it.
if (!mcm_is_positional_array($stop_state) || count($stop_state) === 0) {
	mcm_json_error(400, 'adjust_lists: refused a stop state that is not a non-empty positional array: ' . mcm_log_detail($stop_state_json));
}

$start = mcm_list_rank($start_pos);
$stop  = mcm_list_rank($stop_pos);
if ($start === null) {
	mcm_json_error(400, 'adjust_lists: refused a start position that is not a position: ' . mcm_log_detail($start_pos));
}
if ($stop === null) {
	mcm_json_error(400, 'adjust_lists: refused a stop position that is not a position: ' . mcm_log_detail($stop_pos));
}
// The range has to be one the array actually has, or the loop reads past its
// end and ranks a list by an identifier that is not there.
if ($stop < $start || $stop >= count($stop_state)) {
	mcm_json_error(400, 'adjust_lists: refused positions ' . $start . '-' . $stop . ' against ' . count($stop_state) . ' lists');
}

//printf("c[%s] m[%s]\n", $current_list, $movie_id);
//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('adjust_lists');

// Every list in the range is checked before any of them is written, so a range
// that reaches one list belonging to somebody else moves none of them at all
// rather than stopping half way through a reorder.
$movie_list_ids = array();
for ($i = $start; $i <= $stop; $i++) {
	$movie_list_ids[$i] = mcm_require_list_owner($db_connection, $stop_state[$i]);
}

// adjusting lists
//echo "adjusting lists<br>\n";
foreach ($movie_list_ids as $rank => $movie_list_id) {
	//echo $stop_state[$i]."\n";
	// The owner is in the WHERE clause as well as in the guard above, so the
	// statement itself cannot move a list belonging to anybody else.
	$query = $db_connection->prepare('UPDATE movie_lists SET list_rank = :list_rank WHERE movie_list_id = :movie_list_id AND user_id = :user_id');
	$query->bindValue(':list_rank', $rank, PDO::PARAM_INT);
	$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
	$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
	mcm_db_execute($query, 'adjust_lists: re-ranking a list');
}
//echo "done<br>\n";

// Update our db var
echo 'greatsuccess';
