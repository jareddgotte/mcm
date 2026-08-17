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

// The seed goes in on its own rather than behind a prefix: PHP truncates a
// string argument in a trace at 15 characters, and a prefix would push the part
// the case looks for past the cut, so a trace that did leak it would still pass.
mcm_signs_someone_in('someone', MCM_TEST_SEED);
