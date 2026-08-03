<?php
/**
 * Plugin Name:       CEN Event Member Pictures Creator
 * Description:       Export RSVP attendee information and photos from selected Events Calendar events to PDF.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Custom Plugin
 * Text Domain:       cen-event-member-pictures-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPD_VERSION', '2.0.0' );
define( 'MPD_PATH', plugin_dir_path( __FILE__ ) );

require_once MPD_PATH . 'includes/class-mpd-pdf.php';
require_once MPD_PATH . 'includes/class-mpd-plugin.php';

MPD_Plugin::instance();
