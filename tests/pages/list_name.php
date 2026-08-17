<?php

// Reports what mcm_list_name_error() makes of a battery of submitted list
// names, as one "key=value" line per case.
require_once(__DIR__ . '/inc/bootstrap.php');

header('Content-Type: text/plain; charset=utf-8');

$cases = array(
	'plain'        => 'Want to See',
	'markup'       => '<script>alert(1)</script>',
	'quotes'       => 'It\'s "mine" & yours',
	'accented'     => "Amélie's Favourites",
	'emoji'        => "Movie \xF0\x9F\x8E\xACnight",
	'empty'        => '',
	'spaces_only'  => "  \t ",
	'sixty_four'   => str_repeat('a', 64),
	'sixty_five'   => str_repeat('a', 65),
	// 64 multi-byte characters: within the limit, and only a character count
	// says so - as bytes this is 128 long.
	'sixty_four_mb' => str_repeat("\xC3\xA9", 64),
	'newline'      => "Want\nto See",
	'null_byte'    => "Want\x00to See",
	'invalid_utf8' => "Want \xC3\x28 See",
);

foreach ($cases as $name => $value) {
	echo $name . '=' . mcm_list_name_error($value) . "\n";
}
