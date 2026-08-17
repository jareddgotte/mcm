<?php

// A file that does not compile. PHP 7 and later raise this as a ParseError,
// which is a Throwable, so it reaches the exception handler rather than the
// shutdown handler - see fault_compile.php for the shutdown handler's own case.
// The file name carries the seeded secret, so the message proves where detail
// went.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

require(__DIR__ . '/broken_' . MCM_TEST_SEED . '.php');

echo "this line is never reached\n";
