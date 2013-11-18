// Variables to record whether or not we've loaded the table yet or not.  This is to prevent multiple loadings of each table if we keep going back and forth between tabs
var currentName = 'WTS'
var currentSort = 'name'
var currentOrder = 'asc'

// Move the movie locally so we don't have to refresh the page to see the update
function moveMovie (from_list, to_list, movie_id) {
	var flist = db[from_list]['JSON']
	var element
	for (var key in flist) {
		if (flist[key]['id'] == movie_id) {
			element = flist[key]
			delete flist[key]
			break
		}
	}
	db[to_list]['JSON'].push(element)
}

// This function is necessary because for every time we switch tables, we must enable the Tooltip and Dialog functions.
function enableFunctions () {
	//console.log('en fncs: ' + currentName)
	
	$('#' + currentName + ' img.lazy').lazyload({ threshold: 200 })
	
	$('#' + currentName + ' img')
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
			var movie_id = $(this)[0]['id']
			//console.time('modal exec time')
			$('#dialog').modal({ remote: 'dialog.php?id=' + movie_id })
			//console.timeEnd('modal exec time')
	
			/*console.time('load exec time')
			$('#dialog').load('dialog.php?id=' + movie_id, function() {
				console.timeEnd('load exec time')
				$('#dialog').modal('show')
			})*/
	
			/*console.time('ajax exec time'); // https://developer.mozilla.org/en-US/docs/Web/API/console.time?redirectlocale=en-US&redirectslug=DOM%2Fconsole.time
			$(document.body).addClass('modal-open')
			$.ajax({
				type: 'POST'
			,	url: 'dialog.php' // dialog.php is where we handle the actual HTML that shows up in the Dialog box.
			,	data: { id: movie_id }
			})
			.done(function (msg) {
				console.timeEnd('ajax exec time');
				$('#dialog').html(msg); // load movie details
				//$('#dialog').modal('show')
			});*/
		});
}

// This function actually displays the table of movies depending on which table we want to display
function displayTable () {
	//console.log('displaying table')
	
	// Sort/Order Movie Lists
	var ListItemsJSON = db[currentName]['JSON']
	if (currentSort === 'name') {
		ListItemsJSON.sort(function (a, b) { // Sorts alphabetically by title in asc order.
			var x = a.title.toLowerCase()
			var y = b.title.toLowerCase()
			if (x.substr(0, 4) === 'the ') x = x.substr(4) // Do not consider the word "the" if it's in the beginning of the title while sorting by name.
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
			if (x.substr(0, 4) === 'the ') x = x.substr(4) // Do not consider the word "the" if it's in the beginning of the title while sorting by name.
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
		console.log('unexpected sorting order in displayTable() => ' + currentSort)
		return false
	}
	if (currentOrder === 'desc') { ListItemsJSON.reverse() }
	$('#sort-order li').removeClass('alert-info disabled')
	$('#' + currentSort).parent().addClass('alert-info disabled')
	$('#' + currentOrder).parent().addClass('alert-info disabled')

	// Create the HTML we are going to use as our table of movies
	html = '<div class="posters">'
	$.each(ListItemsJSON, function () {
		if (this['poster_path'] !== null) {
			html += '<img class="lazy img-thumbnail" id="' + this['id'] + '" data-original="http://d3gtl9l2a4fn1j.cloudfront.net/t/p/w154/' + this['poster_path'] + '" alt="' + this['title'] + "\">\n"
		}
	})
	html += '</div>'
	//console.log(currentName)
	$('#' + currentName).html(html) // Set that HTML now
	
	enableFunctions() // We enable our functions now so tooltips and the dialog box work.
}

$(function () {
	// We care about hashes so our table can go to the correct tab upon refreshing or directly being linked to a particular list
	var tabNum = 1
	if (window.location.hash.substr(-4) === '-tab') {
		var tabName = window.location.hash.substr(1, window.location.hash.length - 5)
		tabNum = $.inArray(tabName, Object.keys(db))
		if (tabNum >= 0) currentName = tabName
		tabNum += (tabNum < 0) ? 2 : 1 // this offset is for the :nth-child() selector
	}
	$('#' + currentName).addClass('active')
	$('#list-tabs li:nth-child(' + tabNum + ')').addClass('active')

	//console.log('tab: ' + tab)
	$('#list-tabs').sortable({
		axis: 'x'
	,	stop: function () {
			console.log('sortable stopped')
		}
	})
	$(window).on('tabdrop.on', function () { $('#list-tabs').sortable('disable') })
	$(window).on('tabdrop.off', function () { $('#list-tabs').sortable('enable') })
	$('#list-tabs').tabdrop()
	$('#list-options a').click(function (e) {
		if ($(this).parent().hasClass('disabled')) e.preventDefault();
	})
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
		$.each(db, function () { this['dlog'] = 0 })
		db[currentName]['dlog'] = 1

		displayTable()
	})

	displayTable() // By default, display the "What to See" table
	db[currentName]['dlog'] = 1
	
	// Load our tables for their respective topics
	$('#list-tabs a').on('click', function () {
		tabNum = $.inArray($(this).attr('href').substr(1), Object.keys(db))
		if (tabNum < 0) return
		//console.log('clicked')
		//console.log(currentName)
		currentName = $(this).attr('href').substr(1)
		//console.log(currentName)
		window.location.hash = currentName + '-tab'
		if (db[currentName]['dlog'] === 0) {
			displayTable()
			db[currentName]['dlog'] = 1
		}
		$(this).tab('show')
		$(window).trigger('scroll')
	})

	$('#dialog').on('hidden.bs.modal', function () {
		//console.log('hidden')
		$(this).removeData('bs.modal')
		$('#dialog').empty()
	}).on('shown.bs.modal', function() {
		//console.timeEnd('modal exec time')
		//console.log('shown')
		// Generate Overview popover
		$('#overview').on('click', function () {
			$('#overview-content').toggle(400)
		})
		$('#overview-content-close').on('click', function () {
			$('#overview-content').hide(400)
		})
		// Generate move-to-list dropdown options
		var move_to_lists_html = ''
		$.each(db, function(i, v) {
			move_to_lists_html += '<li'
			if (db[currentName]['name'] == v['name']) move_to_lists_html += ' class="disabled"'
			move_to_lists_html += '><a href="#' + i + '">' + v['name'] + '</a></li>'
		})
		$('#move-to-lists').html(move_to_lists_html)
		// Handle move-to-list option click
		$('#move-to-lists a').on('click', function (e) {
			e.preventDefault()
			var movie_id = $('#dialog #movie-id').html()
			$('#dialog').html('')
			var thref = $(this).attr('href').substr(1)
			//console.log(thref)
			var tlist = db[thref]['id']
			//console.log(tlist)
			$.ajax({
				type: 'POST'
			,	url: 'move.php' // move.php is where we handle the actual movement of movie between TMDb lists
			,	data: { from_list : db[currentName]['id'], to_list : tlist, movie_id: movie_id }
			})
			.done(function (msg) {
				//console.log(msg) // Useful for debugging move.php
				moveMovie(currentName, thref, movie_id)
				$('#dialog').modal('hide')
				displayTable()
				db[currentName]['dlog'] = 0
				db[thref]['dlog'] = 0
			})
		})
	})
});
