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
	const HOOK_WATCHDOG = 'heb_pp_bs_watchdog';

	const GROUP = 'heb-pp-bootstrap';

	/** @var bool */
	private static $long_filters_registered = false;

	/**
	 * Bootstrap AS 任务可能单条跑 10–20 分钟（Opus 翻译）；放宽 queue runner 时间上限。
	 *
	 * @return void
	 */
	public static function register_long_action_filters() {
		if ( self::$long_filters_registered ) {
			return;
		}
		self::$long_filters_registered = true;
		$limit = Heb_Product_Publisher_Admin_Settings::is_quality_translator() ? 1200 : 600;
		add_filter(
			'action_scheduler_queue_runner_time_limit',
			static function () use ( $limit ) {
				return $limit;
			}
		);
	}

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
			'scope_terms'          => true,
			'scope_posts'          => true,
			'scope_post_types'     => heb_pp_distributable_post_types(),
			'scope_menus'          => true,
			'scope_settings'       => true,
			'scope_menu_locations' => false,
			'dry_run'              => false,
			'retry_mode'           => false,
			'retry_of'             => '',
			'retry_items'          => [],
		];
		$opts = array_merge( $defaults, $opts );

		$job_id = Heb_Product_Publisher_Bootstrap_Status::create( (string) $site_id, $opts );

		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf( __( 'Job 创建：目标站 = %s', 'heb-product-publisher' ), (string) $site_id )
		);

		self::schedule_bootstrap_action( self::HOOK_PROBE, [ 'job_id' => $job_id ], 2 );
		self::schedule_watchdog( $job_id );

		return [ 'job_id' => $job_id ];
	}

	/**
	 * 仅重试指定 job 中 failed 的项（新建 retry job，不重复跑全量）。
	 *
	 * @param string $source_job_id Finished job id with errors.
	 * @return array{job_id:string, error?:string}
	 */
	public static function retry_failed( $source_job_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( (string) $source_job_id );
		if ( ! $rec ) {
			return [ 'job_id' => '', 'error' => __( 'Job 不存在。', 'heb-product-publisher' ) ];
		}
		if ( ! in_array(
			$rec['status'],
			[
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
				Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS,
				Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED,
			],
			true
		) ) {
			return [ 'job_id' => '', 'error' => __( '只能重试已结束的 job。', 'heb-product-publisher' ) ];
		}
		$errors = isset( $rec['errors'] ) && is_array( $rec['errors'] ) ? $rec['errors'] : [];
		if ( empty( $errors ) ) {
			return [ 'job_id' => '', 'error' => __( '该 job 没有失败项可重试。', 'heb-product-publisher' ) ];
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return [ 'job_id' => '', 'error' => __( 'Action Scheduler 未加载。', 'heb-product-publisher' ) ];
		}
		$site_id = (string) $rec['site_id'];
		if ( Heb_Product_Publisher_Bootstrap_Status::site_running( $site_id ) ) {
			return [ 'job_id' => '', 'error' => __( '该目标站已有正在运行的 Bootstrap job。', 'heb-product-publisher' ) ];
		}

		$retry_items = [];
		$seen        = [];
		foreach ( $errors as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$type = isset( $e['type'] ) ? sanitize_key( (string) $e['type'] ) : '';
			$sid  = isset( $e['source_id'] ) ? (int) $e['source_id'] : 0;
			if ( '' === $type ) {
				continue;
			}
			if ( 'settings' !== $type && $sid <= 0 ) {
				continue;
			}
			$key = $type . ':' . $sid;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]       = true;
			$retry_items[] = [
				'type'      => $type,
				'source_id' => $sid,
			];
		}
		if ( empty( $retry_items ) ) {
			return [ 'job_id' => '', 'error' => __( '失败列表为空或格式无效。', 'heb-product-publisher' ) ];
		}

		$opts = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
		$opts['retry_mode']  = true;
		$opts['retry_of']    = (string) $source_job_id;
		$opts['retry_items'] = $retry_items;
		$opts['dry_run']     = false;

		$job_id = Heb_Product_Publisher_Bootstrap_Status::create( $site_id, $opts );
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf(
				__( 'Retry job：重试 %1$d 个失败项（来源 job %2$s）', 'heb-product-publisher' ),
				count( $retry_items ),
				substr( (string) $source_job_id, 0, 8 )
			)
		);
		self::schedule_bootstrap_action( self::HOOK_PROBE, [ 'job_id' => $job_id ], 2 );
		self::schedule_watchdog( $job_id );
		return [ 'job_id' => $job_id ];
	}

	/**
	 * 周期性检查 job 是否卡住，并轻推 Action Scheduler 继续跑队列。
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function schedule_watchdog( $job_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + 120,
			self::HOOK_WATCHDOG,
			[ [ 'job_id' => (string) $job_id ] ],
			self::GROUP
		);
	}

	/**
	 * 统计该 job 在 AS 中仍 pending 的任务数。
	 *
	 * @param string $job_id Job id.
	 * @return int
	 */
	public static function count_pending_actions( $job_id ) {
		$snap = self::get_job_queue_snapshot( $job_id );
		return (int) ( $snap['counts']['pending'] ?? 0 );
	}

	/**
	 * Bootstrap job 在 Action Scheduler 中的队列快照（pending / in-progress / failed）。
	 *
	 * @param string $job_id Job id.
	 * @return array{
	 *   items: array<int,array{hook:string,status:string,action_id:int,object_type:string,object_id:int,label:string,scheduled_at:int}>,
	 *   counts: array{pending:int,running:int,failed:int}
	 * }
	 */
	public static function get_job_queue_snapshot( $job_id ) {
		$out = [
			'items'  => [],
			'counts' => [ 'pending' => 0, 'running' => 0, 'failed' => 0 ],
		];
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return $out;
		}

		$hooks = [
			self::HOOK_TERM,
			self::HOOK_POST,
			self::HOOK_MENU,
			self::HOOK_SETTINGS,
			self::HOOK_FINALIZE,
		];
		$status_map = [
			\ActionScheduler_Store::STATUS_PENDING  => 'pending',
			\ActionScheduler_Store::STATUS_RUNNING => 'running',
			\ActionScheduler_Store::STATUS_FAILED  => 'failed',
		];

		foreach ( $status_map as $as_status => $bucket ) {
			foreach ( $hooks as $hook ) {
				$actions = as_get_scheduled_actions(
					[
						'hook'     => $hook,
						'group'    => self::GROUP,
						'status'   => $as_status,
						'per_page' => 200,
					],
					'ids'
				);
				if ( empty( $actions ) ) {
					continue;
				}
				foreach ( $actions as $action_id ) {
					$action = function_exists( 'as_get_scheduled_action' ) ? as_get_scheduled_action( $action_id ) : null;
					if ( ! $action || ! method_exists( $action, 'get_args' ) ) {
						continue;
					}
					$args    = $action->get_args();
					$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
					if ( ! isset( $payload['job_id'] ) || (string) $payload['job_id'] !== (string) $job_id ) {
						continue;
					}

					$object_type = 'finalize';
					$object_id   = 0;
					if ( self::HOOK_POST === $hook && ! empty( $payload['post_id'] ) ) {
						$object_type = 'post';
						$object_id   = (int) $payload['post_id'];
					} elseif ( self::HOOK_TERM === $hook && ! empty( $payload['term_id'] ) ) {
						$object_type = 'term';
						$object_id   = (int) $payload['term_id'];
					} elseif ( self::HOOK_MENU === $hook && ! empty( $payload['menu_id'] ) ) {
						$object_type = 'menu';
						$object_id   = (int) $payload['menu_id'];
					} elseif ( self::HOOK_SETTINGS === $hook ) {
						$object_type = 'settings';
					}

					$label = '';
					if ( 'post' === $object_type && $object_id > 0 ) {
						$label = get_the_title( $object_id );
					} elseif ( 'term' === $object_type && $object_id > 0 ) {
						$t = get_term( $object_id );
						$label = ( $t && ! is_wp_error( $t ) ) ? (string) $t->name : '';
					}

					$scheduled_at = 0;
					if ( method_exists( $action, 'get_schedule' ) && $action->get_schedule() && method_exists( $action->get_schedule(), 'get_date' ) ) {
						$date = $action->get_schedule()->get_date();
						if ( $date && method_exists( $date, 'getTimestamp' ) ) {
							$scheduled_at = (int) $date->getTimestamp();
						}
					}

					$out['items'][] = [
						'hook'         => (string) $hook,
						'status'       => (string) $bucket,
						'action_id'    => (int) $action_id,
						'object_type'  => $object_type,
						'object_id'    => $object_id,
						'label'        => $label,
						'scheduled_at' => $scheduled_at,
					];
					++$out['counts'][ $bucket ];
				}
			}
		}

		return $out;
	}

	/**
	 * 当前 stage 相关的 AS 队列快照（不含已过期阶段的 hook）。
	 *
	 * @param string $job_id Job id.
	 * @param string $stage  Stage key.
	 * @return array{items:array<int,array<string,mixed>>,counts:array{pending:int,running:int,failed:int}}
	 */
	public static function get_stage_queue_snapshot( $job_id, $stage ) {
		$all   = self::get_job_queue_snapshot( $job_id );
		$hooks = self::hooks_for_stage( (string) $stage );
		if ( empty( $hooks ) ) {
			return $all;
		}
		$items  = [];
		$counts = [ 'pending' => 0, 'running' => 0, 'failed' => 0 ];
		foreach ( $all['items'] as $item ) {
			if ( ! in_array( (string) ( $item['hook'] ?? '' ), $hooks, true ) ) {
				continue;
			}
			$items[] = $item;
			$bucket  = (string) ( $item['status'] ?? '' );
			if ( isset( $counts[ $bucket ] ) ) {
				++$counts[ $bucket ];
			}
		}
		return [
			'items'  => $items,
			'counts' => $counts,
		];
	}

	/**
	 * @param string $stage Stage key.
	 * @return array<int,string>
	 */
	private static function hooks_for_stage( $stage ) {
		$map = [
			Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS    => [ self::HOOK_TERM ],
			Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS    => [ self::HOOK_POST ],
			Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS => [ self::HOOK_SETTINGS ],
			Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS    => [ self::HOOK_MENU ],
		];
		return $map[ $stage ] ?? [];
	}

	/**
	 * 撤销指定 job 在某 hook 下仍 pending 的 AS 动作。
	 *
	 * @param string $job_id Job id.
	 * @param string $hook   Hook name.
	 * @return int 取消数量。
	 */
	public static function cancel_job_hook_actions( $job_id, $hook ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return 0;
		}
		$cancelled = 0;
		$actions   = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 500,
			],
			'ids'
		);
		if ( empty( $actions ) ) {
			return 0;
		}
		foreach ( $actions as $action_id ) {
			$action = function_exists( 'as_get_scheduled_action' ) ? as_get_scheduled_action( $action_id ) : null;
			if ( ! $action || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}
			$args    = $action->get_args();
			$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
			if ( ! isset( $payload['job_id'] ) || (string) $payload['job_id'] !== (string) $job_id ) {
				continue;
			}
			as_unschedule_action( $hook, $args, self::GROUP );
			$cancelled++;
		}
		return $cancelled;
	}

	/**
	 * 阶段仍有剩余项，但 AS 中已无 pending/running 时，补排遗漏的 post/term 任务。
	 *
	 * @param string $job_id Job id.
	 * @return int 新补排的任务数。
	 */
	public static function rescue_stalled_stage( $job_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec || ! in_array( $rec['status'] ?? '', [ Heb_Product_Publisher_Bootstrap_Status::STATUS_QUEUED, Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING ], true ) ) {
			return 0;
		}

		$stage = (string) ( $rec['current_stage'] ?? '' );
		$prog  = isset( $rec['progress'][ $stage ] ) && is_array( $rec['progress'][ $stage ] ) ? $rec['progress'][ $stage ] : [];
		$remaining = max(
			0,
			(int) ( $prog['queued'] ?? 0 ) - (int) ( $prog['done'] ?? 0 ) - (int) ( $prog['failed'] ?? 0 ) - (int) ( $prog['skipped'] ?? 0 )
		);
		if ( $remaining <= 0 ) {
			return 0;
		}

		$snap = self::get_stage_queue_snapshot( $job_id, $stage );
		if ( (int) ( $snap['counts']['pending'] ?? 0 ) > 0 || (int) ( $snap['counts']['running'] ?? 0 ) > 0 ) {
			return 0;
		}

		$rescued = 0;
		if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS === $stage ) {
			$opts    = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
			$missing = self::find_missing_stage_objects( $rec, $job_id, self::HOOK_POST, 'post', 'post_id', self::collect_distributable_post_ids( $opts ), $remaining );
			foreach ( $missing as $post_id ) {
				if ( in_array( (int) $post_id, self::get_job_inflight_object_ids( $job_id, self::HOOK_POST, 'post_id' ), true ) ) {
					continue;
				}
				self::schedule_bootstrap_action(
					self::HOOK_POST,
					[
						'job_id'  => $job_id,
						'post_id' => (int) $post_id,
					]
				);
				$rescued++;
			}
			if ( $rescued > 0 ) {
				Heb_Product_Publisher_Bootstrap_Status::add_log(
					$job_id,
					'warning',
					sprintf(
						/* translators: 1: count, 2: post ids */
						__( '队列停滞：已补排 %1$d 个遗漏 post（%2$s）', 'heb-product-publisher' ),
						$rescued,
						'#' . implode( ', #', array_map( 'strval', $missing ) )
					)
				);
			}
		} elseif ( Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS === $stage ) {
			$missing = self::find_missing_stage_objects( $rec, $job_id, self::HOOK_TERM, 'term', 'term_id', self::collect_distributable_term_ids(), $remaining );
			foreach ( $missing as $term_id ) {
				if ( in_array( (int) $term_id, self::get_job_inflight_object_ids( $job_id, self::HOOK_TERM, 'term_id' ), true ) ) {
					continue;
				}
				self::schedule_bootstrap_action(
					self::HOOK_TERM,
					[
						'job_id'  => $job_id,
						'term_id' => (int) $term_id,
					]
				);
				$rescued++;
			}
			if ( $rescued > 0 ) {
				Heb_Product_Publisher_Bootstrap_Status::add_log(
					$job_id,
					'warning',
					sprintf(
						/* translators: 1: count, 2: term ids */
						__( '队列停滞：已补排 %1$d 个遗漏 term（%2$s）', 'heb-product-publisher' ),
						$rescued,
						'#' . implode( ', #', array_map( 'strval', $missing ) )
					)
				);
			}
		} elseif ( Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS === $stage ) {
			as_enqueue_async_action( self::HOOK_SETTINGS, [ [ 'job_id' => $job_id ] ], self::GROUP );
			$rescued = 1;
			Heb_Product_Publisher_Bootstrap_Status::add_log(
				$job_id,
				'warning',
				__( '队列停滞：已补排 settings 任务', 'heb-product-publisher' )
			);
		}

		if ( $rescued > 0 ) {
			Heb_Product_Publisher_Bootstrap_Status::clear_current_item( $job_id );
		}

		return $rescued;
	}

	/**
	 * 找出本阶段仍应处理、但 Bootstrap 计数未完结且 AS 无在途任务的对象 ID。
	 *
	 * @param array<string,mixed> $rec       Job record.
	 * @param string              $job_id    Job id.
	 * @param string              $hook      AS hook.
	 * @param string              $type      Object type label (post/term).
	 * @param string              $id_field  Payload id key.
	 * @param array<int,int>      $expected  Expected object ids.
	 * @param int                 $remaining Remaining count for this stage.
	 * @return array<int,int>
	 */
	private static function find_missing_stage_objects( array $rec, $job_id, $hook, $type, $id_field, array $expected, $remaining ) {
		$finished  = array_unique(
			array_merge(
				self::get_accounted_ids_from_log( $rec, $type ),
				self::get_error_source_ids( $rec, $type )
			)
		);
		$inflight  = self::get_job_inflight_object_ids( $job_id, $hook, $id_field );
		$orphans   = self::get_orphan_ids_from_log( $rec, $type );
		$candidates = array_values(
			array_unique(
				array_merge(
					$orphans,
					array_diff( $expected, $finished, $inflight )
				)
			)
		);
		if ( $remaining > 0 && count( $candidates ) > $remaining ) {
			// 优先补排「已开始但未完结」的孤儿任务。
			$orphans_in_candidates = array_values( array_intersect( $orphans, $candidates ) );
			$rest                  = array_values( array_diff( $candidates, $orphans_in_candidates ) );
			$candidates            = array_merge(
				$orphans_in_candidates,
				array_slice( $rest, 0, max( 0, $remaining - count( $orphans_in_candidates ) ) )
			);
		}
		return $candidates;
	}

	/**
	 * Bootstrap posts 阶段实际要分发的 post type（按推荐顺序）。
	 *
	 * @param array<string,mixed> $opts Job opts.
	 * @return array<int,string>
	 */
	public static function scoped_post_types( array $opts ) {
		$allowed = heb_pp_distributable_post_types();
		$order   = self::post_type_dispatch_order();
		$pick    = [];

		if ( ! empty( $opts['scope_post_types'] ) && is_array( $opts['scope_post_types'] ) ) {
			foreach ( $opts['scope_post_types'] as $pt ) {
				if ( is_string( $pt ) && in_array( $pt, $allowed, true ) ) {
					$pick[ $pt ] = true;
				}
			}
		} elseif ( ! empty( $opts['scope_posts'] ) ) {
			foreach ( $allowed as $pt ) {
				$pick[ $pt ] = true;
			}
		}

		if ( empty( $pick ) ) {
			return [];
		}

		$out = [];
		foreach ( $order as $pt ) {
			if ( isset( $pick[ $pt ] ) ) {
				$out[] = $pt;
			}
		}
		foreach ( array_keys( $pick ) as $pt ) {
			if ( ! in_array( $pt, $out, true ) ) {
				$out[] = $pt;
			}
		}
		return $out;
	}

	/**
	 * elementor_library 优先，便于 page / archive 引用模板。
	 *
	 * @return array<int,string>
	 */
	private static function post_type_dispatch_order() {
		return (array) apply_filters(
			'heb_pp_bootstrap_post_type_order',
			[ 'elementor_library', 'page', 'products', 'solutions' ]
		);
	}

	/**
	 * @param array<string,mixed> $opts Job opts.
	 * @return array<int,int>
	 */
	private static function collect_distributable_post_ids( array $opts = [] ) {
		$ids = [];
		foreach ( self::scoped_post_types( $opts ) as $pt ) {
			$post_ids = get_posts(
				[
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'parent date',
					'order'          => 'ASC',
				]
			);
			if ( ! empty( $post_ids ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $post_ids ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<int,int>
	 */
	private static function collect_distributable_term_ids() {
		$ids = [];
		foreach ( heb_pp_distributable_taxonomies() as $tx ) {
			$terms = get_terms(
				[
					'taxonomy'   => $tx,
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $terms ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string $job_id   Job id.
	 * @param string $hook     AS hook.
	 * @param string $id_field Payload id key.
	 * @return array<int,int>
	 */
	private static function get_job_inflight_object_ids( $job_id, $hook, $id_field ) {
		return self::get_job_object_ids_from_as(
			$job_id,
			$hook,
			$id_field,
			[
				\ActionScheduler_Store::STATUS_PENDING,
				\ActionScheduler_Store::STATUS_RUNNING,
			]
		);
	}

	/**
	 * @param string $job_id   Job id.
	 * @param string $hook     AS hook.
	 * @param string $id_field Payload id key.
	 * @param array<int,string> $statuses AS statuses to include.
	 * @return array<int,int>
	 */
	private static function get_job_object_ids_from_as( $job_id, $hook, $id_field, array $statuses = [] ) {
		$ids = [];
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return $ids;
		}
		if ( empty( $statuses ) ) {
			$statuses = [
				\ActionScheduler_Store::STATUS_PENDING,
				\ActionScheduler_Store::STATUS_RUNNING,
				\ActionScheduler_Store::STATUS_FAILED,
				\ActionScheduler_Store::STATUS_COMPLETE,
				\ActionScheduler_Store::STATUS_CANCELED,
			];
		}
		foreach ( $statuses as $as_status ) {
			$actions = as_get_scheduled_actions(
				[
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => $as_status,
					'per_page' => 500,
				],
				'ids'
			);
			if ( empty( $actions ) ) {
				continue;
			}
			foreach ( $actions as $action_id ) {
				$action = function_exists( 'as_get_scheduled_action' ) ? as_get_scheduled_action( $action_id ) : null;
				if ( ! $action || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$args    = $action->get_args();
				$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
				if ( ! isset( $payload['job_id'] ) || (string) $payload['job_id'] !== (string) $job_id ) {
					continue;
				}
				if ( ! empty( $payload[ $id_field ] ) ) {
					$ids[] = (int) $payload[ $id_field ];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * 日志里「已开始但未完成/失败/跳过」的对象（AS 已结束但 Bootstrap 计数未更新）。
	 *
	 * @param array<string,mixed> $rec  Job record.
	 * @param string              $type Object type.
	 * @return array<int,int>
	 */
	private static function get_orphan_ids_from_log( array $rec, $type ) {
		$started  = [];
		$finished = [];
		$type_re  = preg_quote( (string) $type, '/' );
		foreach ( $rec['log'] ?? [] as $entry ) {
			$msg = isset( $entry['msg'] ) ? (string) $entry['msg'] : '';
			if ( '' === $msg ) {
				continue;
			}
			if ( preg_match( '/^' . $type_re . ' #(\d+).*开始/u', $msg, $m ) ) {
				$started[] = (int) $m[1];
			}
			if ( preg_match( '/^' . $type_re . ' #(\d+).*(?:完成|失败|跳过)/u', $msg, $m ) ) {
				$finished[] = (int) $m[1];
			}
		}
		return array_values( array_diff( array_unique( $started ), array_unique( $finished ) ) );
	}

	/**
	 * @param array<string,mixed> $rec  Job record.
	 * @param string              $type Object type.
	 * @return array<int,int>
	 */
	private static function get_accounted_ids_from_log( array $rec, $type ) {
		$ids  = [];
		$type = preg_quote( (string) $type, '/' );
		foreach ( $rec['log'] ?? [] as $entry ) {
			$msg = isset( $entry['msg'] ) ? (string) $entry['msg'] : '';
			if ( '' === $msg ) {
				continue;
			}
			if ( preg_match( '/^' . $type . ' #(\d+).*(?:完成|失败|跳过)/u', $msg, $m ) ) {
				$ids[] = (int) $m[1];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string,mixed> $rec  Job record.
	 * @param string              $type Object type.
	 * @return array<int,int>
	 */
	private static function get_error_source_ids( array $rec, $type ) {
		$ids = [];
		foreach ( $rec['errors'] ?? [] as $err ) {
			if ( ( $err['type'] ?? '' ) === $type && ! empty( $err['source_id'] ) ) {
				$ids[] = (int) $err['source_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * 手动推进 Bootstrap 相关的 Action Scheduler 队列（后台「推进队列」按钮）。
	 *
	 * @return int 本次处理的 action 数量（估算）。
	 */
	public static function nudge_queue_runner( $job_id = '' ) {
		self::register_long_action_filters();
		Heb_Product_Publisher_Runtime::raise();
		if ( '' !== (string) $job_id ) {
			self::rescue_stalled_stage( (string) $job_id );
			self::maybe_enqueue_finalize( (string) $job_id );
		}
		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			$runner = \ActionScheduler_QueueRunner::instance();
			if ( method_exists( $runner, 'run' ) ) {
				return (int) $runner->run();
			}
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'action_scheduler_run_queue', self::GROUP );
		}
		return 0;
	}

	/**
	 * Job 已到 finished 阶段但 finalize 未跑完时，补排 finalize 任务。
	 *
	 * @param string $job_id Job id.
	 * @return bool 是否补排了 finalize。
	 */
	public static function maybe_enqueue_finalize( $job_id ) {
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		if ( ! $rec ) {
			return false;
		}
		if ( ! in_array( $rec['status'] ?? '', [ Heb_Product_Publisher_Bootstrap_Status::STATUS_QUEUED, Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING ], true ) ) {
			return false;
		}
		if ( (string) ( $rec['current_stage'] ?? '' ) !== Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED ) {
			return false;
		}
		if ( ! empty( $rec['finished_at'] ) ) {
			return false;
		}
		$snap = self::get_job_queue_snapshot( $job_id );
		foreach ( $snap['items'] as $item ) {
			if ( self::HOOK_FINALIZE === ( $item['hook'] ?? '' ) && in_array( $item['status'] ?? '', [ 'pending', 'running' ], true ) ) {
				return false;
			}
		}
		as_enqueue_async_action( self::HOOK_FINALIZE, [ [ 'job_id' => (string) $job_id ] ], self::GROUP );
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'warning',
			__( '队列停滞：已补排 finalize 收尾任务', 'heb-product-publisher' )
		);
		return true;
	}

	/**
	 * dry_run 模式：统计将要分发的 object 数量（不入队）。
	 *
	 * @param array<string,mixed> $rec Job record.
	 * @return array{terms:int,posts:int,menus:int,settings:int}
	 */
	public static function count_dispatchable( array $rec ) {
		$opts = isset( $rec['opts'] ) && is_array( $rec['opts'] ) ? $rec['opts'] : [];
		$out  = [ 'terms' => 0, 'posts' => 0, 'menus' => 0, 'settings' => 0 ];

		if ( ! empty( $opts['scope_terms'] ) ) {
			foreach ( heb_pp_distributable_taxonomies() as $tx ) {
				$terms = get_terms(
					[
						'taxonomy'   => $tx,
						'hide_empty' => false,
						'fields'     => 'ids',
					]
				);
				if ( ! is_wp_error( $terms ) ) {
					$out['terms'] += count( $terms );
				}
			}
		}
		if ( ! empty( $opts['scope_posts'] ) || ! empty( $opts['scope_post_types'] ) ) {
			foreach ( self::scoped_post_types( $opts ) as $pt ) {
				$ids = get_posts(
					[
						'post_type'      => $pt,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					]
				);
				$out['posts'] += count( $ids );
			}
		}
		if ( ! empty( $opts['scope_menus'] ) ) {
			$out['menus'] = count( wp_get_nav_menus() );
		}
		if ( ! empty( $opts['scope_settings'] ) ) {
			$out['settings'] = 1;
		}
		return $out;
	}

	/**
	 * 重试 job：仅 queue 失败项，不跑全量 dispatch。
	 *
	 * @param string              $job_id Job id.
	 * @param array<int,array{type:string,source_id:int}> $items Retry items.
	 * @return void
	 */
	public static function dispatch_retry_items( $job_id, array $items ) {
		$by_stage = [
			Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS    => 0,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS    => 0,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS    => 0,
			Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS => 0,
		];
		$queued   = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
			$sid  = isset( $item['source_id'] ) ? (int) $item['source_id'] : 0;

			if ( 'term' === $type && $sid > 0 ) {
				self::schedule_bootstrap_action(
					self::HOOK_TERM,
					[ 'job_id' => $job_id, 'term_id' => $sid ],
					$queued
				);
				$by_stage[ Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS ]++;
				$queued++;
			} elseif ( 'post' === $type && $sid > 0 ) {
				self::schedule_bootstrap_action(
					self::HOOK_POST,
					[ 'job_id' => $job_id, 'post_id' => $sid ],
					$queued
				);
				$by_stage[ Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS ]++;
				$queued++;
			} elseif ( 'menu' === $type && $sid > 0 ) {
				as_enqueue_async_action(
					self::HOOK_MENU,
					[ [ 'job_id' => $job_id, 'menu_id' => $sid ] ],
					self::GROUP
				);
				$by_stage[ Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS ]++;
				$queued++;
			} elseif ( 'settings' === $type ) {
				as_enqueue_async_action( self::HOOK_SETTINGS, [ [ 'job_id' => $job_id ] ], self::GROUP );
				$by_stage[ Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS ]++;
				$queued++;
			}
		}

		foreach ( $by_stage as $stage => $count ) {
			if ( $count > 0 ) {
				Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, $stage, 'queued', $count );
			}
		}

		$first_stage = Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED;
		foreach (
			[
				Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS,
				Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS,
				Heb_Product_Publisher_Bootstrap_Status::STAGE_SETTINGS,
				Heb_Product_Publisher_Bootstrap_Status::STAGE_MENUS,
			] as $stage
		) {
			if ( $by_stage[ $stage ] > 0 ) {
				$first_stage = $stage;
				break;
			}
		}

		Heb_Product_Publisher_Bootstrap_Status::update(
			$job_id,
			[
				'current_stage' => $first_stage,
				'status'        => Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING,
			]
		);
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf( __( 'Retry 已排队 %d 项', 'heb-product-publisher' ), $queued )
		);

		if ( 0 === $queued ) {
			as_enqueue_async_action( self::HOOK_FINALIZE, [ [ 'job_id' => $job_id ] ], self::GROUP );
		}
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
		Heb_Product_Publisher_Bootstrap_Status::with_advance_lock(
			$job_id,
			static function () use ( $job_id ) {
				$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
				if ( ! $rec || Heb_Product_Publisher_Bootstrap_Status::STATUS_CANCELLED === $rec['status'] ) {
					return;
				}
				$current = isset( $rec['current_stage'] ) ? (string) $rec['current_stage'] : Heb_Product_Publisher_Bootstrap_Status::STAGE_PROBE;

				if ( ! self::stage_complete( $rec, $current ) ) {
					return;
				}

				$next = self::next_stage( $current, $rec );
				if ( $next === $current ) {
					return;
				}

				// 二次读取，防止锁等待期间已被其他 worker 推进。
				$rec2 = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
				if ( ! $rec2 || ( isset( $rec2['current_stage'] ) && (string) $rec2['current_stage'] !== $current ) ) {
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

				if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS === $current ) {
					$cancelled = self::cancel_job_hook_actions( $job_id, self::HOOK_POST );
					if ( $cancelled > 0 ) {
						Heb_Product_Publisher_Bootstrap_Status::add_log(
							$job_id,
							'info',
							sprintf(
								/* translators: %d: count */
								__( '已取消 %d 个过期的 post 队列任务', 'heb-product-publisher' ),
								$cancelled
							)
						);
					}
					Heb_Product_Publisher_Bootstrap_Status::clear_current_item( $job_id );
				} elseif ( Heb_Product_Publisher_Bootstrap_Status::STAGE_TERMS === $current ) {
					self::cancel_job_hook_actions( $job_id, self::HOOK_TERM );
				}

				self::dispatch_stage( $job_id, $next );
			}
		);
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
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS === $next && empty( self::scoped_post_types( $opts ) ) ) {
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
			// retry job：跳过 queued=0 的 stage。
			if ( ! empty( $opts['retry_mode'] ) && Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED !== $next ) {
				$p = isset( $rec['progress'][ $next ] ) ? $rec['progress'][ $next ] : [];
				if ( (int) ( $p['queued'] ?? 0 ) <= 0 ) {
					$next = $flow[ $next ] ?? Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED;
					continue;
				}
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
		$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		$opts = ( $rec && isset( $rec['opts'] ) && is_array( $rec['opts'] ) ) ? $rec['opts'] : [];
		if ( ! empty( $opts['retry_mode'] ) ) {
			if ( Heb_Product_Publisher_Bootstrap_Status::STAGE_FINISHED === $stage ) {
				as_enqueue_async_action( self::HOOK_FINALIZE, [ [ 'job_id' => $job_id ] ], self::GROUP );
			}
			return;
		}
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
			$ordered = self::sort_ids_by_parent_depth( $terms );
			foreach ( $ordered as $row ) {
				self::schedule_bootstrap_action(
					self::HOOK_TERM,
					[
						'job_id'  => $job_id,
						'term_id' => (int) $row['id'],
					],
					(int) $row['depth'] * 120 + $queued
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
		$rec  = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
		$opts = ( $rec && isset( $rec['opts'] ) && is_array( $rec['opts'] ) ) ? $rec['opts'] : [];
		$types = self::scoped_post_types( $opts );
		$queued = 0;
		foreach ( $types as $pt ) {
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
			$ordered = self::sort_posts_by_parent_depth( $ids );
			foreach ( $ordered as $row ) {
				self::schedule_bootstrap_action(
					self::HOOK_POST,
					[
						'job_id'  => $job_id,
						'post_id' => (int) $row['id'],
					],
					(int) $row['depth'] * 120 + $queued
				);
				$queued++;
			}
		}
		Heb_Product_Publisher_Bootstrap_Status::increment( $job_id, Heb_Product_Publisher_Bootstrap_Status::STAGE_POSTS, 'queued', $queued );
		Heb_Product_Publisher_Bootstrap_Status::add_log(
			$job_id,
			'info',
			sprintf(
				/* translators: 1: count, 2: comma-separated post types */
				__( '已排队 %1$d 个 post（%2$s）', 'heb-product-publisher' ),
				$queued,
				! empty( $types ) ? implode( ', ', $types ) : '-'
			)
		);

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

	/**
	 * @param string              $hook Hook name.
	 * @param array<string,mixed> $args Args.
	 * @param int                 $delay Delay in seconds.
	 * @return void
	 */
	private static function schedule_bootstrap_action( $hook, array $args, $delay = 0 ) {
		$delay = max( 0, (int) $delay );
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, [ $args ], self::GROUP );
			return;
		}
		as_enqueue_async_action( $hook, [ $args ], self::GROUP );
	}

	/**
	 * @param array<int,int> $parents id => parent_id map.
	 * @return array<int,array{id:int,depth:int}>
	 */
	private static function sort_ids_by_parent_depth( array $parents ) {
		$depths = [];
		$depth_of = static function ( $id ) use ( &$depth_of, &$depths, $parents ) {
			$id = (int) $id;
			if ( isset( $depths[ $id ] ) ) {
				return $depths[ $id ];
			}
			$parent = isset( $parents[ $id ] ) ? (int) $parents[ $id ] : 0;
			if ( $parent <= 0 || ! isset( $parents[ $parent ] ) || $parent === $id ) {
				$depths[ $id ] = 0;
				return 0;
			}
			$depths[ $id ] = 1 + (int) $depth_of( $parent );
			return $depths[ $id ];
		};

		$rows = [];
		foreach ( array_keys( $parents ) as $id ) {
			$rows[] = [ 'id' => (int) $id, 'depth' => (int) $depth_of( $id ) ];
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['depth'] === $b['depth'] ) {
					return $a['id'] - $b['id'];
				}
				return $a['depth'] - $b['depth'];
			}
		);
		return $rows;
	}

	/**
	 * @param array<int,int> $post_ids Post ids.
	 * @return array<int,array{id:int,depth:int}>
	 */
	private static function sort_posts_by_parent_depth( array $post_ids ) {
		$parents = [];
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			$parents[ $pid ] = (int) wp_get_post_parent_id( $pid );
		}
		return self::sort_ids_by_parent_depth( $parents );
	}
}
