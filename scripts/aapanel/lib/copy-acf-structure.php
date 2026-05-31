<?php
/**
 * 跨站点复制 ACF 内部结构 post（acf-post-type / acf-field-group / acf-taxonomy）。
 *
 * 用法：
 *   wp eval-file copy-acf-structure.php export --path=/path/to/main > /tmp/acf.json
 *   wp eval-file copy-acf-structure.php import --path=/path/to/target < /tmp/acf.json
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$mode = isset( $args[0] ) ? (string) $args[0] : 'export';
$types = [ 'acf-post-type', 'acf-field-group', 'acf-taxonomy' ];

if ( 'export' === $mode ) {
	$out = [];
	foreach ( $types as $pt ) {
		$posts = get_posts(
			[
				'post_type'      => $pt,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		foreach ( $posts as $p ) {
			$meta = get_post_meta( $p->ID );
			$out[] = [
				'post_type'    => (string) $p->post_type,
				'post_title'   => (string) $p->post_title,
				'post_name'    => (string) $p->post_name,
				'post_content' => (string) $p->post_content,
				'post_excerpt' => (string) $p->post_excerpt,
				'post_status'  => (string) $p->post_status,
				'menu_order'   => (int) $p->menu_order,
				'meta'         => is_array( $meta ) ? $meta : [],
			];
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit( 0 );
}

if ( 'import' === $mode ) {
	$raw = stream_get_contents( STDIN );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		fwrite( STDERR, "Empty JSON on stdin.\n" );
		exit( 1 );
	}
	$items = json_decode( $raw, true );
	if ( ! is_array( $items ) ) {
		fwrite( STDERR, "Invalid JSON.\n" );
		exit( 1 );
	}
	$created = 0;
	$skipped = 0;
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['post_type'] ) || empty( $item['post_name'] ) ) {
			continue;
		}
		$pt   = sanitize_key( (string) $item['post_type'] );
		$name = sanitize_title( (string) $item['post_name'] );
		if ( ! in_array( $pt, $types, true ) ) {
			continue;
		}
		$existing = get_posts(
			[
				'post_type'      => $pt,
				'name'           => $name,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		if ( ! empty( $existing ) ) {
			++$skipped;
			continue;
		}
		$post_id = wp_insert_post(
			[
				'post_type'    => $pt,
				'post_title'   => isset( $item['post_title'] ) ? (string) $item['post_title'] : '',
				'post_name'    => $name,
				'post_content' => isset( $item['post_content'] ) ? (string) $item['post_content'] : '',
				'post_excerpt' => isset( $item['post_excerpt'] ) ? (string) $item['post_excerpt'] : '',
				'post_status'  => isset( $item['post_status'] ) ? (string) $item['post_status'] : 'publish',
				'menu_order'   => isset( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
			],
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}
		if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
			foreach ( $item['meta'] as $meta_key => $meta_values ) {
				if ( ! is_string( $meta_key ) || '' === $meta_key ) {
					continue;
				}
				delete_post_meta( $post_id, $meta_key );
				foreach ( (array) $meta_values as $meta_value ) {
					add_post_meta( $post_id, $meta_key, $meta_value );
				}
			}
		}
		++$created;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_json_encode( [ 'created' => $created, 'skipped' => $skipped ] );
	if ( function_exists( 'acf_get_store' ) ) {
		acf_get_store( 'post-types' )->reset();
		acf_get_store( 'taxonomies' )->reset();
	}
	exit( 0 );
}

fwrite( STDERR, "Unknown mode: {$mode}\n" );
exit( 1 );
