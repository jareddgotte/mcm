<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="">
<meta name="keywords" content="">
<meta name="author" content="Jared Gotte">
<title><?php echo (isset($title)) ? "MCM - $title" : 'Movie Collection Manager'; ?></title>

<!--link rel="shortcut icon" href="favicon.ico"-->
<?php if (isset($pre_styles)) foreach ($pre_styles as $v) printf("<link rel=\"stylesheet\" href=\"css/%s.css\">\n", $v); ?>
<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.2/css/bootstrap.min.css">
<?php if (isset($post_styles)) foreach ($post_styles as $v) printf("<link rel=\"stylesheet\" href=\"css/%s.css\">\n", $v); ?>

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<script>window.jQuery || document.write('<script src="js/libs/jquery-1.10.2.min.js">\x3C/script>')</script>
<?php if (isset($pre_scripts)) foreach ($pre_scripts as $v) printf("<script src=\"js/%s.js\"></script>\n", $v); ?>
<script src="//netdna.bootstrapcdn.com/bootstrap/3.0.2/js/bootstrap.min.js"></script>
<script>if (typeof($.fn.modal) === 'undefined') document.write('<link rel="stylesheet" href="css/bootstrap.min.css"><script src="js/libs/bootstrap.min.js">\x3C/script>')</script>
<script src="js/nav.js"></script>
<?php if (isset($post_scripts)) foreach ($post_scripts as $v) printf("<script src=\"js/%s.js?v=%s\"></script>\n", $v, rand(1, 20000)); ?>
<?php echo (isset($script)) ? '<script>' . $script . "</script>\n" : ''; ?>

</head>
<body>

<div class="container">
	<nav class="navbar navbar-default">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#header-nav">
				<span class="sr-only">Toggle navigation</span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand" href=".">Movie Collection Manager</a>
		</div>
		
		<div class="collapse navbar-collapse" id="header-nav">
			<?php
			$nav_active = (isset($nav_active)) ? $nav_active : false;
			$nav_auth = '';
			if (isset($sharing) === FALSE) {
				$nav_auth .= '
					<ul class="nav navbar-nav">
						<li><a href="#create">Create List</a></li>
						<li><a href="#adjust">Adjust Lists</a></li>
						<li><a href="#share">Share Lists</a></li>
					</ul>';
			}
			$nav_auth .= '
				<ul class="nav navbar-nav navbar-right">
					<li' . (($nav_active == 'About') ? ' class="active"' : '') . '><a href="about.php">About</a></li>
					<li class="dropdown">
						<a class="dropdown-toggle" data-toggle="dropdown" href="#">' . ((isset($_SESSION['user_name'])) ? $_SESSION['user_name'] : '') . ' <span class="caret"></span></a>
						<ul class="dropdown-menu">
							<li' . (($nav_active == 'Account') ? ' class="active"' : '') . '><a href="edit.php">Account</a></li>
							<li class="divider"></li>
							<li><a href="index.php?logout">' . $phplogin_lang['Logout'] . '</a></li>
						</ul>
					</li>
				</ul>
			';
			$nav_noauth = '
				<ul class="nav navbar-nav">
					<li' . (($nav_active == 'Login') ? ' class="active"' : '') . '><a href="login.php">Login</a></li>
					<li' . (($nav_active == 'Register') ? ' class="active"' : '') . '><a href="register.php">Register</a></li>
				</ul>
				<ul class="nav navbar-nav navbar-right">
					<li' . (($nav_active == 'About') ? ' class="active"' : '') . '><a href="about.php">About</a></li>
				</ul>
			';
			if (isset($login))
				if ($login->isUserLoggedIn() === true)
					echo $nav_auth;
				else echo $nav_noauth;
			else echo $nav_noauth;
			?>
		</div><!-- /.navbar-collapse -->
	</nav>
