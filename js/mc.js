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
	if (ListItemsJSON.length === 0) html += '<div class="alert alert-info" style="margin: 0 6px;"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Your list is currently empty.  Add a movie by typing a movie\'s title where you see "Add a Movie" above.</div>'
	else {
		$.each(ListItemsJSON, function (i, v) {
			if (v.poster_path !== null) {
				html += '<img class="lazy img-thumbnail" id="' + v.movie_id + '" data-original="' + base_url + poster_size_big + v.poster_path + '" alt="' + v.title + "\">"
			}
		})
	}
	html += '</div>'
	//console.log(currentList)
	$('#' + currentList).html(html) // Set that HTML now
	
	$('.tab-pane img:first-child').bind('transitionend webkitTransitionEnd oTransitionEnd', function(e) {
		if (e.originalEvent.propertyName === 'width') {
			$(window).trigger('scroll')
		}
	})
	$(window).trigger('resize')
	enableFunctions() // We enable our functions now so tooltips and the dialog box work.
}

function listPos (list_id) {
	return $.inArray(list_id*1, $.map(db, function (v) { return v.list_id })) // list_id is multiplied by 1 so it becomes an int
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

function createList (list_name, list_id) {
	$('#welcome-alert').remove()
	window.location.hash = 'list-' + list_id
	$('#list-tabs').append('<li data-listid="' + list_id + '"><a href="#' + list_id + '" data-toggle="pill">' + list_name + '</a></li>');
	$('#list-containers').append('<div class="tab-pane" id="' + list_id + '"></div>');
	db.push({ display_log: 0, list_description: '', list_id: list_id*1, list_name: list_name, movie_details: []}) // list_id is multiplied by 1 to become an int
	currentList = list_id
	//console.log('createList: ' + currentList)
	currentListPos = listPos(currentList)
	displayTable()
	$('#list-tabs li:last-child a').click()
	$(window).trigger('resize')
	enableLists()
}

function deleteList (list_id) {
	//console.log('deleting list')
	var pos = listPos(list_id)
	//console.log(pos)
	db.splice(pos, 1)
	$('#list-tabs li:nth-child(' + (+pos + 2) + ')').remove()
	if (pos === 0) pos++
	$('#list-tabs li:nth-child(' + (+pos + 1) + ') a').click()
	$('#' + list_id).remove()
	if (db.length === 0) {
		$('#list-control').hide()
	}
}

function renameList (list_id, list_name) {
	var pos = listPos(list_id)
	db[pos].list_name = list_name
	$('#list-tabs li:nth-child(' + (+pos + 2) + ') a').html(list_name)
}

function adjustLists (stop_state) {
	var tmp = []
	$.each(stop_state, function (i, e) {
		tmp[i] = db[listPos(e)]
	})
	db = tmp
	$('#list-tabs').children().each(function (i, e) { if (i !== 0) $(e).remove() }) // this removes every list tab while leaving the TabDrop <li> in place so the plugin will still work after adjusting the lists
	$('#list-containers').empty()
	$.each(db, function (i, e) {
		$('#list-tabs').append('<li data-listid="' + e.list_id + '"><a href="#' + e.list_id + '" data-toggle="pill">' + e.list_name + '</a></li>')
		$('#list-containers').append('<div class="tab-pane" id="' + e.list_id + '"></div>')
	})
	$.each(db, function () { this.display_log = 0 })
	
	enableLists()
}

function enableLists () {
	// Prevent all hash links from changing the hash in the location bar
	$('a').on('click', function (e) {
		if ($(this).attr('href').substr(0, 1) === '#') e.preventDefault()
	})
	
	//console.log('tab: ' + tab)
	$('#list-tabs').tabdrop()
	
	// We care about hashes so our table can go to the correct tab upon refreshing or directly being linked to a particular list
	var tabId, tabNum = 1
	if (window.location.hash.substr(1, 5) === 'list-') {
		tabId = window.location.hash.substr(6)
	}
	else tabId = currentList
	tabNum = listPos(tabId)
	if (tabNum >= 0) {
		currentList = tabId
		currentListPos = listPos(currentList)
	}
	tabNum += (tabNum < 0) ? 2 : 1 // this offset is for the :nth-child() selector
	tabNum++ // this offset is for TabDrop

	$('#' + currentList).addClass('active')
	$('#list-tabs li:nth-child(' + tabNum + ')').addClass('active')
	
	// Load our tables for their respective topics
	$('#list-tabs a').on('click', function () {
		if ($(this).parent().hasClass('tabdrop')) return true
		//console.log('clicked')
		//console.log(currentList)
		currentList = $(this).attr('href').substr(1)
		currentListPos = listPos(currentList)
		window.location.hash = 'list-' + currentList
		if (db[currentListPos].display_log === 0) {
			displayTable()
			db[currentListPos].display_log = 1
		}
		$(this).tab('show')
		$(window).trigger('resize')
		$(window).trigger('scroll')
	})
	
	if (db.length > 0) {
		displayTable() // By default, display the "What to See" table
		db[currentListPos].display_log = 1
	} else {
		$('#main-alerts').append('<div class="alert alert-info" id="welcome-alert"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Welcome! Since you are new, please create a new list by clicking on the "Create List" button at the top.  Please enjoy!</div>')
		$('#list-control').hide()
	}
}

function addMovie (list_id, movie_id, title, otitle, path, date) {
	db[listPos(list_id)].movie_details.push({ movie_id: movie_id, title: title, original_title: otitle, poster_path: path, release_date: date })
}

function deleteMovie (list_id, movie_id) {
	var flist = db[listPos(list_id)].movie_details
	$.each(flist, function (i, e) {
		if (e.movie_id == movie_id) {
			flist.splice(i, 1)
			return false;
		}
	})
}

// Move the movie locally so we don't have to refresh the page to see the update
function moveMovie (from_list_id, to_list_id, movie_id) {
	var flist = db[listPos(from_list_id)].movie_details
	$.each(flist, function (i, e) {
		if (e.movie_id === movie_id) {
			db[listPos(to_list_id)].movie_details.push(flist.splice(i, 1)[0])
			return false;
		}
	})
}

function enableSearchCollection (template) {
	// SEARCH COLLECTION FOR MOVIE
	if (db.length > 0) {
		var taObjs = []
		var bhObjs = []
		$.each(db, function (i, e) {
			bhObjs.push(new Bloodhound({
				datumTokenizer: function(d) { return Bloodhound.tokenizers.whitespace(d.tmdb_title) }
			,	queryTokenizer: Bloodhound.tokenizers.whitespace
			,	limit: 5
			,	local: $.map(e.movie_details, function (w) {
					var classed = ''
					if (w.poster_path === null) classed = 'invisible'
					return { tmdb_title: w.title, tmdb_movie_id: w.movie_id, tmdb_poster_path: w.poster_path, tmdb_release_date: w.release_date, tmdb_release_date_abbr: w.release_date.substr(0, 4), classed: classed }
				})
			}))
			
			bhObjs[i].initialize()
			
			taObjs.push({
				name: 'list_' + i
			,	displayKey: 'tmdb_title'
			,	source: bhObjs[i].ttAdapter()
			,	templates: {
					suggestion: Handlebars.compile(template)
				,	header: '<h4>' + e.list_name + '</h4>'
				}
			})
		})
		
		$('#search_collection').val('')
		$('#search_collection').typeahead('destroy')
		$('#search_collection').typeahead(null, taObjs)
	}
	$('#search_collection').on('typeahead:selected', function (e, o, name) {
		$('#dialog').modal({ remote: 'dialog.php?id=' + o.tmdb_movie_id })
	})
	$('#list-control .tt-hint').addClass('form-control')
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
			var movie_id = $(this).attr('id')
			//console.time('modal exec time')
			$('#dialog').modal({ remote: 'dialog.php?id=' + movie_id })
			//console.timeEnd('modal exec time')
		});
}

