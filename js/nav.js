$(function() {
	$('.navbar a').click(function (e) {
		if ($(this).parent().hasClass('disabled')) e.preventDefault()
	})
})
