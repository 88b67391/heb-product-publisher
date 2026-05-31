<?php
/**
 * 集中放开 PHP 时间限制 + 内存限制，专门给跨进程长任务用。
 *
 * 触发位置：
 *  1. Action Scheduler 跑我们的 heb-pp-bootstrap group 的 task 之前
 *     (sideload 几十张图 + OpenRouter 多 batch 翻译，单 task 跑 5-10 分钟很正常)
 *  2. Receiver 端 REST 入口 (/import-product / /import-term / /import-menu /
 *     /import-settings / /manifest / /delete-by-source) 进入处理之前
 *
 * 不在插件全局做 set_time_limit(0)，避免影响普通后台页加载。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Runtime {

	const AS_GROUP_PREFIX = 'heb-pp-';

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
		// AS 在跑单个 action 之前发这个 hook（在 worker handle_* 之前）；
		// 按 group 过滤，避免影响 WooCommerce 或其他插件的 AS 任务。
		add_action( 'action_scheduler_begin_execute', [ $this, 'on_as_begin_execute' ], 10, 1 );
	}

	/**
	 * @param int $action_id Action id.
	 * @return void
	 */
	public function on_as_begin_execute( $action_id ) {
		if ( ! class_exists( '\\ActionScheduler' ) || ! method_exists( '\\ActionScheduler', 'store' ) ) {
			return;
		}
		try {
			$action = \ActionScheduler::store()->fetch_action( (int) $action_id );
			if ( ! $action || ! method_exists( $action, 'get_group' ) ) {
				return;
			}
			$group = (string) $action->get_group();
			if ( 0 !== strpos( $group, self::AS_GROUP_PREFIX ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			// 拿不到 group 时按"是我们的"处理，宁可多放开一次。
			unset( $e );
		}
		if ( 0 === strpos( $group, self::AS_GROUP_PREFIX ) ) {
			Heb_Product_Publisher_Bootstrap_Queue::register_long_action_filters();
		}
		self::raise();
	}

	/**
	 * 显式调用：放开 PHP 时间/内存限制；幂等。
	 *
	 * @return void
	 */
	public static function raise() {
		// set_time_limit(0) 在 safe_mode / disabled_functions 下会抛 warning，所以 @ 屏蔽。
		// 同时还设一个 ini_set 作为双保险（部分主机 set_time_limit 被禁但 ini_set 还能改）。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky

		// 内存：跑大 Elementor JSON 翻译 + 图片 sideload 容易撞 default 256M。
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		// 兜底再 ini_set 一次到 512M（image context 拿到的可能小于这个）。
		$current = @ini_get( 'memory_limit' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $current ) {
			$current_bytes = wp_convert_hr_to_bytes( (string) $current );
			$target_bytes  = wp_convert_hr_to_bytes( '512M' );
			if ( $current_bytes > 0 && $current_bytes < $target_bytes ) {
				@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			}
		}

		// 让 user_abort 不打断长任务（用户刷新页面/关闭浏览器时 PHP 默认会停）。
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
