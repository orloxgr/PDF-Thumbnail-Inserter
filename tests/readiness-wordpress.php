<?php
require_once __DIR__ . '/component-access.php';
/**
 * Real WordPress audit regressions. Use only on an explicitly disposable site.
 * Define PTI_TEST_DISPOSABLE=true and PTI_TEST_WP_ROOT, then include this file.
 * Run directly with PHP (not WP-CLI) to exercise frontend filters too.
 * Define PTI_TEST_REQUIRE_IMAGICK=true for the real PDF server-rendering cases.
 */
if ( ! defined( 'PTI_TEST_DISPOSABLE' ) || true !== PTI_TEST_DISPOSABLE ) {
    exit( 'Requires an explicitly disposable WordPress installation.' );
}
require_once PTI_TEST_WP_ROOT . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
    throw new RuntimeException( 'Requires an isolated local site with cron disabled.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$pti = PDF_Thumbnail_Inserter::instance();
$pti_assertions = array();
$pti_created_ids = array();
$pti_created_paths = array();
$pti_original_settings = get_option( PDF_Thumbnail_Inserter::OPTION_NAME, false );
function pti_audit_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    $GLOBALS['pti_assertions'][] = $message;
}
function pti_audit_private( $name, ...$args ) {
    $method = new ReflectionMethod( pti_test_target( $GLOBALS['pti'], $name ), $name );
    $method->setAccessible( true );
    return $method->invoke( pti_test_target( $GLOBALS['pti'], $method->getName() ), ...$args );
}

