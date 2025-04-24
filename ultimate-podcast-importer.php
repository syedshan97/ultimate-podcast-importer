<?php
/*
Plugin Name: Ultimate Podcast Importer
Description: Imports podcast episodes from RSS feeds as WordPress posts – with per-feed cron scheduling and structured logs.
Version:     1.3.1
Author:      Shan
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
//  Constants & Setup
// -----------------------------------------------------------------------------

define( 'TPI_FEEDS_OPTION', 'tpi_feeds' );
define( 'TPI_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'TPI_LOG_DIR',      TPI_PLUGIN_DIR . 'logs/' );
define( 'TPI_LOG_FILE',     TPI_LOG_DIR . 'tpi.log' );

// Ensure logs directory exists
if ( ! file_exists( TPI_LOG_DIR ) ) {
    wp_mkdir_p( TPI_LOG_DIR );
}

// -----------------------------------------------------------------------------
//  Core Includes
// -----------------------------------------------------------------------------

require_once TPI_PLUGIN_DIR . 'includes/admin-menu.php';
require_once TPI_PLUGIN_DIR . 'includes/feed-importer.php';
require_once TPI_PLUGIN_DIR . 'includes/feed-functions.php';
require_once TPI_PLUGIN_DIR . 'includes/settings.php';

// -----------------------------------------------------------------------------
//  Activation / Deactivation: clear any old cron hooks
// -----------------------------------------------------------------------------

register_activation_hook( __FILE__, 'tpi_clear_legacy_crons' );
register_deactivation_hook( __FILE__, 'tpi_clear_legacy_crons' );

function tpi_clear_legacy_crons() {
    // Remove any leftover hourly or frequent imports
    wp_clear_scheduled_hook( 'tpi_hourly_import' );
    wp_clear_scheduled_hook( 'tpi_frequent_import' );
}

// -----------------------------------------------------------------------------
//  Dynamic Per-Feed Cron Schedules
// -----------------------------------------------------------------------------

add_filter( 'cron_schedules', 'tpi_add_dynamic_cron_schedules' );
function tpi_add_dynamic_cron_schedules( $schedules ) {
    $feeds = get_option( TPI_FEEDS_OPTION, array() );
    foreach ( $feeds as $feed ) {
        $min = intval( $feed['auto_fetch'] );
        if ( $min > 0 ) {
            $key = "every_{$min}_minutes";
            if ( empty( $schedules[ $key ] ) ) {
                $schedules[ $key ] = array(
                    'interval' => $min * 60,
                    'display'  => sprintf( __( 'Every %d Minutes', 'ultimate-podcast-importer' ), $min ),
                );
            }
        }
    }
    return $schedules;
}

// -----------------------------------------------------------------------------
//  Enqueue Admin CSS/JS & Localize AJAX
// -----------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', 'tpi_enqueue_admin_assets' );
function tpi_enqueue_admin_assets( $hook ) {
    // Only on our plugin’s admin page
    if ( strpos( $hook, 'tpi_admin' ) === false ) {
        return;
    }

    // CSS
    wp_enqueue_style( 'tpi-admin-style',
        plugins_url( 'assets/css/style.css', __FILE__ ),
        array(),
        filemtime( TPI_PLUGIN_DIR . 'assets/css/style.css' )
    );

    // JS
    wp_enqueue_script( 'tpi-admin-script',
        plugins_url( 'assets/js/script.js', __FILE__ ),
        array( 'jquery' ),
        filemtime( TPI_PLUGIN_DIR . 'assets/js/script.js' ),
        true
    );

    // Localize a *relative* ajaxurl so CORS never trips:
    wp_localize_script( 'tpi-admin-script', 'tpi_ajax', array(
        'nonce'   => wp_create_nonce( 'tpi_import_nonce' ),
        'ajaxurl' => '/wp-admin/admin-ajax.php',
    ) );
}
