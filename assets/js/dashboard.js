(function ($) {
	'use strict';

	$(function () {
		var manifests = {};

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
			if (remote.media_pending > 0) {
				return { code: 'media_pending', icon: '⏳', label: 'synced, media pending (' + remote.media_pending + ')', color: '#08a' };
			}
			if (remote.modified < localModified) { return { code: 'outdated', icon: '⊘', label: 'outdated', color: '#d94' }; }
			return { code: 'synced', icon: '✓', label: 'synced', color: '#080' };
		}

		function classifyTerm(remote, localHash) {
			if (!remote) { return { code: 'not_sent', icon: '—', label: 'not distributed', color: '#999' }; }
			if (localHash && remote.sync_hash && remote.sync_hash !== localHash) {
				return { code: 'outdated', icon: '⊘', label: 'outdated (name/slug changed)', color: '#d94' };
			}
			return { code: 'synced', icon: '✓', label: 'synced', color: '#080' };
		}

		function resendTargetsForRow($row) {
			var kind = $row.data('kind');
			var type = $row.data('type');
			var sourceId = parseInt($row.data('source-id'), 10);
			var localModified = parseInt($row.data('modified'), 10);
			var localHash = $row.data('sync-hash') || '';
			var siteIds = [];

			$row.find('.heb-pp-dash-cell').each(function () {
				var $cell = $(this);
				var siteId = $cell.data('site-id');
				var manifest = manifests[siteId];
				if (!manifest) { return; }
				var key = type + ':' + sourceId;
				var remote = (kind === 'terms') ? manifest.terms[key] : manifest.posts[key];
				var c = (kind === 'terms') ? classifyTerm(remote, localHash) : classifyPost(remote, localModified);
				if (c.code === 'not_sent' || c.code === 'outdated' || c.code === 'locked' || c.code === 'media_pending') {
					siteIds.push(siteId);
				}
			});
			return siteIds;
		}

		function applyCells() {
			$('.heb-pp-dash-row').each(function () {
				var $row = $(this);
				var kind = $row.data('kind');
				var type = $row.data('type');
				var sourceId = parseInt($row.data('source-id'), 10);
				var localModified = parseInt($row.data('modified'), 10);
				var localHash = $row.data('sync-hash') || '';

				$row.find('.heb-pp-dash-cell').each(function () {
					var $cell = $(this);
					var siteId = $cell.data('site-id');
					var manifest = manifests[siteId];
					var $span = $cell.find('.heb-pp-dash-status');

					if (!manifest) {
						$span.text('·').css('color', '#bbb').attr('title', 'no manifest yet');
						return;
					}

					var key = type + ':' + sourceId;
					var remote = (kind === 'terms') ? manifest.terms[key] : manifest.posts[key];
					var c = (kind === 'terms') ? classifyTerm(remote, localHash) : classifyPost(remote, localModified);

					var inner = c.icon;
					if (remote && remote.edit_url) {
						inner = '<a href="' + remote.edit_url + '" target="_blank" rel="noopener" title="' + (remote.permalink || '') + '" style="color:' + c.color + ';text-decoration:none;">' + c.icon + '</a>';
					}
					var title = c.label;
					if (remote && remote.modified) {
						title += ' · remote modified=' + new Date(remote.modified * 1000).toLocaleString();
					}
					if (remote && remote.media_pending > 0) {
						title += ' · pending media=' + remote.media_pending;
					}
					$span
						.html(inner)
						.attr('title', title)
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

		function resend(sourceId, kind, siteIds, $btn) {
			$btn.prop('disabled', true);
			var oldText = $btn.text();
			$btn.text(HebPPDashboard.i18n.resending);
			var data = {
				action: 'heb_pp_dash_resend',
				nonce: HebPPDashboard.nonce,
				source_id: sourceId,
				kind: kind
			};
			if (siteIds && siteIds.length) {
				data['site_ids[]'] = siteIds;
			}
			$.post(HebPPDashboard.ajaxUrl, data).done(function (res) {
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
				var siteIds = resendTargetsForRow($row);
				if (!siteIds.length) { return; }
				items.push({
					source_id: parseInt($row.data('source-id'), 10),
					kind: $row.data('kind'),
					site_ids: siteIds
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

		function clearCache() {
			$.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_clear_cache',
				nonce: HebPPDashboard.nonce
			}).done(function () {
				loadAllManifests(true);
			});
		}

		$('#heb-pp-dash-refresh').on('click', function () { loadAllManifests(true); });
		$('#heb-pp-dash-clear-cache').on('click', clearCache);
		$('#heb-pp-dash-bulk-resend').on('click', bulkResend);
		$(document).on('click', '.heb-pp-dash-resend', function () {
			var $btn = $(this);
			var $row = $btn.closest('.heb-pp-dash-row');
			var siteIds = resendTargetsForRow($row);
			if (!siteIds.length) {
				siteIds = [];
				$row.find('.heb-pp-dash-cell').each(function () {
					siteIds.push($(this).data('site-id'));
				});
			}
			resend(parseInt($btn.data('source-id'), 10), $btn.data('kind'), siteIds, $btn);
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
