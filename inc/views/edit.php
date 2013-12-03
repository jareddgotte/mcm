<?php
// include html header and display php-login message/error
$title = 'Edit Account';
$nav_active = 'Account';
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

//echo '<h2>'. $_SESSION['user_name'] .' '. $phplogin_lang['Edit title'] .'</h2>';
?>

<div class="row">
	<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
		<!-- edit form for username / this form uses HTML5 attributes, like "required" and type="email" -->
		<form method="post" action="edit.php" name="user_edit_form_name">
			<h2 class="form-signin-heading">Edit Username</h2>
			<div class="form-group">
				<label for="user_name"><?php echo $phplogin_lang['New username']; ?></label>
				<input class="form-control" id="user_name" type="text" name="user_name" pattern="[a-zA-Z0-9]{2,64}" placeholder="<?php echo $phplogin_lang['currently']; ?>: <?php echo $_SESSION['user_name']; ?>" required>
			</div>
			<div class="form-group">
				<button class="btn btn-primary btn-block" type="submit" name="user_edit_submit_name"><?php echo $phplogin_lang['Change username']; ?></button>
			</div>
		</form>
	</div>
</div>

<div class="row">
	<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
		<!-- edit form for user email / this form uses HTML5 attributes, like "required" and type="email" -->
		<form method="post" action="edit.php" name="user_edit_form_email">
			<h2 class="form-signin-heading">Edit Email</h2>
			<div class="form-group">
				<label for="user_email"><?php echo $phplogin_lang['New email']; ?></label>
				<input class="form-control" id="user_email" type="email" name="user_email" placeholder="<?php echo $phplogin_lang['currently']; ?>: <?php echo $_SESSION['user_email']; ?>" required>
			</div>
			<div class="form-group">
				<button class="btn btn-primary btn-block" type="submit" name="user_edit_submit_email"><?php echo $phplogin_lang['Change email']; ?></button>
			</div>
		</form>
	</div>
</div>

<div class="row">
	<div class="col-xs-12 col-md-6 col-lg-4 col-md-offset-3 col-lg-offset-4">
		<!-- edit form for user's password / this form uses the HTML5 attribute "required" -->
		<form method="post" action="edit.php" name="user_edit_form_password">
			<h2 class="form-signin-heading">Edit Password</h2>
			<div class="form-group">
				<label for="user_password_old"><?php echo $phplogin_lang['Old password']; ?></label>
				<input class="form-control" id="user_password_old" type="password" name="user_password_old" autocomplete="off" required>
			</div>
			<div class="form-group">
				<label for="user_password_new"><?php echo $phplogin_lang['New password']; ?></label>
				<input class="form-control" id="user_password_new" type="password" name="user_password_new" autocomplete="off" required>
			</div>
			<div class="form-group">
				<label for="user_password_repeat"><?php echo $phplogin_lang['Repeat new password']; ?></label>
				<input class="form-control" id="user_password_repeat" type="password" name="user_password_repeat" autocomplete="off" required>
			</div>
			<div class="form-group">
				<button class="btn btn-primary btn-block" type="submit" name="user_edit_submit_password"><?php echo $phplogin_lang['Change password']; ?></button>
			</div>
		</form>
	</div>
</div>

<!-- backlink -->
<!--a href="index.php"><?php echo $phplogin_lang['Back to login']; ?></a-->

<?php
// include html footer
include('footer.php');
