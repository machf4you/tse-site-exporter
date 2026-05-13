<?php
/**
 * TSE Site Exporter — extraction logic.
 * Builds per-post PageRecord objects and the full bundle of JSON files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry point. Returns an associative array of filename => payload.
 *
 * @param array $opts
 * @return array
 */
function tse_exporter_run( $opts ) {
    $post_types = tse_exporter_target_post_types();
    $front_id   = (int) get_option( 'page_on_front' );

    $records   = array();
    $count     = 0;
    $truncated = false;

    foreach ( $post_types as $post_type ) {
        $posts = tse_exporter_fetch_posts( $post_type );
        foreach ( $posts as $post ) {
            if ( 'quick' === $opts['mode'] && $count >= $opts['quick_cap'] ) {
                $truncated = true;
                break 2;
            }
            $records[] = tse_exporter_build_record( $post, $front_id, $opts );
            $count++;
        }
    }

    // Build URL → record index for cross-references (classification, types, broken-link check).
    $url_index = array();
    foreach ( $records as $i => $r ) {
        $url_index[ tse_normalize_url( $r['url'] ) ] = $i;
    }

    // Enrich internal links with target metadata + anchor frequency.
    tse_exporter_enrich_internal_links( $records, $url_index );

    // Post-processing: orphans, broken-link check, hierarchy, anchor frequency.
    $postprocess = tse_postprocess_build( $records, $url_index, $opts );

    return tse_exporter_assemble_bundle( $records, $postprocess, $opts, $truncated, $post_types );
}

/**
 * Public post types minus attachments.
 */
function tse_exporter_target_post_types() {
    $types = get_post_types( array( 'public' => true ), 'names' );
    unset( $types['attachment'] );
    return array_values( $types );
}

/**
 * Pull all publish posts of a post type, paginated.
 */
function tse_exporter_fetch_posts( $post_type ) {
    $out      = array();
    $page     = 1;
    $per_page = 200;

    do {
        $q = new WP_Query( array(
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'suppress_filters'       => true,
        ) );
        if ( ! $q->have_posts() ) {
            break;
        }
        foreach ( $q->posts as $p ) {
            $out[] = $p;
        }
        $max = (int) $q->max_num_pages;
        wp_reset_postdata();
        $page++;
    } while ( $page <= $max );

    return $out;
}

/* -------------------------------------------------------------------------
 * Per-post record builder
 * ---------------------------------------------------------------------- */

function tse_exporter_build_record( $post, $front_id, $opts ) {
    setup_postdata( $post );

    $permalink = get_permalink( $post );
    $rendered  = apply_filters( 'the_content', $post->post_content );
    if ( ! is_string( $rendered ) ) {
        $rendered = (string) $post->post_content;
    }

    // Optionally augment with live HTML.
    $live_html = '';
    if ( ! empty( $opts['live_fetch'] ) && $permalink ) {
        $live_html = tse_fetch_live_html( $permalink );
    }
    $html_for_schema = $live_html !== '' ? $live_html : $rendered;

    $dom = tse_load_dom( $rendered );

    // Parse Elementor first so its heading widgets / clean_text feed downstream extractors.
    $elementor_data   = get_post_meta( $post->ID, '_elementor_data', true );
    $elementor_parsed = tse_parse_elementor( $elementor_data );

    $headings = tse_extract_headings( $dom, $elementor_parsed, $live_html );
    $faqs     = tse_extract_faqs( $dom, $html_for_schema );
    $links    = tse_extract_links( $dom, $permalink );
    $images   = tse_extract_images( $dom );

    $plain_text         = tse_normalize_text( wp_strip_all_tags( $rendered ) );
    $shortcodes_removed = tse_normalize_text( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );

    // Elementor pages frequently have empty/placeholder post_content. When the
    // Elementor-derived clean text is substantially richer, use it as plain_text.
    if ( ! empty( $elementor_parsed['is_elementor'] )
        && strlen( $plain_text ) < max( 200, (int) ( strlen( $elementor_parsed['clean_text'] ) / 2 ) ) ) {
        $plain_text = $elementor_parsed['clean_text'];
    }

    $classification = tse_classify( $post, $front_id );
    $is_homepage    = ( $front_id && (int) $post->ID === (int) $front_id );
    $schema_section = tse_schema_build_page_section( $html_for_schema, $classification, $is_homepage );

    $author = get_userdata( $post->post_author );

    $featured_id  = get_post_thumbnail_id( $post->ID );
    $featured     = $featured_id ? tse_build_image( $featured_id ) : null;

    wp_reset_postdata();

    return array(
        'id'           => (int) $post->ID,
        'url'          => $permalink ? $permalink : '',
        'slug'         => $post->post_name,
        'post_type'    => $post->post_type,
        'status'       => $post->post_status,
        'published_at' => tse_to_iso( $post->post_date_gmt ),
        'modified_at'  => tse_to_iso( $post->post_modified_gmt ),
        'parent_id'    => (int) $post->post_parent,
        'template'     => get_page_template_slug( $post->ID ),
        'author'       => $author ? array(
            'id'   => (int) $author->ID,
            'name' => $author->display_name,
        ) : null,
        'classification' => $classification,

        'seo'        => tse_extract_seo( $post->ID, $live_html ),
        'content'    => array(
            'h1'                    => $headings['h1'],
            'h2'                    => $headings['h2'],
            'h3'                    => $headings['h3'],
            'faqs'                  => $faqs,
            'word_count'            => str_word_count( $plain_text ),
            'plain_text'            => $plain_text,
            'shortcodes_removed'    => $shortcodes_removed,
            'elementor_clean_text'  => $elementor_parsed['clean_text'],
        ),
        'links'      => $links,
        'media'      => array(
            'featured' => $featured,
            'images'   => $images,
        ),
        'cro'        => tse_detect_cro( $rendered, $plain_text, $elementor_parsed ),
        'elementor'  => array(
            'is_elementor'  => $elementor_parsed['is_elementor'],
            'sections'      => $elementor_parsed['sections'],
            'widget_counts' => $elementor_parsed['widget_counts'],
        ),
        'schema' => $schema_section,
    );
}

