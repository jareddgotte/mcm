<?php

// include html header and display php-login message/error
include('header.php');

// login form box
if ($login->errors) {
	foreach ($login->errors as $error) {
		printf("<div class=\"alert alert-danger\">%s</div>", $error);
	}
}
if ($login->messages) {
	foreach ($login->messages as $message) {
		printf("<div class=\"alert alert-info\">%s</div>", $message);
	}
}
?>

	<div class="row">
		<div class="col-xs-12 col-sm-4">
			<form method="post" action="index.php" name="loginform">
				<h2 class="form-signin-heading">Please log in</h2>
				<div class="form-group">
					<input type="text" class="form-control" name="user_name" placeholder="<?php echo $phplogin_lang['Username']; ?>" required autofocus>
				</div>
				<div class="form-group">
					<input type="password" class="form-control" name="user_password" placeholder="<?php echo $phplogin_lang['Password']; ?>" required autocomplete="off">
				</div>
				<div class="form-group">
					<label class="checkbox">
						<input type="checkbox" value="1" name="user_rememberme" checked> <?php echo $phplogin_lang['Remember me']; ?>
					</label>
					<button class="btn btn-primary btn-block" type="submit" name="login"><?php echo $phplogin_lang['Log in']; ?></button>
				</div>
			</form>
			<div class="btn-group btn-group-justified">
				<a class="btn btn-default" href="register.php"><?php echo $phplogin_lang['Register new account']; ?></a>
				<a class="btn btn-default" href="password_reset.php"><?php echo $phplogin_lang['I forgot my password']; ?></a>
			</div>
		</div>
		<div class="col-xs-12 col-sm-8">
			<h2>Movie Collection Manager (MCM)</h2>
			<p>With DVDs being so small, the novelty of owning a movie, and the ever growing number of movies today, many people have huge movie collections to showcase.  Perhaps one of these movie collectors would like to entertain a guest of theirs with a movie?  However, their movie collection size could be overwhelming for their guest to decide on a movie.  The purpose of this project is to help make movie collection browsing easier along with these features:<p>
			<ul>
				<li>Allowing the host to narrow down the choices of movies for the guest to choose from by "have not seen," "seen but I wouldn't mind watching again," or other filters of their choosing.</li>
				<li>Easily moving a movie from a "have not seen" to a "have seen" list.</li>
				<li>When clicking on a movie in the collection, being shown a movie summary and trailer(s) with ease.</li>
				<li>Being able to access 100% of the website's features from any device with an HTML5 browser!</li>
				<li>With more features in the works!</li>
			</ul>
		</div>
	</div><!-- /.row -->

<?php
// include html footer
include('footer.php');
