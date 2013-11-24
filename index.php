<?php

error_reporting(E_ALL ^ E_NOTICE);
//ini_set('error_reporting', E_ALL);

// Jared Gotte
// jareddgotte@gmail.com
// 9/24/2013 proj begin date
// 10/19/2013 this version release date

// http://docs.themoviedb.apiary.io/
// https://www.themoviedb.org/documentation/api/wrappers-libraries
// https://github.com/alexanderdickson/waitForImages

/* When considering adding a search/add function, use this code
var api_key = 'YOUR_API_KEY';
$(document).ready(function(){
	$.ajax({
		url: 'http://api.themoviedb.org/3/search/movie?api_key=' + api_key + '&query=fight+club',
		dataType: 'jsonp',
		jsonpCallback: 'testing'
	}).error(function() {
		console.log('error')
	}).done(function(response) {
		for (var i = 0; i < response.results.length; i++) {
			$('#search_results').append('<li>' + response.results[i].title + '</li>');
		}
	});
});
*/

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

require_once('inc/php-login.php');

$login = new Login();

if (isset($_POST['login'])) {
	header('Location: http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'], true, 301);
	exit;
}

if ($login->isUserLoggedIn() === true) {
	// the user is logged in. you can do whatever you want here.
	// for demonstration purposes, we simply show the "you are logged in" view.
	include("inc/views/logged_in.php");

} else {
	// the user is not logged in. you can do whatever you want here.
	// for demonstration purposes, we simply show the "you are not logged in" view.
	include("inc/views/not_logged_in.php");
}