/* -------------------------------------------------------------------------
 * DOM utilities
 * ---------------------------------------------------------------------- */

function tse_load_dom( $html ) {
    $dom = new DOMDocument();
    if ( '' === trim( (string) $html ) ) {
        return $dom;
    }
    $prev_internal = libxml_use_internal_errors( true );
    // Wrap to force UTF-8.
    $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
    $dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev_internal );
    return $dom;
}

function tse_dom_text( $node ) {
    return trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
}

/* -------------------------------------------------------------------------
 * Content structure
 * ---------------------------------------------------------------------- */

function tse_extract_headings( $dom, $elementor_parsed = null, $live_html = '' ) {
    $h1       = '';
    $h2       = array();
    $h3_pairs = array();
    $seen_h2  = array();
    $seen_h3  = array();

    // (1) Headings from the rendered the_content DOM (document order).
    tse_collect_headings_from_dom( $dom, $h1, $h2, $h3_pairs, $seen_h2, $seen_h3 );

    // (2) Headings from Elementor widgets, deduped against the same state.
    if ( is_array( $elementor_parsed ) && ! empty( $elementor_parsed['headings'] ) ) {
        $current_h2 = '';
        foreach ( $elementor_parsed['headings'] as $h ) {
            $text  = isset( $h['text'] )  ? trim( (string) $h['text'] )            : '';
            $level = isset( $h['level'] ) ? strtolower( (string) $h['level'] )     : 'h2';
            if ( '' === $text ) continue;
            $k = strtolower( $text );
            if ( 'h1' === $level ) {
                if ( '' === $h1 ) $h1 = $text;
            } elseif ( 'h2' === $level ) {
                if ( ! isset( $seen_h2[ $k ] ) ) {
                    $seen_h2[ $k ] = true;
                    $h2[] = $text;
                }
                $current_h2 = $text;
            } elseif ( 'h3' === $level ) {
                $pk = $k . '||' . strtolower( $current_h2 );
                if ( ! isset( $seen_h3[ $pk ] ) ) {
                    $seen_h3[ $pk ] = true;
                    $h3_pairs[] = array( 'parent_h2' => $current_h2, 'text' => $text );
                }
            }
        }
    }

    // (3) Last resort: live HTML restricted to <main>/<article> so theme chrome is excluded.
    if ( ( '' === $h1 || empty( $h2 ) ) && '' !== trim( (string) $live_html ) ) {
        $scope    = tse_extract_main_html( $live_html );
        $live_dom = tse_load_dom( $scope );
        tse_collect_headings_from_dom( $live_dom, $h1, $h2, $h3_pairs, $seen_h2, $seen_h3 );
    }

    return array(
        'h1' => $h1,
        'h2' => $h2,
        'h3' => $h3_pairs,
    );
}

/**
 * Walk a DOM in document order and append unique H1/H2/H3 into the shared state.
 */
function tse_collect_headings_from_dom( $dom, &$h1, &$h2, &$h3_pairs, &$seen_h2, &$seen_h3 ) {
    if ( ! ( $dom instanceof DOMDocument ) ) return;
    $xp = new DOMXPath( $dom );
    $nodes = $xp->query( '//h1 | //h2 | //h3' );
    if ( ! $nodes ) return;
    $current_h2 = '';
    foreach ( $nodes as $n ) {
        $text = tse_dom_text( $n );
        if ( '' === $text ) continue;
        $tag = strtolower( $n->nodeName );
        $k   = strtolower( $text );
        if ( 'h1' === $tag ) {
            if ( '' === $h1 ) $h1 = $text;
        } elseif ( 'h2' === $tag ) {
            if ( ! isset( $seen_h2[ $k ] ) ) {
                $seen_h2[ $k ] = true;
                $h2[] = $text;
            }
            $current_h2 = $text;
        } elseif ( 'h3' === $tag ) {
            $pk = $k . '||' . strtolower( $current_h2 );
            if ( ! isset( $seen_h3[ $pk ] ) ) {
                $seen_h3[ $pk ] = true;
                $h3_pairs[] = array( 'parent_h2' => $current_h2, 'text' => $text );
            }
        }
    }
}

/**
 * Narrow live HTML to <main>/<article>/<body> so live-HTML heading extraction
 * doesn't pull in theme headers, footers, nav, widget areas, etc.
 */
function tse_extract_main_html( $html ) {
    if ( preg_match( '#<main\b[^>]*>(.*?)</main>#is',       $html, $m ) ) return $m[1];
    if ( preg_match( '#<article\b[^>]*>(.*?)</article>#is', $html, $m ) ) return $m[1];
    if ( preg_match( '#<body\b[^>]*>(.*?)</body>#is',       $html, $m ) ) return $m[1];
    return $html;
}

function tse_extract_faqs( $dom, $html ) {
    $faqs = array();

    // (1) FAQPage JSON-LD wins.
    foreach ( tse_extract_schema_blocks( $html ) as $block ) {
        $types = isset( $block['@type'] ) ? (array) $block['@type'] : array();
        if ( in_array( 'FAQPage', $types, true ) && ! empty( $block['mainEntity'] ) ) {
            foreach ( (array) $block['mainEntity'] as $entry ) {
                if ( empty( $entry['name'] ) ) continue;
                $answer = '';
                if ( isset( $entry['acceptedAnswer']['text'] ) ) {
                    $answer = wp_strip_all_tags( $entry['acceptedAnswer']['text'] );
                }
                $faqs[] = array( 'q' => (string) $entry['name'], 'a' => trim( $answer ) );
            }
        }
    }
    if ( ! empty( $faqs ) ) {
        return $faqs;
    }

    // (2) Heuristic: H2/H3 ending with "?" followed by content until next heading.
    $xp = new DOMXPath( $dom );
    $headings = $xp->query( '//h2 | //h3 | //h4' );
    foreach ( $headings as $h ) {
        $q = tse_dom_text( $h );
        if ( '' === $q || substr( $q, -1 ) !== '?' ) {
            continue;
        }
        $answer_parts = array();
        $sibling = $h->nextSibling;
        while ( $sibling ) {
            if ( $sibling->nodeType === XML_ELEMENT_NODE && in_array( strtolower( $sibling->nodeName ), array( 'h1','h2','h3','h4' ), true ) ) {
                break;
            }
            $txt = isset( $sibling->textContent ) ? trim( preg_replace( '/\s+/u', ' ', $sibling->textContent ) ) : '';
            if ( '' !== $txt ) {
                $answer_parts[] = $txt;
            }
            $sibling = $sibling->nextSibling;
        }
        $faqs[] = array( 'q' => $q, 'a' => trim( implode( ' ', $answer_parts ) ) );
    }
    return $faqs;
}

