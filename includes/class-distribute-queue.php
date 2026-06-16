<?php
/**
 * Hub 单篇分发后台队列（Action Scheduler，逐站执行）。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Distribute_Queue {

	const HOOK_STEP = 'heb_pp_hub_distribute_step';
	const GROUP     = 'heb-pp-distribute';

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
		add_action( self::HOOK_STEP, [ $this, 'handle_step' ], 10, 1 );
	}

	/**
	 * @param int                                              $post_id        Source post.
	 * @param array<int,string>                                $site_ids       Site ids.
	 * @param array<string,array<string,array<int,string>>>    $site_overrides Overrides.
	 * @return string|\WP_Error Job id.
	 */
	public function enqueue( $post_id, array $site_ids, array $site_overrides = [] ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error( 'heb_pp_as_missing', __( 'Action Scheduler 未加载，无法后台分发。', 'heb-product-publisher' ) );
		}
		if ( empty( $site_ids ) ) {
			return new \WP_Error( 'heb_pp_no_sites', __( '未选择目标站点。', 'heb-product-publisher' ) );
		}

		Heb_Product_Publisher_Distribute_Job::cancel_active_for_post( (int) $post_id );
		$job_id = Heb_Product_Publisher_Distribute_Job::create( (int) $post_id, $site_ids, $site_overrides );
		$this->schedule_step( $job_id, 0 );
		$this->kick_runner();
		return $job_id;
	}

	/**
	 * @param string $job_id Job id.
	 * @param int    $delay  Delay seconds.
	 * @return void
	 */
	public function schedule_step( $job_id, $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = [ [ 'job_id' => (string) $job_id ] ];
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_STEP, $args, self::GROUP );
			return;
		}
		as_enqueue_async_action( self::HOOK_STEP, $args, self::GROUP );
	}

	/**
	 * @param array<int,mixed>|array<string,mixed> $args Args.
	 * @return void
	 */
	public function handle_step( $args ) {
		Heb_Product_Publisher_Runtime::raise();
		$args   = $this->normalize_args( $args );
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( '' === $job_id ) {
			return;
		}

		$rec = Heb_Product_Publisher_Distribute_Job::get( $job_id );
		if ( ! $rec || ! Heb_Product_Publisher_Distribute_Job::is_active_status( (string) $rec['status'] ) ) {
			return;
		}

		$post_id = (int) ( $rec['post_id'] ?? 0 );
		$index   = (int) ( $rec['index'] ?? 0 );
		$total   = (int) ( $rec['total'] ?? 0 );
		$site_ids = isset( $rec['site_ids'] ) && is_array( $rec['site_ids'] ) ? $rec['site_ids'] : [];

		if ( $post_id <= 0 || $index >= $total || empty( $site_ids[ $index ] ) ) {
			$this->finalize( $job_id );
			return;
		}

		$site_id = (string) $site_ids[ $index ];
		$site    = Heb_Product_Publisher_Admin_Settings::get_site( $site_id );
		$label   = $site ? (string) $site['label'] : $site_id;

		Heb_Product_Publisher_Distribute_Job::update(
			$job_id,
			[
				'status'       => Heb_Product_Publisher_Distribute_Job::STATUS_RUNNING,
				'current_site' => $site_id,
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
			return;
		}

		try {
			$basepayload = Heb_Product_Publisher_Sync::build_payload( $post_id );
		} catch ( \Throwable $e ) {
			Heb_Product_Publisher_Distribute_Job::mark_failed(
				$job_id,
				sprintf( 'build_payload: %s', $e->getMessage() )
			);
			return;
		}
		if ( empty( $basepayload ) ) {
			Heb_Product_Publisher_Distribute_Job::mark_failed(
				$job_id,
				__( '无法构造 payload（post 不存在或类型不允许分发）。', 'heb-product-publisher' )
			);
			return;
		}

		$source_locale  = isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : 'en_US';
		$site_overrides = isset( $rec['site_overrides'] ) && is_array( $rec['site_overrides'] ) ? $rec['site_overrides'] : [];
		$translator     = new Heb_Product_Publisher_Translator();
		$hub            = Heb_Product_Publisher_Hub_UI::instance();

		$hub->log_distribution_started( $post_id, $site );

		try {
			$result = $hub->distribute_to_site( $post_id, $basepayload, $source_locale, $site, $site_overrides, $translator );
		} catch ( \Throwable $e ) {
			$msg    = sprintf( 'distribute_to_site: %s', $e->getMessage() );
			$result = [ 'ok' => false, 'message' => $msg, 'locale' => '' ];
			$hub->log_distribution_failure( $post_id, $site_id, $msg, $site );
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
				'index'        => $index,
				'current_site' => '',
			]
		);
		if ( $index >= $total ) {
			$this->finalize( $job_id );
			return;
		}
		$this->schedule_step( $job_id, 1 );
		$this->kick_runner();
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
				'status'       => $status,
				'finished_at'  => time(),
				'current_site' => '',
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

	/**
	 * @return void
	 */
	private function kick_runner() {
		if ( has_action( 'action_scheduler_run_queue' ) ) {
			do_action( 'action_scheduler_run_queue', self::GROUP );
		}
	}

	/**
	 * @param array<int,mixed>|array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private function normalize_args( $args ) {
		if ( is_array( $args ) && ! isset( $args['job_id'] ) ) {
			$first = reset( $args );
			if ( is_array( $first ) ) {
				return $first;
			}
		}
		return is_array( $args ) ? $args : [];
	}
}
