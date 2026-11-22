<?php
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
    $method = new ReflectionMethod( $GLOBALS['pti'], $name );
    $method->setAccessible( true );
    return $method->invoke( $GLOBALS['pti'], ...$args );
}
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
        $generated = pti_audit_private( 'ensure_pdf_preview', $pdf_id );
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
    foreach ( $pti_created_ids as $id ) wp_delete_attachment( $id, true );
    foreach ( $pti_created_paths as $path ) if ( is_file( $path ) ) wp_delete_file( $path );
    if ( false === $pti_original_settings ) {
        delete_option( PDF_Thumbnail_Inserter::OPTION_NAME );
    } else {
        update_option( PDF_Thumbnail_Inserter::OPTION_NAME, $pti_original_settings, false );
    }
}
