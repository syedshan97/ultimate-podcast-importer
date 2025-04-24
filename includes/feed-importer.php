<?php
/**
 * Core feed‐processing functions for the Ultimate Podcast Importer plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scheduled runner for a single feed.
 */
add_action( 'tpi_run_feed_import', 'tpi_scheduled_feed_import' );
function tpi_scheduled_feed_import( $feed_id ) {
    $feeds = get_option( TPI_FEEDS_OPTION, array() );
    if ( empty( $feeds[ $feed_id ] ) ) {
        return;
    }
    $feed_data = $feeds[ $feed_id ];

    // 1) Header
    tpi_log( str_repeat( '=', 50 ) );
    tpi_log( sprintf(
        'Scheduled run for %s @ %s UTC',
        $feed_data['feed_url'],
        current_time( 'mysql', true )
    ) );
    tpi_log( str_repeat( '=', 50 ) );

    // 2) Auto-Fetch
    if ( ! empty( $feed_data['ongoing_import'] ) ) {
        $import_results = tpi_process_feed( $feed_data, false, $feed_id, true );
        $count = count( $import_results['imported'] );
        if ( $count > 0 ) {
            tpi_log( sprintf(
                '[Auto-Fetch] Imported %d new episode%s:',
                $count,
                $count > 1 ? 's' : ''
            ) );
            foreach ( $import_results['imported'] as $item ) {
                tpi_log( ' • ' . $item['title'] );
            }
        } else {
            tpi_log( '[Auto-Fetch] No new episodes found.' );
        }
    }

    // 3) Auto-Update
    if ( ! empty( $feed_data['auto_update'] ) ) {
        $updated = tpi_update_feed_items( $feed_data, $feed_id );
        if ( ! empty( $updated ) ) {
            $ucount = count( $updated );
            tpi_log( sprintf(
                '[Auto-Update] Updated %d episode%s:',
                $ucount,
                $ucount > 1 ? 's' : ''
            ) );
            foreach ( $updated as $title ) {
                tpi_log( ' • ' . $title );
            }
        } else {
            tpi_log( '[Auto-Update] No updates found.' );
        }
    }

    // 4) Footer
    tpi_log( str_repeat( '-', 50 ) );
}

/**
 * Process the entire feed synchronously.
 *
 * @param array  $feed_data
 * @param bool   $show_popup
 * @param string $feed_id
 * @param bool   $is_cron
 * @return array
 */
function tpi_process_feed( $feed_data, $show_popup = false, $feed_id = '', $is_cron = false ) {
    $import_results = array( 'imported' => array() );
    $imported_count = 0;

    // Fetch RSS feed
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

    // Parse XML
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        $import_results['imported'][] = array(
            'title'  => 'Feed parse failed',
            'status' => 'Invalid XML',
        );
        tpi_log( "Invalid XML for {$feed_data['feed_url']}" );
        return $import_results;
    }

    // Timezone fix: import_date at local midnight → GMT timestamp
    if ( ! empty( $feed_data['import_date'] ) ) {
        $local_midnight     = $feed_data['import_date'] . ' 00:00:00';
        $gmt_midnight       = get_gmt_from_date( $local_midnight, 'Y-m-d H:i:s' );
        $min_date_timestamp = strtotime( $gmt_midnight );
    } else {
        $min_date_timestamp = 0;
    }

    // Collect eligible items
    $eligible_items = array();
    foreach ( $xml->channel->item as $item ) {
        if ( strtotime( (string) $item->pubDate ) >= $min_date_timestamp ) {
            $eligible_items[] = $item;
        }
    }
    tpi_log( "Processing feed {$feed_data['feed_url']} - eligible items: " . count( $eligible_items ) );

    // Process each item
    foreach ( $eligible_items as $item ) {
        $res = tpi_process_item( $item, $feed_data, $feed_id );
        if ( $res ) {
            $import_results['imported'][] = $res;
            $imported_count++;
        }
    }

    // Update feed stats
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
 * @param SimpleXMLElement $item
 * @param array            $feed_data
 * @param string           $feed_id
 * @return array|false
 */
