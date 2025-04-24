<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register admin menu
add_action( 'admin_menu', 'tpi_admin_menu' );
function tpi_admin_menu() {
    add_menu_page(
        'Ultimate Podcast Importer',
        'Podcast Importer',
        'manage_options',
        'tpi_admin',
        'tpi_render_admin_page',
        'dashicons-microphone',
        80
    );
}

function tpi_render_admin_page() {
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'add_feed';

    // Handle delete action
    if ( $active_tab === 'delete_feed' && isset( $_GET['id'] ) ) {
        tpi_handle_delete_feed();
        $active_tab = 'manage_feeds';
    }
    ?>
    <div class="wrap">
        <h2>Ultimate Podcast Importer</h2>
        <h2 class="nav-tab-wrapper">
            <a href="?page=tpi_admin&tab=add_feed" class="nav-tab <?php echo ( $active_tab === 'add_feed' ) ? 'nav-tab-active' : ''; ?>">Add Feed</a>
            <a href="?page=tpi_admin&tab=manage_feeds" class="nav-tab <?php echo ( $active_tab === 'manage_feeds' ) ? 'nav-tab-active' : ''; ?>">Manage Feeds</a>
            <a href="?page=tpi_admin&tab=logs" class="nav-tab <?php echo ( $active_tab === 'logs' ) ? 'nav-tab-active' : ''; ?>">Logs</a>
            <a href="?page=tpi_admin&tab=help" class="nav-tab <?php echo ( $active_tab === 'help' ) ? 'nav-tab-active' : ''; ?>">Help</a>
        </h2>
        <?php
            if ( $active_tab === 'edit_feed' && isset( $_GET['id'] ) ) {
                include_once TPI_PLUGIN_DIR . 'templates/edit-feed.php';
            } elseif ( $active_tab === 'add_feed' ) {
                include_once TPI_PLUGIN_DIR . 'templates/add-feed.php';
            } elseif ( $active_tab === 'manage_feeds' ) {
                include_once TPI_PLUGIN_DIR . 'templates/feed-table.php';
            } elseif ( $active_tab === 'logs' ) {
                include_once TPI_PLUGIN_DIR . 'templates/logs.php';
            } elseif ( $active_tab === 'help' ) {
                include_once TPI_PLUGIN_DIR . 'templates/help.php';
            }
        ?>
    </div>
    <?php
}

/**
 * AJAX handler: Initialize or update a feed.
 */
add_action( 'wp_ajax_tpi_import_feed_ajax', 'tpi_import_feed_ajax' );
function tpi_import_feed_ajax() {
    check_ajax_referer( 'tpi_import_nonce', 'nonce' );

    $feed_url       = esc_url_raw( $_POST['feed_url'] );
    $import_date    = sanitize_text_field( $_POST['import_date'] );
    $post_status    = sanitize_text_field( $_POST['post_status'] );
    $default_cat    = sanitize_text_field( $_POST['default_cat'] );
    $ongoing_import = isset( $_POST['ongoing_import'] ) ? 1 : 0;
    $auto_fetch     = absint( $_POST['auto_fetch'] ) ?: 60;
    $author         = intval( $_POST['author'] );
    $auto_update    = isset( $_POST['auto_update'] ) ? 1 : 0;

    $feed_data = array(
        'feed_url'           => $feed_url,
        'import_date'        => $import_date,
        'post_status'        => $post_status,
        'default_cat'        => $default_cat,
        'ongoing_import'     => $ongoing_import,
        'auto_fetch'         => $auto_fetch,
        'author'             => $author,
        'auto_update'        => $auto_update,
        'imported_at'        => '',
        'last_fetched'       => '',
        'first_import_count' => 0,
        'auto_fetched_count' => 0,
        'last_modified'      => '',
    );

    // Save feed settings
    $feed_id = md5( $feed_url );
    $feeds   = get_option( TPI_FEEDS_OPTION, array() );
    $feeds[ $feed_id ] = $feed_data;
    update_option( TPI_FEEDS_OPTION, $feeds );

    // Schedule per-feed cron
    wp_clear_scheduled_hook( 'tpi_run_feed_import', array( $feed_id ) );
    if ( $ongoing_import ) {
        wp_schedule_event(
            time(),
            "every_{$auto_fetch}_minutes",
            'tpi_run_feed_import',
            array( $feed_id )
        );
    }

    wp_send_json_success( array( 'feed_id' => $feed_id ) );
}

/**
 * Handle deletion of a feed and its related data & cron job.
 */
function tpi_handle_delete_feed() {
    if ( isset( $_GET['id'], $_GET['_wpnonce'] ) 
         && wp_verify_nonce( $_GET['_wpnonce'], 'tpi_delete_feed_' . $_GET['id'] ) ) {

        $feed_id = sanitize_text_field( $_GET['id'] );
        $feeds   = get_option( TPI_FEEDS_OPTION, array() );

        if ( isset( $feeds[ $feed_id ] ) ) {
            // Unschedule its cron
            wp_clear_scheduled_hook( 'tpi_run_feed_import', array( $feed_id ) );
            // Remove it
            unset( $feeds[ $feed_id ] );
            update_option( TPI_FEEDS_OPTION, $feeds );
            echo '<div class="updated"><p>Feed and its cron job deleted successfully.</p></div>';
        }
    }
}
