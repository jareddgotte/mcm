# Project agent memory

This file is the project's committed home for project-intrinsic agent knowledge: build, test, release, architecture, and sharp-edge notes that should travel with the code.

## What this project is

A legacy PHP movie-collection manager built on the php-login script. There is no
dependency manager and no build step; the `.php` files in the document root are
the application. Setup is documented in `README.md`.

## Tests

- `php tests/run.php` runs everything; `--filter=<text>` runs one group. The
  suite covers `inc/bootstrap.php` and needs only a PHP CLI - no package
  manager, no framework, no database. Keep it that way.
- Each case builds a throw-away copy of the site under the system temp
  directory and drives it as a child process or through PHP's built-in server,
  so runs never touch the checkout or a real configuration.
- Two traps the harness already works around, documented at their call sites in
  `tests/run.php`: the built-in server command must be prefixed with `exec` or
  terminating it orphans the server and hangs the suite, and `fonts/` has to be
  copied into a fixture or the captcha logs a font warning.
- Failures that only a whole-source view can catch are static checks over
  `token_get_all()` in `tests/entrypoints.php`; comments are stripped first, so
  prose mentioning a call is not mistaken for one, and per-statement facts are
  read from the statement's own tokens, never from the rest of the file.
  `mcm_method_calls()` narrows that to one method, which is how a fact about a
  single code path - "this transition renews the session identifier" - is
  asserted without the rest of the file being able to satisfy it.
- Which handler sees a fatal is not obvious and decides what a case proves. A
  file that does not parse raises a `ParseError`, which is a `Throwable` and
  goes to the exception handler. Only a genuine compile-time fatal - a
  duplicate declaration, as in `tests/pages/fault_compile.php` - reaches
  `mcm_shutdown_handler()`. A "fatal" case built on a parse error leaves the
  shutdown handler untested.
- PHP 8.3 is the modernization target runtime, and the suite is verified there.
  It is also run on 8.1, the older runtime still in play, and on 8.4 as
  forward-compatibility evidence. The suite is developer-only, so its PHP floor
  is independent of the site's.
- Known, unfixed, and surfaced by the suite: on PHP 8.5 `imagefttext()` no
  longer accepts a relative font path, so the captcha's
  `'../fonts/times_new_yorker.ttf'` in `inc/showCaptcha.php` stops rendering
  and logs a font warning. An absolute path still works. The suite is green on
  8.1, 8.3 and 8.4 and fails exactly this one assertion on 8.5; that failure is
  the site's, not the harness's, and the assertion stays as it is until the
  captcha is fixed.
- A database outage is simulated without a database: the fixture's `DB_HOST`
  reaches the DSN verbatim, so `127.0.0.1;port=1` pins a port nothing can be
  listening on and the driver refuses the connection exactly as it would during
  a real outage.
- When changing the harness, re-run the mutation sweep rather than trusting a
  green run: an assertion that cannot fail still passes. Response headers are a
  common way to get a toothless one: the session's own cache limiter already
  sends `Cache-Control: no-store, no-cache, must-revalidate`, so a substring
  check for a caching header passes whether or not the code under test set one.
- Cases that need a POST use `mcm_http_post()`; `mcm_http()` takes the method
  and the body as its fourth and fifth arguments. The ownership cases build
  their fixture table in SQLite in memory, and skip where the runtime has no
  SQLite driver, so the suite still needs no database.
- The suite has no browser and does not want one. `tests/browser/xss.html` is
  opened by hand instead: it renders a hostile list name, movie title, poster
  path and movie identifier through the real scripts and reports what the
  document ended up holding. Its own stylesheet keeps the tab strip on one line
  on purpose - TabDrop moves any tab that wrapped into a dropdown, and
  `renameList()` addresses tabs by position, so a wrapped strip makes it rename
  the wrong tab.
- One group is optional and is the only part that wants more than a PHP CLI:
  `tests/database.php` runs a private, throw-away database server when
  `MCM_TEST_MYSQLD` or `PATH` offers a `mariadbd`/`mysqld`, and otherwise prints
  a loud notice naming the coverage that was skipped. Both paths have to keep
  passing. It needs no production change: `DB_HOST` reaches the DSN verbatim, so
  `127.0.0.1;port=<port>` is the whole seam. See `README.md` for how to enable it.
- That group exists for the three regressions the rest of the suite is blind to,
  listed in `mcm_db_uncovered()`: a call present in a method but never reached, a
  value written to a column too narrow for it, and a `WHERE` clause that stops
  restricting. All three were injected, shown failing and reverted; if you weaken
  the group, do that again rather than assume. The third is also why the list
  endpoints are driven there against real rows, as owner, non-owner and nobody:
  a refusal that has already written is still a refusal from the outside, so
  every case asserts the rows afterwards and not only the response.
