(function ($) {
	'use strict';

	$(function () {
		var $btn = $('#heb-pp-term-distribute-btn');
		if (!$btn.length) {
			return;
		}
		var $result = $('#heb-pp-term-distribute-result');

		$btn.on('click', function (e) {
			e.preventDefault();
			var termId = $btn.data('term-id');
			var siteIds = $('.heb-pp-term-site:checked').map(function () { return this.value; }).get();
			if (siteIds.length === 0) {
				window.alert(HebPPTermHub.i18n.selectSites);
				return;
			}
			$btn.prop('disabled', true);
			$result.html('<p>' + HebPPTermHub.i18n.distributing + '</p>');

			$.ajax({
				url: HebPPTermHub.ajaxUrl,
				method: 'POST',
				data: {
					action: 'heb_pp_distribute_term',
					nonce: HebPPTermHub.nonce,
					term_id: termId,
					site_ids: siteIds
				},
				timeout: 600000 // 10 min, term 翻译比较短但保险
			}).done(function (res) {
				if (!res || !res.success) {
					$result.html('<p style="color:#a00;">' + HebPPTermHub.i18n.error + ': ' + ((res && res.data && res.data.message) || '') + '</p>');
					return;
				}
				var lines = [];
				$.each(res.data || {}, function (sid, r) {
					if (r.ok) {
						var html = '<li><strong style="color:#080;">✓</strong> ' + (r.site_label || sid);
						if (r.remote_url) {
							html += ' &mdash; <a href="' + r.remote_url + '" target="_blank" rel="noopener">' + r.remote_url + '</a>';
						}
						if (r.edit_url) {
							html += ' <a href="' + r.edit_url + '" target="_blank" rel="noopener">' + '[edit]' + '</a>';
						}
						html += '</li>';
						lines.push(html);
					} else {
						lines.push('<li><strong style="color:#a00;">✗</strong> ' + (r.site_label || sid) + ' &mdash; ' + (r.message || '') + '</li>');
					}
				});
				$result.html('<p>' + HebPPTermHub.i18n.done + '</p><ul style="margin:6px 0 0 1em;">' + lines.join('') + '</ul>');
			}).fail(function (xhr) {
				$result.html('<p style="color:#a00;">' + HebPPTermHub.i18n.error + ': ' + (xhr.statusText || '') + '</p>');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
