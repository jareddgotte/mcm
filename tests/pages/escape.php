<?php

// Renders one hostile string through every escaping context the application
// has, using the production helpers and the production list-tab markup. The
// value arrives in the query string, so the page also exercises the real route
// a request value takes to the page.
require_once(__DIR__ . '/inc/bootstrap.php');

$payload = isset($_GET['payload']) ? $_GET['payload'] : '';
$list_id = isset($_GET['list_id']) ? $_GET['list_id'] : '1';

?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>escape probe</title>
</head>
<body>
<p id="text"><?php echo mcm_html($payload); ?></p>
<img id="attr" src="img/tmdb-logo.png" alt="<?php echo mcm_html($payload); ?>">
<a id="url" href="https://example.test/search?q=<?php echo mcm_url($payload); ?>&amp;t=1">search</a>
<ul id="tabs">
<?php echo mcm_list_tab_html($list_id, $payload); ?>
</ul>
<div id="panes">
<?php echo mcm_list_pane_html($list_id); ?>
</div>
<script>
var payload = <?php echo mcm_js($payload); ?>
var list = <?php echo mcm_js(array('list_id' => $list_id, 'list_name' => $payload)); ?>
</script>
</body>
</html>
