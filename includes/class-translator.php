<?php
/**
 * OpenRouter 翻译器。
 *
 * 调用策略：
 *  - 递归收集 payload 中所有"可翻译"字符串 → 打包为 JSON → 一次（或分批）调用 OpenRouter
 *  - 按原 key 路径写回，保留 HTML 结构、URL、数字、布尔不翻译
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Translator {

	/** 每批字符串总字符数上限。 */
	const BATCH_CHAR_LIMIT = 6000;

	/** 单条字符串超过该长度将独占一个批次。 */
	const SOLO_CHAR_LIMIT = 5000;

	/**
	 * 不翻译的 key（子字段名，无论嵌套多深）：标识、slug、数字 ID、颜色、图片 token 等。
	 *
	 * @return array<int,string>
	 */
	public static function skip_keys() {
		$keys = [
			'id', 'ID', 'slug', 'key', 'uid',
			'email', 'phone', 'url', 'link', 'href', 'src',
			'hash', 'token',
			'review_date', 'review_rating',
			'__heb_media', '__heb_url',
		];
		return (array) apply_filters( 'heb_pp_translator_skip_keys', $keys );
	}

	/**
	 * 翻译 payload 中所有可翻译字符串。
	 *
	 * @param array<string,mixed> $payload   输入 payload。
	 * @param string              $src_locale 源语言。
	 * @param string              $dst_locale 目标语言。
	 * @return array{payload: array<string,mixed>, stats: array<string,mixed>, errors: array<int,string>}
	 */
	public function translate_payload( array $payload, $src_locale, $dst_locale ) {
		$errors = [];
		$stats  = [ 'strings' => 0, 'translated' => 0, 'batches' => 0 ];

		if ( '' === trim( (string) $dst_locale ) || self::same_language( $src_locale, $dst_locale ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		$api_key = Heb_Product_Publisher_Admin_Settings::openrouter_key();
		if ( '' === $api_key ) {
			$errors[] = __( '尚未配置 OpenRouter API Key，已跳过翻译。', 'heb-product-publisher' );
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		$strings = [];
		$this->collect_strings( $payload, '', $strings );
		$stats['strings'] = count( $strings );

		if ( empty( $strings ) ) {
			return [ 'payload' => $payload, 'stats' => $stats, 'errors' => $errors ];
		}

		$batches = $this->batch( $strings, self::BATCH_CHAR_LIMIT, self::SOLO_CHAR_LIMIT );
		$stats['batches'] = count( $batches );

		$translated_map = [];
		foreach ( $batches as $batch ) {
			$result = $this->call_openrouter( $batch, $src_locale, $dst_locale, $api_key );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			foreach ( $batch as $path => $text ) {
				if ( isset( $result[ $path ] ) && is_string( $result[ $path ] ) && '' !== $result[ $path ] ) {
					$translated_map[ $path ] = $result[ $path ];
				}
			}
		}

		$stats['translated'] = count( $translated_map );
		$new_payload         = $this->apply_strings( $payload, '', $translated_map );

		return [ 'payload' => $new_payload, 'stats' => $stats, 'errors' => $errors ];
	}

	/**
	 * 判断两种 locale 是否属于同一语言（en_US vs en vs en_GB 视为同语言）。
	 *
	 * @param string $a Locale a.
	 * @param string $b Locale b.
	 * @return bool
	 */
	public static function same_language( $a, $b ) {
		$norm = static function ( $x ) {
			$x = strtolower( (string) $x );
			$x = str_replace( '-', '_', $x );
			$parts = explode( '_', $x );
			return $parts[0];
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * 递归收集可翻译字符串到 $out。
	 *
	 * @param mixed                $value 当前值。
	 * @param string               $path  当前路径（用点号）。
	 * @param array<string,string> $out   输出收集器（引用）。
	 */
	private function collect_strings( $value, $path, array &$out ) {
		if ( is_array( $value ) ) {
			if ( isset( $value['__heb_media'] ) ) {
				return;
			}
			foreach ( $value as $k => $v ) {
				$key   = (string) $k;
				$child = '' === $path ? $key : $path . '.' . $key;
				if ( $this->should_skip_key( $key ) ) {
					continue;
				}
				$this->collect_strings( $v, $child, $out );
			}
			return;
		}
		if ( ! is_string( $value ) ) {
			return;
		}
		if ( ! $this->looks_translatable( $value ) ) {
			return;
		}
		$out[ $path ] = $value;
	}

	/**
	 * 把翻译结果按路径写回 payload。
	 *
	 * @param mixed                $value 当前值。
	 * @param string               $path  当前路径。
	 * @param array<string,string> $map   翻译结果映射。
	 * @return mixed
	 */
	private function apply_strings( $value, $path, array $map ) {
		if ( is_array( $value ) ) {
			if ( isset( $value['__heb_media'] ) ) {
				return $value;
			}
			$out = [];
			foreach ( $value as $k => $v ) {
				$key   = (string) $k;
				$child = '' === $path ? $key : $path . '.' . $key;
				if ( $this->should_skip_key( $key ) ) {
					$out[ $k ] = $v;
					continue;
				}
				$out[ $k ] = $this->apply_strings( $v, $child, $map );
			}
			return $out;
		}
		if ( is_string( $value ) && isset( $map[ $path ] ) ) {
			return $map[ $path ];
		}
		return $value;
	}

	/**
	 * @param string $key Field/array key.
	 * @return bool
	 */
	private function should_skip_key( $key ) {
		if ( '' === $key ) {
			return false;
		}
		$skip = self::skip_keys();
		if ( in_array( $key, $skip, true ) ) {
			return true;
		}
		if ( preg_match( '/(_slug|_id|_url|_link|_email)$/i', $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * 字符串是否值得翻译。
	 * 会排除：纯数字、URL、邮箱、纯符号、ACF 选项键、型号编码（如 108D/2、T/T、GRS、SD、OEM、paypal 等）。
	 *
	 * @param string $s Input string.
	 * @return bool
	 */
	private function looks_translatable( $s ) {
		$trim = trim( $s );
		if ( '' === $trim ) {
			return false;
		}
		if ( is_numeric( $trim ) ) {
			return false;
		}
		if ( in_array( strtolower( $trim ), [ 'true', 'false', 'null', 'yes', 'no', 'on', 'off' ], true ) ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $trim ) ) {
			return false;
		}
		if ( preg_match( '/^[\w\.\-]+@[\w\.\-]+$/i', $trim ) ) {
			return false;
		}
		if ( ! preg_match( '/\p{L}/u', $trim ) ) {
			return false;
		}

		// ACF 选项键 / 型号编码启发式：不含空白、长度较短、仅由 ASCII 字母数字/_-./ 构成。
		// 例：tt, lc, paypal, odm, oem, both, no, SD, GRS, T/T, L/C, 108D/2, 1-A.
		if ( self::looks_like_code_or_slug( $trim ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $s Trimmed string.
	 * @return bool
	 */
	public static function looks_like_code_or_slug( $s ) {
		if ( preg_match( '/\s/u', $s ) ) {
			return false;
		}
		$len = mb_strlen( $s, 'UTF-8' );
		if ( $len <= 0 || $len > 16 ) {
			return false;
		}
		if ( ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9_./\-]*$#', $s ) ) {
			return false;
		}
		// 全小写 ASCII 标识符（ACF 选项键常见：paypal / tt / odm / lc / oem / both）。
		if ( preg_match( '/^[a-z][a-z0-9_\-]*$/', $s ) ) {
			return true;
		}
		// 型号编码：至少含一位数字或斜杠/短横线/点（108D/2、T/T、L-C、1.0），整体较短。
		if ( preg_match( '#[0-9/\-.]#', $s ) ) {
			return true;
		}
		// 全大写缩写：GRS / FOB / SD。
		if ( preg_match( '/^[A-Z]{2,6}$/', $s ) ) {
			return true;
		}
		return false;
	}

	/**
	 * 分批：按字符数上限合并，同时单条超长则独占一批。
	 *
	 * @param array<string,string> $strings 原始 path=>text。
	 * @param int                  $limit   总长度上限。
	 * @param int                  $solo    单条独占阈值。
	 * @return array<int, array<string,string>>
	 */
	private function batch( array $strings, $limit, $solo ) {
		$batches = [];
		$cur     = [];
		$cur_len = 0;
		foreach ( $strings as $path => $text ) {
			$len = strlen( $text );
			if ( $len >= $solo ) {
				if ( ! empty( $cur ) ) {
					$batches[] = $cur;
					$cur       = [];
					$cur_len   = 0;
				}
				$batches[] = [ $path => $text ];
				continue;
			}
			if ( $cur_len + $len > $limit && ! empty( $cur ) ) {
				$batches[] = $cur;
				$cur       = [];
				$cur_len   = 0;
			}
			$cur[ $path ] = $text;
			$cur_len     += $len;
		}
		if ( ! empty( $cur ) ) {
			$batches[] = $cur;
		}
		return $batches;
	}

	/**
	 * 调用 OpenRouter Chat Completions。要求模型返回 JSON。
	 *
	 * @param array<string,string> $batch     path=>text。
	 * @param string               $src       源语言。
	 * @param string               $dst       目标语言。
	 * @param string               $api_key   API key.
	 * @return array<string,string>|\WP_Error
	 */
	private function call_openrouter( array $batch, $src, $dst, $api_key ) {
		$model = Heb_Product_Publisher_Admin_Settings::openrouter_model();

		$system = "You are a professional translator specializing in B2B industrial product catalogs. "
			. "Translate the string values of the given JSON object from {$src} to {$dst}. "
			. "Rules: "
			. "1) Output MUST be a single JSON object with exactly the same keys as the input; every input key must appear in the output. "
			. "2) Preserve HTML tags, attributes, entities and whitespace exactly; only translate visible text nodes. "
			. "3) Do NOT translate and KEEP verbatim: URLs, email addresses, numbers, measurements, dates, brand names, SKU/product codes (e.g. 108D/2, GRS, SD, FOB, T/T, L/C, D/P, D/A), file paths, CSS/HTML identifiers, placeholder tokens like {xxx}, %s, [tag], and Yoast SEO variables wrapped in double percent signs like %%title%%, %%sep%%, %%sitename%%, %%page%%, %%primary_category%%. "
			. "4) If a value looks like an identifier/enum key (e.g. 'paypal', 'odm', 'oem', 'both', 'tt', 'lc'), keep it unchanged. "
			. "5) Translate natural-language sentences, product descriptions and marketing copy into {$dst} using the appropriate professional industry terminology. "
			. "6) Return ONLY the JSON object. No explanation, no markdown fences, no preamble.";

		$user = wp_json_encode( $batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$body = [
			'model'       => $model,
			'temperature' => 0,
			'messages'    => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $user ],
			],
			'response_format' => [ 'type' => 'json_object' ],
		];

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 60,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => 'HEB Product Publisher',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error( 'heb_pp_openrouter_http', sprintf( 'OpenRouter HTTP %d: %s', (int) $code, substr( (string) $raw, 0, 500 ) ) );
		}

		$json = json_decode( (string) $raw, true );
		if ( ! is_array( $json ) || empty( $json['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'heb_pp_openrouter_shape', __( 'OpenRouter 返回格式异常。', 'heb-product-publisher' ) );
		}

		$content = (string) $json['choices'][0]['message']['content'];
		$parsed  = self::parse_json_loose( $content );
		if ( ! is_array( $parsed ) ) {
			return new \WP_Error(
				'heb_pp_openrouter_parse',
				sprintf(
					/* translators: %s: raw model output (truncated) */
					__( 'OpenRouter 返回 JSON 解析失败：%s', 'heb-product-publisher' ),
					substr( $content, 0, 300 )
				)
			);
		}

		$out = [];
		foreach ( $parsed as $k => $v ) {
			if ( is_string( $v ) ) {
				$out[ (string) $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * 宽松解析 LLM 返回：先直解，失败则剥 ``` 代码块、然后提取首个 {...}。
	 *
	 * @param string $raw Raw content.
	 * @return array<string,mixed>|null
	 */
	private static function parse_json_loose( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$direct = json_decode( $raw, true );
		if ( is_array( $direct ) ) {
			return $direct;
		}
		$stripped = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$stripped = preg_replace( '/\s*```$/', '', (string) $stripped );
		$try      = json_decode( (string) $stripped, true );
		if ( is_array( $try ) ) {
			return $try;
		}
		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$sub = substr( $raw, $start, $end - $start + 1 );
			$try = json_decode( $sub, true );
			if ( is_array( $try ) ) {
				return $try;
			}
		}
		return null;
	}
}
