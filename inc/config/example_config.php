<?php

/**
 * Guard against this file being executed by a direct web request.
 * MCM_BOOTSTRAP is defined by inc/bootstrap.php before the config is included,
 * so a legitimate application bootstrap always passes. A direct hit on this
 * file stops here even if a web-server access rule is missing on some host.
 */
if (!defined('MCM_BOOTSTRAP')) {
	header('HTTP/1.0 403 Forbidden');
	exit('Forbidden');
}

/**
 * Configuration for: The Movie Database (TMDb)
 *
 * The value below is a credential and is backend-only: it is never rendered
 * into a page, put in a URL, kept in a session or handed to a browser.
 * Placeholder only in this file - your real value belongs in config.php,
 * which is not in the repository.
 */

/** the v4 read access token inc/tmdb.php sends as "Authorization: Bearer ...". */
define("TMDB_READ_ACCESS_TOKEN", "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"); // This token comes from TMDB

/**
 * Configuration for: what an outbound TMDb request is allowed to cost
 *
 * Every setting below is OPTIONAL and every one of them already has the value
 * shown, so a request is bounded whether or not you touch this section.
 * Uncomment a line only when you want a different value.
 *
 * The endpoint is TMDb's own and a production site has no reason to change it.
 * It is a setting at all because the test suite points it at a local stub, and
 * because it is checked: anything other than an https URL - or a loopback
 * address, which never leaves the machine - is refused rather than sent to.
 */

/** the API origin. No trailing slash, no query string. */
//define('MCM_TMDB_BASE_URL', 'https://api.themoviedb.org/3');
/** how long a request may spend connecting, in milliseconds. */
//define('MCM_TMDB_CONNECT_TIMEOUT_MS', 3000);
/** how long a request may take in total, in milliseconds. */
//define('MCM_TMDB_TIMEOUT_MS', 8000);
/** how much of a response will be read before it is abandoned, in bytes. */
//define('MCM_TMDB_MAX_BYTES', 1048576);
/**
 * where /tmdb.php keeps its one cached answer, the TMDb configuration, for a
 * day at a time. Empty means a private directory of this application's own
 * under the system temporary directory. Set it only where that directory is
 * wiped between requests or shared with something else; the cache is advisory,
 * so a directory that cannot be used costs a request rather than an error.
 */
//define('MCM_TMDB_CACHE_DIR', '');

/**
 * Configuration file for: Database Connection
 * This is the place where your database login constants are saved
 * 
 * For more info about constants please @see http://php.net/manual/en/function.define.php
 * If you want to know why we use "define" instead of "const" @see http://stackoverflow.com/q/2447791/1114320
 */
/** database host, usually it's "127.0.0.1" or "localhost", some servers also need port info, like "127.0.0.1:8080" */
define("DB_HOST", "localhost");
/** name of the database. please note: database and database table are not the same thing! */
define("DB_NAME", "your_database");
/** user for your database. the user needs to have rights for SELECT, UPDATE, DELETE and INSERT.
/** By the way, it's bad style to use "root", but for development it will work */
define("DB_USER", "the_user");
/** The password of the above user */
define("DB_PASS", "xxxxxxxxxx");

/**
 * Configuration for: Cookies
 * Please note: The COOKIE_DOMAIN needs the domain where your app is, 
 * in a format like this: .mydomain.com
 * Note the . in front of the domain. No www, no http, no slash here!
 * For local development .127.0.0.1 or .localhost is fine, but when deploying you should
 * change this to your real domain, like '.mydomain.com' ! The leading dot makes the cookie available for
 * sub-domains too.
 * @see http://stackoverflow.com/q/9618217/1114320
 * @see php.net/manual/en/function.setcookie.php
 */
define('COOKIE_RUNTIME', 1209600); // 1209600 seconds = 2 weeks
define('COOKIE_DOMAIN', '.yourwebsite.com'); // the domain where the cookie is valid for, like '.mydomain.com'
define('COOKIE_SECRET_KEY', 'xxxxxxxxxxxxxxxxxxxxxxxx'); // use to salt cookie content and when changed, can invalidate all databases users cookies