/* -------------------------------------------------------------------------
 * Links
 * ---------------------------------------------------------------------- */

function tse_extract_links( $dom, $source_url ) {
    $internal = array();
    $external = array();
    $self_count = 0;

    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $source_norm = tse_normalize_url( (string) $source_url );

    $anchors = $dom->getElementsByTagName( 'a' );
    foreach ( $anchors as $a ) {
        $href = trim( (string) $a->getAttribute( 'href' ) );
        if ( '' === $href || $href[0] === '#' || stripos( $href, 'javascript:' ) === 0 || stripos( $href, 'mailto:' ) === 0 || stripos( $href, 'tel:' ) === 0 ) {
            continue;
        }

        $abs = tse_to_absolute_url( $href, (string) $source_url );
        $host = wp_parse_url( $abs, PHP_URL_HOST );
        $rel  = array_filter( array_map( 'trim', explode( ' ', strtolower( (string) $a->getAttribute( 'rel' ) ) ) ) );
        $anchor = tse_dom_text( $a );

        $entry = array(
            'url'    => $abs,
            'anchor' => $anchor,
            'rel'    => array_values( $rel ),
        );

        if ( $host && $home_host && strcasecmp( $host, $home_host ) === 0 ) {
            $is_self = tse_normalize_url( $abs ) === $source_norm;
            $entry['is_self'] = $is_self;
            if ( $is_self ) $self_count++;
            $internal[] = $entry;
        } else {
            $entry['is_external'] = true;
            $external[] = $entry;
        }
    }

    return array(
        'internal' => $internal,
        'external' => $external,
        'counts'   => array(
            'internal' => count( $internal ),
            'external' => count( $external ),
            'self'     => $self_count,
        ),
    );
}

/* -------------------------------------------------------------------------
 * Images
 * ---------------------------------------------------------------------- */

function tse_extract_images( $dom ) {
    $out = array();
    $seen = array();
    $imgs = $dom->getElementsByTagName( 'img' );
    foreach ( $imgs as $img ) {
        $src = trim( (string) $img->getAttribute( 'src' ) );
        if ( '' === $src ) continue;
        if ( isset( $seen[ $src ] ) ) continue;
        $seen[ $src ] = true;
        $out[] = array(
            'url'      => $src,
            'alt'      => (string) $img->getAttribute( 'alt' ),
            'filename' => basename( wp_parse_url( $src, PHP_URL_PATH ) ),
        );
    }
    return $out;
}

function tse_build_image( $attachment_id ) {
    $url = wp_get_attachment_url( $attachment_id );
    if ( ! $url ) return null;
    $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    return array(
        'id'       => (int) $attachment_id,
        'url'      => $url,
        'alt'      => is_string( $alt ) ? $alt : '',
        'filename' => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
    );
}

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

function tse_extract_schema_blocks( $html ) {
    if ( ! function_exists( 'tse_schema_extract_from_html' ) ) {
        return array();
    }
    $r = tse_schema_extract_from_html( $html );
    return isset( $r['blocks'] ) ? $r['blocks'] : array();
}

/* -------------------------------------------------------------------------
 * SEO (Yoast / Rank Math)
 * ---------------------------------------------------------------------- */

function tse_extract_seo( $post_id, $live_html = '' ) {
    $post = get_post( $post_id );

    // (1) Authoritative: live rendered <head>.
    $live = tse_seo_extract_from_html( $live_html );

    // (2) Raw plugin meta (frequently templates like "%title% %sep% %sitename%").
    $rm_title  = get_post_meta( $post_id, 'rank_math_title', true );
    $rm_desc   = get_post_meta( $post_id, 'rank_math_description', true );
    $rm_canon  = get_post_meta( $post_id, 'rank_math_canonical_url', true );
    $rm_kw     = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
    $rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
    $rm_og_t   = get_post_meta( $post_id, 'rank_math_facebook_title', true );
    $rm_og_d   = get_post_meta( $post_id, 'rank_math_facebook_description', true );
    $rm_og_i   = get_post_meta( $post_id, 'rank_math_facebook_image', true );

    $y_title   = get_post_meta( $post_id, '_yoast_wpseo_title', true );
    $y_desc    = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
    $y_canon   = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
    $y_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
    $y_kw      = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
    $y_og_t    = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
    $y_og_d    = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
    $y_og_i    = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );

    // AIOSEO.
    $a_title = get_post_meta( $post_id, '_aioseo_title', true );
    $a_desc  = get_post_meta( $post_id, '_aioseo_description', true );

    $source = tse_seo_detect_source( $post_id );

    // Title: live HTML → expanded plugin template → post title.
    $title = '';
    if ( '' !== $live['title'] ) {
        $title = $live['title'];
    } elseif ( '' !== (string) $rm_title ) {
        $title = tse_seo_expand_template( $rm_title, $post );
    } elseif ( '' !== (string) $y_title ) {
        $title = tse_seo_expand_template( $y_title, $post );
    } elseif ( '' !== (string) $a_title ) {
        $title = tse_seo_expand_template( $a_title, $post );
    } elseif ( $post ) {
        $title = get_the_title( $post );
    }

    // Description: live HTML → expanded template → excerpt.
    $desc = '';
    if ( '' !== $live['description'] ) {
        $desc = $live['description'];
    } elseif ( '' !== (string) $rm_desc ) {
        $desc = tse_seo_expand_template( $rm_desc, $post );
    } elseif ( '' !== (string) $y_desc ) {
        $desc = tse_seo_expand_template( $y_desc, $post );
    } elseif ( '' !== (string) $a_desc ) {
        $desc = tse_seo_expand_template( $a_desc, $post );
    } elseif ( $post && '' !== (string) $post->post_excerpt ) {
        $desc = wp_strip_all_tags( $post->post_excerpt );
    }

    $canonical = '' !== $live['canonical'] ? $live['canonical'] : ( $rm_canon ? $rm_canon : ( $y_canon ? $y_canon : '' ) );

    $focus_kw = array();
    if ( '' !== (string) $rm_kw ) {
        $focus_kw = array_values( array_filter( array_map( 'trim', explode( ',', (string) $rm_kw ) ) ) );
    } elseif ( '' !== (string) $y_kw ) {
        $focus_kw = array( trim( (string) $y_kw ) );
    }

    // Robots: prefer live <meta name="robots">.
    $robots_index  = true;
    $robots_follow = true;
    if ( '' !== $live['robots'] ) {
        $low = strtolower( $live['robots'] );
        if ( false !== strpos( $low, 'noindex' ) )  $robots_index  = false;
        if ( false !== strpos( $low, 'nofollow' ) ) $robots_follow = false;
    } else {
        if ( is_array( $rm_robots ) ) {
            if ( in_array( 'noindex',  $rm_robots, true ) ) $robots_index  = false;
            if ( in_array( 'nofollow', $rm_robots, true ) ) $robots_follow = false;
        }
        if ( '1' === (string) $y_noindex ) $robots_index = false;
    }

    $og = array(
        'title'       => '' !== $live['og_title']       ? $live['og_title']       : tse_seo_expand_template( $rm_og_t ? $rm_og_t : $y_og_t, $post ),
        'description' => '' !== $live['og_description'] ? $live['og_description'] : tse_seo_expand_template( $rm_og_d ? $rm_og_d : $y_og_d, $post ),
        'image'       => '' !== $live['og_image']       ? $live['og_image']       : ( $rm_og_i ? $rm_og_i : ( $y_og_i ? $y_og_i : '' ) ),
    );

    return array(
        'source'         => $source,
        'title'          => (string) $title,
        'description'    => (string) $desc,
        'focus_keywords' => $focus_kw,
        'canonical'      => (string) $canonical,
        'robots'         => array(
            'index'  => (bool) $robots_index,
            'follow' => (bool) $robots_follow,
        ),
        'og'             => $og,
        'schema_types'   => array(),
    );
}

