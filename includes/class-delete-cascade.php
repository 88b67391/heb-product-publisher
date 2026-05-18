<?php
/**
 * 主站删除 distributable post / term 时，异步通知所有远端站点删除对应本地副本。
 *
 * 仅 Hub 模式注册。删除请求通过 Action Scheduler 异步发出，避免拖慢主站后台。
 * 远端若识别到目标 post 被 `_heb_pp_locked` 锁定，会返回 success+locked 不删除。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Delete_Cascade {

	const AS_HOOK_POST = 'heb_pp_cascade_delete_post';
	const AS_HOOK_TERM = 'heb_pp_cascade_delete_term';

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
		// 永久删除前 enqueue（trash 不触发；用户只是想软删除时不该级联）。
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 1 );
		add_action( 'pre_delete_term', [ $this, 'on_pre_delete_term' ], 10, 2 );

		add_action( self::AS_HOOK_POST, [ $this, 'as_handle_post' ], 10, 1 );
		add_action( self::AS_HOOK_TERM, [ $this, 'as_handle_term' ], 10, 1 );
	}

	/**
	 * @param int $post_id Post id about to be deleted.
	 */
	public function on_before_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! function_exists( 'heb_pp_distributable_post_types' ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, heb_pp_distributable_post_types(), true ) ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$source_site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		as_enqueue_async_action(
			self::AS_HOOK_POST,
			[
				[
					'post_type'    => (string) $post->post_type,
					'source_id'    => (int) $post_id,
					'source_site'  => $source_site,
				],
			],
			'heb-pp-bootstrap'
		);
	}

	/**
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ) {
		if ( ! function_exists( 'heb_pp_distributable_taxonomies' ) ) {
			return;
		}
		if ( ! in_array( $taxonomy, heb_pp_distributable_taxonomies(), true ) ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$source_site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		as_enqueue_async_action(
			self::AS_HOOK_TERM,
			[
				[
					'taxonomy'    => (string) $taxonomy,
					'source_id'   => (int) $term_id,
					'source_site' => $source_site,
				],
			],
			'heb-pp-bootstrap'
		);
	}

	/**
	 * AS hook handler: post cascade.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function as_handle_post( $args ) {
		$args = $this->normalize_args( $args );
		if ( empty( $args['post_type'] ) || empty( $args['source_id'] ) || empty( $args['source_site'] ) ) {
			return;
		}
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $site ) {
			Heb_Product_Publisher_Remote_Client::post(
				$site,
				'/delete-by-source',
				[
					'kind'        => 'post',
					'post_type'   => (string) $args['post_type'],
					'source_id'   => (int) $args['source_id'],
					'source_site' => (string) $args['source_site'],
				],
				30
			);
		}
	}

	/**
	 * AS hook handler: term cascade.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function as_handle_term( $args ) {
		$args = $this->normalize_args( $args );
		if ( empty( $args['taxonomy'] ) || empty( $args['source_id'] ) || empty( $args['source_site'] ) ) {
			return;
		}
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $site ) {
			Heb_Product_Publisher_Remote_Client::post(
				$site,
				'/delete-by-source',
				[
					'kind'        => 'term',
					'taxonomy'    => (string) $args['taxonomy'],
					'source_id'   => (int) $args['source_id'],
					'source_site' => (string) $args['source_site'],
				],
				30
			);
		}
	}

	/**
	 * @param mixed $args Args.
	 * @return array<string,mixed>
	 */
	private function normalize_args( $args ) {
		if ( is_array( $args ) ) {
			if ( isset( $args['post_type'] ) || isset( $args['taxonomy'] ) ) {
				return $args;
			}
			$first = reset( $args );
			if ( is_array( $first ) ) {
				return $first;
			}
		}
		return [];
	}
}
