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
  green run: an assertion that cannot fail still passes.

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
- Configuration is layered: `inc/config/config.php` (untracked, real values)
  wins, and the bootstrap fills in safe defaults for anything it omits.
  `inc/config/example_config.php` is tracked and must only ever hold
  placeholders. Config files refuse to run unless `MCM_BOOTSTRAP` is defined.

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

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
