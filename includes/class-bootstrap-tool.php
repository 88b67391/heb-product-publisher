<?php
/**
 * Site Bootstrap 后台页面 + AJAX 端点。
 *
 * 仅 Hub 模式实例化。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Bootstrap_Tool {

	const PAGE_SLUG    = 'heb-pp-bootstrap';
	const NONCE_ACTION = 'heb_pp_bootstrap';

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
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_heb_pp_bs_start', [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_heb_pp_bs_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_heb_pp_bs_cancel', [ $this, 'ajax_cancel' ] );
	}

	public function add_menu() {
		add_management_page(
			__( 'HEB Site Bootstrap', 'heb-product-publisher' ),
			__( 'HEB Site Bootstrap', 'heb-product-publisher' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * @param string $hook Current page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'heb-pp-bootstrap', HEB_PP_URL . 'assets/css/bootstrap.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-bootstrap', HEB_PP_URL . 'assets/js/bootstrap.js', [ 'jquery' ], HEB_PP_VERSION, true );
		wp_localize_script(
			'heb-pp-bootstrap',
			'HebPPBootstrap',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'confirmStart'  => __( '确定要启动 Bootstrap？这会把主站所有 distributable 内容推送到目标站点。', 'heb-product-publisher' ),
					'confirmCancel' => __( '取消该 Bootstrap job？已完成的任务不会回滚。', 'heb-product-publisher' ),
					'starting'      => __( '启动中…', 'heb-product-publisher' ),
					'selectSite'    => __( '请选择目标站点。', 'heb-product-publisher' ),
				],
			]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'heb-product-publisher' ) );
		}
		$sites  = Heb_Product_Publisher_Admin_Settings::remote_sites();
		$recent = Heb_Product_Publisher_Bootstrap_Status::recent( 20 );
		?>
		<div class="wrap heb-pp-bootstrap">
			<h1><?php esc_html_e( 'HEB Site Bootstrap', 'heb-product-publisher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '一键把主站全部 distributable 内容（分类 + 产品 + 单页 + Elementor 数据）推送到指定的语言站。任务通过 Action Scheduler 异步运行；关闭页面不影响。', 'heb-product-publisher' ); ?>
			</p>

			<?php if ( empty( $sites ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( '尚未配置远端站点。', 'heb-product-publisher' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=heb-product-publisher' ) ); ?>"><?php esc_html_e( '去配置', 'heb-product-publisher' ); ?></a>
				</p></div>
			<?php else : ?>
				<div class="card heb-pp-bs-start">
					<h2><?php esc_html_e( '启动新 Bootstrap', 'heb-product-publisher' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="heb-pp-bs-site"><?php esc_html_e( '目标站点', 'heb-product-publisher' ); ?></label></th>
							<td>
								<select id="heb-pp-bs-site">
									<option value=""><?php esc_html_e( '— 选择站点 —', 'heb-product-publisher' ); ?></option>
									<?php foreach ( $sites as $s ) : ?>
										<option value="<?php echo esc_attr( $s['id'] ); ?>">
											<?php
											echo esc_html( $s['label'] );
											if ( ! empty( $s['locale_override'] ) ) {
												echo ' [' . esc_html( $s['locale_override'] ) . ']';
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '范围', 'heb-product-publisher' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" id="heb-pp-bs-scope-terms" checked /> <?php esc_html_e( '分类（terms）', 'heb-product-publisher' ); ?></label><br />
									<label><input type="checkbox" id="heb-pp-bs-scope-posts" checked /> <?php esc_html_e( '内容（products / solutions / pages）', 'heb-product-publisher' ); ?></label><br />
									<label><input type="checkbox" id="heb-pp-bs-scope-menus" /> <?php esc_html_e( '导航菜单（PR 4 上线后启用）', 'heb-product-publisher' ); ?></label><br />
									<label><input type="checkbox" id="heb-pp-bs-scope-settings" /> <?php esc_html_e( 'WordPress 全局设置（PR 4 上线后启用）', 'heb-product-publisher' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( '默认仅 terms + posts；菜单和全局设置需要 PR 4 引入对应 sync 类后才会真正执行。', 'heb-product-publisher' ); ?></p>
							</td>
						</tr>
					</table>
					<p>
						<button type="button" class="button button-primary button-large" id="heb-pp-bs-start"><?php esc_html_e( '启动 Bootstrap', 'heb-product-publisher' ); ?></button>
						<span id="heb-pp-bs-start-msg" style="margin-left:8px;"></span>
					</p>
				</div>
			<?php endif; ?>

			<h2 style="margin-top:30px;"><?php esc_html_e( '最近 Bootstrap 任务', 'heb-product-publisher' ); ?></h2>
			<div id="heb-pp-bs-jobs">
				<?php if ( empty( $recent ) ) : ?>
					<p><em><?php esc_html_e( '暂无任务。', 'heb-product-publisher' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Job ID', 'heb-product-publisher' ); ?></th>
								<th><?php esc_html_e( '站点', 'heb-product-publisher' ); ?></th>
								<th><?php esc_html_e( '阶段', 'heb-product-publisher' ); ?></th>
								<th><?php esc_html_e( '状态', 'heb-product-publisher' ); ?></th>
								<th><?php esc_html_e( '进度', 'heb-product-publisher' ); ?></th>
								<th><?php esc_html_e( '更新时间', 'heb-product-publisher' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $rec ) : ?>
								<tr data-job-id="<?php echo esc_attr( $rec['id'] ); ?>" class="heb-pp-bs-job-row">
									<td><code><?php echo esc_html( substr( $rec['id'], 0, 8 ) ); ?></code></td>
									<td><?php echo esc_html( $rec['site_id'] ); ?></td>
									<td class="heb-pp-bs-stage"><?php echo esc_html( $rec['current_stage'] ); ?></td>
									<td class="heb-pp-bs-status"><?php echo esc_html( $rec['status'] ); ?></td>
									<td class="heb-pp-bs-progress"><?php echo esc_html( $this->format_progress( $rec ) ); ?></td>
									<td><?php echo esc_html( gmdate( 'm/d H:i', isset( $rec['updated_at'] ) ? (int) $rec['updated_at'] : 0 ) ); ?></td>
									<td>
										<button type="button" class="button button-small heb-pp-bs-details" data-job-id="<?php echo esc_attr( $rec['id'] ); ?>"><?php esc_html_e( '详情', 'heb-product-publisher' ); ?></button>
										<?php if ( in_array( $rec['status'], [ Heb_Product_Publisher_Bootstrap_Status::STATUS_RUNNING, Heb_Product_Publisher_Bootstrap_Status::STATUS_QUEUED ], true ) ) : ?>
											<button type="button" class="button button-small heb-pp-bs-cancel" data-job-id="<?php echo esc_attr( $rec['id'] ); ?>"><?php esc_html_e( '取消', 'heb-product-publisher' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div id="heb-pp-bs-details" style="display:none;margin-top:20px;">
				<h3><?php esc_html_e( 'Job 详情', 'heb-product-publisher' ); ?></h3>
				<div id="heb-pp-bs-details-body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $rec Job record.
	 * @return string
	 */
	private function format_progress( array $rec ) {
		$lines = [];
		foreach ( (array) ( $rec['progress'] ?? [] ) as $stage => $p ) {
			$queued = (int) ( $p['queued'] ?? 0 );
			if ( $queued <= 0 ) {
				continue;
			}
			$done = (int) ( $p['done'] ?? 0 );
			$fail = (int) ( $p['failed'] ?? 0 );
			$skip = (int) ( $p['skipped'] ?? 0 );
			$lines[] = sprintf( '%s: %d/%d (✗%d ⊘%d)', $stage, $done, $queued, $fail, $skip );
		}
		return implode( ' · ', $lines );
	}

	public function ajax_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_id'] ) ) : '';
		if ( '' === $site_id ) {
			wp_send_json_error( [ 'message' => __( '未选择站点。', 'heb-product-publisher' ) ] );
		}
		$opts = [
			'scope_terms'    => ! empty( $_POST['scope_terms'] ),
			'scope_posts'    => ! empty( $_POST['scope_posts'] ),
			'scope_menus'    => ! empty( $_POST['scope_menus'] ),
			'scope_settings' => ! empty( $_POST['scope_settings'] ),
		];
		$res = Heb_Product_Publisher_Bootstrap_Queue::start( $site_id, $opts );
		if ( ! empty( $res['error'] ) && empty( $res['job_id'] ) ) {
			wp_send_json_error( [ 'message' => (string) $res['error'] ] );
		}
		wp_send_json_success(
			[
				'job_id' => (string) $res['job_id'],
				'notice' => isset( $res['error'] ) ? (string) $res['error'] : '',
			]
		);
	}

	public function ajax_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['job_id'] ) ) : '';
		if ( '' !== $job_id ) {
			$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			if ( ! $rec ) {
				wp_send_json_error( [ 'message' => __( 'Job 不存在。', 'heb-product-publisher' ) ] );
			}
			wp_send_json_success( [ 'job' => $rec, 'summary' => $this->format_progress( $rec ) ] );
		}
		// 不带 job_id：返回最近 20 个的精简快照（用于轮询表格行）。
		$rows = [];
		foreach ( Heb_Product_Publisher_Bootstrap_Status::recent( 20 ) as $rec ) {
			$rows[] = [
				'id'            => $rec['id'],
				'site_id'       => $rec['site_id'],
				'status'        => $rec['status'],
				'current_stage' => $rec['current_stage'],
				'updated_at'    => $rec['updated_at'] ?? 0,
				'summary'       => $this->format_progress( $rec ),
				'finished'      => in_array(
					$rec['status'],
					[
						Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
						Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED,
						Heb_Product_Publisher_Bootstrap_Status::STATUS_CANCELLED,
					],
					true
				),
			];
		}
		wp_send_json_success( [ 'jobs' => $rows ] );
	}

	public function ajax_cancel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( [ 'message' => __( 'job_id 必填。', 'heb-product-publisher' ) ] );
		}
		if ( ! Heb_Product_Publisher_Bootstrap_Status::cancel( $job_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Job 已结束或不存在。', 'heb-product-publisher' ) ] );
		}
		wp_send_json_success( [ 'ok' => true ] );
	}
}
