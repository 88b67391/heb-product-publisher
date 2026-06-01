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

	/** 每批字符串总字符数上限。
	 *
	 * 经验值：6000 字符在含大量 HTML 标签 + 慢模型（Claude/GPT-4）时常导致单批 LLM
	 * 输出超过 60s，触发 cURL error 28。3500 让单批更小，输出更快，超时概率显著下降。
	 * 可通过 filter `heb_pp_translator_batch_char_limit` 调整。
	 */
	const BATCH_CHAR_LIMIT = 2800;

	/** 单条字符串超过该长度将独占一个批次。 */
	const SOLO_CHAR_LIMIT = 2200;

	/** OpenRouter HTTP 调用超时（秒）。
	 *
	 * GPT-5 / Claude Opus 等慢模型 + 长 content（10KB+）单批可能 3-5 分钟。
	 * 质量优先默认 1200s；可通过 filter `heb_pp_translator_http_timeout` 调整。
	 */
	const HTTP_TIMEOUT = 300;

	/** 单批失败时最多重试次数（不含首次）。 */
	const MAX_RETRIES = 2;

	/**
	 * 不翻译的 key（子字段名，无论嵌套多深）：标识、slug、数字 ID、颜色、图片 token 等。
	 *
	 * v3.0 起补充了 Elementor 内部字段（_id / _element_id / elType / widgetType / __globals__
	 * / css_classes / anchor / html_tag / link_url / btn_link / background_video_link 等），
	 * 避免给模型送 widget id / 枚举值 / 链接，浪费 token 也容易翻坏。
	 *
	 * @return array<int,string>
	 */
	public static function skip_keys() {
		$keys = [
			// 通用 ID / URL / 联系方式
			'id', 'ID', 'slug', 'key', 'uid',
			'email', 'phone', 'url', 'link', 'href', 'src',
			'hash', 'token',
			'review_date', 'review_rating',
			// HEB 内部传输 token
			'__heb_media', '__heb_url', '__heb_alt', '__heb_size', '__heb_source',
			// Elementor 内部标识
			'_id', '_element_id', '_element_type', 'elType', 'widgetType',
			'__globals__', 'globals',
			'css_classes', 'anchor', 'html_tag',
			'link_url', 'btn_link', 'menu_anchor',
			'background_video_link', 'background_slideshow_gallery',
			// Elementor 响应式 / 控件尺寸
			'_inline_size', '_inline_size_tablet', '_inline_size_mobile',
			'_column_size',
			// Elementor 动画
			'_animation', '_animation_delay', 'animation', 'animation_delay',
			'hover_animation',
			// Elementor 样式数值 / 颜色（CSS 值不需要翻）
			'background_color', 'border_color',
			'_padding', '_margin', '_border_width', '_border_radius',
			'_z_index',
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

		$batch_limit = (int) apply_filters( 'heb_pp_translator_batch_char_limit', self::BATCH_CHAR_LIMIT );
		$solo_limit  = (int) apply_filters( 'heb_pp_translator_solo_char_limit', self::SOLO_CHAR_LIMIT );
		if ( $batch_limit <= 0 ) {
			$batch_limit = self::BATCH_CHAR_LIMIT;
		}
		if ( $solo_limit <= 0 ) {
			$solo_limit = self::SOLO_CHAR_LIMIT;
		}

		$batches = $this->batch( $strings, $batch_limit, $solo_limit );
		$stats['batches'] = count( $batches );

		$translated_map = [];
		$batch_index    = 0;
		foreach ( $batches as $batch ) {
			$batch_index++;
			$result = $this->translate_batch_resilient( $batch, $src_locale, $dst_locale, $api_key, $batch_index );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			foreach ( $result as $path => $text ) {
				if ( is_string( $path ) && is_string( $text ) && '' !== $text ) {
					$translated_map[ $path ] = $text;
				}
			}
		}

		$stats['translated'] = count( $translated_map );

		// 关键：缺失的字段（翻译失败 / 跳过）用源串补齐，特别对 HTML segments：
		// 任意 segment 缺译都用源段填，避免 apply_strings 拼回时少一节导致内容截断。
		foreach ( $strings as $key => $src ) {
			if ( ! isset( $translated_map[ $key ] ) ) {
				$translated_map[ $key ] = $src;
			}
		}

		$new_payload = $this->apply_strings( $payload, '', $translated_map );
		$new_payload = $this->preserve_elementor_style_fields( $payload, $new_payload );

		return [ 'payload' => $new_payload, 'stats' => $stats, 'errors' => $errors ];
	}

	/**
	 * 翻译后强制还原 Elementor 样式字段，防止 AI 误改 font-size / typography 等数值。
	 *
	 * @param array<string,mixed> $source    原始 payload。
	 * @param array<string,mixed> $translated 已翻译 payload。
	 * @return array<string,mixed>
	 */
	private function preserve_elementor_style_fields( array $source, array $translated ) {
		if ( isset( $source['elementor_data'] ) && is_array( $source['elementor_data'] ) ) {
			$dst = isset( $translated['elementor_data'] ) && is_array( $translated['elementor_data'] ) ? $translated['elementor_data'] : [];
			$translated['elementor_data'] = $this->preserve_elementor_tree( $source['elementor_data'], $dst );
		}
		if ( isset( $source['elementor_page_settings'] ) && is_array( $source['elementor_page_settings'] ) ) {
			$dst = isset( $translated['elementor_page_settings'] ) && is_array( $translated['elementor_page_settings'] ) ? $translated['elementor_page_settings'] : [];
			$translated['elementor_page_settings'] = $this->merge_elementor_settings_deep( $source['elementor_page_settings'], $dst );
		}
		return $translated;
	}

	/**
	 * 递归还原 Elementor 树；settings 内合并全部可译字符串，样式键保留源站。
	 *
	 * @param array<string,mixed> $original Original branch.
	 * @param array<string,mixed> $current  Translated branch.
	 * @return array<string,mixed>
	 */
	private function preserve_elementor_tree( array $original, array $current ) {
		foreach ( $original as $k => $ov ) {
			$key = (string) $k;
			if ( 'settings' === $key && is_array( $ov ) ) {
				$cv            = isset( $current[ $k ] ) && is_array( $current[ $k ] ) ? $current[ $k ] : [];
				$current[ $k ] = $this->merge_elementor_settings_deep( $ov, $cv );
				continue;
			}
			if ( $this->should_skip_key( $key, $key ) || $this->is_elementor_style_key( $key, $key ) ) {
				$current[ $k ] = $ov;
				continue;
			}
			if ( is_array( $ov ) ) {
				$cv            = isset( $current[ $k ] ) && is_array( $current[ $k ] ) ? $current[ $k ] : [];
				$current[ $k ] = $this->preserve_elementor_tree( $ov, $cv );
			}
		}
		return $current;
	}

	/**
	 * Elementor widget settings：保留源站样式；覆盖所有可译字符串字段（不限白名单）。
	 *
	 * @param array<string,mixed> $original Source settings.
	 * @param array<string,mixed> $translated Translated settings.
	 * @return array<string,mixed>
	 */
	private function merge_elementor_settings_deep( array $original, array $translated, $in_repeater = false ) {
		unset( $in_repeater );
		$out = $original;
		foreach ( $translated as $k => $tv ) {
			if ( ! is_string( $k ) || ! array_key_exists( $k, $original ) ) {
				continue;
			}
			if ( $this->should_skip_key( $k, 'settings' ) || $this->is_elementor_style_key( $k, 'settings' ) ) {
				continue;
			}
			$ov = $original[ $k ];
			if ( is_string( $tv ) && is_string( $ov ) && $this->should_merge_elementor_setting_string( $k, 'settings', $ov ) ) {
				$out[ $k ] = $this->sanitize_elementor_html_setting( $k, $tv, $ov );
				continue;
			}
			if ( self::is_elementor_repeater_container_key( $k ) && is_array( $tv ) && is_array( $ov ) ) {
				$out[ $k ] = $this->merge_elementor_repeater_items( $ov, $tv );
				continue;
			}
			if ( is_array( $tv ) && is_array( $ov ) ) {
				$out[ $k ] = $this->merge_elementor_settings_deep( $ov, $tv, false );
			}
		}
		return $out;
	}

	/**
	 * @param array<int|string,mixed> $original Repeater rows.
	 * @param array<int|string,mixed> $translated Translated rows.
	 * @return array<int|string,mixed>
	 */
	private function merge_elementor_repeater_items( array $original, array $translated ) {
		$out = [];
		foreach ( $original as $idx => $orig_item ) {
			if ( ! is_array( $orig_item ) ) {
				$out[ $idx ] = $orig_item;
				continue;
			}
			$trans_item    = isset( $translated[ $idx ] ) && is_array( $translated[ $idx ] ) ? $translated[ $idx ] : [];
			$out[ $idx ]   = $this->merge_elementor_settings_deep( $orig_item, $trans_item, false );
		}
		return $out;
	}

	/**
	 * 允许 AI 翻译的 Elementor settings 字段（白名单）。
	 *
	 * @return array<int,string>
	 */
	public static function elementor_translatable_setting_keys() {
		return (array) apply_filters(
			'heb_pp_elementor_translatable_setting_keys',
			[
				'editor',
				'title',
				'subtitle',
				'text',
				'description',
				'button_text',
				'link_text',
				'inner_text',
				'html',
				'content',
				'tab_title',
				'tab_content',
				'alert_title',
				'alert_description',
				'placeholder',
				'label',
				'caption',
				'tooltip',
				'heading_title',
				'heading_subtitle',
				'title_text',
				'description_text',
				'field_label',
				'field_placeholder',
				'prefix',
				'suffix',
				'empty_fields_message',
				'after_text',
				'before_text',
				'banner_title',
				'banner_description',
				'footer_text',
				'header_text',
				'ribbon_title',
				'heading',
				'sub_heading',
				'highlighted_text',
				'rotating_text',
				'primary_text',
				'secondary_text',
				'cta_text',
				'btn_text',
				'slide_heading',
				'slide_description',
				'testimonial_content',
				'testimonial_name',
				'testimonial_job',
				'address',
				'phone',
				'email',
				'website',
				'message',
				'subject',
				'item_title',
				'item_description',
				'additional_information',
			]
		);
	}

	/**
	 * @return array<int,string>
	 */
	public static function elementor_repeater_container_keys() {
		return (array) apply_filters(
			'heb_pp_elementor_repeater_container_keys',
			[
				'items',
				'tabs',
				'slides',
				'accordion',
				'icon_list',
				'price_list',
				'social_icon_list',
				'form_fields',
				'carousel',
				'gallery',
				'buttons',
				'list_items',
				'features_list',
			]
		);
	}

	/**
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_elementor_translatable_setting_key( $key ) {
		static $cache = null;
		if ( null === $cache ) {
			$cache = array_flip( self::elementor_translatable_setting_keys() );
		}
		return isset( $cache[ $key ] );
	}

	/**
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function is_elementor_repeater_container_key( $key ) {
		static $cache = null;
		if ( null === $cache ) {
			$cache = array_flip( self::elementor_repeater_container_keys() );
		}
		return isset( $cache[ $key ] );
	}

	/**
	 * 清理 editor HTML 里 AI 误加的超大 inline 字号，保留正常排版。
	 *
	 * @param string $key           Setting key.
	 * @param string $html          Translated HTML.
	 * @param string $original_html Source HTML (optional).
	 * @return string
	 */
	private function sanitize_elementor_html_setting( $key, $html, $original_html = '' ) {
		unset( $key, $original_html );
		if ( false === strpos( $html, '<' ) ) {
			return $html;
		}
		$html = preg_replace_callback(
			'/\s*font-size\s*:\s*([^;"\'\s]+);?/i',
			static function ( $m ) {
				if ( preg_match( '/(\d+)/', (string) $m[1], $n ) && (int) $n[1] > 36 ) {
					return '';
				}
				return $m[0];
			},
			$html
		);
		$html = preg_replace_callback(
			'/\s*line-height\s*:\s*([^;"\'\s]+);?/i',
			static function ( $m ) {
				if ( preg_match( '/(\d+)/', (string) $m[1], $n ) && (int) $n[1] > 80 ) {
					return '';
				}
				return $m[0];
			},
			$html
		);
		return is_string( $html ) ? $html : '';
	}

	/**
	 * @param string $path Dot path.
	 * @return bool
	 */
	private function is_elementor_settings_context( $path ) {
		return (bool) preg_match( '/(?:^|\.)settings(\.|$)/', $path );
	}

	/**
	 * settings 内：收集非样式 / 非 skip 字段（不再仅限白名单，避免漏翻 widget 控件）。
	 *
	 * @param string $key  Field key.
	 * @param string $path Dot path.
	 * @return bool
	 */
	private function should_collect_elementor_setting_key( $key, $path ) {
		if ( ! $this->is_elementor_settings_context( $path ) ) {
			return true;
		}
		if ( ctype_digit( $key ) ) {
			return true;
		}
		if ( self::is_elementor_repeater_container_key( $key ) ) {
			return true;
		}
		return ! $this->should_skip_key( $key, $path ) && ! $this->is_elementor_style_key( $key, $path );
	}

	/**
	 * merge 时是否用译文覆盖该字符串 setting。
	 *
	 * @param string $key            Setting key.
	 * @param string $path           Dot path.
	 * @param string $original_value Source string.
	 * @return bool
	 */
	private function should_merge_elementor_setting_string( $key, $path, $original_value ) {
		if ( $this->should_skip_key( $key, $path ) || $this->is_elementor_style_key( $key, $path ) ) {
			return false;
		}
		return $this->looks_translatable( $original_value );
	}

	/**
	 * @param array<string,mixed> $original Original branch.
	 * @param array<string,mixed> $current  Translated branch.
	 * @param string              $path     Dot path for context.
	 * @return array<string,mixed>
	 * @deprecated 3.3.0-beta.23 保留兼容；新逻辑用 preserve_elementor_tree + merge_elementor_settings_deep。
	 */
	private function preserve_elementor_styles_recursive( array $original, array $current, $path ) {
		return $this->preserve_elementor_tree( $original, $current );
	}

	/**
	 * Elementor 控件里的纯样式键（非 editor 正文）。
	 *
	 * @param string $key  Field key.
	 * @param string $path Dot path.
	 * @return bool
	 */
	private function is_elementor_style_key( $key, $path ) {
		if ( preg_match( '/^typography_/i', $key ) || 'typography' === $key ) {
			return true;
		}
		if ( false === strpos( $path, 'elementor_data' ) && false === strpos( $path, 'elementor_page_settings' ) && 'settings' !== $path ) {
			return false;
		}
		return (bool) preg_match(
			'/^(size|unit|sizes|font_size|line_height|letter_spacing|word_spacing|height|width|max_width|min_height|gap|column_gap|row_gap|space_between|flex_|align_|justify_|object_|grid_|z_index|opacity|border_|background_|padding|margin|custom_css|stretch_section|content_width|text_color|title_color|link_color|hover_color|_css|typography)$/i',
			$key
		);
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

	/** 长 HTML 字符串自动切片阈值（字符数）。超过则按 block 边界拆段独立翻译。 */
	const HTML_SPLIT_THRESHOLD = 1200;

	/** 切片后每段的目标上限。 */
	const HTML_SEGMENT_TARGET = 1800;

	/** Path 后缀分隔符：segment 化字符串用 "<path>::seg<i>" 形式存。 */
	const SEGMENT_DELIM = '::seg';

	/**
	 * Bootstrap 队列内默认 strict：任一批次翻译失败则不写入子站，避免源语言残留。
	 * 手动 Dashboard 分发仍允许 warn + 源文兜底（filter 可改）。
	 *
	 * @return bool
	 */
	public static function strict_mode() {
		if ( class_exists( 'Heb_Product_Publisher_Admin_Settings', false )
			&& Heb_Product_Publisher_Admin_Settings::is_quality_translator() ) {
			return true;
		}
		if ( class_exists( 'Heb_Product_Publisher_Bootstrap_Worker', false )
			&& Heb_Product_Publisher_Bootstrap_Worker::in_bootstrap_item() ) {
			return true;
		}
		return (bool) apply_filters( 'heb_pp_translator_strict', false );
	}

	/**
	 * strict 模式下，翻译有错则中止远端写入。
	 *
	 * @param array<int,string> $errors 翻译错误列表。
	 * @return string|null 应中止时返回用户可读原因，否则 null。
	 */
	public static function strict_abort_reason( array $errors ) {
		if ( ! self::strict_mode() || empty( $errors ) ) {
			return null;
		}
		$preview = implode( ' | ', array_slice( array_map( 'strval', $errors ), 0, 2 ) );
		return sprintf(
			/* translators: %s: first translation errors */
			__( '翻译未完整完成，已拒绝写入子站（避免源语言残留）：%s', 'heb-product-publisher' ),
			$preview
		);
	}

	/**
	 * 质量优先：不预切片，保留整段 Elementor 上下文。
	 * 速度优先：长 HTML 按 block 切片。JSON 解析失败时仍会拆批重试（两种模式共有）。
	 *
	 * @return bool
	 */
	public static function html_split_enabled() {
		if ( class_exists( 'Heb_Product_Publisher_Admin_Settings', false ) ) {
			if ( Heb_Product_Publisher_Admin_Settings::is_quality_translator() ) {
				return (bool) apply_filters( 'heb_pp_translator_enable_html_split', false );
			}
			if ( Heb_Product_Publisher_Admin_Settings::PROFILE_SPEED === Heb_Product_Publisher_Admin_Settings::translator_profile() ) {
				return (bool) apply_filters( 'heb_pp_translator_enable_html_split', true );
			}
		}
		$model   = strtolower( (string) Heb_Product_Publisher_Admin_Settings::openrouter_model() );
		$default = ! preg_match( '/opus|sonnet|gpt-4|gpt-5|o1|o3|gemini.*pro|deepseek.*reason/i', $model );
		return (bool) apply_filters( 'heb_pp_translator_enable_html_split', $default );
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
				if ( ! $this->should_collect_elementor_setting_key( $key, $child ) ) {
					continue;
				}
				if ( $this->should_skip_key( $key, $child ) ) {
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

		// 长 HTML 字符串可选切片（默认关闭）；启用后按 block 边界拆段保完整度。
		if ( self::html_split_enabled() ) {
			$segments = $this->maybe_split_html( $value );
			if ( count( $segments ) > 1 ) {
				foreach ( $segments as $i => $seg ) {
					$out[ $path . self::SEGMENT_DELIM . $i ] = $seg;
				}
				return;
			}
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
				if ( ! $this->should_collect_elementor_setting_key( $key, $child ) ) {
					$out[ $k ] = $v;
					continue;
				}
				if ( $this->should_skip_key( $key, $child ) ) {
					$out[ $k ] = $v;
					continue;
				}
				$applied = $this->apply_strings( $v, $child, $map );
				if ( is_string( $applied ) && is_string( $v ) && $this->should_merge_elementor_setting_string( $key, $child, $v ) ) {
					$applied = $this->sanitize_elementor_html_setting( $key, $applied, $v );
				}
				$out[ $k ] = $applied;
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}

		// 1) 直接命中（未切片）
		if ( isset( $map[ $path ] ) ) {
			return $map[ $path ];
		}

		// 2) 切片命中：把 <path>::seg0 / seg1 / ... 按顺序拼回。
		//    任意一段缺失（翻译失败）则降级用源段，保证不丢内容。
		$prefix    = $path . self::SEGMENT_DELIM;
		$has_seg   = false;
		$segments  = [];
		// 注意：必须按数字顺序而不是 map insertion 顺序，避免 batch 乱序。
		for ( $i = 0; $i < 1000; $i++ ) {
			$k = $prefix . $i;
			if ( ! array_key_exists( $k, $map ) ) {
				// 检查更高序号是否存在；不存在则结束
				if ( $has_seg ) {
					break;
				}
				return $value; // 完全没切片，返回原值
			}
			$has_seg    = true;
			$segments[] = (string) $map[ $k ];
		}
		if ( $has_seg && ! empty( $segments ) ) {
			return implode( '', $segments );
		}
		return $value;
	}

	/**
	 * 长 HTML 字符串按 block 级标签边界拆段。
	 *
	 * 切分规则：
	 *  - 仅当字符串长度 ≥ HTML_SPLIT_THRESHOLD 且含足够 block-level 闭合标签时拆
	 *  - 在 </h1-6>、</p>、</li>、</table>、</figure>、</ul>、</ol>、</div>、
	 *    </section>、</article>、</blockquote> 之后切
	 *  - 相邻短段合并直到接近 HTML_SEGMENT_TARGET，避免碎太细导致 batch 数量过多
	 *  - 不破坏标签结构：lookbehind 切分点固定在闭合标签的右括号之后
	 *
	 * @param string $s Source string.
	 * @return array<int,string> 1+ 个 segments；长度为 1 表示不拆。
	 */
	private function maybe_split_html( $s ) {
		$len = strlen( $s );
		if ( $len < self::HTML_SPLIT_THRESHOLD ) {
			return [ $s ];
		}
		// 标签密度太低：很可能是普通长段落，不拆（避免破坏纯文本）。
		if ( substr_count( $s, '</' ) < 4 ) {
			return [ $s ];
		}

		// 在 block-level 闭合标签后切；用 lookbehind 保证切点在 ">"  之后。
		$pieces = preg_split(
			'~(?<=</(?:h[1-6]|p|li|table|figure|ul|ol|div|section|article|blockquote)>)~i',
			$s
		);
		if ( ! is_array( $pieces ) || count( $pieces ) < 2 ) {
			return [ $s ];
		}

		// 合并相邻短段：单段目标 ≈ HTML_SEGMENT_TARGET。
		$merged  = [];
		$current = '';
		foreach ( $pieces as $piece ) {
			$piece = (string) $piece;
			if ( '' === $piece ) {
				continue;
			}
			if ( '' === $current ) {
				$current = $piece;
				continue;
			}
			if ( strlen( $current ) + strlen( $piece ) > self::HTML_SEGMENT_TARGET ) {
				$merged[]  = $current;
				$current   = $piece;
			} else {
				$current .= $piece;
			}
		}
		if ( '' !== $current ) {
			$merged[] = $current;
		}

		// 极端情况单 piece 仍超大：再按 </p> / </li> 二次切；仍不行就放原值。
		$final = [];
		foreach ( $merged as $seg ) {
			if ( strlen( $seg ) <= self::HTML_SEGMENT_TARGET * 1.5 ) {
				$final[] = $seg;
				continue;
			}
			$sub = preg_split( '~(?<=</(?:p|li|td|th)>)~i', $seg );
			if ( ! is_array( $sub ) || count( $sub ) < 2 ) {
				$final[] = $seg; // 没法再切就接受这段大的
				continue;
			}
			$cur = '';
			foreach ( $sub as $part ) {
				$part = (string) $part;
				if ( '' === $part ) {
					continue;
				}
				if ( '' === $cur ) {
					$cur = $part;
					continue;
				}
				if ( strlen( $cur ) + strlen( $part ) > self::HTML_SEGMENT_TARGET ) {
					$final[] = $cur;
					$cur     = $part;
				} else {
					$cur .= $part;
				}
			}
			if ( '' !== $cur ) {
				$final[] = $cur;
			}
		}

		return count( $final ) > 1 ? array_values( $final ) : [ $s ];
	}

	/**
	 * @param string $key  Field/array key.
	 * @param string $path Dot path for context.
	 * @return bool
	 */
	private function should_skip_key( $key, $path = '' ) {
		if ( '' === $key ) {
			return false;
		}
		if ( preg_match( '/^typography_/i', $key ) || 'typography' === $key ) {
			return true;
		}
		$skip = self::skip_keys();
		if ( in_array( $key, $skip, true ) ) {
			return true;
		}
		if ( preg_match( '/(_slug|_id|_url|_link|_email)$/i', $key ) ) {
			return true;
		}
		// Elementor 控件后缀：_tablet / _mobile / _hover / _color / _typography / _shadow
		if ( preg_match( '/(_color|_typography|_shadow|_padding|_margin|_size|_position|_align|_animation|_transition|_border|_background|_overlay|_zoom|_opacity)(_(tablet|mobile|hover|active|focus|extra))?$/i', $key ) ) {
			return true;
		}
		if ( $this->is_elementor_style_key( $key, $path ) ) {
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
	 * 翻译单批；JSON 解析失败时自动拆成更小批次或 HTML 切片重试。
	 *
	 * @param array<string,string> $batch       path=>text。
	 * @param string               $src         源语言。
	 * @param string               $dst         目标语言。
	 * @param string               $api_key     API key.
	 * @param int                  $batch_index 批次序号（日志用）。
	 * @return array<string,string>|\WP_Error
	 */
	private function translate_batch_resilient( array $batch, $src, $dst, $api_key, $batch_index ) {
		if ( empty( $batch ) ) {
			return [];
		}

		$result = $this->call_openrouter_with_retry( $batch, $src, $dst, $api_key, $batch_index );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $this->is_json_parse_error( $result ) ) {
			return $result;
		}

		// 单条长 HTML：按 block 边界切片后逐段翻译。
		if ( 1 === count( $batch ) ) {
			$path = (string) array_key_first( $batch );
			$text = (string) $batch[ $path ];
			if ( strlen( $text ) >= self::HTML_SPLIT_THRESHOLD ) {
				$segments = $this->maybe_split_html( $text );
				if ( count( $segments ) > 1 ) {
					$merged  = [];
					$seg_idx = 0;
					foreach ( $segments as $i => $seg ) {
						++$seg_idx;
						$seg_path = $path . self::SEGMENT_DELIM . $i;
						$seg_res  = $this->call_openrouter_with_retry(
							[ $seg_path => (string) $seg ],
							$src,
							$dst,
							$api_key,
							$batch_index * 100 + $seg_idx
						);
						if ( is_wp_error( $seg_res ) ) {
							return $result;
						}
						$merged = array_merge( $merged, $seg_res );
					}
					return $merged;
				}
			}
			return $result;
		}

		// 多 key 批次：对半拆开后分别翻译。
		$chunk_size = (int) max( 1, ceil( count( $batch ) / 2 ) );
		$parts      = array_chunk( $batch, $chunk_size, true );
		if ( count( $parts ) < 2 ) {
			return $result;
		}

		$merged = [];
		$part_i = 0;
		foreach ( $parts as $part ) {
			++$part_i;
			if ( empty( $part ) ) {
				continue;
			}
			$sub = $this->translate_batch_resilient( $part, $src, $dst, $api_key, $batch_index * 10 + $part_i );
			if ( is_wp_error( $sub ) ) {
				return $result;
			}
			$merged = array_merge( $merged, $sub );
		}
		return $merged;
	}

	/**
	 * @param \WP_Error $err Error.
	 * @return bool
	 */
	private function is_json_parse_error( \WP_Error $err ) {
		$code = (string) $err->get_error_code();
		if ( in_array( $code, [ 'heb_pp_openrouter_parse', 'heb_pp_openrouter_shape' ], true ) ) {
			return true;
		}
		return false !== stripos( (string) $err->get_error_message(), 'JSON' );
	}

	/**
	 * 带指数退避重试的 OpenRouter 调用。仅对网络/HTTP 5xx/超时错误重试，
	 * 对 4xx（API key 错、模型不存在）不重试避免无意义阻塞。
	 *
	 * @param array<string,string> $batch       path=>text。
	 * @param string               $src         源语言。
	 * @param string               $dst         目标语言。
	 * @param string               $api_key     API key.
	 * @param int                  $batch_index 批次序号（用于日志可读性）。
	 * @return array<string,string>|\WP_Error
	 */
	private function call_openrouter_with_retry( array $batch, $src, $dst, $api_key, $batch_index = 0 ) {
		$max_retries = (int) apply_filters( 'heb_pp_translator_max_retries', self::MAX_RETRIES );
		if ( $max_retries < 0 ) {
			$max_retries = 0;
		}

		$last_err = null;
		$retried  = 0;
		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				// 指数退避：5s, 15s, 45s... 给 OpenRouter 端短暂喘息。
				$delay = (int) min( 60, 5 * pow( 3, $attempt - 1 ) );
				sleep( $delay );
				$retried = $attempt;
			}

			$result = $this->call_openrouter( $batch, $src, $dst, $api_key );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_err = $result;
			$code     = (string) $result->get_error_code();
			$msg      = (string) $result->get_error_message();

			// 4xx 类（HTTP 400/401/402/403/404）不重试 —— 模型不存在 / key 失效 /
			// 余额不够（402）/ 权限拒绝 / 输入超限，重试也是白费。
			if ( 'heb_pp_openrouter_http' === $code && preg_match( '/HTTP\s+4\d\d/i', $msg ) ) {
				break;
			}
			// shape / parse 错误（模型输出格式异常）值得重试一次，可能是偶发。
		}

		if ( $last_err instanceof \WP_Error ) {
			// 增强报错可读性：标注是哪一批、实际重试次数。HTTP 402（余额不足）单独
			// 给一个友好提示，免得用户对着 OpenRouter 原始 JSON 摸不着头脑。
			$orig = $last_err->get_error_message();
			$hint = '';
			if ( false !== stripos( $orig, 'HTTP 402' ) || false !== stripos( $orig, 'requires more credits' ) ) {
				$hint = __( ' [余额不足：到 OpenRouter → Settings → Credits 充值，或换便宜的模型如 google/gemini-2.5-flash]', 'heb-product-publisher' );
			} elseif ( false !== stripos( $orig, 'HTTP 401' ) || false !== stripos( $orig, 'HTTP 403' ) ) {
				$hint = __( ' [API key 无效或被拒：到 HEB Publisher 设置页检查 OpenRouter API Key]', 'heb-product-publisher' );
			}
			$enhanced = sprintf(
				/* translators: 1: batch index, 2: attempts count, 3: original error, 4: hint */
				__( '翻译批次 %1$d 失败（已重试 %2$d 次）：%3$s%4$s', 'heb-product-publisher' ),
				max( 1, (int) $batch_index ),
				(int) $retried,
				$orig,
				$hint
			);
			return new \WP_Error( $last_err->get_error_code(), $enhanced );
		}

		return new \WP_Error( 'heb_pp_openrouter_unknown', __( '翻译批次失败（未知错误）。', 'heb-product-publisher' ) );
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

		$system = "You are a professional translator specializing in B2B industrial product catalogs and Elementor-based website content. "
			. "Translate the string values of the given JSON object from {$src} to {$dst}. "
			. "Rules: "
			. "1) Output MUST be a single JSON object with exactly the same keys as the input; every input key must appear in the output. "
			. "2) Preserve HTML tags, attributes, entities and whitespace exactly; only translate visible text nodes. "
			. "3) Do NOT translate and KEEP verbatim: URLs, email addresses, numbers, measurements, dates, brand names, SKU/product codes (e.g. 108D/2, GRS, SD, FOB, T/T, L/C, D/P, D/A), file paths, CSS/HTML identifiers, placeholder tokens like {xxx}, %s, [tag], WordPress shortcodes like [elementor-template id=\"xx\"] or [contact-form-7 ...], and Yoast SEO variables wrapped in double percent signs like %%title%%, %%sep%%, %%sitename%%, %%page%%, %%primary_category%%. "
			. "4) If a value looks like an identifier/enum key (e.g. 'paypal', 'odm', 'oem', 'both', 'tt', 'lc', 'flex-start', 'center', 'auto', 'inherit', 'eager', 'lazy'), keep it unchanged. "
			. "5) For Elementor widget settings: only translate user-visible text like 'title', 'subtitle', 'description', 'button_text', 'tab_title', 'placeholder', 'label', 'caption', 'tooltip', editor HTML content. Do NOT translate or modify ANY typography/style values (font-size, line-height, typography_*, size, unit, colors, spacing, alignment enums). Never change numbers in JSON values. "
			. "6) Translate natural-language sentences, product descriptions and marketing copy into {$dst} using the appropriate professional industry terminology. "
			. "7) Return ONLY the JSON object. No explanation, no markdown fences, no preamble.";

		$user = wp_json_encode( $batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// 动态估算 max_tokens：避免 OpenRouter 按模型 context 上限预估成本
		// （GPT-5 默认 65536，导致账户余额不够时直接 HTTP 402 拒绝整批请求）。
		//
		// 估算规则：输出 tokens ≈ 输入字符数 × 1.6 ÷ 3
		//   - ×1.6：翻译可能膨胀（中文→日文/俄文字数比英文长；HTML 标签全保留）
		//   - ÷3：英文 1 token ≈ 3-4 chars；中文/日文 1 token ≈ 1.5-2 chars
		//   下限 1024（短 batch 避免输出被截断），上限 16384（避免再次撞预算）。
		$user_len   = strlen( (string) $user );
		$est_output = (int) ceil( $user_len * 2.2 / 3 );
		$max_tokens = max( 2048, min( 32768, $est_output ) );
		$max_tokens = (int) apply_filters( 'heb_pp_translator_max_tokens', $max_tokens, $user_len );

		$body = [
			'model'           => $model,
			'temperature'     => 0,
			'max_tokens'      => $max_tokens,
			'messages'        => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $user ],
			],
			'response_format' => [ 'type' => 'json_object' ],
		];

		$timeout = (int) apply_filters( 'heb_pp_translator_http_timeout', self::HTTP_TIMEOUT );
		if ( $timeout < 30 ) {
			$timeout = self::HTTP_TIMEOUT;
		}

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => $timeout,
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
		// ```json ... ``` 代码块（含未闭合 fence 的截断输出）。
		if ( preg_match( '/```(?:json)?\s*(\{[\s\S]*)/i', $raw, $fence ) ) {
			$inner = trim( (string) $fence[1] );
			$inner = preg_replace( '/\s*```$/', '', $inner );
			$try   = json_decode( (string) $inner, true );
			if ( is_array( $try ) ) {
				return $try;
			}
			$start = strpos( $inner, '{' );
			$end   = strrpos( $inner, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$try = json_decode( substr( $inner, $start, $end - $start + 1 ), true );
				if ( is_array( $try ) ) {
					return $try;
				}
			}
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
