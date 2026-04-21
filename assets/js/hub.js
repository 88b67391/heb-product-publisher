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

		$('#heb-pp-btn-distribute').on('click', function () {
			var ids = selectedSiteIds();
			if (!ids.length) {
				alert(HebPPHub.i18n.selectAtLeast);
				return;
			}
			$('#heb-pp-result').empty();
			setBusy(this, true);
			$(this).text(HebPPHub.i18n.translating);

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
				setBusy($('#heb-pp-btn-distribute'), false);
			});
		});
	});
})(jQuery);
