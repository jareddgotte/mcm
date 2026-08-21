<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/inc/php-login.php');

// Whether a list is readable by anybody with its link is the one setting on a
// list that matters to somebody other than its owner. Only the owner sets it,
// and only from a page this site handed out.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();
$user_id = mcm_current_user_id();

// Kill the script if someone got here improperly
$changed_lists_json = isset($_POST['changed_lists']) ? $_POST['changed_lists'] : '';
$share_vals_json = isset($_POST['share_vals']) ? $_POST['share_vals'] : '';

// Two JSON arrays that line up position for position: the lists whose setting
// changed, and what each one changed to. Neither is typed by anybody, so a
// value that is not that shape gets the bounded refusal.
//
// The checks below are what the "=== ''" tests they replace could never do:
// json_decode() answers null for malformed JSON and never '', so a truncated or
// hand-written body used to reach a foreach over null.
$changed_lists = is_string($changed_lists_json) ? json_decode($changed_lists_json, true) : null;
$share_vals    = is_string($share_vals_json) ? json_decode($share_vals_json, true) : null;

if (!mcm_is_positional_array($changed_lists)) {
	mcm_json_error(400, 'share_lists: refused a changed list array that is not a positional array: ' . mcm_log_detail($changed_lists_json));
}
if (!mcm_is_positional_array($share_vals)) {
	mcm_json_error(400, 'share_lists: refused a share value array that is not a positional array: ' . mcm_log_detail($share_vals_json));
}
// Positions that do not line up would pair a list with somebody else's setting,
// or with no setting at all.
if (count($changed_lists) !== count($share_vals)) {
	mcm_json_error(400, 'share_lists: refused ' . count($changed_lists) . ' lists against ' . count($share_vals) . ' share values');
}

// The column is a tinyint holding 0 or 1; anything else is not an answer to
// "is this list shared". Checked here rather than in the loop below, so a value
// nobody could have sent is refused before a connection is opened.
foreach ($share_vals as $share_val) {
	if ($share_val !== 0 && $share_val !== 1 && $share_val !== '0' && $share_val !== '1') {
		mcm_json_error(400, 'share_lists: refused a share value that is not 0 or 1: ' . mcm_log_detail($share_val));
	}
}

//printf("c[%s] m[%s]\n", $current_list, $movie_id);
//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('share_lists');

// Every list is checked before any of them is written, so a request that names
// one list belonging to somebody else changes nothing rather than sharing half
// of what it asked for.
$changes = array();
foreach ($changed_lists as $k => $v) {
	$changes[] = array(mcm_require_list_owner($db_connection, $v), (int) $share_vals[$k]);
}

// adjusting lists
//echo "adjusting lists<br>\n";
foreach ($changes as $change) {
	//echo $stop_state[$i]."\n";
	// The owner is in the WHERE clause as well as in the guard above, so the
	// statement itself cannot share a list belonging to anybody else.
	$query = $db_connection->prepare('UPDATE movie_lists SET share = :share WHERE movie_list_id = :movie_list_id AND user_id = :user_id');
	$query->bindValue(':share', $change[1], PDO::PARAM_INT);
	$query->bindValue(':movie_list_id', $change[0], PDO::PARAM_INT);
	$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
	mcm_db_execute($query, 'share_lists: changing whether a list is shared');
}
//echo "done<br>\n";

// Update our db var
echo 'greatsuccess';
