<?php

// Error reporting is set by inc/bootstrap.php from the configuration.

// Jared Gotte
// jareddgotte@gmail.com
// 9/24/2013 proj begin date
// 10/19/2013 this version release date

// This file used to carry a worked example of the search it wanted: a browser
// calling the movie database directly, with this site's credential written into
// the script. That is the thing issue #36 removed, so the example is gone with
// it. Searching goes through tmdb.php, which reaches the credential only via
// inc/tmdb.php; see the TMDb access section of AGENTS.md.

/* 
	****  Purpose
	The purpose of this site is to be able to view my movies in a visual manner
	rather than browsing through folders and having to individually IMDBing and
	YouTubing them for interest.
	****  Design
	The way that I designed this site is for minimal network usuage.  Therefore,
	I utilize AJAX when possible and use the least amount of calls to the TMDb
	database which houses most of the information about my movies.
	****  How to add and delete movies on my lists
	In order to add or delete movies from my lists, you must use TMDb's website,
	which a link to each of my lists can be found at the bottom of the page.
	****  Room for improvement
	I can add a functionality to add and remove movies from my lists, as well as
	add more lists to the page.
	I can add the ability for users to login and organize their movies themselves.
*/

require_once(__DIR__ . '/inc/bootstrap.php');
// Not for a guard: this page guards nothing. The signed-in view hands
// mcm_csrf_token() to the browser, which is what lets the requests that page
// makes carry a token the mutation endpoints will accept.
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once(__DIR__ . '/inc/php-login.php');

$login = new Login();

if (isset($_POST['login'])) {
	// Same destination as ever - this page - but named by the server rather
	// than by the request: SCRIPT_NAME is what the web server resolved, and the
	// host comes from the configuration or is left off entirely.
	mcm_redirect($_SERVER['SCRIPT_NAME'], 301);
}

if ($login->isUserLoggedIn() === true) {
	// the user is logged in. you can do whatever you want here.
	// for demonstration purposes, we simply show the "you are logged in" view.
	include(__DIR__ . '/inc/views/logged_in.php');

} else {
	// the user is not logged in. you can do whatever you want here.
	// for demonstration purposes, we simply show the "you are not logged in" view.
	include(__DIR__ . '/inc/views/not_logged_in.php');
}
