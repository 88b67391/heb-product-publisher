<?php
/**
 * 批量分发：在 post list 上加 bulk action → 跳转进度页 → AJAX 分批执行。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bulk {

	const NONCE = 'heb_pp_bulk';

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
		add_action( 'admin_init', [ $this, 'register_hooks' ] );
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'wp_ajax_heb_pp_bulk_one', [ $this, 'ajax_one' ] );
	}

	public function register_hooks() {
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			add_filter( "bulk_actions-edit-{$pt}", [ $this, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-{$pt}", [ $this, 'handle_bulk_action' ], 10, 3 );
		}
	}

	/**
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function add_bulk_action( $actions ) {
		$actions['heb_pp_distribute'] = __( 'HEB 分发到已配置站点', 'heb-product-publisher' );
		return $actions;
	}

	/**
	 * @param string           $redirect Redirect URL.
	 * @param string           $action   Bulk action name.
	 * @param array<int,int>   $post_ids Post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'heb_pp_distribute' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $redirect;
		}
		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return $redirect;
		}
		return add_query_arg(
			[
				'page'     => 'heb-pp-bulk',
				'post_ids' => implode( ',', $post_ids ),
				'_wpnonce' => wp_create_nonce( self::NONCE ),
			],
			admin_url( 'admin.php' )
		);
	}

	public function register_page() {
		// 使用 null 作为 parent 使该页可由 URL 访问但不出现在菜单里。
		add_submenu_page(
			null, // phpcs:ignore
			__( 'HEB 批量分发', 'heb-product-publisher' ),
			__( 'HEB 批量分发', 'heb-product-publisher' ),
			'edit_posts',
			'heb-pp-bulk',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security
			wp_die( esc_html__( 'Nonce 校验失败。', 'heb-product-publisher' ) );
		}
		$ids_raw  = isset( $_GET['post_ids'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['post_ids'] ) ) : ''; // phpcs:ignore WordPress.Security
		$post_ids = array_values( array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) ) );
		if ( empty( $post_ids ) ) {
			wp_die( esc_html__( '未选择任何文章。', 'heb-product-publisher' ) );
		}

		$sites = Heb_Product_Publisher_Admin_Settings::remote_sites();

		wp_enqueue_style( 'heb-pp-bulk', HEB_PP_URL . 'assets/css/bulk.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-bulk', HEB_PP_URL . 'assets/js/bulk.js', [ 'jquery' ], HEB_PP_VERSION, true );
		wp_localize_script(
			'heb-pp-bulk',
			'HebPPBulk',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Heb_Product_Publisher_Hub_UI::NONCE_ACTION ),
				'postIds' => $post_ids,
				'i18n'    => [
					'startBtn'   => __( '开始分发', 'heb-product-publisher' ),
					'running'    => __( '正在分发…', 'heb-product-publisher' ),
					'done'       => __( '完成', 'heb-product-publisher' ),
					'needSites'  => __( '请选择至少一个目标站点。', 'heb-product-publisher' ),
					'pending'    => __( '等待中', 'heb-product-publisher' ),
					'processing' => __( '处理中', 'heb-product-publisher' ),
					'success'    => __( '成功', 'heb-product-publisher' ),
					'failed'     => __( '失败', 'heb-product-publisher' ),
				],
			]
		);
		?>
		<div class="wrap heb-pp-bulk-wrap">
			<h1><?php esc_html_e( 'HEB 批量分发', 'heb-product-publisher' ); ?></h1>

			<?php if ( empty( $sites ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: settings URL */
							esc_html__( '尚未配置任何远端站点，请先到 %s 添加。', 'heb-product-publisher' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=heb-product-publisher' ) ) . '">' . esc_html__( '设置页', 'heb-product-publisher' ) . '</a>'
						);
						?>
					</p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<div class="heb-pp-bulk-topbar">
				<div>
					<strong><?php esc_html_e( '目标站点', 'heb-product-publisher' ); ?>：</strong>
					<?php foreach ( $sites as $s ) : ?>
						<label class="heb-pp-bulk-site">
							<input type="checkbox" class="heb-pp-bulk-site-input" value="<?php echo esc_attr( $s['id'] ); ?>" checked />
							<span><?php echo esc_html( $s['label'] ); ?></span>
							<?php if ( ! empty( $s['locale_override'] ) ) : ?>
								<code><?php echo esc_html( $s['locale_override'] ); ?></code>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="heb-pp-bulk-actions">
					<button type="button" class="button button-primary button-hero" id="heb-pp-bulk-start">
						<?php esc_html_e( '开始分发', 'heb-product-publisher' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=heb-pp-log' ) ); ?>" target="_blank"><?php esc_html_e( '打开分发日志', 'heb-product-publisher' ); ?></a>
				</div>
			</div>

			<div class="heb-pp-bulk-progress">
				<div class="heb-pp-bulk-progress__bar"><span id="heb-pp-bulk-bar"></span></div>
				<p id="heb-pp-bulk-summary">
					<?php
					printf(
						/* translators: %d: total posts */
						esc_html__( '共 %d 篇待处理', 'heb-product-publisher' ),
						count( $post_ids )
					);
					?>
				</p>
			</div>

			<table class="wp-list-table widefat striped heb-pp-bulk-table">
				<thead>
					<tr>
						<th style="width:70px;"><?php esc_html_e( '状态', 'heb-product-publisher' ); ?></th>
						<th><?php esc_html_e( '文章', 'heb-product-publisher' ); ?></th>
						<th><?php esc_html_e( '站点结果', 'heb-product-publisher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $post_ids as $pid ) : ?>
						<tr data-post-id="<?php echo (int) $pid; ?>">
							<td class="heb-pp-bulk-status">
								<span class="heb-pp-bulk-badge heb-pp-bulk-badge--pending"><?php esc_html_e( '等待中', 'heb-product-publisher' ); ?></span>
							</td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank">
									<?php echo esc_html( get_the_title( $pid ) ? get_the_title( $pid ) : '#' . $pid ); ?>
								</a>
								<span class="heb-pp-bulk-id">#<?php echo (int) $pid; ?></span>
							</td>
							<td class="heb-pp-bulk-sites"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX：分发单个 post 到所选站点。
	 */
	public function ajax_one() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( Heb_Product_Publisher_Hub_UI::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( '无权编辑该文章。', 'heb-product-publisher' ) ], 403 );
		}
		$site_ids = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['site_ids'] ) )
			: [];
		if ( empty( $site_ids ) ) {
			wp_send_json_error( [ 'message' => __( '未选择站点。', 'heb-product-publisher' ) ] );
		}

		$basepayload = Heb_Product_Publisher_Sync::build_payload( $post_id );
		if ( empty( $basepayload ) ) {
			wp_send_json_error( [ 'message' => __( '无法构造 payload。', 'heb-product-publisher' ) ] );
		}

		$translator    = new Heb_Product_Publisher_Translator();
		$source_locale = isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : 'en_US';
		$hub_ui        = Heb_Product_Publisher_Hub_UI::instance();

		$results = [];
		foreach ( $site_ids as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
			if ( ! $site ) {
				$results[ $sid ] = [ 'ok' => false, 'message' => __( '未找到站点。', 'heb-product-publisher' ) ];
				continue;
			}
			$results[ $sid ] = $hub_ui->distribute_to_site( $post_id, $basepayload, $source_locale, $site, [], $translator );
			// Bulk 模式下分类不做 per-site override：目标站已有则匹配，不存在则创建。
		}
		wp_send_json_success( [ 'post_id' => $post_id, 'results' => $results ] );
	}
}
