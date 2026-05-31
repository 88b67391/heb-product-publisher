#!/usr/bin/env bash
# shellcheck disable=SC2034
# aaPanel / HEB 开站脚本公共函数

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AAPANEL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

load_env() {
	local env_file="${HEB_AAPANEL_ENV:-$AAPANEL_DIR/heb-aapanel.env}"
	if [[ -f "$env_file" ]]; then
		# shellcheck disable=SC1090
		source "$env_file"
	fi

	WWW_ROOT="${WWW_ROOT:-/www/wwwroot}"
	MAIN_DOMAIN="${MAIN_DOMAIN:-www.hongbotex.com}"
	MAIN_URL="${MAIN_URL:-https://$MAIN_DOMAIN}"
	DB_HOST="${DB_HOST:-localhost}"
	TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
	WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
	WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
	HUB_URL="${HUB_URL:-https://$MAIN_DOMAIN/wp-admin}"
}

detect_php() {
	if [[ -n "${PHP_BIN:-}" && -x "$PHP_BIN" ]]; then
		echo "$PHP_BIN"
		return
	fi
	local candidate
	candidate="$(ls -1d /www/server/php/*/bin/php 2>/dev/null | sort -V | tail -1 || true)"
	if [[ -n "$candidate" && -x "$candidate" ]]; then
		echo "$candidate"
		return
	fi
	if command -v php >/dev/null 2>&1; then
		command -v php
		return
	fi
	die "找不到 PHP。请在 heb-aapanel.env 设置 PHP_BIN（aaPanel 常见：/www/server/php/82/bin/php）"
}

detect_wp() {
	if [[ -n "${WP_CLI:-}" && -x "$WP_CLI" ]]; then
		echo "$WP_CLI"
		return
	fi
	if command -v wp >/dev/null 2>&1; then
		command -v wp
		return
	fi
	if [[ -x /usr/local/bin/wp ]]; then
		echo /usr/local/bin/wp
		return
	fi
	if [[ -x /www/server/wp-cli/wp-cli.phar ]]; then
		echo /www/server/wp-cli/wp-cli.phar
		return
	fi
	die "找不到 WP-CLI。aaPanel 终端执行：curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
}

die() {
	echo "错误: $*" >&2
	exit 1
}

info() {
	echo "==> $*"
}

rand_secret() {
	if command -v openssl >/dev/null 2>&1; then
		openssl rand -hex 24
	else
		tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48
	fi
}

site_path() {
	echo "$WWW_ROOT/$1"
}

ensure_main_site() {
	local main_path
	main_path="$(site_path "$MAIN_DOMAIN")"
	[[ -f "$main_path/wp-config.php" ]] || die "主站不存在或不是 WordPress：$main_path"
	echo "$main_path"
}

fix_permissions() {
	local path="$1"
	if id www >/dev/null 2>&1; then
		chown -R www:www "$path" 2>/dev/null || true
	fi
}

wp_run() {
	local path="$1"
	shift
	local php_bin wp_bin
	local allow=()
	php_bin="$(detect_php)"
	wp_bin="$(detect_wp)"
	if [[ "${EUID:-0}" -eq 0 ]]; then
		allow=(--allow-root)
	fi
	if [[ "$wp_bin" == *.phar ]]; then
		"$php_bin" "$wp_bin" --path="$path" "${allow[@]}" "$@"
	else
		"$wp_bin" --path="$path" "${allow[@]}" "$@"
	fi
}

append_wp_config_constants() {
	local path="$1"
	local secret="$2"
	local cfg="$path/wp-config.php"
	[[ -f "$cfg" ]] || die "缺少 wp-config.php：$cfg"

	if grep -q "HEB_PUBLISHER_RECEIVER_SECRET" "$cfg"; then
		info "wp-config.php 已含 HEB_PUBLISHER_RECEIVER_SECRET，跳过写入"
	else
		cat >>"$cfg" <<PHP

/* HEB Product Publisher — 由 heb-aapanel 脚本写入 */
define( 'HEB_PUBLISHER_RECEIVER_SECRET', '$secret' );
define( 'HEB_PP_SITE_ROLE', 'receiver' );
PHP
		info "已写入 wp-config.php：HEB_PUBLISHER_RECEIVER_SECRET + HEB_PP_SITE_ROLE=receiver"
	fi
}

activate_heb_plugins() {
	local path="$1"
	wp_run "$path" plugin activate heb-product-publisher 2>/dev/null || true
	wp_run "$path" plugin is-active heb-product-publisher >/dev/null 2>&1 \
		|| die "heb-product-publisher 未激活，请确认 wp-content/plugins 已从主站同步"
}

install_core_language() {
	local path="$1"
	local locale="$2"
	local lang="${locale%%_*}"
	info "安装 WordPress 语言包：$locale"
	wp_run "$path" language core install "$locale" 2>/dev/null || wp_run "$path" language core install "$lang" 2>/dev/null || true
	wp_run "$path" site switch-language "$locale" 2>/dev/null \
		|| wp_run "$path" option update WPLANG "$locale" 2>/dev/null \
		|| wp_run "$path" option update WPLANG "$lang" 2>/dev/null || true
}

print_next_steps() {
	local domain="$1"
	local locale="$2"
	local secret="$3"
	local site_url="${4:-https://$domain}"

	cat <<EOF

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  语言站准备完成：$domain  ($locale)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1) 主站 $HUB_URL
   → HEB 分发 → 设置 → 远端站点列表 → 添加：
      标签：     ${domain%%.*} 站
      URL：      ${site_url%/}
      接收密钥： $secret
      目标语言： $locale

2) 主站 → HEB 分发 → 站点 Bootstrap
   → 先 Dry run → 再正式跑（全 scope）

3) 主站 → 分发总览 → 刷新 manifest 验收

Receiver secret（请保存）：
  $secret

EOF
}
