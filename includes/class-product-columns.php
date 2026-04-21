<?php
/**
 * 在可分发的 post type 列表页增加"分发状态"列。
 *
 * 数据来源：post meta `_heb_pp_distributions`。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Product_Columns {

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
		add_action( 'admin_init', [ $this, 'register_columns' ] );
		add_action( 'admin_print_styles-edit.php', [ $this, 'inline_styles' ] );
	}

	public function register_columns() {
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$new = [];
		foreach ( $columns as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['heb_pp_dist'] = __( '分发状态', 'heb-product-publisher' );
			}
		}
		if ( ! isset( $new['heb_pp_dist'] ) ) {
			$new['heb_pp_dist'] = __( '分发状态', 'heb-product-publisher' );
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'heb_pp_dist' !== $column ) {
			return;
		}
		$sites         = Heb_Product_Publisher_Admin_Settings::remote_sites();
		$distributions = get_post_meta( $post_id, '_heb_pp_distributions', true );
		$distributions = is_array( $distributions ) ? $distributions : [];

		if ( empty( $sites ) ) {
			echo '<span class="heb-pp-dim">—</span>';
			return;
		}

		$log_base = admin_url( 'tools.php?page=heb-pp-log' );
		echo '<div class="heb-pp-dist-cell">';
		foreach ( $sites as $site ) {
			$sid   = (string) ( isset( $site['id'] ) ? $site['id'] : '' );
			$label = (string) ( isset( $site['label'] ) ? $site['label'] : $sid );
			$short = mb_substr( $label, 0, 12 );
			$d     = isset( $distributions[ $sid ] ) ? $distributions[ $sid ] : null;

			if ( ! $d ) {
				printf(
					'<span class="heb-pp-pill heb-pp-pill--none" title="%1$s">%2$s</span>',
					esc_attr( $label . ' · ' . __( '尚未分发', 'heb-product-publisher' ) ),
					esc_html( $short )
				);
				continue;
			}

			$status = isset( $d['last_status'] ) ? (string) $d['last_status'] : '';
			$class  = 'success' === $status ? 'heb-pp-pill--ok' : ( 'error' === $status ? 'heb-pp-pill--err' : 'heb-pp-pill--none' );
			$icon   = 'success' === $status ? '✓' : ( 'error' === $status ? '✕' : '•' );
			$when   = isset( $d['last_sent_at'] ) ? (int) $d['last_sent_at'] : 0;
			$when_s = $when ? human_time_diff( $when, time() ) . __( '前', 'heb-product-publisher' ) : '';

			$log_url = add_query_arg(
				[ 'post_id' => $post_id, 'site_id' => $sid ],
				$log_base
			);
			$edit_url = isset( $d['remote_edit_url'] ) ? (string) $d['remote_edit_url'] : '';

			if ( $edit_url ) {
				printf(
					'<a class="heb-pp-pill %1$s" href="%2$s" target="_blank" rel="noopener" title="%3$s">%4$s %5$s</a>',
					esc_attr( $class ),
					esc_url( $edit_url ),
					esc_attr( $label . ' · ' . $status . ( $when_s ? ' · ' . $when_s : '' ) ),
					esc_html( $icon ),
					esc_html( $short )
				);
			} else {
				printf(
					'<a class="heb-pp-pill %1$s" href="%2$s" title="%3$s">%4$s %5$s</a>',
					esc_attr( $class ),
					esc_url( $log_url ),
					esc_attr( $label . ' · ' . $status . ( $when_s ? ' · ' . $when_s : '' ) ),
					esc_html( $icon ),
					esc_html( $short )
				);
			}
		}
		echo '</div>';
	}

	public function inline_styles() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, heb_pp_distributable_post_types(), true ) ) {
			return;
		}
		?>
		<style>
			.column-heb_pp_dist { width: 260px; }
			.heb-pp-dist-cell { display:flex; flex-wrap:wrap; gap:4px; }
			.heb-pp-pill {
				display:inline-flex; align-items:center; gap:4px;
				padding:2px 8px; font-size:11px; border-radius:10px;
				line-height:1.6; text-decoration:none; border:1px solid transparent;
			}
			.heb-pp-pill--ok   { background:#e6f7ea; color:#00692b; border-color:#bfe6c9; }
			.heb-pp-pill--err  { background:#fde7e9; color:#9b1c1c; border-color:#f3b9be; }
			.heb-pp-pill--none { background:#f1f1f1; color:#666;    border-color:#e0e0e0; }
			.heb-pp-pill:hover { filter:brightness(0.97); }
			.heb-pp-dim { color:#999; }
		</style>
		<?php
	}
}