/**
 * Detect which SEO plugin is active (or which has data on this post).
 */
function tse_seo_detect_source( $post_id ) {
    if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath\\Helper' ) ) return 'rank_math';
    if ( defined( 'WPSEO_VERSION' )     || class_exists( 'WPSEO_Options' ) )    return 'yoast';
    if ( defined( 'AIOSEO_VERSION' ) )                                          return 'aioseo';

    $map = array(
        'rank_math' => array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ),
        'yoast'     => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ),
        'aioseo'    => array( '_aioseo_title', '_aioseo_description' ),
    );
    foreach ( $map as $plugin => $keys ) {
        foreach ( $keys as $k ) {
            $v = get_post_meta( $post_id, $k, true );
            if ( '' !== (string) $v ) return $plugin;
        }
    }
    return 'none';
}

/**
 * Pull title / description / robots / canonical / og:* directly from rendered HTML head.
 */
function tse_seo_extract_from_html( $html ) {
    $out = array(
        'title'          => '',
        'description'    => '',
        'canonical'      => '',
        'robots'         => '',
        'og_title'       => '',
        'og_description' => '',
        'og_image'       => '',
    );
    if ( '' === trim( (string) $html ) ) {
        return $out;
    }

    $head_html = $html;
    if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $hm ) ) {
        $head_html = $hm[1];
    }

    if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', $head_html, $m ) ) {
        $out['title'] = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
    }

    // meta tags are written attribute-order-agnostic — match both name-first and content-first.
    $meta_grabs = array(
        'description'    => 'description',
        'robots'         => 'robots',
    );
    foreach ( $meta_grabs as $key => $name ) {
        $v = tse_seo_match_meta_name( $head_html, $name );
        if ( '' !== $v ) $out[ $key ] = $v;
    }
    $prop_grabs = array(
        'og_title'       => 'og:title',
        'og_description' => 'og:description',
        'og_image'       => 'og:image',
    );
    foreach ( $prop_grabs as $key => $prop ) {
        $v = tse_seo_match_meta_property( $head_html, $prop );
        if ( '' !== $v ) $out[ $key ] = $v;
    }

    if ( preg_match( '#<link\s+[^>]*rel\s*=\s*["\']canonical["\'][^>]*href\s*=\s*["\']([^"\']*)["\']#is', $head_html, $m )
        || preg_match( '#<link\s+[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*rel\s*=\s*["\']canonical["\']#is', $head_html, $m ) ) {
        $out['canonical'] = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
    }

    return $out;
}

function tse_seo_match_meta_name( $head_html, $name ) {
    $n = preg_quote( $name, '#' );
    if ( preg_match( '#<meta\s+[^>]*name\s*=\s*["\']' . $n . '["\'][^>]*content\s*=\s*["\']([^"\']*)["\']#is', $head_html, $m )
        || preg_match( '#<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']' . $n . '["\']#is', $head_html, $m ) ) {
        return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
    }
    return '';
}

function tse_seo_match_meta_property( $head_html, $prop ) {
    $p = preg_quote( $prop, '#' );
    if ( preg_match( '#<meta\s+[^>]*property\s*=\s*["\']' . $p . '["\'][^>]*content\s*=\s*["\']([^"\']*)["\']#is', $head_html, $m )
        || preg_match( '#<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*property\s*=\s*["\']' . $p . '["\']#is', $head_html, $m ) ) {
        return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
    }
    return '';
}

/**
 * Expand %title%/%sitename%/%sep% style variables used by Yoast / Rank Math / AIOSEO.
 * Prefer the plugin's own helper if available.
 */
function tse_seo_expand_template( $tpl, $post ) {
    $tpl = (string) $tpl;
    if ( '' === $tpl ) return '';

    if ( $post && function_exists( 'wpseo_replace_vars' ) ) {
        $r = wpseo_replace_vars( $tpl, $post );
        if ( ! empty( $r ) && $r !== $tpl ) return $r;
    }
    if ( $post && class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'replace_vars' ) ) {
        $r = \RankMath\Helper::replace_vars( $tpl, $post );
        if ( ! empty( $r ) && $r !== $tpl ) return $r;
    }

    $title    = $post ? get_the_title( $post ) : '';
    $excerpt  = $post && '' !== (string) $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : '';
    $sitename = get_bloginfo( 'name' );
    $sitedesc = get_bloginfo( 'description' );
    $vars = array(
        '%title%' => $title,             '%%title%%' => $title,
        '%sitename%' => $sitename,       '%%sitename%%' => $sitename,
        '%site_title%' => $sitename,     '%%site_title%%' => $sitename,
        '%sitedesc%' => $sitedesc,       '%%sitedesc%%' => $sitedesc,
        '%sep%' => '-',                  '%%sep%%' => '-',
        '%page%' => '',                  '%%page%%' => '',
        '%excerpt%' => $excerpt,         '%%excerpt%%' => $excerpt,
        '%excerpt_only%' => $excerpt,    '%%excerpt_only%%' => $excerpt,
        '%primary_category%' => '',      '%%primary_category%%' => '',
        '%category%' => '',              '%%category%%' => '',
    );
    $out = strtr( $tpl, $vars );
    $out = preg_replace( '/\s+/u', ' ', $out );
    return trim( $out );
}

