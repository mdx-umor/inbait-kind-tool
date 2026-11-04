/* Lavkhata admin */
(function ($) {
	'use strict';
	if (!window.lvkAdmin) { return; }

	// Check a customer's delivery record from the orders list.
	$(document).on('click', '.lvk-check, .lvk-recheck', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var btn = $(this);
		var cell = btn.closest('.lvk-rec');
		btn.prop('disabled', true);
		var old = cell.html();
		cell.html('<span class="lvk-badge">' + lvkAdmin.loading + '</span>');
		$.post(lvkAdmin.ajax, { action: 'lvk_check_record', order: btn.data('order'), _ajax_nonce: lvkAdmin.nonce })
			.done(function (r) {
				if (r && r.success) { cell.replaceWith(r.data.html); return; }
				cell.html(old).append(' <span class="lvk-muted">' + lvkAdmin.failed + '</span>');
			})
			.fail(function () { cell.html(old); });
	});

	// Clicks inside our columns should not open the order.
	$(document).on('click', '.column-lvk_courier a, .column-lvk_confirm a', function (e) { e.stopPropagation(); });

	// Steadfast balance on the overview.
	var bal = $('.lvk-card.lvk-balance .lvk-card-value');
	if (bal.length) {
		$.post(lvkAdmin.ajax, { action: 'lvk_balance', _ajax_nonce: lvkAdmin.nonce }).done(function (r) {
			bal.text(r && r.success ? r.data.steadfast : '—');
		}).fail(function () { bal.text('—'); });
	}
})(jQuery);
