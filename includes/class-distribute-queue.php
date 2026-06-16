<?php
/**
 * Hub 单篇分发队列：任务状态入库，由前端逐站 AJAX 推进（不依赖 AS / WP-Cron）。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Distribute_Queue {

	const STEP_STALE_SECONDS = 900;
	const STEP_LOCK_SECONDS  = 120;

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private static $ctx_job_id = '';

	/**
	 * @param string $message Phase message for active job log.
	 * @return void
	 */
	public static function phase( $message ) {
		if ( '' === self::$ctx_job_id ) {
			return;
		}
		Heb_Product_Publisher_Distribute_Job::set_phase( self::$ctx_job_id, (string) $message );
	}

	/**
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function bind_job( $job_id ) {
		self::$ctx_job_id = (string) $job_id;
	}

	/**
	 * @return void
	 */
	private static function unbind_job() {
		self::$ctx_job_id = '';
	}

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
		// 无 AS hook：避免在部分主机上任务入队后永远不执行。
	}

	/**
	 * @param int                                              $post_id        Source post.
	 * @param array<int,string>                                $site_ids       Site ids.
	 * @param array<string,array<string,array<int,string>>>    $site_overrides Overrides.
	 * @return string Job id.
	 */
	public function enqueue( $post_id, array $site_ids, array $site_overrides = [] ) {
		if ( empty( $site_ids ) ) {
			return '';
		}
		Heb_Product_Publisher_Distribute_Job::cancel_active_for_post( (int) $post_id );
		return Heb_Product_Publisher_Distribute_Job::create( (int) $post_id, $site_ids, $site_overrides );
	}

	/**
	 * 处理当前 job 的下一站点（一次 AJAX 调用 = 一个站点）。
	 *
	 * @param string $job_id Job id.
	 * @param bool   $force  Bypass in-flight lock (stale recovery / user retry).
	 * @return array<string,mixed>|\WP_Error Updated job public view.
	 */
	public function process_step( $job_id, $force = false ) {
		Heb_Product_Publisher_Runtime::raise();
		if ( class_exists( 'Heb_Product_Publisher_Bootstrap_Queue' ) ) {
			Heb_Product_Publisher_Bootstrap_Queue::register_long_action_filters();
		}

		$job_id = (string) $job_id;
		$rec    = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		if ( ! $rec ) {
			return new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}
		if ( ! Heb_Product_Publisher_Distribute_Job::is_active_status( (string) $rec['status'] ) ) {
			return Heb_Product_Publisher_Distribute_Job::public_view( $rec );
		}

		$step_started = (int) ( $rec['step_started_at'] ?? 0 );
		if (
			! $force
			&& Heb_Product_Publisher_Distribute_Job::STATUS_RUNNING === (string) $rec['status']
			&& $step_started > 0
			&& ( time() - $step_started ) < self::STEP_LOCK_SECONDS
		) {
			return Heb_Product_Publisher_Distribute_Job::public_view( $rec );
		}
		if (
			Heb_Product_Publisher_Distribute_Job::STATUS_RUNNING === (string) $rec['status']
			&& $step_started > 0
			&& ( time() - $step_started ) >= self::STEP_STALE_SECONDS
		) {
			Heb_Product_Publisher_Distribute_Job::append_log(
				$job_id,
				'info',
				__( '上一站点处理超时，正在重试…', 'heb-product-publisher' )
			);
		}

		$post_id  = (int) ( $rec['post_id'] ?? 0 );
		$index    = (int) ( $rec['index'] ?? 0 );
		$total    = (int) ( $rec['total'] ?? 0 );
		$site_ids = isset( $rec['site_ids'] ) && is_array( $rec['site_ids'] ) ? $rec['site_ids'] : [];

		if ( $post_id <= 0 || $index >= $total || empty( $site_ids[ $index ] ) ) {
			$this->finalize( $job_id );
			$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
			return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}

		$site_id = (string) $site_ids[ $index ];
		$site    = Heb_Product_Publisher_Admin_Settings::get_site( $site_id );
		$label   = $site ? (string) $site['label'] : $site_id;

		Heb_Product_Publisher_Distribute_Job::update(
			$job_id,
			[
				'status'          => Heb_Product_Publisher_Distribute_Job::STATUS_RUNNING,
				'current_site'    => $site_id,
				'step_started_at' => time(),
				'current_phase'   => __( '准备分发…', 'heb-product-publisher' ),
			]
		);
		Heb_Product_Publisher_Distribute_Job::append_log(
			$job_id,
			'info',
			sprintf(
				/* translators: 1: site label, 2: current index, 3: total */
				__( '正在分发到 %1$s（%2$d/%3$d）…', 'heb-product-publisher' ),
				$label,
				$index + 1,
				$total
			)
		);

		if ( ! $site ) {
			$result = [ 'ok' => false, 'message' => __( '未找到站点。', 'heb-product-publisher' ) ];
			Heb_Product_Publisher_Distribute_Job::set_result( $job_id, $site_id, $result );
			Heb_Product_Publisher_Distribute_Job::append_log( $job_id, 'fail', $label . ': ' . $result['message'] );
			$this->advance( $job_id );
			$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
			return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}

		try {
			$basepayload = Heb_Product_Publisher_Sync::build_payload( $post_id );
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Distribute_Job::mark_failed(
				$job_id,
				sprintf( 'build_payload: %s', $e->getMessage() )
			);
			$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
			return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}
		if ( empty( $basepayload ) ) {
			Heb_Product_Publisher_Distribute_Job::mark_failed(
				$job_id,
				__( '无法构造 payload（post 不存在或类型不允许分发）。', 'heb-product-publisher' )
			);
			$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
			return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}

		$source_locale  = isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : 'en_US';
		$site_overrides = isset( $rec['site_overrides'] ) && is_array( $rec['site_overrides'] ) ? $rec['site_overrides'] : [];
		$translator     = new Heb_Product_Publisher_Translator();
		$hub            = Heb_Product_Publisher_Hub_UI::instance();

		self::bind_job( $job_id );
		try {
			$hub->log_distribution_started( $post_id, $site );
			self::phase( __( '开始翻译并推送…', 'heb-product-publisher' ) );

			try {
				$result = $hub->distribute_to_site( $post_id, $basepayload, $source_locale, $site, $site_overrides, $translator );
			} catch ( \Throwable $e ) {
				$msg    = sprintf( 'distribute_to_site: %s', $e->getMessage() );
				$result = [ 'ok' => false, 'message' => $msg, 'locale' => '' ];
				$hub->log_distribution_failure( $post_id, $site_id, $msg, $site );
			}
		} finally {
			self::unbind_job();
		}

		$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		if ( ! $rec || ! Heb_Product_Publisher_Distribute_Job::is_active_status( (string) $rec['status'] ) ) {
			return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
		}

		$result['label'] = $label;
		Heb_Product_Publisher_Distribute_Job::set_result( $job_id, $site_id, $result );
		if ( ! empty( $result['ok'] ) ) {
			Heb_Product_Publisher_Distribute_Job::append_log( $job_id, 'ok', $label . ': ' . __( '成功', 'heb-product-publisher' ) );
		} else {
			$fail_msg = isset( $result['message'] ) ? (string) $result['message'] : __( '失败', 'heb-product-publisher' );
			Heb_Product_Publisher_Distribute_Job::append_log( $job_id, 'fail', $label . ': ' . $fail_msg );
		}

		$this->advance( $job_id );
		$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		return $rec ? Heb_Product_Publisher_Distribute_Job::public_view( $rec ) : new \WP_Error( 'heb_pp_dist_missing', __( '任务不存在。', 'heb-product-publisher' ) );
	}

	/**
	 * @param string $job_id Job id.
	 * @return void
	 */
	private function advance( $job_id ) {
		$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		if ( ! $rec ) {
			return;
		}
		$index = (int) ( $rec['index'] ?? 0 ) + 1;
		$total = (int) ( $rec['total'] ?? 0 );
		Heb_Product_Publisher_Distribute_Job::update(
			$job_id,
			[
				'index'           => $index,
				'current_site'    => '',
				'step_started_at' => 0,
			]
		);
		if ( $index >= $total ) {
			$this->finalize( $job_id );
		}
	}

	/**
	 * @param string $job_id Job id.
	 * @return void
	 */
	private function finalize( $job_id ) {
		$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		if ( ! $rec ) {
			return;
		}
		$results = isset( $rec['results'] ) && is_array( $rec['results'] ) ? $rec['results'] : [];
		$fail    = 0;
		foreach ( $results as $row ) {
			if ( empty( $row['ok'] ) ) {
				++$fail;
			}
		}
		$status = $fail > 0
			? Heb_Product_Publisher_Distribute_Job::STATUS_DONE_WITH_ERRORS
			: Heb_Product_Publisher_Distribute_Job::STATUS_DONE;

		Heb_Product_Publisher_Distribute_Job::update(
			$job_id,
			[
				'status'          => $status,
				'finished_at'     => time(),
				'current_site'    => '',
				'step_started_at' => 0,
			]
		);
		Heb_Product_Publisher_Distribute_Job::append_log(
			$job_id,
			$fail > 0 ? 'fail' : 'ok',
			$fail > 0
				? sprintf(
					/* translators: 1: ok count, 2: fail count */
					__( '分发完成：%1$d 成功，%2$d 失败。', 'heb-product-publisher' ),
					max( 0, count( $results ) - $fail ),
					$fail
				)
				: __( '全部分发成功。', 'heb-product-publisher' )
		);
		Heb_Product_Publisher_Distribute_Job::clear_active_pointer( $job_id );
	}
}
