<?php
/**
 * Plugin Name:       HEB Product Publisher
 * Plugin URI:        https://github.com/88b67391/heb-product-publisher
 * Description:       一体化产品分发插件：同一插件在主站作为 Hub（翻译 + 分发），在语言站作为 Receiver（接收推送、暴露站点信息）。翻译通过 OpenRouter，升级通过 GitHub Releases 自动推送。2.7 显式站点角色；3.0-alpha.1 单页 + Elementor；3.0-alpha.2 term 分发 + AI 本地化 slug + term archive hreflang + 旧 slug 301。
 * Version:           3.0.0-alpha.2
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

define( 'HEB_PP_VERSION', '3.0.0-alpha.2' );
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

/**
 * 允许被分发的 taxonomy（默认 = 所有 distributable post type 关联的 taxonomy）。
 *
 * 可通过 filter `heb_pp_distributable_taxonomies` 调整。一般是 product-categories /
 * solutions-category 等；page 通常没有 taxonomy，所以白名单基本只含 products / solutions
 * 的分类。
 *
 * @return array<int,string>
 */
function heb_pp_distributable_taxonomies() {
	$taxes = [];
	foreach ( heb_pp_distributable_post_types() as $pt ) {
		if ( ! post_type_exists( $pt ) ) {
			continue;
		}
		foreach ( (array) get_object_taxonomies( $pt ) as $tx ) {
			$tx = sanitize_key( (string) $tx );
			if ( '' === $tx ) {
				continue;
			}
			// 跳过 WP 内置非自定义分类（避免误分发普通博客 category / post_tag）。
			if ( in_array( $tx, [ 'category', 'post_tag', 'post_format' ], true ) ) {
				continue;
			}
			$taxes[ $tx ] = true;
		}
	}
	$out = (array) apply_filters( 'heb_pp_distributable_taxonomies', array_keys( $taxes ) );
	$ret = [];
	foreach ( $out as $tx ) {
		if ( ! is_string( $tx ) ) {
			continue;
		}
		$tx = sanitize_key( $tx );
		if ( '' === $tx || ! taxonomy_exists( $tx ) ) {
			continue;
		}
		$ret[ $tx ] = true;
	}
	return array_keys( $ret );
}

function heb_pp_load_textdomain() {
	load_plugin_textdomain( 'heb-product-publisher', false, dirname( plugin_basename( HEB_PP_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'heb_pp_load_textdomain' );

require_once HEB_PP_PATH . 'includes/bootstrap.php';
