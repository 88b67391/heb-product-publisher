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
		add_action( Heb_Product_Publisher_Bootstrap_Queue::HOOK_WATCHDOG, [ $this, 'handle_watchdog' ], 10, 1 );
	}

	/** @var bool Bootstrap 队列 item 执行中（用于翻译 strict 模式）。 */
	private static $in_bootstrap_item = false;

	/**
	 * @return bool
	 */
	public static function in_bootstrap_item() {
		return self::$in_bootstrap_item;
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

		$probe_pt = 'page';
		if ( ! post_type_exists( 'page' ) ) {
			$pts = heb_pp_distributable_post_types();
			$probe_pt = ! empty( $pts[0] ) ? (string) $pts[0] : 'post';
		}
		$res = Heb_Product_Publisher_Remote_Client::post(
			$site,
			'/site-info',
			[ 'post_type' => $probe_pt ],
			20
		);
		if ( is_wp_error( $res ) ) {
			$this->mark_failed( $job_id, sprintf( __( 'Probe 失败：%s', 'heb-product-publisher' ), $res->get_error_message() ) );
			return;
		}
		$locale = isset( $res['locale'] ) ? (string) $res['locale'] : '';
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', sprintf( __( 'Probe OK，目标站 locale = %s', 'heb-product-publisher' ), '' !== $locale ? $locale : '(unknown)' ) );

		$opts = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];

		if ( ! empty( $opts['dry_run'] ) ) {
			$counts = Heb_Product_Publisher_Bootstrap_Queue::count_dispatchable( $rec );
			Heb_Product_Publisher_Bootstrap_Status::update(
				$job_id,
				[
					'status'        => Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
					'current_stage' => Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED,
					'finished_at'   => time(),
				]
			);
			Heb_Product_Publisher_Bootstrap_Status::add_log(
				$job_id,
				'info',
				sprintf(
					__( 'Dry run 完成：terms=%1$d, posts=%2$d, menus=%3$d, settings=%4$d（未实际推送）', 'heb-product-publisher' ),
					$counts['terms'],
					$counts['posts'],
					$counts['menus'],
					$counts['settings']
				)
			);
			return;
		}

		if ( ! empty( $opts['retry_mode'] ) && ! empty( $opts['retry_items'] ) && is_array( $opts['retry_items'] ) ) {
			Heb_Product_Publisher_Bootstrap_Queue::dispatch_retry_items( $job_id, $opts['retry_items'] );
			return;
		}

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
		if ( ! $this->job_in_stage( $job_id, $stage ) ) {
			return;
		}
		self::$in_bootstrap_item = true;
		$t0    = 0.0;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$term = get_term( $term_id );
			$label = ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '';
			$t0 = $this->begin_item( $job_id, 'term', $term_id, $label );
			$payload = Heb_Product_Publisher_Term_Sync::build_payload( $term_id );
			if ( empty( $payload ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->finish_item( $job_id, 'term', $term_id, $t0, true, __( '空 payload，跳过', 'heb-product-publisher' ) );
				$this->maybe_advance_stage( $job_id );
				return;
			}
			$translator = new Heb_Product_Publisher_Translator();
			$term_sync  = new Heb_Product_Publisher_Term_Sync();
			$res        = $term_sync->distribute_to_site( $term_id, $payload, (string) $payload['source_locale'], $site, $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'term', $term_id, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
				$this->finish_item( $job_id, 'term', $term_id, $t0, false );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
				$this->finish_item( $job_id, 'term', $term_id, $t0, true );
				$this->log_distribute_warns( $job_id, 'term', $term_id, $res );
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'term', $term_id, $e->getMessage() );
			$this->finish_item( $job_id, 'term', $term_id, $t0, false );
		} finally {
			self::$in_bootstrap_item = false;
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
		if ( ! $this->job_in_stage( $job_id, $stage ) ) {
			$this->skip_stale_stage_action( $job_id, 'post', $post_id );
			return;
		}
		self::$in_bootstrap_item = true;
		$t0    = 0.0;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$t0 = $this->begin_item( $job_id, 'post', $post_id, get_the_title( $post_id ) );
			$payload = Heb_Product_Publisher_Sync::build_payload( $post_id );
			if ( empty( $payload ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->finish_item( $job_id, 'post', $post_id, $t0, true, __( '空 payload，跳过', 'heb-product-publisher' ) );
				$this->maybe_advance_stage( $job_id );
				return;
			}
			$translator = new Heb_Product_Publisher_Translator();
			$hub_ui     = Heb_Product_Publisher_Hub_UI::instance();
			$res        = $hub_ui->distribute_to_site( $post_id, $payload, (string) $payload['source_locale'], $site, [], $translator );
			if ( ! empty( $res['locked'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'skipped' );
				$this->finish_item( $job_id, 'post', $post_id, $t0, true, __( '已锁定，跳过', 'heb-product-publisher' ) );
			} elseif ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'post', $post_id, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
				$this->finish_item( $job_id, 'post', $post_id, $t0, false );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
				$this->finish_item( $job_id, 'post', $post_id, $t0, true );
				$this->log_distribute_warns( $job_id, 'post', $post_id, $res );
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'post', $post_id, $e->getMessage() );
			$this->finish_item( $job_id, 'post', $post_id, $t0, false );
		} finally {
			self::$in_bootstrap_item = false;
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
		if ( ! $this->job_in_stage( $job_id, $stage ) ) {
			return;
		}
		self::$in_bootstrap_item = true;
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
			$opts       = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
			$bind_locs  = ! empty( $opts['scope_menu_locations'] );
			$res        = $menu_sync->distribute_to_site( $menu_id, $payload, (string) $payload['source_locale'], $site, $translator, $bind_locs );
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
		} finally {
			self::$in_bootstrap_item = false;
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
		if ( ! $this->job_in_stage( $job_id, $stage ) ) {
			return;
		}
		self::$in_bootstrap_item = true;
		$t0 = 0.0;
		try {
			$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $rec['site_id'] );
			if ( ! $site ) {
				throw new \RuntimeException( 'site config missing' );
			}
			$t0 = $this->begin_item( $job_id, 'settings', 0, __( 'WordPress 全局设置', 'heb-product-publisher' ) );
			$payload    = Heb_Product_Publisher_Settings_Sync::build_payload();
			$translator = new Heb_Product_Publisher_Translator();
			$settings   = new Heb_Product_Publisher_Settings_Sync();
			$res        = $settings->distribute_to_site( $payload, (string) $payload['source_locale'], $site, $translator );
			if ( empty( $res['ok'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
				Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'settings', 0, isset( $res['message'] ) ? (string) $res['message'] : 'unknown' );
				$this->finish_item( $job_id, 'settings', 0, $t0, false );
			} else {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'done' );
				$this->finish_item(
					$job_id,
					'settings',
					0,
					$t0,
					true,
					sprintf(
						__( '✓ 完成（applied %1$d, skipped %2$d）', 'heb-product-publisher' ),
						count( (array) ( $res['applied'] ?? [] ) ),
						count( (array) ( $res['skipped'] ?? [] ) )
					)
				);
			}
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'failed' );
			Heb_Product_Publisher_Bootstrap_Status::add_error( $job_id, 'settings', 0, $e->getMessage() );
			$this->finish_item( $job_id, 'settings', 0, $t0, false );
		} finally {
			self::$in_bootstrap_item = false;
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
		} elseif ( $totals['failed'] > 0 ) {
			$status = Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS;
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
	 * 看门狗：job 仍在跑时每 2 分钟检查一次；疑似卡住时写 warning 并轻推 AS。
	 *
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_watchdog( $args ) {
		$args   = $this->normalize_args( $args );
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( '' === $job_id ) {
			return;
		}
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec || ! $this->job_can_run( $job_id ) ) {
			return;
		}

		Heb_Product_Publisher_Bootstrap_Queue::schedule_watchdog( $job_id );

		$enriched = Heb_Product_Publisher_Bootstrap_Status::enrich( $rec );
		$activity = isset( $enriched['activity'] ) && is_array( $enriched['activity'] ) ? $enriched['activity'] : [];
		$stalled  = ! empty( $activity['queue_stalled'] );
		$stale    = ! empty( $activity['stale'] );
		if ( ! $stalled && ! $stale ) {
			return;
		}

		$pending = (int) ( $activity['pending_actions'] ?? 0 );
		$running = (int) ( $activity['running_actions'] ?? 0 );
		$failed  = (int) ( $activity['failed_actions'] ?? 0 );
		$idle    = (int) ( $activity['idle_seconds'] ?? 0 );
		$remain  = (int) ( $activity['stage_remaining'] ?? 0 );

		if ( $stalled ) {
			$cur = isset( $rec['current_item'] ) && is_array( $rec['current_item'] ) ? $rec['current_item'] : null;
			if ( $cur && ! empty( $cur['source_id'] ) ) {
				Heb_Product_Publisher_Bootstrap_Status::add_log(
					$job_id,
					'warning',
					sprintf(
						/* translators: 1: type, 2: id */
						__( '检测到孤儿任务 %1$s #%2$d（AS 已空但未写入进度），将清除并补排…', 'heb-product-publisher' ),
						(string) ( $cur['type'] ?? 'item' ),
						(int) $cur['source_id']
					)
				);
				Heb_Product_Publisher_Bootstrap_Status::clear_current_item( $job_id );
			}
			Heb_Product_Publisher_Bootstrap_Status::add_log(
				$job_id,
				'warning',
				sprintf(
					/* translators: 1: remaining count, 2: idle seconds, 3: failed AS count */
					__( '队列停滞：本阶段还剩 %1$d 项，但 Action Scheduler 无待处理/运行中任务（已空闲 %2$d 秒；AS 失败 %3$d 项）。正在补排并推进…', 'heb-product-publisher' ),
					$remain,
					$idle,
					$failed
				)
			);
			Heb_Product_Publisher_Bootstrap_Queue::rescue_stalled_stage( $job_id );
		} elseif ( $stale ) {
			Heb_Product_Publisher_Bootstrap_Status::add_log(
				$job_id,
				'warning',
				sprintf(
					/* translators: 1: idle seconds, 2: pending AS actions, 3: running AS actions */
					__( '已 %1$d 秒无进度更新（Opus 单条可跑 10–20 分钟属正常）；队列待处理 %2$d 项、运行中 %3$d 项，正在尝试推进…', 'heb-product-publisher' ),
					$idle,
					$pending,
					$running
				)
			);
		}
		Heb_Product_Publisher_Bootstrap_Queue::nudge_queue_runner( $job_id );
	}

	/**
	 * @param string $job_id    Job id.
	 * @param string $type      Object type.
	 * @param int    $source_id Source id.
	 * @param string $label     Label.
	 * @return float microtime start.
	 */
	private function begin_item( $job_id, $type, $source_id, $label = '' ) {
		Heb_Product_Publisher_Bootstrap_Status::set_current_item( $job_id, $type, $source_id, $label );
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf(
				/* translators: 1: type, 2: id, 3: label */
				__( '%1$s #%2$d «%3$s» 开始…', 'heb-product-publisher' ),
				$type,
				$source_id,
				'' !== $label ? $label : '-'
			)
		);
		return microtime( true );
	}

	/**
	 * @param string      $job_id    Job id.
	 * @param string      $type      Object type.
	 * @param int         $source_id Source id.
	 * @param float       $t0        Start microtime.
	 * @param bool        $ok        Success.
	 * @param string|null $note      Optional note instead of ✓/✗.
	 * @return void
	 */
	private function finish_item( $job_id, $type, $source_id, $t0, $ok, $note = null ) {
		$elapsed = $t0 > 0 ? max( 0, microtime( true ) - $t0 ) : 0;
		Heb_Product_Publisher_Bootstrap_Status::clear_current_item( $job_id );
		if ( null !== $note && '' !== $note ) {
			$msg = sprintf(
				/* translators: 1: type, 2: id, 3: note, 4: seconds */
				__( '%1$s #%2$d %3$s (%.0fs)', 'heb-product-publisher' ),
				$type,
				$source_id,
				$note,
				$elapsed
			);
		} elseif ( $ok ) {
			$msg = sprintf(
				/* translators: 1: type, 2: id, 3: seconds */
				__( '%1$s #%2$d ✓ 完成 (%.0fs)', 'heb-product-publisher' ),
				$type,
				$source_id,
				$elapsed
			);
		} else {
			$msg = sprintf(
				/* translators: 1: type, 2: id, 3: seconds */
				__( '%1$s #%2$d ✗ 失败 (%.0fs)', 'heb-product-publisher' ),
				$type,
				$source_id,
				$elapsed
			);
		}
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, $ok ? 'info' : 'warning', $msg );
	}

	/**
	 * @param string $job_id Job id.
	 * @param string $stage  Expected stage.
	 * @return bool
	 */
	private function job_in_stage( $job_id, $stage ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		return $rec && (string) ( $rec['current_stage'] ?? '' ) === (string) $stage;
	}

	/**
	 * @param string $job_id    Job id.
	 * @param string $type      Object type.
	 * @param int    $source_id Source id.
	 * @return void
	 */
	private function skip_stale_stage_action( $job_id, $type, $source_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		$cur = isset( $rec['current_stage'] ) ? (string) $rec['current_stage'] : '?';
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf(
				/* translators: 1: type, 2: id, 3: current stage */
				__( '忽略过期 %1$s #%2$d 任务（当前阶段：%3$s）', 'heb-product-publisher' ),
				$type,
				$source_id,
				$cur
			)
		);
		$cur_item = isset( $rec['current_item'] ) && is_array( $rec['current_item'] ) ? $rec['current_item'] : null;
		if ( $cur_item && (string) ( $cur_item['type'] ?? '' ) === $type && (int) ( $cur_item['source_id'] ?? 0 ) === (int) $source_id ) {
			Heb_Product_Publisher_Bootstrap_Status::clear_current_item( $job_id );
		}
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
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS,
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
	 * 分发成功但带 warn（如 hreflang 同步失败）时写入 job log。
	 *
	 * @param string               $job_id    Job id.
	 * @param string               $type      term|post.
	 * @param int                  $source_id Source object id.
	 * @param array<string,mixed>  $res       Distribute result.
	 * @return void
	 */
	private function log_distribute_warns( $job_id, $type, $source_id, array $res ) {
		$warns = isset( $res['warn'] ) && is_array( $res['warn'] ) ? $res['warn'] : [];
		if ( empty( $warns ) ) {
			return;
		}
		foreach ( $warns as $w ) {
			$msg = is_string( $w ) ? $w : wp_json_encode( $w );
			if ( ! is_string( $msg ) || '' === $msg ) {
				continue;
			}
			Heb_Product_Publisher_Bootstrap_Status::add_log(
				$job_id,
				'warning',
				sprintf(
					/* translators: 1: object type, 2: source id, 3: warning message */
					__( '%1$s #%2$d: %3$s', 'heb-product-publisher' ),
					$type,
					$source_id,
					$msg
				)
			);
		}
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