/* -------------------------------------------------------------------------
 * CRO
 * ---------------------------------------------------------------------- */

function tse_detect_cro( $rendered_html, $plain_text, $elementor_parsed ) {
    $cta_patterns = array(
        'book', 'buy', 'get started', 'get a quote', 'request a quote',
        'contact us', 'schedule', 'sign up', 'subscribe', 'download',
        'learn more', 'try it', 'start now', 'call now', 'free trial',
        'shop now', 'order now', 'apply now', 'enquire', 'get in touch',
        'reserve', 'register',
    );

    $ctas = array();
    // From rendered anchors and buttons.
    $dom = tse_load_dom( $rendered_html );
    $xp  = new DOMXPath( $dom );
    $clickables = $xp->query( '//a | //button' );
    foreach ( $clickables as $node ) {
        $text = strtolower( tse_dom_text( $node ) );
        if ( '' === $text ) continue;
        foreach ( $cta_patterns as $p ) {
            if ( strpos( $text, $p ) !== false ) {
                $ctas[] = array(
                    'text'     => tse_dom_text( $node ),
                    'type'     => strtolower( $node->nodeName ),
                    'evidence' => 'rendered-' . strtolower( $node->nodeName ),
                );
                break;
            }
        }
    }
    // From Elementor buttons.
    if ( ! empty( $elementor_parsed['button_texts'] ) ) {
        foreach ( $elementor_parsed['button_texts'] as $btxt ) {
            $low = strtolower( $btxt );
            foreach ( $cta_patterns as $p ) {
                if ( strpos( $low, $p ) !== false ) {
                    $ctas[] = array(
                        'text'     => $btxt,
                        'type'     => 'button',
                        'evidence' => 'elementor-button',
                    );
                    break;
                }
            }
        }
    }

    $phones = array();
    if ( preg_match_all( '/(\+?\d[\d\s().\-]{7,}\d)/', $plain_text, $pm ) ) {
        foreach ( $pm[1] as $p ) {
            $digits = preg_replace( '/\D+/', '', $p );
            if ( strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ) {
                $phones[] = trim( $p );
            }
        }
        $phones = array_values( array_unique( $phones ) );
    }

    $emails = array();
    if ( preg_match_all( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $plain_text, $em ) ) {
        $emails = array_values( array_unique( $em[0] ) );
    }

    $forms = array();
    $form_signatures = array(
        'contact-form-7' => '/\[contact-form-7\b/i',
        'wpforms'        => '/\[wpforms\b/i',
        'gravityforms'   => '/\[gravityform\b/i',
        'forminator'     => '/\[forminator_form\b/i',
        'ninja-forms'    => '/\[ninja_form\b/i',
        'fluentform'     => '/\[fluentform\b/i',
    );
    foreach ( $form_signatures as $name => $regex ) {
        if ( preg_match( $regex, $rendered_html ) ) {
            $forms[] = array( 'plugin' => $name, 'fields' => array() );
        }
    }
    if ( ! empty( $elementor_parsed['has_form'] ) ) {
        $forms[] = array( 'plugin' => 'elementor-pro', 'fields' => $elementor_parsed['form_fields'] );
    }
    if ( stripos( $rendered_html, '<form' ) !== false && empty( $forms ) ) {
        $forms[] = array( 'plugin' => 'native-html', 'fields' => array() );
    }

    $trust_keywords = array(
        'certified', 'awarded', 'licensed', 'trusted', 'verified',
        'guaranteed', 'secure payment', 'money-back', 'money back',
        'as seen on', 'featured in', '100% satisfaction', 'industry-leading',
        'years of experience',
    );
    $trust_hits = array();
    $lower_text = strtolower( $plain_text );
    foreach ( $trust_keywords as $kw ) {
        if ( strpos( $lower_text, $kw ) !== false ) {
            $trust_hits[] = $kw;
        }
    }

    // Testimonials / reviews.
    $testi_present = (bool) ( preg_match( '/testimonial|review|what (our|my) clients say|client says|customers say/i', $rendered_html )
        || preg_match( '/class="[^"]*(testimonial|review)[^"]*"/i', $rendered_html ) );
    $testi_count = 0;
    if ( $testi_present && preg_match_all( '/class="[^"]*(testimonial|review)-item[^"]*"/i', $rendered_html, $tm ) ) {
        $testi_count = count( $tm[0] );
    }

    // FAQ section presence.
    $faq_present = ( preg_match( '/frequently asked|FAQ/i', $rendered_html ) || ! empty( $elementor_parsed['has_faq'] ) );

    return array(
        'ctas'          => $ctas,
        'phones'        => $phones,
        'emails'        => $emails,
        'forms'         => $forms,
        'trust_signals' => $trust_hits,
        'testimonials'  => array(
            'present' => $testi_present,
            'count'   => $testi_count,
        ),
        'faq_section'   => array(
            'present' => $faq_present,
        ),
    );
}

