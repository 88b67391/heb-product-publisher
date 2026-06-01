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
		add_action( 'admin_menu', [ $this, 'add_menu' ], 12 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_heb_pp_bs_start', [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_heb_pp_bs_resend', [ $this, 'ajax_resend' ] );
		add_action( 'wp_ajax_heb_pp_bs_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_heb_pp_bs_cancel', [ $this, 'ajax_cancel' ] );
		add_action( 'wp_ajax_heb_pp_bs_retry', [ $this, 'ajax_retry' ] );
		add_action( 'wp_ajax_heb_pp_bs_nudge', [ $this, 'ajax_nudge' ] );
	}

	public function add_menu() {
		add_submenu_page(
			Heb_Product_Publisher_Admin_Menu::PARENT_SLUG,
			__( 'HEB Site Bootstrap', 'heb-product-publisher' ),
			__( '站点 Bootstrap', 'heb-product-publisher' ),
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
		wp_enqueue_style( 'heb-pp-bootstrap', HEB_PP_URL . 'assets/css/bootstrap.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-bootstrap', HEB_PP_URL . 'assets/js/bootstrap.js', [ 'jquery' ], HEB_PP_VERSION, true );
		wp_localize_script(
			'heb-pp-bootstrap',
			'HebPPBootstrap',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'confirmStart'  => __( '确定要启动 Bootstrap？这会把主站 distributable 内容推送到目标站点。', 'heb-product-publisher' ),
					'confirmDryRun' => __( 'Dry run 模式：只统计数量并验证连接，不会实际推送。继续？', 'heb-product-publisher' ),
					'confirmRetry'  => __( '仅重试该 job 中失败的项？', 'heb-product-publisher' ),
					'confirmCancel' => __( '取消该 Bootstrap job？已完成的任务不会回滚。', 'heb-product-publisher' ),
					'confirmResendSettings' => __( '重发全部 WordPress 设置到该站点？', 'heb-product-publisher' ),
					'confirmResendIdentity' => __( '仅重发站点身份（标题、副标题、Logo、Favicon）到该站点？', 'heb-product-publisher' ),
					'confirmResendMenus'    => __( '仅重发导航菜单到该站点？', 'heb-product-publisher' ),
					'confirmResendTemplates' => __( '仅重发 Elementor 模板库（header/footer/archive 等）到该站点？', 'heb-product-publisher' ),
					'selectPostType'      => __( '请至少勾选一种内容类型。', 'heb-product-publisher' ),
					'starting'      => __( '启动中…', 'heb-product-publisher' ),
					'startTimeout'  => __( '请求超时', 'heb-product-publisher' ),
					'refreshHint'   => __( '请刷新页面查看任务是否已创建', 'heb-product-publisher' ),
					'selectSite'    => __( '请选择目标站点。', 'heb-product-publisher' ),
					'processing'    => __( '正在处理', 'heb-product-publisher' ),
					'staleHint'     => __( '长时间无更新：Opus 单条可跑 10–20 分钟；若超过 20 分钟仍无进度可点「推进队列」。', 'heb-product-publisher' ),
					'queueStalledHint' => __( '队列停滞：本阶段还有未完成项，但 Action Scheduler 里没有待处理任务。可能是 WP-Cron 未触发或任务丢失；点「推进队列」会自动补排遗漏项。', 'heb-product-publisher' ),
					'stageRemaining'   => __( '本阶段剩余', 'heb-product-publisher' ),
					'queuePending'     => __( 'AS 待处理', 'heb-product-publisher' ),
					'queueRunning'     => __( 'AS 运行中', 'heb-product-publisher' ),
					'queueFailed'      => __( 'AS 失败', 'heb-product-publisher' ),
					'queueItemsTitle'  => __( 'Action Scheduler 队列', 'heb-product-publisher' ),
					'nudge'         => __( '推进队列', 'heb-product-publisher' ),
					'nudging'       => __( '推进中…', 'heb-product-publisher' ),
					'nudgeDone'     => __( '已触发 Action Scheduler，请稍候刷新进度。', 'heb-product-publisher' ),
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
				<?php esc_html_e( '一键把主站全部 distributable 内容（分类 + 产品 + 单页 + Elementor 模板库）推送到指定的语言站。任务通过 Action Scheduler 异步运行；关闭页面不影响。', 'heb-product-publisher' ); ?>
			</p>

			<?php if ( empty( $sites ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( '尚未配置远端站点。', 'heb-product-publisher' ); ?>
					<a href="<?php echo esc_url( Heb_Product_Publisher_Admin_Menu::url() ); ?>"><?php esc_html_e( '去配置', 'heb-product-publisher' ); ?></a>
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
									<strong><?php esc_html_e( '内容', 'heb-product-publisher' ); ?></strong>
									<span class="description"><?php esc_html_e( '可单独勾选，减少单次任务量', 'heb-product-publisher' ); ?></span><br />
									<span style="display:inline-block;margin-left:1.5em;">
										<?php
										$pt_labels = [
											'elementor_library' => __( 'Elementor 模板（header / footer / archive / loop item）', 'heb-product-publisher' ),
											'page'              => __( '单页（pages）', 'heb-product-publisher' ),
											'products'          => __( '产品（products）', 'heb-product-publisher' ),
											'solutions'         => __( '解决方案（solutions）', 'heb-product-publisher' ),
										];
										foreach ( heb_pp_distributable_post_types() as $pt ) :
											$label = isset( $pt_labels[ $pt ] ) ? $pt_labels[ $pt ] : $pt;
											?>
											<label><input type="checkbox" class="heb-pp-bs-scope-pt" data-pt="<?php echo esc_attr( $pt ); ?>" checked /> <?php echo esc_html( $label ); ?></label><br />
										<?php endforeach; ?>
									</span>
									<strong><?php esc_html_e( 'WordPress 设置', 'heb-product-publisher' ); ?></strong>
									<span class="description"><?php esc_html_e( '可单独勾选', 'heb-product-publisher' ); ?></span><br />
									<span style="display:inline-block;margin-left:1.5em;">
										<?php foreach ( Heb_Product_Publisher_Settings_Sync::settings_groups() as $group_key => $group_label ) : ?>
											<label><input type="checkbox" class="heb-pp-bs-scope-settings-group" data-group="<?php echo esc_attr( $group_key ); ?>" checked /> <?php echo esc_html( $group_label ); ?></label><br />
										<?php endforeach; ?>
									</span>
									<label><input type="checkbox" id="heb-pp-bs-scope-menus" checked /> <?php esc_html_e( '导航菜单（含菜单项 object 反查）', 'heb-product-publisher' ); ?></label><br />
									<label><input type="checkbox" id="heb-pp-bs-scope-menu-locations" /> <?php esc_html_e( '同时绑定主题菜单位置（危险：会覆盖子站 nav_menu_locations）', 'heb-product-publisher' ); ?></label><br />
									<label><input type="checkbox" id="heb-pp-bs-dry-run" /> <?php esc_html_e( 'Dry run（仅探测连接 + 统计数量，不实际推送）', 'heb-product-publisher' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( '推荐勾选 terms + 内容；settings 可按需拆分；menus 请确认子站为空站后再启用。模板建议先于单页推送。', 'heb-product-publisher' ); ?></p>
							</td>
						</tr>
					</table>
					<p>
						<button type="button" class="button button-primary button-large" id="heb-pp-bs-start"><?php esc_html_e( '启动 Bootstrap', 'heb-product-publisher' ); ?></button>
						<span id="heb-pp-bs-start-msg" style="margin-left:8px;"></span>
					</p>
					<p class="description" style="margin-top:12px;">
						<?php esc_html_e( '快捷重发（不删已有内容，仅推送选中 scope）：', 'heb-product-publisher' ); ?>
						<button type="button" class="button" id="heb-pp-bs-resend-identity"><?php esc_html_e( '仅重发站点身份', 'heb-product-publisher' ); ?></button>
						<button type="button" class="button" id="heb-pp-bs-resend-settings"><?php esc_html_e( '仅重发全部 settings', 'heb-product-publisher' ); ?></button>
						<button type="button" class="button" id="heb-pp-bs-resend-templates"><?php esc_html_e( '仅重发 Elementor 模板', 'heb-product-publisher' ); ?></button>
						<button type="button" class="button" id="heb-pp-bs-resend-menus"><?php esc_html_e( '仅重发 menus', 'heb-product-publisher' ); ?></button>
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
								<tr data-job-id="<?php echo esc_attr( $rec['id'] ); ?>" data-job-status="<?php echo esc_attr( (string) $rec['status'] ); ?>" class="heb-pp-bs-job-row">
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
										<?php
										$err_count = isset( $rec['errors'] ) && is_array( $rec['errors'] ) ? count( $rec['errors'] ) : 0;
										$can_retry = $err_count > 0 && in_array(
											$rec['status'],
											[
												Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE,
												Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS,
												Heb_Product_Publisher_Bootstrap_Status::STATUS_FAILED,
											],
											true
										);
										if ( $can_retry ) :
											?>
											<button type="button" class="button button-small heb-pp-bs-retry" data-job-id="<?php echo esc_attr( $rec['id'] ); ?>"><?php esc_html_e( '↻ 重试失败项', 'heb-product-publisher' ); ?></button>
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
		$rec      = Heb_Product_Publisher_Bootstrap_Status::enrich( $rec );
		$lines    = [];
		$stage    = isset( $rec['current_stage'] ) ? (string) $rec['current_stage'] : '';
		$activity = isset( $rec['activity'] ) && is_array( $rec['activity'] ) ? $rec['activity'] : [];

		foreach ( (array) ( $rec['progress'] ?? [] ) as $stg => $p ) {
			$queued = (int) ( $p['queued'] ?? 0 );
			if ( $queued <= 0 ) {
				continue;
			}
			$done = (int) ( $p['done'] ?? 0 );
			$fail = (int) ( $p['failed'] ?? 0 );
			$skip = (int) ( $p['skipped'] ?? 0 );
			$pct  = (int) round( 100 * ( $done + $fail + $skip ) / max( 1, $queued ) );
			$line = sprintf( '%s: %d/%d (%d%%', $stg, $done, $queued, $pct );
			if ( $fail > 0 || $skip > 0 ) {
				$line .= sprintf( ', ✗%d ⊘%d', $fail, $skip );
			}
			$line .= ')';
			if ( $stg === $stage && ! empty( $rec['current_item'] ) && is_array( $rec['current_item'] ) ) {
				$cur = $rec['current_item'];
				$elapsed = isset( $activity['current_elapsed'] ) ? (int) $activity['current_elapsed'] : 0;
				$line .= sprintf(
					' · %s #%d',
					isset( $cur['type'] ) ? (string) $cur['type'] : '?',
					isset( $cur['source_id'] ) ? (int) $cur['source_id'] : 0
				);
				if ( $elapsed > 0 ) {
					$line .= sprintf( ' %dm%02ds', (int) floor( $elapsed / 60 ), $elapsed % 60 );
				}
			}
			$lines[] = $line;
		}
		return implode( ' · ', $lines );
	}

	/**
	 * @return array<int,string>
	 */
	private function parse_scope_post_types_from_request() {
		$allowed = heb_pp_distributable_post_types();
		$raw     = isset( $_POST['scope_post_types'] ) ? wp_unslash( $_POST['scope_post_types'] ) : [];
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $pt ) {
			$pt = sanitize_key( (string) $pt );
			if ( '' !== $pt && in_array( $pt, $allowed, true ) ) {
				$out[] = $pt;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function parse_scope_settings_groups_from_request() {
		$allowed = Heb_Product_Publisher_Settings_Sync::default_settings_groups();
		$raw     = isset( $_POST['scope_settings_groups'] ) ? wp_unslash( $_POST['scope_settings_groups'] ) : [];
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $group ) {
			$group = sanitize_key( (string) $group );
			if ( '' !== $group && in_array( $group, $allowed, true ) ) {
				$out[] = $group;
			}
		}
		return array_values( array_unique( $out ) );
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
			'scope_terms'          => ! empty( $_POST['scope_terms'] ),
			'scope_menus'          => ! empty( $_POST['scope_menus'] ),
			'scope_menu_locations' => ! empty( $_POST['scope_menu_locations'] ),
			'dry_run'              => ! empty( $_POST['dry_run'] ),
		];
		$scope_pts = $this->parse_scope_post_types_from_request();
		if ( empty( $scope_pts ) && ! empty( $_POST['scope_posts'] ) ) {
			$scope_pts = heb_pp_distributable_post_types();
		}
		$scope_settings_groups = $this->parse_scope_settings_groups_from_request();
		if ( empty( $scope_settings_groups ) && ! empty( $_POST['scope_settings'] ) ) {
			$scope_settings_groups = Heb_Product_Publisher_Settings_Sync::default_settings_groups();
		}
		if (
			empty( $scope_pts )
			&& ! $opts['dry_run']
			&& empty( $opts['scope_terms'] )
			&& empty( $opts['scope_menus'] )
			&& empty( $scope_settings_groups )
		) {
			wp_send_json_error( [ 'message' => __( '请至少勾选一种 Bootstrap 范围。', 'heb-product-publisher' ) ] );
		}
		$opts['scope_settings_groups'] = $scope_settings_groups;
		$opts['scope_settings']        = ! empty( $scope_settings_groups );
		$opts['scope_post_types']      = $scope_pts;
		$opts['scope_posts']           = ! empty( $scope_pts );
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

	public function ajax_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_id'] ) ) : '';
		$scope   = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : '';
		if ( '' === $site_id ) {
			wp_send_json_error( [ 'message' => __( '未选择站点。', 'heb-product-publisher' ) ] );
		}
		$opts = [
			'scope_terms'          => false,
			'scope_posts'          => false,
			'scope_post_types'     => [],
			'scope_menus'          => 'menus' === $scope,
			'scope_settings'       => false,
			'scope_settings_groups' => [],
			'scope_menu_locations' => 'menus' === $scope && ! empty( $_POST['scope_menu_locations'] ),
			'dry_run'              => false,
		];
		if ( 'elementor_library' === $scope ) {
			$opts['scope_post_types'] = [ 'elementor_library' ];
			$opts['scope_posts']      = true;
		}
		if ( 'settings' === $scope ) {
			$opts['scope_settings_groups'] = Heb_Product_Publisher_Settings_Sync::default_settings_groups();
			$opts['scope_settings']        = true;
		}
		if ( 'settings_identity' === $scope ) {
			$opts['scope_settings_groups'] = [ 'identity' ];
			$opts['scope_settings']        = true;
		}
		if ( ! $opts['scope_menus'] && ! $opts['scope_settings'] && ! $opts['scope_posts'] ) {
			wp_send_json_error( [ 'message' => __( '无效的 scope。', 'heb-product-publisher' ) ] );
		}
		$res = Heb_Product_Publisher_Bootstrap_Queue::start( $site_id, $opts );
		if ( ! empty( $res['error'] ) && empty( $res['job_id'] ) ) {
			wp_send_json_error( [ 'message' => (string) $res['error'] ] );
		}
		wp_send_json_success( [ 'job_id' => (string) $res['job_id'] ] );
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
			$rec = Heb_Product_Publisher_Bootstrap_Status::enrich( $rec );
			wp_send_json_success( [ 'job' => $rec, 'summary' => $this->format_progress( $rec ) ] );
		}
		// 不带 job_id：返回最近 20 个的精简快照（用于轮询表格行）。
		$rows = [];
		foreach ( Heb_Product_Publisher_Bootstrap_Status::recent( 20 ) as $rec ) {
			$rec = Heb_Product_Publisher_Bootstrap_Status::enrich( $rec );
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
						Heb_Product_Publisher_Bootstrap_Status::STATUS_DONE_WITH_ERRORS,
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

	public function ajax_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( [ 'message' => __( 'job_id 必填。', 'heb-product-publisher' ) ] );
		}
		$res = Heb_Product_Publisher_Bootstrap_Queue::retry_failed( $job_id );
		if ( ! empty( $res['error'] ) && empty( $res['job_id'] ) ) {
			wp_send_json_error( [ 'message' => (string) $res['error'] ] );
		}
		wp_send_json_success( [ 'job_id' => (string) $res['job_id'] ] );
	}

	public function ajax_nudge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		if ( '' !== $job_id ) {
			$rec = Heb_Product_Publisher_Bootstrap_Status::get( $job_id );
			if ( ! $rec ) {
				wp_send_json_error( [ 'message' => __( 'Job 不存在。', 'heb-product-publisher' ) ] );
			}
			Heb_Product_Publisher_Bootstrap_Status::add_log( $job_id, 'info', __( '用户手动推进 Action Scheduler 队列…', 'heb-product-publisher' ) );
		}
		$ran = Heb_Product_Publisher_Bootstrap_Queue::nudge_queue_runner( $job_id );
		wp_send_json_success(
			[
				'processed' => $ran,
				'message'   => __( '已触发队列运行，请稍候查看进度。', 'heb-product-publisher' ),
			]
		);
	}
}
