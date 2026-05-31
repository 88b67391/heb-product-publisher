<?php
/**
 * Hub 端：导航菜单（nav_menu）分发管线。
 *
 * 难点：菜单项 (nav_menu_item post) 里存的是本地 object_id（指向 post / term ID），
 * 直接推到子站没意义。这里把每个 menu_item 解析成 "object 引用"：
 *   { type: post_type|taxonomy|custom, subtype, source_id, source_site, url }
 * 子站收到后按 source_id 反查本地 post / term，找到就替换，找不到就退化为 custom URL。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Menu_Sync {

	const META_MENU_SOURCE_ID   = '_heb_pp_menu_source_id';
	const META_MENU_SOURCE_SITE = '_heb_pp_menu_source_site';

	/**
	 * 构造单个 menu 的分发 payload（未翻译）。
	 *
	 * @param int $menu_id Nav menu term id.
	 * @return array<string,mixed> Payload or empty.
	 */
	public static function build_payload( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu instanceof \WP_Term ) {
			return [];
		}
		$items = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
		if ( ! is_array( $items ) ) {
			$items = [];
		}

		$source_site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$source_host = $source_site;

		$out_items = [];
		foreach ( $items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				continue;
			}
			// Menu item 的关键元数据全在 post_meta 里。
			$type      = (string) get_post_meta( $item->ID, '_menu_item_type', true );          // post_type / taxonomy / custom
			$object    = (string) get_post_meta( $item->ID, '_menu_item_object', true );        // post type slug 或 taxonomy slug
			$object_id = (int) get_post_meta( $item->ID, '_menu_item_object_id', true );
			$parent    = (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true );
			$target    = (string) get_post_meta( $item->ID, '_menu_item_target', true );
			$xfn       = (string) get_post_meta( $item->ID, '_menu_item_xfn', true );
			$classes   = (array) get_post_meta( $item->ID, '_menu_item_classes', true );
			$url       = (string) get_post_meta( $item->ID, '_menu_item_url', true );

			$resolved_url = $url;
			if ( '' === $resolved_url ) {
				if ( 'post_type' === $type && $object_id > 0 ) {
					$resolved_url = (string) get_permalink( $object_id );
				} elseif ( 'taxonomy' === $type && '' !== $object && $object_id > 0 ) {
					$link = get_term_link( $object_id, $object );
					if ( ! is_wp_error( $link ) ) {
						$resolved_url = (string) $link;
					}
				}
			}

			$out_items[] = [
				'source_id'        => (int) $item->ID,
				'source_parent_id' => $parent,
				'title'            => (string) $item->title,
				'description'      => (string) $item->description,
				'attr_title'       => (string) $item->post_excerpt,
				'menu_order'       => (int) $item->menu_order,
				'object_type'      => $type,           // post_type / taxonomy / custom
				'object_subtype'   => $object,         // 具体 post type / taxonomy
				'object_source_id' => $object_id,
				'object_source_site' => $source_host,
				'url'              => $resolved_url,
				'target'           => $target,
				'xfn'              => $xfn,
				'classes'          => array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) ),
			];
		}

		$locations = self::get_theme_locations_for_menu( $menu->term_id );

		return [
			'source_menu_id' => (int) $menu->term_id,
			'source_site'    => $source_site,
			'source_locale'  => Heb_Product_Publisher_Admin_Settings::source_locale(),
			'name'           => (string) $menu->name,
			'slug'           => (string) $menu->slug,
			'description'    => (string) $menu->description,
			'locations'      => $locations,
			'items'          => $out_items,
		];
	}

	/**
	 * @param int $menu_id Menu id.
	 * @return array<int,string>
	 */
	private static function get_theme_locations_for_menu( $menu_id ) {
		$out  = [];
		$locs = (array) get_nav_menu_locations();
		foreach ( $locs as $loc => $assigned ) {
			if ( (int) $assigned === (int) $menu_id ) {
				$out[] = (string) $loc;
			}
		}
		return $out;
	}

	/**
	 * 翻译 payload 里所有 title / description / attr_title / menu name。
	 *
	 * @param array<string,mixed>              $payload    Source payload.
	 * @param string                           $src_locale Source locale.
	 * @param string                           $dst_locale Target locale.
	 * @param Heb_Product_Publisher_Translator $translator Translator.
	 * @return array{payload: array<string,mixed>, stats: array<string,mixed>, errors: array<int,string>}
	 */
	public function translate_payload( array $payload, $src_locale, $dst_locale, Heb_Product_Publisher_Translator $translator ) {
		$stats  = [ 'strings' => 0, 'translated' => 0, 'batches' => 0 ];
		$errors = [];

		if ( '' === trim( (string) $dst_locale ) || Heb_Product_Publisher_Translator::same_language( $src_locale, $dst_locale ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		// 抽取需要翻译的字段，过翻译器，再回填。
		$sub = [
			'name'        => isset( $payload['name'] ) ? (string) $payload['name'] : '',
			'description' => isset( $payload['description'] ) ? (string) $payload['description'] : '',
			'items'       => [],
		];
		foreach ( $payload['items'] as $i => $item ) {
			$sub['items'][ $i ] = [
				'title'       => isset( $item['title'] ) ? (string) $item['title'] : '',
				'description' => isset( $item['description'] ) ? (string) $item['description'] : '',
				'attr_title'  => isset( $item['attr_title'] ) ? (string) $item['attr_title'] : '',
			];
		}

		$result = $translator->translate_payload( $sub, $src_locale, $dst_locale );
		$stats  = array_merge( $stats, $result['stats'] );
		$errors = array_merge( $errors, $result['errors'] );

		if ( isset( $result['payload']['name'] ) ) {
			$payload['name'] = (string) $result['payload']['name'];
		}
		if ( isset( $result['payload']['description'] ) ) {
			$payload['description'] = (string) $result['payload']['description'];
		}
		if ( isset( $result['payload']['items'] ) && is_array( $result['payload']['items'] ) ) {
			foreach ( $result['payload']['items'] as $i => $t ) {
				if ( isset( $payload['items'][ $i ] ) ) {
					$payload['items'][ $i ]['title']       = (string) ( $t['title'] ?? $payload['items'][ $i ]['title'] );
					$payload['items'][ $i ]['description'] = (string) ( $t['description'] ?? $payload['items'][ $i ]['description'] );
					$payload['items'][ $i ]['attr_title']  = (string) ( $t['attr_title'] ?? $payload['items'][ $i ]['attr_title'] );
				}
			}
		}

		return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
	}

	/**
	 * 单菜单 → 单站点分发。
	 *
	 * @param int                              $menu_id       Menu id.
	 * @param array<string,mixed>              $basepayload   Built payload.
	 * @param string                           $source_locale Source locale.
	 * @param array<string,string>             $site          Remote site config.
	 * @param Heb_Product_Publisher_Translator $translator    Translator.
	 * @return array<string,mixed>
	 */
	public function distribute_to_site( $menu_id, array $basepayload, $source_locale, array $site, Heb_Product_Publisher_Translator $translator, $bind_theme_locations = true ) {
		$started = microtime( true );
		$sid     = isset( $site['id'] ) ? (string) $site['id'] : '';
		$label   = isset( $site['label'] ) ? (string) $site['label'] : $sid;

		$target_locale = isset( $site['locale_override'] ) && '' !== $site['locale_override']
			? (string) $site['locale_override']
			: '';
		if ( '' === $target_locale ) {
			$info = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [], 15 );
			if ( is_wp_error( $info ) ) {
				return [
					'ok'          => false,
					'message'     => $info->get_error_message(),
					'site_id'     => $sid,
					'site_label'  => $label,
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				];
			}
			$target_locale = isset( $info['locale'] ) ? (string) $info['locale'] : '';
		}

		$translated = $this->translate_payload( $basepayload, $source_locale, $target_locale, $translator );
		$payload    = $translated['payload'];
		$errors     = $translated['errors'];

		$strict_abort = Heb_Product_Publisher_Translator::strict_abort_reason( $errors );
		if ( null !== $strict_abort ) {
			return [
				'ok'          => false,
				'message'     => $strict_abort,
				'site_id'     => $sid,
				'site_label'  => $label,
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			];
		}

		$payload['target_url_host']      = wp_parse_url( $site['url'], PHP_URL_HOST );
		$payload['bind_theme_locations'] = (bool) $bind_theme_locations;

		$timeout = Heb_Product_Publisher_Admin_Settings::site_timeout( $site );
		$res     = Heb_Product_Publisher_Remote_Client::post( $site, '/import-menu', $payload, $timeout );

		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return [
				'ok'          => false,
				'message'     => $res->get_error_message(),
				'site_id'     => $sid,
				'site_label'  => $label,
				'errors'      => $errors,
				'duration_ms' => $elapsed_ms,
			];
		}
		return [
			'ok'             => true,
			'site_id'        => $sid,
			'site_label'     => $label,
			'remote_menu_id' => isset( $res['menu_id'] ) ? (int) $res['menu_id'] : 0,
			'items_imported' => isset( $res['items_imported'] ) ? (int) $res['items_imported'] : 0,
			'errors'         => $errors,
			'duration_ms'    => $elapsed_ms,
		];
	}
}