- The movie authorization matrix runs on that same server and skips with it.
  Ownership is a question about rows, so a refusal is asserted twice: what the
  client was told, and a before/after snapshot of every table
  (`mcm_db_movies_snapshot()` and friends) proving the refusal wrote nothing.
  What no case covers is a successful import - that path calls TMDb over the
  network, which the suite does not do; its refusals happen earlier and are
  covered.
- Server lifecycle is the sharp edge, because its failure mode is a hang rather
  than a failure. `proc_close()` waits for the child, so calling it on a server
  that ignored SIGTERM never returns; `mcm_db_stop_server()` therefore signals,
  waits a bounded time, escalates to SIGKILL and only then reaps, is idempotent,
  and runs from a shutdown function and a signal handler as well as inline. The
  `exec` prefix matters here for the same reason it does for the built-in server,
  with worse consequences: an orphan holds both the port and the data directory.
- The tracked schema is MyISAM throughout, so nothing is transactional and cases
  re-seed instead of rolling back, and `users.user_registration_datetime`
  defaults to a zero date that a `NO_ZERO_DATE` server refuses - the dump's own
  `SET SQL_MODE` line is what makes it loadable. `README.md` has the detail.

## Request lifecycle

- `inc/bootstrap.php` is the shared bootstrap. Every public entry point includes
  it first and exactly once, and it is the single place that loads
  configuration, installs the error/exception/shutdown handlers, calls
  `session_start()`, and opens database connections. Add new cross-cutting
  request setup there, not in an entry point.
- Public entry points are every `*.php` in the document root plus
  `inc/showCaptcha.php`, which the browser requests directly for the
  registration captcha. A new entry point must include the bootstrap.
- `session_start()` must exist in exactly one place, and every entry point must
  load the bootstrap first. Both are asserted by `php tests/run.php`.
- The session cookie name stays at the server default. Renaming it would sign
  out every visitor of the live site.
- Redirects go through `mcm_redirect()` / `mcm_redirect_target()` in the
  bootstrap. Never build a destination from `HTTP_HOST`, `SERVER_NAME` or
  `PHP_SELF`: the host comes from `MCM_CANONICAL_HOST` or is left out
  altogether, and the path from `SCRIPT_NAME`. `php tests/run.php` asserts that
  no `header()` call in the project's own code reads the request host.
- HTTPS enforcement lives in the bootstrap, ahead of `session_start()` so no
  cookie goes out over plain HTTP, and is temporary (302/307) on purpose:
  `define('MCM_FORCE_HTTPS', false)` has to be able to take it back. Strict
  transport security is deliberately absent for the same reason and the suite
  asserts it stays absent.
- Baseline response headers go out from the bootstrap, before the HTTPS
  redirect can exit, so every response carries them - page, JSON refusal,
  captcha image, redirect. The content policy among them is sent REPORT-ONLY
  and the report-only header name is fixed in `mcm_security_headers()` rather
  than configurable, so no setting can turn a description into an enforcement.
  The policy describes what the pages already do, `'unsafe-eval'` and
  `object-src 'self'` included; a policy that reported on every page view would
  be read once and ignored. `MCM_SECURITY_HEADERS` takes all of it back off.
- Every cookie other than the session cookie goes through `mcm_set_cookie()`,
  which decides HttpOnly, SameSite and Secure itself from the same two settings
  the session cookie uses. A cookie's attributes must be asserted off that
  cookie's own `Set-Cookie` line (`mcm_cookie_header()` in `tests/run.php`): the
  session cookie in the same response carries those attributes too, so a pattern
  run across every header joined together passes either way.
- Configuration is layered: `inc/config/config.php` (untracked, real values)
  wins, and the bootstrap fills in safe defaults for anything it omits.
  `inc/config/example_config.php` is tracked and must only ever hold
  placeholders. Config files refuse to run unless `MCM_BOOTSTRAP` is defined.
- Anything the server renders that it did not write itself - a list name, a
  username, a TMDb string - is escaped for where it lands, by a helper in the
  bootstrap: `mcm_html()` for HTML text and quoted attributes, `mcm_url()` for
  one component of a URL, `mcm_js()` for a value inside a `<script>`. Picking by
  habit rather than by destination is how this silently stops working.
  `mcm_escaping_problems()` in `tests/entrypoints.php` fails the suite when a
  page renders a request superglobal without one of them.
- Escaping is rendering only. Stored values keep the exact bytes that were
  submitted, and `mcm_list_name_error()` rejects a bad list name rather than
  rewriting it, so a name that already contains markup keeps working.
