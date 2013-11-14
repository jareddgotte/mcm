<?php
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

	require_once('inc/config.php');

	// This is the library which houses most of the functions I use to communicate with the TMDb database.
	require_once('inc/TMDb.inc'); // https://github.com/glamorous/TMDb-PHP-API

	// I primarily use sessions to reduce the amount of calls I make to TMDb, as well as maintaining a session
	// with TMDb so I can move movies from one list to another
	session_start();
	
	
	
	// In a perfect world, this call is only made once per session
	if (!isset($_SESSION['tmdb_obj'])) {
		$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
	}
	
	// This token will only be set in the login page
	if (isset($_SESSION['token'])) {
		if (!isset($_SESSION['session'])) { // Make sure we don't request multiple sessions
			$session = $_SESSION['tmdb_obj']->getAuthSession($_SESSION['token']['request_token']);
			if (isset($session['status_code'])) {
				if ($session['status_code'] == 17) { // 17 means "Session denied"
					$_SESSION['logged_in'] = FALSE; // This variable lets the functions below know if we're logged in or not
				}
			}
			else { // If I authorized properly, the following happens
				$_SESSION['session'] = $session['session_id'];
				$_SESSION['logged_in'] = TRUE;
			}
		}
		else $_SESSION['logged_in'] = TRUE;
	}
	else $_SESSION['logged_in'] = FALSE;
	
	// Get the tmdb config so we can pass it onto the TMDbDisplay class for images
	if (!isset($_SESSION['tmdb_config'])) {
		$_SESSION['tmdb_config'] = $_SESSION['tmdb_obj']->getConfiguration();
	}
	$base_url = $_SESSION['tmdb_config']['images']['base_url'];
	$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][1];
	
	// Create our list arrays to pass onto the TMDbDisplay class
	$WTSList = $_SESSION['tmdb_obj']->getList(WTS_LIST_KEY);
	$WTSListItemsJSON = json_encode($WTSList['items']);

	$HNSList = $_SESSION['tmdb_obj']->getList(HNS_LIST_KEY);
	$HNSListItemsJSON = json_encode($HNSList['items']);

	$SeenList = $_SESSION['tmdb_obj']->getList(SEEN_LIST_KEY);
	$SeenListItemsJSON = json_encode($SeenList['items']);

	// I use the following code block to get a list of all of the movies I have so I can compare them with my own
	$merged = array_merge($WTSList['items'], $HNSList['items'], $SeenList['items']);
	/*function cmp ($a, $b) {
		$al = strtolower($a['title']);
		$bl = strtolower($b['title']);
		if ($al == $bl) {
			return 0;
		}
		return ($al < $bl) ? -1 : 1;
	}
	foreach ($merged as $k => $v) {
		$res = $v['title'];
		if (substr($v['title'], 0, 4) == 'The ')
			$res = substr($v['title'], 4) . ', The';
		$merged[$k]['title'] = $res;
	}
	usort($merged, "cmp");
	foreach ($merged as $k => $v) {
		printf("%s\n", $v['title']);
	}*/
?>
<!DOCTYPE html>
<html>
<head>

<meta name="description" content="">
<meta name="keywords" content="">
<meta name="author" content="Jared Gotte">
<meta charset="UTF-8">
<title>Movie Collection Manager</title>

<link rel="stylesheet" href="css/reset.css">
<link rel="stylesheet" href="css/main.css">
<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.min.css">

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>
<script>
window.jQuery || document.write('<link rel="stylesheet" href="css/themes/jquery-ui-1.10.3/smoothness/jquery-ui.min.css"><script src="js/libs/jquery-1.10.2.min.js">\x3C/script><script src="js/libs/jquery-ui-1.10.3.min.js">\x3C/script>');
</script>
<script src="js/jquery.lazyload.min.js"></script>
<script src="js/jquery.waitforimages.min.js"></script>
<script>
console.log('<?php //echo serialize($_SESSION); ?>'); // debug my session variable
console.log('<?php echo count($merged); ?>'); // debug how many movies I have

