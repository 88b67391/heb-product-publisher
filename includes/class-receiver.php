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
			'/get-imported',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_imported' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Hub 预览用：根据 source_post_id + source_site 查询目标站当前导入态。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_get_imported( $request ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$post_type      = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : 'products';
		$source_post_id = isset( $body['source_post_id'] ) ? (int) $body['source_post_id'] : 0;
		$source_site    = isset( $body['source_site'] ) ? sanitize_text_field( (string) $body['source_site'] ) : '';

		if ( ! post_type_exists( $post_type ) || $source_post_id <= 0 || '' === $source_site ) {
			return rest_ensure_response( [ 'exists' => false ] );
		}

		$existing_id = $this->find_by_source( $post_type, $source_post_id, $source_site );
		if ( $existing_id <= 0 ) {
			return rest_ensure_response( [ 'exists' => false ] );
		}

		$post = get_post( $existing_id );
		if ( ! $post ) {
			return rest_ensure_response( [ 'exists' => false ] );
		}

		$seo_map = class_exists( 'Heb_Product_Publisher_Sync' ) ? Heb_Product_Publisher_Sync::seo_key_map() : [];
		$seo     = [];
		foreach ( $seo_map as $sem => $mk ) {
			$v = get_post_meta( $existing_id, $mk, true );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$seo[ $sem ] = $v;
			}
		}

		return rest_ensure_response(
			[
				'exists'   => true,
				'post_id'  => $existing_id,
				'edit_url' => admin_url( 'post.php?post=' . $existing_id . '&action=edit' ),
				'title'    => $post->post_title,
				'excerpt'  => $post->post_excerpt,
				'content'  => $post->post_content,
				'status'   => $post->post_status,
				'seo'      => $seo,
				'modified' => get_post_modified_time( 'c', true, $existing_id ),
			]
		);
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

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		if ( empty( $body['secret'] ) || ! hash_equals( $secret, (string) $body['secret'] ) ) {
			return new \WP_Error( 'heb_pub_forbidden', __( 'Invalid secret.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$post_type = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : 'products';
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'heb_pub_bad_type', __( 'Post type does not exist.', 'heb-product-publisher' ), [ 'status' => 400 ] );
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
				$term_ids = $this->resolve_terms( $tax, $values );
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
				if ( is_string( $key ) && '' !== $key ) {
					update_field( $key, $value, $post_id );
				}
			}
		}

		if ( ! empty( $body['seo'] ) && is_array( $body['seo'] ) ) {
			$this->apply_seo_meta( $post_id, $body['seo'] );
		}

		if ( $source_post_id > 0 ) {
			update_post_meta( $post_id, '_heb_publisher_source_post_id', $source_post_id );
		}
		if ( '' !== $source_site ) {
			update_post_meta( $post_id, '_heb_publisher_source_site', $source_site );
		}

		return rest_ensure_response(
			[
				'success'  => true,
				'post_id'  => $post_id,
				'created'  => 0 === $existing_id,
				'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			]
		);
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
	 * 把 slug 或 {slug,name,parent} 解析为本地 term_id；不存在则创建。
	 *
	 * @param string               $tax    Taxonomy.
	 * @param array<int,mixed>     $values Items from payload.
	 * @return array<int,int>
	 */
	private function resolve_terms( $tax, array $values ) {
		$ids = [];
		foreach ( $values as $v ) {
			$slug = '';
			$name = '';
			if ( is_string( $v ) ) {
				$slug = sanitize_title( $v );
			} elseif ( is_array( $v ) ) {
				$slug = isset( $v['slug'] ) ? sanitize_title( (string) $v['slug'] ) : '';
				$name = isset( $v['name'] ) ? sanitize_text_field( (string) $v['name'] ) : '';
			}
			if ( '' === $slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$insert = wp_insert_term( '' !== $name ? $name : $slug, $tax, [ 'slug' => $slug ] );
			if ( ! is_wp_error( $insert ) && isset( $insert['term_id'] ) ) {
				$ids[] = (int) $insert['term_id'];
			}
		}
		return array_values( array_unique( $ids ) );
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
		return (int) $id;
	}

	/**
	 * @param mixed $value Payload fragment.
	 * @return mixed
	 */
	private function decode_acf_from_transport( $value ) {
		if ( is_array( $value ) && isset( $value['__heb_media'], $value['__heb_url'] )
			&& 'image' === $value['__heb_media'] && is_string( $value['__heb_url'] ) ) {
			$id = $this->sideload_url( $value['__heb_url'] );
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
}
