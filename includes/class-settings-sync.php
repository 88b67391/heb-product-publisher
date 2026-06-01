<?php
/**
 * Hub 端：WordPress 全局选项白名单分发。
 *
 * 白名单刻意保守：只同步主站和子站"应该一致"的字段，避免误覆盖子站自己定的（admin_email、
 * siteurl/home、comment_*）。
 *
 * Page references（page_on_front / page_for_posts / elementor_active_kit）转成
 * { source_post_id } token，由 Receiver 在写入前反查本地 post id；找不到则跳过该 option。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Settings_Sync {

	/**
	 * 普通字符串/数字 option 白名单（直接复制原值；blogname/blogdescription 会另外走翻译）。
	 *
	 * @return array<int,string>
	 */
	public static function copy_options() {
		return (array) apply_filters(
			'heb_pp_settings_copy_options',
			[
				'timezone_string',
				'gmt_offset',
				'date_format',
				'time_format',
				'start_of_week',
				'permalink_structure',
				'category_base',
				'tag_base',
				'show_on_front',
				'posts_per_page',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'thumbnail_crop',
				'image_default_link_type',
				'image_default_size',
			]
		);
	}

	/**
	 * 翻译型 option 白名单：value 是用户可见文本，需要走 OpenRouter。
	 *
	 * @return array<int,string>
	 */
	public static function translate_options() {
		return (array) apply_filters(
			'heb_pp_settings_translate_options',
			[
				'blogname',
				'blogdescription',
			]
		);
	}

	/**
	 * 需要按 source_post_id 映射到本地 post 的 option 白名单。
	 *
	 * @return array<int,string>
	 */
	public static function post_ref_options() {
		return (array) apply_filters(
			'heb_pp_settings_post_ref_options',
			[
				'page_on_front',
				'page_for_posts',
				'elementor_active_kit',
			]
		);
	}

	/**
	 * Elementor 全局 option（标量或数组，不走翻译）。
	 *
	 * @return array<int,string>
	 */
	public static function elementor_options() {
		return (array) apply_filters(
			'heb_pp_settings_elementor_options',
			[
				'elementor_cpt_support',
				'elementor_css_print_method',
				'elementor_default_generic_fonts',
				'elementor_disable_color_schemes',
				'elementor_disable_typography_schemes',
				'elementor_container_width',
				'elementor_viewport_lg',
				'elementor_viewport_md',
				'elementor_viewport_sm',
				'elementor_global_image_lightbox',
				'elementor_experiment-container',
			]
		);
	}

	/**
	 * Yoast SEO 全局 option（整包复制，模板内 %%vars%% 跨语言通用）。
	 *
	 * @return array<int,string>
	 */
	public static function yoast_options() {
		return (array) apply_filters(
			'heb_pp_settings_yoast_options',
			[
				'wpseo',
				'wpseo_titles',
				'wpseo_social',
			]
		);
	}

	/**
	 * theme_mod 同步时排除的键（菜单/小工具/附件 id 由专门流程处理）。
	 *
	 * custom_logo / site_icon 走 {@see media_ref_theme_mods()} + media_refs sideload。
	 *
	 * @return array<int,string>
	 */
	public static function theme_mod_exclude_keys() {
		return (array) apply_filters(
			'heb_pp_settings_theme_mod_exclude',
			[
				'nav_menu_locations',
				'sidebars_widgets',
				'custom_logo',
				'site_icon',
			]
		);
	}

	/**
	 * 需要 sideload 附件后再写入的 theme_mod（值为 attachment ID）。
	 *
	 * @return array<int,string>
	 */
	public static function media_ref_theme_mods() {
		return (array) apply_filters(
			'heb_pp_settings_media_ref_theme_mods',
			[
				'custom_logo',
				'site_icon',
			]
		);
	}

	/**
	 * 收集 logo / favicon 等附件引用（URL + 源 attachment id）。
	 *
	 * @return array<string,array{source_attachment_id:int,url:string}>
	 */
	public static function collect_media_refs() {
		$out = [];
		foreach ( self::media_ref_theme_mods() as $mod_key ) {
			$att_id = (int) get_theme_mod( $mod_key );
			if ( $att_id <= 0 && 'site_icon' === $mod_key ) {
				$att_id = (int) get_option( 'site_icon' );
			}
			if ( $att_id <= 0 ) {
				continue;
			}
			$url = (string) wp_get_attachment_url( $att_id );
			if ( '' === $url ) {
				continue;
			}
			$out[ $mod_key ] = [
				'source_attachment_id' => $att_id,
				'url'                  => $url,
			];
		}
		return (array) apply_filters( 'heb_pp_settings_media_refs_payload', $out );
	}

	/**
	 * Bootstrap settings 可单独勾选的分组。
	 *
	 * @return array<string,string> group_key => admin label.
	 */
	public static function settings_groups() {
		return (array) apply_filters(
			'heb_pp_settings_scope_groups',
			[
				'identity'   => __( '站点身份（标题、副标题、Logo、Favicon）', 'heb-product-publisher' ),
				'permalink'  => __( 'Permalinks 与静态首页', 'heb-product-publisher' ),
				'general'    => __( '常规（时区、日期格式、图片尺寸）', 'heb-product-publisher' ),
				'elementor'  => __( 'Elementor 全局设置 + Kit', 'heb-product-publisher' ),
				'yoast'      => __( 'Yoast SEO', 'heb-product-publisher' ),
				'theme_mods' => __( '主题 Customizer（theme_mods）', 'heb-product-publisher' ),
			]
		);
	}

	/**
	 * @return array<int,string>
	 */
	public static function default_settings_groups() {
		return array_keys( self::settings_groups() );
	}

	/**
	 * @param array<string,mixed> $opts Job opts.
	 * @return array<int,string>
	 */
	public static function resolve_settings_groups( array $opts ) {
		if ( ! empty( $opts['scope_settings_groups'] ) && is_array( $opts['scope_settings_groups'] ) ) {
			$allowed = self::default_settings_groups();
			$out     = [];
			foreach ( $opts['scope_settings_groups'] as $group ) {
				$group = sanitize_key( (string) $group );
				if ( in_array( $group, $allowed, true ) ) {
					$out[] = $group;
				}
			}
			return array_values( array_unique( $out ) );
		}
		if ( ! empty( $opts['scope_settings'] ) ) {
			return self::default_settings_groups();
		}
		return [];
	}

	/**
	 * @return array<int,string>
	 */
	private static function permalink_copy_options() {
		return [
			'permalink_structure',
			'category_base',
			'tag_base',
			'show_on_front',
		];
	}

	/**
	 * 构造 payload。
	 *
	 * @param array<int,string> $groups Empty = all groups.
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $groups = [] ) {
		if ( empty( $groups ) ) {
			$groups = self::default_settings_groups();
		}
		$pick = array_flip( $groups );

		$payload = [
			'source_site'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_locale' => Heb_Product_Publisher_Admin_Settings::source_locale(),
			'copy'          => [],
			'translate'     => [],
			'post_refs'     => [],
			'elementor'     => [],
			'yoast'         => [],
			'theme_mods'    => [],
			'media_refs'    => [],
		];

		if ( isset( $pick['general'] ) || isset( $pick['permalink'] ) ) {
			$permalink_opts = array_flip( self::permalink_copy_options() );
			foreach ( self::copy_options() as $opt ) {
				if ( isset( $permalink_opts[ $opt ] ) ) {
					if ( isset( $pick['permalink'] ) ) {
						$payload['copy'][ $opt ] = get_option( $opt );
					}
					continue;
				}
				if ( isset( $pick['general'] ) ) {
					$payload['copy'][ $opt ] = get_option( $opt );
				}
			}
		}

		if ( isset( $pick['identity'] ) ) {
			foreach ( self::translate_options() as $opt ) {
				$v = get_option( $opt );
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$payload['translate'][ $opt ] = $v;
				}
			}
			$payload['media_refs'] = self::collect_media_refs();
		}

		if ( isset( $pick['permalink'] ) || isset( $pick['elementor'] ) ) {
			foreach ( self::post_ref_options() as $opt ) {
				if ( 'elementor_active_kit' === $opt && ! isset( $pick['elementor'] ) ) {
					continue;
				}
				if ( in_array( $opt, [ 'page_on_front', 'page_for_posts' ], true ) && ! isset( $pick['permalink'] ) ) {
					continue;
				}
				$pid = (int) get_option( $opt );
				if ( $pid > 0 ) {
					$payload['post_refs'][ $opt ] = [
						'source_post_id' => $pid,
					];
				}
			}
		}

		if ( isset( $pick['elementor'] ) ) {
			foreach ( self::elementor_options() as $opt ) {
				$v = get_option( $opt );
				if ( null !== $v && false !== $v && '' !== $v ) {
					$payload['elementor'][ $opt ] = $v;
				}
			}
		}

		if ( isset( $pick['yoast'] ) ) {
			foreach ( self::yoast_options() as $opt ) {
				$v = get_option( $opt );
				if ( is_array( $v ) && ! empty( $v ) ) {
					$payload['yoast'][ $opt ] = $v;
				}
			}
		}

		if ( isset( $pick['theme_mods'] ) ) {
			$payload['theme_mods'] = self::collect_theme_mods();
		}

		return (array) apply_filters( 'heb_pp_settings_payload', $payload, $groups );
	}

	/**
	 * 收集当前子主题 theme_mod（白名单排除后整包复制）。
	 *
	 * @return array<string,mixed>
	 */
	public static function collect_theme_mods() {
		$mods    = get_theme_mods();
		$exclude = array_flip( self::theme_mod_exclude_keys() );
		$out     = [];
		if ( ! is_array( $mods ) ) {
			return $out;
		}
		foreach ( $mods as $key => $val ) {
			if ( ! is_string( $key ) || isset( $exclude[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return (array) apply_filters( 'heb_pp_settings_theme_mods_payload', $out );
	}

	/**
	 * 翻译 payload 的 translate 段。
	 *
	 * @param array<string,mixed>              $payload    Source payload.
	 * @param string                           $src_locale Source locale.
	 * @param string                           $dst_locale Target locale.
	 * @param Heb_Product_Publisher_Translator $translator Translator.
	 * @return array{payload: array<string,mixed>, stats: array<string,mixed>, errors: array<int,string>}
	 */
	public function translate_payload( array $payload, $src_locale, $dst_locale, Heb_Product_Publisher_Translator $translator ) {
		$stats  = [ 'strings' => 0, 'translated' => 0, 'batches' => 0 ];
		$errors = [];

		if ( '' === trim( (string) $dst_locale ) || Heb_Product_Publisher_Translator::same_language( $src_locale, $dst_locale ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}
		if ( empty( $payload['translate'] ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		$result = $translator->translate_payload( [ 'translate' => $payload['translate'] ], $src_locale, $dst_locale );
		$stats  = array_merge( $stats, $result['stats'] );
		$errors = array_merge( $errors, $result['errors'] );

		if ( isset( $result['payload']['translate'] ) && is_array( $result['payload']['translate'] ) ) {
			$payload['translate'] = $result['payload']['translate'];
		}
		return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
	}

	/**
	 * 单站点分发。
	 *
	 * @param array<string,mixed>              $basepayload   Payload.
	 * @param string                           $source_locale Source locale.
	 * @param array<string,string>             $site          Site config.
	 * @param Heb_Product_Publisher_Translator $translator    Translator.
	 * @return array<string,mixed>
	 */
	public function distribute_to_site( array $basepayload, $source_locale, array $site, Heb_Product_Publisher_Translator $translator ) {
		$started = microtime( true );
		$sid     = isset( $site['id'] ) ? (string) $site['id'] : '';
		$label   = isset( $site['label'] ) ? (string) $site['label'] : $sid;

		$target_locale = isset( $site['locale_override'] ) && '' !== $site['locale_override']
			? (string) $site['locale_override']
			: '';
		if ( '' === $target_locale ) {
			$info = Heb_Product_Publisher_Remote_Client::post( $site, '/site-info', [], 15 );
			if ( is_wp_error( $info ) ) {
				return [
					'ok'          => false,
					'message'     => $info->get_error_message(),
					'site_id'     => $sid,
					'site_label'  => $label,
					'errors'      => [],
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				];
			}
			$target_locale = isset( $info['locale'] ) ? (string) $info['locale'] : '';
		}

		$translated = $this->translate_payload( $basepayload, $source_locale, $target_locale, $translator );
		$payload    = $translated['payload'];
		$errors     = $translated['errors'];

		$strict_abort = Heb_Product_Publisher_Translator::strict_abort_reason( $errors );
		if ( null !== $strict_abort ) {
			return [
				'ok'          => false,
				'message'     => $strict_abort,
				'site_id'     => $sid,
				'site_label'  => $label,
				'errors'      => $errors,
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			];
		}

		$timeout = Heb_Product_Publisher_Admin_Settings::site_timeout( $site );
		$res     = Heb_Product_Publisher_Remote_Client::post( $site, '/import-settings', $payload, $timeout );

		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return [
				'ok'          => false,
				'message'     => $res->get_error_message(),
				'site_id'     => $sid,
				'site_label'  => $label,
				'errors'      => $errors,
				'duration_ms' => $elapsed_ms,
			];
		}
		return [
			'ok'            => true,
			'site_id'       => $sid,
			'site_label'    => $label,
			'applied'       => isset( $res['applied'] ) ? (array) $res['applied'] : [],
			'skipped'       => isset( $res['skipped'] ) ? (array) $res['skipped'] : [],
			'errors'        => $errors,
			'duration_ms'   => $elapsed_ms,
		];
	}
}