var db = {
	'WTS' : { // Want to See
		'id'   : '<?php echo WTS_LIST_KEY; ?>', // WTS list ID from TMDb
		'dlog' : 0, // This variable keeps track of whether or not we've displayed this information or not, so I do not redisplay the table every time I switch tabs
		'JSON' : <?php echo $WTSListItemsJSON; ?> // This JSON includes all of the movie information related to the respective list
	},
	'HNS' : { // Haven't Seen
		'id'   : '<?php echo HNS_LIST_KEY; ?>',
		'dlog' : 0,
		'JSON' : <?php echo $HNSListItemsJSON; ?>
	},
	'Seen' : { // Already Seen
		'id'   : '<?php echo SEEN_LIST_KEY; ?>',
		'dlog' : 0,
		'JSON' : <?php echo $SeenListItemsJSON; ?>
	}
}

// Variables to record whether or not we've loaded the table yet or not.  This is to prevent multiple loadings of each table if we keep going back and forth between tabs
var currentName = 'WTS', currentSort = 'name', currentOrder = 'asc';

// Move the movie locally so we don't have to refresh the page to see the update
function moveMovie (from_list, to_list, movie_id) {
	var flist = db[from_list]['JSON'], element;
	for (var key in flist) {
		if (flist[key]['id'] == movie_id) {
			element = flist[key];
			delete flist[key];
			break;
		}
	}
	db[to_list]['JSON'].push(element);
}

// This function is necessary because for every time we switch tables, we must enable the Tooltip and Dialog functions.
function enableFunctions() {
	var selected_tab = $( ".ui-state-active a:first-child" ).attr( "href" );
	
	$( selected_tab + " img.lazy" ).lazyload({
		threshold: 200,
		effect: "fadeIn",
		effect_speed: 200
	});
	
	// This basically makes the tooltip follow the cursor around rather than it having a static position.
	$( selected_tab + " .cell" ).mousemove( function( e ) {
		// from e you can get the pageX(left position) and pageY(top position) 
		$( ".ui-tooltip" ).css({ "left" : e.pageX + 10, "top" : e.pageY + 10 });
	}).mouseout( function() { 
		$( ".ui-tooltip" ).css( "display", "none" );
	}).tooltip ({ // This code chunk turns the tooltip into what the image's alt attr has as its value.
		content: function () {
			return $(this).attr( "alt" );
		},
		show: {
			effect: "fade",
			delay: 10
		},
		hide: {
			effect: "fade",
			delay: 10
		},
		items: "img[alt]" // Enables tooltips for an image's alt attr
	}).on( "click", function() { // The following bit of code is for opening the Dialog box for the movie we are interested in finding out more about.
		$( "#dialog #overview" ).css( "display", "none" );
		$( "#dialog" ).html( "<img class=\"loading\" src=\"img/loading.gif\" />" ) // Shows an animated "loading" gif as the info is loading.
		.dialog({ position: { my: "center", at: "center", of: window } }); // Positions the Dialog window in the very center of the screen.
		//console.time('ajax exec time'); // https://developer.mozilla.org/en-US/docs/Web/API/console.time?redirectlocale=en-US&redirectslug=DOM%2Fconsole.time
		var movie_id = $(this)[0]['id'];
		$.ajax({
			type: "POST",
			url: "dialog.php", // dialog.php is where we handle the actual HTML that shows up in the Dialog box.
			data: { id: movie_id }
		})
		.done(function( msg ) {
			//console.timeEnd('ajax exec time');
			$( "#dialog" ).html(msg); // Useful for debugging dialog.php
			$( "#dialog #icons " + selected_tab ).attr( "src", "img/" + selected_tab.substr(1).toLowerCase() + "-icon-gray.png" ).css( "cursor", "default" ).addClass( "dnc" ); // Change icon to gray
			$( "#dialog #icons img" ).on( "click", function() {
				if ($(this).hasClass( "dnc" ) == false) {
					$( "#dialog" ).html( "<img class=\"loading\" src=\"img/loading.gif\" />" )
					.dialog({ position: { my: "center", at: "center", of: window } });
					var thref = $(this).attr( "id" ), tlist = db[thref]['id'];
					$.ajax({
						type: "POST",
						url: "move.php", // move.php is where we handle the actual movement of movie between TMDb lists
						data: { from_list : db[currentName]['id'], to_list : tlist, movie_id: movie_id }
					})
					.done(function( msg ) {
						//console.log(msg); // Useful for debugging move.php
						moveMovie(currentName, thref, movie_id);
						$( "#dialog" ).dialog( "close" );
						displayTable();
						db[currentName]['dlog'] = 0;
						db[thref]['dlog'] = 0;
					});
				}
				return false;
			}).mousemove( function( e ) { 
				// from e you can get the pageX(left position) and pageY(top position) 
				$( ".ui-tooltip" ).css({ "left" : e.pageX + 10, "top" : e.pageY + 10 });
			}).mouseout( function() { 
				$( ".ui-tooltip" ).css( "display", "none" );
			}).tooltip ({ // This code chunk turns the tooltip into what the image's alt attr has as its value.
				content: function () {
					return $(this).attr( "alt" );
				},
				show: {
					effect: "fade",
					delay: 10
				},
				hide: {
					effect: "fade",
					delay: 10
				},
				items: "img[alt]" // Enables tooltips for an image's alt attr
			});
			
			$( "#dialog #accordion" ).accordion();

			// When images are done loading, calculate width and set Overview's width to it
			$( "#dialog #details" ).waitForImages( function () {
				var overview_height = $( "#dialog #overview" ).outerHeight();
				$( "#dialog #overview" ).css( "width", $( "#dialog #details" ).outerWidth() + "px")
				.css( "display", "block" )
				.css( "height", "18px" )
				.css( "white-space", "nowrap" )
				.css( "text-overflow", "ellipsis" )
				.css( "-o-text-overflow", "ellipsis" )
				.mouseover(function() {
					$(this).css( "white-space", "normal" )
					.stop()
					.animate({
						height: overview_height + "px"
					}, {
						complete: function() {
							$(this).css( "white-space", "normal" );
						}
					});
				})
				.mouseout(function() {
					$(this).css( "white-space", "normal" )
					.stop()
					.animate({
						height: "18px"
					}, {
						complete: function() {
							$(this).css( "white-space", "nowrap" );
						}
					});
				});
				$( "#dialog .ui-accordion-content" ).css( "height", parseInt($( "#dialog #accordion" ).css( 'width' ), 10) * 10 / 16 + "px" );
				$( "#dialog" ).dialog({ position: { my: "center", at: "center", of: window } });
			});
		});
		$( "#dialog" ).dialog({ title: $(this)[0]['firstElementChild']['alt'] })
		.dialog( "open" )
		.on( "dialogclose", function() { $(this).html( "" ); }); // Prevent trailer from playing after closing out of the dialog
	});
}

