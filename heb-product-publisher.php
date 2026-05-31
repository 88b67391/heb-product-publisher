<?php
/**
 * Plugin Name:       HEB Product Publisher
 * Plugin URI:        https://github.com/88b67391/heb-product-publisher
 * Description:       一体化产品分发插件：Hub/Receiver 双角色，OpenRouter 翻译，GitHub Releases 自动升级。3.0 完整功能集 + v3.1 Elementor 图片异步 sideload + v3.1.0-alpha.7 设置页加翻译模型快捷选择按钮（推荐 Gemini Flash / GPT-4o-mini / Claude Haiku / DeepSeek / Gemini Pro / Claude Sonnet），点一下自动填入，不用再手敲模型 ID。
 * Version:           3.3.0-beta.15
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

define( 'HEB_PP_FILE', __FILE__ );
define( 'HEB_PP_PATH', plugin_dir_path( __FILE__ ) );
define( 'HEB_PP_URL', plugin_dir_url( __FILE__ ) );

/**
 * 从 plugin header 动态读取 Version，避免常量与 header 不同步导致
 * Updater 误判（v3.1.0-alpha.2 之前因为忘记同步常量，导致"刚升级又提示
 * 同版本更新"的死循环）。
 *
 * 使用 get_file_data 而非 get_plugin_data：后者必须在 admin 环境下且
 * 会触发 plugin headers 翻译，太重；这里只读一个字段足够。
 */
if ( ! defined( 'HEB_PP_VERSION' ) ) {
	$heb_pp_version = '';
	if ( function_exists( 'get_file_data' ) ) {
		$heb_pp_header  = get_file_data( __FILE__, [ 'Version' => 'Version' ], 'plugin' );
		$heb_pp_version = isset( $heb_pp_header['Version'] ) ? (string) $heb_pp_header['Version'] : '';
		unset( $heb_pp_header );
	}
	if ( '' === $heb_pp_version ) {
		// 极端兜底：手动从 plugin header 解析（首次加载早于 get_file_data 时）。
		$heb_pp_head = @file_get_contents( __FILE__, false, null, 0, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_string( $heb_pp_head ) && preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $heb_pp_head, $m ) ) {
			$heb_pp_version = trim( $m[1] );
		}
		unset( $heb_pp_head, $m );
	}
	define( 'HEB_PP_VERSION', '' !== $heb_pp_version ? $heb_pp_version : '0.0.0' );
	unset( $heb_pp_version );
}

register_activation_hook(
	__FILE__,
	static function () {
		require_once HEB_PP_PATH . 'includes/class-log.php';
		Heb_Product_Publisher_Log::install();

		// 提前建 Action Scheduler 数据表，避免首次 Bootstrap enqueue 时卡顿。
		$as_main = HEB_PP_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
		if ( file_exists( $as_main ) ) {
			require_once $as_main;
			if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
				try {
					ActionScheduler::store()->init();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}
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
	$raw = (array) apply_filters(
		'heb_pp_distributable_post_types',
		[ 'products', 'solutions', 'page', 'elementor_library' ]
	);
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

/**
 * 载入 Action Scheduler 内置版（v3.0 起 Site Bootstrap 需要异步队列）。
 *
 * AS 用 self-registration：会自动选用版本最高的实例运行（如果 WooCommerce 已经
 * 在用更新的版本，它就接管），所以多个插件同时 bundle 不会冲突。
 *
 * 必须在 `plugins_loaded` 前 require，因为 AS 自己注册的 hook 是 `init` 0。
 */
$heb_pp_as_main = HEB_PP_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $heb_pp_as_main ) ) {
	require_once $heb_pp_as_main;
}
unset( $heb_pp_as_main );

require_once HEB_PP_PATH . 'includes/bootstrap.php';
