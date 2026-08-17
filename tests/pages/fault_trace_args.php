<?php

// An uncaught exception raised inside a call whose arguments are secret. The
// trace is worth logging for the frame names; the arguments on those frames are
// not, and on this site they are passwords.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

function mcm_signs_someone_in($user_name, $user_password)
{
	throw new RuntimeException('the database went away mid-login');
}

mcm_signs_someone_in('someone', 'password-' . MCM_TEST_SEED);
