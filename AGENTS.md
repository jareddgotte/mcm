# Project agent memory

This file is the project's committed home for project-intrinsic agent knowledge: build, test, release, architecture, and sharp-edge notes that should travel with the code.

## What this project is

A legacy PHP movie-collection manager built on the php-login script. There is no
dependency manager, no build step and no test suite; the `.php` files in the
document root are the application. Setup is documented in `README.md`.

## Request lifecycle

- `inc/bootstrap.php` is the shared bootstrap. Every public entry point includes
  it first and exactly once, and it is the single place that loads
  configuration, installs the error/exception/shutdown handlers, and calls
  `session_start()`. Add new cross-cutting request setup there, not in an entry
  point.
- Public entry points are every `*.php` in the document root plus
  `inc/showCaptcha.php`, which the browser requests directly for the
  registration captcha. A new entry point must include the bootstrap.
- `session_start()` must exist in exactly one place. `grep -rn "session_start("
  --include="*.php" .` is the check.
- The session cookie name stays at the server default. Renaming it would sign
  out every visitor of the live site.
- Configuration is layered: `inc/config/config.php` (untracked, real values)
  wins, and the bootstrap fills in safe defaults for anything it omits.
  `inc/config/example_config.php` is tracked and must only ever hold
  placeholders. Config files refuse to run unless `MCM_BOOTSTRAP` is defined.

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