// This function actually displays the table of movies depending on which table we want to display
function displayTable () {
	//console.log('displaying table');
	
	var ListItemsJSON = db[currentName]['JSON'];
	
	if (currentSort == 'name') {
		ListItemsJSON.sort( function(a, b) { // Sorts alphabetically by title in asc order.
			var x = a.title.toLowerCase(), y = b.title.toLowerCase();
			if (x.substr(0,4) == 'the ') x = x.substr(4); // Do not consider the word "the" if it's in the beginning of the title while sorting by name.
			if (y.substr(0,4) == 'the ') y = y.substr(4);
			return x < y ? -1 : x > y ? 1 : 0;
		});
	}
	else if (currentSort == 'date') {
		ListItemsJSON.sort( function(a, b) { // Sorts alphabetically by title in asc order.
			var x = a.title.toLowerCase(), y = b.title.toLowerCase();
			if (x.substr(0,4) == 'the ') x = x.substr(4); // Do not consider the word "the" if it's in the beginning of the title while sorting by name.
			if (y.substr(0,4) == 'the ') y = y.substr(4);
			return x > y ? -1 : x < y ? 1 : 0;
		});
		ListItemsJSON.sort( function(a, b) { // Sorts alphabetically by date in asc order.
			var x = a.release_date, y = b.release_date;
			return x < y ? -1 : x > y ? 1 : 0;
		});
	}
	else {
		console.log( 'unexpected sorting order in displayTable() => ' + currentSort );
		return false;
	}

	if (currentOrder == 'desc') ListItemsJSON.reverse();

	// Create the HTML we are going to use as our table of movies
	var html = "<div class=\"table\">";
	$.each(ListItemsJSON, function() {
		if (this['poster_path'] != null)
			html += "<div class=\"cell\" id=\"" + this['id'] + "\">\
		           <img class=\"lazy\" data-original=\"<?php echo $base_url . $poster_size; ?>" + this['poster_path'] + "\" alt=\"" + this['title'] + "\" \>\
		           <div class=\"hidden\">" + this['title'] + "</div>\
		           </div>\n";
	});
	html += "</div>";
	$( '#' + currentName ).html(html); // Set that HTML now
	
	enableFunctions(); // We enable our functions now so tooltips and the dialog box work.
}

