<?php
/**
 * Hub 端：构造待分发 payload + 把 ACF 图片转成传输 token。
 * 分类导出使用 slug（可跨站点）而不是 ID。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Sync {

	/**
	 * 把 ACF / Elementor 数据里的附件引用转换为图片 URL token（远端 sideload 使用）。
	 *
	 * 识别两种 shape：
	 *  - ACF image：纯 attachment ID（int 或 digit-string）
	 *  - Elementor image：`{ id, url, alt?, source?, size? }` 整体节点（递归到该节点时整体转 token）
	 *
	 * @param mixed $value 任意值。
	 * @return mixed
	 */
	public static function encode_acf_for_transport( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$id = (int) $value;
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				$url = wp_get_attachment_image_url( $id, 'full' );
				if ( $url ) {
					return [
						'__heb_media' => 'image',
						'__heb_url'   => $url,
					];
				}
			}
			return $value;
		}

		if ( is_array( $value ) ) {
			// Elementor image shape：{ id: int, url: string, alt?, source?, size? }
			// 整个节点替换为 transport token，避免子节点被翻译器扫到。
			if ( self::looks_like_elementor_image( $value ) ) {
				$src_url = self::resolve_elementor_image_url( $value );
				if ( '' !== $src_url ) {
					return self::make_elementor_image_token( $value, $src_url );
				}
			}
			$out = [];
			foreach ( $value as $k => $v ) {
				$key = (string) $k;
				// 部分控件把背景图存成字符串 URL（非 {id,url} 对象）。
				if ( is_string( $v ) && self::is_elementor_image_field_key( $key ) ) {
					$src_url = self::normalize_media_url( $v );
					if ( self::is_transportable_image_url( $src_url ) ) {
						$out[ $k ] = self::make_elementor_image_token( [], $src_url );
						continue;
					}
				}
				$out[ $k ] = self::encode_acf_for_transport( $v );
			}
			return $out;
		}

		return $value;
	}

	/**
	 * Elementor image 节点典型结构识别（background_image / 幻灯片 / 图片控件等）。
	 *
	 * @param array<mixed,mixed> $value Node value.
	 * @return bool
	 */
	private static function looks_like_elementor_image( array $value ) {
		$url = isset( $value['url'] ) && is_string( $value['url'] ) ? trim( $value['url'] ) : '';
		if ( '' !== $url && self::is_transportable_image_url( $url ) ) {
			return true;
		}
		return self::elementor_image_attachment_id( $value ) > 0;
	}

	/**
	 * @param array<mixed,mixed> $value Elementor image node.
	 * @return int
	 */
	public static function elementor_image_attachment_id( array $value ) {
		if ( ! isset( $value['id'] ) ) {
			return 0;
		}
		$id = $value['id'];
		if ( is_int( $id ) ) {
			return max( 0, $id );
		}
		if ( is_string( $id ) && ctype_digit( $id ) ) {
			return (int) $id;
		}
		return 0;
	}

	/**
	 * 解析 Elementor 图片节点的 sideload 源 URL（含仅 id 无 url 的背景图）。
	 *
	 * @param array<mixed,mixed> $value Elementor image node.
	 * @return string
	 */
	public static function resolve_elementor_image_url( array $value ) {
		$url = isset( $value['url'] ) && is_string( $value['url'] ) ? self::normalize_media_url( $value['url'] ) : '';
		if ( '' !== $url && self::is_transportable_image_url( $url ) ) {
			return $url;
		}
		$id = self::elementor_image_attachment_id( $value );
		if ( $id > 0 && wp_attachment_is_image( $id ) ) {
			$resolved = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				return self::normalize_media_url( $resolved );
			}
		}
		return '';
	}

	/**
	 * URL 是否应纳入 Elementor 图片 sideload（uploads、常见图片扩展名）。
	 *
	 * @param string $url Raw or normalized URL.
	 * @return bool
	 */
	public static function is_transportable_image_url( $url ) {
		$url = self::normalize_media_url( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( 0 === strpos( $url, 'data:' ) ) {
			return false;
		}
		if ( preg_match( '#/wp-content/uploads/#i', $url ) ) {
			return true;
		}
		if ( preg_match( '#^https?://#i', $url ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?|#|$)/i', $url ) ) {
			return true;
		}
		return false;
	}

	/**
	 * 从 Elementor custom CSS 中提取 background / content url(...) 图片地址。
	 *
	 * @param string $css CSS fragment.
	 * @return array<int,string>
	 */
	public static function extract_urls_from_css( $css ) {
		$css = (string) $css;
		if ( '' === $css || false === stripos( $css, 'url(' ) ) {
			return [];
		}
		$urls = [];
		if ( preg_match_all( '~url\(\s*[\'"]?(.*?)[\'"]?\s*\)~i', $css, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$raw = trim( (string) $raw );
				if ( '' === $raw || 0 === strpos( $raw, 'data:' ) ) {
					continue;
				}
				$norm = self::normalize_media_url( $raw );
				if ( self::is_transportable_image_url( $norm ) ) {
					$urls[] = $norm;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * 递归收集 Elementor 树 / settings 中 custom_css 里的待 sideload URL。
	 *
	 * @param mixed              $node  Elementor branch.
	 * @param array<int,string>  $urls  Collector (by ref).
	 */
	public static function collect_elementor_css_media_urls( $node, array &$urls ) {
		if ( is_string( $node ) ) {
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $child ) {
			if ( is_string( $key ) && is_string( $child ) && preg_match( '/custom_css|_css$/i', $key ) ) {
				foreach ( self::extract_urls_from_css( $child ) as $u ) {
					$urls[] = $u;
				}
			}
			self::collect_elementor_css_media_urls( $child, $urls );
		}
	}

	/**
	 * 相对 uploads 路径 → 绝对 URL，便于子站 sideload。
	 *
	 * @param string $url Media URL or path.
	 * @return string
	 */
	public static function normalize_media_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( '/' === $url[0] ) {
			return home_url( $url );
		}
		if ( preg_match( '#^wp-content/uploads/#i', $url ) ) {
			return home_url( '/' . ltrim( $url, '/' ) );
		}
		// Elementor 偶发相对路径：2024/06/foo.jpg 或 uploads/2024/06/foo.jpg
		if ( preg_match( '#^\d{4}/#', $url ) || preg_match( '#^uploads/#i', $url ) ) {
			return home_url( '/wp-content/uploads/' . ltrim( $url, '/' ) );
		}
		return $url;
	}

	/**
	 * @param array<mixed,mixed> $value Elementor image node (可为空数组)。
	 * @param string             $src_url Normalized URL.
	 * @return array<string,string>
	 */
	private static function make_elementor_image_token( array $value, $src_url ) {
		return [
			'__heb_media'  => 'elementor_image',
			'__heb_url'    => (string) $src_url,
			'__heb_alt'    => isset( $value['alt'] ) ? (string) $value['alt'] : '',
			'__heb_size'   => isset( $value['size'] ) ? (string) $value['size'] : '',
			'__heb_source' => isset( $value['source'] ) && '' !== (string) $value['source'] ? (string) $value['source'] : 'library',
		];
	}

	/**
	 * Elementor 控件里常见图片字段名（含 background_image / overlay 等）。
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	private static function is_elementor_image_field_key( $key ) {
		return (bool) preg_match( '/(?:^|_)(?:background_)?(?:image|photo|logo|icon|avatar|picture)(?:_|$)/i', (string) $key );
	}

	/**
	 * 读取 Elementor 主体数据（`_elementor_data` post meta），转为 array 并把图片转 transport token。
	 *
	 * 返回结构：array<int, element>，每个 element 是 Elementor 的 section / column / widget。
	 * 若 post 不是 Elementor 编辑或数据为空，返回空数组（Receiver 端会跳过）。
	 *
	 * @param int $post_id Post id.
	 * @return array<int,mixed>
	 */
	public static function get_elementor_data( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$encoded = self::encode_acf_for_transport( $decoded );
		return is_array( $encoded ) ? $encoded : [];
	}

	/**
	 * Elementor 模板专有的附加 meta（conditions / priority / preview 等），
	 * 仅对 elementor_library 类型的 post 有意义；其他类型返回空数组。
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	public static function collect_elementor_extra_meta( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		// 只对 elementor_library 收集这些 meta，避免污染产品/页面的 payload。
		if ( 'elementor_library' !== $post->post_type ) {
			return [];
		}
		$keys = (array) apply_filters(
			'heb_pp_elementor_extra_meta_keys',
			[
				'_elementor_conditions',
				'_elementor_priority',
				'_elementor_template_include',
				'_elementor_template_disable',
				'_wp_page_template',
			]
		);
		$out = [];
		foreach ( $keys as $k ) {
			$v = get_post_meta( $post->ID, $k, true );
			if ( '' !== $v && null !== $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * 读取 Elementor 页面级设置（`_elementor_page_settings`），不含媒体。
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	public static function get_elementor_page_settings( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$encoded = self::encode_acf_for_transport( $raw );
		return is_array( $encoded ) ? $encoded : [];
	}

	/**
	 * 读取文章原始 ACF 数据（不经 formatting）。
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_acf_raw( $post_id ) {
		if ( ! function_exists( 'get_fields' ) ) {
			return [];
		}
		$fields = get_fields( $post_id, false );
		return is_array( $fields ) ? $fields : [];
	}

	/**
	 * 读取文章的分类，导出为 Receiver 友好的对象数组。
	 *
	 * v3.0 起每个 term 导出为 `{ source_term_id, slug_fallback, name, source_parent_term_id }`，
	 * 让 Receiver 端能按 source_term_id 反向关联本地已存 term，避免重复创建 slug 污染。
	 * 老版本 Receiver 不识别新字段时会回退到 slug_fallback，行为等同于旧版本。
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array<int, array<string,mixed>>>
	 */
	public static function get_term_slugs_map( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		$out = [];
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, [ 'fields' => 'all' ] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$rows = [];
			foreach ( $terms as $t ) {
				if ( ! isset( $t->slug ) ) {
					continue;
				}
				$rows[] = [
					'source_term_id'        => (int) $t->term_id,
					'slug_fallback'         => (string) $t->slug,
					'name'                  => (string) $t->name,
					'source_parent_term_id' => isset( $t->parent ) ? (int) $t->parent : 0,
				];
			}
			if ( ! empty( $rows ) ) {
				$out[ $tax ] = $rows;
			}
		}
		return $out;
	}

	/**
	 * 构造推送 payload（未翻译原文）。
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function build_payload( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		$distributable = heb_pp_distributable_post_types();
		if ( ! in_array( $post->post_type, $distributable, true ) ) {
			return [];
		}

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		$feat_url = $thumb_id && wp_attachment_is_image( $thumb_id )
			? wp_get_attachment_image_url( $thumb_id, 'full' )
			: '';

		$acf = self::encode_acf_for_transport( self::get_acf_raw( $post_id ) );

		$elementor_data          = self::get_elementor_data( $post_id );
		$elementor_settings      = self::get_elementor_page_settings( $post_id );
		$elementor_version       = (string) get_post_meta( $post_id, '_elementor_version', true );
		$elementor_edit_mode     = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		$elementor_template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
		$elementor_extra_meta    = self::collect_elementor_extra_meta( $post_id );

		return [
			'post_type'              => $post->post_type,
			'title'                  => $post->post_title,
			'slug'                   => $post->post_name,
			'content'                => $post->post_content,
			'excerpt'                => $post->post_excerpt,
			'status'                 => $post->post_status,
			'featured_url'           => $feat_url ? (string) $feat_url : '',
			'acf'                    => $acf,
			'elementor_data'          => $elementor_data,
			'elementor_page_settings' => $elementor_settings,
			'elementor_version'       => $elementor_version,
			'elementor_edit_mode'     => $elementor_edit_mode,
			'elementor_template_type' => $elementor_template_type,
			'elementor_extra_meta'    => $elementor_extra_meta,
			'taxonomies'             => self::get_term_slugs_map( $post_id ),
			'seo'                    => self::get_seo_meta( $post_id ),
			'source_post_id'         => (int) $post_id,
			'source_parent_id'       => is_post_type_hierarchical( $post->post_type ) ? (int) $post->post_parent : 0,
			'source_site'            => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_locale'          => Heb_Product_Publisher_Admin_Settings::source_locale(),
			'source_modified'        => (int) get_post_modified_time( 'U', true, $post_id ),
		];
	}

	/**
	 * 收集 Yoast SEO 相关 post meta（只保留有值的字段）。
	 *
	 * 键名使用"语义名"而不是 Yoast 原始 key，Receiver 再映射回 meta key，避免
	 * 目标站未装 Yoast 时污染 DB。
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>
	 */
	public static function get_seo_meta( $post_id ) {
		$map = [
			'title'          => '_yoast_wpseo_title',
			'metadesc'       => '_yoast_wpseo_metadesc',
			'focuskw'        => '_yoast_wpseo_focuskw',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'twitter_title'  => '_yoast_wpseo_twitter-title',
			'twitter_desc'   => '_yoast_wpseo_twitter-description',
		];
		$out = [];
		foreach ( $map as $sem => $mk ) {
			$v = get_post_meta( $post_id, $mk, true );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$out[ $sem ] = $v;
			}
		}
		return $out;
	}

	/**
	 * 给 Receiver 用：语义名 → Yoast meta key。
	 *
	 * @return array<string,string>
	 */
	public static function seo_key_map() {
		return [
			'title'          => '_yoast_wpseo_title',
			'metadesc'       => '_yoast_wpseo_metadesc',
			'focuskw'        => '_yoast_wpseo_focuskw',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'twitter_title'  => '_yoast_wpseo_twitter-title',
			'twitter_desc'   => '_yoast_wpseo_twitter-description',
		];
	}

	/**
	 * 统计 Elementor 树中的 widget 数量（用于分发日志诊断）。
	 *
	 * @param mixed $nodes elementor_data 根数组。
	 * @return int
	 */
	public static function count_elementor_widgets( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( ! empty( $node['widgetType'] ) ) {
				$count++;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$count += self::count_elementor_widgets( $node['elements'] );
			}
		}
		return $count;
	}

	/**
	 * 扫描 payload 中引用的内嵌 Elementor 模板 ID（[elementor-template id="…"]）。
	 *
	 * @param mixed $value 任意节点。
	 * @return array<int,int>
	 */
	public static function find_embedded_elementor_template_ids( $value ) {
		$ids = [];
		self::walk_embedded_elementor_template_ids( $value, $ids );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * 扫描 Elementor settings 里引用的模板/Loop 模板 ID
	 * （loop_item_template_id / template_id / selected_template_id）。
	 * 子站需先分发这些 elementor_library 模板，否则 remap 失败 → Loop 无样式。
	 *
	 * @param mixed $value elementor_data 根节点。
	 * @return array<int,int>
	 */
	public static function find_referenced_template_ids( $value ) {
		$ids  = [];
		$keys = [ 'loop_item_template_id', 'template_id', 'selected_template_id' ];
		self::walk_referenced_template_ids( $value, $keys, $ids );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * @param mixed          $value 当前值。
	 * @param array<int,string> $keys  目标键名。
	 * @param array<int,int> $ids   收集器（引用）。
	 */
	private static function walk_referenced_template_ids( $value, array $keys, array &$ids ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && in_array( $k, $keys, true ) && ( is_numeric( $v ) || ctype_digit( (string) $v ) ) ) {
				$ids[] = (int) $v;
			}
			self::walk_referenced_template_ids( $v, $keys, $ids );
		}
	}

	/**
	 * @param mixed              $value 当前值。
	 * @param array<int,int>     $ids   收集器（引用）。
	 */
	private static function walk_embedded_elementor_template_ids( $value, array &$ids ) {
		if ( is_string( $value ) ) {
			if ( preg_match_all( '/\[elementor-template[^\]]*\bid=["\']?(\d+)["\']?/i', $value, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $v ) {
			self::walk_embedded_elementor_template_ids( $v, $ids );
		}
	}
}