function tpi_process_item( $item, $feed_data, $feed_id ) {
    // Duplicate detection
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

    // Title & content
    $title       = (string) $item->title;
    $description = ! empty( $item->children( 'content', true )->encoded )
        ? (string) $item->children( 'content', true )->encoded
        : (string) $item->description;

    // Audio shortcode
    if ( isset( $item->enclosure['url'] ) ) {
        $audio_url   = (string) $item->enclosure['url'];
        $audio_sc    = '[audio src="' . esc_url( $audio_url ) . '"]';
        $description = $audio_sc . "\n\n" . $description;
    }

    // Publish Date Fix
    $feed_pub_ts = strtotime( (string) $item->pubDate );
    $current_ts  = current_time( 'timestamp' );
    if ( $feed_pub_ts > $current_ts ) {
        $post_date = date( 'Y-m-d H:i:s', $current_ts );
    } else {
        $post_date = date( 'Y-m-d H:i:s', $feed_pub_ts );
    }

    $author_id = ! empty( $feed_data['author'] ) ? intval( $feed_data['author'] ) : get_current_user_id();

    // Insert post
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

    // Meta: GUID & audio URL
    add_post_meta( $post_id, 'tpi_feed_guid', $guid, true );
    if ( isset( $audio_url ) ) {
        update_post_meta( $post_id, 'tpi_audio_url', $audio_url );
    }

    // Featured image
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
            $meta = wp_generate_attachment_metadata( $att_id, get_attached_file( $att_id ) );
            wp_update_attachment_metadata( $att_id, $meta );
            update_post_meta( $post_id, 'tpi_featured_image_url', $img_url );
        } else {
            tpi_log( "Featured image error for post $post_id" );
        }
    }

    // Categories
    $cat_ids = array();
    $keywords = $item->children( $itunes_ns )->keywords;
    if ( $keywords ) {
        $kws = array_map( 'trim', explode( ',', (string) $keywords ) );
        foreach ( array_slice( $kws, 0, 2 ) as $kw ) {
            if ( ! $kw ) continue;
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
        }
    }
    if ( $cat_ids ) {
        wp_set_post_categories( $post_id, $cat_ids );
    }

    return array( 'title' => $title, 'status' => 'success' );
}

/**
 * AJAX handler: Process feed in chunks for progress.
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
    $feed_data      = $feeds[ $feed_id ];
    $transient_key  = 'tpi_feed_' . md5( $feed_data['feed_url'] );
    $cached_feed    = get_transient( $transient_key );

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

    // Timezone fix
    if ( ! empty( $feed_data['import_date'] ) ) {
        $local_midnight     = $feed_data['import_date'] . ' 00:00:00';
        $gmt_midnight       = get_gmt_from_date( $local_midnight, 'Y-m-d H:i:s' );
        $min_date_timestamp = strtotime( $gmt_midnight );
    } else {
        $min_date_timestamp = 0;
    }

    // Gather items
    $eligible = array();
    foreach ( $xml->channel->item as $item ) {
        if ( strtotime( (string) $item->pubDate ) >= $min_date_timestamp ) {
            $eligible[] = $item;
        }
    }
    $total = count( $eligible );

    // Process chunk
    $results   = array();
    $processed = 0;
    for ( $i = $offset; $i < min( $offset + $limit, $total ); $i++ ) {
        $r = tpi_process_item( $eligible[ $i ], $feed_data, $feed_id );
        if ( $r ) {
            $results[] = $r;
            $processed++;
        }
    }

    $new_offset = $offset + $limit;
    $done       = ( $new_offset >= $total );
    if ( $done ) {
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
 * Returns an array of updated episode titles.
 *
 * @param array  $feed_data
 * @param string $feed_id
 * @return array
 */
