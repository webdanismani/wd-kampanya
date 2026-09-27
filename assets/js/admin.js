/* WD Kampanya – yönetim paneli */
(function ($) {
	'use strict';
	$(function () {
		// Tipe göre alanları göster/gizle.
		var $form = $('.wdkm-form');
		function sync() {
			var t = $form.find('input[name=type]:checked').val();
			$form.attr('data-type', t);
			$form.find('.wdkm-if').each(function () {
				var list = String($(this).data('types')).split(' ');
				$(this).toggle(list.indexOf(t) > -1);
			});
			$form.find('.wdkm-type').removeClass('is-on');
			$form.find('input[name=type]:checked').closest('.wdkm-type').addClass('is-on');
		}
		if ($form.length) {
			$form.on('change', 'input[name=type]', sync);
			sync();
		}

		// Kademeler.
		var $tb = $('.wdkm-tiers tbody');
		var next = $tb.find('tr.wdkm-tier').length + 100;
		$('.wdkm-tier-add').on('click', function () {
			var $tpl = $tb.find('tr.wdkm-tier-tpl');
			var $row = $tpl.clone().removeClass('wdkm-tier-tpl').addClass('wdkm-tier').prop('hidden', false);
			$row.find('[name]').each(function () {
				this.name = this.name.replace('__i__', String(next));
				this.disabled = false;
			});
			next++;
			$row.insertBefore($tpl);
			$row.find('input').first().trigger('focus');
		});
		$tb.on('click', '.wdkm-tier-del', function () { $(this).closest('tr').remove(); });

		// Onay isteyen bağlantılar.
		$(document).on('click', 'a[data-confirm]', function (e) {
			if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
		});

		// Simülatör hazır dönemler.
		$('.wdkm-presets [data-days]').on('click', function () {
			var d = parseInt($(this).data('days'), 10);
			var to = new Date(), from = new Date();
			from.setDate(to.getDate() - d);
			var f = function (x) { return x.toISOString().slice(0, 10); };
			$('input[name=from]').val(f(from));
			$('input[name=to]').val(f(to));
		});
	});
})(jQuery);
