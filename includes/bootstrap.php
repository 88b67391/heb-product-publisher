<?php
/**
 * HEB Product Publisher 引导文件。
 *
 * 单插件双角色（自 v2.7.0 起显式声明，参见 `Heb_Product_Publisher_Admin_Settings::site_role()`）：
 *  - Hub 端（主站）：在产品/页面编辑页选择目标站点 → OpenRouter 翻译 → 推送 /import-product
 *  - Receiver 端（语言站）：暴露 /site-info（locale + taxonomies）与 /import-product 等
 *  - Auto：按配置自动推断（向后兼容老安装）
 *
 * 可在 wp-config.php 中预定义（优先级高于后台选项）：
 *   define( 'HEB_PUBLISHER_RECEIVER_SECRET', '...' );      // 本站作为接收端时的共享密钥
 *   define( 'HEB_PP_OPENROUTER_API_KEY',    'sk-or-...' ); // OpenRouter key
 *   define( 'HEB_PP_SITE_ROLE',             'hub' );       // 强制锁定角色：hub / receiver / auto
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-sync.php';
require_once __DIR__ . '/class-receiver.php';
require_once __DIR__ . '/class-site-info.php';
require_once __DIR__ . '/class-translator.php';
require_once __DIR__ . '/class-remote-client.php';
require_once __DIR__ . '/class-admin-settings.php';
require_once __DIR__ . '/class-hub-ui.php';
require_once __DIR__ . '/class-updater.php';
require_once __DIR__ . '/class-log.php';
require_once __DIR__ . '/class-log-admin.php';
require_once __DIR__ . '/class-product-columns.php';
require_once __DIR__ . '/class-bulk.php';
require_once __DIR__ . '/class-hreflang.php';
require_once __DIR__ . '/class-page-lang-map.php';
require_once __DIR__ . '/class-post-lock.php';
require_once __DIR__ . '/class-term-sync.php';
require_once __DIR__ . '/class-term-hub-ui.php';
require_once __DIR__ . '/class-term-redirect.php';
require_once __DIR__ . '/class-menu-sync.php';
require_once __DIR__ . '/class-settings-sync.php';
require_once __DIR__ . '/class-bootstrap-status.php';
require_once __DIR__ . '/class-bootstrap-queue.php';
require_once __DIR__ . '/class-bootstrap-worker.php';
require_once __DIR__ . '/class-bootstrap-tool.php';
require_once __DIR__ . '/class-distribution-dashboard.php';
require_once __DIR__ . '/class-delete-cascade.php';

// 角色无关：任何站点都需要这些（hreflang 输出、设置 UI、更新检查、单页 hreflang 手填、
// 日志查看、term 旧 slug 301 重定向）。
Heb_Product_Publisher_Admin_Settings::instance();
Heb_Product_Publisher_Updater::instance();
Heb_Product_Publisher_Hreflang::instance();
Heb_Product_Publisher_Page_Lang_Map::instance();
Heb_Product_Publisher_Log_Admin::instance();
Heb_Product_Publisher_Term_Redirect::instance();

// Receiver 模式：注册接收端 REST 路由 + /site-info + 子站本地锁定 UI。
if ( Heb_Product_Publisher_Admin_Settings::is_receiver_mode() ) {
	Heb_Product_Publisher_Receiver::instance();
	Heb_Product_Publisher_Site_Info::instance();
	Heb_Product_Publisher_Post_Lock::instance();
}

// Hub 模式：分发 metabox、批量分发、产品列表列、term 分发 UI、Bootstrap、Dashboard、删除级联。
if ( Heb_Product_Publisher_Admin_Settings::is_hub_mode() ) {
	Heb_Product_Publisher_Hub_UI::instance();
	Heb_Product_Publisher_Product_Columns::instance();
	Heb_Product_Publisher_Bulk::instance();
	Heb_Product_Publisher_Term_Hub_UI::instance();
	Heb_Product_Publisher_Bootstrap_Tool::instance();
	Heb_Product_Publisher_Bootstrap_Worker::instance();
	Heb_Product_Publisher_Distribution_Dashboard::instance();
	Heb_Product_Publisher_Delete_Cascade::instance();
}

// 兜底：首次启用后访问管理页自动建表。
add_action(
	'admin_init',
	static function () {
		if ( class_exists( 'Heb_Product_Publisher_Log' ) && ! Heb_Product_Publisher_Log::table_exists() ) {
			Heb_Product_Publisher_Log::install();
		}
	}
);
