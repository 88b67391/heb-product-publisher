<?php
/**
 * Receiver 端：暴露站点 locale 与可分发 post type 的 taxonomies/terms。
 *
 * 路径：POST /wp-json/heb-publisher/v1/site-info
 * Body: { "secret": "<shared>", "post_type": "products" }
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Site_Info {

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

	public function register_routes() {
		$secret = Heb_Product_Publisher_Receiver::get_secret();
		if ( '' === $secret ) {
			return;
		}
		register_rest_route(
			'heb-publisher/v1',
			'/site-info',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_site_info' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_site_info( $request ) {
		$secret = Heb_Product_Publisher_Receiver::get_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'heb_pub_disabled', __( 'Receiver is not configured.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}
		if ( ! $this->rate_limit_ok() ) {
			return new \WP_Error( 'heb_pub_rate_limited', __( 'Too many failed attempts.', 'heb-product-publisher' ), [ 'status' => 429 ] );
		}

		$body = $request->get_json_params();
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

		$allowed = Heb_Product_Publisher_Receiver::allowed_post_types();

		$post_type = isset( $body['post_type'] ) ? sanitize_key( (string) $body['post_type'] ) : '';
		if ( '' === $post_type ) {
			$post_type = ! empty( $allowed ) ? (string) $allowed[0] : 'products';
		}
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'heb_pub_bad_type', __( 'Post type does not exist.', 'heb-product-publisher' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $post_type, $allowed, true ) ) {
			return new \WP_Error( 'heb_pub_forbidden_type', __( 'Post type is not allowed.', 'heb-product-publisher' ), [ 'status' => 403 ] );
		}

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$tax_out    = [];
		foreach ( $taxonomies as $tax_key => $tax_obj ) {
			$terms = get_terms(
				[
					'taxonomy'   => $tax_key,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				]
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$tax_out[ $tax_key ] = [
				'label' => $tax_obj->labels->name,
				'terms' => array_values(
					array_map(
						static function ( $t ) {
							return [
								'id'     => (int) $t->term_id,
								'name'   => (string) $t->name,
								'slug'   => (string) $t->slug,
								'parent' => (int) $t->parent,
							];
						},
						$terms
					)
				),
			];
		}

		$response = [
			'success'                  => true,
			'site_url'                 => home_url( '/' ),
			'home_host'                => wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'locale'                   => get_locale(),
			'post_type'                => $post_type,
			'taxonomies'               => $tax_out,
			'distributable_post_types' => $allowed,
			'plugin_version'           => defined( 'HEB_PP_VERSION' ) ? HEB_PP_VERSION : '',
		];
		if ( ! empty( $body['include_config'] ) ) {
			$response['config'] = self::collect_config_snapshot();
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Bootstrap 验收用：子站关键配置快照。
	 *
	 * @return array<string,mixed>
	 */
	public static function collect_config_snapshot() {
		$post_counts = [];
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			$post_counts[ $pt ] = (int) wp_count_posts( $pt )->publish;
		}
		return [
			'permalink_structure'  => (string) get_option( 'permalink_structure', '' ),
			'blogname'             => (string) get_option( 'blogname', '' ),
			'blogdescription'      => (string) get_option( 'blogdescription', '' ),
			'show_on_front'        => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'        => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts'       => (int) get_option( 'page_for_posts', 0 ),
			'elementor_active_kit' => (int) get_option( 'elementor_active_kit', 0 ),
			'post_counts'          => $post_counts,
		];
	}

	/**
	 * @return bool
	 */
	private function rate_limit_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rl_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		return $n < 30;
	}

	/**
	 * @return void
	 */
	private function rate_limit_bump() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^a-fA-F0-9:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'heb_pp_rl_' . md5( (string) $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
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
}
