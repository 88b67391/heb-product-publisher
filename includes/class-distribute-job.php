<?php
/**
 * 单篇文章 Hub 分发任务状态（逐站 AJAX 推进，可刷新恢复）。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Distribute_Job {

	const OPT_PREFIX        = 'heb_pp_dist_job_';
	const OPT_ACTIVE_PREFIX = 'heb_pp_dist_active_';
	const MAX_LOG           = 80;

	const STATUS_QUEUED           = 'queued';
	const STATUS_RUNNING          = 'running';
	const STATUS_DONE             = 'done';
	const STATUS_DONE_WITH_ERRORS = 'done_with_errors';
	const STATUS_FAILED           = 'failed';
	const STATUS_CANCELLED        = 'cancelled';

	/**
	 * @param int                              $post_id        Source post id.
	 * @param array<int,string>                $site_ids       Target site ids.
	 * @param array<string,array<string,array<int,string>>> $site_overrides Taxonomy overrides.
	 * @return string Job id.
	 */
	public static function create( $post_id, array $site_ids, array $site_overrides = [] ) {
		$post_id  = (int) $post_id;
		$site_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $site_ids ),
					static function ( $id ) {
						return '' !== $id;
					}
				)
			)
		);

		$id  = self::gen_id();
		$now = time();
		$rec = [
			'id'             => $id,
			'post_id'        => $post_id,
			'site_ids'       => $site_ids,
			'site_overrides' => $site_overrides,
			'status'         => self::STATUS_QUEUED,
			'index'          => 0,
			'total'          => count( $site_ids ),
			'current_site'   => '',
			'results'        => [],
			'log'            => [],
			'started_at'     => $now,
			'updated_at'     => $now,
			'finished_at'    => 0,
			'step_started_at'=> 0,
			'current_phase'  => '',
		];

		update_option( self::OPT_PREFIX . $id, $rec, false );
		update_option( self::OPT_ACTIVE_PREFIX . $post_id, $id, false );
		self::append_log( $id, 'info', __( '分发任务已创建。', 'heb-product-publisher' ) );
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
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|null
	 */
	public static function get_active_for_post( $post_id ) {
		$job_id = get_option( self::OPT_ACTIVE_PREFIX . (int) $post_id, '' );
		if ( ! is_string( $job_id ) || '' === $job_id ) {
			return null;
		}
		$rec = self::get( $job_id );
		if ( ! $rec || ! self::is_active_status( (string) $rec['status'] ) ) {
			delete_option( self::OPT_ACTIVE_PREFIX . (int) $post_id );
			return null;
		}
		return $rec;
	}

	/**
	 * @param string              $id    Job id.
	 * @param array<string,mixed> $patch Patch.
	 * @return array<string,mixed>|null
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
	 * @param string $id      Job id.
	 * @param string $level   info|ok|fail.
	 * @param string $message Message.
	 * @return void
	 */
	public static function append_log( $id, $level, $message ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return;
		}
		$log   = isset( $rec['log'] ) && is_array( $rec['log'] ) ? $rec['log'] : [];
		$log[] = [
			't' => time(),
			'l' => sanitize_key( $level ),
			'm' => (string) $message,
		];
		if ( count( $log ) > self::MAX_LOG ) {
			$log = array_slice( $log, -self::MAX_LOG );
		}
		self::update( $id, [ 'log' => $log ] );
	}

	/**
	 * @param string              $id     Job id.
	 * @param string              $site_id Site id.
	 * @param array<string,mixed> $result Result row.
	 * @return void
	 */
	public static function set_result( $id, $site_id, array $result ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return;
		}
		$results                 = isset( $rec['results'] ) && is_array( $rec['results'] ) ? $rec['results'] : [];
		$results[ (string) $site_id ] = $result;
		self::update( $id, [ 'results' => $results ] );
	}

	/**
	 * @param string $id Job id.
	 * @return void
	 */
	public static function clear_active_pointer( $id ) {
		$rec = self::get( $id );
		if ( ! $rec ) {
			return;
		}
		$post_id = (int) ( $rec['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return;
		}
		$active = get_option( self::OPT_ACTIVE_PREFIX . $post_id, '' );
		if ( (string) $active === (string) $id ) {
			delete_option( self::OPT_ACTIVE_PREFIX . $post_id );
		}
	}

	/**
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function cancel_active_for_post( $post_id ) {
		$rec = self::get_active_for_post( (int) $post_id );
		if ( ! $rec ) {
			return;
		}
		self::mark_cancelled( (string) $rec['id'], __( '被新任务取代。', 'heb-product-publisher' ) );
	}

	/**
	 * @param string $id      Job id.
	 * @param string $message Message.
	 * @return void
	 */
	public static function mark_cancelled( $id, $message = '' ) {
		self::append_log( $id, 'info', '' !== $message ? $message : __( '任务已取消。', 'heb-product-publisher' ) );
		self::update(
			$id,
			[
				'status'          => self::STATUS_CANCELLED,
				'finished_at'     => time(),
				'current_site'    => '',
				'step_started_at' => 0,
				'current_phase'   => '',
			]
		);
		self::clear_active_pointer( $id );
	}

	/**
	 * @param string $id Job id.
	 * @return void
	 */
	public static function mark_failed( $id, $message ) {
		self::append_log( $id, 'fail', (string) $message );
		self::update(
			$id,
			[
				'status'       => self::STATUS_FAILED,
				'finished_at'  => time(),
				'current_site' => '',
			]
		);
		self::clear_active_pointer( $id );
	}

	/**
	 * @param array<string,mixed> $rec Job record.
	 * @return array<string,mixed>
	 */
	public static function public_view( array $rec ) {
		$post_id = (int) ( $rec['post_id'] ?? 0 );
		$sites   = [];
		foreach ( (array) ( $rec['site_ids'] ?? [] ) as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( (string) $sid );
			$sites[ (string) $sid ] = $site ? (string) $site['label'] : (string) $sid;
		}
		return [
			'id'           => (string) ( $rec['id'] ?? '' ),
			'post_id'      => $post_id,
			'status'       => (string) ( $rec['status'] ?? '' ),
			'index'        => (int) ( $rec['index'] ?? 0 ),
			'total'        => (int) ( $rec['total'] ?? 0 ),
			'current_site' => (string) ( $rec['current_site'] ?? '' ),
			'current_label'=> isset( $rec['current_site'], $sites[ (string) $rec['current_site'] ] )
				? $sites[ (string) $rec['current_site'] ]
				: '',
			'step_started_at' => (int) ( $rec['step_started_at'] ?? 0 ),
			'current_phase'   => (string) ( $rec['current_phase'] ?? '' ),
			'processing_index'=> self::processing_index( $rec ),
			'results'      => isset( $rec['results'] ) && is_array( $rec['results'] ) ? $rec['results'] : [],
			'log'          => isset( $rec['log'] ) && is_array( $rec['log'] ) ? $rec['log'] : [],
			'site_labels'  => $sites,
			'started_at'   => (int) ( $rec['started_at'] ?? 0 ),
			'updated_at'   => (int) ( $rec['updated_at'] ?? 0 ),
			'finished_at'  => (int) ( $rec['finished_at'] ?? 0 ),
		];
	}

	/**
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_active_status( $status ) {
		return in_array( (string) $status, [ self::STATUS_QUEUED, self::STATUS_RUNNING ], true );
	}

	/**
	 * @param array<string,mixed> $rec Job record.
	 * @return int 1-based index of site being processed (or next queued).
	 */
	public static function processing_index( array $rec ) {
		$index = (int) ( $rec['index'] ?? 0 );
		$total = (int) ( $rec['total'] ?? 0 );
		if ( $total <= 0 ) {
			return 0;
		}
		if ( self::STATUS_RUNNING === (string) ( $rec['status'] ?? '' ) && ! empty( $rec['current_site'] ) ) {
			return min( $total, $index + 1 );
		}
		if ( self::STATUS_QUEUED === (string) ( $rec['status'] ?? '' ) ) {
			return min( $total, $index + 1 );
		}
		return min( $total, $index );
	}

	/**
	 * @return string
	 */
	private static function gen_id() {
		return substr( md5( uniqid( 'dist', true ) . wp_generate_password( 8, false ) ), 0, 12 );
	}
}
