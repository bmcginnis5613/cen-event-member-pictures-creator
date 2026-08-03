<?php
/**
 * Plugin Name:       WordPress User Photo Directory
 * Description:       Export every WordPress user's name, email, and avatar to a photo directory PDF.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Custom Plugin
 * License:           GPL-2.0-or-later
 * Text Domain:       member-photo-directory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPD_VERSION', '1.2.0' );
define( 'MPD_PATH', plugin_dir_path( __FILE__ ) );

require_once MPD_PATH . 'includes/class-mpd-pdf.php';
require_once MPD_PATH . 'includes/class-mpd-plugin.php';

MPD_Plugin::instance();
