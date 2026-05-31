#!/usr/bin/env bash
#
# aaPanel HEB 开站工具入口
#
#   sudo bash scripts/aapanel/heb-aapanel.sh help
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cmd="${1:-help}"
shift || true

case "$cmd" in
	help|-h|--help)
		cat <<'EOF'
HEB × aaPanel 开站工具

【场景 A】aaPanel 新建了空站点 + 数据库（推荐）
  1. aaPanel → 网站 → 添加站点（如 ja.hongbotex.com）
  2. aaPanel → 数据库 → 创建并关联到该站点
  3. cp scripts/aapanel/heb-aapanel.env.example scripts/aapanel/heb-aapanel.env
  4. sudo bash scripts/aapanel/heb-aapanel.sh fresh \
       --domain ja.hongbotex.com --locale ja_JP \
       --db-name 库名 --db-user 用户名 --db-pass '密码'
  5. 主站 HEB 分发 → 添加远端站 → Bootstrap

【场景 B】你已经复制了主站整站到各语言目录（当前 hongbotex 情况）
  sudo bash scripts/aapanel/heb-aapanel.sh prep \
    --domain ja.hongbotex.com --locale ja_JP --yes

  批量（ar/fr/ja/ko/ru/vi）：
  sudo bash scripts/aapanel/heb-aapanel.sh prep-all --yes

  诊断各语言站 WordPress 是否就绪：
  sudo bash scripts/aapanel/heb-aapanel.sh check

  prep 后同步管理员密码（需在 heb-aapanel.env 设置 WP_ADMIN_PASS）：
  sudo bash scripts/aapanel/heb-aapanel.sh prep --domain ja.hongbotex.com --locale ja_JP --sync-admin --yes

【依赖】服务器需安装 WP-CLI：
  curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp

【配置】heb-aapanel.env 中设置 MAIN_DOMAIN=www.hongbotex.com
EOF
		;;
	fresh|new)
		exec bash "$SCRIPT_DIR/heb-new-lang-site.sh" "$@" ;;
	prep)
		exec bash "$SCRIPT_DIR/heb-prep-cloned-site.sh" "$@" ;;
	prep-all)
		exec bash "$SCRIPT_DIR/heb-prep-cloned-site.sh" prep-all "$@" ;;
	check)
		# shellcheck source=lib/common.sh
		source "$SCRIPT_DIR/lib/common.sh"
		load_env
		echo "主站:"
		diagnose_wp_root "$(site_path "$MAIN_DOMAIN")" || true
		echo ""
		for entry in "${LANG_SITES[@]}"; do
			d="${entry%%:*}"
			echo "--- $d ---"
			diagnose_wp_root "$(site_path "$d")" || true
			echo ""
		done
		;;
	*)
		echo "未知命令: $cmd（用 help 查看）" >&2
		exit 1
		;;
esac
