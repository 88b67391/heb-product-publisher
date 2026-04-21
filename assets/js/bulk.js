(function ($) {
	'use strict';

	if (typeof HebPPBulk === 'undefined') {
		return;
	}

	var $start = $('#heb-pp-bulk-start');
	var $bar = $('#heb-pp-bulk-bar');
	var $summary = $('#heb-pp-bulk-summary');
	var $table = $('.heb-pp-bulk-table');

	var postIds = (HebPPBulk.postIds || []).slice();
	var total = postIds.length;
	var processed = 0;
	var okCount = 0;
	var errCount = 0;

	function setRowStatus($row, cls, label) {
		$row.find('.heb-pp-bulk-status').html(
			$('<span>', {
				class: 'heb-pp-bulk-badge heb-pp-bulk-badge--' + cls,
				text: label
			})
		);
	}

	function renderSiteResults($row, results) {
		var $cell = $row.find('.heb-pp-bulk-sites').empty();
		$.each(results, function (sid, r) {
			var ok = r && r.ok;
			var label = r && r.label ? r.label : sid;
			var $pill = $('<span>', {
				class: 'heb-pp-site-pill heb-pp-site-pill--' + (ok ? 'ok' : 'err'),
				text: (ok ? '✓ ' : '✕ ') + sid
			});
			if (ok && r.edit_url) {
				$pill = $('<a>', {
					href: r.edit_url,
					target: '_blank',
					rel: 'noopener',
					class: 'heb-pp-site-pill heb-pp-site-pill--ok',
					text: '✓ ' + sid + ' #' + (r.post_id || '')
				});
			} else if (!ok && r.message) {
				$pill.attr('title', r.message);
			}
			$cell.append($pill).append(' ');
		});
	}

	function updateProgress() {
		var pct = total ? Math.round((processed / total) * 100) : 0;
		$bar.css('width', pct + '%');
		$summary.text(
			processed + ' / ' + total + ' · ' + HebPPBulk.i18n.success + ': ' + okCount + ' · ' + HebPPBulk.i18n.failed + ': ' + errCount
		);
	}

	function processNext(siteIds) {
		if (!postIds.length) {
			$start.prop('disabled', false).text(HebPPBulk.i18n.done);
			return;
		}
		var pid = postIds.shift();
		var $row = $table.find('tr[data-post-id="' + pid + '"]');
		setRowStatus($row, 'processing', HebPPBulk.i18n.processing);

		$.post(HebPPBulk.ajaxUrl, {
			action: 'heb_pp_bulk_one',
			nonce: HebPPBulk.nonce,
			post_id: pid,
			site_ids: siteIds
		})
			.done(function (resp) {
				if (resp && resp.success) {
					renderSiteResults($row, resp.data.results);
					var anyErr = false;
					$.each(resp.data.results, function (sid, r) {
						if (!r.ok) {
							anyErr = true;
						}
					});
					if (anyErr) {
						setRowStatus($row, 'warn', HebPPBulk.i18n.failed);
						errCount++;
					} else {
						setRowStatus($row, 'ok', HebPPBulk.i18n.success);
						okCount++;
					}
				} else {
					setRowStatus($row, 'err', HebPPBulk.i18n.failed);
					$row.find('.heb-pp-bulk-sites').text(
						resp && resp.data && resp.data.message ? resp.data.message : 'unknown error'
					);
					errCount++;
				}
			})
			.fail(function (xhr) {
				setRowStatus($row, 'err', HebPPBulk.i18n.failed);
				$row.find('.heb-pp-bulk-sites').text('HTTP ' + xhr.status);
				errCount++;
			})
			.always(function () {
				processed++;
				updateProgress();
				setTimeout(function () {
					processNext(siteIds);
				}, 200);
			});
	}

	$start.on('click', function () {
		var siteIds = $('.heb-pp-bulk-site-input:checked')
			.map(function () {
				return this.value;
			})
			.get();
		if (!siteIds.length) {
			alert(HebPPBulk.i18n.needSites);
			return;
		}
		$start.prop('disabled', true).text(HebPPBulk.i18n.running);
		processed = 0;
		okCount = 0;
		errCount = 0;
		updateProgress();
		processNext(siteIds);
	});
})(jQuery);
