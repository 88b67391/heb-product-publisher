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
	 * 把 ACF 值里的附件 ID 转换为图片 URL token（远端 sideload 使用）。
	 *
	 * @param mixed $value 任意 ACF 值。
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
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::encode_acf_for_transport( $v );
			}
			return $out;
		}

		return $value;
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
	 * 读取文章的分类（按 slug 导出）。
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array<int,string>>
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
			$slugs = [];
			foreach ( $terms as $t ) {
				if ( isset( $t->slug ) ) {
					$slugs[] = (string) $t->slug;
				}
			}
			if ( ! empty( $slugs ) ) {
				$out[ $tax ] = array_values( array_unique( $slugs ) );
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

		return [
			'post_type'      => $post->post_type,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'featured_url'   => $feat_url ? (string) $feat_url : '',
			'acf'            => $acf,
			'taxonomies'     => self::get_term_slugs_map( $post_id ),
			'seo'            => self::get_seo_meta( $post_id ),
			'source_post_id' => (int) $post_id,
			'source_site'    => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_locale'  => Heb_Product_Publisher_Admin_Settings::source_locale(),
			'source_modified' => (int) get_post_modified_time( 'U', true, $post_id ),
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
