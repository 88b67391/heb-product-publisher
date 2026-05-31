(function ($) {
	'use strict';

	$(function () {
		var $startBtn = $('#heb-pp-bs-start');
		var $startMsg = $('#heb-pp-bs-start-msg');
		var $jobs = $('#heb-pp-bs-jobs');
		var $details = $('#heb-pp-bs-details');
		var $detailsBody = $('#heb-pp-bs-details-body');
		var pollTimer = null;
		var pollDetailsTimer = null;
		var currentDetailJobId = null;

		if (typeof HebPPBootstrap === 'undefined') {
			$startMsg.html('<span style="color:#a00;">Bootstrap 脚本未加载。请 Ctrl+F5 硬刷新，或确认插件已更新到最新版。</span>');
			return;
		}

		function fmtDuration(sec) {
			sec = Math.max(0, parseInt(sec, 10) || 0);
			var m = Math.floor(sec / 60);
			var s = sec % 60;
			return m + 'm' + (s < 10 ? '0' : '') + s + 's';
		}

		function jobFinished(job) {
			return job && (job.status === 'done' || job.status === 'done_with_errors' || job.status === 'failed' || job.status === 'cancelled');
		}

		function startBootstrap() {
			var siteId = $('#heb-pp-bs-site').val();
			var dryRun = $('#heb-pp-bs-dry-run').is(':checked');
			if (!siteId) {
				window.alert(HebPPBootstrap.i18n.selectSite);
				return;
			}
			if (!window.confirm(dryRun ? HebPPBootstrap.i18n.confirmDryRun : HebPPBootstrap.i18n.confirmStart)) {
				return;
			}
			$startBtn.prop('disabled', true);
			$startMsg.text(HebPPBootstrap.i18n.starting);

			$.ajax({
				url: HebPPBootstrap.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: 60000,
				data: {
					action: 'heb_pp_bs_start',
					nonce: HebPPBootstrap.nonce,
					site_id: siteId,
					scope_terms: $('#heb-pp-bs-scope-terms').is(':checked') ? 1 : 0,
					scope_posts: $('#heb-pp-bs-scope-posts').is(':checked') ? 1 : 0,
					scope_menus: $('#heb-pp-bs-scope-menus').is(':checked') ? 1 : 0,
					scope_settings: $('#heb-pp-bs-scope-settings').is(':checked') ? 1 : 0,
					scope_menu_locations: $('#heb-pp-bs-scope-menu-locations').is(':checked') ? 1 : 0,
					dry_run: dryRun ? 1 : 0
				}
			}).done(function (res) {
				if (res && res.success) {
					$startMsg.html('<span style="color:#080;">Job created: <code>' + res.data.job_id.substring(0, 8) + '</code></span>');
					setTimeout(function () { window.location.reload(); }, 1200);
				} else {
					$startMsg.html('<span style="color:#a00;">' + ((res && res.data && res.data.message) || 'Error') + '</span>');
				}
			}).fail(function (xhr, status) {
				var msg = xhr.statusText || 'Error';
				if (status === 'timeout') {
					msg = HebPPBootstrap.i18n.startTimeout || 'Request timed out';
				}
				$startMsg.html('<span style="color:#a00;">' + msg + ' — ' + (HebPPBootstrap.i18n.refreshHint || 'refresh the page') + '</span>');
			}).always(function () {
				$startBtn.prop('disabled', false);
			});
		}

		function resendScope(scope) {
			var siteId = $('#heb-pp-bs-site').val();
			if (!siteId) {
				window.alert(HebPPBootstrap.i18n.selectSite);
				return;
			}
			var msg = scope === 'settings' ? HebPPBootstrap.i18n.confirmResendSettings : HebPPBootstrap.i18n.confirmResendMenus;
			if (!window.confirm(msg)) {
				return;
			}
			$startMsg.text(HebPPBootstrap.i18n.starting);
			$.post(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_resend',
				nonce: HebPPBootstrap.nonce,
				site_id: siteId,
				scope: scope,
				scope_menu_locations: $('#heb-pp-bs-scope-menu-locations').is(':checked') ? 1 : 0
			}).done(function (res) {
				if (res && res.success) {
					$startMsg.html('<span style="color:#080;">Job: <code>' + res.data.job_id.substring(0, 8) + '</code></span>');
					setTimeout(function () { window.location.reload(); }, 1200);
				} else {
					$startMsg.html('<span style="color:#a00;">' + ((res && res.data && res.data.message) || 'Error') + '</span>');
				}
			}).fail(function (xhr) {
				$startMsg.html('<span style="color:#a00;">' + (xhr.statusText || 'Error') + '</span>');
			});
		}

		function cancelJob(jobId) {
			if (!window.confirm(HebPPBootstrap.i18n.confirmCancel)) {
				return;
			}
			$.post(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_cancel',
				nonce: HebPPBootstrap.nonce,
				job_id: jobId
			}).done(function () {
				pollJobs();
				if (currentDetailJobId === jobId) {
					refreshDetails();
				}
			});
		}

		function retryJob(jobId) {
			if (!window.confirm(HebPPBootstrap.i18n.confirmRetry)) {
				return;
			}
			$.post(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_retry',
				nonce: HebPPBootstrap.nonce,
				job_id: jobId
			}).done(function (res) {
				if (res && res.success) {
					window.location.reload();
				} else {
					window.alert((res && res.data && res.data.message) || 'Retry failed');
				}
			});
		}

		function nudgeQueue(jobId, $btn) {
			if ($btn) {
				$btn.prop('disabled', true).text(HebPPBootstrap.i18n.nudging);
			}
			$.post(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_nudge',
				nonce: HebPPBootstrap.nonce,
				job_id: jobId || ''
			}).done(function (res) {
				if (res && res.success) {
					if ($btn) {
						$btn.text(HebPPBootstrap.i18n.nudgeDone);
					}
					setTimeout(refreshDetails, 1500);
					pollJobs();
				}
			}).always(function () {
				if ($btn) {
					setTimeout(function () {
						$btn.prop('disabled', false).text(HebPPBootstrap.i18n.nudge);
					}, 3000);
				}
			});
		}

		function pollJobs() {
			$.get(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_status',
				nonce: HebPPBootstrap.nonce
			}).done(function (res) {
				if (!res || !res.success) { return; }
				var hasRunning = false;
				(res.data.jobs || []).forEach(function (job) {
					var $row = $jobs.find('tr[data-job-id="' + job.id + '"]');
					if (!$row.length) {
						return;
					}
					$row.find('.heb-pp-bs-stage').text(job.current_stage);
					$row.find('.heb-pp-bs-status').text(job.status);
					$row.find('.heb-pp-bs-progress').text(job.summary || '');
					if (!job.finished) {
						hasRunning = true;
					}
				});
				if (hasRunning && !pollTimer) {
					pollTimer = setInterval(pollJobs, 3000);
				} else if (!hasRunning && pollTimer) {
					clearInterval(pollTimer);
					pollTimer = null;
				}
			});
		}

		function renderActivity(job) {
			var act = job.activity || {};
			var html = '';
			var pct = act.stage_pct || 0;
			html += '<div class="heb-pp-bs-progress-bar-wrap"><div class="heb-pp-bs-progress-bar" style="width:' + pct + '%;"></div></div>';
			html += '<p style="margin:6px 0;"><strong>' + pct + '%</strong> · stage <code>' + escapeHtml(job.current_stage) + '</code>';
			if (act.pending_actions) {
				html += ' · AS 待处理 <strong>' + act.pending_actions + '</strong>';
			}
			html += '</p>';

			if (job.current_item && job.current_item.source_id) {
				var cur = job.current_item;
				html += '<p class="heb-pp-bs-current-item">';
				html += escapeHtml(HebPPBootstrap.i18n.processing) + ' <code>' + escapeHtml(cur.type) + ' #' + cur.source_id + '</code>';
				if (cur.label) {
					html += ' «' + escapeHtml(cur.label) + '»';
				}
				if (act.current_elapsed) {
					html += ' · ' + fmtDuration(act.current_elapsed);
				}
				html += '</p>';
			}

			if (act.stale) {
				html += '<div class="notice notice-warning inline" style="margin:10px 0;padding:8px 12px;">';
				html += '<p style="margin:0;">' + escapeHtml(HebPPBootstrap.i18n.staleHint) + '</p>';
				html += '<p style="margin:8px 0 0;"><button type="button" class="button button-small heb-pp-bs-nudge" data-job-id="' + escapeHtml(job.id) + '">' + escapeHtml(HebPPBootstrap.i18n.nudge) + '</button></p>';
				html += '</div>';
			} else if (!jobFinished(job)) {
				html += '<p style="margin:8px 0 0;font-size:12px;color:#666;">';
				html += '<button type="button" class="button button-small heb-pp-bs-nudge" data-job-id="' + escapeHtml(job.id) + '">' + escapeHtml(HebPPBootstrap.i18n.nudge) + '</button>';
				html += '</p>';
			}
			return html;
		}

		function loadDetails(jobId) {
			currentDetailJobId = jobId;
			$details.show();
			$detailsBody.html('<p><em>Loading…</em></p>');
			refreshDetails();
		}

		function refreshDetails() {
			if (!currentDetailJobId) { return; }
			$.get(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_status',
				nonce: HebPPBootstrap.nonce,
				job_id: currentDetailJobId
			}).done(function (res) {
				if (!res || !res.success) {
					$detailsBody.html('<p>Error loading job.</p>');
					return;
				}
				var job = res.data.job;
				var html = '<p><strong>ID:</strong> <code>' + job.id + '</code></p>';
				html += '<p><strong>Site:</strong> ' + escapeHtml(job.site_id) + ' &middot; ';
				html += '<strong>Status:</strong> ' + escapeHtml(job.status) + ' &middot; ';
				html += '<strong>Stage:</strong> ' + escapeHtml(job.current_stage) + '</p>';

				html += renderActivity(job);

				html += '<h4>Progress</h4><table class="widefat" style="max-width:600px;"><thead><tr><th>Stage</th><th>Queued</th><th>Done</th><th>Failed</th><th>Skipped</th></tr></thead><tbody>';
				$.each(job.progress || {}, function (stage, p) {
					html += '<tr><td><code>' + stage + '</code></td><td>' + (p.queued || 0) + '</td><td>' + (p.done || 0) + '</td><td style="color:' + ((p.failed || 0) > 0 ? '#a00' : '') + '">' + (p.failed || 0) + '</td><td>' + (p.skipped || 0) + '</td></tr>';
				});
				html += '</tbody></table>';

				if (job.errors && job.errors.length) {
					html += '<h4 style="margin-top:14px;">Errors (' + job.errors.length + ')</h4>';
					if (job.status === 'done_with_errors' || job.status === 'failed') {
						html += '<p><button type="button" class="button heb-pp-bs-retry" data-job-id="' + escapeHtml(job.id) + '">↻ 重试失败项</button></p>';
					}
					html += '<table class="widefat" style="max-width:800px;"><thead><tr><th>Type</th><th>Source ID</th><th>Message</th></tr></thead><tbody>';
					job.errors.slice(-30).forEach(function (e) {
						html += '<tr><td>' + escapeHtml(e.type) + '</td><td>' + (e.source_id || '-') + '</td><td><code style="white-space:pre-wrap;">' + escapeHtml(e.message || '') + '</code></td></tr>';
					});
					html += '</tbody></table>';
				}

				if (job.log && job.log.length) {
					html += '<h4 style="margin-top:14px;">Log (last 30)</h4>';
					html += '<ul class="heb-pp-bs-log">';
					job.log.slice(-30).forEach(function (l) {
						var color = l.level === 'error' ? '#a00' : (l.level === 'warning' ? '#b80' : '#444');
						var ts = new Date((l.ts || 0) * 1000).toLocaleTimeString();
						html += '<li><code style="color:' + color + ';">[' + ts + ' ' + escapeHtml(l.level) + ']</code> ' + escapeHtml(l.msg || '') + '</li>';
					});
					html += '</ul>';
				}

				if (job.audit && job.audit.checks && job.audit.checks.length) {
					html += '<h4 style="margin-top:14px;">配置验收</h4>';
					html += '<table class="widefat" style="max-width:700px;"><thead><tr><th>检查项</th><th>结果</th><th>说明</th></tr></thead><tbody>';
					job.audit.checks.forEach(function (c) {
						var ok = c.ok ? '✓' : '✗';
						var color = c.ok ? '#080' : '#a00';
						html += '<tr><td><code>' + escapeHtml(c.id || '') + '</code></td>';
						html += '<td style="color:' + color + ';">' + ok + '</td>';
						html += '<td>' + escapeHtml(c.message || '') + '</td></tr>';
					});
					html += '</tbody></table>';
				}
				$detailsBody.html(html);

				if (!jobFinished(job) && !pollDetailsTimer) {
					pollDetailsTimer = setInterval(refreshDetails, 3000);
				} else if (jobFinished(job) && pollDetailsTimer) {
					clearInterval(pollDetailsTimer);
					pollDetailsTimer = null;
				}
			});
		}

		function escapeHtml(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		if (!$startBtn.length) {
			return;
		}

		$startBtn.on('click', startBootstrap);
		$('#heb-pp-bs-resend-settings').on('click', function () { resendScope('settings'); });
		$('#heb-pp-bs-resend-menus').on('click', function () { resendScope('menus'); });
		$jobs.on('click', '.heb-pp-bs-cancel', function () {
			cancelJob($(this).data('job-id'));
		});
		$jobs.on('click', '.heb-pp-bs-retry', function () {
			retryJob($(this).data('job-id'));
		});
		$detailsBody.on('click', '.heb-pp-bs-retry', function () {
			retryJob($(this).data('job-id'));
		});
		$detailsBody.on('click', '.heb-pp-bs-nudge', function () {
			nudgeQueue($(this).data('job-id'), $(this));
		});
		$jobs.on('click', '.heb-pp-bs-details', function () {
			loadDetails($(this).data('job-id'));
		});

		// 启动时若有 running job，自动打开详情并轮询。
		pollJobs();
		var $running = $jobs.find('tr[data-job-status="running"], tr[data-job-status="queued"]');
		if ($running.length) {
			loadDetails($running.first().data('job-id'));
		}
	});
})(jQuery);
