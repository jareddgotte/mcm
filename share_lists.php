<?php

require_once('inc/php-login.php');
session_start();
$errors = array();

// Kill the script if someone got here improperly
$changed_lists = json_decode((isset($_POST['changed_lists'])) ? $_POST['changed_lists'] : ((isset($_GET['changed_lists'])) ? $_GET['changed_lists'] : ''));
$share_vals = json_decode((isset($_POST['share_vals'])) ? $_POST['share_vals'] : ((isset($_GET['share_vals'])) ? $_GET['share_vals'] : ''));

if ($changed_lists === '') { echo 'Error: No changed lists array given.'; exit(); }
if ($share_vals === '') { echo 'Error: No share values array given.'; exit(); }

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
foreach ($changed_lists as $k => $v) {
	//echo $stop_state[$i]."\n";
	$query = $db_connection->prepare('UPDATE movie_lists SET share = :share WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':share', $share_vals[$k], PDO::PARAM_INT);
	$query->bindValue(':movie_list_id', $v, PDO::PARAM_INT);
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
