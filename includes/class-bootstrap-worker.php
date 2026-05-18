<?php
/**
 * Site Bootstrap worker：AS hook 真正执行体。
 *
 * 每个 task 处理单一对象（一个 term 或一个 post），失败不抛出（只记到 status.errors），
 * 让队列里的后续任务继续跑。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bootstrap_Worker {

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
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_PROBE, [ $this, 'handle_probe' ], 10, 1 );
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_TERM, [ $this, 'handle_term' ], 10, 1 );
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_POST, [ $this, 'handle_post' ], 10, 1 );
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_MENU, [ $this, 'handle_menu' ], 10, 1 );
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_SETTINGS, [ $this, 'handle_settings' ], 10, 1 );
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_FINALIZE, [ $this, 'handle_finalize' ], 10, 1 );
	}

	/**
	 * @param array<string,mixed> $args Args (job_id).
	 * @return void
	 */
	public function handle_probe( $args ) {
		$args   = $this->normalize_args( $args );
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( '' === $job_id || ! $this->job_can_run( $job_id ) ) {
			return;
		}
		$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
		if ( ! $site ) {
			$this->mark_failed( $job_id, __( '目标站点配置丢失。', 'heb-product-publisher' ) );
			return;
		}

		Heb_Product_Publisher_Bootstrap_Status::update( $job_id, [ 'status' => Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING ] );

		$res = Heb_Product_Publisher_Remote_Client::post(
			$site,
			'/site-info',
			[ 'post_type' => 'products' ],
			20
		);
		if ( is_wp_error( $res ) ) {
			$this->mark_failed( $job_id, sprintf( __( 'Probe 失败：%s', 'heb-product-publisher' ), $res->get_error_message() ) );
			return;
		}
		$locale = isset( $res['locale'] ) ? (string) $res['locale'] : '';
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', sprintf( __( 'Probe OK，目标站 locale = %s', 'heb-product-publisher' ), '' !== $locale ? $locale : '(unknown)' ) );

		Heb_Product_Publisher_Bootstrap_Queue::advance_stage( $job_id );
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_term( $args ) {
		$args    = $this->normalize_args( $args );
		$job_id  = (string) ( $args['job_id'] ?? '' );
		$term_id = (int) ( $args['term_id'] ?? 0 );
		if ( '' === $job_id || ! $this->job_can_run( $job_id ) ) {
			return;
		}
		$stage = Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$payload = Heb_Product_Publisher_Term_Sync::build_payload( $term_id );
			if ( empty( $payload ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->maybe_advance_stage( $job_id );
				return;
			}
			$translator = new Heb_Product_Publisher_Translator();
			$term_sync  = new Heb_Product_Publisher_Term_Sync();
			$res        = $term_sync->distribute_to_site( $term_id, $payload, (string) $payload['source_locale'], $site, $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'term', $term_id, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'term', $term_id, $e->getMessage() );
		}
		$this->maybe_advance_stage( $job_id );
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_post( $args ) {
		$args    = $this->normalize_args( $args );
		$job_id  = (string) ( $args['job_id'] ?? '' );
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( '' === $job_id || ! $this->job_can_run( $job_id ) ) {
			return;
		}
		$stage = Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$payload = Heb_Product_Publisher_Sync::build_payload( $post_id );
			if ( empty( $payload ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->maybe_advance_stage( $job_id );
				return;
			}
			$translator = new Heb_Product_Publisher_Translator();
			$hub_ui     = Heb_Product_Publisher_Hub_UI::instance();
			$res        = $hub_ui->distribute_to_site( $post_id, $payload, (string) $payload['source_locale'], $site, [], $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'post', $post_id, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'post', $post_id, $e->getMessage() );
		}
		$this->maybe_advance_stage( $job_id );
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_menu( $args ) {
		$args    = $this->normalize_args( $args );
		$job_id  = (string) ( $args['job_id'] ?? '' );
		$menu_id = (int) ( $args['menu_id'] ?? 0 );
		if ( '' === $job_id || ! $this->job_can_run( $job_id ) ) {
			return;
		}
		$stage = Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$payload = Heb_Product_Publisher_Menu_Sync::build_payload( $menu_id );
			if ( empty( $payload ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->maybe_advance_stage( $job_id );
				return;
			}
			$translator = new Heb_Product_Publisher_Translator();
			$menu_sync  = new Heb_Product_Publisher_Menu_Sync();
			$res        = $menu_sync->distribute_to_site( $menu_id, $payload, (string) $payload['source_locale'], $site, $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'menu', $menu_id, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
				Heb_Product_Publisher_Bootstrap_Status::add_log(
					$job_id,
					'info',
					sprintf( __( 'Menu #%1$d → site = %2$d items', 'heb-product-publisher' ), $menu_id, (int) ( $res['items_imported'] ?? 0 ) )
				);
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'menu', $menu_id, $e->getMessage() );
		}
		$this->maybe_advance_stage( $job_id );
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_settings( $args ) {
		$args   = $this->normalize_args( $args );
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( '' === $job_id || ! $this->job_can_run( $job_id ) ) {
			return;
		}
		$stage = Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$payload    = Heb_Product_Publisher_Settings_Sync::build_payload();
			$translator = new Heb_Product_Publisher_Translator();
			$settings   = new Heb_Product_Publisher_Settings_Sync();
			$res        = $settings->distribute_to_site( $payload, (string) $payload['source_locale'], $site, $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'settings', 0, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
				Heb_Product_Publisher_Bootstrap_Status::add_log(
					$job_id,
					'info',
					sprintf(
						__( 'Settings applied: %1$d, skipped: %2$d', 'heb-product-publisher' ),
						count( (array) ( $res['applied'] ?? [] ) ),
						count( (array) ( $res['skipped'] ?? [] ) )
					)
				);
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'settings', 0, $e->getMessage() );
		}
		$this->maybe_advance_stage( $job_id );
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_finalize( $args ) {
		$args   = $this->normalize_args( $args );
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( '' === $job_id ) {
			return;
		}
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec ) {
			return;
		}

		$totals = [
			'done'    => 0,
			'failed'  => 0,
			'skipped' => 0,
			'queued'  => 0,
		];
		foreach ( $rec['progress'] as $p ) {
			$totals['done']    += (int) ( $p['done'] ?? 0 );
			$totals['failed']  += (int) ( $p['failed'] ?? 0 );
			$totals['skipped'] += (int) ( $p['skipped'] ?? 0 );
			$totals['queued']  += (int) ( $p['queued'] ?? 0 );
		}

		$status = Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE;
		if ( $totals['queued'] > 0 && $totals['failed'] === $totals['queued'] ) {
			$status = Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED;
		}

		Heb_Product_Publisher_Bootstrap_Status::update(
			$job_id,
			[
				'status'        => $status,
				'current_stage' => Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED,
				'finished_at'   => time(),
			]
		);
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			$status === Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE ? 'info' : 'error',
			sprintf(
				__( 'Job finished: status=%1$s, done=%2$d, failed=%3$d, skipped=%4$d (of %5$d).', 'heb-product-publisher' ),
				$status,
				$totals['done'],
				$totals['failed'],
				$totals['skipped'],
				$totals['queued']
			)
		);

		/**
		 * 让外部插件（或日志/通知模块）订阅 bootstrap 完成事件。
		 *
		 * @param string              $job_id Job id.
		 * @param array<string,mixed> $rec    Full job record.
		 * @param array<string,int>   $totals Aggregated totals.
		 */
		do_action( 'heb_pp_bootstrap_finalized', $job_id, $rec, $totals );
	}

	/**
	 * 进度推进时自检：当前 stage 全部完成 → advance。
	 * 写入 progress 再读回来，由于 wp_options 是原子写入这里足够避免大部分竞态。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private function maybe_advance_stage( $job_id ) {
		Heb_Product_Publisher_Bootstrap_Queue::advance_stage( $job_id );
	}

	/**
	 * @param string $job_id Job id.
	 * @return bool
	 */
	private function job_can_run( $job_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec ) {
			return false;
		}
		$blocked = in_array(
			$rec['status'],
			[
				Heb_Product_Publisher_Bootstrap_Status::STATUS_CANCELLED,
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
				Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED,
			],
			true
		);
		return ! $blocked;
	}

	/**
	 * @param string $job_id  Job id.
	 * @param string $message Reason.
	 * @return void
	 */
	private function mark_failed( $job_id, $message ) {
		Heb_Product_Publisher_Bootstrap_Status::update(
			$job_id,
			[
				'status'      => Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED,
				'finished_at' => time(),
			]
		);
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'error', (string) $message );
	}

	/**
	 * Action Scheduler 的 args 包成数组 → 取第一个。同时兼容直接传 args 的情况。
	 *
	 * @param mixed $args Args.
	 * @return array<string,mixed>
	 */
	private function normalize_args( $args ) {
		if ( is_array( $args ) ) {
			if ( isset( $args['job_id'] ) ) {
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
