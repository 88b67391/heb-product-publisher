<?php
/**
 * Receiver 端：POST /wp-json/heb-publisher/v1/import-product。
 *
 * 更新：taxonomies 使用 slug 数组（跨站点兼容），不存在的 slug 将自动在目标站点创建。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Receiver {

	/** @var self|null */
	private static $instance = null;

	/** @var array<int,string> 当前请求允许 sideload 的来源域名（含 Hub 主站）。 */
	private $sideload_trusted_hosts = [];

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * @return string
	 */
	public static function get_secret() {
		if ( defined( 'HEB_PUBLISHER_RECEIVER_SECRET' ) && is_string( HEB_PUBLISHER_RECEIVER_SECRET ) && '' !== HEB_PUBLISHER_RECEIVER_SECRET ) {
			return HEB_PUBLISHER_RECEIVER_SECRET;
		}
		$s = get_option( 'heb_publisher_receiver_secret', '' );
		return is_string( $s ) ? $s : '';
	}

	public function register_routes() {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return;
		}
		register_rest_route(
			'heb-publisher/v1',
			'/import-product',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_import' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/sync-lang-map',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_sync_lang_map' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/lookup-by-source',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_lookup_by_source' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/import-term',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_import_term' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/sync-term-lang-map',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_sync_term_lang_map' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/import-menu',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_import_menu' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/import-settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_import_settings' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/manifest',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_manifest' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/delete-by-source',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_delete_by_source' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/process-pending-media',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_process_pending_media' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			'heb-publisher/v1',
			'/regenerate-elementor-css',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_regenerate_elementor_css' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * POST /manifest — 子站返回所有"按 source 关联的"对象摘要，Hub Dashboard 用。
	 *
	 * 可选过滤：
	 *  - post_types: array<string> 限制 post types
	 *  - taxonomies: array<string> 限制 taxonomies
	 *  - since:      int 只返回 modified >= since 的对象
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_manifest( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$post_types = isset( $body['post_types'] ) && is_array( $body['post_types'] )
			? array_map( 'sanitize_key', $body['post_types'] )
			: (
				function_exists( 'heb_pp_distributable_post_types' )
					? heb_pp_distributable_post_types()
					: []
			);
		$post_types = array_values( array_intersect( $post_types, self::allowed_post_types() ) );
		$taxonomies = isset( $body['taxonomies'] ) && is_array( $body['taxonomies'] )
			? array_map( 'sanitize_key', $body['taxonomies'] )
			: (
				function_exists( 'heb_pp_distributable_taxonomies' )
					? heb_pp_distributable_taxonomies()
					: []
			);
		$taxonomies = array_values( array_intersect( $taxonomies, self::allowed_taxonomies() ) );
		$since = isset( $body['since'] ) ? (int) $body['since'] : 0;

		$posts_out = [];
		foreach ( $post_types as $pt ) {
			if ( '' === $pt ) {
				continue;
			}
			$q = new \WP_Query(
				[
					'post_type'              => $pt,
					'post_status'            => 'any',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'meta_query'             => [
						[ 'key' => '_heb_publisher_source_post_id', 'compare' => 'EXISTS' ],
					],
				]
			);
			foreach ( $q->posts as $pid ) {
				$src = (int) get_post_meta( $pid, '_heb_publisher_source_post_id', true );
				$src_site = (string) get_post_meta( $pid, '_heb_publisher_source_site', true );
				$modified = (int) get_post_modified_time( 'U', true, $pid );
				if ( $since > 0 && $modified < $since ) {
					continue;
				}
				$media_progress = class_exists( 'Heb_Product_Publisher_Async_Media' )
					? Heb_Product_Publisher_Async_Media::progress( (int) $pid )
					: [ 'pending' => 0, 'status' => 'done', 'last_run' => 0 ];
				$posts_out[] = [
					'post_type'        => $pt,
					'local_id'         => (int) $pid,
					'source_post_id'   => $src,
					'source_site'      => $src_site,
					'modified'         => $modified,
					'locked'           => '1' === (string) get_post_meta( $pid, '_heb_pp_locked', true ),
					'status'           => (string) get_post_status( $pid ),
					'edit_url'         => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
					'permalink'        => (string) get_permalink( $pid ),
					'title'            => (string) get_the_title( $pid ),
					'media_pending'    => (int) $media_progress['pending'],
					'media_status'     => (string) $media_progress['status'],
				];
			}
		}

		$terms_out = [];
		foreach ( $taxonomies as $tx ) {
			if ( '' === $tx || ! taxonomy_exists( $tx ) ) {
				continue;
			}
			$terms = get_terms(
				[
					'taxonomy'   => $tx,
					'hide_empty' => false,
					'meta_query' => [
						[ 'key' => '_heb_pp_source_term_id', 'compare' => 'EXISTS' ],
					],
				]
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$src      = (int) get_term_meta( $term->term_id, '_heb_pp_source_term_id', true );
				$src_site = (string) get_term_meta( $term->term_id, '_heb_pp_source_site', true );
				$link     = get_term_link( $term );
				$terms_out[] = [
					'taxonomy'       => $tx,
					'local_id'       => (int) $term->term_id,
					'source_term_id' => $src,
					'source_site'    => $src_site,
					'name'           => (string) $term->name,
					'slug'           => (string) $term->slug,
					'sync_hash'      => md5( (string) $term->name . '|' . (string) $term->slug ),
					'edit_url'       => admin_url( 'term.php?taxonomy=' . rawurlencode( $tx ) . '&tag_ID=' . $term->term_id ),
					'permalink'      => is_wp_error( $link ) ? '' : (string) $link,
				];
			}
		}

		return rest_ensure_response(
			[
				'success' => true,
				'posts'   => $posts_out,
				'terms'   => $terms_out,
				'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			]
		);
	}

	/**
	 * POST /delete-by-source — 主站删除内容时级联通知子站删除对应本地副本。
	 *
	 * Payload:
	 *  - kind: 'post' | 'term'
	 *  - post_type / taxonomy
	 *  - source_id, source_site
	 *  - force (optional, true = bypass _heb_pp_locked)
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_delete_by_source( $request ) {
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$kind        = isset( $body['kind'] ) ? sanitize_key( (string) $body['kind'] ) : '';
		$source_site = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		$source_id   = isset( $body['source_id'] ) ? (int) $body['source_id'] : 0;
		$force       = ! empty( $body['force'] );

		if ( '' === $kind || '' === $source_site || $source_id <= 0 ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}

		if ( 'post' === $kind ) {
			$post_type = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
			if ( '' === $post_type ) {
				return new \WP_Error( 'heb_pub_bad_payload', __( 'post_type required.', 'heb-product-publisher' ), [ 'status' => 400 ] );
			}
			if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
				return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed.', 'heb-product-publisher' ), [ 'status' => 403 ] );
			}
			$local_id = $this->find_by_source( $post_type, $source_id, $source_site );
			if ( $local_id <= 0 ) {
				return rest_ensure_response( [ 'success' => true, 'deleted' => false, 'reason' => 'not_found' ] );
			}
			if ( ! $force && '1' === (string) get_post_meta( $local_id, '_heb_pp_locked', true ) ) {
				return rest_ensure_response( [ 'success' => true, 'deleted' => false, 'reason' => 'locked', 'local_id' => $local_id ] );
			}
			$deleted = wp_delete_post( $local_id, true );
			return rest_ensure_response( [ 'success' => true, 'deleted' => (bool) $deleted, 'local_id' => $local_id ] );
		}

		if ( 'term' === $kind ) {
			$taxonomy = isset( $body['taxonomy'] ) ? sanitize_key( (string) $body['taxonomy'] ) : '';
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return new \WP_Error( 'heb_pub_bad_payload', __( 'taxonomy required.', 'heb-product-publisher' ), [ 'status' => 400 ] );
			}
			if ( ! in_array( $taxonomy, self::allowed_taxonomies(), true ) ) {
				return new \WP_Error( 'heb_pub_forbidden_taxonomy', __( 'Taxonomy is not allowed.', 'heb-product-publisher' ), [ 'status' => 403 ] );
			}
			$local_id = $this->find_term_by_source( $taxonomy, $source_id, $source_site );
			if ( $local_id <= 0 ) {
				return rest_ensure_response( [ 'success' => true, 'deleted' => false, 'reason' => 'not_found' ] );
			}
			$res = wp_delete_term( $local_id, $taxonomy );
			return rest_ensure_response(
				[
					'success'  => ! is_wp_error( $res ) && false !== $res,
					'deleted'  => ! is_wp_error( $res ) && false !== $res,
					'local_id' => $local_id,
					'message'  => is_wp_error( $res ) ? $res->get_error_message() : '',
				]
			);
		}

		return new \WP_Error( 'heb_pub_bad_payload', __( 'Unknown kind.', 'heb-product-publisher' ), [ 'status' => 400 ] );
	}

	/**
	 * POST /import-menu — 接收主站 nav menu 分发。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_menu( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$source_menu_id = isset( $body['source_menu_id'] ) ? (int) $body['source_menu_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		$name           = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		if ( $source_menu_id <= 0 || '' === $source_site || '' === $name ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}

		// 1) 找/建本地 menu。默认不按 slug 接管已有本地菜单，避免清空子站自有菜单。
		$menu_id = $this->find_menu_by_source( $source_menu_id, $source_site );
		if ( $menu_id <= 0 ) {
			$slug = isset( $body['slug'] ) ? sanitize_title( (string) $body['slug'] ) : sanitize_title( $name );
			$exists = wp_get_nav_menu_object( $slug );
			if ( $exists instanceof \WP_Term ) {
				if ( ! self::allow_slug_adoption() ) {
					return new \WP_Error(
						'heb_pub_menu_slug_conflict',
						__( 'A local menu with the same slug already exists and is not linked to this source.', 'heb-product-publisher' ),
						[ 'status' => 409 ]
					);
				}
				$menu_id = (int) $exists->term_id;
				update_term_meta( $menu_id, Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_ID, $source_menu_id );
				update_term_meta( $menu_id, Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_SITE, $source_site );
				// 重命名到翻译后名字
				wp_update_nav_menu_object(
					$menu_id,
					[ 'menu-name' => $name, 'description' => isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : '' ]
				);
			} else {
				$menu_id = wp_create_nav_menu( $name );
				if ( is_wp_error( $menu_id ) ) {
					return new \WP_Error( 'heb_pub_menu_create', $menu_id->get_error_message(), [ 'status' => 500 ] );
				}
			}
			update_term_meta( $menu_id, Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_ID, $source_menu_id );
			update_term_meta( $menu_id, Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_SITE, $source_site );
		} else {
			// 更新已存在 menu 的名字 / 描述。
			wp_update_nav_menu_object(
				$menu_id,
				[
					'menu-name'   => $name,
					'description' => isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : '',
				]
			);
		}

		// 2) 清空原有 menu items（reconcile 方式：先删后建，避免 source_id 顺序错乱）。
		$existing_items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
		if ( is_array( $existing_items ) ) {
			foreach ( $existing_items as $it ) {
				if ( $it instanceof \WP_Post ) {
					wp_delete_post( $it->ID, true );
				}
			}
		}

		// 3) 按 source_id → local_menu_item_id 映射，按 menu_order 顺序逐个插入。
		$items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : [];
		usort(
			$items,
			static function ( $a, $b ) {
				return ( (int) ( $a['menu_order'] ?? 0 ) ) - ( (int) ( $b['menu_order'] ?? 0 ) );
			}
		);
		$source_to_local = [];
		$imported        = 0;

		$target_url_host = isset( $body['target_url_host'] ) ? sanitize_text_field( (string) $body['target_url_host'] ) : '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$source_id        = (int) ( $item['source_id'] ?? 0 );
			$source_parent_id = (int) ( $item['source_parent_id'] ?? 0 );
			$title            = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$obj_type         = sanitize_key( (string) ( $item['object_type'] ?? 'custom' ) );
			$obj_subtype      = sanitize_key( (string) ( $item['object_subtype'] ?? '' ) );
			$obj_source_id    = (int) ( $item['object_source_id'] ?? 0 );
			$obj_source_site  = sanitize_text_field( (string) ( $item['object_source_site'] ?? $source_site ) );

			$local_object_id   = 0;
			$resolved_url      = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
			$final_type        = $obj_type;
			$final_subtype     = $obj_subtype;

			// 反查本地对象。
			if ( 'post_type' === $obj_type && $obj_source_id > 0 ) {
				$local_object_id = $this->find_by_source( $obj_subtype, $obj_source_id, $obj_source_site );
			} elseif ( 'taxonomy' === $obj_type && $obj_source_id > 0 ) {
				$local_object_id = $this->find_term_by_source( $obj_subtype, $obj_source_id, $obj_source_site );
			}

			if ( $local_object_id <= 0 ) {
				// 退化为 custom 链接。如果 URL 是源站主域，替换成目标站域名作 fallback。
				$final_type    = 'custom';
				$final_subtype = '';
				if ( '' !== $resolved_url && '' !== $target_url_host && '' !== $source_site && $source_site !== $target_url_host ) {
					$resolved_url = str_replace( '://' . $source_site, '://' . $target_url_host, $resolved_url );
				}
			}

			$args = [
				'menu-item-title'       => $title,
				'menu-item-description' => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
				'menu-item-attr-title'  => sanitize_text_field( (string) ( $item['attr_title'] ?? '' ) ),
				'menu-item-target'      => sanitize_text_field( (string) ( $item['target'] ?? '' ) ),
				'menu-item-classes'     => is_array( $item['classes'] ?? null ) ? implode( ' ', array_map( 'sanitize_html_class', $item['classes'] ) ) : '',
				'menu-item-xfn'         => sanitize_text_field( (string) ( $item['xfn'] ?? '' ) ),
				'menu-item-status'      => 'publish',
				'menu-item-parent-id'   => isset( $source_to_local[ $source_parent_id ] ) ? (int) $source_to_local[ $source_parent_id ] : 0,
			];

			if ( 'custom' === $final_type ) {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = $resolved_url;
			} elseif ( 'post_type' === $final_type ) {
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = $final_subtype;
				$args['menu-item-object-id'] = (int) $local_object_id;
			} elseif ( 'taxonomy' === $final_type ) {
				$args['menu-item-type']      = 'taxonomy';
				$args['menu-item-object']    = $final_subtype;
				$args['menu-item-object-id'] = (int) $local_object_id;
			}

			$new_item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! is_wp_error( $new_item_id ) && $new_item_id > 0 ) {
				$source_to_local[ $source_id ] = (int) $new_item_id;
				$imported++;
			}
		}

		// 4) 设置 theme locations（可显式关闭，避免覆盖子站已有绑定）。
		$bind_locations = ! isset( $body['bind_theme_locations'] ) || ! empty( $body['bind_theme_locations'] );
		if ( $bind_locations && isset( $body['locations'] ) && is_array( $body['locations'] ) ) {
			$locs = get_theme_mod( 'nav_menu_locations', [] );
			if ( ! is_array( $locs ) ) {
				$locs = [];
			}
			foreach ( $body['locations'] as $loc ) {
				$loc = sanitize_key( (string) $loc );
				if ( '' !== $loc ) {
					$locs[ $loc ] = (int) $menu_id;
				}
			}
			set_theme_mod( 'nav_menu_locations', $locs );
		}

		return rest_ensure_response(
			[
				'success'        => true,
				'menu_id'        => (int) $menu_id,
				'items_imported' => (int) $imported,
				'items_total'    => count( $items ),
			]
		);
	}

	/**
	 * POST /import-settings — 接收主站全局选项分发。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_settings( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		try {
			$body = $this->parse_authenticated_request( $request );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$this->sideload_trusted_hosts = $this->collect_sideload_trusted_hosts( $body );
			if ( ! class_exists( 'Heb_Product_Publisher_Settings_Sync' ) ) {
				return new \WP_Error(
					'heb_pub_settings_unavailable',
					__( 'Receiver 插件版本过旧，缺少 Settings_Sync。请更新子站 heb-product-publisher 到最新版后重试。', 'heb-product-publisher' ),
					[ 'status' => 503 ]
				);
			}
			$source_site = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
			if ( '' === $source_site ) {
				return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
			}

			$copy_allowed      = Heb_Product_Publisher_Settings_Sync::copy_options();
			$translate_allowed = Heb_Product_Publisher_Settings_Sync::translate_options();
			$ref_allowed       = Heb_Product_Publisher_Settings_Sync::post_ref_options();

			$applied = [];
			$skipped = [];
			$errors  = [];
			$rewrite_options = [ 'permalink_structure', 'category_base', 'tag_base' ];
			$flush_rewrite   = false;

			foreach ( (array) ( $body['copy'] ?? [] ) as $opt => $val ) {
				$opt = sanitize_key( (string) $opt );
				if ( ! in_array( $opt, $copy_allowed, true ) ) {
					$skipped[] = $opt . ' (not whitelisted)';
					continue;
				}
				$safe = $this->sanitize_settings_option_value( $opt, $val );
				if ( null === $safe ) {
					$skipped[] = $opt . ' (invalid value type)';
					continue;
				}
				try {
					if ( in_array( $opt, $rewrite_options, true ) && get_option( $opt ) !== $safe ) {
						$flush_rewrite = true;
					}
					update_option( $opt, $safe );
					$applied[] = $opt;
				} catch ( \Throwable $e ) {
					$skipped[] = $opt . ' (error)';
					$errors[]  = $opt . ': ' . $e->getMessage();
				}
			}
			foreach ( (array) ( $body['translate'] ?? [] ) as $opt => $val ) {
				$opt = sanitize_key( (string) $opt );
				if ( ! in_array( $opt, $translate_allowed, true ) ) {
					$skipped[] = $opt . ' (not whitelisted)';
					continue;
				}
				try {
					update_option( $opt, sanitize_text_field( (string) $val ) );
					$applied[] = $opt;
				} catch ( \Throwable $e ) {
					$skipped[] = $opt . ' (error)';
					$errors[]  = $opt . ': ' . $e->getMessage();
				}
			}
			foreach ( (array) ( $body['post_refs'] ?? [] ) as $opt => $ref ) {
				$opt = sanitize_key( (string) $opt );
				if ( ! in_array( $opt, $ref_allowed, true ) || ! is_array( $ref ) ) {
					$skipped[] = $opt . ' (not whitelisted or bad ref)';
					continue;
				}
				$source_post_id = (int) ( $ref['source_post_id'] ?? 0 );
				if ( $source_post_id <= 0 ) {
					$skipped[] = $opt . ' (missing source_post_id)';
					continue;
				}
				$local_id = 0;
				foreach ( heb_pp_distributable_post_types() as $pt ) {
					$local_id = $this->find_by_source( $pt, $source_post_id, $source_site );
					if ( $local_id > 0 ) {
						break;
					}
				}
				if ( $local_id <= 0 ) {
					$skipped[] = $opt . ' (no local post matched source_post_id=' . $source_post_id . ')';
					continue;
				}
				try {
					update_option( $opt, (int) $local_id );
					$applied[] = $opt;
				} catch ( \Throwable $e ) {
					$skipped[] = $opt . ' (error)';
					$errors[]  = $opt . ': ' . $e->getMessage();
				}
			}

			if ( $flush_rewrite ) {
				try {
					flush_rewrite_rules( false );
				} catch ( \Throwable $e ) {
					$errors[] = 'flush_rewrite_rules: ' . $e->getMessage();
				}
			}

			foreach ( (array) ( $body['elementor'] ?? [] ) as $opt => $val ) {
				$opt = sanitize_key( (string) $opt );
				if ( ! in_array( $opt, Heb_Product_Publisher_Settings_Sync::elementor_options(), true ) ) {
					$skipped[] = 'elementor:' . $opt . ' (not whitelisted)';
					continue;
				}
				if ( ! $this->is_safe_elementor_option_value( $opt, $val ) ) {
					$skipped[] = 'elementor:' . $opt . ' (invalid value type)';
					continue;
				}
				try {
					update_option( $opt, $val );
					$applied[] = 'elementor:' . $opt;
				} catch ( \Throwable $e ) {
					$skipped[] = 'elementor:' . $opt . ' (error)';
					$errors[]  = 'elementor:' . $opt . ': ' . $e->getMessage();
				}
			}

			foreach ( (array) ( $body['yoast'] ?? [] ) as $opt => $val ) {
				$opt = sanitize_key( (string) $opt );
				if ( ! in_array( $opt, Heb_Product_Publisher_Settings_Sync::yoast_options(), true ) ) {
					$skipped[] = 'yoast:' . $opt . ' (not whitelisted)';
					continue;
				}
				if ( ! is_array( $val ) ) {
					$skipped[] = 'yoast:' . $opt . ' (expected array)';
					continue;
				}
				if ( ! $this->is_yoast_available() ) {
					$skipped[] = 'yoast:' . $opt . ' (Yoast SEO not active)';
					continue;
				}
				try {
					update_option( $opt, $val );
					$applied[] = 'yoast:' . $opt;
				} catch ( \Throwable $e ) {
					$skipped[] = 'yoast:' . $opt . ' (error)';
					$errors[]  = 'yoast:' . $opt . ': ' . $e->getMessage();
				}
			}

			if ( ! empty( $body['theme_mods'] ) && is_array( $body['theme_mods'] ) ) {
				$exclude = array_flip( Heb_Product_Publisher_Settings_Sync::theme_mod_exclude_keys() );
				$mod_n   = 0;
				foreach ( $body['theme_mods'] as $mod_key => $mod_val ) {
					if ( ! is_string( $mod_key ) || '' === $mod_key || isset( $exclude[ $mod_key ] ) ) {
						continue;
					}
					if ( ! $this->is_safe_theme_mod_value( $mod_val ) ) {
						$skipped[] = 'theme_mod:' . $mod_key . ' (invalid value type)';
						continue;
					}
					try {
						set_theme_mod( $mod_key, $mod_val );
						++$mod_n;
					} catch ( \Throwable $e ) {
						$skipped[] = 'theme_mod:' . $mod_key . ' (error)';
						$errors[]  = 'theme_mod:' . $mod_key . ': ' . $e->getMessage();
					}
				}
				if ( $mod_n > 0 ) {
					$applied[] = 'theme_mods:' . $mod_n;
				}
			}

			foreach ( (array) ( $body['media_refs'] ?? [] ) as $mod_key => $ref ) {
				$mod_key = sanitize_key( (string) $mod_key );
				if ( ! in_array( $mod_key, Heb_Product_Publisher_Settings_Sync::media_ref_theme_mods(), true ) || ! is_array( $ref ) ) {
					$skipped[] = 'media_ref:' . $mod_key . ' (not whitelisted or bad ref)';
					continue;
				}
				$url = isset( $ref['url'] ) ? esc_url_raw( (string) $ref['url'] ) : '';
				if ( '' === $url || ! wp_http_validate_url( $url ) ) {
					$skipped[] = 'media_ref:' . $mod_key . ' (missing url)';
					continue;
				}
				try {
					$local_id = $this->sideload_url( $url );
					if ( $local_id <= 0 ) {
						$skipped[] = 'media_ref:' . $mod_key . ' (sideload failed)';
						continue;
					}
					set_theme_mod( $mod_key, $local_id );
					if ( 'site_icon' === $mod_key ) {
						update_option( 'site_icon', $local_id );
					}
					$applied[] = 'media_ref:' . $mod_key;
				} catch ( \Throwable $e ) {
					$skipped[] = 'media_ref:' . $mod_key . ' (error)';
					$errors[]  = 'media_ref:' . $mod_key . ': ' . $e->getMessage();
				}
			}

			$this->post_settings_activation( $applied, $errors, $flush_rewrite );

			if ( empty( $applied ) && ! empty( $errors ) ) {
				return new \WP_Error(
					'heb_pub_settings_failed',
					implode( '; ', array_slice( $errors, 0, 5 ) ),
					[
						'status'  => 500,
						'applied' => $applied,
						'skipped' => $skipped,
					]
				);
			}

			return rest_ensure_response(
				[
					'success'         => true,
					'applied'         => $applied,
					'skipped'         => $skipped,
					'errors'          => $errors,
					'rewrite_flushed' => $flush_rewrite,
					'theme_builder_regenerated' => in_array( 'elementor_theme_builder', $applied, true ),
				]
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'heb_pub_settings_fatal',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * 按 source_menu_id meta 反查本地 nav_menu term。
	 *
	 * @param int    $source_id   Source menu id.
	 * @param string $source_site Source site host.
	 * @return int
	 */
	private function find_menu_by_source( $source_id, $source_site ) {
		$terms = get_terms(
			[
				'taxonomy'   => 'nav_menu',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => [
					'relation' => 'AND',
					[ 'key' => Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_ID, 'value' => (int) $source_id ],
					[ 'key' => Heb_Product_Publisher_Menu_Sync::META_MENU_SOURCE_SITE, 'value' => $source_site ],
				],
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}
		return (int) $terms[0];
	}

	/**
	 * Receiver 端允许写入的 taxonomy 白名单。
	 *
	 * 默认 = 子站当前注册的所有 taxonomy 且对应到 distributable post type 关联的（即与 Hub 一致）。
	 * 可通过 filter `heb_pp_receiver_allowed_taxonomies` 显式扩展。
	 *
	 * @return array<int,string>
	 */
	public static function allowed_taxonomies() {
		$base = function_exists( 'heb_pp_distributable_taxonomies' )
			? heb_pp_distributable_taxonomies()
			: [];
		$pts  = (array) apply_filters( 'heb_pp_receiver_allowed_taxonomies', $base );
		$out  = [];
		foreach ( $pts as $tx ) {
			if ( ! is_string( $tx ) ) {
				continue;
			}
			$tx = sanitize_key( $tx );
			if ( '' !== $tx ) {
				$out[ $tx ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * POST /import-term — 接收主站 term 分发。
	 *
	 * Payload:
	 *  - taxonomy, name, description, slug_fallback, slug_translated
	 *  - source_term_id, source_parent_term_id, source_site, source_locale
	 *  - lang_map (主站当前已知的全语言 URL 矩阵)
	 *  - secret
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_term( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$taxonomy = isset( $body['taxonomy'] ) ? sanitize_key( (string) $body['taxonomy'] ) : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'heb_pub_bad_taxonomy', __( 'Taxonomy does not exist on this site.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $taxonomy, self::allowed_taxonomies(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_taxonomy', __( 'Taxonomy is not allowed for import.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$source_term_id = isset( $body['source_term_id'] ) ? (int) $body['source_term_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		if ( $source_term_id <= 0 || '' === $source_site ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}

		$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'heb_pub_name', __( 'Term name is required.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		$description = isset( $body['description'] ) ? wp_kses_post( (string) $body['description'] ) : '';

		$slug_strategy   = isset( $body['slug_strategy'] ) ? sanitize_key( (string) $body['slug_strategy'] ) : Heb_Product_Publisher_Admin_Settings::default_slug_strategy();
		if ( ! in_array( $slug_strategy, [ 'source', 'localized' ], true ) ) {
			$slug_strategy = Heb_Product_Publisher_Admin_Settings::default_slug_strategy();
		}
		$translated_slug = isset( $body['slug_translated'] ) ? sanitize_title( (string) $body['slug_translated'] ) : '';
		$fallback_slug   = isset( $body['slug_fallback'] ) ? sanitize_title( (string) $body['slug_fallback'] ) : '';
		if ( 'source' === $slug_strategy ) {
			$new_slug = '' !== $fallback_slug ? $fallback_slug : sanitize_title( $name );
		} else {
			$new_slug = '' !== $translated_slug ? $translated_slug : ( '' !== $fallback_slug ? $fallback_slug : sanitize_title( $name ) );
		}

		// 1) 反查已存：先按 source_term_id meta，找不到再按 slug fallback。
		$existing_id = $this->find_term_by_source( $taxonomy, $source_term_id, $source_site );
		if ( ! $existing_id && '' !== $fallback_slug ) {
			$term = get_term_by( 'slug', $fallback_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				if ( ! self::allow_slug_adoption() ) {
					return new \WP_Error(
						'heb_pub_term_slug_conflict',
						__( 'A local term with the same slug already exists and is not linked to this source.', 'heb-product-publisher' ),
						[ 'status' => 409 ]
					);
				}
				$existing_id = (int) $term->term_id;
			}
		}

		// 2) parent 远程反查。
		$source_parent_id = isset( $body['source_parent_term_id'] ) ? (int) $body['source_parent_term_id'] : 0;
		$local_parent     = 0;
		if ( $source_parent_id > 0 ) {
			$local_parent = $this->find_term_by_source( $taxonomy, $source_parent_id, $source_site );
		}

		$args = [
			'description' => $description,
			'slug'        => $new_slug,
		];
		if ( $local_parent > 0 ) {
			$args['parent'] = $local_parent;
		}

		if ( $existing_id > 0 ) {
			// 旧 slug 入 _heb_pp_old_slugs 数组用于 301 redirect。
			$current = get_term( $existing_id, $taxonomy );
			if ( $current instanceof \WP_Term ) {
				$current_slug = (string) $current->slug;
				if ( '' !== $current_slug && $current_slug !== $new_slug ) {
					$old = get_term_meta( $existing_id, '_heb_pp_old_slugs', true );
					$old = is_array( $old ) ? $old : [];
					if ( ! in_array( $current_slug, $old, true ) ) {
						$old[] = $current_slug;
					}
					update_term_meta( $existing_id, '_heb_pp_old_slugs', array_values( $old ) );
				}
			}
			$args['name'] = $name;
			$res          = wp_update_term( $existing_id, $taxonomy, $args );
		} else {
			if ( ! $this->acquire_import_lock( 'term', $taxonomy, $source_term_id, $source_site ) ) {
				$existing_id = $this->find_term_by_source( $taxonomy, $source_term_id, $source_site );
				if ( $existing_id > 0 ) {
					$args['name'] = $name;
					$res          = wp_update_term( $existing_id, $taxonomy, $args );
				} else {
					return new \WP_Error( 'heb_pub_import_busy', __( 'Import already in progress.', 'heb-product-publisher' ), [ 'status' => 409 ] );
				}
			} else {
				$res = wp_insert_term( $name, $taxonomy, $args );
				$this->release_import_lock( 'term', $taxonomy, $source_term_id, $source_site );
			}
		}
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'heb_pub_term_save', $res->get_error_message(), [ 'status' => 500 ] );
		}
		$term_id = isset( $res['term_id'] ) ? (int) $res['term_id'] : 0;
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'heb_pub_term_save', __( 'Term save returned no id.', 'heb-product-publisher' ), [ 'status' => 500 ] );
		}

		update_term_meta( $term_id, '_heb_pp_source_term_id', $source_term_id );
		update_term_meta( $term_id, '_heb_pp_source_site', $source_site );
		if ( ! empty( $body['lang_map'] ) && is_array( $body['lang_map'] ) ) {
			update_term_meta( $term_id, '_heb_pp_term_lang_map', $this->sanitize_lang_map( $body['lang_map'] ) );
		}

		$term_link = get_term_link( $term_id, $taxonomy );
		return rest_ensure_response(
			[
				'success'  => true,
				'term_id'  => $term_id,
				'created'  => 0 === $existing_id,
				'edit_url' => admin_url( 'term.php?taxonomy=' . rawurlencode( $taxonomy ) . '&tag_ID=' . $term_id ),
				'url'      => is_wp_error( $term_link ) ? '' : (string) $term_link,
			]
		);
	}

	/**
	 * POST /sync-term-lang-map — Hub 广播 term 多语言矩阵。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_sync_term_lang_map( $request ) {
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$taxonomy       = isset( $body['taxonomy'] ) ? sanitize_key( (string) $body['taxonomy'] ) : '';
		$source_term_id = isset( $body['source_term_id'] ) ? (int) $body['source_term_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		$lang_map       = isset( $body['lang_map'] ) && is_array( $body['lang_map'] ) ? $this->sanitize_lang_map( $body['lang_map'] ) : [];

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || $source_term_id <= 0 || '' === $source_site ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $taxonomy, self::allowed_taxonomies(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_taxonomy', __( 'Taxonomy is not allowed for import.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$term_id = $this->find_term_by_source( $taxonomy, $source_term_id, $source_site );
		if ( $term_id <= 0 ) {
			return rest_ensure_response( [ 'success' => true, 'updated' => false ] );
		}
		update_term_meta( $term_id, '_heb_pp_term_lang_map', $lang_map );
		return rest_ensure_response( [ 'success' => true, 'updated' => true ] );
	}

	/**
	 * 按 source_term_id + source_site 反查本地 term。
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $source_id  Source term id.
	 * @param string $source_host Source site host.
	 * @return int
	 */
	private function find_term_by_source( $taxonomy, $source_id, $source_host ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => [
					'relation' => 'AND',
					[ 'key' => '_heb_pp_source_term_id', 'value' => (int) $source_id ],
					[ 'key' => '_heb_pp_source_site', 'value' => $source_host ],
				],
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}
		return (int) $terms[0];
	}

	/**
	 * Hub 在 import-product 因 cURL/Gateway timeout 失败时，调用这个端点
	 * 反查目标站是否其实已经收到并创建了文章——避免显示"假失败"。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_lookup_by_source( $request ) {
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$post_type      = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
		$source_post_id = isset( $body['source_post_id'] ) ? (int) $body['source_post_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		if ( '' === $post_type || $source_post_id <= 0 || '' === $source_site ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		$post_id = $this->find_by_source( $post_type, $source_post_id, $source_site );
		if ( $post_id <= 0 ) {
			return rest_ensure_response( [ 'found' => false ] );
		}
		return rest_ensure_response(
			[
				'found'     => true,
				'post_id'   => $post_id,
				'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'permalink' => (string) get_permalink( $post_id ),
				'modified'  => (int) get_post_modified_time( 'U', true, $post_id ),
			]
		);
	}

	/**
	 * 接收端允许写入的 post type 白名单。
	 * 默认 = distributable 列表；可通过 filter `heb_pp_receiver_allowed_post_types`
	 * 在子站显式扩展（例：[ 'products', 'solutions', 'post' ]）。
	 *
	 * @return array<int,string>
	 */
	public static function allowed_post_types() {
		$base = function_exists( 'heb_pp_distributable_post_types' )
			? heb_pp_distributable_post_types()
			: [ 'products', 'solutions' ];
		$pts  = (array) apply_filters( 'heb_pp_receiver_allowed_post_types', $base );
		$out  = [];
		foreach ( $pts as $pt ) {
			if ( ! is_string( $pt ) ) {
				continue;
			}
			$pt = sanitize_key( $pt );
			if ( '' !== $pt ) {
				$out[ $pt ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * 是否允许 Receiver 在没有 source meta 时按 slug 接管已有本地对象。
	 *
	 * 默认关闭，避免推送时误覆盖子站本地内容/菜单。老站点如果确实依赖这个行为，
	 * 可通过 filter `heb_pp_receiver_allow_slug_adoption` 显式打开。
	 *
	 * @return bool
	 */
	private static function allow_slug_adoption() {
		return (bool) apply_filters( 'heb_pp_receiver_allow_slug_adoption', false );
	}

	/**
	 * 简易限速：同 secret 校验失败 N 次后短时间内拒绝（仅基于 transient，存活 5 分钟）。
	 * 防止穷举 secret。
	 *
	 * @return bool true=请放行；false=已被限流。
	 */
	private function rate_limit_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rl_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		return $n < 30; // 5 分钟内最多 30 次失败
	}

	/**
	 * 统一鉴权：secret 校验 + 失败限速 + 成功请求配额。
	 *
	 * @param mixed $body Request JSON body.
	 * @return true|\WP_Error
	 */
	private function verify_authenticated_request( $body ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->request_quota_ok() ) {
			return new \WP_Error( 'heb_pub_quota', __( 'Request quota exceeded.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		$this->request_quota_bump();
		return true;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error Body array or error.
	 */
	private function parse_authenticated_request( $request ) {
		$body = $request->get_json_params();
		$auth = $this->verify_authenticated_request( $body );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return is_array( $body ) ? $body : [];
	}

	/**
	 * 记录一次失败 secret 校验，用于限速。
	 */
	private function rate_limit_bump() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rl_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * 合法请求软限流（防 DoS / manifest 滥用）。
	 *
	 * @return bool
	 */
	private function request_quota_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rq_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		return $n < 180;
	}

	/**
	 * @return void
	 */
	private function request_quota_bump() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rq_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * import 互斥锁，避免并发重复 insert。
	 *
	 * @param string $kind        post|term.
	 * @param string $type        post_type or taxonomy.
	 * @param int    $source_id   Source id.
	 * @param string $source_site Source site host.
	 * @return bool
	 */
	private function acquire_import_lock( $kind, $type, $source_id, $source_site ) {
		$key = sprintf(
			'heb_pp_import_%s_%s_%d_%s',
			sanitize_key( (string) $kind ),
			sanitize_key( (string) $type ),
			(int) $source_id,
			md5( (string) $source_site )
		);
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, 90 );
		return true;
	}

	/**
	 * @param string $kind        post|term.
	 * @param string $type        post_type or taxonomy.
	 * @param int    $source_id   Source id.
	 * @param string $source_site Source site host.
	 * @return void
	 */
	private function release_import_lock( $kind, $type, $source_id, $source_site ) {
		$key = sprintf(
			'heb_pp_import_%s_%s_%d_%s',
			sanitize_key( (string) $kind ),
			sanitize_key( (string) $type ),
			(int) $source_id,
			md5( (string) $source_site )
		);
		delete_transient( $key );
	}

	/**
	 * settings 写入后激活：flush 重写规则、清 Elementor 缓存、重建 Theme Builder 条件缓存。
	 *
	 * @param array<int,string> $applied Applied list (by ref append).
	 * @param array<int,string> $errors  Errors list (by ref append).
	 * @param bool              $flush_rewrite Whether permalink changed.
	 * @return void
	 */
	private function post_settings_activation( array &$applied, array &$errors, $flush_rewrite ) {
		try {
			flush_rewrite_rules( false );
			if ( ! $flush_rewrite ) {
				$applied[] = 'rewrite_flush';
			}
		} catch ( \Throwable $e ) {
			$errors[] = 'rewrite_flush: ' . $e->getMessage();
		}

		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$plugin = \Elementor\Plugin::$instance;
				if ( $plugin && isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
					$applied[] = 'elementor_cache';
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'elementor_clear_cache: ' . $e->getMessage();
			}
		}

		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			try {
				$pro = \ElementorPro\Plugin::instance();
				if ( $pro && isset( $pro->modules_manager ) && method_exists( $pro->modules_manager, 'get_modules' ) ) {
					$module = $pro->modules_manager->get_modules( 'theme-builder' );
					if ( $module && method_exists( $module, 'get_conditions_manager' ) ) {
						$cm = $module->get_conditions_manager();
						if ( $cm && method_exists( $cm, 'get_cache' ) ) {
							$cache = $cm->get_cache();
							if ( $cache && method_exists( $cache, 'regenerate' ) ) {
								$cache->regenerate();
								$applied[] = 'elementor_theme_builder';
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'elementor_theme_builder: ' . $e->getMessage();
			}
		}

		do_action( 'heb_pp_settings_imported' );
	}

	/**
	 * 校验 settings option 值类型，防止写入非法结构。
	 *
	 * @param string $opt Option name.
	 * @param mixed  $val Value.
	 * @return mixed|null Sanitized value or null if rejected.
	 */
	private function sanitize_settings_option_value( $opt, $val ) {
		$int_opts = [
			'posts_per_page',
			'start_of_week',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
		];
		if ( in_array( $opt, $int_opts, true ) ) {
			return (int) $val;
		}
		if ( 'gmt_offset' === $opt ) {
			return (float) $val;
		}
		if ( 'thumbnail_crop' === $opt ) {
			return empty( $val ) ? 0 : 1;
		}
		if ( 'show_on_front' === $opt ) {
			$v = sanitize_key( (string) $val );
			return in_array( $v, [ 'page', 'posts' ], true ) ? $v : null;
		}
		if ( in_array( $opt, [ 'permalink_structure', 'category_base', 'tag_base', 'timezone_string', 'date_format', 'time_format' ], true ) ) {
			return is_scalar( $val ) ? sanitize_text_field( (string) $val ) : null;
		}
		if ( in_array( $opt, Heb_Product_Publisher_Settings_Sync::translate_options(), true ) ) {
			return is_scalar( $val ) ? sanitize_text_field( (string) $val ) : null;
		}
		return is_scalar( $val ) ? $val : null;
	}

	/**
	 * Elementor option 值类型校验。
	 *
	 * @param string $opt Option name.
	 * @param mixed  $val Value.
	 * @return bool
	 */
	private function is_safe_elementor_option_value( $opt, $val ) {
		if ( 'elementor_cpt_support' === $opt ) {
			if ( ! is_array( $val ) ) {
				return false;
			}
			foreach ( $val as $item ) {
				if ( ! is_string( $item ) || '' === sanitize_key( $item ) ) {
					return false;
				}
			}
			return true;
		}
		if ( in_array( $opt, [ 'elementor_container_width', 'elementor_viewport_lg', 'elementor_viewport_md', 'elementor_viewport_sm' ], true ) ) {
			return is_numeric( $val );
		}
		if ( in_array( $opt, [ 'elementor_disable_color_schemes', 'elementor_disable_typography_schemes', 'elementor_global_image_lightbox' ], true ) ) {
			return is_scalar( $val );
		}
		return is_scalar( $val ) || is_array( $val );
	}

	/**
	 * @return bool
	 */
	private function is_yoast_available() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false );
	}

	/**
	 * theme_mod 值只允许 JSON 可序列化的 scalar / array。
	 *
	 * @param mixed $val Value.
	 * @return bool
	 */
	private function is_safe_theme_mod_value( $val ) {
		if ( is_scalar( $val ) || null === $val ) {
			return true;
		}
		if ( ! is_array( $val ) ) {
			return false;
		}
		array_walk_recursive(
			$val,
			static function ( $item ) {
				if ( null !== $item && ! is_scalar( $item ) ) {
					throw new \RuntimeException( 'non-scalar theme_mod value' );
				}
			}
		);
		return true;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$this->sideload_trusted_hosts = $this->collect_sideload_trusted_hosts( $body );

		$post_type = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'heb_pub_bad_type', __( 'Post type does not exist.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed for import.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$title         = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';
		$slug          = isset( $body['slug'] ) ? sanitize_title( (string) $body['slug'] ) : '';
		$slug_strategy = isset( $body['slug_strategy'] ) ? sanitize_key( (string) $body['slug_strategy'] ) : Heb_Product_Publisher_Admin_Settings::default_slug_strategy();
		if ( ! in_array( $slug_strategy, [ 'source', 'localized' ], true ) ) {
			$slug_strategy = Heb_Product_Publisher_Admin_Settings::default_slug_strategy();
		}
		$content = isset( $body['content'] ) ? wp_kses_post( (string) $body['content'] ) : '';
		$excerpt = isset( $body['excerpt'] ) ? sanitize_textarea_field( (string) $body['excerpt'] ) : '';
		$status  = isset( $body['status'] ) ? sanitize_key( (string) $body['status'] ) : 'draft';
		if ( ! in_array( $status, [ 'draft', 'publish', 'pending', 'private' ], true ) ) {
			$status = 'draft';
		}
		if ( '' === $title ) {
			return new \WP_Error( 'heb_pub_title', __( 'Title is required.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}

		$source_post_id = isset( $body['source_post_id'] ) ? (int) $body['source_post_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';

		$existing_id = 0;
		if ( $source_post_id > 0 && '' !== $source_site ) {
			$existing_id = $this->find_by_source( $post_type, $source_post_id, $source_site );
		} elseif ( $source_post_id <= 0 || '' === $source_site ) {
			return new \WP_Error(
				'heb_pub_missing_source',
				__( 'source_post_id and source_site are required for import.', 'heb-product-publisher' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! $existing_id && '' !== $slug && 'source' === $slug_strategy ) {
			$existing = get_posts(
				[
					'post_type'      => $post_type,
					'name'           => $slug,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);
			if ( ! empty( $existing ) ) {
				if ( ! self::allow_slug_adoption() ) {
					return new \WP_Error(
						'heb_pub_slug_conflict',
						__( 'A local post with the same slug already exists and is not linked to this source.', 'heb-product-publisher' ),
						[ 'status' => 409 ]
					);
				}
				$existing_id = (int) $existing[0];
			}
		}

		// 子站本地锁定：管理员显式标记不接受主站推送（避免本地手动修改被覆盖）。
		if ( $existing_id > 0 && '1' === (string) get_post_meta( $existing_id, '_heb_pp_locked', true ) ) {
			return rest_ensure_response(
				[
					'success'   => true,
					'post_id'   => $existing_id,
					'created'   => false,
					'locked'    => true,
					'message'   => __( '目标 post 已被本地锁定（_heb_pp_locked），跳过更新。', 'heb-product-publisher' ),
					'edit_url'  => admin_url( 'post.php?post=' . $existing_id . '&action=edit' ),
					'permalink' => get_permalink( $existing_id ),
				]
			);
		}

		// slug_strategy=localized 时：首次创建根据翻译后标题生成；更新时保留现有 slug，避免外链失效。
		if ( 'localized' === $slug_strategy ) {
			if ( $existing_id > 0 ) {
				$existing_slug = get_post_field( 'post_name', $existing_id );
				$slug          = is_string( $existing_slug ) && '' !== $existing_slug
					? $existing_slug
					: sanitize_title( $title );
			} else {
				$slug = sanitize_title( $title );
			}
		} elseif ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}

		$postarr = [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => $post_type,
		];

		if ( is_post_type_hierarchical( $post_type ) ) {
			$source_parent_id = isset( $body['source_parent_id'] ) ? (int) $body['source_parent_id'] : 0;
			$local_parent     = 0;
			if ( $source_parent_id > 0 && '' !== $source_site ) {
				$local_parent = $this->find_by_source( $post_type, $source_parent_id, $source_site );
			}
			$postarr['post_parent'] = $local_parent > 0 ? $local_parent : 0;
		}

		if ( $existing_id > 0 ) {
			$current_slug = get_post_field( 'post_name', $existing_id );
			if ( is_string( $current_slug ) && '' !== $current_slug && $current_slug !== $slug ) {
				$old = get_post_meta( $existing_id, '_heb_pp_old_slugs', true );
				$old = is_array( $old ) ? $old : [];
				if ( ! in_array( $current_slug, $old, true ) ) {
					$old[] = $current_slug;
				}
				update_post_meta( $existing_id, '_heb_pp_old_slugs', array_values( $old ) );
			}
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			if ( ! $this->acquire_import_lock( 'post', $post_type, $source_post_id, $source_site ) ) {
				$existing_id = $this->find_by_source( $post_type, $source_post_id, $source_site );
				if ( $existing_id > 0 ) {
					$postarr['ID'] = $existing_id;
					$post_id       = wp_update_post( wp_slash( $postarr ), true );
				} else {
					return new \WP_Error( 'heb_pub_import_busy', __( 'Import already in progress.', 'heb-product-publisher' ), [ 'status' => 409 ] );
				}
			} else {
				$post_id = wp_insert_post( wp_slash( $postarr ), true );
				$this->release_import_lock( 'post', $post_type, $source_post_id, $source_site );
				if ( is_wp_error( $post_id ) ) {
					return new \WP_Error( 'heb_pub_save', $post_id->get_error_message(), [ 'status' => 500 ] );
				}
			}
		}
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'heb_pub_save', $post_id->get_error_message(), [ 'status' => 500 ] );
		}
		$post_id = (int) $post_id;

		if ( ! empty( $body['featured_url'] ) && is_string( $body['featured_url'] ) ) {
			$fid = $this->sideload_url( $body['featured_url'] );
			if ( $fid ) {
				set_post_thumbnail( $post_id, $fid );
			}
		}

		if ( ! empty( $body['taxonomies'] ) && is_array( $body['taxonomies'] ) ) {
			foreach ( $body['taxonomies'] as $tax => $values ) {
				$tax = sanitize_key( (string) $tax );
				if ( ! taxonomy_exists( $tax ) || ! is_array( $values ) ) {
					continue;
				}
				if ( ! in_array( $tax, self::allowed_taxonomies(), true ) ) {
					continue;
				}
				$term_ids = $this->resolve_terms( $tax, $values, $source_site );
				if ( empty( $term_ids ) ) {
					wp_set_object_terms( $post_id, [], $tax );
				} else {
					wp_set_object_terms( $post_id, $term_ids, $tax );
				}
			}
		}

		if ( ! empty( $body['acf'] ) && is_array( $body['acf'] ) && function_exists( 'update_field' ) ) {
			$acf = $this->decode_acf_from_transport( $body['acf'] );
			foreach ( $acf as $key => $value ) {
				if ( ! is_string( $key ) || '' === $key ) {
					continue;
				}
				// 禁止覆盖 WP/插件保留的 meta（以 _ 开头）以及非常规 key，
				// 防止 secret 泄露后通过 ACF 通道写入任意 protected meta。
				if ( '_' === $key[0] ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/', $key ) ) {
					continue;
				}
				update_field( $key, $value, $post_id );
			}
		}

		if ( ! empty( $body['seo'] ) && is_array( $body['seo'] ) ) {
			$this->apply_seo_meta( $post_id, $body['seo'] );
		}

		// Elementor data 解码现在返回"待异步 sideload 的远端图片 URL 列表"。
		// 写完 post 主体立即 enqueue AS task 后台慢慢下图，REST 在秒级返回，
		// 不会因为 sideload 几十张图卡住 HTTP / 触发子站 PHP 超时。
		$pending_media = $this->apply_elementor_payload( $post_id, $body );
		$queued        = 0;
		if ( is_array( $pending_media ) && ! empty( $pending_media ) && class_exists( 'Heb_Product_Publisher_Async_Media' ) ) {
			$queued = Heb_Product_Publisher_Async_Media::enqueue( $post_id, $pending_media );
		}

		$bootstrap_ctx = ! empty( $body['bootstrap_context'] );
		if ( $queued > 0 && class_exists( 'Heb_Product_Publisher_Async_Media' ) ) {
			if ( $bootstrap_ctx ) {
				Heb_Product_Publisher_Async_Media::drain_post( $post_id, 20 );
				$progress      = Heb_Product_Publisher_Async_Media::progress( $post_id );
				$queued        = (int) ( $progress['pending'] ?? 0 );
			} else {
				Heb_Product_Publisher_Async_Media::kick_queue_runner();
			}
		}

		if ( $source_post_id > 0 ) {
			update_post_meta( $post_id, '_heb_publisher_source_post_id', $source_post_id );
		}
		if ( '' !== $source_site ) {
			update_post_meta( $post_id, '_heb_publisher_source_site', $source_site );
		}
		if ( ! empty( $body['lang_map'] ) && is_array( $body['lang_map'] ) ) {
			update_post_meta( $post_id, '_heb_pp_lang_map', $this->sanitize_lang_map( $body['lang_map'] ) );
		}

		return rest_ensure_response(
			[
				'success'             => true,
				'post_id'             => $post_id,
				'created'             => 0 === $existing_id,
				'edit_url'            => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'permalink'           => get_permalink( $post_id ),
				'pending_media'       => $queued,
				'pending_media_status' => $queued > 0 ? ( $bootstrap_ctx ? 'partial' : 'queued' ) : 'done',
			]
		);
	}

	/**
	 * 同步语言 URL 映射到已导入文章（Hub 在每次分发成功后广播）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_sync_lang_map( $request ) {
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$post_type      = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
		$source_post_id = isset( $body['source_post_id'] ) ? (int) $body['source_post_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		$lang_map       = isset( $body['lang_map'] ) && is_array( $body['lang_map'] ) ? $this->sanitize_lang_map( $body['lang_map'] ) : [];

		if ( '' === $post_type || ! post_type_exists( $post_type ) || $source_post_id <= 0 || '' === $source_site ) {
			return new \WP_Error( 'heb_pub_bad_payload', __( 'Bad payload.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed for import.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		$post_id = $this->find_by_source( $post_type, $source_post_id, $source_site );
		if ( $post_id <= 0 ) {
			return rest_ensure_response( [ 'success' => true, 'updated' => false ] );
		}
		update_post_meta( $post_id, '_heb_pp_lang_map', $lang_map );
		return rest_ensure_response( [ 'success' => true, 'updated' => true ] );
	}

	/**
	 * 规范化语言映射：lang => URL。
	 *
	 * @param array<string,mixed> $map Raw map.
	 * @return array<string,string>
	 */
	private function sanitize_lang_map( array $map ) {
		$out = [];
		foreach ( $map as $lang => $url ) {
			$lang = strtolower( sanitize_key( (string) $lang ) );
			$url  = esc_url_raw( (string) $url );
			if ( '' === $lang || '' === $url ) {
				continue;
			}
			$out[ $lang ] = $url;
		}
		return $out;
	}

	/**
	 * 写回 Yoast SEO meta。未装 Yoast 也无妨：写入 meta 不会报错，启用后即生效。
	 *
	 * @param int                  $post_id Target post id.
	 * @param array<string,string> $seo     Sematic seo data.
	 */
	private function apply_seo_meta( $post_id, array $seo ) {
		$map = class_exists( 'Heb_Product_Publisher_Sync' ) ? Heb_Product_Publisher_Sync::seo_key_map() : [
			'title'          => '_yoast_wpseo_title',
			'metadesc'       => '_yoast_wpseo_metadesc',
			'focuskw'        => '_yoast_wpseo_focuskw',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'twitter_title'  => '_yoast_wpseo_twitter-title',
			'twitter_desc'   => '_yoast_wpseo_twitter-description',
		];
		foreach ( $map as $sem => $mk ) {
			if ( ! isset( $seo[ $sem ] ) ) {
				continue;
			}
			$v = $seo[ $sem ];
			if ( ! is_string( $v ) ) {
				continue;
			}
			if ( '' === trim( $v ) ) {
				delete_post_meta( $post_id, $mk );
				continue;
			}
			update_post_meta( $post_id, $mk, sanitize_text_field( $v ) );
		}
	}

	/**
	 * 通过 source_post_id + source_site 找到此前导入过的文章。
	 *
	 * @param string $post_type   Post type.
	 * @param int    $source_id   Source post ID.
	 * @param string $source_host Source site host.
	 * @return int
	 */
	private function find_by_source( $post_type, $source_id, $source_host ) {
		$q = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[ 'key' => '_heb_publisher_source_post_id', 'value' => (int) $source_id ],
					[ 'key' => '_heb_publisher_source_site', 'value' => $source_host ],
				],
			]
		);
		return ! empty( $q ) ? (int) $q[0] : 0;
	}

	/**
	 * 把 slug / {slug,name} / {source_term_id, slug_fallback, parent_source_term_id} 解析为本地 term_id。
	 *
	 * 反查优先级（v3.0 起）：
	 *  1. source_term_id + source_site meta（精确匹配，跨语言）
	 *  2. slug fallback（向后兼容老格式 + 兼容已有英文 slug 子站 term）
	 *  3. 都找不到 → wp_insert_term 用 source slug + name 创建
	 *
	 * 安全默认：slug 命中已有本地 term 时只复用关系，不自动补 source meta；如需老版
	 * "按 slug 接管"行为，可通过 `heb_pp_receiver_allow_slug_adoption` 显式开启。
	 *
	 * @param string                                                                                $tax         Taxonomy.
	 * @param array<int,mixed>                                                                      $values      Items from payload.
	 * @param string                                                                                $source_site Source site host (helps backfill mapping meta).
	 * @return array<int,int>
	 */
	private function resolve_terms( $tax, array $values, $source_site = '' ) {
		$ids = [];
		foreach ( $values as $v ) {
			$slug             = '';
			$name             = '';
			$source_term_id   = 0;
			$source_parent_id = 0;
			if ( is_string( $v ) ) {
				$slug = sanitize_title( $v );
			} elseif ( is_array( $v ) ) {
				$source_term_id   = isset( $v['source_term_id'] ) ? (int) $v['source_term_id'] : 0;
				$source_parent_id = isset( $v['source_parent_term_id'] ) ? (int) $v['source_parent_term_id'] : 0;
				$slug             = isset( $v['slug_fallback'] )
					? sanitize_title( (string) $v['slug_fallback'] )
					: ( isset( $v['slug'] ) ? sanitize_title( (string) $v['slug'] ) : '' );
				$name = isset( $v['name'] ) ? sanitize_text_field( (string) $v['name'] ) : '';
			}
			if ( '' === $slug && $source_term_id <= 0 ) {
				continue;
			}

			// 1) 优先按 source_term_id meta 反查（最准）。
			if ( $source_term_id > 0 && '' !== $source_site ) {
				$found = $this->find_term_by_source( $tax, $source_term_id, $source_site );
				if ( $found > 0 ) {
					$ids[] = $found;
					continue;
				}
			}

			// 2) 按 slug 反查（兼容老数据）。默认不隐式写 source meta，避免误接管本地 term。
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					$tid = (int) $term->term_id;
					if ( self::allow_slug_adoption() && $source_term_id > 0 && '' !== $source_site ) {
						$existing_src = get_term_meta( $tid, '_heb_pp_source_term_id', true );
						if ( '' === $existing_src ) {
							update_term_meta( $tid, '_heb_pp_source_term_id', $source_term_id );
							update_term_meta( $tid, '_heb_pp_source_site', $source_site );
						}
					}
					$ids[] = $tid;
					continue;
				}
			}

			// 3) 创建。
			$label = '' !== $name ? $name : $slug;
			if ( '' === $label ) {
				continue;
			}
			$insert_args = [];
			if ( '' !== $slug ) {
				$insert_args['slug'] = $slug;
			}
			if ( $source_parent_id > 0 && '' !== $source_site ) {
				$local_parent = $this->find_term_by_source( $tax, $source_parent_id, $source_site );
				if ( $local_parent > 0 ) {
					$insert_args['parent'] = $local_parent;
				}
			}
			$insert = wp_insert_term( $label, $tax, $insert_args );
			if ( ! is_wp_error( $insert ) && isset( $insert['term_id'] ) ) {
				$tid = (int) $insert['term_id'];
				if ( $source_term_id > 0 && '' !== $source_site ) {
					update_term_meta( $tid, '_heb_pp_source_term_id', $source_term_id );
					update_term_meta( $tid, '_heb_pp_source_site', $source_site );
				}
				$ids[] = $tid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * POST /process-pending-media — 同步 drain Elementor 待 sideload 图片队列。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_process_pending_media( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! class_exists( 'Heb_Product_Publisher_Async_Media' ) ) {
			return new \WP_Error( 'heb_pub_media_unavailable', __( 'Async media handler not loaded.', 'heb-product-publisher' ), [ 'status' => 503 ] );
		}
		$this->sideload_trusted_hosts = $this->collect_sideload_trusted_hosts( $body );
		$limit  = isset( $body['limit'] ) ? max( 1, min( 50, (int) $body['limit'] ) ) : 20;
		$result = Heb_Product_Publisher_Async_Media::process_all_pending( $limit );
		return rest_ensure_response(
			[
				'success'   => true,
				'processed' => (int) ( $result['processed'] ?? 0 ),
				'remaining' => (int) ( $result['remaining'] ?? 0 ),
				'urls_left' => (int) ( $result['urls_left'] ?? 0 ),
			]
		);
	}

	/**
	 * POST /regenerate-elementor-css — Bootstrap 收尾批量重编 Elementor CSS。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_regenerate_elementor_css( $request ) {
		Heb_Product_Publisher_Runtime::raise();
		$body = $this->parse_authenticated_request( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! class_exists( 'Heb_Product_Publisher_Async_Media' ) ) {
			return new \WP_Error( 'heb_pub_media_unavailable', __( 'Async media handler not loaded.', 'heb-product-publisher' ), [ 'status' => 503 ] );
		}
		$limit  = isset( $body['limit'] ) ? max( 1, min( 50, (int) $body['limit'] ) ) : 25;
		$offset = isset( $body['offset'] ) ? max( 0, (int) $body['offset'] ) : 0;
		$result = Heb_Product_Publisher_Async_Media::regenerate_batch_css( $limit, $offset );
		return rest_ensure_response(
			[
				'success'     => true,
				'regenerated' => (int) ( $result['regenerated'] ?? 0 ),
				'processed'   => (int) ( $result['processed'] ?? 0 ),
				'remaining'   => (int) ( $result['remaining'] ?? 0 ),
			]
		);
	}

	/**
	 * @param array<string,mixed> $body Import payload.
	 * @return array<int,string>
	 */
	private function collect_sideload_trusted_hosts( array $body ) {
		$hosts = [];
		$add   = static function ( $host ) use ( &$hosts ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' === $host ) {
				return;
			}
			$hosts[] = $host;
			if ( 0 === strpos( $host, 'www.' ) ) {
				$hosts[] = substr( $host, 4 );
			} else {
				$hosts[] = 'www.' . $host;
			}
		};
		$src = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		if ( '' !== $src ) {
			$h = wp_parse_url( 'https://' . ltrim( $src, '/' ), PHP_URL_HOST );
			if ( is_string( $h ) && '' !== $h ) {
				$add( $h );
			}
			$add( $src );
		}
		if ( class_exists( 'Heb_Product_Publisher_Admin_Settings', false ) ) {
			foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $site ) {
				if ( empty( $site['url'] ) ) {
					continue;
				}
				$h = wp_parse_url( (string) $site['url'], PHP_URL_HOST );
				if ( is_string( $h ) && '' !== $h ) {
					$add( $h );
				}
			}
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $home ) && '' !== $home ) {
			$add( $home );
		}
		return array_values(
			array_unique(
				array_filter(
					(array) apply_filters( 'heb_pp_sideload_trusted_hosts', $hosts, $body )
				)
			)
		);
	}

	/**
	 * 判断 URL 是否指向公共 IP / 公网域名。拒绝：
	 *  - 非 http/https
	 *  - localhost / loopback / link-local / 私网（10/8、172.16/12、192.168/16）
	 *  - IPv6 ::1 / fc00::/7 / fe80::/10
	 *  - 云元数据地址 169.254.169.254 / metadata.google.internal
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_safe_remote_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );

		if ( in_array( $host, $this->sideload_trusted_hosts, true ) ) {
			return true;
		}

		$blocked_hosts = [ 'localhost', 'metadata.google.internal', 'metadata' ];
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return false;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( '169.254.169.254' === $ip ) {
			return false;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	/**
	 * 源 URL 与本地 attachment 的去重 meta key。
	 */
	const META_SIDELOAD_SRC = '_heb_pp_sideload_src';

	/**
	 * 单张远程图片下载大小上限，避免 Receiver 被大文件耗尽磁盘/内存。
	 */
	const MAX_SIDELOAD_BYTES = 15728640; // 15 MB.

	/**
	 * 按源 URL 查找已 sideload 过的 attachment（避免重复下载/生成媒体库垃圾）。
	 *
	 * @param string $url 源 URL.
	 * @return int Attachment ID 或 0.
	 */
	private function find_sideloaded_attachment( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return 0;
		}
		$q = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => self::META_SIDELOAD_SRC,
						'value' => $url,
					],
				],
			]
		);
		return ! empty( $q ) ? (int) $q[0] : 0;
	}

	/**
	 * 同名 public 包装：供 Async_Media (后台异步 sideload) 复用。
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID or 0.
	 */
	public function public_sideload_url( $url ) {
		return $this->sideload_url( $url );
	}

	/**
	 * @param string $url Image URL.
	 * @return int Attachment ID or 0.
	 */
	private function sideload_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return 0;
		}
		$url = esc_url_raw( $url );
		if ( ! wp_http_validate_url( $url ) ) {
			return 0;
		}
		if ( ! $this->is_safe_remote_url( $url ) ) {
			return 0;
		}

		// 去重：源 URL 已 sideload 过则直接复用，避免媒体库重复堆积。
		$existing = $this->find_sideloaded_attachment( $url );
		if ( $existing > 0 ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$limit_filter = static function ( $args, $request_url ) use ( $url ) {
			if ( $request_url === $url ) {
				$args['limit_response_size'] = self::MAX_SIDELOAD_BYTES + 1;
				$args['timeout']             = 60;
				$args['redirection']         = 5;
				$args['reject_unsafe_urls']  = true;
			}
			return $args;
		};
		add_filter( 'http_request_args', $limit_filter, 10, 2 );
		$tmp = download_url( $url, 60 );
		remove_filter( 'http_request_args', $limit_filter, 10 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = $path ? basename( $path ) : 'image.jpg';
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
			$name .= '.jpg';
		}
		if ( filesize( $tmp ) > self::MAX_SIDELOAD_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 0;
		}
		$checked = wp_check_filetype_and_ext( $tmp, sanitize_file_name( $name ) );
		$type    = isset( $checked['type'] ) ? (string) $checked['type'] : '';
		if ( ! in_array( $type, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ], true ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 0;
		}

		$file_array = [
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		];

		$id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 0;
		}
		update_post_meta( (int) $id, self::META_SIDELOAD_SRC, $url );
		return (int) $id;
	}

	/**
	 * 递归 decode payload 中的图片 token，把 transport shape 还原为对应原生结构：
	 *  - { __heb_media: image, __heb_url } → attachment ID（ACF image 兼容）
	 *  - { __heb_media: elementor_image, __heb_url, __heb_alt, __heb_size, __heb_source }
	 *    → { id, url, alt, source, size }（Elementor image 兼容）
	 *
	 * 当传入 `$pending` 数组引用时进入"异步收集"模式：遇到 elementor_image token
	 * 不立即 sideload，而是把远端 URL 写入 $pending，并返回 { id: 0, url: <远端> }
	 * 让前台先用远端原图渲染。后续 Async_Media AS task 完成 sideload 再替换。
	 * ACF image (普通 image) 在任何模式下都同步 sideload（量少、影响小）。
	 *
	 * @param mixed              $value   Payload fragment.
	 * @param array<string>|null &$pending 异步收集列表（按引用）。null = 同步模式。
	 * @return mixed
	 */
	private function decode_acf_from_transport( $value, &$pending = null ) {
		if ( is_array( $value ) && isset( $value['__heb_media'], $value['__heb_url'] ) && is_string( $value['__heb_url'] ) ) {
			$kind = (string) $value['__heb_media'];

			if ( 'elementor_image' === $kind ) {
				$alt    = isset( $value['__heb_alt'] ) ? (string) $value['__heb_alt'] : '';
				$source = isset( $value['__heb_source'] ) && '' !== $value['__heb_source'] ? (string) $value['__heb_source'] : 'library';
				$size   = isset( $value['__heb_size'] ) ? (string) $value['__heb_size'] : '';
				$remote = (string) $value['__heb_url'];

				// 异步模式：留远端 URL + 0 id，写入 pending，AS task 后台处理。
				if ( is_array( $pending ) ) {
					$pending[] = $remote;
					return [
						'id'     => 0,
						'url'    => $remote,
						'alt'    => $alt,
						'source' => $source,
						'size'   => $size,
					];
				}

				// 同步模式（保留以兼容老调用方）。
				$id = $this->sideload_url( $remote );
				if ( $id > 0 ) {
					$url = (string) wp_get_attachment_image_url( $id, 'full' );
					return [
						'id'     => (int) $id,
						'url'    => '' !== $url ? $url : $remote,
						'alt'    => $alt,
						'source' => $source,
						'size'   => $size,
					];
				}
				return [
					'id'     => '',
					'url'    => $remote,
					'alt'    => $alt,
					'source' => $source,
					'size'   => $size,
				];
			}

			// 普通 ACF image：任何模式下都同步 sideload（量少）。
			$id = $this->sideload_url( $value['__heb_url'] );
			return $id > 0 ? $id : '';
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->decode_acf_from_transport( $v, $pending );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * 写回 Elementor 数据 + 页面级设置 + 版本号；清 Elementor 渲染缓存。
	 *
	 * 关键点：
	 *  - `_elementor_data` 在 WP 数据库存的是 JSON 字符串，必须 wp_slash 后再 update_post_meta
	 *    （WP 内部会 unslash 一次，不 slash 就会丢反斜杠）
	 *  - 写完后清缓存：清 `_elementor_css` post meta + Plugin::files_manager->clear_cache()
	 *    确保前端立刻渲染新内容而不是旧 CSS
	 *
	 * @param int                  $post_id Target post id.
	 * @param array<string,mixed>  $body    REST payload.
	 * @return array<string>       异步待 sideload 的远端图片 URL 列表（已去重）。
	 *                             调用方应把它交给 Async_Media::enqueue() 排队。
	 */
	private function apply_elementor_payload( $post_id, array $body ) {
		$pending      = []; // 收集所有 elementor_image 远端 URL，REST 完后异步 sideload。
		$source_site  = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';
		$decoded_data = [];
		$settings     = null;

		$has_data = isset( $body['elementor_data'] ) && is_array( $body['elementor_data'] ) && ! empty( $body['elementor_data'] );

		if ( $has_data ) {
			$decoded_data = $this->decode_acf_from_transport( $body['elementor_data'], $pending );
			if ( ! is_array( $decoded_data ) ) {
				$decoded_data = [];
			}
			if ( '' !== $source_site ) {
				$decoded_data = $this->remap_elementor_elements_tree( $decoded_data, $source_site );
			}
			$json = wp_json_encode( $decoded_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( is_string( $json ) ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
			}
		}

		if ( isset( $body['elementor_page_settings'] ) && is_array( $body['elementor_page_settings'] ) ) {
			$settings = $this->decode_acf_from_transport( $body['elementor_page_settings'], $pending );
			if ( is_array( $settings ) ) {
				if ( '' !== $source_site ) {
					$settings = $this->remap_elementor_settings( $settings, $source_site );
				}
				update_post_meta( $post_id, '_elementor_page_settings', $settings );
			}
		}

		$css_urls = [];
		if ( ! empty( $decoded_data ) ) {
			Heb_Product_Publisher_Sync::collect_elementor_css_media_urls( $decoded_data, $css_urls );
		}
		if ( is_array( $settings ) ) {
			Heb_Product_Publisher_Sync::collect_elementor_css_media_urls( $settings, $css_urls );
		}
		if ( ! empty( $css_urls ) ) {
			$pending = array_values( array_unique( array_merge( $pending, $css_urls ) ) );
		}

		if ( ! empty( $body['elementor_version'] ) && is_string( $body['elementor_version'] ) ) {
			update_post_meta( $post_id, '_elementor_version', sanitize_text_field( $body['elementor_version'] ) );
		}
		if ( ! empty( $body['elementor_edit_mode'] ) && is_string( $body['elementor_edit_mode'] ) ) {
			update_post_meta( $post_id, '_elementor_edit_mode', sanitize_text_field( $body['elementor_edit_mode'] ) );
		} elseif ( $has_data ) {
			// 没传递 edit_mode 但有 data → 默认 'builder'，确保前端能用 Elementor 渲染。
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		}
		if ( ! empty( $body['elementor_template_type'] ) && is_string( $body['elementor_template_type'] ) ) {
			update_post_meta( $post_id, '_elementor_template_type', sanitize_text_field( $body['elementor_template_type'] ) );
		}
		if ( ! empty( $body['elementor_extra_meta'] ) && is_array( $body['elementor_extra_meta'] ) ) {
			$decoded_extra = $this->decode_acf_from_transport( $body['elementor_extra_meta'], $pending );
			if ( is_array( $decoded_extra ) ) {
				if ( '' !== $source_site && isset( $decoded_extra['_elementor_conditions'] ) ) {
					$decoded_extra['_elementor_conditions'] = $this->remap_elementor_conditions(
						$decoded_extra['_elementor_conditions'],
						$source_site
					);
				}
				foreach ( $decoded_extra as $meta_key => $meta_value ) {
					if ( ! is_string( $meta_key ) || '' === $meta_key ) {
						continue;
					}
					// 限制：只允许 _elementor_ / _wp_page_template 前缀的 meta，防止恶意 payload 写任意 meta。
					if ( ! preg_match( '/^(_elementor_|_wp_page_template$)/', $meta_key ) ) {
						continue;
					}
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}
		}

		// 清缓存：写完 _elementor_data 后必须重编 CSS，否则 uploads/elementor/css/
		// 里可能仍保留旧域名背景图或克隆站遗留的错误字号。
		// 有 pending 图片时仍重编（修正 typography）；sideload 完成后会再次重编以替换背景 URL。
		if ( $has_data || ( is_array( $settings ) && ! empty( $settings ) ) ) {
			if ( class_exists( 'Heb_Product_Publisher_Async_Media' ) ) {
				Heb_Product_Publisher_Async_Media::regenerate_post_css( $post_id );
			} else {
				delete_post_meta( $post_id, '_elementor_css' );
				if ( class_exists( '\\Elementor\\Plugin' ) ) {
					try {
						$plugin = \Elementor\Plugin::$instance;
						if ( $plugin && isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
							$plugin->files_manager->clear_cache();
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $pending, 'is_string' ) ) );
	}

	/**
	 * 把主站 post ID 反查为子站本地 ID（仅当存在 source 映射时替换）。
	 *
	 * @param mixed  $id          Candidate id.
	 * @param string $source_site Source site host.
	 * @return mixed
	 */
	private function maybe_remap_source_post_id( $id, $source_site ) {
		if ( ! is_numeric( $id ) ) {
			return $id;
		}
		$source_id = (int) $id;
		if ( $source_id <= 0 || '' === $source_site ) {
			return $id;
		}
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			$local_id = $this->find_by_source( $pt, $source_id, $source_site );
			if ( $local_id > 0 ) {
				return $local_id;
			}
		}
		return $id;
	}

	/**
	 * Elementor widget settings 里引用 post / template 的字段名。
	 *
	 * @return array<int,string>
	 */
	private function elementor_post_id_setting_keys() {
		return (array) apply_filters(
			'heb_pp_elementor_post_id_setting_keys',
			[
				'template_id',
				'selected_template_id',
				'loop_item_template_id',
				'posts_ids',
				'post__in',
				'post__not_in',
				'related_fallback_id',
			]
		);
	}

	/**
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_elementor_post_id_setting_key( $key ) {
		if ( in_array( $key, $this->elementor_post_id_setting_keys(), true ) ) {
			return true;
		}
		return (bool) preg_match( '/_(ids|id)$/', $key );
	}

	/**
	 * 递归 remap Elementor settings 里的 post / template ID。
	 *
	 * @param mixed  $settings    Settings node.
	 * @param string $source_site Source site host.
	 * @return mixed
	 */
	private function remap_elementor_settings( $settings, $source_site ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$out = [];
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = $this->remap_elementor_settings( $value, $source_site );
			} else {
				$out[ $key ] = $value;
			}
			if ( ! $this->is_elementor_post_id_setting_key( (string) $key ) ) {
				continue;
			}
			if ( is_array( $out[ $key ] ) ) {
				foreach ( $out[ $key ] as $idx => $item ) {
					if ( is_array( $item ) && isset( $item['template_id'] ) ) {
						$out[ $key ][ $idx ]['template_id'] = $this->maybe_remap_source_post_id( $item['template_id'], $source_site );
					} else {
						$out[ $key ][ $idx ] = $this->maybe_remap_source_post_id( $item, $source_site );
					}
				}
				continue;
			}
			if ( is_string( $out[ $key ] ) && preg_match( '/^\d+(?:,\d+)*$/', $out[ $key ] ) ) {
				$parts = array_map(
					'intval',
					explode( ',', $out[ $key ] )
				);
				$parts = array_map(
					function ( $pid ) use ( $source_site ) {
						return (int) $this->maybe_remap_source_post_id( $pid, $source_site );
					},
					$parts
				);
				$out[ $key ] = implode( ',', $parts );
				continue;
			}
			$out[ $key ] = $this->maybe_remap_source_post_id( $out[ $key ], $source_site );
		}
		return $out;
	}

	/**
	 * 递归 remap Elementor 元素树（section / container / widget）。
	 *
	 * @param array<int,mixed> $elements    Elementor nodes.
	 * @param string           $source_site Source site host.
	 * @return array<int,mixed>
	 */
	private function remap_elementor_elements_tree( array $elements, $source_site ) {
		foreach ( $elements as $idx => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$elements[ $idx ]['settings'] = $this->remap_elementor_settings( $element['settings'], $source_site );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $idx ]['elements'] = $this->remap_elementor_elements_tree( $element['elements'], $source_site );
			}
		}
		return $elements;
	}

	/**
	 * Theme Builder conditions 里 singular 条件可能带具体 post ID，需 remap。
	 *
	 * @param mixed  $conditions  Conditions meta value.
	 * @param string $source_site Source site host.
	 * @return mixed
	 */
	private function remap_elementor_conditions( $conditions, $source_site ) {
		if ( ! is_array( $conditions ) ) {
			return $conditions;
		}
		$out = [];
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) && false !== strpos( $condition, '/' ) ) {
				$parts = explode( '/', $condition );
				if ( count( $parts ) >= 4 && 'singular' === $parts[1] && is_numeric( $parts[3] ) ) {
					$parts[3] = (string) $this->maybe_remap_source_post_id( (int) $parts[3], $source_site );
					$out[]    = implode( '/', $parts );
					continue;
				}
			}
			$out[] = $condition;
		}
		return $out;
	}
}
