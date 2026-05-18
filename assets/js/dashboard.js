(function ($) {
	'use strict';

	$(function () {
		var manifests = {}; // siteId → { posts: { sourceId+type: row }, terms: { sourceId+tax: row } }

		// Build map by source_post_id and post_type (or source_term_id + taxonomy).
		function indexManifest(data) {
			var posts = {};
			var terms = {};
			(data.posts || []).forEach(function (p) {
				var key = p.post_type + ':' + p.source_post_id;
				posts[key] = p;
			});
			(data.terms || []).forEach(function (t) {
				var key = t.taxonomy + ':' + t.source_term_id;
				terms[key] = t;
			});
			return { posts: posts, terms: terms, host: data.host };
		}

		function classifyPost(remote, localModified) {
			if (!remote) { return { code: 'not_sent', icon: '—', label: 'not distributed', color: '#999' }; }
			if (remote.locked) { return { code: 'locked', icon: '🔒', label: 'locked locally', color: '#b80' }; }
			if (remote.modified < localModified) { return { code: 'outdated', icon: '⊘', label: 'outdated', color: '#d94' }; }
			return { code: 'synced', icon: '✓', label: 'synced', color: '#080' };
		}

		function classifyTerm(remote) {
			if (!remote) { return { code: 'not_sent', icon: '—', label: 'not distributed', color: '#999' }; }
			return { code: 'synced', icon: '✓', label: 'synced', color: '#080' };
		}

		function applyCells() {
			$('.heb-pp-dash-row').each(function () {
				var $row = $(this);
				var kind = $row.data('kind');
				var type = $row.data('type');
				var sourceId = parseInt($row.data('source-id'), 10);
				var localModified = parseInt($row.data('modified'), 10);

				$row.find('.heb-pp-dash-cell').each(function () {
					var $cell = $(this);
					var siteId = $cell.data('site-id');
					var manifest = manifests[siteId];
					var $span = $cell.find('.heb-pp-dash-status');

					if (!manifest) {
						$span.text('·').css('color', '#bbb').attr('title', 'no manifest yet');
						return;
					}

					var key = (kind === 'terms' ? (type + ':' + sourceId) : (type + ':' + sourceId));
					var remote = (kind === 'terms' ? manifest.terms[key] : manifest.posts[key]);
					var c = (kind === 'terms') ? classifyTerm(remote) : classifyPost(remote, localModified);

					var inner = c.icon;
					if (remote && remote.edit_url) {
						inner = '<a href="' + remote.edit_url + '" target="_blank" rel="noopener" title="' + (remote.permalink || '') + '" style="color:' + c.color + ';text-decoration:none;">' + c.icon + '</a>';
					}
					$span
						.html(inner)
						.attr('title', c.label + (remote && remote.modified ? ' · remote modified=' + new Date(remote.modified * 1000).toLocaleString() : ''))
						.css('color', c.color)
						.attr('data-code', c.code);
				});
			});
		}

		function loadSiteManifest(siteId, force) {
			return $.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_manifest',
				nonce: HebPPDashboard.nonce,
				site_id: siteId,
				force: force ? 1 : 0
			}).done(function (res) {
				if (res && res.success) {
					manifests[siteId] = indexManifest(res.data);
				} else {
					manifests[siteId] = { posts: {}, terms: {}, error: (res && res.data && res.data.message) || 'error' };
				}
				applyCells();
			}).fail(function () {
				manifests[siteId] = { posts: {}, terms: {}, error: 'fetch failed' };
				applyCells();
			});
		}

		function loadAllManifests(force) {
			var siteIds = [];
			$('.heb-pp-dash-cell').each(function () {
				var id = $(this).data('site-id');
				if (siteIds.indexOf(id) < 0) { siteIds.push(id); }
			});
			$('#heb-pp-dash-status').text(HebPPDashboard.i18n.loading);
			var promises = siteIds.map(function (id) { return loadSiteManifest(id, force); });
			$.when.apply($, promises).always(function () {
				$('#heb-pp-dash-status').text('');
			});
		}

		function resend(sourceId, kind, $btn) {
			$btn.prop('disabled', true);
			var oldText = $btn.text();
			$btn.text(HebPPDashboard.i18n.resending);
			$.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_resend',
				nonce: HebPPDashboard.nonce,
				source_id: sourceId,
				kind: kind
			}).done(function (res) {
				if (res && res.success) {
					$btn.text(HebPPDashboard.i18n.sentDone).css('color', '#080');
					loadAllManifests(true);
					setTimeout(function () { $btn.text(oldText).css('color', ''); }, 2000);
				} else {
					$btn.text(HebPPDashboard.i18n.sentFailed).css('color', '#a00');
					setTimeout(function () { $btn.text(oldText).css('color', ''); }, 3000);
				}
			}).fail(function () {
				$btn.text(HebPPDashboard.i18n.sentFailed).css('color', '#a00');
				setTimeout(function () { $btn.text(oldText).css('color', ''); }, 3000);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}

		function bulkResend() {
			var items = [];
			$('.heb-pp-dash-row-check:checked').each(function () {
				var $row = $(this).closest('.heb-pp-dash-row');
				items.push({
					source_id: parseInt($row.data('source-id'), 10),
					kind: $row.data('kind')
				});
			});
			if (items.length === 0) { return; }
			if (!window.confirm(HebPPDashboard.i18n.confirmBulk + '\n\n' + items.length + ' item(s).')) { return; }
			$.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_bulk_resend',
				nonce: HebPPDashboard.nonce,
				items: items
			}).done(function (res) {
				if (res && res.success) {
					$('#heb-pp-dash-status').text('Queued: ' + (res.data.queued || 0));
				}
			});
		}

		// Wire up.
		$('#heb-pp-dash-refresh').on('click', function () { loadAllManifests(true); });
		$('#heb-pp-dash-bulk-resend').on('click', bulkResend);
		$(document).on('click', '.heb-pp-dash-resend', function () {
			var $btn = $(this);
			resend(parseInt($btn.data('source-id'), 10), $btn.data('kind'), $btn);
		});
		$(document).on('change', '.heb-pp-dash-select-all', function () {
			var checked = this.checked;
			$(this).closest('table').find('.heb-pp-dash-row-check').prop('checked', checked).trigger('change');
		});
		$(document).on('change', '.heb-pp-dash-row-check', function () {
			$('#heb-pp-dash-bulk-resend').prop('disabled', $('.heb-pp-dash-row-check:checked').length === 0);
		});

		loadAllManifests(false);
	});
})(jQuery);
