<?php

// Pins PHP's pseudo-random generator to a fixed seed and then asks for a token.
// A generator built on mt_rand(), however the value is post-processed, produces
// the same token in every run seeded this way; a cryptographically secure one
// does not. tests/cases.php runs this page twice and compares.
require_once(__DIR__ . '/inc/bootstrap.php');

header('Content-Type: text/plain; charset=utf-8');

$seed = 20260817;

mt_srand($seed);
srand($seed);
echo 'token=' . mcm_random_token(64) . "\n";

// The control: exactly what the previous implementation of the remember-me
// token produced. It has to come out identical in both runs, otherwise the
// seeding above proves nothing about the token above it.
mt_srand($seed);
srand($seed);
echo 'legacy_token=' . hash('sha256', mt_rand()) . "\n";
echo 'seed=' . $seed . "\n";