function tpi_update_feed_items( $feed_data, $feed_id ) {
    $updated_titles = array();
    if ( empty( $feed_data['auto_update'] ) ) {
        return $updated_titles;
    }

    // 1) Try Last-Modified header
    $head     = wp_remote_head( $feed_data['feed_url'] );
    $modified = '';
    if ( ! is_wp_error( $head ) ) {
        $hdrs = wp_remote_retrieve_headers( $head );
        if ( isset( $hdrs['last-modified'] ) ) {
            $modified = $hdrs['last-modified'];
        }
    }
    // 2) Fallback to <lastBuildDate>
    if ( empty( $modified ) ) {
        $full = wp_remote_get( $feed_data['feed_url'] );
        if ( ! is_wp_error( $full ) ) {
            $fxml = simplexml_load_string( wp_remote_retrieve_body( $full ) );
            if ( $fxml && isset( $fxml->channel->lastBuildDate ) ) {
                $modified = (string) $fxml->channel->lastBuildDate;
            }
        }
    }

    // 3) Compare
    if ( ! empty( $modified ) && isset( $feed_data['last_modified'] ) && $feed_data['last_modified'] === $modified ) {
        return $updated_titles;
    }

    // Store new last_modified
    $feeds                       = get_option( TPI_FEEDS_OPTION, array() );
    $feed_data['last_modified']  = $modified;
    $feeds[ $feed_id ]           = $feed_data;
    update_option( TPI_FEEDS_OPTION, $feeds );

    // 4) Re-fetch items (transient or fresh)
    $transient_key = 'tpi_feed_' . md5( $feed_data['feed_url'] );
    $body          = get_transient( $transient_key );
    if ( false === $body ) {
        $resp = wp_remote_get( $feed_data['feed_url'] );
        if ( is_wp_error( $resp ) ) {
            return $updated_titles;
        }
        $body = wp_remote_retrieve_body( $resp );
        set_transient( $transient_key, $body, 300 );
    }
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        return $updated_titles;
    }

    // Timezone filter
    if ( ! empty( $feed_data['import_date'] ) ) {
        $lm      = $feed_data['import_date'] . ' 00:00:00';
        $gmt     = get_gmt_from_date( $lm, 'Y-m-d H:i:s' );
        $min_ts  = strtotime( $gmt );
    } else {
        $min_ts = 0;
    }

    // Loop items and update posts
    $ns = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    foreach ( $xml->channel->item as $item ) {
        if ( strtotime( (string) $item->pubDate ) < $min_ts ) {
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
        $current      = get_post( $post_id );
        $needs_update = false;

        // Title & content build
        $new_title = (string) $item->title;
        $new_desc  = ! empty( $item->children( 'content', true )->encoded )
            ? (string) $item->children( 'content', true )->encoded
            : (string) $item->description;
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

        // Featured image compare
        $imgn    = $item->children( $ns )->image;
        $new_img = $imgn && ! empty( $imgn->attributes()->href ) ? (string) $imgn->attributes()->href : '';
        if ( get_post_meta( $post_id, 'tpi_featured_image_url', true ) !== $new_img ) {
            $needs_update = true;
        }

        // Categories compare
        $new_cats = array();
        $kws      = $item->children( $ns )->keywords;
        if ( $kws ) {
            $arr = array_map( 'trim', explode( ',', (string) $kws ) );
            foreach ( array_slice( $arr, 0, 2 ) as $kw ) {
                if ( ! $kw ) continue;
                $term = term_exists( $kw, 'category' );
                if ( $term ) {
                    $new_cats[] = $term['term_id'];
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

        if ( $needs_update ) {
            // Perform update
            wp_update_post( array(
                'ID'           => $post_id,
                'post_title'   => $new_title,
                'post_content' => $new_desc,
                'post_modified'=> current_time( 'mysql' ),
            ) );
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
            $updated_titles[] = $new_title;
        }
    }

    return $updated_titles;
}
