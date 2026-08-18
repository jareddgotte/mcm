<?php

// The same cookie, for a request the web server terminated TLS for. The signal
// is set the way inc/bootstrap.php's own HTTPS probe pages set it.
$_SERVER['HTTPS'] = 'on';

require_once(__DIR__ . '/probe_set_cookie.php');
