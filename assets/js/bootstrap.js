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

			$.post(HebPPBootstrap.ajaxUrl, {
				action: 'heb_pp_bs_start',
				nonce: HebPPBootstrap.nonce,
				site_id: siteId,
				scope_terms: $('#heb-pp-bs-scope-terms').is(':checked') ? 1 : 0,
				scope_posts: $('#heb-pp-bs-scope-posts').is(':checked') ? 1 : 0,
				scope_menus: $('#heb-pp-bs-scope-menus').is(':checked') ? 1 : 0,
				scope_settings: $('#heb-pp-bs-scope-settings').is(':checked') ? 1 : 0,
				scope_menu_locations: $('#heb-pp-bs-scope-menu-locations').is(':checked') ? 1 : 0,
				dry_run: dryRun ? 1 : 0
			}).done(function (res) {
				if (res && res.success) {
					$startMsg.html('<span style="color:#080;">Job created: <code>' + res.data.job_id.substring(0, 8) + '</code></span>');
					setTimeout(function () { window.location.reload(); }, 1200);
				} else {
					$startMsg.html('<span style="color:#a00;">' + ((res && res.data && res.data.message) || 'Error') + '</span>');
				}
			}).fail(function (xhr) {
				$startMsg.html('<span style="color:#a00;">' + (xhr.statusText || 'Error') + '</span>');
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

				var finished = (job.status === 'done' || job.status === 'done_with_errors' || job.status === 'failed' || job.status === 'cancelled');
				if (!finished && !pollDetailsTimer) {
					pollDetailsTimer = setInterval(refreshDetails, 3000);
				} else if (finished && pollDetailsTimer) {
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
		$jobs.on('click', '.heb-pp-bs-details', function () {
			loadDetails($(this).data('job-id'));
		});

		// 启动时若有 running，自动开始轮询。
		pollJobs();
	});
})(jQuery);
