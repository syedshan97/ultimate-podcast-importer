<?php
// Register admin menu.
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

    // Handle delete action.
    if ( $active_tab == 'delete_feed' && isset( $_GET['id'] ) ) {
        tpi_handle_delete_feed();
        $active_tab = 'manage_feeds';
    }
    ?>
    <div class="wrap">
        <h2>Ultimate Podcast Importer</h2>
        <h2 class="nav-tab-wrapper">
            <a href="?page=tpi_admin&tab=add_feed" class="nav-tab <?php echo ( $active_tab == 'add_feed' ) ? 'nav-tab-active' : ''; ?>">Add Feed</a>
            <a href="?page=tpi_admin&tab=manage_feeds" class="nav-tab <?php echo ( $active_tab == 'manage_feeds' ) ? 'nav-tab-active' : ''; ?>">Manage Feeds</a>
            <a href="?page=tpi_admin&tab=logs" class="nav-tab <?php echo ( $active_tab == 'logs' ) ? 'nav-tab-active' : ''; ?>">Logs</a>
            <a href="?page=tpi_admin&tab=help" class="nav-tab <?php echo ( $active_tab == 'help' ) ? 'nav-tab-active' : ''; ?>">Help</a>
        </h2>
        <?php
            // Check if the active tab is "edit_feed" and an ID is provided.
            if ( $active_tab == 'edit_feed' && isset( $_GET['id'] ) ) {
                include_once TPI_PLUGIN_DIR . 'templates/edit-feed.php';
            } elseif ( $active_tab == 'add_feed' ) {
                include_once TPI_PLUGIN_DIR . 'templates/add-feed.php';
            } elseif ( $active_tab == 'manage_feeds' ) {
                include_once TPI_PLUGIN_DIR . 'templates/feed-table.php';
            } elseif ( $active_tab == 'logs' ) {
                include_once TPI_PLUGIN_DIR . 'templates/logs.php';
            } elseif ( $active_tab == 'help' ) {
                include_once TPI_PLUGIN_DIR . 'templates/help.php';
            }
        ?>
    </div>
    <?php
}

/**
 * Handle deletion of a feed and its related data.
 */
function tpi_handle_delete_feed() {
    if ( isset( $_GET['id'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'tpi_delete_feed_' . $_GET['id'] ) ) {
        $feed_id = sanitize_text_field( $_GET['id'] );
        $feeds   = get_option( TPI_FEEDS_OPTION, array() );
        if ( isset( $feeds[ $feed_id ] ) ) {
            unset( $feeds[ $feed_id ] );
            update_option( TPI_FEEDS_OPTION, $feeds );
            echo '<div class="updated"><p>Feed and all its related data deleted successfully!</p></div>';
        }
    }
}
