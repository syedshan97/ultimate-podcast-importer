<?php
/**
 * Utility functions used by the importer.
 */

/**
 * Log messages to the log file and PHP error log.
 *
 * @param string $message The message to log.
 */
function tpi_log( $message ) {
    $timestamp = current_time( 'mysql' );
    $log_message = "[$timestamp] $message" . PHP_EOL;
    file_put_contents( TPI_LOG_FILE, $log_message, FILE_APPEND );
    error_log('[TPI] ' . $message);
}

/**
 * Build a simplified HTML for the import results popup.
 *
 * Instead of showing a detailed list of imported posts,
 * this function displays a centered green tick icon and a summary message.
 * It also indicates whether the import was a full import or date-wise import,
 * and if date-wise, shows the specified import date.
 *
 * @param array $results   The results array from the import process.
 * @param array $feed_data The feed settings.
 *
 * @return string The HTML content.
 */
function tpi_build_popup_message( $results, $feed_data ) {
    // Calculate total items processed and number of successful imports.
    $total = 0;
    $successful = 0;
    if ( isset( $results['imported'] ) && is_array( $results['imported'] ) ) {
        $total = count( $results['imported'] );
        foreach ( $results['imported'] as $result ) {
            if ( $result['status'] === 'success' ) {
                $successful++;
            }
        }
    }
    
    // Determine import type.
    $import_type = empty( $feed_data['import_date'] )
        ? 'Full import'
        : 'Date-wise import from ' . date( 'F j, Y', strtotime( $feed_data['import_date'] ) );
    
    // Build the popup HTML message.
    $html = '<div style="text-align:center; padding:20px;">';
    $html .= '<div style="font-size:64px; color:green;">&#10004;</div>'; // Green tick icon.
    $html .= '<h3 style="margin:10px 0;">Import Completed Successfully</h3>';
    $html .= '<p>' . esc_html( $import_type ) . '</p>';
    $html .= '<p>Imported ' . esc_html( $successful ) . ' post' . ( $successful != 1 ? 's' : '' );
    $html .= ' out of ' . esc_html( $total ) . ' eligible post' . ( $total != 1 ? 's' : '' ) . '.</p>';
    $html .= '</div>';
    return $html;
}