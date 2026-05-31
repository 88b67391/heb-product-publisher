#!/usr/bin/env bash
# shellcheck disable=SC2034
# aaPanel / HEB 开站脚本公共函数

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AAPANEL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# 持久化路径：WordPress 更新插件会整目录替换，勿把 env 放在 plugins/ 内。
HEB_AAPANEL_ENV_SYSTEM="${HEB_AAPANEL_ENV_SYSTEM:-/etc/heb-aapanel.env}"
HEB_AAPANEL_ENV_LEGACY="$AAPANEL_DIR/heb-aapanel.env"

# 解析 env 路径：HEB_AAPANEL_ENV > /etc/heb-aapanel.env > 插件内旧路径（自动迁移）
resolve_env_file() {
	if [[ -n "${HEB_AAPANEL_ENV:-}" ]]; then
		echo "${HEB_AAPANEL_ENV}"
		return
	fi
	if [[ -f "$HEB_AAPANEL_ENV_SYSTEM" ]]; then
		echo "$HEB_AAPANEL_ENV_SYSTEM"
		return
	fi
	if [[ -f "$HEB_AAPANEL_ENV_LEGACY" ]]; then
		migrate_env_to_system "$HEB_AAPANEL_ENV_LEGACY"
		echo "$HEB_AAPANEL_ENV_SYSTEM"
		return
	fi
	echo "$HEB_AAPANEL_ENV_SYSTEM"
}

migrate_env_to_system() {
	local legacy="$1"
	info "迁移配置：$legacy → $HEB_AAPANEL_ENV_SYSTEM（避免插件更新丢失）"
	cp "$legacy" "$HEB_AAPANEL_ENV_SYSTEM"
	chmod 600 "$HEB_AAPANEL_ENV_SYSTEM" 2>/dev/null || true
	warn "以后请只编辑 $HEB_AAPANEL_ENV_SYSTEM"
}

init_env_file() {
	if [[ -f "$HEB_AAPANEL_ENV_SYSTEM" ]]; then
		die "配置文件已存在：$HEB_AAPANEL_ENV_SYSTEM"
	fi
	[[ -f "$AAPANEL_DIR/heb-aapanel.env.example" ]] || die "缺少 heb-aapanel.env.example"
	cp "$AAPANEL_DIR/heb-aapanel.env.example" "$HEB_AAPANEL_ENV_SYSTEM"
	chmod 600 "$HEB_AAPANEL_ENV_SYSTEM" 2>/dev/null || true
	info "已创建 $HEB_AAPANEL_ENV_SYSTEM"
	echo "请编辑该文件后运行：sudo bash heb-aapanel.sh check"
}

load_env() {
	local env_file
	env_file="$(resolve_env_file)"
	if [[ ! -f "$env_file" ]]; then
		die "缺少配置文件 $env_file。首次使用请运行：sudo bash heb-aapanel.sh init-env"
	fi
	if [[ "$env_file" == *heb-aapanel.env.example ]]; then
		die "请勿直接使用 .env.example，请运行：sudo bash heb-aapanel.sh init-env"
	fi
	validate_env_file "$env_file"
	set +u
	# shellcheck disable=SC1090
	source "$env_file"
	set -u

	WWW_ROOT="${WWW_ROOT:-/www/wwwroot}"
	MAIN_DOMAIN="${MAIN_DOMAIN:-www.hongbotex.com}"
	MAIN_URL="${MAIN_URL:-https://$MAIN_DOMAIN}"
	DB_HOST="${DB_HOST:-localhost}"
	TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
	WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
	WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
	HUB_URL="${HUB_URL:-https://$MAIN_DOMAIN/wp-admin}"
}