- The browser has the same duty for what it renders after the page loads, and
  `js/dom.js` is where it discharges it: the page scripts build elements and
  assign a value as text or as an attribute instead of concatenating it into
  markup. `js/mc.js` and `js/share.js` render posters, typeahead suggestions and
  list headings from those builders, and every page that loads one of them loads
  `js/dom.js` first. The suite reads both scripts and fails on a value joined to
  markup or handed to `.html()`; what a browser then builds is
  `tests/browser/xss.html`.
- `inc/security.php`, loaded by the bootstrap, is where random tokens,
  constant-time comparison, password hashing and session identifier renewal
  live. Login and Registration call it rather than PHP's functions directly, so
  a change to any of that has one place to happen in.
- `inc/guards.php` holds the reusable request guards - signed-in checks, current
  user, POST-only, CSRF, movie-list ownership, the request values an identifier
  or a list position is allowed to be, and the fixed JSON refusal bodies. Its
  CSRF token comes from `mcm_random_token()` and is checked with
  `mcm_hash_equals()`, both from `inc/security.php`; the guards declare no
  primitives of their own. Adoption is deliberate and endpoint by endpoint:
  `mcm_guarded_entry_points()` in `tests/entrypoints.php` is the written-down
  list of who has adopted them, and a page that starts loading them without
  being added there fails the suite.
- The list mutation endpoints - `create_list.php`, `rename_list.php`,
  `delete_list.php`, `adjust_lists.php`, `share_lists.php` - have adopted them,
  as have the movie ones below. Each takes the owner from the session and never
  from the request, and each write names that owner in its own `WHERE` clause:
  the guard refuses the request, and the clause is what leaves the statement
  unable to reach somebody else's row if a guard were ever dropped. A request
  naming several lists checks all of them before writing any, so a range that
  reaches one list belonging to somebody else moves none of them. POST-only and
  CSRF are the guards these have *not* adopted yet; that is its own issue.
- The movie mutation endpoints - `add_movie.php`, `delete_movie.php`,
  `move.php`, `import_list.php` - are guarded in one fixed order, and the order
  is the point: `mcm_require_login()` before the connection is opened, then
  `mcm_require_list_owner()` before the first query is prepared. Anything later
  than that has already written something, because `master_movie_list` is shared
  between every account and an add writes to it before it writes to the list.
  `move.php` checks both ends, and `import_list.php` settles ownership before it
  calls TMDb, so a refusal costs no network request. Duplicate detection is
  scoped to the asking user in both `add_movie.php` and `import_list.php`.
  POST-only and CSRF are deliberately not applied there yet; the suite proves
  it behaviourally - an owner's GET still mutates and an owner's POST without a
  CSRF token still mutates - so that the change which adds them is visible.
- A refusal answers with a status from `mcm_json_error_catalogue()` and that
  status's fixed body, so two different reasons sharing a status cannot be told
  apart from outside; the reason goes to the log through `mcm_log()`. Tokens are
  never logged. A value the page itself computed - a list identifier, a
  position, a JSON array of them - is refused this way when it is malformed. A
  value a person typed is not: `mcm_list_name_error()` still answers a bad list
  name with its own bounded reason, because that one is feedback for whoever
  typed it.

## Database access

- Reach the database only through the bootstrap: `mcm_db_or_fail()` for a page
  that cannot be served without it, `mcm_db_connect()` when the caller shows its
  own message instead (the `Login` and `Registration` classes), and
  `mcm_db_execute()` to run a prepared statement. `new PDO` and reads of
  `DB_PASS` are confined to `inc/bootstrap.php`, and `php tests/run.php` asserts
  it, as it asserts that no application code calls `var_dump()` and friends.
- Never log the stack trace of a failed connection attempt. PHP records call
  arguments in a trace, and the arguments there are the DSN, the user and the
  password - on PHP 8.1 the password appears in full, and only PHP 8.3 masks it.
  `mcm_db_connect()` logs the driver's message alone for that reason, and
  `mcm_scrub_trace()` strips quoted arguments from every other trace.

## Sharp edges

- Deployment of the live site is manual, so every change must be
  additive and leave the site working at each intermediate step.
- Failure detail belongs in the server-side log only; the client gets the
  generic message. Do not add `echo $e->getMessage()` style output.
- `inc/`, `inc/config/` and `inc/views/` carry `.htaccess` rules denying direct
  web access, with `inc/showCaptcha.php` as the one deliberate exception.
- Token lengths are fixed by the columns that hold them (see `.your_database.sql`):
  64 characters for `user_rememberme_token`, 40 for `user_activation_hash` and
  `user_password_reset_hash`. A longer token is silently truncated on the way in
  and then never matches.
- The remember-me cookie is `user id : token : sha256(user id:token +
  COOKIE_SECRET_KEY)`. Changing that formula, or the secret, invalidates every
  remember-me cookie already in a browser.

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
