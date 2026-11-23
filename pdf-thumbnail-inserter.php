<?php
/**
 * Plugin Name:       PDF Thumbnail Inserter
 * Plugin URI:        https://github.com/orloxgr/PDF-Thumbnail-Inserter
 * Description:       Generates and reuses PDF thumbnails, provides a shortcode and Gutenberg block, and adds editor helpers for inserting PDF thumbnail cards.
 * Version:           2.01.02
 * Author:            Byron Iniotakis
 * Author URI:        https://github.com/orloxgr
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pdf-thumbnail-inserter
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'PDF_Thumbnail_Inserter' ) ) {
    define( 'PDF_THUMBNAIL_INSERTER_FILE', __FILE__ );
    define( 'PDF_THUMBNAIL_INSERTER_PATH', plugin_dir_path( __FILE__ ) );
    define( 'PDF_THUMBNAIL_INSERTER_URL', plugin_dir_url( __FILE__ ) );
    require_once __DIR__ . '/includes/class-plugin.php';
    PDF_Thumbnail_Inserter::instance();
}
