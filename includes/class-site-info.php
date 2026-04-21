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

		return rest_ensure_response(
			[
				'success'    => true,
				'site_url'   => home_url( '/' ),
				'home_host'  => wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
				'locale'     => get_locale(),
				'post_type'  => $post_type,
				'taxonomies' => $tax_out,
			]
		);
	}
}
