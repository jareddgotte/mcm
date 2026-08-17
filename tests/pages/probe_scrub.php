<?php

// The trace scrubber on its own, given a trace of the shape PHP produces.
// Runtimes from 7.4 on can be told to leave call arguments out to begin with,
// so exercising the scrubber directly is the only way to see it work on a
// runtime where there was nothing left to remove.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

$trace = "#0 /app/inc/classes/Login.php(150): Login->loginWithPostData('someone', 'pw-" . MCM_TEST_SEED . "', 1)\n#1 {main}";

echo mcm_scrub_trace($trace) . "\n";
echo "request completed\n";
