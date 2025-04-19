<?php
/**
 * Process the entire feed synchronously.
 *
 * This function fetches the RSS feed, filters the items by the import date,
 * processes each eligible item, and returns the results.
 *
 * @param array  $feed_data  Feed settings.
 * @param bool   $show_popup Whether to show a popup after import.
 * @param string $feed_id    Unique feed identifier.
 * @param bool   $is_cron    If true, it's an auto-fetch.
 *
 * @return array Import results.
 */
function tpi_process_feed( $feed_data, $show_popup = false, $feed_id = '', $is_cron = false ) {
    $import_results = array( 'imported' => array() );
    $imported_count = 0;

    // Fetch the RSS feed.
    $response = wp_remote_get( $feed_data['feed_url'] );
    if ( is_wp_error( $response ) ) {
        $import_results['imported'][] = array( 'title' => 'Feed fetch failed', 'status' => $response->get_error_message() );
        tpi_log( "Error: Unable to fetch feed {$feed_data['feed_url']} - " . $response->get_error_message() );
        return $import_results;
    }
    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        $import_results['imported'][] = array( 'title' => 'Feed fetch failed', 'status' => 'Empty feed content' );
        tpi_log( "Error: Empty feed content for {$feed_data['feed_url']}" );
        return $import_results;
    }

    // Parse XML.
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        $import_results['imported'][] = array( 'title' => 'Feed parse failed', 'status' => 'Invalid XML' );
        tpi_log( "Error: Invalid XML for {$feed_data['feed_url']}" );
        return $import_results;
    }

    // Filter eligible items based on the specified import date.
    $eligible_items = array();
    foreach ( $xml->channel->item as $item ) {
        if ( ! empty( $feed_data['import_date'] ) ) {
            $min_date = strtotime( $feed_data['import_date'] );
            $item_date = strtotime( (string)$item->pubDate );
            if ( $item_date >= $min_date ) {
                $eligible_items[] = $item;
            }
        } else {
            $eligible_items[] = $item;
        }
    }
    $total_eligible = count( $eligible_items );
    tpi_log( "Processing feed {$feed_data['feed_url']} - Total eligible items: $total_eligible" );

    // Process each eligible item.
    foreach ( $eligible_items as $item ) {
        $result = tpi_process_item( $item, $feed_data, $feed_id );
        if ( $result ) {
            $import_results['imported'][] = $result;
            $imported_count++;
        }
    }

    // Update feed statistics.
    $feeds = get_option( TPI_FEEDS_OPTION, array() );
    if ( isset( $feeds[ $feed_id ] ) ) {
        if ( ! $is_cron ) {
            $feeds[ $feed_id ]['imported_at'] = current_time( 'mysql' );
            $feeds[ $feed_id ]['first_import_count'] = $imported_count;
        } else {
            $feeds[ $feed_id ]['last_fetched'] = current_time( 'mysql' );
            $feeds[ $feed_id ]['auto_fetched_count'] += $imported_count;
        }
        update_option( TPI_FEEDS_OPTION, $feeds );
    }

    return $import_results;
}

/**
 * Process a single feed item.
 *
 * Checks for duplicates, creates the post with audio embedding,
 * handles featured image processing, and assigns categories.
 *
 * @param SimpleXMLElement $item      The feed item.
 * @param array            $feed_data Feed settings.
 * @param string           $feed_id   Unique feed identifier.
 *
 * @return array|false Array with 'title' and 'status' on success; false if duplicate.
 */
