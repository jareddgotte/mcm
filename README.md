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
5. **Nothing to set for the TMDb proxy.**  `/tmdb.php` exposes five read-only TMDb operations — configuration, movie search, movie details, a movie's videos and list details — and calls TMDb through `/inc/tmdb.php` with the token above.  Read-only is not the same as public: the configuration, a movie and a movie's videos answer any visitor, because the public sharing page needs them without an account, while a search needs a signed-in visitor and a list needs one who owns the list they name — checked before TMDb is asked anything.  It caches the TMDb configuration for a day in a private directory of its own under the system temporary directory, so a fresh browser costs no extra request to TMDb; set `MCM_TMDB_CACHE_DIR` only if that directory is wiped between requests or shared with something else.  Everything that talks to TMDb now goes through it: the add-a-movie search box and the movie detail trailer accordion call it from the browser, `/import_list.php` reads a TMDb list through its `list` operation, and `/add_movie.php` looks a film up through its `movie` operation rather than believing what a request says about it — the last two in the same process rather than over an HTTP request to this site itself, so they go through the same allowlist, the same checks and the same projection a browser would.
6. **Read the content security policy reports (optional).**  Every response carries a small set of baseline headers, and among them a content security policy sent **report-only** — a browser reports what the policy would have stopped and loads the page anyway, so it cannot break a page while you read it.  The default policy describes what the pages already do; if your site loads something else, replace it with `MCM_CONTENT_SECURITY_POLICY` in `config.php`, and set `MCM_CSP_REPORT_URI` if you want the reports posted somewhere rather than left in the browser console.  `define('MCM_SECURITY_HEADERS', false);` takes all of it back off again.

### After deploying the CSRF change
The endpoints that change a list or a movie now accept only a POST that carries the session's own token, and the signed-in page hands that token to the browser.  Nothing needs configuring and nobody is signed out: the token is created the first time a session asks for one, so a visitor who was already signed in simply gets one the next time a page loads.  A collection page that was **already open** in a tab before the files changed does not have a token in it, and its buttons answer "you are not allowed to do that" until the page is reloaded once.  Public sharing links are untouched — they are not authenticated, ask for no token, and keep working.

### Autoloader runtime behavior
The site's classes are loaded from a committed generated class map, `/inc/autoload/`, instead of being required up front.  That map is part of the change and part of the repository; no package manager is involved and no Composer package is installed, and nothing needs configuring.  A request reads a class file only when it first names the class in it, so sixteen pages no longer read `/inc/libs/PHPMailer.php` at all — every list and movie action, the movie dialog, and an anonymous view of a public sharing link among them — while registration and password reset read it exactly when they send, as before.  No account, list, sharing link or password is involved.

If the generated map is unavailable, the bootstrap falls back to the prior loading path: it requires the same three files `/inc/php-login.php` used to require, so every page still works and the loading is what it was before.  Reverting restores that loading everywhere and touches nothing else; `/inc/libs/password_compatibility_library.php` returns with it, unused, as it was.

### Tests
There is one suite and there are two ways to run it.  The cases live in `/tests/cases.php`, written against the harness in `/tests/harness.php`, and both runners call the same closures — an assertion is written once and neither runner owns it.

```
php tests/run.php                    the dependency-free runner
vendor/bin/phpunit                   PHPUnit 12
```

`php tests/run.php` needs nothing but a PHP CLI: no package manager, no test framework, no database, and no web server beyond the one built into PHP.  It runs on a checkout where Composer has never been run and gives the same assertion count either way.

`vendor/bin/phpunit` needs `composer install` first.  It adds what another tool expects — test discovery, group selection and a machine-readable JUnit report, written to `/build/logs/junit.xml` on every run.

Both work on a throw-away copy of the site under the system temp directory, so running either never touches your checkout or your configuration.

