<?php
$log_contents = '';
if ( file_exists( TPI_LOG_FILE ) ) {
    $log_contents = file_get_contents( TPI_LOG_FILE );
}
?>
<h3>Import Logs</h3>
<?php if ( ! empty( $log_contents ) ) : ?>
    <textarea readonly style="width:100%; height:400px;"><?php echo esc_textarea( $log_contents ); ?></textarea>
    <p><a href="?page=tpi_admin&tab=logs&action=clear" onclick="return confirm('Are you sure you want to clear the log?');">Clear Log</a></p>
<?php else : ?>
    <p>No logs available.</p>
<?php endif; ?>
<?php
if ( isset( $_GET['action'] ) && $_GET['action'] == 'clear' ) {
    file_put_contents( TPI_LOG_FILE, '' );
    echo '<div class="updated"><p>Log cleared successfully!</p></div>';
}
?>
