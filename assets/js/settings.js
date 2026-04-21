/* global jQuery, HebPPSettings */
(function ($) {
	'use strict';

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function randomSecret(len) {
		var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		var out = '';
		var arr = new Uint32Array(len);
		(window.crypto || window.msCrypto).getRandomValues(arr);
		for (var i = 0; i < len; i++) out += alphabet.charAt(arr[i] % alphabet.length);
		return out;
	}

	$(function () {
		$('#heb-pp-gen-secret').on('click', function () {
			$('#heb_publisher_receiver_secret').val(randomSecret(48)).trigger('change');
		});

		$('#heb-pp-add-site').on('click', function () {
			var tpl = document.getElementById('heb-pp-site-row-tpl');
			if (!tpl) return;
			var idx = $('#heb-pp-sites-body .heb-pp-site-row').length;
			var html = tpl.innerHTML.replace(/__INDEX__/g, idx);
			$('#heb-pp-sites-body').append(html);
		});

		$(document).on('click', '.heb-pp-remove-site', function () {
			var $row = $(this).closest('.heb-pp-site-row');
			if ($('#heb-pp-sites-body .heb-pp-site-row').length > 1) {
				$row.remove();
			} else {
				$row.find('input').val('');
			}
		});

		$('#heb-pp-test-sites').on('click', function () {
			var $btn = $(this);
			var $out = $('#heb-pp-test-result').empty();
			var rows = $('#heb-pp-sites-body .heb-pp-site-row');
			if (!rows.length) return;

			$btn.prop('disabled', true);
			var pending = 0;

			rows.each(function () {
				var $row = $(this);
				var sid = $row.find('input[name$="[id]"]').val();
				var label = $row.find('input[name$="[label]"]').val() || $row.find('input[name$="[url]"]').val();
				if (!sid) {
					$out.append('<div class="heb-pp-test-result-item err"><strong>' + escapeHtml(label) + '</strong> — ' + '请先保存设置后再测试。' + '</div>');
					return;
				}
				pending++;
				$.post(HebPPSettings.ajaxUrl, {
					action: 'heb_pp_test_site',
					nonce: HebPPSettings.nonce,
					site_id: sid
				}).done(function (resp) {
					var cls, msg;
					if (resp && resp.success) {
						cls = 'ok';
						msg = 'locale=<code>' + escapeHtml(resp.data.locale) + '</code>';
						if (resp.data.taxonomy_keys && resp.data.taxonomy_keys.length) {
							msg += ' · taxonomies: ' + escapeHtml(resp.data.taxonomy_keys.join(', '));
						}
					} else {
						cls = 'err';
						msg = escapeHtml((resp && resp.data && resp.data.message) || 'Error');
					}
					$out.append('<div class="heb-pp-test-result-item ' + cls + '"><strong>' + escapeHtml(label) + '</strong> — ' + msg + '</div>');
				}).fail(function (xhr) {
					$out.append('<div class="heb-pp-test-result-item err"><strong>' + escapeHtml(label) + '</strong> — HTTP ' + xhr.status + '</div>');
				}).always(function () {
					pending--;
					if (pending <= 0) $btn.prop('disabled', false);
				});
			});
			if (pending === 0) $btn.prop('disabled', false);
		});
	});
})(jQuery);
