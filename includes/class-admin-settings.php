<?php
/**
 * Settings page：Receiver / Hub（OpenRouter）/ Remote Sites。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 设置 → HEB Publisher
 */
class Heb_Product_Publisher_Admin_Settings {

	const OPT_RECEIVER_SECRET  = 'heb_publisher_receiver_secret';
	const OPT_SOURCE_LOCALE    = 'heb_pp_source_locale';
	const OPT_OPENROUTER_KEY   = 'heb_pp_openrouter_key';
	const OPT_OPENROUTER_MODEL = 'heb_pp_openrouter_model';
	const OPT_REMOTE_SITES     = 'heb_pp_remote_sites';
	const OPT_GITHUB_REPO      = 'heb_pp_github_repo';

	const DEFAULT_MODEL = 'openai/gpt-4o-mini';

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
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_heb_pp_check_updates', [ $this, 'handle_check_updates' ] );
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
			self::OPT_REMOTE_SITES,
			[ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_remote_sites' ], 'default' => [] ]
		);
		register_setting(
			'heb_pp_settings',
			self::OPT_GITHUB_REPO,
			[ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_github_repo' ], 'default' => '' ]
		);
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
		$info = Heb_Product_Publisher_Updater::instance()->get_release( true );

		$msg = $info && ! empty( $info['version'] )
			? sprintf(
				/* translators: %s: remote version */
				__( '最新 Release 版本为 %s。', 'heb-product-publisher' ),
				$info['version']
			)
			: __( '无法获取更新信息，请检查 GitHub 仓库配置或 Token。', 'heb-product-publisher' );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'heb-product-publisher', 'heb_pp_update_check' => rawurlencode( $msg ) ],
				admin_url( 'options-general.php' )
			)
		);
		exit;
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
			$strategy = isset( $row['slug_strategy'] ) ? sanitize_key( (string) $row['slug_strategy'] ) : 'localized';
			if ( ! in_array( $strategy, [ 'source', 'localized' ], true ) ) {
				$strategy = 'localized';
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
			];
		}
		return $out;
	}

	public function add_menu() {
		add_options_page(
			__( 'HEB Publisher', 'heb-product-publisher' ),
			__( 'HEB Publisher', 'heb-product-publisher' ),
			'manage_options',
			'heb-product-publisher',
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
		$sites           = self::remote_sites();
		$gh_repo         = (string) get_option( self::OPT_GITHUB_REPO, '' );
		$gh_from_config  = defined( 'HEB_PP_GITHUB_REPO' ) && is_string( HEB_PP_GITHUB_REPO ) && '' !== HEB_PP_GITHUB_REPO;

		?>
		<div class="wrap heb-pp-settings">
			<h1><?php esc_html_e( 'HEB Publisher', 'heb-product-publisher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '本插件在主站与语言站安装同一份；主站作为 Hub 分发，语言站作为 Receiver 接收。', 'heb-product-publisher' ); ?>
			</p>

			<form method="post" action="options.php" id="heb-pp-settings-form">
				<?php settings_fields( 'heb_pp_settings' ); ?>

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
							<p class="description"><?php esc_html_e( '如 openai/gpt-4o-mini、anthropic/claude-3-haiku、google/gemini-2.0-flash-001。', 'heb-product-publisher' ); ?></p>
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
							<th style="width:80px"></th>
						</tr>
					</thead>
					<tbody id="heb-pp-sites-body">
						<?php if ( empty( $sites ) ) : ?>
							<?php $this->render_site_row( [ 'id' => '', 'label' => '', 'url' => '', 'receiver_secret' => '', 'locale_override' => '', 'slug_strategy' => 'localized' ], 0 ); ?>
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

				<h2 class="title"><?php esc_html_e( '④ 自动更新（GitHub Releases）', 'heb-product-publisher' ); ?></h2>
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
								<?php esc_html_e( '立即检查更新', 'heb-product-publisher' ); ?>
							</a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( '打开「插件」页', 'heb-product-publisher' ); ?></a>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<template id="heb-pp-site-row-tpl">
				<?php $this->render_site_row( [ 'id' => '', 'label' => '', 'url' => '', 'receiver_secret' => '', 'locale_override' => '', 'slug_strategy' => 'localized' ], '__INDEX__' ); ?>
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
		$strategy = isset( $site['slug_strategy'] ) && in_array( (string) $site['slug_strategy'], [ 'source', 'localized' ], true )
			? (string) $site['slug_strategy']
			: 'localized';
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
					<option value="localized" <?php selected( $strategy, 'localized' ); ?>><?php esc_html_e( '本地化（推荐）', 'heb-product-publisher' ); ?></option>
					<option value="source" <?php selected( $strategy, 'source' ); ?>><?php esc_html_e( '沿用源站英文 slug', 'heb-product-publisher' ); ?></option>
				</select>
				<p class="description" style="margin:4px 0 0;font-size:11px;">
					<?php esc_html_e( '"本地化"：目标站根据翻译后标题生成 slug（SEO 更友好）；"源站"：保持英文 URL。', 'heb-product-publisher' ); ?>
				</p>
			</td>
			<td>
				<button type="button" class="button heb-pp-remove-site"><?php esc_html_e( '删除', 'heb-product-publisher' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
