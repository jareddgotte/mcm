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

### Tests
Run `php tests/run.php`.  The suite covers `/inc/bootstrap.php` and needs nothing but a PHP CLI: no package manager, no test framework, no database, and no web server beyond the one built into PHP.  Every case works on a throw-away copy of the site under the system temp directory, so running the suite never touches your checkout or your configuration.  Add `--filter=<text>` to run a single group.

### Notes
- The `/inc` directory is served-but-internal, so `/inc/.htaccess` and `/inc/config/.htaccess` deny direct web access to it.  The registration captcha at `/inc/showCaptcha.php` is the one deliberate exception, since the browser requests that image directly.
- Config files check for the `MCM_BOOTSTRAP` constant, which is defined by `/inc/bootstrap.php` before the config is included.  This means a direct request to a config file stops immediately even if a web-server rule is missing.
- `/inc/bootstrap.php` is the shared application bootstrap.  Every public entry point includes it first and exactly once, and it is the single place that loads configuration, installs the error and exception handlers, and starts the session.  All of its own settings are optional and documented in `/inc/config/example_config.php`; leaving them out gives you the safe defaults.
