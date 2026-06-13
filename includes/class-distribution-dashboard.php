<?php
/**
 * Hub 端：分发管理 Dashboard。
 *
 * 行 = 主站 distributable post / term；列 = 远端站点；单元格 = 分发状态：
 *   synced (✓)    : 子站有对应记录且 modified >= 主站 modified
 *   outdated (⊘)  : 子站有但 modified < 主站
 *   locked (🔒)   : 子站对应 post 被本地锁定
 *   not_sent (—)  : 子站没有对应记录
 *
 * 子站状态来自 /manifest 端点；用 transient 缓存 5 分钟避免每次访问 dashboard 都发请求。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Distribution_Dashboard {

	const PAGE_SLUG    = 'heb-pp-dashboard';
	const NONCE_ACTION = 'heb_pp_dashboard';
	const CACHE_TTL    = 5 * MINUTE_IN_SECONDS;
	const CACHE_PREFIX = 'heb_pp_manifest_';

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
		add_action( 'admin_menu', [ $this, 'add_menu' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_heb_pp_dash_manifest', [ $this, 'ajax_manifest' ] );
		add_action( 'wp_ajax_heb_pp_dash_resend', [ $this, 'ajax_resend' ] );
		add_action( 'wp_ajax_heb_pp_dash_bulk_resend', [ $this, 'ajax_bulk_resend' ] );
		add_action( 'wp_ajax_heb_pp_dash_clear_cache', [ $this, 'ajax_clear_cache' ] );
	}

	public function add_menu() {
		add_submenu_page(
			Heb_Product_Publisher_Admin_Menu::PARENT_SLUG,
			__( 'HEB Distribution Dashboard', 'heb-product-publisher' ),
			__( '分发总览', 'heb-product-publisher' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * @param string $hook Current page hook.
	 */
	public function enqueue_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && Heb_Product_Publisher_Admin_Menu::hook_suffix( self::PAGE_SLUG ) !== $hook ) {
			return;
		}
		wp_enqueue_style( 'heb-pp-dashboard', HEB_PP_URL . 'assets/css/dashboard.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-dashboard', HEB_PP_URL . 'assets/js/dashboard.js', [ 'jquery' ], HEB_PP_VERSION, true );
		wp_localize_script(
			'heb-pp-dashboard',
			'HebPPDashboard',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'loading'        => __( '加载中…', 'heb-product-publisher' ),
					'refreshing'     => __( '刷新中…', 'heb-product-publisher' ),
					'clearing'       => __( '清除中…', 'heb-product-publisher' ),
					'manifestErrors' => __( '部分站点 manifest 拉取失败，悬停单元格查看详情。', 'heb-product-publisher' ),
					'noManifest'     => __( '无法获取 manifest', 'heb-product-publisher' ),
					'resending'      => __( '重发中…', 'heb-product-publisher' ),
					'sentDone'       => __( '已重发', 'heb-product-publisher' ),
					'sentFailed'     => __( '重发失败', 'heb-product-publisher' ),
					'confirmBulk'    => __( '把选中的项目重新分发到"未同步/过期/锁定"的站点？', 'heb-product-publisher' ),
					'clearCache'     => __( '清除 manifest 缓存', 'heb-product-publisher' ),
					'queued'         => __( '已入队', 'heb-product-publisher' ),
					'selectRows'     => __( '请先勾选要重发的行。', 'heb-product-publisher' ),
					'noSiteColumns'  => __( '未找到远端站点列，请检查设置中的远端站点配置。', 'heb-product-publisher' ),
				],
			]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'heb-product-publisher' ) );
		}
		$sites = Heb_Product_Publisher_Admin_Settings::remote_sites();
		?>
		<div class="wrap heb-pp-dashboard">
			<h1><?php esc_html_e( 'HEB Distribution Dashboard', 'heb-product-publisher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '查看主站内容在每个远端站点的分发状态；可单条/批量重发。状态来自子站 /manifest，缓存 5 分钟。', 'heb-product-publisher' ); ?>
			</p>

			<?php if ( empty( $sites ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( '尚未配置远端站点。', 'heb-product-publisher' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<p class="heb-pp-dash-toolbar">
				<button type="button" class="button" id="heb-pp-dash-refresh"><?php esc_html_e( '刷新所有站点 manifest', 'heb-product-publisher' ); ?></button>
				<button type="button" class="button" id="heb-pp-dash-clear-cache"><?php esc_html_e( '清除 manifest 缓存', 'heb-product-publisher' ); ?></button>
				<button type="button" class="button" id="heb-pp-dash-bulk-resend" disabled><?php esc_html_e( '批量重发选中', 'heb-product-publisher' ); ?></button>
				<span id="heb-pp-dash-status"></span>
			</p>

			<h2 class="heb-pp-dash-section-title"><?php esc_html_e( '📄 内容（posts）', 'heb-product-publisher' ); ?></h2>
			<?php $this->render_table( 'posts', $sites ); ?>

			<h2 class="heb-pp-dash-section-title"><?php esc_html_e( '🏷 分类（terms）', 'heb-product-publisher' ); ?></h2>
			<?php $this->render_table( 'terms', $sites ); ?>
		</div>
		<?php
	}

	/**
	 * @param string                       $kind  posts|terms.
	 * @param array<int, array<string,string>> $sites Sites.
	 * @return void
	 */
	private function render_table( $kind, $sites ) {
		$rows = 'posts' === $kind ? $this->source_posts() : $this->source_terms();
		?>
		<div class="heb-pp-dash-table-wrap">
		<table class="widefat striped heb-pp-dash-table" data-kind="<?php echo esc_attr( $kind ); ?>">
			<thead>
				<tr>
					<th class="heb-pp-dash-check-col"><input type="checkbox" class="heb-pp-dash-select-all" aria-label="<?php esc_attr_e( '全选', 'heb-product-publisher' ); ?>" /></th>
					<th><?php esc_html_e( '标题', 'heb-product-publisher' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( '类型', 'heb-product-publisher' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Source ID', 'heb-product-publisher' ); ?></th>
					<?php foreach ( $sites as $s ) : ?>
						<th style="text-align:center;" data-site-id="<?php echo esc_attr( $s['id'] ); ?>"><?php echo esc_html( $s['label'] ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( '操作', 'heb-product-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo (int) ( 5 + count( $sites ) ); ?>"><em><?php esc_html_e( '暂无可分发对象。', 'heb-product-publisher' ); ?></em></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr
							class="heb-pp-dash-row"
							data-source-id="<?php echo esc_attr( $row['id'] ); ?>"
							data-modified="<?php echo esc_attr( $row['modified'] ); ?>"
							data-type="<?php echo esc_attr( $row['type'] ); ?>"
							data-kind="<?php echo esc_attr( $kind ); ?>"
							<?php if ( 'terms' === $kind ) : ?>
								data-sync-hash="<?php echo esc_attr( isset( $row['sync_hash'] ) ? $row['sync_hash'] : '' ); ?>"
							<?php endif; ?>
						>
							<td class="heb-pp-dash-check-col"><input type="checkbox" class="heb-pp-dash-row-check" /></td>
							<td>
								<strong><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></strong>
							</td>
							<td><code><?php echo esc_html( $row['type'] ); ?></code></td>
							<td><code><?php echo esc_attr( $row['id'] ); ?></code></td>
							<?php foreach ( $sites as $s ) : ?>
								<td class="heb-pp-dash-cell" data-site-id="<?php echo esc_attr( $s['id'] ); ?>" style="text-align:center;">
									<span class="heb-pp-dash-status heb-pp-dash-pending">·</span>
								</td>
							<?php endforeach; ?>
							<td>
								<button type="button" class="button button-small heb-pp-dash-resend" data-source-id="<?php echo esc_attr( $row['id'] ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>"><?php esc_html_e( '↻ 重发', 'heb-product-publisher' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * 主站 distributable 已发布 posts 列表（用于 dashboard 行）。
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function source_posts() {
		$out = [];
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			$q = new \WP_Query(
				[
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				]
			);
			foreach ( $q->posts as $pid ) {
				$out[] = [
					'id'       => (int) $pid,
					'type'     => $pt,
					'title'    => (string) get_the_title( $pid ),
					'modified' => (int) get_post_modified_time( 'U', true, $pid ),
					'edit_url' => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
				];
			}
		}
		return $out;
	}

	/**
	 * 主站 distributable terms 列表。
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function source_terms() {
		$out = [];
		foreach ( heb_pp_distributable_taxonomies() as $tx ) {
			$terms = get_terms(
				[
					'taxonomy'   => $tx,
					'hide_empty' => false,
				]
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$out[] = [
					'id'        => (int) $term->term_id,
					'type'      => $tx,
					'title'     => (string) $term->name,
					'modified'  => 0,
					'sync_hash' => md5( (string) $term->name . '|' . (string) $term->slug ),
					'edit_url'  => admin_url( 'term.php?taxonomy=' . rawurlencode( $tx ) . '&tag_ID=' . $term->term_id ),
				];
			}
		}
		return $out;
	}

	/**
	 * AJAX：拉取单个站点 manifest（缓存 5 分钟）。
	 */
	public function ajax_manifest() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		Heb_Product_Publisher_Runtime::raise();

		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_id'] ) ) : '';
		$force   = ! empty( $_POST['force'] );
		$site    = Heb_Product_Publisher_Admin_Settings::get_site( $site_id );
		if ( ! $site ) {
			wp_send_json_error( [ 'message' => __( '站点不存在。', 'heb-product-publisher' ) ] );
		}

		$cache_key = self::CACHE_PREFIX . md5( (string) $site['url'] );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				wp_send_json_success( $cached + [ 'cached' => true ] );
			}
		}
		$res = Heb_Product_Publisher_Remote_Client::post( $site, '/manifest', [], 60 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		$data = [
			'posts' => isset( $res['posts'] ) ? $res['posts'] : [],
			'terms' => isset( $res['terms'] ) ? $res['terms'] : [],
			'host'  => isset( $res['host'] ) ? (string) $res['host'] : '',
			'when'  => time(),
		];
		set_transient( $cache_key, $data, self::CACHE_TTL );
		wp_send_json_success( $data + [ 'cached' => false ] );
	}

	/**
	 * AJAX：重发单个 post / term 到所有目标站点。
	 */
	public function ajax_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		Heb_Product_Publisher_Runtime::raise();

		$source_id = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$kind      = isset( $_POST['kind'] ) ? sanitize_key( (string) $_POST['kind'] ) : 'posts';
		$site_ids = [];
		if ( isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ) {
			$site_ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['site_ids'] ) );
		}
		$site_ids = array_values( array_filter( $site_ids, static function ( $id ) {
			return is_string( $id ) && '' !== $id;
		} ) );
		if ( $source_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( '源 ID 无效。', 'heb-product-publisher' ) ] );
		}
		if ( empty( $site_ids ) ) {
			$site_ids = array_map(
				static function ( $s ) {
					return (string) $s['id'];
				},
				Heb_Product_Publisher_Admin_Settings::remote_sites()
			);
		}

		$results = [];
		$translator = new Heb_Product_Publisher_Translator();

		if ( 'terms' === $kind ) {
			$payload = Heb_Product_Publisher_Term_Sync::build_payload( $source_id );
			if ( empty( $payload ) ) {
				wp_send_json_error( [ 'message' => __( '无法构造 term payload。', 'heb-product-publisher' ) ] );
			}
			$ts = new Heb_Product_Publisher_Term_Sync();
			foreach ( $site_ids as $sid ) {
				$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
				if ( ! $site ) {
					$results[ $sid ] = [ 'ok' => false, 'message' => 'site not found' ];
					continue;
				}
				$results[ $sid ] = $ts->distribute_to_site( $source_id, $payload, (string) $payload['source_locale'], $site, $translator );
			}
		} else {
			$payload = Heb_Product_Publisher_Sync::build_payload( $source_id );
			if ( empty( $payload ) ) {
				wp_send_json_error( [ 'message' => __( '无法构造 post payload。', 'heb-product-publisher' ) ] );
			}
			$hub = Heb_Product_Publisher_Hub_UI::instance();
			foreach ( $site_ids as $sid ) {
				$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
				if ( ! $site ) {
					$results[ $sid ] = [ 'ok' => false, 'message' => 'site not found' ];
					continue;
				}
				$results[ $sid ] = $hub->distribute_to_site( $source_id, $payload, (string) $payload['source_locale'], $site, [], $translator );
			}
		}

		// 清缓存：重发后再次刷 manifest 会拿最新数据。
		foreach ( $site_ids as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
			if ( $site ) {
				delete_transient( self::CACHE_PREFIX . md5( (string) $site['url'] ) );
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX：批量重发（用 Action Scheduler 异步入队，避免单 ajax 卡死）。
	 */
	public function ajax_bulk_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$items = isset( $_POST['items'] ) && is_array( $_POST['items'] )
			? wp_unslash( $_POST['items'] )
			: [];

		if ( empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( '未选择项目。', 'heb-product-publisher' ) ] );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			wp_send_json_error( [ 'message' => __( 'Action Scheduler 未加载。', 'heb-product-publisher' ) ] );
		}

		$queued = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$source_id = isset( $item['source_id'] ) ? (int) $item['source_id'] : 0;
			$kind      = isset( $item['kind'] ) ? sanitize_key( (string) $item['kind'] ) : 'posts';
			$site_ids  = isset( $item['site_ids'] ) && is_array( $item['site_ids'] )
				? array_map( 'sanitize_text_field', wp_unslash( $item['site_ids'] ) )
				: [];
			if ( $source_id <= 0 ) {
				continue;
			}
			as_enqueue_async_action(
				'heb_pp_dash_resend_one',
				[
					[
						'source_id' => $source_id,
						'kind'      => $kind,
						'site_ids'  => $site_ids,
					],
				],
				Heb_Product_Publisher_Bootstrap_Queue::GROUP
			);
			$queued++;
		}
		wp_send_json_success( [ 'queued' => $queued ] );
	}

	/**
	 * AS hook: 单条异步重发（被 ajax_bulk_resend 触发）。
	 *
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	public static function as_handle_resend_one( $args ) {
		if ( is_array( $args ) && ! isset( $args['source_id'] ) ) {
			$first = reset( $args );
			if ( is_array( $first ) ) {
				$args = $first;
			}
		}
		$source_id = isset( $args['source_id'] ) ? (int) $args['source_id'] : 0;
		$kind      = isset( $args['kind'] ) ? sanitize_key( (string) $args['kind'] ) : 'posts';
		$site_ids  = isset( $args['site_ids'] ) && is_array( $args['site_ids'] )
			? array_map( 'sanitize_text_field', $args['site_ids'] )
			: [];
		if ( $source_id <= 0 ) {
			return;
		}
		$sites = Heb_Product_Publisher_Admin_Settings::remote_sites();
		if ( empty( $sites ) ) {
			return;
		}
		if ( ! empty( $site_ids ) ) {
			$filtered = [];
			foreach ( $sites as $site ) {
				if ( in_array( (string) $site['id'], $site_ids, true ) ) {
					$filtered[] = $site;
				}
			}
			$sites = $filtered;
		}
		$translator = new Heb_Product_Publisher_Translator();
		if ( 'terms' === $kind ) {
			$payload = Heb_Product_Publisher_Term_Sync::build_payload( $source_id );
			if ( empty( $payload ) ) {
				return;
			}
			$ts = new Heb_Product_Publisher_Term_Sync();
			foreach ( $sites as $site ) {
				$ts->distribute_to_site( $source_id, $payload, (string) $payload['source_locale'], $site, $translator );
			}
		} else {
			$payload = Heb_Product_Publisher_Sync::build_payload( $source_id );
			if ( empty( $payload ) ) {
				return;
			}
			$hub = Heb_Product_Publisher_Hub_UI::instance();
			foreach ( $sites as $site ) {
				$hub->distribute_to_site( $source_id, $payload, (string) $payload['source_locale'], $site, [], $translator );
			}
		}
	}

	/**
	 * AJAX：清所有 manifest 缓存。
	 */
	public function ajax_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $s ) {
			delete_transient( self::CACHE_PREFIX . md5( (string) $s['url'] ) );
		}
		wp_send_json_success();
	}
}

// 注册 AS hook 给批量重发 worker 用（即使没 instance() Dashboard 也能跑）。
add_action( 'heb_pp_dash_resend_one', [ 'Heb_Product_Publisher_Distribution_Dashboard', 'as_handle_resend_one' ], 10, 1 );