$(document).ready( function () {
	// We care about hashes so our table can go to the correct tab upon refreshing or directly being linked to a particular list
	var hash = window.location.hash.substr(1,window.location.hash.length - 5), tab = $.inArray(hash, Object.keys(db));
	//console.log(hash);
	if (hash.length > 0)
		currentName = hash;
	$( "#tabs" ).tabs({ active: tab > -1 ? tab : 0 });
	$( "#tabs .ui-tabs-nav" ).append( // Insert our sort menu within the tab div
		"<ul id=\"menu\" style=\"float:right;\">\
			<li><a href=\"#\">Sort by...</a>\
				<ul>\
					<li><a class=\"sort\" href=\"#name\">Name</a></li>\
					<li><a class=\"sort\" href=\"#date\">Release Date</a></li>\
					<li class=\"sortOrder first\"><a class=\"order\" href=\"#asc\">Ascending order</a></li>\
					<li class=\"sortOrder\"><a class=\"order\" href=\"#desc\">Descending order</a></li>\
				</ul>\
			</li>\
		</ul>"
	);
	
	// The following code block is mostly for making the Sort menu collapse after hovering off of it after a set amount of time
	var menu = $( "#menu" ), blurTimer, blurTimeAbandoned = 150; // time in ms for when menu is considered no longer in focus
	menu.menu({ position: { my: "bottom", at: "center-3 bottom-37" } })
	.on( 'menufocus' , function() {
		clearTimeout(blurTimer);
	})
	.on( 'menublur' , function() {
		blurTimer = setTimeout( function() {
			menu.menu( "collapseAll", null, true );
		}, blurTimeAbandoned);
	});

	$('#menu li ul li a').css('min-width', $('#menu li ul li').outerWidth() + 'px'); // post process styling
		
	$( "#menu a" ).on( "click", function() { // This code handles the resorting of the table after clicking an option in the Sorting menu
		var href = $(this).attr( "href" );

		if (href == '#') return false;

		$( "#dialog" ).dialog( "close" );

		if ($(this).hasClass( "sort" ))
			currentSort = href.substr(1);
		else if ($(this).hasClass( "order" ))
			currentOrder = href.substr(1);
		// So we don't redisplay unchanged data
		db['WTS']['dlog'] = 0;
		db['HNS']['dlog'] = 0;
		db['Seen']['dlog'] = 0;
		db[currentName]['dlog'] = 1;

		displayTable();

		return false;
	});
	
	displayTable(); // By default, display the "What to See" table
	db[currentName]['dlog'] = 1;
	
	// Load our tables for their respective topics
	$( "#tabs ul li a.ui-tabs-anchor" ).on( "click", function( event ) {
		$(this).blur();
		$( "#dialog" ).dialog( "close" );
		currentName = $(this).attr( "href" ).substr(1);
		window.location.hash = currentName + '-tab';
		if (db[currentName]['dlog'] == 0) {
			displayTable();
			db[currentName]['dlog'] = 1;
		}
	});

	// Init config for our dialog
	$( "#dialog" ).dialog({ autoOpen: false, width: 'auto', height: 'auto' });
	
	// Behavior for the icons at the very bottom left of the page
	// It's much easier to add and remove movies from my lists from MovieDB's pages so I linked to them
	$( "#footer #footer-nav img" ).on( "click", function() {
		var id = $(this).attr( "id" );
		if (id === "edit")
			window.open( "https://www.themoviedb.org/list/" + db[$( ".ui-state-active a:first-child" ).attr("href").substr(1)]['id'], "_blank" );
		else if (id === "login") {
			var html = "<ol class='decimal'>\
			            	<li><a href='login.php' target='_blank'>Login</a> first, then after you successfully login <strong>and</strong><br /> Allow this Auth Request, go to the next step.</li>\
				            <li><a href='./'>Refresh</a> this page to be logged in.</li>\
			            </ol>";
			$( "#dialog" ).dialog({ position: { my: "center", at: "center", of: window } }) // Positions the Dialog window in the very center of the screen.
			.html(html)
			.dialog({ title: "Login" })
			.dialog({ position: { my: "center", at: "center", of: window } })
			.dialog( "open" );
		}
		else if (id === "logout") {
			$.ajax({
				url: "logout.php",
			})
			.done(function( msg ) {
				window.location = './';
			});
		}
		else if (id === "tmdb-logo") {
			window.open( "https://www.themoviedb.org/", "_blank" );
		}
		return false;
	}).mousemove( function( e ) { 
		// from e you can get the pageX(left position) and pageY(top position) 
		$( ".ui-tooltip" ).css({ "left" : e.pageX + 10, "top" : e.pageY - 24 });
	}).mouseout( function() { 
		$( ".ui-tooltip" ).css( "display", "none" );
	}).tooltip ({ // This code chunk turns the tooltip into what the image's alt attr has as its value.
		content: function () {
			return $(this).attr( "alt" );
		},
		show: {
			effect: "fade",
			delay: 10
		},
		hide: {
			effect: "fade",
			delay: 10
		},
		items: "img[alt]" // Enables tooltips for an image's alt attr
	})

});
</script>
<style>
.ui-menu {
	padding: 0;
	line-height: 1;
}
#menu li a {
	line-height: 1.2;
}
#tabs {
	margin: 10px;
}
.ui-tabs .ui-tabs-panel {
	padding: 1em .8em;
}
.sortOrder.first {
	padding-top: 10px !important;
}
.sortOrder {
	font-size: 80%;
}
.table {
	overflow: hidden;
}
.cell {
	/* Width and height change here depending on the dimensions of graphic we want to use as our movie poster */
	/* [disabled]wwidth: 92px; */
	/* [disabled]hheight: 138px; */
	width: 154px;
	height: 231px;
	float: left;
	margin: 5px;
	cursor: pointer;
}
.cell .hidden {
	opacity: 0;
	text-indent: 100%;
	white-space: nowrap;
	overflow: hidden;
}
.cell img {
	-webkit-box-shadow: 0px 1px 8px rgb(102, 102, 102);
	-moz-box-shadow: 0px 1px 8px rgb(102, 102, 102);
	box-shadow: 0px 1px 8px rgb(102, 102, 102);
}
.ui-dialog .ui-dialog-title {
	margin: 0;
	padding: .1em 0;
}
#dialog {
	min-width: 450px;
}
#dialog .loading {
	margin: auto;
	display: block;
	padding: 5%;
}
#dialog .row {
	margin-bottom: 10px;
}
#dialog .row a img {
	vertical-align: middle;
}
#dialog .hidden {
	display: none;
}
#dialog #overview {
	line-height: 1.2;
	overflow: hidden;
}
#dialog iframe {
	margin: 0 auto;
	display: block;
}
#dialog #noTrailer {
	font-style: italic;
}
#dialog .trailer {
	line-height: 1.7;
}
#dialog #icons {
	display: inline;
	float: right;
}
#dialog #icons img {
	margin-left: 3px;
	cursor: pointer;
}
#dialog ol.decimal {
	list-style-type: decimal;
	margin-left: 20px;
}
#dialog ol.decimal li {
	line-height: 1.6;
}
hr {
	border-width: 0;
	border-color: #000;
	border-top-width: 1px;
	margin: 0;
}
#footer {
	padding-top: 3px;
}
#footer #footer-nav img {
	margin: 0 0 0 3px;
	padding: 0;
	display: inline;
	cursor: pointer;
}
#footer #footer-nav .right {
	float: right;
}
#footer #credits {
	border-top: 1px solid black;
	padding: 3px;
}
</style>

</head>
<body>
<div id="main">
	<div id="tabs">
		<ul>
			<li><a href="#WTS">Want to See</a></li>
			<li><a href="#HNS">Haven't Seen</a></li>
			<li><a href="#Seen">Already Seen</a></li>
		</ul>
		<div id="WTS"></div>
		<div id="HNS"></div>
		<div id="Seen"></div>
	</div>
	<div id="dialog"></div>
	<hr />
	<div id="footer">
		<div id="footer-nav">
			<img id="edit" src="img/edit-list-icon.png" alt="Edit List" />
			<?php
				if ($_SESSION['logged_in'])
					echo '<img id="logout" src="img/logout-icon.png" alt="Log Out" />';
				else echo '<img id="login" src="img/login-icon.png" alt="Log In" />';
			?>
			<img id="tmdb-logo" class="right" src="img/tmdb-logo.png" alt="TMDb" />
	
		</div>
		<div id="credits">
			<div id="copyright">&copy; 2013 <a href="http://www.jaredgotte.com" target="_blank">Jared Gotte</a>. Apache License 2.0.</div>
			<div id="attribution">This product uses the TMDb API but is not endorsed or certified by TMDb. </div>
		</div>
	</div>
</div><!--main-->
</body>
</html>
