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

	const STATUS_QUEUED    = 'queued';
	const STATUS_RUNNING   = 'running';
	const STATUS_DONE      = 'done';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

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
	 * @param array<string,mixed> $opts Bootstrap options (scope toggles, dry_run 等).
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
	 * 递增某 stage 的某计数器（线程安全：用 wp options 自身的写锁，足够本场景）。
	 *
	 * @param string $id    Job id.
	 * @param string $stage Stage key.
	 * @param string $key   Counter key (queued / done / failed / skipped).
	 * @param int    $delta Increment delta.
	 * @return void
	 */
	public static function increment( $id, $stage, $key, $delta = 1 ) {
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
	 * 标记 cancelled；不主动 cancel AS 队列（worker 会自检状态后跳过）。
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public static function cancel( $id ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return false;
		}
		if ( in_array( $rec['status'], [ self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED ], true ) ) {
			return false;
		}
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
	 * @return string
	 */
	private static function gen_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return substr( md5( microtime( true ) . wp_generate_password( 12, false ) ), 0, 16 );
	}
}
