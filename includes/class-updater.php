<?php
/**
 * 自动更新器：把 GitHub Releases 接入 WordPress 插件更新系统。
 *
 * 使用方式（任选其一）：
 *  1) 在 wp-config.php：
 *       define( 'HEB_PP_GITHUB_REPO',  'owner/repo' );        // 例：hongbo/heb-product-publisher
 *       define( 'HEB_PP_GITHUB_TOKEN', 'ghp_xxx' );           // 私有仓库或避免速率限制（可选）
 *  2) 或在「设置 → HEB Publisher」填写 GitHub 仓库（owner/repo）。
 *
 * 工作流：
 *  - 你在 GitHub 打一个 Release（推荐 tag = 插件版本，如 v2.1.0 或 2.1.0）
 *  - 建议在 Release 里上传名为 heb-product-publisher.zip 的资产；
 *    若没有，将回落使用 GitHub 的 zipball_url。
 *  - 所有装了本插件的站点会在 12h 内（或下一次 WP 主动拉更新）检测到，
 *    出现在「仪表盘 → 更新」和「插件」页的一键更新入口。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Updater {

	const TRANSIENT_KEY = 'heb_pp_gh_release_v1';
	const SLUG          = 'heb-product-publisher';

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
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_filter( 'upgrader_pre_download', [ $this, 'authenticate_download' ], 10, 3 );
	}

	/**
	 * @return string
	 */
	public static function repo() {
		if ( defined( 'HEB_PP_GITHUB_REPO' ) && is_string( HEB_PP_GITHUB_REPO ) && '' !== HEB_PP_GITHUB_REPO ) {
			return HEB_PP_GITHUB_REPO;
		}
		$v = get_option( 'heb_pp_github_repo', '' );
		return is_string( $v ) ? trim( $v ) : '';
	}

	/**
	 * @return string
	 */
	public static function token() {
		if ( defined( 'HEB_PP_GITHUB_TOKEN' ) && is_string( HEB_PP_GITHUB_TOKEN ) && '' !== HEB_PP_GITHUB_TOKEN ) {
			return HEB_PP_GITHUB_TOKEN;
		}
		return '';
	}

	/**
	 * 获取并缓存 latest release 信息。
	 *
	 * @param bool $force Skip cache if true.
	 * @return array<string,string>|null
	 */
	public function get_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$repo = self::repo();
		if ( '' === $repo || false === strpos( $repo, '/' ) ) {
			return null;
		}

		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'heb-product-publisher-updater',
		];
		$token = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			[ 'timeout' => 15, 'headers' => $headers ]
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			set_transient( self::TRANSIENT_KEY, [ 'error' => 'HTTP ' . $code ], HOUR_IN_SECONDS );
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['tag_name'] ) ) {
			return null;
		}

		$zip = '';
		if ( ! empty( $json['assets'] ) && is_array( $json['assets'] ) ) {
			foreach ( $json['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
				if ( '' !== $url && preg_match( '/\.zip$/i', $name ) ) {
					$zip = $url;
					break;
				}
			}
		}
		if ( '' === $zip && ! empty( $json['zipball_url'] ) ) {
			$zip = (string) $json['zipball_url'];
		}

		$info = [
			'version'   => ltrim( (string) $json['tag_name'], 'vV' ),
			'zip_url'   => $zip,
			'homepage'  => isset( $json['html_url'] ) ? (string) $json['html_url'] : ( 'https://github.com/' . $repo ),
			'changelog' => isset( $json['body'] ) ? (string) $json['body'] : '',
			'published' => isset( $json['published_at'] ) ? (string) $json['published_at'] : '',
		];

		set_transient( self::TRANSIENT_KEY, $info, 12 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * 清空缓存（Settings 页“立即检查”使用）。
	 */
	public static function purge_cache() {
		delete_transient( self::TRANSIENT_KEY );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * 把本插件的升级信息注入到 WP 插件更新列表。
	 *
	 * @param object|false $transient WP update transient.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->get_release();
		if ( ! $info || empty( $info['version'] ) || empty( $info['zip_url'] ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( HEB_PP_FILE );
		$current     = HEB_PP_VERSION;

		$item = (object) [
			'id'           => $plugin_file,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'new_version'  => $info['version'],
			'url'          => $info['homepage'],
			'package'      => $info['zip_url'],
			'icons'        => [],
			'banners'      => [],
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '7.4',
			'compatibility' => new \stdClass(),
		];

		if ( version_compare( $info['version'], $current, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = [];
			}
			$transient->response[ $plugin_file ] = $item;
			unset( $transient->no_update[ $plugin_file ] );
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$item->new_version                    = $current;
			$transient->no_update[ $plugin_file ] = $item;
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * 插件详情弹窗（"View details"）。
	 *
	 * @param mixed                 $result WP value.
	 * @param string                $action WP action.
	 * @param object                $args   Args.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$info = $this->get_release();
		if ( ! $info ) {
			return $result;
		}

		$changelog = '';
		if ( ! empty( $info['changelog'] ) ) {
			$changelog = '<pre style="white-space:pre-wrap;font-family:inherit">' . esc_html( (string) $info['changelog'] ) . '</pre>';
		}
		return (object) [
			'name'          => 'HEB Product Publisher',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'HEB',
			'homepage'      => $info['homepage'],
			'requires'      => '6.0',
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'last_updated'  => $info['published'],
			'sections'      => [
				'description' => __( '一体化产品多站点分发 + OpenRouter 翻译插件。同一插件在主站作为 Hub，在语言站作为 Receiver。', 'heb-product-publisher' ),
				'changelog'   => $changelog ? $changelog : __( '暂无 release 说明。', 'heb-product-publisher' ),
			],
			'download_link' => $info['zip_url'],
		];
	}

	/**
	 * 对 GitHub 私库 zipball/asset 需要 token：
	 * 在 WP 下载前，通过 download_url 自行 GET（带 Authorization 头），再把临时文件交回给升级器。
	 *
	 * @param bool|string|\WP_Error $reply    默认 false。
	 * @param string                $package  Package URL.
	 * @param \WP_Upgrader          $upgrader Upgrader.
	 * @return bool|string|\WP_Error
	 */
	public function authenticate_download( $reply, $package, $upgrader ) {
		$token = self::token();
		if ( '' === $token ) {
			return $reply;
		}
		if ( ! is_string( $package ) || false === strpos( $package, 'api.github.com' ) && false === strpos( $package, 'github.com' ) ) {
			return $reply;
		}
		if ( false === strpos( $package, self::SLUG ) && false === strpos( $package, '/repos/' . self::repo() . '/' ) && false === strpos( $package, '://' . self::repo() ) ) {
			// 不是本插件的包，跳过。
			if ( false === strpos( $package, self::repo() ) ) {
				return $reply;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url(
			$package,
			300,
			false,
			[
				'Authorization' => 'Bearer ' . $token,
				'User-Agent'    => 'heb-product-publisher-updater',
				'Accept'        => 'application/octet-stream',
			]
		);
		return $tmp;
	}

	/**
	 * GitHub zipball 解压出来的目录是 "owner-repo-sha/"，需要改成 "heb-product-publisher/"，
	 * 否则升级完成后 WP 找不到插件入口文件，会提示已失活。
	 *
	 * @param string        $source        Extracted source dir.
	 * @param string        $remote_source Parent dir.
	 * @param \WP_Upgrader  $upgrader      Upgrader.
	 * @param array         $hook_extra    Upgrade context.
	 * @return string|\WP_Error
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( ! is_object( $upgrader ) || empty( $source ) || empty( $remote_source ) ) {
			return $source;
		}
		$plugin_file = plugin_basename( HEB_PP_FILE );
		$is_ours     = false;
		if ( ! empty( $hook_extra['plugin'] ) && (string) $hook_extra['plugin'] === $plugin_file ) {
			$is_ours = true;
		}
		if ( ! $is_ours && false !== strpos( (string) $source, self::SLUG ) ) {
			$is_ours = false; // 已经叫对了，不需要改。
		}
		if ( ! $is_ours ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG;
		if ( rtrim( $source, '/' ) === rtrim( $desired, '/' ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( rtrim( $source, '/' ), $desired, true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}
}
