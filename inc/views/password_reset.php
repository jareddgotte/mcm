<?php
// include html header and display php-login message/error
$title = 'Reset Password';
//$nav_active = '';
include('header.php');

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

// the user just came to our page by the URL provided in the password-reset-mail
// and all data is valid, so we show the type-your-new-password form
if ($login->passwordResetLinkIsValid() === true) {
?>
<div class="row">
	<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
		<form method="post" action="password_reset.php" name="new_password_form">
			<input type="hidden" name="user_name" value="<?php echo $_GET['user_name']; ?>">
			<input type="hidden" name="user_password_reset_hash" value="<?php echo $_GET['verification_code']; ?>">
			<h2 class="form-signin-heading">Reset Password</h2>
			<div class="form-group">
				<label for="user_password_new"><?php echo $phplogin_lang['New password']; ?></label>
				<input class="form-control" id="user_password_new" type="password" name="user_password_new" pattern=".{6,}" required autocomplete="off">
			</div>
			<div class="form-group">
				<label for="user_password_repeat"><?php echo $phplogin_lang['Repeat new password']; ?></label>
				<input class="form-control" id="user_password_repeat" type="password" name="user_password_repeat" pattern=".{6,}" required autocomplete="off">
			</div>
			<div class="form-group">
				<button class="btn btn-primary btn-block" type="submit" name="submit_new_password"><?php echo $phplogin_lang['Submit new password']; ?></button>
			</div>
		</form>
	</div>
</div>
<?php
// no data from a password-reset-mail has been provided, so we simply show the request-a-password-reset form
} else {
?>
<div class="row">
	<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
		<form method="post" action="password_reset.php" name="password_reset_form">
			<h2 class="form-signin-heading">Reset Password</h2>
			<div class="form-group">
				<label for="user_name"><?php echo $phplogin_lang['Password reset request']; ?></label>
				<input class="form-control" id="user_name" type="text" name="user_name" required>
			</div>
			<div class="form-group">
				<button class="btn btn-primary btn-block" type="submit" name="request_password_reset"><?php echo $phplogin_lang['Reset my password']; ?></button>
			</div>
		</form>
	</div>
</div>
<?php
}
?>
<!--a href="index.php"><?php echo $phplogin_lang['Back to login']; ?></a-->

<?php
// include html footer
include('footer.php');
