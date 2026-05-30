<?php
/**
 * Hub UI：分类（term）编辑页 metabox + 列表 bulk action + AJAX 分发端点。
 *
 * 仅 Hub 模式实例化。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Term_Hub_UI {

	const NONCE_ACTION = 'heb_pp_term_hub';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		foreach ( heb_pp_distributable_taxonomies() as $tx ) {
			add_action( "{$tx}_edit_form_fields", [ $this, 'render_term_metabox' ], 50, 2 );
			add_filter( "bulk_actions-edit-{$tx}", [ $this, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-{$tx}", [ $this, 'handle_bulk_action' ], 10, 3 );
			add_action( "{$tx}_pre_add_form", [ $this, 'render_bulk_result' ] );
		}
		add_action( 'wp_ajax_heb_pp_distribute_term', [ $this, 'ajax_distribute_term' ] );
	}

	/**
	 * @param string $hook Current page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->taxonomy, heb_pp_distributable_taxonomies(), true ) ) {
			return;
		}
		wp_enqueue_style( 'heb-pp-hub', HEB_PP_URL . 'assets/css/hub.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-term-hub', HEB_PP_URL . 'assets/js/term-hub.js', [ 'jquery' ], HEB_PP_VERSION, true );
		wp_localize_script(
			'heb-pp-term-hub',
			'HebPPTermHub',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'distributing' => __( '分发中…', 'heb-product-publisher' ),
					'done'         => __( '完成', 'heb-product-publisher' ),
					'error'        => __( '失败', 'heb-product-publisher' ),
					'selectSites'  => __( '请至少选择一个目标站点。', 'heb-product-publisher' ),
				],
			]
		);
	}

	/**
	 * @param \WP_Term $term Current term.
	 */
	public function render_term_metabox( $term ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$sites          = Heb_Product_Publisher_Admin_Settings::remote_sites();
		$or_key_ready   = '' !== Heb_Product_Publisher_Admin_Settings::openrouter_key();
		$lang_map       = get_term_meta( $term->term_id, Heb_Product_Publisher_Term_Sync::META_LANG_MAP, true );
		$lang_map       = is_array( $lang_map ) ? $lang_map : [];
		?>
		<tr class="form-field heb-pp-term-distribute">
			<th scope="row"><?php esc_html_e( '多站点分发', 'heb-product-publisher' ); ?></th>
			<td>
				<?php if ( empty( $sites ) ) : ?>
					<p class="description">
						<?php esc_html_e( '尚未配置远端站点。', 'heb-product-publisher' ); ?>
						<a href="<?php echo esc_url( Heb_Product_Publisher_Admin_Menu::url() ); ?>">
							<?php esc_html_e( '去配置', 'heb-product-publisher' ); ?>
						</a>
					</p>
				<?php else : ?>
					<?php if ( ! $or_key_ready ) : ?>
						<p class="notice notice-warning inline" style="padding:6px 10px;">
							<?php esc_html_e( '尚未配置 OpenRouter API Key，分发时不会翻译。', 'heb-product-publisher' ); ?>
						</p>
					<?php endif; ?>
					<div class="heb-pp-term-sites">
						<?php foreach ( $sites as $s ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="heb-pp-term-site" value="<?php echo esc_attr( $s['id'] ); ?>" />
								<?php echo esc_html( $s['label'] ); ?>
								<code style="font-size:11px;color:#666;"><?php echo esc_html( $s['locale_override'] ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:8px;">
						<button type="button" class="button button-primary" id="heb-pp-term-distribute-btn" data-term-id="<?php echo esc_attr( $term->term_id ); ?>">
							<?php esc_html_e( '翻译并分发', 'heb-product-publisher' ); ?>
						</button>
					</p>
					<div id="heb-pp-term-distribute-result" class="heb-pp-test-result" aria-live="polite"></div>
				<?php endif; ?>

				<?php if ( ! empty( $lang_map ) ) : ?>
					<h4 style="margin:14px 0 4px;"><?php esc_html_e( '已建立的跨语言 URL', 'heb-product-publisher' ); ?></h4>
					<table class="widefat striped" style="max-width:600px;">
						<thead><tr><th style="width:80px;"><?php esc_html_e( '语言', 'heb-product-publisher' ); ?></th><th><?php esc_html_e( 'URL', 'heb-product-publisher' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $lang_map as $lang => $url ) : ?>
							<tr>
								<td><code><?php echo esc_html( $lang ); ?></code></td>
								<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function add_bulk_action( $actions ) {
		$actions['heb_pp_distribute_term'] = __( 'HEB 分发到所有已配置站点', 'heb-product-publisher' );
		return $actions;
	}

	/**
	 * @param string         $redirect Redirect URL.
	 * @param string         $action   Bulk action.
	 * @param array<int,int> $term_ids Selected term ids.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $term_ids ) {
		if ( 'heb_pp_distribute_term' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $redirect;
		}
		$sites = Heb_Product_Publisher_Admin_Settings::remote_sites();
		if ( empty( $sites ) ) {
			return add_query_arg( 'heb_pp_term_bulk', 'no_sites', $redirect );
		}

		$source_locale = Heb_Product_Publisher_Admin_Settings::source_locale();
		$translator    = new Heb_Product_Publisher_Translator();
		$term_sync     = new Heb_Product_Publisher_Term_Sync();

		$success = 0;
		$fail    = 0;
		foreach ( (array) $term_ids as $tid ) {
			$payload = Heb_Product_Publisher_Term_Sync::build_payload( (int) $tid );
			if ( empty( $payload ) ) {
				$fail++;
				continue;
			}
			$ok_this = true;
			foreach ( $sites as $site ) {
				$r = $term_sync->distribute_to_site( (int) $tid, $payload, $source_locale, $site, $translator );
				if ( empty( $r['ok'] ) ) {
					$ok_this = false;
				}
			}
			if ( $ok_this ) {
				$success++;
			} else {
				$fail++;
			}
		}
		return add_query_arg(
			[
				'heb_pp_term_bulk'    => 'done',
				'heb_pp_term_success' => $success,
				'heb_pp_term_fail'    => $fail,
			],
			$redirect
		);
	}

	/**
	 * 列表顶部展示 bulk 结果（在 add form 之前 render，借这个 hook）。
	 */
	public function render_bulk_result() {
		if ( ! isset( $_GET['heb_pp_term_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$state = sanitize_key( wp_unslash( (string) $_GET['heb_pp_term_bulk'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'no_sites' === $state ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( '尚未配置远端站点，分发已跳过。', 'heb-product-publisher' ) . '</p></div>';
			return;
		}
		if ( 'done' === $state ) {
			$success = isset( $_GET['heb_pp_term_success'] ) ? (int) $_GET['heb_pp_term_success'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$fail    = isset( $_GET['heb_pp_term_fail'] ) ? (int) $_GET['heb_pp_term_fail'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>'
				. sprintf(
					/* translators: 1: success count, 2: fail count */
					esc_html__( '分发完成：成功 %1$d 条 · 失败 %2$d 条', 'heb-product-publisher' ),
					$success,
					$fail
				)
				. '</p></div>';
		}
	}

	/**
	 * AJAX：单个 term 分发到选定站点。
	 */
	public function ajax_distribute_term() {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		Heb_Product_Publisher_Runtime::raise();

		$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$site_ids = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['site_ids'] ) )
			: [];
		if ( $term_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( '无效的 term ID。', 'heb-product-publisher' ) ] );
		}
		if ( empty( $site_ids ) ) {
			wp_send_json_error( [ 'message' => __( '未选择目标站点。', 'heb-product-publisher' ) ] );
		}

		$payload = Heb_Product_Publisher_Term_Sync::build_payload( $term_id );
		if ( empty( $payload ) ) {
			wp_send_json_error( [ 'message' => __( '无法构造 term payload（不存在或 taxonomy 不允许分发）。', 'heb-product-publisher' ) ] );
		}

		$source_locale = Heb_Product_Publisher_Admin_Settings::source_locale();
		$translator    = new Heb_Product_Publisher_Translator();
		$term_sync     = new Heb_Product_Publisher_Term_Sync();

		$results = [];
		foreach ( $site_ids as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
			if ( ! $site ) {
				$results[ $sid ] = [ 'ok' => false, 'message' => __( '未找到站点。', 'heb-product-publisher' ) ];
				continue;
			}
			$results[ $sid ] = $term_sync->distribute_to_site( $term_id, $payload, $source_locale, $site, $translator );
		}

		wp_send_json_success( $results );
	}
}
