<?php
/**
 * 子站本地锁定：避免分站本地手动改动被主站再次分发时覆盖。
 *
 * 仅在 Receiver 模式 + 来自主站的 distributable post 上显示：
 *  - 编辑器顶部 banner：「本内容由主站托管，分发时会覆盖此处修改」
 *  - 侧栏 metabox 提供 "锁定本地副本（不接受主站推送）" 复选框
 *  - 锁定状态写入 post meta `_heb_pp_locked`，Receiver 的 /import-product 收到推送时
 *    若该 meta 为 "1" 则直接返回 success+locked 跳过更新（参见 class-receiver.php）
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Post_Lock {

	const META_LOCKED  = '_heb_pp_locked';
	const NONCE_NAME   = 'heb_pp_post_lock_nonce';
	const NONCE_ACTION = 'heb_pp_post_lock';

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
		add_action( 'admin_notices', [ $this, 'render_editor_banner' ] );
	}

	/**
	 * 当前 post 是否来自主站分发（含 source meta），用来决定要不要显示锁定 UI。
	 *
	 * @param int|\WP_Post|null $post Post or ID.
	 * @return bool
	 */
	private function is_distributed_post( $post = null ) {
		$p = get_post( $post );
		if ( ! $p instanceof \WP_Post ) {
			return false;
		}
		if ( ! function_exists( 'heb_pp_distributable_post_types' ) ) {
			return false;
		}
		if ( ! in_array( $p->post_type, heb_pp_distributable_post_types(), true ) ) {
			return false;
		}
		$src = get_post_meta( $p->ID, '_heb_publisher_source_post_id', true );
		return ! empty( $src );
	}

	public function register_meta_box() {
		if ( ! function_exists( 'heb_pp_distributable_post_types' ) ) {
			return;
		}
		foreach ( heb_pp_distributable_post_types() as $pt ) {
			add_meta_box(
				'heb_pp_post_lock',
				__( '主站托管 / 本地锁定', 'heb-product-publisher' ),
				[ $this, 'render_meta_box' ],
				$pt,
				'side',
				'low'
			);
		}
	}

	/**
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( $post ) {
		if ( ! $this->is_distributed_post( $post ) ) {
			?>
			<p class="description" style="margin:0;">
				<?php esc_html_e( '本内容尚未由主站分发产生，本地无需锁定。', 'heb-product-publisher' ); ?>
			</p>
			<?php
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$locked      = '1' === (string) get_post_meta( $post->ID, self::META_LOCKED, true );
		$source_id   = (int) get_post_meta( $post->ID, '_heb_publisher_source_post_id', true );
		$source_site = (string) get_post_meta( $post->ID, '_heb_publisher_source_site', true );
		?>
		<p style="margin:0 0 8px;">
			<label>
				<input type="checkbox" name="heb_pp_locked" value="1" <?php checked( $locked ); ?> />
				<strong><?php esc_html_e( '锁定本地副本', 'heb-product-publisher' ); ?></strong>
			</label>
		</p>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( '勾选后，主站再次分发本内容时，本地副本将保持不变（接收端返回 locked 状态）。适合在本地做了少量定制不希望被覆盖时使用。', 'heb-product-publisher' ); ?>
		</p>
		<p class="description" style="margin:0;font-size:11px;color:#666;">
			<?php
			printf(
				/* translators: 1: source site host, 2: source post id */
				esc_html__( '来源：%1$s · source_id = %2$d', 'heb-product-publisher' ),
				esc_html( '' !== $source_site ? $source_site : '(unknown)' ),
				$source_id
			);
			?>
		</p>
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
		if ( ! $this->is_distributed_post( $post ) ) {
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
		$lock = ! empty( $_POST['heb_pp_locked'] ) ? '1' : '';
		if ( '' === $lock ) {
			delete_post_meta( $post_id, self::META_LOCKED );
		} else {
			update_post_meta( $post_id, self::META_LOCKED, '1' );
		}
	}

	/**
	 * 在子站 distributable post 编辑器顶部显示主站托管提示。
	 */
	public function render_editor_banner() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		global $post;
		if ( ! $post instanceof \WP_Post || ! $this->is_distributed_post( $post ) ) {
			return;
		}
		$locked = '1' === (string) get_post_meta( $post->ID, self::META_LOCKED, true );

		$media = class_exists( 'Heb_Product_Publisher_Async_Media' )
			? Heb_Product_Publisher_Async_Media::progress( (int) $post->ID )
			: [ 'pending' => 0, 'status' => 'done', 'last_run' => 0 ];
		?>
		<div class="notice notice-info" style="margin-top:10px;">
			<p>
				<strong><?php esc_html_e( '此内容由主站托管', 'heb-product-publisher' ); ?></strong>
				&middot;
				<?php
				if ( $locked ) {
					esc_html_e( '当前已锁定，主站后续分发会跳过本地副本。', 'heb-product-publisher' );
				} else {
					esc_html_e( '主站再次分发会覆盖本地修改。若要保留本地改动，请在右侧勾选「锁定本地副本」。', 'heb-product-publisher' );
				}
				?>
			</p>
			<?php if ( (int) $media['pending'] > 0 || 0 === strpos( (string) $media['status'], 'failed' ) ) : ?>
				<p style="margin-top:6px;">
					<?php if ( (int) $media['pending'] > 0 ) : ?>
						<span style="color:#996800;">
							<?php
							printf(
								/* translators: %d: number of remote images still being sideloaded */
								esc_html__( '正在后台下载 Elementor 远端图片（剩余 %d 张），完成前页面会临时使用主站原图。', 'heb-product-publisher' ),
								(int) $media['pending']
							);
							?>
						</span>
					<?php elseif ( 0 === strpos( (string) $media['status'], 'failed' ) ) : ?>
						<span style="color:#b32d2e;">
							<?php
							printf(
								/* translators: %s: failure reason */
								esc_html__( '部分图片本地化失败：%s。已重试多次仍未成功，可在主站重新分发或在 Tools → Action Scheduler 手动重排。', 'heb-product-publisher' ),
								esc_html( (string) $media['status'] )
							);
							?>
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
