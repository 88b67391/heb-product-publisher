<?php
/**
 * Hub UI：产品编辑页右侧 metabox + AJAX 分发。
 *
 * - 列出已配置的远端站点（复选框）
 * - 「获取分类」按钮：调用目标站点 /site-info 返回分类预览
 * - 「翻译 + 分发」按钮：建 payload → OpenRouter 翻译 → POST /import-product
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Hub_UI {

	const NONCE_ACTION = 'heb_pp_hub_ui';

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
		add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_heb_pp_fetch_site_info', [ $this, 'ajax_fetch_site_info' ] );
		add_action( 'wp_ajax_heb_pp_distribute', [ $this, 'ajax_distribute' ] );
		add_action( 'wp_ajax_heb_pp_test_site', [ $this, 'ajax_test_site' ] );
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( 'settings_page_heb-product-publisher' === $hook ) {
			wp_enqueue_style( 'heb-pp-settings', HEB_PP_URL . 'assets/css/settings.css', [], HEB_PP_VERSION );
			wp_enqueue_script( 'heb-pp-settings', HEB_PP_URL . 'assets/js/settings.js', [ 'jquery' ], HEB_PP_VERSION, true );
			wp_localize_script(
				'heb-pp-settings',
				'HebPPSettings',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				]
			);
			return;
		}

		if ( 'post' !== $screen->base ) {
			return;
		}
		if ( ! in_array( $screen->post_type, heb_pp_distributable_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'heb-pp-hub', HEB_PP_URL . 'assets/css/hub.css', [], HEB_PP_VERSION );
		wp_enqueue_script( 'heb-pp-hub', HEB_PP_URL . 'assets/js/hub.js', [ 'jquery', 'wp-i18n' ], HEB_PP_VERSION, true );

		$post_id      = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$source_slugs = $post_id ? Heb_Product_Publisher_Sync::get_term_slugs_map( $post_id ) : [];

		wp_localize_script(
			'heb-pp-hub',
			'HebPPHub',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'sourceSlugs'  => (object) $source_slugs,
				'i18n'         => [
					'fetching'      => __( '获取目标站点信息中…', 'heb-product-publisher' ),
					'translating'   => __( '翻译并分发中…', 'heb-product-publisher' ),
					'done'          => __( '完成。', 'heb-product-publisher' ),
					'error'         => __( '请求失败', 'heb-product-publisher' ),
					'selectAtLeast' => __( '请至少选择一个目标站点。', 'heb-product-publisher' ),
					'noTerms'       => __( '暂无分类项。', 'heb-product-publisher' ),
					'useSource'     => __( '未获取目标分类，按源站 slug 自动匹配（不存在则创建）。', 'heb-product-publisher' ),
				],
			]
		);
	}

	public function add_metabox() {
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			add_meta_box(
				'heb-pp-hub',
				__( '多站点分发', 'heb-product-publisher' ),
				[ $this, 'render_metabox' ],
				$pt,
				'side',
				'high'
			);
		}
	}

	/**
	 * @param \WP_Post $post Post.
	 */
	public function render_metabox( $post ) {
		$sites          = Heb_Product_Publisher_Admin_Settings::remote_sites();
		$source_locale  = Heb_Product_Publisher_Admin_Settings::source_locale();
		$or_key_ready   = '' !== Heb_Product_Publisher_Admin_Settings::openrouter_key();
		$settings_url   = admin_url( 'options-general.php?page=heb-product-publisher' );
		?>
		<div class="heb-pp-hub-box" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="heb-pp-hub-meta">
				<strong><?php esc_html_e( '源语言：', 'heb-product-publisher' ); ?></strong>
				<code><?php echo esc_html( $source_locale ); ?></code>
				&nbsp;·&nbsp;
				<?php if ( $or_key_ready ) : ?>
					<span class="heb-pp-ok">OpenRouter OK</span>
				<?php else : ?>
					<span class="heb-pp-bad"><?php esc_html_e( '未配置 OpenRouter', 'heb-product-publisher' ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( empty( $sites ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: settings URL */
						esc_html__( '尚未配置任何远端站点，请先到 %s 添加。', 'heb-product-publisher' ),
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'HEB Publisher 设置', 'heb-product-publisher' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<ul class="heb-pp-site-list">
					<?php foreach ( $sites as $site ) :
						$sid = isset( $site['id'] ) ? $site['id'] : '';
						$lbl = isset( $site['label'] ) ? $site['label'] : '';
						$loc = isset( $site['locale_override'] ) ? $site['locale_override'] : '';
						?>
						<li class="heb-pp-site-item" data-site-id="<?php echo esc_attr( $sid ); ?>">
							<label class="heb-pp-site-head">
								<input type="checkbox" class="heb-pp-site-check" value="<?php echo esc_attr( $sid ); ?>" />
								<span class="heb-pp-site-label"><?php echo esc_html( $lbl ); ?></span>
								<span class="heb-pp-site-locale"><?php echo esc_html( $loc ? $loc : '—' ); ?></span>
							</label>
							<div class="heb-pp-site-info" hidden></div>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="heb-pp-actions">
					<button type="button" class="button" id="heb-pp-btn-fetch">
						<?php esc_html_e( '获取目标站点分类', 'heb-product-publisher' ); ?>
					</button>
					<button type="button" class="button button-primary" id="heb-pp-btn-distribute" <?php disabled( ! $or_key_ready || 'auto-draft' === $post->post_status ); ?>>
						<?php esc_html_e( '翻译并分发', 'heb-product-publisher' ); ?>
					</button>
				</div>

				<?php if ( 'auto-draft' === $post->post_status ) : ?>
					<p class="description"><?php esc_html_e( '请先保存/发布本文，再进行分发。', 'heb-product-publisher' ); ?></p>
				<?php endif; ?>

				<div id="heb-pp-result" class="heb-pp-result" aria-live="polite"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * site_overrides[site_id][tax][] = slug
	 * 清理为 string→array<string> 结构。
	 *
	 * @param array<string,mixed> $raw Raw POST.
	 * @return array<string, array<string, array<int,string>>>
	 */
	private function sanitize_site_overrides( $raw ) {
		$out = [];
		foreach ( (array) $raw as $site_id => $taxes ) {
			$site_id = sanitize_text_field( (string) $site_id );
			if ( '' === $site_id || ! is_array( $taxes ) ) {
				continue;
			}
			$tax_out = [];
			foreach ( $taxes as $tax => $slugs ) {
				$tax = sanitize_key( (string) $tax );
				if ( '' === $tax || ! is_array( $slugs ) ) {
					continue;
				}
				$clean = [];
				foreach ( $slugs as $s ) {
					$s = sanitize_title( (string) $s );
					if ( '' !== $s ) {
						$clean[] = $s;
					}
				}
				$tax_out[ $tax ] = array_values( array_unique( $clean ) );
			}
			$out[ $site_id ] = $tax_out;
		}
		return $out;
	}

	/* ========== AJAX ========== */

	/**
	 * 测试站点连接（Settings 页）。
	 */
	public function ajax_test_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_id'] ) ) : '';
		$site    = Heb_Product_Publisher_Admin_Settings::get_site( $site_id );
		if ( ! $site ) {
			wp_send_json_error( [ 'message' => __( '未找到站点。', 'heb-product-publisher' ) ], 404 );
		}

		$res = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [ 'post_type' => 'products' ], 15 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		wp_send_json_success(
			[
				'locale'        => isset( $res['locale'] ) ? $res['locale'] : '',
				'site_url'      => isset( $res['site_url'] ) ? $res['site_url'] : '',
				'taxonomy_keys' => isset( $res['taxonomies'] ) && is_array( $res['taxonomies'] ) ? array_keys( $res['taxonomies'] ) : [],
			]
		);
	}

	/**
	 * 获取目标站点的 locale + 分类（编辑页用）。
	 */
	public function ajax_fetch_site_info() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$site_ids = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['site_ids'] ) )
			: [];
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, heb_pp_distributable_post_types(), true ) ) {
			wp_send_json_error( [ 'message' => __( '不支持的 post type。', 'heb-product-publisher' ) ] );
		}

		$results = [];
		foreach ( $site_ids as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
			if ( ! $site ) {
				$results[ $sid ] = [ 'ok' => false, 'message' => __( '未找到站点。', 'heb-product-publisher' ) ];
				continue;
			}
			$res = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [ 'post_type' => $post_type ], 20 );
			if ( is_wp_error( $res ) ) {
				$results[ $sid ] = [ 'ok' => false, 'message' => $res->get_error_message() ];
				continue;
			}
			$results[ $sid ] = [
				'ok'         => true,
				'locale'     => isset( $res['locale'] ) ? $res['locale'] : '',
				'taxonomies' => isset( $res['taxonomies'] ) ? $res['taxonomies'] : [],
			];
		}
		wp_send_json_success( $results );
	}

	/**
	 * 翻译并分发。
	 */
	public function ajax_distribute() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( '无权编辑该文章。', 'heb-product-publisher' ) ], 403 );
		}
		$site_ids = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['site_ids'] ) )
			: [];
		if ( empty( $site_ids ) ) {
			wp_send_json_error( [ 'message' => __( '未选择目标站点。', 'heb-product-publisher' ) ] );
		}

		$raw_overrides = isset( $_POST['site_overrides'] ) && is_array( $_POST['site_overrides'] )
			? wp_unslash( $_POST['site_overrides'] )
			: [];
		$site_overrides = $this->sanitize_site_overrides( $raw_overrides );

		$basepayload = Heb_Product_Publisher_Sync::build_payload( $post_id );
		if ( empty( $basepayload ) ) {
			wp_send_json_error( [ 'message' => __( '无法构造 payload（post 不存在或类型不允许分发）。', 'heb-product-publisher' ) ] );
		}

		$source_locale = isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : 'en_US';
		$translator    = new Heb_Product_Publisher_Translator();

		$results = [];
		foreach ( $site_ids as $sid ) {
			$site = Heb_Product_Publisher_Admin_Settings::get_site( $sid );
			if ( ! $site ) {
				$results[ $sid ] = [ 'ok' => false, 'message' => __( '未找到站点。', 'heb-product-publisher' ) ];
				continue;
			}

			$target_locale = isset( $site['locale_override'] ) && '' !== $site['locale_override']
				? $site['locale_override']
				: '';
			if ( '' === $target_locale ) {
				$info = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [ 'post_type' => $basepayload['post_type'] ], 15 );
				if ( is_wp_error( $info ) ) {
					$results[ $sid ] = [ 'ok' => false, 'message' => $info->get_error_message() ];
					continue;
				}
				$target_locale = isset( $info['locale'] ) ? (string) $info['locale'] : '';
			}

			$translate_errors = [];
			$translate_stats  = [];
			$payload          = $basepayload;

			if ( isset( $site_overrides[ $sid ] ) && is_array( $site_overrides[ $sid ] ) ) {
				$payload['taxonomies'] = $site_overrides[ $sid ];
			}

			if ( '' !== $target_locale && ! Heb_Product_Publisher_Translator::same_language( $source_locale, $target_locale ) ) {
				$tr = $translator->translate_payload( $payload, $source_locale, $target_locale );
				$payload          = isset( $tr['payload'] ) && is_array( $tr['payload'] ) ? $tr['payload'] : $payload;
				$translate_errors = isset( $tr['errors'] ) ? $tr['errors'] : [];
				$translate_stats  = isset( $tr['stats'] ) ? $tr['stats'] : [];
			}

			$push = Heb_Product_Publisher_Remote_Client::post( $site, '/import-product', $payload, 60 );
			if ( is_wp_error( $push ) ) {
				$results[ $sid ] = [
					'ok'         => false,
					'message'    => $push->get_error_message(),
					'translate'  => $translate_stats,
					'warn'       => $translate_errors,
					'locale'     => $target_locale,
				];
				continue;
			}

			$results[ $sid ] = [
				'ok'        => true,
				'post_id'   => isset( $push['post_id'] ) ? (int) $push['post_id'] : 0,
				'edit_url'  => isset( $push['edit_url'] ) ? (string) $push['edit_url'] : '',
				'created'   => ! empty( $push['created'] ),
				'translate' => $translate_stats,
				'warn'      => $translate_errors,
				'locale'    => $target_locale,
			];
		}

		wp_send_json_success( $results );
	}
}
