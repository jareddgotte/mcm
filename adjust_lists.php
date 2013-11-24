<?php

require_once('inc/php-login.php');
session_start();
$errors = array();

// Kill the script if someone got here improperly
$stop_state = json_decode((isset($_POST['stop_state'])) ? $_POST['stop_state'] : ((isset($_GET['stop_state'])) ? $_GET['stop_state'] : ''));
$start_pos = (isset($_POST['start_pos'])) ? $_POST['start_pos'] : ((isset($_GET['start_pos'])) ? $_GET['start_pos'] : '');
$stop_pos = (isset($_POST['stop_pos'])) ? $_POST['stop_pos'] : ((isset($_GET['stop_pos'])) ? $_GET['stop_pos'] : '');

if ($stop_state === '') { echo 'Error: No stop state given.'; exit(); }
if ($start_pos === '') { echo 'Error: No start pos given.'; exit(); }
if ($stop_pos === '') { echo 'Error: No stop pos given.'; exit(); }

//printf("c[%s] m[%s]\n", $current_list, $movie_id);
//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

// adjusting lists
//echo "adjusting lists<br>\n";
for ($i = $start_pos; $i <= $stop_pos; $i++) {
	//echo $stop_state[$i]."\n";
	$query = $db_connection->prepare('UPDATE movie_lists SET list_rank = :list_rank WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':list_rank', $i, PDO::PARAM_STR);
	$query->bindValue(':movie_list_id', $stop_state[$i], PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
	}
}
//echo "done<br>\n";

if (isset($errors)) if (count($errors) > 0) var_dump($errors);
else {
	// Update our db var
	echo 'greatsuccess';
}