# 双引号 env 值里的 $9、$HOME 等会被 bash 展开，导致报错或密码被截断。
validate_env_file() {
	local f="$1"
	if grep -qE '^[A-Za-z_][A-Za-z0-9_]*="[^"]*\$' "$f" 2>/dev/null; then
		die "heb-aapanel.env 中双引号内的 \$ 会被 bash 展开（例如 \$9 → 空）。请改用单引号，例如：WP_ADMIN_PASS='你的密码'"
	fi
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

warn() {
	echo "警告: $*" >&2
}

# 诊断目录是否像 WordPress 根目录
diagnose_wp_root() {
	local path="$1"
	echo "目录: $path"
	if [[ ! -d "$path" ]]; then
		echo "  ✗ 目录不存在（请先在 aaPanel → 网站 里添加该域名）"
		return 1
	fi
	local ok=1
	[[ -f "$path/wp-config.php" ]] && echo "  ✓ wp-config.php" || { echo "  ✗ 缺少 wp-config.php"; ok=0; }
	[[ -f "$path/wp-load.php" ]] && echo "  ✓ wp-load.php" || { echo "  ✗ 缺少 wp-load.php"; ok=0; }
	[[ -d "$path/wp-content" ]] && echo "  ✓ wp-content/" || echo "  · 无 wp-content/"
	[[ -d "$path/wp-admin" ]] && echo "  ✓ wp-admin/" || echo "  · 无 wp-admin/"
	if [[ "$ok" -eq 0 ]]; then
		echo ""
		echo "  该目录还不是完整 WordPress。可选："
		echo "  A) aaPanel 已建站点+数据库 → 用 fresh 模式："
		echo "     bash heb-aapanel.sh fresh --domain DOMAIN --locale LOCALE --db-name ... --db-user ... --db-pass ..."
		echo "  B) 已从主站复制整站 → 确认 wp-config.php 也在该目录后再 prep"
		return 1
	fi
	return 0
}

assert_wp_root() {
	local path="$1"
	if [[ -f "$path/wp-config.php" && -f "$path/wp-load.php" ]]; then
		return 0
	fi
	echo "错误: 不是 WordPress 目录：$path" >&2
	diagnose_wp_root "$path" >&2 || true
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

# 与主站对齐：子主题 + Elementor + ACF（products/solutions CPT 依赖 ACF 结构配置）
activate_site_stack() {
	local path="$1"
	local main_path
	main_path="$(ensure_main_site)"
	local theme
	theme="$(wp_run "$main_path" option get stylesheet 2>/dev/null || echo hello-elementor-child)"
	wp_run "$path" theme activate "$theme" 2>/dev/null || wp_run "$path" theme activate hello-elementor-child 2>/dev/null || true
	for plugin in elementor advanced-custom-fields-pro advanced-custom-fields; do
		wp_run "$path" plugin activate "$plugin" 2>/dev/null || true
	done
	activate_heb_plugins "$path"
}

# prep 误删 ACF 结构后，从主站重新导入 CPT/字段组定义
repair_structural_config() {
	local target="$1"
	local main_path bundle copy_script n_pt n_fg n_tx result
	main_path="$(ensure_main_site)"
	copy_script="$SCRIPT_DIR/copy-acf-structure.php"
	[[ -f "$copy_script" ]] || die "缺少 $copy_script"

	info "激活与主站一致的插件/主题…"
	activate_site_stack "$target"

	n_pt="$(wp_run "$main_path" post list --post_type=acf-post-type --post_status=any --format=count 2>/dev/null || echo 0)"
	n_fg="$(wp_run "$main_path" post list --post_type=acf-field-group --post_status=any --format=count 2>/dev/null || echo 0)"
	n_tx="$(wp_run "$main_path" post list --post_type=acf-taxonomy --post_status=any --format=count 2>/dev/null || echo 0)"
	info "主站 ACF 结构：post-type=${n_pt} field-group=${n_fg} taxonomy=${n_tx}"
	if [[ "${n_pt:-0}" -eq 0 && "${n_fg:-0}" -eq 0 && "${n_tx:-0}" -eq 0 ]]; then
		die "主站未找到 ACF 结构 post。请确认 ACF Pro 已激活，且 Post Types / Field Groups 在后台可见。"
	fi

	bundle="$(mktemp)"
	info "从主站导出 ACF 结构…"
	if ! wp_run "$main_path" eval-file "$copy_script" export >"$bundle" 2>/dev/null; then
		rm -f "$bundle"
		die "导出失败。请确认主站 WP-CLI 可正常运行。"
	fi
	if [[ ! -s "$bundle" ]] || [[ "$(tr -d '[:space:]' <"$bundle")" == "[]" ]]; then
		rm -f "$bundle"
		die "导出结果为空。请检查主站 ACF 插件是否激活。"
	fi

	info "导入结构配置到 $(basename "$target") …"
	result="$(wp_run "$target" eval-file "$copy_script" import <"$bundle" 2>/dev/null || true)"
	rm -f "$bundle"
	if [[ -n "$result" ]]; then
		info "导入结果：$result"
	fi

	wp_run "$target" rewrite flush 2>/dev/null || true

	if wp_run "$target" post-type list --field=name 2>/dev/null | grep -qx 'products'; then
		info "✓ products post type 已恢复"
	else
		warn "products 仍未注册，请确认子站 ACF Pro 已激活且 import 无报错"
		return 1
	fi
	return 0
}

check_distributable_post_types() {
	local path="$1"
	local label="${2:-}"
	local missing=()
	local pt
	for pt in products solutions page; do
		if ! wp_run "$path" post-type list --field=name 2>/dev/null | grep -qx "$pt"; then
			missing+=("$pt")
		fi
	done
	if [[ ${#missing[@]} -gt 0 ]]; then
		echo "  ✗ 缺少 post type: ${missing[*]}${label:+ ($label)}"
		echo "    → 运行: sudo bash heb-aapanel.sh repair-config --domain $(basename "$path")"
		return 1
	fi
	echo "  ✓ post types: products, solutions, page"
	return 0
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

# 若 heb-aapanel.env 设置了 WP_ADMIN_PASS，同步管理员密码（prep 不改账号，除非显式 --sync-admin）
sync_admin_from_env() {
	local path="$1"
	local user pass email
	user="${WP_ADMIN_USER:-admin}"
	pass="${WP_ADMIN_PASS:-}"
	email="${WP_ADMIN_EMAIL:-admin@example.com}"
	if [[ -z "$pass" ]]; then
		warn "未设置 WP_ADMIN_PASS，跳过管理员密码同步"
		return 0
	fi
	if wp_run "$path" user get "$user" --field=ID >/dev/null 2>&1; then
		wp_run "$path" user update "$user" --user_pass="$pass" --user_email="$email" 2>/dev/null || true
		info "已更新管理员 $user 密码（来自 env）"
	else
		wp_run "$path" user create "$user" "$email" --role=administrator --user_pass="$pass" 2>/dev/null || true
		info "已创建管理员 $user"
	fi
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
   → 先 Dry run → 再正式跑（全 scope，含 settings + menus）
   → prep 只清空内容，不会自动有产品/页面，必须 Bootstrap

3) 主站 → 分发总览 → 刷新 manifest 验收

Receiver secret（请保存）：
  $secret

EOF
}