function tpi_process_item( $item, $feed_data, $feed_id ) {
    // Duplicate check: use GUID or link.
    $guid = (string)$item->guid;
    if ( empty( $guid ) ) {
        $guid = (string)$item->link;
    }
    $existing = get_posts( array(
        'meta_key'   => 'tpi_feed_guid',
        'meta_value' => $guid,
        'post_type'  => 'post',
        'fields'     => 'ids',
    ) );
    if ( ! empty( $existing ) ) {
        return false;
    }

    $title = (string)$item->title;
    $content = (string)( ! empty( $item->children('content', true)->encoded )
        ? $item->children('content', true)->encoded
        : $item->description );
    if ( isset( $item->enclosure['url'] ) ) {
        $audio_url = (string)$item->enclosure['url'];
        $audio_shortcode = '[audio src="' . esc_url( $audio_url ) . '"]';
        $content = $audio_shortcode . "\n\n" . $content;
    }
    $post_date = date( 'Y-m-d H:i:s', strtotime( (string)$item->pubDate ) );
    $author_id = ! empty( $feed_data['author'] ) ? intval( $feed_data['author'] ) : get_current_user_id();

    $post_arr = array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $feed_data['post_status'],
        'post_date'    => $post_date,
        'post_type'    => 'post',
        'post_author'  => $author_id,
    );
    $post_id = wp_insert_post( $post_arr );
    if ( is_wp_error( $post_id ) ) {
        tpi_log( "Error inserting post '$title': " . $post_id->get_error_message() );
        return array( 'title' => $title, 'status' => $post_id->get_error_message() );
    }
    add_post_meta( $post_id, 'tpi_feed_guid', $guid, true );
    if ( isset( $item->enclosure['url'] ) ) {
        update_post_meta( $post_id, 'tpi_audio_url', (string)$item->enclosure['url'] );
    }

    // Handle featured image from <itunes:image>.
    $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    $itunesImage = '';
    if ( isset( $itunes->image ) && ! empty( $itunes->image->attributes()->href ) ) {
        $itunesImage = (string)$itunes->image->attributes()->href;
    }
    if ( ! empty( $itunesImage ) ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        set_time_limit(300);
        $image_id = media_sideload_image( $itunesImage, $post_id, '', 'id' );
        if ( is_wp_error( $image_id ) ) {
            tpi_log( "Image Error for post $post_id: " . $image_id->get_error_message() );
        } else {
            if ( ! set_post_thumbnail( $post_id, $image_id ) ) {
                tpi_log( "Failed to set featured image for post $post_id" );
            }
            $attach_data = wp_generate_attachment_metadata( $image_id, get_attached_file( $image_id ) );
            wp_update_attachment_metadata( $image_id, $attach_data );
        }
    }

    // Process categories: assign up to two from <itunes:keywords> plus default category.
    $itunesKeywords = $itunes->keywords;
    $cat_ids = array();
    if ( ! empty( $itunesKeywords ) ) {
        $keywords = explode( ',', (string)$itunesKeywords );
        $keywords = array_map( 'trim', $keywords );
        $keywords = array_slice( $keywords, 0, 2 );
        foreach ( $keywords as $keyword ) {
            if ( empty( $keyword ) ) continue;
            $term = term_exists( $keyword, 'category' );
            if ( $term !== 0 && $term !== null ) {
                $cat_ids[] = $term['term_id'];
            } else {
                $new_term = wp_insert_term( $keyword, 'category' );
                if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
                    $cat_ids[] = $new_term['term_id'];
                }
            }
        }
    }
    if ( ! empty( $feed_data['default_cat'] ) ) {
        $default = term_exists( $feed_data['default_cat'], 'category' );
        if ( $default !== 0 && $default !== null ) {
            $cat_ids[] = $default['term_id'];
        } else {
            $new_term = wp_insert_term( $feed_data['default_cat'], 'category' );
            if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
                $cat_ids[] = $new_term['term_id'];
            }
        }
    }
    if ( ! empty( $cat_ids ) ) {
        wp_set_post_categories( $post_id, $cat_ids );
    }

    return array( 'title' => $title, 'status' => 'success' );
}

/**
 * AJAX handler: Initialize the feed import.
 *
 * This handler saves the feed settings and returns a unique feed_id.
 */
add_action('wp_ajax_tpi_import_feed_ajax', 'tpi_import_feed_ajax');
function tpi_import_feed_ajax() {
    check_ajax_referer('tpi_import_nonce', 'nonce');
    $feed_url    = esc_url_raw($_POST['feed_url']);
    $import_date = sanitize_text_field($_POST['import_date']);
    $post_status = sanitize_text_field($_POST['post_status']);
    $default_cat = sanitize_text_field($_POST['default_cat']);
    $ongoing_import = isset($_POST['ongoing_import']) ? 1 : 0;
    $auto_fetch  = absint($_POST['auto_fetch']);
    $author      = intval($_POST['author']);

    $feed_data = array(
        'feed_url'       => $feed_url,
        'import_date'    => $import_date,
        'post_status'    => $post_status,
        'default_cat'    => $default_cat,
        'ongoing_import' => $ongoing_import,
        'auto_fetch'     => ($auto_fetch > 0) ? $auto_fetch : 60,
        'author'         => $author,
        'imported_at'            => '',
        'last_fetched'           => '',
        'first_import_count'     => 0,
        'auto_fetched_count'     => 0,
    );
    $feed_id = md5($feed_url);
    $feeds   = get_option(TPI_FEEDS_OPTION, array());
    $feeds[$feed_id] = $feed_data;
    update_option(TPI_FEEDS_OPTION, $feeds);
    wp_send_json_success(array('feed_id' => $feed_id));
}

