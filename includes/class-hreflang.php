<?php
/**
 * Hreflang 输出器。
 *
 * 数据源：post meta `_heb_pp_lang_map`，结构 [ lang => permalink ]。
 * 产品 post 由 Hub 分发流程自动维护；page/post 由手动 meta box 维护（见 class-page-lang-map.php）。
 *
 * 输出位置：wp_head 优先级 1（早于 Yoast/SEO 插件以避免被它们的渲染流程截断）。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Hreflang {

	const META_LANG_MAP      = '_heb_pp_lang_map';
	const TERM_META_LANG_MAP = '_heb_pp_term_lang_map';

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
		add_action( 'wp_head', [ $this, 'render' ], 1 );
	}

	/**
	 * 输出 hreflang `<link>` 标签集。
	 */
	public function render() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! Heb_Product_Publisher_Admin_Settings::hreflang_enabled() ) {
			return;
		}

		$map = [];
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return;
			}
			if ( 'publish' !== $post->post_status ) {
				return;
			}
			$map = self::collect_map_for_post( $post );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$map = self::collect_map_for_term( $term );
		} else {
			return;
		}

		if ( count( $map ) < 2 ) {
			return;
		}

		$xdefault_lang = Heb_Product_Publisher_Admin_Settings::hreflang_xdefault_lang();
		$xdefault_url  = '';
		if ( '' !== $xdefault_lang && isset( $map[ $xdefault_lang ] ) ) {
			$xdefault_url = $map[ $xdefault_lang ];
		} else {
			$xdefault_url = (string) reset( $map );
		}

		echo "\n<!-- HEB Publisher hreflang -->\n";
		foreach ( $map as $lang => $url ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $lang ),
				esc_url( $url )
			);
		}
		if ( '' !== $xdefault_url ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $xdefault_url )
			);
		}
		echo "<!-- /HEB Publisher hreflang -->\n";
	}

	/**
	 * 收集当前 post 的 lang_map 并做规范化、自身兜底。
	 *
	 * 关键防御：若 lang_map 里没有当前页面（少见，slug 改过或 meta 未回写），
	 * 自动加入「当前 lang → permalink」，保证「自含」原则。
	 *
	 * @param \WP_Post $post Current post.
	 * @return array<string,string>
	 */
	public static function collect_map_for_post( \WP_Post $post ) {
		$raw = get_post_meta( $post->ID, self::META_LANG_MAP, true );
		$map = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $lang => $url ) {
				$lang = self::normalize_lang( (string) $lang );
				$url  = esc_url_raw( (string) $url );
				if ( '' === $lang || '' === $url ) {
					continue;
				}
				$map[ $lang ] = $url;
			}
		}

		$current_lang = self::current_site_lang();
		$current_url  = (string) get_permalink( $post );
		if ( '' !== $current_lang && '' !== $current_url && ! isset( $map[ $current_lang ] ) ) {
			$map[ $current_lang ] = $current_url;
		}

		return $map;
	}

	/**
	 * 收集当前 term 的 lang_map 并做规范化、自身兜底。
	 *
	 * 与 collect_map_for_post 同样的 "self-contained" 原则：
	 * lang_map 里若没有当前 lang，会自动补上当前 term archive URL。
	 *
	 * @param \WP_Term $term Current term.
	 * @return array<string,string>
	 */
	public static function collect_map_for_term( \WP_Term $term ) {
		$raw = get_term_meta( $term->term_id, self::TERM_META_LANG_MAP, true );
		$map = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $lang => $url ) {
				$lang = self::normalize_lang( (string) $lang );
				$url  = esc_url_raw( (string) $url );
				if ( '' === $lang || '' === $url ) {
					continue;
				}
				$map[ $lang ] = $url;
			}
		}
		$current_lang = self::current_site_lang();
		$current_url  = get_term_link( $term );
		if ( '' !== $current_lang && ! is_wp_error( $current_url ) && is_string( $current_url ) && '' !== $current_url && ! isset( $map[ $current_lang ] ) ) {
			$map[ $current_lang ] = $current_url;
		}
		return $map;
	}

	/**
	 * 当前 WP 站点对应的 hreflang 语言代码。
	 *
	 * 始终以 WordPress 站点语言（设置 → 常规 → 站点语言）为准——
	 * 这才是站点对外呈现给用户的真实语言；插件里"源语言（source_locale）"
	 * 是主站翻译流程用的字段，跟当前站点的 hreflang 没关系，否则克隆站点
	 * 时一并复制过来会导致子站误认成主站语言。
	 *
	 * 退路：get_locale() 为空时（极少见）才退回 source_locale。
	 *
	 * @return string
	 */
	public static function current_site_lang() {
		$loc = (string) get_locale();
		if ( '' === $loc && class_exists( 'Heb_Product_Publisher_Admin_Settings' ) ) {
			$loc = Heb_Product_Publisher_Admin_Settings::source_locale();
		}
		return self::normalize_lang( $loc );
	}

	/**
	 * 把 WP locale（如 en_US、zh_CN）规范化为 hreflang 格式（en、zh-CN）。
	 *
	 * 单语言（en/ja/fr 等）直接小写；带地区（en_US/zh_CN）转为 `xx-YY` 并保留地区码。
	 * 其他奇怪输入：取小写第一段。
	 *
	 * @param string $raw Raw locale or lang code.
	 * @return string
	 */
	public static function normalize_lang( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( '_', '-', $raw );
		if ( ! preg_match( '/^[a-z0-9\-]+$/', $raw ) ) {
			return '';
		}
		if ( false === strpos( $raw, '-' ) ) {
			return $raw;
		}
		$parts = explode( '-', $raw, 2 );
		$lang  = $parts[0];
		$reg   = strtoupper( $parts[1] );
		if ( '' === $lang ) {
			return '';
		}
		return '' !== $reg ? $lang . '-' . $reg : $lang;
	}
}
