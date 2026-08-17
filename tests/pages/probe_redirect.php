<?php

// Stand-in for a page that redirects. It reports what the bootstrap would put
// in a Location header for each destination below, including the ones a
// request might try to smuggle in through a value the application echoes back.
require_once(__DIR__ . '/inc/bootstrap.php');

if (!headers_sent()) {
	header('Content-Type: text/plain; charset=utf-8');
}

$destinations = array(
	'root'              => '/',
	'page'              => '/index.php',
	'query'             => '/share.php?id=7',
	'subdir'            => '/movies',
	'bare'              => 'index.php',
	'empty'             => '',
	'absolute'          => 'http://attacker.example/index.php',
	'origin_only'       => 'https://attacker.example',
	'protocol_relative' => '//attacker.example/index.php',
	'backslashed'       => "/\\/attacker.example/index.php",
	'injected_header'   => "/index.php\r\nX-Injected: yes",
);

foreach ($destinations as $name => $destination) {
	echo 'target_' . $name . '=' . mcm_redirect_target($destination) . "\n";
}

echo 'origin=' . mcm_canonical_origin() . "\n";
echo 'enforced=' . (mcm_https_is_enforced() ? 'yes' : 'no') . "\n";
echo 'is_https=' . (mcm_request_is_https() ? 'yes' : 'no') . "\n";
