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
				<a class="btn btn-default disabled" href="password_reset.php"><?php echo $phplogin_lang['I forgot my password']; ?></a>
			</div>
		</div>
		<div class="col-xs-12 col-sm-8">
			<h2>Lorem Ipsum</h2>
			<p>"Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?"</p>
		</div>
	</div><!-- /.row -->

<?php
// include html footer
include('footer.php');
