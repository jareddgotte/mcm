<?php

// Reports what mcm_column_text() makes of values that have to fit a
// varchar(255), as one "key=value" line per fact.
require_once(__DIR__ . '/inc/bootstrap.php');

header('Content-Type: text/plain; charset=utf-8');

$cases = array(
	'short'   => 'Fight Club',
	'exactly' => str_repeat('a', 255),
	'longer'  => str_repeat('a', 300),
	// 300 two-byte characters. Only a character count says where the 255th of
	// them ends; there are 600 bytes here.
	'longer_mb' => str_repeat("\xC3\xA9", 300),
	// 128 of the same, which is 256 bytes and so inside the limit by character
	// and outside it by byte. A cut at 255 bytes would land in the middle of
	// the last one and leave a string no page can render, which is the whole
	// reason this helper exists.
	'boundary_mb' => str_repeat("\xC3\xA9", 128),
	// Not text this site can count by character at all.
	'invalid_utf8' => str_repeat("\xC3\x28", 200),
	'not_a_string' => null,
);

foreach ($cases as $name => $value) {
	$cut   = mcm_column_text($value, 255);
	$valid = preg_match('//u', $cut) === 1;

	echo $name . '_bytes=' . strlen($cut) . "\n";
	echo $name . '_chars=' . ($valid ? preg_match_all('/./us', $cut) : -1) . "\n";
	echo $name . '_valid=' . ($valid ? 'yes' : 'no') . "\n";
}
