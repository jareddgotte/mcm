// This function actually displays the table of movies depending on which table we want to display
function displayTable () {
	//console.log('displaying table')
	
	// Sort/Order Movie Lists
	var ListItemsJSON = db[currentListPos].movie_details
	if (currentSort === 'name') {
		ListItemsJSON.sort(function (a, b) { // Sorts alphabetically by title in asc order.
			var x = a.title.toLowerCase()
			var y = b.title.toLowerCase()
			if (x.substr(0, 4) === 'the ') x = x.substr(4) // Do not consider the word \"the\" if it's in the beginning of the title while sorting by name.
			if (y.substr(0, 4) === 'the ') y = y.substr(4)
			return x < y ? -1 :
			       x > y ?  1 :
			       0
		})
	}
	else if (currentSort === 'date') {
		ListItemsJSON.sort(function (a, b) { // Sorts alphabetically by title in asc order.
			var x = a.title.toLowerCase()
			var y = b.title.toLowerCase()
			if (x.substr(0, 4) === 'the ') x = x.substr(4) // Do not consider the word \"the\" if it's in the beginning of the title while sorting by name.
			if (y.substr(0, 4) === 'the ') y = y.substr(4)
			return x > y ? -1 :
			       x < y ?  1 :
			       0
		})
		ListItemsJSON.sort(function (a, b) { // Sorts alphabetically by date in asc order.
			var x = a.release_date
			var y = b.release_date
			return x < y ? -1 :
			       x > y ?  1 :
			       0
		})
	}
	else {
		console.log('unexpected sorting order in displayTable(): ' + currentSort)
		return false
	}
	if (currentOrder === 'desc') { ListItemsJSON.reverse() }
	$('#sort-order li').removeClass('alert-info disabled')
	$('#' + currentSort).parent().addClass('alert-info disabled')
	$('#' + currentOrder).parent().addClass('alert-info disabled')

	// Create the HTML we are going to use as our table of movies
	html = '<div class="posters">'
	$.each(ListItemsJSON, function (i, v) {
		if (v === undefined) return true
		if (v.poster_path !== null) {
			html += '<img class="lazy img-thumbnail" id="' + v.movie_id + '" data-original="' + base_url + poster_size_big + v.poster_path + '" alt="' + v.title + "\">\n"
		}
	})
	html += '</div>'
	//console.log(currentList)
	$('#' + currentList).html(html) // Set that HTML now
	
	enableFunctions() // We enable our functions now so tooltips and the dialog box work.
}

function listPos (list_id) {
	return $.inArray(list_id, $.map(db, function (v) { return v.list_id }))
}

function getCookie (cName) {
	var c_value = document.cookie, c_start = c_value.indexOf(' ' + cName + '=')
	if (c_start === -1) c_start = c_value.indexOf(cName + '=')
	if (c_start === -1) c_value = null
	else {
		c_start = c_value.indexOf('=', c_start) + 1
		var c_end = c_value.indexOf(';', c_start)
		if (c_end === -1) c_end = c_value.length
		c_value = unescape(c_value.substring(c_start, c_end))
	}
	return c_value
}

function setCookie (cName, value, exDays) {
	var exdate = new Date()
	var c_value
	exdate.setDate(exdate.getDate() + exDays)
	c_value = escape(value) + ((exDays === null) ? '' : '; expires=' + exdate.toUTCString())
	document.cookie = cName + '=' + c_value
}

function checkSortOrder (cName) {
	var tmp = getCookie(cName)
	if (tmp !== null && tmp !== '') {
		return tmp
	} else {
		if (cName === 'sort') {
			setCookie(cName, 'name', 365)
			return 'name'
		}
		if (cName === 'order') {
			setCookie(cName, 'asc', 365)
			return 'asc'
		}
	}
	return null
}

// Move the movie locally so we don't have to refresh the page to see the update
function moveMovie (from_list, to_list, movie_id) {
	var flist = db[listPos(from_list)].movie_details
	var element
	for (var key in flist) {
		if (flist[key].movie_id === movie_id) {
			element = flist[key]
			delete flist[key]
			break
		}
	}
	db[listPos(to_list)].movie_details.push(element)
}

