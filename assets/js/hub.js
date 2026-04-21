/* global jQuery, HebPPHub */
(function ($) {
	'use strict';

	var $box, postId;

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
		var $b = $(btn);
		if (busy) {
			$b.prop('disabled', true).data('orig', $b.text()).text($b.text() + '…');
			$box.addClass('heb-pp-busy');
		} else {
			$b.prop('disabled', false);
			if ($b.data('orig')) $b.text($b.data('orig'));
			$box.removeClass('heb-pp-busy');
		}
	}

	/**
	 * 为某站点渲染 locale + 可勾选分类。
	 */
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

	/**
	 * 从 UI 读取每站 taxonomy 覆盖。
	 */
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

	function renderDistribute(siteId, res) {
		var $li = $box.find('.heb-pp-site-item[data-site-id="' + siteId + '"]');
		var label = $li.find('.heb-pp-site-label').text();
		var $result = $('#heb-pp-result');
		var cls = res.ok ? 'ok' : 'err';
		var html = '<strong>' + escapeHtml(label) + '</strong> · ';
		if (res.ok) {
			html += (res.created ? '已新建' : '已更新') + ' #' + res.post_id;
			if (res.locale) html += ' · locale=' + escapeHtml(res.locale);
			if (res.translate && res.translate.strings) {
				html += ' · ' + res.translate.translated + '/' + res.translate.strings + ' 字符串';
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
		$result.append('<div class="heb-pp-result-item ' + cls + '">' + html + '</div>');
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

		function doDistribute(ids) {
			$('#heb-pp-result').empty();
			var $btn = $('#heb-pp-btn-distribute');
			setBusy($btn, true);
			$btn.text(HebPPHub.i18n.distributing);

			var data = buildDistributeData(ids);

			$.post(HebPPHub.ajaxUrl, data).done(function (resp) {
				if (!resp || !resp.success) {
					alert((resp && resp.data && resp.data.message) || HebPPHub.i18n.error);
					return;
				}
				Object.keys(resp.data).forEach(function (sid) {
					renderDistribute(sid, resp.data[sid]);
				});
			}).fail(function (xhr) {
				alert(HebPPHub.i18n.error + ': ' + xhr.status);
			}).always(function () {
				setBusy($btn, false);
			});
		}

		$('#heb-pp-btn-distribute').on('click', function () {
			var ids = selectedSiteIds();
			if (!ids.length) {
				alert(HebPPHub.i18n.selectAtLeast);
				return;
			}
			doDistribute(ids);
		});

		/* ------- 预览 diff ------- */

		function renderDiffPanel(perSite) {
			var $panel = $('#heb-pp-preview-panel');
			if (!$panel.length) {
				$panel = $('<div id="heb-pp-preview-panel" class="heb-pp-preview-panel"></div>');
				$('#heb-pp-result').after($panel);
			}
			$panel.empty();

			var statusLabel = {
				added:     '<span class="heb-pp-diff-st heb-pp-diff-st--add">新增</span>',
				removed:   '<span class="heb-pp-diff-st heb-pp-diff-st--rm">清空</span>',
				changed:   '<span class="heb-pp-diff-st heb-pp-diff-st--ch">修改</span>',
				unchanged: '<span class="heb-pp-diff-st heb-pp-diff-st--un">未变</span>'
			};

			Object.keys(perSite).forEach(function (sid) {
				var res = perSite[sid];
				var $site = $('<section class="heb-pp-preview-site"></section>');
				var head = '<header><strong>' + escapeHtml(res.label || sid) + '</strong>';
				if (res.target_locale) head += ' <code>' + escapeHtml(res.target_locale) + '</code>';
				if (res.existing && res.existing.post_id) {
					head += ' · <a href="' + res.existing.edit_url + '" target="_blank" rel="noopener">#' + res.existing.post_id + '</a>';
				} else {
					head += ' · <em>' + escapeHtml(HebPPHub.i18n.newOnTarget) + '</em>';
				}
				if (res.stats && res.stats.strings) {
					head += ' · ' + res.stats.translated + '/' + res.stats.strings + ' 翻译';
				}
				head += '</header>';
				$site.append(head);

				if (!res.ok) {
					$site.append('<p class="heb-pp-bad">' + escapeHtml(res.message || HebPPHub.i18n.error) + '</p>');
					$panel.append($site);
					return;
				}

				var $list = $('<div class="heb-pp-diff-list"></div>');
				(res.diff || []).forEach(function (f) {
					var $row = $('<div class="heb-pp-diff-row"></div>');
					$row.append(
						'<div class="heb-pp-diff-head">' +
							(statusLabel[f.status] || '') +
							' <strong>' + escapeHtml(f.label) + '</strong>' +
						'</div>'
					);
					if (f.status === 'added') {
						$row.append('<div class="heb-pp-diff-added"><pre>' + escapeHtml(f.next) + '</pre></div>');
					} else if (f.status === 'removed') {
						$row.append('<div class="heb-pp-diff-removed"><pre>' + escapeHtml(f.prev) + '</pre></div>');
					} else if (f.status === 'changed' && f.diff) {
						$row.append('<div class="heb-pp-diff-body">' + f.diff + '</div>');
					} else {
						$row.append('<div class="heb-pp-diff-muted">' + escapeHtml(HebPPHub.i18n.noChange) + '</div>');
					}
					$list.append($row);
				});
				$site.append($list);

				if (res.warn && res.warn.length) {
					var $warn = $('<div class="heb-pp-warn-list"></div>');
					res.warn.forEach(function (w) {
						$warn.append('<div class="heb-pp-warn">⚠ ' + escapeHtml(w) + '</div>');
					});
					$site.append($warn);
				}

				$panel.append($site);
			});

			var $footer = $('<div class="heb-pp-preview-footer"></div>');
			$footer.append(
				'<button type="button" class="button button-primary button-large" id="heb-pp-btn-confirm">' +
					escapeHtml(HebPPHub.i18n.confirmBtn) +
				'</button> ' +
				'<button type="button" class="button" id="heb-pp-btn-cancel-preview">' +
					escapeHtml(HebPPHub.i18n.cancelBtn) +
				'</button>'
			);
			$panel.prepend('<h3 class="heb-pp-preview-title">' + escapeHtml(HebPPHub.i18n.confirmTitle) + '</h3>');
			$panel.append($footer);

			$('html, body').animate({ scrollTop: $panel.offset().top - 80 }, 300);
		}

		$box.on('click', '#heb-pp-btn-preview', function () {
			var ids = selectedSiteIds();
			if (!ids.length) {
				alert(HebPPHub.i18n.selectAtLeast);
				return;
			}
			$('#heb-pp-result').empty();
			$('#heb-pp-preview-panel').remove();
			setBusy(this, true);
			$(this).text(HebPPHub.i18n.previewing);

			var data = buildDistributeData(ids);
			data.action = 'heb_pp_preview';

			$.post(HebPPHub.ajaxUrl, data).done(function (resp) {
				if (!resp || !resp.success) {
					alert((resp && resp.data && resp.data.message) || HebPPHub.i18n.error);
					return;
				}
				renderDiffPanel(resp.data);
			}).fail(function (xhr) {
				alert(HebPPHub.i18n.error + ': ' + xhr.status);
			}).always(function () {
				setBusy($('#heb-pp-btn-preview'), false);
			});
		});

		$(document).on('click', '#heb-pp-btn-confirm', function () {
			var ids = selectedSiteIds();
			if (!ids.length) return;
			$('#heb-pp-preview-panel').remove();
			doDistribute(ids);
		});
		$(document).on('click', '#heb-pp-btn-cancel-preview', function () {
			$('#heb-pp-preview-panel').remove();
		});
	});
})(jQuery);
