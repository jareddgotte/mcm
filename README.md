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
You cannot see the structure of the database nor the configuration file used in this project.

The database structure was exported from PHPMyAdmin into the file `/.your_database.sql`.  Please edit it to change "your_database" to the name of the database you are going to use before importing it into your own.  Remember to delete this file after importing it!

The configuration file would normally be located at `/inc/config/config.php`.  However, I had Git ignore it since it has sensitive information within.  Therefore, I included a `/inc/config/sample_config.php` so you can just rename it to `config.php` then change the appropriate information within the file.