The checks that run automatically are grouped into two tiers — a fast one on every pull request and a longer one after a merge and on a schedule — and each tier is a single script you can run yourself.  See [The two check tiers](#the-two-check-tiers) below.

**Which PHP each covers.**  PHP 8.3 is the modernization target and is where both runners are verified.  8.1 is the older runtime still in play and 8.4 is forward-compatibility evidence; neither is a target.  PHPUnit 12 is the newest major that supports 8.3, and it requires 8.3 or later, so it gates 8.3 and 8.4 and cannot say anything about 8.1 — which is why the dependency-free runner stays and is the one to reach for on an older runtime.  PHP 8.5 is forward evidence only and never a target: four assertions fail there (see the note below), on purpose rather than being silenced.

| | 8.1 | 8.3 (target) | 8.4 | 8.5 |
|---|---|---|---|---|
| `php tests/run.php` | covered | covered | covered | four known failures, not a target |
| `vendor/bin/phpunit` | not supported by the tool | gates | gates | not gating |

**Selecting groups.**  Every group declares what it needs — `source`, `fixture`, `server` or `database` — and carries a tier tag derived from that: `quick` for the groups that listen on no socket, `integration` for the rest.  Either runner will select on a name or on a tag, and the two agree on what they select:

```
php tests/run.php --list                 name every group and its tags
php tests/run.php --group=quick          the quick tier alone
php tests/run.php --filter=cookie        the groups whose name matches

vendor/bin/phpunit --list-groups
vendor/bin/phpunit --group quick
vendor/bin/phpunit --filter cookie
```

Interrupting either runner leaves nothing behind: every built-in server and the optional database server are stopped, and the run's temporary directory is removed, on a normal end, an exception, a fatal and a signal alike.

#### The known PHP 8.5 failures
`imagefttext()` on PHP 8.5 no longer accepts a relative font path, and the captcha in `/inc/showCaptcha.php` names its font as `'../fonts/times_new_yorker.ttf'`.  So on 8.5 the captcha stops rendering and logs a font warning.  A second one joins it: `/inc/libs/PHPMailer.php` uses a `(boolean)` cast, which 8.5 deprecates, and the deprecation reaches the error log — which is enough to fail the checks that assert a page logged nothing at all.  Between them a full run on 8.5 fails four assertions, with a database server available or without one — two of them the captcha's, two the deprecation's.

Until the site loaded its classes through a generated map, that count was twelve with a database server and seven without.  The eight that went were pages that read the mail library on every request without ever sending mail, so the deprecation reached their logs too.  Nothing about the defect changed and nothing was silenced: it still fails the two checks about the paths that do send mail.

Both failures are the site's rather than the harness's, and both are left in place on purpose: the assertions stay as they are until the captcha names its font absolutely and the vendored mail library is dealt with — the latter being [a decision of its own](#the-mail-check-and-what-it-found).  8.5 is recorded as forward evidence and gates nothing; see [The two check tiers](#the-two-check-tiers).

#### Development tooling (optional)
`composer.json` and `composer.lock` exist for development tooling only — the site installs no Composer package, `require` stays empty, and `php tests/run.php` needs nothing installed to run and gives the same result whether or not you've ever run Composer.  The one thing Composer produces that the site does load is `/inc/autoload/`, the class map for the site's own files: it is generated by `tools/dump-autoload.sh` and committed, so a checkout needs no package manager to serve the site.  Regenerate and commit it after adding, renaming or moving a class under `/inc/classes/` or `/inc/libs/` — the suite fails if you forget.  Run `composer install` from the committed `composer.lock` if you want the pinned tool tree — PHPUnit, at one exact version — and it lands in `/vendor`, which is git-ignored and safe to delete at any time.  `/build` is generated the same way, by PHPUnit, and is equally disposable.

#### The browser page
The suite is a PHP one and drives no browser.  What a browser builds out of a hostile list name or movie title is answered by `/tests/browser/xss.html`, which renders a hostile list name, movie title, poster path and movie identifier through the real `/js/dom.js` and `/js/mc.js`, then reports what the document ended up holding; the summary at the top is green when every check passed.  It still opens by hand — in a browser directly, or over any local web server — with nothing installed and no server needed.

It can also be driven automatically, in a real browser rather than a DOM emulation, because two of the checks depend on layout: the tab strip is only correctly addressed by position once the browser has actually laid it out.  From `tests/browser`, run:

```
npm install
npx playwright install chromium
node run.js
```

`npm install` and `npx playwright install chromium` are one-time setup: they fetch a pinned version of Playwright and a matching, pinned Chromium build, reproducibly from the committed `package-lock.json`.  `node run.js` then opens the page, reads its own results table, prints every failing check and why, and exits non-zero if any check failed.  On a machine with no Chromium available, it prints a loud `SKIP:` line naming the coverage that was missed and exits zero, the same contract the optional database group below keeps for a missing database server.  This automates only the checks the page already makes; it covers no more of the site than opening the page by hand always did.

#### The mail check, and what it found
Registration and password reset both end in sending a mail, and the suite drives those paths for real against **stand-ins that live in the fixture itself**: a small SMTP server bound to `127.0.0.1`, and a local mailbox that `sendmail_path` points at.  No mail service, no credential, no outbound network, and nothing installed.  What a send actually did is read off the stand-in's own transcript rather than off a return value.

What a send does is decided by the PHP version it runs on, so one runtime is one data point.  Point the suite at the other PHP CLI binaries you have and it asks each of them the same question:

```
MCM_TEST_PHP=/path/to/php8.1:/path/to/php8.4 php tests/run.php
```

With none named the check still runs, on whichever PHP is running the suite, and prints a loud notice saying what a single runtime cannot show.

**The finding, and it affects visitors.**  `/inc/libs/class.smtp.php` calls `each()`, which PHP **8.0 removed**, from inside the step that sends the message body.  On any PHP 8 runtime with `EMAIL_USE_SMTP` on — which is what `/inc/config/example_config.php` sets and recommends — a send does not fail, it **dies**, and takes the whole request with it:

- The visitor waits about **ten seconds** — the mail client waiting out its own timeout — and is then shown the generic failure page, never the message the page keeps for a mail that could not be sent.
- Registration **inserts the account first and deletes it again only when the send returns false**.  A send that dies never returns, so that clean-up never happens: an unactivated account is left behind, holding an activation code that no mail ever carried, and the same username and the same email address are then both refused as already taken.  The visitor cannot register, and cannot try again.
- A password reset request writes the reset code before it sends, so the code is stored and the visitor is shown a failed page instead of being told to check their mail.
- The `mail()` transport (`EMAIL_USE_SMTP` off) is **not** affected: the same message over that path is delivered normally.

None of this is fixed here — establishing it was the job, and replacing the vendored mail library is a separate decision.  The suite now pins the behaviour down, so whoever changes it will be told exactly which claims their change rewrites.  Which PHP version this site's published copy runs on is **not** something this check establishes; the finding above is about any PHP 8 runtime.

#### The optional database group
Two groups are the exception, and both are optional.  Three kinds of regression cannot be seen without a real database — a call that sits in a method but is never reached, a value written to a column too narrow to hold it, and a query whose `WHERE` clause quietly stops restricting anything — so the suite runs a private, disposable database server when it can find one, and prints a loud notice saying exactly what went uncovered when it cannot.  Either way the suite passes; a run with no database is a normal run, and both runners behave the same way - under PHPUnit those groups are reported as skipped, with the same notice.

The third kind is why the list endpoints are driven here as well: with rows to work on, the suite can sign in as a list's owner, as somebody else, and as nobody at all, and check the rows afterwards rather than only the response.  Without a database it still checks that an anonymous or malformed request is refused before a connection is opened.

A third group joins them when a database is available: what a failed send leaves behind.  Whether the account row created by a registration survives a failed send is a question about a row, so it needs a server, and it is checked both ways — against a send that dies, which leaves the account behind, and against a send the far end refuses politely, which returns false, cleans the account up, and lets the visitor try again.

The second of those groups is the movie authorization matrix: who may add, delete, move and import films, over two accounts with real lists and real rows.  Whose list a request named is a question only a database can answer, so without a server that matrix is skipped and what is left is the part that needs no database — a request with nobody signed in behind it is refused before the endpoint connects at all.

List import is driven the same way, and against a stand-in for TMDb rather than TMDb: what an authorized import writes — the rows it adds, the stale ones it brings up to date, the duplicates it skips for the account asking and only that account — are rows, so they need a server.  What needs no server is what a refusal costs, and that is checked either way by counting the requests the stand-in actually received: a request refused for its method, its session, its token, a TMDb list identifier that is not one, or a local list that is somebody else's leaves that count at zero.

To cover them, download a **MariaDB or MySQL binary tarball**, unpack it anywhere you like, and point the suite at the server binary inside it:

```
MCM_TEST_MYSQLD=/path/to/unpacked/bin/mariadbd php tests/run.php
MCM_TEST_MYSQLD=/path/to/unpacked/bin/mariadbd vendor/bin/phpunit
```

A `mariadbd` or `mysqld` already on your `PATH`, or in the usual places a package puts one, is found without `MCM_TEST_MYSQLD`.  Nothing is installed and no service is started: the harness creates its own data directory, port, socket and credentials under the system temp directory, loads `/.your_database.sql` into it, and destroys all of it when the run ends.  The credentials are generated per run and exist nowhere else, and no application file changes — the server's address travels in `DB_HOST`, which the bootstrap already puts into the connection string verbatim, so `127.0.0.1;port=<port>` reaches it.

Two things about the tracked schema decide whether it loads, and both are the schema's rather than the harness's:
- Every table is `ENGINE=MyISAM`, so nothing in the application is transactional.  A case cannot roll a change back and re-seeds instead.
- `users.user_registration_datetime` defaults to `'0000-00-00 00:00:00'`, which a server whose `sql_mode` contains `NO_ZERO_DATE` refuses — MySQL 5.7 and later have it on by default, MariaDB does not.  The dump opens with its own `SET SQL_MODE` line, and running that line as part of the load is what makes the rest of it loadable.  The application's own connections are unaffected and run on the server's default `sql_mode`, which on a current server includes `STRICT_TRANS_TABLES`; under that mode a value too long for its column is an error rather than the silent truncation older servers performed.

#### The two check tiers
Everything above is a way of running the suite by hand.  The checks the repository runs *automatically* are grouped into two tiers, and every check belongs to exactly one of them.  Each tier is one script, and the automated path runs that same script rather than a copy of it — so what runs in a workflow is what runs on your machine.

```
tools/quality/fast.sh                       the fast tier, about twenty seconds
tools/quality/integration.sh                the longer tier, a few minutes
```

Both write their result to `/build/quality/<tier>/` — a `summary.txt` naming every check and its verdict, and one log per check.  `/build` is generated and git-ignored.  `MCM_QUALITY_PHP` picks the PHP CLI to use; without it they use `php` from your `PATH`.

**The fast tier — while a change is being read.**  It needs a PHP CLI, and for one of its five checks the Composer tool tree.  It starts no server, opens no socket, launches no browser and connects to no database, and that restraint is the whole reason it is separate: it must never become the reason a small change waits.

| check | what it is | what a failure means |
|---|---|---|
| `parse` | `php -l` over every PHP file the repository tracks | that file is a white page for whoever loads it — deployment is manual and additive, so a file that does not parse ships |
| `lint` | the reserved formatting / static-analysis lane | nothing; it reports `RESERVED`, never `PASS`, and checks nothing at all yet |
| `hygiene` | the credential and example-configuration groups, run by name | a credential, a TMDb host or a real value has reached a browser-served file or the tracked example configuration |
| `suite-quick` | every group tagged `quick`, dependency-free runner | an ordinary test failure; the log names the assertion |
| `phpunit-quick` | the same groups under PHPUnit | the same, plus `/build/logs/junit.xml` for whatever reads reports |

**The reserved lane is empty on purpose.**  `tools/quality/lint.sh` runs no tool, reads no configuration and has no opinion about a single character of the source.  Which formatter, which indentation width, which static analyser and which rule set fill it is a separate decision with its own review; the tracked `.editorconfig` is an input to that decision rather than the answer to it.  The lane reports `RESERVED` rather than `PASS` so that nobody reads the summary and believes this repository verifies its formatting.  It does not.

**The longer tier — before a change is considered ready.**  It opens sockets, starts PHP's built-in server many times over, launches a real browser, runs a private database server and drives the mail path on every PHP CLI it is given.

| check | what it is | what a failure means |
|---|---|---|
| `browser` | `/tests/browser/run.js` — the hostile-value page in a real Chromium | a value a visitor typed reached the document as markup, or the tab strip wrapped and `renameList()` would rename the wrong tab |
| `suite-full` | every group, dependency-free runner | an ordinary test failure |
| `phpunit-full` | every group, PHPUnit | the same |
| `runners-agree` | the two runners made the same number of assertions, compared only when both finished green | a group stopped being reached under one of them — the one failure a green run looks exactly like |
| `database` | whether the database-backed groups actually ran | nothing on its own; it is `SKIP` when they did not, naming the regressions that are therefore invisible |
| `mail-runtimes` | whether the mail check saw more than one PHP runtime | the same shape: `SKIP` names what one data point cannot show |

It reaches neither TMDb nor the live site.  Every outbound request in the suite goes to a stand-in inside the run's own throw-away fixture, the mail path ends at a stand-in bound to `127.0.0.1`, and the database server is a private instance the run creates and destroys.  It installs nothing into your system and it deploys nothing.

**A missing prerequisite is loud, never quiet.**  Without a browser, without a database server, or with only one PHP runtime, the longer tier still passes — and its summary carries a `not covered by this run` section naming, in the suite's own words, exactly which regressions went unlooked-for.  Give it what it wants and nothing is skipped:

```
cd tests/browser && npm ci && npx playwright install chromium && cd ../..
export MCM_TEST_MYSQLD="$(tools/quality/fetch-mariadb.sh)"
export MCM_TEST_PHP=/usr/bin/php8.1:/usr/bin/php8.4
tools/quality/integration.sh
```

`tools/quality/fetch-mariadb.sh` downloads one pinned MariaDB binary tarball, checks it against a checksum written down in the script, unpacks it outside the checkout and prints the path to the server binary.  It is the only thing in `tools/quality/` that downloads anything, it is run deliberately rather than as part of a check, and doing it by hand instead — as [the optional database group](#the-optional-database-group) describes — works exactly as well.

`--require` turns those loud skips into failures:

```
tools/quality/integration.sh --require browser,database,mail-runtimes,phpunit
```

That is what the automated run passes, so that a run which lost its browser or its database server fails rather than reporting a green with a third of its coverage missing.  Leave it off when you are running by hand and do not have them; a loud skip is the right answer there.

**How each tier runs automatically, and where the result is.**  Both tiers are GitHub Actions workflows, and both do nothing but call the script above.

| tier | workflow | when it runs | where the result is |
|---|---|---|---|
| fast | `.github/workflows/fast.yml` | every pull request, every push to `master`, and on request | the run's checks on the pull request, plus a `fast-php<version>` artifact holding `/build/quality/fast` |
| longer | `.github/workflows/integration.yml` | after a merge to `master`, weekly, and on request | an `integration-php<version>` artifact holding `/build/quality/integration` and `/build/logs/junit.xml` |

The longer tier deliberately does not run on a pull request: it takes minutes, and the point of the split is that a small change never waits on it.  A reviewer who wants it on a branch before merging runs the workflow on that branch from the Actions tab and points at that run.

**Which PHP the checks exercise, and what each version means.**  PHP 8.3 is the target and is the run that speaks for the project.  8.1 is the older runtime still in play and 8.4 is forward-compatibility evidence — neither is a target, and both gate only because both are green today.  **PHP 8.5 never gates.**  Four assertions fail there, with a database server or without: the captcha's relative font path stops rendering, and the vendored mail library uses a cast that 8.5 deprecates, which the error log then carries into the checks that assert a mail page logged nothing — see [The known PHP 8.5 failures](#the-known-php-85-failures).  Both are the site's own defects.  They are recorded and are not silenced: the 8.5 jobs run, report what they find, and are marked as not blocking.  The fast tier happens to be green on 8.5 today, because both defects show up only in checks that need a socket.

**Every tool is pinned.**  The workflow actions are pinned by commit; PHPUnit by the committed `composer.lock` at one exact version; Playwright and the Chromium build it downloads by `/tests/browser/package-lock.json`; the database server by version and SHA-256 in `tools/quality/fetch-mariadb.sh`; and the PHP minor version by the workflow.  The exact PHP patch level is whatever the runner offers on the day, and each run writes it into its own summary, so a result stays reproducible from what it recorded.

**What the checks deliberately do not do.**  They never deploy — deployment is manual and nothing here changes that, adds a step towards it, or adds a credential that would make one possible.  They add no repository secret.  They do not require themselves before a merge; that is a repository setting and turning it on is separate from building the check.  They do not retire `php tests/run.php`, which stays supported and is what the fast tier's `suite-quick` check runs.  And they choose no formatting standard and no static-analysis tool — see the reserved lane above.

### Notes
- The endpoints that create, rename, reorder, share and delete a movie list require a signed-in owner.  A request from nobody is refused with `401`, a request for somebody else's list with `403`, and a request that is not the shape the page sends with `400`; every refusal answers with a fixed generic body and puts the reason in the server-side log only.  A request naming several lists at once changes none of them unless the caller owns all of them.
- The `/inc` directory is served-but-internal, so `/inc/.htaccess` and `/inc/config/.htaccess` deny direct web access to it.  The registration captcha at `/inc/showCaptcha.php` is the one deliberate exception, since the browser requests that image directly.
- Config files check for the `MCM_BOOTSTRAP` constant, which is defined by `/inc/bootstrap.php` before the config is included.  This means a direct request to a config file stops immediately even if a web-server rule is missing.
- Both cookies a visitor holds — the session cookie and the remember-me cookie — carry `HttpOnly` and `SameSite`, and are marked `Secure` when the request they go out on is over HTTPS.  `MCM_SESSION_COOKIE_SECURE` and `MCM_SESSION_COOKIE_SAMESITE` set that for both at once; the cookie names, lifetimes and domains are unchanged, so a cookie already in a browser keeps working.
- The content security policy is only ever sent report-only, and the header name is fixed in code rather than taken from configuration, so no setting can turn a description into an enforcement.  `Strict-Transport-Security` stays absent for the same reason the HTTPS redirect is a temporary one: a browser remembers it for far longer than it would take to want it back.
- `/inc/tmdb.php` is the backend-only TMDb client, and the only place this site talks to TMDb from.  It is built for one request and thrown away with it, so no credential-bearing object is ever kept in a session.  Every request goes out over HTTPS — a plain-HTTP endpoint is refused unless it is a loopback address, which never leaves the machine — with the peer and host name verified, no redirect followed, a connect timeout, a total timeout, and a size cap that abandons an oversized response as it arrives rather than buffering it.  A failure reaches the caller as a category and one fixed sentence; the upstream body, the URL and the driver detail stop there, and what went wrong goes to the server-side log.
- `/inc/bootstrap.php` is the shared application bootstrap.  Every public entry point includes it first and exactly once, and it is the single place that loads configuration, installs the error and exception handlers, starts the session, and opens database connections.  All of its own settings are optional and documented in `/inc/config/example_config.php`; leaving them out gives you the safe defaults.
