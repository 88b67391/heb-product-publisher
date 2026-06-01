<?php
/**
 * Settings page：HEB 分发 → 设置。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Admin_Settings {

	const OPT_RECEIVER_SECRET  = 'heb_publisher_receiver_secret';
	const OPT_SOURCE_LOCALE    = 'heb_pp_source_locale';
	const OPT_OPENROUTER_KEY   = 'heb_pp_openrouter_key';
	const OPT_OPENROUTER_MODEL = 'heb_pp_openrouter_model';
	const OPT_TRANSLATOR_PROFILE = 'heb_pp_translator_profile';
	const OPT_REMOTE_SITES     = 'heb_pp_remote_sites';
	const OPT_GITHUB_REPO      = 'heb_pp_github_repo';
	const OPT_HREFLANG_ENABLED = 'heb_pp_hreflang_enabled';
	const OPT_HREFLANG_XDEFAULT = 'heb_pp_hreflang_xdefault';
	const OPT_SITE_ROLE        = 'heb_pp_site_role';

	const ROLE_HUB      = 'hub';
	const ROLE_RECEIVER = 'receiver';
	const ROLE_AUTO     = 'auto';

	const DEFAULT_MODEL              = 'openai/gpt-4o-mini';
	const PROFILE_QUALITY            = 'quality';
	const PROFILE_SPEED              = 'speed';
	const DEFAULT_TRANSLATOR_PROFILE = self::PROFILE_QUALITY;
	const DEFAULT_HREFLANG_XDEFAULT  = 'en';
	const DEFAULT_SITE_ROLE          = self::ROLE_AUTO;

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
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ], 10 );
		add_action( 'admin_post_heb_pp_check_updates', [ $this, 'handle_check_updates' ] );
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_check_updates' ], 90 );
		add_action( 'init', [ $this, 'apply_translator_profile' ], 1 );
	}

	/**
	 * OpenRouter key（wp-config 优先）。
	 *
	 * @return string
	 */
	public static function openrouter_key() {
		if ( defined( 'HEB_PP_OPENROUTER_API_KEY' ) && is_string( HEB_PP_OPENROUTER_API_KEY ) && '' !== HEB_PP_OPENROUTER_API_KEY ) {
			return HEB_PP_OPENROUTER_API_KEY;
		}
		$v = get_option( self::OPT_OPENROUTER_KEY, '' );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * @return string
	 */
	public static function openrouter_model() {
		$v = get_option( self::OPT_OPENROUTER_MODEL, self::DEFAULT_MODEL );
		return is_string( $v ) && '' !== $v ? $v : self::DEFAULT_MODEL;
	}

	/**
	 * 翻译策略：quality = 整段翻译 + 失败不写入；speed = HTML 切片 + 更快模型。
	 *
	 * @return string quality|speed
	 */
	public static function translator_profile() {
		$v = get_option( self::OPT_TRANSLATOR_PROFILE, self::DEFAULT_TRANSLATOR_PROFILE );
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
		if ( in_array( $v, [ self::PROFILE_QUALITY, self::PROFILE_SPEED ], true ) ) {
			return $v;
		}
		return self::DEFAULT_TRANSLATOR_PROFILE;
	}

	/**
	 * @return bool
	 */
	public static function is_quality_translator() {
		return self::PROFILE_QUALITY === self::translator_profile();
	}

	/**
	 * 质量优先：更长超时、更高 max_tokens、不预切片 HTML、全局 strict。
	 *
	 * @return void
	 */
	public function apply_translator_profile() {
		if ( ! self::is_quality_translator() ) {
			return;
		}
		add_filter(
			'heb_pp_translator_http_timeout',
			static function () {
				return 1200;
			}
		);
		add_filter(
			'heb_pp_translator_max_retries',
			static function () {
				return 3;
			}
		);
		add_filter(
			'heb_pp_translator_batch_char_limit',
			static function () {
				return 2000;
			}
		);
		add_filter(
			'heb_pp_translator_solo_char_limit',
			static function () {
				return 1600;
			}
		);
		add_filter(
			'heb_pp_translator_max_tokens',
			static function ( $n, $len ) {
				$est = (int) ceil( (int) $len * 2.8 / 3 );
				return max( (int) $n, 16384, min( 32768, $est ) );
			},
			10,
			2
		);
	}

	/**
	 * @return bool
	 */
	public static function hreflang_enabled() {
		$v = get_option( self::OPT_HREFLANG_ENABLED, '1' );
		return '1' === (string) $v;
	}

	/**
	 * x-default 对应的 hreflang 语言代码（规范化后）。
	 *
	 * @return string
	 */
	public static function hreflang_xdefault_lang() {
		$raw = (string) get_option( self::OPT_HREFLANG_XDEFAULT, self::DEFAULT_HREFLANG_XDEFAULT );
		if ( '' === trim( $raw ) ) {
			$raw = self::DEFAULT_HREFLANG_XDEFAULT;
		}
		if ( class_exists( 'Heb_Product_Publisher_Hreflang' ) ) {
			return Heb_Product_Publisher_Hreflang::normalize_lang( $raw );
		}
		return strtolower( trim( $raw ) );
	}

	/**
	 * 本站显式声明的角色：hub / receiver / auto。
	 *
	 * auto 时：按配置自动推断（Hub = 有远端站点 + OpenRouter key；Receiver = 有 receiver_secret）。
	 * hub / receiver 时：完全按显式选择启用 / 隐藏对应能力，避免分站被误配成 Hub 造成反向污染。
	 *
	 * 可通过 wp-config 常量 `HEB_PP_SITE_ROLE` 强制覆盖（部署时锁死角色）。
	 *
	 * @return string  one of: hub / receiver / auto.
	 */
	public static function site_role() {
		if ( defined( 'HEB_PP_SITE_ROLE' ) && is_string( HEB_PP_SITE_ROLE ) ) {
			$forced = strtolower( trim( HEB_PP_SITE_ROLE ) );
			if ( in_array( $forced, [ self::ROLE_HUB, self::ROLE_RECEIVER, self::ROLE_AUTO ], true ) ) {
				return $forced;
			}
		}
		$v = get_option( self::OPT_SITE_ROLE, self::DEFAULT_SITE_ROLE );
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
		if ( ! in_array( $v, [ self::ROLE_HUB, self::ROLE_RECEIVER, self::ROLE_AUTO ], true ) ) {
			return self::DEFAULT_SITE_ROLE;
		}
		return $v;
	}

	/**
	 * 当前是否启用 Hub 能力（分发 metabox、远端站点列表、Bootstrap、Dashboard、批量分发）。
	 *
	 * @return bool
	 */
	public static function is_hub_mode() {
		$role = self::site_role();
		if ( self::ROLE_HUB === $role ) {
			return true;
		}
		if ( self::ROLE_RECEIVER === $role ) {
			return false;
		}
		// auto：有远端站点（不管 OpenRouter key 是否填，至少能跑无翻译分发）即视为 Hub。
		return ! empty( self::remote_sites() );
	}

	/**
	 * 当前是否启用 Receiver 能力（REST 端点 /import-* / /sync-* / /site-info / /lookup-by-source）。
	 *
	 * @return bool
	 */
	public static function is_receiver_mode() {
		$role = self::site_role();
		if ( self::ROLE_RECEIVER === $role ) {
			return true;
		}
		if ( self::ROLE_HUB === $role ) {
			return false;
		}
		// auto：有 receiver_secret 才启用接收端，避免空 secret 暴露公开路由。
		if ( defined( 'HEB_PUBLISHER_RECEIVER_SECRET' ) && is_string( HEB_PUBLISHER_RECEIVER_SECRET ) && '' !== HEB_PUBLISHER_RECEIVER_SECRET ) {
			return true;
		}
		$secret = get_option( self::OPT_RECEIVER_SECRET, '' );
		return is_string( $secret ) && '' !== $secret;
	}

	/**
	 * @return string
	 */
	public static function source_locale() {
		$v = get_option( self::OPT_SOURCE_LOCALE, '' );
		if ( is_string( $v ) && '' !== $v ) {
			return $v;
		}
		$wp_locale = get_locale();
		return $wp_locale ? $wp_locale : 'en_US';
	}

	/**
	 * @return array<int, array<string,string>>
	 */
	public static function remote_sites() {
		$v = get_option( self::OPT_REMOTE_SITES, [] );
		return is_array( $v ) ? array_values( $v ) : [];
	}

	/**
	 * 按 ID 查询远端站点配置。
	 *
	 * @param string $id Site id.
	 * @return array<string,string>|null
	 */
	public static function get_site( $id ) {
		foreach ( self::remote_sites() as $site ) {
			if ( isset( $site['id'] ) && $site['id'] === $id ) {
				return $site;
			}
		}
		return null;
	}

	public function register_settings() {
		register_setting(
			'heb_pp_settings',
			self::OPT_RECEIVER_SECRET,
			[ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_SOURCE_LOCALE,
			[ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_locale' ], 'default' => '' ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_OPENROUTER_KEY,
			[ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_OPENROUTER_MODEL,
			[ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => self::DEFAULT_MODEL ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_TRANSLATOR_PROFILE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_translator_profile' ],
				'default'           => self::DEFAULT_TRANSLATOR_PROFILE,
			]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_REMOTE_SITES,
			[ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_remote_sites' ], 'default' => [] ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_GITHUB_REPO,
			[ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_github_repo' ], 'default' => '' ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_HREFLANG_ENABLED,
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return '1' === (string) $v ? '1' : '0';
				},
				'default'           => '1',
			]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_HREFLANG_XDEFAULT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_locale' ],
				'default'           => self::DEFAULT_HREFLANG_XDEFAULT,
			]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_SITE_ROLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_site_role' ],
				'default'           => self::DEFAULT_SITE_ROLE,
			]
		);
	}

	/**
	 * @param mixed $raw Raw posted value.
	 * @return string
	 */
	public function sanitize_site_role( $raw ) {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		if ( in_array( $raw, [ self::ROLE_HUB, self::ROLE_RECEIVER, self::ROLE_AUTO ], true ) ) {
			return $raw;
		}
		return self::DEFAULT_SITE_ROLE;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public function sanitize_translator_profile( $raw ) {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		if ( in_array( $raw, [ self::PROFILE_QUALITY, self::PROFILE_SPEED ], true ) ) {
			return $raw;
		}
		return self::DEFAULT_TRANSLATOR_PROFILE;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public function sanitize_github_repo( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#(?:github\.com/)?([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+)#', $raw, $m ) ) {
			return $m[1] . '/' . $m[2];
		}
		return '';
	}

	/**
	 * admin-post.php?action=heb_pp_check_updates
	 */
	public function handle_check_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'heb-product-publisher' ) );
		}
		check_admin_referer( 'heb_pp_check_updates' );

		Heb_Product_Publisher_Updater::purge_cache();
		delete_site_transient( 'update_themes' );

		if ( ! function_exists( 'wp_update_plugins' ) || ! function_exists( 'wp_update_themes' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();
		wp_update_themes();

		$info = Heb_Product_Publisher_Updater::instance()->get_release( true );

		$msg = $info && ! empty( $info['version'] )
			? sprintf(
				/* translators: %s: remote version */
				__( '已触发插件+主题更新检查。插件最新 Release 版本为 %s。', 'heb-product-publisher' ),
				$info['version']
			)
			: __( '已触发插件+主题更新检查；但插件 Release 信息获取失败，请检查 GitHub 仓库配置或 Token。', 'heb-product-publisher' );

		wp_safe_redirect(
			add_query_arg(
				'heb_pp_update_check',
				rawurlencode( $msg ),
				Heb_Product_Publisher_Admin_Menu::url()
			)
		);
		exit;
	}

	/**
	 * 在顶部管理条加“一键检查插件+主题更新”入口。
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public function add_admin_bar_check_updates( $admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$href = wp_nonce_url( admin_url( 'admin-post.php?action=heb_pp_check_updates' ), 'heb_pp_check_updates' );
		$admin_bar->add_node(
			[
				'id'    => 'heb-pp-check-all-updates',
				'title' => esc_html__( '检查插件+主题更新', 'heb-product-publisher' ),
				'href'  => $href,
				'meta'  => [
					'title' => esc_attr__( '一键触发 WordPress 插件和主题更新检查', 'heb-product-publisher' ),
				],
			]
		);
	}

	/**
	 * @param mixed $raw Raw locale string.
	 * @return string
	 */
	public function sanitize_locale( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
	}

	/**
	 * @param mixed $raw Raw posted rows.
	 * @return array<int, array<string,string>>
	 */
	public function sanitize_remote_sites( $raw ) {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label    = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$url      = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			$secret   = isset( $row['receiver_secret'] ) ? sanitize_text_field( (string) $row['receiver_secret'] ) : '';
			$locale   = isset( $row['locale_override'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $row['locale_override'] ) : '';
			$strategy = isset( $row['slug_strategy'] ) ? sanitize_key( (string) $row['slug_strategy'] ) : self::default_slug_strategy();
			if ( ! in_array( $strategy, [ 'source', 'localized' ], true ) ) {
				$strategy = self::default_slug_strategy();
			}
			$timeout = isset( $row['timeout'] ) ? (int) $row['timeout'] : 0;
			if ( $timeout < 30 || $timeout > 1200 ) {
				$timeout = 0;
			}
			$id     = isset( $row['id'] ) && is_string( $row['id'] ) && '' !== $row['id']
				? preg_replace( '/[^a-z0-9]/', '', strtolower( $row['id'] ) )
				: '';

			if ( '' === $url ) {
				continue;
			}
			if ( '' === $id ) {
				$id = substr( md5( $url . microtime( true ) . wp_generate_password( 6, false ) ), 0, 10 );
			}
			$url = rtrim( $url, '/' );

			$out[] = [
				'id'              => $id,
				'label'           => '' !== $label ? $label : $url,
				'url'             => $url,
				'receiver_secret' => $secret,
				'locale_override' => (string) $locale,
				'slug_strategy'   => $strategy,
				'timeout'         => $timeout,
			];
		}
		return $out;
	}

	/**
	 * 默认 slug 策略：沿用源站英文 slug，多语言站 URL 一致。
	 *
	 * @return string source|localized
	 */
	public static function default_slug_strategy() {
		return (string) apply_filters( 'heb_pp_default_slug_strategy', 'source' );
	}

	/**
	 * 读取单个远端站点的 slug 策略。
	 *
	 * @param array<string,mixed> $site Site row.
	 * @return string source|localized
	 */
	public static function slug_strategy_for_site( array $site ) {
		$strategy = isset( $site['slug_strategy'] ) ? sanitize_key( (string) $site['slug_strategy'] ) : '';
		if ( ! in_array( $strategy, [ 'source', 'localized' ], true ) ) {
			$strategy = self::default_slug_strategy();
		}
		return (string) apply_filters( 'heb_pp_site_slug_strategy', $strategy, $site );
	}

	/**
	 * 远端站点的 import-product 请求超时秒数；0/未设 → 默认。
	 *
	 * @param array<string,mixed> $site Site row.
	 * @return int
	 */
	public static function site_timeout( array $site ) {
		$t = isset( $site['timeout'] ) ? (int) $site['timeout'] : 0;
		if ( $t < 30 || $t > 1200 ) {
			if ( self::is_quality_translator() ) {
				return 900;
			}
			return 300;
		}
		return $t;
	}

	public function add_menu() {
		add_menu_page(
			__( 'HEB 分发', 'heb-product-publisher' ),
			__( 'HEB 分发', 'heb-product-publisher' ),
			'manage_options',
			Heb_Product_Publisher_Admin_Menu::PARENT_SLUG,
			[ $this, 'render_page' ],
			'dashicons-networking',
			81
		);
		add_submenu_page(
			Heb_Product_Publisher_Admin_Menu::PARENT_SLUG,
			__( 'HEB Publisher 设置', 'heb-product-publisher' ),
			__( '设置', 'heb-product-publisher' ),
			'manage_options',
			Heb_Product_Publisher_Admin_Menu::PARENT_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$receiver_from_config = defined( 'HEB_PUBLISHER_RECEIVER_SECRET' )
			&& is_string( HEB_PUBLISHER_RECEIVER_SECRET )
			&& '' !== HEB_PUBLISHER_RECEIVER_SECRET;

		$or_from_config = defined( 'HEB_PP_OPENROUTER_API_KEY' )
			&& is_string( HEB_PP_OPENROUTER_API_KEY )
			&& '' !== HEB_PP_OPENROUTER_API_KEY;

		$receiver_secret = (string) get_option( self::OPT_RECEIVER_SECRET, '' );
		$source_locale   = self::source_locale();
		$or_key          = (string) get_option( self::OPT_OPENROUTER_KEY, '' );
		$or_model        = self::openrouter_model();
		$translator_profile = self::translator_profile();
		$sites           = self::remote_sites();
		$gh_repo         = (string) get_option( self::OPT_GITHUB_REPO, '' );
		$gh_from_config  = defined( 'HEB_PP_GITHUB_REPO' ) && is_string( HEB_PP_GITHUB_REPO ) && '' !== HEB_PP_GITHUB_REPO;

		$hreflang_on        = self::hreflang_enabled();
		$hreflang_xdefault  = (string) get_option( self::OPT_HREFLANG_XDEFAULT, self::DEFAULT_HREFLANG_XDEFAULT );

		$site_role        = self::site_role();
		$role_forced      = defined( 'HEB_PP_SITE_ROLE' );
		$is_hub_mode      = self::is_hub_mode();
		$is_receiver_mode = self::is_receiver_mode();
		$show_hub         = self::ROLE_RECEIVER !== $site_role;
		$show_receiver    = self::ROLE_HUB !== $site_role;

		?>
		<div class="wrap heb-pp-settings">
			<h1><?php esc_html_e( 'HEB Publisher', 'heb-product-publisher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '本插件在主站与语言站安装同一份；主站作为 Hub 分发，语言站作为 Receiver 接收。', 'heb-product-publisher' ); ?>
			</p>

			<form method="post" action="options.php" id="heb-pp-settings-form">
				<?php settings_fields( 'heb_pp_settings' ); ?>

				<h2 class="title"><?php esc_html_e( '⓪ 本站角色', 'heb-product-publisher' ); ?></h2>
				<?php if ( $role_forced ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								/* translators: %s: role code from wp-config */
								esc_html__( '当前角色由 wp-config.php 中的 HEB_PP_SITE_ROLE 强制锁定为 %s。', 'heb-product-publisher' ),
								'<code>' . esc_html( $site_role ) . '</code>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '角色', 'heb-product-publisher' ); ?></th>
						<td>
							<fieldset <?php echo $role_forced ? 'disabled' : ''; ?>>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPT_SITE_ROLE ); ?>" value="<?php echo esc_attr( self::ROLE_AUTO ); ?>" <?php checked( $site_role, self::ROLE_AUTO ); ?> />
									<?php esc_html_e( '自动（按配置推断，向后兼容）', 'heb-product-publisher' ); ?>
									<span class="description">
										&middot;
										<?php
										if ( $is_hub_mode && $is_receiver_mode ) {
											esc_html_e( '当前推断：Hub + Receiver', 'heb-product-publisher' );
										} elseif ( $is_hub_mode ) {
											esc_html_e( '当前推断：Hub（主站）', 'heb-product-publisher' );
										} elseif ( $is_receiver_mode ) {
											esc_html_e( '当前推断：Receiver（分站）', 'heb-product-publisher' );
										} else {
											esc_html_e( '当前推断：未配置（既不分发也不接收）', 'heb-product-publisher' );
										}
										?>
									</span>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPT_SITE_ROLE ); ?>" value="<?php echo esc_attr( self::ROLE_HUB ); ?>" <?php checked( $site_role, self::ROLE_HUB ); ?> />
									<?php esc_html_e( 'Hub（主站，仅分发）', 'heb-product-publisher' ); ?>
									<span class="description">&middot; <?php esc_html_e( '显示远端列表 / OpenRouter / Bootstrap / Dashboard / 批量分发；不暴露接收端 REST 路由', 'heb-product-publisher' ); ?></span>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPT_SITE_ROLE ); ?>" value="<?php echo esc_attr( self::ROLE_RECEIVER ); ?>" <?php checked( $site_role, self::ROLE_RECEIVER ); ?> />
									<?php esc_html_e( 'Receiver（分站，仅接收）', 'heb-product-publisher' ); ?>
									<span class="description">&middot; <?php esc_html_e( '仅显示接收密钥；隐藏所有 Hub UI 以防误操作把分站当主站', 'heb-product-publisher' ); ?></span>
								</label>
							</fieldset>
							<p class="description" style="margin-top:8px;">
								<?php esc_html_e( '部署到生产建议显式选择角色（不要 auto），可在 wp-config.php 中定义 HEB_PP_SITE_ROLE 锁死。hreflang 输出与单页 hreflang metabox 在任何角色下都启用。', 'heb-product-publisher' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php if ( $show_receiver ) : ?>
				<h2 class="title"><?php esc_html_e( '① Receiver（本站作为接收端）', 'heb-product-publisher' ); ?></h2>
				<?php if ( $receiver_from_config ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( '当前使用 wp-config.php 中的 HEB_PUBLISHER_RECEIVER_SECRET，下方值仅用于显示，不会生效。', 'heb-product-publisher' ); ?></p>
					</div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="heb_publisher_receiver_secret"><?php esc_html_e( '接收密钥', 'heb-product-publisher' ); ?></label></th>
						<td>
							<input
								type="text"
								id="heb_publisher_receiver_secret"
								name="<?php echo esc_attr( self::OPT_RECEIVER_SECRET ); ?>"
								value="<?php echo esc_attr( $receiver_secret ); ?>"
								class="large-text code"
								autocomplete="off"
								<?php echo $receiver_from_config ? 'readonly disabled' : ''; ?>
							/>
							<p class="description">
								<?php esc_html_e( '主站在「远端站点」里填写的“接收密钥”必须与此一致。留空则不会注册接收端接口。', 'heb-product-publisher' ); ?>
							</p>
							<p>
								<button type="button" class="button" id="heb-pp-gen-secret"><?php esc_html_e( '生成随机密钥', 'heb-product-publisher' ); ?></button>
							</p>
						</td>
					</tr>
				</table>

				<?php endif; // show_receiver ?>

				<?php if ( $show_hub ) : ?>
				<h2 class="title"><?php esc_html_e( '② Hub（主站分发 & 翻译）', 'heb-product-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="heb_pp_source_locale"><?php esc_html_e( '源语言（本站内容语言）', 'heb-product-publisher' ); ?></label></th>
						<td>
							<input
								type="text"
								id="heb_pp_source_locale"
								name="<?php echo esc_attr( self::OPT_SOURCE_LOCALE ); ?>"
								value="<?php echo esc_attr( $source_locale ); ?>"
								class="regular-text code"
								placeholder="en_US"
							/>
							<p class="description"><?php esc_html_e( '示例：en_US / zh_CN / ja。留空则使用 WordPress 当前 locale。', 'heb-product-publisher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="heb_pp_openrouter_key"><?php esc_html_e( 'OpenRouter API Key', 'heb-product-publisher' ); ?></label></th>
						<td>
							<?php if ( $or_from_config ) : ?>
								<input type="text" class="large-text code" value="(from wp-config)" readonly disabled />
								<p class="description"><?php esc_html_e( '使用 wp-config.php 中的 HEB_PP_OPENROUTER_API_KEY。', 'heb-product-publisher' ); ?></p>
							<?php else : ?>
								<input
									type="password"
									id="heb_pp_openrouter_key"
									name="<?php echo esc_attr( self::OPT_OPENROUTER_KEY ); ?>"
									value="<?php echo esc_attr( $or_key ); ?>"
									class="large-text code"
									autocomplete="off"
								/>
								<p class="description"><?php esc_html_e( 'OpenRouter 控制台里的 API Key（sk-or-...）。建议用 wp-config 常量 HEB_PP_OPENROUTER_API_KEY。', 'heb-product-publisher' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '翻译策略', 'heb-product-publisher' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:8px;">
									<input
										type="radio"
										name="<?php echo esc_attr( self::OPT_TRANSLATOR_PROFILE ); ?>"
										value="<?php echo esc_attr( self::PROFILE_QUALITY ); ?>"
										<?php checked( $translator_profile, self::PROFILE_QUALITY ); ?>
									/>
									<strong><?php esc_html_e( '质量优先', 'heb-product-publisher' ); ?></strong>
									<span class="description">
										&middot;
										<?php esc_html_e( '整段 Elementor 内容一次翻译；任一批次失败不写入子站；OpenRouter 超时 1200s。推荐 Claude Opus 4.8。', 'heb-product-publisher' ); ?>
									</span>
								</label>
								<label style="display:block;">
									<input
										type="radio"
										name="<?php echo esc_attr( self::OPT_TRANSLATOR_PROFILE ); ?>"
										value="<?php echo esc_attr( self::PROFILE_SPEED ); ?>"
										<?php checked( $translator_profile, self::PROFILE_SPEED ); ?>
									/>
									<strong><?php esc_html_e( '速度优先', 'heb-product-publisher' ); ?></strong>
									<span class="description">
										&middot;
										<?php esc_html_e( '长 HTML 自动切片；更快更省；适合日常单条分发。推荐 Gemini 2.5 Flash。', 'heb-product-publisher' ); ?>
									</span>
								</label>
							</fieldset>
							<p style="margin-top:10px;margin-bottom:0;">
								<button type="button" class="button button-primary button-small heb-pp-profile-quick" data-profile="<?php echo esc_attr( self::PROFILE_QUALITY ); ?>" data-model="anthropic/claude-opus-4.8">
									<?php esc_html_e( '一键：质量优先 + Opus 4.8', 'heb-product-publisher' ); ?>
								</button>
								<button type="button" class="button button-small heb-pp-profile-quick" data-profile="<?php echo esc_attr( self::PROFILE_SPEED ); ?>" data-model="google/gemini-2.5-flash">
									<?php esc_html_e( '一键：速度优先 + Gemini Flash', 'heb-product-publisher' ); ?>
								</button>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="heb_pp_openrouter_model"><?php esc_html_e( '默认翻译模型', 'heb-product-publisher' ); ?></label></th>
						<td>
							<input
								type="text"
								id="heb_pp_openrouter_model"
								name="<?php echo esc_attr( self::OPT_OPENROUTER_MODEL ); ?>"
								value="<?php echo esc_attr( $or_model ); ?>"
								class="regular-text code"
								placeholder="<?php echo esc_attr( self::DEFAULT_MODEL ); ?>"
							/>
							<p class="description" style="margin-bottom:6px;">
								<?php esc_html_e( '点下方推荐模型一键填入，或手动输入 OpenRouter 模型 ID。', 'heb-product-publisher' ); ?>
							</p>
							<div class="heb-pp-model-picker" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
								<?php
								$recommended = [
									[
										'id'      => 'anthropic/claude-opus-4.8',
										'label'   => 'Claude Opus 4.8',
										'desc'    => __( '质量优先 · 慢·贵·整段翻译（Bootstrap 推荐）', 'heb-product-publisher' ),
										'tone'    => 'quality',
										'profile' => self::PROFILE_QUALITY,
									],
									[
										'id'      => 'google/gemini-2.5-flash',
										'label'   => 'Gemini 2.5 Flash',
										'desc'    => __( '速度优先 · 极快·便宜·日常分发', 'heb-product-publisher' ),
										'tone'    => 'primary',
										'profile' => self::PROFILE_SPEED,
									],
									[
										'id'    => 'openai/gpt-4o-mini',
										'label' => 'GPT-4o mini',
										'desc'  => __( '速度快·便宜·质量稳定', 'heb-product-publisher' ),
										'tone'  => '',
									],
									[
										'id'    => 'anthropic/claude-haiku-4.5',
										'label' => 'Claude Haiku 4.5',
										'desc'  => __( '速度快·中等价格·细节保留好', 'heb-product-publisher' ),
										'tone'  => '',
									],
									[
										'id'    => 'deepseek/deepseek-chat-v3.2',
										'label' => 'DeepSeek V3.2',
										'desc'  => __( '极便宜·中等速度·中文输出佳', 'heb-product-publisher' ),
										'tone'  => '',
									],
									[
										'id'    => 'google/gemini-2.5-pro',
										'label' => 'Gemini 2.5 Pro',
										'desc'  => __( '中速·高质量·价格适中', 'heb-product-publisher' ),
										'tone'  => '',
									],
									[
										'id'    => 'anthropic/claude-sonnet-4.5',
										'label' => 'Claude Sonnet 4.5',
										'desc'  => __( '慢·贵·质量高（Sonnet）', 'heb-product-publisher' ),
										'tone'  => 'warn',
									],
								];
								foreach ( $recommended as $m ) :
									$btn_class = 'button button-small';
									if ( 'primary' === $m['tone'] ) {
										$btn_class = 'button button-primary button-small';
									} elseif ( 'quality' === $m['tone'] ) {
										$btn_class = 'button button-primary button-small';
									}
									?>
									<button
										type="button"
										class="<?php echo esc_attr( $btn_class ); ?> heb-pp-model-quick"
										data-model="<?php echo esc_attr( $m['id'] ); ?>"
										<?php if ( ! empty( $m['profile'] ) ) : ?>
											data-profile="<?php echo esc_attr( (string) $m['profile'] ); ?>"
										<?php endif; ?>
										title="<?php echo esc_attr( $m['desc'] ); ?>"
										style="font-family:monospace;"
									><?php echo esc_html( $m['label'] ); ?></button>
								<?php endforeach; ?>
							</div>
							<p class="description" style="margin-top:8px;font-size:11px;color:#888;">
								<?php
								printf(
									/* translators: %s: link to openrouter models page */
									esc_html__( '完整模型列表见 %s。OpenRouter 余额不够时优先选 Gemini Flash 或 DeepSeek。', 'heb-product-publisher' ),
									'<a href="https://openrouter.ai/models" target="_blank" rel="noopener">openrouter.ai/models</a>'
								);
								?>
							</p>
							<script>
							(function(){
								function setProfile(profile) {
									if (!profile) return;
									document.querySelectorAll('input[name="<?php echo esc_js( self::OPT_TRANSLATOR_PROFILE ); ?>"]').forEach(function(r){
										r.checked = (r.value === profile);
									});
								}
								document.querySelectorAll('.heb-pp-model-quick').forEach(function(btn){
									btn.addEventListener('click', function(e){
										e.preventDefault();
										var input = document.getElementById('heb_pp_openrouter_model');
										if (input) {
											input.value = btn.getAttribute('data-model');
											input.focus();
										}
										setProfile(btn.getAttribute('data-profile'));
									});
								});
								document.querySelectorAll('.heb-pp-profile-quick').forEach(function(btn){
									btn.addEventListener('click', function(e){
										e.preventDefault();
										setProfile(btn.getAttribute('data-profile'));
										var input = document.getElementById('heb_pp_openrouter_model');
										if (input) {
											input.value = btn.getAttribute('data-model');
											input.focus();
										}
									});
								});
							})();
							</script>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( '③ 远端站点列表（Hub 分发目标）', 'heb-product-publisher' ); ?></h2>
				<p class="description"><?php esc_html_e( '每条对应一个已安装本插件并启用 Receiver 的语言站。Locale 留空表示由目标站点自动检测。', 'heb-product-publisher' ); ?></p>

				<table class="widefat fixed heb-pp-sites-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '标签', 'heb-product-publisher' ); ?></th>
							<th><?php esc_html_e( '站点 URL', 'heb-product-publisher' ); ?></th>
							<th><?php esc_html_e( '接收密钥', 'heb-product-publisher' ); ?></th>
							<th style="width:120px"><?php esc_html_e( '目标语言（可选）', 'heb-product-publisher' ); ?></th>
							<th style="width:160px"><?php esc_html_e( 'Slug 策略', 'heb-product-publisher' ); ?></th>
							<th style="width:90px"><?php esc_html_e( '超时（秒）', 'heb-product-publisher' ); ?></th>
							<th style="width:80px"></th>
						</tr>
					</thead>
					<tbody id="heb-pp-sites-body">
						<?php if ( empty( $sites ) ) : ?>
							<?php $this->render_site_row( [ 'id' => '', 'label' => '', 'url' => '', 'receiver_secret' => '', 'locale_override' => '', 'slug_strategy' => Heb_Product_Publisher_Admin_Settings::default_slug_strategy(), 'timeout' => 0 ], 0 ); ?>
						<?php else : ?>
							<?php foreach ( $sites as $i => $site ) : ?>
								<?php $this->render_site_row( $site, $i ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="heb-pp-add-site"><?php esc_html_e( '+ 添加站点', 'heb-product-publisher' ); ?></button>
					<button type="button" class="button" id="heb-pp-test-sites"><?php esc_html_e( '测试全部连接', 'heb-product-publisher' ); ?></button>
				</p>
				<div id="heb-pp-test-result" class="heb-pp-test-result" aria-live="polite"></div>
				<?php endif; // show_hub ?>

				<h2 class="title"><?php esc_html_e( '④ Hreflang（多语言 SEO 标签）', 'heb-product-publisher' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '在前台 <head> 输出 hreflang 标签。数据：产品由分发流程自动维护；页面/文章在编辑界面右侧"跨语言版本（hreflang）"中手动填写。', 'heb-product-publisher' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '启用 hreflang 输出', 'heb-product-publisher' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPT_HREFLANG_ENABLED ); ?>"
									value="1"
									<?php checked( $hreflang_on ); ?>
								/>
								<?php esc_html_e( '在前台单页面 <head> 输出 hreflang 标签', 'heb-product-publisher' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="heb_pp_hreflang_xdefault"><?php esc_html_e( 'x-default 语言代码', 'heb-product-publisher' ); ?></label></th>
						<td>
							<input
								type="text"
								id="heb_pp_hreflang_xdefault"
								name="<?php echo esc_attr( self::OPT_HREFLANG_XDEFAULT ); ?>"
								value="<?php echo esc_attr( $hreflang_xdefault ); ?>"
								class="regular-text code"
								placeholder="en"
							/>
							<p class="description"><?php esc_html_e( '搜索引擎未匹配到用户语言时回退到这个语言版本。一般填主站语言代码（如 en、zh-CN）。', 'heb-product-publisher' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( '⑤ 自动更新（GitHub Releases）', 'heb-product-publisher' ); ?></h2>
				<?php if ( isset( $_GET['heb_pp_update_check'] ) ) : ?>
					<div class="notice notice-info inline"><p><?php echo esc_html( wp_unslash( (string) $_GET['heb_pp_update_check'] ) ); ?></p></div>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( '在 GitHub 打一个 Release 就会自动出现在「仪表盘 → 更新」里，与 WordPress 原生插件更新体验一致。', 'heb-product-publisher' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="heb_pp_github_repo"><?php esc_html_e( 'GitHub 仓库', 'heb-product-publisher' ); ?></label></th>
						<td>
							<?php if ( $gh_from_config ) : ?>
								<input type="text" class="regular-text code" value="<?php echo esc_attr( HEB_PP_GITHUB_REPO ); ?>" readonly disabled />
								<p class="description"><?php esc_html_e( '使用 wp-config.php 中的 HEB_PP_GITHUB_REPO。', 'heb-product-publisher' ); ?></p>
							<?php else : ?>
								<input
									type="text"
									id="heb_pp_github_repo"
									name="<?php echo esc_attr( self::OPT_GITHUB_REPO ); ?>"
									value="<?php echo esc_attr( $gh_repo ); ?>"
									class="regular-text code"
									placeholder="owner/repo"
								/>
								<p class="description">
									<?php esc_html_e( '格式：owner/repo，例：hongbo/heb-product-publisher。私有仓库请在 wp-config.php 定义 HEB_PP_GITHUB_TOKEN。', 'heb-product-publisher' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '当前版本', 'heb-product-publisher' ); ?></th>
						<td>
							<code><?php echo esc_html( HEB_PP_VERSION ); ?></code>
							&nbsp;·&nbsp;
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=heb_pp_check_updates' ), 'heb_pp_check_updates' ) ); ?>">
								<?php esc_html_e( '立即检查更新（插件+主题）', 'heb-product-publisher' ); ?>
							</a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( '打开「插件」页', 'heb-product-publisher' ); ?></a>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<template id="heb-pp-site-row-tpl">
				<?php $this->render_site_row( [ 'id' => '', 'label' => '', 'url' => '', 'receiver_secret' => '', 'locale_override' => '', 'slug_strategy' => Heb_Product_Publisher_Admin_Settings::default_slug_strategy(), 'timeout' => 0 ], '__INDEX__' ); ?>
			</template>
		</div>
		<?php
	}

	/**
	 * 单行（可复用为模板）。
	 *
	 * @param array<string,string> $site Site row.
	 * @param int|string           $idx  Row index (or placeholder).
	 */
	private function render_site_row( $site, $idx ) {
		$base     = self::OPT_REMOTE_SITES . '[' . $idx . ']';
		$strategy = Heb_Product_Publisher_Admin_Settings::slug_strategy_for_site( is_array( $site ) ? $site : [] );
		?>
		<tr class="heb-pp-site-row">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( isset( $site['id'] ) ? $site['id'] : '' ); ?>" />
				<input type="text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( isset( $site['label'] ) ? $site['label'] : '' ); ?>" placeholder="JP Site" class="regular-text" />
			</td>
			<td>
				<input type="url" name="<?php echo esc_attr( $base ); ?>[url]" value="<?php echo esc_attr( isset( $site['url'] ) ? $site['url'] : '' ); ?>" placeholder="https://jp.example.com" class="regular-text code" />
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $base ); ?>[receiver_secret]" value="<?php echo esc_attr( isset( $site['receiver_secret'] ) ? $site['receiver_secret'] : '' ); ?>" class="regular-text code" autocomplete="off" />
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $base ); ?>[locale_override]" value="<?php echo esc_attr( isset( $site['locale_override'] ) ? $site['locale_override'] : '' ); ?>" placeholder="ja_JP" class="code" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $base ); ?>[slug_strategy]" class="heb-pp-slug-strategy">
					<option value="source" <?php selected( $strategy, 'source' ); ?>><?php esc_html_e( '沿用源站英文 slug（推荐）', 'heb-product-publisher' ); ?></option>
					<option value="localized" <?php selected( $strategy, 'localized' ); ?>><?php esc_html_e( '本地化 slug', 'heb-product-publisher' ); ?></option>
				</select>
				<p class="description" style="margin:4px 0 0;font-size:11px;">
					<?php esc_html_e( '推荐沿用源站英文 slug：各语言站 URL 一致，便于 hreflang 与跨站跳转。本地化则按翻译标题生成 slug。', 'heb-product-publisher' ); ?>
				</p>
			</td>
			<td>
				<input
					type="number"
					name="<?php echo esc_attr( $base ); ?>[timeout]"
					value="<?php echo esc_attr( isset( $site['timeout'] ) && (int) $site['timeout'] > 0 ? (int) $site['timeout'] : '' ); ?>"
					placeholder="300"
					min="30"
					max="1200"
					step="10"
					class="small-text"
				/>
				<p class="description" style="margin:4px 0 0;font-size:11px;">
					<?php esc_html_e( '质量优先默认 900s；单页 Elementor 可设 1200', 'heb-product-publisher' ); ?>
				</p>
			</td>
			<td>
				<button type="button" class="button heb-pp-remove-site"><?php esc_html_e( '删除', 'heb-product-publisher' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