/* -------------------------------------------------------------------------
 * Elementor parsing
 * ---------------------------------------------------------------------- */

function tse_parse_elementor( $raw ) {
    $result = array(
        'is_elementor'  => false,
        'sections'      => array(),
        'widget_counts' => array(),
        'clean_text'    => '',
        'button_texts'  => array(),
        'has_form'      => false,
        'form_fields'   => array(),
        'has_faq'       => false,
        'headings'      => array(),
    );

    if ( empty( $raw ) ) {
        return $result;
    }
    $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
    if ( ! is_array( $data ) ) {
        return $result;
    }
    $result['is_elementor'] = true;

    $clean_chunks = array();
    foreach ( $data as $section ) {
        $widgets = array();
        tse_elementor_walk( $section, $widgets, $result['widget_counts'], $clean_chunks, $result );
        $result['sections'][] = array(
            'type'    => isset( $section['elType'] ) ? $section['elType'] : 'section',
            'id'      => isset( $section['id'] ) ? $section['id'] : '',
            'widgets' => $widgets,
        );
    }

    // Dedupe consecutive identical chunks (case-insensitive).
    $deduped = array();
    $prev    = '';
    foreach ( $clean_chunks as $c ) {
        $n = strtolower( trim( (string) $c ) );
        if ( '' === $n || $n === $prev ) continue;
        $deduped[] = $c;
        $prev = $n;
    }
    $result['clean_text'] = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $deduped ) ) );

    return $result;
}

function tse_elementor_walk( $node, &$widgets_out, &$counts, &$clean_chunks, &$flags ) {
    if ( ! is_array( $node ) ) return;

    if ( isset( $node['elType'] ) && 'widget' === $node['elType'] ) {
        $mapped = tse_elementor_map_widget( $node, $clean_chunks, $flags );
        if ( $mapped ) {
            $widgets_out[] = $mapped;
            $type = isset( $mapped['type'] ) ? $mapped['type'] : 'unknown';
            $counts[ $type ] = isset( $counts[ $type ] ) ? $counts[ $type ] + 1 : 1;
        }
    }
    if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
        foreach ( $node['elements'] as $child ) {
            tse_elementor_walk( $child, $widgets_out, $counts, $clean_chunks, $flags );
        }
    }
}

function tse_elementor_map_widget( $node, &$clean_chunks, &$flags ) {
    $wt = isset( $node['widgetType'] ) ? $node['widgetType'] : 'unknown';
    $s  = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

    switch ( $wt ) {
        case 'heading':
            $text  = isset( $s['title'] ) ? wp_strip_all_tags( (string) $s['title'] ) : '';
            $level = isset( $s['header_size'] ) ? strtolower( (string) $s['header_size'] ) : 'h2';
            if ( '' !== $text ) {
                $clean_chunks[] = $text;
                $flags['headings'][] = array( 'level' => $level, 'text' => $text );
            }
            return array( 'type' => 'heading', 'level' => $level, 'text' => $text );

        case 'text-editor':
        case 'theme-post-content':
            $text = isset( $s['editor'] ) ? wp_strip_all_tags( (string) $s['editor'] ) : '';
            if ( $text !== '' ) $clean_chunks[] = $text;
            return array( 'type' => 'text', 'text' => $text );

        case 'button':
        case 'theme-site-logo':
            $text = isset( $s['text'] ) ? (string) $s['text'] : '';
            $link = isset( $s['link']['url'] ) ? (string) $s['link']['url'] : '';
            if ( $text !== '' ) {
                $clean_chunks[] = $text;
                $flags['button_texts'][] = $text;
            }
            return array( 'type' => 'button', 'text' => $text, 'link' => $link );

        case 'image':
        case 'theme-post-featured-image':
            $url = isset( $s['image']['url'] ) ? (string) $s['image']['url'] : '';
            $alt = isset( $s['image']['alt'] ) ? (string) $s['image']['alt'] : '';
            return array( 'type' => 'image', 'url' => $url, 'alt' => $alt );

        case 'icon-box':
        case 'image-box':
            $h = isset( $s['title_text'] ) ? wp_strip_all_tags( (string) $s['title_text'] ) : '';
            $d = isset( $s['description_text'] ) ? wp_strip_all_tags( (string) $s['description_text'] ) : '';
            if ( $h !== '' ) $clean_chunks[] = $h;
            if ( $d !== '' ) $clean_chunks[] = $d;
            return array( 'type' => 'icon-box', 'heading' => $h, 'description' => $d );

        case 'icon-list':
            $items = array();
            if ( ! empty( $s['icon_list'] ) && is_array( $s['icon_list'] ) ) {
                foreach ( $s['icon_list'] as $it ) {
                    $t = isset( $it['text'] ) ? (string) $it['text'] : '';
                    if ( $t !== '' ) {
                        $items[] = $t;
                        $clean_chunks[] = $t;
                    }
                }
            }
            return array( 'type' => 'icon-list', 'items' => $items );

        case 'toggle':
        case 'accordion':
            $items = array();
            $tabs = isset( $s['tabs'] ) ? $s['tabs'] : ( isset( $s['_accordion_items'] ) ? $s['_accordion_items'] : array() );
            if ( is_array( $tabs ) ) {
                foreach ( $tabs as $t ) {
                    $q = isset( $t['tab_title'] ) ? wp_strip_all_tags( (string) $t['tab_title'] ) : '';
                    $a = isset( $t['tab_content'] ) ? wp_strip_all_tags( (string) $t['tab_content'] ) : '';
                    if ( $q !== '' ) $clean_chunks[] = $q;
                    if ( $a !== '' ) $clean_chunks[] = $a;
                    $items[] = array( 'q' => $q, 'a' => $a );
                }
            }
            $flags['has_faq'] = true;
            return array( 'type' => 'faq', 'items' => $items );

        case 'form':
            $fields = array();
            if ( ! empty( $s['form_fields'] ) && is_array( $s['form_fields'] ) ) {
                foreach ( $s['form_fields'] as $f ) {
                    $fields[] = isset( $f['field_label'] ) ? (string) $f['field_label'] : ( isset( $f['custom_id'] ) ? (string) $f['custom_id'] : 'field' );
                }
            }
            $flags['has_form']    = true;
            $flags['form_fields'] = $fields;
            return array( 'type' => 'form', 'plugin' => 'elementor-pro', 'fields' => $fields );

        case 'shortcode':
            $sc = isset( $s['shortcode'] ) ? (string) $s['shortcode'] : '';
            return array( 'type' => 'shortcode', 'shortcode' => $sc );

        case 'video':
            return array(
                'type' => 'video',
                'url'  => isset( $s['youtube_url'] ) ? $s['youtube_url'] : ( isset( $s['hosted_url']['url'] ) ? $s['hosted_url']['url'] : '' ),
            );

        case 'testimonial':
        case 'testimonial-carousel':
            $flags['has_testimonial'] = true;
            return array( 'type' => 'testimonial', 'widget_type' => $wt );

        default:
            // Fallback: collect all string settings as text evidence.
            $texts = array();
            tse_collect_strings( $s, $texts );
            $summary = implode( ' ', array_slice( $texts, 0, 20 ) );
            if ( '' !== trim( $summary ) ) {
                $clean_chunks[] = $summary;
            }
            return array(
                'type'        => 'unknown',
                'widget_type' => $wt,
                'text'        => trim( $summary ),
            );
    }
}

