<?php
/**
 * HEB 插件后台菜单：统一顶级「HEB 分发」，避免散落在「工具」下。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Admin_Menu {

	const PARENT_SLUG = 'heb-product-publisher';

	/**
	 * @param string $page_slug Submenu slug (defaults to settings page).
	 * @return string
	 */
	public static function url( $page_slug = self::PARENT_SLUG ) {
		return admin_url( 'admin.php?page=' . rawurlencode( (string) $page_slug ) );
	}

	/**
	 * @param string $page_slug Page slug.
	 * @return string Expected $hook_suffix for admin_enqueue_scripts.
	 */
	public static function hook_suffix( $page_slug ) {
		$page_slug = (string) $page_slug;
		if ( self::PARENT_SLUG === $page_slug ) {
			return 'toplevel_page_' . self::PARENT_SLUG;
		}
		return self::PARENT_SLUG . '_page_' . $page_slug;
	}
}
