<?php

// include html header and display php-login message/error
$title = 'Login';
$nav_active = 'Login';
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
		<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
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
	</div>
<?php
// include html footer
include('footer.php');
