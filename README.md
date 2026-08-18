Movie Collection Manager (MCM)
===
This project is published at http://jaredgotte.com/mcm/
## Accomplishments
### Notable Accomplishments
- Combined multiple third party works, from various technologies, into a cohesive novel product that I had envisioned.

### Thoughts Worth Mentioning
- Third party libraries: BootStrap (CSS, JS), jQuery (JS), PHP-Login (PHP, SQL), and ZeroClipboard (JS).
- Third party API: TheMovieDB.org API (JSON), along with a third party API wrapper (PHP).
- jQuery addons used: LazyLoad, TypeAhead
- BootStrap addon used: TabDrop
- Used Git as my version control system (https://github.com/jareddgotte/mcm).

## About
With DVDs being so small, the novelty of owning a movie, and the ever growing number of movies today, many people have huge movie collections to showcase. Perhaps one of these movie collectors would like to entertain a guest of theirs with a movie? However, their movie collection size could be overwhelming for their guest to decide on a movie. The purpose of this project is to help make movie collection browsing easier with these features:
- Easily add and delete movie lists from your collection!
- Easily add and delete movies from your lists!
- Easily moving a movie from separate lists!
- Clicking a movie within a list shows the trailer(s) along with additional information!
- Being able to access 100% of the website's features from any device with an HTML5 browser!
- Easily share your lists of movies to anyone by a click of a button!
- More features are in the works!

## Important Details
This repository contains a placeholder database schema and a placeholder configuration file.  The values actually used by the published site are not part of the repository.

### Setup
1. **Import the database.**  The database structure was exported from PHPMyAdmin into the file `/.your_database.sql`.  Edit it to change "your_database" to the name of the database you are going to use, then import it into your own.  Remember to delete your edited copy after importing it!
2. **Create the configuration file.**  Copy `/inc/config/example_config.php` to `/inc/config/config.php`, then change the appropriate information within the new file.  `config.php` is listed in `/.gitignore` so that your real credentials are never committed; `example_config.php` stays in the repository and must only ever contain placeholders.
3. **Set your canonical host (optional, but recommended).**  Define `MCM_CANONICAL_HOST` in `config.php` as the one host name the site should use, e.g. `example.com`.  Redirects then name that host over HTTPS, and a plain-HTTP request is sent on to the same address over HTTPS.  Leaving it out is safe: redirects simply leave the host out and stay on whichever one the visitor is already using.  To serve plain HTTP again, add `define('MCM_FORCE_HTTPS', false);` — the redirect is a temporary one, so browsers do not remember it, and the site does not send `Strict-Transport-Security`.
4. **Set your TMDb read access token.**  Define `TMDB_READ_ACCESS_TOKEN` in `config.php` as the v4 read access token from your TMDb account.  It is backend-only: `/inc/tmdb.php` sends it as an `Authorization: Bearer` header from the server and it never reaches a browser, a URL, a session or the log.  The three limits an outbound request runs under — a connect timeout, a total timeout and a response size cap — already have values, so there is nothing else to set; `/inc/config/example_config.php` documents them if you want different ones.
5. **Read the content security policy reports (optional).**  Every response carries a small set of baseline headers, and among them a content security policy sent **report-only** — a browser reports what the policy would have stopped and loads the page anyway, so it cannot break a page while you read it.  The default policy describes what the pages already do; if your site loads something else, replace it with `MCM_CONTENT_SECURITY_POLICY` in `config.php`, and set `MCM_CSP_REPORT_URI` if you want the reports posted somewhere rather than left in the browser console.  `define('MCM_SECURITY_HEADERS', false);` takes all of it back off again.

### After deploying the CSRF change
The endpoints that change a list or a movie now accept only a POST that carries the session's own token, and the signed-in page hands that token to the browser.  Nothing needs configuring and nobody is signed out: the token is created the first time a session asks for one, so a visitor who was already signed in simply gets one the next time a page loads.  A collection page that was **already open** in a tab before the files changed does not have a token in it, and its buttons answer "you are not allowed to do that" until the page is reloaded once.  Public sharing links are untouched — they are not authenticated, ask for no token, and keep working.

### Tests
Run `php tests/run.php`.  The suite covers `/inc/bootstrap.php` and needs nothing but a PHP CLI: no package manager, no test framework, no database, and no web server beyond the one built into PHP.  Every case works on a throw-away copy of the site under the system temp directory, so running the suite never touches your checkout or your configuration.  Add `--filter=<text>` to run a single group.

#### The browser page
The suite is a PHP one and drives no browser.  What a browser builds out of a hostile list name or movie title is answered by `/tests/browser/xss.html`, which you open by hand — in a browser directly, or over any local web server.  It renders a hostile list name, movie title, poster path and movie identifier through the real `/js/dom.js` and `/js/mc.js`, then reports what the document ended up holding; the summary at the top is green when every check passed.  Nothing is installed and no server is needed.

#### The optional database group
Two groups are the exception, and both are optional.  Three kinds of regression cannot be seen without a real database — a call that sits in a method but is never reached, a value written to a column too narrow to hold it, and a query whose `WHERE` clause quietly stops restricting anything — so `tests/run.php` runs a private, disposable database server when it can find one, and prints a loud notice saying exactly what went uncovered when it cannot.  Either way the suite passes; a run with no database is a normal run.

The third kind is why the list endpoints are driven here as well: with rows to work on, the suite can sign in as a list's owner, as somebody else, and as nobody at all, and check the rows afterwards rather than only the response.  Without a database it still checks that an anonymous or malformed request is refused before a connection is opened.

The second of those groups is the movie authorization matrix: who may add, delete, move and import films, over two accounts with real lists and real rows.  Whose list a request named is a question only a database can answer, so without a server that matrix is skipped and what is left is the part that needs no database — a request with nobody signed in behind it is refused before the endpoint connects at all.

To cover them, download a **MariaDB or MySQL binary tarball**, unpack it anywhere you like, and point the suite at the server binary inside it:

```
MCM_TEST_MYSQLD=/path/to/unpacked/bin/mariadbd php tests/run.php
```

A `mariadbd` or `mysqld` already on your `PATH`, or in the usual places a package puts one, is found without `MCM_TEST_MYSQLD`.  Nothing is installed and no service is started: the harness creates its own data directory, port, socket and credentials under the system temp directory, loads `/.your_database.sql` into it, and destroys all of it when the run ends.  The credentials are generated per run and exist nowhere else, and no application file changes — the server's address travels in `DB_HOST`, which the bootstrap already puts into the connection string verbatim, so `127.0.0.1;port=<port>` reaches it.

Two things about the tracked schema decide whether it loads, and both are the schema's rather than the harness's:
- Every table is `ENGINE=MyISAM`, so nothing in the application is transactional.  A case cannot roll a change back and re-seeds instead.
- `users.user_registration_datetime` defaults to `'0000-00-00 00:00:00'`, which a server whose `sql_mode` contains `NO_ZERO_DATE` refuses — MySQL 5.7 and later have it on by default, MariaDB does not.  The dump opens with its own `SET SQL_MODE` line, and running that line as part of the load is what makes the rest of it loadable.  The application's own connections are unaffected and run on the server's default `sql_mode`, which on a current server includes `STRICT_TRANS_TABLES`; under that mode a value too long for its column is an error rather than the silent truncation older servers performed.

### Notes
- The endpoints that create, rename, reorder, share and delete a movie list require a signed-in owner.  A request from nobody is refused with `401`, a request for somebody else's list with `403`, and a request that is not the shape the page sends with `400`; every refusal answers with a fixed generic body and puts the reason in the server-side log only.  A request naming several lists at once changes none of them unless the caller owns all of them.
- The `/inc` directory is served-but-internal, so `/inc/.htaccess` and `/inc/config/.htaccess` deny direct web access to it.  The registration captcha at `/inc/showCaptcha.php` is the one deliberate exception, since the browser requests that image directly.
- Config files check for the `MCM_BOOTSTRAP` constant, which is defined by `/inc/bootstrap.php` before the config is included.  This means a direct request to a config file stops immediately even if a web-server rule is missing.
- Both cookies a visitor holds — the session cookie and the remember-me cookie — carry `HttpOnly` and `SameSite`, and are marked `Secure` when the request they go out on is over HTTPS.  `MCM_SESSION_COOKIE_SECURE` and `MCM_SESSION_COOKIE_SAMESITE` set that for both at once; the cookie names, lifetimes and domains are unchanged, so a cookie already in a browser keeps working.
- The content security policy is only ever sent report-only, and the header name is fixed in code rather than taken from configuration, so no setting can turn a description into an enforcement.  `Strict-Transport-Security` stays absent for the same reason the HTTPS redirect is a temporary one: a browser remembers it for far longer than it would take to want it back.
- `/inc/tmdb.php` is the backend-only TMDb client, and the only place this site talks to TMDb from.  It is built for one request and thrown away with it, so no credential-bearing object is ever kept in a session.  Every request goes out over HTTPS — a plain-HTTP endpoint is refused unless it is a loopback address, which never leaves the machine — with the peer and host name verified, no redirect followed, a connect timeout, a total timeout, and a size cap that abandons an oversized response as it arrives rather than buffering it.  A failure reaches the caller as a category and one fixed sentence; the upstream body, the URL and the driver detail stop there, and what went wrong goes to the server-side log.
- `/inc/bootstrap.php` is the shared application bootstrap.  Every public entry point includes it first and exactly once, and it is the single place that loads configuration, installs the error and exception handlers, starts the session, and opens database connections.  All of its own settings are optional and documented in `/inc/config/example_config.php`; leaving them out gives you the safe defaults.
