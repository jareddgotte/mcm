<?php
// include html header and display php-login message/error
$title = 'Register';
$nav_active = 'Register';
include('header.php');

// show negative messages
if ($registration->errors) {
	foreach ($registration->errors as $error) {
		printf("<div class=\"alert alert-danger\">%s</div>", $error);
	}
}

// show positive messages
if ($registration->messages) {
	foreach ($registration->messages as $message) {
		printf("<div class=\"alert alert-info\">%s</div>", $message);
	}
}

// show register form
// - the user name input field uses a HTML5 pattern check
// - the email input field uses a HTML5 email type check
if (!$registration->registration_successful && !$registration->verification_successful) { ?>

	<div class="row">
		<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
			<form method="post" action="register.php" name="registerform">
				<h2>Please register</h2>
				<div class="form-group">
					<label for="user_name"><?php echo $phplogin_lang['Register username']; ?></label>
					<input type="text" class="form-control" id="user_name" name="user_name" pattern="[a-zA-Z0-9]{2,64}" placeholder="Username" required autofocus>
				</div>
				<div class="form-group">
					<label for="user_email"><?php echo $phplogin_lang['Register email']; ?></label>
					<input type="email" class="form-control" id="user_email" name="user_email" placeholder="Email" required>
				</div>
				<div class="form-group">
					<label for="user_password_new"><?php echo $phplogin_lang['Register password']; ?></label>
					<input type="password" class="form-control" id="user_password_new" name="user_password_new" pattern=".{6,}" placeholder="Password" required autocomplete="off">
				</div>
				<div class="form-group">
					<label for="user_password_repeat"><?php echo $phplogin_lang['Register password repeat']; ?></label>
					<input type="password" class="form-control" id="user_password_repeat" name="user_password_repeat" pattern=".{6,}" placeholder="Password Repeat" required autocomplete="off">
				</div>
				<div class="form-group">
					<img src="inc/showCaptcha.php" alt="captcha" />
				</div>
				<div class="form-group">
					<label for="captcha"><?php echo $phplogin_lang['Register captcha']; ?></label>
					<input type="text" class="form-control" id="captcha" name="captcha" placeholder="" required>
				</div>
				<div class="form-group">
					<button class="btn btn-primary btn-block" type="submit" name="register"><?php echo $phplogin_lang['Register']; ?></button>
				</div>
			</form>
		</div>
	</div>
<?php
}
else {
?>
			<a class="btn btn-default" href="login.php"><?php echo $phplogin_lang['Back to login']; ?></a>
<?php
}
// include html footer
include('footer.php');
