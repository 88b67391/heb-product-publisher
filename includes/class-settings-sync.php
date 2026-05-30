<?php
/**
 * Hub 端：WordPress 全局选项白名单分发。
 *
 * 白名单刻意保守：只同步主站和子站"应该一致"的字段，避免误覆盖子站自己定的（admin_email、
 * siteurl/home、comment_*）。
 *
 * Page references（page_on_front / page_for_posts）转成 { __heb_post_ref: source_id }
 * token，由 Receiver 在写入前反查本地 post id；找不到则跳过该 option。
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
				'show_on_front',     // 'page' / 'posts'
				'posts_per_page',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'thumbnail_crop',
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
			]
		);
	}

	/**
	 * 构造 payload。
	 *
	 * @return array<string,mixed>
	 */
	public static function build_payload() {
		$payload = [
			'source_site'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_locale'  => Heb_Product_Publisher_Admin_Settings::source_locale(),
			'copy'           => [],
			'translate'      => [],
			'post_refs'      => [],
		];
		foreach ( self::copy_options() as $opt ) {
			$payload['copy'][ $opt ] = get_option( $opt );
		}
		foreach ( self::translate_options() as $opt ) {
			$v = get_option( $opt );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$payload['translate'][ $opt ] = $v;
			}
		}
		foreach ( self::post_ref_options() as $opt ) {
			$pid = (int) get_option( $opt );
			if ( $pid > 0 ) {
				$payload['post_refs'][ $opt ] = [
					'source_post_id' => $pid,
				];
			}
		}
		return $payload;
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