/**
 * Configuration for: Email server credentials
 * 
 * Here you can define how you want to send emails.
 * If you have successfully set up a mail server on your linux server and you know
 * what you do, then you can skip this section. Otherwise please set EMAIL_USE_SMTP to true
 * and fill in your SMTP provider account data.
 * 
 * An example setup for using gmail.com [Google Mail] as email sending service, 
 * works perfectly in August 2013. Change the "xxx" to your needs.
 * Please note that there are several issues with gmail, like gmail will block your server
 * for "spam" reasons or you'll have a daily sending limit. See the readme.md for more info.
 *
 * 
 * It's really recommended to use SMTP!
 * 
 */
define("EMAIL_USE_SMTP", true);
define("EMAIL_SMTP_AUTH", true); // leave this true until your SMTP can be used without login
define("EMAIL_SMTP_USERNAME", 'noreply@yourwebsite.com');
define("EMAIL_SMTP_PASSWORD", 'xxxxxxxxxxxxxxxxxxxx');
define("EMAIL_SMTP_HOST", 'ssl://smtphost.com');
define("EMAIL_SMTP_PORT", 465);
define("EMAIL_SMTP_ENCRYPTION", 'ssl');

/**
 * Configuration file for: password reset email data
 * This is the place where your constants are saved
 * 
 * For more info about constants please @see http://php.net/manual/en/function.define.php
 * If you want to know why we use "define" instead of "const" @see http://stackoverflow.com/q/2447791/1114320
 */

/** absolute URL to register.php, necessary for email password reset links */
define("EMAIL_PASSWORDRESET_URL", "http://yourwebsite.com/password_reset.php");
define("EMAIL_PASSWORDRESET_FROM", "noreply@yourwebsite.com");
define("EMAIL_PASSWORDRESET_FROM_NAME", "Movie Collection Manager");
define("EMAIL_PASSWORDRESET_SUBJECT", "Password reset for Movie Collection Manager");
define("EMAIL_PASSWORDRESET_CONTENT", "Please click on this link to reset your password:");

/**
 * Configuration file for: verification email data
 * This is the place where your constants are saved
 * 
 * For more info about constants please @see http://php.net/manual/en/function.define.php
 * If you want to know why we use "define" instead of "const" @see http://stackoverflow.com/q/2447791/1114320
 */

/** absolute URL to register.php, necessary for email verification links */
define("EMAIL_VERIFICATION_URL", "http://yourwebsite.com/register.php");
define("EMAIL_VERIFICATION_FROM", "noreply@yourwebsite.com");
define("EMAIL_VERIFICATION_FROM_NAME", "Movie Collection Manager");
define("EMAIL_VERIFICATION_SUBJECT", "Account Activation for Movie Collection Manager");
define("EMAIL_VERIFICATION_CONTENT", "Please click on this link to activate your account:");

/**
 * Configuration file for: Hashing strength
 * This is the place where you define the strength of your password hashing/salting
 * 
 * To make password encryption very safe and future-proof, the PHP 5.5 hashing/salting functions
 * come with a clever so called COST FACTOR. This number defines the base-2 logarithm of the rounds of hashing,
 * something like 2^12 if your cost factor is 12. By the way, 2^12 would be 4096 rounds of hashing, doubling the
 * round with each increase of the cost factor and therefore doubling the CPU power it needs.
 * Currently, in 2013, the developers of this functions have chosen a cost factor of 10, which fits most standard
 * server setups. When time goes by and server power becomes much more powerful, it might be useful to increase
 * the cost factor, to make the password hashing one step more secure. Have a look here
 * (@see https://github.com/panique/php-login/wiki/Which-hashing-&-salting-algorithm-should-be-used-%3F)
 * in the BLOWFISH benchmark table to get an idea how this factor behaves. For most people this is irrelevant,
 * but after some years this might be very very useful to keep the encryption of your database up to date.
 * 
 * Remember: Every time a user registers or tries to log in (!) this calculation will be done.
 * Don't change this if you don't know what you do.
 * 
 * To get more information about the best cost factor please have a look here
 * @see http://stackoverflow.com/q/4443476/1114320
 * 
 * Those constants will be used in the login and the registration class.
 * 
 * For more info about constants please @see http://php.net/manual/en/function.define.php
 * If you want to know why we use "define" instead of "const" @see http://stackoverflow.com/q/2447791/1114320
 */

// the hash cost factor, PHP's internal default is 10. You can leave this line commented out until you need
// another factor then 10.
define("HASH_COST_FACTOR", "10");

