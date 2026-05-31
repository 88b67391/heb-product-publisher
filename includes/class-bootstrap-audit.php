<?php
/**
 * Bootstrap 完成后对照主站做配置验收（permalink、站点名、内容数量等）。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bootstrap_Audit {

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
		add_action( 'heb_pp_bootstrap_finalized', [ $this, 'run' ], 10, 3 );
	}

	/**
	 * @param string              $job_id Job id.
	 * @param array<string,mixed> $rec    Job record.
	 * @param array<string,int>   $totals Totals.
	 * @return void
	 */
	public function run( $job_id, array $rec, array $totals ) {
		$opts = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
		if ( ! empty( $opts['dry_run'] ) ) {
			return;
		}
		if ( ! in_array(
			$rec['status'] ?? '',
			[
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS,
			],
			true
		) ) {
			return;
		}

		$site_id = (string) ( $rec['site_id'] ?? '' );
		$site    = Heb_Product_Publisher_Admin_Settings::get_site( $site_id );
		if ( ! $site ) {
			return;
		}

		$checks = [];
		$checks[] = $this->check_permalink( $site );
		$checks[] = $this->check_blogname( $site );
		$checks[] = $this->check_post_counts( $site );
		$checks[] = $this->check_elementor_kit( $site );

		Heb_Product_Publisher_Bootstrap_Status::update(
			(string) $job_id,
			[
				'audit' => [
					'at'     => time(),
					'checks' => $checks,
				],
			]
		);

		$fail_n = 0;
		foreach ( $checks as $c ) {
			if ( empty( $c['ok'] ) ) {
				++$fail_n;
			}
		}
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			(string) $job_id,
			$fail_n > 0 ? 'warning' : 'info',
			sprintf(
				/* translators: 1: pass count, 2: total checks */
				__( '配置验收：%1$d/%2$d 项通过', 'heb-product-publisher' ),
				count( $checks ) - $fail_n,
				count( $checks )
			)
		);
	}

	/**
	 * @param array<string,string> $site Remote site.
	 * @return array{ok:bool,id:string,message:string}
	 */
	private function check_permalink( array $site ) {
		$hub = (string) get_option( 'permalink_structure', '' );
		$cfg = $this->fetch_remote_config( $site );
		if ( null === $cfg ) {
			return [
				'ok'      => false,
				'id'      => 'permalink',
				'message' => __( '无法读取子站配置（site-info）', 'heb-product-publisher' ),
			];
		}
		$remote = isset( $cfg['permalink_structure'] ) ? (string) $cfg['permalink_structure'] : '';
		$ok     = $hub === $remote;
		return [
			'ok'      => $ok,
			'id'      => 'permalink',
			'message' => $ok
				? __( '固定链接结构与主站一致', 'heb-product-publisher' )
				: sprintf(
					/* translators: 1: hub structure, 2: remote structure */
					__( '固定链接不一致：主站「%1$s」子站「%2$s」', 'heb-product-publisher' ),
					$hub,
					$remote
				),
		];
	}

	/**
	 * @param array<string,string> $site Remote site.
	 * @return array{ok:bool,id:string,message:string}
	 */
	private function check_blogname( array $site ) {
		$cfg = $this->fetch_remote_config( $site );
		if ( null === $cfg ) {
			return [
				'ok'      => false,
				'id'      => 'blogname',
				'message' => __( '无法读取子站 blogname', 'heb-product-publisher' ),
			];
		}
		$name = isset( $cfg['blogname'] ) ? trim( (string) $cfg['blogname'] ) : '';
		$ok   = '' !== $name;
		return [
			'ok'      => $ok,
			'id'      => 'blogname',
			'message' => $ok
				? sprintf(
					/* translators: %s: site title */
					__( '站点标题已设置：%s', 'heb-product-publisher' ),
					$name
				)
				: __( '站点标题为空（settings 阶段可能未跑或未翻译）', 'heb-product-publisher' ),
		];
	}

	/**
	 * @param array<string,string> $site Remote site.
	 * @return array{ok:bool,id:string,message:string}
	 */
	private function check_post_counts( array $site ) {
		$cfg = $this->fetch_remote_config( $site );
		if ( null === $cfg || empty( $cfg['post_counts'] ) || ! is_array( $cfg['post_counts'] ) ) {
			return [
				'ok'      => false,
				'id'      => 'posts',
				'message' => __( '无法读取子站内容数量', 'heb-product-publisher' ),
			];
		}
		$issues = [];
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			$hub_n    = (int) wp_count_posts( $pt )->publish;
			$remote_n = isset( $cfg['post_counts'][ $pt ] ) ? (int) $cfg['post_counts'][ $pt ] : 0;
			if ( $hub_n > 0 && $remote_n <= 0 ) {
				$issues[] = $pt . ': 0/' . $hub_n;
			} elseif ( $hub_n > 0 && $remote_n < (int) floor( $hub_n * 0.8 ) ) {
				$issues[] = $pt . ': ' . $remote_n . '/' . $hub_n;
			}
		}
		$ok = empty( $issues );
		return [
			'ok'      => $ok,
			'id'      => 'posts',
			'message' => $ok
				? __( '主要 post type 数量正常', 'heb-product-publisher' )
				: sprintf(
					/* translators: %s: issue list */
					__( '内容数量偏少：%s', 'heb-product-publisher' ),
					implode( ', ', $issues )
				),
		];
	}

	/**
	 * @param array<string,string> $site Remote site.
	 * @return array{ok:bool,id:string,message:string}
	 */
	private function check_elementor_kit( array $site ) {
		$hub_kit = (int) get_option( 'elementor_active_kit', 0 );
		if ( $hub_kit <= 0 ) {
			return [
				'ok'      => true,
				'id'      => 'elementor_kit',
				'message' => __( '主站未使用 Elementor Kit，跳过', 'heb-product-publisher' ),
			];
		}
		$cfg = $this->fetch_remote_config( $site );
		if ( null === $cfg ) {
			return [
				'ok'      => false,
				'id'      => 'elementor_kit',
				'message' => __( '无法读取子站 Elementor Kit', 'heb-product-publisher' ),
			];
		}
		$remote_kit = isset( $cfg['elementor_active_kit'] ) ? (int) $cfg['elementor_active_kit'] : 0;
		$ok         = $remote_kit > 0;
		return [
			'ok'      => $ok,
			'id'      => 'elementor_kit',
			'message' => $ok
				? __( 'Elementor Kit 已映射到子站', 'heb-product-publisher' )
				: __( '子站 Elementor Kit 未设置（需同步 elementor_library + settings）', 'heb-product-publisher' ),
		];
	}

	/**
	 * @param array<string,string> $site Remote site.
	 * @return array<string,mixed>|null
	 */
	private function fetch_remote_config( array $site ) {
		static $cache = [];
		$sid = isset( $site['id'] ) ? (string) $site['id'] : md5( wp_json_encode( $site ) );
		if ( isset( $cache[ $sid ] ) ) {
			return $cache[ $sid ];
		}
		$res = Heb_Product_Publisher_Remote_Client::post(
			$site,
			'/site-info',
			[
				'post_type'      => heb_pp_distributable_post_types()[0] ?? 'products',
				'include_config' => true,
			],
			20
		);
		if ( is_wp_error( $res ) || empty( $res['config'] ) || ! is_array( $res['config'] ) ) {
			$cache[ $sid ] = null;
			return null;
		}
		$cache[ $sid ] = $res['config'];
		return $cache[ $sid ];
	}
}
