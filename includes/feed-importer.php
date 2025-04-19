<?php
/**
 * Core feed‐processing functions for the Ultimate Podcast Importer plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Process the entire feed synchronously.
 *
 * Fetches the RSS feed, filters items by the admin’s 'Import Posts From' date,
 * processes each eligible item, and returns the results.
 *
 * @param array  $feed_data  Feed settings.
 * @param bool   $show_popup Whether to build a popup on completion.
 * @param string $feed_id    Unique feed identifier.
 * @param bool   $is_cron    True when run via cron (auto‐fetch).
 * @return array Import results.
 */
function tpi_process_feed( $feed_data, $show_popup = false, $feed_id = '', $is_cron = false ) {
    $import_results  = array( 'imported' => array() );
    $imported_count  = 0;

    // Fetch the RSS feed.
    $response = wp_remote_get( $feed_data['feed_url'] );
    if ( is_wp_error( $response ) ) {
        $import_results['imported'][] = array(
            'title'  => 'Feed fetch failed',
            'status' => $response->get_error_message(),
        );
        tpi_log( "Error fetching feed {$feed_data['feed_url']}: " . $response->get_error_message() );
        return $import_results;
    }
    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        $import_results['imported'][] = array(
            'title'  => 'Feed fetch failed',
            'status' => 'Empty feed content',
        );
        tpi_log( "Empty feed content for {$feed_data['feed_url']}" );
        return $import_results;
    }

    // Parse XML.
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        $import_results['imported'][] = array(
            'title'  => 'Feed parse failed',
            'status' => 'Invalid XML',
        );
        tpi_log( "Invalid XML for {$feed_data['feed_url']}" );
        return $import_results;
    }

    /**
     * Time zone fix:
     * Convert the admin’s “Import Posts From” date (YYYY-MM-DD at local midnight)
     * into a GMT timestamp so comparisons against the feed’s UTC pubDate are correct
     * regardless of WordPress’s timezone setting.
     */
    if ( ! empty( $feed_data['import_date'] ) ) {
        $local_midnight     = $feed_data['import_date'] . ' 00:00:00';
        $gmt_midnight       = get_gmt_from_date( $local_midnight, 'Y-m-d H:i:s' );
        $min_date_timestamp = strtotime( $gmt_midnight );
    } else {
        $min_date_timestamp = 0;
    }

    // Collect eligible items.
    $eligible_items = array();
    foreach ( $xml->channel->item as $item ) {
        $pub_ts = strtotime( (string) $item->pubDate );
        if ( $pub_ts >= $min_date_timestamp ) {
            $eligible_items[] = $item;
        }
    }
    tpi_log( "Processing feed {$feed_data['feed_url']} - eligible items: " . count( $eligible_items ) );

    // Process each item.
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
            $feeds[ $feed_id ]['imported_at']        = current_time( 'mysql' );
            $feeds[ $feed_id ]['first_import_count'] = $imported_count;
        } else {
            $feeds[ $feed_id ]['last_fetched']       = current_time( 'mysql' );
            $feeds[ $feed_id ]['auto_fetched_count'] += $imported_count;
        }
        update_option( TPI_FEEDS_OPTION, $feeds );
    }

    return $import_results;
}

/**
 * Process a single feed item.
 *
 * Handles duplicate detection, post creation (with audio shortcode),
 * featured image sideload, and category mapping.
 *
 * @param SimpleXMLElement $item      A <item> node from the RSS feed.
 * @param array            $feed_data Feed settings.
 * @param string           $feed_id   Unique feed identifier.
 * @return array|false Details on success or false if duplicate.
 */
