<?php
/**
 * HEB Product Publisher 引导文件。
 *
 * 单插件双角色：
 *  - Hub 端（主站）：在产品编辑页选择目标站点 → OpenRouter 翻译 → 推送 /import-product
 *  - Receiver 端（语言站）：暴露 /site-info（locale + taxonomies）与 /import-product
 *
 * 可在 wp-config.php 中预定义（优先级高于后台选项）：
 *   define( 'HEB_PUBLISHER_RECEIVER_SECRET', '...' );   // 本站作为接收端时的共享密钥
 *   define( 'HEB_PP_OPENROUTER_API_KEY',    'sk-or-...' ); // OpenRouter key
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

Heb_Product_Publisher_Receiver::instance();
Heb_Product_Publisher_Site_Info::instance();
Heb_Product_Publisher_Admin_Settings::instance();
Heb_Product_Publisher_Hub_UI::instance();
Heb_Product_Publisher_Updater::instance();