function tse_collect_strings( $value, &$out ) {
    if ( is_string( $value ) ) {
        $clean = wp_strip_all_tags( $value );
        $clean = trim( preg_replace( '/\s+/u', ' ', $clean ) );
        if ( '' === $clean ) return;
        $len = strlen( $clean );
        if ( $len < 3 || $len > 500 ) return;
        // URLs, hex colors, anchors.
        if ( preg_match( '#^https?://#i', $clean ) ) return;
        if ( '#' === $clean[0] ) return;
        // Pure numbers / dimensions / percentages.
        if ( preg_match( '/^[\d.,\-]+(?:px|em|rem|%|vh|vw|pt)?$/i', $clean ) ) return;
        // Typical CSS / icon / theme tokens.
        if ( preg_match( '/^(?:elementor|eicon|fa|fas|far|fab|wp-|e-|et-|edgtf-|jet-|hfe-)[a-z0-9_\-\s]*$/i', $clean ) ) return;
        // Must contain at least one letter (skips colon-separated tokens, IDs, etc.).
        if ( ! preg_match( '/\p{L}/u', $clean ) ) return;
        $out[] = $clean;
    } elseif ( is_array( $value ) ) {
        foreach ( $value as $v ) {
            tse_collect_strings( $v, $out );
        }
    }
}

/* -------------------------------------------------------------------------
 * Classification
 * ---------------------------------------------------------------------- */

function tse_classify( $post, $front_id ) {
    if ( $front_id && (int) $post->ID === $front_id ) {
        return 'homepage';
    }
    if ( 'post' === $post->post_type ) {
        return 'article';
    }
    if ( 'product' === $post->post_type ) {
        return 'money';
    }

    $slug  = strtolower( (string) $post->post_name );
    $title = strtolower( (string) $post->post_title );
    $blob  = $slug . ' ' . $title;

    $money_kw = array(
        'service', 'pricing', 'price', 'plan', 'package', 'hire', 'book',
        'quote', 'buy', 'shop', 'product', 'order', 'checkout', 'contact',
        'consultation', 'demo', 'sign-up', 'signup', 'get-started', 'solution',
        'case-study', 'enquire', 'enquiry', 'estimate', 'apply',
    );
    $support_kw = array(
        'about', 'faq', 'help', 'support', 'docs', 'documentation', 'terms',
        'privacy', 'legal', 'cookie', 'policy', 'shipping', 'returns', 'refund',
        'careers', 'team', 'blog', 'news', 'company', 'thank-you',
    );

    foreach ( $money_kw as $k ) {
        if ( strpos( $blob, $k ) !== false ) return 'money';
    }
    foreach ( $support_kw as $k ) {
        if ( strpos( $blob, $k ) !== false ) return 'support';
    }
    return 'other';
}

/* -------------------------------------------------------------------------
 * Cross-reference / enrichment of internal links
 * ---------------------------------------------------------------------- */

function tse_exporter_enrich_internal_links( &$records, $url_index ) {
    foreach ( $records as &$r ) {
        $src_type  = $r['post_type'];
        $src_class = $r['classification'];

        foreach ( $r['links']['internal'] as &$lnk ) {
            $norm = tse_normalize_url( $lnk['url'] );
            $lnk['source_post_type']     = $src_type;
            $lnk['source_classification'] = $src_class;

            if ( isset( $url_index[ $norm ] ) ) {
                $target = $records[ $url_index[ $norm ] ];
                $lnk['target_post_type']      = $target['post_type'];
                $lnk['target_classification'] = $target['classification'];
                $lnk['target_id']             = $target['id'];
            } else {
                $lnk['target_post_type']      = 'unknown';
                $lnk['target_classification'] = 'unknown';
                $lnk['target_id']             = null;
            }
        }
        unset( $lnk );
    }
    unset( $r );
}

/* -------------------------------------------------------------------------
 * Bundle assembly
 * ---------------------------------------------------------------------- */

