<?php
// Make sure uninstall.php is being called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Define the option key and log file path since the main plugin file is not loaded.
define( 'TPI_FEEDS_OPTION', 'tpi_feeds' );

// Adjust the log file path to reflect your plugin directory.
// Here we assume that the uninstall.php file is in the plugin's root folder.
$plugin_dir = plugin_dir_path( __FILE__ );
define( 'TPI_LOG_FILE', $plugin_dir . 'logs/tpi.log' );

// Delete the plugin's options.
delete_option( TPI_FEEDS_OPTION );

// Delete the log file if it exists.
if ( file_exists( TPI_LOG_FILE ) ) {
    unlink( TPI_LOG_FILE );
}
?>
