<?php

// Issues a cookie through mcm_set_cookie(), the helper every cookie the
// application sets other than the session cookie goes through - the remember-me
// cookie is the one that exists today. Driving the helper directly keeps the
// case free of a database and a real login, while the header it produces is the
// same one a signed-in visitor would receive.
require_once(__DIR__ . '/inc/bootstrap.php');

mcm_set_cookie('rememberme', 'probe-value', time() + COOKIE_RUNTIME, '/', COOKIE_DOMAIN);

header('Content-Type: text/plain; charset=utf-8');
echo "set\n";
