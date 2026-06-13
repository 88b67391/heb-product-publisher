(function ($) {
	'use strict';

	$(function () {
		if (typeof HebPPDashboard === 'undefined') {
			// eslint-disable-next-line no-console
			console.error('HEB Dashboard: HebPPDashboard config missing — script was not localized.');
			return;
		}

		var manifests = {};

		function siteIdFrom($el) {
			return String($el.attr('data-site-id') || '');
		}

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
			return {
				posts: posts,
				terms: terms,
				host: data.host || '',
				error: data.error || '',
				cached: !!data.cached
			};
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
				var siteId = siteIdFrom($cell);
				if (!siteId) { return; }
				var manifest = manifests[siteId];
				if (!manifest || manifest.error) {
					siteIds.push(siteId);
					return;
				}
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
					var siteId = siteIdFrom($cell);
					var manifest = siteId ? manifests[siteId] : null;
					var $span = $cell.find('.heb-pp-dash-status');

					if (!manifest) {
						$span.text('·').css('color', '#bbb').attr('title', 'no manifest yet').removeAttr('data-code');
						return;
					}

					if (manifest.error) {
						$span
							.text('⚠')
							.css('color', '#c00')
							.attr('title', HebPPDashboard.i18n.noManifest + ': ' + manifest.error)
							.attr('data-code', 'manifest_error');
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

		function setStatusMessage(text, title) {
			var $status = $('#heb-pp-dash-status');
			$status.text(text || '');
			if (title) {
				$status.attr('title', title);
			} else {
				$status.removeAttr('title');
			}
		}

		function loadSiteManifest(siteId, force) {
			if (!siteId) {
				return $.Deferred().reject().promise();
			}
			return $.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_manifest',
				nonce: HebPPDashboard.nonce,
				site_id: siteId,
				force: force ? 1 : 0
			}).done(function (res) {
				if (res && res.success) {
					manifests[siteId] = indexManifest(res.data);
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'error';
					manifests[siteId] = { posts: {}, terms: {}, error: msg };
				}
				applyCells();
			}).fail(function (xhr) {
				var msg = 'fetch failed';
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				manifests[siteId] = { posts: {}, terms: {}, error: msg };
				applyCells();
			});
		}

		function collectSiteIds() {
			var siteIds = [];
			$('.heb-pp-dash-cell').each(function () {
				var id = siteIdFrom($(this));
				if (id && siteIds.indexOf(id) < 0) {
					siteIds.push(id);
				}
			});
			return siteIds;
		}

		function loadAllManifests(force, $triggerBtn, triggerOldText) {
			var siteIds = collectSiteIds();
			if (!siteIds.length) {
				setStatusMessage(HebPPDashboard.i18n.noSiteColumns);
				return;
			}

			var $refresh = $('#heb-pp-dash-refresh');
			var $clear = $('#heb-pp-dash-clear-cache');
			$refresh.prop('disabled', true);
			$clear.prop('disabled', true);

			setStatusMessage(HebPPDashboard.i18n.loading);
			var promises = siteIds.map(function (id) { return loadSiteManifest(id, force); });
			$.when.apply($, promises).always(function () {
				$refresh.prop('disabled', false);
				$clear.prop('disabled', false);
				if ($triggerBtn && $triggerBtn.length && triggerOldText) {
					$triggerBtn.text(triggerOldText);
				}

				var errors = [];
				siteIds.forEach(function (id) {
					if (manifests[id] && manifests[id].error) {
						errors.push(id + ': ' + manifests[id].error);
					}
				});
				if (errors.length) {
					setStatusMessage(HebPPDashboard.i18n.manifestErrors, errors.join('\n'));
				} else {
					setStatusMessage('');
				}
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
				data.site_ids = siteIds;
			}
			$.post(HebPPDashboard.ajaxUrl, data).done(function (res) {
				if (res && res.success) {
					$btn.text(HebPPDashboard.i18n.sentDone).css('color', '#080');
					loadAllManifests(true);
					setTimeout(function () { $btn.text(oldText).css('color', ''); }, 2000);
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : HebPPDashboard.i18n.sentFailed;
					$btn.text(HebPPDashboard.i18n.sentFailed).css('color', '#a00').attr('title', msg);
					setTimeout(function () { $btn.text(oldText).css('color', '').removeAttr('title'); }, 3000);
				}
			}).fail(function (xhr) {
				var msg = HebPPDashboard.i18n.sentFailed;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				$btn.text(HebPPDashboard.i18n.sentFailed).css('color', '#a00').attr('title', msg);
				setTimeout(function () { $btn.text(oldText).css('color', '').removeAttr('title'); }, 3000);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}

		function bulkResend() {
			var items = [];
			$('.heb-pp-dash-row-check:checked').each(function () {
				var $row = $(this).closest('.heb-pp-dash-row');
				var siteIds = resendTargetsForRow($row);
				if (!siteIds.length) {
					siteIds = [];
					$row.find('.heb-pp-dash-cell').each(function () {
						var id = siteIdFrom($(this));
						if (id) { siteIds.push(id); }
					});
				}
				if (!siteIds.length) { return; }
				items.push({
					source_id: parseInt($row.data('source-id'), 10),
					kind: $row.data('kind'),
					site_ids: siteIds
				});
			});
			if (items.length === 0) {
				setStatusMessage(HebPPDashboard.i18n.selectRows);
				return;
			}
			if (!window.confirm(HebPPDashboard.i18n.confirmBulk + '\n\n' + items.length + ' item(s).')) { return; }

			var $btn = $('#heb-pp-dash-bulk-resend');
			$btn.prop('disabled', true);
			setStatusMessage(HebPPDashboard.i18n.resending);
			$.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_bulk_resend',
				nonce: HebPPDashboard.nonce,
				items: items
			}).done(function (res) {
				if (res && res.success) {
					setStatusMessage(HebPPDashboard.i18n.queued + ': ' + (res.data.queued || 0));
					loadAllManifests(true);
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : HebPPDashboard.i18n.sentFailed;
					setStatusMessage(msg);
				}
			}).fail(function (xhr) {
				var msg = HebPPDashboard.i18n.sentFailed;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				setStatusMessage(msg);
			}).always(function () {
				$btn.prop('disabled', $('.heb-pp-dash-row-check:checked').length === 0);
			});
		}

		function clearCache() {
			var $btn = $('#heb-pp-dash-clear-cache');
			var oldText = $btn.text();
			$btn.prop('disabled', true).text(HebPPDashboard.i18n.clearing);
			setStatusMessage(HebPPDashboard.i18n.clearing);
			$.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_clear_cache',
				nonce: HebPPDashboard.nonce
			}).done(function () {
				loadAllManifests(true, $btn, oldText);
			}).fail(function () {
				setStatusMessage(HebPPDashboard.i18n.sentFailed);
				$btn.prop('disabled', false).text(oldText);
			});
		}

		$('#heb-pp-dash-refresh').on('click', function () {
			var $btn = $(this);
			var oldText = $btn.text();
			$btn.prop('disabled', true).text(HebPPDashboard.i18n.refreshing);
			loadAllManifests(true, $btn, oldText);
		});
		$('#heb-pp-dash-clear-cache').on('click', clearCache);
		$('#heb-pp-dash-bulk-resend').on('click', bulkResend);
		$(document).on('click', '.heb-pp-dash-resend', function () {
			var $btn = $(this);
			var $row = $btn.closest('.heb-pp-dash-row');
			var siteIds = resendTargetsForRow($row);
			if (!siteIds.length) {
				siteIds = [];
				$row.find('.heb-pp-dash-cell').each(function () {
					var id = siteIdFrom($(this));
					if (id) { siteIds.push(id); }
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
