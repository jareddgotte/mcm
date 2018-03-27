<?php

header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

// I primarily use sessions to reduce the amount of calls I make to TMDb, as well as maintaining a session
// with TMDb so I can move movies from one list to another
//session_start();

if (!isset($_SESSION['db_lists'])) $_SESSION['db_lists'] = $db_lists;
//if (!isset($_SESSION['session'])) $_SESSION['session'] = TMDB_SESSION_ID;

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
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][2];

// Create our list arrays to pass onto the TMDbDisplay class
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = 'Execute error: ' . $errorInfo[2];
}

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	//printf("%s %s\n", $row->list_rank, $row->movie_list_id);
	$movie_lists[(int)$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description, (int)$row->share);
}
ksort($movie_lists);
//var_dump($movie_lists);

// recursively convert any string, array or object to utf8 (from https://stackoverflow.com/a/38398648/2901323)
function convert_to_utf8_recursively($dat) {
  if (is_string($dat)) {
    return utf8_encode($dat);
  }
  elseif (is_array($dat)) {
    $ret = [];
    foreach ($dat as $i => $d) {
      $ret[$i] = convert_to_utf8_recursively($d);
    }
    return $ret;
  }
  elseif (is_object($dat)) {
    foreach ($dat as $i => $d) {
      $dat->$i = convert_to_utf8_recursively($d);
    }
    return $dat;
  }
  else {
    return $dat;
  }
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = 'Execute error: ' . $errorInfo[2];
	}
	$db_var[] = array('list_id' => (int)$v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'share' => $v[3], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
$db_var = json_encode(convert_to_utf8_recursively($db_var));
//var_dump($db_var);

// use to debug any json errors
/*switch (json_last_error()) {
  case JSON_ERROR_NONE:
    echo ' - No errors';
    break;
  case JSON_ERROR_DEPTH:
    echo ' - Maximum stack depth exceeded';
    break;
  case JSON_ERROR_STATE_MISMATCH:
    echo ' - Underflow or the modes mismatch';
    break;
  case JSON_ERROR_CTRL_CHAR:
    echo ' - Unexpected control character found';
    break;
  case JSON_ERROR_SYNTAX:
    echo ' - Syntax error, malformed JSON';
    break;
  case JSON_ERROR_UTF8:
    echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
    break;
  default:
    echo ' - Unknown error';
    break;
}*/

// I use the following code block to get a list of all of the movies I have so I can compare them with my own
/*echo "<!--\n";
$tmp = json_decode($db_var);
$merged = array();
foreach ($tmp as $v) {
	foreach ($v->movie_details as $v2) {
		$merged[] = $v2->title;
	}
}
function cmp ($a, $b) {
	$al = strtolower($a);
	$bl = strtolower($b);
	if ($al == $bl) {
		return 0;
	}
	return ($al < $bl) ? -1 : 1;
}
foreach ($merged as $k => $v) {
	$res = $v;
	if (substr($v, 0, 4) == 'The ')
		$res = substr($v, 4) . ', The';
	$merged[$k] = $res;
}
usort($merged, "cmp");
foreach ($merged as $k => $v) {
	printf("%s\n", $v);
}
echo "-->\n";*/

// include html header and display php-login message/error
$title = 'My Collection';
//$pre_styles = array(); // 'themes/jquery-ui-1.10.3/smoothness/jquery-ui.sortable.min'
$post_styles = array('tabdrop', 'typeahead.js-bootstrap', 'mc');
//$pre_scripts = array(); // 'libs/jquery-ui-1.10.3.sortable.min', 'jquery.ui.touch-punch.min'
$post_scripts = array('libs/ZeroClipboard.min', 'bootstrap-tabdrop', 'jquery.lazyload.min', 'libs/handlebars.min', 'typeahead.bundle.min', 'mc'); // , 'jquery.sortable'
$script = "
//console.log('" . ""/*serialize($_SESSION)*/ . "'); // debug my session variable

var user_id = '" . $_SESSION['user_id'] . "'
var db = " . $db_var . "
var base_url = '" . $base_url . "'
var poster_size_big = '" . $poster_size . "'
var poster_size_small = '" . $_SESSION['tmdb_config']['images']['poster_sizes'][0] . "'

// log how many movies I have
var movie_num = 0
for (var i = 0; i < db.length; i++) {
	var num = db[i].movie_details.length
	//console.log(num)
	movie_num += num
}
//console.log(movie_num)

// Variables to record whether or not we've loaded the table yet or not.  This is to prevent multiple loadings of each table if we keep going back and forth between tabs
var currentList, currentListPos
if (db.length > 0) {
	currentList = db[0].list_id
	currentListPos = listPos(currentList)
}
var currentSort = checkSortOrder('sort') // = 'name'
var currentOrder = checkSortOrder('order') // = 'asc'

$(function() {
	//$('.navbar').append('<div id=\"reso\"style=\"float: right; padding: 2px\">' + $('.container').outerWidth() + '</div>')
	$('.tab-pane img:first-child').bind('transitionend webkitTransitionEnd oTransitionEnd', function(e) {
		//console.log(e.originalEvent.propertyName)
		if (e.originalEvent.propertyName === 'width') {
			$(window).trigger('scroll')
		}
	})
	$(window).on('resize', function () {
		var cw = $('.container').outerWidth()
		var m = 5
		var w = 195
		var n = Math.round((cw+2*m-30)/(2*m+w))

		for (var i = 0; i <= 5; i++) {
			m += (i % 2 === 0)? i : -i
			w = (cw+2*m-30)/n-2*m
			if (w % 1 === 0) {
				break
			}
		}
		w = Math.floor(w)
		h = Math.round((w-10)*278/185+10)
		//$('#reso').html('n:' + n + ' m:' + m + ' w:' + w + ' h:' + h + ' ' + cw)
		$('.tab-pane img').css('width', w + 'px').css('height', h + 'px').css('margin', m + 'px')
		$('.tab-pane .posters').css('margin', '0 -' + m + 'px')
	})
	setTimeout(function() { $(window).trigger('resize') }, 1)
})
";
include('header.php');

// if you need users's information, just put them into the $_SESSION variable and output them here

//echo $phplogin_lang['You are logged in as'] . $_SESSION['user_name'] ."<br />\n";
//echo $login->user_gravatar_image_url;
//echo $phplogin_lang['Profile picture'] .'<br/>'. $login->user_gravatar_image_tag;

if (isset($errors)) var_dump($errors);

$list_tabs = '';
$list_containers = '';
foreach($movie_lists as $v) {
	$list_tabs .= sprintf("<li data-listid=\"%s\"><a href=\"#%s\" data-toggle=\"pill\">%s</a></li>\n", $v[0], $v[0], $v[1]);
	$list_containers .= sprintf("<div class=\"tab-pane\" id=\"%s\"></div>\n", $v[0]);
}

?>

  <ul class="nav nav-pills" id="list-tabs">
    <?php echo $list_tabs; ?>
  </ul>
  <div id="main-alerts"></div>
  <div class="row" id="list-control">
    <div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="add_movie" placeholder="Add a Movie"></div>
    <div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="search_collection" placeholder="Search My Collection"></div>
    <div class="col-xs-6 col-sm-2">
      <div class="btn-group btn-block">
        <button type="button" class="btn btn-default btn-block dropdown-toggle" data-toggle="dropdown">View <span class="caret"></span></button>
        <ul class="dropdown-menu btn-block pull-right" id="sort-order">
          <li class="dropdown-header">Sort by</li>
          <li><a class="sort" href="#name" id="name">Name</a></li>
          <li><a class="sort" href="#date" id="date">Release Date</a></li>
          <li class="divider"></li>
          <li class="dropdown-header">Order by</li>
          <li><a class="order" href="#asc" id="asc">Ascending</a></li>
          <li><a class="order" href="#desc" id="desc">Descending</a></li>
        </ul>
      </div>
    </div><!-- /.col-**-* -->
    <div class="col-xs-6 col-sm-2">
      <div class="btn-group btn-block" id="list-options">
        <button type="button" class="btn btn-default btn-block dropdown-toggle" data-toggle="dropdown">Options <span class="caret"></span></button>
        <ul class="dropdown-menu btn-block pull-right">
          <li><a href="#rename">Rename List</a></li>
          <li><a href="#import">Import List</a></li>
          <li class="alert-danger"><a href="#delete">Delete List</a></li>
        </ul>
      </div>
    </div><!-- /.col-**-* -->
  </div>
  <div class="tab-content" id="list-containers">
    <?php echo $list_containers; ?>
  </div>
  <div class="modal fade" id="dialog" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
      </div>
    </div>
  </div><!-- /.modal -->
  <div class="modal fade" id="adjust-dialog" tabindex="-1" aria-hidden="true">
  </div><!-- /.modal -->
  <div class="modal fade" id="share-dialog" tabindex="-1" aria-hidden="true">
  </div><!-- /.modal -->
  <div class="modal fade" id="import-dialog" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
          <h4 class="modal-title">Import TMDb List</h4>
        </div>
        <div class="modal-body">
          <div id="import-alerts"></div>
          <p>Please enter the TMDb List ID you wish to import.</p>
          <input type="text" class="form-control" id="import-tmdb_list_id" placeholder="e.g. 5212934a760ee36af148407d" value=''>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="import-submit">Import</button>
        </div>
      </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
  </div><!-- /.modal -->
  <div class="modal fade" id="create-dialog" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
          <h4 class="modal-title">Create a New List</h4>
        </div>
        <div class="modal-body">
          <div id="create-alerts"></div>
          <p>Please enter the name of the list you wish to create.</p>
          <input type="text" class="form-control" id="create-list_name" placeholder="e.g. Want to See" value=''>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="create-submit">Create</button>
        </div>
      </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
  </div><!-- /.modal -->
  <div class="modal fade" id="delete-dialog" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
          <h4 class="modal-title">Delete List</h4>
        </div>
        <div class="modal-body">
          <div class="alert alert-danger" style="margin:20px" id="delete-alert">
            <p>Are you <strong>sure</strong> you want to <strong>delete</strong> this movie list?</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger" id="list-delete-yes">Yes</button>
          <button type="button" class="btn btn-default" data-dismiss="modal">No, I do not want to</button>
        </div>
      </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
  </div><!-- /.modal -->
  <div class="modal fade" id="rename-dialog" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
          <h4 class="modal-title">Rename List</h4>
        </div>
        <div class="modal-body">
          <div id="rename-alerts"></div>
          <p>Please enter the new name of this list.</p>
          <input type="text" class="form-control" id="rename-list_name" placeholder="e.g. Want to See" value=''>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="rename-submit">Rename</button>
        </div>
      </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
  </div><!-- /.modal -->
<?php
// include html footer
include('footer.php');
