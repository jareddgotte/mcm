# Project agent memory

This file is the project's committed home for project-intrinsic agent knowledge: build, test, release, architecture, and sharp-edge notes that should travel with the code.

## What this project is

A legacy PHP movie-collection manager built on the php-login script. There is no
build step; the `.php` files in the document root are the application. Setup is
documented in `README.md`. `composer.json` / `composer.lock` exist for
development tooling only - see [Development tooling](#development-tooling) -
and the site itself pulls in no Composer package.

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
- A signed-in browser is `mcm_seed_signed_in()` plus `mcm_session_headers()`:
  the session gets a CSRF token derived from its own identifier, and the request
  carries that session's cookie and token together. The session key is a literal
  in `tests/run.php` because the suite must not load the application to describe
  it; 'guard csrf tokens' is where that literal is checked against what a page
  actually sees, so a wrong key would fail there rather than quietly leave every
  other case driving a session with no token.
- The PHP suite has no browser and does not want one. `tests/browser/xss.html`
  renders a hostile list name, movie title, poster path and movie identifier
  through the real scripts and reports what the document ended up holding. Its
  own stylesheet keeps the tab strip on one line on purpose - TabDrop moves any
  tab that wrapped into a dropdown, and `renameList()` addresses tabs by
  position, so a wrapped strip makes it rename the wrong tab. That layout
  coupling is why it needs a real, layout-capable browser rather than a DOM
  emulation: `tests/browser/run.js`, driven by `npm install && npx playwright
  install chromium && node run.js` from `tests/browser` (README.md has the
  full command), opens the page in a pinned Playwright/Chromium build, reads
  its results table and fails the run on any failing check. It is a separate,
  optional, longer-running check, not part of `php tests/run.php`; on a
  machine with no Chromium available it prints a loud `SKIP:` naming the
  missed coverage and exits zero, same as the database group below does for a
  missing database. `tests/browser/package-lock.json` pins the Playwright
  version; the Chromium build it downloads is pinned by that same version.
  `run.js` reads the page's own results table rather than adding checks of its
  own, and the page still opens by hand unchanged.
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
  It runs a TMDb stub on a second server because `add_movie.php` looks a film
  up; a fixture there without `MCM_TMDB_BASE_URL` would reach the real TMDb.
  `add_movie resolves its own metadata over a real database` is where what gets
  stored is taken apart - a fabricated title served and stored nowhere, a
  refusal costing no outbound call, the update path, and an outage. What no case
  covers is a successful import - that path calls TMDb over the network, which
  the suite does not do; its refusals happen earlier and are covered.
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

## Development tooling

- `composer.json` and `composer.lock` are development tooling only: `require`
  stays empty because nothing the site serves is a Composer package, and
  anything Composer manages lives under `require-dev`. `php tests/run.php`
  needs nothing installed and gives the same assertion count with or without a
  `/vendor` tree, so Composer is optional and never a precondition for the
  suite.
- `composer.lock` is committed so `composer install` resolves the same tool
  versions every time; `/vendor` is git-ignored and safe to delete. Run
  `composer validate --strict` after editing `composer.json`.
- PHP 8.3 is this project's modernization target (see Tests above); the
  `require-dev.php` constraint bounds Composer usage to the range the suite is
  actually exercised on, `>=8.1.0 <8.5.0`. A newer PHP is forward-compatibility
  evidence, never the target, and 8.5 is deliberately outside the constraint
  because of the captcha font regression noted above.

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
- What a browser script may name is read off its string literals, not its raw
  text: `mcm_js_absolute_urls()` fails a script that names any host of its own,
  `mcm_js_object_property()` reads one object-valued property, and
  `mcm_js_ajax_calls()` reports each call's URL, method and the field names it
  sends. That is how "the search is same-origin and the add names only two
  fields" is asserted without a browser; the server half of the same claim is
  the database group above, which is what actually proves nothing else can be
  stored.
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
  primitives of their own. `mcm_guarded_entry_points()` in
  `tests/entrypoints.php` is the written-down list of who loads them, and a page
  that starts loading them without being added there fails the suite;
  `mcm_unguarded_guard_users()` beside it names the files that load them and
  guard nothing - `index.php` and its need for `mcm_csrf_token()`, and the
  read-only TMDb proxy and its need for the refusal bodies.
- Every mutation endpoint - `create_list.php`, `rename_list.php`,
  `delete_list.php`, `adjust_lists.php`, `share_lists.php`, `add_movie.php`,
  `delete_movie.php`, `move.php`, `import_list.php` - asks all four, and the
  order is the point: `mcm_require_post()`, `mcm_require_login()` and
  `mcm_require_csrf()` need only the session, so a request they refuse has
  opened no connection and written nothing; `mcm_require_list_owner()` needs a
  database and so follows it. Anything later than that has already written
  something, because `master_movie_list` is shared between every account and an
  add writes to it before it writes to the list. `move.php` checks both ends,
  and `import_list.php` settles ownership before it calls TMDb, so a refusal
  costs no network request. Duplicate detection is scoped to the asking user in
  both `add_movie.php` and `import_list.php`.
- Each of them takes the owner from the session and never from the request, and
  each write names that owner in its own `WHERE` clause; each also reads its
  values from `$_POST` alone. Both are second layers: the guard is what refuses
  the request, and the clause and the missing query-string fallback are what
  leave the page unable to reach another account's row, or to act on a GET, if a
  guard were ever dropped. A request naming several lists checks all of them
  before writing any, so a range that reaches one list belonging to somebody
  else moves none of them.
- The token is minted on demand by `mcm_csrf_token()` and lasts as long as the
  session, so a second tab keeps working and a session signed in before the
  check existed gets a token on its next page load rather than being turned
  away. `inc/views/logged_in.php` is the only page that hands one out, through
  `mcm_js()` into its script block - never into a URL - and the suite derives
  that list from the source, so a new page that started exposing it would have
  to answer for it. The public sharing page is not authenticated, asks for no
  token, and must stay that way.
- `js/mc.js` attaches it in one `$.ajaxPrefilter`, as the `X-CSRF-Token` header
  that `MCM_CSRF_HEADER` reads. Whether a request leaves this site is jQuery's
  own answer - it resolves the URL and compares scheme, host and port before any
  prefilter runs - and the prefilter reads that answer rather than the URL. No
  request the page makes leaves this site any more, so nothing depends on it
  today; it stays because the next request somebody adds is what it is for. Do
  not re-derive it from the URL here: a pattern in a browser script is also the
  one construct `mcm_js_markers()` cannot read.
- The php-login flows - sign-in, sign-out, registration, password reset - are a
  separate surface. They are handled by the `Login` and `Registration` classes
  rather than the guards, and neither the token nor POST-only has been extended
  to them; `index.php?logout` is still a GET on purpose, because every link to
  it already in a browser would otherwise stop working.
- A refusal answers with a status from `mcm_json_error_catalogue()` and that
  status's fixed body, so two different reasons sharing a status cannot be told
  apart from outside; the reason goes to the log through `mcm_log()`. Tokens are
  never logged. A value the page itself computed - a list identifier, a
  position, a JSON array of them - is refused this way when it is malformed. A
  value a person typed is not: `mcm_list_name_error()` still answers a bad list
  name with its own bounded reason, because that one is feedback for whoever
  typed it.

## TMDb access

- `inc/tmdb.php` is the first-party, backend-only TMDb client and the only
  place a TMDb credential is read. It is loaded on demand rather than by the
  bootstrap, through `inc/tmdb_proxy.php`, which `tmdb.php` and `add_movie.php`
  both require. The credential is `TMDB_READ_ACCESS_TOKEN` and it only ever
  appears in an `Authorization: Bearer` header on a handle built and closed
  inside one call: never in a URL, a session, a page, a script or the log.
- `mcm_tmdb_transport_options()` is deliberately a pure function returning the
  cURL options, because the suite has no HTTPS endpoint to observe "verifies
  the peer" and "follows no redirect" against; those facts are asserted off the
  array a request would be made with. The behaviour that can be observed -
  bearer header, total timeout, size cap, redirect not followed, categorical
  failures - is asserted against `tests/pages/tmdb_stub.php` instead. Both are
  needed: a connect timeout dropped from the options is caught by nothing else.
- `MCM_TMDB_BASE_URL` reaches the client verbatim, which is the whole test seam,
  the same one `DB_HOST` gives the database cases. It is safe because the client
  refuses a plain-HTTP endpoint unless the host is a loopback literal - a
  request that never leaves the machine cannot put the token on a wire. Weaken
  that check and remote plaintext becomes reachable; the suite fails if you do.
- The stub is one PHP built-in server and serves one request at a time, so the
  case that makes it sleep past the timeout runs last in
  `tests/pages/tmdb_client.php`. Move it earlier and whatever follows it is
  answered late and fails as a timeout for a harness reason.
- `tmdb.php` is the proxy entry point and `inc/tmdb_proxy.php` is the whole of
  its policy: the five operations it exposes, what each accepts, the path and
  query each becomes, and the fields each answers with. A request names an
  operation, never a URL; a field the named operation does not accept is
  refused rather than ignored, which is what leaves no way to hand it a host, a
  method, an extra query field or a transport option. Refusals are the shared
  bounded bodies from `inc/guards.php`, and they happen before anything goes
  out. An answer is rebuilt field by field rather than forwarded, so an upstream
  field nobody named reaches nobody; `mcm_key_paths()` in `tests/cases.php` is
  what asserts that, by naming every key a response actually holds.
- Read-only is not the same as public, and each operation declares who may ask
  it. `configuration`, `movie` and `videos` take any session, because the public
  sharing page and the movie dialog need them without an account; `search` needs
  a signed-in caller; `list` needs one who owns the local `movie_list_id` the
  request names. The order inside `mcm_tmdb_resolve()` is the endpoints' own -
  operation, then the session, then the request's values, then the connection
  and ownership, then TMDb - so a refusal costs no outbound request, and the
  suite reads that order off that one function's tokens. `mcm_tmdb_serve()` is
  only the door: it settles the method, calls `mcm_tmdb_resolve()` and turns
  what comes back into a body. A page of this site that needs the same answer
  calls `mcm_tmdb_resolve()` directly rather than making an HTTP request to its
  own server, and passes the connection it already holds so ownership is
  settled over that one; the question is still asked, because the caller policy
  is the operation's and not something a caller may assert for it.
- Neither the POST guard nor the token guard applies, because nothing here
  writes. `mcm_read_guarded_guard_users()` in `tests/entrypoints.php` is where
  that is written down, separately from `mcm_unguarded_guard_users()`, so
  "this endpoint reads" cannot become a way to opt out of the other two guards.
- The `list` operation cannot be driven end to end without a database, so its
  behaviour lives in `tmdb proxy list ownership over a real database` and its
  projection is covered without one by `tests/pages/tmdb_projection.php`, which
  hands every projector a payload TMDb would never send. What an import writes
  from that answer is `import over a real database and a stubbed TMDb`, which
  needs the database server and both built-in servers; the refusals settled
  before a connection is opened are driven without a database by `import
  refusals cost no outbound request`. Both count outbound requests the same
  way, off the stub's own log.
- Only the configuration answer is cached, for a day, in a directory the
  application creates 0700 and writes through a temporary file and a rename, so
  a reader never sees half an answer and two writers cannot corrupt one. It
  holds the projection rather than the upstream body. The cache is advisory
  throughout: every failure means "ask again", never "fail the request". The
  suite counts what actually went out by reading the stub's own request log
  (`mcm_tmdb_stub_requests()`), which is the only way to tell a cache hit from a
  miss and to prove a refusal cost no outbound call.
- The proxy cases need two built-in servers from one fixture - the application
  on one, the stub on the other - because the built-in server answers one
  request at a time and a proxy request reaching a stub on its own server would
  wait for itself. The timeout case stays last for the reason the client's does.
- With a server open, a fatal in a case is worse than a failure: the run dies,
  its servers are orphaned, and they hold the run's own output pipe, so a
  harness driving the suite hangs rather than seeing it fail. Two habits keep
  that from happening, and both were learned the hard way during a mutation
  sweep: read into an answer with `mcm_at()` rather than `$body['data'][0]`, so
  a refusal where an answer was expected is a failing assertion instead of a
  TypeError; and catch `Throwable`, not `Exception`, in a group that has to stop
  a server on the way out.
- Everything in this site that reaches TMDb now goes through the proxy, by two
  doors, and which door a caller uses is decided by who has already asked the
  policy questions. `js/mc.js` searches over HTTP - same-origin, with the
  operation named in the URL - so no browser-served script names a TMDb host or
  a key and `git grep -nE "api_key|themoviedb" -- js/` is empty.
  `import_list.php` and `add_movie.php` call in from this process rather than
  making an HTTP request to this site's own server.
- The two in-process entry points are not the same call, and the difference is
  what a refusal does. `mcm_tmdb_resolve()` is the whole policy in order -
  operation, session, values, ownership, then TMDb - and a refusal ends the
  request there with the bounded body from `inc/guards.php`.
  `mcm_tmdb_execute()` is the execution half alone: it plans and runs, names no
  policy, and hands every failure back as a value. A caller that has not already
  settled who is asking must use `mcm_tmdb_resolve()`, which will settle it.
  Both end at `mcm_tmdb_run()`, which stays the one place an operation is
  executed and the only path to `inc/tmdb.php`.
  `mcm_tmdb_execute_callers()` in `tests/entrypoints.php` is the written-down
  list of who may call the execution half, and a page that starts calling it
  without being added there fails the suite - which is what keeps "I already
  have guards" from becoming something a page may simply assert.
- `import_list.php` is the first page on the proxy and reaches it without HTTP.
  It settles the method, the session, the token, the shape of the TMDb list
  identifier and the local list's ownership itself, and only then calls
  `mcm_tmdb_resolve('list', ...)` with the connection it opened for its own
  writes - the policy is asked again there, because the caller policy belongs to
  the operation and is not something a page may assert on its behalf. The TMDb
  list identifier is the one value a person typed, so a bad one gets its own
  bounded sentence the way a bad list name does, rather than a catalogue
  refusal; an upstream failure is `mcm_fail()`, with the category in the log and
  the generic message to the visitor.
- `add_movie.php` takes a list identifier and a film identifier and nothing
  else. The four descriptive fields a request used to carry are read nowhere,
  so they are ignored rather than refused - a browser holding a cached copy of
  the old script keeps working and still cannot describe a film - and what is
  stored is the movie projection, each value through `mcm_column_text()` to the
  width of the column that holds it. The lookup is `mcm_tmdb_execute()` and it
  happens after `mcm_require_list_owner()`, so a refusal costs no outbound
  request; it wants the returned-failure contract because it has a page of its
  own to answer, and a film it cannot resolve is answered `3` and stores
  nothing. `1` and `2` still mean inserted and duplicate.
- The vendored wrapper that used to live at `inc/classes/TMDb.inc`, and
  `TMDB_API_KEY`, the v3 credential only it read, are gone. `dialog.php`,
  `inc/views/share.php` and `inc/views/logged_in.php` all reach `configuration`
  and (`dialog.php` only) `movie` the same way `dialog.php` already reached
  `videos`: calling `mcm_tmdb_plan()` / `mcm_tmdb_run()` directly rather than
  through `mcm_tmdb_resolve()` or `mcm_tmdb_execute()` - the seam is safe there
  only because all three operations' caller policy is `any`, so a refusal or an
  upstream failure degrades to the projector's own empty shape (e.g.
  `mcm_tmdb_project_configuration(array())`) instead of ending the request, the
  way the wrapper's calls never threw either. Which of a movie's proxied videos
  are a usable YouTube trailer or teaser, and their order, is
  `mcm_dialog_usable_trailers()` in `inc/dialog_trailers.php`; the accordion and
  empty-state markup for that selection is a second pure function beside it,
  `mcm_dialog_trailer_html()`, so `dialog.php` only calls the two in sequence.
  Both are pure functions on the same pattern as the projectors, so the suite
  drives them with hand-built rows in `tests/pages/tmdb_projection.php` rather
  than a live page. The auth-session branch that used to sit in
  `inc/views/logged_in.php` - reading a request token nothing in the codebase
  ever set - is gone with the wrapper it called into; `$_SESSION['logged_in']`,
  the flag that branch alone maintained, was never read anywhere else and is
  gone with it. `grep -rn "TMDb\b" --include='*.php' .` still matches many
  lines after all of this: "TMDb" is also this product's own name, used in
  prose throughout the comments (`inc/tmdb.php`, `inc/tmdb_proxy.php` and
  elsewhere) as well as in test descriptions, so the grep does not go to zero -
  only `new TMDb(...)` and the class file itself do, and that is what
  `tests/cases.php`'s `'the vendored TMDb wrapper and auth-session path are
  gone'` group checks across every project source file.

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
