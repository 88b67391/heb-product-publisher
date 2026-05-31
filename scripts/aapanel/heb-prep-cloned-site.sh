#!/usr/bin/env bash
#
# aaPanel 已复制主站文件/数据库的语言站 → 清空内容、改域名、配 Receiver，供主站 Bootstrap 灌数据
#
# 适用：你已经在 /www/wwwroot/ja.hongbotex.com 复制了主站整站，但还没法走 HEB 分发
#
# 用法：
#   sudo bash scripts/aapanel/heb-prep-cloned-site.sh \
#     --domain ja.hongbotex.com \
#     --locale ja_JP
#
# 批量（读取 heb-aapanel.env 里 LANG_SITES）：
#   sudo bash scripts/aapanel/heb-prep-cloned-site.sh prep-all
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

load_env

MODE="single"
DOMAIN=""
LOCALE=""
FROM_URL=""
TO_URL=""
YES=0

usage() {
	cat <<'EOF'
用法:
  heb-prep-cloned-site.sh --domain DOMAIN --locale LOCALE [选项]
  heb-prep-cloned-site.sh prep-all

选项:
  --domain DOMAIN     语言站目录名，如 ja.hongbotex.com
  --locale LOCALE     WordPress locale，如 ja_JP
  --from-url URL      替换源 URL（默认 heb-aapanel.env 的 MAIN_URL）
  --to-url URL        替换目标 URL（默认 https://DOMAIN）
  --yes               不交互确认，直接清空内容
  prep-all            按 LANG_SITES 批量处理
EOF
	exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		prep-all) MODE="all"; shift ;;
		--domain) DOMAIN="$2"; shift 2 ;;
		--locale) LOCALE="$2"; shift 2 ;;
		--from-url) FROM_URL="$2"; shift 2 ;;
		--to-url) TO_URL="$2"; shift 2 ;;
		--yes) YES=1; shift ;;
		-h|--help) usage 0 ;;
		*) die "未知参数: $1" ;;
	esac
done

prep_one() {
	local domain="$1"
	local locale="$2"
	local from_url="${3:-$MAIN_URL}"
	local to_url="${4:-https://$domain}"
	local target
	target="$(site_path "$domain")"

	[[ -f "$target/wp-config.php" ]] || die "不是 WordPress 目录：$target"

	info "处理 $domain ($locale)"
	info "URL: $from_url → $to_url"

	if [[ "$YES" -ne 1 ]]; then
		echo ""
		echo "即将：search-replace 域名 + 删除所有文章/页面/产品/分类/菜单（保留用户和插件）"
		echo "目录：$target"
		read -r -p "继续？[y/N] " ans
		[[ "$ans" == [yY] ]] || die "已取消"
	fi

	info "替换 URL（含序列化）…"
	wp_run "$target" search-replace "$from_url" "$to_url" --all-tables --precise --recurse-objects --skip-columns=guid 2>/dev/null || true
	# 常见 http/https 双份
	local from_http="${from_url/https:/http:}"
	local to_http="${to_url/https:/http:}"
	if [[ "$from_http" != "$from_url" ]]; then
		wp_run "$target" search-replace "$from_http" "$to_http" --all-tables --precise --recurse-objects --skip-columns=guid 2>/dev/null || true
	fi

	info "删除所有文章…"
	local post_ids
	post_ids="$(wp_run "$target" post list --post_type=any --posts_per_page=-1 --format=ids 2>/dev/null || true)"
	if [[ -n "$post_ids" ]]; then
		# shellcheck disable=SC2086
		wp_run "$target" post delete $post_ids --force 2>/dev/null || true
	fi

	info "删除所有分类/标签…"
	local taxonomies
	taxonomies="$(wp_run "$target" taxonomy list --field=name 2>/dev/null | grep -v '^category$' | grep -v '^post_tag$' || true)"
	for tx in $taxonomies; do
		wp_run "$target" term list "$tx" --field=term_id 2>/dev/null | while read -r tid; do
			[[ -n "$tid" ]] && wp_run "$target" term delete "$tx" "$tid" 2>/dev/null || true
		done
	done
	wp_run "$target" term list category --field=term_id 2>/dev/null | while read -r tid; do
		[[ -n "$tid" ]] && wp_run "$target" term delete category "$tid" 2>/dev/null || true
	done

	info "删除所有菜单…"
	wp_run "$target" menu list --field=term_id 2>/dev/null | while read -r mid; do
		[[ -n "$mid" ]] && wp_run "$target" menu delete "$mid" 2>/dev/null || true
	done

	local secret="${HEB_RECEIVER_SECRET:-$(rand_secret)}"
	append_wp_config_constants "$target" "$secret"
	install_core_language "$target" "$locale"
	activate_heb_plugins "$target"
	wp_run "$target" option update heb_pp_site_role receiver 2>/dev/null || true

	# 清空可能干扰 Bootstrap 的 lang_map / source meta（旧克隆残留）
	info "清理 HEB 分发 meta…"
	wp_run "$target" db query "DELETE FROM ${TABLE_PREFIX}postmeta WHERE meta_key LIKE '_heb_publisher_%' OR meta_key LIKE '_heb_pp_%';" 2>/dev/null || true
	wp_run "$target" db query "DELETE FROM ${TABLE_PREFIX}termmeta WHERE meta_key LIKE '_heb_pp_%';" 2>/dev/null || true

	fix_permissions "$target"
	print_next_steps "$domain" "$locale" "$secret" "$to_url"
}

if [[ "$MODE" == "all" ]]; then
	[[ ${#LANG_SITES[@]} -gt 0 ]] || die "heb-aapanel.env 中 LANG_SITES 为空"
	for entry in "${LANG_SITES[@]}"; do
		d="${entry%%:*}"
		l="${entry#*:}"
		prep_one "$d" "$l"
		echo ""
	done
	exit 0
fi

[[ -n "$DOMAIN" && -n "$LOCALE" ]] || usage 1
FROM_URL="${FROM_URL:-$MAIN_URL}"
TO_URL="${TO_URL:-https://$DOMAIN}"
prep_one "$DOMAIN" "$LOCALE" "$FROM_URL" "$TO_URL"
