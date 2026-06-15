(function ($) {
	'use strict';

	$(function () {
		if (typeof HebPPDashboard === 'undefined') {
			// eslint-disable-next-line no-console
			console.error('HEB Dashboard: HebPPDashboard config missing — script was not localized.');
			return;
		}

		var manifests = {};
		var siteLabels = {};
		var resendBusy = false;

		(HebPPDashboard.sites || []).forEach(function (s) {
			siteLabels[s.id] = s.label || s.id;
		});

		function siteIdFrom($el) {
			return String($el.attr('data-site-id') || '');
		}

		function siteLabel(siteId) {
			return siteLabels[siteId] || siteId;
		}

		function rowTitle($row) {
			return String($row.data('title') || $row.find('td:nth-child(2) strong').text() || '#' + $row.data('source-id'));
		}

		function nowTime() {
			return new Date().toLocaleTimeString();
		}

		function appendLog(level, message) {
			var $log = $('#heb-pp-dash-log');
			if (!$log.length) { return; }
			var cls = 'heb-pp-dash-log-line heb-pp-dash-log-' + level;
			$log.prepend($('<div/>', { 'class': cls }).html(
				'<span class="heb-pp-dash-log-time">' + nowTime() + '</span> ' + message
			));
			while ($log.children().length > 200) {
				$log.children().last().remove();
			}
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

		function setCellBusy($cell, busy) {
			$cell.toggleClass('heb-pp-dash-cell-busy', !!busy);
			$cell.find('.heb-pp-dash-cell-resend').prop('disabled', !!busy || resendBusy);
		}

		function setCellResending($cell) {
			var $span = $cell.find('.heb-pp-dash-status');
			$span.text('…').css('color', '#2271b1').attr('title', HebPPDashboard.i18n.resending).attr('data-code', 'resending');
			setCellBusy($cell, true);
		}

		function formatResultMessage(result) {
			if (!result) { return HebPPDashboard.i18n.sentFailed; }
			var parts = [];
			if (result.message) { parts.push(result.message); }
			if (result.warn && result.warn.length) {
				parts.push(result.warn.join('; '));
			}
			if (result.locked) {
				parts.push('locked');
			}
			return parts.join(' · ') || (result.ok ? HebPPDashboard.i18n.logOk : HebPPDashboard.i18n.logFail);
		}

		function applyCells() {
			$('.heb-pp-dash-row').each(function () {
				var $row = $(this);
				if ($row.hasClass('heb-pp-dash-row-busy')) { return; }
				var kind = $row.data('kind');
				var type = $row.data('type');
				var sourceId = parseInt($row.data('source-id'), 10);
				var localModified = parseInt($row.data('modified'), 10);
				var localHash = $row.data('sync-hash') || '';

				$row.find('.heb-pp-dash-cell').each(function () {
					var $cell = $(this);
					if ($cell.hasClass('heb-pp-dash-cell-busy')) { return; }
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

		function setGlobalBusy(busy) {
			resendBusy = !!busy;
			$('.heb-pp-dash-resend, .heb-pp-dash-cell-resend, #heb-pp-dash-bulk-resend').prop('disabled', busy);
			if (!busy) {
				$('#heb-pp-dash-bulk-resend').prop('disabled', $('.heb-pp-dash-row-check:checked').length === 0);
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
						errors.push(siteLabel(id) + ': ' + manifests[id].error);
					}
				});
				if (errors.length) {
					setStatusMessage(HebPPDashboard.i18n.manifestErrors, errors.join('\n'));
				} else {
					setStatusMessage('');
				}
			});
		}

		function resendOneSite($row, siteId) {
			var sourceId = parseInt($row.data('source-id'), 10);
			var kind = $row.data('kind');
			var title = rowTitle($row);
			var $cell = $row.find('.heb-pp-dash-cell[data-site-id="' + siteId + '"]');
			var label = siteLabel(siteId);

			setCellResending($cell);
			appendLog('info', '<strong>' + HebPPDashboard.i18n.logStart + '</strong> ' +
				$('<span/>').text(title).html() + ' → <code>' + label + '</code>');

			return $.post(HebPPDashboard.ajaxUrl, {
				action: 'heb_pp_dash_resend',
				nonce: HebPPDashboard.nonce,
				source_id: sourceId,
				kind: kind,
				site_ids: [siteId]
			}).then(function (res) {
				setCellBusy($cell, false);
				if (res && res.success && res.data && res.data.results) {
					var result = res.data.results[siteId] || {};
					var msg = formatResultMessage(result);
					if (result.ok) {
						appendLog('ok', '<strong>' + HebPPDashboard.i18n.logOk + '</strong> ' +
							$('<span/>').text(title).html() + ' → <code>' + label + '</code> — ' +
							$('<span/>').text(msg).html());
						return loadSiteManifest(siteId, true);
					}
					appendLog('fail', '<strong>' + HebPPDashboard.i18n.logFail + '</strong> ' +
						$('<span/>').text(title).html() + ' → <code>' + label + '</code> — ' +
						$('<span/>').text(msg).html());
					var $span = $cell.find('.heb-pp-dash-status');
					$span.text('✗').css('color', '#c00').attr('title', msg).attr('data-code', 'resend_failed');
					return $.Deferred().reject(msg).promise();
				}
				var err = (res && res.data && res.data.message) ? res.data.message : HebPPDashboard.i18n.sentFailed;
				appendLog('fail', '<strong>' + HebPPDashboard.i18n.logFail + '</strong> ' +
					$('<span/>').text(title).html() + ' → <code>' + label + '</code> — ' +
					$('<span/>').text(err).html());
				var $spanFail = $cell.find('.heb-pp-dash-status');
				$spanFail.text('✗').css('color', '#c00').attr('title', err).attr('data-code', 'resend_failed');
				return $.Deferred().reject(err).promise();
			}, function (xhr) {
				setCellBusy($cell, false);
				var err = HebPPDashboard.i18n.sentFailed;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					err = xhr.responseJSON.data.message;
				}
				appendLog('fail', '<strong>' + HebPPDashboard.i18n.logFail + '</strong> ' +
					$('<span/>').text(title).html() + ' → <code>' + label + '</code> — ' +
					$('<span/>').text(err).html());
				$cell.find('.heb-pp-dash-status').text('✗').css('color', '#c00').attr('title', err).attr('data-code', 'resend_failed');
				return $.Deferred().reject(err).promise();
			});
		}

		function resendSitesSequential($row, siteIds, $btn) {
			if (!siteIds.length) { return $.Deferred().resolve().promise(); }

			var title = rowTitle($row);
			var oldText = $btn ? $btn.text() : '';
			$row.addClass('heb-pp-dash-row-busy');
			setGlobalBusy(true);
			if ($btn && $btn.length) {
				$btn.prop('disabled', true).text(HebPPDashboard.i18n.resending + ' (0/' + siteIds.length + ')');
			}
			setStatusMessage(HebPPDashboard.i18n.resending + ' — ' + title);

			var chain = $.Deferred().resolve().promise();
			var done = 0;
			var ok = 0;
			var fail = 0;

			siteIds.forEach(function (siteId) {
				chain = chain.then(function () {
					return resendOneSite($row, siteId).then(function () {
						ok++;
					}, function () {
						fail++;
					}).always(function () {
						done++;
						if ($btn && $btn.length) {
							$btn.text(HebPPDashboard.i18n.resending + ' (' + done + '/' + siteIds.length + ')');
						}
					});
				});
			});

			return chain.always(function () {
				$row.removeClass('heb-pp-dash-row-busy');
				setGlobalBusy(false);
				if ($btn && $btn.length) {
					var summary;
					if (fail === 0) {
						summary = HebPPDashboard.i18n.sentDone;
						$btn.css('color', '#080');
					} else if (ok === 0) {
						summary = HebPPDashboard.i18n.sentFailed;
						$btn.css('color', '#a00');
					} else {
						summary = HebPPDashboard.i18n.sentPartial + ' (' + ok + '/' + siteIds.length + ')';
						$btn.css('color', '#b80');
					}
					$btn.text(summary);
					setStatusMessage(summary + ' — ' + title + ' (' + ok + ' ok, ' + fail + ' fail)');
					setTimeout(function () {
						$btn.text(oldText).css('color', '').prop('disabled', false);
					}, 3000);
				}
				applyCells();
			});
		}

		function bulkResend() {
			var items = [];
			$('.heb-pp-dash-row-check:checked').each(function () {
				var $row = $(this).closest('.heb-pp-dash-row');
				var siteIds = resendTargetsForRow($row);
				if (!siteIds.length) {
					$row.find('.heb-pp-dash-cell').each(function () {
						var id = siteIdFrom($(this));
						if (id) { siteIds.push(id); }
					});
				}
				if (!siteIds.length) { return; }
				items.push({ row: $row, siteIds: siteIds });
			});
			if (items.length === 0) {
				setStatusMessage(HebPPDashboard.i18n.selectRows);
				return;
			}
			if (!window.confirm(HebPPDashboard.i18n.confirmBulk + '\n\n' + items.length + ' item(s).')) { return; }

			var $btn = $('#heb-pp-dash-bulk-resend');
			$btn.prop('disabled', true);
			setGlobalBusy(true);
			appendLog('info', '<strong>批量重发</strong> ' + items.length + ' 项');

			var chain = $.Deferred().resolve().promise();
			items.forEach(function (item) {
				chain = chain.then(function () {
					return resendSitesSequential(item.row, item.siteIds, null);
				});
			});

			chain.always(function () {
				setGlobalBusy(false);
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
				appendLog('info', HebPPDashboard.i18n.clearCache);
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
		$('#heb-pp-dash-log-clear').on('click', function () {
			$('#heb-pp-dash-log').empty();
		});

		$(document).on('click', '.heb-pp-dash-resend', function () {
			if (resendBusy) { return; }
			var $btn = $(this);
			var $row = $btn.closest('.heb-pp-dash-row');
			var siteIds = resendTargetsForRow($row);
			if (!siteIds.length) {
				$row.find('.heb-pp-dash-cell').each(function () {
					var id = siteIdFrom($(this));
					if (id) { siteIds.push(id); }
				});
			}
			resendSitesSequential($row, siteIds, $btn);
		});

		$(document).on('click', '.heb-pp-dash-cell-resend', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (resendBusy) { return; }
			var $cell = $(this).closest('.heb-pp-dash-cell');
			var $row = $cell.closest('.heb-pp-dash-row');
			var siteId = siteIdFrom($cell);
			if (!siteId) { return; }
			resendSitesSequential($row, [siteId], $(this));
		});

		$(document).on('change', '.heb-pp-dash-select-all', function () {
			var checked = this.checked;
			$(this).closest('table').find('.heb-pp-dash-row-check').prop('checked', checked).trigger('change');
		});
		$(document).on('change', '.heb-pp-dash-row-check', function () {
			if (!resendBusy) {
				$('#heb-pp-dash-bulk-resend').prop('disabled', $('.heb-pp-dash-row-check:checked').length === 0);
			}
		});

		loadAllManifests(false);
	});
})(jQuery);
