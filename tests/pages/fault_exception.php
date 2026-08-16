<?php

// An uncaught exception carrying detail that must never reach the client.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

throw new Exception('connection refused for ' . MCM_TEST_SEED);
