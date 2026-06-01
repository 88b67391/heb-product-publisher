<?php
/**
 * Hub 端：分类（term）分发管线。
 *
 * 与产品分发不同的两个核心点：
 *  1. payload 极简：name / description / slug fallback / parent_source_term_id
 *  2. slug_strategy=localized 时 slug 走 AI 翻译；source 时沿用源站英文 slug
 *     旧 slug 自动加入 `_heb_pp_old_slugs` 用于 301 redirect 保持 SEO 信号
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Term_Sync {

	const META_SOURCE_TERM_ID = '_heb_pp_source_term_id';
	const META_SOURCE_SITE    = '_heb_pp_source_site';
	const META_LANG_MAP       = '_heb_pp_term_lang_map';
	const META_OLD_SLUGS      = '_heb_pp_old_slugs';

	/**
	 * 构造 term 分发 payload（未翻译原文）。
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed> Payload or empty.
	 */
	public static function build_payload( $term_id ) {
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return [];
		}
		if ( ! in_array( $term->taxonomy, heb_pp_distributable_taxonomies(), true ) ) {
			return [];
		}

		$parent_source_id = 0;
		if ( (int) $term->parent > 0 ) {
			// 父 term 可能本身也是从主站分发来的；这里我们用本地 term_id 作 source（因为这就是主站）。
			$parent_source_id = (int) $term->parent;
		}

		return [
			'taxonomy'                => (string) $term->taxonomy,
			'name'                    => (string) $term->name,
			'description'             => (string) $term->description,
			'slug_fallback'           => (string) $term->slug,
			'source_term_id'          => (int) $term->term_id,
			'source_parent_term_id'   => $parent_source_id,
			'source_site'             => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_locale'           => Heb_Product_Publisher_Admin_Settings::source_locale(),
		];
	}

	/**
	 * 翻译 payload 的 name / description 到 dst_locale。
	 * 同时单独调一次模型生成 URL-friendly 本地化 slug。
	 *
	 * @param array<string,mixed>              $payload    Source payload.
	 * @param string                           $src_locale Source locale.
	 * @param string                           $dst_locale Target locale.
	 * @param Heb_Product_Publisher_Translator $translator     Translator instance.
	 * @param string                           $slug_strategy  source|localized.
	 * @return array{payload: array<string,mixed>, stats: array<string,mixed>, errors: array<int,string>}
	 */
	public function translate_payload( array $payload, $src_locale, $dst_locale, Heb_Product_Publisher_Translator $translator, $slug_strategy = '' ) {
		$stats  = [ 'strings' => 0, 'translated' => 0, 'batches' => 0 ];
		$errors = [];

		if ( '' === trim( (string) $dst_locale ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}
		if ( Heb_Product_Publisher_Translator::same_language( $src_locale, $dst_locale ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		// name / description 走通用翻译器。
		$translate_sub = [
			'name'        => isset( $payload['name'] ) ? (string) $payload['name'] : '',
			'description' => isset( $payload['description'] ) ? (string) $payload['description'] : '',
		];
		$result = $translator->translate_payload( $translate_sub, $src_locale, $dst_locale );
		$stats  = array_merge( $stats, $result['stats'] );
		$errors = array_merge( $errors, $result['errors'] );
		if ( isset( $result['payload']['name'] ) && '' !== $result['payload']['name'] ) {
			$payload['name'] = (string) $result['payload']['name'];
		}
		if ( isset( $result['payload']['description'] ) ) {
			$payload['description'] = (string) $result['payload']['description'];
		}

		if ( 'source' !== $slug_strategy ) {
			// slug 单独走一个 prompt，要求模型输出 URL-friendly 本地化 slug。
			$translated_slug = $this->translate_slug( (string) $payload['name'], $src_locale, $dst_locale );
			if ( '' !== $translated_slug ) {
				$payload['slug_translated'] = $translated_slug;
			}
		}

		return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
	}

	/**
	 * 用 OpenRouter 给一个 term name 生成本地化 slug。
	 *
	 * 失败时返回空字符串，子站会用 sanitize_title(name) 兜底。
	 *
	 * @param string $name Translated name.
	 * @param string $src  Source locale.
	 * @param string $dst  Target locale.
	 * @return string
	 */
	private function translate_slug( $name, $src, $dst ) {
		if ( '' === trim( $name ) ) {
			return '';
		}
		$api_key = Heb_Product_Publisher_Admin_Settings::openrouter_key();
		if ( '' === $api_key ) {
			return '';
		}
		$model = Heb_Product_Publisher_Admin_Settings::openrouter_model();

		$system = "You generate URL-friendly slugs for taxonomy terms on a multilingual website. "
			. "Given a term name (already translated to {$dst}), return a single short slug suitable for URL paths. "
			. "Rules: "
			. "1) Output MUST be a single JSON object with one key: \"slug\". "
			. "2) Slug should be lowercase, use hyphens to separate words. "
			. "3) For Latin-script target languages, transliterate or translate to ASCII letters and digits. "
			. "4) For CJK target languages (ja, ko, zh) or other non-Latin scripts: prefer a short Romanized/transliterated form (e.g. 'porieusuteru-firamento' for ポリエステルフィラメント), NOT raw native characters, to keep URLs portable and avoid percent-encoding. "
			. "5) Keep the slug under 60 characters. "
			. "6) Return ONLY the JSON object. No markdown, no explanation.";

		$user = wp_json_encode( [ 'name' => $name, 'target_language' => $dst ], JSON_UNESCAPED_UNICODE );

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 30,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => 'HEB Product Publisher (slug)',
				],
				'body'    => wp_json_encode(
					[
						'model'           => $model,
						'temperature'     => 0,
						'messages'        => [
							[ 'role' => 'system', 'content' => $system ],
							[ 'role' => 'user', 'content' => $user ],
						],
						'response_format' => [ 'type' => 'json_object' ],
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return '';
		}
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $raw, true );
		if ( ! is_array( $json ) || empty( $json['choices'][0]['message']['content'] ) ) {
			return '';
		}
		$content = (string) $json['choices'][0]['message']['content'];
		$parsed  = json_decode( $content, true );
		if ( is_array( $parsed ) && isset( $parsed['slug'] ) && is_string( $parsed['slug'] ) ) {
			$slug = sanitize_title( $parsed['slug'] );
			return $slug;
		}
		return '';
	}

	/**
	 * 单 term → 单站点分发流程。
	 *
	 * @param int                              $term_id        Source term id.
	 * @param array<string,mixed>              $basepayload    Built payload (untranslated).
	 * @param string                           $source_locale  Source locale.
	 * @param array<string,string>             $site           Remote site config.
	 * @param Heb_Product_Publisher_Translator $translator     Translator instance.
	 * @return array<string,mixed>
	 */
	public function distribute_to_site( $term_id, array $basepayload, $source_locale, array $site, Heb_Product_Publisher_Translator $translator ) {
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
					'ok'         => false,
					'message'    => $info->get_error_message(),
					'site_id'    => $sid,
					'site_label' => $label,
					'errors'     => [],
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				];
			}
			$target_locale = isset( $info['locale'] ) ? (string) $info['locale'] : '';
		}

		$slug_strategy = Heb_Product_Publisher_Admin_Settings::slug_strategy_for_site( $site );

		// 1) 翻译 name/description；localized 时再 AI 生成本地化 slug。
		$translated = $this->translate_payload( $basepayload, $source_locale, $target_locale, $translator, $slug_strategy );
		$payload    = $translated['payload'];
		$errors     = $translated['errors'];
		$payload['slug_strategy'] = $slug_strategy;

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

		// 2) 推送 lang_map（含已知的源站和其他站点 URL）。
		$payload['lang_map'] = $this->collect_term_lang_map( $term_id, $basepayload );

		// 3) POST /import-term。
		$timeout = Heb_Product_Publisher_Admin_Settings::site_timeout( $site );
		$res     = Heb_Product_Publisher_Remote_Client::post( $site, '/import-term', $payload, $timeout );

		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return [
				'ok'         => false,
				'message'    => $res->get_error_message(),
				'site_id'    => $sid,
				'site_label' => $label,
				'errors'     => $errors,
				'duration_ms' => $elapsed_ms,
			];
		}

		// 4) 成功后写回主站 term 的 lang_map（含此目标站 URL）。
		$lang_map_warns = $this->refresh_term_lang_map( $term_id, $basepayload, $site, $target_locale, $res );

		return [
			'ok'             => true,
			'site_id'        => $sid,
			'site_label'     => $label,
			'remote_term_id' => isset( $res['term_id'] ) ? (int) $res['term_id'] : 0,
			'remote_url'     => isset( $res['url'] ) ? (string) $res['url'] : '',
			'edit_url'       => isset( $res['edit_url'] ) ? (string) $res['edit_url'] : '',
			'errors'         => array_merge( $errors, $lang_map_warns ),
			'warn'           => $lang_map_warns,
			'duration_ms'    => $elapsed_ms,
		];
	}

	/**
	 * 收集源 term 已知的多语言 URL 映射（含主站本身）。
	 *
	 * @param int                  $term_id     Term id.
	 * @param array<string,mixed>  $basepayload Payload (for source_locale).
	 * @return array<string,string>
	 */
	private function collect_term_lang_map( $term_id, array $basepayload ) {
		$out = [];
		$map = get_term_meta( $term_id, self::META_LANG_MAP, true );
		if ( is_array( $map ) ) {
			foreach ( $map as $lang => $url ) {
				$lang = sanitize_key( (string) $lang );
				$url  = esc_url_raw( (string) $url );
				if ( '' !== $lang && '' !== $url ) {
					$out[ $lang ] = $url;
				}
			}
		}
		$src_lang = self::locale_to_lang( isset( $basepayload['source_locale'] ) ? (string) $basepayload['source_locale'] : get_locale() );
		$src_url  = get_term_link( (int) $term_id );
		if ( '' !== $src_lang && is_string( $src_url ) && '' !== $src_url ) {
			$out[ $src_lang ] = $src_url;
		}
		return $out;
	}

	/**
	 * 分发成功后写回主站 term lang_map + 推送给所有远端站点同步。
	 *
	 * @param int                   $term_id       Term id.
	 * @param array<string,mixed>   $basepayload   Payload.
	 * @param array<string,string>  $site          Remote site row.
	 * @param string                $target_locale Target locale.
	 * @param array<string,mixed>   $result        Push result.
	 * @return array<int,string> Lang map sync warnings.
	 */
	private function refresh_term_lang_map( $term_id, array $basepayload, array $site, $target_locale, array $result ) {
		$lang = self::locale_to_lang( $target_locale );
		$url  = isset( $result['url'] ) ? esc_url_raw( (string) $result['url'] ) : '';
		if ( '' === $lang || '' === $url ) {
			return [];
		}
		$map          = $this->collect_term_lang_map( $term_id, $basepayload );
		$map[ $lang ] = $url;
		update_term_meta( $term_id, self::META_LANG_MAP, $map );
		return $this->sync_lang_map_to_all_sites( $basepayload, $map );
	}

	/**
	 * @param array<string,mixed>  $basepayload Payload (含 taxonomy / source_term_id / source_site).
	 * @param array<string,string> $lang_map    Lang map.
	 * @return array<int,string> Failures.
	 */
	private function sync_lang_map_to_all_sites( array $basepayload, array $lang_map ) {
		$taxonomy       = isset( $basepayload['taxonomy'] ) ? sanitize_key( (string) $basepayload['taxonomy'] ) : '';
		$source_term_id = isset( $basepayload['source_term_id'] ) ? (int) $basepayload['source_term_id'] : 0;
		$source_site    = isset( $basepayload['source_site'] ) ? sanitize_text_field( (string) $basepayload['source_site'] ) : '';
		if ( '' === $taxonomy || $source_term_id <= 0 || '' === $source_site || empty( $lang_map ) ) {
			return [];
		}
		$failures = [];
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $s ) {
			$res = Heb_Product_Publisher_Remote_Client::post(
				$s,
				'/sync-term-lang-map',
				[
					'taxonomy'       => $taxonomy,
					'source_term_id' => $source_term_id,
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
	 * Locale 转 lang 短码（en_US → en，zh_CN → zh-cn）。
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function locale_to_lang( $locale ) {
		$locale = strtolower( trim( (string) $locale ) );
		if ( '' === $locale ) {
			return '';
		}
		$locale = str_replace( '_', '-', $locale );
		if ( false === strpos( $locale, '-' ) ) {
			return sanitize_key( $locale );
		}
		$parts = explode( '-', $locale, 2 );
		return sanitize_key( (string) $parts[0] );
	}
}