// This function is necessary because for every time we switch tables, we must enable the Tooltip and Dialog functions.
function enableFunctions () {
	//console.log('en fncs: ' + currentList)
	
	$('#' + currentList + ' img.lazy').lazyload({ threshold: 200 })
	
	$('#' + currentList + ' img')
		.mousemove(function (e) {
			// from e you can get the pageX(left position) and pageY(top position)
			$('.popover').css({ 'left' : e.pageX + 15, 'top' : e.pageY - 10 })
		})
		.mouseout(function () { 
			$('.popover').css('display', 'none')
		})
		.popover({ // This code chunk turns the tooltip into what the image's alt attr has as its value.
			placement: 'right'
		,	trigger: 'hover'
		,	content: function () { return $(this).attr('alt') }
		})
		.on('click', function () { // The following bit of code is for opening the Modal Dialog box for the movie we are interested in finding out more about.
			//$('#dialog').attr('data-remote', 'dialog.php?id=' + $(this)[0]['id'])
			//$('#dialog #overview').css('display', 'none')
			var movie_id = $(this).attr('id')
			//console.time('modal exec time')
			$('#dialog').modal({ remote: 'dialog.php?id=' + movie_id })
			//console.timeEnd('modal exec time')
		});
}

$(function () {
	// We care about hashes so our table can go to the correct tab upon refreshing or directly being linked to a particular list
	var tabNum = 1
	if (window.location.hash.substr(1, 5) === 'list-') {
		var tabId = window.location.hash.substr(6)
		tabNum = listPos(tabId)
		if (tabNum >= 0) {
			currentList = tabId
			currentListPos = listPos(currentList)
		}
		tabNum += (tabNum < 0) ? 2 : 1 // this offset is for the :nth-child() selector
	}
	$('#' + currentList).addClass('active')
	$('#list-tabs li:nth-child(' + tabNum + ')').addClass('active')

	//console.log('tab: ' + tab)
	/*var start_state
	$('#list-tabs').sortable({
		axis: 'x'
	,	start: function () {
			start_state = $(this).sortable('toArray', { attribute: 'data-listid' })
			if (start_state[0] === '') start_state.shift()
		}
	,	update: function (i, ui) {
			console.log(start_state)
			var stop_state = $(this).sortable('toArray', { attribute: 'data-listid' })
			if (stop_state[0] === '') stop_state.shift()
			console.log(stop_state)
			console.log(start_state.indexOf($(ui.item).attr('data-listid')))
			console.log(stop_state.indexOf($(ui.item).attr('data-listid')))
		}
	})
	$(window).on('tabdrop.on', function () { $('#list-tabs').sortable('disable') })
	$(window).on('tabdrop.off', function () { $('#list-tabs').sortable('enable') })*/
	$('#list-tabs').tabdrop()
	
	$('#list-options a').click(function (e) {
		e.preventDefault();
		//if ($(this).parent().hasClass('disabled')) e.preventDefault();
		//console.log($(this).attr('href'))
		if ($(this).attr('href') === '#import') {
			$('#import-dialog').modal()
		}
	})
	$('#import-submit').on('click', function () {
		$('#import-alerts').html('')
		//console.log('importing')
		$(this).addClass('disabled')
		//console.log($('#import-tmdb_list_id').val())
		var import_tmdb_list_id = $('#import-tmdb_list_id').val()
		if (import_tmdb_list_id === '') {
			$('#import-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>You must supply a valid TMDb list id.</div>')
				that.removeClass('disabled')
		}
		else {
			var that = $(this)
			$.ajax({
				type: 'POST'
			,	url: 'import_list.php' // move.php is where we handle the actual movement of movie between TMDb lists
			,	data: { movie_list_id: currentList, tmdb_list_id: import_tmdb_list_id }
			})
			.done(function (msg) {
				if (msg.substr(0, 12) === 'greatsuccess') {
					$('#import-alerts').html('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully imported list!</div>')
					db = JSON.parse(msg.substr(12))
					db[currentListPos].display_log = 0
					displayTable()
				}
				else $('#import-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
				that.removeClass('disabled')
				$('#import-tmdb_list_id').val('')
				//console.log(msg) // Useful for debugging
			})
		}
	});
	$('#sort-order a').click(function (e) {
		e.preventDefault()
		if ($(this).hasClass('disabled')) return false
		var href = $(this).attr('href')

		if ($(this).hasClass('sort')) {
			currentSort = href.substr(1)
		} else if ($(this).hasClass('order')) {
			currentOrder = href.substr(1)
		}
		// So we don't redisplay unchanged data
		$.each(db, function () { this.display_log = 0 })
		db[currentListPos].display_log = 1

		displayTable()
	})

	displayTable() // By default, display the "What to See" table
	db[currentListPos].display_log = 1
	
	// Load our tables for their respective topics
	$('#list-tabs a').on('click', function () {
		//e.preventDefault()
		if ($(this).parent().hasClass('tabdrop')) return true
		/*tabNum = $.inArray($(this).attr('href').substr(1), Object.keys(db))
		if (tabNum < 0) return*/
		//console.log('clicked')
		//console.log(currentList)
		currentList = $(this).attr('href').substr(1)
		currentListPos = listPos(currentList)
		//console.log(currentList)
		window.location.hash = 'list-' + currentList
		if (db[currentListPos].display_log === 0) {
			displayTable()
			db[currentListPos].display_log = 1
		}
		$(this).tab('show')
		$(window).trigger('scroll')
	})
	
	// ADD MOVIE
	// This template is used for both #add-movie and #search-collection
	var template = '<p><img class="img-thumbnail {{classed}}" src="' + base_url + 'w45{{tmdb_poster_path}}" alt="{{tmdb_title}}" width="55" height="78"><span><strong>{{tmdb_title}}</strong> <small>(<abbr title="{{tmdb_release_date}}">{{tmdb_release_date_abbr}}</abbr>)</small></span></p>'
	$('#add_movie').typeahead({
		name: 'add_movie'
	,	valueKey: 'tmdb_title'
	,	engine: Hogan
	, template: template
	, remote: {
			url: 'http://api.themoviedb.org/3/search/movie?api_key=1c36628b5c5648a1e1079924b98c0925&search_type=ngram&query=%QUERY'
		,	rateLimitWait: 1000
		, maxParallelRequests: 2
		,	filter: function (data) {
				var add_movie_map = $.map(data.results, function (v) {
					var classed = ''
					if (v.poster_path === null) classed = 'invisible'
					return { tmdb_title: v.title, tokens: v.title.split(' '), tmdb_movie_id: v.id, tmdb_original_title: v.original_title, tmdb_poster_path: v.poster_path, tmdb_release_date: v.release_date, tmdb_release_date_abbr: v.release_date.substr(0, 4), classed: classed }
				})
				return add_movie_map
			}
		}
	})
	$('#add_movie').on('typeahead:selected', function (e, o, name) {
		console.log(o)
		$.ajax({
			type: 'POST'
		,	url: 'add_movie.php' // move.php is where we handle the actual movement of movie between TMDb lists
		,	data: { movie_list_id: currentList, tmdb_movie_id: o.tmdb_movie_id, tmdb_title: o.tmdb_title, tmdb_original_title: o.tmdb_original_title, tmdb_poster_path: o.tmdb_poster_path, tmdb_release_date: o.tmdb_release_date }
		})
		.done(function (msg) {
			console.log(msg) // Useful for debugging
			if (msg.substr(0, 12) === 'greatsuccess') {
				$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully added movie!</div>')
				db = JSON.parse(msg.substr(12))
				db[currentListPos].display_log = 0
				displayTable()
			}
			else $('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
		})
	})
	
	// SEARCH COLLECTION FOR MOVIE
	var search_collection_map = $.map(db, function (v) {
		var movie_details_map = $.map(v.movie_details, function (w) {
			var classed = ''
			if (w.poster_path === null) classed = 'invisible'
			var tags = []
			$.each(w.title.concat(' ', w.original_title, ' ', w.release_date.substr(0, 4)).split(' '), function (i, e) {
				if ($.inArray(e, tags) === -1) tags.push(e)
			})
			return { tmdb_title: w.title, tokens: tags, tmdb_movie_id: w.movie_id, tmdb_poster_path: w.poster_path, tmdb_release_date: w.release_date, tmdb_release_date_abbr: w.release_date.substr(0, 4), classed: classed }
		})
		return { name: v.movie_list_id, valueKey: 'tmdb_title', local: movie_details_map, header: '<h4>' + v.list_name + '</h4>', engine: Hogan, template: template }
	})
	$('#search_collection').typeahead(search_collection_map)
	$('#search_collection').on('typeahead:selected', function (e, o, name) {
		$('#dialog').modal({ remote: 'dialog.php?id=' + o.tmdb_movie_id })
	})

	// For both #add-movie and #search-collection to make hint's style similar to the existing input
	$('#list-control .tt-hint').addClass('form-control')

	$('#dialog').on('hidden.bs.modal', function () {
		//console.log('hidden')
		$(this).removeData('bs.modal')
		$('#dialog').empty()
	}).on('shown.bs.modal', function() {
		//console.timeEnd('modal exec time')
		//console.log('shown')
		// Generate Overview popover
		$('#overview').on('click', function () {
			console.log('test')
			$('#overview-content').toggle(400)
		})
		$('#overview-content-close').on('click', function () {
			$('#overview-content').hide(400)
		})
		// Generate move-to-list dropdown options
		var movie_options_html = '<li class="alert-danger"><a href="#delete">Delete</a></li>'
		movie_options_html += '<li class="divider" role="presentation"></li><li class="dropdown-header" role="presentation">Move to...</li>'
		$.each(db, function(i, v) {
			movie_options_html += '<li'
			if (db[currentListPos].list_id === v.list_id) movie_options_html += ' class="disabled"'
			movie_options_html += '><a href="#' + v.list_id + '">' + v.list_name + '</a></li>'
		})
		$('#movie-options').append(movie_options_html)
		// Handle movie-options option click
		$('#movie-options a').on('click', function (e) {
			e.preventDefault()
			var movie_id = $('#dialog #movie-id').html()
if ($(this).attr('href').substr(1) === 'delete') {
				$('#dialog .modal-body').hide(400)
				$('#dialog .modal-body').after('<div class="alert alert-danger" style="margin:20px" id="delete-alert"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button><p>Are you <strong>sure</strong> you want to <strong>delete</strong> this movie from your "' + db[currentListPos].list_name + '" list?</p><p><button class="btn btn-danger" type="button" id="delete-yes">Yes</button> <button class="btn btn-default" type="button" id="delete-no">No, I do not want to</button></p></div>')
				$('#delete-no').on('click', function () {
					$('#dialog .modal-body').show(400)
					$('#delete-alert').remove()
				})
				$('#delete-yes').on('click', function () {
					$('#dialog').modal('hide')
					$.ajax({
						type: 'POST'
					,	url: 'delete_movie.php' // move.php is where we handle the actual movement of movie between TMDb lists
					,	data: { movie_list_id: currentList, tmdb_movie_id: movie_id }
					})
					.done(function (msg) {
						console.log(msg) // Useful for debugging
						if (msg.substr(0, 12) === 'greatsuccess') {
							$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully deleted movie!</div>')
							db = JSON.parse(msg.substr(12))
							db[currentListPos].display_log = 0
							displayTable()
						}
						else $('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
					})
				})
				return true
			}
			$('#dialog').html('')
			var toList = $(this).attr('href').substr(1)
			//console.log(tlist)
			$.ajax({
				type: 'POST'
			,	url: 'move.php' // move.php is where we handle the actual movement of movie between TMDb lists
			,	data: { from_list: currentList, to_list: toList, movie_id: movie_id }
			})
			.done(function (msg) {
				//console.log(msg) // Useful for debugging move.php
				moveMovie(currentList, toList, movie_id)
				$('#dialog').modal('hide')
				displayTable()
				db[currentListPos].display_log = 0
				db[listPos(toList)].display_log = 0
			})
		})
	})
});