/**
 * Configuration for: Error handling and session cookies
 *
 * Every setting below is OPTIONAL. inc/bootstrap.php applies a safe default
 * for anything this file does not define, so an existing config.php keeps
 * working untouched. Uncomment a line only when you want a different value
 * from the default shown next to it.
 *
 * Note that the session cookie NAME is not configurable on purpose: it stays
 * at the server default, because renaming it would sign out every visitor who
 * currently has a session.
 */

/** which diagnostics get logged. They are never shown to visitors. */
//define('MCM_ERROR_REPORTING', E_ALL & ~E_NOTICE);
/** true prints PHP diagnostics into the page. Only ever useful locally. */
//define('MCM_DISPLAY_ERRORS', false);
/** whether diagnostics are written to the error log at all. */
//define('MCM_LOG_ERRORS', true);
/** absolute path to a private log file. Empty means "wherever PHP already logs". */
//define('MCM_ERROR_LOG', '');
/** the one line a visitor sees when a request fails. Keep it free of detail. */
//define('MCM_GENERIC_ERROR_MESSAGE', 'Sorry, something went wrong. Please try again later.');
/** session cookie lifetime in seconds. 0 means "until the browser closes". */
//define('MCM_SESSION_COOKIE_LIFETIME', 0);
/** path the session cookie is sent for. Use the site's sub-directory if it lives in one. */
//define('MCM_SESSION_COOKIE_PATH', '/');
/** SameSite attribute: 'Lax', 'Strict' or 'None'. Applied on PHP 7.3 and newer. */
//define('MCM_SESSION_COOKIE_SAMESITE', 'Lax');
/** true marks cookies HTTPS-only. Leave undefined to follow the current request. */
//define('MCM_SESSION_COOKIE_SECURE', true);

/**
 * Configuration for: baseline response headers
 *
 * Every response carries a small set of headers describing how a browser should
 * treat what it was given: it may not guess a response's type, other sites may
 * not frame these pages, a cross-origin request is told this origin and no
 * more, and device features no page here uses stay switched off.
 *
 * Alongside them goes a content security policy, and it is sent REPORT-ONLY.
 * That means a browser reports what the policy would have stopped and then
 * loads the page anyway, so the policy cannot break anything while it is being
 * read. The default policy describes what the pages already do - inline scripts
 * and styles, the two content delivery networks the markup names, poster images
 * from TMDb, the search endpoint and the trailer video host. Replace it here if
 * your site loads something else, or set it to '' to leave the header off.
 *
 * TO SWITCH ALL OF THIS OFF: uncomment the MCM_SECURITY_HEADERS line below and
 * set it to false. The next request carries no baseline headers, with no code
 * change and nothing to change beyond this file.
 */

/** false stops all of the baseline headers, the content policy included. */
//define('MCM_SECURITY_HEADERS', false);
/** the content policy, only ever sent report-only. '' leaves the header off. */
//define('MCM_CONTENT_SECURITY_POLICY', "default-src 'self'");
/** where a browser posts policy reports. Empty leaves them in the console. */
//define('MCM_CSP_REPORT_URI', 'https://example.com/csp-report');

/**
 * Configuration for: canonical host and HTTPS
 *
 * MCM_CANONICAL_HOST is the one host name this site is willing to put in a
 * redirect. Without it, redirects are still safe - they leave the host out
 * entirely and stay on whichever one the visitor is using - but the site has no
 * HTTPS address to send a plain-HTTP visitor to, so nothing is enforced.
 *
 * Setting it therefore does two things at once: redirects become absolute
 * "https://<that host>/..." URLs, and plain-HTTP requests are sent on to the
 * same address over HTTPS.
 *
 * TO SWITCH HTTPS ENFORCEMENT OFF: uncomment the MCM_FORCE_HTTPS line below and
 * set it to false. The site then serves plain HTTP again on the next request,
 * with no code change and nothing else to change beyond this file. The redirect
 * is a temporary one for exactly this reason, so browsers do not remember it.
 * Note that the site does not send Strict-Transport-Security, which browsers
 * would remember for far longer.
 */

/** the host used in redirects, e.g. 'example.com' or 'example.com:8443'. No scheme, no path. */
//define('MCM_CANONICAL_HOST', 'example.com');
/** false switches HTTPS enforcement off. Leave undefined to enforce once a canonical host is set. */
//define('MCM_FORCE_HTTPS', false);
/** true only where a proxy in front of this site terminates TLS and forwards plain HTTP. */
//define('MCM_TRUST_FORWARDED_PROTO', true);
