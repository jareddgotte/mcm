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
  Browser-side rendering (`js/mc.js`, `js/share.js`) is not covered yet.
- `inc/security.php`, loaded by the bootstrap, is where random tokens,
  constant-time comparison, password hashing and session identifier renewal
  live. Login and Registration call it rather than PHP's functions directly, so
  a change to any of that has one place to happen in.
- `inc/guards.php` holds the reusable request guards - signed-in checks, current
  user, POST-only, CSRF, movie-list ownership and the fixed JSON refusal bodies.
  Its CSRF token comes from `mcm_random_token()` and is checked with
  `mcm_hash_equals()`, both from `inc/security.php`; the guards declare no
  primitives of their own. It is declarations only, and nothing loads it yet: an
  endpoint adopts it deliberately. `php tests/run.php` asserts that no source outside `tests/`
  includes it, so the first endpoint to adopt a guard also updates that
  assertion in `tests/cases.php`.
- A refusal answers with a status from `mcm_json_error_catalogue()` and that
  status's fixed body, so two different reasons sharing a status cannot be told
  apart from outside; the reason goes to the log through `mcm_log()`. Tokens are
  never logged.

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

- The live site is deployed by hand, file by file, so every change must be
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
