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

		if ( Heb_Product_Publisher_Admin_Menu::hook_suffix( Heb_Product_Publisher_Admin_Menu::PARENT_SLUG ) === $hook ) {
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

		$post_id    = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$source_map = $post_id ? Heb_Product_Publisher_Sync::get_term_slugs_map( $post_id ) : [];

		// 前端只关心 slug 字符串数组（用来预选目标站对应分类）。从 v3.0 的对象数组里抽出 slug。
		$source_slugs_flat = [];
		foreach ( $source_map as $tax => $rows ) {
			$slugs = [];
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['slug_fallback'] ) ) {
					$slugs[] = (string) $row['slug_fallback'];
				} elseif ( is_string( $row ) ) {
					$slugs[] = $row;
				}
			}
			if ( ! empty( $slugs ) ) {
				$source_slugs_flat[ $tax ] = array_values( array_unique( $slugs ) );
			}
		}

		wp_localize_script(
			'heb-pp-hub',
			'HebPPHub',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'sourceSlugs'  => (object) $source_slugs_flat,
				'i18n'         => [
					'fetching'      => __( '获取目标站点信息中…', 'heb-product-publisher' ),
					'translating'   => __( '翻译中，请稍候…', 'heb-product-publisher' ),
					'distributing'  => __( '分发中…', 'heb-product-publisher' ),
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
		$settings_url   = Heb_Product_Publisher_Admin_Menu::url();
		$distributions  = get_post_meta( $post->ID, '_heb_pp_distributions', true );
		if ( ! is_array( $distributions ) ) {
			$distributions = [];
		}
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
						<?php esc_html_e( '获取目标分类', 'heb-product-publisher' ); ?>
					</button>
					<button type="button" class="button button-primary" id="heb-pp-btn-distribute" <?php disabled( ! $or_key_ready || 'auto-draft' === $post->post_status ); ?>>
						<?php esc_html_e( '翻译并分发', 'heb-product-publisher' ); ?>
					</button>
				</div>

				<?php if ( 'auto-draft' === $post->post_status ) : ?>
					<p class="description"><?php esc_html_e( '请先保存/发布本文，再进行分发。', 'heb-product-publisher' ); ?></p>
				<?php endif; ?>

				<div id="heb-pp-result" class="heb-pp-result" aria-live="polite"></div>

				<?php if ( ! empty( $distributions ) ) : ?>
					<div class="heb-pp-hub-history">
						<p><strong><?php esc_html_e( '上次分发记录', 'heb-product-publisher' ); ?></strong></p>
						<ul class="heb-pp-hub-history-list">
							<?php foreach ( $distributions as $sid => $dist ) :
								if ( ! is_array( $dist ) ) {
									continue;
								}
								$status = isset( $dist['last_status'] ) ? (string) $dist['last_status'] : '';
								$status_class = in_array( $status, [ 'success', 'skipped_locked' ], true ) ? 'ok' : ( 'warn' === $status ? 'warn' : 'err' );
								?>
								<li class="heb-pp-hub-history-item heb-pp-<?php echo esc_attr( $status_class ); ?>">
									<strong><?php echo esc_html( isset( $dist['label'] ) ? $dist['label'] : $sid ); ?></strong>
									<?php if ( ! empty( $dist['last_sent_at'] ) ) : ?>
										· <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $dist['last_sent_at'] ) ); ?>
									<?php endif; ?>
									· <code><?php echo esc_html( $status ); ?></code>
									<?php if ( ! empty( $dist['remote_edit_url'] ) ) : ?>
										· <a href="<?php echo esc_url( $dist['remote_edit_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '远端编辑', 'heb-product-publisher' ); ?></a>
									<?php endif; ?>
									<?php if ( ! empty( $dist['pending_media'] ) ) : ?>
										· <span class="heb-pp-warn">⏳ <?php echo esc_html( (string) (int) $dist['pending_media'] ); ?> media</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
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

	/**
	 * 将 UI 分类 override（slug 数组）合并进 build_payload 的对象数组，保留 source_term_id 映射。
	 *
	 * @param array<string, array<int, array<string,mixed>>> $base_taxonomies From build_payload.
	 * @param array<string, array<int, string>>            $overrides       Per-tax slug selections.
	 * @return array<string, array<int, array<string,mixed>>>
	 */
	private function merge_taxonomy_overrides( array $base_taxonomies, array $overrides ) {
		$out = $base_taxonomies;
		foreach ( $overrides as $tax => $slugs ) {
			$tax = sanitize_key( (string) $tax );
			if ( '' === $tax || ! is_array( $slugs ) || ! isset( $base_taxonomies[ $tax ] ) ) {
				continue;
			}
			$allowed = array_flip(
				array_values(
					array_unique(
						array_filter(
							array_map(
								static function ( $s ) {
									return sanitize_title( (string) $s );
								},
								$slugs
							)
						)
					)
				)
			);
			$filtered = [];
			foreach ( (array) $base_taxonomies[ $tax ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$slug = isset( $row['slug_fallback'] ) ? sanitize_title( (string) $row['slug_fallback'] ) : '';
				if ( '' !== $slug && isset( $allowed[ $slug ] ) ) {
					$filtered[] = $row;
				}
			}
			if ( ! empty( $filtered ) ) {
				$out[ $tax ] = $filtered;
			} else {
				unset( $out[ $tax ] );
			}
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

		$default_pt = '';
		$pts        = heb_pp_distributable_post_types();
		if ( ! empty( $pts ) ) {
			$default_pt = (string) $pts[0];
		}
		$res = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [ 'post_type' => $default_pt ], 15 );
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
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( '无权编辑该文章。', 'heb-product-publisher' ) ], 403 );
		}
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
	 *
	 * 整个流程包裹 try/catch：任何意料外异常（OOM / 致命 fatal / 第三方钩子抛错）
	 * 都至少落一条日志，避免"分发失败连日志都没"的静默状况。
	 */
	public function ajax_distribute() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足。', 'heb-product-publisher' ) ], 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		Heb_Product_Publisher_Runtime::raise();

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

		try {
			$basepayload = Heb_Product_Publisher_Sync::build_payload( $post_id );
		} catch ( \Throwable $e ) {
			$msg = sprintf( 'build_payload threw: %s @%s:%d', $e->getMessage(), $e->getFile(), $e->getLine() );
			$this->log_failure( $post_id, '', $msg );
			wp_send_json_error( [ 'message' => $msg ] );
		}
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
				$this->log_failure( $post_id, (string) $sid, __( '未找到站点。', 'heb-product-publisher' ) );
				continue;
			}

			// 入口先落一条 "started" 日志：即使下面 OpenRouter / Receiver hang 死、
			// 浏览器超时断开、PHP 后台继续跑，用户在日志页至少看到分发已发起。
			$this->log_started( $post_id, $site );

			try {
				$results[ $sid ] = $this->distribute_to_site( $post_id, $basepayload, $source_locale, $site, $site_overrides, $translator );
			} catch ( \Throwable $e ) {
				$msg = sprintf( 'distribute_to_site threw: %s @%s:%d', $e->getMessage(), $e->getFile(), $e->getLine() );
				$results[ $sid ] = [ 'ok' => false, 'message' => $msg, 'locale' => '' ];
				$this->log_failure( $post_id, (string) $sid, $msg, $site );
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * 在分发刚开始时立即落一条日志，状态 = 'started'。
	 * 主要价值：OpenRouter / Receiver 卡死时，浏览器看到 504/超时，用户回到
	 * 日志页能看到"已发起"，知道流程进到哪一步（不是完全静默）。
	 *
	 * @param int                   $post_id Source post id.
	 * @param array<string,string>  $site    Remote site row.
	 * @return void
	 */
	private function log_started( $post_id, array $site ) {
		if ( ! class_exists( 'Heb_Product_Publisher_Log' ) ) {
			return;
		}
		$post = get_post( $post_id );
		Heb_Product_Publisher_Log::insert(
			[
				'post_id'       => (int) $post_id,
				'post_type'     => $post ? (string) $post->post_type : '',
				'post_title'    => $post ? (string) $post->post_title : '',
				'site_id'       => isset( $site['id'] ) ? (string) $site['id'] : '',
				'site_label'    => isset( $site['label'] ) ? (string) $site['label'] : '',
				'site_url'      => isset( $site['url'] ) ? (string) $site['url'] : '',
				'status'        => 'started',
				'message'       => __( '分发已发起，开始翻译并推送…', 'heb-product-publisher' ),
			]
		);
	}

	/**
	 * 兜底失败日志（exception/无 site/build 失败等罕见路径）。
	 *
	 * @param int                          $post_id Post id.
	 * @param string                       $sid     Site id.
	 * @param string                       $message Error message.
	 * @param array<string,string>|null    $site    Optional site row.
	 * @return void
	 */
	private function log_failure( $post_id, $sid, $message, $site = null ) {
		if ( ! class_exists( 'Heb_Product_Publisher_Log' ) ) {
			return;
		}
		$post = get_post( $post_id );
		Heb_Product_Publisher_Log::insert(
			[
				'post_id'    => (int) $post_id,
				'post_type'  => $post ? (string) $post->post_type : '',
				'post_title' => $post ? (string) $post->post_title : '',
				'site_id'    => (string) $sid,
				'site_label' => is_array( $site ) && isset( $site['label'] ) ? (string) $site['label'] : '',
				'site_url'   => is_array( $site ) && isset( $site['url'] ) ? (string) $site['url'] : '',
				'status'     => 'error',
				'message'    => (string) $message,
			]
		);
	}

	/**
	 * 反查远端站点是否已存在源站 post 对应的文章（用于把"超时假失败"识别成成功）。
	 *
	 * @param array<string,string> $site Site config.
	 * @param string               $post_type Post type.
	 * @param int                  $source_post_id Source post id.
	 * @param string               $source_site Source site host.
	 * @return array<string,mixed>|null
	 */
	private function probe_remote_post( array $site, $post_type, $source_post_id, $source_site ) {
		if ( '' === $post_type || $source_post_id <= 0 || '' === $source_site ) {
			return null;
		}
		// 等远端把同步收尾（sideload + ACF）跑完，再去查一次。
		sleep( 2 );
		$res = Heb_Product_Publisher_Remote_Client::post(
			$site,
			'/lookup-by-source',
			[
				'post_type'      => $post_type,
				'source_post_id' => $source_post_id,
				'source_site'    => $source_site,
			],
			15
		);
		return is_wp_error( $res ) ? null : (array) $res;
	}

	/**
	 * @param string $locale Locale string.
	 * @return string
	 */
	private function locale_to_lang( $locale ) {
		$locale = strtolower( trim( (string) $locale ) );
		if ( '' === $locale ) {
			return '';
		}
		if ( false !== strpos( $locale, '_' ) ) {
			$parts = explode( '_', $locale );
			return sanitize_key( (string) $parts[0] );
		}
		if ( false !== strpos( $locale, '-' ) ) {
			$parts = explode( '-', $locale );
			return sanitize_key( (string) $parts[0] );
		}
		return sanitize_key( $locale );
	}

	/**
	 * @param int                  $post_id Source post id.
	 * @param array<string,mixed>  $basepayload Base payload.
	 * @return array<string,string>
	 */
	private function get_lang_map( $post_id, array $basepayload ) {
		$map = get_post_meta( $post_id, '_heb_pp_lang_map', true );
		$out = [];
		if ( is_array( $map ) ) {
			foreach ( $map as $lang => $url ) {
				$lang = sanitize_key( (string) $lang );
				$url  = esc_url_raw( (string) $url );
				if ( '' !== $lang && '' !== $url ) {
					$out[ $lang ] = $url;
				}
			}
		}
		$src_lang = $this->locale_to_lang( isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : get_locale() );
		$src_url  = get_permalink( $post_id );
		if ( '' !== $src_lang && is_string( $src_url ) && '' !== $src_url ) {
			$out[ $src_lang ] = $src_url;
		}
		return $out;
	}

	/**
	 * 分发成功后更新源站 map 并同步到所有远端站点。
	 *
	 * @param int                  $post_id Source post id.
	 * @param array<string,mixed>  $basepayload Payload.
	 * @param array<string,string> $site Remote site row.
	 * @param string               $target_locale Target locale.
	 * @param array<string,mixed>  $result Push result.
	 */
	private function refresh_lang_map( $post_id, array $basepayload, array $site, $target_locale, array $result ) {
		if ( empty( $result['ok'] ) ) {
			return [];
		}
		$lang = $this->locale_to_lang( $target_locale );
		$url  = isset( $result['permalink'] ) ? esc_url_raw( (string) $result['permalink'] ) : '';
		if ( '' === $url && ! empty( $result['post_id'] ) && ! empty( $site['url'] ) ) {
			$url = rtrim( (string) $site['url'], '/' ) . '/?p=' . (int) $result['post_id'];
		}
		if ( '' === $lang || '' === $url ) {
			return [];
		}
		$map          = $this->get_lang_map( $post_id, $basepayload );
		$map[ $lang ] = $url;
		update_post_meta( $post_id, '_heb_pp_lang_map', $map );
		return $this->sync_lang_map_to_all_sites( $basepayload, $map );
	}

	/**
	 * @param array<string,mixed>   $basepayload Payload.
	 * @param array<string,string>  $lang_map    Lang map.
	 */
	private function sync_lang_map_to_all_sites( array $basepayload, array $lang_map ) {
		$post_type      = isset( $basepayload['post_type'] ) ? sanitize_key( (string) $basepayload['post_type'] ) : '';
		$source_post_id = isset( $basepayload['source_post_id'] ) ? (int) $basepayload['source_post_id'] : 0;
		$source_site    = isset( $basepayload['source_site'] ) ? sanitize_text_field( (string) $basepayload['source_site'] ) : '';
		if ( '' === $post_type || $source_post_id <= 0 || '' === $source_site || empty( $lang_map ) ) {
			return [];
		}
		$failures = [];
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $s ) {
			$res = Heb_Product_Publisher_Remote_Client::post(
				$s,
				'/sync-lang-map',
				[
					'post_type'      => $post_type,
					'source_post_id' => $source_post_id,
					'source_site'    => $source_site,
					'lang_map'       => $lang_map,
				],
				15
			);
			if ( is_wp_error( $res ) ) {
				$failures[] = sprintf(
					'%s: %s',
					isset( $s['label'] ) ? (string) $s['label'] : '',
					$res->get_error_message()
				);
			}
		}
		return $failures;
	}

	/**
	 * 分发前确保 payload 中 term 的父级已推送到目标站（按层级顺序）。
	 *
	 * @param array<string,mixed>              $basepayload   Payload.
	 * @param array<string,string>             $site          Remote site.
	 * @param Heb_Product_Publisher_Translator $translator    Translator.
	 * @return array<int,string> Warnings.
	 */
	private function ensure_parent_terms_synced( array $basepayload, array $site, Heb_Product_Publisher_Translator $translator ) {
		$warnings  = [];
		$taxonomies = isset( $basepayload['taxonomies'] ) && is_array( $basepayload['taxonomies'] )
			? $basepayload['taxonomies']
			: [];
		if ( empty( $taxonomies ) ) {
			return $warnings;
		}

		$pending = [];
		foreach ( $taxonomies as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$parent_id = isset( $row['source_parent_term_id'] ) ? (int) $row['source_parent_term_id'] : 0;
				if ( $parent_id > 0 ) {
					$pending[ $parent_id ] = true;
				}
			}
		}

		$term_sync = new Heb_Product_Publisher_Term_Sync();
		$source_locale = isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : '';
		$depth       = 0;
		while ( ! empty( $pending ) && $depth < 12 ) {
			$depth++;
			$batch = array_keys( $pending );
			$pending = [];
			foreach ( $batch as $term_id ) {
				$term_id = (int) $term_id;
				$tp = Heb_Product_Publisher_Term_Sync::build_payload( $term_id );
				if ( empty( $tp ) ) {
					continue;
				}
				$res = $term_sync->distribute_to_site( $term_id, $tp, $source_locale, $site, $translator );
				if ( empty( $res['ok'] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: term id, 2: error message */
						__( '父级 term #%1$d 同步失败：%2$s', 'heb-product-publisher' ),
						$term_id,
						isset( $res['message'] ) ? (string) $res['message'] : 'unknown'
					);
				}
				if ( ! empty( $tp['source_parent_term_id'] ) ) {
					$pp = (int) $tp['source_parent_term_id'];
					if ( $pp > 0 ) {
						$pending[ $pp ] = true;
					}
				}
			}
		}
		return $warnings;
	}

	/**
	 * 单 post → 单站点的核心分发流程（供 ajax_distribute 与批量分发复用）。
	 *
	 * @param int                                                     $post_id        Source post id.
	 * @param array<string,mixed>                                     $basepayload    Built payload.
	 * @param string                                                  $source_locale  Source locale.
	 * @param array<string,string>                                    $site           Remote site config.
	 * @param array<string, array<string, array<int,string>>>         $site_overrides Per-site taxonomy overrides.
	 * @param Heb_Product_Publisher_Translator                        $translator     Translator instance.
	 * @return array<string,mixed>
	 */
	public function distribute_to_site( $post_id, array $basepayload, $source_locale, array $site, array $site_overrides, Heb_Product_Publisher_Translator $translator ) {
		$started = microtime( true );
		$sid     = isset( $site['id'] ) ? (string) $site['id'] : '';
		$label   = isset( $site['label'] ) ? (string) $site['label'] : $sid;
		$url     = isset( $site['url'] ) ? (string) $site['url'] : '';
		$slug_strategy = isset( $site['slug_strategy'] ) && in_array( $site['slug_strategy'], [ 'source', 'localized' ], true )
			? $site['slug_strategy']
			: 'localized';

		$target_locale = isset( $site['locale_override'] ) && '' !== $site['locale_override']
			? $site['locale_override']
			: '';
		if ( '' === $target_locale ) {
			$info = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [ 'post_type' => $basepayload['post_type'] ], 15 );
			if ( is_wp_error( $info ) ) {
				$r = [ 'ok' => false, 'message' => $info->get_error_message(), 'locale' => '' ];
				$this->record_distribution( $post_id, $site, $target_locale, $r, [], (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
				return $r;
			}
			$target_locale = isset( $info['locale'] ) ? (string) $info['locale'] : '';
		}

		$translate_errors = [];
		$translate_stats  = [];
		$payload          = $basepayload;
		$payload['slug_strategy'] = $slug_strategy;
		$payload['lang_map']      = $this->get_lang_map( $post_id, $basepayload );

		if ( isset( $site_overrides[ $sid ] ) && is_array( $site_overrides[ $sid ] ) ) {
			$base_tax = isset( $basepayload['taxonomies'] ) && is_array( $basepayload['taxonomies'] )
				? $basepayload['taxonomies']
				: [];
			$payload['taxonomies'] = $this->merge_taxonomy_overrides( $base_tax, $site_overrides[ $sid ] );
		}

		$parent_warnings = $this->ensure_parent_terms_synced( $payload, $site, $translator );

		if ( '' !== $target_locale && ! Heb_Product_Publisher_Translator::same_language( $source_locale, $target_locale ) ) {
			$tr = $translator->translate_payload( $payload, $source_locale, $target_locale );
			$payload          = isset( $tr['payload'] ) && is_array( $tr['payload'] ) ? $tr['payload'] : $payload;
			$translate_errors = isset( $tr['errors'] ) ? $tr['errors'] : [];
			$translate_stats  = isset( $tr['stats'] ) ? $tr['stats'] : [];
		}

		$strict_abort = Heb_Product_Publisher_Translator::strict_abort_reason( $translate_errors );
		if ( null !== $strict_abort ) {
			$r = [
				'ok'        => false,
				'message'   => $strict_abort,
				'translate' => $translate_stats,
				'warn'      => $translate_errors,
				'locale'    => $target_locale,
			];
			$this->record_distribution( $post_id, $site, $target_locale, $r, $translate_stats, (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
			return $r;
		}

		$import_timeout = Heb_Product_Publisher_Admin_Settings::site_timeout( $site );
		$push           = Heb_Product_Publisher_Remote_Client::post( $site, '/import-product', $payload, $import_timeout );
		if ( is_wp_error( $push ) ) {
			// cURL 超时常见于目标站还在 sideload 图片 / 处理 ACF——文章可能其实已创建。
			// 反查 /lookup-by-source 救回这种"假失败"。
			$probe = $this->probe_remote_post( $site, (string) $basepayload['post_type'], (int) $basepayload['source_post_id'], (string) $basepayload['source_site'] );
			if ( is_array( $probe ) && ! empty( $probe['found'] ) ) {
				$r = [
					'ok'        => true,
					'post_id'   => isset( $probe['post_id'] ) ? (int) $probe['post_id'] : 0,
					'edit_url'  => isset( $probe['edit_url'] ) ? (string) $probe['edit_url'] : '',
					'permalink' => isset( $probe['permalink'] ) ? (string) $probe['permalink'] : '',
					'created'   => false, // 反查不知道是新建还是更新，这里保守
					'translate' => $translate_stats,
					'warn'      => array_merge( $translate_errors, [
						sprintf(
							/* translators: %s: error */
							__( 'HTTP 阶段失败（%s），但反查确认远端已写入；本次按"成功"记账。建议把该站超时调大。', 'heb-product-publisher' ),
							$push->get_error_message()
						),
					] ),
					'locale'    => $target_locale,
				];
				$this->record_distribution( $post_id, $site, $target_locale, $r, $translate_stats, (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
				$this->refresh_lang_map( $post_id, $basepayload, $site, $target_locale, $r );
				return $r;
			}
			$r = [
				'ok'        => false,
				'message'   => $push->get_error_message(),
				'translate' => $translate_stats,
				'warn'      => $translate_errors,
				'locale'    => $target_locale,
			];
			$this->record_distribution( $post_id, $site, $target_locale, $r, $translate_stats, (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
			return $r;
		}

		$pending_media = isset( $push['pending_media'] ) ? (int) $push['pending_media'] : 0;
		$warns         = array_merge( $parent_warnings, $translate_errors );

		if ( ! empty( $push['locked'] ) ) {
			$warns[] = isset( $push['message'] ) ? (string) $push['message'] : __( '目标 post 已被本地锁定，跳过更新。', 'heb-product-publisher' );
			$r = [
				'ok'            => false,
				'locked'        => true,
				'status'        => 'skipped_locked',
				'post_id'       => isset( $push['post_id'] ) ? (int) $push['post_id'] : 0,
				'edit_url'      => isset( $push['edit_url'] ) ? (string) $push['edit_url'] : '',
				'permalink'     => isset( $push['permalink'] ) ? (string) $push['permalink'] : '',
				'created'       => false,
				'translate'     => $translate_stats,
				'warn'          => $warns,
				'locale'        => $target_locale,
				'pending_media' => $pending_media,
				'message'       => isset( $push['message'] ) ? (string) $push['message'] : '',
			];
			$this->record_distribution( $post_id, $site, $target_locale, $r, $translate_stats, (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
			return $r;
		}

		if ( $pending_media > 0 ) {
			$warns[] = sprintf(
				/* translators: %d: number of remote images still being downloaded asynchronously on the receiver */
				__( '主站点已写入，子站后台正在异步下载 %d 张 Elementor 图片（不阻塞，可关闭页面）。', 'heb-product-publisher' ),
				$pending_media
			);
		}
		if ( ! empty( $translate_stats['strings'] ) && isset( $translate_stats['translated'] ) ) {
			$ratio = (int) $translate_stats['translated'] / max( 1, (int) $translate_stats['strings'] );
			if ( $ratio < 0.85 && ! empty( $translate_errors ) ) {
				$warns[] = sprintf(
					/* translators: 1: translated count, 2: total strings */
					__( '翻译不完整（%1$d/%2$d），部分内容可能仍为源语言。', 'heb-product-publisher' ),
					(int) $translate_stats['translated'],
					(int) $translate_stats['strings']
				);
			}
		}
		$r = [
			'ok'            => true,
			'post_id'       => isset( $push['post_id'] ) ? (int) $push['post_id'] : 0,
			'edit_url'      => isset( $push['edit_url'] ) ? (string) $push['edit_url'] : '',
			'permalink'     => isset( $push['permalink'] ) ? (string) $push['permalink'] : '',
			'created'       => ! empty( $push['created'] ),
			'translate'     => $translate_stats,
			'warn'          => $warns,
			'locale'        => $target_locale,
			'pending_media' => $pending_media,
		];
		$lang_map_failures = $this->refresh_lang_map( $post_id, $basepayload, $site, $target_locale, $r );
		foreach ( $lang_map_failures as $lf ) {
			$r['warn'][] = sprintf(
				/* translators: %s: remote site error */
				__( 'hreflang 同步失败：%s', 'heb-product-publisher' ),
				$lf
			);
		}
		$this->record_distribution( $post_id, $site, $target_locale, $r, $translate_stats, (int) round( ( microtime( true ) - $started ) * 1000 ), $basepayload );
		return $r;
	}

	/**
	 * 写入日志表 + 更新源文章 _heb_pp_distributions meta。
	 *
	 * @param int                  $post_id     Source post id.
	 * @param array<string,string> $site        Remote site config.
	 * @param string               $locale      Target locale.
	 * @param array<string,mixed>  $result      Distribute result.
	 * @param array<string,int>    $stats       Translate stats.
	 * @param int                  $duration_ms Duration in ms.
	 * @param array<string,mixed>  $basepayload Base payload (for title/type).
	 */
	private function record_distribution( $post_id, array $site, $locale, array $result, array $stats, $duration_ms, array $basepayload ) {
		if ( ! class_exists( 'Heb_Product_Publisher_Log' ) ) {
			return;
		}
		$ok             = ! empty( $result['ok'] );
		$locked         = ! empty( $result['locked'] );
		$remote_post_id = isset( $result['post_id'] ) ? (int) $result['post_id'] : 0;
		$edit_url       = isset( $result['edit_url'] ) ? (string) $result['edit_url'] : '';
		$message        = $ok ? '' : (string) ( isset( $result['message'] ) ? $result['message'] : '' );
		if ( $locked ) {
			$message = isset( $result['message'] ) ? (string) $result['message'] : 'locked';
		}
		if ( $ok && ! empty( $result['warn'] ) && is_array( $result['warn'] ) ) {
			$message = implode( ' ', array_map( 'strval', array_slice( $result['warn'], 0, 5 ) ) );
		}
		$log_status = 'error';
		if ( $locked ) {
			$log_status = 'skipped_locked';
		} elseif ( $ok ) {
			$log_status = ! empty( $result['warn'] ) ? 'warn' : 'success';
		}

		Heb_Product_Publisher_Log::insert(
			[
				'post_id'            => $post_id,
				'post_type'          => isset( $basepayload['post_type'] ) ? (string) $basepayload['post_type'] : get_post_type( $post_id ),
				'post_title'         => isset( $basepayload['title'] ) ? (string) $basepayload['title'] : get_the_title( $post_id ),
				'site_id'            => isset( $site['id'] ) ? (string) $site['id'] : '',
				'site_label'         => isset( $site['label'] ) ? (string) $site['label'] : '',
				'site_url'           => isset( $site['url'] ) ? (string) $site['url'] : '',
				'target_locale'      => (string) $locale,
				'status'             => $log_status,
				'message'            => $message,
				'remote_post_id'     => $remote_post_id,
				'remote_edit_url'    => $edit_url,
				'translated_strings' => isset( $stats['translated'] ) ? (int) $stats['translated'] : 0,
				'translated_total'   => isset( $stats['strings'] ) ? (int) $stats['strings'] : 0,
				'duration_ms'        => (int) $duration_ms,
			]
		);

		$distributions = get_post_meta( $post_id, '_heb_pp_distributions', true );
		if ( ! is_array( $distributions ) ) {
			$distributions = [];
		}
		$site_id = isset( $site['id'] ) ? (string) $site['id'] : '';
		if ( '' !== $site_id ) {
			$dist_status = $locked ? 'skipped_locked' : ( $ok ? ( ! empty( $result['warn'] ) ? 'warn' : 'success' ) : 'error' );
			$distributions[ $site_id ] = [
				'label'          => isset( $site['label'] ) ? (string) $site['label'] : '',
				'url'            => isset( $site['url'] ) ? (string) $site['url'] : '',
				'locale'         => (string) $locale,
				'last_status'    => $dist_status,
				'last_message'   => $message,
				'last_sent_at'   => time(),
				'remote_post_id' => $remote_post_id,
				'remote_edit_url'=> $edit_url,
				'pending_media'  => isset( $result['pending_media'] ) ? (int) $result['pending_media'] : 0,
			];
			update_post_meta( $post_id, '_heb_pp_distributions', $distributions );
		}
	}
}