// Late registrations must retain the facade identity used by existing integrations.
$pti->register_settings();
pti_audit_assert( array( $pti, 'sanitize_settings' ) === $GLOBALS['wp_registered_settings'][ PDF_Thumbnail_Inserter::OPTION_NAME ]['sanitize_callback'], 'Settings registration retains the original facade callback identity' );
$pti_block = WP_Block_Type_Registry::get_instance()->get_registered( 'pdf-thumbnail-inserter/pdf-thumbnail' );
pti_audit_assert( $pti_block && array( $pti, 'render_block' ) === $pti_block->render_callback, 'Dynamic block registration retains the original facade callback identity' );
function pti_audit_pdf() {
    // Valid xref offsets; avoids relying on PDF.js/Ghostscript repair heuristics.
    $stream = "0.1 0.3 0.7 rg 10 10 180 280 re f\n";
    $objects = array(
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /Resources << >> /Contents 4 0 R >>',
        '<< /Length ' . strlen( $stream ) . ">>\nstream\n" . $stream . 'endstream',
    );
    $pdf = "%PDF-1.4\n";
    $offsets = array( 0 );
    foreach ( $objects as $index => $object ) {
        $offsets[] = strlen( $pdf );
        $pdf .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen( $pdf );
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ( array_slice( $offsets, 1 ) as $offset ) $pdf .= sprintf( "%010d 00000 n \n", $offset );
    return $pdf . "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}
function pti_audit_image( $suffix ) {
    $path = $GLOBALS['pti_pdf_path'] . '-' . $suffix . '.png';
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $path );
    $GLOBALS['pti_created_paths'][] = $path;
    return $path;
}

try {
    pti_audit_assert( is_plugin_active( 'pdf-thumbnail-inserter/pdf-thumbnail-inserter.php' ), 'Target plugin active at bootstrap' );
    $block = WP_Block_Type_Registry::get_instance()->get_registered( 'pdf-thumbnail-inserter/pdf-thumbnail' );
    $expected_api = version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ? 3 : 2;
    pti_audit_assert( $block && $expected_api === $block->api_version, 'Block API matches supported WordPress version' );

    // GitHub ZIP users can supply plugin-local translations, outside wordpress.org language packs.
    require_once ABSPATH . WPINC . '/pomo/mo.php';
    $language_dir = WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/languages';
    $language_dir_existed = is_dir( $language_dir );
    wp_mkdir_p( $language_dir );
    $language_path = $language_dir . '/pdf-thumbnail-inserter-el.mo';
    if ( is_file( $language_path ) ) throw new RuntimeException( 'Refusing to replace an existing translation fixture' );
    $catalogue = new MO();
    $catalogue->set_headers( array( 'Content-Type' => 'text/plain; charset=UTF-8', 'Language' => 'el' ) );
    $catalogue->add_entry( new Translation_Entry( array( 'singular' => 'Download PDF', 'translations' => array( 'Λήψη PDF ελέγχου' ) ) ) );
    $catalogue->export_to_file( $language_path );
    $greek_locale = function () { return 'el'; };
    add_filter( 'locale', $greek_locale, 100 );
    try {
        unload_textdomain( 'pdf-thumbnail-inserter', true );
        $pti->load_textdomain();
        pti_audit_assert( 'Λήψη PDF ελέγχου' === __( 'Download PDF', 'pdf-thumbnail-inserter' ), 'Plugin-local MO translations load on an installation without wordpress.org language packs' );
    } finally {
        remove_filter( 'locale', $greek_locale, 100 );
        unload_textdomain( 'pdf-thumbnail-inserter', true );
        $pti->load_textdomain();
        wp_delete_file( $language_path );
        if ( ! $language_dir_existed ) rmdir( $language_dir );
    }

    // File-like values inside HTML attributes must never be rewritten as prose URLs.
    $cover_markup = '<img src="https://example.org/?cover=1&amp;file=https%3A%2F%2Fexample.org%2Fdocument.pdf&amp;title=Report" alt="Report">';
    pti_audit_assert( $cover_markup === pti_audit_private( 'strip_attachment_sources_from_content', $cover_markup ), 'Listing cleanup preserves image attributes containing PDF URLs' );
    pti_audit_assert( false === strpos( pti_audit_private( 'strip_attachment_sources_from_content', '<p>Text https://example.org/document.pdf?download=1 end</p>' ), 'document.pdf' ), 'Listing cleanup removes naked document URLs from text nodes' );

    // The real script printer must keep inline configuration and security attributes.
    $attribute_filter = function ( $attributes ) {
        $attributes['nonce'] = 'pti-audit-csp';
        $attributes['data-extension'] = 'preserved';
        return $attributes;
    };
    add_filter( 'wp_script_attributes', $attribute_filter, 5 );
    wp_add_inline_script( 'pdf-thumbnail-viewer', 'window.ptiBefore = true;', 'before' );
    wp_add_inline_script( 'pdf-thumbnail-viewer', 'window.ptiAfter = true;', 'after' );
    ob_start();
    wp_print_scripts( array( 'pdf-thumbnail-viewer' ) );
    $scripts = ob_get_clean();
    remove_filter( 'wp_script_attributes', $attribute_filter, 5 );
    foreach ( array( 'type="module"', 'nonce="pti-audit-csp"', 'data-extension="preserved"', 'window.ptiBefore', 'window.ptiAfter' ) as $expected ) {
        pti_audit_assert( false !== strpos( $scripts, $expected ), 'Real script output retains ' . $expected );
    }

    $uploads = wp_upload_dir();
    wp_mkdir_p( $uploads['path'] );
    $pti_pdf_path = $uploads['path'] . '/pti-audit-' . bin2hex( random_bytes( 4 ) ) . '-Ελλάδα & αρχείο.pdf';
    file_put_contents( $pti_pdf_path, pti_audit_pdf() );
    $pti_created_paths[] = $pti_pdf_path;
    $pti_created_paths[] = $pti_pdf_path . '.pti-preview.lock';
    $pdf_id = wp_insert_attachment( array( 'post_title' => 'Ελλάδα & αρχείο', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $pti_pdf_path );
    $pti_created_ids[] = $pdf_id;
    $pdf_url = wp_get_attachment_url( $pdf_id );
    $file_data = pti_audit_private( 'get_valid_local_pdf_attachment_file', $pdf_id );
    pti_audit_assert( ! is_wp_error( $file_data ), 'Greek PDF with ampersand resolves inside uploads' );
    pti_audit_assert( '' !== pti_audit_private( 'get_local_upload_pdf_path', $pdf_url ), 'Unencoded Greek attachment URL maps to the original local file' );
    $literal_stem = $uploads['path'] . '/pti-glob-' . bin2hex( random_bytes( 5 ) );
    $literal_pdf = $literal_stem . '[1].pdf';
    $unrelated_image = $literal_stem . '1-pdf-preview.png';
    $literal_image = $literal_stem . '[1]-pdf-120x200.png';
    foreach ( array( $literal_pdf, $unrelated_image, $literal_image ) as $literal_path ) $pti_created_paths[] = $literal_path;
    copy( __DIR__ . '/fixtures/one-page.pdf', $literal_pdf );
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $unrelated_image );
    $literal_url = $uploads['url'] . '/' . rawurlencode( basename( $literal_pdf ) );
    pti_audit_assert( '' === pti_audit_private( 'get_pdf_preview_url_from_file_url', $literal_url, false ), 'A literal bracket filename cannot select a different PDF preview through glob expansion' );
    copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $literal_image );
    $literal_preview = pti_audit_private( 'get_pdf_preview_url_from_file_url', $literal_url, false );
    pti_audit_assert( basename( $literal_image ) === rawurldecode( basename( $literal_preview ) ), 'Glob discovery still finds a size-suffixed preview for a literal bracket filename' );
    pti_audit_assert( $pdf_id === pti_audit_private( 'get_attachment_id_from_file_url', $pdf_url . '?download=1&version=2' ), 'Real WordPress metadata lookup resolves a Greek URL with query parameters' );
    $filename_reference = pathinfo( basename( $pti_pdf_path ), PATHINFO_FILENAME );
    $exact_reference = pti_audit_private( 'get_pdf_file_data_from_reference_title', $filename_reference );
    pti_audit_assert( ! empty( $exact_reference['attachment_id'] ) && $pdf_id === $exact_reference['attachment_id'], 'Legacy filename reference resolves the correct PDF through real metadata queries' );
    $loose_reference = pti_audit_private( 'get_pdf_file_data_from_loose_reference_title', $filename_reference );
    pti_audit_assert( ! empty( $loose_reference['attachment_id'] ) && $pdf_id === $loose_reference['attachment_id'], 'Loose title tokens resolve the correct Greek PDF through real bounded candidate queries' );
    $pti->register_settings();
    $defaults = pti_audit_private( 'get_defaults' );

    // Real Settings API sanitizers: invalid scalar shapes must never become PHP errors.
    set_error_handler( function ( $severity, $message, $file, $line ) {
        throw new ErrorException( $message, 0, $severity, $file, $line );
    } );
    try {
        foreach ( array_keys( $defaults ) as $key ) {
            foreach ( array( array( 'nested' ), (object) array( 'nested' => 1 ) ) as $malformed ) {
                $result = $pti->sanitize_settings( array( $key => $malformed ) );
                pti_audit_assert( is_scalar( $result[ $key ] ), 'Settings reject malformed shape for ' . $key );
            }
        }
    } finally {
        restore_error_handler();
    }
    foreach ( array( 'default_new_tab', 'default_show_title', 'default_show_button', 'use_iframe_pdf_previews', 'convert_gutenberg_pdf_links_to_cards', 'convert_gutenberg_doc_links_to_cards', 'use_first_pdf_preview_as_featured_image', 'use_default_document_cover', 'auto_browser_preview_on_upload' ) as $key ) {
        foreach ( array( 0, 1 ) as $value ) {
            $settings = array_merge( $defaults, array( $key => $value ) );
            update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $settings, false );
            wp_cache_delete( PDF_Thumbnail_Inserter::OPTION_NAME, 'options' );
            pti_audit_assert( $value === get_option( PDF_Thumbnail_Inserter::OPTION_NAME )[ $key ], 'Settings API round trip ' . $key . '=' . $value );
        }
    }

    foreach ( array( 0, 1 ) as $convert ) {
        foreach ( array( 0, 1 ) as $iframe ) {
            foreach ( array( false, true ) as $preview ) {
                update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array(
                    'convert_gutenberg_pdf_links_to_cards' => $convert,
                    'use_iframe_pdf_previews' => $iframe,
                    'max_pdf_preview_height' => 280,
                ) ), false );
                $html = '<div class="wp-block-file">' . ( $preview ? '<object class="wp-block-file__embed" data="' . esc_url( $pdf_url ) . '" type="application/pdf"></object>' : '' ) . '<a href="' . esc_url( $pdf_url ) . '">Ελλάδα &amp; αρχείο</a></div>';
                $attrs = array( 'id' => $pdf_id, 'displayPreview' => $preview, 'previewHeight' => 500 );
                $output = render_block( array( 'blockName' => 'core/file', 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) ) );
                if ( $convert ) {
                    pti_audit_assert( false !== strpos( $output, 'pti-card' ) && false === strpos( $output, '<object' ) && false === strpos( $output, '<iframe' ), 'PDF conversion wins for preview=' . (int) $preview . ', iframe=' . $iframe );
                } elseif ( $iframe && $preview ) {
                    pti_audit_assert( false !== strpos( $output, '<iframe' ) && false !== strpos( $output, 'height: 280px' ), 'Iframe replaces preview and respects maximum height' );
                } else {
                    pti_audit_assert( false === strpos( $output, 'pti-card' ) && false === strpos( $output, '<iframe' ), 'Disabled conversion preserves core file block, preview=' . (int) $preview . ', iframe=' . $iframe );
                }
            }
        }
    }

    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array( 'default_show_title' => 0, 'default_show_button' => 0, 'default_new_tab' => 0, 'default_link_to' => 'none' ) ), false );
    $pti->enqueue_block_editor_assets();
    preg_match_all( '/var pdfThumbnailBlock = (\{[^\n]+\});/', wp_scripts()->get_data( 'pdf-thumbnail-block-editor', 'data' ), $configs );
    $config = json_decode( end( $configs[1] ), true );
    pti_audit_assert( array( 'show_title' => 'no', 'show_button' => 'no', 'new_tab' => 'no', 'link_to' => 'none' ) === $config['defaults'], 'Saved presentation settings reach new Gutenberg inserts' );

    $block_html = render_block( array( 'blockName' => 'pdf-thumbnail-inserter/pdf-thumbnail', 'attrs' => array( 'id' => $pdf_id, 'className' => 'pti-custom-a pti-custom-b', 'class' => 'pti-legacy-class' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
    pti_audit_assert( false !== strpos( $block_html, 'wp-block-pdf-thumbnail-inserter-pdf-thumbnail' ) && false !== strpos( $block_html, 'pti-custom-a pti-custom-b' ), 'Dynamic block preserves WordPress wrapper and Advanced CSS classes' );
    pti_audit_assert( false !== strpos( $block_html, 'pti-legacy-class' ), 'Dynamic block retains legacy custom class attribute' );

    // Verify saved options at their final output, not only sanitizer return values.
    $custom_url = 'https://example.org/Ελλάδα?x=1&y=2';
    foreach ( array( 'file', 'attachment', 'custom', 'none' ) as $mode ) {
        for ( $mask = 0; $mask < 8; $mask++ ) {
            $show_title = (bool) ( $mask & 1 );
            $show_button = (bool) ( $mask & 2 );
            $new_tab = (bool) ( $mask & 4 );
            update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array(
                'default_link_to' => $mode, 'default_show_title' => (int) $show_title,
                'default_show_button' => (int) $show_button, 'default_new_tab' => (int) $new_tab,
                'button_text' => 'Λήψη & ανάγνωση',
            ) ), false );
            $html = $pti->render_shortcode( array( 'id' => $pdf_id, 'url' => $custom_url ) );
            $dom = new DOMDocument();
            @$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
            $xpath = new DOMXPath( $dom );
            $links = $xpath->query( '//a' );
            $captions = $xpath->query( '//figcaption' );
            $buttons = $xpath->query( '//a[contains(@class,"pti-card__button")]' );
            pti_audit_assert( ( $show_title ? 1 : 0 ) === $captions->length && ( $show_button && 'none' !== $mode ? 1 : 0 ) === $buttons->length,
                'Saved title/button options reach final cards: ' . $mode . '/' . $mask );
            $expected_url = array( 'file' => $pdf_url, 'attachment' => get_attachment_link( $pdf_id ), 'custom' => $custom_url, 'none' => '' )[ $mode ];
            $expected_url = html_entity_decode( esc_url( $expected_url ), ENT_QUOTES, 'UTF-8' );
            pti_audit_assert( 'none' === $mode ? 0 === $links->length : $links->length >= 1, 'Saved link mode controls actual links: ' . $mode . '/' . $mask );
            foreach ( $links as $link ) {
                pti_audit_assert( $expected_url === $link->getAttribute( 'href' ) && ( $new_tab ? '_blank' : '' ) === $link->getAttribute( 'target' )
                    && ( ! $new_tab || ( false !== strpos( $link->getAttribute( 'rel' ), 'noopener' ) && false !== strpos( $link->getAttribute( 'rel' ), 'noreferrer' ) ) ),
                    'Correct target, query string and tab protection: ' . $mode . '/' . $mask );
            }
            if ( $buttons->length ) pti_audit_assert( 'Λήψη & ανάγνωση' === trim( $buttons->item( 0 )->textContent ), 'Saved Greek button label is rendered as text' );
        }
    }
    $visuals = array( 'thumbnail_width' => '18rem', 'title_color' => '#123456', 'title_size' => '1.2em',
        'button_color' => '#234567', 'button_hover_color' => '#345678', 'button_text_color' => '#abcdef', 'button_text_size' => '15px' );
    $properties = array( 'thumbnail_width' => 'thumbnail-width', 'title_color' => 'title-color', 'title_size' => 'title-size',
        'button_color' => 'button-bg', 'button_hover_color' => 'button-bg-hover', 'button_text_color' => 'button-color', 'button_text_size' => 'button-size' );
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, $visuals ), false );
    wp_cache_delete( PDF_Thumbnail_Inserter::OPTION_NAME, 'options' );
    $styled = $pti->render_shortcode( array( 'id' => $pdf_id ) );
    foreach ( $visuals as $key => $value ) {
        pti_audit_assert( $value === get_option( PDF_Thumbnail_Inserter::OPTION_NAME )[ $key ] && false !== strpos( $styled, '--pti-' . $properties[ $key ] . ':' . $value ),
            'Saved/reloaded visual option reaches card CSS: ' . $key );
        $override = $pti->render_shortcode( array( 'id' => $pdf_id, $key => 'thumbnail_width' === $key || false !== strpos( $key, 'size' ) ? '21px' : '#fedcba' ) );
        pti_audit_assert( false !== strpos( $override, '--pti-' . $properties[ $key ] . ':' . ( 'thumbnail_width' === $key || false !== strpos( $key, 'size' ) ? '21px' : '#fedcba' ) ),
            'Explicit shortcode visual option overrides the saved default: ' . $key );
    }
    $custom = $pti->render_shortcode( array( 'id' => $pdf_id, 'title' => '<b>Τίτλος & δοκιμή</b>', 'thumbnail' => 'https://example.org/custom.png',
        'button_text' => 'Άνοιγμα', 'class' => 'alpha beta', 'rel' => 'nofollow', 'new_tab' => 'yes' ) );
    pti_audit_assert( false !== strpos( $custom, 'alpha beta' ) && false !== strpos( $custom, 'https://example.org/custom.png' )
        && false !== strpos( $custom, 'Τίτλος &amp; δοκιμή' ) && false === strpos( $custom, '<b>' )
        && false !== strpos( $custom, '>Άνοιγμα</a>' ) && false !== strpos( $custom, 'nofollow noopener noreferrer' ), 'Explicit title, thumbnail, button, classes and rel survive safely' );
    foreach ( array( 'doc', 'docx' ) as $extension ) {
        $url = 'https://example.org/Δοκιμή.' . $extension . '?download=1&v=2';
        $html = '<div class="wp-block-file"><a href="' . esc_url( $url ) . '">Έγγραφο &amp; δοκιμή</a></div>';
        foreach ( array( 0, 1 ) as $enabled ) {
            update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array( 'convert_gutenberg_doc_links_to_cards' => $enabled ) ), false );
            $output = render_block( array( 'blockName' => 'core/file', 'attrs' => array( 'href' => $url ), 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) ) );
            pti_audit_assert( $enabled ? false !== strpos( $output, 'doc-placeholder.png' ) && false !== strpos( $output, 'pti-card' ) : $html === $output,
                'Actual core/file DOC conversion ' . $extension . '=' . $enabled );
        }
    }
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $defaults, false );

    $read_only_post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Read-only PDF listing', 'post_status' => 'publish', 'post_content' => '[pdf_thumbnail_inserter id="' . $pdf_id . '"]' ) );
    $pti_created_ids[] = $read_only_post;
    $read_only = $pti->resolve_first_pdf_for_post( $read_only_post, 'large', false );
    $listing_data = pti_audit_private( 'get_pdf_featured_image_fallback_data', $read_only_post, 'large' );
    $render_path = dirname( $pti_pdf_path ) . '/' . pathinfo( $pti_pdf_path, PATHINFO_FILENAME ) . '-pdf-preview.jpg';
    $pti_created_paths[] = $render_path;
    pti_audit_assert( ! empty( $read_only['pdf_url'] ) && ! empty( $listing_data['pdf_url'] )
        && empty( $read_only['preview_url'] ) && ! file_exists( $render_path )
        && ! get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true )
        && ! get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ERROR, true ), 'Normal cards, core/file rendering and listing resolution do not decode PDFs or write generated previews/error metadata' );
    if ( class_exists( 'Imagick' ) ) {
        $legacy_generation = $pti->resolve_first_pdf_for_post( $read_only_post, 'large' );
        pti_audit_assert( ! empty( $legacy_generation['preview_url'] ) && is_file( $render_path ), 'The public resolver keeps its existing generation default and does not reuse a read-only cache entry' );
        $refreshed_listing = pti_audit_private( 'get_pdf_featured_image_fallback_data', $read_only_post, 'large' );
        pti_audit_assert( ! empty( $refreshed_listing['url'] ), 'Publishing a preview invalidates an earlier missing-preview listing cache within the same request' );
    }

    if ( class_exists( '\\Elementor\\Plugin' ) ) {
        $previous_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
        $previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->is_category = true;
        $GLOBALS['post'] = get_post( $read_only_post );
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array( 'use_first_pdf_preview_as_featured_image' => 1 ) ), false );
        try {
            pti_audit_assert( pti_audit_private( 'is_attachment_list_render_context' )
                && null === \Elementor\Plugin::instance()->documents->get_current()
                && false === $pti->should_suppress_elementor_post_content( $read_only_post ), 'An actual Elementor manager without a current document cannot crash archive rendering' );
        } finally {
            $GLOBALS['wp_query'] = $previous_query;
            $GLOBALS['post'] = $previous_post;
            update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $defaults, false );
        }
    }

    $previous_user = get_current_user_id();
    $upload_was_enqueued = wp_script_is( 'pdf-thumbnail-browser-upload', 'enqueued' );
    wp_set_current_user( 1 );
    foreach ( array( 0, 1 ) as $enabled ) {
        wp_dequeue_script( 'pdf-thumbnail-browser-upload' );
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, array_merge( $defaults, array( 'auto_browser_preview_on_upload' => $enabled ) ), false );
        $pti->enqueue_browser_upload_assets();
        pti_audit_assert( (bool) $enabled === wp_script_is( 'pdf-thumbnail-browser-upload', 'enqueued' ), 'Real WordPress conditionally enqueues upload listeners when auto preview=' . $enabled );
    }
    wp_dequeue_script( 'pdf-thumbnail-browser-upload' );
    if ( $upload_was_enqueued ) wp_enqueue_script( 'pdf-thumbnail-browser-upload' );
    wp_set_current_user( $previous_user );
    update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $defaults, false );

    // A nested lock attempt is rejected; an exception does not leave a stale OS lock.
    $locked = pti_audit_private( 'with_pdf_preview_lock', $pti_pdf_path, function () use ( $pti_pdf_path ) {
        return pti_audit_private( 'with_pdf_preview_lock', $pti_pdf_path, function () { throw new RuntimeException( 'Must not enter second writer' ); } );
    } );
    pti_audit_assert( is_wp_error( $locked ) && 'preview_busy' === $locked->get_error_code(), 'Concurrent preview writer cannot enter critical section' );
    try {
        pti_audit_private( 'with_pdf_preview_lock', $pti_pdf_path, function () { throw new RuntimeException( 'injected lock failure' ); } );
    } catch ( RuntimeException $error ) {
        pti_audit_assert( 'injected lock failure' === $error->getMessage(), 'Injected lock exception propagated' );
    }
    pti_audit_assert( 'released' === pti_audit_private( 'with_pdf_preview_lock', $pti_pdf_path, function () { return 'released'; } ), 'Lock released after exception and retained file is reusable' );

    // Isolate publication from standalone files created during the rendering tests.
    $standalone = dirname( $pti_pdf_path ) . '/' . pathinfo( $pti_pdf_path, PATHINFO_FILENAME ) . '-pdf-preview.jpg';
    $pti_created_paths[] = $standalone;
    if ( is_file( $standalone ) ) wp_delete_file( $standalone );

    // Publication and rollback use real attachments, metadata, image processing and files.
    $result = pti_audit_private( 'create_pdf_preview_attachment', $pdf_id, pti_audit_image( 'saved' ), 'image/png', 'Ελλάδα & αρχείο' );
    pti_audit_assert( ! is_wp_error( $result ) && $result['generated'], 'Real image attachment published' );
    $preview_id = $result['preview_id'];
    pti_audit_assert( pti_audit_private( 'is_owned_pdf_preview', $preview_id, $pdf_id ), 'Preview ownership marker and attachment parent agree' );
    $replay = pti_audit_private( 'save_browser_preview', $pdf_id, $file_data, null );
    pti_audit_assert( ! is_wp_error( $replay ) && $replay['skipped'] && $preview_id === $replay['preview_id'], 'Repeated browser save reuses preview without requiring another upload' );

    $reject_attached_path = function ( $check, $id, $key ) use ( $pdf_id ) {
        return '_wp_attached_file' === $key && (int) wp_get_post_parent_id( $id ) === $pdf_id ? false : $check;
    };
    $path_failure = pti_audit_image( 'rejected-path' );
    add_filter( 'update_post_metadata', $reject_attached_path, 100, 3 );
    $path_result = pti_audit_private( 'create_pdf_preview_attachment', $pdf_id, $path_failure, 'image/png', 'Rejected attached path' );
    remove_filter( 'update_post_metadata', $reject_attached_path, 100 );
    pti_audit_assert( is_wp_error( $path_result ), 'Rejected attachment path write cannot publish a preview' );
    pti_audit_assert( $preview_id === absint( get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) ), 'Rejected attachment path preserves previous preview' );
    pti_audit_assert( ! glob( substr( $path_failure, 0, -4 ) . '*' ), 'Rejected attachment path removes generated files even without stored file metadata' );

    // WordPress can finish image generation while the metadata write is rejected.
    $reject_metadata = function ( $metadata, $id ) use ( $pdf_id ) {
        return (int) wp_get_post_parent_id( $id ) === $pdf_id ? array() : $metadata;
    };
    $rejected_path = pti_audit_image( 'rejected-write' );
    add_filter( 'wp_update_attachment_metadata', $reject_metadata, 100, 2 );
    $rejected = pti_audit_private( 'create_pdf_preview_attachment', $pdf_id, $rejected_path, 'image/png', 'Rejected metadata write' );
    remove_filter( 'wp_update_attachment_metadata', $reject_metadata, 100 );
    pti_audit_assert( is_wp_error( $rejected ), 'Rejected image metadata write cannot publish a preview' );
    pti_audit_assert( $preview_id === absint( get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) ), 'Rejected metadata write preserves previous preview' );
    pti_audit_assert( ! glob( substr( $rejected_path, 0, -4 ) . '*' ), 'Rejected write removes original image and all generated sizes' );

    $failure_filter = function ( $metadata, $id ) use ( $pdf_id ) {
        if ( (int) get_post( $id )->post_parent === $pdf_id ) throw new RuntimeException( 'injected metadata failure' );
        return $metadata;
    };
    $broken_path = pti_audit_image( 'rollback' );
    $before_children = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $pdf_id, 'numberposts' => -1, 'fields' => 'ids' ) );
    add_filter( 'wp_generate_attachment_metadata', $failure_filter, 100, 2 );
    $failed = pti_audit_private( 'create_pdf_preview_attachment', $pdf_id, $broken_path, 'image/png', 'Rollback' );
    remove_filter( 'wp_generate_attachment_metadata', $failure_filter, 100 );
    $after_children = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $pdf_id, 'numberposts' => -1, 'fields' => 'ids' ) );
    pti_audit_assert( is_wp_error( $failed ) && ! file_exists( $broken_path ) && $before_children === $after_children, 'Metadata exception rolls back uploaded file and attachment' );
    pti_audit_assert( $preview_id === (int) get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ) && get_post( $preview_id ), 'Metadata failure preserves existing preview' );

    $metadata_error_filter = function ( $metadata, $id ) use ( $pdf_id ) {
        return (int) get_post( $id )->post_parent === $pdf_id ? new WP_Error( 'fixture_metadata_error', 'Injected metadata error' ) : $metadata;
    };
    $metadata_error_path = pti_audit_image( 'metadata-error' );
    add_filter( 'wp_generate_attachment_metadata', $metadata_error_filter, 100, 2 );
    try {
        $metadata_error = pti_audit_private( 'create_pdf_preview_attachment', $pdf_id, $metadata_error_path, 'image/png', 'Metadata error' );
    } finally {
        remove_filter( 'wp_generate_attachment_metadata', $metadata_error_filter, 100 );
    }
    pti_audit_assert( is_wp_error( $metadata_error ) && ! glob( substr( $metadata_error_path, 0, -4 ) . '*' )
        && $preview_id === (int) get_post_meta( $pdf_id, PDF_Thumbnail_Inserter::META_PREVIEW_ID, true ), 'A filtered WP_Error metadata result rolls back new images and preserves the existing preview' );
    foreach ( array( 'corrupt serialized metadata', array( 'sizes' => 'corrupt sizes' ) ) as $malformed_metadata ) {
        update_post_meta( $pdf_id, '_wp_attachment_metadata', $malformed_metadata );
        pti_audit_assert( '' === pti_audit_private( 'get_wordpress_pdf_preview_url', $pdf_id, 'medium' ), 'Malformed stored metadata cannot become a WordPress preview' );
    }
    delete_post_meta( $pdf_id, '_wp_attachment_metadata' );

    // Stored metadata alone does not prove that a preview image is still readable.
    $image_metadata = wp_get_attachment_metadata( $preview_id );
    $preview_file = get_attached_file( $preview_id );
    $preview_files = array( $preview_file );
    foreach ( $image_metadata['sizes'] as $size ) $preview_files[] = dirname( $preview_file ) . '/' . $size['file'];
    $moved_files = array();
    try {
        foreach ( array_unique( $preview_files ) as $path ) {
            if ( is_file( $path ) ) {
                rename( $path, $path . '.pti-missing' );
                $moved_files[] = $path;
            }
        }
        $missing = pti_audit_private( 'get_pdf_preview_data', $pdf_id, 'medium', false );
        pti_audit_assert( 'placeholder' === $missing['preview_source'], 'Missing local image files do not masquerade as usable previews' );
        $media_response = $pti->add_browser_preview_to_media_response( array( 'sizes' => array( 'full' => array( 'url' => wp_get_attachment_url( $preview_id ) ) ) ), get_post( $pdf_id ), array() );
        pti_audit_assert( empty( $media_response['sizes'] ), 'Media response does not report missing images as an existing preview' );
        file_put_contents( $preview_file, 'not an image' );
        $corrupt = pti_audit_private( 'get_pdf_preview_data', $pdf_id, 'medium', false );
        pti_audit_assert( 'placeholder' === $corrupt['preview_source'], 'Corrupt local metadata image is not served as a preview' );
    } finally {
        if ( is_file( $preview_file . '.pti-missing' ) && is_file( $preview_file ) ) wp_delete_file( $preview_file );
        foreach ( $moved_files as $path ) if ( is_file( $path . '.pti-missing' ) ) rename( $path . '.pti-missing', $path );
    }
    $cdn_filter = function ( $image, $id ) use ( $preview_id ) {
        return $id === $preview_id ? array( 'https://cdn.example.org/offloaded-preview.jpg', 200, 300, true ) : $image;
    };
    add_filter( 'wp_get_attachment_image_src', $cdn_filter, 100, 2 );
    pti_audit_assert( 'https://cdn.example.org/offloaded-preview.jpg' === pti_audit_private( 'get_image_url_with_size_fallback', $preview_id ), 'CDN-filtered preview URLs are retained without a local-file requirement' );
    remove_filter( 'wp_get_attachment_image_src', $cdn_filter, 100 );

    $pti->cleanup_generated_preview( $pdf_id );
    foreach ( array( 'name', 'tmp_name', 'type', 'error', 'size' ) as $field ) {
        $upload = array( 'name' => 'preview.jpg', 'tmp_name' => '/invalid', 'type' => 'image/jpeg', 'error' => 0, 'size' => 10 );
        $upload[ $field ] = array( 'nested' );
        $invalid = pti_audit_private( 'save_browser_preview', $pdf_id, $file_data, $upload );
        pti_audit_assert( is_wp_error( $invalid ), 'Nested upload field rejected: ' . $field );
    }

    $imagick_required = defined( 'PTI_TEST_REQUIRE_IMAGICK' ) && PTI_TEST_REQUIRE_IMAGICK;
    if ( $imagick_required ) {
        pti_audit_assert( class_exists( 'Imagick' ), 'Real Imagick available' );
        // Remove any standalone preview made by the earlier frontend rendering cases.
        $standalone = dirname( $pti_pdf_path ) . '/' . pathinfo( $pti_pdf_path, PATHINFO_FILENAME ) . '-pdf-preview.jpg';
        if ( is_file( $standalone ) ) wp_delete_file( $standalone );
        $pti_created_paths[] = $standalone;
        $pti_created_paths[] = $standalone . '.lock';
        file_put_contents( $standalone . '.lock', '' );
        $generated = pti_audit_private( 'generate_standalone_pdf_preview', $pti_pdf_path, dirname( $pti_pdf_path ), pathinfo( $pti_pdf_path, PATHINFO_FILENAME ), $uploads['basedir'] );
        pti_audit_assert( wp_normalize_path( $standalone ) === $generated && wp_getimagesize( $standalone ), 'Real Ghostscript/Imagick preview ignores stale legacy lock file; generated=' . $generated );
        wp_delete_file( $standalone );
        $core_pattern = dirname( $pti_pdf_path ) . '/' . pathinfo( $pti_pdf_path, PATHINFO_FILENAME ) . '-pdf*.*';
        $before_core_files = glob( $core_pattern );
        $reject_pdf_metadata = function ( $check, $id, $key ) use ( $pdf_id ) {
            return (int) $id === $pdf_id && '_wp_attachment_metadata' === $key ? false : $check;
        };
        add_filter( 'update_post_metadata', $reject_pdf_metadata, 100, 3 );
        try {
            $rejected_core = pti_audit_private( 'maybe_generate_core_pdf_preview', $pdf_id, true );
        } finally {
            remove_filter( 'update_post_metadata', $reject_pdf_metadata, 100 );
        }
        pti_audit_assert( is_wp_error( $rejected_core ) && 'core_preview_metadata_failed' === $rejected_core->get_error_code(), 'Real core preview generation detects rejected PDF metadata writes' );
        pti_audit_assert( glob( $core_pattern ) === $before_core_files && ! wp_get_attachment_metadata( $pdf_id ), 'Rejected core metadata writes leave no orphaned new JPEGs or sub-sizes' );

        $core_exception = function ( $metadata, $id ) use ( $pdf_id ) {
            if ( (int) $id === $pdf_id ) throw new RuntimeException( 'Injected core metadata exception' );
            return $metadata;
        };
        add_filter( 'wp_generate_attachment_metadata', $core_exception, 100, 2 );
        try {
            $interrupted_core = pti_audit_private( 'maybe_generate_core_pdf_preview', $pdf_id, true );
        } finally {
            remove_filter( 'wp_generate_attachment_metadata', $core_exception, 100 );
        }
        pti_audit_assert( is_wp_error( $interrupted_core ) && glob( $core_pattern ) === $before_core_files && ! wp_get_attachment_metadata( $pdf_id ), 'A final core metadata exception rolls back generated files and partial database metadata' );

        $update_exception = function ( $metadata, $id ) use ( $pdf_id ) {
            if ( (int) $id === $pdf_id ) throw new RuntimeException( 'Injected core metadata update exception' );
            return $metadata;
        };
        add_filter( 'wp_update_attachment_metadata', $update_exception, 100, 2 );
        try {
            $interrupted_update = pti_audit_private( 'maybe_generate_core_pdf_preview', $pdf_id, true );
        } finally {
            remove_filter( 'wp_update_attachment_metadata', $update_exception, 100 );
        }
        pti_audit_assert( is_wp_error( $interrupted_update ) && glob( $core_pattern ) === $before_core_files && ! wp_get_attachment_metadata( $pdf_id ), 'A core metadata update-filter exception cannot escape rollback or orphan its new full-size preview' );

        $previous_core_file = dirname( $pti_pdf_path ) . '/' . pathinfo( $pti_pdf_path, PATHINFO_FILENAME ) . '-pdf-existing.png';
        $pti_created_paths[] = $previous_core_file;
        copy( WP_PLUGIN_DIR . '/pdf-thumbnail-inserter/assets/images/pdf-placeholder.png', $previous_core_file );
        $previous_core_meta = array( 'legacy_note' => 'Keep existing PDF metadata', 'sizes' => array( 'full' => array( 'file' => basename( $previous_core_file ), 'width' => 200, 'height' => 300, 'mime-type' => 'image/png' ) ) );
        wp_update_attachment_metadata( $pdf_id, $previous_core_meta );
        $before_core_files = glob( $core_pattern );
        add_filter( 'update_post_metadata', $reject_pdf_metadata, 100, 3 );
        try {
            $failed_replacement = pti_audit_private( 'maybe_generate_core_pdf_preview', $pdf_id, true );
        } finally {
            remove_filter( 'update_post_metadata', $reject_pdf_metadata, 100 );
        }
        pti_audit_assert( is_wp_error( $failed_replacement ) && $previous_core_meta === wp_get_attachment_metadata( $pdf_id ) && is_file( $previous_core_file )
            && glob( $core_pattern ) === $before_core_files, 'Rejected regeneration preserves every existing preview file and the original custom metadata' );
        $corrupt_update = function ( $metadata, $id ) use ( $pdf_id ) { return (int) $id === $pdf_id ? array( 'invalid_fixture' => true ) : $metadata; };
        add_filter( 'wp_update_attachment_metadata', $corrupt_update, 100, 2 );
        try {
            $corrupt_replacement = pti_audit_private( 'maybe_generate_core_pdf_preview', $pdf_id, true );
        } finally {
            remove_filter( 'wp_update_attachment_metadata', $corrupt_update, 100 );
        }
        pti_audit_assert( is_wp_error( $corrupt_replacement ) && $previous_core_meta === wp_get_attachment_metadata( $pdf_id ) && is_file( $previous_core_file )
            && glob( $core_pattern ) === $before_core_files, 'A corrupting metadata transformation is rolled back to the exact previous values and files' );
        wp_delete_file( $previous_core_file );
        delete_post_meta( $pdf_id, '_wp_attachment_metadata' );
        $hook_observations = array();
        $upload_callback = array( $pti, 'maybe_generate_pdf_preview_on_upload' );
        $observe_generation_hook = function ( $metadata, $id ) use ( $pdf_id, $upload_callback, &$hook_observations ) {
            if ( (int) $id === $pdf_id ) $hook_observations[] = has_filter( 'wp_generate_attachment_metadata', $upload_callback );
            return $metadata;
        };
        add_filter( 'wp_generate_attachment_metadata', $observe_generation_hook, PHP_INT_MIN, 2 );
        try {
            $generated = pti_audit_private( 'ensure_pdf_preview', $pdf_id );
        } finally {
            remove_filter( 'wp_generate_attachment_metadata', $observe_generation_hook, PHP_INT_MIN );
        }
        pti_audit_assert( $hook_observations && array( false ) === array_values( array_unique( $hook_observations, SORT_REGULAR ) ), 'Core generation removes the exact originally registered upload callback during metadata generation' );
        pti_audit_assert( 20 === has_filter( 'wp_generate_attachment_metadata', $upload_callback ), 'Core generation restores that same upload callback and priority afterward' );
        pti_audit_assert( ! is_wp_error( $generated ) && $generated['generated'], 'Explicit server generation reports newly generated preview accurately' );
        $again = pti_audit_private( 'ensure_pdf_preview', $pdf_id );
        pti_audit_assert( ! is_wp_error( $again ) && ! $again['generated'], 'Repeated server generation reuses existing preview' );

        // A missing/filtered WordPress PDF editor must not hide the direct Imagick fallback.
        $fallback_path = $uploads['path'] . '/pti-direct-' . bin2hex( random_bytes( 5 ) ) . '.pdf';
        file_put_contents( $fallback_path, pti_audit_pdf() );
        $pti_created_paths[] = $fallback_path;
        $pti_created_paths[] = $fallback_path . '.pti-preview.lock';
        $fallback_id = wp_insert_attachment( array( 'post_title' => 'Direct Imagick upload', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $fallback_path );
        $pti_created_ids[] = $fallback_id;
        $no_editors = function () { return array(); };
        add_filter( 'wp_image_editors', $no_editors, 100 );
        try {
            $environment = pti_audit_private( 'get_environment_status' );
            pti_audit_assert( $environment['generation_available'] && ! $environment['editor_ok'], 'A failed sample/editor does not disable direct server maintenance' );
            $pti->maybe_generate_pdf_preview_on_upload( array(), $fallback_id, 'create' );
            $fallback_preview = pti_audit_private( 'get_pdf_preview_data', $fallback_id, 'full', false );
            pti_audit_assert( 'plugin' === $fallback_preview['preview_source'] && $fallback_preview['preview_id'], 'Actual upload hook uses direct Imagick when WordPress has no usable PDF editor' );
        } finally {
            remove_filter( 'wp_image_editors', $no_editors, 100 );
        }
    }

    echo wp_json_encode( array( 'status' => 'PASS', 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'imagick_cases' => $imagick_required, 'assertions' => $pti_assertions ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
} finally {
    foreach ( $pti_created_ids as $id ) {
        if ( 'attachment' === get_post_type( $id ) ) wp_delete_attachment( $id, true );
        else wp_delete_post( $id, true );
    }
    foreach ( $pti_created_paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    if ( false === $pti_original_settings ) {
        delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    } else {
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $pti_original_settings, false );
    }
}