/*
AJAX handler: Process feed in chunks for progress.
Expects feed_id, offset, and limit.

add_action('wp_ajax_tpi_import_feed_progress', 'tpi_import_feed_progress');
function tpi_import_feed_progress() {
    check_ajax_referer('tpi_import_nonce', 'nonce');
    $feed_id = sanitize_text_field($_POST['feed_id']);
    $offset  = intval($_POST['offset']);
    $limit   = intval($_POST['limit']);
    $feeds   = get_option(TPI_FEEDS_OPTION, array());
    if ( ! isset($feeds[$feed_id]) ) {
        wp_send_json_error("Feed not found.");
    }
    $feed_data = $feeds[$feed_id];

    // Fetch and parse the feed.
    $response = wp_remote_get($feed_data['feed_url']);
    if ( is_wp_error($response) ) {
        wp_send_json_error("Unable to fetch feed.");
    }
    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body);
    if (!$xml) {
        wp_send_json_error("Invalid XML.");
    }
    // Filter eligible items based on import date.
    $eligible_items = array();
    foreach ($xml->channel->item as $item) {
        if ( ! empty($feed_data['import_date']) ) {
            $min_date = strtotime($feed_data['import_date']);
            $item_date = strtotime((string)$item->pubDate);
            if ($item_date >= $min_date) {
                $eligible_items[] = $item;
            }
        } else {
            $eligible_items[] = $item;
        }
    }
    $total = count($eligible_items);
    $results = array();
    $processed = 0;
    for($i = $offset; $i < min($offset + $limit, $total); $i++) {
        $item = $eligible_items[$i];
        $result = tpi_process_item($item, $feed_data, $feed_id);
        if ($result) {
            $results[] = $result;
            $processed++;
        }
    }
    $new_offset = $offset + $limit;
    $done = $new_offset >= $total;
    if ($done) {
        $feeds = get_option(TPI_FEEDS_OPTION, array());
        if ( isset($feeds[$feed_id]) ) {
            $feeds[$feed_id]['imported_at'] = current_time('mysql');
            $feeds[$feed_id]['first_import_count'] = $total;
            update_option(TPI_FEEDS_OPTION, $feeds);
        }
    }
    wp_send_json_success(array(
        "processed"  => $processed,
        "total"      => $total,
        "new_offset" => $new_offset,
        "done"       => $done,
        "results"    => $results
    ));
}

 */

/**
 * AJAX handler: Process feed in chunks for progress.
 *
 * Expects feed_id, offset, and limit.
 */
add_action('wp_ajax_tpi_import_feed_progress', 'tpi_import_feed_progress');
function tpi_import_feed_progress() {
    check_ajax_referer('tpi_import_nonce', 'nonce');
    $feed_id = sanitize_text_field($_POST['feed_id']);
    $offset  = intval($_POST['offset']);
    $limit   = intval($_POST['limit']);
    $feeds   = get_option(TPI_FEEDS_OPTION, array());
    if ( ! isset($feeds[$feed_id]) ) {
        wp_send_json_error("Feed not found.");
    }
    $feed_data = $feeds[$feed_id];

    // Use a transient to cache the feed XML body for 5 minutes.
    $transient_key = 'tpi_feed_' . md5($feed_data['feed_url']);
    $cached_feed = get_transient($transient_key);
    if ( false === $cached_feed ) {
        // Fetch and cache the feed.
        $response = wp_remote_get($feed_data['feed_url']);
        if ( is_wp_error($response) ) {
            wp_send_json_error("Unable to fetch feed.");
        }
        $cached_feed = wp_remote_retrieve_body($response);
        if ( empty($cached_feed) ) {
            wp_send_json_error("Empty feed content.");
        }
        set_transient($transient_key, $cached_feed, 300); // Cache for 300 seconds (5 minutes)
    }
    
    $xml = simplexml_load_string($cached_feed);
    if (!$xml) {
        wp_send_json_error("Invalid XML.");
    }
    
    // Filter eligible items based on the specified import date.
    $eligible_items = array();
    foreach ($xml->channel->item as $item) {
        if ( ! empty($feed_data['import_date']) ) {
            $min_date = strtotime($feed_data['import_date']);
            $item_date = strtotime((string)$item->pubDate);
            if ($item_date >= $min_date) {
                $eligible_items[] = $item;
            }
        } else {
            $eligible_items[] = $item;
        }
    }
    $total = count($eligible_items);
    $results = array();
    $processed = 0;
    for($i = $offset; $i < min($offset + $limit, $total); $i++) {
        $item = $eligible_items[$i];
        $result = tpi_process_item($item, $feed_data, $feed_id);
        if ($result) {
            $results[] = $result;
            $processed++;
        }
    }
    $new_offset = $offset + $limit;
    $done = $new_offset >= $total;
    if ($done) {
        $feeds = get_option(TPI_FEEDS_OPTION, array());
        if ( isset($feeds[$feed_id]) ) {
            $feeds[$feed_id]['imported_at'] = current_time('mysql');
            $feeds[$feed_id]['first_import_count'] = $total;
            update_option(TPI_FEEDS_OPTION, $feeds);
        }
        // Delete transient after full import to force fresh fetch next time.
        delete_transient($transient_key);
    }
    wp_send_json_success(array(
        "processed"  => $processed,
        "total"      => $total,
        "new_offset" => $new_offset,
        "done"       => $done,
        "results"    => $results
    ));
}
