<?php
/**
 * 分发日志管理页：工具 → HEB 分发日志。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Log_Admin {

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
	}

	public function add_menu() {
		add_management_page(
			__( 'HEB 分发日志', 'heb-product-publisher' ),
			__( 'HEB 分发日志', 'heb-product-publisher' ),
			'manage_options',
			'heb-pp-log',
			[ $this, 'render' ]
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$site    = isset( $_GET['site_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['site_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = Heb_Product_Publisher_Log::query(
			[
				'paged'    => $paged,
				'per_page' => 30,
				'site_id'  => $site,
				'status'   => $status,
				'search'   => $search,
				'post_id'  => $post_id,
			]
		);
		$items = $result['items'];
		$total = $result['total'];
		$pages = (int) ceil( $total / 30 );

		$summary = Heb_Product_Publisher_Log::summary();
		$sites   = Heb_Product_Publisher_Admin_Settings::remote_sites();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'HEB 分发日志', 'heb-product-publisher' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: 1: total, 2: success, 3: error, 4: translated strings */
					esc_html__( '总计 %1$d · 成功 %2$d · 失败 %3$d · 累计翻译 %4$d 条字符串', 'heb-product-publisher' ),
					(int) $summary['total'],
					(int) $summary['success'],
					(int) $summary['error'],
					(int) $summary['strings']
				);
				?>
			</p>

			<form method="get" style="margin:10px 0;">
				<input type="hidden" name="page" value="heb-pp-log" />
				<?php if ( $post_id > 0 ) : ?>
					<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
				<?php endif; ?>
				<select name="site_id">
					<option value=""><?php esc_html_e( '全部站点', 'heb-product-publisher' ); ?></option>
					<?php foreach ( $sites as $s ) : ?>
						<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $site, $s['id'] ); ?>>
							<?php echo esc_html( $s['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( '全部状态', 'heb-product-publisher' ); ?></option>
					<option value="success" <?php selected( $status, 'success' ); ?>>✅ success</option>
					<option value="error"   <?php selected( $status, 'error' ); ?>>❌ error</option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '搜索标题 / 站点 / 错误消息', 'heb-product-publisher' ); ?>" />
				<?php submit_button( __( '筛选', 'heb-product-publisher' ), 'secondary', '', false ); ?>
				<?php if ( $site || $status || $search || $post_id ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'tools.php?page=heb-pp-log' ) ); ?>"><?php esc_html_e( '清空条件', 'heb-product-publisher' ); ?></a>
				<?php endif; ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:140px"><?php esc_html_e( '时间', 'heb-product-publisher' ); ?></th>
						<th><?php esc_html_e( '文章', 'heb-product-publisher' ); ?></th>
						<th style="width:140px"><?php esc_html_e( '目标站点', 'heb-product-publisher' ); ?></th>
						<th style="width:80px"><?php esc_html_e( '语言', 'heb-product-publisher' ); ?></th>
						<th style="width:80px"><?php esc_html_e( '状态', 'heb-product-publisher' ); ?></th>
						<th style="width:80px"><?php esc_html_e( '翻译', 'heb-product-publisher' ); ?></th>
						<th style="width:80px"><?php esc_html_e( '耗时', 'heb-product-publisher' ); ?></th>
						<th><?php esc_html_e( '消息 / 链接', 'heb-product-publisher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( '暂无记录。', 'heb-product-publisher' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $items as $row ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->created_at ) ); ?></td>
								<td>
									<?php $edit = get_edit_post_link( (int) $row->post_id ); ?>
									<?php if ( $edit ) : ?>
										<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : '#' . (int) $row->post_id ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row->post_title ? $row->post_title : '#' . (int) $row->post_id ); ?>
									<?php endif; ?>
									<div class="row-actions"><span>#<?php echo (int) $row->post_id; ?> · <?php echo esc_html( $row->post_type ); ?></span></div>
								</td>
								<td><?php echo esc_html( $row->site_label ); ?></td>
								<td><code><?php echo esc_html( $row->target_locale ); ?></code></td>
								<td>
									<?php if ( 'success' === $row->status ) : ?>
										<span style="color:#00a32a;font-weight:600">✅</span>
									<?php else : ?>
										<span style="color:#b32d2e;font-weight:600">❌</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo (int) $row->translated_strings; ?>/<?php echo (int) $row->translated_total; ?>
								</td>
								<td><?php echo (int) $row->duration_ms; ?>ms</td>
								<td>
									<?php if ( 'success' === $row->status && $row->remote_edit_url ) : ?>
										<a href="<?php echo esc_url( $row->remote_edit_url ); ?>" target="_blank" rel="noopener">
											<?php esc_html_e( '在目标站点打开', 'heb-product-publisher' ); ?> → #<?php echo (int) $row->remote_post_id; ?>
										</a>
									<?php endif; ?>
									<?php if ( $row->message ) : ?>
										<div style="color:#646970;word-break:break-all;max-height:4em;overflow:auto;"><?php echo esc_html( $row->message ); ?></div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								[
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'total'     => $pages,
									'current'   => $paged,
									'prev_text' => '‹',
									'next_text' => '›',
								]
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
