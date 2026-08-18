<?php

require_once(__DIR__ . '/inc/bootstrap.php');
// The bounded refusal bodies and the shared value checks, and the five
// operations this entry point exposes. None of the four guards is called here:
// this endpoint reads and never writes, and the pages that will use it include
// the public sharing page, which is not signed in and asks for no token.
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');

/**
 * The TMDb proxy.
 *
 *     GET  /tmdb.php?operation=configuration
 *     GET  /tmdb.php?operation=search&query=alien&page=1
 *     GET  /tmdb.php?operation=movie&movie_id=550
 *     GET  /tmdb.php?operation=videos&movie_id=550
 *     POST /tmdb.php  operation=list&list_id=5212934a760ee36af148407c
 *
 * Those five and nothing else. What each of them accepts, what it asks TMDb
 * for and which fields come back are all declared in inc/tmdb_proxy.php; this
 * file is only the door. A request that names anything else, or carries a
 * field the named operation does not accept, is refused here with the same
 * bounded JSON body every other endpoint refuses with, before any request
 * leaves this server.
 *
 * A GET and a POST are both served because every operation is read-only:
 * nothing here changes a row, so there is nothing for a CSRF token to protect
 * and no reason to turn away the public sharing page, which has none.
 *
 * Nothing in this site calls it yet. Movie search, the trailer modal and list
 * import are moved onto it by issues #36, #37 and #38.
 */

// The request's own fields, from the body of a POST or the query string of a
// GET - never both at once, so a value cannot be smuggled past the method that
// was actually used.
mcm_tmdb_serve(mcm_request_is_post() ? $_POST : $_GET);
