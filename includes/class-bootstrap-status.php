<?php
/**
 * Site Bootstrap 状态存储 + 查询。
 *
 * 每个 bootstrap job 一行 option `heb_pp_bs_job_{id}`，索引列表存在 `heb_pp_bs_jobs`。
 * 所有写操作都过 `update_option(autoload=false)`，避免主站 page load 时被全部加载。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bootstrap_Status {

	const OPT_INDEX  = 'heb_pp_bs_jobs';
	const OPT_PREFIX = 'heb_pp_bs_job_';
	const MAX_LOG    = 200;
	const MAX_ERROR  = 100;

	const STATUS_QUEUED           = 'queued';
	const STATUS_RUNNING          = 'running';
	const STATUS_DONE             = 'done';
	const STATUS_DONE_WITH_ERRORS = 'done_with_errors';
	const STATUS_FAILED           = 'failed';
	const STATUS_CANCELLED        = 'cancelled';

	const STAGE_PROBE    = 'probe';
	const STAGE_TERMS    = 'terms';
	const STAGE_POSTS    = 'posts';
	const STAGE_SETTINGS = 'settings';
	const STAGE_MENUS    = 'menus';
	const STAGE_FINISHED = 'finished';

	/**
	 * 创建新 job 并返回 id。
	 *
	 * @param string $site_id Target site id.
	 * @param array<string,mixed> $opts Bootstrap options (scope toggles, dry_run, retry_*).
	 * @return string
	 */
	public static function create( $site_id, array $opts = [] ) {
		$id      = self::gen_id();
		$site_id = (string) $site_id;
		$now     = time();

		$record = [
			'id'            => $id,
			'site_id'       => $site_id,
			'started_at'    => $now,
			'updated_at'    => $now,
			'finished_at'   => 0,
			'status'        => self::STATUS_QUEUED,
			'current_stage' => self::STAGE_PROBE,
			'opts'          => $opts,
			'progress'      => [
				self::STAGE_TERMS    => [ 'queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ],
				self::STAGE_POSTS    => [ 'queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ],
				self::STAGE_SETTINGS => [ 'queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ],
				self::STAGE_MENUS    => [ 'queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ],
			],
			'log'    => [],
			'errors' => [],
			'current_item' => null,
		];
		update_option( self::OPT_PREFIX . $id, $record, false );

		$index = (array) get_option( self::OPT_INDEX, [] );
		array_unshift( $index, $id );
		$index = array_values( array_unique( $index ) );
		if ( count( $index ) > 50 ) {
			$index = array_slice( $index, 0, 50 );
		}
		update_option( self::OPT_INDEX, $index, false );
		return $id;
	}

	/**
	 * @param string $id Job id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		$rec = get_option( self::OPT_PREFIX . (string) $id, null );
		return is_array( $rec ) ? $rec : null;
	}

	/**
	 * 浅合并更新 job 记录。
	 *
	 * @param string              $id    Job id.
	 * @param array<string,mixed> $patch Patch to merge.
	 * @return array<string,mixed>|null Updated record.
	 */
	public static function update( $id, array $patch ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return null;
		}
		$rec               = array_merge( $rec, $patch );
		$rec['updated_at'] = time();
		update_option( self::OPT_PREFIX . (string) $id, $rec, false );
		return $rec;
	}

	/**
	 * 递增某 stage 的某计数器（带 transient 锁，避免并行 AS worker 丢失计数）。
	 *
	 * @param string $id    Job id.
	 * @param string $stage Stage key.
	 * @param string $key   Counter key (queued / done / failed / skipped).
	 * @param int    $delta Increment delta.
	 * @return void
	 */
	public static function increment( $id, $stage, $key, $delta = 1 ) {
		self::with_job_lock(
			$id,
			static function () use ( $id, $stage, $key, $delta ) {
				$rec = self::get( $id );
				if ( ! $rec ) {
					return;
				}
				if ( ! isset( $rec['progress'][ $stage ] ) ) {
					$rec['progress'][ $stage ] = [ 'queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ];
				}
				if ( ! isset( $rec['progress'][ $stage ][ $key ] ) ) {
					$rec['progress'][ $stage ][ $key ] = 0;
				}
				$rec['progress'][ $stage ][ $key ] = max( 0, (int) $rec['progress'][ $stage ][ $key ] + (int) $delta );
				$rec['updated_at']                  = time();
				update_option( self::OPT_PREFIX . (string) $id, $rec, false );
			}
		);
	}

	/**
	 * 尝试获取 job 级短锁（跨并行 worker 互斥读写 progress / stage）。
	 *
	 * @param string   $id       Job id.
	 * @param callable $callback Callback while lock held.
	 * @return mixed|null Callback return value, or null if lock not acquired.
	 */
	public static function with_job_lock( $id, callable $callback ) {
		$lock_key = 'heb_pp_bs_lock_' . md5( (string) $id );
		$attempts = 0;
		while ( $attempts < 20 ) {
			if ( get_transient( $lock_key ) ) {
				usleep( 100000 );
				$attempts++;
				continue;
			}
			set_transient( $lock_key, 1, 30 );
			try {
				return $callback();
			} finally {
				delete_transient( $lock_key );
			}
		}
		return null;
	}

	/**
	 * 阶段切换专用锁：同一 job 同时只允许一个 worker 执行 advance。
	 *
	 * @param string   $id       Job id.
	 * @param callable $callback Callback while lock held.
	 * @return mixed|null
	 */
	public static function with_advance_lock( $id, callable $callback ) {
		$lock_key = 'heb_pp_bs_adv_' . md5( (string) $id );
		if ( get_transient( $lock_key ) ) {
			return null;
		}
		set_transient( $lock_key, 1, 60 );
		try {
			return $callback();
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * 标记当前正在处理的条目（UI 实时进度用）。
	 *
	 * @param string $id        Job id.
	 * @param string $type      term|post|menu|settings.
	 * @param int    $source_id Source object id.
	 * @param string $label     Human label (title/name).
	 * @return void
	 */
	public static function set_current_item( $id, $type, $source_id, $label = '' ) {
		self::update(
			$id,
			[
				'current_item' => [
					'type'       => (string) $type,
					'source_id'  => (int) $source_id,
					'label'      => (string) $label,
					'started_at' => time(),
				],
			]
		);
	}

	/**
	 * @param string $id Job id.
	 * @return void
	 */
	public static function clear_current_item( $id ) {
		self::update( $id, [ 'current_item' => null ] );
	}

	/**
	 * 为 API / UI 附加计算字段：进度百分比、进行中条目、是否疑似卡住。
	 *
	 * @param array<string,mixed> $rec Job record.
	 * @return array<string,mixed>
	 */
	public static function enrich( array $rec ) {
		$now     = time();
		$updated = (int) ( $rec['updated_at'] ?? 0 );
		$quality = class_exists( 'Heb_Product_Publisher_Admin_Settings', false )
			&& Heb_Product_Publisher_Admin_Settings::is_quality_translator();
		$stale_after = $quality ? 900 : 600;

		$stage   = isset( $rec['current_stage'] ) ? (string) $rec['current_stage'] : '';
		$prog    = isset( $rec['progress'][ $stage ] ) && is_array( $rec['progress'][ $stage ] )
			? $rec['progress'][ $stage ]
			: [];
		$queued  = (int) ( $prog['queued'] ?? 0 );
		$done    = (int) ( $prog['done'] ?? 0 );
		$failed  = (int) ( $prog['failed'] ?? 0 );
		$skipped = (int) ( $prog['skipped'] ?? 0 );
		$finished_in_stage = $done + $failed + $skipped;
		$remaining         = max( 0, $queued - $finished_in_stage );
		$queue_snap        = class_exists( 'Heb_Product_Publisher_Bootstrap_Queue', false )
			? Heb_Product_Publisher_Bootstrap_Queue::get_stage_queue_snapshot( (string) ( $rec['id'] ?? '' ), $stage )
			: [ 'items' => [], 'counts' => [ 'pending' => 0, 'running' => 0, 'failed' => 0 ] ];

		$rec['activity'] = [
			'stage_pct'       => $queued > 0 ? (int) round( 100 * $finished_in_stage / $queued ) : 0,
			'stage_finished'  => $finished_in_stage,
			'stage_queued'    => $queued,
			'stage_remaining' => $remaining,
			'idle_seconds'    => $updated > 0 ? max( 0, $now - $updated ) : 0,
			'stale'           => in_array( $rec['status'] ?? '', [ self::STATUS_QUEUED, self::STATUS_RUNNING ], true )
				&& $updated > 0
				&& ( $now - $updated ) >= $stale_after,
			'stale_after'     => $stale_after,
			'pending_actions' => (int) ( $queue_snap['counts']['pending'] ?? 0 ),
			'running_actions' => (int) ( $queue_snap['counts']['running'] ?? 0 ),
			'failed_actions'  => (int) ( $queue_snap['counts']['failed'] ?? 0 ),
			'queue_items'     => $queue_snap['items'],
		];

		$idle_seconds = $updated > 0 ? max( 0, $now - $updated ) : 0;
		$rec['activity']['idle_seconds']          = $idle_seconds;
		$rec['activity']['queue_stalled']         = $remaining > 0
			&& (int) ( $queue_snap['counts']['pending'] ?? 0 ) === 0
			&& (int) ( $queue_snap['counts']['running'] ?? 0 ) === 0
			&& $idle_seconds >= 120;

		$cur = isset( $rec['current_item'] ) && is_array( $rec['current_item'] ) ? $rec['current_item'] : null;
		if ( $cur && ! empty( $cur['started_at'] ) ) {
			$rec['activity']['current_elapsed'] = max( 0, $now - (int) $cur['started_at'] );
		}

		return $rec;
	}

	/**
	 * @param string $id    Job id.
	 * @param string $level info / warning / error.
	 * @param string $msg   Log message.
	 * @return void
	 */
	public static function add_log( $id, $level, $msg ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return;
		}
		$rec['log'][] = [
			'ts'    => time(),
			'level' => (string) $level,
			'msg'   => (string) $msg,
		];
		if ( count( $rec['log'] ) > self::MAX_LOG ) {
			$rec['log'] = array_slice( $rec['log'], - self::MAX_LOG );
		}
		$rec['updated_at'] = time();
		update_option( self::OPT_PREFIX . (string) $id, $rec, false );
	}

	/**
	 * @param string $id        Job id.
	 * @param string $type      Error type (term / post / probe / settings).
	 * @param int    $source_id Source object id.
	 * @param string $msg       Error message.
	 * @return void
	 */
	public static function add_error( $id, $type, $source_id, $msg ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return;
		}
		$rec['errors'][] = [
			'ts'        => time(),
			'type'      => (string) $type,
			'source_id' => (int) $source_id,
			'message'   => (string) $msg,
		];
		if ( count( $rec['errors'] ) > self::MAX_ERROR ) {
			$rec['errors'] = array_slice( $rec['errors'], - self::MAX_ERROR );
		}
		$rec['updated_at'] = time();
		update_option( self::OPT_PREFIX . (string) $id, $rec, false );
	}

	/**
	 * 最近 N 个 job (含 done/failed)，按时间倒序。
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string,mixed>>
	 */
	public static function recent( $limit = 10 ) {
		$index = (array) get_option( self::OPT_INDEX, [] );
		$out   = [];
		foreach ( array_slice( $index, 0, (int) $limit ) as $id ) {
			$rec = self::get( $id );
			if ( $rec ) {
				$out[] = $rec;
			}
		}
		return $out;
	}

	/**
	 * 还在跑（queued / running）的 jobs。
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function running() {
		$out = [];
		foreach ( self::recent( 50 ) as $rec ) {
			if ( in_array( $rec['status'], [ self::STATUS_QUEUED, self::STATUS_RUNNING ], true ) ) {
				$out[] = $rec;
			}
		}
		return $out;
	}

	/**
	 * 是否有相同 site_id 的 job 仍在跑。
	 *
	 * @param string $site_id Site id.
	 * @return string|null Running job id or null.
	 */
	public static function site_running( $site_id ) {
		foreach ( self::running() as $rec ) {
			if ( (string) $rec['site_id'] === (string) $site_id ) {
				return (string) $rec['id'];
			}
		}
		return null;
	}

	/**
	 * 标记 cancelled 并撤销该 job 尚未执行的 AS 任务。
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public static function cancel( $id ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return false;
		}
		if ( in_array(
			$rec['status'],
			[ self::STATUS_DONE, self::STATUS_DONE_WITH_ERRORS, self::STATUS_FAILED, self::STATUS_CANCELLED ],
			true
		) ) {
			return false;
		}
		self::unschedule_job_actions( $id );
		self::update(
			$id,
			[
				'status'        => self::STATUS_CANCELLED,
				'current_stage' => self::STAGE_FINISHED,
				'finished_at'   => time(),
			]
		);
		self::add_log( $id, 'info', __( 'Job cancelled by user.', 'heb-product-publisher' ) );
		return true;
	}

	/**
	 * 撤销 Bootstrap 组内属于指定 job 的待执行 AS 动作。
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function unschedule_job_actions( $id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}
		$hooks = [
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_PROBE,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_TERM,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_POST,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_SETTINGS,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_MENU,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_FINALIZE,
			Heb_Product_Publisher_Bootstrap_Queue::HOOK_WATCHDOG,
		];
		foreach ( $hooks as $hook ) {
			$actions = as_get_scheduled_actions(
				[
					'hook'   => $hook,
					'group'  => Heb_Product_Publisher_Bootstrap_Queue::GROUP,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
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
				$args = $action->get_args();
				$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
				if ( isset( $payload['job_id'] ) && (string) $payload['job_id'] === (string) $id ) {
					as_unschedule_action( $hook, $args, Heb_Product_Publisher_Bootstrap_Queue::GROUP );
				}
			}
		}
	}

	/**
	 * @return string
	 */
	private static function gen_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return substr( md5( microtime( true ) . wp_generate_password( 12, false ) ), 0, 16 );
	}
}
