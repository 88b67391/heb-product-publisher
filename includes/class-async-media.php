<?php
/**
 * Receiver 端：Elementor 图片异步 sideload。
 *
 * 流程：
 *  1. rest_import 解码 Elementor data 时，遇到图片不立即 sideload，
 *     而是把远端 URL 写入 `_heb_pp_pending_media` post meta（去重），
 *     Elementor JSON 暂时保留 { id: 0, url: <远端> } 让前台直接显示原图。
 *  2. REST 处理完立刻 schedule 一个 AS task：`heb_pp_sideload_post_media`。
 *     REST 立刻返回 200，HTTP 等待秒级而非分钟级。
 *  3. AS worker 后台逐 URL sideload → 拿到本地 attachment + 本地 URL →
 *     扫描当前 `_elementor_data` JSON：先 str_replace URL，再递归
 *     把 { id: 0, url: <本地> } 的 id 换成本地 attachment id。
 *     全部 sideload 完后清掉 pending 标记 + 清 Elementor 渲染缓存。
 *
 * 设计取舍：
 *  - ACF image / featured image 仍同步处理（量少，几张图开销可接受）；
 *  - Elementor data 是大头（一页几十张图）→ 必须异步；
 *  - 失败的 URL 在 pending 列表保留，下次 AS task 自动重跑；
 *  - 同 URL 多 post 复用：依赖 Receiver::find_sideloaded_attachment + META_SIDELOAD_SRC。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Async_Media {

	const AS_HOOK         = 'heb_pp_sideload_post_media';
	const AS_GROUP        = 'heb-pp-async-media';
	const META_PENDING    = '_heb_pp_pending_media';      // array<string url>
	const META_STATUS     = '_heb_pp_media_status';        // 'pending' | 'done' | 'failed:<reason>'
	const META_LAST_RUN   = '_heb_pp_media_last_run';      // unix ts
	const META_TRIED      = '_heb_pp_media_tried';         // array<string url => int count>
	const MAX_RETRIES     = 5;

	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::AS_HOOK, [ $this, 'handle_sideload' ], 10, 1 );
	}

	/**
	 * Receiver 端在 rest_import 完成后调用，安排异步 sideload。
	 *
	 * @param int           $post_id     目标 post id.
	 * @param array<string> $pending_urls 待 sideload 的远端 URL 列表（已去重，仅 Elementor 来的）。
	 * @return int 实际记录的待处理 URL 数。
	 */
	public static function enqueue( $post_id, array $pending_urls ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		// 合并已有 pending（可能上一次 task 还没跑完，又收到了新分发）。
		$existing = get_post_meta( $post_id, self::META_PENDING, true );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}
		$merged = array_values( array_unique( array_filter( array_merge( $existing, $pending_urls ), 'is_string' ) ) );

		if ( empty( $merged ) ) {
			delete_post_meta( $post_id, self::META_PENDING );
			delete_post_meta( $post_id, self::META_STATUS );
			return 0;
		}

		update_post_meta( $post_id, self::META_PENDING, $merged );
		update_post_meta( $post_id, self::META_STATUS, 'pending' );

		// 避免重复排队：如果已有同 post 的 task 在 pending，不再 schedule。
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( as_has_scheduled_action( self::AS_HOOK, [ 'post_id' => $post_id ], self::AS_GROUP ) ) {
				return count( $merged );
			}
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 1, self::AS_HOOK, [ 'post_id' => $post_id ], self::AS_GROUP );
		}

		return count( $merged );
	}

	/**
	 * AS handler：处理一个 post 的 pending media。
	 *
	 * @param int $post_id Post id (AS 传入)。
	 * @return void
	 */
	public function handle_sideload( $post_id ) {
		Heb_Product_Publisher_Runtime::raise();

		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return;
		}

		$pending = get_post_meta( $post_id, self::META_PENDING, true );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			delete_post_meta( $post_id, self::META_STATUS );
			return;
		}

		update_post_meta( $post_id, self::META_LAST_RUN, time() );

		$tried = get_post_meta( $post_id, self::META_TRIED, true );
		if ( ! is_array( $tried ) ) {
			$tried = [];
		}

		$still_pending = [];
		$replacements  = []; // remote_url => [ 'id' => int, 'url' => string ]

		foreach ( $pending as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$attempt = isset( $tried[ $url ] ) ? (int) $tried[ $url ] : 0;
			if ( $attempt >= self::MAX_RETRIES ) {
				continue;
			}
			$tried[ $url ] = $attempt + 1;

			$attachment_id = Heb_Product_Publisher_Receiver::instance()->public_sideload_url( $url );
			if ( $attachment_id <= 0 ) {
				$still_pending[] = $url;
				continue;
			}
			$local_url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( '' === $local_url ) {
				$still_pending[] = $url;
				continue;
			}
			$replacements[ $url ] = [
				'id'  => (int) $attachment_id,
				'url' => $local_url,
			];
		}

		if ( ! empty( $replacements ) ) {
			$this->apply_replacements( $post_id, $replacements );
		}

		update_post_meta( $post_id, self::META_TRIED, $tried );

		if ( empty( $still_pending ) ) {
			delete_post_meta( $post_id, self::META_PENDING );
			update_post_meta( $post_id, self::META_STATUS, 'done' );
			delete_post_meta( $post_id, self::META_TRIED );
			$this->clear_elementor_cache( $post_id );
			return;
		}

		// 还有失败的：留着 + 重新排队（指数退避：尝试次数越多越晚跑）。
		update_post_meta( $post_id, self::META_PENDING, array_values( array_unique( $still_pending ) ) );
		$max_attempt = 0;
		foreach ( $still_pending as $u ) {
			$a = isset( $tried[ $u ] ) ? (int) $tried[ $u ] : 0;
			if ( $a > $max_attempt ) {
				$max_attempt = $a;
			}
		}
		if ( $max_attempt >= self::MAX_RETRIES ) {
			update_post_meta( $post_id, self::META_STATUS, 'failed:max_retries' );
			// 仍清缓存，让前台至少显示已 sideload 的那些。
			$this->clear_elementor_cache( $post_id );
			return;
		}

		$delay = min( 3600, 60 * (int) pow( 2, max( 0, $max_attempt - 1 ) ) ); // 60s, 120s, 240s, 480s, 960s
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::AS_HOOK, [ 'post_id' => $post_id ], self::AS_GROUP );
		}
		update_post_meta( $post_id, self::META_STATUS, 'pending' );
		// 部分完成也清一下缓存，让已 sideload 的图能用本地副本渲染。
		$this->clear_elementor_cache( $post_id );
	}

	/**
	 * 把 sideload 完成的远端 URL 替换为本地 URL + 本地 attachment id。
	 *
	 * 替换两类位置：
	 *  - `_elementor_data` JSON 字符串：先 str_replace url，再 decode/递归改 id；
	 *  - `_elementor_page_settings` 数组：递归改 url + id；
	 *  - `_thumbnail_id` 关联的 attachment：暂不处理（featured 已同步 sideload）。
	 *
	 * @param int                                          $post_id      Post id.
	 * @param array<string,array{id:int,url:string}>       $replacements remote_url => {id, url}.
	 * @return void
	 */
	private function apply_replacements( $post_id, array $replacements ) {
		$ed = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $ed ) && '' !== $ed ) {
			$decoded = json_decode( $ed, true );
			if ( is_array( $decoded ) ) {
				$new = $this->walk_replace( $decoded, $replacements );
				$json = wp_json_encode( $new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( is_string( $json ) ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
				}
			}
		}

		$eps = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_array( $eps ) ) {
			$new_eps = $this->walk_replace( $eps, $replacements );
			update_post_meta( $post_id, '_elementor_page_settings', $new_eps );
		}
	}

	/**
	 * 递归替换：找到 { id, url } 形态的节点，若 url 命中 replacement，把 id+url 改为本地值。
	 *
	 * @param mixed                                        $value        节点。
	 * @param array<string,array{id:int,url:string}>       $replacements 替换表。
	 * @return mixed
	 */
	private function walk_replace( $value, array $replacements ) {
		if ( is_array( $value ) ) {
			// 命中 Elementor image 节点 { id, url, ... }，url 命中就换。
			$has_url = isset( $value['url'] ) && is_string( $value['url'] ) && '' !== $value['url'];
			if ( $has_url && isset( $replacements[ $value['url'] ] ) ) {
				$rep          = $replacements[ $value['url'] ];
				$value['id']  = $rep['id'];
				$value['url'] = $rep['url'];
				// 继续递归子节点（嵌套 image 罕见但保留通用性）。
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->walk_replace( $v, $replacements );
			}
			return $value;
		}
		return $value;
	}

	/**
	 * 清 Elementor 渲染缓存（CSS）让本地化后的图立即生效。
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function clear_elementor_cache( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$plugin = \Elementor\Plugin::instance();
				if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * 给 Dashboard / manifest 用：post 的 sideload 进度。
	 *
	 * @param int $post_id Post id.
	 * @return array{pending:int,status:string,last_run:int}
	 */
	public static function progress( $post_id ) {
		$pending = get_post_meta( (int) $post_id, self::META_PENDING, true );
		$status  = get_post_meta( (int) $post_id, self::META_STATUS, true );
		$last    = get_post_meta( (int) $post_id, self::META_LAST_RUN, true );
		return [
			'pending'  => is_array( $pending ) ? count( $pending ) : 0,
			'status'   => is_string( $status ) && '' !== $status ? $status : 'done',
			'last_run' => (int) $last,
		];
	}
}
