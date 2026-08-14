<?php
/**
 * Main plugin controller.
 *
 * @package CENEventMemberPicturesCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MPD_Plugin {
	/** BuddyBoss Extended Profile field IDs. */
	const XPROFILE_LAST_NAME_FIELD_ID = 2;
	const XPROFILE_TITLE_FIELD_ID   = 4;
	const XPROFILE_COMPANY_FIELD_ID = 5;

	/** BuddyBoss profile types that should never be included in exports. */
	const EXCLUDED_MEMBER_TYPES = array( 'admin', 'admins', 'staff', 'facilitator', 'facilitators' );

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
		add_action( 'admin_menu', array( $this, 'add_export_page' ), 20 );
		add_action( 'admin_post_mpd_export_pdf', array( $this, 'export_pdf' ) );
	}

	/** Add the export screen to The Events Calendar's Events menu. */
	public function add_export_page() {
		add_submenu_page(
			'edit.php?post_type=tribe_events',
			__( 'Export RSVP Pictures', 'cen-event-member-pictures-creator' ),
			__( 'Export Pictures', 'cen-event-member-pictures-creator' ),
			'edit_tribe_events',
			'mpd-export',
			array( $this, 'render_export_page' )
		);
	}

	/** Render the event selection screen. */
	public function render_export_page() {
		if ( ! current_user_can( 'edit_tribe_events' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export event attendees.', 'cen-event-member-pictures-creator' ),
				esc_html__( 'Access denied', 'cen-event-member-pictures-creator' ),
				array( 'response' => 403 )
			);
		}

		$events = $this->get_events();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export RSVP Pictures', 'cen-event-member-pictures-creator' ); ?></h1>
			<p><?php esc_html_e( 'Select one or more events. The PDF will contain each unique WordPress user who RSVP\'d as Going, including their name, title, company, email address, and WordPress avatar. Guest registrations without a WordPress account and members with Admin, Staff, or Facilitator BuddyBoss profile types are excluded.', 'cen-event-member-pictures-creator' ); ?></p>

			<?php if ( ! $this->event_tickets_is_available() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'The Event Tickets plugin must be active before RSVP attendees can be exported.', 'cen-event-member-pictures-creator' ); ?></p></div>
			<?php elseif ( isset( $_GET['mpd_notice'] ) && 'no_attendees' === sanitize_key( wp_unslash( $_GET['mpd_notice'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No eligible Going RSVP attendees were found for the selected events.', 'cen-event-member-pictures-creator' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $events ) ) : ?>
				<p><?php esc_html_e( 'No current or upcoming events were found.', 'cen-event-member-pictures-creator' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mpd_export_pdf">
					<?php wp_nonce_field( 'mpd_export_pdf' ); ?>
					<table class="widefat striped" style="max-width: 1000px">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="mpd-select-all" aria-label="<?php esc_attr_e( 'Select all events', 'cen-event-member-pictures-creator' ); ?>"></td>
								<th><?php esc_html_e( 'Event', 'cen-event-member-pictures-creator' ); ?></th>
								<th><?php esc_html_e( 'Start date', 'cen-event-member-pictures-creator' ); ?></th>
								<th><?php esc_html_e( 'Status', 'cen-event-member-pictures-creator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $events as $event ) : ?>
								<?php $status = get_post_status_object( $event->post_status ); ?>
								<tr>
									<th class="check-column"><input class="mpd-event" type="checkbox" name="event_ids[]" value="<?php echo esc_attr( $event->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'cen-event-member-pictures-creator' ), get_the_title( $event ) ) ); ?>"></th>
									<td><strong><?php echo esc_html( get_the_title( $event ) ); ?></strong></td>
									<td><?php echo esc_html( $this->get_event_start_date( $event->ID ) ); ?></td>
									<td><?php echo esc_html( $status ? $status->label : $event->post_status ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Download PDF', 'cen-event-member-pictures-creator' ) ); ?>
				</form>
				<script>
					document.getElementById('mpd-select-all').addEventListener('change', function () {
						document.querySelectorAll('.mpd-event').forEach(function (checkbox) {
							checkbox.checked = document.getElementById('mpd-select-all').checked;
						});
					});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Stream a PDF containing RSVP attendees from the selected events. */
	public function export_pdf() {
		if ( ! current_user_can( 'edit_tribe_events' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export event attendees.', 'cen-event-member-pictures-creator' ),
				esc_html__( 'Access denied', 'cen-event-member-pictures-creator' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'mpd_export_pdf' );

		if ( ! $this->event_tickets_is_available() ) {
			wp_die( esc_html__( 'The Event Tickets plugin is not active.', 'cen-event-member-pictures-creator' ) );
		}

		$submitted_ids = isset( $_POST['event_ids'] ) ? (array) wp_unslash( $_POST['event_ids'] ) : array();
		$event_ids     = array_values( array_unique( array_filter( array_map( 'absint', $submitted_ids ) ) ) );
		$event_ids     = array_values(
			array_filter(
				$event_ids,
				static function ( $event_id ) {
					return 'tribe_events' === get_post_type( $event_id );
				}
			)
		);

		if ( empty( $event_ids ) ) {
			wp_die(
				esc_html__( 'Please select at least one valid event.', 'cen-event-member-pictures-creator' ),
				esc_html__( 'No events selected', 'cen-event-member-pictures-creator' ),
				array( 'back_link' => true )
			);
		}

		$attendees = $this->get_unique_rsvp_attendees( $event_ids );
		if ( empty( $attendees ) ) {
			wp_safe_redirect( add_query_arg( 'mpd_notice', 'no_attendees', $this->get_export_page_url() ) );
			exit;
		}

		$pdf = new MPD_PDF();
		foreach ( $attendees as $attendee ) {
			$pdf->add_member(
				$attendee['name'],
				$attendee['email'],
				$this->get_pdf_avatar_path( $attendee['avatar_identity'], $attendee['cache_key'] ),
				$attendee['title'],
				$attendee['company']
			);
		}

		$pdf->download( 'event-rsvp-pictures-' . gmdate( 'Y-m-d' ) . '.pdf' );
	}

	/** Return current and upcoming events in ascending start-date order. */
	private function get_events() {
		return get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'meta_key'       => '_EventStartDate', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_EventEndDate',
						'value'   => current_time( 'mysql' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);
	}

	/** Format an event start date with TEC when available. */
	private function get_event_start_date( $event_id ) {
		if ( function_exists( 'tribe_get_start_date' ) ) {
			return tribe_get_start_date( $event_id, false, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		}

		return (string) get_post_meta( $event_id, '_EventStartDate', true );
	}

	/** Whether Event Tickets' RSVP provider is available. */
	private function event_tickets_is_available() {
		return class_exists( 'Tribe__Tickets__RSVP' );
	}

	/**
	 * Collect eligible Going RSVP attendees and deduplicate by user ID.
	 *
	 * @param int[] $event_ids Event post IDs.
	 * @return array<int,array{name:string,email:string,title:string,company:string,sort_last_name:string,avatar_identity:mixed,cache_key:string}>
	 */
	private function get_unique_rsvp_attendees( $event_ids ) {
		$rsvp   = Tribe__Tickets__RSVP::get_instance();
		$people = array();

		foreach ( $event_ids as $event_id ) {
			$event_attendees = $rsvp->get_attendees_by_id( $event_id );
			if ( ! is_array( $event_attendees ) ) {
				continue;
			}

			foreach ( $event_attendees as $attendee ) {
				if ( ! is_array( $attendee ) || ! $this->attendee_is_going( $attendee ) ) {
					continue;
				}

				$name    = trim( (string) $this->first_value( $attendee, array( 'holder_name', 'purchaser_name' ) ) );
				$email   = sanitize_email( $this->first_value( $attendee, array( 'holder_email', 'purchaser_email' ) ) );
				$user_id = isset( $attendee['user_id'] ) ? absint( $attendee['user_id'] ) : 0;

				if ( ! $user_id && $email ) {
					$user = get_user_by( 'email', $email );
					$user_id = $user ? $user->ID : 0;
				}

				if ( ! $user_id ) {
					continue;
				}

				if ( $this->user_has_excluded_member_type( $user_id ) ) {
					continue;
				}

				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}

				$name  = $name ? $name : $this->get_user_name( $user );
				$email = $email ? $email : $user->user_email;

				$key = 'user:' . $user_id;
				if ( isset( $people[ $key ] ) ) {
					continue;
				}

				$people[ $key ] = array(
					'name'            => $name,
					'email'           => $email,
					'title'           => $this->get_xprofile_field( $user_id, self::XPROFILE_TITLE_FIELD_ID ),
					'company'         => $this->get_xprofile_field( $user_id, self::XPROFILE_COMPANY_FIELD_ID ),
					'sort_last_name'  => $this->get_sort_last_name( $user, $name ),
					'avatar_identity' => $user_id,
					'cache_key'       => 'user-' . $user_id,
				);
			}
		}

		uasort(
			$people,
			static function ( $left, $right ) {
				$last_name_comparison = strcasecmp( $left['sort_last_name'], $right['sort_last_name'] );

				return 0 !== $last_name_comparison ? $last_name_comparison : strcasecmp( $left['name'], $right['name'] );
			}
		);

		return array_values( $people );
	}

	/** Return true when Event Tickets marks the RSVP as Going. */
	private function attendee_is_going( $attendee ) {
		$status = isset( $attendee['order_status'] ) ? strtolower( trim( (string) $attendee['order_status'] ) ) : '';

		return 'yes' === $status;
	}

	/** Get the first non-empty attendee field from a list of keys. */
	private function first_value( $attendee, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $attendee[ $key ] ) && '' !== trim( (string) $attendee[ $key ] ) ) {
				return $attendee[ $key ];
			}
		}

		return '';
	}

	/** Use first and last name, falling back to the account display name. */
	private function get_user_name( $user ) {
		$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
		$name  = trim( $first . ' ' . $last );

		return $name ? $name : $user->display_name;
	}

	/** Return true when a user has an excluded BuddyBoss/BuddyPress profile type. */
	private function user_has_excluded_member_type( $user_id ) {
		if ( ! function_exists( 'bp_get_member_type' ) ) {
			return false;
		}

		$member_types = bp_get_member_type( absint( $user_id ), false );
		if ( ! is_array( $member_types ) ) {
			return false;
		}

		foreach ( $member_types as $member_type ) {
			$names = array( $member_type );
			if ( function_exists( 'bp_get_member_type_object' ) ) {
				$type_object = bp_get_member_type_object( $member_type );
				if ( $type_object && ! empty( $type_object->labels ) ) {
					$names[] = isset( $type_object->labels['name'] ) ? $type_object->labels['name'] : '';
					$names[] = isset( $type_object->labels['singular_name'] ) ? $type_object->labels['singular_name'] : '';
				}
			}

			foreach ( $names as $name ) {
				if ( in_array( sanitize_title( (string) $name ), self::EXCLUDED_MEMBER_TYPES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** Return the best available last name for alphabetizing the PDF. */
	private function get_sort_last_name( $user, $display_name ) {
		$last_name = $this->get_xprofile_field( $user->ID, self::XPROFILE_LAST_NAME_FIELD_ID );
		if ( ! $last_name ) {
			$last_name = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
		}

		if ( ! $last_name ) {
			$name_parts = preg_split( '/\s+/', trim( (string) $display_name ) );
			$last_name  = $name_parts ? end( $name_parts ) : '';
		}

		return $last_name;
	}

	/** Return a BuddyBoss/BuddyPress Extended Profile field value. */
	private function get_xprofile_field( $user_id, $field_id ) {
		if ( ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}

		$value = xprofile_get_field_data( absint( $field_id ), absint( $user_id ), 'comma' );
		if ( is_wp_error( $value ) || is_array( $value ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( (string) $value ) );
	}

	/** Return the export page URL. */
	private function get_export_page_url() {
		return admin_url( 'edit.php?post_type=tribe_events&page=mpd-export' );
	}

	/**
	 * Download and cache a filtered WordPress avatar as a square JPEG.
	 *
	 * @param mixed  $identity  User ID or attendee email accepted by get_avatar_url().
	 * @param string $cache_key Safe, stable cache identifier.
	 * @return string Empty string when no usable avatar is available.
	 */
	private function get_pdf_avatar_path( $identity, $cache_key ) {
		if ( ! $identity ) {
			return '';
		}

		$avatar_url = get_avatar_url( $identity, array( 'size' => 600 ) );
		if ( ! $avatar_url ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}

		$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'event-rsvp-pictures';
		$target    = trailingslashit( $cache_dir ) . 'avatar-' . sanitize_file_name( $cache_key ) . '.jpg';
		if ( is_readable( $target ) && filemtime( $target ) > time() - WEEK_IN_SECONDS ) {
			return $target;
		}

		$response = wp_safe_remote_get(
			$avatar_url,
			array(
				'timeout'             => 12,
				'redirection'         => 3,
				'user-agent'          => 'CEN Event RSVP Pictures/' . MPD_VERSION,
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
		$temporary = wp_tempnam( 'mpd-avatar-' . $cache_key );
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
