<?php
/**
 * Plugin Name:       HEB Product Publisher
 * Plugin URI:        https://github.com/88b67391/heb-product-publisher
 * Description:       一体化产品分发插件：同一插件在主站作为 Hub（翻译 + 分发），在语言站作为 Receiver（接收推送、暴露站点信息）。翻译通过 OpenRouter，升级通过 GitHub Releases 自动推送。2.5 新增 hreflang；2.6 默认支持 solutions + 安全加固；2.6.1 attachment 去重 + timeout 可配置 + 超时假失败救回；2.7 显式站点角色；3.0-alpha.1 单页 + Elementor 完整分发管线 + 子站锁定。
 * Version:           3.0.0-alpha.1
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

define( 'HEB_PP_VERSION', '3.0.0-alpha.1' );
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
 * 允许被分发的 post type（默认 products + solutions + page），可通过过滤器
 * `heb_pp_distributable_post_types` 扩展或剔除。
 *
 * 默认包含 `page`：v3.0 起单页（about / contact 之类）也走分发管线，含 Elementor
 * 数据完整克隆 + AI 翻译；不希望分发的页面可以单独锁定（_heb_pp_locked meta）或
 * 用 filter 把 page 整体踢出去。
 *
 * 返回值会被规范化为有效 slug 数组（清洗 + 去重 + 仅保留字符串）。
 *
 * @return array<int,string>
 */
function heb_pp_distributable_post_types() {
	$raw = (array) apply_filters( 'heb_pp_distributable_post_types', [ 'products', 'solutions', 'page' ] );
	$out = [];
	foreach ( $raw as $pt ) {
		if ( ! is_string( $pt ) ) {
			continue;
		}
		$pt = sanitize_key( $pt );
		if ( '' === $pt ) {
			continue;
		}
		$out[ $pt ] = true;
	}
	return array_keys( $out );
}

function heb_pp_load_textdomain() {
	load_plugin_textdomain( 'heb-product-publisher', false, dirname( plugin_basename( HEB_PP_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'heb_pp_load_textdomain' );

require_once HEB_PP_PATH . 'includes/bootstrap.php';
