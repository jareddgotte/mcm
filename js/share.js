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

function enableLists () {
	// Prevent all hash links from changing the hash in the location bar
	$('a').on('click', function (e) {
		if ($(this).attr('href').substr(0, 1) === '#') e.preventDefault()
	})
	
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
	
	if (db.length > 0) {
		if (db[currentListPos].movie_details.length == 0) {
			$('#main-alerts').append('<div class="alert alert-info"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>Your list is currently empty.  Add a movie by typing a movie\'s title where you see "Add a Movie" below.</div>')
		}
	}

	//console.log('tab: ' + tab)
	$('#list-tabs').tabdrop()
	
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
		$(window).trigger('scroll')
	})
	
	if (db.length > 0) {
		displayTable() // By default, display the "What to See" table
		db[currentListPos].display_log = 1
	} else {
		$('#main-alerts').append('<div class="alert alert-danger">This user has no public lists to showcase.</div>')
	}
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

	// SEARCH COLLECTION FOR MOVIE
	var template = '<p><img class="img-thumbnail {{classed}}" src="' + base_url + 'w45{{tmdb_poster_path}}" alt="{{tmdb_title}}" width="55" height="78"><span><strong>{{tmdb_title}}</strong> <small>(<abbr title="{{tmdb_release_date}}">{{tmdb_release_date_abbr}}</abbr>)</small></span></p>'
	if (db.length > 0) {
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
	}
	$('#search_collection').on('typeahead:selected', function (e, o, name) {
		$('#dialog').modal({ remote: 'dialog.php?id=' + o.tmdb_movie_id })
	})
	
	// Remove anything that's in the 'add a movie' / 'search my collection' inputs after clicking off of them.
	$('#search_collection').on('blur', function () {
		$(this).val('')
		$('.tt-hint').val('')
	})
	
	// For #search-collection to make hint's style similar to the existing input
	$('#list-control .tt-hint').addClass('form-control')
	
	$('#dialog').on('hidden.bs.modal', function () {
		//console.log('hidden')
		$(this).removeData('bs.modal')
		$('#dialog').empty()
	}).on('shown.bs.modal', function() {
		$('#movie-options').parent().hide()
		//console.timeEnd('modal exec time')
		//console.log('shown')
		// Generate Overview popover
		$('#overview').on('click', function () {
			//console.log('test')
			$('#overview-content').toggle(400)
		})
		$('#overview-content-close').on('click', function () {
			$('#overview-content').hide(400)
		})
	})
});
