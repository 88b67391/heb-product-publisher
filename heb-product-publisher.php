<?php
/**
 * Plugin Name:       HEB Product Publisher
 * Plugin URI:        https://github.com/88b67391/heb-product-publisher
 * Description:       一体化产品分发插件：同一插件在主站作为 Hub（翻译 + 分发），在语言站作为 Receiver（接收推送、暴露站点信息）。翻译通过 OpenRouter，升级通过 GitHub Releases 自动推送。2.3 新增：Yoast SEO 元数据翻译、分发前 diff 预览（复用预览翻译缓存 10 分钟，省 token）。
 * Version:           2.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            HEB
 * License:           GPL-2.0-or-later
 * Text Domain:       heb-product-publisher
 * Update URI:        https://github.com/88b67391/heb-product-publisher
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HEB_PP_VERSION', '2.3.0' );
define( 'HEB_PP_FILE', __FILE__ );
define( 'HEB_PP_PATH', plugin_dir_path( __FILE__ ) );
define( 'HEB_PP_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook(
	__FILE__,
	static function () {
		require_once HEB_PP_PATH . 'includes/class-log.php';
		Heb_Product_Publisher_Log::install();
	}
);

/**
 * 允许被分发的 post type，可通过过滤器 heb_pp_distributable_post_types 扩展。
 *
 * @return array<int,string>
 */
function heb_pp_distributable_post_types() {
	return (array) apply_filters( 'heb_pp_distributable_post_types', [ 'products' ] );
}

function heb_pp_load_textdomain() {
	load_plugin_textdomain( 'heb-product-publisher', false, dirname( plugin_basename( HEB_PP_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'heb_pp_load_textdomain' );

require_once HEB_PP_PATH . 'includes/bootstrap.php';
