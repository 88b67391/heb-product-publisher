#!/usr/bin/env bash
#
# aaPanel 新建语言站（fresh 模式）
#
# 前置（在 aaPanel 面板完成，约 2 分钟）：
#   1. 网站 → 添加站点 → 域名如 ja.hongbotex.com（只建站点即可）
#   2. 数据库 → 添加数据库 → 记下库名/用户名/密码
#   3. 网站 → 该站点 → 数据库 → 关联刚建的数据库
#
# 用法：
#   cp scripts/aapanel/heb-aapanel.env.example scripts/aapanel/heb-aapanel.env
#   # 编辑 heb-aapanel.env
#   sudo bash scripts/aapanel/heb-new-lang-site.sh \
#     --domain ja.hongbotex.com \
#     --locale ja_JP \
#     --db-name sql_ja_xxx \
#     --db-user sql_ja_xxx \
#     --db-pass 'your-db-password'
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

load_env

DOMAIN=""
LOCALE=""
DB_NAME=""
DB_USER=""
DB_PASS=""
SITE_URL=""
SKIP_UPLOADS=0

usage() {
	sed -n '2,22p' "$0"
	exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--domain) DOMAIN="$2"; shift 2 ;;
		--locale) LOCALE="$2"; shift 2 ;;
		--db-name) DB_NAME="$2"; shift 2 ;;
		--db-user) DB_USER="$2"; shift 2 ;;
		--db-pass) DB_PASS="$2"; shift 2 ;;
		--url) SITE_URL="$2"; shift 2 ;;
		--skip-uploads) SKIP_UPLOADS=1; shift ;;
		-h|--help) usage 0 ;;
		*) die "未知参数: $1（用 --help 查看）" ;;
	esac
done

[[ -n "$DOMAIN" ]] || die "缺少 --domain"
[[ -n "$LOCALE" ]] || die "缺少 --locale"
[[ -n "$DB_NAME" ]] || die "缺少 --db-name"
[[ -n "$DB_USER" ]] || die "缺少 --db-user"
[[ -n "$DB_PASS" ]] || die "缺少 --db-pass"

SITE_URL="${SITE_URL:-https://$DOMAIN}"
TARGET="$(site_path "$DOMAIN")"
MAIN_PATH="$(ensure_main_site)"
SECRET="${HEB_RECEIVER_SECRET:-$(rand_secret)}"
ADMIN_PASS="${WP_ADMIN_PASS:-$(rand_secret)}"

if [[ -e "$TARGET/wp-config.php" ]]; then
	die "目标目录已有 WordPress（$TARGET/wp-config.php）。若是从主站复制的，请用 heb-prep-cloned-site.sh"
fi

mkdir -p "$TARGET"

info "主站：$MAIN_PATH"
info "目标：$TARGET"
info "同步 WordPress 核心（不含 wp-content / wp-config）…"
rsync -a --delete \
	--exclude 'wp-content/' \
	--exclude 'wp-config.php' \
	--exclude '.user.ini' \
	--exclude '.htaccess' \
	"$MAIN_PATH/" "$TARGET/"

info "同步 wp-content（主题/插件/上传）…"
RSYNC_EX=(--exclude 'cache/' --exclude 'upgrade/' --exclude 'backup*/' --exclude 'ai1wm-backups/')
if [[ "$SKIP_UPLOADS" -eq 1 ]]; then
	RSYNC_EX+=(--exclude 'uploads/')
fi
rsync -a "${RSYNC_EX[@]}" "$MAIN_PATH/wp-content/" "$TARGET/wp-content/"

info "创建 wp-config.php …"
wp_run "$TARGET" config create \
	--dbname="$DB_NAME" \
	--dbuser="$DB_USER" \
	--dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" \
	--dbprefix="$TABLE_PREFIX" \
	--skip-check \
	--force

append_wp_config_constants "$TARGET" "$SECRET"

info "安装 WordPress（占位标题，Bootstrap 会 AI 覆盖）…"
wp_run "$TARGET" core install \
	--url="$SITE_URL" \
	--title="Language Site ($DOMAIN)" \
	--admin_user="$WP_ADMIN_USER" \
	--admin_password="$ADMIN_PASS" \
	--admin_email="$WP_ADMIN_EMAIL" \
	--skip-email

install_core_language "$TARGET" "$LOCALE"
activate_heb_plugins "$TARGET"

# 关闭主站 Hub 相关选项（Receiver 站不应当 Hub 用）
wp_run "$TARGET" option update heb_pp_site_role receiver 2>/dev/null || true

fix_permissions "$TARGET"

info "管理员：$WP_ADMIN_USER / $ADMIN_PASS"

print_next_steps "$DOMAIN" "$LOCALE" "$SECRET" "$SITE_URL"
