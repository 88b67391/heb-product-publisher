/* global jQuery, HebPPHub */
(function ($) {
	'use strict';

	var $box, postId, currentJobId = null;

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function selectedSiteIds() {
		return $box.find('.heb-pp-site-check:checked').map(function () {
			return $(this).val();
		}).get();
	}

	function setBusy(btn, busy) {
		var $b = btn ? $(btn) : $();
		if (busy) {
			if ($b.length) {
				$b.prop('disabled', true).data('orig', $b.text()).text(HebPPHub.i18n.distributing);
			}
			$box.addClass('heb-pp-busy');
			$('#heb-pp-btn-distribute').prop('disabled', true);
		} else {
			if ($b.length && $b.data('orig')) {
				$b.prop('disabled', false).text($b.data('orig'));
			}
			$box.removeClass('heb-pp-busy');
			$('#heb-pp-btn-distribute').prop('disabled', false);
		}
	}

	function renderSiteInfo(siteId, info) {
		var $li = $box.find('.heb-pp-site-item[data-site-id="' + siteId + '"]');
		var $out = $li.find('.heb-pp-site-info');
		if (!info.ok) {
			$out.removeAttr('hidden').html('<strong class="heb-pp-bad">' + escapeHtml(info.message || 'Error') + '</strong>');
			return;
		}

		$li.find('.heb-pp-site-locale').text(info.locale || '—');

		var sourceSlugs = (HebPPHub.sourceSlugs && typeof HebPPHub.sourceSlugs === 'object') ? HebPPHub.sourceSlugs : {};
		var html = '<p class="heb-pp-si-head">locale: <code>' + escapeHtml(info.locale || '') + '</code></p>';

		var taxes = info.taxonomies || {};
		var taxKeys = Object.keys(taxes);
		if (!taxKeys.length) {
			html += '<p class="description">' + escapeHtml(HebPPHub.i18n.noTerms) + '</p>';
			$out.removeAttr('hidden').html(html);
			return;
		}

		taxKeys.forEach(function (taxKey) {
			var tax = taxes[taxKey] || {};
			var terms = tax.terms || [];
			var preSet = Array.isArray(sourceSlugs[taxKey]) ? sourceSlugs[taxKey] : [];

			html += '<div class="heb-pp-tax" data-tax="' + escapeHtml(taxKey) + '">';
			html += '<div class="heb-pp-tax-head">';
			html += '<strong>' + escapeHtml(tax.label || taxKey) + '</strong>';
			html += ' <code>' + escapeHtml(taxKey) + '</code>';
			html += ' <a href="#" class="heb-pp-tax-all">全选</a> / <a href="#" class="heb-pp-tax-none">全不选</a>';
			html += '</div>';

			if (!terms.length) {
				html += '<p class="description" style="margin:4px 0 0">' + escapeHtml(HebPPHub.i18n.noTerms) + '</p>';
			} else {
				html += '<ul class="heb-pp-terms">';
				terms.forEach(function (t) {
					var checked = preSet.indexOf(t.slug) !== -1 ? ' checked' : '';
					var indent  = (t.parent && parseInt(t.parent, 10) > 0) ? ' heb-pp-term-child' : '';
					html += '<li class="heb-pp-term' + indent + '">';
					html += '<label><input type="checkbox" class="heb-pp-term-cb" value="' + escapeHtml(t.slug) + '"' + checked + '> ';
					html += escapeHtml(t.name) + ' <span class="heb-pp-term-slug">(' + escapeHtml(t.slug) + ')</span></label>';
					html += '</li>';
				});
				html += '</ul>';
			}

			html += '</div>';
		});

		$out.removeAttr('hidden').html(html);
	}

	function collectOverrides() {
		var out = {};
		$box.find('.heb-pp-site-item').each(function () {
			var $li = $(this);
			var sid = $li.data('site-id');
			if (!$li.find('.heb-pp-site-check').is(':checked')) return;

			var $info = $li.find('.heb-pp-site-info');
			if ($info.is('[hidden]') || !$info.find('.heb-pp-tax').length) return;

			var perSite = {};
			$info.find('.heb-pp-tax').each(function () {
				var $tax = $(this);
				var taxKey = $tax.data('tax');
				var slugs = $tax.find('.heb-pp-term-cb:checked').map(function () {
					return $(this).val();
				}).get();
				perSite[taxKey] = slugs;
			});
			out[sid] = perSite;
		});
		return out;
	}

	function renderDistribute(siteId, res, labels) {
		var label = (labels && labels[siteId]) || res.label || siteId;
		var $result = $('#heb-pp-result');
		var cls = res.ok ? 'ok' : (res.locked ? 'warn' : 'err');
		var html = '<strong>' + escapeHtml(label) + '</strong> · ';
		if (res.locked) {
			html += '🔒 ' + escapeHtml(res.message || 'locked locally');
		} else if (res.ok) {
			html += (res.created ? '已新建' : '已更新') + ' #' + res.post_id;
			if (res.locale) html += ' · locale=' + escapeHtml(res.locale);
			if (res.translate && res.translate.strings) {
				html += ' · ' + res.translate.translated + '/' + res.translate.strings + ' 字符串';
				if (res.translate.strings > 0 && res.translate.translated / res.translate.strings < 0.85) {
					html += ' <span class="heb-pp-warn">⚠ 翻译不完整</span>';
				}
			}
			if (res.pending_media > 0) {
				html += ' · ⏳ ' + res.pending_media + ' media pending';
			}
			if (res.edit_url) html += ' · <a href="' + res.edit_url + '" target="_blank" rel="noopener">在目标站点打开</a>';
		} else {
			html += escapeHtml(res.message || HebPPHub.i18n.error);
		}
		if (res.warn && res.warn.length) {
			res.warn.forEach(function (w) {
				html += '<span class="heb-pp-warn">⚠ ' + escapeHtml(w) + '</span>';
			});
		}

		var $existing = $result.find('.heb-pp-result-item[data-site-id="' + siteId + '"]');
		var itemHtml = '<div class="heb-pp-result-item ' + cls + '" data-site-id="' + escapeHtml(siteId) + '">' + html + '</div>';
		if ($existing.length) {
			$existing.replaceWith(itemHtml);
		} else {
			$result.append(itemHtml);
		}
	}

	function renderJobLog(job) {
		var $log = $('#heb-pp-job-log');
		if (!$log.length || !job || !job.log || !job.log.length) {
			return;
		}
		var lines = job.log.slice().reverse().map(function (row) {
			var cls = row.l === 'ok' ? 'ok' : (row.l === 'fail' ? 'fail' : 'info');
			var t = row.t ? new Date(row.t * 1000).toLocaleTimeString() : '';
			return '<div class="heb-pp-job-log-line heb-pp-job-log-' + cls + '"><span class="heb-pp-job-log-time">' +
				escapeHtml(t) + '</span> ' + escapeHtml(row.m || '') + '</div>';
		});
		$log.removeAttr('hidden').html(lines.join(''));
	}

	function renderJobProgress(job) {
		if (!job) { return; }
		var $result = $('#heb-pp-result');
		var labels = job.site_labels || {};
		var done = parseInt(job.index, 10) || 0;
		var total = parseInt(job.total, 10) || 0;
		var headline = HebPPHub.i18n.progress + ': ' + done + '/' + total;
		if (job.status === 'running' && job.current_label) {
			headline += ' · ' + job.current_label + ' …';
		}
		var $head = $result.find('.heb-pp-job-head');
		if (!$head.length) {
			$result.prepend('<div class="heb-pp-job-head heb-pp-result-item info">' + escapeHtml(headline) + '</div>');
		} else {
			$head.text(headline);
		}

		var $hint = $result.find('.heb-pp-job-hint');
		if (job.status === 'running' && job.current_site) {
			if (!$hint.length) {
				$result.find('.heb-pp-job-head').after('<p class="description heb-pp-job-hint">' + escapeHtml(HebPPHub.i18n.stepWait) + '</p>');
			} else {
				$hint.text(HebPPHub.i18n.stepWait);
			}
		}

		Object.keys(job.results || {}).forEach(function (sid) {
			renderDistribute(sid, job.results[sid], labels);
		});
		renderJobLog(job);
	}

	function jobIsActive(job) {
		return job && (job.status === 'queued' || job.status === 'running');
	}

	function finishJob(job) {
		setBusy($('#heb-pp-btn-distribute'), false);
		currentJobId = null;
		var $head = $('#heb-pp-result .heb-pp-job-head');
		if ($head.length && job) {
			if (job.status === 'done') {
				$head.text(HebPPHub.i18n.done);
			} else if (job.status === 'done_with_errors') {
				$head.text(HebPPHub.i18n.donePartial);
			} else if (job.status === 'failed') {
				$head.text(HebPPHub.i18n.error);
			}
		}
		$('#heb-pp-result .heb-pp-job-hint').remove();
	}

	function runJobStep(jobId) {
		return $.ajax({
			url: HebPPHub.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: 900000,
			data: {
				action: 'heb_pp_hub_dist_step',
				nonce: HebPPHub.nonce,
				job_id: jobId
			}
		});
	}

	function runJobChain(jobId) {
		currentJobId = jobId;
		return runJobStep(jobId).done(function (resp) {
			if (!resp || !resp.success) {
				alert((resp && resp.data && resp.data.message) || HebPPHub.i18n.error);
				setBusy($('#heb-pp-btn-distribute'), false);
				return;
			}
			var job = resp.data;
			renderJobProgress(job);
			if (jobIsActive(job)) {
				return runJobChain(jobId);
			}
			finishJob(job);
		}).fail(function (xhr) {
			var msg = HebPPHub.i18n.error;
			if (xhr && xhr.status === 0) {
				msg += ' (timeout)';
			} else if (xhr && xhr.status) {
				msg += ': ' + xhr.status;
			}
			alert(msg);
			setBusy($('#heb-pp-btn-distribute'), false);
		});
	}

	function buildDistributeData(ids) {
		var overrides = collectOverrides();
		var data = {
			action: 'heb_pp_distribute',
			nonce: HebPPHub.nonce,
			post_id: postId
		};
		data['site_ids[]'] = ids;
		Object.keys(overrides).forEach(function (sid) {
			var taxes = overrides[sid];
			Object.keys(taxes).forEach(function (taxKey) {
				var slugs = taxes[taxKey] || [];
				var base = 'site_overrides[' + sid + '][' + taxKey + ']';
				if (!slugs.length) {
					data[base + '[]'] = '';
				} else {
					data[base + '[]'] = slugs;
				}
			});
		});
		return data;
	}

	function startDistribute(ids) {
		$('#heb-pp-result').html(
			'<div class="heb-pp-job-head heb-pp-result-item info">' + escapeHtml(HebPPHub.i18n.queued) + '</div>' +
			'<p class="description heb-pp-job-hint">' + escapeHtml(HebPPHub.i18n.backgroundHint) + '</p>'
		);
		$('#heb-pp-job-log').removeAttr('hidden').empty();
		setBusy($('#heb-pp-btn-distribute'), true);

		$.ajax({
			url: HebPPHub.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: 30000,
			data: buildDistributeData(ids)
		}).done(function (resp) {
			if (!resp || !resp.success) {
				alert((resp && resp.data && resp.data.message) || HebPPHub.i18n.error);
				setBusy($('#heb-pp-btn-distribute'), false);
				return;
			}
			var jobId = resp.data.job_id;
			if (resp.data.job) {
				renderJobProgress(resp.data.job);
			}
			if (jobId) {
				runJobChain(jobId);
			} else {
				setBusy($('#heb-pp-btn-distribute'), false);
			}
		}).fail(function (xhr) {
			alert(HebPPHub.i18n.error + ': ' + xhr.status);
			setBusy($('#heb-pp-btn-distribute'), false);
		});
	}

	$(function () {
		$box = $('.heb-pp-hub-box');
		if (!$box.length) return;
		postId = parseInt($box.data('post-id'), 10) || 0;

		$box.on('click', '.heb-pp-tax-all', function (e) {
			e.preventDefault();
			$(this).closest('.heb-pp-tax').find('.heb-pp-term-cb').prop('checked', true);
		});
		$box.on('click', '.heb-pp-tax-none', function (e) {
			e.preventDefault();
			$(this).closest('.heb-pp-tax').find('.heb-pp-term-cb').prop('checked', false);
		});

		$('#heb-pp-btn-fetch').on('click', function () {
			var ids = selectedSiteIds();
			if (!ids.length) {
				alert(HebPPHub.i18n.selectAtLeast);
				return;
			}
			setBusy(this, true);
			$.post(HebPPHub.ajaxUrl, {
				action: 'heb_pp_fetch_site_info',
				nonce: HebPPHub.nonce,
				post_id: postId,
				'site_ids[]': ids
			}).done(function (resp) {
				if (!resp || !resp.success) {
					alert((resp && resp.data && resp.data.message) || HebPPHub.i18n.error);
					return;
				}
				Object.keys(resp.data).forEach(function (sid) {
					renderSiteInfo(sid, resp.data[sid]);
				});
			}).fail(function (xhr) {
				alert(HebPPHub.i18n.error + ': ' + xhr.status);
			}).always(function () {
				setBusy($('#heb-pp-btn-fetch'), false);
			});
		});

		$('#heb-pp-btn-distribute').on('click', function () {
			var ids = selectedSiteIds();
			if (!ids.length) {
				alert(HebPPHub.i18n.selectAtLeast);
				return;
			}
			startDistribute(ids);
		});

		if (HebPPHub.activeJob && HebPPHub.activeJob.id && jobIsActive(HebPPHub.activeJob)) {
			$('#heb-pp-result').html(
				'<div class="heb-pp-job-head heb-pp-result-item info">' + escapeHtml(HebPPHub.i18n.distributing) + '</div>' +
				'<p class="description heb-pp-job-hint">' + escapeHtml(HebPPHub.i18n.backgroundHint) + '</p>'
			);
			renderJobProgress(HebPPHub.activeJob);
			setBusy($('#heb-pp-btn-distribute'), true);
			runJobChain(HebPPHub.activeJob.id);
		}
	});
})(jQuery);
