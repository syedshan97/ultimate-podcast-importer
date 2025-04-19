<?php
/*
Plugin Name: Ultimate Podcast Importer
Description: Imports podcast episodes from RSS feeds as WordPress posts – including featured images, audio embedding, and auto-updates (if enabled). Provides an admin UI with feed management, logs, and an AJAX-based progress system.
Version: 1.3
Author: Shan
*/

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants.
define( 'TPI_FEEDS_OPTION', 'tpi_feeds' );
define( 'TPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPI_LOG_DIR', TPI_PLUGIN_DIR . 'logs/' );
define( 'TPI_LOG_FILE', TPI_LOG_DIR . 'tpi.log' );

// Ensure logs folder exists.
if ( ! file_exists( TPI_LOG_DIR ) ) {
    mkdir( TPI_LOG_DIR, 0755, true );
}

// Include required files.
require_once TPI_PLUGIN_DIR . 'includes/admin-menu.php';
require_once TPI_PLUGIN_DIR . 'includes/feed-importer.php';
require_once TPI_PLUGIN_DIR . 'includes/feed-functions.php';
require_once TPI_PLUGIN_DIR . 'includes/settings.php';

// Enqueue admin assets.
function tpi_enqueue_scripts($hook) {
    // Only load on our plugin's admin page.
    if ($hook !== 'toplevel_page_tpi_admin') return;

    wp_enqueue_style('tpi-style', plugins_url('assets/css/style.css', __FILE__));
    wp_enqueue_script('tpi-script', plugins_url('assets/js/script.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('tpi-script', 'tpi_ajax', array(
         'nonce'   => wp_create_nonce('tpi_import_nonce'),
         'ajaxurl' => admin_url('admin-ajax.php')
    ));
}
add_action('admin_enqueue_scripts', 'tpi_enqueue_scripts');

// Activation & deactivation hooks.
register_activation_hook( __FILE__, 'tpi_activate' );
register_deactivation_hook( __FILE__, 'tpi_deactivate' );

function tpi_activate() {
    if ( ! wp_next_scheduled( 'tpi_hourly_import' ) ) {
        wp_schedule_event( time(), 'hourly', 'tpi_hourly_import' );
    }
}

function tpi_deactivate() {
    wp_clear_scheduled_hook( 'tpi_hourly_import' );
}

// Cron callback: Process feeds for new imports and auto-updates.
add_action( 'tpi_hourly_import', 'tpi_cron_import_and_update' );
function tpi_cron_import_and_update() {
    $feeds = get_option( TPI_FEEDS_OPTION, array() );
    if ( ! empty( $feeds ) ) {
        foreach ( $feeds as $feed_id => $feed_data ) {
            // Process new items if ongoing_import is enabled.
            if ( ! empty( $feed_data['ongoing_import'] ) ) {
                tpi_log( "Cron: Starting auto-import for feed {$feed_data['feed_url']}" );
                tpi_process_feed( $feed_data, false, $feed_id, true );
                tpi_log( "Cron: Completed auto-import for feed {$feed_data['feed_url']}" );
            }
            // Process updates if auto_update is enabled.
            if ( ! empty( $feed_data['auto_update'] ) && $feed_data['auto_update'] == 1 ) {
                tpi_log( "Cron: Checking for updates in feed {$feed_data['feed_url']}" );
                tpi_update_feed_items( $feed_data, $feed_id );
            }
        }
    }
}
