<?php
/**
 * Site Bootstrap 编排：把"克隆整站"切分成可重入的小任务，交给 Action Scheduler 跑。
 *
 * Stage 序列：
 *  1. probe     — 拉目标站 /site-info 验证 receiver 可达；用主站当前已知数据初始化
 *  2. terms     — 分发所有 distributable taxonomy 下的 term（按 parent → child）
 *  3. posts     — 分发所有 distributable post type 下已发布的 post（products / solutions / page）
 *  4. settings  — 同步 WordPress 全局选项（PR 4，目前 skip）
 *  5. menus     — 同步导航菜单（PR 4，目前 skip）
 *  6. finalize  — 汇总，发邮件，标 done
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bootstrap_Queue {

	const HOOK_PROBE    = 'heb_pp_bs_probe';
	const HOOK_TERM     = 'heb_pp_bs_term';
	const HOOK_POST     = 'heb_pp_bs_post';
	const HOOK_SETTINGS = 'heb_pp_bs_settings';
	const HOOK_MENU     = 'heb_pp_bs_menu';
	const HOOK_FINALIZE = 'heb_pp_bs_finalize';

	const GROUP = 'heb-pp-bootstrap';

	/**
	 * 创建 job 并 enqueue 第一个 stage（probe）。
	 *
	 * @param string                $site_id Target site id.
	 * @param array<string,mixed>   $opts    Bootstrap options.
	 * @return array{job_id:string, error?:string}
	 */
	public static function start( $site_id, array $opts = [] ) {
		$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $site_id );
		if ( ! $site ) {
			return [ 'job_id' => '', 'error' => __( '目标站点未配置或不存在。', 'heb-product-publisher' ) ];
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return [ 'job_id' => '', 'error' => __( 'Action Scheduler 未加载（vendor 文件缺失？）', 'heb-product-publisher' ) ];
		}
		$running = Heb_Product_Publisher_Bootstrap_Status::site_running( (string) $site_id );
		if ( $running ) {
			return [ 'job_id' => $running, 'error' => __( '该目标站已有正在运行的 Bootstrap job。', 'heb-product-publisher' ) ];
		}

		$defaults = [
			'scope_terms'    => true,
			'scope_posts'    => true,
			'scope_menus'    => false,
			'scope_settings' => false,
			'dry_run'        => false,
		];
		$opts = array_merge( $defaults, $opts );

		$job_id = Heb_Product_Publisher_Bootstrap_Status::create( (string) $site_id, $opts );

		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf( __( 'Job 创建：目标站 = %s', 'heb-product-publisher' ), (string) $site_id )
		);

		as_enqueue_async_action(
			self::HOOK_PROBE,
			[ [ 'job_id' => $job_id ] ],
			self::GROUP
		);

		return [ 'job_id' => $job_id ];
	}

	/**
	 * Stage 结束后调度下一个 stage。从 worker 内部调用。
	 *
	 * 当前 stage 的所有 task 已经 done/failed/skipped 时进入下一个 stage。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function advance_stage( $job_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec || Heb_Product_Publisher_Bootstrap_Status::STATUS_CANCELLED === $rec['status'] ) {
			return;
		}
		$current = isset( $rec['current_stage'] ) ? (string) $rec['current_stage'] : Heb_Product_Publisher_Bootstrap_Status::STAGE_PROBE;

		// 当前 stage 必须全部 task 处理完才进入下一个。
		if ( ! self::stage_complete( $rec, $current ) ) {
			return;
		}

		$next = self::next_stage( $current, $rec );
		if ( $next === $current ) {
			return;
		}
		Heb_Product_Publisher_Bootstrap_Status::update(
			$job_id,
			[
				'current_stage' => $next,
				'status'        => Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING,
			]
		);
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', sprintf( '→ stage: %s', $next ) );

		self::dispatch_stage( $job_id, $next );
	}

	/**
	 * 检查当前 stage 的 task 是否全部跑完（queued == done + failed + skipped）。
	 *
	 * @param array<string,mixed> $rec   Job record.
	 * @param string              $stage Stage key.
	 * @return bool
	 */
	private static function stage_complete( array $rec, $stage ) {
		// probe / finalize 是单一任务，由 worker 直接 advance。
		if ( ! isset( $rec['progress'][ $stage ] ) ) {
			return true;
		}
		$p = $rec['progress'][ $stage ];
		$queued  = (int) ( $p['queued'] ?? 0 );
		$done    = (int) ( $p['done'] ?? 0 );
		$failed  = (int) ( $p['failed'] ?? 0 );
		$skipped = (int) ( $p['skipped'] ?? 0 );
		return $queued > 0 && ( $done + $failed + $skipped ) >= $queued;
	}

	/**
	 * @param string              $current Current stage.
	 * @param array<string,mixed> $rec     Job record.
	 * @return string
	 */
	private static function next_stage( $current, array $rec ) {
		$opts = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
		$flow = [
			Heb_Product_Publisher_Bootstrap_Status::STAGE_PROBE    => Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS    => Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS    => Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS => Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS    => Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED,
		];
		$next = $flow[ $current ] ?? Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED;

		// 按 opts 跳过未启用的 stage。
		while ( true ) {
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS === $next && empty( $opts['scope_terms'] ) ) {
				$next = $flow[ $next ];
				continue;
			}
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS === $next && empty( $opts['scope_posts'] ) ) {
				$next = $flow[ $next ];
				continue;
			}
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS === $next && empty( $opts['scope_settings'] ) ) {
				$next = $flow[ $next ];
				continue;
			}
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS === $next && empty( $opts['scope_menus'] ) ) {
				$next = $flow[ $next ];
				continue;
			}
			break;
		}
		return $next;
	}

	/**
	 * 根据 stage 收集源对象并 enqueue 任务。
	 *
	 * @param string $job_id Job id.
	 * @param string $stage  Stage key.
	 * @return void
	 */
	private static function dispatch_stage( $job_id, $stage ) {
		switch ( $stage ) {
			case Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS:
				self::dispatch_terms( $job_id );
				break;
			case Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS:
				self::dispatch_posts( $job_id );
				break;
			case Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS:
				self::dispatch_settings( $job_id );
				break;
			case Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS:
				self::dispatch_menus( $job_id );
				break;
			case Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED:
				as_enqueue_async_action( self::HOOK_FINALIZE, [ [ 'job_id' => $job_id ] ], self::GROUP );
				break;
		}
	}

	/**
	 * 收集所有 distributable taxonomy 下 term，按层级递增顺序入队（父先于子）。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function dispatch_terms( $job_id ) {
		$queued = 0;
		foreach ( heb_pp_distributable_taxonomies() as $tx ) {
			$terms = get_terms(
				[
					'taxonomy'   => $tx,
					'hide_empty' => false,
					'fields'     => 'id=>parent',
				]
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			// 按 parent ASC 排序（0 在前 → 父 term 在前）。
			asort( $terms );
			foreach ( array_keys( $terms ) as $tid ) {
				as_enqueue_async_action(
					self::HOOK_TERM,
					[
						[
							'job_id'  => $job_id,
							'term_id' => (int) $tid,
						],
					],
					self::GROUP
				);
				$queued++;
			}
		}
		Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS, 'queued', $queued );
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', sprintf( __( '已排队 %d 个 term', 'heb-product-publisher' ), $queued ) );

		// 空集 → 直接 advance（worker 不会有任何 task 来推进进度）。
		if ( 0 === $queued ) {
			self::advance_stage( $job_id );
		}
	}

	/**
	 * 收集所有 distributable post type 下已发布 post，入队。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function dispatch_posts( $job_id ) {
		$queued = 0;
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			$ids = get_posts(
				[
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'parent date',
					'order'          => 'ASC',
				]
			);
			if ( empty( $ids ) ) {
				continue;
			}
			foreach ( $ids as $pid ) {
				as_enqueue_async_action(
					self::HOOK_POST,
					[
						[
							'job_id'  => $job_id,
							'post_id' => (int) $pid,
						],
					],
					self::GROUP
				);
				$queued++;
			}
		}
		Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS, 'queued', $queued );
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', sprintf( __( '已排队 %d 个 post', 'heb-product-publisher' ), $queued ) );

		if ( 0 === $queued ) {
			self::advance_stage( $job_id );
		}
	}

	/**
	 * PR 4 入口；当前阶段直接占位 + advance。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function dispatch_settings( $job_id ) {
		if ( class_exists( 'Heb_Product_Publisher_Settings_Sync' ) ) {
			as_enqueue_async_action( self::HOOK_SETTINGS, [ [ 'job_id' => $job_id ] ], self::GROUP );
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS, 'queued', 1 );
			return;
		}
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', '(settings stage skipped — Settings_Sync not loaded)' );
		self::advance_stage( $job_id );
	}

	/**
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function dispatch_menus( $job_id ) {
		if ( class_exists( 'Heb_Product_Publisher_Menu_Sync' ) ) {
			$menus = wp_get_nav_menus();
			$queued = 0;
			foreach ( $menus as $m ) {
				as_enqueue_async_action(
					self::HOOK_MENU,
					[
						[
							'job_id'   => $job_id,
							'menu_id'  => (int) $m->term_id,
						],
					],
					self::GROUP
				);
				$queued++;
			}
			Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS, 'queued', $queued );
			if ( 0 === $queued ) {
				self::advance_stage( $job_id );
			}
			return;
		}
		Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', '(menus stage skipped — Menu_Sync not loaded)' );
		self::advance_stage( $job_id );
	}
}
