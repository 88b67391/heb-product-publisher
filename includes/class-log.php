<?php
/**
 * 分发日志：自定义表 {prefix}heb_pp_log。
 *
 * 提供：建表（激活时调用）、插入、查询。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Log {

	const DB_VERSION_OPT = 'heb_pp_log_db_version';
	const DB_VERSION     = '1.0.0';

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'heb_pp_log';
	}

	/**
	 * 激活时调用。幂等。
	 */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$current = get_option( self::DB_VERSION_OPT, '' );
		if ( $current === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(40) NOT NULL DEFAULT '',
			post_title TEXT NULL,
			site_id VARCHAR(40) NOT NULL DEFAULT '',
			site_label VARCHAR(200) NOT NULL DEFAULT '',
			site_url VARCHAR(500) NOT NULL DEFAULT '',
			target_locale VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			message TEXT NULL,
			remote_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			remote_edit_url TEXT NULL,
			translated_strings INT NOT NULL DEFAULT 0,
			translated_total INT NOT NULL DEFAULT 0,
			duration_ms INT NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_post (post_id),
			KEY idx_site (site_id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	/**
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string,mixed> $row Row data.
	 * @return int Insert ID.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$defaults = [
			'post_id'            => 0,
			'post_type'          => '',
			'post_title'         => '',
			'site_id'            => '',
			'site_label'         => '',
			'site_url'           => '',
			'target_locale'      => '',
			'status'             => 'error',
			'message'            => '',
			'remote_post_id'     => 0,
			'remote_edit_url'    => '',
			'translated_strings' => 0,
			'translated_total'   => 0,
			'duration_ms'        => 0,
			'user_id'            => get_current_user_id(),
			'created_at'         => current_time( 'mysql' ),
		];
		$data = array_merge( $defaults, $row );
		foreach ( [ 'message', 'remote_edit_url', 'post_title' ] as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$data[ $k ] = is_string( $data[ $k ] ) ? substr( $data[ $k ], 0, 4000 ) : '';
			}
		}
		$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * 查询（分页）。
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array{items: array<int,object>, total:int}
	 */
	public static function query( array $args = [] ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			[
				'post_id'  => 0,
				'site_id'  => '',
				'status'   => '',
				'search'   => '',
				'per_page' => 30,
				'paged'    => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			]
		);

		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 'site_id = %s';
			$params[] = sanitize_text_field( (string) $args['site_id'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(post_title LIKE %s OR site_label LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby = in_array( (string) $args['orderby'], [ 'id', 'post_id', 'site_id', 'status', 'created_at', 'duration_ms' ], true )
			? $args['orderby']
			: 'created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$table = self::table();
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB
			$items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, [ $per_page, $offset ] ) ) ); // phpcs:ignore WordPress.DB
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB
			$items = $wpdb->get_results( $wpdb->prepare( $list_sql, [ $per_page, $offset ] ) ); // phpcs:ignore WordPress.DB
		}

		return [ 'items' => is_array( $items ) ? $items : [], 'total' => $total ];
	}

	/**
	 * 统计汇总。
	 *
	 * @return array<string,int>
	 */
	public static function summary() {
		global $wpdb;
		$t    = self::table();
		$row  = $wpdb->get_row( "SELECT COUNT(*) total, SUM(status='success') ok, SUM(status='error') err, SUM(translated_strings) strs FROM {$t}" ); // phpcs:ignore WordPress.DB
		return [
			'total'   => $row ? (int) $row->total : 0,
			'success' => $row ? (int) $row->ok : 0,
			'error'   => $row ? (int) $row->err : 0,
			'strings' => $row ? (int) $row->strs : 0,
		];
	}
}