$(function () {
	enableLists()
	
	$('#list-control a').click(function () {
		if ($(this).parent().hasClass('disabled')) return false
	})
	
	$('#list-options a').click(function () {
		//console.log($(this).attr('href'))
		if ($(this).attr('href') === '#rename') {
			//console.log('renaming')
			$('#rename-dialog').modal()
			$('#rename-submit').on('click', function () {
				$('#rename-alerts').empty()
				//console.log('renaming')
				$(this).addClass('disabled')
				//console.log($('#rename-list_name').val())
				var list_description = ''; // Haven't implemented this yet
				var rename_list_name = $('#rename-list_name').val()
				if (rename_list_name === '') {
					$('#rename-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>You must supply a valid list name.</div>')
						that.removeClass('disabled')
				}
				else {
					var that = $(this)
					$.ajax({
						type: 'POST'
					,	url: 'rename_list.php'
					,	data: { movie_list_id: currentList, list_name: rename_list_name }
					})
					.done(function (msg) {
						//console.log(msg) // Useful for debugging
						// rely on the msg to see our new list id
						that.removeClass('disabled')
						$('#rename-list_name').val('')
						if (msg.substr(0, 12) === 'greatsuccess') {
							$('#main-alerts').html('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully renamed list!</div>')
							renameList(currentList, rename_list_name)
							$('#rename-dialog').modal('hide')
						}
						else $('#rename-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
					})
				}
			});
		}
		else if ($(this).attr('href') === '#import') {
			$('#import-dialog').modal()
		}
		else if ($(this).attr('href') === '#delete') {
			$('#delete-dialog').modal()
			$('#list-delete-yes').on('click', function () {
				$.ajax({
					type: 'POST'
				,	url: 'delete_list.php'
				,	data: { movie_list_id: currentList }
				})
				.done(function (msg) {
					//console.log(msg) // Useful for debugging
					if (msg.substr(0, 12) === 'greatsuccess') {
						//console.log(currentList)
						$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully deleted list!</div>')
						window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 5000)
						deleteList(currentList)
					}
					else {
						$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong while trying to delete your list!</div>')
					}
					$('#list-delete-yes').off('click')
					$('#delete-dialog').modal('hide')
				})
			})
		}
	})
	$('#import-submit').on('click', function () {
		$('#import-alerts').empty()
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
			,	url: 'import_list.php'
			,	data: { movie_list_id: currentList, tmdb_list_id: import_tmdb_list_id }
			})
			.done(function (msg) {
				//console.log(msg) // Useful for debugging
				if (msg.substr(0, 12) === 'greatsuccess') {
					$('#import-alerts').html('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully imported list!</div>')
					db = JSON.parse(msg.substr(12))
					db[currentListPos].display_log = 0
					displayTable()
				}
				else $('#import-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
				that.removeClass('disabled')
				$('#import-tmdb_list_id').val('')
			})
		}
	});
	$('#sort-order a').click(function () {
		if ($(this).parent().hasClass('disabled')) return
		var href = $(this).attr('href')

		if ($(this).hasClass('sort')) {
			currentSort = href.substr(1)
			setCookie('sort', href.substr(1), 365)
		} else if ($(this).hasClass('order')) {
			currentOrder = href.substr(1)
			setCookie('order', href.substr(1), 365)
		}
		// So we don't redisplay unchanged data
		$.each(db, function () { this.display_log = 0 })
		db[currentListPos].display_log = 1
		
		displayTable()
	})

	// This template is used for both #add-movie and #search-collection
	var template = '<p><img class="img-thumbnail {{classed}}" src="' + base_url + 'w45{{tmdb_poster_path}}" alt="{{tmdb_title}}" width="55" height="78"><span><strong>{{tmdb_title}}</strong> <small>(<abbr title="{{tmdb_release_date}}">{{tmdb_release_date_abbr}}</abbr>)</small></span></p>'

	// ADD MOVIE
	var taAddMovie = new Bloodhound( {
		datumTokenizer: function(d) { return Bloodhound.tokenizers.whitespace(d.tmdb_title) }
	,	queryTokenizer: Bloodhound.tokenizers.whitespace
	,	limit: 5
	,	remote: {
			url: 'http://api.themoviedb.org/3/search/movie?api_key=1c36628b5c5648a1e1079924b98c0925&search_type=ngram&query=%QUERY'
		,	rateLimitWait: 1000
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
	
	taAddMovie.initialize()
	
	$('#add_movie').typeahead(null, {
		name: 'taAddMovie'
	,	displayKey: 'tmdb_title'
	,	source: taAddMovie.ttAdapter()
	,	templates: {
			suggestion: Handlebars.compile(template)
		}
	})
	
	$('#add_movie').on('typeahead:selected', function (e, o, name) {
		$.ajax({
			type: 'POST'
		,	url: 'add_movie.php'
		,	data: { movie_list_id: currentList, tmdb_movie_id: o.tmdb_movie_id, tmdb_title: o.tmdb_title, tmdb_original_title: o.tmdb_original_title, tmdb_poster_path: o.tmdb_poster_path, tmdb_release_date: o.tmdb_release_date }
		})
		.done(function (msg) {
			//console.log(msg) // Useful for debugging
			var code = +msg
			//if (isNaN(code) === true) {
			//	$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
			//	return
			//}
			switch (code) {
				case 1:
					$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully added <abbr title="' + o.tmdb_title + ' (' + o.tmdb_release_date.substr(0, 4) + ')">movie</abbr>!</div>')
					window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 5000)
					addMovie(currentList, o.tmdb_movie_id, o.tmdb_title, o.tmdb_original_title, o.tmdb_poster_path, o.tmdb_release_date)
					db[currentListPos].display_log = 0
					displayTable()
					enableSearchCollection(template)
					break;
				case 2:
					$('#main-alerts').append('<div class="alert alert-warning"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Movie already exists in your collection!</div>')
					window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 10000)
					break;
				default:
					$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong! error[' + code + ']</div>')
			}
		})
	})
	
	// SEARCH COLLECTION FOR MOVIE
	enableSearchCollection(template)

	function statesBad (a, b) {
		if (a === b || a.length !== b.length) return true
		var ret = true
		$.each(a, function (i) {
			if (a[i] !== b[i]) {
				ret = false
				return false
			}
		})
		return ret
	}
	function getChangeRange (a, b) {
		var lo = false
		var hi
		$.each(a, function (i) {
			if (a[i] !== b[i]) {
				if (lo === false) {
					lo = i
					hi = i
				}
				else {
					hi = Math.max(hi, i)
				}
			}
		})
		return { lo: lo, hi: hi }
	}
	function getSharedListsChange (a, b) {
		var ret = []
		$.each(a, function (i) {
			if (a[i] !== b[i]) {
				ret.push(i)
			}
		})
		return ret
	}
	var start_state = false
	var stop_state = false
	$('#header-nav a').on('click', function () {
		if ($(this).attr('href').substr(1) === 'create') {
			$('#create-dialog').modal()
		}
		else if ($(this).attr('href').substr(1) === 'adjust') {
			start_state = false
			stop_state = false
			$('#adjust-dialog')
				.modal()
				.empty()
				.append('\
					<div class="modal-dialog">\
						<div class="modal-content">\
							<div class="modal-header">\
								<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>\
								<h4 class="modal-title">Adjust Lists</h4>\
							</div>\
							<div class="modal-body">' + (function () {
								var ret = '<ul class="nav nav-pills nav-stacked" id="sortit">'
								$.each(db, function (i, e) {
									//console.log(e)
									ret += '<li ' + ((e.list_id == currentList)? 'class="active" ' : '') + 'data-listid="' + e.list_id + '"><span class="glyphicon glyphicon-chevron-up sort-block up-btn"></span><span class="glyphicon glyphicon-chevron-down sort-block down-btn"></span><a class="" href="#">' + e.list_name + '</a></li>'
								})
								return ret += '</ul>'
							})() + '\
							</div>\
							<div class="modal-footer">\
								<button class="btn btn-default" type="button" data-dismiss="modal">Close</button>\
								<button class="btn btn-primary" type="button" id="save">Save</button>\
							</div>\
						</div><!-- /.modal-content -->\
					</div><!-- /.modal-dialog -->')
			start_state = $.map($.makeArray($('#adjust-dialog ul li')), function (v) { return $(v).attr('data-listid') })
			//console.log(start_state)

			$('#adjust-dialog #sortit li a').on('click', function (e) {
				e.preventDefault()
			})
			$('#adjust-dialog #sortit .up-btn').on('click', function () {
				$(this).parent().insertBefore($(this).parent().prev())
				stop_state = $.map($.makeArray($('#adjust-dialog ul li')), function (v) { return $(v).attr('data-listid') })
				//console.log(stop_state)
			})
			$('#adjust-dialog #sortit .down-btn').on('click', function () {
				$(this).parent().insertAfter($(this).parent().next())
				stop_state = $.map($.makeArray($('#adjust-dialog ul li')), function (v) { return $(v).attr('data-listid') })
				//console.log(stop_state)
			})

			$('#adjust-dialog #save').on('click', function () {
				//console.log('save')
				if (statesBad(start_state, stop_state)) {
					return false
				}
				var changeRange = getChangeRange(start_state, stop_state)
				//console.log(changeRange)
				$.ajax({
					type: 'POST'
				,	url: 'adjust_lists.php'
				,	data: { stop_state: JSON.stringify(stop_state), start_pos: changeRange.lo, stop_pos: changeRange.hi }
				})
				.done(function (msg) {
					//console.log(msg) // Useful for debugging
					if (msg.substr(0, 12) === 'greatsuccess') {
						$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully adjusted lists!</div>')
						window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 5000)
						adjustLists(stop_state)
					}
					else {
						$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong while adjusting your lists!</div>')
					}
					$('#adjust-dialog').modal('hide')
				})
			})
		}
		else if ($(this).attr('href').substr(1) === 'share') {
			start_state = false
			stop_state = false
			$('#share-dialog')
				.modal()
				.empty()
				.append('\
					<div class="modal-dialog">\
						<div class="modal-content">\
							<div class="modal-header">\
								<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>\
								<h4 class="modal-title">Share Lists</h4>\
							</div>\
							<div class="modal-body">\
								<div id="share-alerts"></div>\
								<p>Distribute this link to share your public lists:</p>\
								<div class="input-group" style="margin: 0 0 10px;">\
									<span class="input-group-btn">\
										<button id="share-copy-button" class="btn btn-primary" type="button" data-clipboard-target="share-copy-input">Copy</button>\
									</span>\
									<input id="share-copy-input" type="text" class="form-control" value="http://' + window.location.hostname + '/mcm/share.php?id=' + user_id + '" readonly="readonly" onclick="this.select()">\
								</div><!-- /input-group -->\
								<p>By default, all of your lists are private.  If you would like to publicly share your lists, check them then save below.</p>' + (function () {
								var ret = '<ul class="nav nav-pills nav-stacked" id="shareit">'
								$.each(db, function (i, e) {
									//console.log(e)
									ret += '<li ' + ((e.list_id == currentList)? 'class="active" ' : '') + 'data-listid="' + e.list_id + '"><input type="checkbox" value="1"' + ((e.share)? ' checked' : '') + '> <a class="" href="#">' + e.list_name + '</a></li>'
								})
								return ret += '</ul>'
							})() + '\
							</div>\
							<div class="modal-footer">\
								<button class="btn btn-default" type="button" data-dismiss="modal">Close</button>\
								<button class="btn btn-primary" type="button" id="save">Save</button>\
							</div>\
						</div><!-- /.modal-content -->\
					</div><!-- /.modal-dialog -->')

			ZeroClipboard.config( { moviePath: 'js/libs/ZeroClipboard.swf' } )
			var clip_client = new ZeroClipboard($('#share-copy-button'))
			clip_client.on('complete', function () {
				$('#share-alerts').html('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully copied link!</div>')
			})

			var list_input_arr = $.makeArray($('#share-dialog ul li input'))
			start_state = $.map(list_input_arr, function (v) { return ($(v).prop('checked'))? 1 : 0 })
			//console.log(start_state)
			$('#share-dialog #shareit li a').on('click', function (e) {
				e.preventDefault()
			})

			$('#share-dialog #save').on('click', function () {
				//console.log('save')
				stop_state = $.map(list_input_arr, function (v) { return ($(v).prop('checked'))? 1 : 0 })
				//console.log(stop_state)
				var gslc = getSharedListsChange(start_state, stop_state)
				//console.log(gslc)
				var list_arr = $.makeArray($('#share-dialog ul li'))
				var changed_lists = $.map(gslc, function (v) { return $(list_arr[v]).attr('data-listid') })
				//console.log(changed_lists)
				var share_vals = $.map(gslc, function (v) { return stop_state[v] })
				//console.log(share_vals)
				if (statesBad(start_state, stop_state)) {
					return false
				}
				$('#share-dialog').empty()
				$.ajax({
					type: 'POST'
				,	url: 'share_lists.php'
				,	data: { changed_lists: JSON.stringify(changed_lists), share_vals: JSON.stringify(share_vals) }
				})
				.done(function (msg) {
					//console.log(msg) // Useful for debugging
					if (msg.substr(0, 12) === 'greatsuccess') {
						$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully saved public list settings!</div>')
						window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 5000)
						$.each(stop_state, function (i, e) { db[i].share = e })
					}
					else {
						$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong while saving your lists!</div>')
					}
					$('#share-dialog').modal('hide')
				})
			})
		}
	})
	$('#create-submit').on('click', function () {
		var that = $(this)
		$('#create-alerts').empty()
		//console.log('creating')
		$(this).addClass('disabled')
		//console.log($('#create-list_name').val())
		var list_description = ''; // Haven't implemented this yet
		var create_list_name = $('#create-list_name').val()
		if (create_list_name === '') {
			$('#create-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>You must supply a valid list name.</div>')
				that.removeClass('disabled')
		}
		else {
			var that = $(this)
			$.ajax({
				type: 'POST'
			,	url: 'create_list.php'
			,	data: { list_name: create_list_name, list_description: list_description, list_rank: db.length }
			})
			.done(function (msg) {
				//console.log(msg) // Useful for debugging
				// rely on the msg to see our new list id
				if (msg.substr(0, 14) === 'movie_list_id:') {
					$('#create-alerts').html('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully created list!</div>')
					var list_id = msg.substr(14)
					createList(create_list_name, list_id, db.length)
					$('#list-control').show()
				}
				else $('#create-alerts').html('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
				that.removeClass('disabled')
				$('#create-list_name').val('')
			})
		}
	});

	// For both #add-movie and #search-collection to make hint's style similar to the existing input
	$('#list-control .tt-hint').addClass('form-control')
	
	$('#dialog').on('hidden.bs.modal', function () {
		//console.log('hidden')
		$(this).removeData('bs.modal')
		$('#dialog').find('.modal-content').empty()
	}).on('loaded.bs.modal', function() {
		//console.timeEnd('modal exec time')
		//console.log('loaded')
		// Generate Overview popover
		$('#overview').on('click', function () {
			//console.log('test')
			$('#overview-content').toggle(400)
		})
		$('#overview-content-close').on('click', function () {
			$('#overview-content').hide(400)
		})
		// Generate move-to-list dropdown options
		var movie_options_html = '<li class="alert-danger"><a href="#delete">Delete</a></li>'
		movie_options_html += '<li class="divider"></li><li class="dropdown-header">Move to...</li>'
		$.each(db, function(i, v) {
			movie_options_html += '<li'
			if (db[currentListPos].list_id === v.list_id) movie_options_html += ' class="disabled"'
			movie_options_html += '><a href="#' + v.list_id + '">' + v.list_name + '</a></li>'
		})
		$('#movie-options').append(movie_options_html)
		// Handle movie-options option click
		$('#movie-options a').on('click', function (e) {
			e.preventDefault()
			if ($(this).parent().hasClass('disabled')) return false
			var movie_id = $('#dialog #movie-id').html()
			if ($(this).attr('href').substr(1) === 'delete') {
				$('#dialog .modal-body').hide(400)
				$('#dialog .modal-body').after('<div class="alert alert-danger" style="margin:20px" id="delete-alert"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button><p>Are you <strong>sure</strong> you want to <strong>delete</strong> this movie from your "' + db[currentListPos].list_name + '" list?</p><p><button class="btn btn-danger" type="button" id="delete-yes">Yes</button> <button class="btn btn-default" type="button" id="delete-no">No, I do not want to</button></p></div>')
				$('#delete-no').on('click', function () {
					$('#dialog .modal-body').show(400)
					$('#delete-alert').remove()
				})
				$('#delete-yes').on('click', function () {
					$.ajax({
						type: 'POST'
					,	url: 'delete_movie.php'
					,	data: { movie_list_id: currentList, tmdb_movie_id: movie_id }
					})
					.done(function (msg) {
						//console.log(msg) // Useful for debugging
						if (msg.substr(0, 12) === 'greatsuccess') {
							$('#main-alerts').append('<div class="alert alert-success"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Successfully deleted movie!</div>')
							window.setTimeout(function () { $('#main-alerts div:last-child').hide(400, function () { this.remove() }) }, 5000)
							//db = JSON.parse(msg.substr(12))
							deleteMovie(currentList, movie_id)
							$('#dialog').modal('hide')
							db[currentListPos].display_log = 0
							displayTable()
							enableSearchCollection(template)
						}
						else {
							$('#main-alerts').append('<div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Something went wrong!</div>')
						}
					})
				})
				return true
			}
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
				enableSearchCollection(template)
			})
		})
	})
});
