<?php
/**
 * Term / Post 旧 slug 301 重定向。
 *
 * 主站再次分发时，目标站若 slug 改了，旧 slug 会写入 `_heb_pp_old_slugs` 数组。
 * 这里在前端 404 时反查这些旧 slug，找到对应 term 或 post 就 301 跳到当前永久链接，
 * 保住已经被搜索引擎索引或者别处贴出去的链接的 SEO 信号。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Term_Redirect {

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
		add_action( 'template_redirect', [ $this, 'maybe_redirect_old_slug' ] );
	}

	public function maybe_redirect_old_slug() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! is_404() ) {
			return;
		}
		if ( ! function_exists( 'heb_pp_distributable_taxonomies' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return;
		}
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		if ( empty( $segments ) ) {
			return;
		}
		$last_segment = $segments[ count( $segments ) - 1 ];
		$maybe_slug   = sanitize_title( rawurldecode( $last_segment ) );
		if ( '' === $maybe_slug ) {
			return;
		}

		foreach ( heb_pp_distributable_taxonomies() as $tax ) {
			$terms = get_terms(
				[
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 5,
					'meta_query' => [
						[
							'key'     => '_heb_pp_old_slugs',
							'value'   => '"' . $maybe_slug . '"',
							'compare' => 'LIKE',
						],
					],
				]
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$old = get_term_meta( $term->term_id, '_heb_pp_old_slugs', true );
				if ( ! is_array( $old ) || ! in_array( $maybe_slug, $old, true ) ) {
					continue;
				}
				$new_url = get_term_link( $term );
				if ( is_wp_error( $new_url ) || ! is_string( $new_url ) || '' === $new_url ) {
					continue;
				}
				wp_safe_redirect( $new_url, 301 );
				exit;
			}
		}

		if ( function_exists( 'heb_pp_distributable_post_types' ) ) {
			$post_types = heb_pp_distributable_post_types();
			if ( ! empty( $post_types ) ) {
				$posts = get_posts(
					[
						'post_type'      => $post_types,
						'post_status'    => 'any',
						'posts_per_page' => 5,
						'meta_query'     => [
							[
								'key'     => '_heb_pp_old_slugs',
								'value'   => '"' . $maybe_slug . '"',
								'compare' => 'LIKE',
							],
						],
					]
				);
				if ( is_array( $posts ) ) {
					foreach ( $posts as $post ) {
						if ( ! $post instanceof \WP_Post ) {
							continue;
						}
						$old = get_post_meta( $post->ID, '_heb_pp_old_slugs', true );
						if ( ! is_array( $old ) || ! in_array( $maybe_slug, $old, true ) ) {
							continue;
						}
						$new_url = get_permalink( $post );
						if ( ! is_string( $new_url ) || '' === $new_url ) {
							continue;
						}
						wp_safe_redirect( $new_url, 301 );
						exit;
					}
				}
			}
		}
	}
}