function tse_exporter_assemble_bundle( $records, $postprocess, $opts, $truncated, $post_types ) {
    $manifest = array(
        'plugin'         => 'TSE Site Exporter',
        'plugin_version' => TSE_SITE_EXPORTER_VERSION,
        'site_url'       => home_url(),
        'site_name'      => get_bloginfo( 'name' ),
        'wp_version'     => get_bloginfo( 'version' ),
        'exported_at'    => gmdate( 'c' ),
        'mode'           => $opts['mode'],
        'options'        => array(
            'live_fetch'     => (bool) $opts['live_fetch'],
            'broken_check'   => (bool) $opts['broken_check'],
            'include_slices' => (bool) $opts['include_slices'],
        ),
        'post_types'      => array_values( $post_types ),
        'total_records'   => count( $records ),
        'truncated'       => (bool) $truncated,
        'status_filter'   => 'publish',
        'files'           => array( 'manifest.json', 'full-export.json' ),
    );

    $bundle = array(
        'manifest.json'    => $manifest,
        'full-export.json' => $records,
    );

    if ( $opts['include_slices'] ) {
        // SEO slice.
        $seo = array();
        foreach ( $records as $r ) {
            $seo[] = array(
                'id'    => $r['id'],
                'url'   => $r['url'],
                'title' => $r['seo']['title'],
                'description' => $r['seo']['description'],
                'focus_keywords' => $r['seo']['focus_keywords'],
                'canonical' => $r['seo']['canonical'],
                'robots' => $r['seo']['robots'],
                'og' => $r['seo']['og'],
                'source' => $r['seo']['source'],
                'classification' => $r['classification'],
            );
        }

        // Internal & external link slices.
        $internal_edges = array();
        $external_edges = array();
        foreach ( $records as $r ) {
            foreach ( $r['links']['internal'] as $l ) {
                $internal_edges[] = array(
                    'source'                => $r['url'],
                    'target'                => $l['url'],
                    'anchor'                => $l['anchor'],
                    'rel'                   => $l['rel'],
                    'is_self'               => ! empty( $l['is_self'] ),
                    'source_post_type'      => $l['source_post_type'],
                    'source_classification' => $l['source_classification'],
                    'target_post_type'      => $l['target_post_type'],
                    'target_classification' => $l['target_classification'],
                    'target_id'             => $l['target_id'],
                );
            }
            foreach ( $r['links']['external'] as $l ) {
                $external_edges[] = array(
                    'source' => $r['url'],
                    'target' => $l['url'],
                    'anchor' => $l['anchor'],
                    'rel'    => $l['rel'],
                );
            }
        }

        $cro = array();
        foreach ( $records as $r ) {
            $cro[] = array(
                'id'             => $r['id'],
                'url'            => $r['url'],
                'classification' => $r['classification'],
                'cro'            => $r['cro'],
            );
        }

        $schema = array();
        foreach ( $records as $r ) {
            if ( ! empty( $r['schema'] ) ) {
                $schema[] = array_merge(
                    array(
                        'id'             => $r['id'],
                        'url'            => $r['url'],
                        'classification' => $r['classification'],
                    ),
                    $r['schema']
                );
            }
        }

        $elementor = array();
        foreach ( $records as $r ) {
            if ( ! empty( $r['elementor']['is_elementor'] ) ) {
                $elementor[] = array(
                    'id'            => $r['id'],
                    'url'           => $r['url'],
                    'widget_counts' => $r['elementor']['widget_counts'],
                    'sections'      => $r['elementor']['sections'],
                );
            }
        }

        $bundle['seo-data.json']           = $seo;
        $bundle['internal-links.json']     = array(
            'edges'                  => $internal_edges,
            'anchor_text_frequency'  => $postprocess['anchor_freq'],
        );
        $bundle['external-links.json']     = $external_edges;
        $bundle['cro-analysis.json']       = $cro;
        $bundle['schema.json']              = $schema;
        $bundle['schema-rollup.json']       = tse_schema_build_rollup( $records );
        $bundle['elementor-structure.json'] = $elementor;
        $bundle['hierarchy.json']          = $postprocess['hierarchy'];
        $bundle['orphans.json']            = array(
            'orphan_pages'         => $postprocess['orphans'],
            'broken_internal_links' => $postprocess['broken_links'],
        );

        $manifest['files'] = array_keys( $bundle );
        $bundle['manifest.json'] = $manifest;
    }

    return $bundle;
}

/* -------------------------------------------------------------------------
 * URL helpers
 * ---------------------------------------------------------------------- */

function tse_normalize_text( $text ) {
    $text = (string) $text;
    if ( '' === $text ) return '';
    $text = preg_replace( '/[\r\n\t]+/', ' ', $text );
    $text = preg_replace( '/\s+/u', ' ', $text );
    $text = trim( $text );
    // Dedupe consecutive identical sentences (a common rendering artefact).
    $parts = preg_split( '/(?<=[.!?])\s+/u', $text );
    if ( ! $parts || count( $parts ) < 2 ) return $text;
    $out  = array();
    $prev = '';
    foreach ( $parts as $p ) {
        $p = trim( $p );
        if ( '' === $p ) continue;
        $k = strtolower( $p );
        if ( $k === $prev ) continue;
        $out[] = $p;
        $prev = $k;
    }
    return implode( ' ', $out );
}

function tse_normalize_url( $url ) {
    if ( ! is_string( $url ) || '' === $url ) return '';
    $parts = wp_parse_url( $url );
    if ( ! $parts || empty( $parts['host'] ) ) return rtrim( $url, '/' );
    $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
    $host   = strtolower( $parts['host'] );
    $path   = isset( $parts['path'] ) ? $parts['path'] : '/';
    $path   = rtrim( $path, '/' );
    if ( '' === $path ) $path = '/';
    return $scheme . '://' . $host . $path;
}

function tse_to_absolute_url( $href, $base ) {
    if ( preg_match( '#^https?://#i', $href ) ) {
        return $href;
    }
    $base_parts = wp_parse_url( $base );
    if ( ! $base_parts || empty( $base_parts['host'] ) ) {
        return $href;
    }
    $scheme = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
    $host   = $base_parts['host'];
    $origin = $scheme . '://' . $host;
    if ( '' === $href ) return $base;
    if ( $href[0] === '/' ) return $origin . $href;
    $base_path = isset( $base_parts['path'] ) ? rtrim( dirname( $base_parts['path'] ), '/' ) : '';
    return $origin . $base_path . '/' . ltrim( $href, '/' );
}

function tse_to_iso( $gmt ) {
    if ( ! $gmt || '0000-00-00 00:00:00' === $gmt ) return null;
    return gmdate( 'c', strtotime( $gmt . ' UTC' ) );
}

function tse_fetch_live_html( $url ) {
    $response = wp_remote_get( $url, array(
        'timeout'     => 8,
        'redirection' => 3,
        'sslverify'   => false,
        'user-agent'  => 'TSE-Site-Exporter/' . TSE_SITE_EXPORTER_VERSION,
    ) );
    if ( is_wp_error( $response ) ) return '';
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 400 ) return '';
    return (string) wp_remote_retrieve_body( $response );
}
