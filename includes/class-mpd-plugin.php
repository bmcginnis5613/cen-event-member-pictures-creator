<?php
/**
 * Main plugin controller.
 *
 * @package MemberPhotoDirectory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MPD_Plugin {
	/** @var MPD_Plugin|null */
	private static $instance = null;

	/** Get the plugin instance. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_export_page' ) );
		add_action( 'admin_post_mpd_export_pdf', array( $this, 'export_pdf' ) );
	}

	/** Add the export screen under the standard Users menu. */
	public function add_export_page() {
		add_users_page(
			__( 'Export User Directory', 'member-photo-directory' ),
			__( 'Export Directory PDF', 'member-photo-directory' ),
			'list_users',
			'mpd-export',
			array( $this, 'render_export_page' )
		);
	}

	/** Render the wp-admin export screen. */
	public function render_export_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export WordPress User Directory', 'member-photo-directory' ); ?></h1>
			<p><?php esc_html_e( 'The directory automatically includes every WordPress user and reads their first name, last name, email address, and WordPress avatar.', 'member-photo-directory' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $this->get_export_url() ); ?>"><?php esc_html_e( 'Download PDF', 'member-photo-directory' ); ?></a></p>
		</div>
		<?php
	}

	/** Return the nonce-protected PDF URL. */
	private function get_export_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=mpd_export_pdf' ), 'mpd_export_pdf' );
	}

	/** Stream a PDF containing every WordPress user. */
	public function export_pdf() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export the user directory.', 'member-photo-directory' ),
				esc_html__( 'Access denied', 'member-photo-directory' ),
				array( 'response' => 403 )
			);
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mpd_export_pdf' ) ) {
			wp_die(
				esc_html__( 'The PDF link has expired. Please return to the directory and try again.', 'member-photo-directory' ),
				esc_html__( 'PDF link expired', 'member-photo-directory' ),
				array( 'response' => 403 )
			);
		}

		$pdf = new MPD_PDF();
		foreach ( $this->get_directory_users() as $user ) {
			$pdf->add_member(
				$this->get_user_name( $user ),
				$user->user_email,
				$this->get_pdf_avatar_path( $user->ID )
			);
		}

		$pdf->download( 'wordpress-user-directory-' . gmdate( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * Return every WordPress user, sorted by display name.
	 *
	 * @return WP_User[]
	 */
	private function get_directory_users() {
		return get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);
	}

	/** Use first and last name, falling back to the account display name. */
	private function get_user_name( $user ) {
		$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
		$name  = trim( $first . ' ' . $last );

		return $name ? $name : $user->display_name;
	}

	/**
	 * Download and cache a user's filtered WordPress avatar as a square JPEG.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Empty string when no usable avatar is available.
	 */
	private function get_pdf_avatar_path( $user_id ) {
		$avatar_url = get_avatar_url(
			$user_id,
			array(
				'size' => 600,
			)
		);
		if ( ! $avatar_url ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}

		$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'member-photo-directory';
		$target    = trailingslashit( $cache_dir ) . 'avatar-' . absint( $user_id ) . '.jpg';
		if ( is_readable( $target ) && filemtime( $target ) > time() - WEEK_IN_SECONDS ) {
			return $target;
		}

		$response = wp_safe_remote_get(
			$avatar_url,
			array(
				'timeout'     => 12,
				'redirection' => 3,
				'user-agent'  => 'WordPress Member Photo Directory/' . MPD_VERSION,
				'limit_response_size' => 5 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return is_readable( $target ) ? $target : '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return is_readable( $target ) ? $target : '';
		}

		wp_mkdir_p( $cache_dir );
		$temporary = wp_tempnam( 'mpd-avatar-' . $user_id );
		if ( ! $temporary || false === file_put_contents( $temporary, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return is_readable( $target ) ? $target : '';
		}

		$editor = wp_get_image_editor( $temporary );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $temporary );
			return is_readable( $target ) ? $target : '';
		}

		$editor->resize( 600, 600, true );
		$saved = $editor->save( $target, 'image/jpeg' );
		wp_delete_file( $temporary );

		return is_wp_error( $saved ) ? '' : $saved['path'];
	}
}
