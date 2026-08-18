<?php

require_once(__DIR__ . '/inc/bootstrap.php');
// The bounded refusal bodies, the shared value checks, and two of the four
// guards: inc/tmdb_proxy.php asks for a signed-in user where an operation needs
// one, and for the owner of a list where it names one. Neither the POST guard
// nor the token guard applies - every operation is read-only, so there is no
// write for a token to protect, and the public sharing page, which has no
// token, is one of the pages that will ask.
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');

/**
 * The TMDb proxy.
 *
 *     GET  /tmdb.php?operation=configuration                      any session
 *     GET  /tmdb.php?operation=movie&movie_id=550                  any session
 *     GET  /tmdb.php?operation=videos&movie_id=550                 any session
 *     GET  /tmdb.php?operation=search&query=alien&page=1           signed in
 *     POST /tmdb.php  operation=list&list_id=<tmdb>&movie_list_id=<local>
 *                                                                  list owner
 *
 * Those five and nothing else. What each of them accepts, who may ask it, what
 * it asks TMDb for and which fields come back are all declared in
 * inc/tmdb_proxy.php; this file is only the door. A request that names anything
 * else, or carries a field the named operation does not accept, is refused here
 * with the same bounded JSON body every other endpoint refuses with, before any
 * request leaves this server.
 *
 * Read-only is not the same as public. The three open operations are the ones
 * the public sharing page and the movie dialog need without an account. Search
 * needs a signed-in caller, and a list needs one who owns the local list the
 * request names - checked before TMDb is asked anything, so a refusal costs no
 * outbound request.
 *
 * A GET and a POST are both served because every operation is read-only:
 * nothing here changes a row, so there is nothing for a CSRF token to protect
 * and no reason to turn away a page that has none.
 *
 * Nothing in this site calls it yet. Movie search, the trailer modal and list
 * import are moved onto it by issues #36, #37 and #38.
 */

// The request's own fields, from the body of a POST or the query string of a
// GET - never both at once, so a value cannot be smuggled past the method that
// was actually used.
mcm_tmdb_serve(mcm_request_is_post() ? $_POST : $_GET);
