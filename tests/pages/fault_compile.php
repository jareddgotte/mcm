<?php

// A true compile-time fatal, reaching the shutdown handler.
//
// The required file declares a function, so requiring it a second time is a
// duplicate declaration: E_COMPILE_ERROR, raised while the file is compiled.
// Unlike a parse error - which PHP 7 and later raise as a ParseError, a
// Throwable that the exception handler picks up - this never reaches the error
// or exception handler, so only mcm_shutdown_handler() can see it. The seeded
// secret is in the function name, and therefore in the message.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

require(__DIR__ . '/declares_' . MCM_TEST_SEED . '.php');
require(__DIR__ . '/declares_' . MCM_TEST_SEED . '.php');

echo "this line is never reached\n";
