<?php

/**
 * Report which classes a request left declared, without touching the page.
 *
 * Prepended to a real page with auto_prepend_file, so what runs is that page
 * exactly as it is committed - no probe standing in for it, no include added
 * to it. The report goes out from a shutdown function rather than from the
 * bottom of the request, because the pages worth asking about include the ones
 * that refuse and exit: a guard that ends the request would skip anything
 * appended after it, and "a refused list mutation reads no mail library" is
 * precisely one of the claims being made.
 *
 * class_exists() is asked with autoloading off throughout. Asking with it on
 * would load the class in order to answer, which is the opposite of the
 * question.
 */

register_shutdown_function(function () {
	$lines = array();

	foreach (array('Login', 'Registration', 'PHPMailer', 'SMTP', 'phpmailerException') as $class) {
		$lines[] = 'class_' . $class . '=' . (class_exists($class, false) ? 'yes' : 'no');
	}

	// Whether the file was read at all, which is the cost this change is about:
	// a class that is declared was read, and a file that was read cost the
	// request the time to compile it.
	$read = array();
	foreach (get_included_files() as $path) {
		$read[] = str_replace('\\', '/', $path);
	}

	$files = array(
		'inc/autoload/autoload.php',
		'inc/classes/Login.php',
		'inc/classes/Registration.php',
		'inc/libs/PHPMailer.php',
		'inc/libs/class.smtp.php',
	);
	foreach ($files as $file) {
		$found = 'no';
		foreach ($read as $path) {
			if (substr($path, -(strlen($file) + 1)) === '/' . $file) {
				$found = 'yes';
				break;
			}
		}
		$lines[] = 'read_' . $file . '=' . $found;
	}

	echo "\n" . implode("\n", $lines) . "\n";
});
