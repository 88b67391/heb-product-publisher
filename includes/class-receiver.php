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
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
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

		$translated_slug = isset( $body['slug_translated'] ) ? sanitize_title( (string) $body['slug_translated'] ) : '';
		$fallback_slug   = isset( $body['slug_fallback'] ) ? sanitize_title( (string) $body['slug_fallback'] ) : '';
		$new_slug        = '' !== $translated_slug ? $translated_slug : ( '' !== $fallback_slug ? $fallback_slug : sanitize_title( $name ) );

		// 1) 反查已存：先按 source_term_id meta，找不到再按 slug fallback。
		$existing_id = $this->find_term_by_source( $taxonomy, $source_term_id, $source_site );
		if ( ! $existing_id && '' !== $fallback_slug ) {
			$term = get_term_by( 'slug', $fallback_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
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
			$res = wp_insert_term( $name, $taxonomy, $args );
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
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
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
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
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
	 * 记录一次失败，用于限速。
	 */
	private function rate_limit_bump() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rl_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import( $request ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$post_type = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'heb_pub_bad_type', __( 'Post type does not exist.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed for import.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$title         = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';
		$slug          = isset( $body['slug'] ) ? sanitize_title( (string) $body['slug'] ) : '';
		$slug_strategy = isset( $body['slug_strategy'] ) ? sanitize_key( (string) $body['slug_strategy'] ) : 'localized';
		if ( ! in_array( $slug_strategy, [ 'source', 'localized' ], true ) ) {
			$slug_strategy = 'localized';
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
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
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

		$this->apply_elementor_payload( $post_id, $body );

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
				'success'  => true,
				'post_id'  => $post_id,
				'created'  => 0 === $existing_id,
				'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'permalink' => get_permalink( $post_id ),
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
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts. Try again later.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			$this->rate_limit_bump();
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
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
	 * 关键：当 source_site 是 payload 主调用方传入的（即产品/页面 payload 的 source_site）时，
	 * 我们能给"已有英文 slug 但未关联 source_term_id"的子站 term 隐式补一条 meta，做反向链接。
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

			// 2) 按 slug 反查（兼容老数据 + 隐式建立 source 反向 link）。
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					$tid = (int) $term->term_id;
					if ( $source_term_id > 0 && '' !== $source_site ) {
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

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = $path ? basename( $path ) : 'image.jpg';
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
			$name .= '.jpg';
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
	 * @param mixed $value Payload fragment.
	 * @return mixed
	 */
	private function decode_acf_from_transport( $value ) {
		if ( is_array( $value ) && isset( $value['__heb_media'], $value['__heb_url'] ) && is_string( $value['__heb_url'] ) ) {
			$kind = (string) $value['__heb_media'];
			$id   = $this->sideload_url( $value['__heb_url'] );
			if ( 'elementor_image' === $kind ) {
				if ( $id > 0 ) {
					$url = (string) wp_get_attachment_image_url( $id, 'full' );
					return [
						'id'     => (int) $id,
						'url'    => '' !== $url ? $url : (string) $value['__heb_url'],
						'alt'    => isset( $value['__heb_alt'] ) ? (string) $value['__heb_alt'] : '',
						'source' => isset( $value['__heb_source'] ) && '' !== $value['__heb_source'] ? (string) $value['__heb_source'] : 'library',
						'size'   => isset( $value['__heb_size'] ) ? (string) $value['__heb_size'] : '',
					];
				}
				// Sideload 失败：保留远端 URL 作为外链兜底（Elementor 仍能渲染但无本地 attachment）。
				return [
					'id'     => '',
					'url'    => (string) $value['__heb_url'],
					'alt'    => isset( $value['__heb_alt'] ) ? (string) $value['__heb_alt'] : '',
					'source' => isset( $value['__heb_source'] ) && '' !== $value['__heb_source'] ? (string) $value['__heb_source'] : 'library',
					'size'   => isset( $value['__heb_size'] ) ? (string) $value['__heb_size'] : '',
				];
			}
			// 默认 ACF image：返回 attachment ID（失败则空字符串）。
			return $id > 0 ? $id : '';
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->decode_acf_from_transport( $v );
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
	 */
	private function apply_elementor_payload( $post_id, array $body ) {
		$has_data = isset( $body['elementor_data'] ) && is_array( $body['elementor_data'] ) && ! empty( $body['elementor_data'] );

		if ( $has_data ) {
			$decoded_data = $this->decode_acf_from_transport( $body['elementor_data'] );
			if ( ! is_array( $decoded_data ) ) {
				$decoded_data = [];
			}
			$json = wp_json_encode( $decoded_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( is_string( $json ) ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
			}
		}

		if ( isset( $body['elementor_page_settings'] ) && is_array( $body['elementor_page_settings'] ) ) {
			$settings = $this->decode_acf_from_transport( $body['elementor_page_settings'] );
			if ( is_array( $settings ) ) {
				update_post_meta( $post_id, '_elementor_page_settings', $settings );
			}
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

		// 清缓存：写完 _elementor_data 后必须清 CSS 缓存，否则前端可能还在用旧版式。
		if ( $has_data ) {
			delete_post_meta( $post_id, '_elementor_css' );
			if ( class_exists( '\\Elementor\\Plugin' ) ) {
				try {
					$plugin = \Elementor\Plugin::$instance;
					if ( $plugin && isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
						$plugin->files_manager->clear_cache();
					}
				} catch ( \Throwable $e ) {
					// Elementor 未启用或内部异常时忽略，不阻塞导入流程。
					unset( $e );
				}
			}
		}
	}
}
