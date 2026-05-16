<?php
/**
 * 给非分发型 post type（默认 page / post）提供 hreflang 映射 UI。
 *
 * 数据存到与产品一致的 `_heb_pp_lang_map` post meta，
 * 这样 class-hreflang.php 的输出器无需区分来源。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Page_Lang_Map {

	const META_LANG_MAP = '_heb_pp_lang_map';
	const NONCE_NAME    = 'heb_pp_lang_map_nonce';
	const NONCE_ACTION  = 'heb_pp_lang_map_save';

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
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	/**
	 * 默认 page / post。可通过过滤器扩展。可分发型（产品）由分发流程自动维护，无需此 UI。
	 *
	 * @return array<int,string>
	 */
	public static function supported_post_types() {
		$pts          = (array) apply_filters( 'heb_pp_lang_map_post_types', [ 'page', 'post' ] );
		$distributable = function_exists( 'heb_pp_distributable_post_types' )
			? heb_pp_distributable_post_types()
			: [];
		$out = [];
		foreach ( $pts as $pt ) {
			$pt = sanitize_key( (string) $pt );
			if ( '' === $pt || in_array( $pt, $distributable, true ) ) {
				continue;
			}
			if ( post_type_exists( $pt ) ) {
				$out[] = $pt;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public function register_meta_box() {
		foreach ( self::supported_post_types() as $pt ) {
			add_meta_box(
				'heb_pp_lang_map',
				__( '跨语言版本（hreflang）', 'heb-product-publisher' ),
				[ $this, 'render_meta_box' ],
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$stored = get_post_meta( $post->ID, self::META_LANG_MAP, true );
		$stored = is_array( $stored ) ? $stored : [];

		// 收集面板里要展示的语言：远端站点 locale + source_locale + 已存自定义键。
		$rows = [];

		// 源站语言（主站才有意义；其他站也允许显示）。
		$src_locale = Heb_Product_Publisher_Admin_Settings::source_locale();
		$src_lang   = Heb_Product_Publisher_Hreflang::normalize_lang( $src_locale );
		if ( '' !== $src_lang ) {
			$rows[ $src_lang ] = [
				'label'   => sprintf(
					/* translators: %s: locale code */
					__( '本站语言（%s）', 'heb-product-publisher' ),
					$src_locale
				),
				'value'   => isset( $stored[ $src_lang ] ) ? (string) $stored[ $src_lang ] : (string) get_permalink( $post ),
				'builtin' => true,
			];
		}

		// 远端站点 locale_override。
		foreach ( Heb_Product_Publisher_Admin_Settings::remote_sites() as $site ) {
			$loc  = isset( $site['locale_override'] ) ? (string) $site['locale_override'] : '';
			$lang = Heb_Product_Publisher_Hreflang::normalize_lang( $loc );
			if ( '' === $lang ) {
				continue;
			}
			if ( isset( $rows[ $lang ] ) ) {
				continue;
			}
			$rows[ $lang ] = [
				'label'   => sprintf(
					'%s（%s）',
					isset( $site['label'] ) && '' !== $site['label'] ? $site['label'] : $site['url'],
					$loc
				),
				'value'   => isset( $stored[ $lang ] ) ? (string) $stored[ $lang ] : '',
				'builtin' => true,
			];
		}

		// 已存的、面板里没列出来的（用户加过的"其他语言"），全部继续显示并允许编辑。
		foreach ( $stored as $lang => $url ) {
			$lang = Heb_Product_Publisher_Hreflang::normalize_lang( (string) $lang );
			if ( '' === $lang || isset( $rows[ $lang ] ) ) {
				continue;
			}
			$rows[ $lang ] = [
				'label'   => sprintf(
					/* translators: %s: language code */
					__( '其他语言（%s）', 'heb-product-publisher' ),
					$lang
				),
				'value'   => (string) $url,
				'builtin' => false,
			];
		}

		?>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( '为每个语言版本填入完整 URL；留空表示当前页面在该语言没有对应版本。', 'heb-product-publisher' ); ?>
		</p>

		<?php if ( empty( $rows ) ) : ?>
			<p><em><?php esc_html_e( '请先到「设置 → HEB Publisher」配置源语言或远端站点，本面板会自动列出语言。', 'heb-product-publisher' ); ?></em></p>
		<?php else : ?>
			<table class="form-table" role="presentation" style="margin:0;">
				<tbody>
				<?php foreach ( $rows as $lang => $row ) : ?>
					<tr>
						<th scope="row" style="padding:6px 0;width:auto;font-weight:normal;">
							<label for="heb_pp_lang_map_<?php echo esc_attr( $lang ); ?>">
								<code><?php echo esc_html( $lang ); ?></code>
								<span class="description" style="display:block;font-size:11px;color:#666;">
									<?php echo esc_html( $row['label'] ); ?>
								</span>
							</label>
						</th>
						<td style="padding:6px 0;">
							<input
								type="url"
								class="widefat code"
								id="heb_pp_lang_map_<?php echo esc_attr( $lang ); ?>"
								name="heb_pp_lang_map[<?php echo esc_attr( $lang ); ?>]"
								value="<?php echo esc_attr( $row['value'] ); ?>"
								placeholder="https://..."
							/>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr style="margin:12px 0 8px;" />
		<p style="margin:0 0 4px;font-weight:600;"><?php esc_html_e( '其他语言（可选）', 'heb-product-publisher' ); ?></p>
		<p class="description" style="margin:0 0 4px;font-size:11px;">
			<?php esc_html_e( '按 lang|url 每行一条，例如：de|https://de.example.com/about/', 'heb-product-publisher' ); ?>
		</p>
		<textarea
			name="heb_pp_lang_map_extra"
			rows="3"
			class="widefat code"
			placeholder="de|https://de.example.com/about/"
		></textarea>
		<?php
	}

	/**
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$map = [];

		if ( isset( $_POST['heb_pp_lang_map'] ) && is_array( $_POST['heb_pp_lang_map'] ) ) {
			foreach ( wp_unslash( $_POST['heb_pp_lang_map'] ) as $lang => $url ) {
				$lang = Heb_Product_Publisher_Hreflang::normalize_lang( (string) $lang );
				$url  = esc_url_raw( trim( (string) $url ) );
				if ( '' === $lang || '' === $url ) {
					continue;
				}
				$map[ $lang ] = $url;
			}
		}

		if ( ! empty( $_POST['heb_pp_lang_map_extra'] ) ) {
			$extra = (string) wp_unslash( $_POST['heb_pp_lang_map_extra'] );
			$lines = preg_split( '/\r\n|\r|\n/', $extra );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = explode( '|', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				$lang = Heb_Product_Publisher_Hreflang::normalize_lang( trim( $parts[0] ) );
				$url  = esc_url_raw( trim( $parts[1] ) );
				if ( '' === $lang || '' === $url ) {
					continue;
				}
				$map[ $lang ] = $url;
			}
		}

		if ( empty( $map ) ) {
			delete_post_meta( $post_id, self::META_LANG_MAP );
			return;
		}
		update_post_meta( $post_id, self::META_LANG_MAP, $map );
	}
}
