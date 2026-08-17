<?php

// An uncaught Error rather than an Exception: a call to a method that does not
// exist, with the seeded secret in the method name so it lands in the message.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

$object = new stdClass();
$object->{'missing_method_' . MCM_TEST_SEED}();
