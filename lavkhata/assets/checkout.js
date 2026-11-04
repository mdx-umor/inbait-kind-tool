/* Lavkhata: re-check payment options when the customer enters their phone. */
(function ($) {
	'use strict';
	var last = '';
	var timer = null;
	function digits(v) { return String(v || '').replace(/[০-৯]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.indexOf(d); }).replace(/\D/g, ''); }
	function check() {
		var p = digits($('#billing_phone').val());
		if (p.length >= 11 && p !== last) {
			last = p;
			$(document.body).trigger('update_checkout');
		}
	}
	$(document.body).on('input change blur', '#billing_phone', function () {
		clearTimeout(timer);
		timer = setTimeout(check, 600);
	});
})(jQuery);
