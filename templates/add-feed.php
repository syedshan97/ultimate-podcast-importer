<?php
// Get list of authors and categories.
$authors = get_users( array( 'who' => 'authors', 'orderby' => 'display_name', 'order' => 'ASC' ) );
$categories = get_categories( array( 'hide_empty' => false ) );
?>
<h3>Add Feed</h3>
<form id="tpi-feed-form" method="post">
    <?php wp_nonce_field( 'tpi_add_feed', 'tpi_nonce' ); ?>
    <table class="form-table">
        <tr>
            <th scope="row"><label for="feed_url">RSS Feed URL</label></th>
            <td><input type="url" name="feed_url" id="feed_url" class="regular-text" required placeholder="https://feeds.transistor.fm/daily-security-review"></td>
        </tr>
        <tr>
            <th scope="row"><label for="import_date">Import Posts From (YYYY-MM-DD)</label></th>
            <td><input type="date" name="import_date" id="import_date"></td>
        </tr>
        <tr>
            <th scope="row">Post Status</th>
            <td>
                <label><input type="radio" name="post_status" value="publish" checked> Published</label><br>
                <label><input type="radio" name="post_status" value="draft"> Draft</label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="default_cat">Default Category</label></th>
            <td>
                <select name="default_cat" id="default_cat">
                    <option value="">Select Category</option>
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row">Author</th>
            <td>
                <select name="author" id="author">
                    <?php foreach ( $authors as $user ) : ?>
                        <option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row">Ongoing Import</th>
            <td>
                <label>
                    <input type="checkbox" name="ongoing_import" value="1">
                    Enable auto-import for new episodes.
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="auto_fetch">Auto-Fetch Interval (minutes)</label></th>
            <td>
                <input type="number" name="auto_fetch" id="auto_fetch" class="small-text" value="60" min="1">
                <p class="description">Default is 60 minutes.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Auto Update</th>
            <td>
                <label>
                    <input type="checkbox" name="auto_update" value="1">
                    Automatically update existing posts if changes are detected.
                </label>
            </td>
        </tr>
    </table>
    <!-- Form submission handled via AJAX -->
    <input type="submit" value="Import Feed" class="button button-primary" id="tpi-feed-submit" />
</form>

<!-- Progress bar container (hidden by default) -->
<div id="tpi-progress" style="display:none; margin-top:20px;">
    <div id="tpi-progress-bar" style="width:0%; height:20px; background:#0073aa;"></div>
    <p id="tpi-progress-text">Imported 0 of 0</p>
</div>

<!-- Popup for final results -->
<div id="tpi-popup-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
    <div id="tpi-popup" style="background:#fff; padding:20px; width:90%; max-width:500px; margin:100px auto; border-radius:5px; position:relative; max-height:300px; overflow-y:auto;">
    </div>
</div>
