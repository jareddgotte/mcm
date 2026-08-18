/*
 * Browser-side element builders.
 *
 * The server escapes everything it renders itself - mcm_html(), mcm_url() and
 * mcm_js() in inc/bootstrap.php - and hands the page its data as JSON. This
 * file is the other half of that defence: once the data is in the browser, a
 * list name or a TMDb title is a value rather than markup, so the scripts build
 * an element and assign the value as text or as an attribute instead of
 * concatenating it into a string of HTML.
 *
 * Nothing here changes a value. A list named "<b>x</b>" is stored as those nine
 * characters and shows up as those nine characters; only the way it reaches the
 * document changes.
 *
 * Both js/mc.js (the collection page) and js/share.js (the public sharing page)
 * render the same posters, suggestions and list headings, so they build them
 * from here.
 */

// A value as a text node. Everything that is not markup the page wrote itself
// goes through this, or through .text() and .attr(), which do the same thing.
function mcmText (value) {
	return document.createTextNode((value === null || value === undefined) ? '' : String(value))
}

// Append content to an element without ever parsing it as HTML: a string
// becomes text, an element or jQuery object is appended as it is, and an array
// is appended in order, so a caller can mix its own wording with built
// elements.
function mcmAppend ($element, content) {
	if (content === null || content === undefined) return $element

	if ($.isArray(content)) {
		$.each(content, function (i, part) { mcmAppend($element, part) })
		return $element
	}
	if (typeof content === 'string' || typeof content === 'number') {
		return $element.append(mcmText(content))
	}
	return $element.append(content)
}

// <abbr title="...">text</abbr>, the shape every alert on the collection page
// uses to name the movie or the list it is talking about.
function mcmAbbr (title, text) {
	return $('<abbr>').attr('title', (title === null || title === undefined) ? '' : String(title)).text(text)
}

// One movie poster. The identifier, the poster URL and the title all come from
// TMDb by way of the page's JSON; the title is also what the popover shows,
// which Bootstrap renders as text.
function mcmPosterImage (movie, base_url, poster_size) {
	return $('<img>')
		.addClass('lazy img-thumbnail')
		.attr('id', movie.movie_id)
		.attr('data-original', base_url + poster_size + movie.poster_path)
		.attr('alt', movie.title)
}

// One movie-list tab, the browser-side twin of mcm_list_tab_html(): the same
// strip is rendered by the server on the first load and extended here when a
// list is created or the lists are reordered.
function mcmListTab (list_id, list_name) {
	return $('<li>')
		.attr('data-listid', list_id)
		.append($('<a>').attr('href', '#' + list_id).attr('data-toggle', 'pill').text(list_name))
}

// The empty container a tab reveals, the twin of mcm_list_pane_html().
function mcmListPane (list_id) {
	return $('<div>').addClass('tab-pane').attr('id', list_id)
}

// The heading typeahead puts above one list's suggestions. typeahead prepends
// whatever the template returns, and jQuery appends an element as an element,
// so this hands back a node rather than a string.
function mcmListHeader (list_name) {
	return $('<h4>').text(list_name)
}

// One typeahead suggestion, as the markup typeahead insists on: it substitutes
// the result into a wrapper of its own with String.replace(), so this template
// cannot hand back an element the way the heading does. The element is built
// first and serialised at the end, so no value is ever concatenated into
// markup.
function mcmSuggestionMarkup (datum, base_url) {
	var $poster = $('<img>')
		.addClass('img-thumbnail')
		.attr('src', base_url + 'w45' + datum.tmdb_poster_path)
		.attr('alt', datum.tmdb_title)
		.attr('width', '55')
		.attr('height', '78')
	if (datum.classed) $poster.addClass(datum.classed)

	var $year = $('<small>')
	mcmAppend($year, ['(', mcmAbbr(datum.tmdb_release_date, datum.tmdb_release_date_abbr), ')'])

	var $body = $('<span>')
	mcmAppend($body, [$('<strong>').text(datum.tmdb_title), ' ', $year])

	return mcmMarkup($('<p>').append($poster).append($body))
}

// An element as the markup that would rebuild it, for the one interface that
// takes nothing else. Serialising an element that was built by assignment is
// safe in a way that building the same string by concatenation is not.
function mcmMarkup ($element) {
	return $('<div>').append($element).html()
}