function tpi_process_item( $item, $feed_data, $feed_id ) {
    // Use GUID or link to detect duplicates.
    $guid = (string) $item->guid ?: (string) $item->link;
    $existing = get_posts( array(
        'meta_key'   => 'tpi_feed_guid',
        'meta_value' => $guid,
        'post_type'  => 'post',
        'fields'     => 'ids',
    ) );
    if ( ! empty( $existing ) ) {
        return false;
    }

    // Build title and content.
    $title       = (string) $item->title;
    $description = (string) ( ! empty( $item->children( 'content', true )->encoded )
        ? $item->children( 'content', true )->encoded
        : $item->description );

    // Embed audio shortcode if present.
    if ( isset( $item->enclosure['url'] ) ) {
        $audio_url   = (string) $item->enclosure['url'];
        $audio_sc    = '[audio src="' . esc_url( $audio_url ) . '"]';
        $description = $audio_sc . "\n\n" . $description;
    }

    /**
     * Publish Date Fix:
     * Clamp any feed pubDate in the future to “now” (WordPress local time)
     * so that WordPress does not schedule it but publishes immediately.
     */
    $feed_pub_ts = strtotime( (string) $item->pubDate );
    $current_ts  = current_time( 'timestamp' );
    if ( $feed_pub_ts > $current_ts ) {
        // Future date → use now
        $post_date = date( 'Y-m-d H:i:s', $current_ts );
    } else {
        // Past or present → preserve feed date
        $post_date = date( 'Y-m-d H:i:s', $feed_pub_ts );
    }

    $author_id = ! empty( $feed_data['author'] ) ? intval( $feed_data['author'] ) : get_current_user_id();

    $post_arr = array(
        'post_title'   => $title,
        'post_content' => $description,
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

    // Save GUID for future duplicate checks.
    add_post_meta( $post_id, 'tpi_feed_guid', $guid, true );
    if ( isset( $item->enclosure['url'] ) ) {
        update_post_meta( $post_id, 'tpi_audio_url', (string) $item->enclosure['url'] );
    }

    // Featured image sideload.
    $itunes_ns    = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    $itunes_image = $item->children( $itunes_ns )->image;
    if ( $itunes_image && ! empty( $itunes_image->attributes()->href ) ) {
        $img_url = (string) $itunes_image->attributes()->href;
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        set_time_limit( 300 );
        $att_id = media_sideload_image( $img_url, $post_id, '', 'id' );
        if ( ! is_wp_error( $att_id ) && set_post_thumbnail( $post_id, $att_id ) ) {
            $attach_data = wp_generate_attachment_metadata( $att_id, get_attached_file( $att_id ) );
            wp_update_attachment_metadata( $att_id, $attach_data );
            update_post_meta( $post_id, 'tpi_featured_image_url', $img_url );
        } else {
            tpi_log( "Featured image error for post $post_id: " . ( is_wp_error( $att_id ) ? $att_id->get_error_message() : 'set_post_thumbnail failed' ) );
        }
    }

    // Category mapping: first two keywords + default category.
    $cat_ids         = array();
    $itunes_keywords = $item->children( $itunes_ns )->keywords;
    if ( $itunes_keywords ) {
        $kws = array_map( 'trim', explode( ',', (string) $itunes_keywords ) );
        foreach ( array_slice( $kws, 0, 2 ) as $kw ) {
            if ( ! $kw ) {
                continue;
            }
            $term = term_exists( $kw, 'category' );
            if ( $term ) {
                $cat_ids[] = $term['term_id'];
            } else {
                $new = wp_insert_term( $kw, 'category' );
                if ( ! is_wp_error( $new ) ) {
                    $cat_ids[] = $new['term_id'];
                }
            }
        }
    }
    if ( ! empty( $feed_data['default_cat'] ) ) {
        $def = term_exists( $feed_data['default_cat'], 'category' );
        if ( $def ) {
            $cat_ids[] = $def['term_id'];
        } else {
            $new = wp_insert_term( $feed_data['default_cat'], 'category' );
            if ( ! is_wp_error( $new ) ) {
                $cat_ids[] = $new['term_id'];
            }
        }
    }
    if ( $cat_ids ) {
        wp_set_post_categories( $post_id, $cat_ids );
    }

    return array( 'title' => $title, 'status' => 'success' );
}

/**
 * AJAX handler: Initialize the feed import.
 * Saves feed settings and returns a unique feed_id.
 */
add_action( 'wp_ajax_tpi_import_feed_ajax', 'tpi_import_feed_ajax' );
function tpi_import_feed_ajax() {
    check_ajax_referer( 'tpi_import_nonce', 'nonce' );
    $feed_url       = esc_url_raw( $_POST['feed_url'] );
    $import_date    = sanitize_text_field( $_POST['import_date'] );
    $post_status    = sanitize_text_field( $_POST['post_status'] );
    $default_cat    = sanitize_text_field( $_POST['default_cat'] );
    $ongoing_import = isset( $_POST['ongoing_import'] ) ? 1 : 0;
    $auto_fetch     = absint( $_POST['auto_fetch'] );
    $author         = intval( $_POST['author'] );
    $auto_update    = isset( $_POST['auto_update'] ) ? 1 : 0;

    $feed_data = array(
        'feed_url'            => $feed_url,
        'import_date'         => $import_date,
        'post_status'         => $post_status,
        'default_cat'         => $default_cat,
        'ongoing_import'      => $ongoing_import,
        'auto_fetch'          => ( $auto_fetch > 0 ) ? $auto_fetch : 60,
        'author'              => $author,
        'auto_update'         => $auto_update,
        'imported_at'         => '',
        'last_fetched'        => '',
        'first_import_count'  => 0,
        'auto_fetched_count'  => 0,
        'last_modified'       => '',
    );
    $feed_id = md5( $feed_url );
    $feeds   = get_option( TPI_FEEDS_OPTION, array() );
    $feeds[ $feed_id ] = $feed_data;
    update_option( TPI_FEEDS_OPTION, $feeds );

    wp_send_json_success( array( 'feed_id' => $feed_id ) );
}

/**
 * AJAX handler: Process feed in chunks for progress.
 * Expects feed_id, offset, and limit.
 */
add_action( 'wp_ajax_tpi_import_feed_progress', 'tpi_import_feed_progress' );
function tpi_import_feed_progress() {
    check_ajax_referer( 'tpi_import_nonce', 'nonce' );
    $feed_id = sanitize_text_field( $_POST['feed_id'] );
    $offset  = intval( $_POST['offset'] );
    $limit   = intval( $_POST['limit'] );
    $feeds   = get_option( TPI_FEEDS_OPTION, array() );
    if ( ! isset( $feeds[ $feed_id ] ) ) {
        wp_send_json_error( 'Feed not found.' );
    }
    $feed_data = $feeds[ $feed_id ];

    // Transient caching to avoid refetching on every chunk.
    $transient_key = 'tpi_feed_' . md5( $feed_data['feed_url'] );
    $cached_feed   = get_transient( $transient_key );
    if ( false === $cached_feed ) {
        $resp = wp_remote_get( $feed_data['feed_url'] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( 'Unable to fetch feed.' );
        }
        $cached_feed = wp_remote_retrieve_body( $resp );
        set_transient( $transient_key, $cached_feed, 300 );
    }
    $xml = simplexml_load_string( $cached_feed );
    if ( ! $xml ) {
        wp_send_json_error( 'Invalid XML.' );
    }

    /**
     * Time zone fix:
     * Interpret the admin’s “Import Posts From” date at local midnight
     * and convert to GMT for correct filtering.
     */
    if ( ! empty( $feed_data['import_date'] ) ) {
        $local_midnight     = $feed_data['import_date'] . ' 00:00:00';
        $gmt_midnight       = get_gmt_from_date( $local_midnight, 'Y-m-d H:i:s' );
        $min_date_timestamp = strtotime( $gmt_midnight );
    } else {
        $min_date_timestamp = 0;
    }

    // Gather eligible items.
    $eligible_items = array();
    foreach ( $xml->channel->item as $item ) {
        if ( strtotime( (string) $item->pubDate ) >= $min_date_timestamp ) {
            $eligible_items[] = $item;
        }
    }
    $total = count( $eligible_items );

    // Process this chunk.
    $results   = array();
    $processed = 0;
    for ( $i = $offset; $i < min( $offset + $limit, $total ); $i++ ) {
        $res = tpi_process_item( $eligible_items[ $i ], $feed_data, $feed_id );
        if ( $res ) {
            $results[]  = $res;
            $processed++;
        }
    }

    $new_offset = $offset + $limit;
    $done       = ( $new_offset >= $total );
    if ( $done ) {
        // Finalize stats on first import.
        if ( isset( $feeds[ $feed_id ] ) ) {
            $feeds[ $feed_id ]['imported_at']        = current_time( 'mysql' );
            $feeds[ $feed_id ]['first_import_count'] = $total;
            update_option( TPI_FEEDS_OPTION, $feeds );
        }
        delete_transient( $transient_key );
    }

    wp_send_json_success( array(
        'processed'  => $processed,
        'total'      => $total,
        'new_offset' => $new_offset,
        'done'       => $done,
        'results'    => $results,
    ) );
}

/**
 * Auto-update existing imported posts if the feed changes.
 *
 * Checks the HTTP Last-Modified header (or falls back to <lastBuildDate>)
 * and compares to the stored 'last_modified' value. Only when changed does it
 * loop through eligible items (same date filter) and update posts if key fields
 * differ.
 *
 * @param array  $feed_data Feed settings.
 * @param string $feed_id   Unique feed identifier.
 */
function tpi_update_feed_items( $feed_data, $feed_id ) {
    if ( empty( $feed_data['auto_update'] ) ) {
        tpi_log( "Auto-update skipped (not enabled) for {$feed_data['feed_url']}" );
        return;
    }

    // Attempt to read HTTP Last-Modified header.
    $head     = wp_remote_head( $feed_data['feed_url'] );
    $modified = '';
    if ( ! is_wp_error( $head ) ) {
        $headers = wp_remote_retrieve_headers( $head );
        if ( isset( $headers['last-modified'] ) ) {
            $modified = $headers['last-modified'];
        }
    }
    // Fallback: parse <lastBuildDate> from the feed.
    if ( empty( $modified ) ) {
        $full = wp_remote_get( $feed_data['feed_url'] );
        if ( ! is_wp_error( $full ) ) {
            $fxml = simplexml_load_string( wp_remote_retrieve_body( $full ) );
            if ( $fxml && isset( $fxml->channel->lastBuildDate ) ) {
                $modified = (string) $fxml->channel->lastBuildDate;
            }
        }
    }

    // Compare to stored value.
    if ( ! empty( $modified ) && isset( $feed_data['last_modified'] ) && $feed_data['last_modified'] === $modified ) {
        tpi_log( "Auto-update: feed not modified since last check for {$feed_data['feed_url']}" );
        return;
    }

    // Store new last_modified.
    $feeds                         = get_option( TPI_FEEDS_OPTION, array() );
    $feed_data['last_modified']    = $modified;
    $feeds[ $feed_id ]             = $feed_data;
    update_option( TPI_FEEDS_OPTION, $feeds );

    // Reuse transient caching logic for feed content.
    $transient_key = 'tpi_feed_' . md5( $feed_data['feed_url'] );
    $cached_feed   = get_transient( $transient_key );
    if ( false === $cached_feed ) {
        $resp = wp_remote_get( $feed_data['feed_url'] );
        if ( is_wp_error( $resp ) ) {
            tpi_log( "Auto-update fetch error for {$feed_data['feed_url']}" );
            return;
        }
        $cached_feed = wp_remote_retrieve_body( $resp );
        set_transient( $transient_key, $cached_feed, 300 );
    }
    $xml = simplexml_load_string( $cached_feed );
    if ( ! $xml ) {
        tpi_log( "Auto-update parse error for {$feed_data['feed_url']}" );
        return;
    }

    /**
     * Time zone fix:
     * Same date logic as above to ensure we only check items
     * published on or after the admin’s chosen import_date.
     */
    if ( ! empty( $feed_data['import_date'] ) ) {
        $local_midnight     = $feed_data['import_date'] . ' 00:00:00';
        $gmt_midnight       = get_gmt_from_date( $local_midnight, 'Y-m-d H:i:s' );
        $min_date_timestamp = strtotime( $gmt_midnight );
    } else {
        $min_date_timestamp = 0;
    }

    // Loop through eligible items and update if needed.
    foreach ( $xml->channel->item as $item ) {
        if ( strtotime( (string) $item->pubDate ) < $min_date_timestamp ) {
            continue;
        }
        $guid  = (string) $item->guid ?: (string) $item->link;
        $posts = get_posts( array(
            'meta_key'   => 'tpi_feed_guid',
            'meta_value' => $guid,
            'post_type'  => 'post',
            'fields'     => 'ids',
        ) );
        if ( empty( $posts ) ) {
            continue;
        }
        $post_id      = $posts[0];
        $needs_update = false;
        $current      = get_post( $post_id );

        // Build new title/content/audio just as in tpi_process_item().
        $new_title = (string) $item->title;
        $new_desc  = (string) ( ! empty( $item->children('content', true)->encoded )
            ? $item->children('content', true)->encoded
            : $item->description );
        if ( isset( $item->enclosure['url'] ) ) {
            $au       = (string) $item->enclosure['url'];
            $sc       = '[audio src="' . esc_url( $au ) . '"]';
            $new_desc = $sc . "\n\n" . $new_desc;
            if ( get_post_meta( $post_id, 'tpi_audio_url', true ) !== $au ) {
                $needs_update = true;
            }
        }
        if ( $current->post_title !== $new_title || $current->post_content !== $new_desc ) {
            $needs_update = true;
        }

        // Featured image comparison...
        $ns       = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
        $imgn     = $item->children( $ns )->image;
        $new_img  = $imgn && ! empty( $imgn->attributes()->href ) ? (string) $imgn->attributes()->href : '';
        if ( get_post_meta( $post_id, 'tpi_featured_image_url', true ) !== $new_img ) {
            $needs_update = true;
        }

        // Category comparison...
        $new_cats = array();
        $kws      = $item->children( $ns )->keywords;
        if ( $kws ) {
            $arr = array_map( 'trim', explode( ',', (string) $kws ) );
            foreach ( array_slice( $arr, 0, 2 ) as $kw ) {
                if ( ! $kw ) continue;
                $t = term_exists( $kw, 'category' );
                if ( $t ) {
                    $new_cats[] = $t['term_id'];
                } else {
                    $n = wp_insert_term( $kw, 'category' );
                    if ( ! is_wp_error( $n ) ) {
                        $new_cats[] = $n['term_id'];
                    }
                }
            }
        }
        if ( ! empty( $feed_data['default_cat'] ) ) {
            $def = term_exists( $feed_data['default_cat'], 'category' );
            if ( $def ) {
                $new_cats[] = $def['term_id'];
            }
        }
        $cur_cats = wp_get_post_categories( $post_id );
        sort( $new_cats ); sort( $cur_cats );
        if ( $new_cats !== $cur_cats ) {
            $needs_update = true;
        }

        // Perform updates if needed.
        if ( $needs_update ) {
            $upd = array(
                'ID'           => $post_id,
                'post_title'   => $new_title,
                'post_content' => $new_desc,
                'post_modified'=> current_time( 'mysql' ),
            );
            wp_update_post( $upd );
            if ( isset( $au ) ) {
                update_post_meta( $post_id, 'tpi_audio_url', $au );
            }
            if ( $new_img && $new_img !== get_post_meta( $post_id, 'tpi_featured_image_url', true ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                $att = media_sideload_image( $new_img, $post_id, '', 'id' );
                if ( ! is_wp_error( $att ) ) {
                    set_post_thumbnail( $post_id, $att );
                    update_post_meta( $post_id, 'tpi_featured_image_url', $new_img );
                }
            }
            wp_set_post_categories( $post_id, $new_cats );
            tpi_log( "Auto-update: updated post ID $post_id" );
        } else {
            tpi_log( "Auto-update: no changes for post ID $post_id" );
        }
    }
}