<?php

/**
 * Check PHP prerequisites and load the common variables the php-login script
 * needs.
 *
 * This file used to require four more: the password compatibility library, the
 * PHPMailer library, and the Login and Registration classes. The libraries and
 * the classes now come from the generated autoloader the bootstrap registers,
 * so each is read only on a request that names the class in it - see
 * inc/autoload/ and the "autoload" section of composer.json. The compatibility
 * library is gone: it only ever loaded below PHP 5.5, which is far below what
 * this code can run on at all.
 */

// The oldest PHP this application can run on. 7.0 is what its own syntax and
// functions actually require - inc/dialog_trailers.php uses the spaceship
// operator and inc/security.php calls random_bytes(), both PHP 7.0 - which is
// what this check has to say. It is a floor and not a target: PHP 8.3 is the
// modernization target, and the checks that gate a change run there.
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
	exit('Sorry, this site does not run on a PHP version smaller than 7.0.0 !');
}

// Configuration, error handling, the class autoloader and the session all come
// from the shared bootstrap, which every public entry point already includes.
// Requiring it again here is a no-op, and keeps this file usable on its own.
require_once(dirname(__FILE__) . '/bootstrap.php');

// detection of the language for the current user
$user_lang = (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en');
// if translation file for the specified language doesn't exist, we use default english file
//
// This one path is deliberately left resolving against the process working
// directory, which is the document root and has never held a lang/ directory:
// every request this site has ever served has therefore fallen back to English.
// Anchoring it would start serving three translations that have never been
// served, which is a change to what visitors see and belongs to its own issue,
// not to a change about loading.
if (!file_exists('lang/' . $user_lang . '.php')) {
	$user_lang = 'en';
}
// save language as constant and include language translated strings
define('PHPLOGIN_LANG', $user_lang);
include(dirname(__FILE__) . '/lang/' . PHPLOGIN_LANG . '.php');
