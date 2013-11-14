<?php
	include('inc/TMDb.inc'); // https://github.com/glamorous/TMDb-PHP-API
	session_start();
	
	// Kill the script if someone got here improperly
	if (!isset($_POST['from_list']) || !isset($_POST['to_list']) || !isset($_POST['movie_id'])) {
		//printf("Not all values required POSTed: from_list[%s] to_list[%s] movie_id[%s] ", $_POST['from_list'], $_POST['to_list'], $_POST['movie_id']);
		echo "Not all values required POSTed.";
		exit();
	}
	
	// This function removes a movie from a list and adds it to another within the TMDb database
	function moveMovie ($from_list, $to_list, $movie_id) {
		// Remove from list
		$fcheck = $_SESSION['tmdb_obj']->checkItemInList($from_list, (int) $movie_id);
		if ($fcheck['item_present']) {
			$remove_response = $_SESSION['tmdb_obj']->removeItemFromList($from_list, (int) $movie_id, $_SESSION['session']);
			//var_dump($remove_response);
			// $remove_response['status_code'] == 12 means success
		}
		else {
			// error: report movie is not on the list I'm removing it from
			printf("Movie ID [%s] is not on the list I'm removing it from [%s]", $movie_id, $from_list);
		}
		
		// Add to list
		$tcheck = $_SESSION['tmdb_obj']->checkItemInList($to_list, (int) $movie_id);
		if (!$tcheck['item_present']) {
			$add_response = $_SESSION['tmdb_obj']->addItemToList($to_list, (int) $movie_id, $_SESSION['session']);
			//var_dump($add_response);
			// $add_response['status_code'] == 12 means success
		}
		else {
			// error: report movie is already on the list I'm putting it to
			printf("Movie ID [%s] is already on the list I'm putting it to [%s]", $movie_id, $to_list);
		}
		
	}
	
	moveMovie($_POST['from_list'], $_POST['to_list'], $_POST['movie_id']);
	
?>

