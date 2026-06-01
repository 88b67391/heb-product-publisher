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
				$src_url = isset( $value['url'] ) ? self::normalize_media_url( (string) $value['url'] ) : '';
				if ( '' !== $src_url ) {
					return [
						'__heb_media'  => 'elementor_image',
						'__heb_url'    => $src_url,
						'__heb_alt'    => isset( $value['alt'] ) ? (string) $value['alt'] : '',
						'__heb_size'   => isset( $value['size'] ) ? (string) $value['size'] : '',
						'__heb_source' => isset( $value['source'] ) && '' !== $value['source'] ? (string) $value['source'] : 'library',
					];
				}
			}
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::encode_acf_for_transport( $v );
			}
			return $out;
		}

		return $value;
	}

	/**
	 * Elementor image 节点典型结构识别。
	 * 必须同时具备 id 和 url；url 必须是 http(s) 开头。
	 *
	 * @param array<mixed,mixed> $value Node value.
	 * @return bool
	 */
	private static function looks_like_elementor_image( array $value ) {
		if ( ! isset( $value['url'] ) ) {
			return false;
		}
		$url = $value['url'];
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			// 媒体库路径：即使没有 id 字段也视为图片（常见于 background_image）。
			if ( preg_match( '#/wp-content/uploads/#i', $url ) ) {
				return true;
			}
		} elseif ( preg_match( '#^/?wp-content/uploads/#i', $url ) ) {
			return true;
		}
		if ( ! preg_match( '#^https?://#i', $url ) && ! preg_match( '#^/?wp-content/uploads/#i', $url ) ) {
			return false;
		}
		if ( ! isset( $value['id'] ) ) {
			return preg_match( '#/wp-content/uploads/#i', $url ) || preg_match( '#^/?wp-content/uploads/#i', $url );
		}
		// id 必须看起来像 attachment ID 或者空字符串（来自 hover image 等可选项）。
		$id = $value['id'];
		if ( is_int( $id ) ) {
			return true;
		}
		if ( is_string( $id ) && ( '' === $id || ctype_digit( $id ) ) ) {
			return true;
		}
		return false;
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
		return $url;
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
}
