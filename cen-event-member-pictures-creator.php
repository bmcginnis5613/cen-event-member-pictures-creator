<?php
/**
 * Plugin Name:       CEN Event Member Pictures Creator
 * Description:       Export RSVP attendee information and photos from selected Events Calendar events to PDF.
 * Version:           2.2.2
 * Author:            FirstTracks Marketing
 * Author URI:        https://firsttracksmarketing.com
 * Text Domain:       cen-event-member-pictures-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPD_VERSION', '2.2.2' );
define( 'MPD_PATH', plugin_dir_path( __FILE__ ) );

require_once MPD_PATH . 'includes/class-mpd-pdf.php';
require_once MPD_PATH . 'includes/class-mpd-plugin.php';

MPD_Plugin::instance();
