<?php
$feeds = get_option( TPI_FEEDS_OPTION, array() );
?>
<h3>Manage Feeds</h3>
<?php if ( ! empty( $feeds ) ) : ?>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th>Feed URL</th>
                <th>Post Status</th>
                <th>Ongoing Import</th>
                <th>Default Category</th>
                <th>Auto-Fetch (min)</th>
                <th>Last Imported (Manual)</th>
                <th>Last Auto Fetched</th>
                <th>First Time Imported Posts</th>
                <th>Auto Fetched Posts</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $feeds as $feed_id => $feed_data ) : ?>
                <tr>
                    <td><?php echo esc_html( $feed_data['feed_url'] ); ?></td>
                    <td><?php echo esc_html( ucfirst( $feed_data['post_status'] ) ); ?></td>
                    <td><?php echo ! empty( $feed_data['ongoing_import'] ) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html( $feed_data['default_cat'] ); ?></td>
                    <td><?php echo esc_html( $feed_data['auto_fetch'] ); ?></td>
                    <td><?php echo ! empty( $feed_data['imported_at'] ) ? esc_html( $feed_data['imported_at'] ) : '—'; ?></td>
                    <td><?php echo ! empty( $feed_data['last_fetched'] ) ? esc_html( $feed_data['last_fetched'] ) : '—'; ?></td>
                    <td><?php echo esc_html( $feed_data['first_import_count'] ); ?></td>
                    <td><?php echo esc_html( $feed_data['auto_fetched_count'] ); ?></td>
                    <td>
                        <a href="?page=tpi_admin&tab=edit_feed&id=<?php echo esc_attr( $feed_id ); ?>">Edit</a> |
                        <a href="?page=tpi_admin&tab=delete_feed&id=<?php echo esc_attr( $feed_id ); ?>&_wpnonce=<?php echo wp_create_nonce( 'tpi_delete_feed_' . $feed_id ); ?>" onclick="return confirm('Are you sure you want to delete this feed and all its data?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else : ?>
    <p>No feeds added yet.</p>
<?php endif; ?>
