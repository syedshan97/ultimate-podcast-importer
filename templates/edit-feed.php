<?php
// templates/edit-feed.php

// Attempt to get the feed ID from the GET parameter.
$feed_id = '';
if ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) {
    $feed_id = sanitize_text_field( $_GET['id'] );
} else {
    echo '<p>Error: No feed ID provided.</p>';
    return;
}

// Retrieve feeds.
$feeds = get_option( TPI_FEEDS_OPTION, array() );

// Check if the feed exists.
if ( ! isset( $feeds[ $feed_id ] ) ) {
    echo '<p>Error: Feed not found.</p>';
    return;
}

$feed_data = $feeds[ $feed_id ];

// Get list of authors and categories.
$authors = get_users( array( 'who' => 'authors', 'orderby' => 'display_name', 'order' => 'ASC' ) );
$categories = get_categories( array( 'hide_empty' => false ) );
?>

<h3>Edit Feed</h3>
<form method="post">
    <?php wp_nonce_field( 'tpi_edit_feed', 'tpi_nonce' ); ?>
    <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed_id ); ?>">
    <table class="form-table">
        <tr>
            <th scope="row"><label for="feed_url">RSS Feed URL</label></th>
            <td><input type="url" name="feed_url" id="feed_url" class="regular-text" required value="<?php echo esc_attr( $feed_data['feed_url'] ); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="import_date">Import Posts From (YYYY-MM-DD)</label></th>
            <td><input type="date" name="import_date" id="import_date" value="<?php echo esc_attr( $feed_data['import_date'] ); ?>"></td>
        </tr>
        <tr>
            <th scope="row">Post Status</th>
            <td>
                <label><input type="radio" name="post_status" value="publish" <?php checked( $feed_data['post_status'], 'publish' ); ?>> Published</label><br>
                <label><input type="radio" name="post_status" value="draft" <?php checked( $feed_data['post_status'], 'draft' ); ?>> Draft</label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="default_cat">Default Category</label></th>
            <td>
                <select name="default_cat" id="default_cat">
                    <option value="">Select Category</option>
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->name ); ?>" <?php selected( $feed_data['default_cat'], $cat->name ); ?>>
                            <?php echo esc_html( $cat->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row">Author</th>
            <td>
                <select name="author" id="author">
                    <?php foreach ( $authors as $user ) : ?>
                        <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $feed_data['author'], $user->ID ); ?>>
                            <?php echo esc_html( $user->display_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row">Ongoing Import</th>
            <td>
                <label>
                    <input type="checkbox" name="ongoing_import" value="1" <?php checked( $feed_data['ongoing_import'], 1 ); ?>>
                    Enable auto-import for new episodes.
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="auto_fetch">Auto-Fetch Interval (minutes)</label></th>
            <td>
                <input type="number" name="auto_fetch" id="auto_fetch" class="small-text" value="<?php echo esc_attr( $feed_data['auto_fetch'] ); ?>" min="1">
            </td>
        </tr>
        <tr>
            <th scope="row">Auto Update</th>
            <td>
                <label>
                    <input type="checkbox" name="auto_update" value="1" <?php checked( $feed_data['auto_update'], 1 ); ?>>
                    Automatically update posts if changes are detected.
                </label>
            </td>
        </tr>
    </table>
    <?php submit_button( 'Update Feed', 'primary', 'tpi_edit_submit' ); ?>
</form>

<?php
if ( isset( $_POST['tpi_edit_submit'] ) && check_admin_referer( 'tpi_edit_feed', 'tpi_nonce' ) ) {
    $feed_url       = esc_url_raw( $_POST['feed_url'] );
    $import_date    = sanitize_text_field( $_POST['import_date'] );
    $post_status    = sanitize_text_field( $_POST['post_status'] );
    $default_cat    = sanitize_text_field( $_POST['default_cat'] );
    $ongoing_import = isset( $_POST['ongoing_import'] ) ? 1 : 0;
    $auto_fetch     = absint( $_POST['auto_fetch'] );
    $author         = intval( $_POST['author'] );
    $auto_update    = isset($_POST['auto_update']) ? 1 : 0;

    $feed_data = array(
        'feed_url'       => $feed_url,
        'import_date'    => $import_date,
        'post_status'    => $post_status,
        'default_cat'    => $default_cat,
        'ongoing_import' => $ongoing_import,
        'auto_fetch'     => ($auto_fetch > 0) ? $auto_fetch : 60,
        'author'         => $author,
        'auto_update'    => $auto_update,
        'imported_at'            => $_POST['imported_at'] ?? '',
        'last_fetched'           => $_POST['last_fetched'] ?? '',
        'first_import_count'     => $_POST['first_import_count'] ?? 0,
        'auto_fetched_count'     => $_POST['auto_fetched_count'] ?? 0,
    );
    $feeds = get_option( TPI_FEEDS_OPTION, array() );
    $feeds[ $_POST['feed_id'] ] = $feed_data;
    update_option( TPI_FEEDS_OPTION, $feeds );
    echo '<div class="updated"><p>Feed updated successfully!</p></div>';
}
?>
